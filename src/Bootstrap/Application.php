<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Database\Connection;
use App\Database\DatabaseBackup;
use App\Database\Migrator;
use App\Database\PdoTransaction;
use LogicException;
use App\Domain\AuditRepository;
use App\Domain\BookingService;
use App\Domain\ClinicCalendar;
use App\Domain\OutboxRepository;
use App\Domain\RetentionPolicy;
use App\Domain\IdempotencyStore;
use App\Domain\AcknowledgeUrgentCase;
use App\Domain\AppointmentRepository;
use App\Domain\ApproveForScheduling;
use App\Domain\BookAppointment;
use App\Domain\CancelAppointment;
use App\Domain\ChangeUserRole;
use App\Domain\CreateUserAccount;
use App\Domain\DocumentRepository;
use App\Domain\DocumentStorage;
use App\Domain\DocumentWorkspace;
use App\Domain\PatientRelationshipRepository;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralRetentionRepository;
use App\Domain\ReferralRetentionService;
use App\Domain\ReferralTrackingLink;
use App\Domain\ReviewQuarantinedDocument;
use App\Domain\DocumentAccessService;
use App\Domain\TrackingTokenRepository;
use App\Domain\UploadReferralDocument;
use App\Domain\VerifyPhysician;
use App\Domain\CompleteProcedureSession;
use App\Domain\EscalateUrgentCase;
use App\Domain\RecordPatientArrival;
use App\Domain\RecordUrgentContactAttempt;
use App\Domain\PhysicianProfileRepository;
use App\Domain\RecordClinicalDecision;
use App\Domain\ScheduleWorkspace;
use App\Domain\ScheduleRepository;
use App\Domain\ScheduleBoard;
use App\Domain\RescheduleAppointment;
use App\Storage\FileSignatureScanner;
use App\Storage\FilesystemDocumentStorage;
use App\Domain\RegisterUrgentCase;
use App\Domain\ReferralRepository;
use App\Domain\RetentionRepository;
use App\Domain\SubmitElectiveReferral;
use App\Domain\Transaction;
use App\Domain\TriageReferral;
use App\Domain\UrgentCaseRepository;
use App\Domain\RetentionService;
use App\Domain\UserRepository;
use App\Notifications\CurlHttpClient;
use App\Notifications\HttpTransport;
use App\Notifications\LogTransport;
use App\Notifications\NotificationTransport;
use App\Notifications\OutboxDispatcher;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\RouteAccess;
use App\Http\Router;
use App\Repositories\PdoAppointmentRepository;
use App\Repositories\PdoAuditRepository;
use App\Repositories\PdoBookingRepository;
use App\Repositories\PdoClinicCalendar;
use App\Repositories\PdoDocumentRepository;
use App\Repositories\PdoReferralRetentionRepository;
use App\Repositories\PdoTrackingTokenRepository;
use App\Repositories\PdoIdempotencyStore;
use App\Repositories\PdoOutboxRepository;
use App\Repositories\PdoPatientRelationshipRepository;
use App\Repositories\PdoPhysicianProfileRepository;
use App\Repositories\PdoUrgentCaseRepository;
use App\Repositories\PdoReferralRepository;
use App\Repositories\PdoRetentionRepository;
use App\Repositories\PdoScheduleRepository;
use App\Repositories\PdoUserRepository;
use App\Security\Auth;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\Diagnostics;
use App\Support\Logger;
use App\Support\LogReader;
use App\Support\View;
use App\Validation\Validator;
use DateTimeZone;
use PDO;

/**
 * Composition root: the only place that knows how the pieces are wired.
 *
 * The database connection is lazy so public pages and the router can be
 * exercised without a database being available.
 */
final class Application
{
    private ?PDO $pdo = null;

    private ?Transaction $transaction = null;

    private ?ReferralRepository $referrals = null;

    private ?IdempotencyStore $idempotency = null;

    private ?SubmitElectiveReferral $submitReferral = null;

    private ?RecordClinicalDecision $recordDecision = null;

    private ?TriageReferral $triageReferral = null;

    private ?ApproveForScheduling $approveForScheduling = null;

    private ?UrgentCaseRepository $urgentCases = null;

    private ?AppointmentRepository $appointments = null;

    private ?PhysicianProfileRepository $physicians = null;

