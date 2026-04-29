<section class="home-hero">
    <h1>Welcome to <?= htmlspecialchars($appName) ?></h1>
    <p>Your <?= (int) $leagueSize ?>-team fantasy football dynasty league.</p>
    <p>This app syncs league data from Yahoo Fantasy Football and adds your offline dynasty layer on top.</p>
    <div class="home-actions">
        <a class="btn btn--secondary" href="/index.php?r=history">Explore History</a>
        <a class="btn btn--secondary" href="/index.php?r=rosters">View Rosters</a>
        <a class="btn btn--secondary" href="/index.php?r=comish">Commissioner Tools</a>
    </div>
    <p class="home-note">League data sync and admin controls live in the Comish section.</p>
</section>
