<?php
//namespace IsolatedSites;

namespace IsolatedSites\Listener;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Laminas\EventManager\EventInterface;
//use Omeka\Entity\User;
use Omeka\Settings\UserSettings as UserSettings;
use Doctrine\DBAL\Connection;
use Laminas\Authentication\AuthenticationService;
use Laminas\Mvc\Application as Application;

class ModifyQueryListener
{
    private $authService;
    private $userSettings;
    private $connection;
    private $application;

    public function __construct(
        AuthenticationService $authService,
        UserSettings $userSettings,
        Connection $connection,
        Application $application
    ) {
        $this->authService = $authService;
        $this->userSettings = $userSettings;
        $this->connection = $connection;
        $this->application = $application;
    }
    /**
     * Modify the item query based on the user's role.
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
            $sql = 'SELECT site_id FROM site_permission WHERE user_id = :user_id';
            $stmt = $this->connection->executeQuery($sql, ['user_id' => $user->getId()]);
            $siteIds = $stmt->fetchFirstColumn(); // Returns an array of site IDs

            $queryBuilder = $event->getParam('queryBuilder');

            // Show items attached to a granted site OR owned by the user, so a
            // user's own items that are not yet attached to any site stay visible
            // (a plain INNER JOIN would hide them). An empty $siteIds renders as
            // "IN (NULL)" and matches nothing, leaving the owner condition.
            $alias = $queryBuilder->getRootAliases()[0];
            $queryBuilder->leftJoin("$alias.sites", 'site')
               ->andWhere("site.id IN (:siteIds) OR $alias.owner = :userId")
               ->setParameter('siteIds', $siteIds)
               ->setParameter('userId', $user->getId());
        }
    }
}
