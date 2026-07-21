# Reporting Group-By Clockify Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make solidtime's 3-level group-by match Clockify: Tag allowed at any level (with correct totals), and dynamic slots where a deeper level appears only under a non-terminal dimension (terminal = Description).

**Architecture:** Revise the v1 three-level feature (merged on `main`). Remove the tag-exclusion shortcuts; correct tag double-counting at level 3; make the frontend slots dynamic.

**Tech Stack:** Laravel 11 / PHP 8.3 / PostgreSQL; Vue 3 + TS + Pinia; Vitest; PHPUnit.

## Global Constraints

- Tag is allowed at ANY group level (1, 2, or 3). No validation, service, zod, or UI rule may exclude it.
- Terminal dimension = `description` ONLY. A slot at depth d reveals depth d+1 (d<3) only if the depth-d value is set and not terminal.
- Do NOT replicate Clockify's Client>Project>Task hierarchy exclusion — any field at any level, minus already-chosen dimensions.
- Max depth 3. `sub_sub_group` still requires `sub_group`.
- Tag totals: a parent of a tag-expanded level must show the NON-expanded (real) total, never the sum of tag children (entries with N tags would count N times).
- 2-level export byte-identity from v1 still holds; exports need NO change (they render key/description generically).
- Run `./vendor/bin/pint` on changed PHP; `npx prettier --write` + `npx eslint` on changed JS/Vue; `npx vue-tsc --noEmit` must stay 0 errors.
- PHP tests run via the ephemeral-docker + scratch-DB method documented in `.superpowers/sdd/task-1-report.md` (from the v1 run). Do NOT run `git checkout/switch/branch/reset`; commit only on the current branch with `git add <specific files>`.

---

### Task 1: Validation — allow tag as sub_sub_group

**Files:**
- Modify: `app/Http/Requests/V1/TimeEntry/TimeEntryAggregateRequest.php`
- Modify: `app/Http/Requests/V1/TimeEntry/TimeEntryAggregateExportRequest.php`
- Test: `tests/Unit/Endpoint/Api/V1/TimeEntryEndpointTest.php`

- [ ] **Step 1: Update the failing tests**

In `TimeEntryEndpointTest.php`, the v1 tests assert tag is rejected. Replace them:
- Delete `test_aggregate_rejects_sub_sub_group_of_tag` and `test_aggregate_rejects_sub_sub_group_when_group_is_tag` (rename/rework to assert 200).
- Add:

```php
public function test_aggregate_accepts_sub_sub_group_of_tag(): void
{
    $data = $this->createUserWithPermission(['time-entries:view:all']);
    $response = $this->actingAs($data->user)->getJson(route('api.v1.time-entries.aggregate', [
        'organization' => $data->organization->getKey(),
        'group' => 'user',
        'sub_group' => 'project',
        'sub_sub_group' => 'tag',
    ]));
    $response->assertStatus(200);
}

public function test_aggregate_accepts_tag_at_group_with_third_level(): void
{
    $data = $this->createUserWithPermission(['time-entries:view:all']);
    $response = $this->actingAs($data->user)->getJson(route('api.v1.time-entries.aggregate', [
        'organization' => $data->organization->getKey(),
        'group' => 'tag',
        'sub_group' => 'user',
        'sub_sub_group' => 'project',
    ]));
    $response->assertStatus(200);
}
```
Keep the existing `test_aggregate_rejects_sub_sub_group_without_sub_group` (still valid).

(Use the exact acting-as / permission / factory conventions already present in that file — copy from a neighbouring aggregate test.)

- [ ] **Step 2: Run — expect failure**

Run: `php artisan test --filter=test_aggregate_accepts`
Expected: FAIL (422, tag currently rejected).

- [ ] **Step 3: Edit the rule in both request classes**

In each class's `sub_sub_group` rule, remove `Rule::notIn(['tag'])` and remove the closure's tag branch. Final rule:

