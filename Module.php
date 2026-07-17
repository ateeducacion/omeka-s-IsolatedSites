<?php
declare(strict_types=1);

namespace IsolatedSites;

use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;
use IsolatedSites\Form\ConfigForm;
use IsolatedSites\Listener\ModifyQueryListener;
use IsolatedSites\Listener\ModifyUserSettingsFormListener;
use IsolatedSites\Listener\ModifyItemSetQueryListener;
use IsolatedSites\Listener\ModifyAssetQueryListener;
use IsolatedSites\Listener\ModifySiteQueryListener;
use IsolatedSites\Listener\ModifyMediaQueryListener;
use IsolatedSites\Listener\UserApiListener;
use IsolatedSites\Listener\ItemSetSitesHydrationListener;
use IsolatedSites\Listener\ItemSetSitesFormListener;
use Omeka\Permissions\Acl;
use Omeka\Permissions\Assertion\IsSelfAssertion;
use Omeka\Permissions\Assertion\OwnsEntityAssertion;
use IsolatedSites\Assertion\HasAccessToItemSiteAssertion;
use Laminas\Permissions\Acl\Assertion\AssertionInterface as AInterface;
use Laminas\Permissions\Acl\Acl as LAcl;
use Laminas\Permissions\Acl\Role\RoleInterface as RInterface;
use Laminas\Permissions\Acl\Resource\ResourceInterface as ResInterface;

/**
 * Main class for the IsoltatedSites module.
 */
class Module extends AbstractModule
{
    /** Site-scoped roles, all isolated to their granted sites. They differ only
     * in write capability (read isolation is driven by the limit_to_granted_sites
     * user setting, not the role):
     *   - site_researcher: read-only within granted sites.
     *   - site_editor: manage content (items, item sets, media), but NOT the site.
     *   - site_manager: content + edit the site (pages, title, navigation, theme).
     */
    const ROLE_SITE_EDITOR = 'site_editor';
    const ROLE_SITE_MANAGER = 'site_manager';
    const ROLE_SITE_RESEARCHER = 'site_researcher';
    
