<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Settings;

/**
 * GoHighLevel API v2 client.
 *
 * Auth is a Private Integration token (Settings → GoHighLevel, or per-user on
 * the Users screen so Billy and Darren can point at their own sub-accounts).
 * Create one in GHL under Settings → Private Integrations with these scopes:
 * contacts.readonly, contacts.write, opportunities.readonly, opportunities.write,
 * locations.readonly.
 */
final class GoHighLevel
{
    private const BASE = 'https://services.leadconnectorhq.com';
    private const VERSION = '2021-07-28';

    public function __construct(
        private string $token,
        private string $locationId,
    ) {
    }

    /**
     * Resolve credentials for a user: their own if set, otherwise the
     * account-wide pair from Settings.
     *
     * @param array<string, mixed>|null $user
     */
    public static function forUser(?array $user = null): ?self
    {
        $token = '';
        $location = '';

        if ($user !== null) {
            $token = Users::ghlToken($user);
            $location = (string) ($user['ghl_location_id'] ?? '');
        }

        if ($token === '') {
            $token = Settings::get('ghl_token');
            if ($location === '') {
                $location = Settings::get('ghl_location_id');
            }
        }

        if ($token === '' || $location === '') {
            return null;
        }

        return new self($token, $location);
    }

    public static function isConfigured(): bool
    {
        return Settings::get('ghl_token') !== '' && Settings::get('ghl_location_id') !== '';
    }

    public function locationId(): string
    {
        return $this->locationId;
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $response = $this->request('GET', '/locations/' . rawurlencode($this->locationId));

        if (!$response['ok']) {
            return ['ok' => false, 'message' => $response['error']];
        }

        $name = $response['data']['location']['name'] ?? $response['data']['name'] ?? null;

        return [
            'ok' => true,
            'message' => 'Connected to ' . (is_string($name) ? $name : 'location ' . $this->locationId),
        ];
    }

    /**
     * Push a lead into GoHighLevel as a contact, then attach the qualification
     * detail as a note so the rep sees the same context the brief gave them.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, contact_id?: string, message: string}
     */
    public function pushLead(array $lead): array
    {
        $names = self::splitName((string) ($lead['decision_maker'] ?? ''));

        $payload = array_filter([
            'locationId' => $this->locationId,
            'firstName' => $names[0],
            'lastName' => $names[1],
            'name' => (string) ($lead['decision_maker'] ?? '') !== ''
                ? (string) $lead['decision_maker']
                : (string) $lead['company'],
            'email' => self::emailForPush($lead),
            'phone' => (string) ($lead['direct_phone'] ?? '') !== ''
                ? (string) $lead['direct_phone']
                : (string) ($lead['phone'] ?? ''),
            'companyName' => (string) $lead['company'],
            'website' => (string) ($lead['website'] ?? ''),
            'city' => self::cityOf((string) ($lead['market'] ?? '')),
            'state' => (string) ($lead['state'] ?? ''),
            'source' => 'Prospector',
            'tags' => array_values(array_filter([
                'prospector',
                strtolower(trim((string) ($lead['vertical'] ?? ''))) ?: null,
                (string) ($lead['door'] ?? '') !== '' ? 'door: ' . strtolower((string) $lead['door']) : null,
                'fit ' . (int) ($lead['fit_score'] ?? 0),
            ])),
        ], static fn ($v): bool => $v !== '' && $v !== null && $v !== []);

        // upsert matches on email/phone within the location, so re-pushing a
        // lead updates the existing contact instead of duplicating it.
        $response = $this->request('POST', '/contacts/upsert', $payload);

        if (!$response['ok']) {
            // Older sub-accounts may not expose /contacts/upsert; fall back to create.
            $response = $this->request('POST', '/contacts/', $payload);
            if (!$response['ok']) {
                return ['ok' => false, 'message' => $response['error']];
            }
        }

        $contactId = $response['data']['contact']['id']
            ?? $response['data']['id']
            ?? null;

        if (!is_string($contactId) || $contactId === '') {
            return ['ok' => false, 'message' => 'GoHighLevel accepted the contact but returned no ID.'];
        }

        $this->addNote($contactId, self::noteFor($lead));

        return ['ok' => true, 'contact_id' => $contactId, 'message' => 'Pushed to GoHighLevel'];
    }

    /** @return array{ok: bool, message: string} */
    public function addNote(string $contactId, string $body): array
    {
        $response = $this->request('POST', '/contacts/' . rawurlencode($contactId) . '/notes', [
            'body' => mb_substr($body, 0, 5000),
        ]);

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Note added' : $response['error']];
    }

    /**
     * @return array{ok: bool, contacts: list<array<string, mixed>>, error: string}
     */
    public function contacts(int $limit = 25, string $query = ''): array
    {
        $params = ['locationId' => $this->locationId, 'limit' => max(1, min(100, $limit))];
        if ($query !== '') {
            $params['query'] = $query;
        }

        $response = $this->request('GET', '/contacts/?' . http_build_query($params));

        if (!$response['ok']) {
            return ['ok' => false, 'contacts' => [], 'error' => $response['error']];
        }

        $contacts = $response['data']['contacts'] ?? [];

        return [
            'ok' => true,
            'contacts' => is_array($contacts) ? array_values($contacts) : [],
            'error' => '',
        ];
    }

