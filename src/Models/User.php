<?php

declare(strict_types=1);

namespace PII\Models;

use PII\Core\Database;

/**
 * User Model — authentication and CRUD.
 * Passwords are hashed with Argon2id.
 */
class User
{
    public static function findById(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT id, username, email, display_name, is_active,
                    must_change_password, landing_module,
                    last_login, created_at, updated_at
             FROM users WHERE id = ?",
            [$id]
        );
    }

    public static function findByUsername(string $username): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT id, username, email, password_hash, display_name,
                    is_active, must_change_password, failed_logins, lockout_until,
                    last_login, created_at, updated_at
             FROM users WHERE username = ?",
            [$username]
        );
    }

    public static function all(array $filters = []): array
    {
        $db     = Database::getInstance();
        $where  = [];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(username LIKE ? OR email LIKE ? OR display_name LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT id, username, email, display_name, is_active,
                       last_login, created_at, updated_at
                FROM users
                {$whereSQL}
                ORDER BY username ASC";
        return $db->fetchAll($sql, $params);
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($username === '') {
            throw new \InvalidArgumentException('Username is required.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email address is not valid.');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        if ($email !== '') {
            $existing = $db->fetch("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        } else {
            $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        }
        if ($existing) {
            throw new \RuntimeException('A user with that username' . ($email ? ' or email' : '') . ' already exists.');
        }

        return (int) $db->insert('users', [
            'username'             => $username,
            'email'                => $email !== '' ? $email : null,
            'password_hash'        => password_hash($password, PASSWORD_ARGON2ID),
            'display_name'         => trim($data['display_name'] ?? ''),
            'is_active'            => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'must_change_password' => isset($data['must_change_password']) ? (int) $data['must_change_password'] : 0,
            'landing_module'       => !empty($data['landing_module']) ? $data['landing_module'] : null,
        ]);
    }

    public static function updateUser(int $id, array $data): int
    {
        $db = Database::getInstance();
        $updateData = [];

        if (isset($data['username']) && trim($data['username']) !== '') {
            $dup = $db->fetch("SELECT id FROM users WHERE username = ? AND id != ?", [trim($data['username']), $id]);
            if ($dup) {
                throw new \RuntimeException('Username already taken.');
            }
            $updateData['username'] = trim($data['username']);
        }

        if (array_key_exists('email', $data)) {
            $email = trim($data['email'] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Email address is not valid.');
            }
            if ($email !== '') {
                $dup = $db->fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $id]);
                if ($dup) {
                    throw new \RuntimeException('Email already taken.');
                }
            }
            $updateData['email'] = $email !== '' ? $email : null;
        }

        if (isset($data['display_name'])) {
            $updateData['display_name'] = trim($data['display_name']);
        }
        if (isset($data['is_active'])) {
            $updateData['is_active'] = (int) $data['is_active'];
        }
        if (isset($data['must_change_password'])) {
            $updateData['must_change_password'] = (int) $data['must_change_password'];
        }
        if (array_key_exists('landing_module', $data)) {
            $updateData['landing_module'] = ($data['landing_module'] !== null && $data['landing_module'] !== '')
                ? $data['landing_module'] : null;
        }
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                throw new \InvalidArgumentException('Password must be at least 8 characters.');
            }
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        if (empty($updateData)) {
            return 0;
        }
        return $db->update('users', $updateData, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('users', 'id = ?', [$id]);
    }

    public static function authenticate(string $username, string $password): array|false
    {
        $user = self::findByUsername($username);
        if (!$user || !(int) $user['is_active']) {
            return false;
        }
        if (self::lockoutMinutesLeft($user) > 0) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Rehash if algorithm cost has changed
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Database::getInstance()->update(
                'users',
                ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)],
                'id = ?',
                [$user['id']]
            );
        }

        unset($user['password_hash'], $user['failed_logins'], $user['lockout_until']);

        // Attach group / admin info
        $user['is_admin'] = self::isAdmin((int) $user['id']) ? 1 : 0;
        $user['groups']   = self::getGroups((int) $user['id']);

        return $user;
    }

    /* ------------------------------------------------------------------
     *  Login rate limiting (5 failures → 15-minute lockout)
     * ----------------------------------------------------------------*/

    public const MAX_FAILED_LOGINS   = 5;
    public const LOCKOUT_MINUTES     = 15;

    /** Minutes left on an active lockout, or 0 if none. */
    public static function lockoutMinutesLeft(array $user): int
    {
        if (empty($user['lockout_until'])) {
            return 0;
        }
        $left = strtotime($user['lockout_until']) - time();
        return $left > 0 ? (int) ceil($left / 60) : 0;
    }

    public static function recordFailedLogin(int $id): void
    {
        $db   = Database::getInstance();
        $user = $db->fetch("SELECT failed_logins FROM users WHERE id = ?", [$id]);
        $fails = (int) ($user['failed_logins'] ?? 0) + 1;
        $data  = ['failed_logins' => $fails];
        if ($fails >= self::MAX_FAILED_LOGINS) {
            $data['lockout_until'] = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
            $data['failed_logins'] = 0;   // fresh count after the lockout expires
        }
        $db->update('users', $data, 'id = ?', [$id]);
    }

    public static function clearLoginFailures(int $id): void
    {
        Database::getInstance()->update(
            'users',
            ['failed_logins' => 0, 'lockout_until' => null],
            'id = ?',
            [$id]
        );
    }

    /**
     * Change the user's own password (requires the current one) and clear
     * the must-change flag. Throws on validation failure.
     */
    public static function changeOwnPassword(int $id, string $current, string $new): void
    {
        $db  = Database::getInstance();
        $row = $db->fetch("SELECT password_hash FROM users WHERE id = ?", [$id]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            throw new \RuntimeException('Current password is incorrect.');
        }
        if (strlen($new) < 8) {
            throw new \InvalidArgumentException('New password must be at least 8 characters.');
        }
        if ($new === $current) {
            throw new \InvalidArgumentException('New password must be different from the current one.');
        }
        $db->update('users', [
            'password_hash'        => password_hash($new, PASSWORD_ARGON2ID),
            'must_change_password' => 0,
        ], 'id = ?', [$id]);
    }

    public static function updateLastLogin(int $id): void
    {
        Database::getInstance()->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    /* ------------------------------------------------------------------
     *  Group / admin helpers
     * ----------------------------------------------------------------*/

    public static function isAdmin(int $userId): bool
    {
        $row = Database::getInstance()->fetch(
            "SELECT 1
             FROM user_group_members ugm
             JOIN permission_groups pg ON pg.id = ugm.group_id
             WHERE ugm.user_id = ? AND pg.is_admin = 1
             LIMIT 1",
            [$userId]
        );
        return (bool) $row;
    }

    public static function getGroups(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT pg.id, pg.name, pg.is_admin
             FROM user_group_members ugm
             JOIN permission_groups pg ON pg.id = ugm.group_id
             WHERE ugm.user_id = ?
             ORDER BY pg.name",
            [$userId]
        );
    }

    public static function setGroups(int $userId, array $groupIds): void
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $db->delete('user_group_members', 'user_id = ?', [$userId]);
            foreach (array_unique($groupIds) as $gid) {
                $gid = (int) $gid;
                if ($gid > 0) {
                    $db->insert('user_group_members', ['user_id' => $userId, 'group_id' => $gid]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
