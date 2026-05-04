<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;
use Throwable;

class HeadToHeadHistoryService
{
    private const EXCLUDED_TEAM_NAME_PARTS = ['mordlustig', 'tru. crew', 'tru crew'];

    private YahooApiClient $api;
    private string $currentLeagueKey;
    private int $startSeason;

    public function __construct(YahooApiClient $api, string $currentLeagueKey, int $startSeason = 2018)
    {
        $this->api = $api;
        $this->currentLeagueKey = $currentLeagueKey;
        $this->startSeason = $startSeason;
    }

    /**
     * @return array{
     *   seasons: int[],
     *   teams: array<int, array{id: string, team_name: string, logo_url: string}>,
     *   matrix: array<string, array<string, array{w: int, l: int, d: int, games: int}>>,
     *   total_games: int,
     *   errors: array<int, string>
     * }
     */
    public function getHeadToHeadMatrix(bool $includeDebug = false): array
    {
        $leagues = $this->collectLeagueChain();

        if ($leagues === []) {
            return [
                'seasons' => [],
                'teams' => [],
                'matrix' => [],
                'total_games' => 0,
                'errors' => [],
            ];
        }

        usort($leagues, static fn(array $a, array $b): int => $b['season'] <=> $a['season']);

        $teamDirectoryById = [];
        $fallbackTeamsById = [];
        $teamLookupBySeason = [];
        $pairRecords = [];
        $errors = [];
        $totalGames = 0;
        $debug = [
            'outcome_sources' => [
                'is_tied' => 0,
                'winner_team_key' => 0,
                'score_fallback' => 0,
                'skipped_no_outcome' => 0,
                'skipped_unplayed_0_0' => 0,
            ],
            'draw_samples' => [],
        ];

        foreach ($leagues as $league) {
            $season = (int) ($league['season'] ?? 0);
            $leagueKey = (string) ($league['league_key'] ?? '');

            if ($season <= 0 || $leagueKey === '') {
                continue;
            }

            try {
                $standingsRaw = $this->api->get('league/' . $leagueKey . '/standings');
                $seasonTeams = $this->parseStandingsDirectory($standingsRaw);

                foreach ($seasonTeams as $team) {
                    $identity = (string) $team['identity'];
                    $name = (string) $team['name'];
                    $normalized = $this->normalizeName($name);
                    $teamLookupBySeason[$season][$normalized] = $identity;

                    if (!isset($teamDirectoryById[$identity])) {
                        $teamDirectoryById[$identity] = [
                            'team_name' => $name,
                            'logo_url' => (string) $team['logo_url'],
                            'last_seen_season' => $season,
                        ];
                    } elseif ($season >= (int) ($teamDirectoryById[$identity]['last_seen_season'] ?? 0)) {
                        $teamDirectoryById[$identity]['team_name'] = $name;
                        $teamDirectoryById[$identity]['logo_url'] = (string) $team['logo_url'];
                        $teamDirectoryById[$identity]['last_seen_season'] = $season;
                    }
                }
            } catch (Throwable $e) {
                // Keep going; we may still parse scoreboards for this season.
            }

            try {
                $bounds = $this->determineSeasonWeekBounds($leagueKey);
                $endWeek = (int) ($bounds['end_week'] ?? 0);
                $playoffStartWeek = (int) ($bounds['playoff_start_week'] ?? 0);
                $seenSeasonMatchups = [];

                if ($endWeek <= 0) {
                    continue;
                }

                for ($week = 1; $week <= $endWeek; $week++) {
                    $scoreboardRaw = $this->getScoreboardForWeek($leagueKey, $week);
                    $weekMatchups = $this->dedupeMatchups($this->parseScoreboardWeek($scoreboardRaw, $week));

                    foreach ($weekMatchups as $matchup) {
                        if (($matchup['is_playoffs'] ?? false) || ($matchup['is_consolation'] ?? false)) {
                            continue;
                        }

                        if ($playoffStartWeek > 0 && $week >= $playoffStartWeek) {
                            continue;
                        }

                        $teamANameRaw = (string) ($matchup['team_1'] ?? '');
                        $teamBNameRaw = (string) ($matchup['team_2'] ?? '');
                        $teamAScore = $matchup['team_1_score'] ?? null;
                        $teamBScore = $matchup['team_2_score'] ?? null;
                        $teamAKey = (string) ($matchup['team_1_key'] ?? '');
                        $teamBKey = (string) ($matchup['team_2_key'] ?? '');
                        $winnerTeamKey = (string) ($matchup['winner_team_key'] ?? '');
                        $isTied = (bool) ($matchup['is_tied'] ?? false);
                        $matchupId = trim((string) ($matchup['matchup_id'] ?? ''));

                        if ($teamANameRaw === '' || $teamBNameRaw === '') {
                            continue;
                        }

                        $dedupeKey = $matchupId !== ''
                            ? 'id:' . $matchupId
                            : 'wk:' . $week . '|pair:' . strtolower($teamANameRaw) . '|' . strtolower($teamBNameRaw);

                        if (isset($seenSeasonMatchups[$dedupeKey])) {
                            continue;
                        }
                        $seenSeasonMatchups[$dedupeKey] = true;

                        $teamA = $this->resolveCanonicalTeam($teamANameRaw, $season, $teamLookupBySeason, $teamDirectoryById);
                        $teamB = $this->resolveCanonicalTeam($teamBNameRaw, $season, $teamLookupBySeason, $teamDirectoryById);

                        if ($teamA === null || $teamB === null) {
                            continue;
                        }

                        if ($teamA['id'] === $teamB['id']) {
                            continue;
                        }

                        if (!isset($teamDirectoryById[$teamA['id']])) {
                            $fallbackTeamsById[$teamA['id']] = [
                                'team_name' => (string) $teamA['team_name'],
                                'logo_url' => '',
                            ];
                        }

                        if (!isset($teamDirectoryById[$teamB['id']])) {
                            $fallbackTeamsById[$teamB['id']] = [
                                'team_name' => (string) $teamB['team_name'],
                                'logo_url' => '',
                            ];
                        }

                        $pairKey = $this->buildPairKey($teamA['id'], $teamB['id']);
                        if (!isset($pairRecords[$pairKey])) {
                            $pairRecords[$pairKey] = [
                                'a_id' => $teamA['id'],
                                'b_id' => $teamB['id'],
                                'a_w' => 0,
                                'a_l' => 0,
                                'a_d' => 0,
                            ];
                        }

                        $storedAId = (string) $pairRecords[$pairKey]['a_id'];
                        $storedBId = (string) $pairRecords[$pairKey]['b_id'];

                        $winnerId = null;
                        if ($winnerTeamKey !== '' && $teamAKey !== '' && $winnerTeamKey === $teamAKey) {
                            $winnerId = (string) $teamA['id'];
                        } elseif ($winnerTeamKey !== '' && $teamBKey !== '' && $winnerTeamKey === $teamBKey) {
                            $winnerId = (string) $teamB['id'];
                        }

                        if ($isTied) {
                            $debug['outcome_sources']['is_tied']++;
                            $pairRecords[$pairKey]['a_d']++;
                            if (count($debug['draw_samples']) < 30) {
                                $debug['draw_samples'][] = [
                                    'season' => $season,
                                    'week' => $week,
                                    'team_1' => $teamANameRaw,
                                    'team_2' => $teamBNameRaw,
                                    'team_1_score' => $teamAScore,
                                    'team_2_score' => $teamBScore,
                                    'winner_team_key' => $winnerTeamKey,
                                    'source' => 'is_tied',
                                ];
                            }
                        } elseif ($winnerId !== null) {
                            $debug['outcome_sources']['winner_team_key']++;
                            if ($winnerId === $storedAId) {
                                $pairRecords[$pairKey]['a_w']++;
                            } elseif ($winnerId === $storedBId) {
                                $pairRecords[$pairKey]['a_l']++;
                            } else {
                                continue;
                            }
                        } elseif ((is_float($teamAScore) || is_int($teamAScore)) && (is_float($teamBScore) || is_int($teamBScore))) {
                            $debug['outcome_sources']['score_fallback']++;
                            $aScore = (float) $teamAScore;
                            $bScore = (float) $teamBScore;

                            // Yahoo returns many future scheduled matchups as 0-0 with no winner/tie marker.
                            // Those are unplayed placeholders and must not count as draws.
                            if ($winnerTeamKey === '' && !$isTied && $aScore == 0.0 && $bScore == 0.0) {
                                $debug['outcome_sources']['skipped_unplayed_0_0']++;
                                continue;
                            }

                            $storedAScore = $storedAId === $teamA['id'] ? $aScore : $bScore;
                            $storedBScore = $storedAId === $teamA['id'] ? $bScore : $aScore;

                            if ($storedAScore > $storedBScore) {
                                $pairRecords[$pairKey]['a_w']++;
                            } elseif ($storedAScore < $storedBScore) {
                                $pairRecords[$pairKey]['a_l']++;
                            } else {
                                $pairRecords[$pairKey]['a_d']++;
                                if (count($debug['draw_samples']) < 30) {
                                    $debug['draw_samples'][] = [
                                        'season' => $season,
                                        'week' => $week,
                                        'team_1' => $teamANameRaw,
                                        'team_2' => $teamBNameRaw,
                                        'team_1_score' => $teamAScore,
                                        'team_2_score' => $teamBScore,
                                        'winner_team_key' => $winnerTeamKey,
                                        'source' => 'score_fallback_equal',
                                    ];
                                }
                            }
                        } else {
                            $debug['outcome_sources']['skipped_no_outcome']++;
                            continue;
                        }

                        $totalGames++;
                    }
                }
            } catch (Throwable $e) {
                $errors[$season] = $e->getMessage();
            }
        }

        $teamRows = [];
        foreach ($teamDirectoryById as $id => $team) {
            $name = (string) ($team['team_name'] ?? '');
            if ($name === '' || $this->isExcludedTeamName($name)) {
                continue;
            }

            $teamRows[] = [
                'id' => (string) $id,
                'team_name' => $name,
                'logo_url' => (string) ($team['logo_url'] ?? ''),
            ];
        }

        foreach ($fallbackTeamsById as $id => $team) {
            if (isset($teamDirectoryById[$id])) {
                continue;
            }

            $name = (string) ($team['team_name'] ?? '');
            if ($name === '' || $this->isExcludedTeamName($name)) {
                continue;
            }

            $teamRows[] = [
                'id' => (string) $id,
                'team_name' => $name,
                'logo_url' => (string) ($team['logo_url'] ?? ''),
            ];
        }

        usort($teamRows, static function (array $a, array $b): int {
            return strcasecmp((string) $a['team_name'], (string) $b['team_name']);
        });

        $teamIds = array_map(static fn(array $t): string => (string) $t['id'], $teamRows);
        $matrix = [];

        foreach ($teamIds as $teamId) {
            $matrix[$teamId] = [];
            foreach ($teamIds as $oppId) {
                if ($teamId === $oppId) {
                    continue;
                }

                $matrix[$teamId][$oppId] = ['w' => 0, 'l' => 0, 'd' => 0, 'games' => 0];
            }
        }

        foreach ($pairRecords as $pair) {
            $aId = (string) ($pair['a_id'] ?? '');
            $bId = (string) ($pair['b_id'] ?? '');

            if (!isset($matrix[$aId][$bId]) || !isset($matrix[$bId][$aId])) {
                continue;
            }

            $aW = (int) ($pair['a_w'] ?? 0);
            $aL = (int) ($pair['a_l'] ?? 0);
            $aD = (int) ($pair['a_d'] ?? 0);
            $games = $aW + $aL + $aD;

            $matrix[$aId][$bId] = [
                'w' => $aW,
                'l' => $aL,
                'd' => $aD,
                'games' => $games,
            ];

            $matrix[$bId][$aId] = [
                'w' => $aL,
                'l' => $aW,
                'd' => $aD,
                'games' => $games,
            ];
        }

        $seasons = array_values(array_map(static fn(array $l): int => (int) $l['season'], $leagues));
        rsort($seasons);

        $result = [
            'seasons' => array_values(array_unique($seasons)),
            'teams' => $teamRows,
            'matrix' => $matrix,
            'total_games' => $totalGames,
            'errors' => $errors,
        ];

        if ($includeDebug) {
            $result['debug'] = $debug;
        }

        return $result;
    }

