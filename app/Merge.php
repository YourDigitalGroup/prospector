<?php

declare(strict_types=1);

namespace Prospector;

/**
 * Merge variables in outbound copy: {{contact.first_name}} and friends.
 *
 * The names are GoHighLevel's, deliberately, so a seller who already writes
 * these in a GoHighLevel template writes the same thing here and copy moves
 * between the two without editing.
 *
 * **The substitution happens here, not there.** Passing an unresolved
 * {{contact.first_name}} to /conversations/messages and hoping GoHighLevel
 * fills it in is a bet on undocumented behaviour, and the way that bet loses is
 * a real prospect receiving "Hi {{contact.first_name}}". Resolving from the
 * lead record before the message leaves means the preview on the screen is
 * exactly what is sent, and it can be tested.
 *
 * **An unresolvable variable falls back rather than blanking.** No first name on
 * file gives "there" from the fallback below, so "Hi {{contact.first_name}}"
 * reads "Hi there" instead of "Hi ,". A greeting is the most common use and the
 * most obvious when it breaks. Anything with no sensible stand-in resolves to
 * an empty string, which is at least not a visible placeholder.
 */
final class Merge
{
    /**
     * The variables, in the order the picker offers them.
     *
     * Each maps to the lead column it comes from, a fallback for when that
     * column is empty, and a label. Keeping the set small is the point: twenty
     * variables nobody can remember is worse than eight that are obvious.
     *
     * @var array<string, array{label: string, field: string, fallback: string}>
     */
    private const CONTACT = [
        'contact.first_name' => ['label' => 'First name', 'field' => 'first_name', 'fallback' => 'there'],
        'contact.last_name' => ['label' => 'Last name', 'field' => 'last_name', 'fallback' => ''],
        'contact.full_name' => ['label' => 'Full name', 'field' => 'decision_maker', 'fallback' => 'there'],
        'contact.company_name' => ['label' => 'Company', 'field' => 'company', 'fallback' => 'your team'],
        'contact.email' => ['label' => 'Their email', 'field' => 'email', 'fallback' => ''],
        'contact.phone' => ['label' => 'Their phone', 'field' => 'phone', 'fallback' => ''],
        'contact.title' => ['label' => 'Their job title', 'field' => 'title', 'fallback' => ''],
        'contact.city' => ['label' => 'Their market', 'field' => 'market', 'fallback' => ''],
        'contact.state' => ['label' => 'Their state', 'field' => 'state', 'fallback' => ''],
        'contact.website' => ['label' => 'Their website', 'field' => 'website', 'fallback' => ''],
    ];

    /**
     * The sender's own details, from the signature, so a line like "call me on
     * {{user.phone}}" does not have to be retyped per person.
     *
     * @var array<string, array{label: string, field: string}>
     */
    private const USER = [
        'user.first_name' => ['label' => 'Your first name', 'field' => 'name'],
        'user.name' => ['label' => 'Your full name', 'field' => 'name'],
        'user.company_name' => ['label' => 'Your company', 'field' => 'company'],
        'user.phone' => ['label' => 'Your phone', 'field' => 'phone'],
        'user.email' => ['label' => 'Your email', 'field' => 'email'],
    ];

    /**
     * Grouped for the picker.
     *
     * @return array<string, array<string, string>> heading => token => label
     */
    public static function groups(): array
    {
        $contact = [];
        foreach (self::CONTACT as $token => $spec) {
            $contact[$token] = $spec['label'];
        }

        $user = [];
        foreach (self::USER as $token => $spec) {
            $user[$token] = $spec['label'];
        }

        return ['About them' => $contact, 'About you' => $user];
    }

    /** @return list<string> */
    public static function tokens(): array
    {
        return array_merge(array_keys(self::CONTACT), array_keys(self::USER));
    }

    /**
     * Resolve every variable in a string for one lead.
     *
     * @param array<string, mixed>  $lead
     * @param array<string, string> $signature the sender, from Signature::forUser
     */
    public static function render(string $text, array $lead, array $signature = []): string
    {
        if ($text === '' || !str_contains($text, '{{')) {
            return $text;
        }

        $values = self::values($lead, $signature);

        // Whitespace inside the braces is tolerated because people type it:
        // {{ contact.first_name }} is the same variable.
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/i',
            static function (array $m) use ($values): string {
                $token = strtolower($m[1]);

                // An unknown variable is left exactly as typed rather than
                // silently deleted. A visible {{contact.nickname}} in the
                // preview is how somebody finds out they invented a variable;
                // a blank space is not.
                return array_key_exists($token, $values) ? $values[$token] : $m[0];
            },
            $text
        );
    }

    /**
     * Every variable's value for one lead.
     *
     * @param array<string, mixed>  $lead
     * @param array<string, string> $signature
     * @return array<string, string>
     */
    public static function values(array $lead, array $signature = []): array
    {
        $names = self::splitName((string) ($lead['decision_maker'] ?? ''));
        $lead['first_name'] = $names['first'];
        $lead['last_name'] = $names['last'];

        $values = [];

        foreach (self::CONTACT as $token => $spec) {
            $value = trim((string) ($lead[$spec['field']] ?? ''));
            $values[$token] = $value !== '' ? $value : $spec['fallback'];
        }

        $senderNames = self::splitName(trim((string) ($signature['name'] ?? '')));

        foreach (self::USER as $token => $spec) {
            $value = trim((string) ($signature[$spec['field']] ?? ''));

            if ($token === 'user.first_name') {
                $value = $senderNames['first'];
            }

            $values[$token] = $value;
        }

        return $values;
    }

    /**
     * Split a stored name into a first and last part.
     *
     * Leads carry one `decision_maker` field, because that is how they arrive —
     * from a batch, a spreadsheet or a business card, always as one string. A
     * greeting needs the first name out of it, so it is split here rather than
     * adding two more columns that every source would have to learn to fill.
     *
     * Trailing credentials are dropped, since "Cale Slack, DDS" should give a
     * last name of "Slack" and not "Slack, DDS".
     *
     * @return array{first: string, last: string}
     */
    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        if ($name === '') {
            return ['first' => '', 'last' => ''];
        }

        // "Slack, Cale" — a sorted list, so the parts are the other way round.
        // Distinguished from "Cale Slack, DDS" by the comma coming before the
        // first space rather than after it.
        if (preg_match('/^([^,\s]+),\s+(.+)$/', $name, $m) === 1) {
            return ['first' => trim($m[2]), 'last' => trim($m[1])];
        }

        $name = trim(explode(',', $name)[0]);
        $parts = array_values(array_filter(explode(' ', $name)));

        if (count($parts) === 1) {
            return ['first' => $parts[0], 'last' => ''];
        }

        return ['first' => (string) array_shift($parts), 'last' => implode(' ', $parts)];
    }
}
