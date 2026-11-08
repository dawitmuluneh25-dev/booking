<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Database\Connection;
use App\Database\DatabaseBackup;
use App\Database\Migrator;
use App\Domain\AuditRepository;
use App\Domain\BookingService;
use App\Domain\ClinicCalendar;
use App\Domain\OutboxRepository;
use App\Domain\RetentionPolicy;
use App\Domain\RetentionRepository;
use App\Domain\RetentionService;
use App\Domain\UserRepository;
use App\Notifications\LogTransport;
use App\Notifications\OutboxDispatcher;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\PdoAuditRepository;
use App\Repositories\PdoBookingRepository;
use App\Repositories\PdoClinicCalendar;
use App\Repositories\PdoOutboxRepository;
use App\Repositories\PdoRetentionRepository;
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

        $application = new self(
            $config,
            new View($config->root('templates'), ['appName' => $config->appName()]),
            new Router(),
            new Logger($config->root('var/logs')),
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
        return $this->dispatcher ??= new OutboxDispatcher(
            $this->outbox(),
            new LogTransport($this->config->root('var/logs/notifications.log')),
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
