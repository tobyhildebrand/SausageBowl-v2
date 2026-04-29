<!DOCTYPE html>
<?php
$routeFromQuery = isset($_GET['r']) ? (string) $_GET['r'] : '';

if ($routeFromQuery !== '') {
    $currentRoute = '/' . trim($routeFromQuery, '/');
} else {
    $currentRoute = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $currentRoute = '/' . trim((string) $currentRoute, '/');

    if ($currentRoute === '/index.php') {
        $currentRoute = '/';
    }
}

$isHomeActive = $currentRoute === '/';
$isRostersActive = $currentRoute === '/rosters';
$isHistoryActive = $currentRoute === '/history';
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Dynasty League') ?> — <?= htmlspecialchars($appName ?? 'Dynasty League') ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="/public/assets/images/league-logo.png">
    <link rel="shortcut icon" href="/public/assets/images/league-logo.png">
    <link rel="apple-touch-icon" href="/public/assets/images/league-logo.png">
    <link rel="stylesheet" href="/public/assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <div class="site-header__inner">
            <a class="site-brand" href="/index.php" aria-label="Go to home">
                <img
                    class="site-brand__logo"
                    src="/public/assets/images/league-logo.png"
                    alt="League logo"
                >
                <span class="site-brand__text">
                    <strong class="site-title"><?= htmlspecialchars($appName ?? 'Dynasty League') ?></strong>
                    <span class="site-subtitle">Dynasty League Control Center</span>
                </span>
            </a>

            <nav class="site-nav" aria-label="Main navigation">
                <a href="/index.php" class="<?= $isHomeActive ? 'is-active' : '' ?>" aria-current="<?= $isHomeActive ? 'page' : 'false' ?>">Home</a>
                <a href="/index.php?r=rosters" class="<?= $isRostersActive ? 'is-active' : '' ?>" aria-current="<?= $isRostersActive ? 'page' : 'false' ?>">Rosters</a>
                <a href="/index.php?r=history" class="<?= $isHistoryActive ? 'is-active' : '' ?>" aria-current="<?= $isHistoryActive ? 'page' : 'false' ?>">History</a>
            </nav>
        </div>
    </header>

    <main class="site-main">
        <?= $content /* already-rendered, trusted HTML from templates */ ?>
    </main>

    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> Now it goes around the Sausage</p>
    </footer>
</body>
</html>
