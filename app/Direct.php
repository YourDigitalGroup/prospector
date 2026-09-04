<?php

declare(strict_types=1);

namespace Prospector;

/**
 * Send one message to one lead, written on the spot.
 *
 * The cadence in Outreach is for a planned sequence: six steps, drafted by a
 * model, approved, scheduled. This is the other half — the reply to something
 * they said, the follow-up after a call, the one-off that does not belong to a
 * sequence at all. Nothing is drafted, nothing is scheduled, and nothing is
 * stored in the emails table; it goes now or it does not go.
 *
 * Everything leaves through the owner's own GoHighLevel sub-account, which is
 * the whole reason this is gated on a private integration being connected.
 * Prospector has SMTP credentials of its own, but they are for the daily brief
 * — an internal mail to a colleague. Sending prospecting mail through them
 * would put cold outreach on the same domain reputation as the tool's own
 * notifications, and it would leave the conversation invisible to the CRM the
 * seller actually works in. Going through GoHighLevel means the sending domain,
 * the unsubscribe handling and the reply routing are all the ones already set
 * up for that seller, and the message lands in the contact's timeline where
 * their reply will land too.
 *
 * The lead does not have to be in GoHighLevel first. If there is no contact
 * yet, one is created and the lead is marked as synced — the same thing
 * Emails::send does for a cadence step, for the same reason: being asked to go
 * and press another button before you can answer somebody is not a safeguard,
 * it is just an extra step.
 */
final class Direct
{
    /** How much of the body goes on the timeline before it is cut. */
    private const ACTIVITY_EXCERPT = 140;

