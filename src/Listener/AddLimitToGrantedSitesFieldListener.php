<?php
namespace IsolatedSites\Listener;

use Omeka\Api\Manager as ApiManager;
use Laminas\EventManager\EventInterface;
use Omeka\Entity\User;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Acl\Acl;
use Laminas\ServiceManager\ServiceLocatorInterface;

class AddLimitToGrantedSitesFieldListener
{
     
    protected $acl;

    public function __construct(ItemAdapter $itemAdapter, Acl $acl)
    {
        $this->acl = $acl;
    }
     /**
     * Modify the form query based on the user's role.
     *
     * @param EventInterface $event
     */
    public function __invoke(EventInterface $event)
    {
        var_dump(get_class($event->getTarget()));
        error_log("**** EVENTO LLAMADO******");
        /** @var \Laminas\Form\Form $form */
        $form = $event->getTarget();
        $fieldset = $form->get('user-settings');

        /** @var \Omeka\Entity\User $user */
        $user = $form->getOption('user');

        $services = $event->getApplication()->getServiceManager();
        $userRoles = $acl->getRoles();

        // Obtener si es global_admin
        $isGlobalAdmin = $user && $user->getRole() === 'global_admin';

        $fieldset->add([
            'name' => 'limit_to_granted_sites',
            'type' => 'Checkbox',
            'options' => [
                'label' => 'Limit item listing to granted sites',
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'id' => 'limit_to_granted_sites',
                'checked' => !$isGlobalAdmin, // Checked por defecto si NO es global_admin
            ],
        ]);
    }
}
