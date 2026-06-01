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
    /** Custom role for site editors with limited access to their granted sites */
    const ROLE_SITE_EDITOR = 'site_editor';
    
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

        $acl->addRole(self::ROLE_SITE_EDITOR, Acl::ROLE_EDITOR);
        $acl->addRoleLabel(self::ROLE_SITE_EDITOR, 'Site Editor'); // @translate



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

        //Items/Media permissions
        // Deny update if no access to any site of the item
        $itemResources = [
            \Omeka\Entity\Item::class,
            \Omeka\Entity\Media::class,
        ];
        $acl->deny(
            self::ROLE_SITE_EDITOR,
            $itemResources,
            ['update', 'delete', 'edit'],
            $denyIfNoAccess
        );

        $acl->allow(
            self::ROLE_SITE_EDITOR,
            $itemResources,
            ['read', 'browse', 'show', 'index']
        );

        $acl->deny(
            'editor',
            [
                'Omeka\Api\Adapter\ItemAdapter',
                'Omeka\Api\Adapter\MediaAdapter',
            ],
            [
                'batch_delete_all',
            ]
        );


        // ItemSets/Asset permissions
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

        $acl->deny(
            'site_editor',
            [
                \Omeka\Entity\ItemSet::class,
                \Omeka\Entity\Asset::class,
            ],
            ['update', 'delete'],
            $denyIfNotOwned
        );

        $acl->allow(
            'site_editor',
            [
                \Omeka\Entity\ItemSet::class,
                \Omeka\Entity\Asset::class,
            ],
            ['create']
        );

        // Deny access to logs
        if ($this->hasAclResource($acl, \Log\Controller\Admin\LogController::class)) {
            $acl->deny(
                'site_editor',
                [\Log\Controller\Admin\LogController::class],
                ['browse']
            );
        }
        //Resource template permissions
        // Deny all resource template actions inherited from editor role

        $acl->deny(
            self::ROLE_SITE_EDITOR,
            [\Omeka\Entity\ResourceTemplate::class,
            \Omeka\Controller\Admin\ResourceTemplate::class,
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class],
        );

        // Allow only specific read actions
        $acl->allow(
            self::ROLE_SITE_EDITOR,
            [\Omeka\Controller\Admin\ResourceTemplate::class],
            ['index', 'browse', 'show', 'show-details','table-templates']
        );
        // Allow only specific read actions
        $acl->allow(
            self::ROLE_SITE_EDITOR,
            [\Omeka\Entity\ResourceTemplate::class,
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class],
            ['read','search']
        );

        // User admin permissions
        $isSelfAssertion = new IsSelfAssertion();

        $acl->deny(
            self::ROLE_SITE_EDITOR,
            [
                \Omeka\Entity\User::class,
            ]
        );

        $acl->deny(
            self::ROLE_SITE_EDITOR,
            [\Omeka\Controller\Admin\User::class],
            ['browse']
        );
        
        $acl->allow(
            self::ROLE_SITE_EDITOR,
            [\Omeka\Entity\User::class],
            ['read', 'update', 'change-password'],
            $isSelfAssertion
        );

        $acl->allow(
            self::ROLE_SITE_EDITOR,
            [\Omeka\Controller\Admin\User::class],
            ['show', 'edit'],
            $isSelfAssertion
        );

        // ─── SITE: denegar creación y administración general del sitio ───────────────
        $acl->deny(
            Module::ROLE_SITE_EDITOR,
            \Omeka\Entity\Site::class,
            ['create', 'delete']
        );

        $acl->deny(
            'site_editor',
            \Omeka\Controller\SiteAdmin\Index::class,
            ['index', 'edit', 'navigation', 'users', 'theme', 'add', 'delete']
        );

        // ─── PÁGINAS: permitir solo listar y editar páginas ──────────────────────────
        $acl->allow(
            'site_editor',
            \Omeka\Entity\SitePage::class,
            ['read', 'index']        // sin 'create' ni 'delete'
        );


        // Site editors may edit existing pages of sites where they have the
        // required per-site permission (core gates SitePage 'update' behind a
        // per-site assertion); they cannot create or delete pages. 'edit' must NOT
        // be denied here, otherwise the controller-level deny 403s the page editor.
        $acl->deny(
            'site_editor',
            \Omeka\Controller\SiteAdmin\Page::class,
            ['add', 'delete']         // no puede crear ni eliminar páginas
        );

        // ─── SYSTEM INFO: denegar acceso a información del sistema ───────────────────

        $acl->deny(
            self::ROLE_SITE_EDITOR,
            [\Omeka\Controller\Admin\SystemInfo::class],
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
