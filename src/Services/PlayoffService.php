<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;
use Throwable;

class PlayoffService
{
    private YahooApiClient $api;
    private string $currentLeagueKey;
    private int $startSeason;

    public function __construct(YahooApiClient $api, string $currentLeagueKey, int $startSeason = 2018)
    {
        $this->api = $api;
        $this->currentLeagueKey = $currentLeagueKey;
        $this->startSeason = $startSeason;
    }

    /** @return array{season: int, rounds: array, champion: ?string, runner_up: ?string} */
    public function getPlayoffBracket(int $seasonYear): array
    {
        $result = $this->getPlayoffBracketWithDebug($seasonYear);
        return $result['bracket'];
    }

    /** @return array<int, array{season: int, rounds: array, champion: ?string, runner_up: ?string}> */
    public function getAllPlayoffBrackets(): array
    {
        $brackets = [];
        $currentYear = (int) date('Y');

        for ($year = $this->startSeason; $year <= $currentYear; $year++) {
            $brackets[$year] = $this->getPlayoffBracket($year);
        }

        return $brackets;
    }

    /**
     * @return array{
     *   brackets: array<int, array{season: int, rounds: array, champion: ?string, runner_up: ?string}>,
     *   debug: array<int, array<string, mixed>>
     * }
     */
    public function getAllPlayoffBracketsWithDebug(): array
    {
        $brackets = [];
        $debug = [];
        $currentYear = (int) date('Y');

        for ($year = $this->startSeason; $year <= $currentYear; $year++) {
            $result = $this->getPlayoffBracketWithDebug($year);
            $brackets[$year] = $result['bracket'];
            $debug[$year] = $result['debug'];
        }

        return [
            'brackets' => $brackets,
            'debug' => $debug,
        ];
    }

    /** @return array{bracket: array{season: int, rounds: array, champion: ?string, runner_up: ?string}, debug: array<string, mixed>} */
    private function getPlayoffBracketWithDebug(int $seasonYear): array
    {
        $debug = [
            'season' => $seasonYear,
            'league_key' => null,
            'playoff_start_week' => 0,
            'end_week' => 0,
            'weeks_attempted' => [],
            'weeks_used' => [],
            'total_matchups_seen' => 0,
            'total_playoff_matchups' => 0,
            'errors' => [],
            'status' => 'init',
        ];

        try {
            $leagueKey = $this->getLeagueKeyForSeason($seasonYear);
            $debug['league_key'] = $leagueKey;

            if ($leagueKey === null || $leagueKey === '') {
                $debug['status'] = 'no_league_key_for_season';
                return ['bracket' => $this->emptyBracket($seasonYear), 'debug' => $debug];
            }

            $playoffStartWeek = 0;
            $endWeek = 0;

            try {
                $leagueMetaRaw = $this->api->get('league/' . $leagueKey);
                $leagueMeta = $this->parseLeagueMeta($leagueMetaRaw);
                $playoffStartWeek = (int) ($leagueMeta['playoff_start_week'] ?? 0);
                $endWeek = (int) ($leagueMeta['end_week'] ?? 0);
            } catch (Throwable $e) {
                $debug['errors'][] = 'league_meta_failed: ' . $e->getMessage();
            }

            if ($playoffStartWeek <= 0 || $endWeek <= 0) {
                try {
                    $settingsRaw = $this->api->get('league/' . $leagueKey . '/settings');
                    $settings = $this->parseSettings($settingsRaw);
                    $playoffStartWeek = (int) ($settings['playoff_start_week'] ?? 0);
                    $endWeek = (int) ($settings['end_week'] ?? 0);
                } catch (Throwable $e) {
                    $debug['errors'][] = 'settings_failed: ' . $e->getMessage();
                }
            }

            $debug['playoff_start_week'] = $playoffStartWeek;
            $debug['end_week'] = $endWeek;

            if ($playoffStartWeek <= 0 || $endWeek <= 0 || $playoffStartWeek > $endWeek) {
                $debug['status'] = 'invalid_playoff_week_range';
                return ['bracket' => $this->emptyBracket($seasonYear), 'debug' => $debug];
            }

            $matchupsByWeek = [];

            for ($week = $playoffStartWeek; $week <= $endWeek; $week++) {
                $debug['weeks_attempted'][] = $week;

                try {
                    $raw = $this->api->get('league/' . $leagueKey . '/scoreboard', ['week' => $week]);
                    $weekMatchups = $this->parseScoreboardWeek($raw);
                    $debug['total_matchups_seen'] += count($weekMatchups);

                    // Preferred: explicit playoff, excluding consolation.
                    $playoffMatchups = array_values(array_filter(
                        $weekMatchups,
                        static fn(array $m): bool => ($m['is_playoffs'] ?? false) && !($m['is_consolation'] ?? false)
                    ));

                    $filterMode = 'explicit_playoffs';

                    // Fallback #1: if Yahoo did not set is_playoffs reliably, keep non-consolation matchups.
                    if ($playoffMatchups === []) {
                        $playoffMatchups = array_values(array_filter(
                            $weekMatchups,
                            static fn(array $m): bool => !($m['is_consolation'] ?? false)
                        ));
                        $filterMode = 'non_consolation_fallback';
                    }

                    // Fallback #2: as last resort keep all matchups in declared playoff week range.
                    if ($playoffMatchups === []) {
                        $playoffMatchups = $weekMatchups;
                        $filterMode = 'all_matchups_fallback';
                    }

                    if ($playoffMatchups !== []) {
                        $matchupsByWeek[$week] = $playoffMatchups;
                        $debug['weeks_used'][] = [
                            'week' => $week,
                            'filter_mode' => $filterMode,
                            'raw_matchups' => count($weekMatchups),
                            'used_matchups' => count($playoffMatchups),
                        ];
                        $debug['total_playoff_matchups'] += count($playoffMatchups);
                    }
                } catch (Throwable $e) {
                    $debug['errors'][] = 'week_' . $week . '_failed: ' . $e->getMessage();
                }
            }

            if ($matchupsByWeek === []) {
                $debug['status'] = 'no_matchups_after_filtering';
                return ['bracket' => $this->emptyBracket($seasonYear), 'debug' => $debug];
            }

            $debug['status'] = 'ok';
            return [
                'bracket' => $this->buildBracket($matchupsByWeek, $seasonYear),
                'debug' => $debug,
            ];
        } catch (Throwable $e) {
            $debug['status'] = 'fatal';
            $debug['errors'][] = $e->getMessage();
            return ['bracket' => $this->emptyBracket($seasonYear), 'debug' => $debug];
        }
    }

