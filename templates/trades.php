<?php
/** @var int[]    $seasons  */
/** @var string[] $managers */
/** @var array[]  $trades   */
/** @var array<int,string> $errors */

$filterSeason  = (int)   ($_GET['season']  ?? 0);
$filterManager = (string)($_GET['manager'] ?? '');
$filterFromManager = (string)($_GET['from_manager'] ?? '');
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

<?php
$filtered = array_filter($trades, static function (array $trade) use ($filterSeason, $filterManager, $filterFromManager): bool {
    if ($filterSeason !== 0 && $trade['season'] !== $filterSeason) {
        return false;
    }
    if ($filterManager !== '' && $trade['side_a']['team_name'] !== $filterManager) {
        return false;
    }
    if ($filterFromManager !== '' && $trade['side_b']['team_name'] !== $filterFromManager) {
        return false;
    }
    return true;
});

$filteredCount = count($filtered);
?>

<?php if ($filteredCount === 0): ?>
    <div class="trade-empty">
        <?php if ($totalTrades === 0): ?>
            No trade data could be loaded. This may be a temporary Yahoo API issue — try refreshing.
        <?php else: ?>
            No trades match the selected filters.
        <?php endif; ?>
    </div>
<?php else: ?>

<form method="get" action="" id="trade-filter-form">
    <input type="hidden" name="r" value="trades">
<div class="history-table-wrap trade-table-wrap">
    <table class="history-table trade-table">
        <thead>
            <tr>
                <th class="trade-th--season">
                    <div class="trade-th__label">Season</div>
                    <select class="trade-th__select" name="season" onchange="document.getElementById('trade-filter-form').submit()">
                        <option value="0"<?= $filterSeason === 0 ? ' selected' : '' ?>>All</option>
                        <?php foreach ($seasons as $s): ?>
                            <option value="<?= (int) $s ?>"<?= $filterSeason === (int) $s ? ' selected' : '' ?>><?= (int) $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </th>
                <th>Date</th>
                <th class="trade-col--team-a">
                    <div class="trade-th__label">Manager</div>
                    <select class="trade-th__select" name="manager" onchange="document.getElementById('trade-filter-form').submit()">
                        <option value=""<?= $filterManager === '' ? ' selected' : '' ?>>All</option>
                        <?php foreach ($managers as $mgr): ?>
                            <option value="<?= htmlspecialchars($mgr) ?>"<?= $filterManager === $mgr ? ' selected' : '' ?>><?= htmlspecialchars($mgr) ?></option>
                        <?php endforeach; ?>
                    </select>
                </th>
                <th class="trade-col--assets-a">Gave away</th>
                <th class="trade-col--arrow"></th>
                <th class="trade-col--assets-b">Received</th>
                <th class="trade-col--team-b">
                    <div class="trade-th__label">From Manager</div>
                    <select class="trade-th__select" name="from_manager" onchange="document.getElementById('trade-filter-form').submit()">
                        <option value=""<?= $filterFromManager === '' ? ' selected' : '' ?>>All</option>
                        <?php foreach ($managers as $mgr): ?>
                            <option value="<?= htmlspecialchars($mgr) ?>"<?= $filterFromManager === $mgr ? ' selected' : '' ?>><?= htmlspecialchars($mgr) ?></option>
                        <?php endforeach; ?>
                    </select>
                </th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filtered as $trade):
            $sideA = $trade['side_a'];
            $sideB = $trade['side_b'];
            $assetsA = $sideA['assets'];
            $assetsB = $sideB['assets'];
            $highlightA = $filterManager !== '' && $sideA['team_name'] === $filterManager;
            $highlightB = $filterFromManager !== '' && $sideB['team_name'] === $filterFromManager;
        ?>
            <tr>
                <td><?= (int) $trade['season'] ?></td>
                <td class="trade-col--date"><?= htmlspecialchars($trade['date'] ?? '—') ?></td>

                <td class="trade-col--team-a<?= $highlightA ? ' trade-team--highlight' : '' ?>">
                    <?= htmlspecialchars($sideA['team_name']) ?>
                </td>

                <td class="trade-col--assets-a">
                    <?php if ($assetsA !== []): ?>
                        <div class="trade-assets trade-assets--right">
                            <?php foreach ($assetsA as $asset): ?>
                                <span class="trade-asset"><?= htmlspecialchars($asset) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="trade-assets--empty">—</span>
                    <?php endif; ?>
                </td>

                <td class="trade-col--arrow">
                    <span class="trade-arrow" aria-hidden="true">⇄</span>
                </td>

                <td class="trade-col--assets-b">
                    <?php if ($assetsB !== []): ?>
                        <div class="trade-assets trade-assets--left">
                            <?php foreach ($assetsB as $asset): ?>
                                <span class="trade-asset"><?= htmlspecialchars($asset) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="trade-assets--empty">—</span>
                    <?php endif; ?>
                </td>

                <td class="trade-col--team-b<?= $highlightB ? ' trade-team--highlight' : '' ?>">
                    <?= htmlspecialchars($sideB['team_name']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</form>
<?php if ($filterSeason !== 0 || $filterManager !== '' || $filterFromManager !== ''): ?>
    <p class="trade-result-count"><a href="/index.php?r=trades">Clear filters</a></p>
<?php endif; ?>

<?php endif; ?>
