<?php

use Prospector\Auth;
use Prospector\Leads;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\View;
use Prospector\Users;

/**
 * Home: who to contact next, and how the week is going.
 *
 * Built from what people log — actions, statuses, follow-up reminders — so the
 * page fills itself in as the work is done. The queue's buttons work without
 * leaving the page; app.js handles them, and without JavaScript they are plain
 * forms that post and come back.
 *
 * @var int|null $scopeUser  whose dashboard — null is the whole team
 * @var array{due: list<array<string, mixed>>, stalled: list<array<string, mixed>>, cold: list<array<string, mixed>>, fresh: list<array<string, mixed>>} $queue
 * @var array{this: array<string, int>, last: array<string, int>, days: list<array{label: string, date: string, count: int, today: bool}>, goal: int} $board
 * @var list<array{date: string, label: string, sub: string, today: bool, items: list<array<string, mixed>>}> $upcoming
 * @var list<array{key: string, label: string, count: int, rate: int|null, link: array<string, string>}> $funnel
 * @var list<array<string, mixed>> $owners
 * @var list<array{user: array<string, mixed>, run: array<string, mixed>|null}> $todaysRuns
 * @var string $scheduleText
 * @var array<string, mixed>|null $currentUser
 * @var string $csrf
 */

$isAdmin = Auth::isAdmin();
$hour = (int) Clock::local()->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = $currentUser !== null ? explode(' ', (string) $currentUser['name'])[0] : '';
$ownerParam = $scopeUser !== null && $isAdmin ? ['owner' => (string) $scopeUser] : [];

$sections = [
    'due' => ['title' => 'Follow-ups due', 'hint' => 'Reminders that have come round', 'tone' => 'due'],
    'stalled' => ['title' => 'Reached out, nothing since', 'hint' => 'Two days or more without a reply logged', 'tone' => 'stalled'],
    'cold' => ['title' => 'Going cold', 'hint' => 'In play, but nothing for ten days', 'tone' => 'cold'],
    'fresh' => ['title' => 'Fresh and untouched', 'hint' => 'Best fit first', 'tone' => 'fresh'],
];

$todo = count($queue['due']) + count($queue['stalled']) + count($queue['cold']);

// How a row explains why it is here.
$why = static function (string $section, array $lead): string {
    $since = static fn (?string $when): string => match ($label = Leads::sinceLabel($when)) {
        '' => 'a while ago', 'today' => 'today', 'yesterday' => 'yesterday', default => $label . ' ago',
    };

    return match ($section) {
        'due' => (Clock::display((string) $lead['reminder_due'], 'Y-m-d') === Clock::today()
                ? 'Due ' . Clock::display((string) $lead['reminder_due'], 'g:ia') . ' today'
                : 'Due since ' . Clock::display((string) $lead['reminder_due'], 'D M j'))
            . (!empty($lead['reminder_note']) ? ' — ' . $lead['reminder_note'] : ''),
        'stalled' => Leads::actionLabel((string) $lead['last_action']) . ' ' . $since($lead['last_action_at']) . ', nothing since',
        'cold' => ((string) $lead['status'] === 'meeting' ? 'Meeting booked' : Leads::statusLabel((string) $lead['status']))
            . ' · last touch ' . $since($lead['last_action_at'] ?? $lead['updated_at']),
        default => 'Fit ' . (int) $lead['fit_score'] . (!empty($lead['vertical']) ? ' · ' . $lead['vertical'] : ''),
    };
};

$initials = static fn (string $name): string => Users::initials($name);
$phoneOf = static function (array $lead): string {
    $raw = (string) (($lead['direct_phone'] ?? '') !== '' ? $lead['direct_phone'] : ($lead['phone'] ?? ''));

    return preg_replace('/[^0-9+]/', '', $raw) ?? '';
};

// The week, for the scoreboard.
$touches = (int) $board['this']['touches'];
$goal = max(1, (int) $board['goal']);
$ringPct = min(100, (int) round($touches / $goal * 100));
$circumference = 2 * M_PI * 52;
$peakDay = max(1, (int) ceil($goal / 5), ...array_map(static fn ($d) => $d['count'], $board['days']));

$tiles = [
    'calls' => ['label' => 'Calls', 'icon' => 'phone'],
    'emails' => ['label' => 'Emails', 'icon' => 'mail'],
    'meetings' => ['label' => 'Meetings', 'icon' => 'clock'],
    'wins' => ['label' => 'Signed', 'icon' => 'zap'],
];