    /**
     * Retrieve the configuration array.
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Execute logic when the module is installed.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function install(ServiceLocatorInterface $serviceLocator, ?Messenger $messenger = null)
    {
        if (!$this->isModuleLoaded('Log', $serviceLocator)) {
            throw new ModuleCannotInstallException(
                'The module "IsolatedSites" requires the module "Log" to be installed and active.'
            );
        }

        if (!$messenger) {
            $messenger = new Messenger();
        }
        $message = new Message("IsolatedSites module installed.");
        $messenger->addSuccess($message);
    }
    /**
     * Execute logic when the module is uninstalled.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator, ?Messenger $messenger = null)
    {
        if (!$messenger) {
            $messenger = new Messenger();
        }
        $message = new Message("IsolatedSites module uninstalled.");
        $messenger->addSuccess($message);
    }

    public function onBootstrap(\Laminas\Mvc\MvcEvent $event)
    {

        $this->serviceLocator = $event->getApplication()->getServiceManager();
        $sharedEventManager = $this->serviceLocator->get('SharedEventManager');

        $this->addAclRoleAndRules();
        $this->attachListeners($sharedEventManager);
    }
    /**
     * Register the file validator service and renderers.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {

        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this->serviceLocator->get(ModifyUserSettingsFormListener::class), '__invoke']
        );

        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_input_filters',
            [$this->serviceLocator->get(ModifyUserSettingsFormListener::class), 'addInputFilters']
        );

        $sharedEventManager->attach(
            'CAS\Controller\LoginController',
            'cas.user.create.post',
            [$this->serviceLocator->get(ModifyUserSettingsFormListener::class), 'handleUserSettings']
        );

        // The "Enable this option to hide unallowed sites" module setting acts as a
        // global kill switch for the read-side site filtering. When disabled, the
        // api.search.query listeners are not attached (the per-user
        // limit_to_granted_sites/limit_to_own_assets flags still gate behaviour
        // when enabled). Defaults to on so isolation stays active unless an admin
        // explicitly turns it off.
        $settings = $this->serviceLocator->get('Omeka\Settings');
        if ($settings->get('activate_IsolatedSites', true)) {
            //Listener to limit item view
            $sharedEventManager->attach(
                'Omeka\Api\Adapter\ItemAdapter',
                'api.search.query',
                [$this->serviceLocator->get(ModifyQueryListener::class), '__invoke']
            );

            // For limit the view of ItemSets
            $sharedEventManager->attach(
                'Omeka\Api\Adapter\ItemSetAdapter',
                'api.search.query',
                [$this->serviceLocator->get(ModifyItemSetQueryListener::class), '__invoke']
            );

            // For limit the view of Assets
            $sharedEventManager->attach(
                'Omeka\Api\Adapter\AssetAdapter',
                'api.search.query',
                [$this->serviceLocator->get(ModifyAssetQueryListener::class), '__invoke']
            );

            $sharedEventManager->attach(
                'Omeka\Api\Adapter\SiteAdapter',
                'api.search.query',
                [$this->serviceLocator->get(ModifySiteQueryListener::class), '__invoke']
            );

            // For limit the view of Media
            $sharedEventManager->attach(
                'Omeka\Api\Adapter\MediaAdapter',
                'api.search.query',
                [$this->serviceLocator->get(ModifyMediaQueryListener::class), '__invoke']
            );
        }

        // Item-set site assignment for site-scoped roles. Deliberately outside
        // the activate_IsolatedSites switch: that flag governs read-side
        // filtering, not the ability to place an item set in your own site.
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.hydrate.post',
            [$this->serviceLocator->get(ItemSetSitesHydrationListener::class), '__invoke']
        );

        $formListener = $this->serviceLocator->get(ItemSetSitesFormListener::class);
        foreach (['add', 'edit'] as $action) {
            $sharedEventManager->attach(
                'Omeka\Controller\Admin\ItemSet',
                "view.$action.section_nav",
                [$formListener, 'addSectionNav']
            );
            $sharedEventManager->attach(
                'Omeka\Controller\Admin\ItemSet',
                "view.$action.form.after",
                [$formListener, 'renderFieldset']
            );
        }

        // API listeners for custom user settings
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\UserAdapter',
            'api.hydrate.post',
            [$this->serviceLocator->get(UserApiListener::class), 'handleApiHydrate']
        );

        // This event is triggered for JSON-LD serialization (works for both REST and some PHP API cases)
        $sharedEventManager->attach(
            'Omeka\Api\Representation\UserRepresentation',
            'rep.resource.json',
            [$this->serviceLocator->get(UserApiListener::class), 'handleRepresentationJson']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\UserAdapter',
            'api.create.post',
            [$this->serviceLocator->get(UserApiListener::class), 'handleApiCreate']
        );
    }
    /**
     * Get the configuration form for this module.
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->serviceLocator;
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');
        
        $form = new ConfigForm;
        $form->init();
        
        $form->setData([
            'activate_IsolatedSites_cb' => $settings->get('activate_IsolatedSites', 1),
        ]);
        
        return $renderer->formCollection($form, false);
    }
    
    /**
     * Handle the configuration form submission.
     *
     * @param AbstractController $controller
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        
        $config = $controller->plugin('params')->fromPost();

        $value = isset($config['activate_IsolatedSites_cb']) ? $config['activate_IsolatedSites_cb'] : 0;

        // Save configuration settings in omeka settings database
        $settings->set('activate_IsolatedSites', $value);
    }
     /**
     * Add ACL role and rules for this module.
     */
    protected function addAclRoleAndRules(): void
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // ─── Roles ────────────────────────────────────────────────────────────────
        // site_researcher inherits the read-only "researcher"; site_editor and
        // site_manager inherit "editor". All three are isolated to their granted
        // sites via the limit_to_granted_sites user setting.
        $acl->addRole(self::ROLE_SITE_RESEARCHER, Acl::ROLE_RESEARCHER);
        $acl->addRoleLabel(self::ROLE_SITE_RESEARCHER, 'Site Researcher'); // @translate
        $acl->addRole(self::ROLE_SITE_EDITOR, Acl::ROLE_EDITOR);
        $acl->addRoleLabel(self::ROLE_SITE_EDITOR, 'Site Editor'); // @translate
        $acl->addRole(self::ROLE_SITE_MANAGER, Acl::ROLE_EDITOR);
        $acl->addRoleLabel(self::ROLE_SITE_MANAGER, 'Site Manager'); // @translate

