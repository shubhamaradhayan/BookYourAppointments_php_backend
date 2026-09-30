<?php

declare(strict_types=1);

/**
 * BookFlow configuration.
 *
 * Configuration is loaded from environment variables so secrets are not
 * committed to source control.
 */

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    return $value;
}

return [
    'app' => [
        'env' => env('APP_ENV', 'production'),
        'url' => rtrim((string) env('APP_URL', ''), '/'),
        'frontend_url' => rtrim((string) env('FRONTEND_URL', ''), '/'),
    ],

    'database' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'appointment_saas'),
        'user' => env('DB_USER', 'root'),
        'password' => env('DB_PASS', 'root'),
    ],

    'jwt' => [
        'secret' => (string) env('JWT_SECRET', ''),
        'issuer' => env('JWT_ISSUER', 'bookflow'),
        'access_ttl' => (int) env('JWT_ACCESS_TTL', '900'),
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', '2592000'),
    ],

    'encryption' => [
        'key' => (string) env('APP_ENCRYPTION_KEY', ''),
    ],

    'mail' => [
        'enabled' => filter_var(env('MAIL_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'host' => (string) env('MAIL_HOST', 'smtp.gmail.com'),
        'port' => (int) env('MAIL_PORT', '587'),
        'username' => (string) env('MAIL_USERNAME', ''),
        'password' => (string) env('MAIL_PASSWORD', ''),
        'encryption' => strtolower((string) env('MAIL_ENCRYPTION', 'tls')),
        'from_email' => (string) env('MAIL_FROM_EMAIL', ''),
        'from_name' => (string) env('MAIL_FROM_NAME', 'BookFlow'),
    ],

    'google' => [
        'client_id' => (string) env('GOOGLE_CLIENT_ID', ''),
        'login_client_id' => (string) env(
            'GOOGLE_LOGIN_CLIENT_ID',
            env('GOOGLE_CLIENT_ID', '')
        ),
        'client_secret' => (string) env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => (string) env('GOOGLE_REDIRECT_URI', ''),
        'calendar_id' => env('GOOGLE_CALENDAR_ID', 'primary'),
        'scope' => (string) env(
            'GOOGLE_CALENDAR_SCOPE',
            'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.events.freebusy'
        ),
    ],
];
