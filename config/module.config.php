<?php
declare(strict_types=1);

namespace IsolatedSites;

use Omeka\Acl\Acl;
use Omeka\Api\Adapter\ItemAdapter;
use Laminas\ServiceManager\ServiceLocatorInterface;
use IsolatedSites\Form\UserSettingsFieldset;
use Omeka\Settings\User as UserSettingsService;
use Laminas\Authentication\AuthenticationService;
use Doctrine\DBAL\Connection;
use IsolatedSites\Listener\ModifyUserSettingsFormListener;
use IsolatedSites\Listener\ModifyQueryListener;
use IsolatedSites\Listener\ModifyItemSetQueryListener;
use IsolatedSites\Listener\ModifyAssetQueryListener;
use IsolatedSites\Listener\ModifySiteQueryListener;
use IsolatedSites\Listener\ModifyMediaQueryListener;
use IsolatedSites\Listener\UserApiListener;
use Laminas\Mvc\Application;

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
                    $container->get(UserSettingsService::class),
                    $container->get('Omeka\AuthenticationService')
                );
            },
            ModifyQueryListener::class => function ($container) {
                //$routeMatch = $application->getMvcEvent()->getRouteMatch();
                return new ModifyQueryListener(
                    $container->get('Omeka\AuthenticationService'),
                    $container->get('Omeka\Settings\User'),
                    $container->get('Omeka\Connection'),
                    $container->get('Application')
                );
            },
            ModifyItemSetQueryListener::class => function ($services) {
                return new ModifyItemSetQueryListener(
                    $services->get('Omeka\AuthenticationService'),
                    $services->get('Omeka\Settings\User'),
                    $services->get('Omeka\Connection'),
                    $services->get('Application')
                );
            },
            ModifyAssetQueryListener::class => function ($services) {
                return new ModifyAssetQueryListener(
                    $services->get('Omeka\AuthenticationService'),
                    $services->get('Omeka\Settings\User'),
                    $services->get('Application')
                );
            },
            ModifySiteQueryListener::class => function ($services) {
                return new ModifySiteQueryListener(
                    $services->get('Omeka\AuthenticationService'),
                    $services->get('Omeka\Settings\User'),
                    $services->get('Omeka\Connection'),
                    $services->get('Application')
                );
            },
            ModifyMediaQueryListener::class => function ($services) {
                return new ModifyMediaQueryListener(
                    $services->get('Omeka\AuthenticationService'),
                    $services->get('Omeka\Settings\User'),
                    $services->get('Omeka\Connection'),
                    $services->get('Application')
                );
            },
            UserApiListener::class => function ($services) {
                return new UserApiListener(
                    $services->get('Omeka\Settings\User')
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
