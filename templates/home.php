<section class="home-hero">
    <h1>Welcome to <?= htmlspecialchars($appName) ?></h1>
    <p>Your <?= (int) $leagueSize ?>-team fantasy football dynasty league.</p>
    <p>This app syncs league data from Yahoo Fantasy Football and adds your offline dynasty layer on top.</p>
    <div class="home-actions">
        <a class="btn btn--secondary" href="/index.php?r=history">Explore History</a>
        <a class="btn btn--secondary" href="/index.php?r=rosters">View Rosters</a>
        <?php if (!empty($isCommissioner)): ?>
            <a class="btn" href="/index.php?r=yahoo/connect">Connect Yahoo League</a>
        <?php endif; ?>
    </div>
    <?php if (!empty($isCommissioner)): ?>
        <p class="home-note">Commissioner mode is active. Yahoo sync controls are visible only in this session.</p>
    <?php endif; ?>
</section>