    private ?ChangeUserRole $changeUserRole = null;

    private ?CreateUserAccount $createUserAccount = null;

    private ?VerifyPhysician $verifyPhysician = null;

    private ?PatientRelationshipRepository $relationships = null;

    private ?ScheduleRepository $schedule = null;

    private ?ScheduleBoard $scheduleBoard = null;

    private ?RescheduleAppointment $rescheduleAppointment = null;

    private ?CancelAppointment $cancelAppointment = null;

    private ?BookAppointment $bookAppointment = null;

    private ?RecordPatientArrival $recordArrival = null;

    private ?CompleteProcedureSession $completeSession = null;

    private ?RecordUrgentContactAttempt $recordUrgentContact = null;

    private ?EscalateUrgentCase $escalateUrgent = null;

    private ?RegisterUrgentCase $registerUrgent = null;

    private ?AcknowledgeUrgentCase $acknowledgeUrgent = null;

    private ?Kernel $kernel = null;

    private ?UserRepository $users = null;

    private ?Auth $auth = null;

    private ?Gate $gate = null;

    private ?OutboxRepository $outbox = null;

    private ?OutboxDispatcher $dispatcher = null;

    private ?LogReader $logReader = null;

    private ?Migrator $migrator = null;

    private ?DatabaseBackup $backups = null;

    private ?Diagnostics $diagnostics = null;

    private ?RetentionRepository $retentionRepository = null;

    private ?ClinicCalendar $calendar = null;

    private ?DocumentRepository $documents = null;

    private ?DocumentStorage $documentStorage = null;

    private ?TrackingTokenRepository $trackingTokens = null;

    private ?ReferralAccessResolver $referralAccess = null;

    private ?UploadReferralDocument $uploadDocument = null;

    private ?DocumentAccessService $documentAccess = null;

    private ?ReviewQuarantinedDocument $reviewDocument = null;

    private ?ReferralTrackingLink $trackingLink = null;

    private ?ReferralRetentionRepository $referralRetentionRepository = null;

    private function __construct(
        private readonly Config $config,
        private readonly View $view,
        private readonly Router $router,
        private readonly Logger $logger,
    ) {
    }

    public static function boot(string $root): self
    {
        Env::load($root . '/.env');

        $config = new Config($root);
        date_default_timezone_set($config->timezone());

        $router = new Router();

        $application = new self(
            $config,
            new View($config->root('templates'), ['appName' => $config->appName()]),
            $router,
            new Logger($config->root('var/logs')),
        );

        // The authoriser needs the application that has just been built, so it is
        // installed after construction rather than captured before it exists.
        $router->authorizeWith(
            static function (RouteAccess $access) use ($application): void {
                if ($access->permission !== null) {
                    $application->gate()->authorize($access->permission);

                    return;
                }

                $application->gate()->authenticate();
            },
        );

        /** @var callable(Application): void $routes */
        $routes = require $root . '/routes/web.php';
        $routes($application);

        return $application;
    }

