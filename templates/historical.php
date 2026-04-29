<?php
$seasons = $history['seasons'] ?? [];
$rows = $history['rows'] ?? [];
$last3Seasons = $history['last3_seasons'] ?? [];
$seasonStates = $history['season_states'] ?? [];

$pointSeasons = $points['seasons'] ?? [];
$pointRows = $points['rows'] ?? [];
$pointLast3Seasons = $points['last3_seasons'] ?? [];
$pointSeasonStates = $points['season_states'] ?? [];

$insightRows = $insights['rows'] ?? [];
$insightLast3Seasons = $insights['last3_seasons'] ?? [];
$closeMargin = (float) ($insights['close_margin'] ?? 10.0);

$tabFromQuery = strtolower((string) ($_GET['tab'] ?? 'placings'));
$activeTab = in_array($tabFromQuery, ['placings', 'points', 'insights'], true) ? $tabFromQuery : 'placings';

$formatAverage = static function (?float $value): string {
    if ($value === null) {
        return '—';
    }

    return number_format($value, 1, '.', '');
};

$formatPoints = static function (?float $value): string {
    if ($value === null) {
        return '—';
    }

    return number_format($value, 1, '.', '');
};

$formatSigned = static function (?float $value, int $decimals = 2): string {
    if ($value === null) {
        return '—';
    }

    $formatted = number_format($value, $decimals, '.', '');
    return $value > 0 ? '+' . $formatted : $formatted;
};

$medalForPlace = static function (?int $place): string {
    if ($place === 1) {
        return '🥇';
    }

    if ($place === 2) {
        return '🥈';
    }

    if ($place === 3) {
        return '🥉';
    }

    return '';
};

$seasonIssues = [];
foreach ($seasons as $season) {
    $state = $seasonStates[(int) $season]['status'] ?? 'ok';
    if ($state === 'partial' || $state === 'error') {
        $seasonIssues[(int) $season] = $seasonStates[(int) $season];
    }
}

$pointSeasonIssues = [];
foreach ($pointSeasons as $season) {
    $state = $pointSeasonStates[(int) $season]['status'] ?? 'ok';
    if ($state === 'partial' || $state === 'error') {
        $pointSeasonIssues[(int) $season] = $pointSeasonStates[(int) $season];
    }
}
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Historical League Standings</h1>
    </div>
</section>

<p class="history-subtitle">
    Head-to-head era since 2018. Toggle between placings, points, and insights, then click any column header to sort.
</p>

<div class="history-tabs" role="tablist" aria-label="Historical data view switch">
    <button
        type="button"
        class="history-tab<?= $activeTab === 'placings' ? ' is-active' : '' ?>"
        id="history-tab-placings"
        data-tab="placings"
        role="tab"
        aria-controls="history-pane-placings"
        aria-selected="<?= $activeTab === 'placings' ? 'true' : 'false' ?>"
    >
        Placings
    </button>
    <button
        type="button"
        class="history-tab<?= $activeTab === 'points' ? ' is-active' : '' ?>"
        id="history-tab-points"
        data-tab="points"
        role="tab"
        aria-controls="history-pane-points"
        aria-selected="<?= $activeTab === 'points' ? 'true' : 'false' ?>"
    >
        Points
    </button>
    <button
        type="button"
        class="history-tab<?= $activeTab === 'insights' ? ' is-active' : '' ?>"
        id="history-tab-insights"
        data-tab="insights"
        role="tab"
        aria-controls="history-pane-insights"
        aria-selected="<?= $activeTab === 'insights' ? 'true' : 'false' ?>"
    >
        Insights
    </button>
</div>

