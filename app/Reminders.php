<?php

declare(strict_types=1);

namespace Prospector;

use DateTimeImmutable;
use DateTimeZone;
use Prospector\Support\Clock;
use Prospector\Support\Database;
use Prospector\Support\Settings;

/**
 * Follow-up reminders: "remind me about this lead on Thursday at 2".
 *
 * A reminder belongs to the person who set it, not to the lead's owner — an
 * admin setting one on Darren's lead is asking to be reminded themselves.
 *
 * It does two separate jobs, because either can fail without the other:
 *
 *   - **It sends an email when it comes due.** That needs something to wake the
 *     app up at the right time — the cron webhook, or the page-load sweep below
 *     as a backstop — and if nothing does, the email is late.
 *   - **It shows as due in the app** until it is dealt with, which needs nothing
 *     at all. So a reminder whose email was late, or went to spam, is still
 *     sitting there in the top bar.
 *
 * It is dealt with when the person presses Done, or logs an action on that lead
 * after it came due — having called them back is the follow-up, and the
 * reminder should not go on asking.
 */
final class Reminders
{
    /** Attempts at sending before a reminder's email is given up on. */
    private const MAX_ATTEMPTS = 3;

    /**
     * Turn a local date and time — what a person types — into the stored UTC
     * timestamp. Null when it does not parse.
     */
    public static function fromLocal(string $date, string $time): ?string
    {
        $date = trim($date);
        $time = trim($time) === '' ? '09:00' : trim($time);

        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($time, 0, 5), Clock::timezone());

        if ($local === false) {
            return null;
        }

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The one-tap choices. Each is a local date and time, worked out from now in
     * business time so "tomorrow at 9" means 9 in Sioux Falls, not on the server.
     *
     * Weekends are stepped over. A follow-up call set for Saturday is a follow-up
     * call that happens on Monday anyway, and the email would land when nobody
     * is working.
     *
     * @return array<string, array{label: string, date: string, time: string}>
     */
    public static function presets(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::local();

        $weekday = static function (DateTimeImmutable $day): DateTimeImmutable {
            while ((int) $day->format('N') >= 6) {
                $day = $day->modify('+1 day');
            }

            return $day;
        };

        $at = static fn (DateTimeImmutable $day, string $label, string $time = '09:00'): array => [
            'label' => $label,
            'date' => $day->format('Y-m-d'),
            'time' => $time,
        ];

        $tomorrow = $weekday($now->modify('+1 day'));
        $twoDays = $weekday($now->modify('+2 days'));
        $nextWeek = $weekday($now->modify('+7 days'));

        return [
            'tomorrow' => $at($tomorrow, 'Tomorrow, 9am'),
            'two_days' => $at($twoDays, $twoDays->format('l') . ', 9am'),
            'next_week' => $at($nextWeek, 'Next ' . $nextWeek->format('l') . ', 9am'),
        ];
    }

    /** @return int the new reminder's id, or 0 when the time does not make sense */
    public static function create(int $leadId, int $userId, string $dueAtUtc, string $note = ''): int
    {
        if (strtotime($dueAtUtc) === false) {
            return 0;
        }

        $id = Database::insert('reminders', [
            'lead_id' => $leadId,
            'user_id' => $userId,
            'due_at' => $dueAtUtc,
            'note' => trim($note) === '' ? null : mb_substr(trim($note), 0, 1000),
            'attempts' => 0,
            'created_at' => Clock::now(),
        ]);

        Leads::addActivity(
            $leadId,
            $userId,
            'reminder',
            'Follow-up reminder set for ' . Clock::display($dueAtUtc, 'D M j, g:ia')
                . (trim($note) !== '' ? ' — ' . mb_strimwidth(trim($note), 0, 120, '…') : '')
        );

        return $id;
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM reminders WHERE id = :id', ['id' => $id]);
    }

    /**
     * Mark a reminder dealt with. The same thing whether it is being cancelled
     * before it came due or ticked off after: either way it should stop
     * showing, and stop being sent.
     */
    public static function done(int $id): void
    {
        Database::run(
            'UPDATE reminders SET done_at = :now WHERE id = :id AND done_at IS NULL',
            ['now' => Clock::now(), 'id' => $id]
        );
    }