    /**
     * Can this owner send at all?
     *
     * Separated from send() because the screen needs the answer before anyone
     * types anything — an explanation up front beats a form that fails on
     * submit.
     *
     * @param array<string, mixed>|null $owner
     * @return array{ok: bool, reason: string}
     */
    public static function available(?array $owner): array
    {
        if ($owner === null) {
            return ['ok' => false, 'reason' => 'That lead has no owner to send as.'];
        }

        if (GoHighLevel::forUser($owner) === null) {
            return [
                'ok' => false,
                'reason' => (string) ($owner['name'] ?? 'This account')
                    . ' has no GoHighLevel private integration connected, and mail goes out through it.',
            ];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Whether a lead can be reached on a given channel, and why not.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, reason: string}
     */
    public static function reachable(array $lead, string $channel): array
    {
        if (self::channel($channel) === 'SMS') {
            $phone = trim((string) ($lead['direct_phone'] ?? '')) !== ''
                ? (string) $lead['direct_phone']
                : (string) ($lead['phone'] ?? '');

            return trim($phone) !== ''
                ? ['ok' => true, 'reason' => '']
                : ['ok' => false, 'reason' => 'No phone number on file — dig for one first.'];
        }

        return Outreach::deliverability($lead);
    }

    /**
     * Send it.
     *
     * @param array<string, mixed> $lead
     * @param array{subject?: string, body?: string, channel?: string, confirm_unverified?: bool, signature?: bool} $message
     * @return array{ok: bool, message: string}
     */
    public static function send(array $lead, array $message, ?int $actorId = null): array
    {
        $channel = self::channel((string) ($message['channel'] ?? 'Email'));
        $body = trim((string) ($message['body'] ?? ''));

        if ($body === '') {
            return ['ok' => false, 'message' => 'Write something first — nothing was sent.'];
        }

        $owner = Users::find((int) $lead['user_id']);
        $available = self::available($owner);

        if (!$available['ok']) {
            return ['ok' => false, 'message' => $available['reason']];
        }

        $reachable = self::reachable($lead, $channel);
        if (!$reachable['ok']) {
            return ['ok' => false, 'message' => $reachable['reason']];
        }

        // A 'pattern' address was inferred from the shape of other addresses at
        // the domain and never confirmed. Bulk sends refuse it outright; a
        // deliberate one-off to somebody you picked is a different decision, so
        // this asks rather than refuses — but it does ask.
        if ($channel === 'Email'
            && Outreach::isUnverified($lead)
            && ($message['confirm_unverified'] ?? false) !== true) {
            return [
                'ok' => false,
                'message' => (string) $lead['email'] . ' has not been confirmed, so it may bounce. '
                    . 'Tick the box to send anyway.',
            ];
        }

        /** @var GoHighLevel $client */
        $client = GoHighLevel::forUser($owner);
        $leadId = (int) $lead['id'];
        $contactId = trim((string) ($lead['ghl_contact_id'] ?? ''));

        if ($contactId === '') {
            $push = $client->pushLead($lead);

            if (!$push['ok']) {
                return ['ok' => false, 'message' => 'Could not create the GoHighLevel contact: ' . $push['message']];
            }

            $contactId = (string) ($push['contact_id'] ?? '');
            Leads::markSyncedToGhl($leadId, $contactId);
            Leads::addActivity($leadId, $actorId, 'ghl', 'Pushed to GoHighLevel to send a message');
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        if ($channel === 'Email' && $subject === '') {
            $subject = self::defaultSubject($lead);
        }

        // A signature belongs on an email and not on a text, where it would eat
        // most of the message and read as a machine wrote it.
        $outgoing = $channel === 'Email' && ($message['signature'] ?? true) !== false
            ? self::signed($body, $owner)
            : $body;

        $result = $client->sendMessage($contactId, $channel, $outgoing, $subject);

        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        Leads::addActivity(
            $leadId,
            $actorId,
            $channel === 'Email' ? 'email' : 'note',
            $channel === 'Email'
                ? 'Emailed: ' . $subject
                : 'Texted: ' . mb_strimwidth($body, 0, self::ACTIVITY_EXCERPT, '…')
        );

        // The same real-world event a cadence step 1 stands for: this lead has
        // now actually been contacted. Enrolment is deduplicated per lead and
        // workflow, so a cadence firing the same event later is harmless.
        if ($channel === 'Email') {
            $fresh = Leads::find($leadId);
            if ($fresh !== null) {
                Automations::apply('email_sent', $fresh, $actorId);
            }
        }

        return [
            'ok' => true,
            'message' => $channel === 'Email'
                ? 'Sent to ' . (string) $lead['email'] . '.'
                : 'Text sent.',
        ];
    }

    /**
     * The default subject, when nobody typed one.
     *
     * Their company rather than ours: a subject line that opens with the
     * sender's name reads as a mailshot, and this is the opposite of one.
     *
     * @param array<string, mixed> $lead
     */
    public static function defaultSubject(array $lead): string
    {
        return mb_substr((string) $lead['company'], 0, 190);
    }

    /**
     * The body with the owner's sign-off on the end.
     *
     * Left alone if the signature is already in there, so re-sending an edited
     * draft that was signed once does not sign it twice.
     *
     * @param array<string, mixed>|null $owner
     */
    public static function signed(string $body, ?array $owner): string
    {
        $signature = self::signature($owner);

        if ($signature === '' || str_contains($body, $signature)) {
            return $body;
        }

        return rtrim($body) . "\n\n" . $signature;
    }

    /** @param array<string, mixed>|null $owner */
    public static function signature(?array $owner): string
    {
        return trim((string) ($owner['email_signature'] ?? ''));
    }

    /**
     * What a signature says when nobody has written one, offered on the setup
     * screen as a starting point rather than applied silently — a sign-off
     * nobody chose is worse than none.
     *
     * @param array<string, mixed> $owner
     */
    public static function suggestedSignature(array $owner): string
    {
        return trim((string) $owner['name']) . "\n44i\n" . trim((string) $owner['email']);
    }

    private static function channel(string $channel): string
    {
        return strtoupper(trim($channel)) === 'SMS' ? 'SMS' : 'Email';
    }
}
