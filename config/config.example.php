<?php
// Copy this file to config.php and fill in your values.
// config.php is gitignored and must never be committed.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'dynasty_league',
        'user'    => 'db_user',
        'pass'    => 'secret',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'         => 'NowItGoesAroundTheSausage',
        'league_size'  => 12,   // number of teams in the league
        'debug'        => false, // set true only in local development
    ],

    // Yahoo Fantasy Sports API – OAuth 2.0 credentials.
    // Register your app at https://developer.yahoo.com/apps/ with scope fspt-r (read).
    // The redirect_uri must exactly match what you registered there.
    'yahoo' => [
        'client_id'     => 'your_client_id_here',
        'client_secret' => 'your_client_secret_here',
        'redirect_uri'  => 'https://sausagebowl.ch/yahoo/callback',
        // Yahoo game key for NFL (stays constant per season year; update each season).
        'game_key'      => 'nfl',
        // Your specific league key, e.g. "449.l.12345" – find it in your Yahoo league URL.
        'league_key'    => '449.l.your_league_id',
    ],
];
