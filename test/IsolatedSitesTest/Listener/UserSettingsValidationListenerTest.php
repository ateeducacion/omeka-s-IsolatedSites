<?php
declare(strict_types=1);

namespace IsolatedSitesTest\Listener;

use IsolatedSites\Listener\UserSettingsValidationListener;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\Mvc\Controller\PluginManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Entity\User;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Settings\UserSettings;
use PHPUnit\Framework\TestCase;

class UserSettingsValidationListenerTest extends TestCase
{
    private $settings;
    private $messenger;
    private $listener;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(UserSettings::class);
        $plugins = $this->createMock(PluginManager::class);
        $this->messenger = $this->createMock(Messenger::class);
        $plugins->method('get')->with('messenger')->willReturn($this->messenger);
        $this->listener = new UserSettingsValidationListener($this->settings, $plugins);
    }

    private function user(int $id, string $role = 'site_editor'): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRole')->willReturn($role);
        return $user;
    }

    private function finish(?string $route = 'admin/default'): void
    {
        $event = new MvcEvent();
        if ($route !== null) {
            $match = new RouteMatch([]);
            $match->setMatchedRouteName($route);
            $event->setRouteMatch($match);
        }
        $this->listener->processPendingWarnings($event);
    }

    public function settingsCases(): array
    {
        return [[true, [1], true], [false, [1], false], [1, [1], true], ['1', [1], true],
            ['yes', [1], true], ['off', [1], false], ['strange', [1], true], [[], [1], false],
            [true, new \ArrayIterator([1]), true], [true, 7, true], [true, null, false],
            [true, '', false], [true, [null, '', false], false]];
    }

    /** @dataProvider settingsCases */
    public function testSettingsControlWarning($limit, $sites, bool $valid): void
    {
        $this->settings->method('get')->willReturnCallback(function ($key, $default, $id) use ($limit, $sites) {
            $this->assertSame(42, $id);
            return $key === 'limit_to_granted_sites' ? $limit : $sites;
        });
        $this->messenger->expects($valid ? $this->never() : $this->once())->method('addWarning');
        $this->listener->queueUserForValidation(new Event('update', null, ['entity' => $this->user(42)]));
        $this->finish();
        $this->finish();
    }

    public function testResolvesResponseRepresentationAndTargetAndDeduplicatesWarning(): void
    {
        $this->settings->method('get')->willReturn(false);
        $this->messenger->expects($this->once())->method('addWarning');
        $representation = $this->createMock(UserRepresentation::class);
        $representation->method('getEntity')->willReturn($this->user(1));
        $response = new class ($representation) {
            private $content;
            public function __construct($content) { $this->content = $content; }
            public function getContent() { return $this->content; }
        };
        $this->listener->queueUserForValidation(new Event('update', null, ['response' => $response]));
        $this->listener->queueUserForValidation(new Event('update', $this->user(2, 'site_manager')));
        $this->listener->queueUserForValidation(new Event('update', $response));
        $this->listener->queueUserForValidation(new Event('update', null, ['entity' => $this->user(3, 'site_researcher')]));
        $this->listener->queueUserForValidation(new Event('update', new \stdClass()));
        $this->finish();
    }

    public function testNonAdminAndMissingRoutesDiscardPendingWarnings(): void
    {
        $this->messenger->expects($this->never())->method('addWarning');
        foreach ([null, 'site'] as $route) {
            $this->listener->queueUserForValidation(new Event('update', $this->user(42)));
            $this->finish($route);
            $this->finish();
        }
    }

    public function testAttachesAndProcessesSharedAndFinishEvents(): void
    {
        $shared = new SharedEventManager();
        $events = new EventManager($shared);
        $this->listener->attach($events);
        $this->assertCount(1, $shared->getListeners([\Omeka\Api\Adapter\UserAdapter::class], 'api.update.post'));
        $withoutShared = new EventManager();
        $this->listener->attach($withoutShared);
        $this->assertCount(0, $withoutShared->trigger('unrelated'));
    }

    public function testBrowseWarningOnlyForManagedRolesWithInvalidSettings(): void
    {
        $this->settings->method('get')->willReturn(false);
        $view = new PhpRenderer();
        $user = $this->createMock(UserRepresentation::class);
        $user->method('id')->willReturn(42);
        $user->method('role')->willReturn('site_editor');
        ob_start();
        $this->listener->appendUserBrowseWarning(new Event('browse', $view, ['resource' => $user]));
        $html = ob_get_clean();
        $this->assertStringContainsString('screen-reader-text', $html);
        $this->assertStringContainsString('Site editor isolation settings are incomplete.', $html);
        $other = $this->createMock(UserRepresentation::class);
        $other->method('role')->willReturn('global_admin');
        $zero = $this->createMock(UserRepresentation::class);
        $zero->method('role')->willReturn('site_manager');
        $zero->method('id')->willReturn(0);
        $this->assertNull($this->listener->appendUserBrowseWarning(new Event('browse', $view)));
        $this->assertNull($this->listener->appendUserBrowseWarning(new Event('browse', $view, ['resource' => $other])));
        $this->assertNull($this->listener->appendUserBrowseWarning(new Event('browse', $view, ['resource' => $zero])));
    }

    public function testRegistersWarningScriptOnlyForPhpRenderer(): void
    {
        $view = new PhpRenderer();
        $view->getHelperPluginManager()->setService('assetUrl', function () { return '/warning.js'; });
        $this->listener->registerUserFormAssets(new Event('edit', new \stdClass()));
        $this->listener->registerUserFormAssets(new Event('edit', $view));
        $this->assertStringContainsString('warning.js', (string) $view->headScript());
        $this->assertStringContainsString('defer', (string) $view->headScript());
    }
}