```php
'sub_sub_group' => [
    'nullable',
    Rule::enum(TimeEntryAggregationType::class),
    function (string $attribute, mixed $value, \Closure $fail): void {
        if ($value === null || $value === '') {
            return;
        }
        if ($this->input('sub_group') === null || $this->input('sub_group') === '') {
            $fail('The sub_sub_group requires sub_group to be set.');
        }
    },
],
```

- [ ] **Step 4: Run — expect pass**

Run: `php artisan test --filter="test_aggregate_accepts|test_aggregate_rejects_sub_sub_group_without_sub_group"`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Http/Requests/V1/TimeEntry/TimeEntryAggregateRequest.php app/Http/Requests/V1/TimeEntry/TimeEntryAggregateExportRequest.php
git add app/Http/Requests/V1/TimeEntry/ tests/Unit/Endpoint/Api/V1/TimeEntryEndpointTest.php
git commit -m "feat(reporting): allow tag as third group level in validation"
```

---

### Task 2: Service — remove guard, correct tag double-count at level 3

**Files:**
- Modify: `app/Service/TimeEntryAggregationService.php` (`getAggregatedTimeEntries`, lines 53-252)
- Test: `tests/Unit/Service/TimeEntryAggregationServiceTest.php`

**Current structure (read before editing):** guard at 55-57; LATERAL tag cross-join gated at line 66 on group_1/group_2; per-group_1 base map for tag-as-group_2 at 122-141; group_3 assembly at 150-177; group_2 tag override at 194-200; overall tag total at 219-234.

- [ ] **Step 1: Write failing tests**

Add to `TimeEntryAggregationServiceTest.php`:

```php
public function test_aggregate_three_levels_with_tag_as_third_level_does_not_double_count(): void
{
    // Arrange: one user, one project, one entry carrying TWO tags → the tag
    // expansion would double the project/user totals if not corrected.
    $user = User::factory()->create(['id' => '00000000-0000-0000-0000-000000000001']);
    $project = Project::factory()->create(['id' => '5de4e6df-9560-4675-95be-18d42c441bfc']);
    $tagA = Tag::factory()->create(['name' => 'A']);
    $tagB = Tag::factory()->create(['name' => 'B']);
    TimeEntry::factory()->startWithDuration(now(), 10)->forProject($project)->create([
        'user_id' => $user->getKey(),
        'tags' => [$tagA->getKey(), $tagB->getKey()],
    ]);
    $query = TimeEntry::query();

    // Act: User -> Project -> Tag
    $result = $this->service->getAggregatedTimeEntries(
        $query,
        TimeEntryAggregationType::User,
        TimeEntryAggregationType::Project,
        'Europe/Vienna', Weekday::Monday, false,
        Carbon::now()->subDays(2)->utc(), Carbon::now()->subDay()->utc(),
        true, null, null,
        TimeEntryAggregationType::Tag,
    );

    // Assert: overall / user / project totals are the REAL 10s (not 20s from double count);
    // the two tag leaves are 10s each.
    $this->assertSame(10, $result['seconds']);
    $this->assertSame(10, $result['grouped_data'][0]['seconds']);              // user
    $this->assertSame(10, $result['grouped_data'][0]['grouped_data'][0]['seconds']); // project
    $tagLeaves = $result['grouped_data'][0]['grouped_data'][0]['grouped_data'];
    $this->assertCount(2, $tagLeaves);
    $this->assertSame(10, $tagLeaves[0]['seconds']);
    $this->assertSame(10, $tagLeaves[1]['seconds']);
}
```

Add `use App\Models\User;` / `Tag` imports if missing. (Check the Tag factory + `tags` column shape against an existing tag test in the same file; adapt the arrange to match how tags are stored/queried.)

- [ ] **Step 2: Run — expect failure**

Run: `php artisan test --filter=test_aggregate_three_levels_with_tag_as_third_level`
Expected: FAIL — currently throws `InvalidArgumentException` (the guard).

- [ ] **Step 3: Remove the guard**

Delete lines 55-57 (the `if ($group3Type === Tag) throw ...`). Also delete the v1 guard test `test_aggregate_time_entries_throws_exception_if_third_level_group_is_tag` from `TimeEntryAggregationServiceTest.php`.

- [ ] **Step 4: Extend the tag LATERAL cross-join condition**

Line 66 — add group_3:

```php
if (($group1Type === TimeEntryAggregationType::Tag) || ($group2Type === TimeEntryAggregationType::Tag) || ($group3Type === TimeEntryAggregationType::Tag)) {
```

- [ ] **Step 5: Add a per-(group_1, group_2) base map for tag-as-group_3**

After the existing `$baseTotalsPerGroup1Map` block (ends line 141), add:

```php
// If Tag is the third group, prepare base totals per (group_1, group_2) pair
// without tag expansion, to correct the inflated parent totals.
$baseTotalsPerGroup1Group2Map = [];
if ($group3Type === TimeEntryAggregationType::Tag && $group2Select !== null) {
    $baseTotalsPerPairQuery = $baseTotalsQuery->clone();
    $baseTotalsPerPair = $baseTotalsPerPairQuery
        ->selectRaw(
            $group1Select.' as group_1,'.
            $group2Select.' as group_2,'.
            ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')))) as aggregate,'.
            ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')) * (coalesce(billable_rate, 0)::float/60/60))) as cost'
        )
        ->groupBy('group_1', 'group_2')
        ->get();
    foreach ($baseTotalsPerPair as $row) {
        /** @var object{group_1: mixed, group_2: mixed, aggregate: int|null, cost: int|null} $row */
        $pairKey = ((string) ($row->group_1 ?? ''))."\x1f".((string) ($row->group_2 ?? ''));
        $baseTotalsPerGroup1Group2Map[$pairKey] = [
            'aggregate' => (int) ($row->aggregate ?? 0),
            'cost' => (int) ($row->cost ?? 0),
        ];
    }
}
```

- [ ] **Step 6: Override group_2 totals when Tag is group_3**

Inside the `if ($group3Select !== null)` branch, replace the `$group2Response[]` push (lines 169-177) so the group_2 node uses the base pair total when group_3 is Tag, and accumulate group_1 from that corrected value:

```php
$group2Seconds = $group3ResponseSum;
$group2Cost = $group3ResponseCost;
if ($group3Type === TimeEntryAggregationType::Tag) {
    $pairKey = ((string) $group1)."\x1f".((string) $group2);
    if (array_key_exists($pairKey, $baseTotalsPerGroup1Group2Map)) {
        $group2Seconds = $baseTotalsPerGroup1Group2Map[$pairKey]['aggregate'];
        $group2Cost = $baseTotalsPerGroup1Group2Map[$pairKey]['cost'];
    }
}
$group2Response[] = [
    'key' => $group2 === '' ? null : (string) $group2,
    'seconds' => $group2Seconds,
    'cost' => $showBillableRate ? $group2Cost : null,
    'grouped_type' => $group3Type->value,
    'grouped_data' => $group3Response,
];
$group2ResponseSum += $group2Seconds;
$group2ResponseCost += $group2Cost;
```

(Leaf tag rows in `$group3Response` keep their per-tag seconds — correct; only the parent rollup is corrected.)

- [ ] **Step 7: Include group_3 in the overall tag-total recomputation**

Line 220 — extend `$hasTagGrouping`:

```php
$hasTagGrouping = ($group1Type === TimeEntryAggregationType::Tag) || ($group2Type === TimeEntryAggregationType::Tag) || ($group3Type === TimeEntryAggregationType::Tag);
```

Also update the stale comment at 191-193 (group_3 CAN now be a tag group).

- [ ] **Step 8: Run — expect pass + no regressions**

Run: `php artisan test --filter=TimeEntryAggregationServiceTest`
Expected: PASS (new tag-L3 test; existing tag-L2, 3-level, 2-level tests still green; the guard test is gone).

- [ ] **Step 9: Pint + commit**

```bash
./vendor/bin/pint app/Service/TimeEntryAggregationService.php
git add app/Service/TimeEntryAggregationService.php tests/Unit/Service/TimeEntryAggregationServiceTest.php
git commit -m "feat(reporting): correct tag double-count at third group level"
```

---

### Task 3: Zod client — allow tag as sub_sub_group

**Files:**
- Modify: `resources/js/packages/api/src/openapi.json.client.ts`

- [ ] **Step 1: Add `'tag'` to both sub_sub_group enums**

In the `sub_sub_group` param of BOTH `getAggregatedTimeEntries` and `exportAggregatedTimeEntries`, add `'tag'` to the `z.enum([...])` list (so it reads the same 11 values as `sub_group`: day, week, month, year, user, project, task, client, billable, description, tag).

- [ ] **Step 2: Verify types**

Run: `npx vue-tsc --noEmit`
Expected: EXIT 0.

- [ ] **Step 3: Commit**

```bash
npx prettier --write resources/js/packages/api/src/openapi.json.client.ts
git add resources/js/packages/api/src/openapi.json.client.ts
git commit -m "feat(reporting): allow tag in sub_sub_group API client enum"
```

---

### Task 4: Frontend — dynamic Clockify-style slots

**Files:**
- Modify: `resources/js/utils/useReporting.ts`
- Modify: `resources/js/Components/Common/Reporting/ReportingOverview.vue`
- Test: `resources/js/utils/useReporting.test.ts`

- [ ] **Step 1: Write failing helper tests**

Add to `useReporting.ts` an exported terminal set + predicate, and test it. In `useReporting.test.ts`:

```ts
import { TERMINAL_GROUP_OPTIONS, isTerminalGroupOption } from './useReporting';

describe('terminal group options', () => {
    it('marks description as terminal', () => {
        expect(isTerminalGroupOption('description')).toBe(true);
    });
    it('marks non-description dimensions as non-terminal', () => {
        expect(isTerminalGroupOption('user')).toBe(false);
        expect(isTerminalGroupOption('date' as never)).toBe(false);
        expect(isTerminalGroupOption(null)).toBe(false);
    });
    it('exposes description as the only terminal option', () => {
        expect(TERMINAL_GROUP_OPTIONS).toEqual(['description']);
    });
});
```

- [ ] **Step 2: Run — expect failure**

Run: `npx vitest run resources/js/utils/useReporting.test.ts`
Expected: FAIL — not exported.

- [ ] **Step 3: Implement the terminal helper**

In `useReporting.ts`:

```ts
export const TERMINAL_GROUP_OPTIONS: GroupingOption[] = ['description'];

export function isTerminalGroupOption(option: GroupingOption | null): boolean {
    return option !== null && TERMINAL_GROUP_OPTIONS.includes(option);
}
```

- [ ] **Step 4: Run — expect pass**

Run: `npx vitest run resources/js/utils/useReporting.test.ts`
Expected: PASS.

- [ ] **Step 5: Wire dynamic slots in `ReportingOverview.vue`**

Replace the v1 tag-aware watcher and template gating with terminal-aware logic:

- Collision watcher `[group, subGroup]` — keep three-way distinctness via `nextDistinctOption`; drop the tag-clearing branch; clear `subGroup` when `group` is terminal; clear `subSubGroup` when `subGroup` is unset OR terminal:

```ts
watch(
    [group, subGroup],
    () => {
        const options = groupByOptions.map((o) => o.value);
        // slot 2 cannot exist under a terminal slot 1
        if (isTerminalGroupOption(group.value)) {
            subGroup.value = null;
        } else if (subGroup.value === group.value) {
            subGroup.value = nextDistinctOption([group.value], options) ?? null;
        }
        // slot 3 cannot exist without slot 2 or under a terminal slot 2
        if (!subGroup.value || isTerminalGroupOption(subGroup.value)) {
            subSubGroup.value = null;
        } else if (
            subSubGroup.value &&
            (subSubGroup.value === group.value || subSubGroup.value === subGroup.value)
        ) {
            subSubGroup.value = nextDistinctOption([group.value, subGroup.value], options) ?? null;
        }
    },
    { immediate: true }
);
```

(Note: `subGroup` must be nullable storage — change to `useStorage<GroupingOption | null>('reporting-sub-group', 'task')` if it is not already nullable, so "(None)" works.)

- `tableQueryParams`: send both optional, no tag narrow:

```ts
sub_group: subGroup.value ?? undefined,
sub_sub_group: subSubGroup.value ?? undefined,
```

- Template — slot 2 shown when `group` non-terminal; slot 3 shown when `subGroup` set and non-terminal. Options exclude only already-chosen dimensions (tag NOT filtered):

```vue
<template v-if="!isTerminalGroupOption(group)">
    and
    <ReportingGroupBySelect
        v-model="subGroup"
        :options="groupByOptions.filter((o) => o.value !== group)" />
</template>
<template v-if="subGroup && !isTerminalGroupOption(subGroup)">
    and
    <ReportingGroupBySelect
        v-model="subSubGroup"
        :options="groupByOptions.filter((o) => o.value !== group && o.value !== subGroup)" />
</template>
```

Ensure `ReportingGroupBySelect` renders a "(None)" entry mapping to `null` for slots 2 and 3. If it does not already, add a nullable/None affordance (it models `null` per v1 report — add an explicit "(None)" option to the passed `:options` or a prop that enables it). Slot 1 (`group`) must NOT offer "(None)".

- [ ] **Step 6: Verify**

Run: `npx vitest run resources/js/utils/useReporting.test.ts` (green), `npx vue-tsc --noEmit` (0), `npx eslint resources/js/Components/Common/Reporting/ReportingOverview.vue resources/js/utils/useReporting.ts` (0).

- [ ] **Step 7: Commit**

```bash
npx prettier --write resources/js/Components/Common/Reporting/ReportingOverview.vue resources/js/utils/useReporting.ts resources/js/utils/useReporting.test.ts
git add resources/js/Components/Common/Reporting/ReportingOverview.vue resources/js/utils/useReporting.ts resources/js/utils/useReporting.test.ts
git commit -m "feat(reporting): dynamic Clockify-style group-by slots"
```

---

### Task 5: Verification

- [ ] **Step 1:** `php artisan test --filter=TimeEntry` — service + endpoint green (baseline pre-existing failures only).
- [ ] **Step 2:** `npx vitest run resources/js/utils/useReporting.test.ts` — green.
- [ ] **Step 3:** `npx vue-tsc --noEmit` — 0 errors.
- [ ] **Step 4:** Manual smoke via the container overlay: sync changed backend files (docker cp) + `octane:reload`/restart, run dev.sh, verify: Tag selectable at level 3; User→Project→Tag totals correct; Description as slot 2 hides slot 3; "(None)" clears deeper slots.

## Self-Review

- Tag any level → Task 1 (validation) + Task 2 (service correctness) + Task 3 (zod) + Task 4 (UI no-filter) ✓
- Tag double-count at L3 → Task 2 per-(g1,g2) base map + parent override ✓
- Terminal Description / dynamic slots → Task 4 ✓
- No hierarchy exclusion → Task 4 options filter excludes only chosen dims ✓
- Exports unchanged (render generically) — no task needed ✓
- v1 guard + rejection tests removed alongside new acceptance tests → Tasks 1, 2 ✓
- Placeholder scan: complete code in every step ✓
- Type consistency: `isTerminalGroupOption`/`TERMINAL_GROUP_OPTIONS` defined Task 4 Step 3, used Task 4 Step 5; `nextDistinctOption` from v1 ✓
