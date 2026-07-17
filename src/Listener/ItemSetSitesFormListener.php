<?php
declare(strict_types=1);

namespace IsolatedSites\Listener;

use Doctrine\ORM\EntityManager;
use IsolatedSites\Module;
use IsolatedSites\Service\GrantedSites;
use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\EventInterface;
use Omeka\Entity\Site;
use Omeka\Settings\UserSettings;

/**
 * Adds a Sites section to the item-set add/edit form for site-scoped roles.
 *
 * Core's item-set form has no site selector — assignment lives on Site >
 * Resources, which site_editor may not reach — so without this a Site Editor
 * cannot place an item set in their own site.
 */
class ItemSetSitesFormListener
{
    /** Must match the fieldset id in the partial for the tab to activate. */
    public const SECTION_ID = 'isolatedsites-item-set-sites';

    private const MANAGED_ROLES = [
        Module::ROLE_SITE_EDITOR,
        Module::ROLE_SITE_MANAGER,
    ];

    private $auth;
    private $grantedSites;
    private $entityManager;
    private $userSettings;

    public function __construct(
        AuthenticationService $auth,
        GrantedSites $grantedSites,
        EntityManager $entityManager,
        UserSettings $userSettings
    ) {
        $this->auth = $auth;
        $this->grantedSites = $grantedSites;
        $this->entityManager = $entityManager;
        $this->userSettings = $userSettings;
    }

    /**
     * Triggered with $filter = true, so params must be written back.
     */
    public function addSectionNav(EventInterface $event): void
    {
        if (!$this->isManagedUser()) {
            return;
        }

        $sectionNav = $event->getParam('section_nav');
        $sectionNav[self::SECTION_ID] = 'Sites'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Triggered with $filter = false, so the markup must be echoed.
     */
    public function renderFieldset(EventInterface $event): void
    {
        if (!$this->isManagedUser()) {
            return;
        }

        $view = $event->getTarget();
        $itemSet = isset($view->itemSet) ? $view->itemSet : null;

        echo $view->partial('isolated-sites/item-set-sites-fieldset', [
            'sectionId' => self::SECTION_ID,
            'sites' => $this->buildSiteOptions($itemSet),
        ]);
    }

    /**
     * @param \Omeka\Api\Representation\ItemSetRepresentation|null $itemSet
     * @return array<int, array{id: int, title: string, selected: bool}>
     */
    public function buildSiteOptions($itemSet = null): array
    {
        $user = $this->auth->getIdentity();
        if (!$user) {
            return [];
        }

        $granted = $this->grantedSites->forUser((int) $user->getId());
        if (!$granted) {
            return [];
        }

        $selected = array_intersect(
            $itemSet
                ? array_map('intval', array_keys($itemSet->sites()))
                : $this->defaultSiteIds((int) $user->getId()),
            $granted
        );

        $sites = $this->entityManager
            ->getRepository(Site::class)
            ->findBy(['id' => $granted], ['title' => 'ASC']);

        $options = [];
        foreach ($sites as $site) {
            $id = (int) $site->getId();
            $options[] = [
                'id' => $id,
                'title' => (string) $site->getTitle(),
                'selected' => in_array($id, $selected, true),
            ];
        }

        return $options;
    }

    /**
     * Reuses the core setting that already drives site assignment for new items.
     *
     * @return int[]
     */
    private function defaultSiteIds(int $userId): array
    {
        $this->userSettings->setTargetId($userId);
        $defaults = $this->userSettings->get('default_item_sites', []);

        return is_array($defaults) ? array_map('intval', $defaults) : [];
    }

    private function isManagedUser(): bool
    {
        $user = $this->auth->getIdentity();

        return $user && in_array($user->getRole(), self::MANAGED_ROLES, true);
    }
}
