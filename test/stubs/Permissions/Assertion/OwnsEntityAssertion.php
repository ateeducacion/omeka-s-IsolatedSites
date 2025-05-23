<?php
namespace Omeka\Permissions\Assertion;

use Omeka\Entity\Value;
use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Assertion\AssertionInterface;
use Laminas\Permissions\Acl\Resource\ResourceInterface;
use Laminas\Permissions\Acl\Role\RoleInterface;

class OwnsEntityAssertion implements AssertionInterface
{
    public function assert(
        Acl $acl,
        RoleInterface $role = null,
        ResourceInterface $resource = null,
        $privilege = null
    ) {
        if (!$role || !$role instanceof \Omeka\Entity\User) {
            return false;
        }
        if ($resource instanceof Value) {
            $resource = $resource->getResource();
        }
        $owner = $resource->getOwner();
        return $owner && $role->getId() === $owner->getId();
    }
}
