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

    public function __construct(
        AuthenticationService $auth,
        UserSettings $userSettings,
        Connection $connection
    ) {
        $this->auth = $auth;
        $this->userSettings = $userSettings;
        $this->connection = $connection;
    }

    public function __invoke(Event $event)
    {
        $user = $this->auth->getIdentity();
        
        // Don't filter for admin users or when no user is logged in
        if (!$user || $user->getRole() === 'global_admin') {
            return;
        }

        $this->userSettings->setTargetId($user->getId());
        $limitToGrantedSites = $this->userSettings->get('limit_to_granted_sites', false);

        if (!$limitToGrantedSites) {
            return;
        }

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

    protected function getGrantedSites($userId)
    {
        $qb = $this->connection->createQueryBuilder();
        
        $qb->select('DISTINCT s.id')
           ->from('site', 's')
           ->leftJoin('s', 'site_permission', 'sp', 's.id = sp.site_id')
           ->where('sp.user_id = :userId')
           ->setParameter('userId', $userId);

        return $qb->executeQuery()->fetchFirstColumn();
    }
}
