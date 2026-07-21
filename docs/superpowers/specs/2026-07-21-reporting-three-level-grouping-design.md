# Reporting: three-level group-by (User → Date → Description)

**Date:** 2026-07-21
**Status:** Approved design, pending spec review

## Problem

The Reporting → Overview table supports two levels of grouping (`group` +
`sub_group`), rendered as a nested, expandable pivot. Users need a third level
so a single view can show, for example, **User → Date → Description**: per
member, broken down by day, broken down by task/description — matching the
Clockify "Summary report" interactive tree.

Gap #1 (already shipped on branch `feat/reporting-time-group-by`) exposed
day/week/month/year as group options, enabling User→Date **or** User→Task. Gap
#2 removes the two-level ceiling so all three dimensions show at once.

## Scope

- **In:** a third grouping level (`sub_sub_group`) across the on-screen Overview
  table **and** all exports (PDF, Excel/xlsx, CSV, ODS).
- **In:** any pair/triple of the existing dimensions
  (user/project/task/client/billable/description/day/week/month/year).
- **Out (constraint):** `sub_sub_group` does **not** accept `tag`. Tag grouping
  uses a LATERAL-join expansion whose double-counting correction is only
  computed for level 1; extending it to level 3 (base totals per
  `(group_1, group_2)`) is deferred. Tag remains available at levels 1 and 2.
- **Out:** generic N-level aggregation. We add an explicit `group_3`, mirroring
  the existing `group_1`/`group_2` pattern, capped at three levels.

## Approach

Explicit third level (`group_3`), following the existing two-level code shape.
Chosen over a generic recursive rewrite because the aggregation service carries
correctness-sensitive logic (tag expansion, gap filling, billable-rate cost)
that a broad refactor would put at regression risk.

## Data flow

```
query params: group, sub_group, sub_sub_group
   → TimeEntryAggregateRequest (validation + getters)
   → TimeEntryController::aggregate / aggregateExport
   → TimeEntryAggregationService::getAggregatedTimeEntries($group1,$group2,$group3,…)
        builds  GROUP BY group_1, group_2, group_3
        returns 3-level nested { grouped_data → grouped_data → grouped_data }
   → screen: ReportingOverview.tableData (recursive) → ReportingRow (recursive)
   → export: getAggregatedTimeEntriesWithDescriptions → pdf/spreadsheet blades
```

## Backend changes

### `app/Enums/TimeEntryAggregationType.php`
No change — already includes every needed case (day/week/month/year plus
entity types). `sub_sub_group` validation reuses this enum with an added
`not_in:tag` guard.

### `app/Service/TimeEntryAggregationService.php`
- `getAggregatedTimeEntries(...)` gains `?TimeEntryAggregationType $group3Type`
  (inserted after `$group2Type`).
  - Compute `$group3Select` via existing `getGroupByQuery` when `$group3Type`
    is set **and** `$group2Type` is set (a third level requires a second).
  - Extend `selectRaw`, `groupBy` (`['group_1','group_2','group_3']`), and
    `orderBy` chains.
  - Extend the nested assembly: for each group_2 bucket, when `$group3Select`
    is set, build a `group_3` child array of leaf rows
    (`grouped_type: null, grouped_data: null`) and set the group_2 node's
    `grouped_type => $group3Type->value` and `grouped_data => $group3Response`;
    accumulate group_2 seconds/cost from its group_3 children.
  - Update the method's return-type docblock to three nesting levels.
- Tag handling: because `sub_sub_group` cannot be `tag`, the existing tag
  branches (`$group1Type`/`$group2Type` === Tag) are unchanged. Add an
  assertion/guard that `$group3Type !== Tag` to make the constraint explicit in
  code.
- `getAggregatedTimeEntriesWithDescriptions(...)` gains `$group3Type`; collect
  `keysGroup3`, load a `descriptionMapGroup3`, and set `description`/`color` on
  the third-level nodes (mirroring the group_2 loop). Update its return-type
  docblock.
- `fillGapsInTimeGroups(...)`: used only for the single-dimension history chart
  (`fill_gaps_in_time_groups=true`), which never sets `sub_group`/
  `sub_sub_group`. No third-level gap filling is required; verify the table path
  (which passes `fillGaps=false`) is unaffected and add a regression test.

