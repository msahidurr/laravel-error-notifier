<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Message;
use Msahidurr\ErrorNotifier\Contracts\Channel;
use Msahidurr\ErrorNotifier\ErrorReport;
use RuntimeException;

/**
 * Plain-text email through the app's own mail setup.
 */
class MailChannel implements Channel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected MailFactory $mail, protected array $config)
    {
    }

    public function send(ErrorReport $report): void
    {
        $to = array_values(array_filter(array_map('trim', is_array($this->config['to'] ?? null)
            ? $this->config['to']
            : explode(',', (string) ($this->config['to'] ?? '')))));

        if ($to === []) {
            throw new RuntimeException(static::class.' is not configured (to missing).');
        }

        $subject = sprintf('[%s] %s in %s: %s',
            $report->title,
            class_basename($report->exceptionClass),
            $report->environment,
            mb_strimwidth(preg_replace('/\s+/', ' ', $report->message), 0, 120, '…'),
        );

        $this->mail->mailer($this->config['mailer'] ?? null)->raw($this->body($report), function (Message $message) use ($to, $subject) {
            $message->to($to)->subject($subject);

            if (! empty($this->config['from'])) {
                $message->from($this->config['from']);
            }
        });
    }

    protected function body(ErrorReport $report): string
    {
        $lines = [
            $report->title,
            str_repeat('=', mb_strlen($report->title)),
            '',
            'Project:     '.$report->appName,
            'Environment: '.$report->environment,
            'Exception:   '.$report->exceptionClass,
        ];

        foreach ($report->context as $label => $value) {
            $lines[] = str_pad($label.':', 13).$value;
        }

        $lines[] = 'File:        '.$report->file.':'.$report->line;
        $lines[] = 'Time:        '.$report->time->format('Y-m-d H:i:s T');
        $lines[] = '';
        $lines[] = 'Message:';
        $lines[] = $report->message;

        if ($report->trace) {
            $lines[] = '';
            $lines[] = 'Trace:';
            $lines[] = $report->trace;
        }

        return implode("\n", $lines);
    }
}
