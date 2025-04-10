<?php
namespace IsolatedSites\Listener;

use Laminas\EventManager\EventInterface;
use IsolatedSites\Form\UserSettingsFieldset;
use Omeka\Permissions\Acl;
use Laminas\EventManager\Event;
use Doctrine\ORM\EntityManager;
use Omeka\Settings\UserSettings as UserSettingsService;

class ModifyUserSettingsFormListener
{
    protected $acl;
    protected $entityManager;
    protected $userSettingsService;

    public function __construct(Acl $acl, EntityManager $entityManager, UserSettingsService $userSettingsService)
    {
        $this->acl = $acl;
        $this->entityManager=$entityManager;
        $this->userSettingsService=$userSettingsService;
    }

    public function __invoke(Event $event)
    {
        $form = $event->getTarget();

        // Check role, optionally hide checkbox for global-admin
        $userId = $form->getOption('user_id');

        if ($userId) {
            $user = $this->entityManager->find(\Omeka\Entity\User::class, $userId);
            
            if ($user) {
                // Ya tienes el objeto completo del usuario
                $role = $user->getRole();
                // Ahora puedes usar el rol o lo que necesites
                if ($user && $this->acl->isAdminRole($role)) {
                    return;
                }
            }
        }
        // Retrieve the Omeka Settings service for user-specific settings
        $settingsService = $this->userSettingsService;
        // Set the user ID to ensure the settings are saved for the current user
        $settingsService->setTargetId($user->getId());

        // Get the stored value for 'limit_to_granted_sites' (default to false if not set)
        $limitToGrantedSites = $settingsService->get('limit_to_granted_sites', false);
    
        $fieldset = $form->get('user-settings');
        $fieldset->add([
            'name' => 'limit_to_granted_sites',
            'type' => 'Checkbox',
            'options' => [
                'label' => 'Limit item list to granted sites only',// @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
                'info' =>   'If checked, items and itemsets shown in admin'.//@translate
                    'view are limited to those assigned to sites where the user has permissions', //@translate
            ],

            'attributes' => [
                'value' => $limitToGrantedSites ? '1' : '0', // Set to the stored value or '0' if not set,
            ],
        ]);
        //var_dump($fieldset->get('locale'));
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
        $form = $event->getTarget(); // Get the form
        $data = $form->getData(); // Get the form data
    
        // Get the value of the checkbox field 'limit_to_granted_sites'
        $limitToGrantedSites = $data['limit_to_granted_sites'] ?? false;
    
        // Get the user of user-settings form
        $userId = $form->getOption('user_id');
        $user = $user = $this->entityManager->find(\Omeka\Entity\User::class, $userId);
        
        // Retrieve the Omeka Settings service for user-specific settings
        $settingsService = $this->userSettingsService;
        
        // Set the user ID to ensure the settings are saved for the current user
        $settingsService->setTargetId($user->getId());
    
        // Save the custom setting
        $settingsService->set('limit_to_granted_sites', $limitToGrantedSites);
    }
}
