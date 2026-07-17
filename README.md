# IsolatedSites Module for Omeka S

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-IsolatedSites%2Frefs%2Fheads%2Fmain%2Fblueprint.json">
  <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-IsolatedSites/refs/heads/main/.github/assets/playground-preview-button.svg" alt="Try IsolatedSites in your browser" width="224">
</a><br>
<small><a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-IsolatedSites%2Frefs%2Fheads%2Fmain%2Fblueprint.json">Try in your browser</a></small>

The **IsolatedSites** module is a comprehensive security and access control solution for Omeka S that enforces **content isolation based on site permissions**. It ensures users can only view and interact with resources (items, item sets, media, assets, and sites) belonging to sites they are explicitly granted access to, significantly enhancing security and usability in multi-site, multi-user environments.

The module achieves this through **role-based access control** with specialized site-scoped roles (`site_researcher`, `site_editor`, `site_manager`), **user-configurable scope limitation settings**, and **full API integration** for programmatic access management. This allows organizations to maintain strict data boundaries between different sites and users while preserving a streamlined administrative experience for site editors and content managers.

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

- **Site-scoped Roles** 🎭:
  - Three specialized roles for site-scoped work, all isolated to the sites a user is granted on:
    - `site_researcher` (inherits `researcher`): read-only access within granted sites.
    - `site_editor` (inherits `editor`): manages content (items, item sets, media) of granted sites, but not the site itself.
    - `site_manager` (inherits `editor`): manages content **and** the site — pages, title, navigation and theme — of granted sites.
  - Because core only exposes item-set site assignment on **Sites → Resources** (which `site_editor` cannot reach), the module adds a **Sites** tab to the item-set add/edit form so site-scoped roles can still assign their item sets.
  - Ideal for organizations with multiple sites requiring strict content isolation between teams.
  - See the [Site-scoped Roles](#site-scoped-roles) section below for the full permission breakdown.

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

- Log in as admin (`admin@example.com` / `PLEASE_CHANGEME`).

### 🧪 Verifying site isolation (local Docker)

On first boot the Docker environment provisions a ready-made multi-site scenario
(see `data/provision-demo.php`) so the isolation can be checked end to end:

| User | Email | Password | Role | Scope |
| --- | --- | --- | --- | --- |
| Editor (control) | `editor@example.com` | `1234` | `editor` | No isolation — sees everything |
| Site Researcher A | `siteresearcher.a@example.com` | `1234` | `site_researcher` | **site-a**, read-only |
| Site Editor A | `siteeditor.a@example.com` | `1234` | `site_editor` | **site-a**, manages content (not the site) |
| Site Manager A | `sitemanager.a@example.com` | `1234` | `site_manager` | **site-a**, content **and** site/pages |
| Site Editor B | `siteeditor.b@example.com` | `1234` | `site_editor` | **site-b**, manages content |

Each site has two items and an item set. The
[Impersonate](https://github.com/ateeducacion/omeka-s-Impersonate) module is also
installed so you can switch into any of these users from the admin user list
(or with `?login_as=<userId>`) without juggling passwords. To verify:

1. Impersonate **Site Editor A** → admin **Items / Item sets / Sites** show only
   *site-a*; *site-b* is hidden. They can add/edit items but the page editor is
   blocked.
2. Impersonate **Site Manager A** → same isolation, but they *can* edit *site-a*'s
   pages, title and navigation.
3. Impersonate **Site Researcher A** → sees *site-a* content read-only (no
   add/edit buttons).
4. Impersonate **Site Editor B** → the mirror image (only *site-b*).
5. **Editor** / **admin** → everything is visible.
6. Confirm the same filtering applies over the REST API, e.g.
   `GET /api/items` authenticated as Site Editor A returns only their items.

> **Playground:** the [browser playground](https://ateeducacion.github.io/omeka-s-playground/)
> provisions the same scenario from `blueprint.json` (sites *site-a* / *site-b*
> with `site_researcher` / `site_editor` / `site_manager` users, per-site
> permissions, the `limit_to_granted_sites` user setting, and the Impersonate
> module). Open the **Try in your browser** link and impersonate the demo users
> above (password `password` in the playground) to compare the three roles.
> Multi-site blueprints require an up-to-date playground build.

## 🛠️ Usage

After installing the module:

1. (Optional) Go to **Admin → Modules → IsolatedSites → Configure**. The **Enable this option to hide unallowed sites** switch is a global kill switch for the read-side filtering. It is **on by default**; turning it off disables the per-user site/asset filtering for everyone.
2. Go to **Profiles → User Settings**.
3. Enable the options:
   - **Limit to granted sites**
   - **Limit assets list to my own assets**
4. Save changes.

Depending on the settings enabled, the admin interface will be dynamically filtered to show only the permitted resources.

---

## 📋 Requirements

- Omeka S **version 3.x or 4.x**
- PHP **7.4** or newer
- Composer (only for building or developing)

---

## 🧩 Technical Notes (Summary)

- **New User Settings**: Two flags are added per user:
  - `limit_to_granted_sites`
  - `limit_to_own_assets`
- **Global Toggle**: The `activate_IsolatedSites` module setting (module Configure page) globally enables or disables the read-side `api.search.query` filtering. It is on by default; the per-user `limit_to_granted_sites` / `limit_to_own_assets` flags still gate behaviour when it is on.
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

**Reading settings** (with the service manager in `$services` and `Omeka\ApiManager` in `$api`):
```php
$response = $api->read('users', $id);
$user = $response->getContent();

$userSettingsService = $services->get('Omeka\Settings\User');
$userSettingsService->setTargetId($user->id());

$userSettingsService->get('limit_to_granted_sites', false);
$userSettingsService->get('limit_to_own_assets', false);
```

**Updating settings**:
```php
$api->update('users', 1, [
    'o:name' => 'Updated Name',
    'o-module-isolatedsites:limit_to_granted_sites' => true,
    'o-module-isolatedsites:limit_to_own_assets' => false,
], [], ['isPartial' => true]);
```

**Note**: For PHP API calls, custom settings must be accessed via `getJsonLd()` or helper methods. See [API_INTEGRATION_README.md](API_INTEGRATION_README.md) for complete documentation.

---

## 📄 License

This module is released under the [GNU General Public License v3.0 (GPL-3.0)](https://www.gnu.org/licenses/gpl-3.0.html).

---

## 📬 Support

For questions, suggestions, or contributions, please open an [Issue](https://github.com/ateeducacion/omeka-s-IsolatedSites/issues) or submit a Pull Request.

## Site-scoped Roles

The module adds **three site-scoped roles**. All three are isolated to the sites a
user is granted on (via the `limit_to_granted_sites` setting); they differ only in
**what they can write**:

| Role | Inherits | Can do | Cannot do |
| --- | --- | --- | --- |
| `site_researcher` | `researcher` | **Read** items, item sets, media and pages of their granted sites | Create or edit any content; edit the site |
| `site_editor` | `editor` | Everything `site_researcher` can **plus** create/edit/delete **content** (items, item sets, media) reachable through a granted site (or owned) | Edit the site itself — pages, title, navigation, theme |
| `site_manager` | `editor` | Everything `site_editor` can **plus** edit the **site** — pages, title, navigation and theme — of their granted sites | Create or delete sites; manage a site's user permissions |

In short: **`site_editor` manages content, `site_manager` also manages the site**, and
**`site_researcher` is read-only**. None of them can create/delete sites, manage other
users, change resource templates, or see system information — those stay with global
administrators.

### Permission Comparison

| Capability | editor (core) | site_researcher | site_editor | site_manager |
| --- | --- | --- | --- | --- |
| Read items / item sets / media | All sites | Granted sites only | Granted sites only | Granted sites only |
| Create / edit content | All sites | ❌ | ✅ (granted sites / owned) | ✅ (granted sites / owned) |
| Assign an item set to a site | All sites (**Sites > Resources**) | ❌ | ✅ (granted sites, item-set **Sites** tab) | ✅ (granted sites, item-set **Sites** tab or **Sites > Resources**) |
| Edit pages, title, navigation, theme | All sites | ❌ | ❌ | ✅ (granted sites) |
| Create / delete sites | ✅ | ❌ | ❌ | ❌ |
| Manage site user permissions | ✅ | ❌ | ❌ | ❌ |
| Resource templates | Full | Read-only | Read-only | Read-only |
| User management | Any user | Own profile only | Own profile only | Own profile only |

> Read isolation is enforced by the `api.search.query` listeners + the
> `limit_to_granted_sites` user setting (role-independent); write isolation is
> enforced by the ACL rules above + the per-site access assertion. Page editing for
> `site_manager` is additionally gated by Omeka core's per-site permission.

> Core exposes item-set site assignment only on **Sites > Resources**, which
> `site_editor` cannot reach, so the module adds a **Sites** tab to the item-set
> add/edit form. Only the sites you have permissions on are listed, and
> assignments to any other site are preserved untouched when you save — a Site
> Editor can never detach an item set from a site they cannot see. New item sets
> are pre-selected from your **Default sites for new items** user setting. This
> tab is independent of `activate_IsolatedSites` and `limit_to_granted_sites`:
> both govern read-side visibility, while assignment follows from site
> permissions alone.

### Configuration Checklist
> The admin UI surfaces a warning whenever a content-managing site role
> (`site_editor` / `site_manager`) is missing the required isolation settings.

- Assign the role in **Admin > Users**, then grant the user a permission for each site they should access (**Sites > Permissions**): `viewer` is enough for `site_researcher`, `editor`/`admin` for `site_editor` / `site_manager`.
- Set **Default sites for new items** in **Admin > Users > User settings** so new items (and item sets) a content role creates belong to those sites.
- Enable **`limit_to_granted_sites`** in the same panel to activate the site-based filtering.
- Remind users they will only see and manage content linked to their permitted sites; content elsewhere remains hidden.
