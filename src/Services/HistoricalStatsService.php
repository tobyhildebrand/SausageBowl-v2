<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;
use Throwable;

class HistoricalStatsService
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
     *   last3_seasons: int[],
    *   season_states: array<int, array{status: string, message: string, expected_teams: int, teams_found: int}>,
     *   rows: array<int, array{
     *     id: string,
     *     team_name: string,
     *     logo_url: string,
     *     seasons_played: int,
     *     places: array<int, int>,
     *     average_place: float,
     *     trend_l3y: ?float
     *   }>
     * }
     */
    public function getHistoricalStandings(): array
    {
        $leagues = $this->collectLeagueChain();

        if ($leagues === []) {
            return [
                'seasons' => [],
                'last3_seasons' => [],
                'season_states' => [],
                'rows' => [],
            ];
        }

        usort($leagues, static fn(array $a, array $b): int => $a['season'] <=> $b['season']);

        $latestSeasonInChain = 0;
        foreach ($leagues as $league) {
            $latestSeasonInChain = max($latestSeasonInChain, (int) ($league['season'] ?? 0));
        }

        // Historical view should include only fully finished seasons.
        // Prefer Yahoo's is_finished flag and fall back to excluding the latest season.
        $finishedLeagues = [];
        foreach ($leagues as $league) {
            $season = (int) ($league['season'] ?? 0);
            $isFinished = (bool) ($league['is_finished'] ?? false);

            if ($isFinished || $season < $latestSeasonInChain) {
                $finishedLeagues[] = $league;
            }
        }

        if ($finishedLeagues === []) {
            return [
                'seasons' => [],
                'last3_seasons' => [],
                'season_states' => [],
                'rows' => [],
            ];
        }

        $leagues = $finishedLeagues;
        $seasons = array_values(array_map(static fn(array $l): int => (int) $l['season'], $leagues));

        $rowsById = [];
        $seasonStates = [];

        foreach ($leagues as $league) {
            $season = (int) $league['season'];
            $expectedTeams = max(0, (int) ($league['num_teams'] ?? 0));

            try {
                $standingsRaw = $this->api->get('league/' . $league['league_key'] . '/standings');
                $teams = $this->parseStandings($standingsRaw);
            } catch (Throwable $e) {
                $seasonStates[$season] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'expected_teams' => $expectedTeams,
                    'teams_found' => 0,
                ];
                continue;
            }

            $teamsFound = count($teams);
            if ($expectedTeams > 0 && $teamsFound < $expectedTeams) {
                $seasonStates[$season] = [
                    'status' => 'partial',
                    'message' => 'Yahoo returned partial standings for this season.',
                    'expected_teams' => $expectedTeams,
                    'teams_found' => $teamsFound,
                ];
            } else {
                $seasonStates[$season] = [
                    'status' => 'ok',
                    'message' => '',
                    'expected_teams' => $expectedTeams,
                    'teams_found' => $teamsFound,
                ];
            }

            foreach ($teams as $team) {
                $id = $team['identity'];

                if (!isset($rowsById[$id])) {
                    $rowsById[$id] = [
                        'id' => $id,
                        'team_name' => $team['name'],
                        'logo_url' => $team['logo_url'],
                        'first_seen_season' => $season,
                        'last_seen_season' => $season,
                        'places' => [],
                    ];
                }

                if ($season >= $rowsById[$id]['last_seen_season']) {
                    $rowsById[$id]['team_name'] = $team['name'];
                    $rowsById[$id]['logo_url'] = $team['logo_url'];
                    $rowsById[$id]['last_seen_season'] = $season;
                }

                $rowsById[$id]['places'][$season] = (int) $team['rank'];
            }
        }

        // If a season appears "partial" only because some teams joined later,
        // treat that season as complete historical data.
        $futureJoinersBySeason = [];
        foreach ($rowsById as $teamRow) {
            $firstSeenSeason = (int) ($teamRow['first_seen_season'] ?? 0);
            if ($firstSeenSeason <= 0) {
                continue;
            }

            foreach ($seasons as $season) {
                $seasonInt = (int) $season;
                if ($firstSeenSeason > $seasonInt) {
                    $futureJoinersBySeason[$seasonInt] = (int) ($futureJoinersBySeason[$seasonInt] ?? 0) + 1;
                }
            }
        }

        foreach ($seasonStates as $season => $state) {
            if (($state['status'] ?? '') !== 'partial') {
                continue;
            }

            $expectedTeams = (int) ($state['expected_teams'] ?? 0);
            $teamsFound = (int) ($state['teams_found'] ?? 0);
            $missingTeams = max(0, $expectedTeams - $teamsFound);
            $futureJoiners = (int) ($futureJoinersBySeason[(int) $season] ?? 0);

            if ($missingTeams > 0 && $missingTeams <= $futureJoiners) {
                $seasonStates[$season] = [
                    'status' => 'ok',
                    'message' => '',
                    'expected_teams' => $expectedTeams,
                    'teams_found' => $teamsFound,
                ];
            }
        }

        $last3Seasons = array_slice($seasons, -3);
        $rows = [];

        foreach ($rowsById as $row) {
            /** @var array<int, int> $places */
            $places = $row['places'];
            ksort($places);

            $all = array_values($places);
            $averagePlace = round(array_sum($all) / max(1, count($all)), 1);

            $last3Values = [];
            foreach ($last3Seasons as $s) {
                if (isset($places[$s])) {
                    $last3Values[] = $places[$s];
                }
            }

            $trendL3y = null;
            if ($last3Values !== []) {
                $trendL3y = round(array_sum($last3Values) / count($last3Values), 1);
            }

            $rows[] = [
                'id' => (string) $row['id'],
                'team_name' => (string) $row['team_name'],
                'logo_url' => (string) $row['logo_url'],
                'seasons_played' => count($all),
                'places' => $places,
                'average_place' => $averagePlace,
                'trend_l3y' => $trendL3y,
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => ($a['average_place'] <=> $b['average_place'])
                ?: strcmp((string) $a['team_name'], (string) $b['team_name'])
        );

        return [
            'seasons' => $seasons,
            'last3_seasons' => $last3Seasons,
            'season_states' => $seasonStates,
            'rows' => $rows,
        ];
    }

    /** @return array<int, array{league_key: string, season: int, num_teams: int, is_finished: bool}> */
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
                    'num_teams' => (int) ($meta['num_teams'] ?? 0),
                    'is_finished' => (bool) ($meta['is_finished'] ?? false),
                ];
            }

            if ($season <= $this->startSeason) {
                break;
            }

            $renewedLeagueKey = $this->buildRenewedLeagueKey((string) ($meta['renew'] ?? ''));
            if ($renewedLeagueKey === null) {
                break;
            }

            $key = $renewedLeagueKey;
        }

        return $chain;
    }

    /** @return array<string, mixed> */
    private function parseLeagueMeta(array $raw): array
    {
        $meta = $raw['fantasy_content']['league'][0] ?? [];

        if (!is_array($meta)) {
            throw new RuntimeException('Unexpected Yahoo API response structure for league meta.');
        }

        return [
            'league_key' => (string) ($meta['league_key'] ?? ''),
            'season' => (string) ($meta['season'] ?? ''),
            'renew' => (string) ($meta['renew'] ?? ''),
            'num_teams' => (int) ($meta['num_teams'] ?? 0),
            'is_finished' => $this->toBool($meta['is_finished'] ?? false),
        ];
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

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
        }

        return false;
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

    /**
     * @return array<int, array{identity: string, name: string, logo_url: string, rank: int}>
     */
    private function parseStandings(array $raw): array
    {
        $leagueData = $raw['fantasy_content']['league'] ?? null;

        if (!is_array($leagueData) || !isset($leagueData[1])) {
            throw new RuntimeException('Unexpected Yahoo API response structure for standings.');
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

            $rank = 0;
            if (isset($teamData[1]['team_standings']['rank']) && is_numeric($teamData[1]['team_standings']['rank'])) {
                $rank = (int) $teamData[1]['team_standings']['rank'];
            } else {
                $foundRank = $this->findFirstNumericRank($teamData);
                if ($foundRank !== null) {
                    $rank = $foundRank;
                }
            }

            if ($rank <= 0) {
                continue;
            }

            $identity = $guid !== ''
                ? 'guid:' . $guid
                : 'name:' . $this->normalizeName($name);

            $teams[] = [
                'identity' => $identity,
                'name' => $name,
                'logo_url' => $logoUrl,
                'rank' => $rank,
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

            foreach ($item as $k => $v) {
                $flat[$k] = $v;
            }
        }

        return $flat;
    }

    private function findFirstNumericRank(array $node): ?int
    {
        foreach ($node as $k => $v) {
            if ($k === 'rank' && is_numeric($v)) {
                $rank = (int) $v;
                if ($rank > 0) {
                    return $rank;
                }
            }

            if (is_array($v)) {
                $found = $this->findFirstNumericRank($v);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
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
}
