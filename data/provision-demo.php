<?php
/**
 * Demo provisioning script for the IsolatedSites module.
 *
 * Creates a small multi-site / multi-user scenario so the per-site isolation can
 * be verified end to end:
 *
 *   - Two sites: "site-a" and "site-b".
 *   - Two site editors (created beforehand by docker-compose via omeka-s-cli):
 *       siteeditor.a@example.com  -> granted admin on site-a only
 *       siteeditor.b@example.com  -> granted admin on site-b only
 *     Both get limit_to_granted_sites + limit_to_own_assets enabled.
 *   - A few items assigned to each site, plus one item set owned by each editor.
 *
 * Expected result once logged into /admin:
 *   - siteeditor.a sees only site-a (and its items) + their own item set; site-b
 *     content is hidden. siteeditor.b sees the mirror image. The plain "editor"
 *     and the global admin keep seeing everything.
 *
 * The bundled omeka-s-cli (GhentCDH) cannot create sites, site permissions or
 * per-user settings, so this is done through the Omeka S API. The script is
 * idempotent: sites are only created when their slug does not already exist.
 *
 * Invoked once on first boot from docker-compose POST_CONFIGURE_COMMANDS:
 *   php /var/www/html/volume/modules/IsolatedSites/data/provision-demo.php
 */

declare(strict_types=1);

use Omeka\Entity\User;
use Omeka\Mvc\Application;

$omekaPath = getenv('OMEKA_PATH') ?: '/var/www/html';

if (!is_file($omekaPath . '/bootstrap.php')) {
    fwrite(STDERR, "[provision] Omeka bootstrap not found at $omekaPath; set OMEKA_PATH. Aborting.\n");
    exit(1);
}

chdir($omekaPath);
require $omekaPath . '/bootstrap.php';

$application = Application::init(require $omekaPath . '/application/config/application.config.php');
$services = $application->getServiceManager();

/** @var \Doctrine\ORM\EntityManager $em */
$em = $services->get('Omeka\EntityManager');
/** @var \Omeka\Api\Manager $api */
$api = $services->get('Omeka\ApiManager');

// Authenticate as the admin user so the API operations pass the ACL checks.
$adminEmail = getenv('OMEKA_ADMIN_EMAIL') ?: 'admin@example.com';
$admin = $em->getRepository(User::class)->findOneBy(['email' => $adminEmail]);
if (!$admin) {
    fwrite(STDERR, "[provision] Admin user $adminEmail not found; aborting.\n");
    exit(1);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($admin);

$findUser = static function (string $email) use ($em): ?User {
    return $em->getRepository(User::class)->findOneBy(['email' => $email]);
};

$siteExists = static function (string $slug) use ($api): bool {
    return count($api->search('sites', ['slug' => $slug])->getContent()) > 0;
};

// Resolve the dcterms:title property id (defaults to 1 on a stock install).
$titleProps = $api->search('properties', ['term' => 'dcterms:title'])->getContent();
$titlePropId = $titleProps ? $titleProps[0]->id() : 1;

$titleValue = static function (string $text) use ($titlePropId): array {
    return [[
        'type' => 'literal',
        'property_id' => $titlePropId,
        '@value' => $text,
    ]];
};

// --- Demo definition --------------------------------------------------------
// site-a showcases the three site-scoped roles; site-b shows cross-site isolation.
// 'role' is the per-site permission (viewer/editor/admin); the global role is set
// by docker-compose's omeka-s-cli user:add calls. 'collectionOwner' owns the
// item set, to also exercise ownership-based filtering.
$sites = [
    [
        'slug' => 'site-a',
        'title' => 'Site A (Team A)',
        'grants' => [
            ['email' => 'siteresearcher.a@example.com', 'role' => 'viewer'],
            ['email' => 'siteeditor.a@example.com',     'role' => 'editor'],
            ['email' => 'sitemanager.a@example.com',    'role' => 'admin'],
        ],
        'collectionOwner' => 'siteeditor.a@example.com',
    ],
    [
        'slug' => 'site-b',
        'title' => 'Site B (Team B)',
        'grants' => [
            ['email' => 'siteeditor.b@example.com', 'role' => 'editor'],
        ],
        'collectionOwner' => 'siteeditor.b@example.com',
    ],
];

$userSettings = $services->get('Omeka\Settings\User');

foreach ($sites as $d) {
    // Enable isolation settings for every granted user and build the site's
    // permission payload (idempotent).
    $sitePermissions = [];
    foreach ($d['grants'] as $grant) {
        $user = $findUser($grant['email']);
        if (!$user) {
            fwrite(STDERR, "[provision] User {$grant['email']} not found; skipping grant.\n");
            continue;
        }
        $userSettings->setTargetId($user->getId());
        $userSettings->set('limit_to_granted_sites', true);
        $userSettings->set('limit_to_own_assets', true);

        $sitePermissions[] = ['o:user' => ['o:id' => $user->getId()], 'o:role' => $grant['role']];
    }

    if ($siteExists($d['slug'])) {
        echo "[provision] Site {$d['slug']} already exists; skipping creation.\n";
        continue;
    }

    // Create the site with its per-user permissions.
    $site = $api->create('sites', [
        'o:title' => $d['title'],
        'o:slug' => $d['slug'],
        'o:theme' => 'default',
        'o:is_public' => true,
        'o:site_permission' => $sitePermissions,
    ])->getContent();
    $siteId = $site->id();
    echo "[provision] Created site {$d['slug']} (#{$siteId}) with " . count($sitePermissions) . " permission(s).\n";

    // Create two items assigned to this site.
    for ($i = 1; $i <= 2; $i++) {
        $api->create('items', [
            'dcterms:title' => $titleValue("{$d['title']} - Item {$i}"),
            'o:is_public' => true,
            'o:site' => [['o:id' => $siteId]],
        ]);
    }
    echo "[provision] Created 2 items in {$d['slug']}.\n";

    // Create one item set owned by the site's content editor (ownership-based
    // filtering is independent of site membership).
    $owner = $findUser($d['collectionOwner']);
    if ($owner) {
        $api->create('item_sets', [
            'dcterms:title' => $titleValue("{$d['title']} - Collection"),
            'o:is_public' => true,
            'o:owner' => ['o:id' => $owner->getId()],
        ]);
        echo "[provision] Created 1 item set owned by {$d['collectionOwner']}.\n";
    }
}

echo "[provision] Done.\n";
