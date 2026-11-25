<?php

/**
 * The operator console.
 *
 * This file exists, with this extension, because the style gate cannot read a file
 * called `bin/console`: `phpcs` derives the extension from the name, finds none, and
 * silently skips it. The entry point itself stays where the host expects it â€” see
 * `bin/console`, which requires this file and nothing else.
 *
 * It is a script rather than a class: the commands are one-shot process runs, `exit()`
 * is the return channel, and wrapping them in methods would add indirection without
 * making any of them testable. Function declarations and executable statements in the
 * same file are therefore deliberate, and the side-effect sniff is disabled below for
 * the same reason it is disabled in `bin/ci-status.php`.
 *
 * phpcs:disable PSR1.Files.SideEffects
 */

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Database\DemoSeedGuard;
use App\Database\DemoSeedRefused;
use App\Database\DemoSeeder;
use App\Database\Migrator;
use App\Domain\Actor;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\AuthorisationException;
use App\Domain\ChangeUserAccess;
use App\Domain\ContentSource;
use App\Domain\SeedEscalationRegister;
use App\Domain\UserNotFoundException;
use App\Domain\ValidationException;
use App\Domain\WithdrawContent;
use App\Domain\RetentionPolicy;
use App\Domain\RetentionService;
use App\Domain\Role;
use App\Security\Auth;
use App\Security\PasswordHasher;
use App\Support\BackupAlert;
use App\Support\BackupCipher;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}

require $autoload;

$command = $argv[1] ?? 'help';
$options = array_slice($argv, 2);

// `db:backup --key` is answered before the application boots, because booting connects to the
// database — and the moment an operator needs a key is the moment a new installation has no data
// in it yet. A generator that requires a working database cannot be used to set up a backup.
if ($command === 'db:backup' && in_array('--key', $options, true)) {
    echo BackupCipher::generateKey() . PHP_EOL;
    echo 'Set BACKUP_ENCRYPTION_KEY to this value, then re-run without --key.' . PHP_EOL;
    exit(0);
}

// A backup that cannot boot is still a scheduled backup that failed. Booting connects to the database
// and builds the router, so an unreachable database once produced a PHP fatal (exit 255, stack trace,
// no alert) instead of the exit 1 an operator or a cron wrapper can act on. D-09 asks for the failure
// to be recorded and alerted; "the process died" satisfies neither.
try {
    $app = Application::boot($root);
} catch (Throwable $bootFailure) {
    if ($command !== 'db:backup') {
        throw $bootFailure;
    }

    BackupAlert::record(
        (new \App\Bootstrap\Config($root))->backupDirectory(),
        'boot-failed',
        $bootFailure->getMessage(),
    );
    fwrite(STDERR, 'Backup failed before it could start: ' . $bootFailure->getMessage() . PHP_EOL);

    exit(1);
}

switch ($command) {
    case 'doctor':
        exit(doctor($app));

    case 'migrate':
        exit(runMigrations($app, in_array('--dry-run', $options, true)));

    case 'migrate:status':
        exit(migrationStatus($app));

    case 'bookings:list':
        exit(listBookings($app));

    case 'booking:show':
        exit(showBooking($app, $options));

    case 'booking:confirm':
        exit(changeStatus($app, 'confirm', $options));

    case 'booking:cancel':
        exit(changeStatus($app, 'cancel', $options));

    case 'booking:complete':
        exit(changeStatus($app, 'complete', $options));

    case 'user:create':
        exit(createUser($app, $options));

    case 'user:list':
        exit(listUsers($app));

    case 'user:reset-token':
        exit(issueResetToken($app, $options));

    case 'user:deactivate':
        exit(changeUserAccess($app, false, $options));

    case 'user:reactivate':
        exit(changeUserAccess($app, true, $options));

    case 'outbox:list':
        exit(listOutbox($app));

    case 'outbox:dispatch':
        exit(dispatchOutbox($app, $options));

    case 'outbox:receipt':
        exit(recordReceipt($app, $options));

    case 'db:backup':
        exit(createBackup($app, $options));

    case 'db:backups':
        exit(listBackups($app));

    case 'db:restore-drill':
        exit(restoreDrill($app, $options));

    case 'logs:review':
        exit(reviewLogs($app, $options));

    case 'diag':
        exit(printDiagnostics($app));

    case 'retention:plan':
        exit(retentionPlan($app));

    case 'retention:purge':
        exit(retentionPurge($app, $options));

    case 'accounts:requests':
        exit(listAccountRequests($app, $options));

    case 'demo:seed':
        exit(demoSeed($app, $options));

    case 'content:sync':
        exit(syncContent($app));

    case 'content:withdraw':
        exit(withdrawContent($app, $options));

    case 'escalation:seed-register':
        exit(seedEscalationRegister($app));

    case 'backup:key-check':
        exit(checkBackupKey($app));

    case 'security:credentials':
        exit(reportCredentials($app, $options));

    case 'release:gates':
        exit(reportReleaseGates($app, $options));

    case 'help':
    default:
        echo <<<TXT
        Booking console

          php bin/console doctor              Check runtime, configuration and database
          php bin/console migrate             Apply pending migrations
          php bin/console migrate --dry-run   Show what would be applied
          php bin/console migrate:status      List migrations and their state
          php bin/console bookings:list       Print the next 20 bookings
          php bin/console booking:show BK-…   Show one booking
          php bin/console booking:confirm BK-… [--actor=you]
          php bin/console booking:cancel BK-… [--actor=you] [--reason="…"]
          php bin/console booking:complete BK-… [--actor=you]

          php bin/console user:create <email> <name> <admin|staff> [--password=…]
          php bin/console user:list
          php bin/console user:reset-token <email>
          php bin/console user:deactivate <email> --actor=<email>
          php bin/console user:reactivate <email> --actor=<email>

          php bin/console outbox:list [--limit=20]
          php bin/console outbox:dispatch [--limit=20]
          php bin/console outbox:receipt <provider-message-id> [--at="2026-11-13 09:00:00"]

          php bin/console diag                  Print the operational checks
          php bin/console db:backup [--prune=14] [--require-encryption]
          php bin/console db:backup --key      Print a new BACKUP_ENCRYPTION_KEY and exit
          php bin/console db:backups
          php bin/console db:restore-drill [--file=…] [--database=…_drill] [--keep]
          php bin/console logs:review [--level=error] [--limit=30]

          php bin/console retention:plan
          php bin/console retention:purge [--days=2557] [--yes] [--limit=500]

          php bin/console accounts:requests [--expire]

        accounts:requests lists the referring-account queue and, with --expire,
        closes anything nobody answered in 60 days. Receiving no answer is not a
        decision, so it is recorded as expiry rather than approval or refusal.

          php bin/console demo:seed [--yes] [--password=…]
          php bin/console content:sync        Import the service and policy pages as drafts
          php bin/console content:withdraw <content-key> --actor=<email> --yes
                                              Take a published page down, and say who decided it
          php bin/console backup:key-check     Check the backup key's custody against D-08
          php bin/console security:credentials [--require-recorded] [--today=YYYY-MM-DD]
                                              Print the D-10 credential register and what is overdue
          php bin/console release:gates [--json]   Print the seven §3.2 release gates and their evidence
          php bin/console escalation:seed-register
                                              Create the five D-06 route categories, empty and inactive

        user:create prints a generated password once when --password is omitted.
        outbox:dispatch is meant to run from cron, never from a web request.
        outbox:receipt records that the provider reported delivery; it sends nothing,
        and refuses an id belonging to a message this system never handed over.
        demo:seed fills an empty development database with fictional records and
        refuses to run in production. It prints one password for every demo account.

        TXT;
        exit($command === 'help' ? 0 : 1);
}

