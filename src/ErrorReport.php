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
        public readonly ?Throwable $exception = null,
    ) {
    }
}
