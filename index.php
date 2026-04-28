<?php

declare(strict_types=1);

// Fallback entry point for hosting setups where document root is fixed to
// /httpdocs and cannot be changed to /httpdocs/public.

require __DIR__ . '/public/index.php';
