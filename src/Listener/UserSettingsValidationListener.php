<?php
declare(strict_types=1);

namespace IsolatedSites\Listener;

use Laminas\EventManager\EventInterface;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ListenerAggregateInterface;
use Laminas\EventManager\ListenerAggregateTrait;
use Laminas\Mvc\Controller\PluginManager as ControllerPluginManager;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Adapter\UserAdapter;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Entity\User;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Settings\UserSettings as UserSettingsService;

class UserSettingsValidationListener implements ListenerAggregateInterface
{
    use ListenerAggregateTrait;

    private const WARNING_MESSAGE = "\u{26A0}\u{FE0F} Configuration warning: 'Site Editor' requires ".
                    "'<limit_to_granted_sites' enabled and at least one default site.";
    private const BROWSE_WARNING_TEXT = 'Site editor isolation settings are incomplete.';

    /**
     * @var UserSettingsService
     */
    private $userSettings;

    /**
     * @var ControllerPluginManager
     */
    private $controllerPlugins;

    /**
     * @var array<int, array{is_site_editor: bool}>
     */
    private $pendingUsers = [];

    /**
     * @var bool
     */
    private $warningQueued = false;

    public function __construct(
        UserSettingsService $userSettings,
        ControllerPluginManager $controllerPlugins
    ) {
        $this->userSettings = $userSettings;
        $this->controllerPlugins = $controllerPlugins;
    }

    /**
     * Attach events for validating user settings coherence and registering assets.
     *
     * @param EventManagerInterface $events
     * @param int $priority
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $sharedEvents = $events->getSharedManager();
        if ($sharedEvents) {
            $this->listeners[] = $sharedEvents->attach(
                UserAdapter::class,
                'api.update.post',
                [$this, 'queueUserForValidation'],
                -100
            );

            $this->listeners[] = $sharedEvents->attach(
                UserAdapter::class,
                'api.create.post',
                [$this, 'queueUserForValidation'],
                -100
            );

            $this->listeners[] = $sharedEvents->attach(
                \Omeka\Controller\Admin\User::class,
                'view.edit.after',
                [$this, 'registerUserFormAssets'],
                $priority
            );

            $this->listeners[] = $sharedEvents->attach(
                '*',
                'view.browse.actions',
                [$this, 'appendUserBrowseWarning'],
                0
            );
        }

        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_FINISH,
            [$this, 'processPendingWarnings'],
            -1000
        );
    }

    /**
     * Queue user IDs for validation once the request lifecycle completes.
     *
     * @param EventInterface $event
     */
    public function queueUserForValidation(EventInterface $event): void
    {
        $user = $this->resolveUserFromEvent($event);
        if (!$user instanceof User) {
            return;
        }

        $this->pendingUsers[$user->getId()] = [
            'is_site_editor' => $user->getRole() === 'site_editor',
        ];
    }

    /**
     * Validate pending users and add messenger warnings where needed.
     *
     * @param MvcEvent $event
     */
    public function processPendingWarnings(MvcEvent $event): void
    {
        if (empty($this->pendingUsers)) {
            return;
        }

        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch) {
            $this->pendingUsers = [];
            $this->warningQueued = false;
            return;
        }

        $routeName = (string) $routeMatch->getMatchedRouteName();
        $isAdminRoute = strpos($routeName, 'admin') === 0;
        if (!$isAdminRoute) {
            $this->pendingUsers = [];
            $this->warningQueued = false;
            return;
        }

        foreach ($this->pendingUsers as $userId => $context) {
            if (empty($context['is_site_editor'])) {
                continue;
            }

            if (!$this->configurationIsValid($userId)) {
                $this->queueWarningMessage();
            }
        }

