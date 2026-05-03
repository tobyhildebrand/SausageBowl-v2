<?php
/** @var int[]    $seasons  */
/** @var string[] $managers */
/** @var array[]  $trades   */
/** @var array<int,string> $errors */

$filterSeason  = (int)   ($_GET['season']  ?? 0);
$filterManager = (string)($_GET['manager'] ?? '');
$totalTrades   = count($trades);
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Trade History</h1>
        <span class="page-header__meta"><?= $totalTrades ?> trade<?= $totalTrades !== 1 ? 's' : '' ?> since 2018</span>
    </div>
</section>

<?php if ($errors !== []): ?>
    <div class="trade-notice trade-notice--warning">
        Could not load data for:
        <?php foreach ($errors as $errSeason => $errMsg): ?>
            <strong><?= (int) $errSeason ?></strong> (<?= htmlspecialchars($errMsg) ?>)
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="trade-filters">
    <form method="get" action="" class="trade-filters__form">
        <input type="hidden" name="r" value="trades">

        <label class="trade-filters__label" for="filter-season">Season</label>
        <select class="trade-filters__select" id="filter-season" name="season" onchange="this.form.submit()">
            <option value="0"<?= $filterSeason === 0 ? ' selected' : '' ?>>All seasons</option>
            <?php foreach ($seasons as $s): ?>
                <option value="<?= (int) $s ?>"<?= $filterSeason === (int) $s ? ' selected' : '' ?>><?= (int) $s ?></option>
            <?php endforeach; ?>
        </select>

        <label class="trade-filters__label" for="filter-manager">Manager</label>
        <select class="trade-filters__select" id="filter-manager" name="manager" onchange="this.form.submit()">
            <option value=""<?= $filterManager === '' ? ' selected' : '' ?>>All managers</option>
            <?php foreach ($managers as $mgr): ?>
                <option value="<?= htmlspecialchars($mgr) ?>"<?= $filterManager === $mgr ? ' selected' : '' ?>><?= htmlspecialchars($mgr) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($filterSeason !== 0 || $filterManager !== ''): ?>
            <a href="/index.php?r=trades" class="trade-filters__clear">Clear filters</a>
        <?php endif; ?>
    </form>
</div>

<?php
// Apply filters
$filtered = array_filter($trades, static function (array $trade) use ($filterSeason, $filterManager): bool {
    if ($filterSeason !== 0 && $trade['season'] !== $filterSeason) {
        return false;
    }
    if ($filterManager !== '' && $trade['side_a']['team_name'] !== $filterManager && $trade['side_b']['team_name'] !== $filterManager) {
        return false;
    }
    return true;
});

$filteredCount = count($filtered);
?>

<?php if ($filterSeason !== 0 || $filterManager !== ''): ?>
    <p class="trade-result-count">
        Showing <strong><?= $filteredCount ?></strong> trade<?= $filteredCount !== 1 ? 's' : '' ?><?php if ($filterManager !== ''): ?> involving <strong><?= htmlspecialchars($filterManager) ?></strong><?php endif; ?><?php if ($filterSeason !== 0): ?> in <strong><?= $filterSeason ?></strong><?php endif; ?>.
    </p>
<?php endif; ?>

<?php if ($filteredCount === 0): ?>
    <div class="trade-empty">
        <?php if ($totalTrades === 0): ?>
            No trade data could be loaded. This may be a temporary Yahoo API issue — try refreshing.
        <?php else: ?>
            No trades match the selected filters.
        <?php endif; ?>
    </div>
<?php else: ?>

<div class="trade-table-wrap">
    <table class="trade-table">
        <thead>
            <tr>
                <th class="trade-table__col--season">Season</th>
                <th class="trade-table__col--date">Date</th>
                <th class="trade-table__col--team trade-table__col--team-a">Manager</th>
                <th class="trade-table__col--assets trade-table__col--assets-a">Sent</th>
                <th class="trade-table__col--arrow" aria-label="Trade direction"></th>
                <th class="trade-table__col--assets trade-table__col--assets-b">Sent</th>
                <th class="trade-table__col--team trade-table__col--team-b">Manager</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filtered as $trade):
            $sideA = $trade['side_a'];
            $sideB = $trade['side_b'];
            $assetsA = $sideA['assets'];
            $assetsB = $sideB['assets'];
            $highlightA = $filterManager !== '' && $sideA['team_name'] === $filterManager;
            $highlightB = $filterManager !== '' && $sideB['team_name'] === $filterManager;
        ?>
            <tr class="trade-row">
                <td class="trade-table__col--season"><?= (int) $trade['season'] ?></td>
                <td class="trade-table__col--date"><?= htmlspecialchars($trade['date'] ?? '—') ?></td>

                <td class="trade-table__col--team trade-table__col--team-a<?= $highlightA ? ' is-highlighted' : '' ?>">
                    <?= htmlspecialchars($sideA['team_name']) ?>
                </td>

                <td class="trade-table__col--assets trade-table__col--assets-a">
                    <?php if ($assetsA !== []): ?>
                        <ul class="trade-assets">
                            <?php foreach ($assetsA as $asset): ?>
                                <li class="trade-asset"><?= htmlspecialchars($asset) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span class="trade-assets--empty">—</span>
                    <?php endif; ?>
                </td>

                <td class="trade-table__col--arrow">
                    <span class="trade-arrow" aria-hidden="true">⇄</span>
                </td>

                <td class="trade-table__col--assets trade-table__col--assets-b">
                    <?php if ($assetsB !== []): ?>
                        <ul class="trade-assets">
                            <?php foreach ($assetsB as $asset): ?>
                                <li class="trade-asset"><?= htmlspecialchars($asset) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span class="trade-assets--empty">—</span>
                    <?php endif; ?>
                </td>

                <td class="trade-table__col--team trade-table__col--team-b<?= $highlightB ? ' is-highlighted' : '' ?>">
                    <?= htmlspecialchars($sideB['team_name']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>
