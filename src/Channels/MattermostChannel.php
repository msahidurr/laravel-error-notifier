<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * Mattermost incoming webhook. Same payload shape as Slack, but standard
 * markdown: **bold**, and no HTML entity escaping.
 */
class MattermostChannel extends MarkdownChannel
{
    public function send(ErrorReport $report): void
    {
        $payload = ['text' => $this->text($report)];

        foreach (['username', 'icon_url', 'channel'] as $key) {
            if (! empty($this->config[$key])) {
                $payload[$key] = $this->config[$key];
            }
        }

        $this->postJson($this->required('webhook_url'), $payload);
    }

    protected function bold(string $text): string
    {
        return '**'.$text.'**';
    }
}
