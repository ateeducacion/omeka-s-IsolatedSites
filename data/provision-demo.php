<?php
/**
 * Demo provisioning for the IsolatedSites module's Docker stack.
 *
 * The Docker image applies blueprint.json with Omeka-S-Cli, which (as of 0.18)
 * installs the modules and creates the users, the two sites (site-a, site-b) and
 * their per-site permissions. It does not apply per-user settings, item sets or
 * items, so this script applies those parts of the same blueprint.json, giving
 * Docker the same demo as the Omeka S Playground:
 *
 *   - users[].settings (limit_to_granted_sites, limit_to_own_assets, ...);
 *   - itemSets, created when no item set has that title;
 *   - items, created when no item has that title, in their item sets and only in
 *     the sites they list (so a site that takes new items does not get them all).
 *
 * Docker-only extra: each team's collection is owned by its site editor, to also
 * exercise ownership-based filtering.
 *
 * Idempotent; run on every start from docker-compose POST_CONFIGURE_COMMANDS:
 *   php /var/www/html/volume/modules/IsolatedSites/data/provision-demo.php
 */

declare(strict_types=1);

use Omeka\Entity\User;
use Omeka\Mvc\Application;

const COLLECTION_OWNERS = [
    'Team A Collection' => 'siteeditor.a@example.com',
    'Team B Collection' => 'siteeditor.b@example.com',
];

$omekaPath = getenv('OMEKA_PATH') ?: '/var/www/html';

if (!is_file($omekaPath . '/bootstrap.php')) {
    fwrite(STDERR, "[provision] Omeka bootstrap not found at $omekaPath; set OMEKA_PATH. Aborting.\n");
    exit(1);
}

$blueprint = json_decode((string) file_get_contents(__DIR__ . '/../blueprint.json'), true);
if (!is_array($blueprint)) {
    fwrite(STDERR, "[provision] Unable to read blueprint.json; aborting.\n");
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

$propertyId = static function (string $term) use ($api): ?int {
    $properties = $api->search('properties', ['term' => $term])->getContent();
    return $properties ? $properties[0]->id() : null;
};
$titleId = $propertyId('dcterms:title');
$literal = static function (string $term, ?string $text) use ($propertyId): array {
    $id = $propertyId($term);
    return $id && $text !== null && $text !== ''
        ? [$term => [['type' => 'literal', 'property_id' => $id, '@value' => $text]]]
        : [];
};
$findByTitle = static function (string $resource, string $title) use ($api, $titleId): ?int {
    $found = $api->search($resource, [
        'property' => [['property' => $titleId, 'type' => 'eq', 'text' => $title]],
        'limit' => 1,
    ])->getContent();
    return $found ? $found[0]->id() : null;
};

// Per-user settings
$userSettings = $services->get('Omeka\Settings\User');
foreach ($blueprint['users'] ?? [] as $spec) {
    $user = $findUser((string) ($spec['email'] ?? ''));
    if (!$user || empty($spec['settings'])) {
        continue;
    }
    $userSettings->setTargetId($user->getId());
    foreach ($spec['settings'] as $key => $value) {
        $userSettings->set($key, $value);
    }
    echo "[provision] Applied the settings of {$spec['email']}.\n";
}

// Sites, by slug and title, as created from the blueprint
$siteIds = [];
foreach ($api->search('sites')->getContent() as $site) {
    $siteIds[strtolower($site->slug())] = $site->id();
    $siteIds[strtolower($site->title())] = $site->id();
}

// Item sets
$itemSetIds = [];
foreach ($blueprint['itemSets'] ?? [] as $spec) {
    $title = (string) $spec['title'];
    $id = $findByTitle('item_sets', $title);
    if (!$id) {
        $payload = $literal('dcterms:title', $title)
            + $literal('dcterms:description', $spec['description'] ?? null)
            + ['o:is_public' => true];
        $owner = isset(COLLECTION_OWNERS[$title]) ? $findUser(COLLECTION_OWNERS[$title]) : null;
        if ($owner) {
            $payload['o:owner'] = ['o:id' => $owner->getId()];
        }
        $id = $api->create('item_sets', $payload)->getContent()->id();
        echo "[provision] Created item set \"$title\".\n";
    }
    $itemSetIds[$title] = $id;
}

// Items
foreach ($blueprint['items'] ?? [] as $spec) {
    $title = (string) $spec['title'];
    if ($findByTitle('items', $title)) {
        continue;
    }
    $payload = $literal('dcterms:title', $title)
        + $literal('dcterms:description', $spec['description'] ?? null)
        + $literal('dcterms:creator', $spec['creator'] ?? null)
        + ['o:is_public' => true];
    $payload['o:item_set'] = array_values(array_map(
        static fn ($id) => ['o:id' => $id],
        array_intersect_key($itemSetIds, array_flip($spec['itemSets'] ?? []))
    ));
    $payload['o:site'] = [];
    foreach ($spec['sites'] ?? [] as $site) {
        if (isset($siteIds[strtolower((string) $site)])) {
            $payload['o:site'][] = ['o:id' => $siteIds[strtolower((string) $site)]];
        }
    }
    $api->create('items', $payload);
    echo "[provision] Created item \"$title\".\n";
}

echo "[provision] Done.\n";
