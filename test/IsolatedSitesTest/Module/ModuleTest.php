<?php
declare(strict_types=1);

namespace IsolatedSitesTest\Module;

use IsolatedSites\Module;
use IsolatedSites\Form\ConfigForm;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Controller\Plugin\Messenger;
use PHPUnit\Framework\TestCase;
use IsolatedSites\Listener\ModifyQueryListener;
use IsolatedSites\Listener\ModifyUserSettingsFormListener;
use IsolatedSites\Listener\ModifyItemSetQueryListener;
use IsolatedSites\Listener\ModifyAssetQueryListener;
use IsolatedSites\Listener\ModifySiteQueryListener;

class ModuleTest extends TestCase
{
    protected $module;
    protected $serviceLocator;
    protected $sharedEventManager;

    public function setUp(): void
    {
        $this->module = new Module();
        
        // Mock service locator
        $this->serviceLocator = $this->createMock(ServiceLocatorInterface::class);
        
        // Mock shared event manager
        $this->sharedEventManager = $this->createMock(SharedEventManagerInterface::class);

        $this->ModifyUserSettingsFormListener = $this->createMock(ModifyUserSettingsFormListener::class);

        $this->ModifyQueryListener = $this->createMock(ModifyQueryListener::class);

        $this->ModifyItemSetQueryListener = $this->createMock(ModifyItemSetQueryListener::class);
    }

    public function testGetConfig()
    {
        $config = $this->module->getConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('form_elements', $config);
        $this->assertArrayHasKey('service_manager', $config);
    }

    public function testInstall()
    {
        // Capture that success message is added
        $messenger = $this->createMock(\Omeka\Mvc\Controller\Plugin\Messenger::class);
        $messenger->expects($this->once())
            ->method('addSuccess')
            ->with($this->callback(function ($message) {
                return $message instanceof \Omeka\Stdlib\Message
                    && (string) $message === 'IsolatedSites module installed.';
            }));
    
        $this->module->install($this->serviceLocator, $messenger);
    }

    public function testUninstall()
    {
        // Capture that success message is added
        $messenger = $this->createMock(\Omeka\Mvc\Controller\Plugin\Messenger::class);
        $messenger->expects($this->once())
            ->method('addSuccess')
            ->with($this->callback(function ($message) {
                return $message instanceof \Omeka\Stdlib\Message
                    && (string) $message === 'IsolatedSites module uninstalled.';
            }));
    
        $this->module->uninstall($this->serviceLocator, $messenger);
    }

    public function testAttachListeners()
    {
        
        // Setup mock listeners as invokable objects
        $mockUserSettingsListener = $this->getMockBuilder(ModifyUserSettingsFormListener::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $mockQueryListener = $this->getMockBuilder(ModifyQueryListener::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $mockItemSetQueryListener = $this->getMockBuilder(ModifyItemSetQueryListener::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockAssetQueryListener = $this->getMockBuilder(ModifyAssetQueryListener::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockSiteQueryListener = $this->getMockBuilder(ModifySiteQueryListener::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Setup service locator to return our mock listeners
        $this->serviceLocator->expects($this->exactly(7))
            ->method('get')
            ->willReturnMap([
                [ModifyUserSettingsFormListener::class, $mockUserSettingsListener],
                [ModifyQueryListener::class, $mockQueryListener],
                [ModifyItemSetQueryListener::class, $mockItemSetQueryListener],
                [ModifyAssetQueryListener::class, $mockAssetQueryListener],
                [ModifySiteQueryListener::class, $mockSiteQueryListener],
            ]);

        // Test that all expected event listeners are attached
        $this->sharedEventManager->expects($this->exactly(7))
            ->method('attach')
            ->withConsecutive(
                [
                    $this->equalTo(\Omeka\Form\UserForm::class),
                    $this->equalTo('form.add_elements'),
                    $this->identicalTo([$mockUserSettingsListener, '__invoke'])
                ],
                [
                    $this->equalTo(\Omeka\Form\UserForm::class),
                    $this->equalTo('form.add_input_filters'),
                    $this->identicalTo([$mockUserSettingsListener, 'addInputFilters'])
                ],
                [
                    $this->equalTo('CAS\Controller\LoginController'),
                    $this->equalTo('cas.user.create.post'),
                    $this->identicalTo([$mockUserSettingsListener, 'handleUserSettings'])
                ],
                [
                    $this->equalTo('Omeka\Api\Adapter\ItemAdapter'),
                    $this->equalTo('api.search.query'),
                    $this->identicalTo([$mockQueryListener, '__invoke'])
                ],
                [
                    $this->equalTo('Omeka\Api\Adapter\ItemSetAdapter'),
                    $this->equalTo('api.search.query'),
                    $this->identicalTo([$mockItemSetQueryListener, '__invoke'])
                ],
                [
                    $this->equalTo('Omeka\Api\Adapter\AssetAdapter'),
                    $this->equalTo('api.search.query'),
                    $this->identicalTo([$mockAssetQueryListener, '__invoke'])
                ],
                [
                    $this->equalTo('Omeka\Api\Adapter\SiteAdapter'),
                    $this->equalTo('api.search.query'),
                    $this->identicalTo([$mockSiteQueryListener, '__invoke'])
                ]
            );

        $this->module->setServiceLocator($this->serviceLocator);

        $this->module->attachListeners($this->sharedEventManager);
    }

    public function testGetConfigForm()
    {
        // Mock renderer
       $renderer = $this->getMockBuilder(PhpRenderer::class)
            ->disableOriginalConstructor()
            ->addMethods(['formCollection']) // Add the formCollection method to the mock
            ->getMock();
        
        // Mock settings
        $settings = $this->createMock(\Omeka\Settings\Settings::class);
        $settings->expects($this->once())
            ->method('get')
            ->with('activate_IsolatedSites', 1)
            ->willReturn(1);

        // Mock service locator get calls
        $this->serviceLocator->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                ['Config', []],
                ['Omeka\Settings', $settings]
            ]);

        // Set service locator to module
        $this->module->setServiceLocator($this->serviceLocator);

        // Mock renderer formCollection method
        $renderer->expects($this->once())
            ->method('formCollection')
            ->with(
                $this->isInstanceOf(ConfigForm::class),
                false
            )
            ->willReturn('form_html');

        $result = $this->module->getConfigForm($renderer);
        $this->assertEquals('form_html', $result);
    }

    public function testHandleConfigForm()
    {
        // Mock controller
        $controller = $this->getMockBuilder(AbstractController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['plugin','onDispatch'])
            ->getMock();
        
        // Mock settings
        $settings = $this->createMock(\Omeka\Settings\Settings::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('activate_IsolatedSites', 1);

        // Mock service locator
        $this->serviceLocator->expects($this->once())
            ->method('get')
            ->with('Omeka\Settings')
            ->willReturn($settings);

        // Set service locator to module
        $this->module->setServiceLocator($this->serviceLocator);

        // Mock controller params
        $params = $this->createMock(\Laminas\Mvc\Controller\Plugin\Params::class);

        $controller->expects($this->once())
            ->method('plugin')
            ->with('params')
            ->willReturn($params);

        $params->expects($this->once())
            ->method('fromPost')
            ->willReturn(['activate_IsolatedSites_cb' => 1]);


        $this->module->handleConfigForm($controller);
    }
}
