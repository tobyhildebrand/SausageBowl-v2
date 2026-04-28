<?php
// This file documents every environment variable the app requires.
// Do NOT put real values here – this file is committed to source control.
//
// How to set env vars in Plesk:
//   Domains → sausagebowl.ch → Apache & nginx Settings
//   → "Additional directives for HTTP" and "... for HTTPS"
//   Add one line per variable, e.g.:
//     SetEnv DB_NAME SausageBowl
//
// For local Apache vhost development, add the same SetEnv lines there.
// ---------------------------------------------------------------------------
//
// Required variables (app will throw a RuntimeException if any are missing):
//
//   DB_NAME              database name, e.g. SausageBowl
//   DB_USER              database username
//   DB_PASS              database password
//   YAHOO_CLIENT_ID      from https://developer.yahoo.com/apps/
//   YAHOO_CLIENT_SECRET  from https://developer.yahoo.com/apps/
//   YAHOO_REDIRECT_URI   https://sausagebowl.ch/yahoo/callback
//   YAHOO_LEAGUE_KEY     e.g. 449.l.12345 – visible in your Yahoo league URL
//
// Optional variables (sensible defaults apply):
//
//   DB_HOST              default: localhost
//   DB_PORT              default: 3306
//   APP_DEBUG            true|false  default: false
//   YAHOO_GAME_KEY       default: nfl

