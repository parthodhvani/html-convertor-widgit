<?php
/**
 * Anthropic Claude API client.
 */

declare(strict_types=1);

final class ClaudeClient
{
    private string $apiKey;
    private string $model;
    private string $apiUrl;
    private string $apiVersion;
    private int $maxTokens;

    /** @var callable|null */
    private $logger;

    public function __construct(array $config, ?callable $logger = null)
    {
        $this->apiKey     = (string) ($config['anthropic_api_key'] ?? '');
        $this->model      = (string) ($config['anthropic_model'] ?? 'claude-sonnet-4-20250514');
        $this->apiUrl     = (string) ($config['anthropic_api_url'] ?? 'https://api.anthropic.com/v1/messages');
        $this->apiVersion = (string) ($config['anthropic_version'] ?? '2023-06-01');
        $this->maxTokens  = (int) ($config['anthropic_max_tokens'] ?? 16000);
        $this->logger     = $logger;
    }

    public function isConfigured(): bool
    {
        $key = trim($this->apiKey);
        return $key !== '' && $key !== 'YOUR_ANTHROPIC_API_KEY_HERE';
    }

    /**
     * Send a message and return the assistant text content.
     *
     * @param array<int, array{role:string, content:string}> $messages
     */
    public function chat(array $messages, ?string $system = null): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'Claude API key is not set. Edit ai-tool/config.php and set anthropic_api_key, ' .
                'or export ANTHROPIC_API_KEY.'
            );
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => $messages,
        ];

        if ($system !== null && $system !== '') {
            $payload['system'] = $system;
        }

        $this->log('Calling Claude model: ' . $this->model);

        $response = $this->request($payload);
        $text     = $this->extractText($response);

        if ($text === '') {
            throw new RuntimeException('Claude returned an empty response.');
        }

        return $text;
    }

    /**
     * Ask Claude for a JSON object and decode it.
     *
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, ?string $system = null): array
    {
        $text = $this->chat($messages, $system);
        $json = $this->extractJson($text);

        if ($json === null) {
            // Persist raw response for debugging
            $debugFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR .
                'claude-raw-' . date('Ymd-His') . '.txt';
            @file_put_contents($debugFile, $text);
            throw new RuntimeException(
                'Could not parse JSON from Claude response. Raw output saved to logs/.'
            );
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Failed to encode Claude request JSON.');
        }

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion,
        ];

        if (function_exists('curl_init')) {
            return $this->requestCurl($body, $headers);
        }

        return $this->requestStream($body, $headers);
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function requestCurl(string $body, array $headers): array
    {
        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Claude API request failed: ' . $err);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Claude API (HTTP ' . $code . ').');
        }

        if ($code >= 400) {
            $msg = $decoded['error']['message'] ?? $raw;
            throw new RuntimeException('Claude API error (HTTP ' . $code . '): ' . $msg);
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function requestStream(string $body, array $headers): array
    {
        $headerStr = implode("\r\n", $headers);
        $context   = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => $headerStr,
                'content' => $body,
                'timeout' => 600,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($this->apiUrl, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Claude API request failed (stream wrapper).');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Claude API.');
        }

        if (isset($decoded['error'])) {
            $msg = is_array($decoded['error'])
                ? ($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string) $decoded['error'];
            throw new RuntimeException('Claude API error: ' . $msg);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        $parts = [];
        $content = $response['content'] ?? [];
        if (!is_array($content)) {
            return '';
        }

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Fenced ```json ... ```
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
            $candidate = trim($m[1]);
            $decoded   = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Raw JSON object
        if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // First { ... } block
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded   = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
