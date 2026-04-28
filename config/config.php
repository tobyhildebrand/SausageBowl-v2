<?php
// Runtime configuration.
// Secrets are read from environment variables set either by the web server
// (e.g. SetEnv in Apache directives) or by config/secrets.php (gitignored).
// Never hardcode credentials here.

// Load secrets.php if present – fallback for hosts without server-level env vars.
$secretsFile = __DIR__ . '/secrets.php';
if (file_exists($secretsFile)) {
    require $secretsFile;
}
unset($secretsFile);

/**
 * Require an environment variable. Throws immediately if missing or empty
 * so the app fails loudly rather than connecting with broken credentials.
 */
$requireEnv = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException(
            "Required environment variable '{$name}' is not set."
        );
    }
    return $value;
};

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => $requireEnv('DB_NAME'),
        'user'    => $requireEnv('DB_USER'),
        'pass'    => $requireEnv('DB_PASS'),
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'        => 'NowItGoesAroundTheSausage',
        'league_size' => 12,
        'debug'       => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    ],
    'yahoo' => [
        'client_id'     => $requireEnv('YAHOO_CLIENT_ID'),
        'client_secret' => $requireEnv('YAHOO_CLIENT_SECRET'),
        'redirect_uri'  => $requireEnv('YAHOO_REDIRECT_URI'),
        'game_key'      => getenv('YAHOO_GAME_KEY') ?: 'nfl',
        'league_key'    => $requireEnv('YAHOO_LEAGUE_KEY'),
    ],
];

