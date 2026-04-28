<section class="home-hero">
    <h1>Welcome to <?= htmlspecialchars($appName) ?></h1>
    <p>Your <?= (int) $leagueSize ?>-team fantasy football dynasty league.</p>
    <p>This app syncs league data from Yahoo Fantasy Football and adds your offline dynasty layer on top.</p>
    <div class="home-actions">
        <a class="btn" href="/yahoo/connect">Connect Yahoo League</a>
    </div>
</section>
