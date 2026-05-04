<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;
use Throwable;

/**
 * RosterService – fetches and parses all team rosters from the Yahoo API.
 *
 * Yahoo API endpoint used:
 *   league/{league_key}/teams/roster/players
 *
 * Returns all 12 teams with their full rosters in one call.
 *
 * Returned shape per team:
 *   [
 *     'key'      => 'nfl.l.28841.t.1',
 *     'name'     => 'Team Name',
 *     'managers' => ['Owner Name'],
 *     'players'  => [
 *       [
 *         'name'               => 'Patrick Mahomes',
 *         'nfl_team'           => 'KC',
 *         'primary_position'   => 'QB',
 *         'selected_position'  => 'QB',   // actual lineup slot
 *         'status'             => '',      // 'IR', 'Q', etc.
 *       ],
 *       ...
 *     ],
 *   ]
 */
class RosterService
{
    private const EXCLUDED_TEAM_NAME_PARTS = ['mordlustig', 'tru. crew', 'tru crew'];

    // Position display order for roster cards.
    public const POSITION_ORDER = ['QB', 'WR', 'RB', 'TE', 'K', 'DEF', 'BN', 'IR'];

    private YahooApiClient $api;
    private string         $leagueKey;

    public function __construct(YahooApiClient $api, string $leagueKey)
    {
        $this->api       = $api;
        $this->leagueKey = $leagueKey;
    }

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Fetch all rosters and return them grouped by team.
     *
     * @return array[]  Indexed array of teams, each with a 'players' sub-array.
     */
    public function getAllRosters(): array
    {
        $resolvedKey = $this->resolveLatestLeagueKey($this->leagueKey);
        $raw = $this->fetchRosterPayload($resolvedKey);

        return $this->parseRosters($raw);
    }

    /**
     * Fetch roster data including league-level metadata useful for UI state.
     *
     * @return array{teams: array<int, array<string, mixed>>, league: array<string, mixed>}
     */
    public function getRosterOverview(): array
    {
        // Walk the renew chain forward so we start from the most recent league.
        // This avoids crashes when the configured key belongs to an old season
        // whose roster endpoint Yahoo no longer serves.
        $resolvedKey = $this->resolveLatestLeagueKey($this->leagueKey);

        $raw = $this->fetchRosterPayload($resolvedKey);
        $leagueMeta = $this->parseLeagueMeta($raw);
        $sourceLeagueMeta = $leagueMeta;
        $teams = $this->parseRosters($raw);

        $usedFallbackLeagueKey = null;
        $draftStatus = strtolower((string) ($leagueMeta['draft_status'] ?? ''));
        // Trigger fallback for predraft (empty rosters) OR postdraft (season ended,
        // offseason add/drop transactions live in the renewed next-season league).
        $shouldTryRenewed = ($draftStatus === 'postdraft')
            || ($draftStatus === 'predraft' && $this->areAllTeamsEmpty($teams));
        if ($shouldTryRenewed) {
            $renewedLeagueKey = $this->buildRenewedLeagueKey((string) ($leagueMeta['renew'] ?? ''));
            if ($renewedLeagueKey !== null) {
                $fallbackRaw = $this->fetchRosterPayload($renewedLeagueKey);
                $fallbackMeta = $this->parseLeagueMeta($fallbackRaw);
                $fallbackTeams = $this->parseRosters($fallbackRaw);

                if (!$this->areAllTeamsEmpty($fallbackTeams)) {
                    $teams = $fallbackTeams;
                    $sourceLeagueMeta = $fallbackMeta;
                    $usedFallbackLeagueKey = $renewedLeagueKey;
                }
            }
        }

        return [
            'teams'  => $teams,
            'league' => [
                'league_key'           => (string) ($sourceLeagueMeta['league_key'] ?? ''),
                'name'                 => (string) ($sourceLeagueMeta['name'] ?? ''),
                'season'               => (string) ($sourceLeagueMeta['season'] ?? ''),
                'draft_status'         => (string) ($sourceLeagueMeta['draft_status'] ?? ''),
                'current_week'         => (string) ($sourceLeagueMeta['current_week'] ?? ''),
                'used_fallback'        => $usedFallbackLeagueKey !== null,
                'fallback_league_key'  => (string) ($usedFallbackLeagueKey ?? ''),
                'configured_league_key'=> $this->leagueKey,
                'configured_season'    => (string) ($leagueMeta['season'] ?? ''),
            ],
        ];
    }

