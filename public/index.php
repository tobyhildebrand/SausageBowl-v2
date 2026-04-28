<?php

declare(strict_types=1);

/**
 * Entry point for all HTTP requests.
 *
 * The web server must be configured to route all requests here
 * (see .htaccess or nginx config).
 *
 * Document root: /public
 */

define('APP_ROOT', dirname(__DIR__));

// Composer autoloader
require APP_ROOT . '/vendor/autoload.php';

use App\Core\YahooApiClient;
use App\Core\YahooOAuthClient;
use App\Helpers\View;
use App\Services\RosterService;

// Load app config (used for display values; DB connection is lazy via DB::get())
$config = require APP_ROOT . '/config/config.php';

// Session is needed for the Yahoo OAuth CSRF state parameter.
session_start();

// Enable error display in debug mode only.
if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// -------------------------------------------------------------------------
// Basic front-controller routing.
// Supports two modes:
// 1) Clean URLs via rewrite       : /yahoo/connect
// 2) No-rewrite fallback (Plesk)  : /index.php?r=yahoo/connect
// -------------------------------------------------------------------------
$routeFromQuery = isset($_GET['r']) ? (string) $_GET['r'] : '';

if ($routeFromQuery !== '') {
    $uri = '/' . trim($routeFromQuery, '/');
} else {
    $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $uri = '/' . trim($uri, '/');

    // When the app is called as /index.php without rewrite, treat it as home.
    if ($uri === '/index.php') {
        $uri = '/';
    }
}

try {
    switch ($uri) {
        case '/':
            View::render('home', [
                'title'      => 'Home',
                'appName'    => $config['app']['name'],
                'leagueSize' => $config['app']['league_size'],
            ]);
            break;

        // ------------------------------------------------------------------
        // Yahoo OAuth flow
        // ------------------------------------------------------------------

        case '/yahoo/connect':
            // Initiate the OAuth dance – redirects the admin to Yahoo.
            $oauth = new YahooOAuthClient($config['yahoo']);
            header('Location: ' . $oauth->getAuthUrl());
            exit;

        case '/yahoo/callback':
            // Yahoo redirects back here with ?code=…&state=…
            $code  = $_GET['code']  ?? '';
            $state = $_GET['state'] ?? '';

            if ($code === '') {
                http_response_code(400);
                echo 'Missing authorization code.';
                exit;
            }

            $oauth = new YahooOAuthClient($config['yahoo']);
            $oauth->handleCallback($code, $state);

            View::render('yahoo_connect', [
                'title'   => 'Yahoo Connected',
                'appName' => $config['app']['name'],
                'success' => true,
            ]);
            break;

        case '/rosters':
            $oauth = new YahooOAuthClient($config['yahoo']);
            $apiClient = new YahooApiClient($oauth);
            $rosterService = new RosterService($apiClient, $config['yahoo']['league_key']);
            $teams = $rosterService->getAllRosters();
            View::render('rosters', [
                'title'   => 'Rosters',
                'appName' => $config['app']['name'],
                'teams'   => $teams,
            ]);
            break;

        default:
            http_response_code(404);
            View::render('home', [
                'title'      => '404 – Not Found',
                'appName'    => $config['app']['name'],
                'leagueSize' => $config['app']['league_size'],
            ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    if ($config['app']['debug']) {
        echo '<pre>' . htmlspecialchars((string) $e) . '</pre>';
    } else {
        echo 'An unexpected error occurred. Please try again later.';
    }
}
