<?php
/**
 * @var list<\App\Domain\User> $users
 * @var bool                   $unavailable
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Users</h1>
    </div>

    <?php if ($unavailable): ?>
        <div class="alert alert-error" role="alert">Accounts cannot be loaded right now.</div>
    <?php elseif ($users === []): ?>
        <p class="empty">No accounts yet. Create one with <code>php bin/console user:create</code>.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Staff accounts</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">State</th>
                        <th scope="col">Last sign-in</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= e($user->id) ?></td>
                            <td><?= e($user->name) ?></td>
                            <td><?= e($user->email) ?></td>
                            <td><?= e($user->role->label()) ?></td>
                            <td><?= e($user->active ? 'Active' : 'Disabled') ?></td>
                            <td><?= e($user->lastLoginAt ?? 'Never') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">
            Accounts are created from the console. There is no self-service sign-up by design.
        </p>
    <?php endif; ?>
</section>
