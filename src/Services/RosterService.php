<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\YahooApiClient;
use RuntimeException;

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
        $raw = $this->api->get("league/{$this->leagueKey}/teams/roster/players");

        return $this->parseRosters($raw);
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
                $teams[] = $team;
            }
        }

        // Sort teams alphabetically by name for consistent display.
        usort($teams, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $teams;
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
}
