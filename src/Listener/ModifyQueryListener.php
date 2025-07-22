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
        
        if ($limit!=null && $limit) {
            $sql = 'SELECT site_id FROM site_permission WHERE user_id = :user_id';
            $stmt = $this->connection->executeQuery($sql, ['user_id' => $user->getId()]);
            $siteIds = $stmt->fetchFirstColumn(); // Returns an array of site IDs
    
            $queryBuilder = $event->getParam('queryBuilder');
    
            $alias = $queryBuilder->getRootAliases()[0];
            $queryBuilder->innerJoin("$alias.sites", 'site')
               ->andWhere('site.id IN (:siteIds)')
               ->setParameter('siteIds', $siteIds);
        }
    }
}
