<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * YahooApiClient – makes authenticated requests to the Yahoo Fantasy Sports API.
 *
 * Base URL : https://fantasysports.yahooapis.com/fantasy/v2/
 * Format   : JSON (?format=json appended to every request)
 *
 * Usage:
 *   $client = new YahooApiClient($yahooOAuthClient);
 *   $data   = $client->get('league/' . $leagueKey);
 *   $data   = $client->get('league/' . $leagueKey . '/teams');
 *
 * The client transparently refreshes the access token when it is expired.
 * On a genuine 401 response from Yahoo it retries once after a fresh token.
 */
class YahooApiClient
{
    private const BASE_URL = 'https://fantasysports.yahooapis.com/fantasy/v2/';

    private YahooOAuthClient $oauthClient;

    public function __construct(YahooOAuthClient $oauthClient)
    {
        $this->oauthClient = $oauthClient;
    }

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Perform a GET request to the Yahoo Fantasy Sports API.
     *
     * @param  string $endpoint  Path relative to the base URL, e.g. 'league/449.l.12345/teams'
     * @param  array  $params    Optional query parameters (merged with format=json).
     * @return array             Decoded JSON response as an associative array.
     *
     * @throws RuntimeException  On network error or non-2xx response.
     */
    public function get(string $endpoint, array $params = []): array
    {
        $accessToken = $this->oauthClient->getValidAccessToken();

        [$data, $httpCode] = $this->request($endpoint, $params, $accessToken);

        // On 401, the local token might be stale; force one refresh and retry.
        if ($httpCode === 401) {
            // YahooOAuthClient will refresh internally on the next call.
            $accessToken = $this->oauthClient->getValidAccessToken();
            [$data, $httpCode] = $this->request($endpoint, $params, $accessToken);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $error = $data['error']['description'] ?? "HTTP {$httpCode}";
            throw new RuntimeException("Yahoo API error for '{$endpoint}': {$error}");
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Execute a single curl GET and return [decoded_array, http_status_code].
     */
    private function request(string $endpoint, array $params, string $accessToken): array
    {
        $params['format'] = 'json';

        $url = self::BASE_URL . ltrim($endpoint, '/') . '?' . http_build_query($params);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $body     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $curlErr !== '') {
            throw new RuntimeException("Yahoo API request failed (curl): {$curlErr}");
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                "Yahoo API returned non-JSON response for '{$endpoint}'."
            );
        }

        return [$data, $httpCode];
    }
}
