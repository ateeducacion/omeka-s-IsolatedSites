<?php
declare(strict_types=1);

namespace IsolatedSites\Assertion;

use Doctrine\DBAL\Connection;
use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Assertion\AssertionInterface;
use Laminas\Permissions\Acl\Resource\ResourceInterface;
use Laminas\Permissions\Acl\Role\RoleInterface;
use Laminas\Router\RouteMatch;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Exception\ExceptionInterface as ApiExceptionInterface;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Controller\Admin\Item as AdminItemController;
use Omeka\Controller\Admin\Media as AdminMediaController;
use Omeka\Entity\Item;
use Omeka\Entity\Media;
use Omeka\Settings\UserSettings;

/**
 * Assertion to check if a user has access to an item based on site permissions.
 * Optimized with caching to minimize database calls.
 */
class HasAccessToItemSiteAssertion implements AssertionInterface
{
    protected $userSettings;
    protected $connection;

    /** @var ServiceLocatorInterface|null */
    protected $services;

    /** @var array<int, array<int>> Cache for user granted sites [userId => [siteIds]] */
    protected $grantedSitesCache = [];

    /** @var array<int, array<int>> Cache for item site assignments [itemId => [siteIds]] */
    protected $itemSitesCache = [];

    /** @var array<int, bool> Cache for user settings [userId => bool] */
    protected $userSettingsCache = [];

    /** @var array<int, array<int>> Cache for media site assignments [mediaId => [siteIds]] */
    protected $mediaSitesCache = [];

    /** @var bool|null Flag to avoid querying media_site when unavailable */
    protected $mediaSiteTableAvailable;

    public function __construct(UserSettings $userSettings, Connection $connection)
    {
        $this->userSettings = $userSettings;
        $this->connection = $connection;
    }

    public function setServiceLocator(ServiceLocatorInterface $services): void
    {
        $this->services = $services;
    }

