<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Msahidurr\ErrorNotifier\Contracts\Channel;
use RuntimeException;
use Throwable;

/**
 * Base for channels that deliver over HTTP. Webhook URLs and tokens are
 * credentials, so they are stripped from any error before it is rethrown.
 */
abstract class HttpChannel implements Channel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config)
    {
    }

    /**
     * Config value that must be set, e.g. the webhook URL.
     */
    protected function required(string $key): string
    {
        $value = (string) ($this->config[$key] ?? '');

        if ($value === '') {
            throw new RuntimeException(static::class." is not configured ({$key} missing).");
        }

        return $value;
    }

    /**
     * POST JSON and throw (with secrets redacted) on any failure.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $secrets  Values to redact from error messages.
     * @param  (callable(PendingRequest): PendingRequest)|null  $tap
     */
    protected function postJson(string $url, array $payload, array $secrets = [], ?callable $tap = null): Response
    {
        try {
            $request = Http::timeout((int) ($this->config['timeout'] ?? 5))->acceptJson();

            if ($tap) {
                $request = $tap($request);
            }

            return $request->post($url, $payload)->throw();
        } catch (Throwable $e) {
            throw new RuntimeException($this->redact($e->getMessage(), array_merge([$url], $secrets)));
        }
    }

    /**
     * POST an already-encoded JSON body exactly as given.
     *
     * @param  array<int, string>  $secrets
     * @param  array<string, string>  $headers
     */
    protected function postBody(string $url, string $body, array $secrets = [], array $headers = []): Response
    {
        return $this->postJson($url, [], $secrets, fn (PendingRequest $request) => $request
            ->withHeaders($headers)
            ->withBody($body, 'application/json'));
    }

    protected function encode(mixed $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, string>  $secrets
     */
    protected function redact(string $text, array $secrets): string
    {
        $secrets = array_filter($secrets);
        $urls = array_filter($secrets, fn ($secret) => filter_var($secret, FILTER_VALIDATE_URL));

        // Plain secrets (tokens) first, so a token inside a URL leaves the rest readable.
        foreach (array_diff($secrets, $urls) as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        // For URLs keep the host (useful for debugging), hide path and query.
        foreach ($urls as $url) {
            $parts = parse_url($url);
            $sensitive = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');

            if (strlen($sensitive) > 1) {
                $text = str_replace($sensitive, '/***', $text);
            }
        }

        return $text;
    }
}
