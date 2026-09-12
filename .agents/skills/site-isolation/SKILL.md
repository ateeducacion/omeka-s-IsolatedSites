---
name: site-isolation
description: "Change admin/API resource filtering, site membership assertions, or write permissions."
---

# Site isolation

Start with `src/Service/GrantedSites.php`, the listeners under `src/`, and `Module.php`.
Trace both query filtering and write assertions; hiding a resource in browse results is not authorization.

- Explicit `site_permission` rows define granted sites. `limit_to_granted_sites` controls filtering;
  toggling it must never grant membership or write privileges.
- Preserve distinct paths for authenticated non-admin admin/API requests, global/site administrators,
  anonymous public requests, and CLI. Check `api` and `api-local` behavior as well as the browser UI.
- Item visibility can include owned, unassigned items. Keep the ownership alternative and LEFT JOIN
  semantics; an empty granted-site set must not accidentally become an unrestricted query.
- Apply the relevant rules to media, item sets, assets and sites too. Inspect `HasAccessToItemSite`
  and API write listeners when changing membership; read filters and write permissions must agree.
- Set target IDs on shared settings services before user-specific reads.

Run affected listener/assertion tests and the full suite. Include no-grant, own-unassigned, another
user's item, admin exemption, direct API reads/writes and limit-disabled cases for changed boundaries.
