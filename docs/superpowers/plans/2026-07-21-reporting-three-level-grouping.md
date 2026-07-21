# Reporting Three-Level Group-By Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a third grouping level (`sub_sub_group`) to the Reporting Overview table and all exports, enabling trees like User → Date → Description.

**Architecture:** Extend the existing explicit two-level aggregation (`group_1`/`group_2`) with an explicit third level (`group_3`), appended as an optional trailing parameter so no existing caller breaks. The recursive frontend row component renders the third level for free; the Overview page gains a third select and a recursive table-data mapper. Exports (PDF/xlsx/csv/ods) gain a third column/level.

**Tech Stack:** Laravel 11 (PHP 8.3, PHPUnit), PostgreSQL, Vue 3 + Inertia + TypeScript, Pinia, Vitest, Maatwebsite Excel, Gotenberg (PDF via Blade).

## Global Constraints

- Aggregation is capped at **three** levels — do not generalize to N-level.
- `group_3` is added as a **trailing optional parameter** (`?TimeEntryAggregationType $group3Type = null`) on service methods; never insert it mid-signature (existing positional callers/tests must stay green).
- **Tag exclusion:** when `sub_sub_group` is present, none of `group` / `sub_group` / `sub_sub_group` may be `tag`. This keeps the tag LATERAL-expansion double-count logic untouched (it only ever runs in 1- or 2-level requests). Enforced by backend validation and hidden in the UI.
- A third level requires a second: `sub_sub_group` present without `sub_group` is invalid.
- Follow existing code style: PHPUnit `test_*` methods with full-array `assertSame`; Vue `<script setup lang="ts">`; run PHP tests with `php artisan test`, JS tests with `npx vitest run`.
- Run `./vendor/bin/pint` (PHP) and `npx prettier --write` + `npx eslint` on every changed file before committing.

---

### Task 1: Request validation — `sub_sub_group`

**Files:**
- Modify: `app/Http/Requests/V1/TimeEntry/TimeEntryAggregateRequest.php`
- Modify: `app/Http/Requests/V1/TimeEntry/TimeEntryAggregateExportRequest.php`
- Test: `tests/Feature/Endpoint/Api/V1/TimeEntryEndpointTest.php` (or the existing aggregate endpoint test file; create `tests/Feature/Endpoint/Api/V1/TimeEntryAggregateSubSubGroupTest.php` if none focuses on aggregate)

**Interfaces:**
- Produces: `getSubSubGroup(): ?TimeEntryAggregationType` on both request classes.

- [ ] **Step 1: Write the failing feature test**

Locate the aggregate endpoint route name (`time-entries.aggregate`). Add to a feature test file:

```php
public function test_aggregate_rejects_sub_sub_group_of_tag(): void
{
    $data = $this->createUserWithPermission(['time-entries:view:all']);
    $response = $this->actingAs($data->user)->getJson(route('api.v1.time-entries.aggregate', [
        'organization' => $data->organization->getKey(),
        'group' => 'user',
        'sub_group' => 'day',
        'sub_sub_group' => 'tag',
    ]));
    $response->assertStatus(422);
    $response->assertJsonValidationErrorFor('sub_sub_group');
}

public function test_aggregate_rejects_sub_sub_group_without_sub_group(): void
{
    $data = $this->createUserWithPermission(['time-entries:view:all']);
    $response = $this->actingAs($data->user)->getJson(route('api.v1.time-entries.aggregate', [
        'organization' => $data->organization->getKey(),
        'group' => 'user',
        'sub_sub_group' => 'description',
    ]));
    $response->assertStatus(422);
    $response->assertJsonValidationErrorFor('sub_sub_group');
}

public function test_aggregate_rejects_sub_sub_group_when_group_is_tag(): void
{
    $data = $this->createUserWithPermission(['time-entries:view:all']);
    $response = $this->actingAs($data->user)->getJson(route('api.v1.time-entries.aggregate', [
        'organization' => $data->organization->getKey(),
        'group' => 'tag',
        'sub_group' => 'user',
        'sub_sub_group' => 'day',
    ]));
    $response->assertStatus(422);
    $response->assertJsonValidationErrorFor('sub_sub_group');
}
```