/**
 * Import the §17.1–§17.4 service pages (§17.5) and the three HR-WEB-CONTENT-R1-001 policy pages
 * as drafts.
 *
 * This is an import, not a publish. It exists so the approved words live in a
 * row that carries their provenance instead of in a template, and it refuses to
 * invent the fields the package does not supply: a page whose clinical reviewer,
 * approver or dates are unknown is imported as a draft that says so, and the
 * publish command will name them rather than quietly letting the page through.
 *
 * Idempotent on (content key, version, language). Running it twice imports nothing the second
 * time, but it is not a no-op: an existing *draft* is rewritten from the source, which is how a
 * corrected paragraph on a page that has no dates yet reaches the row it will eventually be
 * published from. A *published* row is never touched — an import must not rewrite words under an
 * approval someone signed.
 */
function syncContent(Application $app): int
{
    $source = dirname(__DIR__) . '/database/content/service-pages.php';

    if (!is_file($source)) {
        fwrite(STDERR, "Content source not found: {$source}\n");
        return 1;
    }

    $pages = require $source;

    if (!is_array($pages)) {
        fwrite(STDERR, "Content source did not return a list of pages.\n");
        return 1;
    }

    // The three public policy pages are a separate document and a separate file. They import
    // the same way and land as drafts; without approver and dates they cannot be published,
    // which is deliberate while those values are outstanding.
    $policySource = dirname(__DIR__) . '/database/content/policy-pages.php';

    if (is_file($policySource)) {
        $policyPages = require $policySource;

        if (is_array($policyPages)) {
            $pages = array_merge($pages, $policyPages);
        }
    }

    $repository = $app->content();
    $contentSource = new ContentSource();
    $now = gmdate('Y-m-d H:i:s');
    $created = 0;
    $existing = 0;
    $failures = 0;
    $unpublishable = 0;

    foreach ($pages as $index => $page) {
        if (!is_array($page)) {
            fwrite(STDERR, "Entry {$index} is not a page.\n");
            $failures++;
            continue;
        }

        // Decoded from a JSON object, so the keys are strings; `toDraft()` validates every
        // required key and refuses the entry if one is missing, which is what makes the
        // annotation true rather than hopeful.
        /** @var array<string, mixed> $page */
        try {
            $draft = $contentSource->toDraft($page, $now);
        } catch (ValidationException $exception) {
            fwrite(STDERR, "Entry {$index}: " . implode(' ', $exception->errors) . "\n");
            $failures++;
            continue;
        }

        $key = $draft->contentKey;
        $version = $draft->version;
        $language = $draft->language;

        $already = $repository->findVersion($key, $version, $language);

        if ($already !== null) {
            $existing++;
            // A draft is brought in line with the source, so that supplying a
            // reviewer or a date later actually lands somewhere. A published row is
            // left alone: an import must not rewrite words under an approval.
            $state = $repository->reconcileDraft($already->id, $draft, $now)
                ? 'synchronised'
                : 'left as it was (not a draft)';
            $already = $repository->find($already->id);
        } else {
            $already = $repository->find($repository->importDraft($draft, $now));
            $created++;
            $state = 'imported';
        }

        if ($already === null) {
            fwrite(STDERR, "{$key}: import did not produce a record.\n");
            $failures++;
            continue;
        }

        $missing = $already->missingForPublication();

        if ($missing !== []) {
            $unpublishable++;
        }
        $verdict = $missing === []
            ? 'ready to publish'
            : 'not publishable yet — no ' . implode(', no ', $missing);

        printf(
            "%-28s %-4s %-16s %-9s %s\n",
            $already->contentKey,
            $already->version,
            $state,
            $already->status->label(),
            $verdict,
        );
    }

    $present = $created + $existing;

    printf("\n%d imported, %d already present, %d failed.\n", $created, $existing, $failures);

    // "Nothing to do" was the wrong sentence: a run that imports nothing can still rewrite every
    // draft it finds, because an existing draft is brought in line with the source. An operator
    // reading "nothing to do" would have no reason to look at the rows, so it says what happened
    // instead of how many rows it happened to.
    if ($created === 0 && $existing > 0) {
        echo "No page was imported: all {$existing} in the source are already present. Any draft among\n";
        echo "them was synchronised with the source; published rows were left untouched.\n";
    }

    if ($unpublishable > 0) {
        echo "These are drafts. For {$unpublishable} of {$present}, §17.5 is still short of at least one\n";
        echo "provenance field — clinical reviewer, approver, approval date, effective date, review\n";
        echo "date — and no command here will invent them.\n";
    } else {
        echo "All {$present} are complete by §17.5 and ready to publish. This command does not publish:\n";
        echo "that is a dated, attributed act, performed at /admin/content.\n";
    }

    return $failures === 0 ? 0 : 1;
}

/**
 * Take a published page down, addressed by its content key.
 *
 * Publishing is an approval act and so is withdrawing, so this names the person who decided it
 * (`--actor`) and refuses to run without `--yes`. The alternative — a human logging into
 * /admin/content to click a button — is the right path when a human is available; this exists for
 * the case where a decision has already been recorded and the deployed state contradicts it, which
 * is exactly how HR-ENG-DEC-R1-003 D-03 arrived: the decision was made on 22 November and the page
 * stayed live.
 *
 * The page is not deleted. It keeps its words, its approver and its dates, and gains the time and
 * the person who took it down, because the question after a withdrawal is "what did we show, and
 * until when?"
 *
 * @param array<int, string> $options Arguments after the command name.
 */
function withdrawContent(Application $app, array $options): int
{
    $positional = array_values(array_filter(
        $options,
        static fn (string $option): bool => !str_starts_with($option, '--'),
    ));

    $actorEmail = '';
    $confirmed = false;

    foreach ($options as $option) {
        if (str_starts_with($option, '--actor=')) {
            $actorEmail = substr($option, 8);
        }

        if ($option === '--yes') {
            $confirmed = true;
        }
    }

    if ($positional === [] || $actorEmail === '') {
        fwrite(STDERR, "Usage: content:withdraw <content-key> --actor=<email> --yes\n");

        return 1;
    }

    if (!$confirmed) {
        fwrite(STDERR, "Refusing without --yes: this changes what the public site shows.\n");

        return 1;
    }

    $credentials = $app->users()->findCredentialsByEmail(Auth::normalizeEmail($actorEmail));

    if ($credentials === null) {
        fwrite(STDERR, "No such actor: {$actorEmail}\n");

        return 1;
    }

    if (!$credentials['user']->active) {
        fwrite(STDERR, "The actor account is suspended, so it cannot withdraw anything.\n");

        return 1;
    }

    $actor = new Actor($credentials['user']->id, $credentials['user']->role);
    $withdraw = new WithdrawContent($app->content());

    try {
        $page = $withdraw->executeByKey($actor, $positional[0]);
    } catch (AuthorisationException $exception) {
        fwrite(STDERR, "{$credentials['user']->email} may not withdraw content: " . $exception->getMessage() . "\n");

        return 1;
    } catch (ValidationException $exception) {
        fwrite(STDERR, implode(' ', $exception->errors) . "\n");

        return 1;
    }

    printf(
        "Withdrawn: %s version %s (%s), by %s at %s.\n",
        $page->contentKey,
        $page->version,
        $page->language,
        $credentials['user']->email,
        $page->withdrawnAtUtc ?? 'unknown',
    );
    echo "The record is kept. The public route now answers as though the page does not exist.\n";

    return 0;
}

