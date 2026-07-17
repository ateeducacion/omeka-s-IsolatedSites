<?php
namespace IsolatedSitesTest\Listener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use IsolatedSites\Service\GrantedSites;
use PHPUnit\Framework\TestCase;

class GrantedSitesTest extends TestCase
{
    private function serviceReturning(array $rows, &$captured = null): GrantedSites
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn($rows);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')
            ->willReturnCallback(function ($sql, $params) use ($result, &$captured) {
                $captured = ['sql' => $sql, 'params' => $params];
                return $result;
            });

        return new GrantedSites($connection);
    }

    public function testReturnsSiteIdsAsIntegers()
    {
        $service = $this->serviceReturning(['1', '3']);

        $this->assertSame([1, 3], $service->forUser(7));
    }

    public function testReturnsEmptyArrayForUserWithoutPermissions()
    {
        $service = $this->serviceReturning([]);

        $this->assertSame([], $service->forUser(7));
    }

    public function testQueriesSitePermissionForTheGivenUser()
    {
        $captured = null;
        $service = $this->serviceReturning(['1'], $captured);

        $service->forUser(7);

        $this->assertStringContainsString('site_permission', $captured['sql']);
        $this->assertSame(['user_id' => 7], $captured['params']);
    }
}