<div class="history-pane<?= $activeTab === 'placings' ? ' is-active' : '' ?>" id="history-pane-placings" role="tabpanel" aria-labelledby="history-tab-placings"<?= $activeTab === 'placings' ? '' : ' hidden' ?>>
    <?php if ($seasonIssues !== []): ?>
        <div class="history-state history-state--warning">
            <strong>Data quality notice:</strong>
            <ul>
                <?php foreach ($seasonIssues as $season => $issue): ?>
                    <li>
                        <?= (int) $season ?>:
                        <?php if (($issue['status'] ?? '') === 'error'): ?>
                            Could not load this season from Yahoo.
                        <?php else: ?>
                            Partial data loaded (<?= (int) ($issue['teams_found'] ?? 0) ?>/<?= (int) ($issue['expected_teams'] ?? 0) ?> teams).
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="history-table-wrap">
        <table class="history-table js-sortable-table" id="historyPlacingsTable">
            <thead>
            <tr>
                <th data-sort="team" data-type="string">Team</th>
                <?php foreach ($seasons as $season): ?>
                    <th data-sort="season-<?= (int) $season ?>" data-type="number">
                        <?= (int) $season ?>
                    </th>
                <?php endforeach; ?>
                <th data-sort="avg" data-type="number">Average Place</th>
                <th data-sort="trend" data-type="number">Trend (L3Y)</th>
                <th data-sort="wld" data-type="string" title="All-time regular-season record">Overall W-L-D</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="history-team" data-sort-value="<?= htmlspecialchars(strtolower((string) $row['team_name'])) ?>">
                        <?php if (!empty($row['logo_url'])): ?>
                            <img class="history-team__logo" src="<?= htmlspecialchars((string) $row['logo_url']) ?>" alt="<?= htmlspecialchars((string) $row['team_name']) ?> logo" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <span><?= htmlspecialchars((string) $row['team_name']) ?></span>
                    </td>

                    <?php foreach ($seasons as $season): ?>
                        <?php
                            $place = $row['places'][(int) $season] ?? null;
                            $medal = $medalForPlace(is_int($place) ? $place : null);
                            $state = $seasonStates[(int) $season]['status'] ?? 'ok';
                            $seasonWld = $row['wld'][(int) $season] ?? null;
                        ?>
                        <td class="history-place" data-sort-value="<?= $place ?? 99 ?>">
                            <?php if ($place !== null): ?>
                                <span class="history-place__content">
                                    <span class="history-place__value"><?= (int) $place ?></span>
                                    <span class="history-place__medal<?= $medal === '' ? ' is-empty' : '' ?>" aria-hidden="true">
                                        <?= $medal !== '' ? $medal : '•' ?>
                                    </span>
                                </span>
                                <?php if ($seasonWld !== null): ?>
                                    <span class="history-place__wld"><?= (int) $seasonWld['w'] ?>-<?= (int) $seasonWld['l'] ?><?= (int) $seasonWld['d'] > 0 ? '-' . (int) $seasonWld['d'] : '' ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($state === 'error'): ?>
                                    <span class="history-place__error" title="Season data unavailable from Yahoo">⚠</span>
                                <?php elseif ($state === 'partial'): ?>
                                    <span class="history-place__loading" title="Season loaded partially">⏳</span>
                                <?php else: ?>
                                    <span class="history-place__empty">—</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                    <td class="history-place" data-sort-value="<?= (float) $row['average_place'] ?>">
                        <?= htmlspecialchars($formatAverage((float) $row['average_place'])) ?>
                    </td>

                    <td class="history-place" data-sort-value="<?= $row['trend_l3y'] ?? 99 ?>">
                        <?= htmlspecialchars($formatAverage(isset($row['trend_l3y']) ? (float) $row['trend_l3y'] : null)) ?>
                    </td>
                    <?php
                        $tw = (int) ($row['total_wins'] ?? 0);
                        $tl = (int) ($row['total_losses'] ?? 0);
                        $td = (int) ($row['total_draws'] ?? 0);
                        $tGames = $tw + $tl + $td;
                        $winPct = $tGames > 0 ? round($tw / $tGames * 100) : null;
                    ?>
                    <td class="history-place history-place--wld" data-sort-value="<?= $winPct ?? -1 ?>">
                        <span class="history-wld"><?= $tw ?>-<?= $tl ?><?= $td > 0 ? '-' . $td : '' ?></span>
                        <?php if ($winPct !== null): ?>
                            <span class="insight-sub"><?= $winPct ?>%</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($last3Seasons !== []): ?>
        <p class="history-footnote">
            L3Y uses seasons <?= (int) $last3Seasons[0] ?> to <?= (int) $last3Seasons[count($last3Seasons) - 1] ?>.
        </p>
    <?php endif; ?>
</div>

