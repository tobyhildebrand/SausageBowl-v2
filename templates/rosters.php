<section class="page-header">
    <h1>League Rosters</h1>
</section>

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
            <p class="roster-card__empty">No players on roster.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
