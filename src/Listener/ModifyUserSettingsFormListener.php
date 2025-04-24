<?php
namespace IsolatedSites\Listener;

use Laminas\EventManager\EventInterface;
use IsolatedSites\Form\UserSettingsFieldset;
use Omeka\Entity\User;
use Omeka\Permissions\Acl;
use Laminas\EventManager\Event;
use Doctrine\ORM\EntityManager;
use Omeka\Settings\UserSettings as UserSettingsService;
use Laminas\Authentication\AuthenticationService;

class ModifyUserSettingsFormListener
{
    protected $acl;
    protected $entityManager;
    protected $userSettingsService;
    protected $auth;

    public function __construct(
        Acl $acl,
        EntityManager $entityManager,
        UserSettingsService $userSettingsService,
        AuthenticationService $auth
    ) {
        $this->acl = $acl;
        $this->entityManager = $entityManager;
        $this->userSettingsService = $userSettingsService;
        $this->auth = $auth;
    }

    public function __invoke(Event $event)
    {
        try {
            $form = $event->getTarget();
            $userId = $form->getOption('user_id');

            if (!$userId) {
                return;
            }

            $user = $this->entityManager->find(User::class, $userId);

            if ($user == null) {
                return;
            }

            $role = $user->getRole();
            if ($this->acl->isAdminRole($role)) {
                return;
            }
    
            try {
                $fieldset = $form->get('user-settings');
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Required fieldset not found');
            }

            // Get the current logged-in user's role
            $currentUser = $this->getCurrentUser();
            if ($currentUser == null) {
                return;
            }

            $isCurrentUserGlobalAdmin = $currentUser && $this->acl->isAdminRole($currentUser->getRole());
    
            // Get the user settings
            $this->userSettingsService->setTargetId($userId);
            $limitToGrantedSites = $this->userSettingsService->get('limit_to_granted_sites', false);
            $limitToOwnAssets = $this->userSettingsService->get('limit_to_own_assets', false);

            $fieldset->add([
                'name' => 'limit_to_granted_sites',
                'type' => 'Checkbox',
                'options' => [
                    'label' => 'Limit item list to granted sites only', // @translate
                    'use_hidden_element' => true,
                    'checked_value' => '1',
                    'unchecked_value' => '0',
                    'info' => 'If checked, items and itemsets shown in admin ' . // @translate
                        'view are limited to those assigned to sites where the user has permissions', //    @translate
                ],
                'attributes' => [
                    'value' => $limitToGrantedSites ? '1' : '0',
                    'disabled' => !$isCurrentUserGlobalAdmin,
                    'readonly' => !$isCurrentUserGlobalAdmin,
                ],
            ]);

            $fieldset->add([
                'name' => 'limit_to_own_assets',
                'type' => 'Checkbox',
                'options' => [
                    'label' => 'Limit assets to owned assets only', // @translate
                    'use_hidden_element' => true,
                    'checked_value' => '1',
                    'unchecked_value' => '0',
                    'info' => 'If checked, assets shown in admin ' . // @translate
                        'view are limited to those owned by the user', // @translate
                ],
                'attributes' => [
                    'value' => $limitToOwnAssets ? '1' : '0',
                    'disabled' => !$isCurrentUserGlobalAdmin,
                    'readonly' => !$isCurrentUserGlobalAdmin,
                ],
            ]);
        } catch (\Doctrine\ORM\ORMException $e) {
            throw $e;
        }
    }

    public function addInputFilters(Event $event)
    {
        $form = $event->getTarget();
        $inputFilter = $form->getInputFilter();
        
        $inputFilter->add([
            'name' => 'limit_to_granted_sites',
            'required' => false,
            'filters' => [
                ['name' => 'Boolean'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'limit_to_own_assets',
            'required' => false,
            'filters' => [
                ['name' => 'Boolean'],
            ],
        ]);
    }

    public function handleUserSettings(EventInterface $event)
    {
        try {
            $form = $event->getTarget();
            $data = $form->getData() ?? [];
            $userId = $form->getOption('user_id');

            if ($userId == null) {
                return;
            }

            $user = $this->entityManager->find(\Omeka\Entity\User::class, $userId);
            if ($user == null) {
                return;
            }

            // Get the current logged-in user's role
            $currentUser = $this->getCurrentUser();
            $isCurrentUserGlobalAdmin = $currentUser && $this->acl->isAdminRole($currentUser->getRole());
    
            $this->userSettingsService->setTargetId($userId);

            // Only allow changes if the current user is a global admin
            if ($isCurrentUserGlobalAdmin) {
                $limitToGrantedSites = isset($data['limit_to_granted_sites'])
                    ? (bool)$data['limit_to_granted_sites']
                    : false;

                $limitToOwnAssets = isset($data['limit_to_own_assets'])
                    ? (bool)$data['limit_to_own_assets']
                    : false;

                $this->userSettingsService->set('limit_to_granted_sites', $limitToGrantedSites);
                $this->userSettingsService->set('limit_to_own_assets', $limitToOwnAssets);
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Get the current logged-in user
     *
     * @return \Omeka\Entity\User|null
     */
    protected function getCurrentUser()
    {
        $identity = $this->auth->getIdentity();
        if ($identity) {
            return $this->entityManager->find(\Omeka\Entity\User::class, $identity->getId());
        }
        return null;
    }
}