<div class="history-pane<?= $activeTab === 'points' ? ' is-active' : '' ?>" id="history-pane-points" role="tabpanel" aria-labelledby="history-tab-points"<?= $activeTab === 'points' ? '' : ' hidden' ?>>
    <?php if ($pointSeasonIssues !== []): ?>
        <div class="history-state history-state--warning">
            <strong>Data quality notice:</strong>
            <ul>
                <?php foreach ($pointSeasonIssues as $season => $issue): ?>
                    <li>
                        <?= (int) $season ?>:
                        <?php if (($issue['status'] ?? '') === 'error'): ?>
                            Could not load this season from Yahoo.
                        <?php else: ?>
                            Partial data loaded (<?= (int) ($issue['teams_found'] ?? 0) ?>/<?= (int) ($issue['expected_teams'] ?? 0) ?> teams).
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="history-table-wrap">
        <table class="history-table js-sortable-table" id="historyPointsTable">
            <thead>
            <tr>
                <th data-sort="team" data-type="string">Team</th>
                <?php foreach ($pointSeasons as $season): ?>
                    <th data-sort="season-<?= (int) $season ?>" data-type="number">
                        <?= (int) $season ?>
                    </th>
                <?php endforeach; ?>
                <th data-sort="avg" data-type="number">Avg Points</th>
                <th data-sort="trend" data-type="number">Trend (L3Y)</th>
                <th data-sort="all-time" data-type="number">All-Time RS Points</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pointRows as $row): ?>
                <tr>
                    <td class="history-team" data-sort-value="<?= htmlspecialchars(strtolower((string) $row['team_name'])) ?>">
                        <?php if (!empty($row['logo_url'])): ?>
                            <img class="history-team__logo" src="<?= htmlspecialchars((string) $row['logo_url']) ?>" alt="<?= htmlspecialchars((string) $row['team_name']) ?> logo" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <span><?= htmlspecialchars((string) $row['team_name']) ?></span>
                    </td>

                    <?php foreach ($pointSeasons as $season): ?>
                        <?php
                            $seasonPoints = isset($row['points'][(int) $season]) ? (float) $row['points'][(int) $season] : null;
                            $state = $pointSeasonStates[(int) $season]['status'] ?? 'ok';
                        ?>
                        <td class="history-place history-place--points" data-sort-value="<?= $seasonPoints ?? -1 ?>">
                            <?php if ($seasonPoints !== null): ?>
                                <span class="history-points__value"><?= htmlspecialchars($formatPoints($seasonPoints)) ?></span>
                            <?php else: ?>
                                <?php if ($state === 'error'): ?>
                                    <span class="history-place__error" title="Season data unavailable from Yahoo">⚠</span>
                                <?php elseif ($state === 'partial'): ?>
                                    <span class="history-place__loading" title="Season loaded partially">⏳</span>
                                <?php else: ?>
                                    <span class="history-place__empty">—</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                    <td class="history-place history-place--points" data-sort-value="<?= (float) $row['average_points'] ?>">
                        <?= htmlspecialchars($formatPoints((float) $row['average_points'])) ?>
                    </td>

                    <td class="history-place history-place--points" data-sort-value="<?= $row['trend_l3y'] ?? -1 ?>">
                        <?= htmlspecialchars($formatPoints(isset($row['trend_l3y']) ? (float) $row['trend_l3y'] : null)) ?>
                    </td>

                    <td class="history-place history-place--points" data-sort-value="<?= (float) $row['all_time_points'] ?>">
                        <strong><?= htmlspecialchars($formatPoints((float) $row['all_time_points'])) ?></strong>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pointLast3Seasons !== []): ?>
        <p class="history-footnote">
            L3Y uses seasons <?= (int) $pointLast3Seasons[0] ?> to <?= (int) $pointLast3Seasons[count($pointLast3Seasons) - 1] ?>.
        </p>
    <?php endif; ?>
</div>