$funnelTop = max(1, (int) ($funnel[0]['count'] ?? 1));
?>

<?php $showOwners = $isAdmin; require __DIR__ . '/partials/notices.php'; ?>

<div class="dash" data-dash>

<div class="page-head dash-head reveal" style="--i:0">
    <div>
        <h1><?= View::e($greeting . ($firstName !== '' ? ', ' . $firstName : '')) ?></h1>
        <div class="sub">
            <?= View::e(Clock::local()->format('l, F j')) ?> ·
            <?php if ($todo > 0): ?>
                <strong data-todo-count><?= $todo ?></strong> <?= $todo === 1 ? 'lead needs' : 'leads need' ?> a follow-up
            <?php else: ?>
                nothing waiting on you
            <?php endif; ?>
        </div>
    </div>
    <div class="page-head-actions">
        <?php if ($isAdmin && $owners !== []): ?>
            <form method="get" action="<?= View::e(View::url('dashboard')) ?>" class="dash-owner">
                <select name="owner" aria-label="Whose dashboard" onchange="this.form.submit()">
                    <option value="">The whole team</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= (int) $owner['id'] ?>" <?= $scopeUser === (int) $owner['id'] ? 'selected' : '' ?>>
                            <?= View::e($owner['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a class="btn" href="<?= View::e(View::url('leads', $ownerParam)) ?>">
            <?php $name = 'list'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            All leads
        </a>
    </div>
</div>

<?php /* ------------------------------------------------ the week */ ?>
<section class="dash-week" aria-label="This week">
    <div class="card dash-goal reveal" style="--i:1">
        <div class="dash-goal-ring">
            <svg viewBox="0 0 120 120" role="img"
                 aria-label="<?= $touches ?> of <?= $goal ?> touches this week">
                <circle class="ring-track" cx="60" cy="60" r="52"/>
                <circle class="ring-fill" cx="60" cy="60" r="52" data-ring
                        style="--c: <?= round($circumference, 2) ?>; --p: <?= $ringPct ?>"/>
            </svg>
            <div class="ring-label">
                <span class="ring-num" data-count="<?= $touches ?>" data-score="touches"><?= $touches ?></span>
                <span class="ring-of">of <span data-score-goal><?= $goal ?></span></span>
            </div>
        </div>
        <div class="dash-goal-body">
            <div class="tile-label">Touches this week</div>
            <div class="dash-goal-note">
                <?php if ($touches >= $goal): ?>
                    Goal hit. Everything from here is extra.
                <?php else: ?>
                    <span data-score-left><?= $goal - $touches ?></span> to go — every call, email and text counts.
                <?php endif; ?>
            </div>
            <div class="week-bars" role="img" aria-label="Touches each day this week">
                <?php foreach ($board['days'] as $day): ?>
                    <div class="week-bar<?= $day['today'] ? ' is-today' : '' ?>" title="<?= View::e($day['label']) ?>: <?= (int) $day['count'] ?>">
                        <span class="week-bar-fill" style="--h: <?= round($day['count'] / $peakDay * 100) ?>%"
                              <?= $day['today'] ? 'data-today-bar data-peak="' . $peakDay . '" data-n="' . (int) $day['count'] . '"' : '' ?>></span>
                        <span class="week-bar-day"><?= View::e(substr($day['label'], 0, 1)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php $n = 2; foreach ($tiles as $key => $tile): ?>
        <?php
        $now = (int) $board['this'][$key];
        $before = (int) $board['last'][$key];
        $delta = $now - $before;
        ?>
        <div class="card dash-stat reveal" style="--i:<?= $n++ ?>" data-stat="<?= View::e($key) ?>">
            <div class="dash-stat-top">
                <span class="dash-stat-icon dash-stat-<?= View::e($key) ?>">
                    <?php $name = $tile['icon']; $size = 16; require __DIR__ . '/partials/icon.php'; ?>
                </span>
                <span class="tile-label"><?= View::e($tile['label']) ?></span>
            </div>
            <div class="dash-stat-num" data-count="<?= $now ?>" data-score="<?= View::e($key) ?>"><?= $now ?></div>
            <div class="dash-delta <?= $delta > 0 ? 'is-up' : ($delta < 0 ? 'is-down' : 'is-flat') ?>" data-delta="<?= View::e($key) ?>" data-last="<?= $before ?>">
                <?php if ($delta > 0): ?>▲ <?= $delta ?> vs last week
                <?php elseif ($delta < 0): ?>▼ <?= abs($delta) ?> vs last week
                <?php else: ?>Same as last week<?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<div class="dash-main">

<?php /* ------------------------------------------------ the queue */ ?>
<section class="card dash-queue reveal" style="--i:6" aria-label="Who to contact next">
    <div class="card-head">
        <h2>Who to contact next</h2>
        <span class="pill" data-queue-total><?= $todo + count($queue['fresh']) ?></span>
    </div>

    <div class="queue-empty"<?= ($todo + count($queue['fresh'])) > 0 ? ' hidden' : '' ?> data-queue-empty>
        <div class="queue-empty-mark">
            <?php $name = 'check'; $size = 34; require __DIR__ . '/partials/icon.php'; ?>
        </div>
        <h3>All caught up</h3>
        <p class="dim">No follow-ups due and nothing going cold. The next batch arrives at <?= View::e($scheduleText) ?>.</p>
    </div>

    <?php $row = 0; foreach ($sections as $key => $section): ?>
        <?php if ($queue[$key] === []) { continue; } ?>
        <div class="q-section q-<?= View::e($section['tone']) ?>" data-section="<?= View::e($key) ?>">
            <div class="q-section-head">
                <span class="q-dot"></span>
                <h3><?= View::e($section['title']) ?></h3>
                <span class="q-count" data-section-count><?= count($queue[$key]) ?></span>
                <span class="q-hint"><?= View::e($section['hint']) ?></span>
            </div>

            <ul class="q-list" data-limit="5">
                <?php foreach ($queue[$key] as $i => $lead): ?>
                    <?php $tel = $phoneOf($lead); ?>
                    <li class="q-row<?= $i >= 5 ? ' is-extra' : '' ?>" style="--i:<?= min(12, $row++) ?>">
                        <div class="q-avatar" aria-hidden="true"><?= View::e($initials((string) ($lead['decision_maker'] ?: $lead['company']))) ?></div>

                        <div class="q-main">
                            <a class="q-company" href="<?= View::e(View::url('leads/' . (int) $lead['id'])) ?>"><?= View::e($lead['company']) ?></a>
                            <div class="q-who">
                                <?= View::e(trim((string) ($lead['decision_maker'] ?? '')) ?: 'Nobody named yet') ?>
                                <?php if (!empty($lead['title'])): ?><span class="dim"> · <?= View::e($lead['title']) ?></span><?php endif; ?>
                                <?php if ($isAdmin && $scopeUser === null): ?><span class="q-owner"><?= View::e($lead['owner_name']) ?></span><?php endif; ?>
                            </div>
                            <div class="q-why"><?= View::e($why($key, $lead)) ?></div>
                        </div>

                        <div class="q-actions">
                            <?php if ($tel !== ''): ?>
                                <a class="q-btn q-call" href="tel:<?= View::e($tel) ?>" title="Call <?= View::e($lead['decision_maker'] ?? $lead['company']) ?>">
                                    <?php $name = 'phone'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                                    <span class="sr-only">Call</span>
                                </a>
                            <?php endif; ?>

                            <form method="post" action="<?= View::e(View::url('dashboard/act')) ?>" class="q-form">
                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                                <input type="hidden" name="op" value="log">
                                <button type="submit" class="q-btn" name="log_action" value="called">Called</button>
                                <button type="submit" class="q-btn" name="log_action" value="voicemail" title="Left a voicemail">VM</button>
                                <button type="submit" class="q-btn" name="log_action" value="emailed">Emailed</button>
                            </form>

                            <?php if ($key === 'due'): ?>
                                <form method="post" action="<?= View::e(View::url('dashboard/act')) ?>" class="q-form q-form-reminder">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                                    <input type="hidden" name="reminder_id" value="<?= (int) $lead['reminder_id'] ?>">
                                    <button type="submit" class="q-btn q-quiet" name="op" value="snooze" title="Move it two working days on">Snooze</button>
                                    <button type="submit" class="q-btn q-quiet" name="op" value="done" title="Mark this follow-up done">Done</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (count($queue[$key]) > 5): ?>
                <button type="button" class="q-more" data-more>Show <?= count($queue[$key]) - 5 ?> more</button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<aside class="dash-side">

    <?php /* ------------------------------------------- the next 7 days */ ?>
    <section class="card reveal" style="--i:7" aria-label="Follow-ups this week">
        <div class="card-head">
            <h2>Next 7 days</h2>
            <span class="muted small"><?= array_sum(array_map(static fn ($d) => count($d['items']), $upcoming)) ?> scheduled</span>
        </div>
        <ol class="days">
            <?php foreach ($upcoming as $day): ?>
                <li class="day<?= $day['today'] ? ' is-today' : '' ?><?= $day['items'] === [] ? ' is-empty' : '' ?>">
                    <div class="day-when">
                        <span class="day-name"><?= View::e($day['label']) ?></span>
                        <span class="day-date"><?= View::e($day['sub']) ?></span>
                    </div>
                    <div class="day-items">
                        <?php if ($day['items'] === []): ?>
                            <span class="day-none">—</span>
                        <?php else: ?>
                            <?php foreach (array_slice($day['items'], 0, 3) as $item): ?>
                                <a class="day-item" href="<?= View::e(View::url('leads/' . (int) $item['lead_id'])) ?>"
                                   title="<?= View::e(trim((string) ($item['note'] ?? '')) ?: (string) $item['company']) ?>">
                                    <span class="day-time"><?= View::e($item['time']) ?></span>
                                    <?= View::e($item['company']) ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if (count($day['items']) > 3): ?>
                                <span class="day-more">+<?= count($day['items']) - 3 ?> more</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <?php /* ------------------------------------------- the funnel */ ?>
    <section class="card reveal" style="--i:8" aria-label="Pipeline">
        <div class="card-head">
            <h2>Pipeline</h2>
            <span class="muted small">every lead, all time</span>
        </div>
        <div class="card-body">
            <ol class="funnel">
                <?php foreach ($funnel as $i => $stage): ?>
                    <li class="funnel-step funnel-<?= View::e($stage['key']) ?>">
                        <?php if ($stage['rate'] !== null): ?>
                            <span class="funnel-rate" title="of the step before"><?= (int) $stage['rate'] ?>%</span>
                        <?php endif; ?>
                        <a class="funnel-row" href="<?= View::e(View::url('leads', $stage['link'] + $ownerParam)) ?>">
                            <span class="funnel-label"><?= View::e($stage['label']) ?></span>
                            <span class="funnel-track">
                                <span class="funnel-bar" style="--w: <?= max(3, round($stage['count'] / $funnelTop * 100)) ?>%; --i: <?= $i ?>"></span>
                            </span>
                            <span class="funnel-num" data-count="<?= (int) $stage['count'] ?>"><?= (int) $stage['count'] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

</aside>
</div>

<?php /* ------------------------------------------------ today's batches */ ?>
<?php if ($todaysRuns !== []): ?>
    <section class="card dash-batches reveal" style="--i:9" aria-label="Today's batches">
        <div class="card-head">
            <h2>Today's batches</h2>
            <a class="btn btn-sm btn-ghost" href="<?= View::e(View::url('runs')) ?>">All batches</a>
        </div>
        <div class="batch-strip">
            <?php foreach ($todaysRuns as $entry): ?>
                <?php $run = $entry['run']; $owner = $entry['user']; $status = $run === null ? 'pending' : (string) $run['status']; ?>
                <div class="batch-chip">
                    <div class="avatar"><?= View::e(Users::initials((string) $owner['name'])) ?></div>
                    <div class="batch-chip-body">
                        <div class="cell-primary"><?= View::e($owner['name']) ?></div>
                        <div class="cell-sub">
                            <?php if ($run === null): ?>Not run yet · <?= View::e($scheduleText) ?>
                            <?php elseif ($status === 'running'): ?>Running now
                            <?php elseif ($status === 'failed'): ?><span style="color: var(--danger)">Failed</span> — <?= View::e(mb_strimwidth((string) $run['error'], 0, 60, '…')) ?>
                            <?php else: ?><?= (int) $run['lead_count'] ?> leads · <?= View::e((string) $run['vertical']) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($run !== null): ?>
                        <a class="btn btn-sm" href="<?= View::e(View::url('runs/' . $run['id'])) ?>">Open</a>
                    <?php else: ?>
                        <form method="post" action="<?= View::e(View::url('runs/start')) ?>" data-busy="Starting…"
                              data-confirm="Run the <?= View::e(Runs::loopLabel((string) $owner['loop'])) ?> batch for <?= View::e($owner['name']) ?> now?">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $owner['id'] ?>">
                            <input type="hidden" name="send_email" value="1">
                            <button type="submit" class="btn btn-sm">Run now</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="dash-toast" role="status" aria-live="polite" data-toast hidden></div>
</div>
