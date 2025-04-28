# IsolatedSites Module for Omeka S

The **IsolatedSites** module enhances the Omeka S admin experience by allowing users to **only view content (items, item sets, assets, and sites) they are explicitly permitted to access**.  
This improves clarity, security, and usability in multi-site, multi-user environments.

---

## ✨ Features

- **User Settings Extension**:
  - Two new options are added in each user's settings (`Profiles → User Settings`):
    - 🔒 **Limit to granted sites**:
      - Items and Item Sets assigned to sites where the logged-in user has no role are **filtered out** from the admin browse pages.
      - In the Admin Dashboard and under the "Sites" navigation menu, only the sites where the user has assigned roles are shown.
    - 📄 **Limit assets list to my own assets**:
      - Assets not owned by the logged-in user are hidden from the admin browse page.
- **Automatic Filtering**:
  - When enabled, content filtering is automatic without requiring any manual user action.
- **Admin Exemption**:
  - Global administrators retain **unrestricted access** to all content, regardless of the settings.
- **Docker Compose for Easy Testing**:
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
- **Resource Filters**:
  - Items: Filtered based on granted sites.
  - Item Sets: Filtered based on granted sites.
  - Assets: Filtered based on ownership.
  - Sites: Filtered based on granted site permissions.
- **Admin Users**: Administrators are exempt from restrictions.
- **No API restrictions**: This module only changes **admin UI** visibility, it does not alter underlying Omeka S permission checks.

---

## 📄 License

This module is released under the [GNU General Public License v3.0 (GPL-3.0)](https://www.gnu.org/licenses/gpl-3.0.html).

---

## 📬 Support

For questions, suggestions, or contributions, please open an [Issue](https://github.com/ateeducacion/omeka-s-IsolatedSites/issues) or submit a Pull Request.

