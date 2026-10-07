<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\Channels\Concerns\FitsMessageLength;
use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * PagerDuty Events API v2. The report fingerprint is used as dedup_key, so
 * repeats of the same error update one incident instead of opening new ones.
 */
class PagerDutyChannel extends HttpChannel
{
    use FitsMessageLength;

    protected const SEVERITIES = ['critical', 'error', 'warning', 'info'];

    public function send(ErrorReport $report): void
    {
        $routingKey = $this->required('routing_key');
        $severity = in_array($this->config['severity'] ?? null, self::SEVERITIES, true) ? $this->config['severity'] : 'error';

        $details = $report->toArray();
        unset($details['title'], $details['fingerprint']);
        $details['message'] = $this->limit($report->message, 2000);
        $details['trace'] = $report->trace ? $this->limit($report->trace, 3000) : null;

        $event = [
            'routing_key' => $routingKey,
            'event_action' => 'trigger',
            'payload' => [
                // limit() adds "…", so stay one under PagerDuty's maximums.
                'summary' => $this->limit("[{$report->environment}] {$report->exceptionClass}: {$report->message}", 1000),
                'source' => $this->limit($report->appName ?: 'laravel', 254),
                'severity' => $severity,
                'timestamp' => $report->time->format(DATE_ATOM),
                'component' => $this->limit($report->exceptionClass, 254),
                'group' => $this->limit($report->environment, 254),
                'custom_details' => $details,
            ],
        ];

        if ($report->fingerprint !== '') {
            $event['dedup_key'] = $report->fingerprint;
        }

        $this->postJson('https://events.pagerduty.com/v2/enqueue', $event, [$routingKey]);
    }
}
