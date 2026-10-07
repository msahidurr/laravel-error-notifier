<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\Channels\Concerns\FitsMessageLength;
use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * Discord channel webhook, sent as one embed.
 * Limits: title 256, description 4096, field value 1024, 6000 in total.
 */
class DiscordChannel extends HttpChannel
{
    use FitsMessageLength;

    protected const FIELD_LIMIT = 250;

    public function send(ErrorReport $report): void
    {
        $fields = [
            $this->field('Project', $report->appName),
            $this->field('Environment', $report->environment),
            $this->field('Exception', $report->exceptionClass, false),
        ];

        foreach ($report->context as $label => $value) {
            $fields[] = $this->field((string) $label, $value, $label !== 'Request' && $label !== 'Command');
        }

        $fields[] = $this->field('File', $report->file.':'.$report->line, false);

        $payload = [
            // Never ping @everyone/@here/users because of text inside an exception message.
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => $this->limit('🚨 '.$report->title, 250),
                'description' => $this->fit(fn (array $budget) => $this->description($report, $budget), 3500),
                'color' => 0xE01E5A,
                'fields' => $fields,
                'timestamp' => $report->time->format(DATE_ATOM),
            ]],
        ];

        foreach (['username', 'avatar_url'] as $key) {
            if (! empty($this->config[$key])) {
                $payload[$key] = $this->config[$key];
            }
        }

        $this->postJson($this->required('webhook_url'), $payload);
    }

    /**
     * @param  array{message: int, trace: int, value: int}  $budget
     */
    protected function description(ErrorReport $report, array $budget): string
    {
        $text = $this->codeBlock($this->limit($report->message, $budget['message']));

        if ($report->trace && $budget['trace'] > 0) {
            $text .= "\n**Trace**\n".$this->codeBlock($this->limit($report->trace, $budget['trace']));
        }

        return $text;
    }

    /**
     * @return array{name: string, value: string, inline: bool}
     */
    protected function field(string $name, string $value, bool $inline = true): array
    {
        $value = trim($value);

        return [
            'name' => $this->limit($name, 100) ?: '-',
            // Discord rejects embeds with an empty field value.
            'value' => $value === '' ? '-' : '`'.str_replace('`', 'ˋ', $this->limit($value, self::FIELD_LIMIT)).'`',
            'inline' => $inline,
        ];
    }

    protected function codeBlock(string $text): string
    {
        return "```\n".str_replace('```', 'ˋˋˋ', $text)."\n```";
    }
}
