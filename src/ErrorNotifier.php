<?php

namespace Msahidurr\ErrorNotifier;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Msahidurr\ErrorNotifier\Contracts\Channel;
use Msahidurr\ErrorNotifier\Jobs\SendErrorReport;
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
                $this->dispatch($this->reports->make($exception));
            }
        } catch (Throwable $e) {
            // Never allow error notification to break the application.
            $this->logFailure('report', $e);
        } finally {
            $this->reporting = false;
        }
    }

    /**
     * Queue the report when queueing is enabled, otherwise send it now.
     * If queueing fails (e.g. the queue backend is what's broken), send now.
     */
    public function dispatch(ErrorReport $report): void
    {
        if (! filter_var($this->config['queue']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->sendReport($report);

            return;
        }

        try {
            // Errors usually roll back the current DB transaction. With a queue
            // connection set to after_commit, the job would be discarded with it.
            $job = (new SendErrorReport($report->withoutException()))->beforeCommit();

            if (! empty($this->config['queue']['connection'])) {
                $job->onConnection($this->config['queue']['connection']);
            }

            if (! empty($this->config['queue']['queue'])) {
                $job->onQueue($this->config['queue']['queue']);
            }

            $this->app->make(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $this->logFailure('queue', $e);
            $this->sendReport($report);
        }
    }

    /**
     * Deliver a report to the given channels (default: all configured),
     * applying each channel's filters and rate limit.
     *
     * @param  array<int, string>|null  $channels
     */
    public function sendReport(ErrorReport $report, ?array $channels = null): void
    {
        foreach ($channels ?? $this->configuredChannels() as $name) {
            try {
                if (! $this->channelAccepts($name, $report)) {
                    continue;
                }

                if (! $this->withinRateLimit($name)) {
                    $this->warnRateLimited($name);

                    continue;
                }

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

    /**
     * Per-channel exception filters: drivers.<name>.only_exceptions / except_exceptions.
     */
    protected function channelAccepts(string $name, ErrorReport $report): bool
    {
        $config = $this->channelConfig($name);

        foreach ((array) ($config['except_exceptions'] ?? []) as $class) {
            if ($report->isA($class)) {
                return false;
            }
        }

        $only = (array) ($config['only_exceptions'] ?? []);

        if ($only === []) {
            return true;
        }

        foreach ($only as $class) {
            if ($report->isA($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-channel cap on messages per minute: drivers.<name>.rate_limit (0 = unlimited).
     */
    protected function withinRateLimit(string $name): bool
    {
        $max = (int) ($this->channelConfig($name)['rate_limit'] ?? 0);

        if ($max <= 0) {
            return true;
        }

        try {
            $limiter = new RateLimiter($this->cache);
            $key = 'error-notifier:rate:'.$name;

            if ($limiter->tooManyAttempts($key, $max)) {
                return false;
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            // If the cache is down, sending matters more than limiting.
        }

        return true;
    }

    /**
     * Log once per minute per channel, not once per dropped report, so a burst
     * of errors doesn't also flood the log.
     */
    protected function warnRateLimited(string $name): void
    {
        try {
            if (! $this->cache->add('error-notifier:rate-warned:'.$name, true, 60)) {
                return;
            }
        } catch (Throwable) {
            // Without the cache, warn every time rather than never.
        }

        $this->logger->warning('Error notifier rate limit reached; further reports to this channel are dropped for up to a minute.', [
            'channel' => $name,
            'rate_limit' => (int) ($this->channelConfig($name)['rate_limit'] ?? 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function channelConfig(string $name): array
    {
        return (array) ($this->config['drivers'][$name] ?? []);
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

        try {
            // add() only succeeds when the key does not exist yet.
            return ! $this->cache->add('error-notifier:'.$this->reports->fingerprint($exception), true, $seconds);
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