        $this->pendingUsers = [];
        $this->warningQueued = false;
    }

    /**
     * Register the JavaScript asset that manages client-side warnings.
     *
     * @param EventInterface $event
     */
    public function registerUserFormAssets(EventInterface $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof PhpRenderer) {
            return;
        }

        $view->headScript()->appendFile(
            $view->assetUrl('js/user-settings-warning.js', 'IsolatedSites'),
            'text/javascript',
            ['defer' => 'defer']
        );
    }

    /**
     * Append a warning icon for site editors with incomplete isolation settings.
     *
     * @param EventInterface $event
     * @return string|null
     */
    public function appendUserBrowseWarning(EventInterface $event): ?string
    {
        $resource = $event->getParam('resource');
        if (!$resource instanceof UserRepresentation) {
            return null;
        }
        if ($resource->role() !== 'site_editor') {
            return null;
        }

        $userId = (int) $resource->id();
        if (!$userId || $this->configurationIsValid($userId)) {
            return null;
        }

        $message = self::BROWSE_WARNING_TEXT;
        $view = $event->getTarget();

        if ($view instanceof PhpRenderer) {
            echo '<li class="site-editor-warning" title="'.$view->escapeHtmlAttr($message).
                '">⚠️</span><span class="screen-reader-text">'.$view->escapeHtml($message).'</span></li>';
            return null;
        }

        echo '<li class="site-editor-warning" title="'.$view->escapeHtmlAttr($message).
            '">⚠️</span><span class="screen-reader-text">'.$view->escapeHtml($message).'</span></li>';
        return null;
    }

    /**
     * Determine whether the persisted user settings satisfy the module requirements.
     *
     * @param int $userId
     * @return bool
     */
    private function configurationIsValid(int $userId): bool
    {
        $limitToGrantedSites = $this->booleanSettingValue(
            'limit_to_granted_sites',
            false,
            $userId
        );

        $defaultItemSites = $this->normaliseDefaultSites(
            $this->userSettings->get('default_item_sites', [], $userId)
        );

        return $limitToGrantedSites && !empty($defaultItemSites);
    }

    /**
     * Normalize the default item sites value returned by the settings service.
     *
     * @param mixed $defaultItemSites
     * @return array
     */
    private function normaliseDefaultSites($defaultItemSites): array
    {
        if ($defaultItemSites instanceof \Traversable) {
            $defaultItemSites = iterator_to_array($defaultItemSites);
        } elseif (!is_array($defaultItemSites)) {
            $defaultItemSites = $defaultItemSites !== null && $defaultItemSites !== ''
                ? [$defaultItemSites]
                : [];
        }

        return array_values(array_filter($defaultItemSites, static function ($value) {
            return $value !== null && $value !== '' && $value !== false;
        }));
    }

    /**
     * Extract a boolean-friendly value from the user settings store.
     *
     * @param string $settingId
     * @param bool $default
     * @param int $userId
     * @return bool
     */
    private function booleanSettingValue(string $settingId, bool $default, int $userId): bool
    {
        $value = $this->userSettings->get($settingId, $default, $userId);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return (bool) (int) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return !empty($value);
    }

    /**
     * Retrieve the messenger plugin.
     *
     * @return Messenger
     */
    private function messenger(): Messenger
    {
        return $this->controllerPlugins->get('messenger');
    }

    /**
     * Queue the shared warning message once per request.
     */
    private function queueWarningMessage(): void
    {
        if ($this->warningQueued) {
            return;
        }

        $this->messenger()->addWarning(self::WARNING_MESSAGE);
        $this->warningQueued = true;
    }

    /**
     * Resolve the user entity associated with the triggered event.
     *
     * @param EventInterface $event
     * @return User|null
     */
    private function resolveUserFromEvent(EventInterface $event): ?User
    {
        $user = null;

        $response = $event->getParam('response');
        if ($response && method_exists($response, 'getContent')) {
            $user = $this->extractUserEntity($response->getContent());
        }

        if (!$user) {
            $entity = $event->getParam('entity');
            $user = $this->extractUserEntity($entity);
        }

        if (!$user) {
            $target = $event->getTarget();
            $user = $this->extractUserEntity($target);
            if (!$user
                && is_object($target)
                && method_exists($target, 'getContent')
            ) {
                $user = $this->extractUserEntity($target->getContent());
            }
        }

        return $user;
    }

    /**
     * Safely derive a user entity from various possible representations.
     *
     * @param mixed $candidate
     * @return User|null
     */
    private function extractUserEntity($candidate): ?User
    {
        if ($candidate instanceof User) {
            return $candidate;
        }

        if (is_object($candidate) && method_exists($candidate, 'getEntity')) {
            $entity = $candidate->getEntity();
            if ($entity instanceof User) {
                return $entity;
            }
        }

        return null;
    }
}
