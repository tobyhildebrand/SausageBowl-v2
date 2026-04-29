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
use App\Services\AuthService;
use App\Services\HistoricalInsightsService;
use App\Services\HistoricalPointsService;
use App\Services\HistoricalStatsService;
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

$auth = new AuthService();

$routeUrl = static function (string $route = '/'): string {
    $route = '/' . trim($route, '/');

    if ($route === '/') {
        return '/index.php';
    }

    return '/index.php?r=' . ltrim($route, '/');
};

$normalizeRedirectRoute = static function (?string $route): string {
    $route = '/' . trim((string) $route, '/');

    if ($route === '/' || $route === '') {
        return '/comish';
    }

    if (!preg_match('#^/[a-z0-9/_-]+$#i', $route)) {
        return '/comish';
    }

    return $route;
};

$render = static function (string $template, array $vars = []) use ($config, $auth): void {
    $vars += [
        'appName' => $config['app']['name'],
        'currentUser' => $auth->currentUser(),
    ];

    View::render($template, $vars);
};

try {
    switch ($uri) {
        case '/':
            $render('home', [
                'title'      => 'Home',
                'leagueSize' => $config['app']['league_size'],
            ]);
            break;

        case '/login':
            $hasUsers = $auth->hasAnyUsers();
            $redirectRoute = $normalizeRedirectRoute($_GET['redirect'] ?? $_POST['redirect'] ?? '/comish');
            $error = null;

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $action = (string) ($_POST['action'] ?? 'login');

                if ($action === 'bootstrap' && !$hasUsers) {
                    $displayName = trim((string) ($_POST['display_name'] ?? ''));
                    $email = trim((string) ($_POST['email'] ?? ''));
                    $password = (string) ($_POST['password'] ?? '');
                    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

                    if ($password !== $passwordConfirm) {
                        $error = 'Passwords do not match.';
                    } else {
                        try {
                            $auth->createInitialCommissioner($displayName, $email, $password);
                            $auth->login($email, $password);
                            header('Location: ' . $routeUrl($redirectRoute));
                            exit;
                        } catch (Throwable $e) {
                            $error = $e->getMessage();
                        }
                    }
                } elseif ($action === 'login' && $hasUsers) {
                    $email = trim((string) ($_POST['email'] ?? ''));
                    $password = (string) ($_POST['password'] ?? '');

                    if ($auth->login($email, $password)) {
                        header('Location: ' . $routeUrl($redirectRoute));
                        exit;
                    }

                    $error = 'Invalid email or password.';
                }
            } elseif ($auth->isCommissioner()) {
                header('Location: ' . $routeUrl($redirectRoute));
                exit;
            }

            $render('login', [
                'title' => $hasUsers ? 'Login' : 'Set Up Commissioner Account',
                'hasUsers' => $hasUsers,
                'redirectRoute' => $redirectRoute,
                'error' => $error,
            ]);
            break;

        case '/logout':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $auth->logout();
            }

            header('Location: ' . $routeUrl('/'));
            exit;

        case '/comish':
            if ($auth->isLoggedIn() && !$auth->isCommissioner()) {
                http_response_code(403);
                echo 'Commissioner access required.';
                exit;
            }

            if (!$auth->isCommissioner()) {
                header('Location: ' . $routeUrl('/login') . '&redirect=' . rawurlencode('/comish'));
                exit;
            }

            $render('comish', [
                'title'   => 'Comish',
            ]);
            break;

        // ------------------------------------------------------------------
        // Yahoo OAuth flow
        // ------------------------------------------------------------------

        case '/yahoo/connect':
            if ($auth->isLoggedIn() && !$auth->isCommissioner()) {
                http_response_code(403);
                echo 'Commissioner access required.';
                exit;
            }

            if (!$auth->isCommissioner()) {
                header('Location: ' . $routeUrl('/login') . '&redirect=' . rawurlencode('/comish'));
                exit;
            }

            // Initiate the OAuth dance – redirects the admin to Yahoo.
            $oauth = new YahooOAuthClient($config['yahoo']);
            header('Location: ' . $oauth->getAuthUrl());
            exit;

        case '/yahoo/callback':
            if ($auth->isLoggedIn() && !$auth->isCommissioner()) {
                http_response_code(403);
                echo 'Commissioner access required.';
                exit;
            }

            if (!$auth->isCommissioner()) {
                header('Location: ' . $routeUrl('/login') . '&redirect=' . rawurlencode('/comish'));
                exit;
            }

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

            $render('yahoo_connect', [
                'title'   => 'Yahoo Connected',
                'success' => true,
            ]);
            break;

        case '/rosters':
            $oauth = new YahooOAuthClient($config['yahoo']);
            $apiClient = new YahooApiClient($oauth);
            $rosterService = new RosterService($apiClient, $config['yahoo']['league_key']);
            $overview = $rosterService->getRosterOverview();
            $render('rosters', [
                'title'   => 'Rosters',
                'teams'   => $overview['teams'],
                'league'  => $overview['league'],
            ]);
            break;

        case '/history':
            $oauth = new YahooOAuthClient($config['yahoo']);
            $apiClient = new YahooApiClient($oauth);
            $historyService = new HistoricalStatsService($apiClient, $config['yahoo']['league_key'], 2018);
            $pointsService = new HistoricalPointsService($apiClient, $config['yahoo']['league_key'], 2018);
            $insightsService = new HistoricalInsightsService($apiClient, $config['yahoo']['league_key'], 2018, 10.0);
            $history = $historyService->getHistoricalStandings();
            $points = $pointsService->getHistoricalPoints();
            $insights = $insightsService->getInsights($history, $points);

            $render('historical', [
                'title'   => 'Historical Stats',
                'history' => $history,
                'points'  => $points,
                'insights' => $insights,
            ]);
            break;

        default:
            http_response_code(404);
            $render('home', [
                'title'      => '404 – Not Found',
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
