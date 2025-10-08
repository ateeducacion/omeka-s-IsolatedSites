# IsolatedSites Module for Omeka S

The **IsolatedSites** module is a comprehensive security and access control solution for Omeka S that enforces **content isolation based on site permissions**. It ensures users can only view and interact with resources (items, item sets, media, assets, and sites) belonging to sites they are explicitly granted access to, significantly enhancing security and usability in multi-site, multi-user environments.

The module achieves this through **role-based access control** with a specialized `site_editor` role, **user-configurable scope limitation settings**, and **full API integration** for programmatic access management. This allows organizations to maintain strict data boundaries between different sites and users while preserving a streamlined administrative experience for site editors and content managers.

---

## ✨ Features

- **User Setting Options** ⚙️:
  - Two flags are added per user:
    - `limit_to_granted_sites`
    - `limit_to_own_assets`
  - These options are available in each user's settings (`Profiles → User Settings`):
    - 🔒 **Limit to granted sites**:
      - Items and Item Sets assigned to sites where the logged-in user has no role are **filtered out** from the admin browse pages.
      - In the Admin Dashboard and under the "Sites" navigation menu, only the sites where the user has assigned roles are shown.
    - 📄 **Limit assets list to my own assets**:
      - Assets not owned by the logged-in user are hidden from the admin browse page.

- **Site Editor Role** 🎭:
  - A specialized `site_editor` role for site-scoped content management.
  - Inherits core `editor` capabilities but restricts access to only assigned sites.
  - Users can view and edit Item Sets they own and those included in sites with granted access.
  - SiteAdmin permissions configured to allow access to the Resources tab for adding Item Sets while restricting other administrative actions.
  - Ideal for organizations with multiple sites requiring strict content isolation between teams.

- **Full API Integration** 🔌:
  - Custom user settings (`limit_to_granted_sites`, `limit_to_own_assets`) are fully accessible via REST and PHP APIs.
  - Programmatic management of user permissions and scope settings.
  - Seamless integration with existing Omeka S API workflows.
  - See the API Integration section below for detailed usage examples.

- **Automatic Filtering**:
  - When enabled, content filtering is automatic without requiring any manual user action.
  - Resource queries are filtered at the API level for consistent behavior.

- **Admin Exemption**:
  - Global administrators retain **unrestricted access** to all content, regardless of the settings.
  - Ensures system administrators can always perform maintenance and troubleshooting.

- **Docker Compose for Easy Testing** 🐳:
  - A simple Docker environment is included for fast testing and development.

---

## 🚀 Installation

### Manual Installation

