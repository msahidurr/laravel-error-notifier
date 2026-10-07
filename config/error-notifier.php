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
    | Channel Drivers
    |--------------------------------------------------------------------------
    |
    | Per-channel settings. Each entry is passed to its driver.
    |
    */

    'drivers' => [

        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_ERROR_CHAT_ID'),
            'message_thread_id' => env('TELEGRAM_ERROR_THREAD_ID'),
            'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
            'timeout' => 5,
        ],

    ],

];
