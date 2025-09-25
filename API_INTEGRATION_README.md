# IsolatedSites API Integration

This document explains how the custom settings (`limit_to_granted_sites`, `limit_to_own_assets`) have been integrated into the Omeka-S API to allow reading and modifying them through standard API calls.

## Overview

The custom settings are now exposed as part of the user resource API, allowing them to be read and modified using the same patterns as standard user data. This integration maintains backward compatibility while extending the API functionality through event-based listeners, avoiding UserAdapter subclassing to prevent conflicts with Omeka-S and other modules.

## Implementation Details

### 1. Event-Based API Extension (`src/Listener/UserApiListener.php`)

The `UserApiListener` provides API functionality through event listeners without subclassing UserAdapter:

- **API Hydration**: Processes custom settings during API write operations (create/update)
- **JSON-LD Integration**: Adds custom settings to REST API responses
- **Batch Operations**: Handles custom settings in batch updates
- **Non-Intrusive**: Works alongside the default adapter without replacing it

Key events handled:
- `api.hydrate.post`: Saves custom settings during write operations
- `rep.resource.json`: Adds custom settings to JSON-LD (REST API responses)
- `api.batch_update.pre`: Handles batch operations with custom settings

### 2. Custom API Representation (`src/Api/Representation/UserRepresentation.php`)

The custom `UserRepresentation` extends Omeka's base `UserRepresentation` and adds:

- **JSON-LD Integration**: Custom settings appear in `getJsonLd()` output
- **Seamless Integration**: Works with existing user representation features

### 3. Module Configuration (`Module.php`)

The module configuration registers event listeners for API operations:

```php
// API listeners for custom user settings
$sharedEventManager->attach(
    'Omeka\Api\Adapter\UserAdapter',
    'api.hydrate.post',
    [$this->serviceLocator->get(UserApiListener::class), 'handleApiHydrate']
);

$sharedEventManager->attach(
    'Omeka\Api\Representation\UserRepresentation',
    'rep.resource.json',
    [$this->serviceLocator->get(UserApiListener::class), 'handleRepresentationJson']
);

$sharedEventManager->attach(
    'Omeka\Api\Adapter\UserAdapter',
    'api.batch_update.pre',
    [$this->serviceLocator->get(UserApiListener::class), 'handleBatchUpdate']
);
$sharedEventManager->attach(
    'Omeka\Api\Adapter\UserAdapter',
    'api.create.post',
    [$this->serviceLocator->get(UserApiListener::class), 'handleApiCreate']
);
```

**Note**: This approach uses event listeners instead of subclassing UserAdapter, avoiding ACL conflicts and compatibility issues with other modules.

## API Usage

### Reading User Data

**GET** `/api/users/{id}`

Response includes custom settings:
```json
{
  "o:id": 1,
  "o:name": "John Doe",
  "o:email": "john@example.com",
  "o:role": "editor",
  "o:is_active": true,
  "o-module-isolatedsites:limit_to_granted_sites": true,
  "o-module-isolatedsites:limit_to_own_assets": false
}
```

### Updating User Data

**PUT** `/api/users/{id}`

Request body can include custom settings:
```json
{
  "o:name": "Updated Name",
  "o-module-isolatedsites:limit_to_granted_sites": false,
  "o-module-isolatedsites:limit_to_own_assets": true
}
```

### Batch Operations

**PATCH** `/api/users`

Batch update multiple users with custom settings:
```json
{
  "ids": [1, 2, 3],
  "o-module-isolatedsites:limit_to_granted_sites": true,
  "o-module-isolatedsites:limit_to_own_assets": false
}
```

## PHP API Usage Examples

### Reading Settings

```php
// Get user with custom settings
$user = $api->read('users', 1)->getContent();

// Method 3: Direct UserSettings access
$userSettings = $serviceLocator->get('Omeka\Settings\User');
$userSettings->setTargetId($user->id());
$limitToGrantedSites = $userSettings->get('limit_to_granted_sites', false);
$limitToOwnAssets = $userSettings->get('limit_to_own_assets', false);
```

### Updating Settings

```php
// Update user with custom settings
$api->update('users', 1, [
    'o:name' => 'Updated Name',
    'o-module-isolatedsites:limit_to_granted_sites' => true,
    'o-module-isolatedsites:limit_to_own_assets' => false
]);
```

### Batch Operations

```php
// Batch update multiple users
$api->batchUpdate('users', [1, 2, 3], [
    'o-module-isolatedsites:limit_to_granted_sites' => false,
    'o-module-isolatedsites:limit_to_own_assets' => true
]);
```

## JavaScript/AJAX Usage Examples

### Reading Settings

```javascript
// Fetch user data
fetch('/api/users/1')
  .then(response => response.json())
  .then(user => {
    const limitToGrantedSites = user['o-module-isolatedsites:limit_to_granted_sites'];
    const limitToOwnAssets = user['o-module-isolatedsites:limit_to_own_assets'];
    console.log('Limit to granted sites:', limitToGrantedSites);
    console.log('Limit to own assets:', limitToOwnAssets);
  });
```

### Updating Settings

```javascript
// Update user data
fetch('/api/users/1', {
  method: 'PUT',
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  },
  body: JSON.stringify({
    'o:name': 'Updated Name',
    'o-module-isolatedsites:limit_to_granted_sites': false,
    'o-module-isolatedsites:limit_to_own_assets': true
  })
})
.then(response => response.json())
.then(data => console.log('User updated:', data));
```

## Data Storage

The custom settings are stored in Omeka-S's `user_setting` table using the UserSettings service:

- Setting key: `limit_to_granted_sites`
- Setting key: `limit_to_own_assets`
- Values: Boolean (true/false)
- Scope: Per-user settings

## Backward Compatibility

This implementation maintains full backward compatibility:

- Existing API calls continue to work unchanged
- Form-based user management still functions
- Custom settings appear in both API and form interfaces
- No database schema changes required

## Security Considerations

- Custom settings follow the same permission model as standard user data
- Only users with appropriate permissions can modify user settings
- Settings are validated and sanitized before storage
- Batch operations respect individual user permissions


This script demonstrates all the API integration points and provides usage examples.

## Benefits

1. **Unified Interface**: Custom settings accessible through standard API
2. **Programmatic Access**: Easy integration with external systems
3. **Batch Operations**: Efficient bulk updates of user settings
4. **Standard Patterns**: Follows Omeka-S API conventions
5. **Type Safety**: Boolean validation and conversion
6. **Extensible**: Easy to add more custom settings in the future
