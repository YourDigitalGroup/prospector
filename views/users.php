<?php

use Prospector\Auth;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\View;
use Prospector\Users;

/**
 * @var list<array<string, mixed>> $users
 * @var array<string, string> $loops
 * @var string $csrf
 */
?>

<div class="page-head">
    <div>
        <h1>Users</h1>
        <div class="sub">Who gets a daily batch, which loop they run, and how they sign in.</div>
    </div>
</div>

<div class="card mb">
    <div class="card-body tight">
        <div class="table-scroll">
            <table class="data">
                <thead>
                <tr>
                    <th>Person</th>
                    <th>Loop</th>
                    <th>Sign-in</th>
                    <th>Daily batch</th>
                    <th>GoHighLevel</th>
                    <th>Last seen</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <div class="row" style="gap:9px">
                                <div class="avatar"><?= View::e(Users::initials((string) $user['name'])) ?></div>
                                <div>
                                    <div class="cell-primary">
                                        <?= View::e($user['name']) ?>
                                        <?php if ((string) $user['role'] === 'admin'): ?>
                                            <span class="badge badge-high">Admin</span>
                                        <?php endif; ?>
                                        <?php if ((int) $user['active'] !== 1): ?>
                                            <span class="badge badge-disqualified">Disabled</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cell-sub"><?= View::e($user['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="nowrap"><?= View::e(Runs::loopLabel((string) $user['loop'])) ?></td>
                        <td class="nowrap small">
                            <?php if ((int) $user['requires_password'] === 1): ?>
                                <span class="dot ok"></span> Password required
                            <?php else: ?>
                                <span class="dot warn"></span> Email only
                            <?php endif; ?>
                        </td>
                        <td class="nowrap small">
                            <?php if ((int) $user['autorun'] === 1 && (string) $user['loop'] !== 'none'): ?>
                                <span class="dot ok"></span> On<?= (int) $user['daily_email'] === 1 ? ' + email' : ', no email' ?>
                            <?php else: ?>
                                <span class="muted">Off</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap small">
                            <?php if (Users::ghlToken($user) !== ''): ?>
                                <span class="dot ok"></span> Own sub-account
                            <?php else: ?>
                                <span class="muted">Account default</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap small muted"><?= View::e(Clock::relative($user['last_login_at'] ?? null)) ?></td>
                        <td class="right nowrap">
                            <a class="btn btn-sm" href="#user-<?= (int) $user['id'] ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$blank = [
    'id' => 0,
    'name' => '',
    'email' => '',
    'role' => 'user',
    'loop' => 'none',
    'geography' => '',
    'requires_password' => 0,
    'daily_email' => 1,
    'autorun' => 1,
    'active' => 1,
    'ghl_location_id' => '',
];

