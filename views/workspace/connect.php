<?php

use Prospector\Auth;
use Prospector\Support\View;

/**
 * @var array{ok: bool, message: string}|null $connection
 * @var bool $hasToken
 * @var string $locationId
 * @var array<string, mixed> $workspaceUser
 * @var bool $viewingOther
 */

$wsQuery = $viewingOther ? ['user_id' => (int) $workspaceUser['id']] : [];

$scopes = [
    'locations.readonly' => 'The connection test',
    'contacts.readonly, contacts.write' => 'Contacts, notes, tasks, pushing leads',
    'opportunities.readonly, opportunities.write' => 'The pipeline board and moving cards',
    'conversations.readonly, conversations.write' => 'The inbox',
    'conversations/message.readonly' => 'Reading a thread',
    'conversations/message.write' => 'Sending email and SMS',
    'workflows.readonly' => 'Listing automations',
];
?>

<div class="page-head">
    <div>
        <h1>Connect GoHighLevel</h1>
        <div class="sub">
            <?php if ($viewingOther): ?>
                Setting up <?= View::e($workspaceUser['name']) ?>'s sub-account
            <?php else: ?>
                Point Prospector at your own sub-account
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Your sub-account</h2></div>
        <div class="card-body">
            <?php if ($connection !== null): ?>
                <div class="alert <?= $connection['ok'] ? 'alert-success' : 'alert-error' ?>">
                    <?php $name = $connection['ok'] ? 'check' : 'alert'; $size = 17; require __DIR__ . '/../partials/icon.php'; ?>
                    <div><?= View::e($connection['message']) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= View::e(View::url('ghl/connect', $wsQuery)) ?>">
                <input type="hidden" name="csrf" value="<?= View::e(Auth::csrfToken()) ?>">

                <div class="field">
                    <label for="ghl_location_id">Location ID</label>
                    <input type="text" id="ghl_location_id" name="ghl_location_id" value="<?= View::e($locationId) ?>"
                           placeholder="e.g. ve9EPM428h8vShlRW1KT" autocomplete="off" spellcheck="false">
                    <div class="hint">In GoHighLevel: Settings &rarr; Business Profile, or the string in the URL after <code>/location/</code>.</div>
                </div>

                <div class="field">
                    <label for="ghl_token">Private Integration token</label>
                    <input type="password" id="ghl_token" name="ghl_token" autocomplete="new-password" spellcheck="false"
                           placeholder="<?= $hasToken ? 'Saved — leave blank to keep it' : 'pit-…' ?>">
                    <div class="hint">
                        <?php if ($hasToken): ?>
                            A token is saved. Leave this blank unless you are replacing it.
                        <?php else: ?>
                            Stored encrypted. It is never shown again after saving.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="btn-row">
                    <button class="btn btn-primary" type="submit">Save and test</button>
                    <?php if ($hasToken): ?>
                        <button class="btn btn-ghost" type="submit"
                                formaction="<?= View::e(View::url('ghl/disconnect', $wsQuery)) ?>"
                                onclick="return confirm('Disconnect this sub-account? The token will be deleted.')">
                            Disconnect
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Making the token</h2></div>
        <div class="card-body">
            <ol class="steps">
                <li>In GoHighLevel, open the <strong>sub-account</strong> you sell from — not the agency view.</li>
                <li>Go to <strong>Settings → Private Integrations</strong> and create one.</li>
                <li>Tick the scopes below, create it, and copy the token straight away — GoHighLevel shows it once.</li>
                <li>Paste it here with your Location ID.</li>
            </ol>

            <div class="table-scroll"><table class="data">
                <thead><tr><th>Scope</th><th>Needed for</th></tr></thead>
                <tbody>
                    <?php foreach ($scopes as $scope => $why): ?>
                        <tr>
                            <td><code><?= View::e($scope) ?></code></td>
                            <td class="dim"><?= View::e($why) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

            <p class="dim mt">
                Miss a scope and only that panel stops working — the rest of the workspace carries on.
                Conversation AI agents need the Conversation AI scopes, which not every plan includes.
            </p>
        </div>
    </div>
</div>
