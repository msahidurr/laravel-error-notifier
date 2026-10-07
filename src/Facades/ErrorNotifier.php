<?php

namespace Msahidurr\ErrorNotifier\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void report(\Throwable $exception)
 * @method static void sendReport(\Msahidurr\ErrorNotifier\ErrorReport $report, array|null $channels = null)
 * @method static bool shouldReport(\Throwable $exception)
 * @method static bool isEnabled()
 * @method static \Msahidurr\ErrorNotifier\Contracts\Channel channel(string|null $name = null)
 * @method static array configuredChannels()
 * @method static \Msahidurr\ErrorNotifier\ErrorNotifier extend(string $driver, \Closure $callback)
 *
 * @see \Msahidurr\ErrorNotifier\ErrorNotifier
 */
class ErrorNotifier extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Msahidurr\ErrorNotifier\ErrorNotifier::class;
    }
}
