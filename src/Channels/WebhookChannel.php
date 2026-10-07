<?php

namespace Msahidurr\ErrorNotifier\Channels;

use Msahidurr\ErrorNotifier\ErrorReport;

/**
 * POSTs the report as JSON to any URL (n8n, Zapier, your own service...).
 * With a secret configured, the body is signed with HMAC-SHA256 in the
 * X-Error-Notifier-Signature header ("sha256=<hex>").
 */
class WebhookChannel extends HttpChannel
{
    public function send(ErrorReport $report): void
    {
        $body = $this->encode($report->toArray());
        $secret = (string) ($this->config['secret'] ?? '');
        $headers = array_map('strval', (array) ($this->config['headers'] ?? []));

        // Credential headers are redacted from errors; harmless ones (e.g. X-Team: api)
        // are not, or every "api" in the error message would become "***".
        $secrets = [$secret];

        foreach ($headers as $name => $value) {
            if (preg_match('/auth|token|key|secret|signature|password|cookie/i', (string) $name)) {
                $secrets[] = $value;
                $secrets[] = preg_replace('/^(Bearer|Basic|Token)\s+/i', '', $value);
            }
        }

        if ($secret !== '') {
            $headers['X-Error-Notifier-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        $this->postBody($this->required('url'), $body, $secrets, $headers);
    }
}
