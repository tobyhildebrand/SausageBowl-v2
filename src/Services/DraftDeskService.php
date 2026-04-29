<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class DraftDeskService
{
    public function getUpcomingSeasonYearReference(int $referenceYear): ?int
    {
        $pdo = DB::get();

        $nextStmt = $pdo->prepare(
            'SELECT season_year
             FROM draft_upcoming_settings
             WHERE season_year >= :reference
             ORDER BY season_year ASC
             LIMIT 1'
        );
        $nextStmt->execute([':reference' => $referenceYear]);
        $nextSeason = $nextStmt->fetchColumn();

        if ($nextSeason !== false) {
            return (int) $nextSeason;
        }

        $latestStmt = $pdo->query(
            'SELECT season_year
             FROM draft_upcoming_settings
             ORDER BY season_year DESC
             LIMIT 1'
        );
        $latestSeason = $latestStmt !== false ? $latestStmt->fetchColumn() : false;

        return $latestSeason === false ? null : (int) $latestSeason;
    }

    public function getUpcomingSeasonData(int $seasonYear): array
    {
        $pdo = DB::get();

        $settingsStmt = $pdo->prepare('SELECT round_count FROM draft_upcoming_settings WHERE season_year = :season LIMIT 1');
        $settingsStmt->execute([':season' => $seasonYear]);
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $roundCount = max(1, min(20, (int) ($settings['round_count'] ?? 8)));

        $orderStmt = $pdo->prepare(
            'SELECT slot_no, team_name
             FROM draft_upcoming_order
             WHERE season_year = :season
             ORDER BY slot_no ASC'
        );
        $orderStmt->execute([':season' => $seasonYear]);
        $orderRows = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        $overridesStmt = $pdo->prepare(
            'SELECT id, season_year, round_no, slot_no, from_team_name, current_owner_name, note, created_at
             FROM draft_pick_overrides
             WHERE season_year = :season
             ORDER BY round_no ASC, slot_no ASC'
        );
        $overridesStmt->execute([':season' => $seasonYear]);
        $overrideRows = $overridesStmt->fetchAll(PDO::FETCH_ASSOC);

        $order = [];
        foreach ($orderRows as $row) {
            $order[(int) $row['slot_no']] = (string) $row['team_name'];
        }

        $overrideByRoundSlot = [];
        foreach ($overrideRows as $row) {
            $round = (int) $row['round_no'];
            $slot = (int) $row['slot_no'];
            $overrideByRoundSlot[$round][$slot] = $row;
        }

        $boardRows = [];
        foreach ($order as $slotNo => $teamName) {
            $row = [
                'slot_no' => $slotNo,
                'default_team' => $teamName,
                'rounds' => [],
            ];

            for ($round = 1; $round <= $roundCount; $round++) {
                $override = $overrideByRoundSlot[$round][$slotNo] ?? null;
                $owner = $override !== null ? (string) $override['current_owner_name'] : $teamName;

                $row['rounds'][$round] = [
                    'owner' => $owner,
                    'is_changed' => $override !== null,
                    'note' => $override !== null ? (string) ($override['note'] ?? '') : '',
                    'override_id' => $override !== null ? (int) $override['id'] : null,
                ];
            }

            $boardRows[] = $row;
        }

        return [
            'season_year' => $seasonYear,
            'round_count' => $roundCount,
            'default_order' => $order,
            'board_rows' => $boardRows,
            'overrides' => $overrideRows,
        ];
    }

    public function saveUpcomingSetup(int $seasonYear, int $roundCount, string $defaultOrderText): void
    {
        if ($seasonYear < 2020 || $seasonYear > 2100) {
            throw new InvalidArgumentException('Season year is out of range.');
        }

        $roundCount = max(1, min(20, $roundCount));
        $teams = $this->parseTeamList($defaultOrderText);

        $this->saveUpcomingSetupByOrder($seasonYear, $roundCount, $teams);
    }

    /**
     * @param array<int, array{team_name: string, slot_no: int}> $assignments
     */
    public function saveUpcomingSetupByAssignments(int $seasonYear, int $roundCount, array $assignments): void
    {
        if ($seasonYear < 2020 || $seasonYear > 2100) {
            throw new InvalidArgumentException('Season year is out of range.');
        }

        $roundCount = max(1, min(20, $roundCount));

        if (count($assignments) < 4) {
            throw new InvalidArgumentException('Please assign at least 4 teams.');
        }

        $teamsBySlot = [];
        foreach ($assignments as $assignment) {
            $teamName = trim((string) ($assignment['team_name'] ?? ''));
            $slotNo = (int) ($assignment['slot_no'] ?? 0);

            if ($teamName === '') {
                throw new InvalidArgumentException('All teams in step 1 must have a name.');
            }

            if ($slotNo <= 0) {
                throw new InvalidArgumentException('Each team must have a pick number assigned.');
            }

            if (isset($teamsBySlot[$slotNo])) {
                throw new InvalidArgumentException('Each pick number can only be assigned once.');
            }

            $teamsBySlot[$slotNo] = $teamName;
        }

        ksort($teamsBySlot);
        $teams = array_values($teamsBySlot);

        $expectedSlots = range(1, count($teamsBySlot));
        if (array_keys($teamsBySlot) !== $expectedSlots) {
            throw new InvalidArgumentException('Pick numbers must be a continuous range starting at 1.');
        }

        $this->saveUpcomingSetupByOrder($seasonYear, $roundCount, $teams);
    }

    /**
     * @param string[] $teams
     */
    private function saveUpcomingSetupByOrder(int $seasonYear, int $roundCount, array $teams): void
    {
        if (count($teams) < 4) {
            throw new InvalidArgumentException('Please provide at least 4 teams in default order.');
        }

        $teams = array_values(array_unique(array_map(static fn(string $v): string => trim($v), $teams)));

        foreach ($teams as $teamName) {
            if ($teamName === '') {
                throw new InvalidArgumentException('Team names in default order cannot be empty.');
            }
        }

        $pdo = DB::get();
        $pdo->beginTransaction();

        try {
            $settings = $pdo->prepare(
                'INSERT INTO draft_upcoming_settings (season_year, round_count)
                 VALUES (:season, :round_count)
                 ON DUPLICATE KEY UPDATE round_count = VALUES(round_count)'
            );
            $settings->execute([
                ':season' => $seasonYear,
                ':round_count' => $roundCount,
            ]);

            $deleteOrder = $pdo->prepare('DELETE FROM draft_upcoming_order WHERE season_year = :season');
            $deleteOrder->execute([':season' => $seasonYear]);

            $insertOrder = $pdo->prepare(
                'INSERT INTO draft_upcoming_order (season_year, slot_no, team_name)
                 VALUES (:season, :slot_no, :team_name)'
            );

            $slot = 1;
            foreach ($teams as $teamName) {
                $insertOrder->execute([
                    ':season' => $seasonYear,
                    ':slot_no' => $slot,
                    ':team_name' => $teamName,
                ]);
                $slot++;
            }

            // Keep overrides only for slots that still exist and rounds still in range.
            $cleanup = $pdo->prepare(
                'DELETE FROM draft_pick_overrides
                 WHERE season_year = :season
                   AND (slot_no > :max_slot OR round_no > :max_round)'
            );
            $cleanup->execute([
                ':season' => $seasonYear,
                ':max_slot' => count($teams),
                ':max_round' => $roundCount,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function upsertPickOverrideByFromTeam(
        int $seasonYear,
        int $roundNo,
        string $fromTeamName,
        string $currentOwnerName,
        string $note,
        int $createdByUserId
    ): void {
        $slotNo = $this->findSlotByFromTeam($seasonYear, $fromTeamName);
        $this->upsertPickOverride($seasonYear, $roundNo, $slotNo, $currentOwnerName, $note, $createdByUserId);
    }

    public function removePickOverrideByFromTeam(int $seasonYear, int $roundNo, string $fromTeamName): void
    {
        $slotNo = $this->findSlotByFromTeam($seasonYear, $fromTeamName);
        $this->removePickOverride($seasonYear, $roundNo, $slotNo);
    }

    private function findSlotByFromTeam(int $seasonYear, string $fromTeamName): int
    {
        $fromTeamName = trim($fromTeamName);
        if ($fromTeamName === '') {
            throw new InvalidArgumentException('From team is required.');
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'SELECT slot_no
             FROM draft_upcoming_order
             WHERE season_year = :season
               AND LOWER(team_name) = LOWER(:team_name)
             LIMIT 1'
        );
        $stmt->execute([
            ':season' => $seasonYear,
            ':team_name' => $fromTeamName,
        ]);

        $slotNo = $stmt->fetchColumn();
        if ($slotNo === false) {
            throw new RuntimeException('From team was not found in default order for this season.');
        }

        return (int) $slotNo;
    }

    public function upsertPickOverride(
        int $seasonYear,
        int $roundNo,
        int $slotNo,
        string $currentOwnerName,
        string $note,
        int $createdByUserId
    ): void {
        $currentOwnerName = trim($currentOwnerName);
        $note = trim($note);

        if ($roundNo <= 0 || $roundNo > 20) {
            throw new InvalidArgumentException('Round number is invalid.');
        }

        if ($slotNo <= 0 || $slotNo > 50) {
            throw new InvalidArgumentException('Slot number is invalid.');
        }

        if ($currentOwnerName === '') {
            throw new InvalidArgumentException('Current owner team name is required.');
        }

        $pdo = DB::get();

        $defaultStmt = $pdo->prepare(
            'SELECT team_name
             FROM draft_upcoming_order
             WHERE season_year = :season AND slot_no = :slot
             LIMIT 1'
        );
        $defaultStmt->execute([
            ':season' => $seasonYear,
            ':slot' => $slotNo,
        ]);
        $defaultTeam = $defaultStmt->fetchColumn();

        if (!is_string($defaultTeam) || $defaultTeam === '') {
            throw new RuntimeException('Default order slot not found for this season. Save upcoming setup first.');
        }

        if (strcasecmp($defaultTeam, $currentOwnerName) === 0) {
            $delete = $pdo->prepare(
                'DELETE FROM draft_pick_overrides
                 WHERE season_year = :season AND round_no = :round AND slot_no = :slot'
            );
            $delete->execute([
                ':season' => $seasonYear,
                ':round' => $roundNo,
                ':slot' => $slotNo,
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO draft_pick_overrides
                (season_year, round_no, slot_no, from_team_name, current_owner_name, note, created_by_user_id)
             VALUES
                (:season, :round, :slot, :from_team, :owner, :note, :created_by)
             ON DUPLICATE KEY UPDATE
                from_team_name = VALUES(from_team_name),
                current_owner_name = VALUES(current_owner_name),
                note = VALUES(note),
                created_by_user_id = VALUES(created_by_user_id)'
        );

        $stmt->execute([
            ':season' => $seasonYear,
            ':round' => $roundNo,
            ':slot' => $slotNo,
            ':from_team' => $defaultTeam,
            ':owner' => $currentOwnerName,
            ':note' => $note,
            ':created_by' => $createdByUserId,
        ]);
    }

    public function removePickOverride(int $seasonYear, int $roundNo, int $slotNo): void
    {
        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'DELETE FROM draft_pick_overrides
             WHERE season_year = :season AND round_no = :round AND slot_no = :slot'
        );
        $stmt->execute([
            ':season' => $seasonYear,
            ':round' => $roundNo,
            ':slot' => $slotNo,
        ]);
    }

    public function addFutureTrade(
        int $seasonYear,
        ?int $roundNo,
        string $fromTeamName,
        string $currentOwnerName,
        string $note,
        int $createdByUserId
    ): void {
        $fromTeamName = trim($fromTeamName);
        $currentOwnerName = trim($currentOwnerName);
        $note = trim($note);

        if ($seasonYear < 2020 || $seasonYear > 2100) {
            throw new InvalidArgumentException('Future trade year is out of range.');
        }

        if ($fromTeamName === '' || $currentOwnerName === '') {
            throw new InvalidArgumentException('Both from-team and new owner are required.');
        }

        if ($roundNo !== null) {
            $roundNo = max(1, min(20, $roundNo));
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'INSERT INTO draft_future_pick_trades
                (season_year, round_no, from_team_name, current_owner_name, note, created_by_user_id)
             VALUES
                (:season, :round, :from_team, :owner, :note, :created_by)'
        );

        $stmt->bindValue(':season', $seasonYear, PDO::PARAM_INT);
        if ($roundNo === null) {
            $stmt->bindValue(':round', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':round', $roundNo, PDO::PARAM_INT);
        }
        $stmt->bindValue(':from_team', $fromTeamName, PDO::PARAM_STR);
        $stmt->bindValue(':owner', $currentOwnerName, PDO::PARAM_STR);
        $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        $stmt->bindValue(':created_by', $createdByUserId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function listFutureTrades(int $fromSeasonYear): array
    {
        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'SELECT id, season_year, round_no, from_team_name, current_owner_name, note, created_at
             FROM draft_future_pick_trades
             WHERE season_year >= :from_season
             ORDER BY season_year ASC, round_no ASC, id ASC'
        );
        $stmt->execute([':from_season' => $fromSeasonYear]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateFutureTrade(
        int $tradeId,
        int $seasonYear,
        ?int $roundNo,
        string $fromTeamName,
        string $currentOwnerName,
        string $note,
        int $updatedByUserId
    ): void {
        $fromTeamName = trim($fromTeamName);
        $currentOwnerName = trim($currentOwnerName);
        $note = trim($note);

        if ($tradeId <= 0) {
            throw new InvalidArgumentException('Future trade ID is invalid.');
        }

        if ($seasonYear < 2020 || $seasonYear > 2100) {
            throw new InvalidArgumentException('Future trade year is out of range.');
        }

        if ($fromTeamName === '' || $currentOwnerName === '') {
            throw new InvalidArgumentException('Both from-team and new owner are required.');
        }

        if ($roundNo !== null) {
            $roundNo = max(1, min(20, $roundNo));
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'UPDATE draft_future_pick_trades
             SET season_year = :season,
                 round_no = :round,
                 from_team_name = :from_team,
                 current_owner_name = :owner,
                 note = :note,
                 created_by_user_id = :updated_by
             WHERE id = :id'
        );

        $stmt->bindValue(':season', $seasonYear, PDO::PARAM_INT);
        if ($roundNo === null) {
            $stmt->bindValue(':round', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':round', $roundNo, PDO::PARAM_INT);
        }
        $stmt->bindValue(':from_team', $fromTeamName, PDO::PARAM_STR);
        $stmt->bindValue(':owner', $currentOwnerName, PDO::PARAM_STR);
        $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        $stmt->bindValue(':updated_by', $updatedByUserId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $tradeId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare('SELECT id FROM draft_future_pick_trades WHERE id = :id LIMIT 1');
            $existsStmt->execute([':id' => $tradeId]);
            if ($existsStmt->fetchColumn() === false) {
                throw new RuntimeException('Future trade row was not found.');
            }
        }
    }

    private function parseTeamList(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $teams = [];

        foreach ($lines as $line) {
            $team = trim($line);
            if ($team === '') {
                continue;
            }

            $teams[] = $team;
        }

        return array_values(array_unique($teams));
    }
}
