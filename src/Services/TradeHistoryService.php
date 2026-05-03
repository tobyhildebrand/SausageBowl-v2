<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;
use Throwable;

class TradeHistoryService
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
     * Fetch all completed trades for every season since startSeason.
     *
     * @return array{
     *   seasons: int[],
     *   managers: string[],
     *   trades: array<int, array{
     *     season: int,
     *     date: string,
     *     timestamp: int,
     *     side_a: array{team_name: string, assets: string[]},
     *     side_b: array{team_name: string, assets: string[]}
     *   }>,
     *   errors: array<int, string>
     * }
     */
    public function getAllTrades(): array
    {
        $leagues = $this->collectLeagueChain();

        if ($leagues === []) {
            return ['seasons' => [], 'managers' => [], 'trades' => [], 'errors' => []];
        }

        usort($leagues, static fn(array $a, array $b): int => $b['season'] <=> $a['season']);

        $allTrades = [];
        $seasons = [];
        $managersSet = [];
        $errors = [];

        foreach ($leagues as $league) {
            $season = (int) $league['season'];
            $seasons[] = $season;

            try {
                $raw = $this->api->get(
                    'league/' . $league['league_key'] . '/transactions;types=trade'
                );
                $trades = $this->parseTransactions($raw, $season);
                foreach ($trades as $trade) {
                    $allTrades[] = $trade;
                    $managersSet[$trade['side_a']['team_name']] = true;
                    $managersSet[$trade['side_b']['team_name']] = true;
                }
            } catch (Throwable $e) {
                $errors[$season] = $e->getMessage();
            }
        }

        // Sort trades: newest season first, then by timestamp descending
        usort($allTrades, static function (array $a, array $b): int {
            if ($a['season'] !== $b['season']) {
                return $b['season'] <=> $a['season'];
            }
            return $b['timestamp'] <=> $a['timestamp'];
        });

        $managers = array_keys($managersSet);
        sort($managers);

        rsort($seasons);
        $seasons = array_values(array_unique($seasons));

        return [
            'seasons' => $seasons,
            'managers' => $managers,
            'trades' => $allTrades,
            'errors' => $errors,
        ];
    }

    /**
     * Parse the transactions API response into normalised trade records.
     *
     * @return array<int, array{
     *   season: int,
     *   date: string,
     *   timestamp: int,
     *   side_a: array{team_name: string, assets: string[]},
     *   side_b: array{team_name: string, assets: string[]}
     * }>
     */
    private function parseTransactions(array $raw, int $season): array
    {
        $leagueData = $raw['fantasy_content']['league'] ?? null;

        if (!is_array($leagueData) || !isset($leagueData[1])) {
            return [];
        }

        $txSection = $leagueData[1]['transactions'] ?? null;

        if (!is_array($txSection)) {
            return [];
        }

        $trades = [];

        foreach ($txSection as $key => $value) {
            // Skip the count key
            if ($key === 'count' || !is_array($value)) {
                continue;
            }

            $txWrapper = $value['transaction'] ?? null;
            if (!is_array($txWrapper)) {
                continue;
            }

            // txWrapper[0] = transaction meta, txWrapper[1] = players section
            $meta = is_array($txWrapper[0] ?? null) ? $txWrapper[0] : null;
            if ($meta === null) {
                continue;
            }

            $type = (string) ($meta['type'] ?? '');
            $status = (string) ($meta['status'] ?? '');

            if ($type !== 'trade' || $status !== 'successful') {
                continue;
            }

            $timestamp = (int) ($meta['timestamp'] ?? 0);
            $traderTeamName = (string) ($meta['trader_team_name'] ?? '');
            $tradeeTeamName = (string) ($meta['tradee_team_name'] ?? '');

            if ($traderTeamName === '' || $tradeeTeamName === '') {
                continue;
            }

            $date = $timestamp > 0
                ? date('Y-m-d', $timestamp)
                : '';

            // Parse player/pick assets
            $playersSection = $txWrapper[1]['players'] ?? null;

            $sideAAssets = []; // assets sent BY trader → received by tradee
            $sideBAssets = []; // assets sent BY tradee → received by trader

            if (is_array($playersSection)) {
                foreach ($playersSection as $pKey => $pValue) {
                    if ($pKey === 'count' || !is_array($pValue)) {
                        continue;
                    }

                    $playerWrapper = $pValue['player'] ?? null;
                    if (!is_array($playerWrapper)) {
                        continue;
                    }

                    // playerWrapper[0] = array of player meta fragments
                    // playerWrapper[1] = { transaction_data: { ... } }
                    $assetName = $this->extractAssetName($playerWrapper[0] ?? []);
                    $txData = $playerWrapper[1]['transaction_data'] ?? [];

                    $sourceTeamName = (string) ($txData['source_team_name'] ?? '');

                    if ($assetName === '') {
                        continue;
                    }

                    // Group by which side sent this asset
                    if ($sourceTeamName === $traderTeamName) {
                        $sideAAssets[] = $assetName;
                    } else {
                        $sideBAssets[] = $assetName;
                    }
                }
            }

            $trades[] = [
                'season' => $season,
                'date' => $date,
                'timestamp' => $timestamp,
                'side_a' => [
                    'team_name' => $traderTeamName,
                    'assets' => $sideAAssets,
                ],
                'side_b' => [
                    'team_name' => $tradeeTeamName,
                    'assets' => $sideBAssets,
                ],
            ];
        }

        return $trades;
    }

    /**
     * Extract a human-readable asset name from the Yahoo player meta fragment array.
     * Each fragment in playerWrapper[0] is an associative array with one or more fields.
     *
     * @param mixed $fragments
     */
    private function extractAssetName($fragments): string
    {
        if (!is_array($fragments)) {
            return '';
        }

        $fullName = '';
        $displayPosition = '';
        $isPick = false;

        foreach ($fragments as $fragment) {
            if (!is_array($fragment)) {
                continue;
            }

            if (isset($fragment['full_name']) && (string) $fragment['full_name'] !== '') {
                $fullName = (string) $fragment['full_name'];
            }

            if (isset($fragment['display_position'])) {
                $displayPosition = (string) $fragment['display_position'];
            }

            // Draft picks in Yahoo have a specific flag or name pattern
            if (isset($fragment['is_undroppable'])) {
                // not a pick indicator, skip
            }

            // Sometimes pick name comes directly as full_name like "2024 Pick"
        }

        if ($fullName !== '') {
            return $fullName;
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // League chain traversal – same pattern as HistoricalStatsService
    // -------------------------------------------------------------------------

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
            } catch (Throwable $e) {
                break;
            }

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
}