        // Roles allowed to manage content (create/edit items, item sets, media).
        $contentRoles = [self::ROLE_SITE_EDITOR, self::ROLE_SITE_MANAGER];
        // Roles that may NOT edit the site itself (pages, title, navigation, theme).
        $noSiteEditRoles = [self::ROLE_SITE_EDITOR, self::ROLE_SITE_RESEARCHER];
        // Every site-scoped role (shared restrictions apply to all of them).
        $siteRoles = [
            self::ROLE_SITE_RESEARCHER,
            self::ROLE_SITE_EDITOR,
            self::ROLE_SITE_MANAGER,
        ];

        // ─── Assertions ─────────────────────────────────────────────────────────────
        $siteAccessAssertion = $services->get(HasAccessToItemSiteAssertion::class);
        if (method_exists($siteAccessAssertion, 'setServiceLocator')) {
            $siteAccessAssertion->setServiceLocator($services);
        }

        $denyIfNoAccess = new class($siteAccessAssertion) implements AInterface {
            private $inner;

            public function __construct(AInterface $inner)
            {
                $this->inner = $inner;
            }

            public function assert(
                LAcl $acl,
                ?RInterface $role = null,
                ?ResInterface $resource = null,
                $privilege = null
            ) {
                try {
                    return !$this->inner->assert($acl, $role, $resource, $privilege);
                } catch (\Throwable $e) {
                    return true;
                }
            }
        };

        $ownsAssertion = new OwnsEntityAssertion();
        $denyIfNotOwned = new class($ownsAssertion) implements AInterface {
            private $owns;

            public function __construct(OwnsEntityAssertion $owns)
            {
                $this->owns = $owns;
            }

            public function assert(
                LAcl $acl,
                ?RInterface $role = null,
                ?ResInterface $resource = null,
                $privilege = null
            ) {
                try {
                    return !$this->owns->assert($acl, $role, $resource, $privilege);
                } catch (\Throwable $e) {
                    return true;
                }
            }
        };

        // ─── Items / Media ──────────────────────────────────────────────────────────
        $itemResources = [
            \Omeka\Entity\Item::class,
            \Omeka\Entity\Media::class,
        ];

        // Everyone may read (the query listeners scope WHAT is read); content roles
        // may edit only items/media reachable through a granted site (or owned);
        // researchers are strictly read-only.
        $acl->allow($siteRoles, $itemResources, ['read', 'browse', 'show', 'index']);
        $acl->deny($contentRoles, $itemResources, ['update', 'delete', 'edit'], $denyIfNoAccess);
        $acl->deny(
            self::ROLE_SITE_RESEARCHER,
            $itemResources,
            ['create', 'update', 'delete', 'edit']
        );

        $acl->deny(
            Acl::ROLE_EDITOR,
            [
                'Omeka\Api\Adapter\ItemAdapter',
                'Omeka\Api\Adapter\MediaAdapter',
            ],
            [
                'batch_delete_all',
            ]
        );

