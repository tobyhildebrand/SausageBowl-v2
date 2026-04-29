<?php
$seasonYear = (int) ($seasonYear ?? (int) date('Y'));
$draftUpcoming = is_array($draftUpcoming ?? null) ? $draftUpcoming : [];
$futureTrades = is_array($futureTrades ?? null) ? $futureTrades : [];
$draftError = (string) ($draftError ?? '');
$draftNotice = (string) ($draftNotice ?? '');
$wizardStep = (int) ($wizardStep ?? 1);
if ($wizardStep < 1 || $wizardStep > 3) {
    $wizardStep = 1;
}
$leagueTeamNames = is_array($leagueTeamNames ?? null) ? $leagueTeamNames : [];

$roundCount = (int) ($draftUpcoming['round_count'] ?? 8);
$boardRows = is_array($draftUpcoming['board_rows'] ?? null) ? $draftUpcoming['board_rows'] : [];
$overrides = is_array($draftUpcoming['overrides'] ?? null) ? $draftUpcoming['overrides'] : [];
$roundRange = range(1, max(1, $roundCount));
$slotByTeam = [];
foreach ((array) ($draftUpcoming['default_order'] ?? []) as $slot => $teamName) {
    $slotByTeam[(string) $teamName] = (int) $slot;
}

$noticeMap = [
    'setup_saved' => 'Upcoming draft setup saved.',
    'override_saved' => 'Pick-owner override saved.',
    'override_deleted' => 'Pick-owner override removed.',
    'future_trade_added' => 'Future pick trade added to ledger.',
    'future_trade_updated' => 'Future pick trade updated.',
];
$noticeText = $noticeMap[$draftNotice] ?? '';
?>

<section class="page-header">
    <div class="page-header__row">
        <h1>Comish Draft Desk</h1>
    </div>
    <p>Commissioner-only write access. Configure the upcoming draft board and track traded picks in future seasons.</p>
</section>

<?php if ($noticeText !== ''): ?>
    <section class="card draft-alert draft-alert--ok">
        <p><?= htmlspecialchars($noticeText) ?></p>
    </section>
<?php endif; ?>

<?php if ($draftError !== ''): ?>
    <section class="card draft-alert draft-alert--error">
        <p><?= htmlspecialchars($draftError) ?></p>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Yahoo Sync</h2>
    <p>Use this when you need to reconnect or refresh the league integration with Yahoo Fantasy Sports.</p>
    <p>
        <a class="btn" href="/index.php?r=yahoo/connect">Connect Yahoo League</a>
    </p>
</section>

