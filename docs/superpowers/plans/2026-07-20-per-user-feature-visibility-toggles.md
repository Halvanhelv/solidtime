# Per-user Feature Visibility Toggles — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each user hide Calendar, Timesheet, the Tags section, and the Dashboard Billable widgets from their own UI, via four per-user boolean preferences (default on).

**Architecture:** Four `boolean` columns on `users` (default `true`), plumbed through the existing user-update pattern (`timezone`/`week_start`). Frontend gates read the flags synchronously from Inertia `auth.user`; a Profile settings form edits them via the existing `updateUser` API mutation. Visibility only — no data/behavior change.

**Tech Stack:** Laravel 12 (migration, Eloquent casts, FormRequest, API Resource), Scramble OpenAPI + `openapi-zod-client`, `model:typer`, Vue 3 `<script setup lang="ts">`, Inertia, TanStack Query, Pest (backend tests), vitest (frontend test), `bin/dev.sh` dev-loop.

## Global Constraints

- Four columns, exact names: `calendar_enabled`, `timesheet_enabled`, `tags_enabled`, `dashboard_billable_widgets_enabled`. All `boolean`, `NOT NULL`, `default(true)`.
- Gating is visibility-only. Do NOT change backend aggregation, filters, exports, imports, saved reports, or existing tag data.
- `packages/ui/*` components must stay app-state-agnostic — read flags in `resources/js/` app code and pass down via `v-if`/prop; never read `usePage()`/user state inside `packages/ui`.
- Frontend nav/dashboard/reporting gating reads `usePage().props.auth.user.<flag>` (synchronous). The settings form reads via `useUserQuery` and writes via `useUpdateUserMutation`.
- Default all-on ⇒ zero behavior change until a user opts out. Backward compatible.
- Spec: `docs/superpowers/specs/2026-07-20-per-user-feature-visibility-toggles-design.md`.

---

### Task 1: Backend — columns, model, validation, controller, resource

**Files:**
- Create: `database/migrations/2026_07_20_000000_add_feature_visibility_prefs_to_users_table.php`
- Modify: `app/Models/User.php` (`$fillable` ~line 80, `$casts` ~line 103)
- Modify: `app/Http/Requests/V1/User/UserUpdateRequest.php` (`rules()` ~line 39-62, getters after ~line 78)
- Modify: `app/Http/Controllers/Api/V1/UserController.php` (`update()`, after the `getTimezone()` block)
- Modify: `app/Http/Resources/V1/User/UserResource.php` (`toArray()` after `week_start`)
- Test: `tests/Unit/Endpoint/Api/V1/UserEndpointTest.php` (locate the file with the `PUT /users/{user}` test; if the exact name differs, use the existing user-update endpoint test file)

**Interfaces:**
- Produces (consumed by all later tasks): API `User` (from `getMe` / `updateUser`) and Inertia `auth.user` gain four `bool` fields `calendar_enabled`, `timesheet_enabled`, `tags_enabled`, `dashboard_billable_widgets_enabled`. `UpdateUserBody` accepts them (all optional).

- [ ] **Step 1: Write the failing endpoint test**

In the user-update endpoint test file, add:

```php
public function test_update_endpoint_persists_feature_visibility_flags(): void
{
    $data = $this->createUserWithPermission();
    $user = $data->user;
    Passport::actingAs($user);

    $response = $this->putJson(route('api.v1.users.update', ['user' => $user->getKey()]), [
        'calendar_enabled' => false,
        'timesheet_enabled' => false,
        'tags_enabled' => false,
        'dashboard_billable_widgets_enabled' => false,
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.calendar_enabled', false);
    $response->assertJsonPath('data.timesheet_enabled', false);
    $response->assertJsonPath('data.tags_enabled', false);
    $response->assertJsonPath('data.dashboard_billable_widgets_enabled', false);

    $user->refresh();
    $this->assertFalse($user->calendar_enabled);
    $this->assertFalse($user->timesheet_enabled);
    $this->assertFalse($user->tags_enabled);
    $this->assertFalse($user->dashboard_billable_widgets_enabled);
}

public function test_feature_visibility_flags_default_true_and_survive_partial_update(): void
{
    $data = $this->createUserWithPermission();
    $user = $data->user;
    $this->assertTrue($user->calendar_enabled);
    Passport::actingAs($user);

    // Partial update that omits the flags must leave them unchanged.
    $response = $this->putJson(route('api.v1.users.update', ['user' => $user->getKey()]), [
        'name' => 'Renamed',
    ]);

    $response->assertStatus(200);
    $user->refresh();
    $this->assertTrue($user->calendar_enabled);
    $this->assertTrue($user->tags_enabled);
}
```

