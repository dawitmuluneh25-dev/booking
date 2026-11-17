<?php

/**
 * Route table. Every route declares its own method and handler; there is no
 * implicit dispatch and no catch-all controller.
 */

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Controllers\AdminAuditController;
use App\Controllers\AdminController;
use App\Controllers\AdminDiagnosticsController;
use App\Controllers\AdminEscalationRouteController;
use App\Controllers\AdminLaboratoryController;
use App\Controllers\AdminHoursController;
use App\Controllers\AdminPhysicianController;
use App\Controllers\AdminResourceController;
use App\Controllers\AdminUserController;
use App\Controllers\AppointmentController;
use App\Controllers\AuthController;
use App\Controllers\AvailabilityController;
use App\Controllers\BookingController;
use App\Controllers\AdminContentController;
use App\Controllers\PolicyController;
use App\Controllers\ServiceController;
use App\Controllers\DocumentController;
use App\Controllers\HealthController;
use App\Controllers\ScheduleController;
use App\Controllers\ForgotPasswordController;
use App\Controllers\HomeController;
use App\Controllers\MyReferralsController;
use App\Controllers\NotificationController;
use App\Controllers\PasswordResetController;
use App\Controllers\PractitionerRegisterController;
use App\Controllers\ReferralController;
use App\Controllers\ReviewController;
use App\Controllers\UrgentController;
use App\Domain\Permission;
use App\Domain\RequestPasswordReset;
use App\Http\Request;
use App\Http\RouteAccess;

