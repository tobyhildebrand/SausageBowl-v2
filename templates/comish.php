<?php
$seasonYear = (int) ($seasonYear ?? (int) date('Y'));
$draftUpcoming = is_array($draftUpcoming ?? null) ? $draftUpcoming : [];
$futureTrades = is_array($futureTrades ?? null) ? $futureTrades : [];
$defaultOrderText = (string) ($defaultOrderText ?? '');
$draftError = (string) ($draftError ?? '');
$draftNotice = (string) ($draftNotice ?? '');

$roundCount = (int) ($draftUpcoming['round_count'] ?? 8);
$boardRows = is_array($draftUpcoming['board_rows'] ?? null) ? $draftUpcoming['board_rows'] : [];
$roundRange = range(1, max(1, $roundCount));

$noticeMap = [
    'setup_saved' => 'Upcoming draft setup saved.',
    'override_saved' => 'Pick-owner override saved.',
    'override_deleted' => 'Pick-owner override removed.',
    'future_trade_added' => 'Future pick trade added to ledger.',
];
$noticeText = $noticeMap[$draftNotice] ?? '';
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Comish Draft Desk</h1>
    </div>
    <p>Commissioner-only write access. Configure the upcoming draft board and track traded picks in future seasons.</p>
</section>

<?php if ($noticeText !== ''): ?>
    <section class="card draft-alert draft-alert--ok">
        <p><?= htmlspecialchars($noticeText) ?></p>
    </section>
<?php endif; ?>

<?php if ($draftError !== ''): ?>
    <section class="card draft-alert draft-alert--error">
        <p><?= htmlspecialchars($draftError) ?></p>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Yahoo Sync</h2>
    <p>Use this when you need to reconnect or refresh the league integration with Yahoo Fantasy Sports.</p>
    <p>
        <a class="btn" href="/index.php?r=yahoo/connect">Connect Yahoo League</a>
    </p>
</section>

<section class="card card--spaced">
    <h2>Upcoming Draft Board (<?= (int) $seasonYear ?>)</h2>
    <p>Set the default order once, then only override the slots that changed ownership due to trades.</p>

    <form method="get" action="/index.php" class="draft-inline-form">
        <input type="hidden" name="r" value="comish">
        <label class="auth-field">
            <span class="auth-field__label">Season</span>
            <input class="auth-field__input" type="number" name="season" min="2020" max="2100" value="<?= (int) $seasonYear ?>">
        </label>
        <div class="auth-actions">
            <button type="submit" class="btn btn--secondary">Switch Season</button>
        </div>
    </form>

    <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>" class="auth-form card--spaced">
        <input type="hidden" name="action" value="save_upcoming_setup">
        <input type="hidden" name="season_year" value="<?= (int) $seasonYear ?>">

        <div class="draft-setup-grid">
            <label class="auth-field">
                <span class="auth-field__label">Rounds</span>
                <input class="auth-field__input" type="number" name="round_count" min="1" max="20" value="<?= (int) $roundCount ?>" required>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Default Draft Order (one team per line)</span>
                <textarea class="auth-field__input draft-order-text" name="default_order_text" rows="8" required><?= htmlspecialchars($defaultOrderText) ?></textarea>
            </label>
        </div>

        <div class="auth-actions">
            <button type="submit" class="btn">Save Upcoming Setup</button>
        </div>
    </form>

    <div class="draft-board-wrap card--spaced">
        <?php if ($boardRows === []): ?>
            <p class="home-note">No default order configured for <?= (int) $seasonYear ?> yet. Save setup above to render the board.</p>
        <?php else: ?>
            <table class="history-table draft-board-table">
                <thead>
                <tr>
                    <th>Slot</th>
                    <th>Default Team</th>
                    <?php foreach ($roundRange as $round): ?>
                        <th>Round #<?= (int) $round ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($boardRows as $row): ?>
                    <tr>
                        <td><?= (int) ($row['slot_no'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string) ($row['default_team'] ?? '')) ?></td>
                        <?php foreach ($roundRange as $round): ?>
                            <?php $cell = $row['rounds'][$round] ?? null; ?>
                            <td class="<?= !empty($cell['is_changed']) ? 'draft-cell--changed' : 'draft-cell--default' ?>">
                                <?= htmlspecialchars((string) ($cell['owner'] ?? '')) ?>
                                <?php if (!empty($cell['is_changed']) && !empty($cell['note'])): ?>
                                    <span class="insight-sub" title="<?= htmlspecialchars((string) $cell['note']) ?>">(trade)</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>" class="auth-form card--spaced">
        <input type="hidden" name="action" value="save_pick_override">
        <input type="hidden" name="season_year" value="<?= (int) $seasonYear ?>">

        <h3>Set Pick-Owner Override</h3>

        <div class="draft-form-grid">
            <label class="auth-field">
                <span class="auth-field__label">Round</span>
                <input class="auth-field__input" type="number" name="round_no" min="1" max="20" required>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Slot</span>
                <input class="auth-field__input" type="number" name="slot_no" min="1" max="50" required>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Current Owner Team</span>
                <input class="auth-field__input" type="text" name="current_owner_name" maxlength="120" required>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Note (optional)</span>
                <input class="auth-field__input" type="text" name="note" maxlength="255" placeholder="Trade details / reference">
            </label>
        </div>

        <div class="auth-actions">
            <button type="submit" class="btn">Save Override</button>
        </div>
    </form>

    <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>" class="auth-form card--spaced">
        <input type="hidden" name="action" value="delete_pick_override">
        <input type="hidden" name="season_year" value="<?= (int) $seasonYear ?>">

        <h3>Remove Override</h3>

        <div class="draft-form-grid draft-form-grid--compact">
            <label class="auth-field">
                <span class="auth-field__label">Round</span>
                <input class="auth-field__input" type="number" name="round_no" min="1" max="20" required>
            </label>
            <label class="auth-field">
                <span class="auth-field__label">Slot</span>
                <input class="auth-field__input" type="number" name="slot_no" min="1" max="50" required>
            </label>
        </div>

        <div class="auth-actions">
            <button type="submit" class="btn btn--secondary">Delete Override</button>
        </div>
    </form>
