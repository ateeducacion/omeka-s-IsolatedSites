# Item-set site assignment for site-scoped roles

- **Issue:** [#32](https://github.com/ateeducacion/omeka-s-IsolatedSites/issues/32)
- **Branch:** `32-site-editor-cannot-assign-an-item-set-to-their-granted-sites`
- **Date:** 2026-07-17
- **Status:** Approved

## Problem

A user with the `site_editor` role can create an item set and edit the item sets they own, but has no way to assign that item set to any of the sites they have been granted permissions on. The item set becomes an orphan: visible to its owner only, absent from the site it was created for, and invisible to every other member of that site.

Two independently correct facts combine to cause this:

1. **Omeka S core does not expose site assignment on the item-set form.** `application/view/omeka/admin/item-set/form.phtml` renders only `resource-values` (*Values*) and `advanced-settings` (*Advanced*). The item form, by contrast, has a `sites` tab. The only place `o:site_item_set` can be written is Site → Resources (`Omeka\Controller\SiteAdmin\IndexController::resourcesAction`).
2. **This module denies `site_editor` access to that page.** `Module.php:402-406` denies `$noSiteEditRoles` (= `site_editor`, `site_researcher`) on `\Omeka\Controller\SiteAdmin\Index` for `index`, `edit`, `navigation`, `users`, `theme`, `add`, `delete`.

Net effect: the role has create/update rights on item sets and zero ability to place them in a site.

Note that `site_manager` is **not** affected by the bug — `Module.php:395-399` denies it only `add`, `delete`, and `users`, so it can still reach Site → Resources today. It is included in this change for consistency, not as a fix.

### Consequence of an orphaned item set

Per `src/Listener/ModifyItemSetQueryListener.php:83`, an item set matching no `siteItemSets` row is visible only through the `owner = :userId` fallback. It is therefore invisible to other Site Editors of the same site, and never appears on the public site.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Who gets the selector | `site_editor` + `site_manager` | Fixes the blocked role; keeps the two content roles behaving identically. Excluding `site_manager` would leave two different UIs for one task. |
| Sites outside the user's permissions | Preserve, invisible | A Site Editor must never be able to detach an item set from a site they cannot see. Showing a count would leak the existence of hidden sites. |
| Create-time default | Prefill `default_item_sites ∩ granted`; empty allowed | Reuses the core user setting that already drives new items — one setting, one mental model, no new config. Empty stays legal so admins and scripts are unaffected. |
| Approach | Module-side form injection + adapter reconciliation | No ACL loosening; covers create and edit in one place; keeps the module's isolation invariant intact. |

### Rejected alternatives

- **Grant `site_editor` the `resources` privilege on `SiteAdmin\Index`.** That page's POST calls `api->update('sites', ...)`, so the role would need `update` on the `Site` entity — precisely the power the role exists to withhold — and it would also expose item-pool and saved-search controls. It is per-site rather than per-item-set, so it cannot serve the create path either.
- **Auto-assign on create from `default_item_sites`, no UI.** Cheap, but fixes only half the issue: no edit path, and the assignment is invisible and unchangeable.

## Critical constraint: the relation is mapped asymmetrically

This is the fact the implementation lives or dies on.

```php
// core Site.php:108-117 — the side core writes from
@OneToMany(targetEntity="SiteItemSet", mappedBy="site",
           orphanRemoval=true, cascade={"persist", "remove"})
@OrderBy({"position" = "ASC"})

// core ItemSet.php:28-31 — the side we write from
@OneToMany(targetEntity="SiteItemSet", mappedBy="itemSet")   // no cascade, no orphanRemoval
```

Core's `SiteAdapter::hydrate` (lines 219-247) reconciles with `$siteItemSets->add(...)` / `->removeElement(...)`. That idiom works **only** because the Site side cascades. Mirroring it from the ItemSet side silently no-ops: no insert on add, no delete on remove, no error raised.

**Therefore: reconcile via `EntityManager::persist()` / `EntityManager::remove()` on `SiteItemSet` directly. Never via the `ItemSet::$siteItemSets` collection.**

## Architecture

Two listeners and one shared service, following the module's existing one-listener-per-concern shape. `Module.php` ACL rules are **not** modified; only listener attachment and service wiring change.

| Component | Responsibility |
|---|---|
| `src/Listener/ItemSetSitesFormListener.php` | Adds the **Sites** tab; echoes the fieldset on the item-set add/edit form |
| `src/Listener/ItemSetSitesHydrationListener.php` | Reconciles `SiteItemSet` rows on save |
| `src/Service/GrantedSites.php` | Single source of truth for "which sites may this user manage" |
| `view/isolated-sites/item-set-sites-fieldset.phtml` | The fieldset markup |

`config/module.config.php` already points a `template_path_stack` at `<root>/view`, which does not yet exist. This change creates it.

### `GrantedSites` service

The query `SELECT site_id FROM site_permission WHERE user_id = :id` is currently duplicated in `ModifyItemSetQueryListener:61`, `ModifySiteQueryListener::getGrantedSites`, and elsewhere. This change needs it in two more places, so it is extracted:

```php
final class GrantedSites
{
    public function __construct(Connection $connection) {}

    /** @return int[] Site ids the user holds a site_permission row for. */
    public function forUser(int $userId): array;
}
```

**Scope guard:** new code uses the service. Existing listeners are **not** migrated in this change — that is a follow-up, not this issue.

### Activation guard

Both listeners no-op unless the current user's role is `site_editor` or `site_manager`. Every other role — `global_admin`, `site_admin`, core `editor`, `site_researcher` — sees byte-identical behaviour to today, including REST clients: an `o:site` key sent by a non-site-scoped user is ignored, leaving core semantics untouched.

Expose the role list as a constant shared with `Module::ROLE_SITE_EDITOR` / `ROLE_SITE_MANAGER` rather than hardcoding strings.

### Interaction with the module's existing flags

Neither existing flag gates this feature. Both govern **read-side visibility filtering**; site assignment is a **write-side capability** that follows from `site_permission` rows alone.

| Flag | Effect on this feature | Why |
|---|---|---|
| `activate_IsolatedSites` (module setting) | **None.** The tab renders and reconciliation runs whether it is on or off. | `Module.php:132` uses it to decide whether to attach the `api.search.query` listeners. Turning it off means "stop hiding things," not "take away the ability to assign an item set to my own site." Gating on it would reintroduce the exact orphan bug whenever an admin flips the switch. |
| `limit_to_granted_sites` (user setting) | **None.** The option list is always the user's `site_permission` sites. | This flag controls what a user can *see*. It does not widen what they may *manage*: a `site_editor` with the flag off can browse every site's item sets but still holds permissions on only their own sites. Widening the selector to all sites when the flag is off would let them attach item sets to sites they have no permission on — a privilege escalation. |

The resulting behaviour is coherent: with `limit_to_granted_sites` off, a Site Editor may see item sets belonging to sites they cannot manage, and the preserve-invisible rule still protects those sites' rows on save.

## Event wiring

Verified against core:

| Event | Identifier | Filter | Contract |
|---|---|---|---|
| `view.add.section_nav` | `Omeka\Controller\Admin\ItemSet` | `true` | Mutate `section_nav` param and set it back |
| `view.edit.section_nav` | `Omeka\Controller\Admin\ItemSet` | `true` | Same |
| `view.add.form.after` | `Omeka\Controller\Admin\ItemSet` | `false` | Listener must **echo**; return value discarded |
| `view.edit.form.after` | `Omeka\Controller\Admin\ItemSet` | `false` | Same |
| `api.hydrate.post` | `Omeka\Api\Adapter\ItemSetAdapter` | — | Params: `entity`, `request` |

The two view contracts differ because `SectionNav.php:27` calls `trigger($event, $args, true)` while `form.phtml` calls `trigger("view.$action.form.after", ['form' => $form])` with `$filter` defaulting to `false` (`Trigger.php:44`).

The section id and the fieldset's `id` attribute must match for the tab to activate; use `isolatedsites-item-set-sites`.

## Data flow

**Create.** GET `/admin/item-set/add` → tab injected → fieldset lists granted sites as checkboxes, pre-checked with `default_item_sites ∩ granted`. POST → `ItemSetController::addAction` forwards raw POST to the API, so `o:site[]` arrives in the adapter request content → `api.hydrate.post` reconciles.

**Edit.** Identical, pre-checked from existing `SiteItemSet` rows ∩ granted.

### Reconciliation algorithm

```
granted   = GrantedSites::forUser(currentUser)
submitted = (request o:site[] as ints) ∩ granted     # ids outside granted are dropped
existing  = itemSet.getSiteItemSets()

for each siteId in submitted where no existing row for siteId:
    row = new SiteItemSet
    row.setSite(site); row.setItemSet(itemSet)
    row.setPosition(max(position among that site's rows) + 1)
    em.persist(row)

for each row in existing where row.site ∈ granted and row.site ∉ submitted:
    em.remove(row)

for each row in existing where row.site ∉ granted:
    leave untouched                                   # preserve, invisible
```

`api.hydrate.post` is chosen over `api.create.post` so the work joins the adapter's existing transaction: a later validation failure rolls back naturally and no second flush is needed. On create the `ItemSet` has no id yet at this point; Doctrine's commit-order calculator resolves the insert order because both entities are persisted before the same flush and the association is set.

**Partial updates:** if `o:site` is absent from the request content, skip reconciliation entirely. Absence means "do not touch," never "unassign everything." This mirrors core's `shouldHydrate` semantics (which is `protected` and so cannot be called directly from a listener).

## Error handling

| Situation | Behaviour |
|---|---|
| User has zero granted sites | Tab renders with an empty-state message, not a broken widget |
| Submitted site id outside `granted` (forged POST) | Dropped silently; no error, no 500 |
| Site deleted mid-request | `findEntity` throws; caught and skipped |
| `o:site` absent on partial update | Reconciliation skipped entirely |

## Testing

Unit tests in the existing `test/IsolatedSitesTest/Module/` style — real entity stubs from `test/stubs`, mocked `EntityManager`.

**`ItemSetSitesFormListenerTest`**
- Tab is added for `site_editor` and `site_manager`
- Tab is not added for `global_admin`, `editor`, `site_researcher`
- Options are scoped to granted sites only
- Create prefills `default_item_sites ∩ granted`
- Edit prefills from existing rows ∩ granted
- Zero granted sites renders the empty state

**`ItemSetSitesHydrationListenerTest`**
- Persists a `SiteItemSet` for a newly selected granted site
- Removes the row for a deselected granted site
- **Preserves the row for a non-granted site** — the critical isolation test
- Drops a submitted id the user has no permission on
- No-ops for `global_admin` and core `editor`
- No-ops when `o:site` is absent on a partial update
- New row's `position` appends after that site's existing rows
- Reconciles identically with `activate_IsolatedSites` off
- Option list stays scoped to granted sites with `limit_to_granted_sites` off

**`GrantedSitesTest`**
- Returns site ids for a user with permissions; empty array for one without

## Acceptance criteria

- [ ] A `site_editor` sees a **Sites** section on the item-set add and edit forms
- [ ] The selector lists only the sites the user has permissions on
- [ ] Saving with a site selected creates the `SiteItemSet` row; the item set then appears in that site and is visible to other members of it
- [ ] Deselecting a granted site removes only that row
- [ ] Saving with no site selected is allowed and creates no rows
- [ ] Assignments to sites the user cannot access are preserved untouched
- [ ] Behaviour is unaffected by `activate_IsolatedSites` and `limit_to_granted_sites`
- [ ] `global_admin` and non-site-scoped users see no behavioural change
- [ ] `site_researcher` gets no writable selector
- [ ] README role matrix (lines 237-250) updated to reflect the new capability
- [ ] Unit tests cover option scoping, create, update, deselect, and the non-detachment guard

## Out of scope

- Migrating the existing listeners to `GrantedSites`
- Any change to Site → Resources, or to the ACL rules in `Module.php`
- Exposing the Sites tab to `global_admin` (arguably a core Omeka gap; not this module's call)
- A dedicated `default_item_sets` user setting distinct from `default_item_sites`
