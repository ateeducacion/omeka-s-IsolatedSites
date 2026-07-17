<?php
declare(strict_types=1);

namespace IsolatedSites\Service;

use Doctrine\DBAL\Connection;

/**
 * Resolves the sites a user holds an explicit permission for.
 *
 * Site permissions — not the limit_to_granted_sites flag — are the authority on
 * which sites a user may manage content in.
 */
class GrantedSites
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return int[] Site ids the user has a site_permission row for.
     */
    public function forUser(int $userId): array
    {
        $sql = 'SELECT DISTINCT site_id FROM site_permission WHERE user_id = :user_id';
        $ids = $this->connection
            ->executeQuery($sql, ['user_id' => $userId])
            ->fetchFirstColumn();

        return array_map('intval', $ids);
    }
}
