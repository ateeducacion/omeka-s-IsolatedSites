<?php
namespace IsolatedSites\Listener;

use Laminas\EventManager\Event;
use Laminas\Authentication\AuthenticationService;
use Omeka\Permissions\Acl;
use Omeka\Settings\UserSettings;
use Omeka\Entity\User;

class UserApiListener
{
    protected $userSettings;
    protected $authService;
    protected $acl;
    protected $pendingUserSettings = [];

    public function __construct(
        UserSettings $userSettings,
        AuthenticationService $authService,
        Acl $acl
    ) {
        $this->userSettings = $userSettings;
        $this->authService = $authService;
        $this->acl = $acl;
    }

    /**
     * Whether the acting (logged-in) user may change the isolation settings.
     *
     * Only administrators may, since these flags control the user's own access
     * scope. Trusted internal/system calls that run without an authenticated
     * identity (CLI jobs, provisioning) are also allowed. This prevents a
     * restricted user from lifting their own restrictions via the API.
     *
     * @return bool
     */
    protected function actingUserMayModifySettings(): bool
    {
        $identity = $this->authService->getIdentity();
        if (!$identity) {
            return true;
        }

        return $this->acl->isAdminRole($identity->getRole());
    }

    /**
     * Handle API hydration to process custom settings
     */
    public function handleApiHydrate(Event $event)
    {
        $request = $event->getParam('request');
        $entity = $event->getParam('entity');

        // Only handle User entities
        if (!$entity instanceof User) {
            return;
        }

        // Collect any isolation settings present in the request.
        $incoming = [];
        foreach (['limit_to_granted_sites', 'limit_to_own_assets'] as $key) {
            $rawValue = $request->getValue('o-module-isolatedsites:' . $key);
            if ($rawValue !== null) {
                $incoming[$key] = $rawValue;
            }
        }

        if (empty($incoming)) {
            return;
        }

        // Only administrators (or trusted internal/system calls) may change these
        // settings; otherwise silently ignore the keys so a restricted user cannot
        // lift their own isolation via the API.
        if (!$this->actingUserMayModifySettings()) {
            return;
        }

        // Validate ALL values before persisting any, so a malformed second value
        // cannot leave the first one half-written (settings auto-commit on set()).
        $validated = [];
        foreach ($incoming as $key => $rawValue) {
            $validated[$key] = $this->validateBooleanValue($rawValue, $key);
        }

        $userId = $entity->getId();
        if ($userId) {
            // Update operation - entity already has an ID.
            $this->userSettings->setTargetId($userId);
            foreach ($validated as $key => $value) {
                $this->userSettings->set($key, $value);
            }
        } else {
            // Create operation - entity has no ID yet; defer until api.create.post.
            $this->pendingUserSettings = $validated;
        }
    }

    /**
     * Handle API create post-operation to save pending custom settings
     */
    public function handleApiCreate(Event $event)
    {
        $adapter = $event->getTarget();
        $response = $event->getParam('response');
        
        // Only handle UserAdapter
        if (!$adapter instanceof \Omeka\Api\Adapter\UserAdapter) {
            return;
        }
        
        // Check if we have pending settings to save
        if (empty($this->pendingUserSettings)) {
            return;
        }
        
        $entity = $response->getContent();
        
        // Only handle User entities that now have an ID
        if (!$entity instanceof User || !$entity->getId()) {
            return;
        }
        
        // Set the target user for settings
        $this->userSettings->setTargetId($entity->getId());
        
        // Save the pending settings
        foreach ($this->pendingUserSettings as $key => $value) {
            $this->userSettings->set($key, $value);
        }
        
        // Clear pending settings
        $this->pendingUserSettings = [];
    }

    /**
     * Handle representation JSON serialization to add custom settings
     */
    public function handleRepresentationJson(Event $event)
    {
        $representation = $event->getTarget();
        // Only handle User representations
        if (!$representation instanceof \Omeka\Api\Representation\UserRepresentation) {
            return;
        }

        $jsonLd = $event->getParam('jsonLd');
        if (!is_array($jsonLd)) {
            return;
        }

        // Set the target user for settings
        $this->userSettings->setTargetId($representation->id());

        // Add custom settings to JSON-LD
        $jsonLd['o-module-isolatedsites:limit_to_granted_sites'] =
            (bool) $this->userSettings->get('limit_to_granted_sites', false);
        $jsonLd['o-module-isolatedsites:limit_to_own_assets'] =
            (bool) $this->userSettings->get('limit_to_own_assets', false);

        // Set the modified JSON-LD back to the event
        $event->setParam('jsonLd', $jsonLd);
    }

    /**
     * Validate that a value is a proper boolean or can be converted to boolean
     *
     * @param mixed $value The value to validate
     * @param string $fieldName The field name for error messages
     * @return bool The validated boolean value
     * @throws \Omeka\Api\Exception\ValidationException If the value is not a valid boolean
     */
    protected function validateBooleanValue($value, $fieldName)
    {
        // Accept actual boolean values
        if (is_bool($value)) {
            return $value;
        }
        
        // Accept integer 0 and 1
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (bool) $value;
        }
        
        // Accept string representations of boolean values
        if (is_string($value)) {
            $lowerValue = strtolower(trim($value));
            if (in_array($lowerValue, ['true', '1', 'yes', 'on'])) {
                return true;
            }
            if (in_array($lowerValue, ['false', '0', 'no', 'off', ''])) {
                return false;
            }
        }
        
        // Accept numeric strings that are 0 or 1
        if (is_numeric($value)) {
            $numValue = (float) $value;
            if ($numValue === 0.0 || $numValue === 1.0) {
                return (bool) $numValue;
            }
        }
        
        // If we get here, the value is invalid
        throw new \Omeka\Api\Exception\ValidationException(sprintf(
            'Invalid value for %s. Expected boolean value (true/false,'.
            '1/0, "true"/"false", "yes"/"no", "on"/"off"), got: %s',
            $fieldName,
            is_string($value) ? '"' . $value . '"' : gettype($value) . '(' . var_export($value, true) . ')'
        ));
    }
}
