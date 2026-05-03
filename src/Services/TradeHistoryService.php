<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;
use Throwable;

class TradeHistoryService
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
    *   stats_rows: array<int, array{team_name: string, total_trades: int, free_agent_adds: int}>,
    *   errors: array<int, string>,
    *   stats_errors: array<int, string>
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
        $statsErrors = [];
        $statsByManager = [];
        $teamDirectoryById = [];
        $teamLookupBySeason = [];

        foreach ($leagues as $league) {
            $season = (int) $league['season'];
            $seasons[] = $season;
            $leagueKey = (string) ($league['league_key'] ?? '');

            try {
                $standingsRaw = $this->api->get('league/' . $leagueKey . '/standings');
                $seasonTeams = $this->parseStandingsDirectory($standingsRaw);

                foreach ($seasonTeams as $team) {
                    $identity = (string) $team['identity'];
                    $teamLookupBySeason[$season][$this->normalizeName((string) $team['name'])] = $identity;

                    if (!isset($teamDirectoryById[$identity])) {
                        $teamDirectoryById[$identity] = [
                            'team_name' => (string) $team['name'],
                            'logo_url' => (string) $team['logo_url'],
                            'last_seen_season' => $season,
                        ];
                    } elseif ($season >= (int) ($teamDirectoryById[$identity]['last_seen_season'] ?? 0)) {
                        $teamDirectoryById[$identity]['team_name'] = (string) $team['name'];
                        $teamDirectoryById[$identity]['logo_url'] = (string) $team['logo_url'];
                        $teamDirectoryById[$identity]['last_seen_season'] = $season;
                    }
                }
            } catch (Throwable $e) {
                // Best effort only. Trade data can still render without standings identity mapping.
            }

            try {
                $raw = $this->api->get(
                    'league/' . $leagueKey . '/transactions;types=trade;out=players'
                );
                $trades = $this->parseTransactions($raw, $season);
                foreach ($trades as $trade) {
                    $resolvedTrade = $this->resolveTradeTeams($trade, $season, $teamLookupBySeason, $teamDirectoryById);
                    if ($resolvedTrade === null) {
                        continue;
                    }

                    $allTrades[] = $resolvedTrade;
                    $managersSet[$resolvedTrade['side_a']['team_name']] = true;
                    $managersSet[$resolvedTrade['side_b']['team_name']] = true;
                    $statsByManager = $this->incrementTradeStat($statsByManager, $resolvedTrade['side_a']['team_name']);
                    $statsByManager = $this->incrementTradeStat($statsByManager, $resolvedTrade['side_b']['team_name']);
                }
            } catch (Throwable $e) {
                $errors[$season] = $e->getMessage();
            }

            try {
                $addRaw = $this->api->get(
                    'league/' . $leagueKey . '/transactions;out=players'
                );
                $addCounts = $this->parseFreeAgentAdds($addRaw);
                foreach ($addCounts as $teamName => $count) {
                    $teamNameString = $this->resolveCanonicalTeamName((string) $teamName, $season, $teamLookupBySeason, $teamDirectoryById);
                    if ($teamNameString === null || $teamNameString === '' || $count <= 0) {
                        continue;
                    }

                    $managersSet[$teamNameString] = true;
                    if (!isset($statsByManager[$teamNameString])) {
                        $statsByManager[$teamNameString] = [
                            'team_name' => $teamNameString,
                            'total_trades' => 0,
                            'free_agent_adds' => 0,
                        ];
                    }
                    $statsByManager[$teamNameString]['free_agent_adds'] += $count;
                }
            } catch (Throwable $e) {
                $statsErrors[$season] = $e->getMessage();
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

        foreach ($managers as $manager) {
            if (!isset($statsByManager[$manager])) {
                $statsByManager[$manager] = [
                    'team_name' => $manager,
                    'total_trades' => 0,
                    'free_agent_adds' => 0,
                ];
            }
        }

        $statsRows = array_values($statsByManager);
        usort($statsRows, static function (array $a, array $b): int {
            if ($a['total_trades'] !== $b['total_trades']) {
                return $b['total_trades'] <=> $a['total_trades'];
            }
            if ($a['free_agent_adds'] !== $b['free_agent_adds']) {
                return $b['free_agent_adds'] <=> $a['free_agent_adds'];
            }
            return strcasecmp((string) $a['team_name'], (string) $b['team_name']);
        });

        rsort($seasons);
        $seasons = array_values(array_unique($seasons));

        return [
            'seasons' => $seasons,
            'managers' => $managers,
            'trades' => $allTrades,
            'stats_rows' => $statsRows,
            'errors' => $errors,
            'stats_errors' => $statsErrors,
        ];
    }

    /**
     * @param array<string, array{team_name: string, total_trades: int, free_agent_adds: int}> $statsByManager
     * @return array<string, array{team_name: string, total_trades: int, free_agent_adds: int}>
     */
    private function incrementTradeStat(array $statsByManager, string $teamName): array
    {
        if ($teamName === '') {
            return $statsByManager;
        }

        if (!isset($statsByManager[$teamName])) {
            $statsByManager[$teamName] = [
                'team_name' => $teamName,
                'total_trades' => 0,
                'free_agent_adds' => 0,
            ];
        }

        $statsByManager[$teamName]['total_trades']++;
        return $statsByManager;
    }

    /**
     * @return array<string, int>
     */
    private function parseFreeAgentAdds(array $raw): array
    {
        $leagueData = $raw['fantasy_content']['league'] ?? null;

        if (!is_array($leagueData) || !isset($leagueData[1])) {
            return [];
        }

        $txSection = $leagueData[1]['transactions'] ?? null;
        if (!is_array($txSection)) {
            return [];
        }

        $counts = [];

        foreach ($txSection as $key => $value) {
            if ($key === 'count' || !is_array($value)) {
                continue;
            }

            $txWrapper = $value['transaction'] ?? null;
            if (!is_array($txWrapper)) {
                continue;
            }

            $meta = is_array($txWrapper[0] ?? null) ? $txWrapper[0] : null;
            if ($meta === null) {
                continue;
            }

            $type = strtolower((string) ($meta['type'] ?? ''));
            $status = strtolower((string) ($meta['status'] ?? 'successful'));
            if (!in_array($type, ['add', 'add/drop'], true) || !in_array($status, ['successful', ''], true)) {
                continue;
            }

            $playersSection = $txWrapper[1]['players'] ?? ($txWrapper['players'] ?? null);
            if (!is_array($playersSection)) {
                continue;
            }

            foreach ($playersSection as $playerKey => $playerValue) {
                if ($playerKey === 'count' || !is_array($playerValue)) {
                    continue;
                }

                $playerWrapper = $playerValue['player'] ?? null;
                if (!is_array($playerWrapper)) {
                    continue;
                }

                $txData = $playerWrapper[1]['transaction_data'] ?? ($playerWrapper['transaction_data'] ?? []);
                $txDataEntry = $txData;
                if (is_array($txData) && isset($txData[0]) && is_array($txData[0])) {
                    $txDataEntry = $txData[0];
                }

                $sourceType = strtolower((string) ($txDataEntry['source_type'] ?? ''));
                $destinationTeamName = (string) ($txDataEntry['destination_team_name'] ?? '');

                if ($sourceType !== 'freeagents' || $destinationTeamName === '') {
                    continue;
                }

                $counts[$destinationTeamName] = (int) ($counts[$destinationTeamName] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array{season: int, date: string, timestamp: int, side_a: array{team_name: string, assets: string[]}, side_b: array{team_name: string, assets: string[]}} $trade
     * @param array<int, array<string, string>> $teamLookupBySeason
     * @param array<string, array{team_name: string, logo_url: string, last_seen_season: int}> $teamDirectoryById
     * @return array{season: int, date: string, timestamp: int, side_a: array{team_name: string, assets: string[]}, side_b: array{team_name: string, assets: string[]}}|null
     */
    private function resolveTradeTeams(array $trade, int $season, array $teamLookupBySeason, array $teamDirectoryById): ?array
    {
        $sideATeamName = $this->resolveCanonicalTeamName((string) ($trade['side_a']['team_name'] ?? ''), $season, $teamLookupBySeason, $teamDirectoryById);
        $sideBTeamName = $this->resolveCanonicalTeamName((string) ($trade['side_b']['team_name'] ?? ''), $season, $teamLookupBySeason, $teamDirectoryById);

        if ($sideATeamName === null || $sideBTeamName === null) {
            return null;
        }

        $trade['side_a']['team_name'] = $sideATeamName;
        $trade['side_b']['team_name'] = $sideBTeamName;

        return $trade;
    }

    /**
     * @param array<int, array<string, string>> $teamLookupBySeason
     * @param array<string, array{team_name: string, logo_url: string, last_seen_season: int}> $teamDirectoryById
     */
    private function resolveCanonicalTeamName(string $rawTeamName, int $season, array $teamLookupBySeason, array $teamDirectoryById): ?string
    {
        if ($rawTeamName === '') {
            return null;
        }

        if ($this->isExcludedTeamName($rawTeamName)) {
            return null;
        }

        $normalizedName = $this->normalizeName($rawTeamName);
        $identity = $teamLookupBySeason[$season][$normalizedName] ?? null;

        if ($identity !== null && isset($teamDirectoryById[$identity]['team_name'])) {
            return (string) $teamDirectoryById[$identity]['team_name'];
        }

        return $rawTeamName;
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
