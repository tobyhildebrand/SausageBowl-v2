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

    // Build position-aligned groups so each position section has identical
    // height across all team cards for easier cross-team comparison.
    $positionOrder = App\Services\RosterService::POSITION_ORDER;
    $teamGroups = [];
    $maxByPosition = [];
    $extraPositions = [];

    foreach ($teams as $teamIndex => $team) {
        $grouped = App\Services\RosterService::groupByPosition($team['players']);
        $teamGroups[$teamIndex] = $grouped;

        foreach ($grouped as $position => $playersAtPosition) {
            if (!in_array($position, $positionOrder, true) && !in_array($position, $extraPositions, true)) {
                $extraPositions[] = $position;
            }

            $count = count($playersAtPosition);
            $maxByPosition[$position] = max((int) ($maxByPosition[$position] ?? 0), $count);
        }
    }

    $displayPositions = [];
    foreach ($positionOrder as $position) {
        if ((int) ($maxByPosition[$position] ?? 0) > 0) {
            $displayPositions[] = $position;
        }
    }

    foreach ($extraPositions as $position) {
        if ((int) ($maxByPosition[$position] ?? 0) > 0) {
            $displayPositions[] = $position;
        }
    }
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
        <br>
        Source league: <?= htmlspecialchars((string) ($league['league_key'] ?? '')) ?>
        (season <?= htmlspecialchars((string) ($league['season'] ?? '')) ?>),
        configured: <?= htmlspecialchars((string) ($league['configured_league_key'] ?? '')) ?>
        (season <?= htmlspecialchars((string) ($league['configured_season'] ?? '')) ?>)
    </div>
<?php endif; ?>

<div class="roster-grid">
<?php foreach ($teams as $teamIndex => $team): ?>
    <div class="roster-card">
        <div class="roster-card__header">
            <div class="roster-card__title-row">
                <?php if (!empty($team['logo_url'])): ?>
                    <img
                        class="roster-card__team-logo"
                        src="<?= htmlspecialchars($team['logo_url']) ?>"
                        alt="<?= htmlspecialchars($team['name']) ?> logo"
                        loading="lazy"
                        decoding="async"
                    >
                <?php endif; ?>
                <span class="roster-card__team"><?= htmlspecialchars($team['name']) ?></span>
            </div>
        </div>

        <?php foreach ($displayPositions as $position): ?>
            <?php
                $players = $teamGroups[$teamIndex][$position] ?? [];
                $maxSlotsForPosition = (int) ($maxByPosition[$position] ?? count($players));
                $placeholderCount = max(0, $maxSlotsForPosition - count($players));
            ?>
            <div class="position-group">
                <div class="position-group__label position-group__label--<?= strtolower(htmlspecialchars($position)) ?>">
                    <?= htmlspecialchars($position) ?>
                </div>
                <ul class="player-list">
                    <?php foreach ($players as $player): ?>
                        <li class="player-list__item<?= $player['status'] !== '' ? ' player-list__item--injured' : '' ?>">
                            <span class="player-list__name"><?= htmlspecialchars($player['name']) ?></span>
                            <?php if ($player['status'] !== ''): ?>
                                <span class="player-list__status"><?= htmlspecialchars($player['status']) ?></span>
                            <?php endif; ?>
                            <?php if ($player['nfl_team'] !== ''): ?>
                                <span class="player-list__nfl-team"><?= htmlspecialchars($player['nfl_team']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>

                    <?php for ($i = 0; $i < $placeholderCount; $i++): ?>
                        <li class="player-list__item player-list__item--placeholder" aria-hidden="true">
                            <span class="player-list__name">&nbsp;</span>
                        </li>
                    <?php endfor; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <?php if (empty($team['players']) && empty($displayPositions)): ?>
            <p class="roster-card__empty">
                <?= $isPreDraft ? 'Rosters unlock after draft.' : 'No players on roster.' ?>
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