### `app/Http/Requests/V1/TimeEntry/TimeEntryAggregateRequest.php`
- Add `sub_sub_group`: `nullable`, `Rule::enum(TimeEntryAggregationType::class)`,
  `Rule::notIn(['tag'])`, and `required_with`-style dependency is **not** used
  (it is optional) but validation must reject `sub_sub_group` present without
  `sub_group` — add a rule so a third level cannot exist without a second.
- Add `getSubSubGroup(): ?TimeEntryAggregationType`.
- Mirror all of the above in `TimeEntryAggregateExportRequest` (it extends /
  duplicates this request's rules).

### `app/Http/Controllers/Api/V1/TimeEntryController.php`
- `aggregate()` and `aggregateExport()`: read `$group3Type =
  $request->getSubSubGroup()` and pass it to the service calls.
- `aggregateExport()`: pass `$subSubGroup` into the PDF Blade payload and the
  `TimeEntriesReportExport` constructor.

### API schema / TS types
- `resources/js/packages/api/src/openapi.json.client.ts`: add a `sub_sub_group`
  query param (enum without `tag`) to the aggregate + aggregate-export
  endpoints.
- `AggregatedTimeEntriesQueryParams` (in `resources/js/packages/api/src`): add
  optional `sub_sub_group`.

## Frontend changes

### `resources/js/utils/useReporting.ts`
- No new group options (gap #1 already added date options). Add a helper to
  filter tag out of the **third** select's option list.

### `resources/js/Components/Common/Reporting/ReportingOverview.vue`
- Add `subSubGroup = useStorage<GroupingOption | null>('reporting-sub-sub-group', null)`.
- Extend the collision watcher to keep `group`, `subGroup`, `subSubGroup`
  mutually distinct; when `subGroup` is cleared, clear `subSubGroup` too.
- `tableQueryParams`: include `sub_sub_group: subSubGroup.value ?? undefined`.
- Rewrite `tableData` as a **recursive** mapper over `grouped_data` (currently
  two hand-written levels), threading each node's `grouped_type` so
  `getNameForReportingRowEntry(key, type)` resolves labels at every depth.
- Template: render a third "and [select]" `ReportingGroupBySelect`, shown only
  when `subGroup` is set; its options exclude `tag` and the two already-chosen
  dimensions.

### `resources/js/Components/Common/Reporting/ReportingRow.vue`
- No change. It already recurses on `entry.grouped_data`, so a third level
  renders and expands for free.

## Export changes

### `resources/views/reports/time-entry-aggregate/spreadsheet.blade.php`
- Add a third data column (header = `$subSubGroup?->description()`), shown only
  when `$subSubGroup` is present.
- The current loop assumes `group1Entry['grouped_data']` is non-null. Make the
  nesting tolerant: when `sub_sub_group` is set, iterate the third level; when a
  level is absent, fall back to the current behaviour. Guard against null
  `grouped_data` at each level.
- Extend total/formula column offsets to account for the extra column.

### `resources/views/reports/time-entry-aggregate/pdf.blade.php`
- Render the third nesting level in the grouped table section (indentation +
  labels), consistent with the on-screen tree. Chart/KPI sections unchanged.

### `app/Service/ReportExport/TimeEntriesReportExport.php`
- Constructor gains `?TimeEntryAggregationType $subSubGroup`; pass into the
  spreadsheet view. Update the data docblock to three levels.

## Testing

- **Unit** (`TimeEntryAggregationServiceTest`): three-level aggregation returns
  correctly nested seconds/cost; parent totals equal the sum of children at
  each level; `group_3` without `group_2` is ignored; billable-rate cost
  aggregates at level 3.
- **Feature** (aggregate endpoint): `sub_sub_group` accepted; produces
  three-level payload; `sub_sub_group=tag` is rejected (422);
  `sub_sub_group` without `sub_group` is rejected (422).
- **Feature** (aggregate-export): xlsx/csv/ods contain the third column and
  rows; PDF debug HTML contains third-level rows.
- **Frontend** (`useReporting.test.ts` + Overview): third select excludes tag +
  already-chosen dimensions; collision keeps three distinct values; clearing
  `subGroup` clears `subSubGroup`; recursive `tableData` builds three levels.

## Risks / notes

- Tag-at-level-3 is intentionally excluded; if a later need arises it is a
  self-contained follow-up (base totals per `(group_1, group_2)`).
- The spreadsheet total row uses column-letter formulas (`=SUM(C2:C…)`); the
  added column shifts these — cover with an export test to catch off-by-one.
- Employee role still restricted to own `member_id`; three-level grouping does
  not change permission checks.
