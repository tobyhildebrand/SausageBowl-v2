<?php
/** @var int[] $seasons */
/** @var array<int, array{id: string, team_name: string, logo_url: string}> $teams */
/** @var array<string, array<string, array{w: int, l: int, d: int, games: int}>> $matrix */
/** @var int $totalGames */
/** @var array<int, string> $errors */

$seasons = is_array($seasons ?? null) ? $seasons : [];
$teams = is_array($teams ?? null) ? $teams : [];
$matrix = is_array($matrix ?? null) ? $matrix : [];
$errors = is_array($errors ?? null) ? $errors : [];
$totalGames = (int) ($totalGames ?? 0);

$shortName = static function (string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) {
        $first = (string) ($parts[0] ?? '');
        $second = (string) ($parts[1] ?? '');
        $candidate = trim($first . ' ' . $second);
    } else {
        $candidate = $name;
    }

    if (strlen($candidate) > 14) {
        return substr($candidate, 0, 13) . '...';
    }

    return $candidate;
};

$seasonLabel = 'No seasons loaded';
if ($seasons !== []) {
    $minSeason = (int) min($seasons);
    $maxSeason = (int) max($seasons);
    $seasonLabel = $minSeason === $maxSeason
        ? (string) $minSeason
        : ($minSeason . '-' . $maxSeason);
}
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Historic Head-to-Head</h1>
        <span class="page-header__meta"><?= $totalGames ?> matchup<?= $totalGames !== 1 ? 's' : '' ?> in sample</span>
    </div>
</section>

<p class="history-subtitle">
    Matrix view of all-time regular-season records between every pair of teams (W-L-D), aggregated from weekly Yahoo scoreboards.
</p>

<?php if ($errors !== []): ?>
    <div class="trade-notice trade-notice--warning">
        Could not load matchup data for:
        <?php foreach ($errors as $errSeason => $errMsg): ?>
            <strong><?= (int) $errSeason ?></strong> (<?= htmlspecialchars($errMsg) ?>)
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="history-state history-state--info">
    <strong>How to read:</strong>
    Each row is "your team", each column is the opponent. Cell format is W-L-D from the row team's perspective.
    Diagonal cells are blank because a team cannot play itself. Scope: regular season only (playoffs excluded).
    Seasons covered: <?= htmlspecialchars($seasonLabel) ?>.
</div>

<?php if ($teams === []): ?>
    <div class="trade-empty">No head-to-head data could be loaded right now.</div>
<?php else: ?>
    <div class="history-table-wrap h2h-table-wrap">
        <table class="history-table h2h-table">
            <thead>
                <tr>
                    <th class="h2h-sticky-col">Team</th>
                    <?php foreach ($teams as $opponent): ?>
                        <th class="h2h-col-team" title="<?= htmlspecialchars((string) $opponent['team_name']) ?>">
                            <span class="h2h-col-label"><?= htmlspecialchars($shortName((string) $opponent['team_name'])) ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teams as $team): ?>
                    <?php
                        $teamId = (string) $team['id'];
                    ?>
                    <tr>
                        <td class="history-team h2h-sticky-col">
                            <?php if (!empty($team['logo_url'])): ?>
                                <img class="history-team__logo" src="<?= htmlspecialchars((string) $team['logo_url']) ?>" alt="<?= htmlspecialchars((string) $team['team_name']) ?> logo" loading="lazy" decoding="async">
                            <?php endif; ?>
                            <span class="h2h-row-team-name"><?= htmlspecialchars((string) $team['team_name']) ?></span>
                        </td>

                        <?php foreach ($teams as $opponent): ?>
                            <?php
                                $oppId = (string) $opponent['id'];
                                $cell = $matrix[$teamId][$oppId] ?? ['w' => 0, 'l' => 0, 'd' => 0, 'games' => 0];
                                $games = (int) ($cell['games'] ?? 0);
                            ?>
                            <td class="history-place h2h-cell<?= $teamId === $oppId ? ' h2h-cell--self' : '' ?>" data-sort-value="<?= $games ?>">
                                <?php if ($teamId === $oppId): ?>
                                    <span class="history-place__empty">—</span>
                                <?php elseif ($games === 0): ?>
                                    <span class="history-place__empty">0-0-0</span>
                                <?php else: ?>
                                    <span class="h2h-record"><?= (int) $cell['w'] ?>-<?= (int) $cell['l'] ?><?= (int) $cell['d'] > 0 ? '-' . (int) $cell['d'] : '-0' ?></span>
                                    <span class="insight-sub"><?= $games ?> game<?= $games !== 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