    /**
     * Logging an action on a lead after a reminder came due is the follow-up.
     * Only due ones: a call today does not answer a reminder set for next week.
     */
    public static function completeDueFor(int $leadId, int $userId): int
    {
        return Database::run(
            'UPDATE reminders SET done_at = :now
             WHERE lead_id = :lead AND user_id = :user AND done_at IS NULL AND due_at <= :due',
            ['now' => Clock::now(), 'lead' => $leadId, 'user' => $userId, 'due' => Clock::now()]
        )->rowCount();
    }

    /**
     * Open reminders on a lead — not yet dealt with, whether or not they have
     * come due — soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function openFor(int $leadId): array
    {
        return Database::all(
            'SELECT r.*, u.name AS user_name FROM reminders r JOIN users u ON u.id = r.user_id
             WHERE r.lead_id = :lead AND r.done_at IS NULL
             ORDER BY r.due_at ASC',
            ['lead' => $leadId]
        );
    }

    /** How many of this person's reminders have come due and not been dealt with. */
    public static function dueCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM reminders r JOIN leads l ON l.id = r.lead_id
             WHERE r.user_id = :user AND r.done_at IS NULL AND r.due_at <= :now
               AND l.archived_at IS NULL',
            ['user' => $userId, 'now' => Clock::now()]
        );
    }

    public static function isDue(array $reminder): bool
    {
        return strtotime((string) $reminder['due_at']) <= strtotime(Clock::now());
    }

    /**
     * Email every reminder that has come due and not been emailed yet.
     *
     * Safe to call from several places at once — the cron webhook, the daily
     * script, a page load — because each reminder is claimed with a conditional
     * UPDATE before anything is sent, and only the caller whose UPDATE changed
     * a row goes on to send it. Two overlapping sweeps cannot both email the
     * same reminder.
     *
     * The sender is a parameter so the checks can catch what would have gone
     * out without a mail server. In the app it is always Mailer::send.
     *
     * @param callable(string, string, string, string): array{ok: bool, message: string} $send
     * @return array{sent: int, failed: int, messages: list<string>}
     */
    public static function sendDue(int $limit = 25, ?callable $send = null): array
    {
        $send ??= [Mailer::class, 'send'];
        $now = Clock::now();
        $out = ['sent' => 0, 'failed' => 0, 'messages' => []];

        $due = Database::all(
            'SELECT r.id FROM reminders r JOIN leads l ON l.id = r.lead_id
             WHERE r.sent_at IS NULL AND r.done_at IS NULL AND r.due_at <= :now
               AND r.attempts < :max AND l.archived_at IS NULL
             ORDER BY r.due_at ASC LIMIT ' . max(1, $limit),
            ['now' => $now, 'max' => self::MAX_ATTEMPTS]
        );

        foreach ($due as $row) {
            $id = (int) $row['id'];

            // The claim. Whoever changes the row owns the send.
            $claimed = Database::run(
                'UPDATE reminders SET sent_at = :now, attempts = attempts + 1
                 WHERE id = :id AND sent_at IS NULL AND done_at IS NULL',
                ['now' => $now, 'id' => $id]
            )->rowCount();

            if ($claimed !== 1) {
                continue;
            }

            $reminder = self::find($id);
            $lead = $reminder !== null ? Leads::find((int) $reminder['lead_id']) : null;
            $user = $reminder !== null ? Users::find((int) $reminder['user_id']) : null;

            if ($reminder === null || $lead === null || $user === null) {
                continue;
            }

            $result = $send(
                (string) $user['email'],
                (string) $user['name'],
                self::subject($lead),
                self::emailHtml($reminder, $lead, $user)
            );

            if ($result['ok']) {
                $out['sent']++;
                Database::run('UPDATE reminders SET error = NULL WHERE id = :id', ['id' => $id]);
                continue;
            }

            // Release the claim so the next sweep tries again, up to the limit.
            // The reminder still shows as due in the app regardless.
            Database::run(
                'UPDATE reminders SET sent_at = NULL, error = :error WHERE id = :id',
                ['error' => mb_substr($result['message'], 0, 500), 'id' => $id]
            );
            $out['failed']++;
            $out['messages'][] = (string) $lead['company'] . ': ' . $result['message'];
        }

        return $out;
    }

    /**
     * The page-load backstop.
     *
     * Reminder emails are only as punctual as whatever wakes the app up, and the
     * scheduled webhook is not always configured. So any signed-in page load
     * also sweeps for due reminders — after the page has been handed to the
     * browser, so nobody waits on a mail server to see their leads.
     *
     * Only where the response can genuinely be finished first. Elsewhere a slow
     * mail server would hold up somebody's page, and that is worse than a late
     * reminder that is already showing as due in the top bar. Throttled to once
     * a minute across the whole install.
     */
    public static function sweepAfterResponse(): void
    {
        $finish = function_exists('fastcgi_finish_request')
            ? 'fastcgi_finish_request'
            : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);

        if ($finish === null) {
            return;
        }

        register_shutdown_function(static function () use ($finish): void {
            try {
                $last = Settings::get('reminders_swept_at');

                if ($last !== '' && strtotime(Clock::now()) - (int) strtotime($last) < 60) {
                    return;
                }

                // Only worth finishing the response early when there is work.
                $pending = Database::scalar(
                    'SELECT id FROM reminders WHERE sent_at IS NULL AND done_at IS NULL
                     AND due_at <= :now AND attempts < :max LIMIT 1',
                    ['now' => Clock::now(), 'max' => self::MAX_ATTEMPTS]
                );

                Settings::set('reminders_swept_at', Clock::now());

                if ($pending === null) {
                    return;
                }

                $finish();
                @set_time_limit(120);
                self::sendDue(10);
            } catch (\Throwable $e) {
                // A reminder sweep must never turn a page that rendered fine
                // into an error. The next sweep tries again.
                Support\Background::log('Reminder sweep failed: ' . $e->getMessage());
            }
        });
    }

    /** @param array<string, mixed> $lead */
    public static function subject(array $lead): string
    {
        $who = trim((string) ($lead['decision_maker'] ?? ''));

        return 'Follow up: ' . (string) $lead['company'] . ($who !== '' ? ' — ' . $who : '');
    }

    /**
     * @param array<string, mixed> $reminder
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $user
     */
    public static function emailHtml(array $reminder, array $lead, array $user): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $url = rtrim(Settings::publicUrl(), '/') . '/leads/' . (int) $lead['id'];
        $first = Merge::splitName((string) $user['name'])['first'] ?: (string) $user['name'];

        $rows = [];
        $add = static function (string $label, mixed $value) use (&$rows, $e): void {
            $value = trim((string) $value);
            if ($value !== '') {
                $rows[] = '<tr><td style="padding:4px 14px 4px 0;color:#6b7280;white-space:nowrap;vertical-align:top">'
                    . $e($label) . '</td><td style="padding:4px 0;color:#111827">' . $e($value) . '</td></tr>';
            }
        };

        $add('Contact', trim((string) ($lead['decision_maker'] ?? '') . (empty($lead['title']) ? '' : ', ' . $lead['title'])));
        $add('Direct phone', $lead['direct_phone'] ?? '');
        $add('Phone', $lead['phone'] ?? '');
        $add('Email', $lead['email'] ?? '');
        if (!empty($lead['last_action'])) {
            $add('Last action', Leads::actionLabel((string) $lead['last_action'])
                . ', ' . Clock::display((string) $lead['last_action_at'], 'D M j'));
        }
        $add('Status', Leads::statusLabel((string) $lead['status']));

        $note = trim((string) ($reminder['note'] ?? ''));

        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f5f7;'
            . 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;padding:28px">'
            . '<p style="margin:0 0 6px;color:#6b7280;font-size:13px">Follow-up reminder · '
            . $e(Clock::display((string) $reminder['due_at'], 'D M j, g:ia T')) . '</p>'
            . '<h1 style="margin:0 0 16px;font-size:22px;color:#111827">' . $e($lead['company']) . '</h1>'
            . '<p style="margin:0 0 16px;color:#111827">Hi ' . $e($first) . ', you asked to be reminded to follow up here.</p>'
            . ($note !== ''
                ? '<div style="margin:0 0 18px;padding:12px 14px;background:#fff7ed;border-left:3px solid #ff004c;'
                    . 'color:#111827">' . nl2br($e($note)) . '</div>'
                : '')
            . '<table style="border-collapse:collapse;font-size:14px;margin:0 0 22px">' . implode('', $rows) . '</table>'
            . '<a href="' . $e($url) . '" style="display:inline-block;background:#111827;color:#fff;'
            . 'text-decoration:none;padding:11px 18px;border-radius:8px;font-weight:600">Open the lead</a>'
            . '<p style="margin:22px 0 0;color:#9ca3af;font-size:12px">Log a call or an email on the lead and this'
            . ' reminder is marked done.</p>'
            . '</div></body></html>';
    }
}
