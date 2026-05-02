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
     * @return array{
     *   season: int,
     *   rounds: array<int, array{
     *     round_no: int,
     *     round_label: string,
     *     matchups: array<int, array{
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

            $settingsRaw = $this->api->get('league/' . $leagueKey . '/settings');
            $settings = $this->parseSettings($settingsRaw);

            $playoffStartWeek = $settings['playoff_start_week'];
            $endWeek = $settings['end_week'];

            if ($playoffStartWeek <= 0 || $endWeek <= 0 || $playoffStartWeek > $endWeek) {
                return $this->emptyBracket($seasonYear);
            }

            $matchupsByWeek = [];
            for ($week = $playoffStartWeek; $week <= $endWeek; $week++) {
                try {
                    $raw = $this->api->get('league/' . $leagueKey . '/scoreboard;week=' . $week);
                    $weekMatchups = $this->parseScoreboardWeek($raw);

                    $playoffMatchups = array_filter(
                        $weekMatchups,
                        static fn(array $m): bool => ($m['is_playoffs'] ?? false) && !($m['is_consolation'] ?? false)
                    );

                    if ($playoffMatchups !== []) {
                        $matchupsByWeek[$week] = array_values($playoffMatchups);
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }

            if ($matchupsByWeek === []) {
                return $this->emptyBracket($seasonYear);
            }

            return $this->buildBracket($matchupsByWeek, $seasonYear);
        } catch (Throwable $e) {
            return $this->emptyBracket($seasonYear);
        }
    }

    /**
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

        usort($chain, static fn(array $a, array $b): int => ((int) $a['season']) <=> ((int) $b['season']));

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
        ];
    }

    /** @return array{playoff_start_week: int, end_week: int} */
    private function parseSettings(array $raw): array
    {
        $settings = $raw['fantasy_content']['league'][1]['settings'] ?? null;

        if (!is_array($settings)) {
            throw new RuntimeException('Unexpected Yahoo API response structure for league settings.');
        }

        return [
            'playoff_start_week' => (int) ($settings['playoff_start_week'] ?? 0),
            'end_week' => (int) ($settings['end_week'] ?? 0),
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
        $scoreboard = $raw['fantasy_content']['league'][1]['scoreboard'] ?? null;
        if (!is_array($scoreboard)) {
            return [];
        }

        $matchupsRaw = $scoreboard[0]['matchups'] ?? $scoreboard['0']['matchups'] ?? $scoreboard['matchups'] ?? null;
        if (!is_array($matchupsRaw)) {
            return [];
        }

        $matchups = [];

        foreach ($matchupsRaw as $key => $entry) {
            if ($key === 'count') {
                continue;
            }

            $matchup = $entry['matchup'] ?? null;
            if (!is_array($matchup)) {
                continue;
            }

            $isPlayoffs = (string) ($matchup['is_playoffs'] ?? '0') === '1';
            $isConsolation = (string) ($matchup['is_consolation'] ?? '0') === '1';

            $teams = $matchup['teams'] ?? null;
            if (!is_array($teams)) {
                continue;
            }

            $team1Block = $teams[0]['team'] ?? $teams['0']['team'] ?? null;
            $team2Block = $teams[1]['team'] ?? $teams['1']['team'] ?? null;

            if (!is_array($team1Block) || !is_array($team2Block)) {
                continue;
            }

            $team1Name = $this->extractTeamName($team1Block);
            $team2Name = $this->extractTeamName($team2Block);

            if ($team1Name === null || $team2Name === null) {
                continue;
            }

            $team1Score = $this->extractTeamScore($team1Block);
            $team2Score = $this->extractTeamScore($team2Block);

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
                'is_playoffs' => $isPlayoffs,
                'is_consolation' => $isConsolation,
            ];
        }

        return $matchups;
    }

    private function extractTeamName(array $teamBlock): ?string
    {
        $items = $teamBlock[0] ?? null;
        if (!is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                return $item['name'];
            }
        }

        return null;
    }

    private function extractTeamScore(array $teamBlock): ?float
    {
        $points = $teamBlock[1]['team_points']['total'] ?? null;
        if ($points === null || $points === '') {
            return null;
        }

        return (float) $points;
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
