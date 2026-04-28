<?php
// secrets.php – gitignored, never committed.
//
// Use this file when your hosting environment does not support setting
// environment variables via server config (e.g. Plesk Apache directives).
//
// How it works: putenv() sets a process-level environment variable.
// config.php reads everything via getenv(), so both this file and real
// server-level env vars (SetEnv in Apache) work transparently.
// If a variable is already set by the server, putenv() here has no effect.
//
// Copy this template, fill in your values, and upload it to the server.
// Do NOT commit this file to git.

putenv('DB_NAME=SausageBowl');
putenv('DB_USER=your_db_username');
putenv('DB_PASS=your_db_password');

putenv('YAHOO_CLIENT_ID=your_yahoo_client_id');
putenv('YAHOO_CLIENT_SECRET=your_yahoo_client_secret');
putenv('YAHOO_REDIRECT_URI=https://sausagebowl.ch/yahoo/callback');
putenv('YAHOO_LEAGUE_KEY=449.l.your_league_id');

// Optional – uncomment to override defaults:
// putenv('DB_HOST=localhost');
// putenv('DB_PORT=3306');
// putenv('YAHOO_GAME_KEY=nfl');
// putenv('APP_DEBUG=false');