Match the file's existing helper for creating an authenticated user (e.g. `createUserWithPermission()` / `Passport::actingAs`) — copy whatever the neighbouring tests in this file use; the two snippets above assume the common solidtime pattern.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter=feature_visibility` (or the project's test runner: `composer test -- --filter=feature_visibility`)
Expected: FAIL — column/attribute does not exist.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_20_000000_add_feature_visibility_prefs_to_users_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('calendar_enabled')->default(true);
            $table->boolean('timesheet_enabled')->default(true);
            $table->boolean('tags_enabled')->default(true);
            $table->boolean('dashboard_billable_widgets_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'calendar_enabled',
                'timesheet_enabled',
                'tags_enabled',
                'dashboard_billable_widgets_enabled',
            ]);
        });
    }
};
```

- [ ] **Step 4: Model casts + fillable + PHPDoc**

In `app/Models/User.php`, add to the `$casts` array (after `'is_placeholder' => 'boolean',`):

```php
'calendar_enabled' => 'boolean',
'timesheet_enabled' => 'boolean',
'tags_enabled' => 'boolean',
'dashboard_billable_widgets_enabled' => 'boolean',
```

Add the same four keys to the `$fillable` array. Add PHPDoc property lines near the other `@property` declarations at the top of the class:

```php
 * @property bool $calendar_enabled
 * @property bool $timesheet_enabled
 * @property bool $tags_enabled
 * @property bool $dashboard_billable_widgets_enabled
```

- [ ] **Step 5: Validation rules + getters**

In `app/Http/Requests/V1/User/UserUpdateRequest.php`, add to the `rules()` return array (after `week_start`):

```php
'calendar_enabled' => ['boolean'],
'timesheet_enabled' => ['boolean'],
'tags_enabled' => ['boolean'],
'dashboard_billable_widgets_enabled' => ['boolean'],
```

Add four getters (after `getWeekStart()`):

```php
public function getCalendarEnabled(): ?bool
{
    return $this->has('calendar_enabled') ? $this->boolean('calendar_enabled') : null;
}

public function getTimesheetEnabled(): ?bool
{
    return $this->has('timesheet_enabled') ? $this->boolean('timesheet_enabled') : null;
}

public function getTagsEnabled(): ?bool
{
    return $this->has('tags_enabled') ? $this->boolean('tags_enabled') : null;
}

