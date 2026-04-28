<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Dynasty League') ?> — <?= htmlspecialchars($appName ?? 'Dynasty League') ?></title>
    <link rel="stylesheet" href="/public/assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <a class="site-title" href="/index.php"><?= htmlspecialchars($appName ?? 'Dynasty League') ?></a>
        <nav class="site-nav">
            <a href="/index.php">Home</a>
        </nav>
    </header>

    <main class="site-main">
        <?= $content /* already-rendered, trusted HTML from templates */ ?>
    </main>

    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> Now it goes around the Sausage</p>
    </footer>
</body>
</html>
