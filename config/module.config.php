<?php
declare(strict_types=1);

namespace IsolatedSites;

use IsolatedSites\Listener\ModifyUserSettingsFormListener;
use Omeka\Acl\Acl;
use Omeka\Api\Adapter\ItemAdapter;
use Laminas\ServiceManager\ServiceLocatorInterface;
use IsolatedSites\Form\UserSettingsFieldset;
use Omeka\Settings\User as UserSettingsService;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            Form\SiteSettingsFieldset::class => Form\SiteSettingsFieldset::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            ModifyUserSettingsFormListener::class => function ($container) {
                return new ModifyUserSettingsFormListener(
                    $container->get('Omeka\Acl'),
                    $container->get('Omeka\EntityManager'),
                    $container->get(UserSettingsService::class)
                );
            },
        ],
    ],
    'IsolatedSites' => [
        'settings' => [
            'activate_IsolatedSites' => true,
        ]
    ],
    'environment' => 'development',
];