public function getDashboardBillableWidgetsEnabled(): ?bool
{
    return $this->has('dashboard_billable_widgets_enabled') ? $this->boolean('dashboard_billable_widgets_enabled') : null;
}
```

- [ ] **Step 6: Controller assignment**

In `app/Http/Controllers/Api/V1/UserController.php::update()`, after the existing `if ($request->getTimezone() !== null) { $user->timezone = ...; }` block (and before the `$user->save()`), add:

```php
if ($request->getCalendarEnabled() !== null) {
    $user->calendar_enabled = $request->getCalendarEnabled();
}
if ($request->getTimesheetEnabled() !== null) {
    $user->timesheet_enabled = $request->getTimesheetEnabled();
}
if ($request->getTagsEnabled() !== null) {
    $user->tags_enabled = $request->getTagsEnabled();
}
if ($request->getDashboardBillableWidgetsEnabled() !== null) {
    $user->dashboard_billable_widgets_enabled = $request->getDashboardBillableWidgetsEnabled();
}
```

(If `getWeekStart()` is applied after this point in the method, keep all assignments together before the save.)

- [ ] **Step 7: Resource output**

In `app/Http/Resources/V1/User/UserResource.php::toArray()`, add after the `week_start` entry:

```php
/** @var bool $calendar_enabled Whether Calendar is visible in this user's sidebar */
'calendar_enabled' => $this->resource->calendar_enabled,
/** @var bool $timesheet_enabled Whether Timesheet is visible in this user's sidebar */
'timesheet_enabled' => $this->resource->timesheet_enabled,
/** @var bool $tags_enabled Whether Tags (section, pickers, report grouping) are visible for this user */
'tags_enabled' => $this->resource->tags_enabled,
/** @var bool $dashboard_billable_widgets_enabled Whether the Dashboard billable widgets are visible for this user */
'dashboard_billable_widgets_enabled' => $this->resource->dashboard_billable_widgets_enabled,
```

- [ ] **Step 8: Run tests + migrate**

Run: `./vendor/bin/sail artisan migrate` (dev DB), then `composer test -- --filter=feature_visibility`
Expected: both tests PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Models/User.php app/Http/Requests/V1/User/UserUpdateRequest.php app/Http/Controllers/Api/V1/UserController.php app/Http/Resources/V1/User/UserResource.php tests/
git commit -m "feat(user): add per-user feature visibility preference columns"
```

---

### Task 2: Regenerate frontend types (models.ts + zod client)

**Files:**
- Modify (generated): `resources/js/types/models.ts`
- Modify (generated): `resources/js/packages/api/src/openapi.json.client.ts`

**Interfaces:**
- Consumes: Task 1's model + `UserResource`.
- Produces: `User` (models.ts) and API `User`/`UpdateUserBody` now carry the four `boolean` fields — required by Tasks 3-6.

- [ ] **Step 1: Regenerate the Eloquent-model TypeScript types**

Run: `composer generate-typescript`
Expected: `resources/js/types/models.ts` — the `User` type now lists `calendar_enabled: boolean;` etc.

- [ ] **Step 2: Regenerate the API zod client**

Ensure the app serves the OpenAPI doc, then run against the correct base URL (this repo runs on `:8000`, not `:80`):

Run: `npx openapi-zod-client http://localhost:8000/docs/api.json --output resources/js/packages/api/src/openapi.json.client.ts --base-url /api`
Expected: the generated `User` schema and `UpdateUserBody` include the four optional booleans.

- [ ] **Step 3: Type-check**

Run: `npx vue-tsc --noEmit`
Expected: exit 0, 0 errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/types/models.ts resources/js/packages/api/src/openapi.json.client.ts
git commit -m "chore(types): regenerate user types with feature visibility flags"
```

---

### Task 3: Profile settings form to edit the toggles

**Files:**
- Create: `resources/js/Pages/Profile/Partials/InterfacePreferencesForm.vue`
- Modify: `resources/js/Pages/Profile/Show.vue` (import + render after `ThemeForm`, ~line 34)
- Test: `resources/js/Pages/Profile/Partials/InterfacePreferencesForm.test.ts`

**Interfaces:**
- Consumes: `useUserQuery` (`user` with the four flags), `useUpdateUserMutation` (`mutateAsync({ userId, body })`), Task 2 types.
- Produces: user-facing controls to flip the flags.

- [ ] **Step 1: Write the failing component test**

`resources/js/Pages/Profile/Partials/InterfacePreferencesForm.test.ts`:

```ts
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import InterfacePreferencesForm from './InterfacePreferencesForm.vue';

const mutateAsync = vi.fn().mockResolvedValue({});
vi.mock('@/utils/useUserQuery', () => ({
    useUserQuery: () => ({
        user: { value: {
            id: 'u1', name: 'A', email: 'a@b.c', timezone: 'UTC', week_start: 'monday',
            calendar_enabled: true, timesheet_enabled: false,
            tags_enabled: true, dashboard_billable_widgets_enabled: true,
        } },
    }),
    useUpdateUserMutation: () => ({ mutateAsync, isPending: { value: false } }),
}));

