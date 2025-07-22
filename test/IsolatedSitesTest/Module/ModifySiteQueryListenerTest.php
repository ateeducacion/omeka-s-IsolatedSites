<?php
namespace IsolatedSitesTest\Listener;

use IsolatedSites\Listener\ModifySiteQueryListener;
use Laminas\EventManager\Event;
use Omeka\Entity\User;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Result;

class ModifySiteQueryListenerTest extends TestCase
{
    private $auth;
    private $userSettings;
    private $connection;
    private $listener;
    private $queryBuilder;
    private $event;
    private $user;

    protected function setUp(): void
    {
        // Create mocks for dependencies
        $this->auth = $this->createMock(AuthenticationService::class);
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->connection = $this->createMock(Connection::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->event = $this->createMock(Event::class);
        $this->user = $this->createMock(User::class);
        $this->application = $this->createMock(\Laminas\Mvc\Application::class);
        $this->mvcEvent = $this->createMock(\Laminas\Mvc\MvcEvent::class);
        $this->routeMatch = $this->createMock(\Laminas\Router\RouteMatch::class);
        
        // Setup application mock to return MVC event
        $this->application->method('getMvcEvent')->willReturn($this->mvcEvent);
        $this->mvcEvent->method('getRouteMatch')->willReturn($this->routeMatch);
        $this->routeMatch->method('getMatchedRouteName')->willReturn('admin/default');

        // Create the listener
        $this->listener = new ModifySiteQueryListener(
            $this->auth,
            $this->userSettings,
            $this->connection,
            $this->application
        );
    }

    public function testInvokeForGlobalAdmin()
    {
        // Setup user as global admin
        $this->user->expects($this->once())
            ->method('getRole')
            ->willReturn('global_admin');

        $this->auth->expects($this->once())
            ->method('getIdentity')
            ->willReturn($this->user);

        // QueryBuilder should not be modified for global admin
        $this->event->expects($this->never())
            ->method('getParam');

        $this->listener->__invoke($this->event);
    }

    public function testInvokeForNonAdminWithoutLimitSetting()
    {
        // Setup user as non-admin
        $this->user->expects($this->once())
            ->method('getRole')
            ->willReturn('editor');
        
        $this->user->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $this->auth->expects($this->once())
            ->method('getIdentity')
            ->willReturn($this->user);

        // Setup user settings
        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', 1)
            ->willReturn(false);

        // QueryBuilder should not be modified when limit setting is false
        $this->event->expects($this->never())
            ->method('getParam');

        $this->listener->__invoke($this->event);
    }

    public function testInvokeForNonAdminWithLimitSetting()
    {
        // Setup user as non-admin
        $this->user->expects($this->once())
            ->method('getRole')
            ->willReturn('editor');
        
        $this->user->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(1);

        $this->auth->expects($this->once())
            ->method('getIdentity')
            ->willReturn($this->user);

        // Setup user settings
        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', 1)
            ->willReturn(true);

        // Setup granted sites query
        $grantedSitesQb = $this->createMock(\Doctrine\DBAL\Query\QueryBuilder::class);
        $statement = $this->createMock(Result::class);
        
        $this->connection->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($grantedSitesQb);

        $grantedSitesQb->expects($this->once())
            ->method('select')
            ->with('DISTINCT s.id')
            ->willReturnSelf();
        
        $grantedSitesQb->expects($this->once())
            ->method('from')
            ->with('site', 's')
            ->willReturnSelf();

        $grantedSitesQb->expects($this->once())
            ->method('leftJoin')
            ->willReturnSelf();

        $grantedSitesQb->expects($this->once())
            ->method('Where')
            ->willReturnSelf();

        $grantedSitesQb->expects($this->once())
            ->method('execute')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn(['site-1', 'site-2']);

        // Setup main query builder
        $this->event->expects($this->once())
            ->method('getParam')
            ->with('queryBuilder')
            ->willReturn($this->queryBuilder);

        // Expect the query to be modified with the granted sites

        $this->queryBuilder->method('getRootAliases')
            ->willReturn(['root']);

        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('root.id IN (:granted_sites)')
            ->willReturnSelf();

        $this->queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('granted_sites', ['site-1', 'site-2'])
            ->willReturnSelf();

        $this->listener->__invoke($this->event);
    }

    public function testInvokeForNonAdminWithNoGrantedSites()
    {
        // Setup user as non-admin
        $this->user->expects($this->once())
            ->method('getRole')
            ->willReturn('editor');
        
        $this->user->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(1);

        $this->auth->expects($this->once())
            ->method('getIdentity')
            ->willReturn($this->user);

        // Setup user settings
        $this->userSettings->expects($this->once())
            ->method('get')
            ->with('limit_to_granted_sites', 1)
            ->willReturn(true);

        // Setup granted sites query
        $grantedSitesQb = $this->createMock(\Doctrine\DBAL\Query\QueryBuilder::class);
        $statement = $this->createMock(Result::class);
        
        $this->connection->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($grantedSitesQb);

        $grantedSitesQb->expects($this->once())
            ->method('select')
            ->willReturnSelf();

        $grantedSitesQb->expects($this->once())
            ->method('from')
            ->willReturnSelf();

        $grantedSitesQb->expects($this->once())
            ->method('leftJoin')
            ->willReturnSelf();

        $grantedSitesQb->expects($this->once())
            ->method('Where')
            ->willReturnSelf();
        
        $grantedSitesQb->expects($this->once())
            ->method('execute')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        // Setup main query builder
        $this->event->expects($this->once())
            ->method('getParam')
            ->with('queryBuilder')
            ->willReturn($this->queryBuilder);

        // Expect the query to be modified to return no results
        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('1 = 0')
            ->willReturnSelf();

        $this->listener->__invoke($this->event);
    }
}
