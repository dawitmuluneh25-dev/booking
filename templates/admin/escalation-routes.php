<?php
/**
 * @var list<array{
 *     route: \App\Domain\EscalationRoute,
 *     verifiedBy: string|null,
 *     verifiedAt: string|null
 * }>                                       $routes
 * @var \App\Domain\EscalationReadinessReport $readiness
 * @var list<\App\Domain\EscalationRouteKind> $kinds
 * @var array<string, string>                 $errors
 * @var string|null                           $notice
 * @var \App\Domain\User                      $currentUser
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Escalation routes</h1>
        <a class="button button-secondary button-small" href="/urgent-cases">Urgent board</a>
    </div>

    <?php if ($notice !== null): ?>
        <p class="alert alert-info"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That change was not recorded.</p>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($readiness->activated): ?>
        <p class="alert alert-info" role="status">
            <strong>Escalation is available.</strong> The primary, secondary and receiving-team
            routes are recorded and verified.
        </p>
    <?php else: ?>
        <p class="alert alert-warning" role="status">
            <strong>Escalation is disabled.</strong> <?= e($readiness->message()) ?>
        </p>
    <?php endif; ?>

    <p class="lede">
        Who to telephone during an urgent case, and whether anybody has confirmed that the number
        is answered. Recording a route and verifying it are separate acts: a number typed into this
        page counts toward nothing until somebody says how they confirmed it.
    </p>

    <p class="muted">
        There are no intervals, countdowns or automatic escalations here, by decision. The level on
        an urgent case is the coordinator's judgement, recorded with their name and their reason.
        After-hours escalation is allowed only where cover genuinely exists, so an unstaffed
        after-hours route cannot be recorded at all.
    </p>

    <h2 class="section-title">Recorded routes</h2>
    <?php if ($routes === []): ?>
        <p><strong>No route is recorded.</strong> Urgent escalation stays disabled until the
            primary, secondary and receiving-team arrangements exist and are verified.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Escalation roster</caption>
                <thead>
                    <tr>
                        <th scope="col">Route</th>
                        <th scope="col">Who answers</th>
                        <th scope="col">State</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routes as $entry): ?>
                        <?php $route = $entry['route']; ?>
                        <tr>
                            <th scope="row">
                                <?= e($route->kind->label()) ?>
                                <span class="muted block"><?= e($route->label) ?></span>
                            </th>
                            <td>
                                <?= e($route->contactName) ?>
                                <span class="muted block mono"><?= e($route->contactPhone) ?></span>
                                <?php if ($route->kind->requiresStaffing() && !$route->isStaffed): ?>
                                    <span class="muted block">Not staffed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$route->isActive): ?>
                                    <span class="badge">Retired</span>
                                <?php elseif ($route->isVerified()): ?>
                                    <span class="badge">Verified</span>
                                    <span class="muted block">
                                        By <?= e((string) $entry['verifiedBy']) ?>
                                        on <?= e((string) $entry['verifiedAt']) ?>
                                    </span>
                                    <?php if ($route->verificationNote !== null): ?>
                                        <span class="muted block"><?= e($route->verificationNote) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge">Awaiting verification</span>
                                    <span class="muted block">Counts toward nothing yet.</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($route->isActive && !$route->isVerified()): ?>
                                    <form method="post"
                                          action="/admin/escalation-routes/<?= e((string) $route->id) ?>/verify"
                                          class="stack">
                                        <?= \App\Security\Csrf::field() ?>
                                        <div class="field">
                                            <label for="note_<?= e((string) $route->id) ?>">
                                                How did you confirm it is answered?
                                            </label>
                                            <input type="text" id="note_<?= e((string) $route->id) ?>"
                                                   name="verification_note" minlength="10" maxlength="300" required>
                                        </div>
                                        <div class="form-actions">
                                            <button class="button button-small" type="submit">Verify</button>
                                        </div>
                                    </form>
                                <?php endif; ?>

                                <?php if ($route->isActive): ?>
                                    <form method="post"
                                          action="/admin/escalation-routes/<?= e((string) $route->id) ?>/retire"
                                          class="inline-form">
                                        <?= \App\Security\Csrf::field() ?>
                                        <button class="button button-secondary button-small" type="submit">
                                            Retire
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="section-title">Record a route</h2>
    <p class="muted">
        Record what Operations has confirmed exists. The route will not count toward escalation
        until it is verified separately.
    </p>
    <form method="post" action="/admin/escalation-routes" class="stack">
        <?= \App\Security\Csrf::field() ?>

        <div class="field">
            <label for="kind">Which route is this?</label>
            <select id="kind" name="kind" required>
                <?php foreach ($kinds as $kind): ?>
                    <option value="<?= e($kind->value) ?>"><?= e($kind->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="label">Name it, so the roster is unambiguous</label>
            <input type="text" id="label" name="label" maxlength="120" minlength="2" required>
        </div>

        <div class="field">
            <label for="contact_name">Who answers</label>
            <input type="text" id="contact_name" name="contact_name" maxlength="120" minlength="2" required>
        </div>

        <div class="field">
            <label for="contact_phone">Number</label>
            <input type="tel" id="contact_phone" name="contact_phone" maxlength="32" required>
        </div>

        <div class="field">
            <label for="is_staffed">Is this route genuinely staffed?</label>
            <select id="is_staffed" name="is_staffed">
                <option value="1">Yes — somebody answers</option>
                <option value="0">No — it exists but nobody covers it</option>
            </select>
            <p class="muted">An after-hours route that is not staffed cannot be recorded.</p>
        </div>

        <div class="form-actions">
            <button class="button" type="submit">Record the route</button>
        </div>
    </form>
</section>
