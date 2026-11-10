<?php
/**
 * @var list<\App\Domain\User>    $users
 * @var bool                      $unavailable
 * @var array<string, string>     $errors
 * @var array<string, string>     $previous
 * @var \App\Domain\User|null     $created
 * @var string|null               $temporaryPassword
 * @var list<\App\Domain\Role>    $roles
 * @var string|null               $notice
 * @var string|null               $noticeDetail
 * @var \App\Domain\User|null     $currentUser
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Users</h1>
        <a class="button button-secondary button-small" href="/admin">Back</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status">
            <p><?= e($notice) ?></p>
            <?php if ($noticeDetail !== null): ?>
                <p><?= e($noticeDetail) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($temporaryPassword !== null && $created !== null): ?>
        <div class="alert alert-warning" role="alert">
            <p>
                Account created for <strong><?= e($created->name) ?></strong>
                (<?= e($created->role->label()) ?>).
            </p>
            <p>
                Temporary password, shown once:
                <code class="secret"><?= e($temporaryPassword) ?></code>
            </p>
            <p class="muted">
                It is not stored anywhere in readable form and cannot be shown again. Give it to the
                person directly; they should reset it at first sign-in.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($unavailable): ?>
        <p>Accounts cannot be loaded right now.</p>
    <?php elseif ($users === []): ?>
        <p class="empty">No accounts yet. Use the form below to create the first one.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Staff accounts</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">State</th>
                        <th scope="col">Last sign-in</th>
                        <th scope="col">Change role</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <th scope="row"><?= e($user->name) ?></th>
                            <td><?= e($user->email) ?></td>
                            <td><?= e($user->role->label()) ?></td>
                            <td><?= e($user->active ? 'Active' : 'Disabled') ?></td>
                            <td><?= e($user->lastLoginAt ?? 'Never') ?></td>
                            <td>
                                <?php if ($currentUser !== null && $currentUser->id === $user->id): ?>
                                    <span class="muted">Your own role</span>
                                <?php else: ?>
                                    <form method="post" action="/admin/users/role" class="inline-form">
                                        <?= \App\Security\Csrf::field() ?>
                                        <input type="hidden" name="user_id" value="<?= e((string) $user->id) ?>">
                                        <label class="visually-hidden" for="role-<?= e((string) $user->id) ?>">
                                            New role for <?= e($user->name) ?>
                                        </label>
                                        <select id="role-<?= e((string) $user->id) ?>" name="role">
                                            <?php foreach ($roles as $role): ?>
                                                <option
                                                    value="<?= e($role->value) ?>"
                                                    <?= $role === $user->role ? 'selected' : '' ?>
                                                >
                                                    <?= e($role->label()) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="link-button" type="submit">Save</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">
            There is no self-service sign-up by design. Changing a role ends that person's existing
            sessions, so a revoked privilege takes effect on their next request.
        </p>
    <?php endif; ?>

    <h2 class="section-title">Create an account</h2>
    <form method="post" action="/admin/users">
        <?= \App\Security\Csrf::field() ?>

        <div class="form-row">
            <label for="name">Full name</label>
            <input id="name" type="text" name="name" required value="<?= e($previous['name'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required value="<?= e($previous['email'] ?? '') ?>">
        </div>

        <div class="form-row">
            <label for="role">Role</label>
            <select id="role" name="role" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role->value) ?>" <?= ($previous['role'] ?? '') === $role->value ? 'selected' : '' ?>>
                        <?= e($role->label()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="button" type="submit">Create account</button>
    </form>
</section>
