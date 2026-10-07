<?php

namespace Msahidurr\ErrorNotifier\Contracts;

use Msahidurr\ErrorNotifier\ErrorReport;

interface Channel
{
    /**
     * Deliver the report. Throw on failure; the notifier logs it and moves on
     * to the next channel.
     */
    public function send(ErrorReport $report): void;
}
