<?php
$seasons = $history['seasons'] ?? [];
$rows = $history['rows'] ?? [];
$last3Seasons = $history['last3_seasons'] ?? [];
$seasonStates = $history['season_states'] ?? [];

$formatAverage = static function (?float $value): string {
    if ($value === null) {
        return '—';
    }

    return number_format($value, 1, '.', '');
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
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Historical League Standings</h1>
    </div>
</section>

<p class="history-subtitle">
    Head-to-head era since 2018. Click any column header to sort.
</p>

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
    <table class="history-table" id="historyTable">
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
                    ?>
                    <td class="history-place" data-sort-value="<?= $place ?? 99 ?>">
                        <?php if ($place !== null): ?>
                            <span class="history-place__content">
                                <span class="history-place__value"><?= (int) $place ?></span>
                                <span class="history-place__medal<?= $medal === '' ? ' is-empty' : '' ?>" aria-hidden="true">
                                    <?= $medal !== '' ? $medal : '•' ?>
                                </span>
                            </span>
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

<script>
(() => {
    const table = document.getElementById('historyTable');
    if (!table) {
        return;
    }

    const headers = Array.from(table.querySelectorAll('thead th[data-sort]'));
    const tbody = table.querySelector('tbody');

    if (!tbody) {
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
})();
</script>