/**
 * Put D-06's five route categories into the register, empty and inactive.
 *
 * This is the one console command that writes rows nobody signed, so it is also the most
 * constrained: it creates categories, never contacts, and an existing category is left untouched.
 * Operations fills the rows in through /admin/escalation-routes, where recording a contact and
 * verifying that somebody answers it are two separate acts.
 *
 * It prints what it found rather than what it did, because the interesting case is running it twice:
 * the second run must report five existing and create nothing.
 */
function seedEscalationRegister(Application $app): int
{
    $seeder = new SeedEscalationRegister($app->escalationRoutes());
    $result = $seeder->execute();

    foreach ($result['created'] as $kind) {
        printf("%-18s %-24s created, inactive, no contact\n", $kind->routeKey(), $kind->receivingRole());
    }

    foreach ($result['existing'] as $kind) {
        printf("%-18s %-24s already present, left as it is\n", $kind->routeKey(), $kind->receivingRole());
    }

    printf(
        "\n%d created, %d already present. Urgent escalation stays disabled until the primary,\n"
        . "secondary and receiving-team routes are recorded and verified.\n",
        count($result['created']),
        count($result['existing']),
    );

    return 0;
}

/**
 * Report whether the backup key is held the way D-08 requires (custody, not cryptography).
 *
 * Exit 1 on any failed check so a cron entry or a release script can act on it, 0 when the only
 * findings are warnings — a missing recovery copy is a warning because it cannot be created from this
 * host, and pretending otherwise would make the command always fail and therefore never be read.
 */
function checkBackupKey(Application $app): int
{
    $config = new \App\Bootstrap\Config(dirname(__DIR__));
    $custody = new \App\Support\KeyCustody(
        $config->publicRoot(),
        $config->backupDirectory(),
        is_file($config->envFile()) ? $config->envFile() : null,
        $config->backupRecoveryKeyFile(),
        $config->backupEncryptionKey() !== null,
    );

    foreach ($custody->checks() as $check) {
        printf("[%-4s] %-26s %s\n", strtoupper($check['state']), $check['check'], $check['detail']);
    }

    if ($custody->hasFailure()) {
        echo PHP_EOL . 'At least one D-08 custody control fails; see docs/operations/backup-key-custody.md.' . PHP_EOL;

        return 1;
    }

    echo PHP_EOL . 'No D-08 custody control fails. Warnings still need a person: see the custody record.' . PHP_EOL;

    return 0;
}

/**
 * Print the D-10 credential register: who owns each secret, when it was last rotated, and what is due.
 *
 * Exit 1 on an overdue or impossible rotation date, and — with `--require-recorded` — on any secret
 * that has no rotation on record or no named custodian. Without the flag those are warnings, because
 * every credential in this system is currently unrecorded: a command that failed on its first line
 * every run is a command an operator learns to ignore, which is the opposite of a control.
 *
 * The archive question is answered here rather than left to prose: D-10 forbids retiring a backup key
 * while archives it encrypted are still within retention, and the archives are on disk with their
 * timestamps in their names.
 *
 * @param list<string> $options
 */
function reportCredentials(Application $app, array $options): int
{
    $config = new \App\Bootstrap\Config(dirname(__DIR__));
    $path = $config->root('database/operations/credential-inventory.php');

    try {
        $inventory = \App\Support\CredentialInventory::fromFile($path);
    } catch (\InvalidArgumentException $invalid) {
        fwrite(STDERR, $invalid->getMessage() . PHP_EOL);

        return 1;
    }

    $today = gmdate('Y-m-d');

    foreach ($options as $option) {
        if (str_starts_with($option, '--today=')) {
            $today = substr($option, strlen('--today='));
        }
    }

    try {
        $report = $inventory->report($today, configuredEnvKeys($inventory->secretEnvKeys()));
    } catch (\InvalidArgumentException $invalid) {
        fwrite(STDERR, $invalid->getMessage() . PHP_EOL);

        return 1;
    }

    echo 'Credential register — HR-ENG-DEC-R1-003 D-10, ' . $config->root('') . PHP_EOL;
    echo 'Rotation interval: ' . \App\Support\CredentialInventory::ROTATION_INTERVAL_DAYS
        . ' days, as at ' . $today . ' (UTC)' . PHP_EOL . PHP_EOL;

    foreach ($report as $row) {
        $owner = $row['custodian'] === null
            ? $row['office'] . ' (custodian unassigned)'
            : $row['office'] . ' (' . $row['custodian'] . ')';

        printf(
            "[%-12s] %-24s %-12s %-40s %s\n",
            strtoupper($row['state']),
            $row['key'],
            $row['kind'],
            $owner,
            $row['detail'],
        );
    }

    $backupKeySection = backupKeySection($config, $inventory, $today);

    if ($backupKeySection !== []) {
        echo PHP_EOL . 'Backup key and the archives it unlocks' . PHP_EOL;

        foreach ($backupKeySection as $line) {
            echo '  ' . $line . PHP_EOL;
        }
    }

    $requireRecorded = in_array('--require-recorded', $options, true);

    echo PHP_EOL;

    if ($inventory->hasFailure($report, $requireRecorded)) {
        echo 'The register has findings that must be closed before a production release.';

        if (!$requireRecorded) {
            echo ' Run with --require-recorded to fail on an unrecorded rotation or an unnamed';
            echo ' custodian as well as on an overdue one.';
        }

        echo PHP_EOL;

        return 1;
    }

    echo 'Nothing is overdue. A rotation with no date on record is still a gap rather than a pass:';
    echo ' see docs/operations/credential-rotation.md.' . PHP_EOL;

    return 0;
}

/**
 * Print §3.2's seven release gates against what this host can observe.
 *
 * Exit 1 while any gate is not met. That is the true state today and it is the intended state: the
 * command exists so the number is visible and the responsible authority is named per row, not so it
 * can be made green. Nothing here approves anything, and no flag turns a gate green — see §3.3, which
 * says no item in the record authorises release by itself.
 *
 * @param list<string> $options
 */
function reportReleaseGates(Application $app, array $options): int
{
    $report = (new \App\Domain\ReleaseGates(collectReleaseGateFacts($app)))->assess();

    if (in_array('--json', $options, true)) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . PHP_EOL;

        return \App\Domain\ReleaseGates::blockedCount($report) === 0 ? 0 : 1;
    }

    echo 'Release gates — HR-ENG-DEC-R1-003 §3.2, ' . count($report) . ' gates' . PHP_EOL . PHP_EOL;

    foreach ($report as $gate) {
        printf("[%-7s] %-36s %s\n", strtoupper($gate['state']), $gate['name'], $gate['authority']);
        echo '          evidence: ' . $gate['evidence'] . PHP_EOL;
        echo '          ' . $gate['detail'] . PHP_EOL . PHP_EOL;
    }

    $blocked = \App\Domain\ReleaseGates::blockedCount($report);

    if ($blocked === 0) {
        echo 'All seven gates are met. §3.3 still requires documented release authorisation from the'
            . ' responsible owners: this command reports, it does not approve.' . PHP_EOL;

        return 0;
    }

    echo $blocked . ' of ' . count($report) . ' gates are not met. Each names the authority that owns it;'
        . ' no flag or environment variable here can close one.' . PHP_EOL;

    return 1;
}

