<?php
namespace IsolatedSitesTest\Listener;

use IsolatedSites\Listener\ModifyQueryListener;
use IsolatedSites\Listener\ModifyItemSetQueryListener;
use Laminas\EventManager\EventInterface;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Omeka\Entity\User;

class ModifyItemSetQueryListenerTest extends TestCase
{
    private $authService;
    private $userSettings;
    private $connection;
    private $event;
    private $queryBuilder;
    private $user;

    protected function setUp(): void
    {
        // Mock dependencies
        $this->authService = $this->createMock(AuthenticationService::class);
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->connection = $this->createMock(Connection::class);
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

        $listener = new ModifyItemSetQueryListener(
            $this->authService,
            $this->userSettings,
            $this->connection,
            $this->application
        );

        // Event should not be modified
        $this->event->expects($this->never())
            ->method('getParam');

        $listener($this->event);
    }

    public function testRegularUserWithNoSitePermissions()
    {
        // Setup
        $userId = 1;
        $siteIds = []; // Empty array to simulate no permissions
        
        $this->user->method('getRole')->willReturn('editor');
        $this->user->method('getId')->willReturn($userId);
        $this->authService->method('getIdentity')->willReturn($this->user);

        $stmt = $this->createMock(\Doctrine\DBAL\Result::class);
        $stmt->method('fetchFirstColumn')->willReturn($siteIds);

        $this->connection->method('executeQuery')
            ->willReturn($stmt);

        $this->userSettings->method('get')
            ->with('limit_to_granted_sites', 1)
            ->willReturn(1);

        $this->queryBuilder->method('getRootAliases')
            ->willReturn(['root']);

        // With no site permissions, user should only see itemsets they own
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('root.owner = :userId')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('userId', $userId)
            ->willReturn($this->queryBuilder);

        $this->event->method('getParam')
            ->with('queryBuilder')
            ->willReturn($this->queryBuilder);

        $listener = new ModifyItemSetQueryListener(
            $this->authService,
            $this->userSettings,
            $this->connection,
            $this->application
        );

        $listener($this->event);
    }

    public function testRegularUserWithSitePermissions()
    {
        // Setup
        $userId = 1;
        $siteIds = [1, 2, 3];
        
        $this->user->method('getRole')->willReturn('editor');
        $this->user->method('getId')->willReturn($userId);
        $this->authService->method('getIdentity')->willReturn($this->user);

        $stmt = $this->createMock(\Doctrine\DBAL\Result::class);
        $stmt->method('fetchFirstColumn')->willReturn($siteIds);

        $this->connection->method('executeQuery')
            ->willReturn($stmt);

        $this->userSettings->method('get')
            ->with('limit_to_granted_sites', 1)
            ->willReturn(1);

        $this->queryBuilder->method('getRootAliases')
            ->willReturn(['root']);

        // With site permissions, user should see itemsets from granted sites OR itemsets they own
        $this->queryBuilder->expects($this->exactly(2))
            ->method('leftJoin')
            ->withConsecutive(
                ['root.siteItemSets', 'sis'],
                ['sis.site', 'site']
            )
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('site.id IN (:siteIds) OR root.owner = :userId')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->withConsecutive(
                ['siteIds', $siteIds],
                ['userId', $userId]
            )
            ->willReturn($this->queryBuilder);

        $this->event->method('getParam')
            ->with('queryBuilder')
            ->willReturn($this->queryBuilder);

        $listener = new ModifyItemSetQueryListener(
            $this->authService,
            $this->userSettings,
            $this->connection,
            $this->application
        );

        $listener($this->event);
    }
}
