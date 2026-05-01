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

    /**
     * Get playoff bracket data for a specific season.
     *
     * @return array{
     *   season: int,
     *   rounds: array<int, array{
     *     round_no: int,
     *     round_label: string,
     *     matchups: array<int, array{
     *       matchup_no: int,
     *       team_1: string,
     *       team_2: string,
     *       team_1_score: ?float,
     *       team_2_score: ?float,
     *       winner: ?string
     *     }>
     *   }>,
     *   champion: ?string,
     *   runner_up: ?string
     * }
     */
    public function getPlayoffBracket(int $seasonYear): array
    {
        try {
            $leagueKey = $this->getLeagueKeyForSeason($seasonYear);
            if ($leagueKey === null) {
                return $this->emptyBracket($seasonYear);
            }

            $raw = $this->api->get('league/' . $leagueKey . '/scoreboard;week=-1');
            return $this->parseBracket($raw, $seasonYear);
        } catch (Throwable $e) {
            // If we can't fetch from Yahoo, return empty bracket
            return $this->emptyBracket($seasonYear);
        }
    }

    /**
     * Get all playoff brackets for all seasons from start year to current year.
     *
     * @return array<int, array{season: int, rounds: array, champion: ?string, runner_up: ?string}>
     */
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
     * Find the league key for a given season year by walking the league chain.
     */
    private function getLeagueKeyForSeason(int $seasonYear): ?string
    {
        try {
            $chain = $this->collectLeagueChain();

            foreach ($chain as $league) {
                if ($league['season'] === $seasonYear) {
                    return $league['league_key'];
                }
            }

            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Walk back through the league renewal chain to find all seasons.
     */
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

                $renewedLeagueKey = $this->buildRenewedLeagueKey((string) ($meta['renew'] ?? ''));
                if ($renewedLeagueKey === null) {
                    break;
                }

                $key = $renewedLeagueKey;
            } catch (Throwable $e) {
                break;
            }
        }

        usort($chain, static fn(array $a, array $b): int => $a['season'] <=> $b['season']);
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

    /**
     * Parse the playoff bracket from Yahoo's scoreboard (week=-1).
     * Week -1 contains the playoff bracket structure.
     *
     * @return array{season: int, rounds: array, champion: ?string, runner_up: ?string}
     */
    private function parseBracket(array $raw, int $seasonYear): array
    {
        $scoreboardData = $raw['fantasy_content']['league'][1]['scoreboard'] ?? null;

        if (!is_array($scoreboardData)) {
            return $this->emptyBracket($seasonYear);
        }

        $matchupsRaw = $scoreboardData['0']['matchups'] ?? [];
        if (!is_array($matchupsRaw)) {
            return $this->emptyBracket($seasonYear);
        }

        $roundsByNo = [];
        $allWinners = [];

        foreach ($matchupsRaw as $key => $matchupData) {
            if ($key === 'count' || !is_array($matchupData)) {
                continue;
            }

            $matchup = $matchupData['matchup'] ?? [];
            if (!is_array($matchup)) {
                continue;
            }

            $roundNo = (int) ($matchup['week'] ?? 0);
            $matchupNo = (int) ($matchup['week_start'] ?? 0);
            $matchupId = (int) ($matchup['matchup_id'] ?? 0);

            $teams = $matchup['teams'] ?? [];
            if (!is_array($teams) || count($teams) < 2) {
                continue;
            }

            $team1Data = is_array($teams['0'] ?? null) ? $teams['0'] : null;
            $team2Data = is_array($teams['1'] ?? null) ? $teams['1'] : null;

            if (!$team1Data || !$team2Data) {
                continue;
            }

            $team1Name = $this->extractTeamName($team1Data);
            $team2Name = $this->extractTeamName($team2Data);
            $team1Score = $this->extractTeamScore($team1Data);
            $team2Score = $this->extractTeamScore($team2Data);

            if (!$team1Name || !$team2Name) {
                continue;
            }

            $winner = null;
            if ($team1Score !== null && $team2Score !== null) {
                $winner = $team1Score > $team2Score ? $team1Name : $team2Name;
                $allWinners[] = $winner;
            }

            if (!isset($roundsByNo[$roundNo])) {
                $roundsByNo[$roundNo] = [
                    'round_no' => $roundNo,
                    'round_label' => $this->getRoundLabel($roundNo),
                    'matchups' => [],
                ];
            }

            $roundsByNo[$roundNo]['matchups'][] = [
                'matchup_no' => $matchupId,
                'team_1' => $team1Name,
                'team_2' => $team2Name,
                'team_1_score' => $team1Score,
                'team_2_score' => $team2Score,
                'winner' => $winner,
            ];
        }

        ksort($roundsByNo);

        $champion = null;
        $runnerUp = null;

        // Champion is the winner with the highest round number (finals)
        if (!empty($roundsByNo)) {
            $lastRound = end($roundsByNo);
            if (!empty($lastRound['matchups'])) {
                $finalMatchup = end($lastRound['matchups']);
                $champion = $finalMatchup['winner'];
                $runnerUp = $finalMatchup['winner'] === $finalMatchup['team_1']
                    ? $finalMatchup['team_2']
                    : $finalMatchup['team_1'];
            }
        }

        return [
            'season' => $seasonYear,
            'rounds' => array_values($roundsByNo),
            'champion' => $champion,
            'runner_up' => $runnerUp,
        ];
    }

    private function extractTeamName(array $teamData): ?string
    {
        $team = $teamData['team'] ?? null;
        if (!is_array($team)) {
            return null;
        }

        $name = $team['name'] ?? $team[2]['name'] ?? null;
        return $name ? (string) $name : null;
    }

    private function extractTeamScore(array $teamData): ?float
    {
        $points = $teamData['points'] ?? $teamData[2]['points'] ?? null;
        return $points !== null ? (float) $points : null;
    }

    private function getRoundLabel(int $roundNo): string
    {
        return match ($roundNo) {
            1 => 'Quarterfinals',
            2 => 'Semifinals',
            3 => 'Finals',
            default => "Round {$roundNo}",
        };
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