    private function getLeagueKeyForSeason(int $seasonYear): ?string
    {
        $chain = $this->collectLeagueChain();

        foreach ($chain as $league) {
            if ((int) ($league['season'] ?? 0) === $seasonYear) {
                return (string) ($league['league_key'] ?? '');
            }
        }

        return null;
    }

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

        usort($chain, static fn(array $a, array $b): int => ((int) ($a['season'] ?? 0)) <=> ((int) ($b['season'] ?? 0)));

        return $chain;
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
     *   team_1: string,
     *   team_2: string,
     *   team_1_score: ?float,
     *   team_2_score: ?float,
     *   winner: ?string,
     *   is_playoffs: bool,
     *   is_consolation: bool
     * }>
     */
    private function parseScoreboardWeek(array $raw): array
    {
        $leagueData = $raw['fantasy_content']['league'] ?? null;
        if (!is_array($leagueData)) {
            return [];
        }

        $matchupsNodes = [];
        $this->collectNodesByKey($leagueData, 'matchups', $matchupsNodes);

        $matchups = [];

        foreach ($matchupsNodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach ($node as $k => $entry) {
                if ($k === 'count') {
                    continue;
                }

                $matchupData = $entry['matchup'] ?? $entry;
                if (!is_array($matchupData)) {
                    continue;
                }

                $teams = $this->parseMatchupTeams($matchupData);
                if (count($teams) !== 2) {
                    continue;
                }

                $team1Name = (string) ($teams[0]['name'] ?? '');
                $team2Name = (string) ($teams[1]['name'] ?? '');
                if ($team1Name === '' || $team2Name === '') {
                    continue;
                }

                $team1Score = $teams[0]['points'] ?? null;
                $team2Score = $teams[1]['points'] ?? null;

                $winner = null;
                if ($team1Score !== null && $team2Score !== null) {
                    $winner = $team1Score > $team2Score ? $team1Name : $team2Name;
                }

                $matchups[] = [
                    'team_1' => $team1Name,
                    'team_2' => $team2Name,
                    'team_1_score' => $team1Score,
                    'team_2_score' => $team2Score,
                    'winner' => $winner,
                    'is_playoffs' => (string) ($matchupData['is_playoffs'] ?? '0') === '1',
                    'is_consolation' => (string) ($matchupData['is_consolation'] ?? '0') === '1',
                ];
            }
        }

        return $matchups;
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

    /** @return array<int, array{name: string, points: ?float}> */
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
            foreach ($meta as $item) {
                if (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                    $name = $item['name'];
                    break;
                }
            }

            if ($name === '') {
                continue;
            }

            $teams[] = [
                'name' => $name,
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

    /**
     * @param array<int, list<array<string, mixed>>> $matchupsByWeek
     * @return array{season: int, rounds: array, champion: ?string, runner_up: ?string}
     */
    private function buildBracket(array $matchupsByWeek, int $seasonYear): array
    {
        ksort($matchupsByWeek);

        $weeks = array_keys($matchupsByWeek);
        $totalRounds = count($weeks);
        $rounds = [];

        foreach ($weeks as $idx => $week) {
            $positionFromEnd = $totalRounds - 1 - $idx;
            $rounds[] = [
                'round_no' => $idx + 1,
                'round_label' => $this->roundLabelFromEnd($positionFromEnd),
                'matchups' => $matchupsByWeek[$week],
            ];
        }

        $champion = null;
        $runnerUp = null;

        if ($rounds !== []) {
            $finalRound = $rounds[count($rounds) - 1];
            $finalMatchup = $finalRound['matchups'][0] ?? null;

            if (is_array($finalMatchup)) {
                $champion = $finalMatchup['winner'] ?? null;
                if (is_string($champion) && $champion !== '') {
                    $runnerUp = $champion === ($finalMatchup['team_1'] ?? null)
                        ? ($finalMatchup['team_2'] ?? null)
                        : ($finalMatchup['team_1'] ?? null);
                    if (!is_string($runnerUp)) {
                        $runnerUp = null;
                    }
                } else {
                    $champion = null;
                }
            }
        }

        return [
            'season' => $seasonYear,
            'rounds' => $rounds,
            'champion' => $champion,
            'runner_up' => $runnerUp,
        ];
    }

    private function roundLabelFromEnd(int $positionFromEnd): string
    {
        return match ($positionFromEnd) {
            0 => 'Finals',
            1 => 'Semifinals',
            2 => 'Quarterfinals',
            default => 'Round ' . ($positionFromEnd + 1),
        };
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

    /** @return array{season: int, rounds: array, champion: ?string, runner_up: ?string} */
    private function emptyBracket(int $seasonYear): array
    {
        return [
            'season' => $seasonYear,
            'rounds' => [],
            'champion' => null,
            'runner_up' => null,
        ];
    }
}