/**
 * The facts behind `release:gates`, gathered in one place.
 *
 * Three of them are deliberately null. `restoreDrillRecorded` and `appointmentsNamed` describe records
 * that do not exist — a drill prints its verdict and stores none, and the operating model names no
 * individuals — so the honest fact is "unobservable", not "false". A false would read as "checked and
 * absent", which is a different and stronger claim.
 */
function collectReleaseGateFacts(Application $app): \App\Domain\ReleaseGateFacts
{
    $pdo = $app->pdo();
    $config = new \App\Bootstrap\Config(dirname(__DIR__));

    // "Approved and published", not "in force". D-01's evidence is an approver, an approval date and a
    // publication — the effective date determines when a reader can see the page, which is a separate
    // question and, for this release, a date in December. Counting `effective_on <= CURDATE()` here
    // made a correctly approved set read as unmet until the new year.
    $policy = ['total' => 0, 'published' => 0];
    $statement = $pdo->query(
        "SELECT content_key,
                MAX(
                    status = 'published'
                    AND effective_on IS NOT NULL
                    AND approver IS NOT NULL
                    AND approved_on IS NOT NULL
                ) AS is_live
           FROM published_content
          WHERE content_key LIKE 'policy.%'
          GROUP BY content_key"
    );

    if ($statement !== false) {
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $policy['total']++;

            $live = $row['is_live'] ?? 0;

            if (is_numeric($live) && (int) $live === 1) {
                $policy['published']++;
            }
        }
    }

    $intussusception = $pdo->query(
        "SELECT COUNT(*) FROM published_content
          WHERE content_key = 'service.intussusception' AND status = 'published'"
    );
    $publishedCount = $intussusception === false ? 0 : $intussusception->fetchColumn();
    $intussusceptionPublished = is_numeric($publishedCount) && (int) $publishedCount > 0;

    $routes = ['total' => 0, 'verified' => 0];
    $statement = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(is_active = 1 AND verified_at IS NOT NULL
                    AND contact_name <> '' AND contact_phone <> '') AS verified
           FROM escalation_routes"
    );

    if ($statement !== false) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            $total = $row['total'] ?? 0;
            $verified = $row['verified'] ?? 0;

            $routes['total'] = is_numeric($total) ? (int) $total : 0;
            $routes['verified'] = is_numeric($verified) ? (int) $verified : 0;
        }
    }

    $archives = 0;
    $directory = $config->backupDirectory();

    if (is_dir($directory)) {
        foreach (scandir($directory) ?: [] as $entry) {
            if (preg_match('/\Abooking-\d{8}-\d{6}(?:-\d+)?\.sql\.gz\.enc\z/', $entry) === 1) {
                $archives++;
            }
        }
    }

    $credentials = ['total' => 0, 'rotated' => 0, 'named' => 0];
    $registerPath = $config->root('database/operations/credential-inventory.php');

    if (is_file($registerPath)) {
        $inventory = \App\Support\CredentialInventory::fromFile($registerPath);

        foreach ($inventory->credentials() as $entry) {
            $credentials['total']++;

            if ($entry['rotated_on'] !== null) {
                $credentials['rotated']++;
            }

            if ($entry['custodian'] !== null) {
                $credentials['named']++;
            }
        }
    }

    // The number of policy pages is a property of the source, not of what happens to be in this
    // database: a development schema that a test has swept should not make the gate look closer to
    // met than the release candidate is. Published pages still come from the database, because
    // publication is a database fact.
    $policySource = $config->root('database/content/policy-pages.php');

    if (is_file($policySource)) {
        /** @var mixed $policyPages */
        $policyPages = require $policySource;

        if (is_array($policyPages)) {
            $policy['total'] = max($policy['total'], count($policyPages));
        }
    }

    $recovery = $config->backupRecoveryKeyFile();

    return new \App\Domain\ReleaseGateFacts(
        policyPagesTotal: $policy['total'],
        policyPagesPublished: $policy['published'],
        retentionActive: $config->retentionScheduleActive(),
        intussusceptionPublished: $intussusceptionPublished,
        escalationRoutesTotal: $routes['total'],
        escalationRoutesVerified: $routes['verified'],
        appointmentsDefined: count(\App\Domain\OperationalAppointment::cases()),
        appointmentsNamed: null,
        backupKeyConfigured: $config->backupEncryptionKey() !== null,
        encryptedArchives: $archives,
        restoreDrillRecorded: null,
        credentialsTotal: $credentials['total'],
        credentialsWithRotation: $credentials['rotated'],
        credentialsWithCustodian: $credentials['named'],
        recoveryKeyPresent: $recovery !== null && is_file($recovery),
    );
}

/**
 * @param list<string> $keys
 *
 * @return list<string> the keys that currently hold a non-empty value
 */
function configuredEnvKeys(array $keys): array
{
    $set = [];

    foreach ($keys as $key) {
        $value = \App\Bootstrap\Env::get($key);

        if ($value !== null && trim($value) !== '') {
            $set[] = $key;
        }
    }

    return $set;
}

/**
 * The D-10 archive rule, stated against the files that are actually on disk.
 *
 * @return list<string>
 */
function backupKeySection(
    \App\Bootstrap\Config $config,
    \App\Support\CredentialInventory $inventory,
    string $today,
): array {
    $archives = [];
    $directory = $config->backupDirectory();

    if (!is_dir($directory)) {
        return ['The backup directory does not exist yet (' . $directory . '), so no archive depends on a key.'];
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if (preg_match('/\Abooking-\d{8}-\d{6}(?:-\d+)?\.sql\.gz(?:\.enc)?\z/', $entry) === 1) {
            $archives[] = $entry;
        }
    }

    $rotatedOn = null;

    foreach ($inventory->credentials() as $entry) {
        if ($entry['kind'] === 'backup-key') {
            $rotatedOn = $entry['rotated_on'];
        }
    }

    if ($archives === []) {
        return ['No archive is on disk, so there is nothing the current key has to stay for.'];
    }

    $lines = [count($archives) . ' archive(s) on disk under ' . $directory . '.'];

    if ($rotatedOn === null) {
        $lines[] = 'No backup-key rotation is on record. Any rotation must keep the previous key until';
        $lines[] = 'every archive above has expired or has been re-encrypted and verified (D-10).';

        return $lines;
    }

    $predating = \App\Support\CredentialInventory::archivesPredating($archives, $rotatedOn);

    if ($predating === []) {
        $lines[] = 'Every archive postdates the rotation recorded on ' . $rotatedOn
            . ', so nothing here depends on the previous key.';

        return $lines;
    }

    $lines[] = count($predating) . ' archive(s) predate the rotation recorded on ' . $rotatedOn
        . ' and were written under the previous key:';
    $lines[] = 'oldest: ' . $predating[0];
    $lines[] = 'The previous key must not be destroyed while any of them is within retention (D-10).';

    if ($today < $rotatedOn) {
        $lines[] = 'The recorded rotation is dated in the future; check the register.';
    }

    return $lines;
}

