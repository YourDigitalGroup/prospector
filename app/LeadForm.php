<?php

declare(strict_types=1);

namespace Prospector;

/**
 * The shape of a lead typed in by hand, and the rules for accepting one.
 *
 * This is the third way a lead can arrive — after the daily batch and an
 * uploaded file — and it is the only one where a person is sitting there
 * typing. That changes two things.
 *
 * First, nothing is silently dropped. An upload has to cope with a hundred
 * rows of somebody else's spreadsheet, so a bad address is noted and the row
 * goes in without it; that is the right call for a file and the wrong one for
 * a form, where the fix is to point at the field and let it be corrected.
 * Everything here comes back as a per-field error with the typing intact.
 *
 * Second, the email is treated as verified by default. Everywhere else in
 * Prospector an address with no stated provenance is 'pattern' — guessed from
 * the shape of other addresses at the domain — and is deliberately held back
 * from bulk sends. That default exists because a model or a scraper supplied
 * the address and nobody checked it. A lead entered here came out of an actual
 * conversation, so the honest default is the opposite one. It is still a
 * dropdown rather than a hard-coded value: type in an address you worked out
 * rather than were given, and say so.
 *
 * The field list is not a second copy of the import's columns. SPEC below says
 * how each one presents itself, but what gets read, validated and stored comes
 * from LeadImport::fields(), so a column added to the importer turns up on this
 * form too — in the "Anything else" group, with a plain text box, until someone
 * gives it a proper home. The two cannot quietly drift apart.
 */
final class LeadForm
{
    /**
     * How each field presents itself. Every key here must be a field
     * LeadImport knows about, plus 'status', which is ours alone: an upload has
     * no business setting a disposition, and someone typing in a lead they just
     * spoke to very much does.
     *
     * @var array<string, array{label: string, type: string, hint?: string, rows?: int, placeholder?: string, options?: array<string, string>}>
     */
    private const SPEC = [
        'company' => [
            'label' => 'Company',
            'type' => 'text',
            'placeholder' => 'Prairie Sky Radio',
            'hint' => 'The only thing that is required.',
        ],
        'website' => ['label' => 'Website', 'type' => 'text', 'placeholder' => 'prairieskyradio.com'],
        'vertical' => ['label' => 'Vertical', 'type' => 'text', 'placeholder' => 'Independent radio'],
        'door' => ['label' => 'Door', 'type' => 'text', 'placeholder' => 'Gap Filler'],
        'market' => ['label' => 'Market', 'type' => 'text', 'placeholder' => 'Sioux City'],
        'state' => ['label' => 'State', 'type' => 'text', 'placeholder' => 'IA'],

        'decision_maker' => ['label' => 'Contact', 'type' => 'text', 'placeholder' => 'Dana Whitfield'],
        'title' => ['label' => 'Title', 'type' => 'text', 'placeholder' => 'General Manager'],
        'email' => ['label' => 'Email', 'type' => 'email', 'placeholder' => 'dwhitfield@prairieskyradio.com'],
        'email_confidence' => [
            'label' => 'How good is that address?',
            'type' => 'select',
            'options' => [
                'verified' => 'Verified — they gave it to me',
                'high' => 'High — found it published somewhere',
                'pattern' => 'Guessed from the pattern at that domain',
            ],
            'hint' => 'Guessed addresses are held back from bulk sends.',
        ],
        'phone' => ['label' => 'Main phone', 'type' => 'tel', 'placeholder' => '(712) 555-0142'],
        'direct_phone' => ['label' => 'Direct or mobile', 'type' => 'tel', 'placeholder' => '(712) 555-0188'],
        'linkedin' => ['label' => 'LinkedIn', 'type' => 'text', 'placeholder' => 'linkedin.com/in/…'],

        'fit_score' => [
            'label' => 'Fit score',
            'type' => 'number',
            'placeholder' => '0–100',
            'hint' => 'Leave blank if you have not scored them; it stores as 0 rather than a guess.',
        ],
        'why' => [
            'label' => 'Why them',
            'type' => 'textarea',
            'rows' => 3,
            'placeholder' => 'What makes them worth the call.',
        ],
        'hook' => [
            'label' => 'Opening hook',
            'type' => 'textarea',
            'rows' => 2,
            'placeholder' => 'The first line you would open with.',
        ],
        'evidence' => [
            'label' => 'Where this came from',
            'type' => 'textarea',
            'rows' => 2,
            'placeholder' => 'Met at the SDBA conference, 14 March.',
            'hint' => 'How you know. Worth writing down while you still remember.',
        ],

        'status' => [
            'label' => 'Status',
            'type' => 'select',
            'options' => Leads::STATUSES,
            'hint' => 'If you have already spoken to them, say so — it starts the same automations a disposition would.',
        ],
    ];

