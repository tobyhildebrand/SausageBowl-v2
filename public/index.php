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
use App\Services\DraftDeskService;
use App\Services\HistoricalInsightsService;
use App\Services\HistoricalPointsService;
use App\Services\HistoricalStatsService;
use App\Services\RosterService;

// Load app config (used for display values; DB connection is lazy via DB::get())
$config = require APP_ROOT . '/config/config.php';

// Session is needed for auth and Yahoo OAuth CSRF state parameter.
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
$draftDesk = new DraftDeskService();

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

        case '/draft-board':
            $seasonYear = (int) ($_GET['season'] ?? date('Y'));
            if ($seasonYear < 2020 || $seasonYear > 2100) {
                $seasonYear = (int) date('Y');
            }

            $upcoming = $draftDesk->getUpcomingSeasonData($seasonYear);
            $render('draft_board', [
                'title' => 'Draft Board',
                'seasonYear' => $seasonYear,
                'draftUpcoming' => $upcoming,
            ]);
            break;

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

            $seasonYear = (int) ($_GET['season'] ?? date('Y'));
            if ($seasonYear < 2020 || $seasonYear > 2100) {
                $seasonYear = (int) date('Y');
            }

            $wizardStep = (int) ($_GET['step'] ?? 1);
            if ($wizardStep < 1 || $wizardStep > 3) {
                $wizardStep = 1;
            }

            $draftError = null;
            $draftNotice = (string) ($_GET['notice'] ?? '');
            $leagueTeamNames = [];

            try {
                $oauth = new YahooOAuthClient($config['yahoo']);
                $apiClient = new YahooApiClient($oauth);
                $rosterService = new RosterService($apiClient, $config['yahoo']['league_key']);
                $overview = $rosterService->getRosterOverview();

                foreach ((array) ($overview['teams'] ?? []) as $team) {
                    $name = trim((string) ($team['name'] ?? ''));
                    if ($name !== '') {
                        $leagueTeamNames[] = $name;
                    }
                }
            } catch (Throwable $e) {
                // Fallback to saved default-order teams below.
            }

            $leagueTeamNames = array_values(array_unique($leagueTeamNames));

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $action = (string) ($_POST['action'] ?? '');
                $user = $auth->currentUser();
                $userId = (int) ($user['id'] ?? 0);

                try {
                    if ($action === 'save_upcoming_setup') {
                        $setupSeason = (int) ($_POST['season_year'] ?? $seasonYear);
                        $roundCount = (int) ($_POST['round_count'] ?? 8);
                        $teamNames = (array) ($_POST['team_name'] ?? []);
                        $slotNos = (array) ($_POST['slot_no'] ?? []);

                        $assignments = [];
                        $rowCount = min(count($teamNames), count($slotNos));
                        for ($i = 0; $i < $rowCount; $i++) {
                            $assignments[] = [
                                'team_name' => (string) ($teamNames[$i] ?? ''),
                                'slot_no' => (int) ($slotNos[$i] ?? 0),
                            ];
                        }

                        $draftDesk->saveUpcomingSetupByAssignments($setupSeason, $roundCount, $assignments);
                        header('Location: ' . $routeUrl('/comish') . '&season=' . rawurlencode((string) $setupSeason) . '&step=2&notice=setup_saved');
                        exit;
                    }

                    if ($action === 'save_pick_override') {
                        $overrideSeason = (int) ($_POST['season_year'] ?? $seasonYear);
                        $roundNo = (int) ($_POST['round_no'] ?? 0);
                        $fromTeamName = (string) ($_POST['from_team_name'] ?? '');
                        $owner = (string) ($_POST['current_owner_name'] ?? '');
                        $note = (string) ($_POST['note'] ?? '');
                        $draftDesk->upsertPickOverrideByFromTeam($overrideSeason, $roundNo, $fromTeamName, $owner, $note, $userId);
                        header('Location: ' . $routeUrl('/comish') . '&season=' . rawurlencode((string) $overrideSeason) . '&step=2&notice=override_saved');
                        exit;
                    }

                    if ($action === 'delete_pick_override') {
                        $overrideSeason = (int) ($_POST['season_year'] ?? $seasonYear);
                        $roundNo = (int) ($_POST['round_no'] ?? 0);
                        $fromTeamName = (string) ($_POST['from_team_name'] ?? '');
                        $draftDesk->removePickOverrideByFromTeam($overrideSeason, $roundNo, $fromTeamName);
                        header('Location: ' . $routeUrl('/comish') . '&season=' . rawurlencode((string) $overrideSeason) . '&step=2&notice=override_deleted');
                        exit;
                    }

                    if ($action === 'add_future_trade') {
                        $tradeSeason = (int) ($_POST['season_year'] ?? $seasonYear);
                        $roundRaw = trim((string) ($_POST['round_no'] ?? ''));
                        $roundNo = $roundRaw === '' ? null : (int) $roundRaw;
                        $fromTeam = (string) ($_POST['from_team_name'] ?? '');
                        $owner = (string) ($_POST['current_owner_name'] ?? '');
                        $note = (string) ($_POST['note'] ?? '');
                        $draftDesk->addFutureTrade($tradeSeason, $roundNo, $fromTeam, $owner, $note, $userId);
                        header('Location: ' . $routeUrl('/comish') . '&season=' . rawurlencode((string) $seasonYear) . '&step=' . rawurlencode((string) $wizardStep) . '&notice=future_trade_added');
                        exit;
                    }
                } catch (Throwable $e) {
                    $draftError = $e->getMessage();
                }
            }

            $upcoming = $draftDesk->getUpcomingSeasonData($seasonYear);

            if ($leagueTeamNames === []) {
                foreach ((array) ($upcoming['default_order'] ?? []) as $name) {
                    $teamName = trim((string) $name);
                    if ($teamName !== '') {
                        $leagueTeamNames[] = $teamName;
                    }
                }
                $leagueTeamNames = array_values(array_unique($leagueTeamNames));
            }

            $futureTrades = $draftDesk->listFutureTrades($seasonYear + 1);

            $render('comish', [
                'title'   => 'Comish',
                'seasonYear' => $seasonYear,
                'draftUpcoming' => $upcoming,
                'futureTrades' => $futureTrades,
                'draftError' => $draftError,
                'draftNotice' => $draftNotice,
                'wizardStep' => $wizardStep,
                'leagueTeamNames' => $leagueTeamNames,
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
