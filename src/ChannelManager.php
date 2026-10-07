<?php

namespace Msahidurr\ErrorNotifier;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use Msahidurr\ErrorNotifier\Channels\DiscordChannel;
use Msahidurr\ErrorNotifier\Channels\GoogleChatChannel;
use Msahidurr\ErrorNotifier\Channels\MailChannel;
use Msahidurr\ErrorNotifier\Channels\MattermostChannel;
use Msahidurr\ErrorNotifier\Channels\PagerDutyChannel;
use Msahidurr\ErrorNotifier\Channels\SlackChannel;
use Msahidurr\ErrorNotifier\Channels\TeamsChannel;
use Msahidurr\ErrorNotifier\Channels\TelegramChannel;
use Msahidurr\ErrorNotifier\Channels\WebhookChannel;
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

    protected function createSlackDriver(): Channel
    {
        return new SlackChannel($this->driverConfig('slack'));
    }

    protected function createMattermostDriver(): Channel
    {
        return new MattermostChannel($this->driverConfig('mattermost'));
    }

    protected function createDiscordDriver(): Channel
    {
        return new DiscordChannel($this->driverConfig('discord'));
    }

    protected function createGoogleChatDriver(): Channel
    {
        return new GoogleChatChannel($this->driverConfig('google_chat'));
    }

    protected function createTeamsDriver(): Channel
    {
        return new TeamsChannel($this->driverConfig('teams'));
    }

    protected function createMailDriver(): Channel
    {
        return new MailChannel($this->container->make('mail.manager'), $this->driverConfig('mail'));
    }

    protected function createWebhookDriver(): Channel
    {
        return new WebhookChannel($this->driverConfig('webhook'));
    }

    protected function createPagerdutyDriver(): Channel
    {
        return new PagerDutyChannel($this->driverConfig('pagerduty'));
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
