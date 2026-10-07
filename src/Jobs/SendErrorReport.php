<?php

namespace Msahidurr\ErrorNotifier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Msahidurr\ErrorNotifier\ErrorNotifier;
use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * Delivers a report from a queue worker so the failing request isn't slowed
 * down by the channel HTTP calls.
 */
class SendErrorReport implements ShouldQueue
{
    use Queueable;

    /**
     * Channel failures are logged, not retried, to avoid duplicate alerts.
     */
    public int $tries = 1;

    /**
     * @param  array<int, string>|null  $channels
     */
    public function __construct(public ErrorReport $report, public ?array $channels = null)
    {
    }

    public function handle(ErrorNotifier $notifier): void
    {
        $notifier->sendReport($this->report, $this->channels);
    }
}
