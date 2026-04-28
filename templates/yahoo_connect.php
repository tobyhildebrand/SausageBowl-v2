<?php if ($success ?? false): ?>
<section class="card">
    <h1>Yahoo Fantasy Sports connected</h1>
    <p>The app is now authorised to read your league data. Tokens are stored securely in the database and will be refreshed automatically.</p>
    <p><a href="/">Back to Home</a></p>
</section>
<?php else: ?>
<section class="card">
    <h1>Connect to Yahoo Fantasy Sports</h1>
    <p>Click the button below to authorise this app to read your Yahoo Fantasy Football league. You will be redirected to Yahoo and back.</p>
    <p><a class="btn" href="/yahoo/connect">Connect with Yahoo</a></p>
</section>
<?php endif; ?>
