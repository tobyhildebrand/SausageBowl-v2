<?php
/** @var int[]    $seasons  */
/** @var string[] $managers */
/** @var array[]  $trades   */
/** @var array[]  $statsRows */
/** @var array<int,string> $errors */
/** @var array<int,string> $statsErrors */

$filterSeason = (int) ($_GET['season'] ?? 0);
$filterManager = (string) ($_GET['manager'] ?? '');
$filterFromManager = (string) ($_GET['from_manager'] ?? '');
$tabFromQuery = strtolower((string) ($_GET['tab'] ?? 'history'));
$activeTab = in_array($tabFromQuery, ['history', 'stats'], true) ? $tabFromQuery : 'history';
$totalTrades = count($trades);
$statsRows = is_array($statsRows ?? null) ? $statsRows : [];
$statsErrors = is_array($statsErrors ?? null) ? $statsErrors : [];

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

<section class="page-header">
    <div class="page-header__row">
        <h1>Trade History</h1>
        <span class="page-header__meta"><?= $totalTrades ?> trade<?= $totalTrades !== 1 ? 's' : '' ?> since 2018</span>
    </div>
</section>

<p class="history-subtitle">
    Browse individual trades or switch to general team-level stats for trade volume and free-agent activity since 2018.
</p>

<div class="history-tabs" role="tablist" aria-label="Trade data view switch">
    <button
        type="button"
        class="history-tab<?= $activeTab === 'history' ? ' is-active' : '' ?>"
        id="trade-tab-history"
        data-trade-tab="history"
        role="tab"
        aria-controls="trade-pane-history"
        aria-selected="<?= $activeTab === 'history' ? 'true' : 'false' ?>"
    >
        Trades
    </button>
    <button
        type="button"
        class="history-tab<?= $activeTab === 'stats' ? ' is-active' : '' ?>"
        id="trade-tab-stats"
        data-trade-tab="stats"
        role="tab"
        aria-controls="trade-pane-stats"
        aria-selected="<?= $activeTab === 'stats' ? 'true' : 'false' ?>"
    >
        General Stats
    </button>
</div>

