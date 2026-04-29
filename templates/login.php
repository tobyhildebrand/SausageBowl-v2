<section class="page-header">
    <div class="page-header__row">
        <h1><?= !empty($hasUsers) ? 'Commissioner Login' : 'Set Up Commissioner Access' ?></h1>
    </div>
    <p>
        <?= !empty($hasUsers)
            ? 'Sign in with your database-backed account to access the Comish section.'
            : 'No users exist yet. Create the first commissioner account to lock down the admin area.' ?>
    </p>
</section>

<section class="card auth-card">
    <?php if (!empty($error)): ?>
        <div class="auth-alert auth-alert--error"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>

    <form method="post" action="/index.php?r=login" class="auth-form">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars((string) ($redirectRoute ?? '/comish')) ?>">

        <?php if (empty($hasUsers)): ?>
            <input type="hidden" name="action" value="bootstrap">

            <label class="auth-field">
                <span class="auth-field__label">Display Name</span>
                <input class="auth-field__input" type="text" name="display_name" required maxlength="100" autocomplete="name">
            </label>
        <?php else: ?>
            <input type="hidden" name="action" value="login">
        <?php endif; ?>

        <label class="auth-field">
            <span class="auth-field__label">Email</span>
            <input class="auth-field__input" type="email" name="email" required maxlength="190" autocomplete="email">
        </label>

        <label class="auth-field">
            <span class="auth-field__label">Password</span>
            <input class="auth-field__input" type="password" name="password" required minlength="10" autocomplete="current-password">
        </label>

        <?php if (empty($hasUsers)): ?>
            <label class="auth-field">
                <span class="auth-field__label">Confirm Password</span>
                <input class="auth-field__input" type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
            </label>
        <?php endif; ?>

        <div class="auth-actions">
            <button type="submit" class="btn"><?= !empty($hasUsers) ? 'Login' : 'Create Commissioner Account' ?></button>
            <a class="btn btn--secondary" href="/index.php">Back Home</a>
        </div>
    </form>
</section>