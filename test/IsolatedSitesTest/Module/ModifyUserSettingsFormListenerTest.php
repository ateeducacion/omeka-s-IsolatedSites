<?php
namespace IsolatedSitesTest\Listener;

use IsolatedSites\Listener\ModifyUserSettingsFormListener;
use Omeka\Entity\User;
use Omeka\Permissions\Acl;
use Doctrine\ORM\EntityManager;
use Omeka\Settings\UserSettings;
use PHPUnit\Framework\TestCase;
use Laminas\EventManager\Event;
use Laminas\Form\Form;
use Laminas\Form\Fieldset;
use Laminas\InputFilter\InputFilter;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Storage\StorageInterface;

class ModifyUserSettingsFormListenerTest extends TestCase
{
    private $listener;
    private $acl;
    private $entityManager;
    private $userSettings;
    private $auth;
    private $event;
    private $form;
    private $user;
    private $fieldset;
    private $currentUser;

    protected function setUp(): void
    {
        // Create mocks for dependencies
        $this->acl = $this->createMock(Acl::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->auth = $this->createMock(AuthenticationService::class);
        
        // Create the listener
        $this->listener = new ModifyUserSettingsFormListener(
            $this->acl,
            $this->entityManager,
            $this->userSettings,
            $this->auth
        );

        // Create mock form, event, and other common objects
        $this->form = $this->createMock(Form::class);
        $this->event = $this->createMock(Event::class);
        $this->user = $this->createMock(User::class);
        $this->fieldset = $this->createMock(Fieldset::class);
        $this->currentUser = $this->createMock(User::class);
    }

    public function testInvokeForGlobalAdminUser()
    {
        // Setup storage and identity
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('read')->willReturn((object)['id' => 1]);
        $this->auth->method('getStorage')->willReturn($storage);
        
        // Setup current user as editor
        $this->auth->method('getIdentity')->willReturn($this->currentUser);
        $this->currentUser->method('getRole')->willReturn('global_admin');
        $this->currentUser->method('getId')->willReturn(1);
   
        $this->entityManager->method('find')
            ->willReturnCallback(function($class, $id) {
                if ($class === User::class && $id === 1) {
                    return $this->currentUser;
                }
                if ($class === User::class && $id === 2) {
                    return $this->user;
                }
                return null;
        });
    
        $this->acl->method('isAdminRole')
            ->willReturnCallback(function ($role) {
                return $role === 'global_admin';
        });
    
        $this->event->expects($this->once())
            ->method('getTarget')
            ->willReturn($this->form);

        $this->form->expects($this->once())
            ->method('getOption')
            ->with('user_id')->willreturn(2);

        $this->user->expects($this->once())
            ->method('getRole')
            ->willReturn('editor');

        $this->form->expects($this->once())
            ->method('get')
            ->with('user-settings')->willReturn($this->fieldset);
    
        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', false)
            ->willReturn(false);
    
        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with(2);
    
        // Check that the field is added without disabled/readonly attributes
        $this->fieldset->expects($this->once())
            ->method('add')
            ->with($this->callback(function($params) {
                return (!isset($params['attributes']['disabled']) || $params['attributes']['disabled'] === false)
                    && (!isset($params['attributes']['readonly']) || $params['attributes']['readonly'] === false);
            }));
    
        $this->user->method('getId')->willReturn(2);
        $this->listener->__invoke($this->event);
    }

    public function testInvokeForNonGlobalAdminUser()
    {
        // Setup storage and identity
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('read')->willReturn((object)['id' => 1]);
        $this->auth->method('getStorage')->willReturn($storage);

        // Setup current user as editor
        $this->auth->method('getIdentity')->willReturn($this->currentUser);
        $this->currentUser->method('getRole')->willReturn('editor');
        $this->currentUser->method('getId')->willReturn(1);


        $this->entityManager->method('find')
            ->willReturnCallback(function($class, $id) {
                if ($class === User::class && $id === 1) {
                    return $this->currentUser;
                }
                if ($class === User::class && $id === 2) {
                    return $this->user;
                }
                return null;
        });

        $this->acl->method('isAdminRole')
            ->willReturnCallback(function ($role) {
                return $role === 'global_admin';
        });

        $this->event->expects($this->once())
            ->method('getTarget')
            ->willReturn($this->form);

        $this->form->expects($this->once())
            ->method('getOption')
            ->with('user_id')->willreturn(2);

        $this->user->expects($this->once())
            ->method('getRole')
            ->willReturn('editor');

        $this->form->expects($this->once())
            ->method('get')
            ->with('user-settings')->willReturn($this->fieldset);
    
        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', false)
            ->willReturn(false);
    
        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with(2);

        $this->userSettings->method('get')
            ->with('limit_to_granted_sites', false)
            ->willReturn(false);

        // Check that the field is added with disabled/readonly attributes
        $this->fieldset->expects($this->once())
            ->method('add')
            ->with($this->callback(function($params) {
                return $params['attributes']['disabled'] === true 
                    && $params['attributes']['readonly'] === true;
            }));

        $this->listener->__invoke($this->event);
    }

    public function testHandleUserSettingsAsGlobalAdmin()
    {
        // Setup storage and identity
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('read')->willReturn((object)['id' => 1]);
        $this->auth->method('getStorage')->willReturn($storage);
    
        // Setup current user as editor
        $this->auth->method('getIdentity')->willReturn($this->currentUser);
        $this->currentUser->method('getRole')->willReturn('global_admin');
        $this->currentUser->method('getId')->willReturn(1);


        $this->entityManager->method('find')
            ->willReturnCallback(function($class, $id) {
                if ($class === User::class && $id === 1) {
                    return $this->currentUser;
                }
                if ($class === User::class && $id === 2) {
                    return $this->user;
                }
                return null;
        });
    
        $this->acl->method('isAdminRole')
            ->willReturnCallback(function ($role) {
                return $role === 'global_admin';
        });

        $formData = ['limit_to_granted_sites' => true];
        
        // Setup the event to return the form
        $this->event->expects($this->once())
            ->method('getTarget')
            ->willReturn($this->form);
    
        $this->form->expects($this->once())
            ->method('getData')
            ->willReturn($formData);
            
        $this->form->expects($this->once())
            ->method('getOption')
            ->with('user_id')
            ->willReturn(2);
    
        // Verify that settings are changed
        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with(2);
    
        $this->userSettings->expects($this->once())
            ->method('set')
            ->with('limit_to_granted_sites', true);
    
        $this->listener->handleUserSettings($this->event);
    }

    public function testHandleUserSettingsAsNonGlobalAdmin()
    {
        // Setup storage and identity
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('read')->willReturn((object)['id' => 1]);
        $this->auth->method('getStorage')->willReturn($storage);
    
        // Setup current user as editor
        $this->auth->method('getIdentity')->willReturn($this->currentUser);
        $this->currentUser->method('getRole')->willReturn('editor');
        $this->currentUser->method('getId')->willReturn(1);


        $this->entityManager->method('find')
            ->willReturnCallback(function($class, $id) {
                if ($class === User::class && $id === 1) {
                    return $this->currentUser;
                }
                if ($class === User::class && $id === 2) {
                    return $this->user;
                }
                return null;
        });
    
        $this->acl->method('isAdminRole')
            ->willReturnCallback(function ($role) {
                return $role === 'global_admin';
        });
    
        $formData = ['limit_to_granted_sites' => true];
        
        // Setup the event to return the form
        $this->event->expects($this->once())
            ->method('getTarget')
            ->willReturn($this->form);
    
        $this->form->expects($this->once())
            ->method('getData')
            ->willReturn($formData);
            
        $this->form->expects($this->once())
            ->method('getOption')
            ->with('user_id')
            ->willReturn(2);
    
        // Get existing setting
        $this->userSettings->expects($this->once())
            ->method('setTargetId')
            ->with(2);
    
        $this->userSettings->method('get')
            ->with('limit_to_granted_sites', false)
            ->willReturn(false);
    
        // Verify that original setting is preserved
        $this->userSettings->expects($this->never())
            ->method('set')
            ->with('limit_to_granted_sites', false);
    
        $this->listener->handleUserSettings($this->event);
    }

    public function testAddInputFilters()
    {
        $inputFilter = $this->createMock(InputFilter::class);

        $this->event->expects($this->once())
            ->method('getTarget')
            ->willReturn($this->form);

        $this->form->expects($this->once())
            ->method('getInputFilter')
            ->willReturn($inputFilter);

        $inputFilter->expects($this->once())
            ->method('add')
            ->with($this->callback(function($params) {
                return $params['name'] === 'limit_to_granted_sites'
                    && $params['required'] === false
                    && isset($params['filters'])
                    && $params['filters'][0]['name'] === 'Boolean';
            }));

        $this->listener->addInputFilters($this->event);
    }

    public function testInvokeWithoutUserId()
    {
        $this->event->method('getTarget')->willReturn($this->form);
        $this->form->method('getOption')->with('user_id')->willReturn(null);

        $this->entityManager->expects($this->never())->method('find');
        $this->fieldset->expects($this->never())->method('add');

        $this->listener->__invoke($this->event);
    }

    public function testInvokeWithInvalidUserId()
    {
        $this->event->method('getTarget')->willReturn($this->form);
        $this->form->method('getOption')->with('user_id')->willReturn(999);

        $this->entityManager->method('find')
            ->with(User::class, 999)
            ->willReturn(null);

        $this->fieldset->expects($this->never())->method('add');

        $this->listener->__invoke($this->event);
    }

    public function testHandleUserSettingsWithoutUserId()
    {
        $this->event->method('getTarget')->willReturn($this->form);
        $this->form->method('getOption')->with('user_id')->willReturn(null);

        $this->userSettings->expects($this->never())->method('set');

        $this->listener->handleUserSettings($this->event);
    }

    public function testHandleUserSettingsWithInvalidUserId()
    {
        $this->event->method('getTarget')->willReturn($this->form);
        $this->form->method('getOption')->with('user_id')->willReturn(999);

        $this->entityManager->method('find')
            ->with(User::class, 999)
            ->willReturn(null);

        $this->userSettings->expects($this->never())->method('set');

        $this->listener->handleUserSettings($this->event);
    }
}
