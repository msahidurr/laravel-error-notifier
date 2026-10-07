<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Illuminate\Support\Facades\Http;
use Msahidurr\ErrorNotifier\Contracts\Channel;
use Msahidurr\ErrorNotifier\ErrorReport;
use RuntimeException;
use Throwable;

class TelegramChannel implements Channel
{
    /**
     * Telegram rejects messages longer than 4096 characters.
     */
    protected const MAX_MESSAGE_LENGTH = 4096;

    /**
     * Raw (pre-escaping) character limits per field, tried in order until the
     * formatted message fits. Truncating raw values before escaping keeps the
     * HTML valid; truncating the final HTML could cut a tag or entity in half
     * and make Telegram reject the whole message.
     *
     * @var array<int, array{message: int, trace: int, value: int}>
     */
    protected const BUDGETS = [
        ['message' => 1000, 'trace' => 1500, 'value' => 500],
        ['message' => 600, 'trace' => 600, 'value' => 300],
        ['message' => 300, 'trace' => 0, 'value' => 150],
        ['message' => 100, 'trace' => 0, 'value' => 60],
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config)
    {
    }

    public function send(ErrorReport $report): void
    {
        $this->sendMessage($this->format($report));
    }

    /**
     * Send a raw HTML-formatted message to the configured chat.
     */
    public function sendMessage(string $message): void
    {
        $token = (string) ($this->config['bot_token'] ?? '');

        if ($token === '' || empty($this->config['chat_id'])) {
            throw new RuntimeException('Telegram channel is not configured (bot_token / chat_id missing).');
        }

        $threadId = $this->config['message_thread_id'] ?? null;

        $payload = [
            'chat_id' => $this->config['chat_id'],
            'text' => $message,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if (is_numeric($threadId)) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        try {
            Http::timeout((int) ($this->config['timeout'] ?? 5))
                ->post($this->apiUrl()."/bot{$token}/sendMessage", $payload)
                ->throw();
        } catch (Throwable $e) {
            // HTTP client errors include the request URL, which contains the bot token.
            throw new RuntimeException(str_replace($token, '***', $e->getMessage()), 0);
        }
    }

    /**
     * Base URL of the Bot API; override for a self-hosted Bot API server.
     */
    protected function apiUrl(): string
    {
        return rtrim((string) ($this->config['api_url'] ?? '') ?: 'https://api.telegram.org', '/');
    }

    protected function format(ErrorReport $report): string
    {
        foreach (self::BUDGETS as $budget) {
            $message = $this->formatWithBudget($report, $budget);

            if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
                return $message;
            }
        }

        return $message;
    }

    /**
     * @param  array{message: int, trace: int, value: int}  $budget
     */
    protected function formatWithBudget(ErrorReport $report, array $budget): string
    {
        $value = fn (string $text) => $this->code($this->limit($text, $budget['value']));

        $lines = [
            '🚨 <b>'.$this->escape($this->limit($report->title, 100)).'</b>',
            '',
            '<b>Project:</b> '.$this->escape($this->limit($report->appName, 100)),
            '<b>Environment:</b> '.$this->escape($this->limit($report->environment, 50)),
            '',
            '<b>Exception:</b>',
            $value($report->exceptionClass),
            '',
            '<b>Message:</b>',
            $this->code($this->limit($report->message, $budget['message'])),
            '',
        ];

        foreach ($report->context as $label => $text) {
            $lines[] = '<b>'.$this->escape($this->limit((string) $label, 50)).':</b> '.$value($text);
        }

        if ($report->context !== []) {
            $lines[] = '';
        }

        $lines[] = '<b>File:</b>';
        $lines[] = $value($report->file);
        $lines[] = '<b>Line:</b> '.$report->line;

        if ($report->trace && $budget['trace'] > 0) {
            $lines[] = '';
            $lines[] = '<b>Trace:</b>';
            $lines[] = '<pre>'.$this->escape($this->limit($report->trace, $budget['trace'])).'</pre>';
        }

        $lines[] = '';
        $lines[] = '<b>Time:</b> '.$report->time->format('Y-m-d H:i:s T');

        return implode("\n", $lines);
    }

    protected function limit(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }

    protected function code(string $value): string
    {
        return '<code>'.$this->escape($value).'</code>';
    }

    /**
     * Telegram HTML only requires <, > and & to be escaped.
     */
    protected function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
