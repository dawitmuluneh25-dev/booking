<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Database\Connection;
use App\Database\DatabaseBackup;
use App\Database\Migrator;
use App\Database\PdoTransaction;
use LogicException;
use App\Domain\AccountRequestAttempts;
use App\Domain\AccountRequestRepository;
use App\Domain\AccountRequestThrottle;
use App\Domain\ApproveAccountRequest;
use App\Domain\AuditRepository;
use App\Domain\AuditTrail;
use App\Domain\RejectAccountRequest;
use App\Domain\RequestAccount;
use App\Domain\SessionHumanCheck;
use App\Domain\BookingService;
use App\Domain\ClinicCalendar;
use App\Domain\OpenUrgentReview;
use App\Domain\NotificationFeedRepository;
use App\Domain\NotificationRetentionRepository;
use App\Domain\NotificationRetentionService;
use App\Domain\OperationalAppointmentRepository;
use App\Domain\RestoreDrillRepository;
use App\Domain\SaveOperationalAppointment;
use App\Domain\OutboxRepository;
use App\Domain\RetentionPolicy;
use App\Domain\RetentionSchedule;
use App\Domain\IdempotencyStore;
use App\Domain\AcknowledgeUrgentCase;
use App\Domain\AppointmentRepository;
use App\Domain\AdvanceUrgentCoordination;
use App\Domain\ApproveForScheduling;
use App\Domain\BookAppointment;
use App\Domain\CancelAppointment;
use App\Domain\ChangeUserRole;
use App\Domain\CloseUrgentCase;
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
use App\Domain\ReassignClinicalPriority;
use App\Domain\ReviewQuarantinedDocument;
use App\Domain\DocumentAccessService;
use App\Domain\TrackingTokenRepository;
use App\Domain\UploadReferralDocument;
use App\Domain\VerifyPhysician;
use App\Domain\CompleteProcedureSession;
use App\Domain\EscalateUrgentCase;
use App\Domain\RecordPatientArrival;
use App\Domain\RecordUrgentContactAttempt;
use App\Domain\RecordUrgentDecision;
use App\Domain\PhysicianProfileRepository;
use App\Domain\ClinicalAssessmentRepository;
use App\Domain\ConsentRepository;
use App\Domain\RecordClinicalAssessment;
use App\Domain\VerifyGuardianAuthority;
use App\Domain\AddLaboratoryDestination;
use App\Domain\LaboratoryDestinationRepository;
use App\Domain\ChangePractitionerCredentialStatus;
use App\Domain\ClinicalPrivileges;
use App\Domain\EscalationReadiness;
use App\Domain\EscalationRouteRepository;
use App\Domain\RecordEscalationRoute;
use App\Domain\RetireEscalationRoute;
use App\Domain\VerifyEscalationRoute;
use App\Domain\EndPractitionerPrivilege;
use App\Domain\GrantPractitionerPrivilege;
use App\Domain\PractitionerCredentialRepository;
use App\Domain\RecordClinicalDecision;
use App\Domain\RecordPractitionerCredential;
use App\Domain\RecordProcedureConsent;
use App\Domain\WithdrawConsent;
use App\Domain\ContentRepository;
use App\Domain\PublishContent;
use App\Domain\WithdrawContent;
use App\Domain\ScheduleWorkspace;
use App\Domain\ScheduleRepository;
use App\Domain\ScheduleBoard;
use App\Domain\RescheduleAppointment;
use App\Storage\FileSignatureScanner;
use App\Storage\FilesystemDocumentStorage;
use App\Domain\RegisterUrgentCase;
use App\Domain\ProcedureMilestoneRepository;
use App\Domain\ProcedureRepository;
use App\Domain\RecordProcedureMilestone;
use App\Domain\ReferralRepository;
use App\Domain\SaveReferralDraft;
use App\Domain\SubmitDraft;
use App\Domain\RetentionRepository;
use App\Domain\SubmitElectiveReferral;
use App\Domain\Transaction;
use App\Domain\TriageReferral;
use App\Domain\UrgentCaseRepository;
use App\Domain\RetentionService;
use App\Domain\UserRepository;
use App\Notifications\BrevoTransport;
use App\Notifications\DeliveryReadiness;
use App\Notifications\MisconfiguredTransport;
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
use App\Repositories\PdoAuditTrail;
use App\Repositories\PdoBookingRepository;
use App\Repositories\PdoClinicCalendar;
use App\Repositories\PdoDocumentRepository;
use App\Repositories\PdoNotificationRetentionRepository;
use App\Repositories\PdoOperationalAppointmentRepository;
use App\Repositories\PdoRestoreDrillRepository;
use App\Repositories\PdoReferralRetentionRepository;
use App\Repositories\PdoTrackingTokenRepository;
use App\Repositories\PdoClinicalAssessmentRepository;
use App\Repositories\PdoConsentRepository;
use App\Repositories\PdoAccountRequestAttempts;
use App\Repositories\PdoAccountRequestRepository;
use App\Repositories\PdoContentRepository;
use App\Repositories\PdoLaboratoryDestinationRepository;
use App\Repositories\PdoNotificationFeed;
use App\Repositories\PdoIdempotencyStore;
use App\Repositories\PdoOutboxRepository;
use App\Repositories\PdoPatientRelationshipRepository;
use App\Repositories\PdoPhysicianProfileRepository;
use App\Repositories\PdoEscalationRouteRepository;
use App\Repositories\PdoPractitionerCredentialRepository;
use App\Repositories\PdoProcedureMilestoneRepository;
use App\Repositories\PdoProcedureRepository;
use App\Repositories\PdoUrgentCaseRepository;
use App\Domain\ResourceAdminRepository;
use App\Domain\ResourceBlockRepository;
use App\Repositories\PdoReferralRepository;
use App\Repositories\PdoResourceAdminRepository;
use App\Repositories\PdoResourceBlockRepository;
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

    private ?ReassignClinicalPriority $reassignPriority = null;

    private ?ConsentRepository $consents = null;

    private ?ClinicalAssessmentRepository $assessments = null;

    private ?RecordClinicalAssessment $recordAssessment = null;

    private ?VerifyGuardianAuthority $verifyGuardian = null;

    private ?LaboratoryDestinationRepository $laboratories = null;

    private ?PractitionerCredentialRepository $practitionerCredentials = null;

    private ?ClinicalPrivileges $clinicalPrivileges = null;

    private ?RecordPractitionerCredential $recordPractitionerCredential = null;

    private ?ChangePractitionerCredentialStatus $changeCredentialStatus = null;

    private ?GrantPractitionerPrivilege $grantPrivilege = null;

    private ?EndPractitionerPrivilege $endPrivilege = null;

    private ?EscalationRouteRepository $escalationRoutes = null;

    private ?EscalationReadiness $escalationReadiness = null;

    private ?RecordEscalationRoute $recordEscalationRoute = null;

    private ?VerifyEscalationRoute $verifyEscalationRoute = null;

    private ?RetireEscalationRoute $retireEscalationRoute = null;

    private ?AddLaboratoryDestination $addLaboratory = null;

    private ?RecordProcedureConsent $recordConsent = null;

    private ?WithdrawConsent $withdrawConsent = null;

    private ?UrgentCaseRepository $urgentCases = null;

    private ?AppointmentRepository $appointments = null;

    private ?PhysicianProfileRepository $physicians = null;

    private ?ChangeUserRole $changeUserRole = null;

    private ?CreateUserAccount $createUserAccount = null;

    private ?AccountRequestRepository $accountRequests = null;

    private ?AccountRequestAttempts $accountRequestAttempts = null;

    private ?AccountRequestThrottle $accountRequestThrottle = null;

    private ?RequestAccount $requestAccount = null;

    private ?ApproveAccountRequest $approveAccountRequest = null;

    private ?RejectAccountRequest $rejectAccountRequest = null;

    private ?VerifyPhysician $verifyPhysician = null;

    private ?PatientRelationshipRepository $relationships = null;

    private ?ScheduleRepository $schedule = null;

    private ?ScheduleBoard $scheduleBoard = null;

    private ?ResourceBlockRepository $resourceBlocks = null;

    private ?ResourceAdminRepository $resourceAdmin = null;

    private ?RescheduleAppointment $rescheduleAppointment = null;

    private ?CancelAppointment $cancelAppointment = null;

    private ?BookAppointment $bookAppointment = null;

    private ?ProcedureMilestoneRepository $procedureMilestones = null;

    private ?RecordProcedureMilestone $recordProcedureMilestone = null;

    private ?RecordPatientArrival $recordArrival = null;

    private ?CompleteProcedureSession $completeSession = null;

    private ?RecordUrgentContactAttempt $recordUrgentContact = null;

    private ?EscalateUrgentCase $escalateUrgent = null;

    private ?RegisterUrgentCase $registerUrgent = null;

    private ?AcknowledgeUrgentCase $acknowledgeUrgent = null;

    private ?CloseUrgentCase $closeUrgent = null;

    private ?RecordUrgentDecision $recordUrgentDecision = null;

    private ?AdvanceUrgentCoordination $advanceCoordination = null;

    private ?OpenUrgentReview $openReview = null;

    private ?ContentRepository $content = null;

    private ?NotificationFeedRepository $notificationFeed = null;

    private ?PublishContent $publishContent = null;

    private ?WithdrawContent $withdrawContent = null;

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

    private ?NotificationRetentionRepository $notificationRetentionRepository = null;

    private ?OperationalAppointmentRepository $operationalAppointments = null;

    private ?RestoreDrillRepository $restoreDrills = null;

    private ?AuditTrail $auditTrail = null;

    private ?ProcedureRepository $procedures = null;

    private ?SaveReferralDraft $saveDraft = null;

    private ?SubmitDraft $submitDraft = null;

    private ?string $requestId = null;

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
            $this->clinicalPrivileges(),
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

    public function reassignClinicalPriority(): ReassignClinicalPriority
    {
        return $this->reassignPriority ??= new ReassignClinicalPriority(
            $this->referrals(),
            new Validator(),
            $this->transaction(),
        );
    }

    /**
     * Consent records live beside the referral rather than inside it: a referral
     * reaching a terminal state must not take the record of what a patient agreed
     * with it (§18.1).
     */
    public function consents(): ConsentRepository
    {
        return $this->consents ??= new PdoConsentRepository($this->pdo());
    }

    public function clinicalAssessments(): ClinicalAssessmentRepository
    {
        return $this->assessments ??= new PdoClinicalAssessmentRepository($this->pdo());
    }

    public function recordClinicalAssessment(): RecordClinicalAssessment
    {
        return $this->recordAssessment ??= new RecordClinicalAssessment(
            $this->clinicalAssessments(),
            $this->referrals(),
            new Validator(),
            $this->transaction(),
        );
    }

    public function verifyGuardianAuthority(): VerifyGuardianAuthority
    {
        return $this->verifyGuardian ??= new VerifyGuardianAuthority($this->referrals(), new Validator());
    }

    public function notificationFeed(): NotificationFeedRepository
    {
        return $this->notificationFeed ??= new PdoNotificationFeed($this->pdo());
    }

    public function content(): ContentRepository
    {
        return $this->content ??= new PdoContentRepository($this->pdo());
    }

    public function publishContent(): PublishContent
    {
        return $this->publishContent ??= new PublishContent($this->content());
    }

    public function withdrawContent(): WithdrawContent
    {
        return $this->withdrawContent ??= new WithdrawContent($this->content());
    }

    public function laboratories(): LaboratoryDestinationRepository
    {
        return $this->laboratories ??= new PdoLaboratoryDestinationRepository($this->pdo());
    }

    public function addLaboratoryDestination(): AddLaboratoryDestination
    {
        return $this->addLaboratory ??= new AddLaboratoryDestination($this->laboratories());
    }

    /**
     * The credential register (Decision 6, AC-009).
     *
     * One instance per request, shared by the approval check and the screen that
     * writes it — the check reads it on every clinical approval.
     */
    public function practitionerCredentials(): PractitionerCredentialRepository
    {
        return $this->practitionerCredentials ??= new PdoPractitionerCredentialRepository($this->pdo());
    }

    public function clinicalPrivileges(): ClinicalPrivileges
    {
        return $this->clinicalPrivileges ??= new ClinicalPrivileges($this->practitionerCredentials());
    }

    public function recordPractitionerCredential(): RecordPractitionerCredential
    {
        return $this->recordPractitionerCredential ??= new RecordPractitionerCredential(
            $this->practitionerCredentials(),
            $this->users(),
            new Validator(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    public function changeCredentialStatus(): ChangePractitionerCredentialStatus
    {
        return $this->changeCredentialStatus ??= new ChangePractitionerCredentialStatus(
            $this->practitionerCredentials(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    public function grantPractitionerPrivilege(): GrantPractitionerPrivilege
    {
        return $this->grantPrivilege ??= new GrantPractitionerPrivilege(
            $this->practitionerCredentials(),
            $this->procedures(),
            $this->users(),
            new Validator(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    public function endPractitionerPrivilege(): EndPractitionerPrivilege
    {
        return $this->endPrivilege ??= new EndPractitionerPrivilege(
            $this->practitionerCredentials(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    /**
     * The escalation roster (Decision 4).
     *
     * One instance per request: the board asks it whether escalation is usable, and
     * the refusals ask it the same question through the same object rather than
     * re-deciding from the rows.
     */
    public function escalationRoutes(): EscalationRouteRepository
    {
        return $this->escalationRoutes ??= new PdoEscalationRouteRepository($this->pdo());
    }

    public function escalationReadiness(): EscalationReadiness
    {
        return $this->escalationReadiness ??= new EscalationReadiness($this->escalationRoutes());
    }

    public function recordEscalationRoute(): RecordEscalationRoute
    {
        return $this->recordEscalationRoute ??= new RecordEscalationRoute(
            $this->escalationRoutes(),
            new Validator(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    public function verifyEscalationRoute(): VerifyEscalationRoute
    {
        return $this->verifyEscalationRoute ??= new VerifyEscalationRoute(
            $this->escalationRoutes(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    public function retireEscalationRoute(): RetireEscalationRoute
    {
        return $this->retireEscalationRoute ??= new RetireEscalationRoute(
            $this->escalationRoutes(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    public function recordProcedureConsent(): RecordProcedureConsent
    {
        return $this->recordConsent ??= new RecordProcedureConsent(
            $this->consents(),
            $this->referrals(),
            new Validator(),
            $this->transaction(),
        );
    }

    public function withdrawConsent(): WithdrawConsent
    {
        return $this->withdrawConsent ??= new WithdrawConsent($this->consents(), new Validator());
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

    public function saveReferralDraft(): SaveReferralDraft
    {
        return $this->saveDraft ??= new SaveReferralDraft($this->referrals(), $this->procedures());
    }

    public function submitDraft(): SubmitDraft
    {
        return $this->submitDraft ??= new SubmitDraft($this->referrals());
    }

    public function procedures(): ProcedureRepository
    {
        return $this->procedures ??= new PdoProcedureRepository($this->pdo());
    }

    public function physicians(): PhysicianProfileRepository
    {
        return $this->physicians ??= new PdoPhysicianProfileRepository($this->pdo(), $this->transaction());
    }

    public function changeUserRole(): ChangeUserRole
    {
        return $this->changeUserRole ??= new ChangeUserRole(
            $this->users(),
            $this->auditTrail(),
            $this->transaction(),
        );
    }

    public function createUserAccount(): CreateUserAccount
    {
        return $this->createUserAccount ??= new CreateUserAccount(
            $this->users(),
            new PasswordHasher(),
            $this->auditTrail(),
            $this->transaction(),
        );
    }

    public function verifyPhysician(): VerifyPhysician
    {
        return $this->verifyPhysician ??= new VerifyPhysician(
            $this->physicians(),
            $this->auditTrail(),
            $this->transaction(),
        );
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

    public function resourceBlocks(): ResourceBlockRepository
    {
        return $this->resourceBlocks ??= new PdoResourceBlockRepository($this->pdo());
    }

    public function resourceAdmin(): ResourceAdminRepository
    {
        return $this->resourceAdmin ??= new PdoResourceAdminRepository($this->pdo());
    }

    public function scheduleBoard(): ScheduleBoard
    {
        return $this->scheduleBoard ??= new ScheduleBoard(
            $this->schedule(),
            $this->resourceBlocks(),
        );
    }

    public function rescheduleAppointment(): RescheduleAppointment
    {
        return $this->rescheduleAppointment ??= new RescheduleAppointment(
            $this->appointments(),
            $this->transaction(),
            $this->outbox(),
        );
    }

    public function cancelAppointment(): CancelAppointment
    {
        return $this->cancelAppointment ??= new CancelAppointment(
            $this->appointments(),
            $this->transaction(),
            $this->outbox(),
        );
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
            $this->outbox(),
        );
    }

    public function procedureMilestones(): ProcedureMilestoneRepository
    {
        return $this->procedureMilestones ??= new PdoProcedureMilestoneRepository($this->pdo());
    }

    public function recordProcedureMilestone(): RecordProcedureMilestone
    {
        // The consent boundary is passed in because "consent verified" has to name
        // the record it verified; without one there is nothing to assert, and the
        // command refuses rather than storing a claim the record cannot support.
        return $this->recordProcedureMilestone ??= new RecordProcedureMilestone(
            $this->appointments(),
            $this->procedureMilestones(),
            $this->consents(),
            new Validator(),
            $this->transaction(),
            $this->auditTrail(),
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

    public function closeUrgentCase(): CloseUrgentCase
    {
        return $this->closeUrgent ??= new CloseUrgentCase($this->urgentCases());
    }

    public function openUrgentReview(): OpenUrgentReview
    {
        return $this->openReview ??= new OpenUrgentReview($this->urgentCases());
    }

    public function advanceUrgentCoordination(): AdvanceUrgentCoordination
    {
        return $this->advanceCoordination ??= new AdvanceUrgentCoordination($this->urgentCases());
    }

    public function recordUrgentDecision(): RecordUrgentDecision
    {
        return $this->recordUrgentDecision ??= new RecordUrgentDecision(
            $this->urgentCases(),
            new Validator(),
            $this->transaction(),
        );
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

    public function accountRequests(): AccountRequestRepository
    {
        return $this->accountRequests ??= new PdoAccountRequestRepository($this->pdo());
    }

    public function accountRequestAttempts(): AccountRequestAttempts
    {
        return $this->accountRequestAttempts ??= new PdoAccountRequestAttempts($this->pdo());
    }

    public function accountRequestThrottle(): AccountRequestThrottle
    {
        return $this->accountRequestThrottle ??= new AccountRequestThrottle($this->accountRequestAttempts());
    }

    public function requestAccount(): RequestAccount
    {
        return $this->requestAccount ??= new RequestAccount(
            $this->accountRequests(),
            $this->accountRequestThrottle(),
            new SessionHumanCheck(),
            $this->auditTrail(),
        );
    }

    public function approveAccountRequest(): ApproveAccountRequest
    {
        return $this->approveAccountRequest ??= new ApproveAccountRequest(
            $this->accountRequests(),
            $this->createUserAccount(),
            $this->physicians(),
            $this->auditTrail(),
            $this->transaction(),
        );
    }

    public function rejectAccountRequest(): RejectAccountRequest
    {
        return $this->rejectAccountRequest ??= new RejectAccountRequest(
            $this->accountRequests(),
            $this->auditTrail(),
        );
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
        // Production is only allowed to claim delivery when something can deliver. A log driver there
        // marks rows `sent` and sends nothing, and the outbox is the record staff use to answer "was the
        // patient told?" — so the answer has to be a failure they can see, not a receipt they cannot.
        $readiness = DeliveryReadiness::assess(
            $this->config->notificationDriver(),
            $this->config->isProduction(),
            $this->config->mailActivationApproved(),
            $this->config->brevoApiKey() !== '',
            $this->config->brevoSender() !== '',
            $this->config->notificationUrl() !== '',
            $this->config->notificationToken() !== '',
        );

        if ($this->config->isProduction() && !$readiness['ok']) {
            return new MisconfiguredTransport((string) $readiness['reason']);
        }

        if ($this->config->notificationDriver() === 'http') {
            return new HttpTransport(
                new CurlHttpClient(),
                $this->config->notificationUrl(),
                $this->config->notificationToken(),
                $this->config->notificationTimeout(),
                new DateTimeZone($this->config->timezone()),
            );
        }

        if ($this->config->notificationDriver() === 'brevo') {
            // Production sending stays off until O-3 section 8 evidence is
            // accepted. Note what an unactivated transport does: it fails
            // loudly per message rather than falling back to the log driver,
            // because a log driver marks the outbox "sent" and would make the
            // queue look healthy while nothing left the building.
            return new BrevoTransport(
                new CurlHttpClient(),
                $this->config->brevoApiKey(),
                $this->config->brevoSender(),
                $this->config->brevoSenderName(),
                BrevoTransport::ENDPOINT,
                10,
                new DateTimeZone($this->config->timezone()),
                !$this->config->isProduction() || $this->config->mailActivationApproved(),
            );
        }

        return new LogTransport(
            $this->config->root('var/logs/notifications.log'),
            new DateTimeZone($this->config->timezone()),
        );
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

    /**
     * D-02's per-class retention schedule, and whether it has been activated.
     *
     * Deliberately not consulted by `retention()`: the service still runs the single window, because
     * the schedule is adopted and not activated (see `RetentionSchedule`). `retention:plan` is where
     * the difference is reported, which is the only thing this slice is allowed to change until
     * Legal validates the periods.
     */
    public function retentionSchedule(): RetentionSchedule
    {
        return new RetentionSchedule($this->config->retentionScheduleActive());
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

        $review = $this->reviewDocument ??= new ReviewQuarantinedDocument(
            $this->documents(),
            $accessService,
            $this->auditTrail(),
            $this->transaction(),
        );

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

        // Referrals carry their own class in D-02's table. Reading it here keeps the schedule the only
        // place a period is decided: today both are seven years, but a rule that is right by coincidence
        // is a rule that will be wrong quietly.
        $schedule = $this->retentionSchedule();
        $days = $schedule->isActive()
            ? $schedule->daysFor('referral_and_intake')
            : $this->config->retentionDays();

        return new ReferralRetentionService(
            $repository,
            $this->documentStorage(),
            new RetentionPolicy($days),
        );
    }

    public function notificationRetention(int $days): NotificationRetentionService
    {
        $repository = $this->notificationRetentionRepository ??= new PdoNotificationRetentionRepository($this->pdo());

        // The days come from the caller, and the caller reads `RetentionSchedule::daysFor()`: this
        // class retires at 365 days, and a policy built from `retentionDays()` would hold it for seven
        // years. Passing the number in keeps one place — the schedule — deciding periods.
        return new NotificationRetentionService($repository, new RetentionPolicy($days));
    }

    public function restoreDrills(): RestoreDrillRepository
    {
        return $this->restoreDrills ??= new PdoRestoreDrillRepository($this->pdo());
    }

    public function operationalAppointments(): OperationalAppointmentRepository
    {
        return $this->operationalAppointments ??= new PdoOperationalAppointmentRepository($this->pdo());
    }

    /**
     * Saves one appointment, validates it against the enum, and writes the audit row in the same
     * transaction. Constructed per call rather than memoised: it holds nothing but references, and a
     * cached instance would hide a change to the audit trail binding during a test.
     */
    public function saveOperationalAppointment(): SaveOperationalAppointment
    {
        return new SaveOperationalAppointment(
            $this->operationalAppointments(),
            new Validator(),
            $this->transaction(),
            $this->auditTrail(),
        );
    }

    public function audit(): AuditRepository
    {
        return new PdoAuditRepository($this->pdo());
    }

    /**
     * The append-only trail for authority, credential and document decisions.
     *
     * One per process: PHP serves one request per process here, and a console
     * invocation is one run, so a per-process id is a faithful correlation id
     * without threading a value through every command signature.
     */
    public function auditTrail(): AuditTrail
    {
        return $this->auditTrail ??= new PdoAuditTrail($this->pdo(), $this->requestId());
    }

    /** Correlation id for this request or console run, generated once. */
    public function requestId(): string
    {
        return $this->requestId ??= bin2hex(random_bytes(8));
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
            $this->config->backupEncryptionKey(),
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