    /**
     * @return array{ok: bool, opportunities: list<array<string, mixed>>, error: string}
     */
    public function opportunities(int $limit = 25): array
    {
        $params = ['location_id' => $this->locationId, 'limit' => max(1, min(100, $limit))];

        $response = $this->request('GET', '/opportunities/search?' . http_build_query($params));

        if (!$response['ok']) {
            return ['ok' => false, 'opportunities' => [], 'error' => $response['error']];
        }

        $opportunities = $response['data']['opportunities'] ?? [];

        return [
            'ok' => true,
            'opportunities' => is_array($opportunities) ? array_values($opportunities) : [],
            'error' => '',
        ];
    }

    /** @return array{ok: bool, pipelines: list<array<string, mixed>>, error: string} */
    public function pipelines(): array
    {
        $response = $this->request(
            'GET',
            '/opportunities/pipelines?' . http_build_query(['locationId' => $this->locationId])
        );

        if (!$response['ok']) {
            return ['ok' => false, 'pipelines' => [], 'error' => $response['error']];
        }

        $pipelines = $response['data']['pipelines'] ?? [];

        return ['ok' => true, 'pipelines' => is_array($pipelines) ? array_values($pipelines) : [], 'error' => ''];
    }

    /**
     * Create an opportunity for a pushed contact, when a pipeline and stage are
     * configured.
     *
     * @param array<string, mixed> $lead
     * @return array{ok: bool, message: string}
     */
    public function createOpportunity(string $contactId, array $lead): array
    {
        $pipelineId = Settings::get('ghl_pipeline_id');
        $stageId = Settings::get('ghl_stage_id');

        if ($pipelineId === '' || $stageId === '') {
            return ['ok' => false, 'message' => 'No pipeline and stage configured.'];
        }

        $response = $this->request('POST', '/opportunities/', [
            'pipelineId' => $pipelineId,
            'pipelineStageId' => $stageId,
            'locationId' => $this->locationId,
            'contactId' => $contactId,
            'name' => (string) $lead['company'],
            'status' => 'open',
        ]);

        return ['ok' => $response['ok'], 'message' => $response['ok'] ? 'Opportunity created' : $response['error']];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, data: array<string, mixed>, error: string, status: int}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        if ($ch === false) {
            return ['ok' => false, 'data' => [], 'error' => 'Could not initialise the HTTP client.', 'status' => 0];
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Version: ' . self::VERSION,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_POSTFIELDS] = $encoded === false ? '{}' : $encoded;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'data' => [],
                'error' => 'Could not reach GoHighLevel: ' . ($curlError !== '' ? $curlError : 'unknown network error'),
                'status' => $status,
            ];
        }

        $decoded = json_decode((string) $raw, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $message = $data['message'] ?? $data['error'] ?? null;
            if (is_array($message)) {
                $message = implode('; ', array_map('strval', $message));
            }

            $detail = is_string($message) && $message !== ''
                ? $message
                : 'HTTP ' . $status . ' from GoHighLevel';

            if ($status === 401 || $status === 403) {
                $detail .= ' — check the private integration token and its scopes.';
            }

            return ['ok' => false, 'data' => $data, 'error' => $detail, 'status' => $status];
        }

        return ['ok' => true, 'data' => $data, 'error' => '', 'status' => $status];
    }

    /**
     * A pattern-confidence email has not been verified, so it must not be the
     * address a bulk sequence fires at. Push the lead without it and leave the
     * address in the note for a human to confirm.
     *
     * @param array<string, mixed> $lead
     */
    private static function emailForPush(array $lead): string
    {
        $email = trim((string) ($lead['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return ($lead['email_confidence'] ?? '') === 'pattern' ? '' : $email;
    }

    /** @param array<string, mixed> $lead */
    private static function noteFor(array $lead): string
    {
        $lines = ['Prospector lead — fit score ' . (int) ($lead['fit_score'] ?? 0)];

        $add = static function (string $label, mixed $value) use (&$lines): void {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        };

        $add('Vertical', $lead['vertical'] ?? null);
        $add('Buyer door', $lead['door'] ?? null);
        $add('Market', $lead['market'] ?? null);
        $add('Why them', $lead['why'] ?? null);
        $add('Opening hook', $lead['hook'] ?? null);
        $add('Website', $lead['website'] ?? null);
        $add('LinkedIn', $lead['linkedin'] ?? null);
        $add('Main phone', $lead['phone'] ?? null);
        $add('Source', $lead['source'] ?? null);

        if (($lead['email_confidence'] ?? '') === 'pattern' && ($lead['email'] ?? '') !== '') {
            $lines[] = 'Unverified pattern email (do NOT bulk send, verify first): ' . $lead['email'];
        } elseif (($lead['email'] ?? '') !== '') {
            $lines[] = 'Email confidence: ' . (string) ($lead['email_confidence'] ?? 'unknown');
        }

        $lines[] = 'Delivered ' . Clock::display((string) ($lead['created_at'] ?? Clock::now()), 'M j, Y');

        return implode("\n", $lines);
    }

    /** @return array{0: string, 1: string} */
    private static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return ['', ''];
        }

        $parts = explode(' ', $name);
        $first = array_shift($parts) ?? '';

        return [$first, implode(' ', $parts)];
    }

    private static function cityOf(string $market): string
    {
        if ($market === '') {
            return '';
        }

        return trim(explode(',', $market)[0]);
    }
}