foreach (array_merge($users, [$blank]) as $user):
    $isNew = (int) $user['id'] === 0;
    $isSelf = (int) $user['id'] === Auth::id();
    ?>
    <div class="card mb" id="user-<?= (int) $user['id'] ?>">
        <div class="card-head">
            <h2><?= $isNew ? 'Add someone' : View::e($user['name']) ?></h2>
            <?php if ($isSelf): ?><span class="pill">This is you</span><?php endif; ?>
        </div>
        <form method="post" action="<?= View::e(View::url('users/save')) ?>">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">

            <div class="card-body">
                <div class="field-row">
                    <div class="field">
                        <label for="name-<?= (int) $user['id'] ?>">Name</label>
                        <input type="text" id="name-<?= (int) $user['id'] ?>" name="name"
                               value="<?= View::e($user['name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="email-<?= (int) $user['id'] ?>">Email</label>
                        <input type="email" id="email-<?= (int) $user['id'] ?>" name="email"
                               value="<?= View::e($user['email']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="role-<?= (int) $user['id'] ?>">Role</label>
                        <select id="role-<?= (int) $user['id'] ?>" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                            <option value="user" <?= (string) $user['role'] === 'user' ? 'selected' : '' ?>>
                                Sees only their own leads
                            </option>
                            <option value="admin" <?= (string) $user['role'] === 'admin' ? 'selected' : '' ?>>
                                Admin — sees and manages everything
                            </option>
                        </select>
                        <?php if ($isSelf): ?>
                            <input type="hidden" name="role" value="admin">
                            <div class="hint">You cannot remove your own admin access.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="loop-<?= (int) $user['id'] ?>">Prospecting loop</label>
                        <select id="loop-<?= (int) $user['id'] ?>" name="loop">
                            <?php foreach ($loops as $key => $label): ?>
                                <option value="<?= View::e($key) ?>" <?= (string) $user['loop'] === $key ? 'selected' : '' ?>>
                                    <?= View::e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="geography-<?= (int) $user['id'] ?>">
                            Geography override <span class="muted" style="font-weight:500">(optional)</span>
                        </label>
                        <input type="text" id="geography-<?= (int) $user['id'] ?>" name="geography"
                               value="<?= View::e($user['geography'] ?? '') ?>"
                               placeholder="Leave blank to rotate markets automatically">
                        <div class="hint">Pin them to one region, or leave blank so each day moves on.</div>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="password-<?= (int) $user['id'] ?>">
                            <?= $isNew ? 'Password' : 'New password' ?>
                            <span class="muted" style="font-weight:500">(optional)</span>
                        </label>
                        <input type="password" id="password-<?= (int) $user['id'] ?>" name="password"
                               autocomplete="new-password"
                               placeholder="<?= $isNew ? 'Only needed if a password is required' : 'Leave blank to keep the current one' ?>">
                    </div>
                    <div class="field">
                        <label for="ghl_location-<?= (int) $user['id'] ?>">GoHighLevel location ID</label>
                        <input type="text" id="ghl_location-<?= (int) $user['id'] ?>" name="ghl_location_id"
                               value="<?= View::e($user['ghl_location_id'] ?? '') ?>"
                               placeholder="Leave blank to use the account default">
                    </div>
                    <div class="field">
                        <label for="ghl_token-<?= (int) $user['id'] ?>">GoHighLevel token</label>
                        <input type="password" id="ghl_token-<?= (int) $user['id'] ?>" name="ghl_token"
                               autocomplete="new-password"
                               placeholder="<?= !$isNew && Users::ghlToken($user) !== '' ? 'Saved — type a new one to replace it' : 'Optional per-user token' ?>">
                    </div>
                </div>

                <div class="grid grid-3 mt">
                    <div class="check">
                        <input type="checkbox" id="requires_password-<?= (int) $user['id'] ?>" name="requires_password"
                               value="1" <?= (int) $user['requires_password'] === 1 ? 'checked' : '' ?>>
                        <div>
                            <label for="requires_password-<?= (int) $user['id'] ?>">Requires a password</label>
                            <div class="hint">Off means their email address alone signs them in.</div>
                        </div>
                    </div>
                    <div class="check">
                        <input type="checkbox" id="autorun-<?= (int) $user['id'] ?>" name="autorun" value="1"
                            <?= (int) $user['autorun'] === 1 ? 'checked' : '' ?>>
                        <div>
                            <label for="autorun-<?= (int) $user['id'] ?>">Automatic daily batch</label>
                            <div class="hint">Include them in the morning run.</div>
                        </div>
                    </div>
                    <div class="check">
                        <input type="checkbox" id="daily_email-<?= (int) $user['id'] ?>" name="daily_email" value="1"
                            <?= (int) $user['daily_email'] === 1 ? 'checked' : '' ?>>
                        <div>
                            <label for="daily_email-<?= (int) $user['id'] ?>">Email the brief</label>
                            <div class="hint">Send the batch to their inbox.</div>
                        </div>
                    </div>
                    <div class="check">
                        <input type="checkbox" id="active-<?= (int) $user['id'] ?>" name="active" value="1"
                            <?= (int) $user['active'] === 1 ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
                        <div>
                            <label for="active-<?= (int) $user['id'] ?>">Account active</label>
                            <div class="hint">Turning this off blocks sign-in but keeps the leads.</div>
                        </div>
                        <?php if ($isSelf): ?><input type="hidden" name="active" value="1"><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-foot">
                <div class="row-between">
                    <button type="submit" class="btn btn-primary">
                        <?= $isNew ? 'Add user' : 'Save changes' ?>
                    </button>
                    <?php if (!$isNew && !$isSelf): ?>
                        <span class="muted small">Deleting removes their leads and batch history too.</span>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if (!$isNew && !$isSelf): ?>
            <div class="card-foot">
                <form method="post" action="<?= View::e(View::url('users/delete')) ?>"
                      data-confirm="Delete <?= View::e($user['name']) ?> along with every lead and batch of theirs? This cannot be undone.">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <?php $name = 'trash'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                        Delete <?= View::e($user['name']) ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
