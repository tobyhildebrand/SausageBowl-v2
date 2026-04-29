<?php
$seasonYear = (int) ($seasonYear ?? (int) date('Y'));
$upcomingSeasonYear = isset($upcomingSeasonYear) && $upcomingSeasonYear !== null ? (int) $upcomingSeasonYear : null;
$viewMode = (string) ($viewMode ?? 'none');
$draftUpcoming = is_array($draftUpcoming ?? null) ? $draftUpcoming : [];
$futureTrades = is_array($futureTrades ?? null) ? $futureTrades : [];
$boardRows = is_array($draftUpcoming['board_rows'] ?? null) ? $draftUpcoming['board_rows'] : [];
$roundCount = (int) ($draftUpcoming['round_count'] ?? 8);
$roundRange = range(1, max(1, $roundCount));
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Draft Board</h1>
        <?php if ($upcomingSeasonYear !== null): ?>
            <span class="league-status-badge">Upcoming season: <?= (int) $upcomingSeasonYear ?></span>
        <?php endif; ?>
    </div>
    <?php if ($upcomingSeasonYear !== null): ?>
        <p>Read-only draft data. The board is shown only for the upcoming season (<?= (int) $upcomingSeasonYear ?>).</p>
    <?php else: ?>
        <p>Read-only draft data.</p>
    <?php endif; ?>
</section>

<section class="card">
    <form method="get" action="/index.php" class="draft-inline-form">
        <input type="hidden" name="r" value="draft-board">
        <label class="auth-field">
            <span class="auth-field__label">Season</span>
            <input class="auth-field__input" type="number" name="season" min="2020" max="2100" value="<?= (int) $seasonYear ?>">
        </label>
        <div class="auth-actions">
            <button type="submit" class="btn btn--secondary">Switch Season</button>
        </div>
    </form>

    <div class="draft-board-wrap card--spaced">
        <?php if ($viewMode === 'board'): ?>
            <?php if ($boardRows === []): ?>
                <p class="home-note">No draft board published for <?= (int) $seasonYear ?> yet.</p>
            <?php else: ?>
                <table class="history-table draft-board-table draft-board-table--matrix">
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
                            <?php $defaultTeam = (string) ($row['default_team'] ?? ''); ?>
                            <td><span class="draft-cell-owner" title="<?= htmlspecialchars($defaultTeam) ?>"><?= htmlspecialchars($defaultTeam) ?></span></td>
                            <?php foreach ($roundRange as $round): ?>
                                <?php $cell = $row['rounds'][$round] ?? null; ?>
                                <?php $isChanged = !empty($cell['is_changed']); ?>
                                <?php $tradeNote = trim((string) ($cell['note'] ?? '')); ?>
                                <?php $tradeTitle = $tradeNote !== '' ? $tradeNote : 'Traded pick'; ?>
                                <td class="<?= $isChanged ? 'draft-cell--changed' : 'draft-cell--default' ?>"<?= $isChanged ? ' title="' . htmlspecialchars($tradeTitle) . '"' : '' ?>>
                                    <?php $owner = (string) ($cell['owner'] ?? ''); ?>
                                    <span class="draft-cell-owner" title="<?= htmlspecialchars($owner) ?>"><?= htmlspecialchars($owner) ?></span>
                                    <?php if ($isChanged): ?>
                                        <span class="insight-sub draft-trade-flag" title="<?= htmlspecialchars($tradeTitle) ?>">(traded)</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif ($viewMode === 'future_trades'): ?>
            <?php if ($futureTrades === []): ?>
                <p class="home-note">No future draft pick trades logged for <?= (int) $seasonYear ?> or later.</p>
            <?php else: ?>
                <table class="history-table draft-board-table">
                    <thead>
                    <tr>
                        <th>Season</th>
                        <th>Round</th>
                        <th>Pick Originally Owned By</th>
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
        <?php else: ?>
            <?php if ($upcomingSeasonYear === null): ?>
                <p class="home-note">No upcoming draft season is configured yet.</p>
            <?php else: ?>
                <p class="home-note">Draft board is only available for upcoming season <?= (int) $upcomingSeasonYear ?>. Select that season to view the board, or choose a later season to view future pick trades.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