1. Download the latest [release from GitHub](https://github.com/ateeducacion/omeka-s-IsolatedSites/releases).
2. Extract the ZIP file into your Omeka S `modules` directory.
3. In the Omeka S Admin Panel, navigate to **Modules**, find **IsolatedSites**, and click **Install**.

### Using Docker (for testing)

A `docker-compose.yml` file is provided:

```bash
# Make sure you have Docker and Docker Compose installed
git clone https://github.com/ateeducacion/omeka-s-IsolatedSites.git
cd omeka-s-IsolatedSites
make up
```
- Wait for the containers to start (this may take a minute).

- Access Omeka S at http://localhost:8080.

- Finish installation via the web installer and log in as admin.

## 🛠️ Usage

After installing the module:

1. Go to **Profiles → User Settings**.
2. Enable the options:
   - **Limit to granted sites**
   - **Limit assets list to my own assets**
3. Save changes.

Depending on the settings enabled, the admin interface will be dynamically filtered to show only the permitted resources.

---

## 📋 Requirements

- Omeka S **version 4.x** or later
- PHP **7.4** or newer
- Composer (only for building or developing)

---

## 🧩 Technical Notes (Summary)

- **New User Settings**: Two flags are added per user:
  - `limit_to_granted_sites`
  - `limit_to_own_assets`
- **Event Listeners**:
  - Listeners attached to `api.search.query` events filter the resources dynamically at query time.
  - API event listeners handle custom settings in user API operations.
- **Resource Filters**:
  - Items: Filtered based on granted sites.
  - Item Sets: Filtered based on granted sites and ownership. 
  - Assets: Filtered based on ownership.
  - Sites: Filtered based on granted site permissions.
- **Admin Users**: Administrators are exempt from restrictions.
- **API Integration**: Custom user settings are accessible through both REST API and PHP API.
- **No Permission Changes**: This module only changes **admin UI** visibility and adds API access to custom settings, it does not alter underlying Omeka S permission checks.

---

## 🔌 API Integration

The custom user settings (`limit_to_granted_sites`, `limit_to_own_assets`) are fully integrated with Omeka-S API:

### REST API Usage

**Reading user data** (GET `/api/users/{id}`):
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

**Updating user data** (PUT `/api/users/{id}`):
```json
{
  "o:name": "Updated Name",
  "o-module-isolatedsites:limit_to_granted_sites": false,
  "o-module-isolatedsites:limit_to_own_assets": true
}
```

### PHP API Usage
Using Service Manager in $service and api in $api Omeka/ApiManager
$response = $api->read('users', $ID);
$user = $response->getContent();
$userSettingsService = $services->get('Omeka\Settings\User');
$userSettingsService->setTargetId($user->id());

$userSettingsService->get('limit_to_granted_sites', false)
$userSettingsService->get('limit_to_own_assets', false)


**Updating settings**:
```php
$api->update('users', 1, [
    'o:name' => 'Updated Name',
    'o-module-isolatedsites:limit_to_granted_sites' => true,
    'o-module-isolatedsites:limit_to_own_assets' => false
],[],'isPartial'=> true);
```

**Note**: For PHP API calls, custom settings must be accessed via `getJsonLd()` or helper methods. See [API_INTEGRATION_README.md](API_INTEGRATION_README.md) for complete documentation.

---

## 📄 License

This module is released under the [GNU General Public License v3.0 (GPL-3.0)](https://www.gnu.org/licenses/gpl-3.0.html).

---

## 📬 Support

For questions, suggestions, or contributions, please open an [Issue](https://github.com/ateeducacion/omeka-s-IsolatedSites/issues) or submit a Pull Request.

## Site Editor Role

The module adds a `site_editor` role for site-scoped content editors. It inherits from the core `editor` role but applies the IsolatedSites filtering so users only interact with items and media that belong to sites they are permitted to manage. The role keeps the minimum set of editor capabilities required for day-to-day content work while removing global administration features.

- Inherits every capability of `editor`, then relies on the site access assertion to scope actions to the user's granted sites.
- Only items and media attached to at least one permitted site stay editable; everything else is hidden or read-only by design.
- Resource-template, site creation, and user-administration privileges are trimmed to keep the role focused on content work.
- Permissions limited in SiteAdmin index controller so that the user can access the Resources tab to add ItemSets to sites. 

### Permission Comparison

| Capability | editor | site_editor | Notes |
| --- | --- | --- | --- |
| Items and media | Create, read, update, delete across all sites | Same actions, but only for items and media attached to the user's granted sites | Restrictions enforced by the site access assertion and the `limit_to_granted_sites` setting |
| Resource templates | Create, edit, delete templates | Read-only access to template listings and details | Prevents accidental global changes while still allowing reference |
| User management | Browse and edit any user (core behaviour) | Limited to viewing and updating their own profile | Inherits `editor` abilities only when self-targeted |
| Site management | Create and manage any site | Cannot create new sites and can only work in sites where they are an `Author` | Site-level `Author` permission controls page editing within a site |

### Configuration Checklist

- Assign the `site_editor` role in Admin > Users, then grant the user `Author` permission for each site they should manage (Sites > Permissions).
- Set a Default site for the user in Admin > Users > User settings so new items they create automatically belong to that site.
- Enable `limit_to_granted_sites` in the same settings panel to activate the site-based filtering.
- Remind site editors that they will only see and manage content linked to their permitted sites; content elsewhere remains hidden.
