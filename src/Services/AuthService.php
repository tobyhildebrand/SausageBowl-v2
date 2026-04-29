<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use PDO;
use RuntimeException;

class AuthService
{
    private const SESSION_USER_ID = 'auth_user_id';

    private ?array $currentUser = null;
    private bool $loadedCurrentUser = false;

    public function hasAnyUsers(): bool
    {
        $pdo = DB::get();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        return $count > 0;
    }

    public function currentUser(): ?array
    {
        if ($this->loadedCurrentUser) {
            return $this->currentUser;
        }

        $this->loadedCurrentUser = true;
        $userId = isset($_SESSION[self::SESSION_USER_ID]) ? (int) $_SESSION[self::SESSION_USER_ID] : 0;
        if ($userId <= 0) {
            return null;
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'SELECT id, email, display_name, role, is_active, last_login_at, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
            $this->logout();
            return null;
        }

        $this->currentUser = $user;
        return $this->currentUser;
    }

    public function isLoggedIn(): bool
    {
        return $this->currentUser() !== null;
    }

    public function isCommissioner(): bool
    {
        $user = $this->currentUser();
        return $user !== null && ($user['role'] ?? '') === 'commissioner';
    }

    public function login(string $email, string $password): bool
    {
        $email = $this->normalizeEmail($email);
        if ($email === '' || $password === '') {
            return false;
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'SELECT id, email, display_name, password_hash, role, is_active
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
            return false;
        }

        if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            return false;
        }

        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];
        session_regenerate_id(true);

        $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $update->execute([':id' => (int) $user['id']]);

        $this->loadedCurrentUser = false;
        $this->currentUser = null;
        $this->currentUser();

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_USER_ID]);
        $this->currentUser = null;
        $this->loadedCurrentUser = true;
    }

    public function createInitialCommissioner(string $displayName, string $email, string $password): void
    {
        $displayName = trim($displayName);
        $email = $this->normalizeEmail($email);

        if ($displayName === '' || $email === '' || $password === '') {
            throw new RuntimeException('Display name, email, and password are required.');
        }

        if (strlen($password) < 10) {
            throw new RuntimeException('Password must be at least 10 characters long.');
        }

        if ($this->hasAnyUsers()) {
            throw new RuntimeException('Initial commissioner account already exists.');
        }

        $pdo = DB::get();
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, display_name, password_hash, role, is_active)
             VALUES (:email, :display_name, :password_hash, :role, 1)'
        );
        $stmt->execute([
            ':email' => $email,
            ':display_name' => $displayName,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => 'commissioner',
        ]);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
