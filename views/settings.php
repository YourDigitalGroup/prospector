<?php

use Prospector\Support\Settings;
use Prospector\Support\View;

/**
 * @var array<string, string> $settings
 * @var string $cronUrl
 * @var bool $canDetach
 * @var bool $envKey
 * @var string $scheduleText
 * @var string $timezone
 * @var string $csrf
 * @var array<string, mixed>|null $currentUser
 */

$secretSet = static fn (string $key): bool => Settings::hasSecret($key);
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <div class="sub">API keys, delivery schedule, email and GoHighLevel. Only admins see this screen.</div>
    </div>
</div>

<form method="post" action="<?= View::e(View::url('settings')) ?>">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">

    <div class="grid grid-2">
        <div class="stack">
            <div class="card">
                <div class="card-head">
                    <h2>Anthropic API</h2>
                    <?php if ($secretSet('anthropic_api_key') || $envKey): ?>
                        <span class="pill"><span class="dot ok"></span> Key set</span>
                    <?php else: ?>
                        <span class="pill"><span class="dot bad"></span> Missing</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($envKey): ?>
                        <div class="alert alert-info">
                            <?php $name = 'check'; $size = 16; require __DIR__ . '/partials/icon.php'; ?>
                            <div>An <code>ANTHROPIC_API_KEY</code> environment variable is set on this server, and
                                it takes priority over anything entered here.</div>
                        </div>
                    <?php endif; ?>

                    <div class="field">
                        <label for="anthropic_api_key">API key</label>
                        <input type="password" id="anthropic_api_key" name="anthropic_api_key"
                               autocomplete="new-password"
                               placeholder="<?= $secretSet('anthropic_api_key') ? 'Saved — type a new key to replace it' : 'sk-ant-…' ?>">
                        <div class="hint">
                            Stored encrypted. Get one from console.anthropic.com. This is what pays for the
                            daily research.
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="effort">Research depth</label>
                            <select id="effort" name="effort">
                                <?php foreach (['low' => 'Low — fastest, cheapest', 'medium' => 'Medium', 'high' => 'High (recommended)', 'xhigh' => 'Extra high', 'max' => 'Max — slowest, most thorough'] as $key => $label): ?>
                                    <option value="<?= View::e($key) ?>" <?= ($settings['effort'] ?? 'high') === $key ? 'selected' : '' ?>>
                                        <?= View::e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">Higher digs through more sources per batch and costs more.</div>
                        </div>
                        <div class="field">
                            <label>Model</label>
                            <input type="text" value="<?= View::e($settings['model'] ?? 'claude-opus-5') ?>" disabled>
                            <div class="hint">Set in config.php.</div>
                        </div>
                    </div>
                </div>
                <div class="card-foot">
                    <button type="submit" class="btn btn-sm" formaction="<?= View::e(View::url('settings/test/anthropic')) ?>"
                            formmethod="post">
                        Test the API key
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Daily schedule</h2></div>
                <div class="card-body">
                    <div class="field-row">
                        <div class="field">
                            <label for="run_hour">Hour</label>
                            <select id="run_hour" name="run_hour">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?= $h ?>" <?= (int) ($settings['run_hour'] ?? 7) === $h ? 'selected' : '' ?>>
                                        <?= date('g a', mktime($h, 0) ?: 0) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="run_minute">Minute</label>
                            <select id="run_minute" name="run_minute">
                                <?php foreach ([0, 15, 30, 45] as $m): ?>
                                    <option value="<?= $m ?>" <?= (int) ($settings['run_minute'] ?? 30) === $m ? 'selected' : '' ?>>
                                        :<?= str_pad((string) $m, 2, '0', STR_PAD_LEFT) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Timezone</label>
                            <input type="text" value="<?= View::e($timezone) ?>" disabled>
                            <div class="hint">Set in config.php.</div>
                        </div>
                    </div>

                    <div class="check">
                        <input type="checkbox" id="run_weekdays_only" name="run_weekdays_only" value="1"
                            <?= ($settings['run_weekdays_only'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <div>
                            <label for="run_weekdays_only">Weekdays only</label>
                            <div class="hint">
                                Skips Saturday and Sunday. A lead delivered on a weekend just ages before
                                anyone can call it.
                            </div>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="batch_size">Leads per batch</label>
                            <input type="number" id="batch_size" name="batch_size" min="1" max="25"
                                   value="<?= View::e($settings['batch_size'] ?? '10') ?>">
                        </div>
                        <div class="field">
                            <label for="min_fit_score">Minimum fit score</label>
                            <input type="number" id="min_fit_score" name="min_fit_score" min="0" max="100"
                                   value="<?= View::e($settings['min_fit_score'] ?? '70') ?>">
                            <div class="hint">Anything below this is dropped rather than padding the batch.</div>
                        </div>
                    </div>

                    <div class="alert alert-info mt">
                        <?php $name = 'clock'; $size = 16; require __DIR__ . '/partials/icon.php'; ?>
                        <div>Currently delivering at <strong><?= View::e($scheduleText) ?></strong>.</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>Scheduling hook</h2>
                    <span class="pill"><?= $canDetach ? 'Background capable' : 'Foreground only' ?></span>
                </div>
                <div class="card-body">
                    <p class="dim small">
                        Something has to wake the app each morning. Two options — either is enough on its own.
                    </p>

                    <div class="field">
                        <label>1. cPanel cron job (recommended)</label>
                        <div class="mono" style="background:var(--surface-3);padding:9px 11px;border-radius:7px;overflow-x:auto">
                            30 7 * * 1-5 /usr/local/bin/php <?= View::e(dirname(__DIR__)) ?>/bin/daily.php
                        </div>
                        <div class="hint">
                            Set the cron time in the server's own timezone so it lands on
                            <?= View::e($scheduleText) ?>. The script re-checks the clock before it runs anything.
                        </div>
                    </div>

                    <div class="field">
                        <label>2. Webhook URL</label>
                        <div class="mono" style="background:var(--surface-3);padding:9px 11px;border-radius:7px;overflow-wrap:anywhere">
                            <?= View::e($cronUrl) ?>
                        </div>
                        <div class="hint">
                            Hit this from any external scheduler. The included GitHub Actions workflow uses it —
                            add the URL as the <code>PROSPECTOR_CRON_URL</code> repository secret. Keep the token
                            private: anyone with this URL can trigger a batch.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head">
                    <h2>Email delivery</h2>
                    <?php if (($settings['mail_from_email'] ?? '') !== ''): ?>
                        <span class="pill"><span class="dot ok"></span> From set</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="field-row">
                        <div class="field">
                            <label for="mail_from_email">From address</label>
                            <input type="email" id="mail_from_email" name="mail_from_email"
                                   value="<?= View::e($settings['mail_from_email'] ?? '') ?>"
                                   placeholder="prospector@44i.com">
                            <div class="hint">Use an address on a domain this server is allowed to send for.</div>
                        </div>
                        <div class="field">
                            <label for="mail_from_name">From name</label>
                            <input type="text" id="mail_from_name" name="mail_from_name"
                                   value="<?= View::e($settings['mail_from_name'] ?? 'Prospector') ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="mail_transport">How to send</label>
                        <select id="mail_transport" name="mail_transport">
                            <option value="mail" <?= ($settings['mail_transport'] ?? 'mail') === 'mail' ? 'selected' : '' ?>>
                                Server mail (simplest on cPanel)
                            </option>
                            <option value="smtp" <?= ($settings['mail_transport'] ?? '') === 'smtp' ? 'selected' : '' ?>>
                                SMTP (more reliable delivery)
                            </option>
                        </select>
                    </div>

                    <fieldset>
                        <div class="field-row">
                            <div class="field">
                                <label for="smtp_host">SMTP host</label>
                                <input type="text" id="smtp_host" name="smtp_host"
                                       value="<?= View::e($settings['smtp_host'] ?? '') ?>" placeholder="mail.44i.com">
                            </div>
                            <div class="field">
                                <label for="smtp_port">Port</label>
                                <input type="number" id="smtp_port" name="smtp_port"
                                       value="<?= View::e($settings['smtp_port'] ?? '587') ?>">
                            </div>
                            <div class="field">
                                <label for="smtp_secure">Encryption</label>
                                <select id="smtp_secure" name="smtp_secure">
                                    <option value="tls" <?= ($settings['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                                    <option value="ssl" <?= ($settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="none" <?= ($settings['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                        </div>

                        <div class="field-row">
                            <div class="field">
                                <label for="smtp_username">SMTP username</label>
                                <input type="text" id="smtp_username" name="smtp_username"
                                       value="<?= View::e($settings['smtp_username'] ?? '') ?>" autocomplete="off">
                            </div>
                            <div class="field">
                                <label for="smtp_password">SMTP password</label>
                                <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password"
                                       placeholder="<?= $secretSet('smtp_password') ? 'Saved — type a new one to replace it' : '' ?>">
                            </div>
                        </div>
                    </fieldset>
                </div>
                <div class="card-foot">
                    <div class="row">
                        <input type="email" name="test_email" placeholder="<?= View::e($currentUser['email'] ?? '') ?>"
                               style="width:210px" aria-label="Send a test email to">
                        <button type="submit" class="btn btn-sm" formaction="<?= View::e(View::url('settings/test/email')) ?>"
                                formmethod="post">
                            Send a test
                        </button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>GoHighLevel</h2>
                    <?php if ($secretSet('ghl_token') && ($settings['ghl_location_id'] ?? '') !== ''): ?>
                        <span class="pill"><span class="dot ok"></span> Connected</span>
                    <?php else: ?>
                        <span class="pill"><span class="dot bad"></span> Not set up</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label for="ghl_token">Private integration token</label>
                        <input type="password" id="ghl_token" name="ghl_token" autocomplete="new-password"
                               placeholder="<?= $secretSet('ghl_token') ? 'Saved — type a new token to replace it' : 'pit-…' ?>">
                        <div class="hint">
                            GoHighLevel → Settings → Private Integrations. Scopes needed:
                            <code>contacts.readonly</code>, <code>contacts.write</code>,
                            <code>opportunities.readonly</code>, <code>opportunities.write</code>,
                            <code>locations.readonly</code>.
                        </div>
                    </div>

                    <div class="field">
                        <label for="ghl_location_id">Location ID</label>
                        <input type="text" id="ghl_location_id" name="ghl_location_id"
                               value="<?= View::e($settings['ghl_location_id'] ?? '') ?>">
                        <div class="hint">The sub-account these leads belong to.</div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="ghl_pipeline_id">Pipeline ID <span class="muted" style="font-weight:500">(optional)</span></label>
                            <input type="text" id="ghl_pipeline_id" name="ghl_pipeline_id"
                                   value="<?= View::e($settings['ghl_pipeline_id'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="ghl_stage_id">Stage ID <span class="muted" style="font-weight:500">(optional)</span></label>
                            <input type="text" id="ghl_stage_id" name="ghl_stage_id"
                                   value="<?= View::e($settings['ghl_stage_id'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="hint">
                        With both filled in, pushing a lead also opens an opportunity in that stage.
                    </div>

                    <div class="check mt">
                        <input type="checkbox" id="clear_ghl_token" name="clear_ghl_token" value="1">
                        <div>
                            <label for="clear_ghl_token">Disconnect</label>
                            <div class="hint">Clears the saved token when you save.</div>
                        </div>
                    </div>
                </div>
                <div class="card-foot">
                    <button type="submit" class="btn btn-sm" formaction="<?= View::e(View::url('settings/test/ghl')) ?>"
                            formmethod="post">
                        Test the connection
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Sign-in security</h2></div>
                <div class="card-body">
                    <p class="dim small">
                        Billy and Darren sign in with their email address alone — no password. That is
                        convenient, and it does mean anyone who knows the address and the site URL can
                        read their leads. If that stops being an acceptable trade, turn on
                        <strong>requires password</strong> for them on the
                        <a href="<?= View::e(View::url('users')) ?>">Users</a> screen; they already have
                        <code>44i123</code> set, so nothing else needs changing.
                    </p>
                    <p class="dim small">
                        Change the default password on every account before this goes anywhere public.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt">
        <button type="submit" class="btn btn-primary">Save settings</button>
        <span class="muted small">Secrets already saved stay put unless you type a replacement.</span>
    </div>
</form>
