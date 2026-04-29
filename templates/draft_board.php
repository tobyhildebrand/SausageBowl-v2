<?php
$seasonYear = (int) ($seasonYear ?? (int) date('Y'));
$draftUpcoming = is_array($draftUpcoming ?? null) ? $draftUpcoming : [];
$boardRows = is_array($draftUpcoming['board_rows'] ?? null) ? $draftUpcoming['board_rows'] : [];
$roundCount = (int) ($draftUpcoming['round_count'] ?? 8);
$roundRange = range(1, max(1, $roundCount));
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Draft Board</h1>
    </div>
    <p>Read-only upcoming draft board with traded pick ownership applied.</p>
</section>

<section class="card">
    <form method="get" action="/index.php" class="draft-inline-form">
        <input type="hidden" name="r" value="draft-board">
        <label class="auth-field">
            <span class="auth-field__label">Season</span>
            <input class="auth-field__input" type="number" name="season" min="2020" max="2100" value="<?= (int) $seasonYear ?>">
        </label>
        <div class="auth-actions">
            <button type="submit" class="btn btn--secondary">Switch Season</button>
        </div>
    </form>

    <div class="draft-board-wrap card--spaced">
        <?php if ($boardRows === []): ?>
            <p class="home-note">No draft board published for <?= (int) $seasonYear ?> yet.</p>
        <?php else: ?>
            <table class="history-table draft-board-table">
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
                        <td><?= (int) ($row['slot_no'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string) ($row['default_team'] ?? '')) ?></td>
                        <?php foreach ($roundRange as $round): ?>
                            <?php $cell = $row['rounds'][$round] ?? null; ?>
                            <td class="<?= !empty($cell['is_changed']) ? 'draft-cell--changed' : 'draft-cell--default' ?>">
                                <?= htmlspecialchars((string) ($cell['owner'] ?? '')) ?>
                                <?php if (!empty($cell['is_changed'])): ?>
                                    <span class="insight-sub">(traded)</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