<div class="history-pane<?= $activeTab === 'history' ? ' is-active' : '' ?>" id="trade-pane-history" role="tabpanel" aria-labelledby="trade-tab-history"<?= $activeTab === 'history' ? '' : ' hidden' ?>>
    <?php if ($errors !== []): ?>
        <div class="trade-notice trade-notice--warning">
            Could not load trade data for:
            <?php foreach ($errors as $errSeason => $errMsg): ?>
                <strong><?= (int) $errSeason ?></strong> (<?= htmlspecialchars($errMsg) ?>)
            <?php endforeach; ?>
        </div>
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
        <form method="get" action="" id="trade-filter-form">
            <input type="hidden" name="r" value="trades">
            <input type="hidden" name="tab" value="history">
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
                    <?php foreach ($filtered as $trade): ?>
                        <?php
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
                                            <div class="trade-asset-row">
                                                <span class="trade-asset"><?= htmlspecialchars($asset) ?></span>
                                            </div>
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
                                            <div class="trade-asset-row">
                                                <span class="trade-asset"><?= htmlspecialchars($asset) ?></span>
                                            </div>
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
            <p class="trade-result-count"><a href="/index.php?r=trades&tab=history">Clear filters</a></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="history-pane<?= $activeTab === 'stats' ? ' is-active' : '' ?>" id="trade-pane-stats" role="tabpanel" aria-labelledby="trade-tab-stats"<?= $activeTab === 'stats' ? '' : ' hidden' ?>>
    <?php if ($statsErrors !== []): ?>
        <div class="trade-notice trade-notice--warning">
            Could not load free-agent add data for:
            <?php foreach ($statsErrors as $errSeason => $errMsg): ?>
                <strong><?= (int) $errSeason ?></strong> (<?= htmlspecialchars($errMsg) ?>)
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="history-state history-state--info">
        <strong>How to read:</strong>
        Total Trades counts completed trades involving the team. Free Agent Adds counts direct FA pickups (no FAAB cost). Waiver Adds counts waiver claims (FAAB auctions). FAAB Spent is the total bid amount spent on waiver claims.
    </div>

    <div class="history-table-wrap">
        <table class="history-table js-sortable-table" id="tradeStatsTable">
            <thead>
                <tr>
                    <th data-sort="team" data-type="string">Team</th>
                    <th data-sort="trades" data-type="number">Total Trades</th>
                    <th data-sort="adds" data-type="number">Free Agent Adds</th>
                    <th data-sort="waiver" data-type="number">Waiver Adds</th>
                    <th data-sort="faab" data-type="number">Total FAAB Spent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statsRows as $row): ?>
                    <tr>
                        <td class="history-team" data-sort-value="<?= htmlspecialchars(strtolower((string) $row['team_name'])) ?>">
                            <span><?= htmlspecialchars((string) $row['team_name']) ?></span>
                        </td>
                        <td class="history-place history-place--points" data-sort-value="<?= (int) ($row['total_trades'] ?? 0) ?>">
                            <strong><?= (int) ($row['total_trades'] ?? 0) ?></strong>
                        </td>
                        <td class="history-place history-place--points" data-sort-value="<?= (int) ($row['free_agent_adds'] ?? 0) ?>">
                            <strong><?= (int) ($row['free_agent_adds'] ?? 0) ?></strong>
                        </td>
                        <td class="history-place history-place--points" data-sort-value="<?= (int) ($row['waiver_adds'] ?? 0) ?>">
                            <strong><?= (int) ($row['waiver_adds'] ?? 0) ?></strong>
                        </td>
                        <td class="history-place history-place--points" data-sort-value="<?= (int) ($row['faab_spent'] ?? 0) ?>">
                            <strong>$<?= number_format((int) ($row['faab_spent'] ?? 0)) ?></strong>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(() => {
    const initSortableTable = (table) => {
        const headers = Array.from(table.querySelectorAll('thead th[data-sort]'));
        const tbody = table.querySelector('tbody');

        if (!tbody || headers.length === 0) {
            return;
        }

        let activeIndex = -1;
        let direction = 'asc';

        const getCellValue = (row, index, type) => {
            const cell = row.children[index];
            if (!cell) {
                return type === 'number' ? Number.POSITIVE_INFINITY : '';
            }

            const raw = cell.dataset.sortValue ?? cell.textContent ?? '';
            if (type === 'number') {
                const num = Number(raw);
                return Number.isFinite(num) ? num : Number.POSITIVE_INFINITY;
            }

            return raw.toString().toLowerCase().trim();
        };

        const updateHeaderState = (index, dir) => {
            headers.forEach((th, i) => {
                th.classList.remove('is-sorted-asc', 'is-sorted-desc');
                if (i === index) {
                    th.classList.add(dir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
                }
            });
        };

        headers.forEach((th, index) => {
            th.addEventListener('click', () => {
                const type = th.dataset.type || 'string';

                if (activeIndex === index) {
                    direction = direction === 'asc' ? 'desc' : 'asc';
                } else {
                    activeIndex = index;
                    direction = 'asc';
                }

                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort((a, b) => {
                    const av = getCellValue(a, index, type);
                    const bv = getCellValue(b, index, type);

                    if (av < bv) {
                        return direction === 'asc' ? -1 : 1;
                    }
                    if (av > bv) {
                        return direction === 'asc' ? 1 : -1;
                    }
                    return 0;
                });

                rows.forEach((row) => tbody.appendChild(row));
                updateHeaderState(index, direction);
            });
        });
    };

    document.querySelectorAll('.js-sortable-table').forEach((table) => {
        initSortableTable(table);
    });

    const tabs = Array.from(document.querySelectorAll('[data-trade-tab]'));
    const panes = Array.from(document.querySelectorAll('#trade-pane-history, #trade-pane-stats'));

    const activateTab = (tabName) => {
        tabs.forEach((tab) => {
            const isActive = tab.dataset.tradeTab === tabName;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panes.forEach((pane) => {
            const isActive = pane.id === `trade-pane-${tabName}`;
            pane.classList.toggle('is-active', isActive);
            if (isActive) {
                pane.removeAttribute('hidden');
            } else {
                pane.setAttribute('hidden', 'hidden');
            }
        });
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const tabName = tab.dataset.tradeTab;
            if (!tabName) {
                return;
            }

            activateTab(tabName);

            const params = new URLSearchParams(window.location.search);
            params.set('tab', tabName);
            const newUrl = `${window.location.pathname}?${params.toString()}`;
            window.history.replaceState({}, '', newUrl);
        });
    });
})();
</script>
