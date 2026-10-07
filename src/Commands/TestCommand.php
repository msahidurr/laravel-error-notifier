<?php

namespace Msahidurr\ErrorNotifier\Commands;

use Illuminate\Console\Command;
use Msahidurr\ErrorNotifier\ErrorNotifier;
use RuntimeException;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'error-notifier:test
                            {--channel=* : Channel(s) to test (default: all configured channels)}';

    protected $description = 'Send a test error report to the configured channels';

    public function handle(ErrorNotifier $notifier): int
    {
        $channels = $this->option('channel') ?: $notifier->configuredChannels();

        if ($channels === []) {
            $this->error('No channels configured. Set ERROR_NOTIFIER_CHANNELS.');

            return self::FAILURE;
        }

        $report = $notifier->reportFactory()->make(
            new RuntimeException('This is a test exception from error-notifier:test.')
        );

        $failed = false;

        // Bypasses the enabled/environment/throttle filters so credentials
        // can be verified from any environment.
        foreach ($channels as $name) {
            try {
                $notifier->channel($name)->send($report);
                $this->info("✔ {$name}: sent");
            } catch (Throwable $e) {
                $failed = true;
                $this->error("✘ {$name}: {$e->getMessage()}");
            }
        }

        if (! $notifier->isEnabled()) {
            $this->warn('Note: ERROR_NOTIFIER_ENABLED is false, so real exceptions are not being sent.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