describe('InterfacePreferencesForm', () => {
    it('seeds checkboxes from the user and submits changed flags', async () => {
        const wrapper = mount(InterfacePreferencesForm);
        // Timesheet starts disabled for this user
        const timesheet = wrapper.get('[data-testid="pref-timesheet_enabled"]');
        expect((timesheet.element as HTMLInputElement).checked).toBe(false);

        await wrapper.get('[data-testid="pref-save"]').trigger('click');
        expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({
            userId: 'u1',
            body: expect.objectContaining({ timesheet_enabled: false }),
        }));
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run resources/js/Pages/Profile/Partials/InterfacePreferencesForm.test.ts`
Expected: FAIL — component file does not exist.

- [ ] **Step 3: Implement the form**

`resources/js/Pages/Profile/Partials/InterfacePreferencesForm.vue`:

```vue
<script setup lang="ts">
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import FormSection from '@/Components/FormSection.vue';
import { Field, FieldLabel, FieldDescription } from '@/packages/ui/src/field';
import { Checkbox } from '@/packages/ui/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import ActionMessage from '@/Components/ActionMessage.vue';
import { useUserQuery, useUpdateUserMutation } from '@/utils/useUserQuery';

const { user } = useUserQuery();
const updateUser = useUpdateUserMutation();

const prefs = reactive({
    calendar_enabled: true,
    timesheet_enabled: true,
    tags_enabled: true,
    dashboard_billable_widgets_enabled: true,
});

let seeded = false;
watch(
    user,
    (u) => {
        if (u && !seeded) {
            prefs.calendar_enabled = u.calendar_enabled;
            prefs.timesheet_enabled = u.timesheet_enabled;
            prefs.tags_enabled = u.tags_enabled;
            prefs.dashboard_billable_widgets_enabled = u.dashboard_billable_widgets_enabled;
            seeded = true;
        }
    },
    { immediate: true }
);

const recentlySaved = { value: false } as { value: boolean };

async function save() {
    if (!user.value) return;
    try {
        await updateUser.mutateAsync({ userId: user.value.id, body: { ...prefs } });
        // Refresh Inertia auth.user so the sidebar reflects the change immediately.
        router.reload({ only: ['auth'] });
    } catch {
        // toast handled by the mutation
    }
}

const rows: { key: keyof typeof prefs; label: string; description: string }[] = [
    { key: 'calendar_enabled', label: 'Calendar', description: 'Show Calendar in the sidebar.' },
    { key: 'timesheet_enabled', label: 'Timesheet', description: 'Show Timesheet in the sidebar.' },
    { key: 'tags_enabled', label: 'Tags', description: 'Show the Tags section, tag pickers, and tag reporting.' },
    { key: 'dashboard_billable_widgets_enabled', label: 'Billable widgets', description: 'Show Billable Time / Billable Amount on the dashboard.' },
];
</script>

<template>
    <FormSection @submitted="save">
        <template #title>Sidebar &amp; features</template>
        <template #description>
            Hide features you don't use. This only affects your own view.
        </template>
        <template #form>
            <Field v-for="row in rows" :key="row.key" class="col-span-6 flex items-start gap-3">
                <Checkbox
                    :id="`pref-${row.key}`"
                    :data-testid="`pref-${row.key}`"
                    v-model="prefs[row.key]" />
                <div>
                    <FieldLabel :for="`pref-${row.key}`">{{ row.label }}</FieldLabel>
                    <FieldDescription>{{ row.description }}</FieldDescription>
                </div>
            </Field>
        </template>
        <template #actions>
            <ActionMessage :on="recentlySaved.value" class="me-3">Saved.</ActionMessage>
            <PrimaryButton
                data-testid="pref-save"
                :class="{ 'opacity-25': updateUser.isPending.value }"
                :disabled="updateUser.isPending.value">
                Save
            </PrimaryButton>
        </template>
    </FormSection>
</template>
```

Note: confirm the `Checkbox` component's `v-model` prop name and that `data-testid` passes through to the underlying `<input>`. If `Checkbox` does not forward arbitrary attrs to the input, bind `:data-testid` on a wrapping element and adjust the test selector accordingly. Match `ThemeForm.vue`'s `Checkbox` usage.

- [ ] **Step 4: Render it on the Profile page**

In `resources/js/Pages/Profile/Show.vue`: add `import InterfacePreferencesForm from '@/Pages/Profile/Partials/InterfacePreferencesForm.vue';` with the other partial imports, and render it after `<ThemeForm />` (line ~34), wrapped in the same `<SectionBorder />` + container pattern the sibling forms use:

```vue
                    <SectionBorder />
                    <InterfacePreferencesForm class="mt-10 sm:mt-0" />
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `npx vitest run resources/js/Pages/Profile/Partials/InterfacePreferencesForm.test.ts`
Expected: PASS.

- [ ] **Step 6: Type-check + commit**

Run: `npx vue-tsc --noEmit` → 0 errors.

```bash
git add resources/js/Pages/Profile/Partials/InterfacePreferencesForm.vue resources/js/Pages/Profile/Partials/InterfacePreferencesForm.test.ts resources/js/Pages/Profile/Show.vue
git commit -m "feat(settings): add per-user interface preferences form"
```

---

### Task 4: Gate sidebar nav + command palette (Calendar, Timesheet, Tags)

**Files:**
- Modify: `resources/js/Layouts/AppLayout.vue` (nav items: Calendar ~184-188, Timesheet ~189-193, Tags ~246-251)
- Modify: `resources/js/utils/commandPaletteCommands.ts` (calendar entry ~99-107; tags entries ~166-172 and any "Set Tags"/"Create Tag" entries)
- Modify: `resources/js/Components/CommandPalette/CommandPaletteProvider.vue` (pass the flags into the command builder's context)

**Interfaces:**
- Consumes: `usePage().props.auth.user.{calendar_enabled,timesheet_enabled,tags_enabled}` (Task 2 types).

- [ ] **Step 1: Gate the three sidebar nav items**

`AppLayout.vue` already exposes `page` (`usePage<{ auth: { user: User } }>()`, line 114). Add `v-if` to each item:

Calendar (line 184):
```vue
                            <NavigationSidebarItem
                                v-if="page.props.auth.user.calendar_enabled"
                                title="Calendar"
                                :icon="CalendarIcon"
                                :current="route().current('calendar')"
                                :href="route('calendar')"></NavigationSidebarItem>
```

Timesheet (line 189):
```vue
                            <NavigationSidebarItem
                                v-if="page.props.auth.user.timesheet_enabled"
                                title="Timesheet"
                                :icon="TableCellsIcon"
                                :current="route().current('timesheet')"
                                :href="route('timesheet')"></NavigationSidebarItem>
```

Tags (line 246) — combine with the existing permission gate:
```vue
                            <NavigationSidebarItem
                                v-if="canViewTags() && page.props.auth.user.tags_enabled"
                                title="Tags"
                                :icon="TagIcon"
                                :current="route().current('tags')"
                                :href="route('tags')"></NavigationSidebarItem>
```

- [ ] **Step 2: Thread the flags into the command palette context**

In `resources/js/Components/CommandPalette/CommandPaletteProvider.vue`, `page = usePage(...)` already exists (line 76). Where it builds the `permissions` context object passed to `commandPaletteCommands`, add the three feature flags (read from `page.props.auth.user`). Extend the context type and object, e.g.:

```ts
const featureFlags = computed(() => ({
    calendar: page.props.auth.user.calendar_enabled,
    tags: page.props.auth.user.tags_enabled,
}));
```

and pass `featureFlags.value` (or the individual booleans) into the command-builder call alongside `permissions`.

- [ ] **Step 3: Gate the palette commands**

In `resources/js/utils/commandPaletteCommands.ts`, extend the builder's context param type with `calendarEnabled: boolean; tagsEnabled: boolean;` (next to the existing `canViewTags` predicate at line 67). Then:

- `nav-calendar` (line 99): add `permission: () => calendarEnabled,` (the palette already filters entries by their `permission` predicate — confirm the field name used by neighbouring entries, e.g. `permission: permissions.canViewReport` at line 132, and match it).
- Each tags entry (`nav-tags` line 166 has `permission: permissions.canViewTags`; plus "Set Tags"/"Create Tag" entries): change to a combined predicate, e.g. `permission: () => permissions.canViewTags() && tagsEnabled`.

Wire the two new context values from Step 2 through the builder's parameter.

- [ ] **Step 4: Type-check + live verify + commit**

Run: `npx vue-tsc --noEmit` → 0 errors.
Live (dev-loop): toggle Calendar/Timesheet/Tags off in Profile → the sidebar items disappear and the palette no longer lists them; toggle on → they return.

```bash
git add resources/js/Layouts/AppLayout.vue resources/js/utils/commandPaletteCommands.ts resources/js/Components/CommandPalette/CommandPaletteProvider.vue
git commit -m "feat(nav): gate Calendar/Timesheet/Tags entry points on per-user flags"
```

---

### Task 5: Gate Dashboard billable widgets

**Files:**
- Modify: `resources/js/Components/Dashboard/ThisWeekOverview.vue` (cards ~262-285, queries ~91-115)

**Interfaces:**
- Consumes: `usePage().props.auth.user.dashboard_billable_widgets_enabled`.

- [ ] **Step 1: Read the flag**

In `ThisWeekOverview.vue` script, add (using the existing Inertia import; add `usePage` if not already imported):

```ts
import { usePage } from '@inertiajs/vue3';
const page = usePage<{ auth: { user: { dashboard_billable_widgets_enabled: boolean } } }>();
const billableWidgetsEnabled = computed(() => page.props.auth.user.dashboard_billable_widgets_enabled);
```

- [ ] **Step 2: Gate the two cards**

Wrap the "Billable Time" `StatCard` (line 262) and "Billable Amount" `StatCard` (line 273) each in `v-if="billableWidgetsEnabled"`. Leave "Spent Time" and `ProjectsChartCard` untouched.

- [ ] **Step 3: Skip the feeding queries when hidden**

For the `totalWeeklyBillableTime` query (line 91) and `totalWeeklyBillableAmount` query (line 104), change their `enabled:` option to also require the flag:

```ts
enabled: computed(() => !!organizationId.value && billableWidgetsEnabled.value),
```

- [ ] **Step 4: Type-check + live verify + commit**

Run: `npx vue-tsc --noEmit` → 0 errors.
Live: toggle Billable widgets off → both cards vanish from the dashboard, the two `/charts/total-weekly-billable-*` requests stop firing (check the network tab); "Spent Time" + projects chart remain.

```bash
git add resources/js/Components/Dashboard/ThisWeekOverview.vue
git commit -m "feat(dashboard): gate billable widgets on per-user flag"
```

---

### Task 6: Gate Tags in reporting + time-entry pickers

**Files:**
- Modify: `resources/js/Components/Common/Reporting/ReportingFilterBar.vue` (TagDropdown block ~98-125)
- Modify: `resources/js/utils/useReporting.ts` (`groupByOptions`, `'tag'` entry ~112-113)
- Modify: `resources/js/Components/Timesheet/TimesheetRow.vue` (tag dropdown)
- Modify: the time-entry tag-picker consumers (time tracker + time-entry create/edit/mass-update modals/rows that render a tag dropdown from `packages/ui`)

**Interfaces:**
- Consumes: `usePage().props.auth.user.tags_enabled`.

- [ ] **Step 1: Hide the reporting Tag filter**

In `ReportingFilterBar.vue`, add `const page = usePage<{ auth: { user: { tags_enabled: boolean } } }>();` (import `usePage` from `@inertiajs/vue3`) and wrap the `<TagDropdown>` + its tag-match-type control (lines ~98-125) in `v-if="page.props.auth.user.tags_enabled"`.

- [ ] **Step 2: Remove "Group by Tag" when tags are off**

In `useReporting.ts`, the composable returns `groupByOptions`. Filter out the `'tag'` entry when tags are disabled. Since this util is called within component setup, read the flag via `usePage`:

```ts
import { usePage } from '@inertiajs/vue3';
// inside the composable, after building groupByOptions:
const page = usePage<{ auth: { user: { tags_enabled: boolean } } }>();
const visibleGroupByOptions = computed(() =>
    page.props.auth.user.tags_enabled
        ? groupByOptions
        : groupByOptions.filter((o) => o.value !== 'tag')
);
return { groupByOptions: visibleGroupByOptions, /* ...existing returns... */ };
```

Confirm the current return shape and keep every other returned member unchanged; only swap `groupByOptions` for the filtered computed. Update consumers only if they destructure a non-ref (they already use `groupByOptions` as a value in templates, which a computed satisfies).

- [ ] **Step 3: Hide tag pickers on time-entry surfaces**

For each app-level component that renders a tag dropdown (`TimesheetRow.vue`, and the time-tracker / time-entry create/edit/mass-update modals & rows), read `page.props.auth.user.tags_enabled` in the app component and wrap the tag-dropdown element in `v-if="tags_enabled"` (or pass a `:show-tags` prop that the wrapper honours). Do NOT edit the `packages/ui` picker components themselves — gate at the consumer.

Locate the consumers with: `grep -rlE "TagDropdown|TimeEntryRowTagDropdown|TimeTrackerTagDropdown" resources/js` (excluding `packages/ui`). Apply the same `v-if` pattern to each.

- [ ] **Step 4: Type-check + live verify + commit**

Run: `npx vue-tsc --noEmit` → 0 errors.
Live: with Tags off — the reporting filter bar shows no Tag filter, "Group by" has no Tags option, and time-entry rows/modals/timer show no tag picker. Existing entries keep their stored tags (data unchanged); toggling Tags back on restores every control.

```bash
git add resources/js/Components/Common/Reporting/ReportingFilterBar.vue resources/js/utils/useReporting.ts resources/js/Components/Timesheet/TimesheetRow.vue
# plus any other tag-picker consumer files edited in Step 3
git commit -m "feat(reporting,time-entry): gate tag UI on per-user flag"
```

---

## Self-Review

**1. Spec coverage:**
- 4 columns default true → Task 1. ✓
- Backend plumbing (model/request/controller/resource) → Task 1. ✓
- Both type regenerations → Task 2. ✓
- Settings UI to edit flags → Task 3. ✓
- Calendar/Timesheet/Tags nav + palette gating → Task 4. ✓
- Dashboard billable widgets (cards + skip queries) → Task 5. ✓
- Tags reporting filter + group-by + time-entry pickers → Task 6. ✓
- `packages/ui` stays app-state-agnostic → enforced in Task 6 Step 3 + Global Constraints. ✓
- Visibility-only, data untouched → live checks in Tasks 5-6, Global Constraints. ✓
- Backend test (persist + defaults + partial) → Task 1. ✓ Settings-form vitest → Task 3. ✓

**2. Placeholder scan:** No TBD/TODO. Each code step carries real code. Steps that must adapt to an unseen file (test helper in Task 1 Step 1; `Checkbox` attr passthrough in Task 3 Step 3; palette `permission` field name in Task 4 Step 3; `useReporting` return shape in Task 6 Step 2; tag-picker consumer list in Task 6 Step 3) name exactly what to confirm and the pattern to match — not vague "handle it".

**3. Type consistency:** Column names identical across migration, casts, request rules, getters, controller, resource, and all frontend reads (`calendar_enabled`, `timesheet_enabled`, `tags_enabled`, `dashboard_billable_widgets_enabled`). Getters return `?bool`; controller assigns only when non-null (partial-update safe). Frontend reads `page.props.auth.user.<flag>` (models.ts type from Task 2) for gating and `useUserQuery` `user.<flag>` (API type from Task 2) for the form. `UpdateUserBody` (Task 2) carries the optional booleans the form submits. Consistent.