return static function (Application $app): void {
    $router = $app->router();

    $home = new HomeController($app->view(), $app->auth());
    $auth = new AuthController($app->view(), $app->auth(), $app->auditTrail());
    $reset = new PasswordResetController($app->view(), $app->auth());
    $notifications = new NotificationController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->notificationFeed(),
    );
    $forgot = new ForgotPasswordController(
        $app->view(),
        new RequestPasswordReset($app->auth(), $app->outbox(), $app->config()->baseUrl()),
    );
    $adminUsers = new AdminUserController(
        $app->view(),
        $app->users(),
        $app->auth(),
        $app->gate(),
        $app->createUserAccount(),
        $app->changeUserRole(),
    );
    $admin = new AdminController($app->view(), $app->auth(), $app->gate());

    $audit = new AdminAuditController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->audit(),
        $app->auditTrail(),
        $app->retentionRepository(),
    );

    $hours = new AdminHoursController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->calendar(),
    );

    $physicians = new AdminPhysicianController(
        $app->view(),
        $app->gate(),
        $app->physicians(),
        $app->verifyPhysician(),
        $app->config()->timezone(),
    );

    $resources = new AdminResourceController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->schedule(),
        $app->resourceBlocks(),
        $app->resourceAdmin(),
        $app->config()->timezone(),
    );

    $laboratories = new AdminLaboratoryController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->laboratories(),
        $app->addLaboratoryDestination(),
    );

    // The credential register a clinical approval is checked against (Decision 6,
    // AC-009). Read it as "what a verification is made of": who, against what
    // evidence, in force from when, until when, covering which procedures.
    $practitioners = new PractitionerRegisterController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->users(),
        $app->practitionerCredentials(),
        $app->procedures(),
        $app->recordPractitionerCredential(),
        $app->changeCredentialStatus(),
        $app->grantPractitionerPrivilege(),
        $app->endPractitionerPrivilege(),
        $app->config()->timezone(),
    );

    $content = new AdminContentController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->content(),
        $app->publishContent(),
        $app->withdrawContent(),
    );

    $diagnostics = new AdminDiagnosticsController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        static fn () => $app->diagnostics(),
    );

    $availability = new AvailabilityController(
        $app->view(),
        static fn () => $app->bookings(),
        $app->config()->timezone(),
        $app->auth(),
        $app->calendar(),
    );

    $booking = static fn (): BookingController => new BookingController(
        $app->view(),
        static fn () => $app->bookings(),
        $app->config()->timezone(),
        $app->auth(),
        $app->gate(),
    );

    $router->get('/', static fn (Request $request) => $home->index($request), RouteAccess::public('public home page'));

    $services = new ServiceController($app->view(), $app->auth(), $app->content());

    // §17 pages, written for somebody who has not signed in and carrying §17.5's
    // approver and review date from the content record rather than a template.
    $router->get(
        '/services',
        static fn (Request $request) => $services->index($request),
        RouteAccess::public('public service index'),
    );
    $router->get(
        '/services/{slug}',
        static fn (Request $request, array $params) => $services->show($request, $params),
        RouteAccess::public('public service page'),
    );

    $policy = new PolicyController($app->view(), $app->auth(), $app->content());

    // HR-WEB-CONTENT-R1-001. Each path names its content key explicitly rather than
    // deriving it from the URL, so a page cannot be reached under a key it is not.
    // Until a version is published with an approver and a review date these return 404.
    $router->get(
        '/privacy',
        static fn (Request $request) => $policy->show($request, 'policy.privacy'),
        RouteAccess::public('privacy notice'),
    );
    $router->get(
        '/booking-policy',
        static fn (Request $request) => $policy->show($request, 'policy.booking'),
        RouteAccess::public('booking policy'),
    );
    $router->get(
        '/referral-guidance',
        static fn (Request $request) => $policy->show($request, 'policy.referral'),
        RouteAccess::public('referral guidance'),
    );

    $router->get(
        '/login',
        static fn (Request $request) => $auth->showLogin($request),
        RouteAccess::public('sign-in form'),
    );
    $router->post(
        '/login',
        static fn (Request $request) => $auth->login($request),
        RouteAccess::public('sign-in submission'),
    );
    $router->get(
        '/notifications',
        static fn (Request $request) => $notifications->index($request),
        RouteAccess::permission(Permission::NotificationRead),
    );

    $router->post('/logout', static fn (Request $request) => $auth->logout($request), RouteAccess::authenticated());

    $router->get(
        '/password/reset/{token}',
        static fn (Request $request, array $params) => $reset->show($request, $params),
        RouteAccess::public('password reset link from email'),
    );
    $router->post(
        '/password/reset/{token}',
        static fn (Request $request, array $params) => $reset->update($request, $params),
        RouteAccess::public('password reset submission'),
    );

    // A person who cannot sign in cannot authenticate first, so the form that gets
    // them back in has to be public. It answers identically for every address.
    $router->get(
        '/password/forgot',
        static fn (Request $request) => $forgot->show($request),
        RouteAccess::public('request a password reset link'),
    );
    $router->post(
        '/password/forgot',
        static fn (Request $request) => $forgot->store($request),
        RouteAccess::public('password reset request submission'),
    );

    $router->get(
        '/availability',
        static fn (Request $request) => $availability->index($request),
        RouteAccess::public('availability before sign-in'),
    );

    $router->get(
        '/bookings',
        static fn (Request $request) => $booking()->index($request),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->get(
        '/bookings/new',
        static fn (Request $request) => $booking()->create($request),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->post(
        '/bookings',
        static fn (Request $request) => $booking()->store($request),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->get(
        '/bookings/{reference}',
        static fn (Request $request, array $params) => $booking()->show($request, $params),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->post(
        '/bookings/{reference}/confirm',
        static fn (Request $request, array $params) => $booking()->confirm($request, $params),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->post(
        '/bookings/{reference}/cancel',
        static fn (Request $request, array $params) => $booking()->cancel($request, $params),
        RouteAccess::permission(Permission::BookingsManage),
    );

    $router->get(
        '/admin',
        static fn (Request $request) => $admin->index($request),
        RouteAccess::permission(Permission::SystemMaintain),
    );
    $router->get(
        '/admin/users',
        static fn (Request $request) => $adminUsers->index($request),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->post(
        '/admin/users',
        static fn (Request $request) => $adminUsers->store($request),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->post(
        '/admin/users/role',
        static fn (Request $request) => $adminUsers->changeRole($request),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->get(
        '/admin/audit',
        static fn (Request $request) => $audit->index($request),
        RouteAccess::permission(Permission::SystemMaintain),
    );
    $router->get(
        '/admin/hours',
        static fn (Request $request) => $hours->index($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/hours',
        static fn (Request $request) => $hours->update($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->get(
        '/admin/physicians',
        static fn (Request $request) => $physicians->index($request),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->post(
        '/admin/physicians/{id}/verify',
        static fn (Request $request, array $params) => $physicians->verify($request, $params),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->post(
        '/admin/physicians/{id}/suspend',
        static fn (Request $request, array $params) => $physicians->suspend($request, $params),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->post(
        '/admin/physicians/{id}/reject',
        static fn (Request $request, array $params) => $physicians->reject($request, $params),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->get(
        '/admin/resources',
        static fn (Request $request) => $resources->index($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/resources',
        static fn (Request $request) => $resources->update($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/resources/create',
        static fn (Request $request) => $resources->store($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/resources/{id}',
        static fn (Request $request, array $params) => $resources->edit($request, $params),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/resources/{id}/week',
        static fn (Request $request, array $params) => $resources->saveWeek($request, $params),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    // The laboratory catalogue a specimen dispatch will reference (§19.1). There is
    // no delete route: a laboratory closes, because specimens already sent there
    // must keep naming where they went.
    $router->get(
        '/admin/laboratories',
        static fn (Request $request) => $laboratories->index($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/laboratories',
        static fn (Request $request) => $laboratories->store($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/laboratories/{id}',
        static fn (Request $request, array $params) => $laboratories->toggle($request, $params),
        RouteAccess::permission(Permission::ClinicConfigure),
    );

    $escalationRoutesAdmin = new AdminEscalationRouteController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->escalationRoutes(),
        $app->escalationReadiness(),
        $app->users(),
        $app->recordEscalationRoute(),
        $app->verifyEscalationRoute(),
        $app->retireEscalationRoute(),
        $app->config()->timezone(),
    );

    // The escalation roster (Decision 4). Operational configuration, so it sits with
    // the other operational settings rather than behind a clinical permission: the
    // decision says Operations verifies the arrangements, and nothing here decides
    // anything about a patient. There is no interval route, because there is no
    // interval.
    $router->get(
        '/admin/escalation-routes',
        static fn (Request $request) => $escalationRoutesAdmin->index($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/escalation-routes',
        static fn (Request $request) => $escalationRoutesAdmin->store($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    // Verifying is a separate act from recording: it names who checked, when, and how.
    $router->post(
        '/admin/escalation-routes/{id}/verify',
        static fn (Request $request, array $params) => $escalationRoutesAdmin->verify($request, $params),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/escalation-routes/{id}/retire',
        static fn (Request $request, array $params) => $escalationRoutesAdmin->retire($request, $params),
        RouteAccess::permission(Permission::ClinicConfigure),
    );

    // The credential register (Decision 6, AC-009). Not under /admin, and not behind
    // an administrative permission: Decision 5 leaves the system administrator
    // technical authority only, and Decision 6 says administrators must not be able
    // to bypass the verification requirement. An account that can write this table
    // can satisfy it for itself, so the separation is the control.
    $router->get(
        '/practitioners',
        static fn (Request $request) => $practitioners->index($request),
        RouteAccess::permission(Permission::ClinicalCredentialManage),
    );
    $router->post(
        '/practitioners/credentials',
        static fn (Request $request) => $practitioners->recordCredential($request),
        RouteAccess::permission(Permission::ClinicalCredentialManage),
    );
    // Suspension is reversible and revocation is not, so state changes are one
    // action with the target state in the body rather than three routes that would
    // have to be kept in step with the enum.
    $router->post(
        '/practitioners/credentials/{credentialId}/status',
        static fn (Request $request, array $params) => $practitioners->changeStatus($request, $params),
        RouteAccess::permission(Permission::ClinicalCredentialManage),
    );
    $router->post(
        '/practitioners/credentials/{credentialId}/privileges',
        static fn (Request $request, array $params) => $practitioners->grantPrivilege($request, $params),
        RouteAccess::permission(Permission::ClinicalCredentialManage),
    );
    // Ending a privilege is a date, not a deletion: the row stays and says when the
    // authority stopped, so decisions taken while it was open stay explainable.
    $router->post(
        '/practitioners/privileges/{privilegeId}/end',
        static fn (Request $request, array $params) => $practitioners->endPrivilege($request, $params),
        RouteAccess::permission(Permission::ClinicalCredentialManage),
    );

    // §17.5: publishing is a named, dated act, and the screen that performs it is
    // also the screen that says why a page is not showing.
    $router->get(
        '/admin/content',
        static fn (Request $request) => $content->index($request),
        RouteAccess::permission(Permission::ContentPublish),
    );
    $router->post(
        '/admin/content/{id}/publish',
        static fn (Request $request, array $params) => $content->publish($request, $params),
        RouteAccess::permission(Permission::ContentPublish),
    );
    $router->post(
        '/admin/content/{id}/withdraw',
        static fn (Request $request, array $params) => $content->withdraw($request, $params),
        RouteAccess::permission(Permission::ContentPublish),
    );

    $router->get(
        '/admin/diagnostics',
        static fn (Request $request) => $diagnostics->index($request),
        RouteAccess::permission(Permission::SystemMaintain),
    );

    $schedule = new ScheduleController(
        $app->view(),
        $app->gate(),
        $app->logger(),
        static fn () => $app->scheduleWorkspace(),
        $app->config()->timezone(),
    );

    $router->get(
        '/schedule',
        static fn (Request $request) => $schedule->index($request),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $router->get(
        '/schedule/book',
        static fn (Request $request) => $schedule->create($request),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $router->post(
        '/schedule/book',
        static fn (Request $request) => $schedule->store($request),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $router->get(
        '/schedule/{reference}/move',
        static fn (Request $request, array $params) => $schedule->move($request, $params),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $router->post(
        '/schedule/{reference}/reschedule',
        static fn (Request $request, array $params) => $schedule->reschedule($request, $params),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $router->post(
        '/schedule/{reference}/cancel',
        static fn (Request $request, array $params) => $schedule->cancel($request, $params),
        RouteAccess::permission(Permission::BookingsManage),
    );

    $appointments = new AppointmentController(
        $app->view(),
        $app->gate(),
        $app->appointments(),
        $app->procedureMilestones(),
        $app->referrals(),
        $app->consents(),
        $app->documents(),
        $app->recordProcedureMilestone(),
        $app->config()->timezone(),
    );

    // The day's list and one appointment are the same audience: the four roles that
    // hold `schedule.manage` are the ones in the room. Which event each of them may
    // *record* is asked per kind inside the page and again in the command.
    $router->get(
        '/appointments',
        static fn (Request $request) => $appointments->index($request),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $router->get(
        '/appointments/{reference}',
        static fn (Request $request, array $params) => $appointments->show($request, $params),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $router->post(
        '/appointments/{reference}/milestones',
        static fn (Request $request, array $params) => $appointments->recordMilestone($request, $params),
        RouteAccess::permission(Permission::ScheduleManage),
    );
    $referrals = static fn (): ReferralController => new ReferralController(
        $app->view(),
        $app->gate(),
        $app->submitReferral(),
        $app->saveReferralDraft(),
        $app->submitDraft(),
        $app->referrals(),
        $app->procedures(),
        $app->approveForScheduling(),
        $app->reassignClinicalPriority(),
        $app->verifyGuardianAuthority(),
        $app->clinicalAssessments(),
        $app->recordClinicalAssessment(),
        $app->consents(),
        $app->recordProcedureConsent(),
        $app->withdrawConsent(),
        $app->config()->timezone(),
    );

    // The referrer's own list (BL-E1). A separate surface from the triage queue on
    // purpose: the permission admits the page, and `ReferralAccessPolicy` decides
    // the rows, so a physician reading a colleague's referral would take a change
    // to the profile on the row and not a guessed URL.
    $myReferrals = new MyReferralsController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->referrals(),
        $app->referralAccess(),
        $app->config()->timezone(),
    );

    $router->get(
        '/my-referrals',
        static fn (Request $request) => $myReferrals->index($request),
        RouteAccess::permission(Permission::ReferralReadOwn),
    );

    // The queue and the intake form are staff and referrer surfaces. There is no
    // anonymous intake: a referral names a submitting account, and a patient
    // without one tracks a case through the status link instead (ADR-013).
    $router->get(
        '/referrals',
        static fn (Request $request) => $referrals()->index($request),
        RouteAccess::permission(Permission::ReferralTriage),
    );
    $router->get(
        '/referrals/new',
        static fn (Request $request) => $referrals()->create($request),
        RouteAccess::permission(Permission::ReferralSubmit),
    );
    $router->post(
        '/referrals',
        static fn (Request $request) => $referrals()->store($request),
        RouteAccess::permission(Permission::ReferralSubmit),
    );
    $router->post(
        '/referrals/draft',
        static fn (Request $request) => $referrals()->saveDraft($request),
        RouteAccess::permission(Permission::ReferralSubmit),
    );
    $router->post(
        '/referrals/{reference}/submit',
        static fn (Request $request, array $params) => $referrals()->submit($request, $params),
        RouteAccess::permission(Permission::ReferralSubmit),
    );

    $router->post(
        '/referrals/{reference}/advance',
        static fn (Request $request, array $params) => $referrals()->advance($request, $params),
        RouteAccess::permission(Permission::ReferralTriage),
    );
    $router->post(
        '/referrals/{reference}/reply',
        static fn (Request $request, array $params) => $referrals()->reply($request, $params),
        RouteAccess::permission(Permission::ReferralTriage),
    );

    // Releasing a clinically approved referral is a scheduling act, not a triage
    // one, so the route carries the scheduling permission and the command repeats
    // the check before it moves anything.
    $router->post(
        '/referrals/{reference}/release',
        static fn (Request $request, array $params) => $referrals()->release($request, $params),
        RouteAccess::permission(Permission::ScheduleManage),
    );

    // Changing an assigned clinical priority is a clinical act on a referral that
    // has already left clinical review, which is why it is not `/decision`.
    $router->post(
        '/referrals/{reference}/priority',
        static fn (Request $request, array $params) => $referrals()->reassignPriority($request, $params),
        RouteAccess::permission(Permission::ClinicalApprove),
    );

    $router->post(
        '/referrals/{reference}/guardian-verification',
        static fn (Request $request, array $params) => $referrals()->verifyGuardian($request, $params),
        RouteAccess::permission(Permission::ReferralTriage),
    );

    $router->post(
        '/referrals/{reference}/assessment',
        static fn (Request $request, array $params) => $referrals()->recordAssessment($request, $params),
        RouteAccess::permission(Permission::ClinicalReview),
    );

    // Consent is recorded by a clinician after the decision and before the
    // procedure (§18.1); the command refuses anything earlier.
    $router->post(
        '/referrals/{reference}/consent',
        static fn (Request $request, array $params) => $referrals()->recordConsent($request, $params),
        RouteAccess::permission(Permission::ClinicalApprove),
    );
    $router->post(
        '/referrals/{reference}/consent/withdraw',
        static fn (Request $request, array $params) => $referrals()->withdrawConsent($request, $params),
        RouteAccess::permission(Permission::ClinicalApprove),
    );

    $router->get(
        '/referrals/{reference}',
        static fn (Request $request, array $params) => $referrals()->show($request, $params),
        RouteAccess::permission(Permission::ReferralTriage),
    );

    $review = new ReviewController(
        $app->view(),
        $app->gate(),
        $app->recordClinicalDecision(),
        $app->referrals(),
        $app->procedures(),
        $app->clinicalPrivileges(),
        $app->config()->timezone(),
    );

    $router->get(
        '/review',
        static fn (Request $request) => $review->index($request),
        RouteAccess::permission(Permission::ClinicalReview),
    );
    $router->get(
        '/review/{reference}',
        static fn (Request $request, array $params) => $review->show($request, $params),
        RouteAccess::permission(Permission::ClinicalReview),
    );
    $router->post(
        '/referrals/{reference}/decision',
        static fn (Request $request, array $params) => $review->decide($request, $params),
        RouteAccess::permission(Permission::ClinicalApprove),
    );
    $urgent = new UrgentController(
        $app->view(),
        $app->gate(),
        $app->urgentCases(),
        $app->registerUrgentCase(),
        $app->acknowledgeUrgentCase(),
        $app->recordUrgentContactAttempt(),
        $app->escalateUrgentCase(),
        $app->closeUrgentCase(),
        $app->recordUrgentDecision(),
        $app->advanceUrgentCoordination(),
        $app->openUrgentReview(),
        // Decision 4: escalation is gated on a verified roster, so the board reads
        // the same assessment the commands refuse with.
        $app->escalationReadiness(),
        $app->escalationRoutes(),
        $app->config()->timezone(),
    );

    // `/urgent` is reserved for the public urgent-pathway notice (BL-A6): that page
    // has to work without a session, because it is the one a parent reaches at 3am.
    // The board behind these routes is the staff surface, so it lives elsewhere.
    $router->get(
        '/urgent-cases',
        static fn (Request $request) => $urgent->index($request),
        RouteAccess::permission(Permission::UrgentAcknowledge),
    );
    $router->get(
        '/urgent-cases/new',
        static fn (Request $request) => $urgent->create($request),
        RouteAccess::permission(Permission::ReferralSubmit),
    );
    $router->post(
        '/urgent-cases',
        static fn (Request $request) => $urgent->store($request),
        RouteAccess::permission(Permission::ReferralSubmit),
    );
    $router->get(
        '/urgent-cases/{reference}',
        static fn (Request $request, array $params) => $urgent->show($request, $params),
        RouteAccess::permission(Permission::UrgentAcknowledge),
    );
    // Acknowledgement is the one act WF-05 wants available to whoever is at the
    // desk, so it carries the weaker permission; the domain still refuses a role
    // that the coordination table does not name.
    $router->post(
        '/urgent-cases/{reference}/acknowledge',
        static fn (Request $request, array $params) => $urgent->acknowledge($request, $params),
        RouteAccess::permission(Permission::UrgentAcknowledge),
    );
    $router->post(
        '/urgent-cases/{reference}/contact',
        static fn (Request $request, array $params) => $urgent->contact($request, $params),
        RouteAccess::permission(Permission::UrgentAcknowledge),
    );
    $router->post(
        '/urgent-cases/{reference}/escalate',
        static fn (Request $request, array $params) => $urgent->escalate($request, $params),
        RouteAccess::permission(Permission::UrgentCoordinate),
    );
    $router->post(
        '/urgent-cases/{reference}/close',
        static fn (Request $request, array $params) => $urgent->close($request, $params),
        RouteAccess::permission(Permission::UrgentCoordinate),
    );
    $router->post(
        '/urgent-cases/{reference}/decision',
        static fn (Request $request, array $params) => $urgent->decide($request, $params),
        RouteAccess::permission(Permission::UrgentCoordinate),
    );
    // The operational axis: whether the receiving team is engaged, and whether
    // anything is left to coordinate. Neither is inferred from closure.
    // The first clinical step: a radiologist picking the case up. Without it a new
    // case could never reach a state where a decision is recordable.
    $router->post(
        '/urgent-cases/{reference}/review',
        static fn (Request $request, array $params) => $urgent->openReview($request, $params),
        RouteAccess::permission(Permission::ClinicalReview),
    );
    $router->post(
        '/urgent-cases/{reference}/coordination',
        static fn (Request $request, array $params) => $urgent->coordinate($request, $params),
        RouteAccess::permission(Permission::UrgentCoordinate),
    );

    $documents = new DocumentController(
        $app->view(),
        $app->gate(),
        $app->logger(),
        static fn () => $app->documentWorkspace(),
        $app->config()->timezone(),
        $app->config()->baseUrl(),
        $app->config()->documentMaxBytes(),
    );

    $router->get(
        '/referrals/{reference}/documents',
        static fn (Request $request, array $params) => $documents->index($request, $params),
        RouteAccess::permission(Permission::DocumentReadMetadata),
    );
    $router->post(
        '/referrals/{reference}/documents',
        static fn (Request $request, array $params) => $documents->store($request, $params),
        RouteAccess::permission(Permission::DocumentUpload),
    );
    $router->post(
        '/referrals/{reference}/status-link',
        static fn (Request $request, array $params) => $documents->issueLink($request, $params),
        RouteAccess::permission(Permission::ReferralTriage),
    );
    $router->get(
        '/documents/{ref}/download',
        static fn (Request $request, array $params) => $documents->download($request, $params),
        RouteAccess::permission(Permission::DocumentDownload),
    );
    $router->post(
        '/documents/{ref}/review',
        static fn (Request $request, array $params) => $documents->review($request, $params),
        RouteAccess::permission(Permission::DocumentQuarantineManage),
    );

    // The token is the credential: the route is public because the point of a
    // status link is that it works without an account, and single-use because a
    // link that survives being forwarded is a leaked link.
    $router->get(
        '/r/{token}',
        static fn (Request $request, array $params) => $documents->showStatus($request, $params),
        RouteAccess::public('single-use status link; possession of the token is the credential'),
    );

    $health = new HealthController(static fn () => $app->pdo());
    $router->get(
        '/healthz',
        static fn (Request $request) => $health($request),
        RouteAccess::public('liveness probe for the host'),
    );
};
