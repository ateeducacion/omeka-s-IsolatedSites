<?php

namespace IsolatedSites\Listener;

use Laminas\EventManager\EventInterface;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;

class ModifyAssetQueryListener
{
    private $authService;
    private $userSettings;

    public function __construct(
        AuthenticationService $authService,
        UserSettings $userSettings
    ) {
        $this->authService = $authService;
        $this->userSettings = $userSettings;
    }

    /**
     * Modify the asset query to limit results to owned assets
     *
     * @param EventInterface $event
     */
    public function __invoke(EventInterface $event)
    {
        $user = $this->authService->getIdentity();

        // Don't limit assets for global admins or public users
        if (!$user || $user->getRole() === 'global_admin') {
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
