<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable
    |--------------------------------------------------------------------------
    */

    'enabled' => env('ERROR_NOTIFIER_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Active Channels
    |--------------------------------------------------------------------------
    |
    | The drivers every reported exception is sent to. Each name must match a
    | key in "drivers" below or a driver registered with ErrorNotifier::extend().
    | Example: ERROR_NOTIFIER_CHANNELS=telegram,slack
    |
    */

    'channels' => array_filter(array_map('trim', explode(',', env('ERROR_NOTIFIER_CHANNELS', 'telegram')))),

    /*
    |--------------------------------------------------------------------------
    | Automatic Reporting
    |--------------------------------------------------------------------------
    |
    | When true, the package hooks into Laravel's exception handler and sends
    | every reported exception. Set to false to call ErrorNotifier::report($e)
    | yourself.
    |
    */

    'auto_report' => env('ERROR_NOTIFIER_AUTO_REPORT', true),

    /*
    |--------------------------------------------------------------------------
    | Message Title
    |--------------------------------------------------------------------------
    */

    'title' => env('ERROR_NOTIFIER_TITLE', 'Application Error'),

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | excluded_paths: Request::is() patterns; each also matches its sub-paths,
    | e.g. 'api/v2/otp/generate' also excludes 'api/v2/otp/generate/*'.
    |
    | excluded_exceptions: exception classes (and subclasses) never reported.
    |
    */

    'ignored_environments' => ['local', 'testing'],

    'excluded_paths' => [
        // 'api/v2/otp/generate',
    ],

    'excluded_exceptions' => [
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Content
    |--------------------------------------------------------------------------
    |
    | user_name_attribute: the authenticated user attribute shown next to the
    | user ID (null to show only the ID).
    |
    | trace_lines: number of stack trace lines to include (0 to disable).
    |
    */

    'include_user' => true,

    'user_name_attribute' => 'name',

    'include_ip' => true,

    'trace_lines' => 0,

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    |
    | Suppress duplicate reports of the same exception (class + file + line +
    | message) for the given number of seconds, using the default cache
    | store. Set to 0 to disable.
    |
    */

    'throttle_seconds' => env('ERROR_NOTIFIER_THROTTLE', 60),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Send reports from a queue worker instead of during the failing request.
    | Recommended when using several channels or the mail channel. If the
    | report cannot be queued (e.g. the queue backend is down), it is sent
    | immediately instead.
    |
    */

    'queue' => [
        'enabled' => env('ERROR_NOTIFIER_QUEUE', false),
        'connection' => env('ERROR_NOTIFIER_QUEUE_CONNECTION'),
        'queue' => env('ERROR_NOTIFIER_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Drivers
    |--------------------------------------------------------------------------
    |
    | Settings for each channel. Only channels listed in "channels" are used.
    |
    | Every driver also accepts:
    |   'rate_limit'        => max messages per minute (0 = unlimited); extra
    |                          reports are dropped and a warning is logged.
    |   'only_exceptions'   => [...] send only these exception classes.
    |   'except_exceptions' => [...] never send these exception classes.
    |
    */

    'drivers' => [

        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_ERROR_CHAT_ID'),
            'message_thread_id' => env('TELEGRAM_ERROR_THREAD_ID'),
            'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
            'timeout' => 5,
            'rate_limit' => 20, // Telegram allows about 20 messages/minute per group.
        ],

        'slack' => [
            'webhook_url' => env('SLACK_ERROR_WEBHOOK_URL'),
            'timeout' => 5,
            'rate_limit' => 50, // Slack allows about 1 message/second per webhook.
        ],

        'mattermost' => [
            'webhook_url' => env('MATTERMOST_ERROR_WEBHOOK_URL'),
            'timeout' => 5,
            'rate_limit' => 50,
        ],

        'discord' => [
            'webhook_url' => env('DISCORD_ERROR_WEBHOOK_URL'),
            'username' => env('DISCORD_ERROR_USERNAME'),
            'timeout' => 5,
            'rate_limit' => 25, // Discord allows about 30 requests/minute per webhook.
        ],

        'google_chat' => [
            'webhook_url' => env('GOOGLE_CHAT_ERROR_WEBHOOK_URL'),
            'thread_key' => env('GOOGLE_CHAT_ERROR_THREAD_KEY'), // optional: keep all reports in one thread
            'timeout' => 5,
            'rate_limit' => 50, // Google Chat allows about 1 message/second per space.
        ],

        'teams' => [
            // A Teams *Workflows* webhook URL. Old Office 365 connector URLs stopped working in May 2026.
            'webhook_url' => env('TEAMS_ERROR_WEBHOOK_URL'),
            'timeout' => 10,
            'rate_limit' => 30,
        ],

        'mail' => [
            'to' => env('ERROR_NOTIFIER_MAIL_TO'), // comma-separated addresses
            'from' => env('ERROR_NOTIFIER_MAIL_FROM'), // defaults to mail.from
            'mailer' => env('ERROR_NOTIFIER_MAILER'), // defaults to the app's default mailer
            'rate_limit' => 10,
        ],

        'webhook' => [
            'url' => env('ERROR_NOTIFIER_WEBHOOK_URL'),
            'secret' => env('ERROR_NOTIFIER_WEBHOOK_SECRET'), // signs the body: X-Error-Notifier-Signature
            'headers' => [],
            'timeout' => 5,
            'rate_limit' => 60,
        ],

        'pagerduty' => [
            'routing_key' => env('PAGERDUTY_ROUTING_KEY'),
            'severity' => env('PAGERDUTY_SEVERITY', 'error'), // critical, error, warning or info
            'timeout' => 5,
            'rate_limit' => 60,
        ],

    ],

];
