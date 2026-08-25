<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Settings;
use RuntimeException;

/**
 * A client for a local, OpenAI-compatible model server — Ollama, LM Studio,
 * llama.cpp's server, vLLM, LiteLLM, anything that speaks
 * `POST /v1/chat/completions`.
 *
 * The connection is account-wide and lives in Settings, which is admin-only.
 * That is deliberate: it is one machine on one network, everyone's work goes
 * through it, and a per-user copy would only create four ways for it to be
 * wrong.
 *
 * Local servers vary far more than a hosted API does, so this is written to be
 * forgiving in the two places it matters: the base URL is normalised however it
 * was typed, and JSON comes back out of the response even when the model
 * wrapped it in prose or a code fence. Everything else fails loudly.
 */
final class LocalModel
{
    /** Local boxes are slower than a hosted API, and a cold model has to load. */
    private const TIMEOUT = 300;
    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        private string $baseUrl,
        private string $model,
        private string $key = ''
    ) {
    }

    /**
     * Build from the saved settings, or null when it has not been set up.
     *
     * A key is not required — Ollama takes none by default, and demanding one
     * would make the most common local setup impossible to configure.
     */
    public static function configured(): ?self
    {
        $url = self::normaliseUrl(Settings::get('local_model_url'));
        $model = trim(Settings::get('local_model_name'));

        if ($url === '' || $model === '') {
            return null;
        }

        return new self($url, $model, Settings::localModelKey());
    }

    public static function isConfigured(): bool
    {
        return self::configured() !== null;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Accept what people actually type.
     *
     * `http://mac-mini:11434`, `mac-mini:11434/v1/`, and
     * `http://mac-mini:11434/v1/chat/completions` all mean the same server, and
     * being strict about it would just produce a connection error that reads
     * like the machine is down.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = 'http://' . $url;
        }

        $url = rtrim($url, '/');

        // Trim a pasted endpoint back to its base.
        $url = preg_replace('#/chat/completions$#i', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    /**
     * Ask for a structured object back.
     *
     * The schema is sent as `response_format` for servers that honour it, and
     * also spelled out in the prompt for those that do not — a local model that
     * ignores the parameter will usually still follow a clear instruction, and
     * the parser below copes with the rest.
     *
     * @param array<string, mixed> $schema
     * @return array{data: array<string, mixed>, input_tokens: int, output_tokens: int}
     */
    public function json(string $system, string $prompt, array $schema, int $maxTokens = 4000): array
    {
        $response = $this->chat([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system . "\n\nReply with a single JSON object and nothing else. "
                        . "No prose before or after it, no code fence. It must match this schema:\n"
                        . json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
            'stream' => false,
        ]);

        $text = (string) ($response['choices'][0]['message']['content'] ?? '');
        $parsed = self::extractJson($text);

        if ($parsed === null) {
            throw new RuntimeException(
                'The local model did not return usable JSON. It replied: '
                . mb_substr(trim($text), 0, 200)
            );
        }

        $usage = $response['usage'] ?? [];

        return [
            'data' => $parsed,
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        ];
    }

    /**
     * Pull a JSON object out of whatever the model said.
     *
     * Pure, and separately tested. Local models fence their output, apologise
     * before it, or add a closing remark after it far more often than a hosted
     * one does, and throwing that away is cheaper than failing the run.
     *
     * @return array<string, mixed>|null
     */
    public static function extractJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Reasoning models emit a visible thinking block before the answer.
        $text = trim(preg_replace('#<think>.*?</think>#is', '', $text) ?? $text);

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // ```json … ``` or a bare fence.
        if (preg_match('#```(?:json)?\s*(.+?)\s*```#is', $text, $m) === 1) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Last resort: the outermost braces. Scanned rather than matched with a
        // regex so a brace inside a string cannot end the object early.
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start, $len = strlen($text); $i < $len; $i++) {
            $char = $text[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode(substr($text, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * Verify the server answers and knows the configured model.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array
    {
        try {
            $response = $this->chat([
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ready']],
                'max_tokens' => 16,
                'stream' => false,
            ]);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $reply = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        $reply = trim(preg_replace('#<think>.*?</think>#is', '', $reply) ?? $reply);

        if ($reply === '') {
            return [
                'ok' => false,
                'message' => 'The server answered but the reply was empty. Check that "'
                    . $this->model . '" is a model it has pulled.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Connected to ' . $this->model . ' at ' . $this->baseUrl
                . '. Reply: ' . mb_substr($reply, 0, 60),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function chat(array $payload): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->key !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }

        $handle = curl_init($this->baseUrl . '/chat/completions');
        if ($handle === false) {
            throw new RuntimeException('Could not open a connection to the local model.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new RuntimeException(
                'Could not reach ' . $this->baseUrl . ': ' . $error
                . '. Check the machine is on, the server is running, and it is listening on an '
                . 'address this site can reach.'
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $raw, true);

        if ($status >= 400) {
            $message = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? '')
                : '';

            throw new RuntimeException(
                'The local model server returned ' . $status
                . ($message !== '' ? ': ' . $message : '.')
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'The local model server replied with something that is not JSON. '
                . 'Is that URL an OpenAI-compatible endpoint?'
            );
        }

        return $decoded;
    }
}