function doctor(Application $app): int
{
    $failures = 0;

    $report = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
        if (!$ok) {
            $failures++;
        }
        printf("[%s] %-18s %s\n", $ok ? ' OK ' : 'FAIL', $label, $detail);
    };

    // Compared against a value rather than a literal 80300: `composer.json` already
    // requires PHP ^8.3, so a literal test is dead code that PHPStan rightly flags —
    // and a diagnostic that cannot fail is not a diagnostic.
    $requiredPhp = '8.3.0';
    $report('php', version_compare(PHP_VERSION, $requiredPhp, '>='), PHP_VERSION);

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl'] as $extension) {
        $report('ext-' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'missing');
    }

    $envFile = $app->root('.env');
    $report('.env', is_file($envFile), is_file($envFile) ? $envFile : 'copy .env.example to .env');

    $config = $app->config()->database();
    $report(
        'db-config',
        $config['database'] !== '' && $config['username'] !== '',
        $config['database'] . ' @ ' . $config['host'],
    );

    try {
        $pdo = $app->pdo();
        // `query()` returns false when the statement fails, which is not null — the
        // nullsafe operator did not cover it, and the next line would have fataled
        // instead of reporting the database as unreachable.
        $statement = $pdo->query('SELECT VERSION()');
        $version = $statement === false ? null : $statement->fetchColumn();
        $report('db-connect', true, is_string($version) ? $version : 'connected');

        $migrator = new Migrator($pdo, $app->root('database/migrations'));
        $pending = 0;
        foreach ($migrator->status() as $migration) {
            if (!$migration['applied']) {
                $pending++;
            } elseif (!$migration['checksum_ok']) {
                $report('migration-' . $migration['version'], false, 'checksum drift');
            }
        }
        $report('migrations', $pending === 0, $pending === 0 ? 'up to date' : $pending . ' pending');
    } catch (Throwable $exception) {
        $report('db-connect', false, $exception->getMessage());
    }

    echo PHP_EOL . ($failures === 0 ? "Environment looks ready.\n" : $failures . " check(s) failed.\n");

    return $failures === 0 ? 0 : 1;
}

function runMigrations(Application $app, bool $dryRun): int
{
    try {
        $migrator = new Migrator($app->pdo(), $app->root('database/migrations'));
        $applied = $migrator->migrate($dryRun);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
        fwrite(STDERR, "MySQL DDL may have partially applied. Inspect the schema before retrying.\n");

        return 1;
    }

    if ($applied === []) {
        echo "Nothing to apply.\n";

        return 0;
    }

    foreach ($applied as $name) {
        echo ($dryRun ? 'would apply ' : 'applied ') . $name . PHP_EOL;
    }

    return 0;
}

