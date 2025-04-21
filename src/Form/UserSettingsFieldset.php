<?php
namespace IsolatedSites\Form;

use Laminas\Form\Fieldset;
//use Omeka\Acl\Acl;
use Omeka\Permissions\Acl;

class UserSettingsFieldset extends Fieldset
{
    protected $acl;

    public function __construct(Acl $acl)
    {
        //parent::__construct('user-settings');
        $this->acl = $acl;
    }

    public function init()
    {
        // Use $this->acl if needed

        $this->add([
            'name' => 'limit_to_granted_sites',
            'type' => 'Checkbox',
            'options' => [
                'label' => 'Limit to granted sites',
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'checked' => true,
            ],
        ]);
    }
}
