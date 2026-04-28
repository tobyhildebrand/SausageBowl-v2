<?php

declare(strict_types=1);

// Fallback entry point for hosting setups where document root is fixed to
// /httpdocs and cannot be changed to /httpdocs/public.

// TEMPORARY DEBUG – remove after error is identified.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/public/index.php';
