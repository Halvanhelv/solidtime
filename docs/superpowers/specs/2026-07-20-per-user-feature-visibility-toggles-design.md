# Per-user feature visibility toggles (hide sidebar items & dashboard widgets)

**Date:** 2026-07-20
**Status:** Approved (design)
**Scope:** Full-stack. Add 4 per-user boolean preferences that let each user hide unused features from their own sidebar/dashboard. Display-only gating — no data, backend behavior, or other users are affected.

## Problem & decision

Team feedback: Calendar, Timesheet, the Tags section, and the Dashboard "Billable Time / Billable Amount" widgets are clutter for teams that don't use them. They should be hideable.

**Scope decision (settled in brainstorming):**
- **Per-user, global on the `User`** — each person hides features for themselves, across all their organizations. Not org-level (this is a personal decluttering preference, not team governance) and not per-project (these are workspace-global UI surfaces, not project features — confirmed: this matches Clockify, which has no per-project feature toggles).
- Chosen over **org-level** (would need Owner/Admin governance; user wants personal control) and over **per-workspace `Member`** (multi-org correctness not needed; user wants one global setting).
- Default **all enabled** → existing behavior is unchanged until a user opts to hide something.

## The four preferences

New boolean columns on `users`, default `true`:

| Column | Hides when `false` |
|---|---|
| `calendar_enabled` | Calendar sidebar nav item + its command-palette entry |
| `timesheet_enabled` | Timesheet sidebar nav item |
| `tags_enabled` | Tags sidebar nav item, tag command-palette entries, reporting Tag filter + "Group by Tag" option, and tag pickers on time-entry surfaces |
| `dashboard_billable_widgets_enabled` | The "Billable Time" and "Billable Amount" cards on the Dashboard |

Gating is **visibility only**. Backend storage, aggregation, filters, exports, imports, saved reports, and existing tag data are untouched. A user who hides tags still has their time entries' tags stored; other users still see tags.

## Backend

Follow the existing per-user field pattern (mirrors how `timezone` / `week_start` flow through the same files).

1. **Migration** — add four `boolean` columns to `users`, `default(true)`, not null.
   `database/migrations/2026_07_20_000000_add_feature_visibility_prefs_to_users_table.php`.
2. **Model** — `app/Models/User.php`: add the four to `$casts` as `'boolean'` (casts array starts line 103) and to `$fillable` (line 80) so they're mass-assignable, plus `@property bool` PHPDoc.
3. **Validation** — `app/Http/Requests/V1/User/UserUpdateRequest.php`: add four rules `['boolean']` in `rules()` (alongside `week_start`, line ~60) and four typed getters returning `?bool` (nullable = absent-means-unchanged, mirroring `getTimezone()`):
   ```php
   public function getCalendarEnabled(): ?bool
   {
       return $this->has('calendar_enabled') ? $this->boolean('calendar_enabled') : null;
   }
   ```
   (and `getTimesheetEnabled`, `getTagsEnabled`, `getDashboardBillableWidgetsEnabled` identically).
4. **Controller** — `app/Http/Controllers/Api/V1/UserController.php::update()`: after the existing `getTimezone()` assignment block, assign each when non-null:
   ```php
   if ($request->getCalendarEnabled() !== null) {
       $user->calendar_enabled = $request->getCalendarEnabled();
   }
   ```
   (×4). Update authorization is already enforced there (`$user->getKey() !== $this->user()->getKey()` → 403).
5. **Resource** — `app/Http/Resources/V1/User/UserResource.php::toArray()`: expose the four (after `week_start`), each with a `/** @var bool $x ... */` doc line so Scramble types them:
   ```php
   /** @var bool $calendar_enabled Whether the Calendar feature is visible in this user's sidebar */
   'calendar_enabled' => $this->resource->calendar_enabled,
   ```
   `getMe` (`GET /users/me`) returns this resource, so the frontend `me` query receives the flags. The update endpoint (`PUT /users/{user}`) already returns `UserResource`.

## Type generation (both sources)

The frontend has two generated type sources; both consume `User`, so both must be regenerated:

