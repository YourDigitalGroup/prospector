<?php

declare(strict_types=1);

namespace Prospector;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Prospector\Support\Settings;
use RuntimeException;

/**
 * Thin wrapper around the Anthropic PHP SDK for the two calls the prospector
 * makes: a web-research turn that may run long, and a structured extraction
 * turn that turns the research into rows we can store.
 */
final class Claude
{
    private const RESEARCH_MAX_TOKENS = 16000;
    private const EXTRACT_MAX_TOKENS = 16000;

    /** Server-tool turns pause every 10 iterations; resume up to this many times. */
    private const MAX_CONTINUATIONS = 12;

    private Client $client;

    public function __construct(private string $model = 'claude-opus-5', private string $effort = 'high')
    {
        $key = Settings::anthropicKey();
        if ($key === '') {
            throw new RuntimeException(
                'No Anthropic API key configured. Add one under Settings before running a batch.'
            );
        }

        // Research turns with adaptive thinking and many web searches can run
        // for minutes; the default 10-minute timeout covers a paused segment.
        $this->client = new Client(apiKey: $key);
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Run a web-research turn. Handles `pause_turn`, which the API returns when
     * a server-tool turn hits its iteration limit — the conversation is
     * re-sent so the model picks up where it left off.
     *
     * @return array{text: string, input_tokens: int, output_tokens: int, searches: int}
     */
    /**
     * @param list<string> $blockedDomains Domains the model must not search or
     *                                     fetch. Enforced by the API rather than
     *                                     by instruction, so it holds even if the
     *                                     model is inclined to try.
     */
    public function research(string $system, string $prompt, array $blockedDomains = []): array
    {
        $search = ['type' => 'web_search_20260209', 'name' => 'web_search'];
        $fetch = ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 40];

        if ($blockedDomains !== []) {
            $search['blocked_domains'] = $blockedDomains;
            $fetch['blocked_domains'] = $blockedDomains;
        }

        $tools = [$search, $fetch];

        /** @var list<array<string, mixed>> $messages */
        $messages = [['role' => 'user', 'content' => $prompt]];

        $inputTokens = 0;
        $outputTokens = 0;
        $searches = 0;
        $text = '';

        for ($i = 0; $i <= self::MAX_CONTINUATIONS; $i++) {
            $response = $this->send([
                'maxTokens' => self::RESEARCH_MAX_TOKENS,
                'messages' => $messages,
                'model' => $this->model,
                'system' => $system,
                'thinking' => ['type' => 'adaptive'],
                'outputConfig' => ['effort' => $this->effort],
                'tools' => $tools,
            ]);

            $inputTokens += $response->usage->inputTokens;
            $outputTokens += $response->usage->outputTokens;
            $searches += $response->usage->serverToolUse->webSearchRequests ?? 0;

            $text .= $this->textOf($response);

            if ($response->stopReason === 'refusal') {
                throw new RuntimeException(
                    'The model declined this research request'
                    . ($response->stopDetails?->explanation !== null
                        ? ': ' . $response->stopDetails->explanation
                        : '.')
                );
            }

            if ($response->stopReason !== 'pause_turn') {
                break;
            }

            // Resume the paused turn: echo the assistant content back and
            // re-send. No extra user message — the API detects the pause.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
        }

        if (trim($text) === '') {
            throw new RuntimeException('The research turn returned no text. Check the API key and rate limits.');
        }

        return [
            'text' => $text,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'searches' => $searches,
        ];
    }

    /**
     * Second pass: convert the research brief into validated rows. No tools, so
     * a JSON schema can be enforced on the output.
     *
     * @param array<string, mixed> $schema
     * @return array{data: array<string, mixed>, input_tokens: int, output_tokens: int}
     */
    public function extract(string $system, string $prompt, array $schema): array
    {
        $response = $this->send([
            'maxTokens' => self::EXTRACT_MAX_TOKENS,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'model' => $this->model,
            'system' => $system,
            'thinking' => ['type' => 'adaptive'],
            'outputConfig' => [
                'effort' => 'medium',
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ]);

        if ($response->stopReason === 'refusal') {
            throw new RuntimeException('The model declined to structure this batch.');
        }

        $parsed = $response->parsedOutput();

        if (!is_array($parsed)) {
            // Structured outputs guarantee the first text block is valid JSON,
            // but a max_tokens cut-off can truncate it.
            $decoded = json_decode($this->textOf($response), true);
            if (!is_array($decoded)) {
                $hint = $response->stopReason === 'max_tokens'
                    ? ' The response hit the token limit — try a smaller batch size.'
                    : '';
                throw new RuntimeException('Could not read structured leads from the model response.' . $hint);
            }
            $parsed = $decoded;
        }

        return [
            'data' => $parsed,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ];
    }

    /**
     * Verify the configured key works. Used by the "Test connection" button.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client->messages->create(
                maxTokens: 16,
                messages: [['role' => 'user', 'content' => 'Reply with the single word: ready']],
                model: $this->model,
                thinking: ['type' => 'disabled'],
                outputConfig: ['effort' => 'low'],
            );

            return [
                'ok' => true,
                'message' => 'Connected to ' . $response->model . '. Reply: ' . trim($this->textOf($response)),
            ];
        } catch (APIStatusException $e) {
            return ['ok' => false, 'message' => 'API error ' . ($e->status ?? 0) . ': ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $params */
    private function send(array $params): Message
    {
        $attempts = 0;

        while (true) {
            try {
                /** @var Message $message */
                $message = $this->client->messages->create(...$params);

                return $message;
            } catch (APIStatusException $e) {
                $status = (int) ($e->status ?? 0);
                $retryable = in_array($status, [408, 409, 429, 500, 502, 503, 504, 529], true);

                if (!$retryable || $attempts >= 3) {
                    throw new RuntimeException(
                        'Anthropic API error ' . $status . ': ' . $e->getMessage(),
                        $status,
                        $e
                    );
                }

                $attempts++;
                sleep(2 ** $attempts);
            }
        }
    }

    private function textOf(Message $message): string
    {
        $parts = [];
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock && $block->text !== '') {
                $parts[] = $block->text;
            }
        }

        return implode("\n", $parts);
    }
}
