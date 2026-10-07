<?php

namespace Msahidurr\ErrorNotifier;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Msahidurr\ErrorNotifier\Contracts\Channel;
use Psr\Log\LoggerInterface;
use Throwable;

class ErrorNotifier
{
    /**
     * Guards against reporting failures that occur while reporting.
     */
    protected bool $reporting = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Application $app,
        protected ChannelManager $channels,
        protected ErrorReportFactory $reports,
        protected Cache $cache,
        protected LoggerInterface $logger,
        protected array $config,
    ) {
    }

    /**
     * Send the exception to every configured channel if it passes all filters.
     */
    public function report(Throwable $exception): void
    {
        if ($this->reporting) {
            return;
        }

        $this->reporting = true;

        try {
            if ($this->shouldReport($exception) && ! $this->isThrottled($exception)) {
                $this->sendReport($this->reports->make($exception));
            }
        } catch (Throwable $e) {
            // Never allow error notification to break the application.
            $this->logFailure('report', $e);
        } finally {
            $this->reporting = false;
        }
    }

    /**
     * Deliver a report to the given channels (default: all configured), bypassing filters.
     *
     * @param  array<int, string>|null  $channels
     */
    public function sendReport(ErrorReport $report, ?array $channels = null): void
    {
        foreach ($channels ?? $this->configuredChannels() as $name) {
            try {
                $this->channel($name)->send($report);
            } catch (Throwable $e) {
                $this->logFailure($name, $e);
            }
        }
    }

    /**
     * Determine whether the exception should be reported.
     */
    public function shouldReport(Throwable $exception): bool
    {
        return $this->isEnabled()
            && ! $this->isIgnoredEnvironment()
            && ! $this->isExcludedPath()
            && ! $this->isExcludedException($exception);
    }

    public function isEnabled(): bool
    {
        return filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function channel(?string $name = null): Channel
    {
        return $this->channels->driver($name);
    }

    /**
     * @return array<int, string>
     */
    public function configuredChannels(): array
    {
        return array_values(array_unique((array) ($this->config['channels'] ?? [])));
    }

    /**
     * Register a custom channel driver: fn (Application $app, array $config): Channel.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->channels->extend($driver, $callback);

        return $this;
    }

    public function reportFactory(): ErrorReportFactory
    {
        return $this->reports;
    }

    protected function isIgnoredEnvironment(): bool
    {
        $environments = (array) ($this->config['ignored_environments'] ?? []);

        return $environments !== [] && $this->app->environment($environments);
    }

    /**
     * Determine whether the current request path is excluded from reporting.
     */
    protected function isExcludedPath(): bool
    {
        if ($this->reports->isConsole() || ! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app['request'];

        foreach ((array) ($this->config['excluded_paths'] ?? []) as $path) {
            $path = trim($path, '/');

            if ($request->is($path, $path.'/*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the exception type should be excluded from reporting.
     */
    protected function isExcludedException(Throwable $exception): bool
    {
        foreach ((array) ($this->config['excluded_exceptions'] ?? []) as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suppress identical exceptions reported within the throttle window.
     */
    protected function isThrottled(Throwable $exception): bool
    {
        $seconds = (int) ($this->config['throttle_seconds'] ?? 0);

        if ($seconds <= 0) {
            return false;
        }

        $key = 'error-notifier:'.sha1(implode('|', [
            get_class($exception),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getMessage(),
        ]));

        try {
            // add() only succeeds when the key does not exist yet.
            return ! $this->cache->add($key, true, $seconds);
        } catch (Throwable) {
            return false;
        }
    }

    protected function logFailure(string $channel, Throwable $e): void
    {
        $this->logger->error('Error notifier failed.', [
            'channel' => $channel,
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
    }
}
