<?php

namespace Msahidurr\ErrorNotifier;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use Msahidurr\ErrorNotifier\Channels\TelegramChannel;
use Msahidurr\ErrorNotifier\Contracts\Channel;

/**
 * Resolves channel drivers. Built-in drivers are create{Name}Driver methods;
 * apps add their own with ErrorNotifier::extend('name', fn ($app, $config) => new MyChannel($config)).
 *
 * @method Channel driver(string|null $driver = null)
 */
class ChannelManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $channels = $this->config->get('error-notifier.channels', []);

        if (empty($channels)) {
            throw new InvalidArgumentException('No error-notifier channels are configured.');
        }

        return reset($channels);
    }

    protected function createTelegramDriver(): Channel
    {
        return new TelegramChannel($this->driverConfig('telegram'));
    }

    /**
     * Custom creators receive the driver's config as a second argument.
     */
    protected function callCustomCreator($driver)
    {
        return $this->customCreators[$driver]($this->container, $this->driverConfig($driver));
    }

    /**
     * @return array<string, mixed>
     */
    protected function driverConfig(string $driver): array
    {
        return (array) $this->config->get("error-notifier.drivers.{$driver}", []);
    }
}
