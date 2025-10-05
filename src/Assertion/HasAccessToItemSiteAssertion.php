<?php
declare(strict_types=1);

namespace IsolatedSites\Assertion;

use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Assertion\AssertionInterface;
use Laminas\Permissions\Acl\Resource\ResourceInterface;
use Laminas\Permissions\Acl\Role\RoleInterface;
use Omeka\Entity\Item;
use Omeka\Entity\User;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Connection;

/**
 * Assertion to check if a user has access to an item based on site permissions.
 * Optimized with caching to minimize database calls.
 */
class HasAccessToItemSiteAssertion implements AssertionInterface
{
    protected $userSettings;
    protected $connection;
    
    /** @var array Cache for user granted sites [userId => [siteIds]] */
    protected $grantedSitesCache = [];
    
    /** @var array Cache for item site assignments [itemId => [siteIds]] */
    protected $itemSitesCache = [];
    
    /** @var array Cache for user settings [userId => bool] */
    protected $userSettingsCache = [];

    public function __construct(UserSettings $userSettings, Connection $connection)
    {
        $this->userSettings = $userSettings;
        $this->connection = $connection;
    }

    /**
     * Assert whether to allow access to item based on site permissions.
     * Returns true to ALLOW access, false to DENY access.
     *
     * @param Acl $acl
     * @param RoleInterface $role
     * @param ResourceInterface $resource
     * @param string $privilege
     * @return bool True to allow access, false to deny access
     */
    public function assert(Acl $acl, RoleInterface $role = null, ResourceInterface $resource = null, $privilege = null)
    {
        // If no resource or role, deny access
        if (!$resource || !$role) {
            return false;
        }

        // Get the user from the role
        if (!method_exists($role, 'getId')) {
            return false;
        }

        $userId = $role->getId();
        
        // Check if user has limit_to_granted_sites setting enabled (with caching)
        if (!isset($this->userSettingsCache[$userId])) {
            $this->userSettings->setTargetId($userId);
            $this->userSettingsCache[$userId] = $this->userSettings->get('limit_to_granted_sites', false);
        }
        $limitToGrantedSites = $this->userSettingsCache[$userId];
        
        // If setting is not enabled, allow access
        if (!$limitToGrantedSites) {
            return true;
        }

        // Get the item from the resource
        $item = null;
        if ($resource instanceof Item) {
            $item = $resource;
        } elseif (method_exists($resource, 'getEntity')) {
            $entity = $resource->getEntity();
            if ($entity instanceof Item) {
                $item = $entity;
            }
        } elseif (method_exists($resource, 'resource')) {
            $entity = $resource->resource();
            if ($entity instanceof Item) {
                $item = $entity;
            }
        }

        // If we can't get the item, deny access for safety
        if (!$item) {
            return false;
        }

        $itemId = $item->getId();
        
        // Get user's granted sites (with caching)
        if (!isset($this->grantedSitesCache[$userId])) {
            $sql = 'SELECT site_id FROM site_permission WHERE user_id = :user_id';
            $stmt = $this->connection->executeQuery($sql, ['user_id' => $userId]);
            $this->grantedSitesCache[$userId] = $stmt->fetchFirstColumn();
        }
        $grantedSiteIds = $this->grantedSitesCache[$userId];
        
        // If user has no granted sites, deny access
        if (empty($grantedSiteIds)) {
            return false;
        }

        // Get item's site assignments (with caching)
        if (!isset($this->itemSitesCache[$itemId])) {
            $sql = 'SELECT site_id FROM item_site WHERE item_id = :item_id';
            $stmt = $this->connection->executeQuery($sql, ['item_id' => $itemId]);
            $this->itemSitesCache[$itemId] = $stmt->fetchFirstColumn();
        }
        $itemSiteIds = $this->itemSitesCache[$itemId];
        
        // Check if there's any intersection between user's granted sites and item's sites
        $hasAccess = !empty(array_intersect($grantedSiteIds, $itemSiteIds));
        
        return $hasAccess;
    }
}
