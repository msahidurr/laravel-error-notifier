<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * Slack incoming webhook (https://api.slack.com/messaging/webhooks).
 */
class SlackChannel extends MarkdownChannel
{
    public function send(ErrorReport $report): void
    {
        $payload = ['text' => $this->text($report)];

        foreach (['username', 'icon_emoji', 'channel'] as $key) {
            if (! empty($this->config[$key])) {
                $payload[$key] = $this->config[$key];
            }
        }

        $this->postJson($this->required('webhook_url'), $payload);
    }

    /**
     * Slack requires &, < and > to be escaped, even inside code blocks.
     * This also stops "<!channel>"-style mentions in exception messages.
     */
    protected function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
