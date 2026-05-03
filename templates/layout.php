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
$isTradesActive = $currentRoute === '/trades';
$isDraftBoardActive = $currentRoute === '/draft-board';
$isComishActive = $currentRoute === '/comish';
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
                <a href="/index.php?r=history" class="<?= $isHistoryActive ? 'is-active' : '' ?>" aria-current="<?= $isHistoryActive ? 'page' : 'false' ?>">League History</a>
                <a href="/index.php?r=trades" class="<?= $isTradesActive ? 'is-active' : '' ?>" aria-current="<?= $isTradesActive ? 'page' : 'false' ?>">Trades</a>
                <a href="/index.php?r=draft-board" class="<?= $isDraftBoardActive ? 'is-active' : '' ?>" aria-current="<?= $isDraftBoardActive ? 'page' : 'false' ?>">Draft</a>
                <a href="/index.php?r=comish" class="<?= $isComishActive ? 'is-active' : '' ?>" aria-current="<?= $isComishActive ? 'page' : 'false' ?>">Comish</a>
            </nav>

            <div class="site-account">
                <?php if (!empty($currentUser)): ?>
                    <span class="site-account__user">
                        <?= htmlspecialchars((string) ($currentUser['display_name'] ?? $currentUser['email'] ?? 'User')) ?>
                    </span>
                    <form method="post" action="/index.php?r=logout" class="site-account__form">
                        <button type="submit" class="site-account__button">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="/index.php?r=login" class="site-account__link">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="site-main">
        <?= $content /* already-rendered, trusted HTML from templates */ ?>
    </main>

    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> Now it goes around the Sausage</p>
        <p class="site-footer__attribution">League data sourced from <a href="https://sports.yahoo.com/fantasy/" target="_blank" rel="noopener noreferrer">Yahoo Fantasy Sports</a>.</p>
    </footer>
</body>
</html>
