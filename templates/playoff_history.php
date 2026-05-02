<?php

declare(strict_types=1);

/**
 * playoff_history.php
 *
 * Displays playoff bracket trees for all seasons from oldest to newest.
 * Each year's bracket shows quarterfinals, semifinals, and finals with results.
 */

?>
<section class="page-header">
    <h1><?= htmlspecialchars($title ?? 'Playoff History') ?></h1>
</section>

<?php if (!empty($debugPlayoff)): ?>
    <div class="playoff-state playoff-state--info" style="margin-bottom: 1rem;">
        Debug mode is ON. Diagnostics are shown below.
        <br>
        <a href="/index.php?r=playoff-history">Disable debug mode</a>
    </div>

    <?php if (!empty($playoffDebug)): ?>
        <div class="history-table-wrap" style="margin-bottom: 1.25rem;">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Season</th>
                        <th>Status</th>
                        <th>League Key</th>
                        <th>Playoff Weeks</th>
                        <th>Raw Matchups</th>
                        <th>Used Matchups</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($playoffDebug as $season => $dbg): ?>
                        <?php
                            $startWeek = (int) ($dbg['playoff_start_week'] ?? 0);
                            $endWeek = (int) ($dbg['end_week'] ?? 0);
                            $errors = (array) ($dbg['errors'] ?? []);
                        ?>
                        <tr>
                            <td><?= (int) $season ?></td>
                            <td><?= htmlspecialchars((string) ($dbg['status'] ?? 'unknown')) ?></td>
                            <td><?= htmlspecialchars((string) ($dbg['league_key'] ?? '')) ?></td>
                            <td><?= $startWeek ?> - <?= $endWeek ?></td>
                            <td><?= (int) ($dbg['total_matchups_seen'] ?? 0) ?></td>
                            <td><?= (int) ($dbg['total_playoff_matchups'] ?? 0) ?></td>
                            <td>
                                <?php if ($errors !== []): ?>
                                    <?php foreach ($errors as $error): ?>
                                        <div><?= htmlspecialchars((string) $error) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="playoff-state playoff-state--info" style="margin-bottom: 1rem;">
        Need diagnostics? Open
        <a href="/index.php?r=playoff-history&amp;debug_playoff=1">Playoff History debug mode</a>.
    </div>
<?php endif; ?>

<div class="playoff-history">
    <?php if (empty($playoffBrackets)): ?>
        <div class="playoff-state playoff-state--info">
            No playoff data available.
        </div>
    <?php else: ?>
        <?php foreach ($playoffBrackets as $season => $bracket): ?>
            <?php
                $hasData = !empty($bracket['rounds']);
                $champion = $bracket['champion'] ?? null;
                $runnerUp = $bracket['runner_up'] ?? null;
            ?>
            <section class="playoff-season" data-season="<?= (int) $season ?>">
                <div class="playoff-season__header">
                    <h2 class="playoff-season__title">
                        <?= (int) $season ?> Season
                        <?php if ($champion): ?>
                            <span class="playoff-season__champion">
                                🏆 <?= htmlspecialchars((string) $champion) ?>
                            </span>
                        <?php endif; ?>
                    </h2>
                </div>

                <?php if ($hasData): ?>
                    <div class="playoff-bracket">
                        <?php foreach ($bracket['rounds'] as $round): ?>
                            <div class="playoff-round" data-round="<?= (int) $round['round_no'] ?>">
                                <div class="playoff-round__label">
                                    <?= htmlspecialchars((string) $round['round_label']) ?>
                                </div>

                                <div class="playoff-round__matchups">
                                    <?php foreach ($round['matchups'] as $matchup): ?>
                                        <?php
                                            $team1 = $matchup['team_1'] ?? '';
                                            $team2 = $matchup['team_2'] ?? '';
                                            $score1 = $matchup['team_1_score'];
                                            $score2 = $matchup['team_2_score'];
                                            $winner = $matchup['winner'];
                                            $hasScores = $score1 !== null && $score2 !== null;
                                            $team1Won = $hasScores && $score1 > $score2;
                                            $team2Won = $hasScores && $score2 > $score1;
                                        ?>
                                        <div class="playoff-matchup">
                                            <div class="playoff-matchup__teams">
                                                <div class="playoff-team <?= $team1Won ? 'is-winner' : '' ?>">
                                                    <span class="playoff-team__name">
                                                        <?= htmlspecialchars((string) $team1) ?>
                                                    </span>
                                                    <?php if ($hasScores): ?>
                                                        <span class="playoff-team__score">
                                                            <?= htmlspecialchars((string) round((float) $score1, 2)) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="playoff-matchup__divider">
                                                    <?php if ($hasScores && $team1Won): ?>
                                                        <span class="playoff-matchup__vs">vs</span>
                                                    <?php elseif ($hasScores && $team2Won): ?>
                                                        <span class="playoff-matchup__vs">vs</span>
                                                    <?php else: ?>
                                                        <span class="playoff-matchup__vs">vs</span>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="playoff-team <?= $team2Won ? 'is-winner' : '' ?>">
                                                    <span class="playoff-team__name">
                                                        <?= htmlspecialchars((string) $team2) ?>
                                                    </span>
                                                    <?php if ($hasScores): ?>
                                                        <span class="playoff-team__score">
                                                            <?= htmlspecialchars((string) round((float) $score2, 2)) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($runnerUp): ?>
                        <div class="playoff-season__result">
                            <span class="playoff-result-label">Runner-up:</span>
                            <span class="playoff-result-value"><?= htmlspecialchars((string) $runnerUp) ?></span>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="playoff-state playoff-state--warning">
                        No playoff data recorded for <?= (int) $season ?>.
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
