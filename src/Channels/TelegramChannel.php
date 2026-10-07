<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\Channels\Concerns\FitsMessageLength;
use Msahidurr\ErrorNotifier\ErrorReport;

class TelegramChannel extends HttpChannel
{
    use FitsMessageLength;

    /**
     * Telegram rejects messages longer than 4096 characters.
     */
    protected const MAX_MESSAGE_LENGTH = 4096;

    public function send(ErrorReport $report): void
    {
        $this->sendMessage($this->fit(fn (array $budget) => $this->format($report, $budget), self::MAX_MESSAGE_LENGTH));
    }

    /**
     * Send a raw HTML-formatted message to the configured chat.
     */
    public function sendMessage(string $message): void
    {
        $token = $this->required('bot_token');
        $chatId = $this->required('chat_id');
        $threadId = $this->config['message_thread_id'] ?? null;

        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if (is_numeric($threadId)) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        $this->postJson($this->apiUrl()."/bot{$token}/sendMessage", $payload, [$token]);
    }

    /**
     * Base URL of the Bot API; override for a self-hosted Bot API server.
     */
    protected function apiUrl(): string
    {
        return rtrim((string) ($this->config['api_url'] ?? '') ?: 'https://api.telegram.org', '/');
    }

    /**
     * @param  array{message: int, trace: int, value: int}  $budget
     */
    protected function format(ErrorReport $report, array $budget): string
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
