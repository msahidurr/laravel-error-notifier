<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\Channels\Concerns\FitsMessageLength;
use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * Shared text layout for chat apps that use markdown-style formatting.
 * User-controlled values (message, URL, trace...) always go inside code
 * spans/blocks so they cannot trigger formatting or mentions.
 */
abstract class MarkdownChannel extends HttpChannel
{
    use FitsMessageLength;

    protected const MAX_MESSAGE_LENGTH = 4000;

    protected function text(ErrorReport $report): string
    {
        return $this->fit(fn (array $budget) => $this->format($report, $budget), static::MAX_MESSAGE_LENGTH);
    }

    /**
     * @param  array{message: int, trace: int, value: int}  $budget
     */
    protected function format(ErrorReport $report, array $budget): string
    {
        $value = fn (string $text) => $this->code($this->limit($text, $budget['value']));

        $lines = [
            '🚨 '.$this->bold($this->escape($this->limit($report->title, 100))),
            $this->bold('Project:').' '.$this->escape($this->limit($report->appName, 100))
                .'  •  '.$this->bold('Environment:').' '.$this->escape($this->limit($report->environment, 50)),
            '',
            $this->bold('Exception:').' '.$value($report->exceptionClass),
            $this->bold('Message:'),
            $this->codeBlock($this->limit($report->message, $budget['message'])),
        ];

        foreach ($report->context as $label => $text) {
            $lines[] = $this->bold($this->escape($this->limit((string) $label, 50)).':').' '.$value($text);
        }

        $lines[] = $this->bold('File:').' '.$value($report->file.':'.$report->line);

        if ($report->trace && $budget['trace'] > 0) {
            $lines[] = $this->bold('Trace:');
            $lines[] = $this->codeBlock($this->limit($report->trace, $budget['trace']));
        }

        $lines[] = $this->bold('Time:').' '.$report->time->format('Y-m-d H:i:s T');

        return implode("\n", $lines);
    }

    protected function bold(string $text): string
    {
        return '*'.$text.'*';
    }

    protected function code(string $text): string
    {
        return '`'.$this->escape(str_replace('`', 'ˋ', $text)).'`';
    }

    protected function codeBlock(string $text): string
    {
        // A literal ``` inside the content would close the block early.
        return "```\n".$this->escape(str_replace('```', 'ˋˋˋ', $text))."\n```";
    }

    protected function escape(string $text): string
    {
        return $text;
    }
}