        // ─── Item sets / Assets ───────────────────────────────────────────────────────
        $ownedResources = [
            \Omeka\Entity\ItemSet::class,
            \Omeka\Entity\Asset::class,
        ];
        $acl->allow($contentRoles, $ownedResources, ['create']);
        $acl->deny($contentRoles, $ownedResources, ['update', 'delete'], $denyIfNotOwned);
        $acl->deny(self::ROLE_SITE_RESEARCHER, $ownedResources, ['create', 'update', 'delete']);

        // ─── Logs ───────────────────────────────────────────────────────────────────
        if ($this->hasAclResource($acl, \Log\Controller\Admin\LogController::class)) {
            $acl->deny($siteRoles, [\Log\Controller\Admin\LogController::class], ['browse']);
        }

        // ─── Resource templates: read-only for every site role ──────────────────────
        $acl->deny(
            $siteRoles,
            [\Omeka\Entity\ResourceTemplate::class,
            \Omeka\Controller\Admin\ResourceTemplate::class,
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class],
        );
        $acl->allow(
            $siteRoles,
            [\Omeka\Controller\Admin\ResourceTemplate::class],
            ['index', 'browse', 'show', 'show-details','table-templates']
        );
        $acl->allow(
            $siteRoles,
            [\Omeka\Entity\ResourceTemplate::class,
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class],
            ['read','search']
        );

        // ─── Users: self-management only for every site role ─────────────────────────
        $isSelfAssertion = new IsSelfAssertion();
        $acl->deny($siteRoles, [\Omeka\Entity\User::class]);
        $acl->deny($siteRoles, [\Omeka\Controller\Admin\User::class], ['browse']);
        $acl->allow(
            $siteRoles,
            [\Omeka\Entity\User::class],
            ['read', 'update', 'change-password'],
            $isSelfAssertion
        );
        $acl->allow(
            $siteRoles,
            [\Omeka\Controller\Admin\User::class],
            ['show', 'edit'],
            $isSelfAssertion
        );

        // ─── Sites: no site-scoped role may create or delete sites ───────────────────
        $acl->deny($siteRoles, \Omeka\Entity\Site::class, ['create', 'delete']);

        // ─── System info: off-limits ─────────────────────────────────────────────────
        $acl->deny($siteRoles, [\Omeka\Controller\Admin\SystemInfo::class]);

        // ─── Site administration (SiteAdmin\Index + SitePage) ────────────────────────
        // site_manager MAY edit the site (title, navigation, theme) and its pages, but
        // cannot create/delete sites nor manage site user permissions.
        $acl->deny(
            self::ROLE_SITE_MANAGER,
            \Omeka\Controller\SiteAdmin\Index::class,
            ['add', 'delete', 'users']
        );

        // site_editor and site_researcher cannot edit the site at all.
        $acl->deny(
            $noSiteEditRoles,
            \Omeka\Controller\SiteAdmin\Index::class,
            ['index', 'edit', 'navigation', 'users', 'theme', 'add', 'delete']
        );

        // Pages: everyone may view; only site_manager may add/edit/delete (core still
        // gates SitePage writes behind a per-site permission assertion).
        $acl->allow($siteRoles, \Omeka\Entity\SitePage::class, ['read', 'index']);
        $acl->deny(
            $noSiteEditRoles,
            \Omeka\Controller\SiteAdmin\Page::class,
            ['add', 'edit', 'delete']
        );
    }

    protected function isModuleLoaded(string $moduleName, ?ServiceLocatorInterface $serviceLocator = null): bool
    {
        $serviceLocator = $serviceLocator ?: $this->getServiceLocator();
        if (!$serviceLocator) {
            return false;
        }

        try {
            $moduleManager = $serviceLocator->get('ModuleManager');
        } catch (\Throwable $e) {
            return false;
        }

        if (!method_exists($moduleManager, 'getLoadedModules')) {
            return false;
        }

        return array_key_exists($moduleName, $moduleManager->getLoadedModules(true));
    }

    protected function hasAclResource($acl, string $resource): bool
    {
        return is_object($acl) && method_exists($acl, 'hasResource') && $acl->hasResource($resource);
    }
}