    /**
     * Given a flat player list, group players by their primary position.
     * Order follows POSITION_ORDER; unknown positions appear at the end.
     */
    public static function groupByPosition(array $players): array
    {
        $groups = [];

        foreach ($players as $player) {
            $pos = $player['primary_position'] ?? 'BN';
            $groups[$pos][] = $player;
        }

        // Sort groups according to canonical position order.
        $ordered = [];
        foreach (self::POSITION_ORDER as $pos) {
            if (isset($groups[$pos])) {
                $ordered[$pos] = $groups[$pos];
                unset($groups[$pos]);
            }
        }

        // Append any remaining unknown positions.
        foreach ($groups as $pos => $posPlayers) {
            $ordered[$pos] = $posPlayers;
        }

        return $ordered;
    }

    // -------------------------------------------------------------------------
    // Yahoo JSON parsing
    //
    // Yahoo returns a deeply nested structure. This method navigates it
    // defensively, skipping malformed entries rather than crashing.
    // -------------------------------------------------------------------------

    private function parseRosters(array $raw): array
    {
        // Navigate: fantasy_content → league → [1] → teams
        $leagueData = $raw['fantasy_content']['league'] ?? null;

        if (!is_array($leagueData) || !isset($leagueData[1]['teams'])) {
            throw new RuntimeException('Unexpected Yahoo API response structure for roster data.');
        }

        $teamsRaw = $leagueData[1]['teams'];
        $teams    = [];

        foreach ($teamsRaw as $key => $value) {
            // The 'count' key is metadata, not a team entry.
            if ($key === 'count') {
                continue;
            }

            $teamData = $value['team'] ?? null;
            if (!is_array($teamData)) {
                continue;
            }

            $team = $this->parseTeam($teamData);
            if ($team !== null) {
                if ($this->isExcludedTeamName((string) ($team['name'] ?? ''))) {
                    continue;
                }

                $teams[] = $team;
            }
        }

        // Sort teams alphabetically by name for consistent display.
        usort($teams, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $teams;
    }

    private function parseLeagueMeta(array $raw): array
    {
        $meta = $raw['fantasy_content']['league'][0] ?? [];

        if (!is_array($meta)) {
            return [];
        }

        return [
            'league_key'   => (string) ($meta['league_key'] ?? ''),
            'name'         => (string) ($meta['name'] ?? ''),
            'season'       => (string) ($meta['season'] ?? ''),
            'draft_status' => (string) ($meta['draft_status'] ?? ''),
            'current_week' => (string) ($meta['current_week'] ?? ''),
            'renew'        => (string) ($meta['renew'] ?? ''),
        ];
    }

    private function areAllTeamsEmpty(array $teams): bool
    {
        if ($teams === []) {
            return true;
        }

        foreach ($teams as $team) {
            if (!empty($team['players'])) {
                return false;
            }
        }

        return true;
    }

    private function buildRenewedLeagueKey(string $renewValue): ?string
    {
        // Yahoo renew format: "<game_id>_<league_id>" (example: 461_45436)
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
     * Walk the Yahoo league renew chain forward from $leagueKey, returning the
     * best available league key for rosters.
     *
     * Strategy:
     * 1) Follow renew chain from configured key.
     * 2) If that looks stale, augment with user's NFL league list.
     * 3) Prefer newest season that returns non-empty rosters.
     *
     * Falls back to the configured key if no candidate succeeds.
     */
    private function resolveLatestLeagueKey(string $leagueKey): string
    {
        $candidates = [];
        $seen = [];
        $key = $leagueKey;
        $maxHops = 8;
        $configuredName = '';

        // 1) Follow renew chain from configured key.
        for ($i = 0; $i < $maxHops; $i++) {
            if ($key === '' || isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;

            try {
                $meta = $this->api->get("league/{$key}");
                $leagueMeta = $this->parseLeagueMeta($meta);
                $season = (int) ($leagueMeta['season'] ?? 0);
                $name = (string) ($leagueMeta['name'] ?? '');

                if ($i === 0) {
                    $configuredName = $name;
                }

                $candidates[$key] = max((int) ($candidates[$key] ?? 0), $season);

                $renewValue = (string) ($leagueMeta['renew'] ?? '');
                $renewed = $this->buildRenewedLeagueKey($renewValue);
                if ($renewed === null) {
                    break;
                }
                $key = $renewed;
            } catch (Throwable $e) {
                break;
            }
        }

        // 2) If renew chain appears stale, augment with the user's NFL league list.
        $newestKnownSeason = 0;
        foreach ($candidates as $candidateSeason) {
            $newestKnownSeason = max($newestKnownSeason, (int) $candidateSeason);
        }

        if ($newestKnownSeason <= ((int) date('Y') - 1)) {
            $nameNeedle = $this->normalizeName($configuredName);
            $userLeagues = $this->collectUserNflLeagues();

            foreach ($userLeagues as $league) {
                $candidateKey = (string) ($league['league_key'] ?? '');
                $candidateSeason = (int) ($league['season'] ?? 0);
                $candidateName = $this->normalizeName((string) ($league['name'] ?? ''));

                if ($candidateKey === '' || $candidateSeason <= 0) {
                    continue;
                }

                if ($nameNeedle !== '') {
                    $matchesName = $candidateName !== ''
                        && (
                            strpos($candidateName, $nameNeedle) !== false
                            || strpos($nameNeedle, $candidateName) !== false
                        );

                    if (!$matchesName) {
                        continue;
                    }
                }

                $candidates[$candidateKey] = max((int) ($candidates[$candidateKey] ?? 0), $candidateSeason);
            }
        }

        if ($candidates === []) {
            return $leagueKey;
        }

        // 3) Prefer newest season that actually returns non-empty rosters.
        arsort($candidates, SORT_NUMERIC);
        $fallbackSuccessful = null;

        foreach (array_keys($candidates) as $candidateKey) {
            try {
                $raw = $this->fetchRosterPayload($candidateKey);
                $teams = $this->parseRosters($raw);

                if (!$this->areAllTeamsEmpty($teams)) {
                    return $candidateKey;
                }

                if ($fallbackSuccessful === null) {
                    $fallbackSuccessful = $candidateKey;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $fallbackSuccessful ?? $leagueKey;
    }

    /**
     * @return array<int, array{league_key: string, season: int, name: string}>
     */
    private function collectUserNflLeagues(): array
    {
        try {
            $raw = $this->api->get('users;use_login/games;game_keys=nfl/leagues');
        } catch (Throwable $e) {
            return [];
        }

        $results = [];
        $this->collectUserNflLeaguesRecursive($raw, $results);

        if ($results === []) {
            return [];
        }

        $deduped = [];
        foreach ($results as $row) {
            $key = (string) ($row['league_key'] ?? '');
            if ($key === '') {
                continue;
            }

            if (!isset($deduped[$key]) || ((int) $row['season'] > (int) $deduped[$key]['season'])) {
                $deduped[$key] = $row;
            }
        }

        return array_values($deduped);
    }

    /**
     * @param mixed $node
     * @param array<int, array{league_key: string, season: int, name: string}> $results
     */
    private function collectUserNflLeaguesRecursive($node, array &$results): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node[0]) && is_array($node[0])) {
            $flat = [];
            foreach ($node[0] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($item as $k => $v) {
                    $flat[$k] = $v;
                }
            }

            $leagueKey = (string) ($flat['league_key'] ?? '');
            $season = (int) ($flat['season'] ?? 0);
            if ($leagueKey !== '' && $season > 0) {
                $results[] = [
                    'league_key' => $leagueKey,
                    'season' => $season,
                    'name' => (string) ($flat['name'] ?? ''),
                ];
            }
        }

        foreach ($node as $child) {
            $this->collectUserNflLeaguesRecursive($child, $results);
        }
    }

    private function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '', $value);
        return is_string($normalized) ? $normalized : '';
    }

    private function fetchRosterPayload(string $leagueKey): array
    {
        $today = date('Y-m-d');

        // Prefer as-of-date roster to include offseason adds/drops.
        try {
            return $this->api->get("league/{$leagueKey}/teams/roster;date={$today}/players");
        } catch (Throwable $e) {
            // Fallback to default roster endpoint for leagues that reject date-scoped calls.
            return $this->api->get("league/{$leagueKey}/teams/roster/players");
        }
    }

    private function parseTeam(array $teamData): ?array
    {
        // teamData[0] is an array of team meta fields (each a one-key array).
        $meta = $teamData[0] ?? [];
        if (!is_array($meta)) {
            return null;
        }

        // Flatten meta array: each element is ['field_name' => value].
        $flat = [];
        foreach ($meta as $item) {
            if (is_array($item)) {
                foreach ($item as $k => $v) {
                    $flat[$k] = $v;
                }
            }
        }

        $teamKey  = $flat['team_key']  ?? '';
        $teamName = $flat['name']      ?? 'Unknown Team';
        $teamLogo = '';

        if (isset($flat['team_logos'][0]['team_logo']['url']) && is_string($flat['team_logos'][0]['team_logo']['url'])) {
            $teamLogo = $flat['team_logos'][0]['team_logo']['url'];
        }

        // Managers are nested inside 'managers' → [0] → 'manager' → nickname.
        $managers = [];
        $rawMgrs  = $flat['managers'] ?? [];
        foreach ($rawMgrs as $mgr) {
            $nickname = $mgr['manager']['nickname'] ?? null;
            if ($nickname !== null) {
                $managers[] = $nickname;
            }
        }

        // Roster players are in teamData[1]['roster']['0']['players'].
        $rosterSection = $teamData[1]['roster'] ?? [];
        $playersRaw    = $rosterSection['0']['players'] ?? [];

        $players = [];
        foreach ($playersRaw as $pKey => $pValue) {
            if ($pKey === 'count') {
                continue;
            }

            $playerData = $pValue['player'] ?? null;
            if (!is_array($playerData)) {
                continue;
            }

            $player = $this->parsePlayer($playerData);
            if ($player !== null) {
                $players[] = $player;
            }
        }

        return [
            'key'      => $teamKey,
            'name'     => $teamName,
            'logo_url' => $teamLogo,
            'managers' => $managers,
            'players'  => $players,
        ];
    }

    private function parsePlayer(array $playerData): ?array
    {
        // playerData[0] is the player meta array (same flattening pattern as team).
        $meta = $playerData[0] ?? [];
        if (!is_array($meta)) {
            return null;
        }

        $flat = [];
        foreach ($meta as $item) {
            if (is_array($item)) {
                foreach ($item as $k => $v) {
                    $flat[$k] = $v;
                }
            }
        }

        // Full name is nested under 'name' → 'full'.
        $name = $flat['name']['full'] ?? ($flat['name'] ?? 'Unknown Player');

        // Eligible positions: array of ['position' => 'QB'] entries.
        $primaryPosition = '';
        $eligibleRaw     = $flat['eligible_positions'] ?? [];
        foreach ($eligibleRaw as $ep) {
            $pos = $ep['position'] ?? '';
            if ($pos !== '' && !in_array($pos, ['BN', 'IR', 'W/R', 'W/T', 'Q/W/R/T', 'W/R/T'], true)) {
                $primaryPosition = $pos;
                break;
            }
        }

        // Selected (lineup slot) position is in playerData[1].
        $selectedPosition = $playerData[1]['selected_position']['position'] ?? $primaryPosition;

        $nflTeam = $flat['editorial_team_abbr'] ?? '';
        $status  = $flat['status']              ?? '';

        return [
            'name'              => $name,
            'nfl_team'          => strtoupper($nflTeam),
            'primary_position'  => $primaryPosition ?: $selectedPosition,
            'selected_position' => $selectedPosition,
            'status'            => $status,
        ];
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
