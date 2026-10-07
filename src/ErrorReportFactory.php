<?php

namespace Msahidurr\ErrorNotifier;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Throwable;

class ErrorReportFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Application $app,
        protected array $config,
    ) {
    }

    public function make(Throwable $exception): ErrorReport
    {
        $traceLines = (int) ($this->config['trace_lines'] ?? 0);

        $trace = $traceLines > 0
            ? $this->relativePath(implode("\n", array_slice(explode("\n", $exception->getTraceAsString()), 0, $traceLines)))
            : null;

        return new ErrorReport(
            title: (string) ($this->config['title'] ?? 'Application Error'),
            appName: (string) $this->app['config']->get('app.name'),
            environment: $this->app->environment(),
            exceptionClass: get_class($exception),
            message: $this->clean(Str::limit($this->clean($exception->getMessage()), 1000)),
            file: $this->clean($this->relativePath($exception->getFile())),
            line: $exception->getLine(),
            context: array_map(fn ($value) => $this->clean((string) $value), $this->context()),
            trace: $trace === null ? null : $this->clean($trace),
            time: now(),
            fingerprint: $this->fingerprint($exception),
            exception: $exception,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function context(): array
    {
        if ($this->isConsole()) {
            $command = implode(' ', array_slice($_SERVER['argv'] ?? [], 1));

            return ['Command' => $command !== '' ? $command : 'console'];
        }

        $request = $this->app['request'];

        $context = ['Request' => $request->method().' '.$request->fullUrl()];

        if ($this->config['include_user'] ?? true) {
            $context['User'] = $this->resolveUser();
        }

        if ($this->config['include_ip'] ?? true) {
            $context['IP'] = (string) $request->ip();
        }

        return $context;
    }

    /**
     * Stable hash identifying "the same error": class + file + line + message.
     */
    public function fingerprint(Throwable $exception): string
    {
        return sha1(implode('|', [
            get_class($exception),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getMessage(),
        ]));
    }

    /**
     * Replace invalid UTF-8 bytes, which would otherwise break JSON encoding
     * for every channel.
     */
    protected function clean(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * Strip the project root from paths so messages stay short and readable.
     */
    protected function relativePath(string $value): string
    {
        return str_replace(rtrim($this->app->basePath(), '/').'/', '', $value);
    }

    /**
     * Whether we are in a real console context (artisan, queue worker) rather
     * than handling an HTTP request.
     */
    public function isConsole(): bool
    {
        return $this->app->runningInConsole() && ! $this->app->runningUnitTests();
    }

    protected function resolveUser(): string
    {
        try {
            $user = $this->app['auth']->user();
        } catch (Throwable) {
            return 'Unknown';
        }

        if (! $user) {
            return 'Guest';
        }

        $id = (string) $user->getAuthIdentifier();
        $attribute = $this->config['user_name_attribute'] ?? null;

        $name = $attribute ? ($user->{$attribute} ?? null) : null;

        if ((is_scalar($name) || $name instanceof \Stringable) && filled((string) $name)) {
            return "{$id} ({$name})";
        }

        return $id;
    }
}