<div class="history-pane<?= $activeTab === 'insights' ? ' is-active' : '' ?>" id="history-pane-insights" role="tabpanel" aria-labelledby="history-tab-insights"<?= $activeTab === 'insights' ? '' : ' hidden' ?>>
    <div class="history-state history-state--info">
        <strong>How to read:</strong>
        PEI = points/rank efficiency, Luck Gap = points rank minus final rank, Momentum = weighted L3Y form, Volatility = rank standard deviation, Close-Call = wins in games decided by <?= htmlspecialchars((string) $formatPoints($closeMargin)) ?> points or less.
    </div>

    <div class="history-table-wrap">
        <table class="history-table js-sortable-table" id="historyInsightsTable">
            <thead>
            <tr>
                <th data-sort="team" data-type="string">Team</th>
                <th data-sort="pei" data-type="number" title="Placement Efficiency Index = average(points/rank)">PEI</th>
                <th data-sort="luck" data-type="number" title="Average points-rank minus final-rank. Positive means outperforming points profile.">Luck Gap</th>
                <th data-sort="momentum" data-type="number" title="Weighted form score over the last 3 finished seasons.">Momentum</th>
                <th data-sort="volatility" data-type="number" title="Standard deviation of season ranks. Lower means steadier.">Volatility</th>
                <th data-sort="close-rate" data-type="number" title="Close-call win rate in games decided by <?= htmlspecialchars((string) $formatPoints($closeMargin)) ?> points or less.">Close-Call Conv.</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($insightRows as $row): ?>
                <?php
                    $pei = isset($row['placement_efficiency']) ? (float) $row['placement_efficiency'] : null;
                    $luck = isset($row['luck_gap']) ? (float) $row['luck_gap'] : null;
                    $momentum = isset($row['momentum']) ? (float) $row['momentum'] : null;
                    $volatility = isset($row['volatility']) ? (float) $row['volatility'] : null;
                    $closeRate = isset($row['close_call_rate']) ? (float) $row['close_call_rate'] : null;
                    $closeWins = (int) ($row['close_call_wins'] ?? 0);
                    $closeGames = (int) ($row['close_call_games'] ?? 0);
                ?>
                <tr>
                    <td class="history-team" data-sort-value="<?= htmlspecialchars(strtolower((string) $row['team_name'])) ?>">
                        <?php if (!empty($row['logo_url'])): ?>
                            <img class="history-team__logo" src="<?= htmlspecialchars((string) $row['logo_url']) ?>" alt="<?= htmlspecialchars((string) $row['team_name']) ?> logo" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <span><?= htmlspecialchars((string) $row['team_name']) ?></span>
                    </td>

                    <td class="history-place history-place--points" data-sort-value="<?= $pei ?? -1 ?>">
                        <?= htmlspecialchars($formatPoints($pei)) ?>
                    </td>
                    <td class="history-place history-place--points" data-sort-value="<?= $luck ?? -999 ?>">
                        <span class="<?= $luck !== null && $luck > 0 ? 'insight-positive' : ($luck !== null && $luck < 0 ? 'insight-negative' : '') ?>">
                            <?= htmlspecialchars($formatSigned($luck, 2)) ?>
                        </span>
                    </td>
                    <td class="history-place history-place--points" data-sort-value="<?= $momentum ?? -1 ?>">
                        <?= htmlspecialchars($formatPoints($momentum)) ?>
                    </td>
                    <td class="history-place history-place--points" data-sort-value="<?= $volatility ?? 999 ?>">
                        <?= htmlspecialchars($formatSigned($volatility, 2)) ?>
                    </td>
                    <td class="history-place history-place--points" data-sort-value="<?= $closeRate ?? -1 ?>">
                        <?php if ($closeRate !== null): ?>
                            <strong><?= htmlspecialchars($formatPoints($closeRate)) ?>%</strong>
                            <span class="insight-sub">(<?= (int) $closeWins ?>/<?= (int) $closeGames ?>)</span>
                        <?php else: ?>
                            <span class="history-place__empty">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($insightLast3Seasons !== []): ?>
        <p class="history-footnote">
            Momentum and close-call conversion use seasons <?= (int) $insightLast3Seasons[0] ?> to <?= (int) $insightLast3Seasons[count($insightLast3Seasons) - 1] ?>.
        </p>
    <?php endif; ?>

    <div class="insight-guide">
        <p class="insight-guide__title">What do these metrics mean?</p>
        <div class="insight-guide__grid">

            <div class="insight-card">
                <p class="insight-card__name">PEI &mdash; Placement Efficiency Index</p>
                <p class="insight-card__tagline">Are you finishing where your points deserve?</p>
                <p class="insight-card__body">
                    Each season your total points are divided by your final rank. The average across all seasons is your PEI.
                    A higher number means you consistently score big <em>and</em> finish high &mdash; the ideal combination.
                </p>
                <p class="insight-card__example">
                    Example: 1 500 pts, 3rd place &rarr; 500. Next season 1 400 pts, 1st place &rarr; 1 400. PEI = 950. A team finishing 1st with fewer points beats one finishing 3rd with more.
                </p>
            </div>

            <div class="insight-card">
                <p class="insight-card__name">Luck Gap</p>
                <p class="insight-card__tagline">Did fortune favour you &mdash; or were you robbed?</p>
                <p class="insight-card__body">
                    Every week your score is compared to every other team. That produces a &ldquo;points rank&rdquo; independent of your actual W&ndash;L record.
                    Luck Gap = points rank &minus; final rank. <span class="insight-positive">Positive</span> = you finished better than your scoring deserved (lucky schedule).
                    <span class="insight-negative">Negative</span> = you outscored most teams but still finished lower (unlucky matchups).
                </p>
                <p class="insight-card__example">
                    Example: You ranked 3rd in points but finished 6th &rarr; Luck Gap &minus;3 (unlucky). You ranked 8th in points but finished 4th &rarr; Luck Gap +4 (lucky).
                </p>
            </div>

            <div class="insight-card">
                <p class="insight-card__name">Momentum</p>
                <p class="insight-card__tagline">Are you on the rise or fading?</p>
                <p class="insight-card__body">
                    A weighted form score across the last three finished seasons. Recent seasons count far more than older ones
                    (60&nbsp;% this year, 30&nbsp;% last year, 10&nbsp;% the year before). Both your rank and your points output feed into the score.
                    Higher is better.
                </p>
                <p class="insight-card__example">
                    Example: Finishing 2nd &rarr; 8th &rarr; 1st puts heavy weight on that 1st place &mdash; high Momentum.
                    Finishing 1st &rarr; 1st &rarr; 10th drags the score down sharply despite the good history.
                </p>
            </div>

            <div class="insight-card">
                <p class="insight-card__name">Volatility</p>
                <p class="insight-card__tagline">Consistent contender or a wildcard?</p>
                <p class="insight-card__body">
                    The standard deviation of your season-end ranks across all completed seasons.
                    A <strong>low</strong> number means you reliably land in the same range every year.
                    A <strong>high</strong> number means your finish is hard to predict &mdash; feast or famine.
                </p>
                <p class="insight-card__example">
                    Example: Finishing 2nd, 3rd, 2nd, 1st &rarr; Volatility &asymp;&nbsp;0.8 (rock solid).
                    Finishing 1st, 9th, 2nd, 8th &rarr; Volatility &asymp;&nbsp;3.9 (unpredictable).
                </p>
            </div>

            <div class="insight-card">
                <p class="insight-card__name">Close-Call Conversion</p>
                <p class="insight-card__tagline">Do you win the tight ones?</p>
                <p class="insight-card__body">
                    Of all your matchups decided by <?= htmlspecialchars((string) $formatPoints($closeMargin)) ?>&nbsp;points or fewer, what percentage did you win?
                    These nail-biting games are largely down to last-minute lineup calls and a bit of luck.
                    A high rate suggests you tend to come out on top when it matters most.
                </p>
                <p class="insight-card__example">
                    Example: 7 close games, 5 wins &rarr; 71&nbsp;%. The sample (shown in brackets) tells you how reliable the stat is &mdash; treat a 2/2 very differently from a 10/14.
                </p>
            </div>

        </div>
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

    const tabs = Array.from(document.querySelectorAll('.history-tab'));
    const panes = Array.from(document.querySelectorAll('.history-pane'));

    const activateTab = (tabName) => {
        tabs.forEach((tab) => {
            const isActive = tab.dataset.tab === tabName;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panes.forEach((pane) => {
            const isActive = pane.id === `history-pane-${tabName}`;
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
            const tabName = tab.dataset.tab;
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