1. `composer generate-typescript` → `resources/js/types/models.ts` (the Inertia `auth.user` type used for **synchronous nav gating**). New model columns appear here.
2. `npm run zod:generate` → `resources/js/packages/api/src/openapi.json.client.ts` (the API `User` + `UpdateUserBody` used by the Profile settings form's `updateUser` mutation). The npm script points at `http://localhost:80/docs/api.json`; this repo's dev container serves on `:8000`, so run against the correct base URL (adjust the script's URL or run `npx openapi-zod-client http://localhost:8000/docs/api.json --output resources/js/packages/api/src/openapi.json.client.ts --base-url /api`). The app must be serving the OpenAPI doc when this runs.

## Frontend

### Reading the flags
- **Nav + dashboard + reporting gating:** read from Inertia `usePage().props.auth.user` (typed `User` from `@/types/models`) — synchronous, already how `AppLayout.vue` (line 114-117) and the timezone modal read the user. No async flash.
- **The settings form** (edit the toggles): uses the existing `useUserQuery` (API `me`) for current values + `useUpdateUserMutation` for saving — the exact pattern `UpdateProfileInformationForm.vue` already uses.

### Gating points

**`calendar_enabled`**
- `resources/js/Layouts/AppLayout.vue:184-188` — wrap Calendar `NavigationSidebarItem` in `v-if="auth.user.calendar_enabled"`.
- `resources/js/utils/commandPaletteCommands.ts:98-106` — the `nav-calendar` command: add a visibility predicate on `calendar_enabled` (mirror how existing entries gate on `permissions.canViewTags`).

**`timesheet_enabled`**
- `resources/js/Layouts/AppLayout.vue:189-193` — wrap Timesheet `NavigationSidebarItem` in `v-if="auth.user.timesheet_enabled"`. (Timesheet has no command-palette entry.)

**`dashboard_billable_widgets_enabled`**
- `resources/js/Components/Dashboard/ThisWeekOverview.vue:262-285` — wrap the two "Billable Time" / "Billable Amount" `StatCard`s in `v-if="auth.user.dashboard_billable_widgets_enabled"`. Also guard the two feeding queries (`totalWeeklyBillableTime` lines 91-102, `totalWeeklyBillableAmount` lines 104-115) with `enabled:` on the flag so hidden cards fire no requests. Leave the sibling "Spent Time" card and `ProjectsChartCard` untouched.

**`tags_enabled`** (largest surface — the plan should phase this)
- `resources/js/Layouts/AppLayout.vue:246-251` — Tags nav item: combine with the existing gate → `v-if="canViewTags() && auth.user.tags_enabled"`.
- Command palette (`commandPaletteCommands.ts` tag entries ~166-172, 331-336, 436-442): add `tags_enabled` to their visibility predicates.
- Reporting:
  - `resources/js/Components/Common/Reporting/ReportingFilterBar.vue` — hide the `TagDropdown` + tag-match-type control (`v-if="tags_enabled"`).
  - `resources/js/utils/useReporting.ts` — filter the `'tag'` entry out of `groupByOptions` when `tags_enabled` is false (so "Group by Tag" disappears from Overview/Detailed).
- Time-entry tag pickers on app-level surfaces: `resources/js/Components/Timesheet/TimesheetRow.vue`, and the tag dropdowns in the time tracker / time-entry create/edit/mass-update modals and rows. These consume shared pickers from `packages/ui`. Gate at the **app-level consumer** with `v-if="tags_enabled"` (or pass a `:show-tags` prop) — do NOT make `packages/ui` components read app state directly.

> **Note on the shared UI package:** `packages/ui` pickers must stay app-state-agnostic. The flag is read in app code (`resources/js/…`) and passed down as a prop or used in a wrapping `v-if`. This keeps the reusable library decoupled.

### Settings UI
New Profile partial `resources/js/Pages/Profile/Partials/InterfacePreferencesForm.vue`, rendered in `resources/js/Pages/Profile/Show.vue` (alongside `UpdateProfileInformationForm` at line 28 and `ThemeForm`). A `FormSection` titled e.g. "Sidebar & features" with four `Checkbox`es (Calendar, Timesheet, Tags, Billable widgets), seeded from `useUserQuery` and saved via `useUpdateUserMutation` (`mutateAsync({ userId, body: { calendar_enabled, … } })`). Mirrors `UpdateProfileInformationForm.vue`'s query/mutation wiring and `ThemeForm.vue`'s checkbox layout. On success the `me` query invalidates; a full reload isn't required for the settings page itself, but nav gating (which reads Inertia `auth.user`) only refreshes on the next Inertia navigation/reload — acceptable, and a `router.reload({ only: ['auth'] })` after save can refresh the sidebar immediately if desired.

## Testing

**Backend (mirror existing user-update tests):**
- Endpoint test in `tests/Unit/Endpoint/Api/V1/User*` (the file covering `PUT /users/{user}`): asserts (a) each flag persists when sent, (b) `UserResource` / `getMe` returns the flags, (c) defaults are `true` for a freshly created user, (d) a partial update omitting the flags leaves them unchanged.
- If a `UserResource` unit test exists, extend it to assert the four keys are present.

**Frontend:**
- `InterfacePreferencesForm.vue` is a small, mountable presentational form (unlike `ReportingOverview`) — a vitest mounting it with a mocked `useUserQuery`/`useUpdateUserMutation` can assert the four checkboxes reflect and submit the flags. Worth writing (contrast with the KPI-strip spec, where mounting was impractical).
- Nav/widget gating is a one-line `v-if` per site; covered by the live dev-loop check below rather than component tests.
- Live check via `bin/dev.sh` on `http://localhost:8000`: toggle each off in Profile, confirm the corresponding sidebar item / dashboard cards / reporting Tag controls disappear, and that data (existing tags on entries, reports) is unaffected.

## Alternatives rejected

- **Org-level toggles** — governance model; user wants personal control, not admin-enforced.
- **Per-workspace (`Member`) toggles** — multi-org-correct but more code; user chose one global setting on `User`.
- **Per-project toggles** — architectural mismatch: sidebar/dashboard are org-global surfaces with no "active project" context; Clockify has no such thing either.
- **Client-side `localStorage`** (like `ThemeForm`'s device prefs) — simplest (no backend), but **per-device**, which fails the explicit "global on the user account, synced across devices" requirement.

## Files touched (summary)

Backend: migration; `User.php`; `UserUpdateRequest.php`; `UserController.php`; `UserResource.php`.
Generation: `models.ts`; `openapi.json.client.ts`.
Frontend: `AppLayout.vue`; `commandPaletteCommands.ts`; `ThisWeekOverview.vue`; `ReportingFilterBar.vue`; `useReporting.ts`; `TimesheetRow.vue` + time-entry tag-picker consumers; new `InterfacePreferencesForm.vue`; `Profile/Show.vue`.
Tests: user-update endpoint test; `InterfacePreferencesForm` vitest.
