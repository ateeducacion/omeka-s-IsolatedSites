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

        // api.search.query fires for the admin UI AND the REST API (route names
        // 'api' / 'api-local'). Filtering must cover both contexts; gating on the
        // admin route alone left the authenticated REST API completely unfiltered.
        $routeMatch = $this->application->getMvcEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $isAdmin = strpos($routeName, 'admin') === 0;
        $isApi = strpos($routeName, 'api') === 0;

        // Skip anonymous requests and both administrator roles (global_admin and
        // site_admin, per Acl::isAdminRole), as well as any non-admin/non-API
        // context (e.g. public site, CLI).
        if ((!$isAdmin && !$isApi)
            || !$user
            || in_array($user->getRole(), ['global_admin', 'site_admin'], true)
        ) {
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
