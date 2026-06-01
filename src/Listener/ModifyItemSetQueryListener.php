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

        // api.search.query fires for the admin UI AND the REST API (route names
        // 'api' / 'api-local'). Filtering must cover both contexts; gating on the
        // admin route alone left the authenticated REST API completely unfiltered.
        $routeMatch = $this->application->getMvcEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $isAdmin = strpos($routeName, 'admin') === 0;
        $isApi = strpos($routeName, 'api') === 0;

        // Skip anonymous requests (core's is_public filter applies) and both
        // administrator roles (global_admin and site_admin, per Acl::isAdminRole),
        // as well as any non-admin/non-API context (e.g. public site, CLI).
        if ((!$isAdmin && !$isApi)
            || !$user
            || in_array($user->getRole(), ['global_admin', 'site_admin'], true)
        ) {
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
