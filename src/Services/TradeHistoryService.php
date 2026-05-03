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
    /**
     * Return the raw Yahoo API response for transactions.
     *
     * @param int $season If > 0, tries to fetch that season; otherwise newest season.
     */
    public function getRawTransactions(int $season = 0): array
    {
        $leagues = $this->collectLeagueChain();
        if ($leagues === []) {
            return [];
        }

        usort($leagues, static fn(array $a, array $b): int => $b['season'] <=> $a['season']);

        $league = $leagues[0];
        if ($season > 0) {
            foreach ($leagues as $candidate) {
                if ((int) ($candidate['season'] ?? 0) === $season) {
                    $league = $candidate;
                    break;
                }
            }
        }

        return $this->api->get('league/' . $league['league_key'] . '/transactions;types=trade;out=players');
    }

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
                    'league/' . $league['league_key'] . '/transactions;types=trade;out=players'
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

            // Parse player/pick assets. Yahoo may place players either under [1]['players']
            // or directly under ['players'] depending on the response shape.
            $playersSection = $txWrapper[1]['players'] ?? ($txWrapper['players'] ?? null);

            // Picks are returned inside transaction meta as an array of { pick: { ... } }.
            $picksSection = $meta['picks'] ?? [];

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

                    // playerWrapper[0] is usually meta fragments; some responses are associative.
                    $assetName = $this->extractAssetName($playerWrapper[0] ?? $playerWrapper);
                    $txData = $playerWrapper[1]['transaction_data'] ?? ($playerWrapper['transaction_data'] ?? []);

                    // transaction_data is commonly an array with one entry.
                    $txDataEntry = $txData;
                    if (is_array($txData) && isset($txData[0]) && is_array($txData[0])) {
                        $txDataEntry = $txData[0];
                    }

                    $sourceTeamName = (string) ($txDataEntry['source_team_name'] ?? '');
                    $destinationTeamName = (string) ($txDataEntry['destination_team_name'] ?? '');

                    if ($assetName === '') {
                        continue;
                    }

                    // Group by which side sent this asset
                    if ($sourceTeamName === $traderTeamName) {
                        $sideAAssets[] = $assetName;
                    } elseif ($sourceTeamName === $tradeeTeamName) {
                        $sideBAssets[] = $assetName;
                    } elseif ($destinationTeamName === $traderTeamName) {
                        // If source is missing, infer by destination.
                        $sideBAssets[] = $assetName;
                    } else {
                        $sideAAssets[] = $assetName;
                    }
                }
            }

            if (is_array($picksSection)) {
                foreach ($picksSection as $pickNode) {
                    if (!is_array($pickNode) || !isset($pickNode['pick']) || !is_array($pickNode['pick'])) {
                        continue;
                    }

                    $pick = $pickNode['pick'];
                    $sourceTeamName = (string) ($pick['source_team_name'] ?? '');
                    $round = trim((string) ($pick['round'] ?? ''));

                    $pickLabel = $round !== '' ? 'Pick round ' . $round : 'Draft pick';

                    if ($sourceTeamName === $traderTeamName) {
                        $sideAAssets[] = $pickLabel;
                    } elseif ($sourceTeamName === $tradeeTeamName) {
                        $sideBAssets[] = $pickLabel;
                    }
                }
            }

            $sideAAssets = array_values(array_unique($sideAAssets));
            $sideBAssets = array_values(array_unique($sideBAssets));

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
     *
     * Yahoo returns player meta as a list of single-key arrays, e.g.:
     *   [0] => ["player_key" => "..."]
     *   [1] => ["player_id" => "..."]
     *   [2] => ["name" => ["full" => "Patrick Mahomes", ...]]
     *   [3] => ["display_position" => "QB"]
     *   ...
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

        // Case 1: associative form: ['name' => ['full' => ...], 'display_position' => ...]
        if (isset($fragments['name']['full']) && is_string($fragments['name']['full'])) {
            $fullName = trim($fragments['name']['full']);
        }
        if (isset($fragments['full_name']) && is_string($fragments['full_name']) && trim($fragments['full_name']) !== '') {
            $fullName = trim($fragments['full_name']);
        }
        if (isset($fragments['display_position']) && is_string($fragments['display_position'])) {
            $displayPosition = trim($fragments['display_position']);
        }

        // Case 2: list-of-fragments form used by Yahoo.
        foreach ($fragments as $fragment) {
            if (!is_array($fragment)) {
                continue;
            }

            // Name is nested: { "name": { "full": "Patrick Mahomes" } }
            if (isset($fragment['name']['full']) && (string) $fragment['name']['full'] !== '') {
                $fullName = (string) $fragment['name']['full'];
            }

            if (isset($fragment['display_position']) && (string) $fragment['display_position'] !== '') {
                $displayPosition = (string) $fragment['display_position'];
            }
        }

        // Fallback: recursive lookup for older/newer response shapes.
        if ($fullName === '') {
            $nameNode = $this->findFirstNodeByKey($fragments, 'name');
            if (is_array($nameNode) && isset($nameNode['full']) && is_string($nameNode['full'])) {
                $fullName = trim($nameNode['full']);
            }
        }

        if ($displayPosition === '') {
            $positionNode = $this->findFirstNodeByKey($fragments, 'display_position');
            if (is_string($positionNode)) {
                $displayPosition = trim($positionNode);
            }
        }

        if ($fullName === '') {
            return '';
        }

        if ($displayPosition !== '') {
            return $fullName . ' (' . $displayPosition . ')';
        }

        return $fullName;
    }

    /** @param mixed $node
     *  @return mixed
     */
    private function findFirstNodeByKey($node, string $targetKey)
    {
        if (!is_array($node)) {
            return null;
        }

        if (array_key_exists($targetKey, $node)) {
            return $node[$targetKey];
        }

        foreach ($node as $child) {
            $found = $this->findFirstNodeByKey($child, $targetKey);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
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
