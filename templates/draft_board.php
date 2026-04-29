<?php
$seasonYear = (int) ($seasonYear ?? (int) date('Y'));
$upcomingSeasonYear = isset($upcomingSeasonYear) && $upcomingSeasonYear !== null ? (int) $upcomingSeasonYear : null;
$viewMode = (string) ($viewMode ?? 'none');
$draftUpcoming = is_array($draftUpcoming ?? null) ? $draftUpcoming : [];
$futureTrades = is_array($futureTrades ?? null) ? $futureTrades : [];
$historicalDraft = is_array($historicalDraft ?? null) ? $historicalDraft : [];
$boardRows = is_array($draftUpcoming['board_rows'] ?? null) ? $draftUpcoming['board_rows'] : [];
$roundCount = (int) ($draftUpcoming['round_count'] ?? 8);
$roundRange = range(1, max(1, $roundCount));
$histBoardRows = is_array($historicalDraft['board_rows'] ?? null) ? $historicalDraft['board_rows'] : [];
$histRoundCount = (int) ($historicalDraft['round_count'] ?? 0);
$histRoundRange = $histRoundCount > 0 ? range(1, $histRoundCount) : [];
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
            <input class="auth-field__input" type="number" name="season" min="2018" max="2100" value="<?= (int) $seasonYear ?>">
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
                            <?php $slotNo = (int) ($row['slot_no'] ?? 0); ?>
                            <td><?= $slotNo ?></td>
                            <?php $defaultTeam = (string) ($row['default_team'] ?? ''); ?>
                            <td><span class="draft-cell-owner"><?= htmlspecialchars($defaultTeam) ?></span></td>
                            <?php foreach ($roundRange as $round): ?>
                                <?php $cell = $row['rounds'][$round] ?? null; ?>
                                <?php $isChanged = !empty($cell['is_changed']); ?>
                                <?php $tradeNote = trim((string) ($cell['note'] ?? '')); ?>
                                <?php $tradeTitle = $tradeNote !== '' ? $tradeNote : 'Traded pick'; ?>
                                <?php $draftedPlayer = trim((string) ($cell['drafted_player'] ?? '')); ?>
                                <td class="<?= $isChanged ? 'draft-cell--changed' : 'draft-cell--default' ?>"<?= $isChanged ? ' title="' . htmlspecialchars($tradeTitle) . '"' : '' ?>>
                                    <?php if ($isChanged): ?>
                                        <?php $owner = (string) ($cell['owner'] ?? ''); ?>
                                        <span class="draft-cell-owner"><?= htmlspecialchars($owner) ?></span>
                                    <?php else: ?>
                                        <span class="draft-pick-code"><?= htmlspecialchars(sprintf('%d.%02d', (int) $round, $slotNo)) ?></span>
                                    <?php endif; ?>
                                    <?php if ($draftedPlayer !== ''): ?>
                                        <span class="draft-picked-player"><?= htmlspecialchars($draftedPlayer) ?></span>
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
        <?php elseif ($viewMode === 'historical'): ?>
            <table class="history-table draft-board-table draft-board-table--matrix">
                <thead>
                <tr>
                    <th>Slot</th>
                    <th>Team</th>
                    <?php foreach ($histRoundRange as $round): ?>
                        <th>Round #<?= (int) $round ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($histBoardRows as $row): ?>
                    <tr>
                        <?php $slotNo = (int) ($row['slot_no'] ?? 0); ?>
                        <?php $defaultTeam = (string) ($row['default_team'] ?? ''); ?>
                        <td><?= $slotNo ?></td>
                        <td><span class="draft-cell-owner"><?= htmlspecialchars($defaultTeam) ?></span></td>
                        <?php foreach ($histRoundRange as $round): ?>
                            <?php $cell = $row['rounds'][$round] ?? null; ?>
                            <?php $player = trim((string) ($cell['drafted_player'] ?? '')); ?>
                            <?php $isTraded = !empty($cell['is_traded']); ?>
                            <?php $owner = trim((string) ($cell['owner'] ?? '')); ?>
                            <?php $fromTeam = trim((string) ($cell['from_team'] ?? '')); ?>
                            <?php $note = trim((string) ($cell['note'] ?? '')); ?>
                            <?php $tooltip = $isTraded ? ($note !== '' ? $note : "Traded from {$fromTeam}") : ''; ?>
                            <td class="<?= $isTraded ? 'draft-cell--changed' : 'draft-cell--default' ?>"<?= $tooltip !== '' ? ' title="' . htmlspecialchars($tooltip) . '"' : '' ?>>
                                <?php if ($isTraded && $owner !== ''): ?>
                                    <span class="draft-cell-owner"><?= htmlspecialchars($owner) ?></span>
                                <?php endif; ?>
                                <?php if ($player !== ''): ?>
                                    <span class="draft-picked-player"><?= htmlspecialchars($player) ?></span>
                                <?php else: ?>
                                    <span class="draft-pick-code"><?= htmlspecialchars(sprintf('%d.%02d', (int) $round, $slotNo)) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <?php if ($upcomingSeasonYear === null): ?>
                <p class="home-note">No upcoming draft season is configured yet.</p>
            <?php else: ?>
                <p class="home-note">No draft data found for <?= (int) $seasonYear ?>. Select season <?= (int) $upcomingSeasonYear ?> for the upcoming board, a later season for future pick trades, or a past season with imported picks.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