(If `createUserWithPermission` differs in this suite, copy the arrange pattern from a neighbouring test in the same file.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=test_aggregate_rejects_sub_sub_group`
Expected: FAIL — `sub_sub_group` currently unvalidated, requests return 200.

- [ ] **Step 3: Add the rule + getter to `TimeEntryAggregateRequest`**

In `rules()`, immediately after the `sub_group` rule (line ~48), add:

```php
// Type of third grouping. Optional; requires sub_group. Tag is not allowed
// at any level when a third level is used (avoids tag double-count logic).
'sub_sub_group' => [
    'nullable',
    'required_with:__never__', // no-op; kept explicit that it is optional
    Rule::enum(TimeEntryAggregationType::class),
    Rule::notIn(['tag']),
    function (string $attribute, mixed $value, \Closure $fail): void {
        if ($value === null || $value === '') {
            return;
        }
        if ($this->input('sub_group') === null || $this->input('sub_group') === '') {
            $fail('The sub_sub_group requires sub_group to be set.');
        }
        if ($this->input('group') === 'tag' || $this->input('sub_group') === 'tag') {
            $fail('Tag grouping cannot be combined with a third grouping level.');
        }
    },
],
```

Remove the `required_with:__never__` line (it was illustrative) — the field is simply optional via `nullable`. Final rule:

```php
'sub_sub_group' => [
    'nullable',
    Rule::enum(TimeEntryAggregationType::class),
    Rule::notIn(['tag']),
    function (string $attribute, mixed $value, \Closure $fail): void {
        if ($value === null || $value === '') {
            return;
        }
        if ($this->input('sub_group') === null || $this->input('sub_group') === '') {
            $fail('The sub_sub_group requires sub_group to be set.');
        }
        if ($this->input('group') === 'tag' || $this->input('sub_group') === 'tag') {
            $fail('Tag grouping cannot be combined with a third grouping level.');
        }
    },
],
```

Add the getter next to `getSubGroup()`:

```php
public function getSubSubGroup(): ?TimeEntryAggregationType
{
    return $this->input('sub_sub_group') !== null && $this->input('sub_sub_group') !== ''
        ? TimeEntryAggregationType::from($this->input('sub_sub_group'))
        : null;
}
```

- [ ] **Step 4: Mirror in `TimeEntryAggregateExportRequest`**

Add the identical `sub_sub_group` rule after its `sub_group` rule (line ~55) and the identical `getSubSubGroup()` getter after its `getSubGroup()` (line ~222). Note: in the export request `sub_group` is `required`, so the "requires sub_group" branch is defensive but harmless — keep it.

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test --filter=test_aggregate_rejects_sub_sub_group`
Expected: PASS (all three).

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Http/Requests/V1/TimeEntry/TimeEntryAggregateRequest.php app/Http/Requests/V1/TimeEntry/TimeEntryAggregateExportRequest.php
git add app/Http/Requests/V1/TimeEntry/TimeEntryAggregateRequest.php app/Http/Requests/V1/TimeEntry/TimeEntryAggregateExportRequest.php tests/
git commit -m "feat(reporting): validate sub_sub_group aggregation param"
```

---

### Task 2: Service — three-level `getAggregatedTimeEntries`

**Files:**
- Modify: `app/Service/TimeEntryAggregationService.php` (method `getAggregatedTimeEntries`, lines 47-199; return-type docblock 26-46)
- Test: `tests/Unit/Service/TimeEntryAggregationServiceTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `getAggregatedTimeEntries($query, $group1, $group2, $timezone, $startOfWeek, $fillGaps, $start, $end, $showBillableRate, $roundingType, $roundingMinutes, ?TimeEntryAggregationType $group3Type = null)` returning a 3-level-capable nested array; the third-level nodes have `grouped_type: null, grouped_data: null`.

- [ ] **Step 1: Write the failing unit test**

Append to `TimeEntryAggregationServiceTest.php`, mirroring `test_aggregate_time_entries_by_project_and_description`:

```php
public function test_aggregate_time_entries_by_user_project_and_description_three_levels(): void
{
    // Arrange
    $user = User::factory()->create(['id' => '00000000-0000-0000-0000-000000000001']);
    $project = Project::factory()->create(['id' => '5de4e6df-9560-4675-95be-18d42c441bfc']);
    TimeEntry::factory()->startWithDuration(now(), 10)->forProject($project)->create([
        'user_id' => $user->getKey(), 'description' => 'Test',
    ]);
    TimeEntry::factory()->startWithDuration(now(), 10)->forProject($project)->create([
        'user_id' => $user->getKey(), 'description' => 'Test',
    ]);
    $query = TimeEntry::query();

    // Act
    $result = $this->service->getAggregatedTimeEntries(
        $query,
        TimeEntryAggregationType::User,
        TimeEntryAggregationType::Project,
        'Europe/Vienna',
        Weekday::Monday,
        false,
        Carbon::now()->subDays(2)->utc(),
        Carbon::now()->subDay()->utc(),
        true,
        null,
        null,
        TimeEntryAggregationType::Description,
    );

    // Assert
    $this->assertSame([
        'seconds' => 20,
        'cost' => 0,
        'grouped_type' => 'user',
        'grouped_data' => [
            [
                'key' => $user->getKey(),
                'seconds' => 20,
                'cost' => 0,
                'grouped_type' => 'project',
                'grouped_data' => [
                    [
                        'key' => $project->getKey(),
                        'seconds' => 20,
                        'cost' => 0,
                        'grouped_type' => 'description',
                        'grouped_data' => [
                            [
                                'key' => 'Test',
                                'seconds' => 20,
                                'cost' => 0,
                                'grouped_type' => null,
                                'grouped_data' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], $result);
}
```

Add `use App\Models\User;` to the test imports if absent.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=test_aggregate_time_entries_by_user_project_and_description_three_levels`
Expected: FAIL — service ignores the 12th argument; result nests only two levels.

- [ ] **Step 3: Extend the method signature and grouping**

In `getAggregatedTimeEntries`, append the parameter:

```php
public function getAggregatedTimeEntries(Builder $timeEntriesQuery, ?TimeEntryAggregationType $group1Type, ?TimeEntryAggregationType $group2Type, string $timezone, Weekday $startOfWeek, bool $fillGapsInTimeGroups, ?Carbon $start, ?Carbon $end, bool $showBillableRate, ?TimeEntryRoundingType $roundingType, ?int $roundingMinutes, ?TimeEntryAggregationType $group3Type = null): array
```

Add `$group3Select = null;` beside the existing `$group1Select`/`$group2Select` declarations. Replace the group-select/groupBy block (lines 65-72) with:

```php
if ($group1Type !== null) {
    $group1Select = $this->getGroupByQuery($group1Type, $timezone, $startOfWeek);
    $groupBy = ['group_1'];
    if ($group2Type !== null) {
        $group2Select = $this->getGroupByQuery($group2Type, $timezone, $startOfWeek);
        $groupBy = ['group_1', 'group_2'];
        if ($group3Type !== null) {
            $group3Select = $this->getGroupByQuery($group3Type, $timezone, $startOfWeek);
            $groupBy = ['group_1', 'group_2', 'group_3'];
        }
    }
}
```

Extend the `selectRaw` (line 77-82) to include `group_3`:

```php
$timeEntriesQuery->selectRaw(
    ($group1Select !== null ? $group1Select.' as group_1,' : '').
    ($group2Select !== null ? $group2Select.' as group_2,' : '').
    ($group3Select !== null ? $group3Select.' as group_3,' : '').
    ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')))) as aggregate,'.
    ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')) * (coalesce(billable_rate, 0)::float/60/60))) as cost'
);
```

Extend ordering (after line 87-91):

```php
if ($group1Select !== null) {
    $timeEntriesQuery->orderBy('group_1');
    if ($group2Select !== null) {
        $timeEntriesQuery->orderBy('group_2');
        if ($group3Select !== null) {
            $timeEntriesQuery->orderBy('group_3');
        }
    }
}
```

- [ ] **Step 4: Extend the response assembly**

Update the `groupBy` collection call (line 96) to include group_3:

```php
$groupedAggregates = $timeEntriesAggregates->groupBy(
    $group3Select !== null ? ['group_1', 'group_2', 'group_3']
        : ($group2Select !== null ? ['group_1', 'group_2'] : ['group_1'])
);
```

Inside the `foreach ($groupedAggregates as $group1 => $group1Aggregates)` loop, replace the `if ($group2Select !== null) { ... }` inner block (lines 124-147) with a version that builds a third level when present:

```php
if ($group2Select !== null) {
    $group2ResponseSum = 0;
    $group2ResponseCost = 0;
    foreach ($group1Aggregates as $group2 => $group2Bucket) {
        /** @var string|int $group2 */
        if ($group3Select !== null) {
            // $group2Bucket is keyed by group_3
            $group3Response = [];
            $group3ResponseSum = 0;
            $group3ResponseCost = 0;
            /** @var Collection<int|string, Collection<int, object{aggregate: int, cost: int}>> $group2Bucket */
            foreach ($group2Bucket as $group3 => $aggregate) {
                /** @var string|int $group3 */
                /** @var Collection<int, object{aggregate: int, cost: int}> $aggregate */
                $group3Response[] = [
                    'key' => $group3 === '' ? null : (string) $group3,
                    'seconds' => (int) $aggregate->get(0)->aggregate,
                    'cost' => $showBillableRate ? (int) $aggregate->get(0)->cost : null,
                    'grouped_type' => null,
                    'grouped_data' => null,
                ];
                $group3ResponseSum += (int) $aggregate->get(0)->aggregate;
                $group3ResponseCost += (int) $aggregate->get(0)->cost;
            }
            $group2Response[] = [
                'key' => $group2 === '' ? null : (string) $group2,
                'seconds' => $group3ResponseSum,
                'cost' => $showBillableRate ? $group3ResponseCost : null,
                'grouped_type' => $group3Type->value,
                'grouped_data' => $group3Response,
            ];
            $group2ResponseSum += $group3ResponseSum;
            $group2ResponseCost += $group3ResponseCost;
        } else {
            /** @var Collection<int, object{aggregate: int, cost: int}> $group2Bucket */
            $group2Response[] = [
                'key' => $group2 === '' ? null : (string) $group2,
                'seconds' => (int) $group2Bucket->get(0)->aggregate,
                'cost' => $showBillableRate ? (int) $group2Bucket->get(0)->cost : null,
                'grouped_type' => null,
                'grouped_data' => null,
            ];
            $group2ResponseSum += (int) $group2Bucket->get(0)->aggregate;
            $group2ResponseCost += (int) $group2Bucket->get(0)->cost;
        }
    }
    // Override primary group totals when Tag is subgroup to avoid double counting.
    // Note: group_3 is never set together with a tag group (validation forbids it),
    // so this branch only runs in genuine two-level tag requests.
    if ($group2Type === TimeEntryAggregationType::Tag) {
        $keyForMap = (string) $group1;
        if (array_key_exists($keyForMap, $baseTotalsPerGroup1Map)) {
            $group2ResponseSum = $baseTotalsPerGroup1Map[$keyForMap]['aggregate'];
            $group2ResponseCost = $baseTotalsPerGroup1Map[$keyForMap]['cost'];
        }
    }
} else {
    /** @var Collection<int, object{aggregate: int, cost: int}> $group1Aggregates */
    $group2ResponseSum = (int) $group1Aggregates->get(0)->aggregate;
    $group2ResponseCost = (int) $group1Aggregates->get(0)->cost;
    $group2Response = null;
}
```

Keep `$group2Response = [];` initialization before the `if` (as today). The outer `$group1Response[]` push (lines 155-161) is unchanged.

- [ ] **Step 5: Update the return-type docblock**

Replace the method docblock (lines 26-46) to describe three nesting levels: the second-level node's `grouped_type` is `string|null` and `grouped_data` is `null|array<...third-level leaf...>`, where the third-level leaf has `grouped_type: null, grouped_data: null`. Mirror the exact structure shown in `getAggregatedTimeEntriesWithDescriptions`'s docblock but without `description`/`color`.

- [ ] **Step 6: Run to verify pass + regressions green**

Run: `php artisan test --filter=TimeEntryAggregationServiceTest`
Expected: PASS — new three-level test plus all existing two-level tests.

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Service/TimeEntryAggregationService.php
git add app/Service/TimeEntryAggregationService.php tests/Unit/Service/TimeEntryAggregationServiceTest.php
git commit -m "feat(reporting): three-level aggregation in getAggregatedTimeEntries"
```

---

### Task 3: Service — descriptors for third level

**Files:**
- Modify: `app/Service/TimeEntryAggregationService.php` (method `getAggregatedTimeEntriesWithDescriptions`, lines 226-286; docblock 201-224)
- Test: `tests/Unit/Service/TimeEntryAggregationServiceTest.php`

**Interfaces:**
- Produces: `getAggregatedTimeEntriesWithDescriptions(..., ?TimeEntryAggregationType $group3Type = null)` that also sets `description`/`color` on third-level nodes.

- [ ] **Step 1: Write the failing unit test**

Append a test that calls `getAggregatedTimeEntriesWithDescriptions` with User→Project→Description and asserts the third-level node carries `description` (the raw description string) and `color` (`null`), plus the project node carries the project name and color. Model it on any existing `WithDescriptions` test in the file (search `getAggregatedTimeEntriesWithDescriptions`); assert with `assertSame` on the nested array including `description`/`color` keys at all three levels.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=WithDescriptions`
Expected: FAIL — third-level nodes lack `description`/`color`.

- [ ] **Step 3: Implement**

Append `?TimeEntryAggregationType $group3Type = null` to the signature and pass it through to `getAggregatedTimeEntries`:

```php
$aggregatedTimeEntries = $this->getAggregatedTimeEntries($timeEntriesQuery, $group1Type, $group2Type, $timezone, $startOfWeek, $fillGapsInTimeGroups, $start, $end, $showBillableRate, $roundingType, $roundingMinutes, $group3Type);
```

Extend key collection (lines 230-242) with `$keysGroup3 = [];` and, inside the group2 loop, collect third-level keys:

```php
if ($group2['grouped_data'] !== null) {
    foreach ($group2['grouped_data'] as $group3) {
        $keysGroup3[] = $group3['key'];
    }
}
```

Add `$descriptionMapGroup3 = $group3Type !== null ? $this->loadDescriptorsMap($keysGroup3, $group3Type) : [];` beside the group1/group2 maps.

In the enrichment loop (lines 247-258), after setting group2 description/color, add a nested loop over `$group2['grouped_data']` that sets each third-level node's `description` and `color` from `$descriptionMapGroup3`, mirroring the group2 assignment exactly (guard `!== null` on the nested `grouped_data`).

Update the method docblock (lines 201-224 and 260-283) to three levels with `description`/`color` at each.

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=TimeEntryAggregationServiceTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Service/TimeEntryAggregationService.php
git add app/Service/TimeEntryAggregationService.php tests/Unit/Service/TimeEntryAggregationServiceTest.php
git commit -m "feat(reporting): third-level descriptors in aggregation export data"
```

---

### Task 4: Controller wiring

**Files:**
- Modify: `app/Http/Controllers/Api/V1/TimeEntryController.php` (`aggregate` ~397-433, `aggregateExport` ~446-548)
- Test: covered by Task 5/6 export tests and a new aggregate happy-path feature test here.

**Interfaces:**
- Consumes: `getSubSubGroup()` (Task 1), three-level service methods (Tasks 2-3).

- [ ] **Step 1: Write the failing feature test**

Add to the aggregate feature test file:

```php
public function test_aggregate_returns_three_level_tree(): void
{
    $data = $this->createUserWithPermission(['time-entries:view:all']);
    $project = Project::factory()->forOrganization($data->organization)->create();
    TimeEntry::factory()->forMember($data->member)->forProject($project)->startWithDuration(now(), 60)->create(['description' => 'A']);

    $response = $this->actingAs($data->user)->getJson(route('api.v1.time-entries.aggregate', [
        'organization' => $data->organization->getKey(),
        'group' => 'user',
        'sub_group' => 'project',
        'sub_sub_group' => 'description',
    ]));

    $response->assertStatus(200);
    $response->assertJsonPath('data.grouped_type', 'user');
    $response->assertJsonPath('data.grouped_data.0.grouped_type', 'project');
    $response->assertJsonPath('data.grouped_data.0.grouped_data.0.grouped_type', 'description');
    $response->assertJsonPath('data.grouped_data.0.grouped_data.0.grouped_data.0.key', 'A');
}
```

Adjust factory helpers (`forMember`/`forProject`/`startWithDuration`) to whatever the suite uses — copy from a neighbouring aggregate test's arrange block.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=test_aggregate_returns_three_level_tree`
Expected: FAIL — `data.grouped_data.0.grouped_data.0.grouped_type` is `null` (third level not requested).

- [ ] **Step 3: Wire `aggregate()`**

After `$group2Type = $request->getSubGroup();` add `$group3Type = $request->getSubSubGroup();` and pass `$group3Type` as the trailing argument to `getAggregatedTimeEntries(...)`.

- [ ] **Step 4: Wire `aggregateExport()`**

After `$subGroup = $request->getSubGroup();` add `$subSubGroup = $request->getSubSubGroup();`. Pass `$subSubGroup` as the trailing arg to `getAggregatedTimeEntriesWithDescriptions(...)`. Add `'subSubGroup' => $subSubGroup,` to the PDF Blade payload array. Pass `$subSubGroup` as the new trailing constructor arg to `new TimeEntriesReportExport(...)` (see Task 5 for the constructor).

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test --filter=test_aggregate_returns_three_level_tree`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Http/Controllers/Api/V1/TimeEntryController.php
git add app/Http/Controllers/Api/V1/TimeEntryController.php tests/
git commit -m "feat(reporting): pass sub_sub_group through aggregate controllers"
```

---

### Task 5: Spreadsheet export (xlsx/csv/ods) third column

**Files:**
- Modify: `app/Service/ReportExport/TimeEntriesReportExport.php`
- Modify: `resources/views/reports/time-entry-aggregate/spreadsheet.blade.php`
- Test: `tests/Feature/...` export test (search for existing `TimeEntriesReportExport` / aggregate export test; create `tests/Unit/ReportExport/TimeEntriesReportExportThreeLevelTest.php` if none)

**Interfaces:**
- Consumes: three-level `$data` from Task 3; `$subSubGroup` from Task 4.
- Produces: `new TimeEntriesReportExport(array $data, ExportFormat $exportFormat, string $currency, TimeEntryAggregationType $group, TimeEntryAggregationType $subGroup, bool $showBillableRate, ?TimeEntryAggregationType $subSubGroup = null)`.

- [ ] **Step 1: Write the failing test**

Add a test that renders the export view with three-level data and asserts the third dimension's header (`$subSubGroup->description()`) and a third-level row value appear. Use `view('reports.time-entry-aggregate.spreadsheet', [...])->render()` and `assertStringContainsString`. Construct minimal three-level `$data` inline (an array literal matching the service output shape with `description` keys).

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=ThreeLevel`
Expected: FAIL — third column/rows absent.

- [ ] **Step 3: Extend the exporter class**

Add `private ?TimeEntryAggregationType $subSubGroup;`, append `?TimeEntryAggregationType $subSubGroup = null` to the constructor, assign it, and pass `'subSubGroup' => $this->subSubGroup,` into the `view(...)` payload. Update both `@var`/`@param` docblocks to three levels.

- [ ] **Step 4: Extend the blade**

In `spreadsheet.blade.php`:
- After the `sub_group` `<th>` (line ~16), add a conditional third header:

```blade
@if($subSubGroup)
    <th style="border: 1px solid black; font-weight: bold;" data-type="{{ DataType::TYPE_STRING }}">
        {{ $subSubGroup->description() }}
    </th>
@endif
```

- Replace the two-level `@foreach($data['grouped_data'] as $group1Entry) @foreach($group1Entry['grouped_data'] as $group2Entry)` body with a structure that, when `$subSubGroup` is set, iterates a third loop and emits a row per leaf with three label cells; when not set, keeps today's two-cell behaviour. Extract the duration/decimal/amount cells (unchanged) to run off the deepest node in each branch. Guard every `['grouped_data']` access with `?? []`.
- The label cell for the third column mirrors the existing `$group2Entry` label logic (`billable` → Yes/No, else `description ?? key ?? '-'`), reading from the third-level node.
- **Column shift:** the Total-row `=SUM(C2:C{{ $counter }})` formulas assume the Duration column is `C`. With the extra label column present, Duration moves to `D`, decimal to `E`, amount to `F`. Make the column letters dynamic: compute a base offset (`$subSubGroup ? 1 : 0`) and build the letters (e.g. `chr(ord('C') + $offset)`), or branch the three formula cells on `$subSubGroup`. Ensure `$counter`/`$totalDuration` still increment once per emitted leaf row.

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test --filter=ThreeLevel`
Expected: PASS.

- [ ] **Step 6: Regression — two-level export still correct**

Run the existing export test suite: `php artisan test --filter=Export`
Expected: PASS (two-level unchanged).

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Service/ReportExport/TimeEntriesReportExport.php
git add app/Service/ReportExport/TimeEntriesReportExport.php resources/views/reports/time-entry-aggregate/spreadsheet.blade.php tests/
git commit -m "feat(reporting): third grouping column in spreadsheet export"
```

---

### Task 6: PDF export third level

**Files:**
- Modify: `resources/views/reports/time-entry-aggregate/pdf.blade.php`
- Test: reuse `aggregateExport` with `debug=true` (returns HTML) — add a feature test asserting third-level content in the returned `html`.

**Interfaces:**
- Consumes: `$subSubGroup` + three-level `$aggregatedData` from Task 4.

- [ ] **Step 1: Write the failing test**

Add a feature test that calls the export route with `format=pdf`, `debug=true`, `group=user`, `sub_group=project`, `sub_sub_group=description`, and asserts `$response->json('html')` contains the third-level description text and the project name. (Debug mode returns JSON `{html, footer_html}` without needing Gotenberg — see controller lines 554-559.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=test_export_pdf_three_level`
Expected: FAIL — third-level rows not rendered.

- [ ] **Step 3: Implement**

Read the grouped-table section of `pdf.blade.php` (the part iterating `$aggregatedData['grouped_data']` and its `grouped_data`). Add a third nested iteration rendered only when `$subSubGroup` is set, with deeper indentation and the same label resolution (`description ?? key`, `billable` → Yes/No). Leave the KPI header and ECharts history chart sections unchanged. Guard nested `grouped_data` with `?? []`.

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=test_export_pdf_three_level`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/reports/time-entry-aggregate/pdf.blade.php tests/
git commit -m "feat(reporting): render third grouping level in PDF export"
```

---

### Task 7: API zod client — `sub_sub_group` param

**Files:**
- Modify: `resources/js/packages/api/src/openapi.json.client.ts` (aggregate endpoint params ~line 4284; export endpoint params — search the second occurrence of `name: 'sub_group'`)
- Test: none (generated client); TS type flows automatically into `AggregatedTimeEntriesQueryParams`.

**Interfaces:**
- Produces: `sub_sub_group` accepted on `getAggregatedTimeEntries` and `exportAggregatedTimeEntries` query params; `AggregatedTimeEntriesQueryParams` (in `resources/js/packages/api/src/index.ts:81`) gains it via `ZodiosQueryParamsByAlias`.

- [ ] **Step 1: Add the param to the aggregate endpoint**

Directly after the `sub_group` param object in the `getAggregatedTimeEntries` endpoint, add (note: enum omits `tag`):

```ts
{
    name: 'sub_sub_group',
    type: 'Query',
    schema: z
        .enum([
            'day',
            'week',
            'month',
            'year',
            'user',
            'project',
            'task',
            'client',
            'billable',
            'description',
        ])
        .optional(),
},
```

- [ ] **Step 2: Add the same param to the export endpoint**

Find the `exportAggregatedTimeEntries` operation's `sub_group` param and add the identical `sub_sub_group` object after it.

- [ ] **Step 3: Verify types compile**

Run: `npx vue-tsc --noEmit`
Expected: EXIT 0, no `error TS`.

- [ ] **Step 4: Commit**

```bash
npx prettier --write resources/js/packages/api/src/openapi.json.client.ts
git add resources/js/packages/api/src/openapi.json.client.ts
git commit -m "feat(reporting): add sub_sub_group to aggregate API client"
```

> Note: this file is normally regenerated by `npm run zod:generate` from the backend OpenAPI JSON (requires a running server). We edit it directly to mirror the Task 1 backend param; a later regeneration will reproduce the same param from the request annotation.

---

### Task 8: Overview page — third select + recursive table data

**Files:**
- Modify: `resources/js/Components/Common/Reporting/ReportingOverview.vue`
- Modify: `resources/js/utils/useReporting.ts` (add a tag-filtered option helper if needed)
- Test: `resources/js/utils/useReporting.test.ts` + a component-level test if a harness exists (else cover the pure logic in a small extracted helper)

**Interfaces:**
- Consumes: `GroupingOption` (from `useReporting.ts`), `tableQueryParams` shape.
- Produces: reactive `subSubGroup` storage; recursive `tableData` builder; a third `ReportingGroupBySelect` in the template.

- [ ] **Step 1: Write the failing test for recursive tableData + collision**

The recursion and collision rules are pure functions — extract them so they are unit-testable. In `useReporting.ts` add and export:

```ts
export function nextDistinctOption(
    taken: (GroupingOption | null)[],
    options: GroupingOption[]
): GroupingOption | undefined {
    return options.find((o) => !taken.includes(o));
}
```

In `useReporting.test.ts` add:

```ts
import { nextDistinctOption } from './useReporting';

describe('nextDistinctOption', () => {
    it('returns the first option not already taken', () => {
        expect(nextDistinctOption(['user', 'project'], ['user', 'project', 'task'])).toBe('task');
    });
    it('returns undefined when all options are taken', () => {
        expect(nextDistinctOption(['user', 'project'], ['user', 'project'])).toBeUndefined();
    });
});
```

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run resources/js/utils/useReporting.test.ts`
Expected: FAIL — `nextDistinctOption` not exported.

- [ ] **Step 3: Implement `nextDistinctOption`**

Add the function shown above to `useReporting.ts` and export it.

- [ ] **Step 4: Run to verify pass**

Run: `npx vitest run resources/js/utils/useReporting.test.ts`
Expected: PASS.

- [ ] **Step 5: Wire the Overview page**

In `ReportingOverview.vue`:
- Add storage: `const subSubGroup = useStorage<GroupingOption | null>('reporting-sub-sub-group', null);`
- Replace the two-way collision watcher (lines 92-104) with one keeping all three distinct and clearing `subSubGroup` when `subGroup` is unset or when tag is chosen at level 1/2:

```ts
watch(
    [group, subGroup],
    () => {
        const taken: (GroupingOption | null)[] = [group.value];
        if (subGroup.value === group.value) {
            subGroup.value = nextDistinctOption(taken, groupByOptions.map((o) => o.value)) ?? subGroup.value;
        }
        taken.push(subGroup.value);
        // Tag anywhere in the first two levels disables the third level entirely.
        const tagInUpperLevels = group.value === 'tag' || subGroup.value === 'tag';
        if (!subGroup.value || tagInUpperLevels) {
            subSubGroup.value = null;
        } else if (subSubGroup.value && taken.includes(subSubGroup.value)) {
            subSubGroup.value = nextDistinctOption([...taken, 'tag'], groupByOptions.map((o) => o.value)) ?? null;
        }
    },
    { immediate: true }
);
```

- Extend `tableQueryParams` (lines 143-149):

```ts
const tableQueryParams = computed<AggregatedTimeEntriesQueryParams>(() => {
    return {
        ...filterParams.value,
        group: group.value,
        sub_group: subGroup.value,
        sub_sub_group: subSubGroup.value ?? undefined,
    };
});
```

- Replace the two-level `tableData` (lines 283-302) with a recursive mapper:

```ts
type TableRow = {
    seconds: number;
    cost: number | null;
    description: string | null | undefined;
    grouped_data: TableRow[];
};

function mapGroupedData(
    entries: AggregatedTimeEntries['grouped_data'],
    type: string | null
): TableRow[] {
    return (
        entries?.map((entry) => ({
            seconds: entry.seconds,
            cost: entry.cost,
            description: getNameForReportingRowEntry(entry.key, type),
            grouped_data: mapGroupedData(entry.grouped_data ?? null, entry.grouped_type ?? null),
        })) ?? []
    );
}

const tableData = computed<TableRow[] | undefined>(() => {
    const root = aggregatedTableTimeEntries.value;
    return root ? mapGroupedData(root.grouped_data ?? null, root.grouped_type ?? null) : undefined;
});
```

- In the template, after the second `ReportingGroupBySelect` (the "and [select]" at line ~451), add a third, shown only when a second level and no tag are selected:

```vue
<template v-if="subGroup && group !== 'tag' && subGroup !== 'tag'">
    and
    <ReportingGroupBySelect
        v-model="subSubGroup"
        :options="groupByOptions.filter((o) => o.value !== 'tag' && o.value !== group && o.value !== subGroup)" />
</template>
```

Confirm `ReportingGroupBySelect` accepts `null` as a value; if it requires a non-null model, wrap with a computed that maps `null`↔a sentinel, or extend the component to allow clearing. If the component cannot represent "no third group", add an explicit "None" option to the third select's options and treat it as `null` in `tableQueryParams`.

- [ ] **Step 6: Typecheck + lint**

Run: `npx vue-tsc --noEmit && npx eslint resources/js/Components/Common/Reporting/ReportingOverview.vue resources/js/utils/useReporting.ts`
Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
npx prettier --write resources/js/Components/Common/Reporting/ReportingOverview.vue resources/js/utils/useReporting.ts
git add resources/js/Components/Common/Reporting/ReportingOverview.vue resources/js/utils/useReporting.ts resources/js/utils/useReporting.test.ts
git commit -m "feat(reporting): third group-by select and recursive table data"
```

---

### Task 9: Manual verification + full suites

**Files:** none (verification task).

- [ ] **Step 1: Full backend suite**

Run: `php artisan test --filter=TimeEntry`
Expected: PASS.

- [ ] **Step 2: Full JS unit suite for reporting**

Run: `npx vitest run resources/js/utils/useReporting.test.ts`
Expected: PASS.

- [ ] **Step 3: Typecheck whole frontend**

Run: `npx vue-tsc --noEmit`
Expected: EXIT 0.

- [ ] **Step 4: Manual smoke (dev loop)**

Start the dev loop (`bin/dev.sh`), open Reporting → Overview, pick Members → Day → Description, expand a member then a day, confirm the third level renders. Export xlsx and PDF; confirm three columns / three indented levels. Note: backend changes require a fresh PHP container per the project's dev-loop caveat.

- [ ] **Step 5: Final commit if any fixups**

```bash
git add -A
git commit -m "test(reporting): verify three-level grouping end-to-end"
```

---

## Self-Review

**Spec coverage:**
- `sub_sub_group` validation (no-tag, requires sub_group) → Task 1 ✓
- 3-level `getAggregatedTimeEntries` → Task 2 ✓
- 3-level descriptors → Task 3 ✓
- Controller wiring (aggregate + export + PDF payload + exporter ctor) → Task 4 ✓
- Spreadsheet export third column + formula shift → Task 5 ✓
- PDF export third level → Task 6 ✓
- API zod client + TS type → Task 7 ✓
- Overview third select + recursive tableData + ReportingRow (no change) → Task 8 ✓
- fillGaps untouched / regression → covered by Task 2 Step 6 (existing gap tests stay green); table path passes `fillGaps=false`.
- Tests (unit/feature/export/front) → Tasks 1-8 ✓

**Placeholder scan:** no TBD/TODO; every code step shows code. The illustrative `required_with:__never__` is explicitly removed in the same step.

**Type consistency:** `getSubSubGroup(): ?TimeEntryAggregationType` (Task 1) consumed in Task 4; trailing `?TimeEntryAggregationType $group3Type = null` consistent across Tasks 2-3; `TimeEntriesReportExport` ctor trailing `?TimeEntryAggregationType $subSubGroup = null` defined Task 5, called Task 4 (Task 5 lands the ctor before Task 4's call is exercised by tests — if executing strictly in order, Task 4 Step 4 references the new ctor arg; run Task 5 before Task 4's export path is tested, or land the ctor signature as part of Task 4. Recommended order: 1 → 2 → 3 → 5 → 4 → 6 → 7 → 8 → 9).

**Note on task order:** execute **Task 5 before Task 4** so the `TimeEntriesReportExport` constructor accepts `$subSubGroup` when Task 4 wires it. All other tasks follow numeric order.