    public function assert(Acl $acl, RoleInterface $role = null, ResourceInterface $resource = null, $privilege = null)
    {
        try {
            if (!$resource || !$role || !method_exists($role, 'getId')) {
                return false;
            }
            
            $userId = (int) $role->getId();
            if (!$userId) {
                return false;
            }

            if (!$this->shouldLimitToGrantedSites($userId)) {
                return true;
            }
            
            $item = $this->resolveItem($resource);
            if (!$item) {
                return false;
            }
            return $this->userHasAccessToAnyItemSite($userId, $item, $resource);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveItem($resource)
    {
        if ($resource instanceof Item || $resource instanceof ItemRepresentation) {
            return $resource;
        }
        
        $resolvedFromMedia = $this->resolveItemFromMedia($resource);
        if ($resolvedFromMedia) {
            return $resolvedFromMedia;
        }

        if (is_object($resource)) {
            if (method_exists($resource, 'getEntity')) {
                $entity = $resource->getEntity();
                if ($entity instanceof Item) {
                    return $entity;
                }
                if ($entity instanceof Media) {
                    return $this->resolveItem($entity);
                }
            }
            if (method_exists($resource, 'resource')) {
                $entity = $resource->resource();
                if ($entity instanceof Item || $entity instanceof ItemRepresentation) {
                    return $entity;
                }
                if ($entity instanceof Media || $entity instanceof MediaRepresentation) {
                    return $this->resolveItem($entity);
                }
            }
        }
        
        if ($resource instanceof ItemAdapter || $resource === ItemAdapter::class) {
            return $this->resolveFromRequestOrRoute();
        }

        if ($resource instanceof AdminItemController || $resource === AdminItemController::class) {
            return $this->resolveFromRequestOrRoute();
        }

        if ($resource instanceof MediaAdapter || $resource === MediaAdapter::class) {
            return $this->resolveItemFromMediaRequestOrRoute();
        }

        if ($resource instanceof AdminMediaController || $resource === AdminMediaController::class) {
            return $this->resolveItemFromMediaRequestOrRoute();
        }

        return null;
    }

    private function resolveItemFromMedia($resource)
    {

        if ($resource instanceof Media) {
            $item = $resource->getItem();
            return $item instanceof Item ? $item : null;
        }

        if ($resource instanceof MediaRepresentation) {
            $item = $resource->item();
            return $item instanceof ItemRepresentation ? $item : null;
        }

        return null;
    }

    private function resolveFromRequestOrRoute()
    {
        $itemId = $this->extractItemId();
        if (!$itemId) {
            return null;
        }

        return $this->fetchItem($itemId);
    }

    private function resolveItemFromMediaRequestOrRoute()
    {
        $mediaId = $this->extractMediaId();
        if (!$mediaId) {
            return null;
        }

        $media = $this->fetchMedia($mediaId);
        if (!$media) {
            return null;
        }

        return $this->resolveItem($media);
    }

    private function extractItemId(): ?int
    {
        $id = $this->extractItemIdFromRequest();
        if ($id) {
            return $id;
        }

        return $this->extractItemIdFromRoute();
    }

    

    private function extractMediaId(): ?int
    {
        $id = $this->extractMediaIdFromRequest();
        if ($id) {
            return $id;
        }

        return $this->extractMediaIdFromRoute();
    }

    private function extractItemIdFromRequest(): ?int
    {
        if (!$this->services || !$this->services->has('Request')) {
            return null;
        }

        $request = $this->services->get('Request');
        if (!$request) {
            return null;
        }

        $id = null;

        if (method_exists($request, 'getPost')) {
            $id = $request->getPost('id', null);
        }
        if ((null === $id || '' === $id) && method_exists($request, 'getQuery')) {
            $id = $request->getQuery('id', null);
        }

        return $this->normalizeId($id);
    }

    private function extractItemIdFromRoute(): ?int
    {
        $routeMatch = $this->getRouteMatch();
        if (!$routeMatch) {
            return null;
        }

        foreach (['item-id', 'item_id', 'id'] as $param) {
            $value = $routeMatch->getParam($param, null);
            $id = $this->normalizeId($value);
            if ($id) {
                return $id;
            }
        }

        return null;
    }

    

    private function extractMediaIdFromRequest(): ?int
    {
        if (!$this->services || !$this->services->has('Request')) {
            return null;
        }

        $request = $this->services->get('Request');
        if (!$request) {
            return null;
        }

        $id = null;

        if (method_exists($request, 'getPost')) {
            $id = $request->getPost('media_id', null);
            if (null === $id || '' === $id) {
                $id = $request->getPost('id', null);
            }
        }
        if ((null === $id || '' === $id) && method_exists($request, 'getQuery')) {
            $id = $request->getQuery('media_id', null);
            if (null === $id || '' === $id) {
                $id = $request->getQuery('id', null);
            }
        }

        return $this->normalizeId($id);
    }

    private function extractMediaIdFromRoute(): ?int
    {
        $routeMatch = $this->getRouteMatch();
        if (!$routeMatch) {
            return null;
        }

        foreach (['media-id', 'media_id', 'id'] as $param) {
            $value = $routeMatch->getParam($param, null);
            $id = $this->normalizeId($value);
            if ($id) {
                return $id;
            }
        }

        return null;
    }

    private function normalizeId($id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (is_array($id)) {
            return null;
        }
        if (!is_scalar($id)) {
            return null;
        }

        $intId = (int) $id;
        return $intId > 0 ? $intId : null;
    }

    private function fetchItem(int $itemId)
    {
        if (!$this->services || !$this->services->has('Omeka\ApiManager')) {
            return null;
        }

        $api = $this->services->get('Omeka\ApiManager');
        if (!$api) {
            return null;
        }

        try {
            return $api->read('items', $itemId)->getContent();
        } catch (NotFoundException | ApiExceptionInterface $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    

    private function fetchMedia(int $mediaId)
    {
        if (!$this->services || !$this->services->has('Omeka\ApiManager')) {
            return null;
        }

        $api = $this->services->get('Omeka\ApiManager');
        if (!$api) {
            return null;
        }

        try {
            return $api->read('media', $mediaId)->getContent();
        } catch (NotFoundException | ApiExceptionInterface $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function shouldLimitToGrantedSites(int $userId): bool
    {
        if (!isset($this->userSettingsCache[$userId])) {
            try {
                $this->userSettings->setTargetId($userId);
                $this->userSettingsCache[$userId] = (bool) $this->userSettings->get('limit_to_granted_sites', false);
            } catch (\Throwable $e) {
                $this->userSettingsCache[$userId] = true;
            }
        }

        return $this->userSettingsCache[$userId];
    }

    private function userHasAccessToAnyItemSite(int $userId, $item, $resource = null): bool
    {
        $itemId = null;
        if ($item instanceof Item) {
            $itemId = (int) $item->getId();
        } elseif ($item instanceof ItemRepresentation) {
            $itemId = (int) $item->id();
        }

        if (!$itemId) {
            return false;
        }

        if (!isset($this->grantedSitesCache[$userId])) {
            $sql = 'SELECT site_id FROM site_permission WHERE user_id = :user_id';
            $stmt = $this->connection->executeQuery($sql, ['user_id' => $userId]);
            $siteIds = array_map('intval', $stmt->fetchFirstColumn());
            $this->grantedSitesCache[$userId] = $siteIds;
        }
        $grantedSiteIds = $this->grantedSitesCache[$userId];
        if (empty($grantedSiteIds)) {
            return false;
        }

        if (!isset($this->itemSitesCache[$itemId])) {
            $sql = 'SELECT site_id FROM item_site WHERE item_id = :item_id';
            $stmt = $this->connection->executeQuery($sql, ['item_id' => $itemId]);
            $siteIds = array_map('intval', $stmt->fetchFirstColumn());
            $this->itemSitesCache[$itemId] = $siteIds;
        }
        $resourceSiteIds = $this->itemSitesCache[$itemId];

        if ($resource) {
            $mediaId = $this->resolveMediaId($resource);
            if ($mediaId) {
                $mediaSiteIds = $this->getMediaSiteIds($mediaId);
                if ($mediaSiteIds) {
                    $resourceSiteIds = array_unique(array_merge($resourceSiteIds, $mediaSiteIds));
                }
            }
        }

        if (empty($resourceSiteIds)) {
            return false;
        }

        return !empty(array_intersect($grantedSiteIds, $resourceSiteIds));
    }

    private function resolveMediaId($resource): ?int
    {
        if ($resource instanceof Media) {
            return $this->normalizeId($resource->getId());
        }

        if ($resource instanceof MediaRepresentation) {
            return $this->normalizeId($resource->id());
        }

        if (is_object($resource)) {
            if (method_exists($resource, 'getEntity')) {
                $entity = $resource->getEntity();
                if ($entity instanceof Media) {
                    return $this->normalizeId($entity->getId());
                }
            }
            if (method_exists($resource, 'resource')) {
                $entity = $resource->resource();
                if ($entity instanceof Media || $entity instanceof MediaRepresentation) {
                    return $this->resolveMediaId($entity);
                }
            }
        }

        if ($resource instanceof MediaAdapter || $resource === MediaAdapter::class) {
            return $this->extractMediaId();
        }

        if ($resource instanceof AdminMediaController || $resource === AdminMediaController::class) {
            return $this->extractMediaId();
        }

        return null;
    }

    private function getMediaSiteIds(int $mediaId): array
    {
        if (isset($this->mediaSitesCache[$mediaId])) {
            return $this->mediaSitesCache[$mediaId];
        }

        if ($this->mediaSiteTableAvailable === false) {
            $this->mediaSitesCache[$mediaId] = [];
            return [];
        }

        try {
            $sql = 'SELECT site_id FROM media_site WHERE media_id = :media_id';
            $stmt = $this->connection->executeQuery($sql, ['media_id' => $mediaId]);
            $siteIds = array_map('intval', $stmt->fetchFirstColumn());
            $this->mediaSitesCache[$mediaId] = $siteIds;
            $this->mediaSiteTableAvailable = true;
            return $siteIds;
        } catch (\Throwable $e) {
            $this->mediaSiteTableAvailable = false;
            $this->mediaSitesCache[$mediaId] = [];
            return [];
        }
    }

    private function getRouteMatch(): ?RouteMatch
    {
        if (!$this->services || !$this->services->has('Application')) {
            return null;
        }

        $application = $this->services->get('Application');
        if (!$application || !method_exists($application, 'getMvcEvent')) {
            return null;
        }

        $event = $application->getMvcEvent();
        if (!$event || !method_exists($event, 'getRouteMatch')) {
            return null;
        }

        $routeMatch = $event->getRouteMatch();
        return $routeMatch instanceof RouteMatch ? $routeMatch : null;
    }
}
