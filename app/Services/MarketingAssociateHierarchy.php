<?php
declare(strict_types=1);

final class MarketingAssociateHierarchy
{
    public const DESIGNATIONS = [
        'marketing_manager' => 'Marketing Manager',
        'deputy_manager' => 'Deputy Manager',
        'assistant_general_manager' => 'Assistant General Manager',
        'deputy_general_manager' => 'Deputy General Manager',
        'deputy_director' => 'Deputy Director',
    ];

    private const LEVELS = [
        'marketing_manager' => 1,
        'deputy_manager' => 2,
        'assistant_general_manager' => 3,
        'deputy_general_manager' => 4,
        'deputy_director' => 5,
    ];

    public static function level(string $designation): ?int
    {
        return self::LEVELS[$designation] ?? null;
    }

    public static function targetDesignation(string $designation): ?string
    {
        return [
            'deputy_director' => 'deputy_general_manager',
            'deputy_general_manager' => 'assistant_general_manager',
            'assistant_general_manager' => 'deputy_manager',
            'deputy_manager' => 'marketing_manager',
        ][$designation] ?? null;
    }

    public static function assignmentIsValid(
        PDO $pdo,
        string $designation,
        ?int $managerId,
        ?int $associateUserId = null
    ): bool {
        $level = self::level($designation);
        if ($level === null) {
            return false;
        }
        if ($level === 5) {
            return $managerId === null;
        }
        if ($managerId === null || $managerId === $associateUserId) {
            return false;
        }

        $managerDesignation = array_search($level + 1, self::LEVELS, true);
        if ($managerDesignation === false) {
            return false;
        }
        $statement = $pdo->prepare(
            "SELECT u.id
             FROM users u
             JOIN roles account_role ON account_role.id = u.role_id
             JOIN marketing_associates ma ON ma.user_id = u.id
             WHERE u.id = :manager_id
               AND u.account_status = 'active'
               AND account_role.code = 'marketing_associate'
               AND ma.approval_status = 'approved'
               AND ma.designation_code = :designation"
        );
        $statement->execute(['manager_id' => $managerId, 'designation' => $managerDesignation]);

        return $statement->fetchColumn() !== false;
    }
}
