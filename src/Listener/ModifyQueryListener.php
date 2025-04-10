<?php
//namespace IsolatedSites;

namespace IsolatedSites\Listener;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Laminas\EventManager\EventInterface;
use Omeka\Entity\User;

class ModifyQueryListener
{
    /**
     * Modify the item query based on the user's role.
     *
     * @param EventInterface $event
     */
    public function __invoke(EventInterface $event)
    {
        
        $user = $event->getTarget()->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();

        // Not limit the view of items/item_sets to global_admins o not_logged users (public view)
        if (!$user || $user->getRole() === 'global_admin') {
            return;
        }

        $userSettings = $event->getTarget()->getServiceLocator()->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->getId());
        $limit = $userSettings->get('limit_to_granted_sites', 1);
        
        if ($limit) {
            $connection = $event->getTarget()->getServiceLocator()->get('Omeka\Connection');
            $sql = 'SELECT site_id FROM site_permission WHERE user_id = :user_id';
            $stmt = $connection->executeQuery($sql, ['user_id' => $user->getId()]);
            $siteIds = $stmt->fetchFirstColumn(); // Returns an array of site IDs
    
            $queryBuilder = $event->getParam('queryBuilder');
    
            $alias = $queryBuilder->getRootAliases()[0];
            $queryBuilder->innerJoin("$alias.sites", 'site')
               ->andWhere('site.id IN (:siteIds)')
               ->setParameter('siteIds', $siteIds);
        }
    }
}
