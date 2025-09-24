<?php
namespace IsolatedSites\Listener;

use Laminas\EventManager\Event;
use Omeka\Settings\UserSettings;
use Omeka\Entity\User;

class UserApiListener
{
    protected $userSettings;
    protected $pendingUserSettings = [];

    public function __construct(UserSettings $userSettings)
    {
        $this->userSettings = $userSettings;
    }

    /**
     * Handle API hydration to process custom settings
     */
    public function handleApiHydrate(Event $event)
    {
        $adapter = $event->getTarget();
        $request = $event->getParam('request');
        $entity = $event->getParam('entity');
        
        // Only handle User entities
        if (!$entity instanceof User) {
            return;
        }

        
        // For create operations, entity doesn't have ID yet, so we store the settings temporarily
        $userId = $entity->getId();
        
        if ($userId) {
            // Update operation - entity has ID
            $this->userSettings->setTargetId($userId);
            
            // Handle limit_to_granted_sites setting
            if ($request->getValue('o-module-isolatedsites:limit_to_granted_sites') !== null) {
                $rawValue = $request->getValue('o-module-isolatedsites:limit_to_granted_sites');
                $value = $this->validateBooleanValue($rawValue, 'limit_to_granted_sites');
                $this->userSettings->set('limit_to_granted_sites', $value);
            }

            // Handle limit_to_own_assets setting
            if ($request->getValue('o-module-isolatedsites:limit_to_own_assets') !== null) {
                $rawValue = $request->getValue('o-module-isolatedsites:limit_to_own_assets');
                $value = $this->validateBooleanValue($rawValue, 'limit_to_own_assets');
                $this->userSettings->set('limit_to_own_assets', $value);
            }
        } else {
            // Create operation - store settings temporarily for later processing
            $this->pendingUserSettings = [];
            
            if ($request->getValue('o-module-isolatedsites:limit_to_granted_sites') !== null) {
                $rawValue = $request->getValue('o-module-isolatedsites:limit_to_granted_sites');
                $value = $this->validateBooleanValue($rawValue, 'limit_to_granted_sites');
                $this->pendingUserSettings['limit_to_granted_sites'] = $value;
            }
            
            if ($request->getValue('o-module-isolatedsites:limit_to_own_assets') !== null) {
                $rawValue = $request->getValue('o-module-isolatedsites:limit_to_own_assets');
                $value = $this->validateBooleanValue($rawValue, 'limit_to_own_assets');
                $this->pendingUserSettings['limit_to_own_assets'] = $value;
            }
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
     * Handle batch update preprocessing to include custom settings
     */
    public function handleBatchUpdate(Event $event)
    {
        $adapter = $event->getTarget();
        $data = $event->getParam('data');
        $request = $event->getParam('request');

        // Only handle UserAdapter
        if (!$adapter instanceof \Omeka\Api\Adapter\UserAdapter) {
            return;
        }

        $rawData = $request->getContent();

        // Add custom settings to batch data if present with validation
        if (isset($rawData['o-module-isolatedsites:limit_to_granted_sites'])) {
            $value = $this->validateBooleanValue(
                $rawData['o-module-isolatedsites:limit_to_granted_sites'],
                'limit_to_granted_sites'
            );
            $data['o-module-isolatedsites:limit_to_granted_sites'] = $value;
        }
        if (isset($rawData['o-module-isolatedsites:limit_to_own_assets'])) {
            $value = $this->validateBooleanValue(
                $rawData['o-module-isolatedsites:limit_to_own_assets'],
                'limit_to_own_assets'
            );
            $data['o-module-isolatedsites:limit_to_own_assets'] = $value;
        }

        $event->setParam('data', $data);
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
