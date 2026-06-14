<?php
// lib/Auth.php

class Auth {
    const TOKEN_BYTES = 32;
    const COOKIE_LIFETIME_SECONDS = 315360000; // 10 years

    private PDO $db;
    private ?array $user = null;   // current user after requireLogin()

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // ── LOGIN / LOGOUT ────────────────────────────────────────

    /**
     * Login attempt. Returns token or null on invalid credentials.
     */
    public function login(string $username, string $password, string $ip = '', string $ua = ''): ?string {
        $stmt = $this->db->prepare("
            SELECT id, password_hash, role, display_name, is_active
            FROM users
            WHERE username = :u
        ");
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        // Update last_login
        $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
                 ->execute(['id' => $user['id']]);

        // Create session
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->db->prepare("
            INSERT INTO sessions (token, user_id, ip, user_agent, expires_at)
            VALUES (:token, :uid, :ip, :ua, 'infinity'::timestamptz)
        ")->execute(['token' => $token, 'uid' => $user['id'], 'ip' => $ip, 'ua' => $ua]);

        return $token;
    }

    public function logout(string $token): void {
        $this->db->prepare("DELETE FROM sessions WHERE token = :t")
                 ->execute(['t' => $token]);
    }

    public function createSessionForUser(int $userId, string $ip = '', string $ua = ''): ?string {
        $stmt = $this->db->prepare("
            SELECT id
            FROM users
            WHERE id = :id
              AND is_active = TRUE
        ");
        $stmt->execute(['id' => $userId]);
        if (!$stmt->fetchColumn()) {
            return null;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->db->prepare("
            INSERT INTO sessions (token, user_id, ip, user_agent, expires_at)
            VALUES (:token, :uid, :ip, :ua, 'infinity'::timestamptz)
        ")->execute(['token' => $token, 'uid' => $userId, 'ip' => $ip, 'ua' => $ua]);

        return $token;
    }

    // ── SESSION CHECK ─────────────────────────────────────────

    /**
     * Validate token from cookie, return user data or null.
     */
    public function check(string $token): ?array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.role, u.display_name, u.display_tz
            FROM sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token = :t
              AND (s.expires_at > NOW() OR s.expires_at = 'infinity'::timestamptz)
              AND u.is_active = TRUE
        ");
        $stmt->execute(['t' => $token]);
        $user = $stmt->fetch() ?: null;

        if ($user) {
            // Extend session on activity
            $this->db->prepare("
                UPDATE sessions
                SET last_seen_at = NOW(),
                    expires_at   = 'infinity'::timestamptz
                WHERE token = :t
            ")->execute(['t' => $token]);
        }

        return $user;
    }

    /**
     * Get user from cookie or redirect to login.
     * Call at the start of every protected page.
     */
    public function requireLogin(): array {
        $token = $_COOKIE['fb_ads_token'] ?? '';
        if (!$token) $this->redirectLogin();

        $user = $this->check($token);
        if (!$user) $this->redirectLogin();

        $this->user = $user;
        return $user;
    }

    public function requireAdmin(): array {
        $user = $this->requireLogin();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            die(json_encode(['error' => 'Forbidden']));
        }
        return $user;
    }

    private function redirectLogin(): never {
        header('Location: /login.php');
        exit;
    }

    // ── ACCESS CONTROL ────────────────────────────────────────

    /**
     * WHERE clause for filtering accounts by user access.
     * For admins - no restrictions.
     * For regular users - only accounts from their accessible BMs.
     * $alias - alias of the ad_accounts table in the query.
     */
    public function bmFilter(array $user, string $alias = 'aa'): array {
        if ($user['role'] === 'admin') {
            return ['sql' => '1=1', 'params' => []];
        }
        return [
            'sql'    => "{$alias}.bm_id IN (SELECT bm_id FROM user_bm_accounts WHERE user_id = :_uid)",
            'params' => ['_uid' => $user['id']],
        ];
    }

    // Backward compatibility - use bmFilter
    public function fbAccountFilter(array $user, string $alias = 'aa'): array {
        return $this->bmFilter($user, $alias);
    }

    /**
     * List of bm_id values available to the user.
     */
    public function allowedBmIds(array $user): array {
        if ($user['role'] === 'admin') {
            return $this->db->query("SELECT id FROM business_managers")
                            ->fetchAll(PDO::FETCH_COLUMN);
        }
        $stmt = $this->db->prepare("SELECT bm_id FROM user_bm_accounts WHERE user_id = :uid");
        $stmt->execute(['uid' => $user['id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Backward compatibility
    public function allowedFbAccountIds(array $user): array {
        return $this->allowedBmIds($user);
    }

    public function setUserBmAccounts(int $userId, array $bmIds): void {
        $this->db->prepare("DELETE FROM user_bm_accounts WHERE user_id = :uid")
                 ->execute(['uid' => $userId]);
        if (!$bmIds) return;
        $stmt = $this->db->prepare("
            INSERT INTO user_bm_accounts (user_id, bm_id) VALUES (:uid, :bid)
            ON CONFLICT DO NOTHING
        ");
        foreach ($bmIds as $bid) {
            $stmt->execute(['uid' => $userId, 'bid' => (string)$bid]);
        }
    }

    // Backward compatibility
    public function setUserFbAccounts(int $userId, array $ids): void {
        $this->setUserBmAccounts($userId, $ids);
    }

    // ── USER MANAGEMENT (admin only) ──────────────────────────

    public function createUser(string $username, string $password, string $role, string $displayName, int $createdBy): int {
        if ($role === 'buyer') {
            $role = 'user';
        }
        if (!in_array($role, ['admin', 'user'], true)) throw new \InvalidArgumentException("Invalid role");

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, role, display_name, created_by)
            VALUES (:u, :h, :r, :dn, :cb)
            RETURNING id
        ");
        $stmt->execute(['u' => $username, 'h' => $hash, 'r' => $role, 'dn' => $displayName, 'cb' => $createdBy]);
        return (int)$stmt->fetchColumn();
    }

    public function updatePassword(int $userId, string $newPassword): void {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->prepare("UPDATE users SET password_hash = :h WHERE id = :id")
                 ->execute(['h' => $hash, 'id' => $userId]);
    }

    public function setActive(int $userId, bool $active): void {
        $this->db->prepare("UPDATE users SET is_active = :a WHERE id = :id")
                 ->execute(['a' => $active ? 'TRUE' : 'FALSE', 'id' => $userId]);
        if (!$active) {
            // Kill all sessions
            $this->db->prepare("DELETE FROM sessions WHERE user_id = :id")
                     ->execute(['id' => $userId]);
        }
    }

    public function listUsers(): array {
        return $this->db->query("
            SELECT
                u.id, u.username, u.role, u.display_name,
                u.is_active, u.created_at, u.last_login_at, u.display_tz,
                creator.username AS created_by_name,
                COALESCE(
                    JSON_AGG(uba.bm_id::text) FILTER (WHERE uba.bm_id IS NOT NULL),
                    '[]'
                ) AS bm_ids
            FROM users u
            LEFT JOIN users creator       ON creator.id = u.created_by
            LEFT JOIN user_bm_accounts uba ON uba.user_id = u.id
            GROUP BY u.id, creator.username
            ORDER BY u.role, u.username
        ")->fetchAll();
    }

    public function getUser(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT u.*,
                COALESCE(
                    JSON_AGG(uba.bm_id::text) FILTER (WHERE uba.bm_id IS NOT NULL),
                    '[]'
                ) AS bm_ids
            FROM users u
            LEFT JOIN user_bm_accounts uba ON uba.user_id = u.id
            WHERE u.id = :id
            GROUP BY u.id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    // ── CLEANUP ───────────────────────────────────────────────
    public function cleanupSessions(): int {
        $stmt = $this->db->query("DELETE FROM sessions WHERE expires_at < NOW()");
        return $stmt->rowCount();
    }
}
