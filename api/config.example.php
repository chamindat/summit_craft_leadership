<?php
/**
 * Copy this file to config.php and update the values for your environment.
 * Do not commit config.php to Git.
 *
 * Generate a strong app secret:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *
 * Generate an admin password hash:
 *   php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), PHP_EOL;"
 */
return [
    'app' => [
        'business_name' => 'SummitCraft Leadership',
        'debug' => true, // Use false on the live server.
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'summitcraft_leadership',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'admin' => [
        'username' => 'admin',
        // Default local password is ChangeMeNow!. Replace before live use.
        'password_hash' => '$2y$12$n5t3TDVBiFhQI9qd28UWyeBSN7vOafqKS.2ktMPD67o9VsZ4vMAye',
        'using_default_password' => true,
    ],
    'security' => [
        // Replace this with a long random value on every environment.
        'app_secret' => 'replace-with-a-long-random-secret-at-least-32-characters',
        'admin_cookie_name' => 'scl_admin_session',
        'admin_session_ttl_seconds' => 14400, // 4 hours
        'cookie_path' => '/',
        'cookie_secure' => false, // Set true on HTTPS live server.
        'cookie_samesite' => 'Strict',
        // Optional. Leave blank unless you have tested it with all fonts/scripts used by the site.
        'content_security_policy' => '',
    ],
    'rate_limits' => [
        'admin_login' => [
            'max_attempts' => 5,
            'window_seconds' => 900,
            'lock_seconds' => 1800,
        ],
        'public_forms' => [
            'max_attempts' => 8,
            'window_seconds' => 600,
            'lock_seconds' => 900,
        ],
    ],
    'email' => [
        // Initial version logs emails in email_logs. Set enabled=true and method='mail' only if PHP mail() is correctly configured.
        // For a real live site, use a proper SMTP provider/PHPMailer integration.
        'enabled' => false,
        'method' => 'log', // log or mail
        'admin_email' => 'Summitcraftleadership@outlook.com',
        'from_email' => 'noreply@example.com',
    ],
];
