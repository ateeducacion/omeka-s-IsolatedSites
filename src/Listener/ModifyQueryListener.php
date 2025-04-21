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

class ModifyQueryListener
{
    private $authService;
    private $userSettings;
    private $connection;

    public function __construct(
        AuthenticationService $authService,
        UserSettings $userSettings,
        Connection $connection
    ) {
        $this->authService = $authService;
        $this->userSettings = $userSettings;
        $this->connection = $connection;
    }
    /**
     * Modify the item query based on the user's role.
     *
     * @param EventInterface $event
     */
    public function __invoke(EventInterface $event)
    {
        $user = $this->authService->getIdentity();

        // Not limit the view of items/item_sets to global_admins o not_logged users (public view)
        if (!$user || $user->getRole() === 'global_admin') {
            return;
        }

        $this->userSettings->setTargetId($user->getId());
        $limit = $this->userSettings->get('limit_to_granted_sites', 1);
        
        if ($limit) {
            $sql = 'SELECT site_id FROM site_permission WHERE user_id = :user_id';
            $stmt = $this->connection->executeQuery($sql, ['user_id' => $user->getId()]);
            $siteIds = $stmt->fetchFirstColumn(); // Returns an array of site IDs
    
            $queryBuilder = $event->getParam('queryBuilder');
            error_log("    CLASE               ");
            error_log(get_class($queryBuilder));
    
            $alias = $queryBuilder->getRootAliases()[0];
            $queryBuilder->innerJoin("$alias.sites", 'site')
               ->andWhere('site.id IN (:siteIds)')
               ->setParameter('siteIds', $siteIds);
        }
    }
}