    public function handle(Request $request): Response
    {
        return $this->kernel()->handle($request);
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function view(): View
    {
        return $this->view;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function root(string $path = ''): string
    {
        return $this->config->root($path);
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::create($this->config->database());
    }

    /**
     * One unit of work per request. Repositories join it instead of opening their
     * own, so a command that writes across them commits or fails as a whole.
     */
    public function transaction(): Transaction
    {
        return $this->transaction ??= new PdoTransaction($this->pdo());
    }

    public function referrals(): ReferralRepository
    {
        return $this->referrals ??= new PdoReferralRepository($this->pdo(), $this->transaction());
    }

    public function recordClinicalDecision(): RecordClinicalDecision
    {
        return $this->recordDecision ??= new RecordClinicalDecision(
            $this->referrals(),
            new Validator(),
            $this->transaction(),
        );
    }

    public function triageReferral(): TriageReferral
    {
        return $this->triageReferral ??= new TriageReferral($this->referrals(), new Validator());
    }

    public function approveForScheduling(): ApproveForScheduling
    {
        return $this->approveForScheduling ??= new ApproveForScheduling($this->referrals());
    }

    public function urgentCases(): UrgentCaseRepository
    {
        return $this->urgentCases ??= new PdoUrgentCaseRepository($this->pdo(), $this->transaction());
    }

    public function registerUrgentCase(): RegisterUrgentCase
    {
        return $this->registerUrgent ??= new RegisterUrgentCase($this->urgentCases(), new Validator());
    }

    public function acknowledgeUrgentCase(): AcknowledgeUrgentCase
    {
        return $this->acknowledgeUrgent ??= new AcknowledgeUrgentCase($this->urgentCases());
    }

    public function physicians(): PhysicianProfileRepository
    {
        return $this->physicians ??= new PdoPhysicianProfileRepository($this->pdo(), $this->transaction());
    }

    public function changeUserRole(): ChangeUserRole
    {
        return $this->changeUserRole ??= new ChangeUserRole($this->users());
    }

    public function createUserAccount(): CreateUserAccount
    {
        return $this->createUserAccount ??= new CreateUserAccount($this->users(), new PasswordHasher());
    }

    public function verifyPhysician(): VerifyPhysician
    {
        return $this->verifyPhysician ??= new VerifyPhysician($this->physicians());
    }

    public function relationships(): PatientRelationshipRepository
    {
        return $this->relationships ??= new PdoPatientRelationshipRepository($this->pdo());
    }

    public function appointments(): AppointmentRepository
    {
        return $this->appointments ??= new PdoAppointmentRepository($this->pdo(), $this->transaction());
    }

    public function schedule(): ScheduleRepository
    {
        return $this->schedule ??= new PdoScheduleRepository($this->pdo());
    }

    public function scheduleBoard(): ScheduleBoard
    {
        return $this->scheduleBoard ??= new ScheduleBoard($this->schedule());
    }

    public function rescheduleAppointment(): RescheduleAppointment
    {
        return $this->rescheduleAppointment ??= new RescheduleAppointment($this->appointments());
    }

    public function cancelAppointment(): CancelAppointment
    {
        return $this->cancelAppointment ??= new CancelAppointment($this->appointments());
    }

    public function scheduleWorkspace(): ScheduleWorkspace
    {
        return new ScheduleWorkspace(
            $this->scheduleBoard(),
            $this->referrals(),
            $this->appointments(),
            $this->bookAppointment(),
            $this->rescheduleAppointment(),
            $this->cancelAppointment(),
        );
    }

    public function bookAppointment(): BookAppointment
    {
        return $this->bookAppointment ??= new BookAppointment(
            $this->referrals(),
            $this->appointments(),
            $this->transaction(),
        );
    }

    public function recordPatientArrival(): RecordPatientArrival
    {
        return $this->recordArrival ??= new RecordPatientArrival($this->appointments());
    }

    public function completeProcedureSession(): CompleteProcedureSession
    {
        return $this->completeSession ??= new CompleteProcedureSession($this->appointments());
    }

    public function recordUrgentContactAttempt(): RecordUrgentContactAttempt
    {
        return $this->recordUrgentContact ??= new RecordUrgentContactAttempt($this->urgentCases());
    }

    public function escalateUrgentCase(): EscalateUrgentCase
    {
        return $this->escalateUrgent ??= new EscalateUrgentCase($this->urgentCases());
    }

    public function idempotency(): IdempotencyStore
    {
        return $this->idempotency ??= new PdoIdempotencyStore($this->pdo());
    }

    public function submitReferral(): SubmitElectiveReferral
    {
        return $this->submitReferral ??= new SubmitElectiveReferral(
            $this->referrals(),
            new Validator(),
            $this->idempotency(),
            $this->physicians(),
        );
    }

    public function users(): UserRepository
    {
        return $this->users ??= new PdoUserRepository($this->pdo());
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this->users(), new PasswordHasher(), $this->config->sessionLifetime());
    }

    public function gate(): Gate
    {
        return $this->gate ??= new Gate($this->auth());
    }

    public function outbox(): OutboxRepository
    {
        return $this->outbox ??= new PdoOutboxRepository($this->pdo());
    }

    public function dispatcher(): OutboxDispatcher
    {
        return $this->dispatcher ??= new OutboxDispatcher($this->outbox(), $this->transport());
    }

    /**
     * Development writes messages to a log; production posts them to the
     * gateway bridge. A provider is never called from a web request.
     */
    public function transport(): NotificationTransport
    {
        if ($this->config->notificationDriver() === 'http') {
            return new HttpTransport(
                new CurlHttpClient(),
                $this->config->notificationUrl(),
                $this->config->notificationToken(),
                $this->config->notificationTimeout(),
            );
        }

        return new LogTransport($this->config->root('var/logs/notifications.log'));
    }

    public function retentionRepository(): RetentionRepository
    {
        return $this->retentionRepository ??= new PdoRetentionRepository($this->pdo());
    }

    public function retention(): RetentionService
    {
        return new RetentionService(
            $this->retentionRepository(),
            new RetentionPolicy($this->config->retentionDays()),
        );
    }

    public function referralAccess(): ReferralAccessResolver
    {
        return $this->referralAccess ??= new ReferralAccessResolver(
            new ReferralAccessPolicy(),
            $this->physicians(),
            $this->relationships(),
        );
    }

    public function documentStorage(): DocumentStorage
    {
        return $this->documentStorage ??= new FilesystemDocumentStorage($this->config->documentStoragePath());
    }

    public function documents(): DocumentRepository
    {
        return $this->documents ??= new PdoDocumentRepository($this->pdo(), $this->transaction());
    }

    public function trackingTokens(): TrackingTokenRepository
    {
        return $this->trackingTokens ??= new PdoTrackingTokenRepository($this->pdo());
    }

    public function trackingLink(): ReferralTrackingLink
    {
        return $this->trackingLink ??= new ReferralTrackingLink(
            $this->trackingTokens(),
            $this->referrals(),
            $this->config->trackingLinkTtlMinutes(),
        );
    }

    /**
     * The document screen's collaborators. Built once so the page, the upload,
     * the stream and the review all share one access policy and one storage root.
     */
    public function documentWorkspace(): DocumentWorkspace
    {
        $types = $this->config->documentTypes();
        $access = $this->referralAccess();

        $upload = $this->uploadDocument ??= new UploadReferralDocument(
            $this->documents(),
            $this->documentStorage(),
            new FileSignatureScanner(),
            $access,
            $this->transaction(),
            $types,
            $this->config->documentMaxBytes(),
        );

        $accessService = $this->documentAccess ??= new DocumentAccessService(
            $this->documents(),
            $this->referrals(),
            $access,
        );

        $review = $this->reviewDocument ??= new ReviewQuarantinedDocument($this->documents(), $accessService);

        return new DocumentWorkspace(
            $this->referrals(),
            $this->documents(),
            $this->documentStorage(),
            $access,
            $upload,
            $review,
            $accessService,
            $this->trackingLink(),
            $types,
        );
    }

    public function referralRetention(): ReferralRetentionService
    {
        $repository = $this->referralRetentionRepository ??= new PdoReferralRetentionRepository(
            $this->pdo(),
            $this->transaction(),
        );

        return new ReferralRetentionService(
            $repository,
            $this->documentStorage(),
            new RetentionPolicy($this->config->retentionDays()),
        );
    }

    public function audit(): AuditRepository
    {
        return new PdoAuditRepository($this->pdo());
    }

    public function logReader(): LogReader
    {
        return $this->logReader ??= new LogReader($this->config->logDirectory());
    }

    public function migrator(): Migrator
    {
        return $this->migrator ??= new Migrator($this->pdo(), $this->root('database/migrations'));
    }

    public function backups(): DatabaseBackup
    {
        return $this->backups ??= new DatabaseBackup(
            $this->pdo(),
            $this->config->database()['database'],
            $this->config->backupDirectory(),
            function (string $database): PDO {
                $configuration = $this->config->database();
                $configuration['database'] = $database;

                return Connection::create($configuration, $database === '');
            },
        );
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics ??= new Diagnostics(
            $this->pdo(),
            $this->migrator(),
            $this->backups(),
            $this->logReader(),
            $this->root(),
        );
    }

    public function calendar(): ClinicCalendar
    {
        return $this->calendar ??= new PdoClinicCalendar($this->pdo());
    }

    public function bookings(): BookingService
    {
        return new BookingService(
            new PdoBookingRepository($this->pdo()),
            new Validator(),
            new DateTimeZone($this->config->timezone()),
            $this->calendar(),
        );
    }

    private function kernel(): Kernel
    {
        return $this->kernel ??= new Kernel(
            $this->router,
            $this->view,
            $this->logger,
            $this->config->debug(),
        );
    }
}
