<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\Channels\Concerns\FitsMessageLength;
use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * Microsoft Teams via a Workflows ("Post to a channel when a webhook request
 * is received") webhook URL, sent as an Adaptive Card. The old Office 365
 * connector webhooks were switched off in May 2026 and are not supported.
 */
class TeamsChannel extends HttpChannel
{
    use FitsMessageLength;

    /**
     * Teams rejects messages over about 28 KB; stay well below.
     */
    protected const MAX_PAYLOAD_BYTES = 20000;

    public function send(ErrorReport $report): void
    {
        $body = $this->fit(
            fn (array $budget) => $this->encode($this->payload($report, $budget)),
            self::MAX_PAYLOAD_BYTES,
            'strlen',
        );

        $this->postBody($this->required('webhook_url'), $body);
    }

    /**
     * @param  array{message: int, trace: int, value: int}  $budget
     * @return array<string, mixed>
     */
    protected function payload(ErrorReport $report, array $budget): array
    {
        $facts = [
            ['title' => 'Project', 'value' => $this->plain($report->appName, 100)],
            ['title' => 'Environment', 'value' => $this->plain($report->environment, 50)],
            ['title' => 'Exception', 'value' => $this->plain($report->exceptionClass, $budget['value'])],
        ];

        foreach ($report->context as $label => $value) {
            $facts[] = ['title' => $this->plain((string) $label, 50), 'value' => $this->plain($value, $budget['value'])];
        }

        $facts[] = ['title' => 'File', 'value' => $this->plain($report->file.':'.$report->line, $budget['value'])];
        $facts[] = ['title' => 'Time', 'value' => $report->time->format('Y-m-d H:i:s T')];

        $body = [
            ['type' => 'TextBlock', 'text' => '🚨 '.$this->plain($report->title, 100), 'weight' => 'Bolder', 'size' => 'Medium', 'color' => 'Attention', 'wrap' => true],
            $this->literal($report->message, $budget['message']),
            ['type' => 'FactSet', 'facts' => $facts],
        ];

        if ($report->trace && $budget['trace'] > 0) {
            $body[] = $this->literal($report->trace, $budget['trace'], 'Small');
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => $body,
                ],
            ]],
        ];
    }

    protected function plain(string $text, int $limit): string
    {
        return $this->limit($text, $limit);
    }

    /**
     * A TextRun is never parsed as markdown, so exception text shows exactly as-is.
     *
     * @return array<string, mixed>
     */
    protected function literal(string $text, int $limit, string $size = 'Default'): array
    {
        return [
            'type' => 'RichTextBlock',
            'inlines' => [[
                'type' => 'TextRun',
                'text' => $this->limit($text, $limit),
                'fontType' => 'Monospace',
                'size' => $size,
            ]],
        ];
    }
}