<section class="card card--spaced">
    <h2>Upcoming Draft Board (<?= (int) $seasonYear ?>)</h2>
    <p>Wizard flow: 1) setup default order, 2) enter traded picks, 3) generate visual draft board.</p>

    <div class="draft-wizard-steps" role="tablist" aria-label="Draft setup wizard steps">
        <a class="draft-wizard-step<?= $wizardStep === 1 ? ' is-active' : '' ?>" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=1">1. Setup Order</a>
        <a class="draft-wizard-step<?= $wizardStep === 2 ? ' is-active' : '' ?>" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=2">2. Traded Picks</a>
        <a class="draft-wizard-step<?= $wizardStep === 3 ? ' is-active' : '' ?>" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=3">3. Draft Table</a>
    </div>

    <form method="get" action="/index.php" class="draft-inline-form">
        <input type="hidden" name="r" value="comish">
        <input type="hidden" name="step" value="<?= (int) $wizardStep ?>">
        <label class="auth-field">
            <span class="auth-field__label">Season</span>
            <input class="auth-field__input" type="number" name="season" min="2020" max="2100" value="<?= (int) $seasonYear ?>">
        </label>
        <div class="auth-actions">
            <button type="submit" class="btn btn--secondary">Switch Season</button>
        </div>
    </form>

    <?php if ($wizardStep === 1): ?>
        <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=1" class="auth-form card--spaced" id="draft-setup-form">
            <input type="hidden" name="action" value="save_upcoming_setup">
            <input type="hidden" name="season_year" value="<?= (int) $seasonYear ?>">

            <div class="draft-setup-grid">
                <label class="auth-field">
                    <span class="auth-field__label">Rounds</span>
                    <input class="auth-field__input" type="number" name="round_count" min="1" max="20" value="<?= (int) $roundCount ?>" required>
                </label>

                <?php if ($leagueTeamNames === []): ?>
                    <p class="home-note">Team list is currently unavailable. Please ensure Yahoo sync is connected, then refresh this page.</p>
                <?php else: ?>
                    <div class="draft-board-wrap">
                        <table class="history-table draft-assignment-table">
                            <thead>
                            <tr>
                                <th>Team</th>
                                <th>Pick #</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $pickOptions = range(1, count($leagueTeamNames)); ?>
                            <?php foreach ($leagueTeamNames as $teamName): ?>
                                <?php $selectedSlot = (int) ($slotByTeam[(string) $teamName] ?? 0); ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars((string) $teamName) ?>
                                        <input type="hidden" name="team_name[]" value="<?= htmlspecialchars((string) $teamName) ?>">
                                    </td>
                                    <td>
                                        <select class="auth-field__input js-slot-select" name="slot_no[]" required>
                                            <option value="">Choose pick</option>
                                            <?php foreach ($pickOptions as $pickNo): ?>
                                                <option value="<?= (int) $pickNo ?>"<?= $selectedSlot === (int) $pickNo ? ' selected' : '' ?>>#<?= (int) $pickNo ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="draft-setup-guard" id="draft-setup-guard" role="status" aria-live="polite"></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="auth-actions">
                <button type="submit" class="btn">Save Step 1</button>
                <a class="btn btn--secondary" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=2">Next: Step 2</a>
            </div>
        </form>
    <?php elseif ($wizardStep === 2): ?>
        <?php if ($boardRows === []): ?>
            <p class="home-note">No default order configured yet for <?= (int) $seasonYear ?>. Complete step 1 first.</p>
            <p><a class="btn btn--secondary" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=1">Back to Step 1</a></p>
        <?php else: ?>
            <div class="draft-board-wrap card--spaced">
                <?php if ($overrides === []): ?>
                    <p class="home-note">No traded picks recorded yet for this draft.</p>
                <?php else: ?>
                    <table class="history-table draft-board-table">
                        <thead>
                        <tr>
                            <th>Round</th>
                            <th>Slot</th>
                            <th>From Team</th>
                            <th>Current Owner</th>
                            <th>Note</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overrides as $override): ?>
                            <tr>
                                <td>#<?= (int) ($override['round_no'] ?? 0) ?></td>
                                <td><?= (int) ($override['slot_no'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string) ($override['from_team_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($override['current_owner_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($override['note'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=2" class="auth-form card--spaced">
                <input type="hidden" name="action" value="save_pick_override">
                <input type="hidden" name="season_year" value="<?= (int) $seasonYear ?>">

                <h3>Add or Update Traded Pick</h3>

                <div class="draft-form-grid">
                    <label class="auth-field">
                        <span class="auth-field__label">Round</span>
                        <input class="auth-field__input" type="number" name="round_no" min="1" max="20" required>
                    </label>

                    <label class="auth-field">
                        <span class="auth-field__label">From Team (default owner)</span>
                        <select class="auth-field__input" name="from_team_name" required>
                            <option value="">Choose team</option>
                            <?php foreach ($leagueTeamNames as $teamName): ?>
                                <option value="<?= htmlspecialchars((string) $teamName) ?>"><?= htmlspecialchars((string) $teamName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="auth-field">
                        <span class="auth-field__label">Current Owner Team</span>
                        <select class="auth-field__input" name="current_owner_name" required>
                            <option value="">Choose team</option>
                            <?php foreach ($leagueTeamNames as $teamName): ?>
                                <option value="<?= htmlspecialchars((string) $teamName) ?>"><?= htmlspecialchars((string) $teamName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="auth-field">
                        <span class="auth-field__label">Note (optional)</span>
                        <input class="auth-field__input" type="text" name="note" maxlength="255" placeholder="Trade details / reference">
                    </label>
                </div>

                <div class="auth-actions">
                    <button type="submit" class="btn">Save Traded Pick</button>
                </div>
            </form>

            <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=2" class="auth-form card--spaced">
                <input type="hidden" name="action" value="delete_pick_override">
                <input type="hidden" name="season_year" value="<?= (int) $seasonYear ?>">

                <h3>Remove Traded Pick Entry</h3>

                <div class="draft-form-grid draft-form-grid--compact">
                    <label class="auth-field">
                        <span class="auth-field__label">Round</span>
                        <input class="auth-field__input" type="number" name="round_no" min="1" max="20" required>
                    </label>
                    <label class="auth-field">
                        <span class="auth-field__label">From Team</span>
                        <select class="auth-field__input" name="from_team_name" required>
                            <option value="">Choose team</option>
                            <?php foreach ($leagueTeamNames as $teamName): ?>
                                <option value="<?= htmlspecialchars((string) $teamName) ?>"><?= htmlspecialchars((string) $teamName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="auth-actions">
                    <button type="submit" class="btn btn--secondary">Delete Entry</button>
                    <a class="btn" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=3">Next: Step 3 Generate Table</a>
                </div>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <div class="draft-board-wrap card--spaced">
            <?php if ($boardRows === []): ?>
                <p class="home-note">No default order configured for <?= (int) $seasonYear ?> yet. Complete step 1 first.</p>
            <?php else: ?>
                <table class="history-table draft-board-table draft-board-table--matrix">
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
                                <?php $isChanged = !empty($cell['is_changed']); ?>
                                <?php $tradeNote = trim((string) ($cell['note'] ?? '')); ?>
                                <?php $tradeTitle = $tradeNote !== '' ? $tradeNote : 'Traded pick'; ?>
                                <td class="<?= $isChanged ? 'draft-cell--changed' : 'draft-cell--default' ?>"<?= $isChanged ? ' title="' . htmlspecialchars($tradeTitle) . '"' : '' ?>>
                                    <?= htmlspecialchars((string) ($cell['owner'] ?? '')) ?>
                                    <?php if ($isChanged): ?>
                                        <span class="insight-sub" title="<?= htmlspecialchars($tradeTitle) ?>">(trade)</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="auth-actions card--spaced">
            <a class="btn btn--secondary" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=1">Back to Step 1</a>
            <a class="btn btn--secondary" href="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=2">Back to Step 2</a>
        </div>
    <?php endif; ?>
</section>

<?php if ($wizardStep === 1 && $leagueTeamNames !== []): ?>
    <script>
        (function () {
            var form = document.getElementById('draft-setup-form');
            if (!form) {
                return;
            }

            var selects = Array.prototype.slice.call(form.querySelectorAll('.js-slot-select'));
            var guard = document.getElementById('draft-setup-guard');
            var saveButton = form.querySelector('button[type="submit"]');

            function renderState(message, state) {
                if (!guard) {
                    return;
                }
                guard.textContent = message;
                guard.classList.remove('is-error', 'is-ok', 'is-warn');
                guard.classList.add(state);
            }

            function validateAssignments() {
                var seen = Object.create(null);
                var duplicates = Object.create(null);
                var missingCount = 0;

                selects.forEach(function (select) {
                    var value = String(select.value || '').trim();
                    select.classList.remove('draft-assignment-select--duplicate');

                    if (value === '') {
                        missingCount += 1;
                        return;
                    }

                    if (seen[value]) {
                        duplicates[value] = true;
                    }
                    seen[value] = true;
                });

                var duplicateList = Object.keys(duplicates).sort(function (a, b) {
                    return Number(a) - Number(b);
                });

                if (duplicateList.length > 0) {
                    selects.forEach(function (select) {
                        var value = String(select.value || '').trim();
                        if (value !== '' && duplicates[value]) {
                            select.classList.add('draft-assignment-select--duplicate');
                        }
                    });

                    if (saveButton) {
                        saveButton.disabled = true;
                    }
                    renderState(
                        'Duplicate pick numbers detected: #' + duplicateList.join(', #') + '. Each team must have a unique pick number.',
                        'is-error'
                    );
                    return false;
                }

                if (missingCount > 0) {
                    if (saveButton) {
                        saveButton.disabled = true;
                    }
                    renderState(
                        'Pick numbers are missing for ' + missingCount + ' team' + (missingCount === 1 ? '' : 's') + '.',
                        'is-warn'
                    );
                    return false;
                }

                if (saveButton) {
                    saveButton.disabled = false;
                }
                renderState('Order looks valid. Ready to save.', 'is-ok');
                return true;
            }

            selects.forEach(function (select) {
                select.addEventListener('change', validateAssignments);
            });

            form.addEventListener('submit', function (event) {
                if (!validateAssignments()) {
                    event.preventDefault();
                }
            });

            validateAssignments();
        })();
    </script>
<?php endif; ?>

<section class="card card--spaced">
    <h2>Future Draft Pick Trades (<?= (int) ($seasonYear + 1) ?>+)</h2>
    <p>For seasons where default order is unknown, track only traded picks in this ledger.</p>

    <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=<?= (int) $wizardStep ?>" class="auth-form">
        <input type="hidden" name="action" value="add_future_trade">

        <div class="draft-form-grid">
            <label class="auth-field">
                <span class="auth-field__label">Season</span>
                <input class="auth-field__input" type="number" name="season_year" min="2020" max="2100" required value="<?= (int) ($seasonYear + 1) ?>">
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Round (optional)</span>
                <input class="auth-field__input" type="number" name="round_no" min="1" max="20" placeholder="Unknown yet">
            </label>

            <label class="auth-field">
                <span class="auth-field__label">From Team</span>
                <?php if ($leagueTeamNames !== []): ?>
                    <select class="auth-field__input" name="from_team_name" required>
                        <option value="">Choose team</option>
                        <?php foreach ($leagueTeamNames as $teamName): ?>
                            <option value="<?= htmlspecialchars((string) $teamName) ?>"><?= htmlspecialchars((string) $teamName) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input class="auth-field__input" type="text" name="from_team_name" maxlength="120" required>
                <?php endif; ?>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Current Owner</span>
                <?php if ($leagueTeamNames !== []): ?>
                    <select class="auth-field__input" name="current_owner_name" required>
                        <option value="">Choose team</option>
                        <?php foreach ($leagueTeamNames as $teamName): ?>
                            <option value="<?= htmlspecialchars((string) $teamName) ?>"><?= htmlspecialchars((string) $teamName) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input class="auth-field__input" type="text" name="current_owner_name" maxlength="120" required>
                <?php endif; ?>
            </label>

            <label class="auth-field">
                <span class="auth-field__label">Note (optional)</span>
                <input class="auth-field__input" type="text" name="note" maxlength="255" placeholder="Trade context">
            </label>
        </div>

        <div class="auth-actions">
            <button type="submit" class="btn">Add Future Pick Trade</button>
        </div>
    </form>

    <div class="draft-board-wrap card--spaced">
        <?php if ($futureTrades === []): ?>
            <p class="home-note">No future trades logged yet.</p>
        <?php else: ?>
            <form method="post" action="/index.php?r=comish&amp;season=<?= (int) $seasonYear ?>&amp;step=<?= (int) $wizardStep ?>">
                <input type="hidden" name="action" value="update_future_trade">

            <table class="history-table draft-board-table">
                <thead>
                <tr>
                    <th>Season</th>
                    <th>Round</th>
                    <th>Pick Originally Owned By</th>
                    <th>Current Owner</th>
                    <th>Note</th>
                    <th>Logged At</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($futureTrades as $trade): ?>
                    <?php $tradeId = (int) ($trade['id'] ?? 0); ?>
                    <tr>
                        <td>
                            <input class="auth-field__input" type="number" name="season_year[<?= (int) $tradeId ?>]" min="2020" max="2100" required value="<?= (int) ($trade['season_year'] ?? 0) ?>">
                        </td>
                        <td>
                            <?php if (isset($trade['round_no']) && $trade['round_no'] !== null): ?>
                                <input class="auth-field__input" type="number" name="round_no[<?= (int) $tradeId ?>]" min="1" max="20" value="<?= (int) $trade['round_no'] ?>" placeholder="TBD">
                            <?php else: ?>
                                <input class="auth-field__input" type="number" name="round_no[<?= (int) $tradeId ?>]" min="1" max="20" value="" placeholder="TBD">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($leagueTeamNames !== []): ?>
                                <select class="auth-field__input" name="from_team_name[<?= (int) $tradeId ?>]" required>
                                    <option value="">Choose team</option>
                                    <?php foreach ($leagueTeamNames as $teamName): ?>
                                        <option value="<?= htmlspecialchars((string) $teamName) ?>"<?= strcasecmp((string) $teamName, (string) ($trade['from_team_name'] ?? '')) === 0 ? ' selected' : '' ?>><?= htmlspecialchars((string) $teamName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="auth-field__input" type="text" name="from_team_name[<?= (int) $tradeId ?>]" maxlength="120" required value="<?= htmlspecialchars((string) ($trade['from_team_name'] ?? '')) ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($leagueTeamNames !== []): ?>
                                <select class="auth-field__input" name="current_owner_name[<?= (int) $tradeId ?>]" required>
                                    <option value="">Choose team</option>
                                    <?php foreach ($leagueTeamNames as $teamName): ?>
                                        <option value="<?= htmlspecialchars((string) $teamName) ?>"<?= strcasecmp((string) $teamName, (string) ($trade['current_owner_name'] ?? '')) === 0 ? ' selected' : '' ?>><?= htmlspecialchars((string) $teamName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="auth-field__input" type="text" name="current_owner_name[<?= (int) $tradeId ?>]" maxlength="120" required value="<?= htmlspecialchars((string) ($trade['current_owner_name'] ?? '')) ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <input class="auth-field__input" type="text" name="note[<?= (int) $tradeId ?>]" maxlength="255" value="<?= htmlspecialchars((string) ($trade['note'] ?? '')) ?>" placeholder="Trade context">
                        </td>
                        <td><?= htmlspecialchars((string) ($trade['created_at'] ?? '')) ?></td>
                        <td>
                            <button class="btn btn--secondary" type="submit" name="trade_id" value="<?= (int) $tradeId ?>">Update</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </form>
        <?php endif; ?>
    </div>
</section>