function migrationStatus(Application $app): int
{
    try {
        $migrator = new Migrator($app->pdo(), $app->root('database/migrations'));
        $rows = $migrator->status();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cannot read migration status: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if ($rows === []) {
        echo "No migration files found.\n";

        return 0;
    }

    printf("%-8s %-40s %-9s %s\n", 'VERSION', 'FILE', 'APPLIED', 'CHECKSUM');
    foreach ($rows as $row) {
        printf(
            "%-8s %-40s %-9s %s\n",
            $row['version'],
            $row['name'],
            $row['applied'] ? 'yes' : 'no',
            $row['checksum_ok'] ? 'ok' : 'DRIFT',
        );
    }

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function showBooking(Application $app, array $options): int
{
    $reference = firstArgument($options);
    if ($reference === '') {
        fwrite(STDERR, "Usage: booking:show <reference>\n");

        return 1;
    }

    try {
        $booking = $app->bookings()->find($reference);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cannot read booking: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if ($booking === null) {
        fwrite(STDERR, "No booking with reference {$reference}.\n");

        return 1;
    }

    printf("reference : %s\n", $booking->reference);
    printf("status    : %s\n", $booking->status->value);
    printf("when (UTC): %s\n", $booking->scheduledAtUtc);
    printf("service   : %s\n", $booking->service);
    printf("name      : %s\n", $booking->patientName);
    printf("phone     : %s\n", $booking->patientPhone);

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function changeStatus(Application $app, string $action, array $options): int
{
    $reference = firstArgument($options);
    $actor = 'cli';
    $reason = null;

    foreach ($options as $option) {
        if (str_starts_with($option, '--actor=')) {
            $actor = substr($option, 8);
        }
        if (str_starts_with($option, '--reason=')) {
            $reason = substr($option, 9);
        }
    }

    if ($reference === '') {
        fwrite(STDERR, "Usage: booking:{$action} <reference>\n");

        return 1;
    }

    try {
        $service = $app->bookings();
        $booking = match ($action) {
            'confirm' => $service->confirm($reference, $actor),
            'cancel' => $service->cancel($reference, $actor, $reason),
            'complete' => $service->complete($reference, $actor),
        };
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);

        return 1;
    }

    printf("%s  →  %s\n", $booking->reference, $booking->status->value);

    if ($action === 'cancel') {
        echo "slot released\n";
    }

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function firstArgument(array $options): string
{
    foreach ($options as $option) {
        if (!str_starts_with($option, '--')) {
            return trim($option);
        }
    }

    return '';
}

function listOutbox(Application $app): int
{
    try {
        $messages = $app->outbox()->recent(20);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cannot read the outbox: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if ($messages === []) {
        echo "Outbox is empty.\n";

        return 0;
    }

    printf(
        "%-4s %-22s %-8s %-9s %-8s %-19s %s\n",
        'ID',
        'TEMPLATE',
        'CHANNEL',
        'STATUS',
        'ATTEMPTS',
        'DELIVERED',
        'RECIPIENT',
    );
    foreach ($messages as $message) {
        printf(
            "%-4d %-22s %-8s %-9s %-8d %-19s %s\n",
            $message->id,
            $message->template->value,
            $message->channel->value,
            $message->status->value,
            $message->attempts,
            // "unknown" rather than blank: no receipt is a fact, and a blank cell
            // reads as a column that failed to print.
            $message->deliveredAt ?? 'unknown',
            '***' . substr($message->recipient, -3),
        );
    }

    return 0;
}

/**
 * Record a provider's delivery report against the message it concerns.
 *
 * There is no inbound callback endpoint in this release, so the report is entered
 * here: by an operator reading the provider's log, or by whatever job is written
 * when the provider's webhook is configured. What matters is that the row can now
 * hold the fact, and that acceptance (`sent_at`) stays distinguishable from it.
 */
/**
 * @param array<int, string> $options Arguments after the command name.
 */
function recordReceipt(Application $app, array $options): int
{
    $positional = array_values(array_filter(
        $options,
        static fn (string $option): bool => !str_starts_with($option, '--'),
    ));

    $providerMessageId = trim($positional[0] ?? '');

    if ($providerMessageId === '') {
        fwrite(STDERR, "Usage: outbox:receipt <provider-message-id> [--at=\"YYYY-MM-DD HH:MM:SS\"]\n");

        return 1;
    }

    $at = gmdate('Y-m-d H:i:s');
    foreach ($options as $option) {
        if (str_starts_with($option, '--at=')) {
            $at = trim(substr($option, 5), " \"'");
        }
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) !== 1) {
        fwrite(STDERR, "The --at value must be a UTC timestamp like \"2026-11-13 09:00:00\".\n");

        return 1;
    }

    try {
        $recorded = $app->outbox()->markDelivered($providerMessageId, $at);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cannot record the receipt: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if (!$recorded) {
        fwrite(STDERR, sprintf(
            "No sent message carries the provider id %s. A receipt is only recorded for a message "
                . "this system handed over; check the id against the provider's log.\n",
            $providerMessageId,
        ));

        return 1;
    }

    echo "Delivery recorded against {$providerMessageId} at {$at} (UTC).\n";

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function dispatchOutbox(Application $app, array $options): int
{
    $limit = 20;
    foreach ($options as $option) {
        if (str_starts_with($option, '--limit=')) {
            $limit = max(1, min((int) substr($option, 8), 100));
        }
    }

    if ($app->config()->notificationDriver() === 'http' && $app->config()->notificationUrl() === '') {
        fwrite(STDERR, "NOTIFY_DRIVER=http but NOTIFY_HTTP_URL is empty. Set it in .env.\n");

        return 1;
    }

    try {
        $result = $app->dispatcher()->dispatch($limit);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Dispatch failed: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    printf(
        "sent %d, retrying %d, dead %d\n",
        $result['sent'],
        $result['retrying'],
        $result['dead'],
    );

    return $result['dead'] > 0 ? 1 : 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function createUser(Application $app, array $options): int
{
    $positional = array_values(array_filter(
        $options,
        static fn (string $option): bool => !str_starts_with($option, '--'),
    ));

    if (count($positional) < 3) {
        fwrite(STDERR, "Usage: user:create <email> <name> <admin|staff> [--password=…]\n");

        return 1;
    }

    $email = Auth::normalizeEmail($positional[0]);
    $name = trim($positional[1]);
    $role = Role::tryFrom($positional[2]);

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "That email address is not valid.\n");

        return 1;
    }

    if ($name === '') {
        fwrite(STDERR, "A name is required.\n");

        return 1;
    }

    if ($role === null) {
        fwrite(STDERR, "Role must be admin or staff.\n");

        return 1;
    }

    $password = null;
    foreach ($options as $option) {
        if (str_starts_with($option, '--password=')) {
            $password = substr($option, 11);
        }
    }

    $generated = $password === null;
    $password ??= generatePassword();

    $hasher = new PasswordHasher();
    $errors = $hasher->policyErrors($password);
    if ($errors !== []) {
        fwrite(STDERR, implode(' ', $errors) . PHP_EOL);

        return 1;
    }

    try {
        $id = $app->users()->create($email, $name, $role, $hasher->hash($password));
    } catch (\PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            fwrite(STDERR, "A user with that email already exists.\n");

            return 1;
        }

        fwrite(STDERR, 'Could not create the user: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    printf("created  #%d  %s  (%s)\n", $id, $email, $role->value);

    if ($generated) {
        echo "password: {$password}\n";
        echo "This is shown once; store it in the password manager now.\n";
    }

    return 0;
}

function listUsers(Application $app): int
{
    try {
        $users = $app->users()->all();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cannot read users: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if ($users === []) {
        echo "No users yet. Create one with: php bin/console user:create <email> <name> admin\n";

        return 0;
    }

    printf("%-4s %-32s %-8s %-7s %s\n", 'ID', 'EMAIL', 'ROLE', 'STATE', 'LAST LOGIN');
    foreach ($users as $user) {
        printf(
            "%-4d %-32s %-8s %-7s %s\n",
            $user->id,
            $user->email,
            $user->role->value,
            $user->active ? 'active' : 'disabled',
            $user->lastLoginAt ?? 'never',
        );
    }

    return 0;
}

/**
 * Suspend or restore an account. `--actor` is required rather than defaulted, because
 * a suspension has to name the person who decided it — "cli" is not a name an auditor
 * can follow up.
 *
 * @param array<int, string> $options Arguments after the command name.
 */
function changeUserAccess(Application $app, bool $active, array $options): int
{
    $verb = $active ? 'reactivate' : 'deactivate';
    $positional = array_values(array_filter(
        $options,
        static fn (string $option): bool => !str_starts_with($option, '--'),
    ));

    if ($positional === []) {
        fwrite(STDERR, "Usage: user:$verb <email> --actor=<email>\n");

        return 1;
    }

    $actorEmail = '';
    foreach ($options as $option) {
        if (str_starts_with($option, '--actor=')) {
            $actorEmail = substr($option, 8);
        }
    }

    if ($actorEmail === '') {
        fwrite(STDERR, "--actor=<email> is required: a suspension has to name who decided it.\n");

        return 1;
    }

    $actorCredentials = $app->users()->findCredentialsByEmail(Auth::normalizeEmail($actorEmail));

    if ($actorCredentials === null) {
        fwrite(STDERR, "No such actor: $actorEmail\n");

        return 1;
    }

    if (!$actorCredentials['user']->active) {
        // A suspended account cannot suspend anybody; that is the point of suspending it.
        fwrite(STDERR, "The actor account is suspended.\n");

        return 1;
    }

    $targetCredentials = $app->users()->findCredentialsByEmail(Auth::normalizeEmail($positional[0]));

    if ($targetCredentials === null) {
        fwrite(STDERR, "No such user: {$positional[0]}\n");

        return 1;
    }

    $actor = new Actor($actorCredentials['user']->id, $actorCredentials['user']->role);
    $command = new ChangeUserAccess($app->users(), $app->auditTrail(), $app->transaction());

    try {
        $user = $command->execute($actor, $targetCredentials['user']->id, $active);
    } catch (AuthorisationException | UserNotFoundException $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);

        return 1;
    }

    printf("%s  #%d  %s  (%s)\n", $active ? 'restored' : 'suspended', $user->id, $user->email, $user->role->value);

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function issueResetToken(Application $app, array $options): int
{
    $email = firstArgument($options);
    if ($email === '') {
        fwrite(STDERR, "Usage: user:reset-token <email>\n");

        return 1;
    }

    $link = $app->auth()->issueResetToken($email);
    if ($link === null) {
        fwrite(STDERR, "No active user with that email.\n");

        return 1;
    }

    // The break-glass path. A person who can run this already has the database;
    // printing the link to a terminal they control adds no exposure that their
    // access did not already carry, and it works when mail does not.
    echo "One-time reset link, valid for 60 minutes (expires {$link->expiresAtUtc} UTC):\n";
    echo "/password/reset/{$link->token}\n";

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function createBackup(Application $app, array $options): int
{
    // `--key` is handled before the application boots (see the dispatch block at the top of this
    // file), because it must work on an installation that has no database yet. Reaching here with
    // it set means the dispatch was changed; say so rather than print a key from the wrong place.
    if (in_array('--key', $options, true)) {
        fwrite(STDERR, "db:backup --key is answered before boot; this path should be unreachable.\n");

        return 1;
    }

    $prune = null;
    $requireEncryption = false;
    foreach ($options as $option) {
        if (str_starts_with($option, '--prune=')) {
            $prune = max(1, (int) substr($option, 8));
        }
        if ($option === '--require-encryption') {
            $requireEncryption = true;
        }
    }

    if ($requireEncryption && $app->config()->backupEncryptionKey() === null) {
        fwrite(STDERR, "Backup refused: --require-encryption was given but BACKUP_ENCRYPTION_KEY is not set.\n");
        fwrite(STDERR, "No file was written. Generate a key with: php bin/console db:backup --key\n");

        // D-09: a scheduled backup that fails must leave something an operator can act on. The exit
        // code is for the cron wrapper; this record is for the person who reads `diag` tomorrow.
        BackupAlert::record(
            $app->backups()->directory(),
            'key-missing',
            '--require-encryption was given but BACKUP_ENCRYPTION_KEY is empty or unset',
        );

        // 2 rather than 1: "refused for want of a key" is a different condition from "the dump
        // failed", and a cron wrapper that only sees an exit code should be able to tell them apart.
        return 2;
    }

    try {
        $file = $app->backups()->create();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Backup failed: ' . $exception->getMessage() . PHP_EOL);

        BackupAlert::record(
            $app->backups()->directory(),
            'dump-failed',
            $exception->getMessage(),
        );

        return 1;
    }

    printf(
        "written %s (%s, %d tables, %d rows, %s)\n",
        $file->name,
        $file->humanSize(),
        $file->tables,
        $file->rows,
        $file->encrypted ? 'encrypted' : 'NOT ENCRYPTED',
    );

    if (!$file->encrypted) {
        fwrite(STDERR, "WARNING: this dump is unencrypted. It is a complete copy of the clinical database,\n");
        fwrite(STDERR, "readable by anyone who obtains the file. §9 of HR-OPS-REF-R1-001 requires encrypted\n");
        fwrite(STDERR, "backups; set BACKUP_ENCRYPTION_KEY (php bin/console db:backup --key) to comply.\n");
    }

    try {
        if ($prune !== null) {
            printf("pruned %d old backup(s), keeping %d\n", $app->backups()->prune($prune), $prune);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Prune failed: ' . $exception->getMessage() . PHP_EOL);
    }

    return 0;
}

function listBackups(Application $app): int
{
    $files = $app->backups()->all(50);

    if ($files === []) {
        echo "No backups yet. Run: php bin/console db:backup\n";

        return 0;
    }

    printf("%-38s %-10s %-5s %s\n", 'FILE', 'SIZE', 'ENC', 'WRITTEN (UTC)');
    foreach ($files as $file) {
        printf(
            "%-38s %-10s %-5s %s\n",
            $file->name,
            $file->humanSize(),
            $file->encrypted ? 'yes' : 'no',
            gmdate('Y-m-d H:i', $file->modifiedAt),
        );
    }

    $plain = array_filter($files, static fn ($file): bool => !$file->encrypted);
    if ($plain !== []) {
        printf(
            "\n%d of %d listed backup(s) are unencrypted and remain so until `prune` removes them.\n",
            count($plain),
            count($files),
        );
    }

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function restoreDrill(Application $app, array $options): int
{
    $path = '';
    $target = null;
    $keep = false;

    foreach ($options as $option) {
        if (str_starts_with($option, '--file=')) {
            $path = substr($option, 7);
        }
        if (str_starts_with($option, '--database=')) {
            $target = substr($option, 11);
        }
        if ($option === '--keep') {
            $keep = true;
        }
    }

    try {
        $file = $path === '' ? $app->backups()->latest() : $app->backups()->open($path);

        if ($file === null) {
            fwrite(STDERR, "No backup found. Run: php bin/console db:backup\n");

            return 1;
        }

        echo 'restoring ' . $file->name . ' (' . ($file->encrypted ? 'encrypted' : 'unencrypted') . ')' . PHP_EOL;
        $result = $app->backups()->verify($file, $target, $keep);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Restore drill failed: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    printf(
        "%s: %d tables, %d rows\n",
        $result['target'],
        $result['tables'],
        $result['rows'],
    );

    if ($result['mismatches'] !== []) {
        fwrite(STDERR, "Row counts do not match:\n");
        foreach ($result['mismatches'] as $mismatch) {
            fwrite(STDERR, '  ' . $mismatch . PHP_EOL);
        }

        return 1;
    }

    echo $result['dropped'] ? "scratch database dropped\n" : "scratch database left in place\n";
    echo "backup verified: the file restores to identical row counts\n";

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function reviewLogs(Application $app, array $options): int
{
    $level = 'error';
    $limit = 30;

    foreach ($options as $option) {
        if (str_starts_with($option, '--level=')) {
            $level = strtolower(substr($option, 8));
        }
        if (str_starts_with($option, '--limit=')) {
            $limit = max(1, min((int) substr($option, 8), 200));
        }
    }

    $records = $app->logReader()->recent($level, $limit);

    if ($records === []) {
        echo 'No ' . $level . ' entries in the retained logs.' . PHP_EOL;

        return 0;
    }

    foreach ($records as $record) {
        printf(
            "%s  %-7s %s  %s\n",
            str_replace('T', ' ', substr($record->timestamp, 0, 19)),
            strtoupper($record->level),
            $record->message,
            $record->contextLine(),
        );
    }

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function retentionService(Application $app, array $options): RetentionService
{
    $days = $app->config()->retentionDays();

    foreach ($options as $option) {
        if (str_starts_with($option, '--days=')) {
            $days = (int) substr($option, 7);
        }
    }

    return new RetentionService($app->retentionRepository(), new RetentionPolicy($days));
}

function retentionPlan(Application $app): int
{
    try {
        $plan = retentionService($app, [])->plan();
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);

        return 1;
    }

    $referrals = $app->referralRetention()->plan();

    printf("retention : %d days\n", $plan['days']);
    printf("cutoff    : %s (UTC)\n", $plan['cutoff']);
    printf("eligible  : %d booking(s) still holding personal data\n", $plan['eligible']);
    printf("referrals : %d referral(s) with documents on the same schedule\n", $referrals['eligible']);

    if ($plan['eligible'] > 0 || $referrals['eligible'] > 0) {
        echo PHP_EOL . "Run: php bin/console retention:purge --yes" . PHP_EOL;
    }

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
/**
 * D-02's per-class schedule, printed beside the window that actually runs.
 *
 * The point of this report is the gap, not the table. A reader who runs `retention:plan` should see
 * both what was adopted and what is executing, because the alternative — a schedule in a decision
 * document and a different number in the code — is how the retention period came to contradict the
 * privacy notice for three weeks (§23.2).
 *
 * Nothing is counted for the classes here. Counting what would be deleted under a schedule that has
 * not been validated would invite somebody to read the number as a plan of record; the table states
 * the adopted periods and the state of the gate, and says which classes are unmanaged today.
 */
function retentionScheduleReport(Application $app): void
{
    $schedule = $app->retentionSchedule();

    echo PHP_EOL . 'D-02 schedule by record class' . PHP_EOL;

    foreach ($schedule->classes() as $recordClass => $days) {
        printf("  %-32s %5d days\n", $recordClass, $days);
    }

    printf(
        "  %-32s %5d days max, %d copies\n",
        'encrypted_backups',
        $schedule->backupMaxAgeDays(),
        $schedule->backupCopyCap(),
    );

    if ($schedule->isActive()) {
        echo PHP_EOL . 'The schedule is ACTIVE: Legal validation is recorded and these periods run.' . PHP_EOL;

        return;
    }

    echo PHP_EOL . 'The schedule is NOT ACTIVE. ' . wordwrap($schedule->inactiveReason(), 100) . PHP_EOL;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function retentionPurge(Application $app, array $options): int
{
    $confirmed = in_array('--yes', $options, true);
    $batch = 500;

    foreach ($options as $option) {
        if (str_starts_with($option, '--limit=')) {
            $batch = max(1, min((int) substr($option, 8), 5000));
        }
    }

    try {
        $service = retentionService($app, $options);
        $referralService = $app->referralRetention();

        if (!$confirmed) {
            $plan = $service->plan();
            $referrals = $referralService->plan();
            printf(
                "dry run: %d booking(s) and %d referral(s) would be redacted (retention %d days, cutoff %s)\n",
                $plan['eligible'],
                $referrals['eligible'],
                $plan['days'],
                $plan['cutoff'],
            );
            echo "Nothing changed. Re-run with --yes to redact." . PHP_EOL;

            return 1;
        }

        $result = $service->purge('cli:retention', $batch);
        $documentResult = $referralService->purge('cli:retention', $batch);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Retention failed: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    printf(
        "redacted %d booking(s) in %d batch(es), cutoff %s\n",
        $result['redacted'],
        $result['batches'],
        $result['cutoff'],
    );
    printf(
        "redacted %d referral(s) and removed %d document file(s)\n",
        $documentResult['referrals'],
        $documentResult['documents'],
    );

    // The account-request queue is not clinical, so it does not follow the clinical
    // retention window: an applicant nobody answered is expired after 60 days, and
    // the counter rows behind the public form's rate cap after one day. Both are
    // hygiene, and both would otherwise grow without limit on an unattended host.
    $requestsExpired = $app->accountRequests()->expirePendingOlderThan(
        gmdate('Y-m-d H:i:s', time() - (60 * 86400)),
    );
    $attemptsRemoved = $app->accountRequestAttempts()->purgeOlderThan(
        gmdate('Y-m-d H:i:s', time() - 86400),
    );

    printf(
        "expired %d unanswered account request(s); removed %d rate-limit counter row(s)\n",
        $requestsExpired,
        $attemptsRemoved,
    );

    if ($requestsExpired > 0) {
        $app->auditTrail()->record(new AuditEntry(
            action: AuditAction::AccountRequestExpired,
            objectType: 'account_request',
            objectRef: 'batch',
            after: ['expired' => $requestsExpired, 'after_days' => 60],
        ));
    }

    if ($result['remaining'] || $documentResult['remaining']) {
        echo "More rows remain; run the command again." . PHP_EOL;
    }

    return 0;
}

/**
 * The referring-account queue, from the console.
 *
 * Exists so the queue is observable from cron's log rather than only in a browser:
 * an unanswered request is a clinician who cannot refer, and nobody sees a web page
 * they do not open.
 */
/**
 * @param array<int, string> $options Arguments after the command name.
 */
function listAccountRequests(Application $app, array $options): int
{
    $expire = in_array('--expire', $options, true);

    try {
        if ($expire) {
            $expired = $app->accountRequests()->expirePendingOlderThan(
                gmdate('Y-m-d H:i:s', time() - (60 * 86400)),
            );
            printf("expired %d request(s) older than 60 days\n", $expired);

            if ($expired > 0) {
                $app->auditTrail()->record(new AuditEntry(
                    action: AuditAction::AccountRequestExpired,
                    objectType: 'account_request',
                    objectRef: 'batch',
                    after: ['expired' => $expired, 'after_days' => 60],
                ));
            }
        }

        $pending = $app->accountRequests()->pending();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Requests unavailable: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if ($pending === []) {
        echo 'No account request is waiting for a decision.' . PHP_EOL;

        return 0;
    }

    printf("%d request(s) waiting:\n", count($pending));

    foreach ($pending as $request) {
        printf(
            "  %s  %-28s %-32s %s\n",
            $request->reference,
            $request->fullName,
            $request->practiceName,
            $request->createdAtUtc . ' UTC',
        );
    }

    echo PHP_EOL . 'Decide them at /admin/account-requests.' . PHP_EOL;

    return 0;
}

/**
 * @param array<int, string> $options Arguments after the command name.
 */
function demoSeed(Application $app, array $options): int
{
    $guard = new DemoSeedGuard($app->config()->environment());

    // The environment is checked before the password is generated, before the
    // database is opened, and before anything is printed that looks like progress.
    try {
        $guard->requirePermittedEnvironment();
    } catch (DemoSeedRefused $refusal) {
        fwrite(STDERR, $refusal->getMessage() . PHP_EOL);

        return 1;
    }

    $password = null;

    foreach ($options as $option) {
        if (str_starts_with($option, '--password=')) {
            $password = substr($option, 11);
        }
    }

    $confirmed = in_array('--yes', $options, true);

    if (!$confirmed) {
        if (!stream_isatty(STDIN)) {
            fwrite(STDERR, DemoSeedRefused::unconfirmed()->getMessage() . PHP_EOL);

            return 1;
        }

        echo 'This writes fictional patients into the database configured in .env.' . PHP_EOL;
        echo "Type 'seed' to continue: ";

        $answer = fgets(STDIN);
        $confirmed = is_string($answer) && trim($answer) === 'seed';

        if (!$confirmed) {
            echo 'Cancelled. Nothing was written.' . PHP_EOL;

            return 1;
        }
    }

    $generated = $password === null;
    $password ??= generatePassword();

    try {
        $report = (new DemoSeeder($app, $guard))->seed($password);
    } catch (DemoSeedRefused $refusal) {
        fwrite(STDERR, $refusal->getMessage() . PHP_EOL);

        return 1;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'The demo seed failed and rolled back: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if (!$report->seeded()) {
        echo 'The demo dataset is already present; nothing was changed.' . PHP_EOL;

        return 0;
    }

    printf(
        "seeded %d user(s), %d resource(s), %d referral(s), %d appointment(s), %d urgent case(s)\n",
        $report->users,
        $report->resources,
        $report->referrals,
        $report->appointments,
        $report->urgentCases,
    );

    echo "sign in as admin@demo.invalid, staff@demo.invalid, radiologist@demo.invalid," . PHP_EOL;
    echo "physician@demo.invalid or coordinator@demo.invalid" . PHP_EOL;

    if ($generated) {
        echo "password: {$password}" . PHP_EOL;
        echo 'Every demo account shares it. It is shown once and stored nowhere.' . PHP_EOL;
    }

    return 0;
}

function printDiagnostics(Application $app): int
{
    $failed = 0;

    try {
        $checks = $app->diagnostics()->checks();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Diagnostics unavailable: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    foreach ($checks as $check) {
        if ($check['state'] === 'fail') {
            $failed++;
        }
        printf("[%-4s] %-22s %s\n", strtoupper($check['state']), $check['label'], $check['value']);
    }

    echo PHP_EOL . ($failed === 0 ? "No failing checks.\n" : $failed . " check(s) failing.\n");

    return $failed === 0 ? 0 : 1;
}

function generatePassword(): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';

    for ($i = 0; $i < 20; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

function listBookings(Application $app): int
{
    try {
        $bookings = $app->bookings()->upcoming(20);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cannot read bookings: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }

    if ($bookings === []) {
        echo "No upcoming bookings.\n";

        return 0;
    }

    foreach ($bookings as $booking) {
        printf(
            "%s  %s  %-12s %s\n",
            $booking->reference,
            $booking->scheduledAtUtc,
            $booking->service,
            $booking->patientName,
        );
    }

    return 0;
}
