<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;
use Throwable;

class HistoricalInsightsService
{
    private const EXCLUDED_TEAM_NAME_PARTS = ['mordlustig', 'tru. crew', 'tru crew'];
    private const MOMENTUM_WEIGHTS = [0.1, 0.3, 0.6];

    private YahooApiClient $api;
    private string $currentLeagueKey;
    private int $startSeason;
    private float $closeMarginPoints;

    public function __construct(YahooApiClient $api, string $currentLeagueKey, int $startSeason = 2018, float $closeMarginPoints = 10.0)
    {
        $this->api = $api;
        $this->currentLeagueKey = $currentLeagueKey;
        $this->startSeason = $startSeason;
        $this->closeMarginPoints = $closeMarginPoints;
    }

    /**
     * @param array<string, mixed> $history
     * @param array<string, mixed> $points
     * @return array{
     *   close_margin: float,
     *   last3_seasons: int[],
     *   rows: array<int, array{
     *     id: string,
     *     team_name: string,
     *     logo_url: string,
     *     placement_efficiency: ?float,
     *     luck_gap: ?float,
     *     momentum: ?float,
     *     volatility: ?float,
     *     close_call_rate: ?float,
     *     close_call_wins: int,
     *     close_call_games: int
     *   }>
     * }
     */
    public function getInsights(array $history, array $points): array
    {
        $seasons = array_values(array_map('intval', (array) ($history['seasons'] ?? [])));
        $last3Seasons = array_values(array_map('intval', (array) ($history['last3_seasons'] ?? [])));

        /** @var array<int, array<string, mixed>> $historyRows */
        $historyRows = (array) ($history['rows'] ?? []);
        /** @var array<int, array<string, mixed>> $pointRows */
        $pointRows = (array) ($points['rows'] ?? []);

        if ($historyRows === [] && $pointRows === []) {
            return [
                'close_margin' => $this->closeMarginPoints,
                'last3_seasons' => $last3Seasons,
                'rows' => [],
            ];
        }

        $rowsById = [];

        foreach ($historyRows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $rowsById[$id] = [
                'id' => $id,
                'team_name' => (string) ($row['team_name'] ?? ''),
                'logo_url' => (string) ($row['logo_url'] ?? ''),
                'places' => (array) ($row['places'] ?? []),
                'points' => [],
            ];
        }

        foreach ($pointRows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            if (!isset($rowsById[$id])) {
                $rowsById[$id] = [
                    'id' => $id,
                    'team_name' => (string) ($row['team_name'] ?? ''),
                    'logo_url' => (string) ($row['logo_url'] ?? ''),
                    'places' => [],
                    'points' => [],
                ];
            }

            if ((string) $rowsById[$id]['team_name'] === '') {
                $rowsById[$id]['team_name'] = (string) ($row['team_name'] ?? '');
            }

            if ((string) $rowsById[$id]['logo_url'] === '') {
                $rowsById[$id]['logo_url'] = (string) ($row['logo_url'] ?? '');
            }

            $rowsById[$id]['points'] = (array) ($row['points'] ?? []);
        }

        $teamCountBySeason = $this->buildTeamCountBySeason($historyRows, $seasons);
        $maxPointsBySeason = $this->buildMaxPointsBySeason($pointRows, $seasons);
        $pointsRankBySeason = $this->buildPointsRankBySeason($pointRows, $seasons);
        $closeCallById = $this->collectCloseCallStats($last3Seasons);

        $rows = [];

        foreach ($rowsById as $id => $row) {
            /** @var array<int, int> $places */
            $places = [];
            foreach ((array) $row['places'] as $season => $place) {
                if (is_numeric($season) && is_numeric($place)) {
                    $places[(int) $season] = (int) $place;
                }
            }

            /** @var array<int, float> $pointsBySeason */
            $pointsBySeason = [];
            foreach ((array) $row['points'] as $season => $pts) {
                if (is_numeric($season) && is_numeric($pts)) {
                    $pointsBySeason[(int) $season] = (float) $pts;
                }
            }

            $placementEfficiency = $this->calcPlacementEfficiency($places, $pointsBySeason);
            $luckGap = $this->calcLuckGap($id, $places, $pointsRankBySeason);
            $momentum = $this->calcMomentum($id, $places, $pointsBySeason, $last3Seasons, $teamCountBySeason, $maxPointsBySeason);
            $volatility = $this->calcVolatility($places);

            $closeStats = $closeCallById[$id] ?? ['wins' => 0, 'games' => 0];
            $closeGames = (int) $closeStats['games'];
            $closeWins = (int) $closeStats['wins'];
            $closeRate = $closeGames > 0 ? round(($closeWins / $closeGames) * 100, 1) : null;

            $rows[] = [
                'id' => (string) $id,
                'team_name' => (string) $row['team_name'],
                'logo_url' => (string) $row['logo_url'],
                'placement_efficiency' => $placementEfficiency,
                'luck_gap' => $luckGap,
                'momentum' => $momentum,
                'volatility' => $volatility,
                'close_call_rate' => $closeRate,
                'close_call_wins' => $closeWins,
                'close_call_games' => $closeGames,
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => (($b['momentum'] ?? -INF) <=> ($a['momentum'] ?? -INF))
                ?: (($b['placement_efficiency'] ?? -INF) <=> ($a['placement_efficiency'] ?? -INF))
                ?: strcmp((string) $a['team_name'], (string) $b['team_name'])
        );

        return [
            'close_margin' => $this->closeMarginPoints,
            'last3_seasons' => $last3Seasons,
            'rows' => $rows,
        ];
    }

    /** @param array<int, array<string, mixed>> $historyRows @param int[] $seasons @return array<int, int> */
    private function buildTeamCountBySeason(array $historyRows, array $seasons): array
    {
        $counts = [];

        foreach ($seasons as $season) {
            $count = 0;
            foreach ($historyRows as $row) {
                if (isset($row['places'][(int) $season]) && is_numeric($row['places'][(int) $season])) {
                    $count++;
                }
            }

            $counts[(int) $season] = $count;
        }

        return $counts;
    }

    /** @param array<int, array<string, mixed>> $pointRows @param int[] $seasons @return array<int, float> */
    private function buildMaxPointsBySeason(array $pointRows, array $seasons): array
    {
        $maxBySeason = [];

        foreach ($seasons as $season) {
            $max = 0.0;
            foreach ($pointRows as $row) {
                if (isset($row['points'][(int) $season]) && is_numeric($row['points'][(int) $season])) {
                    $max = max($max, (float) $row['points'][(int) $season]);
                }
            }

            $maxBySeason[(int) $season] = $max;
        }

        return $maxBySeason;
    }

    /** @param array<int, array<string, mixed>> $pointRows @param int[] $seasons @return array<int, array<string, int>> */
    private function buildPointsRankBySeason(array $pointRows, array $seasons): array
    {
        $rankBySeason = [];

        foreach ($seasons as $season) {
            $values = [];

            foreach ($pointRows as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                if (isset($row['points'][(int) $season]) && is_numeric($row['points'][(int) $season])) {
                    $values[] = [
                        'id' => $id,
                        'points' => (float) $row['points'][(int) $season],
                    ];
                }
            }

            usort(
                $values,
                static fn(array $a, array $b): int => ($b['points'] <=> $a['points'])
            );

            $ranks = [];
            $currentRank = 0;
            $lastPoints = null;
            $position = 0;

            foreach ($values as $entry) {
                $position++;
                $points = (float) $entry['points'];

                if ($lastPoints === null || $points !== $lastPoints) {
                    $currentRank = $position;
                    $lastPoints = $points;
                }

                $ranks[(string) $entry['id']] = $currentRank;
            }

            $rankBySeason[(int) $season] = $ranks;
        }

        return $rankBySeason;
    }

    /** @param array<int, int> $places @param array<int, float> $pointsBySeason */
    private function calcPlacementEfficiency(array $places, array $pointsBySeason): ?float
    {
        $values = [];

        foreach ($places as $season => $place) {
            if ($place <= 0 || !isset($pointsBySeason[(int) $season])) {
                continue;
            }

            $values[] = $pointsBySeason[(int) $season] / $place;
        }

        if ($values === []) {
            return null;
        }

        return round((float) array_sum($values) / count($values), 1);
    }

    /** @param array<int, int> $places @param array<int, array<string, int>> $pointsRankBySeason */
    private function calcLuckGap(string $id, array $places, array $pointsRankBySeason): ?float
    {
        $values = [];

        foreach ($places as $season => $place) {
            $pointsRank = $pointsRankBySeason[(int) $season][$id] ?? null;
            if ($pointsRank === null) {
                continue;
            }

            $values[] = (float) ($pointsRank - $place);
        }

        if ($values === []) {
            return null;
        }

        return round((float) array_sum($values) / count($values), 2);
    }

    /**
     * @param array<int, int> $places
     * @param array<int, float> $pointsBySeason
     * @param int[] $last3Seasons
     * @param array<int, int> $teamCountBySeason
     * @param array<int, float> $maxPointsBySeason
     */
    private function calcMomentum(string $id, array $places, array $pointsBySeason, array $last3Seasons, array $teamCountBySeason, array $maxPointsBySeason): ?float
    {
        $seasonScores = [];

        foreach ($last3Seasons as $season) {
            $seasonInt = (int) $season;
            $place = $places[$seasonInt] ?? null;
            $points = $pointsBySeason[$seasonInt] ?? null;
            $teamCount = (int) ($teamCountBySeason[$seasonInt] ?? 0);
            $maxPoints = (float) ($maxPointsBySeason[$seasonInt] ?? 0.0);

            if ($place === null || $teamCount <= 0 || $maxPoints <= 0.0 || $points === null) {
                continue;
            }

            $rankScore = (($teamCount + 1 - $place) / $teamCount) * 100.0;
            $pointsScore = ($points / $maxPoints) * 100.0;

            $seasonScores[] = ($rankScore * 0.5) + ($pointsScore * 0.5);
        }

        if ($seasonScores === []) {
            return null;
        }

        $weights = self::MOMENTUM_WEIGHTS;
        $count = count($seasonScores);

        if ($count < count($weights)) {
            $weights = array_slice($weights, -$count);
        }

        $weightSum = array_sum($weights);
        if ($weightSum <= 0.0) {
            return null;
        }

        $score = 0.0;
        $offset = count($seasonScores) - count($weights);
        foreach ($weights as $idx => $weight) {
            $score += $seasonScores[$offset + $idx] * ($weight / $weightSum);
        }

        return round($score, 1);
    }

    /** @param array<int, int> $places */
    private function calcVolatility(array $places): ?float
    {
        if (count($places) < 2) {
            return null;
        }

        $values = array_map('floatval', array_values($places));
        $mean = array_sum($values) / count($values);

        $sq = 0.0;
        foreach ($values as $v) {
            $d = $v - $mean;
            $sq += $d * $d;
        }

        return round(sqrt($sq / count($values)), 2);
    }

    /** @param int[] $targetSeasons @return array<string, array{wins: int, games: int}> */
    private function collectCloseCallStats(array $targetSeasons): array
    {
        if ($targetSeasons === []) {
            return [];
        }

        $stats = [];
        $leagues = $this->collectLeagueChain();

        foreach ($leagues as $league) {
            $season = (int) ($league['season'] ?? 0);
            if (!in_array($season, $targetSeasons, true)) {
                continue;
            }

            $regularEndWeek = $this->resolveRegularSeasonEndWeek($league);
            if ($regularEndWeek <= 0) {
                continue;
            }

            for ($week = 1; $week <= $regularEndWeek; $week++) {
                try {
                    $raw = $this->api->get('league/' . $league['league_key'] . '/scoreboard', ['week' => $week]);
                } catch (Throwable $e) {
                    continue;
                }

                $matchups = $this->parseScoreboardMatchups($raw);
                foreach ($matchups as $matchup) {
                    if (count($matchup) !== 2) {
                        continue;
                    }

                    $a = $matchup[0];
                    $b = $matchup[1];

                    $margin = abs((float) $a['points'] - (float) $b['points']);
                    if ($margin > $this->closeMarginPoints) {
                        continue;
                    }

                    $aId = (string) $a['identity'];
                    $bId = (string) $b['identity'];

                    if (!isset($stats[$aId])) {
                        $stats[$aId] = ['wins' => 0, 'games' => 0];
                    }
                    if (!isset($stats[$bId])) {
                        $stats[$bId] = ['wins' => 0, 'games' => 0];
                    }

                    $stats[$aId]['games']++;
                    $stats[$bId]['games']++;

                    if ((float) $a['points'] > (float) $b['points']) {
                        $stats[$aId]['wins']++;
                    } elseif ((float) $b['points'] > (float) $a['points']) {
                        $stats[$bId]['wins']++;
                    }
                }
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $league */
    private function resolveRegularSeasonEndWeek(array $league): int
    {
        $playoffStartWeek = (int) ($league['playoff_start_week'] ?? 0);
        if ($playoffStartWeek > 1) {
            return $playoffStartWeek - 1;
        }

        return (int) ($league['end_week'] ?? 0);
    }

    /**
     * @return array<int, array<int, array{identity: string, points: float}>>
     */
    private function parseScoreboardMatchups(array $raw): array
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
                if (count($teams) === 2) {
                    $matchups[] = $teams;
                }
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

    /** @return array<int, array{identity: string, points: float}> */
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

            $flat = $this->flattenMeta($meta);
            $name = (string) ($flat['name'] ?? '');

            if ($name === '' || $this->isExcludedTeamName($name)) {
                continue;
            }

            $points = $this->extractTeamPointsFromMatchup($teamData);
            if ($points === null) {
                continue;
            }

            $identity = $this->resolveIdentity($flat, $name);
            $teams[$identity] = [
                'identity' => $identity,
                'points' => $points,
            ];
        }

        return array_values($teams);
    }

    private function extractTeamPointsFromMatchup(array $teamData): ?float
    {
        if (
            isset($teamData[1]['team_points']['total'])
            && is_scalar($teamData[1]['team_points']['total'])
        ) {
            $parsed = $this->toFloat($teamData[1]['team_points']['total']);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $this->findTeamPointsRecursively($teamData);
    }

    /** @param array<string, mixed>|array<int, mixed> $node */
    private function findTeamPointsRecursively(array $node): ?float
    {
        foreach ($node as $k => $v) {
            if ($k === 'team_points' && is_array($v)) {
                if (isset($v['total']) && is_scalar($v['total'])) {
                    $parsed = $this->toFloat($v['total']);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
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

    /** @return array<int, array{league_key: string, season: int, is_finished: bool, playoff_start_week: int, end_week: int}> */
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
                    'is_finished' => (bool) ($meta['is_finished'] ?? false),
                    'playoff_start_week' => (int) ($meta['playoff_start_week'] ?? 0),
                    'end_week' => (int) ($meta['end_week'] ?? 0),
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

        usort($chain, static fn(array $a, array $b): int => $a['season'] <=> $b['season']);

        $latest = 0;
        foreach ($chain as $league) {
            $latest = max($latest, (int) ($league['season'] ?? 0));
        }

        $finished = [];
        foreach ($chain as $league) {
            $season = (int) ($league['season'] ?? 0);
            if ((bool) ($league['is_finished'] ?? false) || $season < $latest) {
                $finished[] = $league;
            }
        }

        return $finished;
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
            'is_finished' => $this->toBool($meta['is_finished'] ?? false),
            'playoff_start_week' => (int) ($meta['playoff_start_week'] ?? 0),
            'end_week' => (int) ($meta['end_week'] ?? 0),
        ];
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

    /** @param array<string, mixed> $flat */
    private function resolveIdentity(array $flat, string $name): string
    {
        if (isset($flat['managers'][0]['manager']['guid']) && is_string($flat['managers'][0]['manager']['guid'])) {
            return 'guid:' . $flat['managers'][0]['manager']['guid'];
        }

        return 'name:' . $this->normalizeName($name);
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
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

    /** @param mixed $value */
    private function toFloat($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace(',', '', $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
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
