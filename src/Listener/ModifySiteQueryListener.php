<?php
namespace IsolatedSites\Listener;

use Laminas\EventManager\Event;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Connection;
use Omeka\Entity\User;

class ModifySiteQueryListener
{
    /**
     * @var AuthenticationService
     */
    protected $auth;

    /**
     * @var UserSettings
     */
    protected $userSettings;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var mixed
     */
    protected $application;

    public function __construct(
        AuthenticationService $auth,
        UserSettings $userSettings,
        Connection $connection,
        $application
    ) {
        $this->auth = $auth;
        $this->userSettings = $userSettings;
        $this->connection = $connection;
        $this->application = $application;
    }

    public function __invoke(Event $event)
    {
        $user = $this->auth->getIdentity();

        // Check if we're in the admin interface
        $routeMatch = $this->application->getMvcEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $isAdmin = strpos($routeName, 'admin') === 0;

        // Only apply filtering in admin interface for non-global-admin users
        if (!$isAdmin || !$user || $user->getRole() === 'global_admin') {
            return;
        }

        $this->userSettings->setTargetId($user->getId());
        $limitToGrantedSites = $this->userSettings->get('limit_to_granted_sites', 1);

        if ($limitToGrantedSites!=null && $limitToGrantedSites) {
            $qb = $event->getParam('queryBuilder');
            
            // Get the sites where the user has permissions
            $grantedSites = $this->getGrantedSites($user->getId());
            
            if (empty($grantedSites)) {
                // If user has no permissions, return no results
                $qb->andWhere('1 = 0');
                return;
            }

            // Add condition to only show sites where user has permissions
            $alias=$qb->getRootAliases()[0];
            $qb->andWhere("$alias.id IN (:granted_sites)")
               ->setParameter('granted_sites', $grantedSites);
        }
    }

    protected function getGrantedSites($userId)
    {
        $qb = $this->connection->createQueryBuilder();
        
        $qb->select('DISTINCT s.id')
           ->from('site', 's')
           ->leftJoin('s', 'site_permission', 'sp', 's.id = sp.site_id')
           ->where('sp.user_id = :userId')
           ->setParameter('userId', $userId);

        return $qb->execute()->fetchFirstColumn();
    }
}
