<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * Google Chat space webhook (https://developers.google.com/workspace/chat/quickstart/webhooks).
 * Limits: 32 KB per message, about 1 message per second per space.
 */
class GoogleChatChannel extends MarkdownChannel
{
    public function send(ErrorReport $report): void
    {
        $url = $this->required('webhook_url');

        if (! empty($this->config['thread_key'])) {
            // Group all error reports into one thread instead of flooding the space.
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query([
                'threadKey' => $this->config['thread_key'],
                'messageReplyOption' => 'REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD',
            ]);
        }

        $this->postJson($url, ['text' => $this->text($report)]);
    }
}
