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

    public function __construct(Acl $acl, EntityManager $entityManager, UserSettingsService $userSettingsService, AuthenticationService $auth)
    {
        $this->acl = $acl;
        $this->entityManager=$entityManager;
        $this->userSettingsService=$userSettingsService;
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

    
            //$fieldset = $form->get('user-settings');
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
                    'disabled' => !$isCurrentUserGlobalAdmin, // Disable the field if current user is not global    admin
                    'readonly' => !$isCurrentUserGlobalAdmin, // Make it readonly if current user is not global     admin
                ],
            ]);
        } catch (\Doctrine\ORM\ORMException $e) {
            throw $e;
        }
    }
    public function addInputFilters(Event $event)
    {
        $form = $event->getTarget(); // Obtener el formulario
        $inputFilter = $form->getInputFilter(); // Obtener el filtro de entrada
        
        // Aquí puedes agregar tus filtros personalizados
        $inputFilter->add([
            'name' => 'limit_to_granted_sites', // Nombre del campo en el formulario
            'required' => false, // Si es obligatorio o no
            'filters' => [
                ['name' => 'Boolean'], // Si es un checkbox, convertirlo a booleano
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
            $limitToGrantedSites = $this->userSettingsService->get('limit_to_granted_sites', false);

            // Only allow changes if the current user is a global admin
            if ($isCurrentUserGlobalAdmin) {
                $limitToGrantedSites = isset($data['limit_to_granted_sites'])
                    ? (bool)$data['limit_to_granted_sites']
                    : false;

                $this->userSettingsService->set('limit_to_granted_sites', $limitToGrantedSites);
            }
        } catch (\Exception $e) {
            // Log error or handle appropriately
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
        // You'll need to implement this method based on your authentication system
        // This is just a placeholder - implement according to your needs
        $identity = $this->auth->getIdentity();
        if ($identity) {
            return $this->entityManager->find(\Omeka\Entity\User::class, $identity->getId());
        }
        return null;
    }
}
