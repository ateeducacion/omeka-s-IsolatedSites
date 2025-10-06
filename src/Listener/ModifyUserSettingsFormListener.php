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

            // Check if current user is allowed to modify these settings
            // Only global admins and supervisors can modify these settings
            $currentUserRole = $currentUser->getRole();
            $canModifySettings = $this->acl->isAdminRole($currentUserRole) ||
                                 $currentUserRole === 'supervisor';
    
            // Get the user settings
            $this->userSettingsService->setTargetId($userId);
            $limitToGrantedSites = $this->userSettingsService->get('limit_to_granted_sites', false);
            $limitToOwnAssets = $this->userSettingsService->get('limit_to_own_assets', false);

            // Build attributes for the fields
            $limitToGrantedSitesAttributes = [
                'value' => $limitToGrantedSites ? '1' : '0',
            ];
            $limitToOwnAssetsAttributes = [
                'value' => $limitToOwnAssets ? '1' : '0',
            ];

            // If user cannot modify settings, make fields disabled
            if (!$canModifySettings) {
                $limitToGrantedSitesAttributes['disabled'] = 'disabled';
                $limitToOwnAssetsAttributes['disabled'] = 'disabled';
            }

            $infoSuffix = !$canModifySettings
                ? ' (Only global administrators and supervisors can modify this setting)' // @translate
                : '';

            $fieldset->add([
                'name' => 'limit_to_granted_sites',
                'type' => 'Checkbox',
                'options' => [
                    'label' => 'Limit item list to granted sites only', // @translate
                    'use_hidden_element' => true,
                    'checked_value' => '1',
                    'unchecked_value' => '0',
                    'info' => 'If checked, items and itemsets shown in admin ' . // @translate
                        'view are limited to those assigned to sites where the user has permissions' . //    @translate
                        $infoSuffix,
                ],
                'attributes' => $limitToGrantedSitesAttributes,
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
                        'view are limited to those owned by the user' . // @translate
                        $infoSuffix,
                ],
                'attributes' => $limitToOwnAssetsAttributes,
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
    
    /**
     * Handle form validation and prevent unauthorized changes
     * This should be called after form validation but before saving
     */
    public function handleFormValidation(Event $event)
    {
        $form = $event->getTarget();
        
        // Get the current logged-in user
        $currentUser = $this->getCurrentUser();
        if ($currentUser == null) {
            return;
        }

        // Check if current user is allowed to modify these settings
        $currentUserRole = $currentUser->getRole();
        $canModifySettings = $this->acl->isAdminRole($currentUserRole) ||
                             $currentUserRole === 'supervisor';

        // If the user cannot modify settings, restore the original values
        if (!$canModifySettings) {
            $userId = $form->getOption('user_id');
            if ($userId) {
                $this->userSettingsService->setTargetId($userId);
                $existingLimitToGrantedSites = $this->userSettingsService->get('limit_to_granted_sites', false);
                $existingLimitToOwnAssets = $this->userSettingsService->get('limit_to_own_assets', false);

                // Get the fieldset and override the values
                try {
                    $fieldset = $form->get('user-settings');
                    if ($fieldset->has('limit_to_granted_sites')) {
                        $fieldset->get('limit_to_granted_sites')->setValue($existingLimitToGrantedSites ? '1' : '0');
                    }
                    if ($fieldset->has('limit_to_own_assets')) {
                        $fieldset->get('limit_to_own_assets')->setValue($existingLimitToOwnAssets ? '1' : '0');
                    }
                } catch (\Exception $e) {
                    // Fieldset not found, skip
                }
            }
        }
    }

    public function handleUserSettings(EventInterface $event)
    {
        try {
            // For cas.user.create.pre event, the user is the target
            $user = $event->getTarget();
            if (!$user instanceof \Omeka\Entity\User) {
                return;
            }
            // Set the user settings to true by default
            $userId = $user->getId();
            if ($userId) {
                $this->userSettingsService->setTargetId($userId);
                $this->userSettingsService->set('limit_to_granted_sites', true);
                $this->userSettingsService->set('limit_to_own_assets', true);
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