</section>

<section class="card card--spaced">
    <h2>Future Draft Pick Trades (<?= (int) ($seasonYear + 1) ?>+)</h2>
    <p>For seasons where default order is unknown, track only traded picks in this ledger.</p>

    <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>" class="auth-form">
        <input type="hidden" name="action" value="add_future_trade">

        <div class="draft-form-grid">
            <label class="auth-field">
                <span class="auth-field__label">Season</span>
                <input class="auth-field__input" type="number" name="season_year" min="2020" max="2100" required value="<?= (int) ($seasonYear + 1) ?>">
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Round (optional)</span>
                <input class="auth-field__input" type="number" name="round_no" min="1" max="20" placeholder="Unknown yet">
            </label>

            <label class="auth-field">
                <span class="auth-field__label">From Team</span>
                <input class="auth-field__input" type="text" name="from_team_name" maxlength="120" required>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Current Owner</span>
                <input class="auth-field__input" type="text" name="current_owner_name" maxlength="120" required>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Note (optional)</span>
                <input class="auth-field__input" type="text" name="note" maxlength="255" placeholder="Trade context">
            </label>
        </div>

        <div class="auth-actions">
            <button type="submit" class="btn">Add Future Pick Trade</button>
        </div>
    </form>

    <div class="draft-board-wrap card--spaced">
        <?php if ($futureTrades === []): ?>
            <p class="home-note">No future trades logged yet.</p>
        <?php else: ?>
            <table class="history-table draft-board-table">
                <thead>
                <tr>
                    <th>Season</th>
                    <th>Round</th>
                    <th>From Team</th>
                    <th>Current Owner</th>
                    <th>Note</th>
                    <th>Logged At</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($futureTrades as $trade): ?>
                    <tr>
                        <td><?= (int) ($trade['season_year'] ?? 0) ?></td>
                        <td>
                            <?php if (isset($trade['round_no']) && $trade['round_no'] !== null): ?>
                                #<?= (int) $trade['round_no'] ?>
                            <?php else: ?>
                                <span class="history-place__empty">TBD</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($trade['from_team_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($trade['current_owner_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($trade['note'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($trade['created_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
