<?php

namespace IsolatedSitesTest\Listener;

use IsolatedSites\Listener\ModifyAssetQueryListener;
use Laminas\EventManager\EventInterface;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Omeka\Entity\User;

class ModifyAssetQueryListenerTest extends TestCase
{
    private $authService;
    private $userSettings;
    private $event;
    private $queryBuilder;
    private $user;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthenticationService::class);
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->event = $this->createMock(EventInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->user = $this->createMock(User::class);
        $this->application = $this->createMock(\Laminas\Mvc\Application::class);
        $this->mvcEvent = $this->createMock(\Laminas\Mvc\MvcEvent::class);
        $this->routeMatch = $this->createMock(\Laminas\Router\RouteMatch::class);
        
        // Setup application mock to return MVC event
        $this->application->method('getMvcEvent')->willReturn($this->mvcEvent);
        $this->mvcEvent->method('getRouteMatch')->willReturn($this->routeMatch);
        $this->routeMatch->method('getMatchedRouteName')->willReturn('admin/default');
    }

    public function testGlobalAdminBypassesFilter()
    {
        // Setup
        $this->user->method('getRole')->willReturn('global_admin');
        $this->authService->method('getIdentity')->willReturn($this->user);

        $listener = new ModifyAssetQueryListener(
            $this->authService,
            $this->userSettings,
            $this->application
        );

        // Event should not be modified
        $this->event->expects($this->never())
            ->method('getParam');

        $listener($this->event);
    }

    public function testPublicUserBypassesFilter()
    {
        // Setup
        $this->authService->method('getIdentity')->willReturn(null);

        $listener = new ModifyAssetQueryListener(
            $this->authService,
            $this->userSettings,
            $this->application
        );

        // Event should not be modified
        $this->event->expects($this->never())
            ->method('getParam');

        $listener($this->event);
    }

    public function testRegularUserWithLimitEnabled()
    {
        // Setup
        $userId = 1;
        
        $this->user->method('getRole')->willReturn('editor');
        $this->user->method('getId')->willReturn($userId);
        $this->authService->method('getIdentity')->willReturn($this->user);

        $this->userSettings->method('get')
            ->with('limit_to_own_assets', 1)
            ->willReturn(1);

        $this->queryBuilder->method('getRootAliases')
            ->willReturn(['root']);

        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('root.owner = :user')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('user', $this->user)
            ->willReturn($this->queryBuilder);

        $this->event->method('getParam')
            ->with('queryBuilder')
            ->willReturn($this->queryBuilder);

        $listener = new ModifyAssetQueryListener(
            $this->authService,
            $this->userSettings,
            $this->application
        );

        $listener($this->event);
    }

    public function testRegularUserWithLimitDisabled()
    {
        // Setup
        $userId = 1;
        
        $this->user->method('getRole')->willReturn('editor');
        $this->user->method('getId')->willReturn($userId);
        $this->authService->method('getIdentity')->willReturn($this->user);

        $this->userSettings->method('get')
            ->with('limit_to_own_assets', 1)
            ->willReturn(0);

        // Event should not modify the query
        $this->queryBuilder->expects($this->never())
            ->method('andWhere');

        $listener = new ModifyAssetQueryListener(
            $this->authService,
            $this->userSettings,
            $this->application
        );

        $listener($this->event);
    }
}
