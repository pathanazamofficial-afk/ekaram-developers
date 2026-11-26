<?php
declare(strict_types=1);

final class Auth
{
    public static function requireAdminPage(): array
    {
        Security::startSession();
        self::preventPrivateCaching();
        $user = self::currentUser();
        if ($user === null || $user['role_code'] !== 'admin') {
            header('Location: /admin/login.php', true, 303);
            exit;
        }

        return $user;
    }

    public static function requireAdminApi(): array
    {
        Security::startSession();
        self::preventPrivateCaching();
        $user = self::currentUser();
        if ($user === null || $user['role_code'] !== 'admin') {
            Response::json(['success' => false, 'message' => 'Authentication required'], 401);
        }

        return $user;
    }

    public static function requireEmployeePage(): array
    {
        Security::startSession();
        self::preventPrivateCaching();
        $user = self::currentUser();
        if ($user === null || $user['role_code'] !== 'marketing_associate') {
            $destination = $user !== null && $user['role_code'] === 'admin'
                ? '/admin/login.php'
                : '/marketing-associate/login.php';
            header('Location: ' . $destination, true, 303);
            exit;
        }

        header('Location: /marketing-associate/index.php', true, 303);
        exit;
    }

    public static function requireEmployeeApi(): array
    {
        return self::requireMarketingAssociateApi();
    }

    public static function requireMarketingAssociatePage(): array
    {
        Security::startSession();
        self::preventPrivateCaching();
        $user = self::currentUser();
        if ($user === null || $user['role_code'] !== 'marketing_associate') {
            header('Location: /marketing-associate/login.php', true, 303);
            exit;
        }

        if (!self::marketingAssociateCanOperate((int) $user['id'])) {
            unset($_SESSION['user_id'], $_SESSION['role_code']);
            header('Location: /marketing-associate/login.php', true, 303);
            exit;
        }

        return $user;
    }

    public static function requireMarketingAssociateApi(): array
    {
        Security::startSession();
        self::preventPrivateCaching();
        $user = self::currentUser();
        if ($user === null || $user['role_code'] !== 'marketing_associate') {
            Response::json(['success' => false, 'message' => 'Authentication required'], 401);
        }

        if (!self::marketingAssociateCanOperate((int) $user['id'])) {
            unset($_SESSION['user_id'], $_SESSION['role_code']);
            Response::json(['success' => false, 'message' => 'An approved account with an Admin-assigned designation is required'], 403);
        }

        return $user;
    }

    public static function visibleUserIds(int $userId): array
    {
        $statement = Database::connection()->prepare(
            'WITH RECURSIVE hierarchy_edges(user_id, manager_id) AS (
                SELECT user_id, reporting_manager_id FROM marketing_associate_profiles
             ),
             reporting_team(user_id) AS (
                SELECT CAST(:user_id AS UNSIGNED)
                UNION DISTINCT
                SELECT edge.user_id
                FROM hierarchy_edges edge
                JOIN reporting_team rt ON edge.manager_id = rt.user_id
             )
             SELECT user_id FROM reporting_team'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function marketingAssociateCanOperate(int $userId): bool
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT ma.approval_status, ma.designation_code
             FROM marketing_associates ma
             WHERE ma.user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $associate = $statement->fetch();
        return $associate !== false
            && $associate['approval_status'] === 'approved'
            && is_string($associate['designation_code'])
            && array_key_exists($associate['designation_code'], MarketingAssociateHierarchy::DESIGNATIONS);
    }

    public static function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) {
            return null;
        }

        $statement = Database::connection()->prepare(
            "SELECT u.id, u.full_name, u.account_status, r.code AS role_code
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.account_status = 'active'
             LIMIT 1"
        );
        $statement->execute(['id' => $_SESSION['user_id']]);
        $user = $statement->fetch();
        if ($user === false) {
            unset($_SESSION['user_id']);
            return null;
        }

        return $user;
    }

    private static function preventPrivateCaching(): void
    {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
    }
}
