<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\RichText;

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
     * @param array{subject?: string, body?: string, channel?: string, to?: string, signature?: bool, attachments?: list<string>} $message
     * @return array{ok: bool, message: string}
     */
    public static function send(array $lead, array $message, ?int $actorId = null): array
    {
        $channel = self::channel((string) ($message['channel'] ?? 'Email'));

        // The composer posts HTML for email and plain text for SMS. Cleaning
        // happens here rather than at the call site so nothing can reach
        // GoHighLevel without going through it.
        $body = $channel === 'Email'
            ? RichText::clean((string) ($message['body'] ?? ''))
            : trim((string) ($message['body'] ?? ''));

        if ($channel === 'Email' ? RichText::isEmpty($body) : $body === '') {
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

        // Somebody corrected the address in the compose box. Applied to the lead
        // before anything is sent, and pushed to the GoHighLevel contact below,
        // so the address that receives this is the address on file afterwards.
        //
        // Correcting rather than overriding for one send is the deliberate
        // choice: GoHighLevel addresses a message by contact id, so an override
        // that its API declined to honour would send to the old address while
        // the screen said otherwise. Wrong recipient, silently, is the one
        // failure this must not have.
        $retarget = trim((string) ($message['to'] ?? ''));

        if ($channel === 'Email' && $retarget !== '' && strcasecmp($retarget, (string) $lead['email']) !== 0) {
            if (filter_var($retarget, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'message' => '"' . $retarget . '" is not a valid email address.'];
            }

            Leads::setEmail((int) $lead['id'], $retarget, 'verified', $actorId);
            $lead['email'] = $retarget;
            $lead['email_confidence'] = 'verified';
        }

        // Note what is deliberately not here: a confirmation step for an
        // address marked 'pattern' — inferred from the shape of other addresses
        // at the domain rather than confirmed.
        //
        // Bulk sends still refuse those; that guard lives in Outbox and is the
        // one that matters, because nobody reads a hundred addresses before
        // pressing send on all of them. One-off compose is the opposite
        // situation: somebody opened this lead, opened this dialog, and typed a
        // message to this person. Asking them to confirm a decision they have
        // already made three times is the kind of prompt people learn to click
        // through without reading, which costs the warning its meaning
        // everywhere else it appears. The lead itself still badges the address
        // as inferred, which is where that belongs.

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

        // Keep GoHighLevel's copy of the address in step with ours. Done after
        // the contact exists, and its failure is not fatal: emailTo below still
        // names the right recipient, and a lead whose address we could not
        // write back is better than a send that did not happen.
        if ($channel === 'Email' && $retarget !== '' && $contactId !== '') {
            $client->updateContact($contactId, ['email' => (string) $lead['email']]);
        }

        $signature = Signature::forUser($owner);
        $wantsSignature = ($message['signature'] ?? true) !== false;

        // Merge variables resolve against this lead, so one message written
        // once becomes a different message per recipient. Subject too — a
        // subject line is the most useful place for a company name.
        $subject = Merge::render(trim((string) ($message['subject'] ?? '')), $lead, $signature);
        if ($channel === 'Email' && $subject === '') {
            $subject = self::defaultSubject($lead);
        }

        $body = Merge::render($body, $lead, $signature);

        $outgoing = $body;
        $html = null;
        $attachments = [];

        if ($channel === 'Email') {
            $attachments = Attachment::urls($message['attachments'] ?? []);

            // A signature belongs on an email and not on a text, where it would
            // eat most of the message and read as a machine wrote it.
            $signatureText = $wantsSignature ? Signature::text($signature) : '';
            $signatureHtml = $wantsSignature ? Signature::html($signature) : '';

            // The text part is derived from the cleaned HTML rather than being
            // a second thing to write, so the two cannot drift apart.
            $outgoing = RichText::toText($body);
            if ($signatureText !== '') {
                $outgoing = rtrim($outgoing) . "\n\n" . $signatureText;
            }

            $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;">'
                . $body
                . '</div>'
                . ($signatureHtml !== ''
                    ? '<div style="padding-top:18px;margin-top:16px;border-top:1px solid #e3e7ec;">'
                        . $signatureHtml . '</div>'
                    : '');
        }

        $result = $client->sendMessage(
            $contactId,
            $channel,
            $outgoing,
            $subject,
            $html,
            $channel === 'Email' ? (string) $lead['email'] : '',
            $attachments
        );

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
     * Send the same message to a list of leads, one message each.
     *
     * One message each rather than one message to many: every recipient sees
     * only their own address, merge variables resolve to their own details, and
     * a failure on one lead does not take the rest down. GoHighLevel's
     * conversation model is per contact anyway, so there is no bulk endpoint to
     * use even if that were wanted.
     *
     * Deliberately *not* silent about the ones that did not go. A bulk send
     * that reports "sent to 40" while eight bounced off a missing address is
     * worse than one that says 32 and names the reasons.
     *
     * @param list<array<string, mixed>> $leads
     * @param array<string, mixed>       $message
     * @return array{sent: int, failed: int, reasons: array<string, int>}
     */
    public static function sendMany(array $leads, array $message, ?int $actorId = null): array
    {
        $sent = 0;
        $failed = 0;
        $reasons = [];

        foreach ($leads as $lead) {
            // No 'to' override in a bulk send: it would mean sending forty
            // people's mail to one address.
            unset($message['to']);

            $result = self::send($lead, $message, $actorId);

            if ($result['ok']) {
                $sent++;
                continue;
            }

            $failed++;
            $reason = $result['message'];
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        return ['sent' => $sent, 'failed' => $failed, 'reasons' => $reasons];
    }

    /**
     * Which of these leads can actually be emailed, and why the rest cannot.
     *
     * Answered before anyone writes anything, so the compose screen can say
     * "38 of the 45 you picked" rather than reporting seven failures after the
     * fact.
     *
     * @param list<array<string, mixed>> $leads
     * @return array{ready: list<array<string, mixed>>, blocked: array<string, int>}
     */
    public static function triage(array $leads): array
    {
        $ready = [];
        $blocked = [];

        foreach ($leads as $lead) {
            $owner = Users::find((int) $lead['user_id']);
            $available = self::available($owner);

            if (!$available['ok']) {
                $blocked[$available['reason']] = ($blocked[$available['reason']] ?? 0) + 1;
                continue;
            }

            $reachable = self::reachable($lead, 'Email');

            if (!$reachable['ok']) {
                $blocked[$reachable['reason']] = ($blocked[$reachable['reason']] ?? 0) + 1;
                continue;
            }

            $ready[] = $lead;
        }

        return ['ready' => $ready, 'blocked' => $blocked];
    }

    /**
     * How this owner's email is signed, for the compose screen to show.
     *
     * @param array<string, mixed>|null $owner
     * @return array<string, string>
     */
    public static function signature(?array $owner): array
    {
        return Signature::forUser($owner);
    }

    /**
     * The address the recipient will see this as coming from.
     *
     * GoHighLevel sends as the sub-account, so this is its address rather than
     * anything Prospector controls. Empty until a connection has been tested,
     * which is when it gets captured.
     *
     * @param array<string, mixed>|null $owner
     */
    public static function fromAddress(?array $owner): string
    {
        return trim((string) ($owner['ghl_from_email'] ?? ''));
    }

    private static function channel(string $channel): string
    {
        return strtoupper(trim($channel)) === 'SMS' ? 'SMS' : 'Email';
    }
}
