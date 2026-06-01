<?php
namespace IsolatedSites\Listener;

use Laminas\EventManager\Event;
use Laminas\Authentication\AuthenticationService;
use Omeka\Settings\UserSettings;
use Doctrine\DBAL\Connection;
use Omeka\Entity\User;

class ModifySiteQueryListener
{
    /**
     * @var AuthenticationService
     */
    protected $auth;

    /**
     * @var UserSettings
     */
    protected $userSettings;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var mixed
     */
    protected $application;

    public function __construct(
        AuthenticationService $auth,
        UserSettings $userSettings,
        Connection $connection,
        $application
    ) {
        $this->auth = $auth;
        $this->userSettings = $userSettings;
        $this->connection = $connection;
        $this->application = $application;
    }

    public function __invoke(Event $event)
    {
        $user = $this->auth->getIdentity();

        // api.search.query fires for the admin UI AND the REST API (route names
        // 'api' / 'api-local'). Filtering must cover both contexts; gating on the
        // admin route alone left the authenticated REST API completely unfiltered.
        $routeMatch = $this->application->getMvcEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $isAdmin = strpos($routeName, 'admin') === 0;
        $isApi = strpos($routeName, 'api') === 0;

        // Skip anonymous requests and both administrator roles (global_admin and
        // site_admin, per Acl::isAdminRole), as well as any non-admin/non-API
        // context (e.g. public site, CLI).
        if ((!$isAdmin && !$isApi)
            || !$user
            || in_array($user->getRole(), ['global_admin', 'site_admin'], true)
        ) {
            return;
        }

        $this->userSettings->setTargetId($user->getId());
        $limitToGrantedSites = $this->userSettings->get('limit_to_granted_sites', 1);

        if ($limitToGrantedSites) {
            $qb = $event->getParam('queryBuilder');
            
            // Get the sites where the user has permissions
            $grantedSites = $this->getGrantedSites($user->getId());
            
            if (empty($grantedSites)) {
                // If user has no permissions, return no results
                $qb->andWhere('1 = 0');
                return;
            }

            // Add condition to only show sites where user has permissions
            $alias=$qb->getRootAliases()[0];
            $qb->andWhere("$alias.id IN (:granted_sites)")
               ->setParameter('granted_sites', $grantedSites);
        }
    }

    protected function getGrantedSites($userId)
    {
        // Use the DBAL Connection directly (like the sibling listeners) rather
        // than the DBAL query builder. Connection::executeQuery() exists across
        // DBAL 2.x/3.x/4.x, whereas Query\QueryBuilder::executeQuery() only exists
        // from DBAL 3.1 (Omeka S 4.x ships DBAL 2.x) and execute() is removed in
        // DBAL 4 — so going through the connection keeps this version-safe.
        $sql = 'SELECT DISTINCT s.id FROM site s'
            . ' LEFT JOIN site_permission sp ON s.id = sp.site_id'
            . ' WHERE sp.user_id = :userId';

        return $this->connection
            ->executeQuery($sql, ['userId' => $userId])
            ->fetchFirstColumn();
    }
}
