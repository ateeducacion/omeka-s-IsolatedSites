<?php
declare(strict_types=1);

namespace IsolatedSites;

use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;
use IsolatedSites\Form\ConfigForm;
use IsolatedSites\Listener\ModifyQueryListener;
use IsolatedSites\Listener\ModifyUserSettingsFormListener;
use IsolatedSites\Listener\ModifyItemSetQueryListener;
use IsolatedSites\Listener\ModifyAssetQueryListener;
use IsolatedSites\Listener\ModifySiteQueryListener;

/**
 * Main class for the IsoltatedSites module.
 */
class Module extends AbstractModule
{
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
    public function install(ServiceLocatorInterface $serviceLocator, Messenger $messenger = null)
    {
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
    public function uninstall(ServiceLocatorInterface $serviceLocator, Messenger $messenger = null)
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
            \Omeka\Form\UserForm::class,
            'form.submit',
            [$this->serviceLocator->get(ModifyUserSettingsFormListener::class), 'handleUserSettings']
        );

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
    
    // /**
}