    /**
     * The fields, grouped the way the form reads top to bottom.
     *
     * Any importable field not placed in a group by hand lands in "Anything
     * else" rather than falling off the form — see the class note.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        $groups = [
            'The organisation' => ['company', 'website', 'vertical', 'door', 'market', 'state'],
            'The person' => ['decision_maker', 'title', 'email', 'email_confidence', 'phone', 'direct_phone', 'linkedin'],
            'The qualification' => ['fit_score', 'why', 'hook', 'evidence'],
            'Where it stands' => ['status'],
        ];

        $placed = array_merge(...array_values($groups));
        $unplaced = array_values(array_diff(LeadImport::fields(), $placed));

        if ($unplaced !== []) {
            $groups['Anything else'] = $unplaced;
        }

        return $groups;
    }

    /**
     * Every field on the form, in the order it appears.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /**
     * @return array{label: string, type: string, hint?: string, rows?: int, placeholder?: string, options?: array<string, string>}
     */
    public static function field(string $name): array
    {
        return self::SPEC[$name] ?? ['label' => ucfirst(str_replace('_', ' ', $name)), 'type' => 'text'];
    }

    /**
     * What an empty form starts with.
     *
     * @return array<string, string>
     */
    public static function blank(): array
    {
        $values = [];

        foreach (self::fields() as $field) {
            $values[$field] = '';
        }

        // The two defaults that carry an argument. See the class note on why
        // 'verified' rather than 'pattern'.
        $values['email_confidence'] = 'verified';
        $values['status'] = 'new';

        return $values;
    }

    /**
     * Read a submitted form back, whether or not it is any good.
     *
     * Returned as typed — trimmed, but otherwise untouched — so a form that
     * fails validation can be handed straight back with the person's own words
     * still in it.
     *
     * @param callable(string): string $read
     * @return array<string, string>
     */
    public static function read(callable $read): array
    {
        $values = [];

        foreach (self::fields() as $field) {
            $values[$field] = trim($read($field));
        }

        return $values;
    }

    /**
     * Turn a filled-in form into something Leads::create will take.
     *
     * @param array<string, string> $values
     * @return array{lead: array<string, mixed>, status: string, errors: array<string, string>}
     */
    public static function validate(array $values): array
    {
        $errors = [];
        $lead = [];

        $company = trim($values['company'] ?? '');
        if ($company === '') {
            $errors['company'] = 'A company name is the one thing a lead cannot do without.';
        }

        foreach (self::fields() as $field) {
            if ($field === 'status') {
                continue;
            }
            $value = trim($values[$field] ?? '');
            if ($value !== '') {
                $lead[$field] = $value;
            }
        }

        // A typed address gets checked and handed back rather than quietly
        // dropped, which is the difference between a form and a file.
        if (isset($lead['email'])) {
            if (filter_var($lead['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'That does not look like an email address.';
            }
        }

        // Confidence without an address describes nothing.
        if (!isset($lead['email'])) {
            unset($lead['email_confidence']);
        } else {
            $stated = strtolower($lead['email_confidence'] ?? '');
            $lead['email_confidence'] = in_array($stated, ['verified', 'high', 'pattern'], true) ? $stated : 'verified';
        }

        $score = trim($values['fit_score'] ?? '');
        if ($score !== '' && !is_numeric($score)) {
            $errors['fit_score'] = 'A fit score is a number from 0 to 100, or nothing at all.';
        }
        $lead['fit_score'] = $score === '' ? 0 : max(0, min(100, (int) round((float) $score)));

        foreach (['website', 'linkedin'] as $field) {
            if (isset($lead[$field])) {
                $lead[$field] = self::url($lead[$field]);
            }
        }

        $status = $values['status'] ?? 'new';
        if (!array_key_exists($status, Leads::STATUSES)) {
            $status = 'new';
        }

        return ['lead' => $lead, 'status' => $status, 'errors' => $errors];
    }

    /** Same rule the importer uses: a bare domain is a website, not a typo. */
    private static function url(string $url): string
    {
        $url = trim($url);

        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return 'https://' . ltrim($url, '/');
    }
}
