# IsolatedSites Module for Omeka S

## Features
**- In Profiles->User Settings:** 
  - if "limit to granted sites" checkbox:
       - items and item sets assigned to sites where the logged in user has no role are filtered out from the admin browse page
       - Sites in admin dashboard and in admin/site route where user has no permissions are filtered out 
  - if "limit to own assets" checkbox assets which do not belong to the user logged in are filtered out from the admin browse page
  - if user is global admin the above checkboxs do not apply

## Installation

### Manual Installation

1. Download the latest release from the GitHub repository
2. Extract the zip file to your Omeka S `modules` directory
3. Log in to the Omeka S admin panel and navigate to Modules

### Using Docker

A Docker Compose file is provided for easy testing:

1. Make sure you have Docker and Docker Compose installed
2. Clone this repository
3. From the repository root, run:

```bash
make up
```

4. Wait for the containers to start (this may take a minute)
5. Access Omeka S at http://localhost:8080
6. Finish the installation and login as admin user

## Installation

See general end user documentation for [Installing a module](http://omeka.org/s/docs/user-manual/modules/#installing-modules)

## Usage



## Requirements

- Omeka S 4.x or later

## License

This module is published under the [GNU GPLv3](LICENSE) license.

