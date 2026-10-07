<?php

namespace Msahidurr\ErrorNotifier;

use DateTimeInterface;
use Throwable;

/**
 * Channel-independent description of a reported exception. Channels only
 * decide how to format and deliver it.
 */
class ErrorReport
{
    /**
     * @param  array<string, string>  $context  Ordered label => value pairs (Request, User, IP, Command...).
     * @param  string  $fingerprint  Stable hash of class + file + line + message; identical errors share it.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $appName,
        public readonly string $environment,
        public readonly string $exceptionClass,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly array $context,
        public readonly ?string $trace,
        public readonly DateTimeInterface $time,
        public readonly string $fingerprint = '',
        public readonly ?Throwable $exception = null,
    ) {
    }

    /**
     * Copy without the exception object, so the report can be serialized for the queue.
     */
    public function withoutException(): static
    {
        return new static(
            $this->title,
            $this->appName,
            $this->environment,
            $this->exceptionClass,
            $this->message,
            $this->file,
            $this->line,
            $this->context,
            $this->trace,
            $this->time,
            $this->fingerprint,
        );
    }

    /**
     * Whether the reported exception is (a subclass of) the given class.
     */
    public function isA(string $class): bool
    {
        return $this->exceptionClass === ltrim($class, '\\') || is_a($this->exceptionClass, $class, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'app' => $this->appName,
            'environment' => $this->environment,
            'exception' => $this->exceptionClass,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'context' => $this->context,
            'trace' => $this->trace,
            'time' => $this->time->format(DateTimeInterface::ATOM),
            'fingerprint' => $this->fingerprint,
        ];
    }
}
