<?php
declare(strict_types=1);

namespace IsolatedSites\Listener;

use Doctrine\ORM\EntityManager;
use IsolatedSites\Module;
use IsolatedSites\Service\GrantedSites;
use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\EventInterface;
use Omeka\Api\Request;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Site;
use Omeka\Entity\SiteItemSet;

/**
 * Reconciles an item set's site assignments for site-scoped roles.
 *
 * Core exposes site assignment only on Site > Resources, which site_editor may
 * not reach, so this listener consumes the o:site[] field that
 * ItemSetSitesFormListener adds to the item-set form.
 */
class ItemSetSitesHydrationListener
{
    /**
     * Roles whose item-set site assignments this listener manages. Every other
     * role keeps core behaviour, so o:site is ignored for them.
     */
    private const MANAGED_ROLES = [
        Module::ROLE_SITE_EDITOR,
        Module::ROLE_SITE_MANAGER,
    ];

    private $auth;
    private $grantedSites;
    private $entityManager;

    public function __construct(
        AuthenticationService $auth,
        GrantedSites $grantedSites,
        EntityManager $entityManager
    ) {
        $this->auth = $auth;
        $this->grantedSites = $grantedSites;
        $this->entityManager = $entityManager;
    }

    public function __invoke(EventInterface $event): void
    {
        $user = $this->auth->getIdentity();
        if (!$user || !in_array($user->getRole(), self::MANAGED_ROLES, true)) {
            return;
        }

        $entity = $event->getParam('entity');
        $request = $event->getParam('request');
        if (!$entity instanceof ItemSet || !$request instanceof Request) {
            return;
        }

        $content = $request->getContent();
        // An absent key means "leave assignments alone" (e.g. a partial REST
        // update). Only an explicitly submitted, empty list unassigns.
        if (!array_key_exists('o:site', $content)) {
            return;
        }

        $granted = $this->grantedSites->forUser((int) $user->getId());
        $submitted = array_values(array_intersect(
            $this->normalizeIds($content['o:site']),
            $granted
        ));

        $existing = [];
        foreach ($entity->getSiteItemSets() as $row) {
            $site = $row->getSite();
            if ($site) {
                $existing[(int) $site->getId()] = $row;
            }
        }

        // Unassign only what the user can see. Rows for sites outside their
        // permissions are left untouched and never surfaced.
        foreach ($existing as $siteId => $row) {
            if (in_array($siteId, $granted, true) && !in_array($siteId, $submitted, true)) {
                $this->entityManager->remove($row);
            }
        }

        foreach ($submitted as $siteId) {
            if (isset($existing[$siteId])) {
                continue;
            }
            $site = $this->entityManager->find(Site::class, $siteId);
            if (!$site) {
                continue;
            }
            // ItemSet::$siteItemSets has no cascade, so the row must be
            // persisted explicitly; adding to the collection would no-op.
            $row = new SiteItemSet();
            $row->setSite($site);
            $row->setItemSet($entity);
            $row->setPosition($this->nextPosition($site));
            $this->entityManager->persist($row);
        }
    }

    /**
     * Accepts both the form's scalar ids and the REST shape [{'o:id': 1}].
     *
     * @param mixed $value
     * @return int[]
     */
    private function normalizeIds($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['o:id'] ?? null;
            }
            if (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    private function nextPosition(Site $site): int
    {
        $max = 0;
        foreach ($site->getSiteItemSets() as $row) {
            $max = max($max, (int) $row->getPosition());
        }

        return $max + 1;
    }
}
