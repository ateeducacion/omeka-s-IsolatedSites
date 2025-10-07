<?php
declare(strict_types=1);

namespace IsolatedSites\Listener;

use Laminas\EventManager\Event;
use Laminas\Mvc\MvcEvent;
use Laminas\Authentication\AuthenticationService;
use Omeka\Permissions\Acl;

/**
 * Listener to filter admin navigation menu based on user role.
 */
class FilterAdminNavigationListener
{
    protected $auth;

    public function __construct(AuthenticationService $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Filter the admin navigation to show only allowed sections for restricted roles.
     *
     * @param Event $event
     */
    public function __invoke(Event $event)
    {
        $identity = $this->auth->getIdentity();
        
        if (!$identity) {
            return;
        }

        $role = $identity->getRole();
        
        // Only filter navigation for reviewer, author, and researcher roles
        if (!in_array($role, [Acl::ROLE_REVIEWER, Acl::ROLE_AUTHOR, Acl::ROLE_RESEARCHER])) {
            return;
        }

        // Define which navigation links to keep
        $linksToKeep = [
            'sites',      // Sites
            'items',      // Items
            'item-sets',  // Item Sets
        ];

        // Get the navigation container
        $nav = $event->getParam('nav');
        
        if (!$nav) {
            return;
        }

        // Filter the navigation to keep only allowed links
        $pages = $nav->getPages();
        foreach ($pages as $page) {
            $id = $page->getId();
            if (!in_array($id, $linksToKeep)) {
                $nav->removePage($page);
            }
        }
    }
}
