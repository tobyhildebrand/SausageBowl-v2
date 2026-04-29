<section class="page-header">
    <div class="page-header__row">
        <h1>League Rosters</h1>
        <?php
            $statusLabel = strtoupper((string)($league['draft_status'] ?? 'unknown'));
            $statusClass = strtolower((string)($league['draft_status'] ?? 'unknown'));
        ?>
        <span class="league-status-badge league-status-badge--<?= htmlspecialchars($statusClass) ?>">
            <?= htmlspecialchars($statusLabel) ?>
        </span>
    </div>
</section>

<?php
    $draftStatus = strtolower((string)($league['draft_status'] ?? ''));
    $isPreDraft = $draftStatus === 'predraft';
    $usedFallback = (bool)($league['used_fallback'] ?? false);
?>

<?php if ($isPreDraft): ?>
    <div class="roster-notice">
        <?php if ($usedFallback): ?>
            Yahoo reports this league as pre-draft, so live roster data is empty.
            Showing carry-over rosters from the renewed previous season league instead.
        <?php else: ?>
            Yahoo currently reports this league as pre-draft, so roster player lists are still empty.
            They will populate automatically after the draft.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="roster-grid">
<?php foreach ($teams as $team): ?>
    <div class="roster-card">
        <div class="roster-card__header">
            <span class="roster-card__team"><?= htmlspecialchars($team['name']) ?></span>
            <?php if (!empty($team['managers'])): ?>
                <span class="roster-card__manager"><?= htmlspecialchars(implode(', ', $team['managers'])) ?></span>
            <?php endif; ?>
        </div>

        <?php
            $grouped = App\Services\RosterService::groupByPosition($team['players']);
        ?>

        <?php foreach ($grouped as $position => $players): ?>
            <div class="position-group">
                <div class="position-group__label position-group__label--<?= strtolower(htmlspecialchars($position)) ?>">
                    <?= htmlspecialchars($position) ?>
                </div>
                <ul class="player-list">
                    <?php foreach ($players as $player): ?>
                        <li class="player-list__item<?= $player['status'] !== '' ? ' player-list__item--injured' : '' ?>">
                            <span class="player-list__name"><?= htmlspecialchars($player['name']) ?></span>
                            <?php if ($player['nfl_team'] !== ''): ?>
                                <span class="player-list__nfl-team"><?= htmlspecialchars($player['nfl_team']) ?></span>
                            <?php endif; ?>
                            <?php if ($player['status'] !== ''): ?>
                                <span class="player-list__status"><?= htmlspecialchars($player['status']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <?php if (empty($team['players'])): ?>
            <p class="roster-card__empty">
                <?= $isPreDraft ? 'Rosters unlock after draft.' : 'No players on roster.' ?>
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
