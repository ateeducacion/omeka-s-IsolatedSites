<?php

namespace IsolatedSites\Listener;

use Laminas\EventManager\EventInterface;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Connection;

class ModifyItemSetQueryListener
{
    private $authService;
    private $userSettings;
    private $connection;
    private $application;

    public function __construct(
        AuthenticationService $authService,
        UserSettings $userSettings,
        Connection $connection,
        $application
    ) {
        $this->authService = $authService;
        $this->userSettings = $userSettings;
        $this->connection = $connection;
        $this->application = $application;
    }

    /**
     * Modify the itemset query based on the user's role and site permissions.
     *
     * @param EventInterface $event
     */
    public function __invoke(EventInterface $event)
    {
        $user = $this->authService->getIdentity();

        // Check if we're in the admin interface
        $routeMatch = $this->application->getMvcEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $isAdmin = strpos($routeName, 'admin') === 0;

        // Only apply filtering in admin interface for non-global-admin users
        if (!$isAdmin || !$user || $user->getRole() === 'global_admin') {
            return;
        }

        $this->userSettings->setTargetId($user->getId());
        $limit = $this->userSettings->get('limit_to_granted_sites', 1);
        
        if ($limit) {
            // Get the sites where the user has permissions
            $sql = 'SELECT site_id FROM site_permission WHERE user_id = :user_id';
            $stmt = $this->connection->executeQuery($sql, ['user_id' => $user->getId()]);
            $siteIds = $stmt->fetchFirstColumn();

            $queryBuilder = $event->getParam('queryBuilder');
            $alias = $queryBuilder->getRootAliases()[0];

            // Create OR condition: itemsets from granted sites OR itemsets owned by user
            if (empty($siteIds)) {
                // User has no site permissions, only show itemsets they own
                $queryBuilder->andWhere("$alias.owner = :userId")
                    ->setParameter('userId', $user->getId());
            } else {
                // User has site permissions, show itemsets from granted sites OR owned by user
                $queryBuilder->leftJoin(
                    "$alias.siteItemSets",
                    'sis'
                )
                    ->leftJoin(
                        'sis.site',
                        'site'
                    )
                    ->andWhere('site.id IN (:siteIds) OR ' . $alias . '.owner = :userId')
                    ->setParameter('siteIds', $siteIds)
                    ->setParameter('userId', $user->getId());
            }
        }
    }
}
