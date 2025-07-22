<?php

namespace IsolatedSites\Listener;

use Laminas\EventManager\EventInterface;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;

class ModifyAssetQueryListener
{
    private $authService;
    private $userSettings;
    private $application;

    public function __construct(
        AuthenticationService $authService,
        UserSettings $userSettings,
        $application
    ) {
        $this->authService = $authService;
        $this->userSettings = $userSettings;
        $this->application = $application;
    }

    /**
     * Modify the asset query to limit results to owned assets
     *
     * @param EventInterface $event
     */
    public function __invoke(EventInterface $event)
    {
        $user = $this->authService->getIdentity();

        // Check if we're in the admin interface
        $routeMatch = $this->application->getMvcEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $isAdmin = strpos($routeName, 'admin') === 0;

        // Only apply filtering in admin interface for non-global-admin users
        if (!$isAdmin || !$user || $user->getRole() === 'global_admin') {
            return;
        }

        $this->userSettings->setTargetId($user->getId());
        $limitToOwnAssets = $this->userSettings->get('limit_to_own_assets', 1);

        if ($limitToOwnAssets === null) {
            $limitToOwnAssets = true;
        }

        if ($limitToOwnAssets) {
            $queryBuilder = $event->getParam('queryBuilder');
            $alias = $queryBuilder->getRootAliases()[0];

            $queryBuilder->andWhere("$alias.owner = :user")
                ->setParameter('user', $user);
        }
    }
}