    /**
     * Return parsed matchup diagnostics for a single season/week.
     *
     * @return array<string, mixed>
     */
    public function getWeekDebug(int $season, int $week): array
    {
        $leagues = $this->collectLeagueChain();
        if ($leagues === []) {
            return ['error' => 'No leagues found in renewal chain.'];
        }

        $leagueForSeason = null;
        foreach ($leagues as $league) {
            if ((int) ($league['season'] ?? 0) === $season) {
                $leagueForSeason = $league;
                break;
            }
        }

        if (!is_array($leagueForSeason)) {
            return ['error' => 'Season not found in league chain.', 'requested_season' => $season];
        }

        $leagueKey = (string) ($leagueForSeason['league_key'] ?? '');
        if ($leagueKey === '') {
            return ['error' => 'Resolved season has empty league key.', 'requested_season' => $season];
        }

        $raw = $this->getScoreboardForWeek($leagueKey, $week);
        $parsed = $this->parseScoreboardWeek($raw, $week);

        return [
            'season' => $season,
            'week' => $week,
            'league_key' => $leagueKey,
            'matchup_count' => count($parsed),
            'parsed_matchups' => $parsed,
        ];
    }

    /** @return array<int, array{league_key: string, season: int}> */
    private function collectLeagueChain(): array
    {
        $chain = [];
        $seen = [];
        $key = $this->currentLeagueKey;

        for ($i = 0; $i < 20; $i++) {
            if ($key === '' || isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;

            try {
                $raw = $this->api->get('league/' . $key);
                $meta = $this->parseLeagueMeta($raw);

                $season = (int) ($meta['season'] ?? 0);
                if ($season === 0) {
                    break;
                }

                if ($season >= $this->startSeason) {
                    $chain[] = [
                        'league_key' => (string) ($meta['league_key'] ?? $key),
                        'season' => $season,
                    ];
                }

                if ($season <= $this->startSeason) {
                    break;
                }

                $renewedKey = $this->buildRenewedLeagueKey((string) ($meta['renew'] ?? ''));
                if ($renewedKey === null) {
                    break;
                }

                $key = $renewedKey;
            } catch (Throwable $e) {
                break;
            }
        }

        return $chain;
    }

    /** @return array{playoff_start_week: int, end_week: int} */
    private function determineSeasonWeekBounds(string $leagueKey): array
    {
        $playoffStartWeek = 0;
        $endWeek = 0;

        try {
            $leagueRaw = $this->api->get('league/' . $leagueKey);
            $meta = $this->parseLeagueMeta($leagueRaw);
            $playoffStartWeek = (int) ($meta['playoff_start_week'] ?? 0);
            $endWeek = (int) ($meta['end_week'] ?? 0);
        } catch (Throwable $e) {
            // Fallback to settings below.
        }

        if ($playoffStartWeek <= 0 || $endWeek <= 0) {
            try {
                $settingsRaw = $this->api->get('league/' . $leagueKey . '/settings');
                $settings = $this->parseSettings($settingsRaw);
                if ($playoffStartWeek <= 0) {
                    $playoffStartWeek = (int) ($settings['playoff_start_week'] ?? 0);
                }
                if ($endWeek <= 0) {
                    $endWeek = (int) ($settings['end_week'] ?? 0);
                }
            } catch (Throwable $e) {
                // Keep any values from league meta.
            }
        }

        return [
            'playoff_start_week' => $playoffStartWeek,
            'end_week' => $endWeek,
        ];
    }

    /** @return array<string, mixed> */
    private function parseLeagueMeta(array $raw): array
    {
        $meta = $raw['fantasy_content']['league'][0] ?? [];

        if (!is_array($meta)) {
            throw new RuntimeException('Unexpected Yahoo API response structure for league meta.');
        }

        $playoffStartWeek = $this->findIntByKeyRecursively($meta, 'playoff_start_week')
            ?? $this->findIntByKeyRecursively($raw, 'playoff_start_week')
            ?? 0;

        $endWeek = $this->findIntByKeyRecursively($meta, 'end_week')
            ?? $this->findIntByKeyRecursively($meta, 'num_weeks')
            ?? $this->findIntByKeyRecursively($raw, 'end_week')
            ?? $this->findIntByKeyRecursively($raw, 'num_weeks')
            ?? 0;

        return [
            'league_key' => (string) ($meta['league_key'] ?? ''),
            'season' => (string) ($meta['season'] ?? ''),
            'renew' => (string) ($meta['renew'] ?? ''),
            'playoff_start_week' => $playoffStartWeek,
            'end_week' => $endWeek,
        ];
    }

    /** @return array{playoff_start_week: int, end_week: int} */
    private function parseSettings(array $raw): array
    {
        $settings = $raw['fantasy_content']['league'][1]['settings'] ?? null;

        if (!is_array($settings)) {
            throw new RuntimeException('Unexpected Yahoo API response structure for league settings.');
        }

        $playoffStartWeek = $this->findIntByKeyRecursively($settings, 'playoff_start_week')
            ?? $this->findIntByKeyRecursively($raw, 'playoff_start_week')
            ?? 0;

        $endWeek = $this->findIntByKeyRecursively($settings, 'end_week')
            ?? $this->findIntByKeyRecursively($settings, 'num_weeks')
            ?? $this->findIntByKeyRecursively($raw, 'end_week')
            ?? $this->findIntByKeyRecursively($raw, 'num_weeks')
            ?? 0;

        return [
            'playoff_start_week' => $playoffStartWeek,
            'end_week' => $endWeek,
        ];
    }

    /**
     * @return array<int, array{
     *   matchup_id: string,
     *   team_1: string,
     *   team_2: string,
    *   team_1_key: string,
    *   team_2_key: string,
     *   team_1_score: ?float,
     *   team_2_score: ?float,
    *   winner_team_key: string,
    *   is_tied: bool,
     *   is_playoffs: bool,
     *   is_consolation: bool
     * }>
     */
    private function parseScoreboardWeek(array $raw, int $targetWeek): array
    {
        $scoreboard = $raw['fantasy_content']['league'][1]['scoreboard'] ?? null;
        if (!is_array($scoreboard)) {
            return [];
        }

        $matchupsRaw = $scoreboard[0]['matchups'] ?? $scoreboard['0']['matchups'] ?? $scoreboard['matchups'] ?? null;
        if (!is_array($matchupsRaw)) {
            return [];
        }

        $matchups = [];

        foreach ($matchupsRaw as $k => $entry) {
            if ($k === 'count') {
                continue;
            }

            $matchupData = $entry['matchup'] ?? $entry;
            if (!is_array($matchupData)) {
                continue;
            }

            $weekStart = $this->toInt($matchupData['week_start'] ?? null)
                ?? $this->toInt($matchupData['week'] ?? null);
            $weekEnd = $this->toInt($matchupData['week_end'] ?? null) ?? $weekStart;

            if ($weekStart === null || $weekEnd === null || $targetWeek < $weekStart || $targetWeek > $weekEnd) {
                continue;
            }

            $teams = $this->parseMatchupTeams($matchupData);
            if (count($teams) !== 2) {
                continue;
            }

            $team1Name = (string) ($teams[0]['name'] ?? '');
            $team2Name = (string) ($teams[1]['name'] ?? '');
            $team1Key = (string) ($teams[0]['team_key'] ?? '');
            $team2Key = (string) ($teams[1]['team_key'] ?? '');
            if ($team1Name === '' || $team2Name === '') {
                continue;
            }

            $winnerTeamKey = trim((string) ($matchupData['winner_team_key'] ?? ''));
            $isTied = $this->toBool($matchupData['is_tied'] ?? false);

            $matchups[] = [
                'matchup_id' => (string) ($matchupData['matchup_id'] ?? ''),
                'team_1' => $team1Name,
                'team_2' => $team2Name,
                'team_1_key' => $team1Key,
                'team_2_key' => $team2Key,
                'team_1_score' => $teams[0]['points'] ?? null,
                'team_2_score' => $teams[1]['points'] ?? null,
                'winner_team_key' => $winnerTeamKey,
                'is_tied' => $isTied,
                'is_playoffs' => (string) ($matchupData['is_playoffs'] ?? '0') === '1',
                'is_consolation' => (string) ($matchupData['is_consolation'] ?? '0') === '1',
            ];
        }

        return $matchups;
    }

    /** @param array<int, array<string, mixed>> $matchups @return array<int, array<string, mixed>> */
    private function dedupeMatchups(array $matchups): array
    {
        $seen = [];
        $out = [];

        foreach ($matchups as $m) {
            $id = trim((string) ($m['matchup_id'] ?? ''));

            if ($id !== '') {
                $key = 'id:' . $id;
            } else {
                $a = strtolower(trim((string) ($m['team_1'] ?? '')));
                $b = strtolower(trim((string) ($m['team_2'] ?? '')));
                $pair = [$a, $b];
                sort($pair);
                $key = 'pair:' . implode('|', $pair);
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $m;
        }

        return $out;
    }

    /** @return array<int, array{name: string, team_key: string, points: ?float}> */
    private function parseMatchupTeams(array $matchupData): array
    {
        $teamNodes = [];
        $this->collectNodesByKey($matchupData, 'team', $teamNodes);

        $teams = [];

        foreach ($teamNodes as $teamData) {
            if (!is_array($teamData)) {
                continue;
            }

            $meta = $teamData[0] ?? [];
            if (!is_array($meta)) {
                continue;
            }

            $name = '';
            $teamKey = '';
            foreach ($meta as $item) {
                if (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                    $name = $item['name'];
                }

                if (is_array($item) && isset($item['team_key']) && is_string($item['team_key'])) {
                    $teamKey = $item['team_key'];
                }
            }

            if ($name === '') {
                continue;
            }

            $teams[] = [
                'name' => $name,
                'team_key' => $teamKey,
                'points' => $this->extractTeamScore($teamData),
            ];
        }

        if (count($teams) > 2) {
            $teams = array_slice($teams, 0, 2);
        }

        return $teams;
    }

    private function extractTeamScore(array $teamData): ?float
    {
        if (
            isset($teamData[1]['team_points']['total'])
            && is_scalar($teamData[1]['team_points']['total'])
        ) {
            return (float) $teamData[1]['team_points']['total'];
        }

        return $this->findTeamPointsRecursively($teamData);
    }

    /** @param array<string, mixed> $node @param array<int, mixed> $collector */
    private function collectNodesByKey(array $node, string $wantedKey, array &$collector): void
    {
        foreach ($node as $k => $v) {
            if ($k === $wantedKey && is_array($v)) {
                $collector[] = $v;
            }

            if (is_array($v)) {
                $this->collectNodesByKey($v, $wantedKey, $collector);
            }
        }
    }

    /** @param array<string, mixed>|array<int, mixed> $node */
    private function findIntByKeyRecursively(array $node, string $wantedKey): ?int
    {
        foreach ($node as $k => $v) {
            if ($k === $wantedKey) {
                $parsed = $this->toInt($v);
                if ($parsed !== null) {
                    return $parsed;
                }
            }

            if (is_array($v)) {
                $found = $this->findIntByKeyRecursively($v, $wantedKey);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param mixed $value */
    private function toInt($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    /** @param mixed $value */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
    }

    /** @param array<string, mixed>|array<int, mixed> $node */
    private function findTeamPointsRecursively(array $node): ?float
    {
        foreach ($node as $k => $v) {
            if ($k === 'team_points' && is_array($v) && isset($v['total']) && is_scalar($v['total'])) {
                return (float) $v['total'];
            }

            if (is_array($v)) {
                $found = $this->findTeamPointsRecursively($v);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function getScoreboardForWeek(string $leagueKey, int $week): array
    {
        try {
            return $this->api->get('league/' . $leagueKey . '/scoreboard', ['week' => $week]);
        } catch (Throwable $e) {
            // Fall back to Yahoo matrix-param endpoint for older game IDs.
        }

        return $this->api->get('league/' . $leagueKey . '/scoreboard;week=' . $week);
    }

    /**
     * @return array<int, array{identity: string, name: string, logo_url: string}>
     */
    private function parseStandingsDirectory(array $raw): array
    {
        $leagueData = $raw['fantasy_content']['league'] ?? null;

        if (!is_array($leagueData) || !isset($leagueData[1])) {
            return [];
        }

        $standingsSection = $leagueData[1]['standings'] ?? [];
        $teamsRaw = [];

        if (isset($standingsSection['teams']) && is_array($standingsSection['teams'])) {
            $teamsRaw = $standingsSection['teams'];
        } elseif (isset($standingsSection[0]['teams']) && is_array($standingsSection[0]['teams'])) {
            $teamsRaw = $standingsSection[0]['teams'];
        }

        if ($teamsRaw === []) {
            return [];
        }

        $teams = [];

        foreach ($teamsRaw as $key => $value) {
            if ($key === 'count') {
                continue;
            }

            $teamData = $value['team'] ?? null;
            if (!is_array($teamData)) {
                continue;
            }

            $meta = $teamData[0] ?? [];
            if (!is_array($meta)) {
                continue;
            }

            $flat = $this->flattenMeta($meta);
            $name = (string) ($flat['name'] ?? 'Unknown Team');
            if ($this->isExcludedTeamName($name)) {
                continue;
            }

            $logoUrl = '';
            if (isset($flat['team_logos'][0]['team_logo']['url']) && is_string($flat['team_logos'][0]['team_logo']['url'])) {
                $logoUrl = $flat['team_logos'][0]['team_logo']['url'];
            }

            $guid = '';
            if (isset($flat['managers'][0]['manager']['guid']) && is_string($flat['managers'][0]['manager']['guid'])) {
                $guid = $flat['managers'][0]['manager']['guid'];
            }

            $identity = $guid !== ''
                ? 'guid:' . $guid
                : 'name:' . $this->normalizeName($name);

            $teams[] = [
                'identity' => $identity,
                'name' => $name,
                'logo_url' => $logoUrl,
            ];
        }

        return $teams;
    }

    /** @return array<string, mixed> */
    private function flattenMeta(array $meta): array
    {
        $flat = [];

        foreach ($meta as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach ($item as $key => $value) {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param array<int, array<string, string>> $teamLookupBySeason
     * @param array<string, array{team_name: string, logo_url: string, last_seen_season: int}> $teamDirectoryById
     * @return array{id: string, team_name: string}|null
     */
    private function resolveCanonicalTeam(string $rawTeamName, int $season, array $teamLookupBySeason, array $teamDirectoryById): ?array
    {
        if ($rawTeamName === '' || $this->isExcludedTeamName($rawTeamName)) {
            return null;
        }

        $normalized = $this->normalizeName($rawTeamName);
        $identity = $teamLookupBySeason[$season][$normalized] ?? null;

        if ($identity !== null && isset($teamDirectoryById[$identity]['team_name'])) {
            return [
                'id' => (string) $identity,
                'team_name' => (string) $teamDirectoryById[$identity]['team_name'],
            ];
        }

        $fallbackIdentity = 'name:' . $normalized;

        return [
            'id' => $fallbackIdentity,
            'team_name' => $rawTeamName,
        ];
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    private function isExcludedTeamName(string $teamName): bool
    {
        $normalized = strtolower($teamName);

        foreach (self::EXCLUDED_TEAM_NAME_PARTS as $part) {
            if ($part !== '' && strpos($normalized, $part) !== false) {
                return true;
            }
        }

        return false;
    }

    private function buildPairKey(string $teamAId, string $teamBId): string
    {
        if ($teamAId <= $teamBId) {
            return $teamAId . '||' . $teamBId;
        }

        return $teamBId . '||' . $teamAId;
    }

    private function buildRenewedLeagueKey(string $renewValue): ?string
    {
        if ($renewValue === '' || strpos($renewValue, '_') === false) {
            return null;
        }

        [$gameId, $leagueId] = explode('_', $renewValue, 2);

        if ($gameId === '' || $leagueId === '') {
            return null;
        }

        return $gameId . '.l.' . $leagueId;
    }
}
