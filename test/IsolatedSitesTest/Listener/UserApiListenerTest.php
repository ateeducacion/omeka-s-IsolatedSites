<?php
declare(strict_types=1);

namespace IsolatedSitesTest\Listener;

use IsolatedSites\Listener\UserApiListener;
use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\Event;
use Omeka\Api\Adapter\UserAdapter;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Api\Request;
use Omeka\Api\Response;
use Omeka\Entity\User;
use Omeka\Permissions\Acl;
use Omeka\Settings\UserSettings;
use PHPUnit\Framework\TestCase;

class UserApiListenerTest extends TestCase
{
    private $settings;
    private $auth;
    private $acl;
    private $listener;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(UserSettings::class);
        $this->auth = $this->createMock(AuthenticationService::class);
        $this->acl = $this->createMock(Acl::class);
        $this->listener = new UserApiListener($this->settings, $this->auth, $this->acl);
    }

    private function hydrate($id, array $values): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $request = new Request('update', 'users');
        $request->setContent($values);
        $this->listener->handleApiHydrate(new Event('hydrate', null, ['entity' => $user, 'request' => $request]));
    }

    public function booleanValues(): array
    {
        return [[true, true], [false, false], [1, true], [0, false], [' yes ', true],
            ['OFF', false], ['true', true], ['false', false], ['1.0', true], ['0.0', false],
            [1.0, true], [0.0, false], ['', false]];
    }

    /** @dataProvider booleanValues */
    public function testTrustedHydrationNormalizesValues($raw, bool $expected): void
    {
        $this->settings->expects($this->once())->method('setTargetId')->with(42);
        $this->settings->expects($this->once())->method('set')->with('limit_to_granted_sites', $expected);
        $this->hydrate(42, ['o-module-isolatedsites:limit_to_granted_sites' => $raw]);
    }

    public function testNonAdminCannotRemoveIsolation(): void
    {
        $actor = $this->createMock(User::class);
        $actor->method('getRole')->willReturn('site_editor');
        $this->auth->method('getIdentity')->willReturn($actor);
        $this->acl->expects($this->once())->method('isAdminRole')->with('site_editor')->willReturn(false);
        $this->settings->expects($this->never())->method('set');
        $this->hydrate(42, ['o-module-isolatedsites:limit_to_granted_sites' => false]);
    }

    public function testAdminMayUpdateBothSettings(): void
    {
        $actor = $this->createMock(User::class);
        $actor->method('getRole')->willReturn('global_admin');
        $this->auth->method('getIdentity')->willReturn($actor);
        $this->acl->method('isAdminRole')->willReturn(true);
        $writes = [];
        $this->settings->method('set')->willReturnCallback(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
        });
        $this->hydrate(42, ['o-module-isolatedsites:limit_to_granted_sites' => false,
            'o-module-isolatedsites:limit_to_own_assets' => true]);
        $this->assertSame(['limit_to_granted_sites' => false, 'limit_to_own_assets' => true], $writes);
    }

    public function invalidValues(): array
    {
        return [['invalid'], [2], [2.0], [[]]];
    }

    /** @dataProvider invalidValues */
    public function testAllValuesAreValidatedBeforeAnyWrite($invalid): void
    {
        $this->settings->expects($this->never())->method('set');
        $this->expectException(ValidationException::class);
        $this->hydrate(42, ['o-module-isolatedsites:limit_to_granted_sites' => true,
            'o-module-isolatedsites:limit_to_own_assets' => $invalid]);
    }

    public function testIgnoresUnrelatedEntitiesAndMissingKeys(): void
    {
        $this->settings->expects($this->never())->method('set');
        $this->listener->handleApiHydrate(new Event('hydrate', null, ['entity' => new \stdClass()]));
        $this->hydrate(42, []);
    }

    public function testCreateDefersWritesUntilPersistedUserAndClearsQueue(): void
    {
        $writes = [];
        $this->settings->method('set')->willReturnCallback(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
        });
        $this->hydrate(null, ['o-module-isolatedsites:limit_to_own_assets' => true]);
        $this->assertSame([], $writes);
        $adapter = $this->createMock(UserAdapter::class);
        $response = $this->createMock(Response::class);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $response->method('getContent')->willReturn($user);
        $event = new Event('create', $adapter, ['response' => $response]);
        $this->settings->expects($this->once())->method('setTargetId')->with(42);
        $this->listener->handleApiCreate(new Event('create', new \stdClass()));
        $empty = $this->createMock(Response::class);
        $empty->method('getContent')->willReturn(new \stdClass());
        $this->listener->handleApiCreate(new Event('create', $adapter, ['response' => $empty]));
        $this->listener->handleApiCreate($event);
        $this->assertSame(['limit_to_own_assets' => true], $writes);
        $this->listener->handleApiCreate($event);
    }

    public function testSerializationAddsBooleanSettingsToExistingJson(): void
    {
        $user = $this->createMock(UserRepresentation::class);
        $user->method('id')->willReturn(42);
        $this->settings->expects($this->once())->method('setTargetId')->with(42);
        $this->settings->method('get')->willReturnMap([
            ['limit_to_granted_sites', false, null, 1], ['limit_to_own_assets', false, null, 0],
        ]);
        $event = new Event('json', $user, ['jsonLd' => ['o:id' => 42]]);
        $this->listener->handleRepresentationJson(new Event('json', new \stdClass()));
        $this->listener->handleRepresentationJson(new Event('json', $user));
        $this->listener->handleRepresentationJson($event);
        $this->assertSame(['o:id' => 42, 'o-module-isolatedsites:limit_to_granted_sites' => true,
            'o-module-isolatedsites:limit_to_own_assets' => false], $event->getParam('jsonLd'));
    }
}
