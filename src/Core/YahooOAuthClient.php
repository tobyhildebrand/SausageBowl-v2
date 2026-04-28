<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * YahooOAuthClient – manages the OAuth 2.0 token lifecycle with Yahoo.
 *
 * This is an app-level integration: one administrator performs the OAuth
 * dance once via the /yahoo/connect flow, and the tokens are stored in the
 * `app_settings` table. Every subsequent request uses the stored tokens,
 * auto-refreshing when they are about to expire.
 *
 * Yahoo OAuth 2.0 endpoints:
 *   Authorization : https://api.login.yahoo.com/oauth2/request_auth
 *   Token         : https://api.login.yahoo.com/oauth2/get_token
 *
 * Required scope: fspt-r  (Fantasy Sports – read)
 */
class YahooOAuthClient
{
    private const AUTH_URL  = 'https://api.login.yahoo.com/oauth2/request_auth';
    private const TOKEN_URL = 'https://api.login.yahoo.com/oauth2/get_token';
    private const SCOPE     = 'fspt-r';

    // Stored token setting key in app_settings.
    private const SETTINGS_KEY = 'yahoo_oauth_token';

    // Refresh the token if it expires within this many seconds.
    private const REFRESH_BUFFER_SECONDS = 300;

    private array $yahooConfig;

    public function __construct(array $yahooConfig)
    {
        $this->yahooConfig = $yahooConfig;
    }

    // -------------------------------------------------------------------------
    // Authorization URL
    // -------------------------------------------------------------------------

    /**
     * Returns the Yahoo authorization URL the admin must visit.
     * Stores a CSRF state token in the session.
     */
    public function getAuthUrl(): string
    {
        $this->startSessionIfNeeded();

        $state = bin2hex(random_bytes(16));
        $_SESSION['yahoo_oauth_state'] = $state;

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->yahooConfig['client_id'],
            'redirect_uri'  => $this->yahooConfig['redirect_uri'],
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    // -------------------------------------------------------------------------
    // Exchange authorization code for tokens
    // -------------------------------------------------------------------------

    /**
     * Validates the OAuth callback, exchanges the code for tokens, and
     * persists them to the database.
     *
     * @throws RuntimeException on CSRF mismatch, missing code, or API error.
     */
    public function handleCallback(string $code, string $state): void
    {
        $this->startSessionIfNeeded();

        // CSRF check.
        $expectedState = $_SESSION['yahoo_oauth_state'] ?? '';
        unset($_SESSION['yahoo_oauth_state']);

        if (!hash_equals($expectedState, $state) || $expectedState === '') {
            throw new RuntimeException('OAuth state mismatch – possible CSRF attack.');
        }

        $tokens = $this->requestTokens([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->yahooConfig['redirect_uri'],
        ]);

        $this->persistTokens($tokens);
    }

    // -------------------------------------------------------------------------
    // Get a valid access token (refreshing automatically if needed)
    // -------------------------------------------------------------------------

    /**
     * Returns a valid access token, refreshing it transparently if expired.
     *
     * @throws RuntimeException if no tokens are stored yet (admin must connect).
     */
    public function getValidAccessToken(): string
    {
        $tokens = $this->loadTokens();

        if ($tokens === null) {
            throw new RuntimeException(
                'Yahoo not connected. Visit /yahoo/connect to authorise the app.'
            );
        }

        if ($this->isExpired($tokens)) {
            $tokens = $this->refresh($tokens['refresh_token']);
        }

        return $tokens['access_token'];
    }

    /**
     * Returns true if Yahoo tokens have been stored (i.e. admin has connected).
     */
    public function isConnected(): bool
    {
        return $this->loadTokens() !== null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Refresh tokens using the stored refresh_token. */
    private function refresh(string $refreshToken): array
    {
        $tokens = $this->requestTokens([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $this->persistTokens($tokens);

        return $tokens;
    }

    /**
     * Make a POST request to Yahoo's token endpoint.
     * Yahoo requires HTTP Basic Auth with client_id:client_secret.
     */
    private function requestTokens(array $params): array
    {
        $ch = curl_init(self::TOKEN_URL);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_USERPWD        => $this->yahooConfig['client_id']
                                      . ':' . $this->yahooConfig['client_secret'],
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $body  = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException("Yahoo token request failed (curl): {$error}");
        }

        $data = json_decode($body, true);

        if ($httpCode !== 200 || !isset($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? $body;
            throw new RuntimeException("Yahoo token endpoint error ({$httpCode}): {$msg}");
        }

        // Store the absolute expiry time instead of the relative expires_in.
        $data['expires_at'] = time() + (int) ($data['expires_in'] ?? 3600);

        return $data;
    }

    private function isExpired(array $tokens): bool
    {
        return ($tokens['expires_at'] ?? 0) < (time() + self::REFRESH_BUFFER_SECONDS);
    }

    // -------------------------------------------------------------------------
    // Token persistence (app_settings table)
    // -------------------------------------------------------------------------

    private function persistTokens(array $tokens): void
    {
        $pdo = DB::get();

        $json = json_encode($tokens, JSON_THROW_ON_ERROR);

        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_at    = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            ':key'   => self::SETTINGS_KEY,
            ':value' => $json,
        ]);
    }

    private function loadTokens(): ?array
    {
        $pdo  = DB::get();
        $stmt = $pdo->prepare(
            'SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => self::SETTINGS_KEY]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $tokens = json_decode($row['setting_value'], true);

        return is_array($tokens) ? $tokens : null;
    }

    private function startSessionIfNeeded(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
