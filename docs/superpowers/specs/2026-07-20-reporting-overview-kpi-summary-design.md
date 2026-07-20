# Reporting Overview — KPI summary strip (Total / Billable / Amount)

**Date:** 2026-07-20
**Status:** Approved (design)
**Scope:** Frontend-only. One file: `resources/js/Components/Common/Reporting/ReportingOverview.vue`.

## Problem

The Clockify-style Summary report on `/reporting` (the "Overview" tab) already has
the per-day bar chart, group-by (incl. User + Description), grouped table, and pie
chart. What it lacks — visible in the Clockify reference — is a **KPI summary strip**
above the chart showing three period totals side by side:

- **Total** — total tracked duration across the filtered set
- **Billable** — billable-only duration within the same filtered set
- **Amount** — billable cost (money)

Today the Overview shows Total duration + Cost only as a **row at the bottom of the
grouped table**, and has no separate "Billable duration" figure. The Detailed report
(`/reporting/detailed`) already added an equivalent Total + Billable strip
(commits `5c2ed061`, `64fea9cb`); this brings the Overview to parity and adds Amount.

## Non-goals

- No backend change. `group:'billable'` aggregation already exists and is covered by
  `tests/Unit/Service/TimeEntryAggregationServiceTest.php`.
- No API/OpenAPI/zod change.
- No shared component extraction / refactor of `ReportingDetailed.vue`. The strip is
  inline in Overview, mirroring how Detailed did it (accepting a small, contained
  duplication of the Total/Billable markup rather than refactoring working code).
- No new "Weekly" tab, no default group change.

## Data

Reuse existing Overview state; add exactly one aggregate query.

### Total + Amount — already present
`aggregatedTableTimeEntries` (computed, line 158) is the current table aggregation
respecting all filter-bar filters. Its top-level fields give both KPIs for free:

- `Total`  = `aggregatedTableTimeEntries.seconds`
- `Amount` = `aggregatedTableTimeEntries.cost` (nullable). Non-billable entries
  contribute 0 to cost (their `billable_rate` is null), so this already equals the
  billable amount.

### Billable duration — one new query (mirror Detailed lines 205-220)
Fire a second aggregation over the **same filter params** with `group: 'billable'`.
The service splits the filtered set into two buckets keyed `'0'` (non-billable) and
`'1'` (billable) in a single call. Read the `'1'` bucket's `seconds`.

This is preferred over forcing `billable=true` on a plain query, which would silently
return 0 whenever the user's own billable filter is set to `false`. `group:'billable'`
composes correctly with any billable filter state:
- filter = `true`  → only bucket `'1'` present → Billable == Total
- filter = `false` → only bucket `'0'` present → Billable == 0
- filter unset     → both buckets → Billable == the billable subset

Implementation (in `ReportingOverview.vue` script):

```ts
// Reuse the existing filterParams (all filter-bar filters, no group/sub_group).
const billableSummaryParams = computed<AggregatedTimeEntriesQueryParams>(() => ({
    ...filterParams.value,
    group: 'billable',
}));
const { data: billableSummaryResponse } = useAggregatedTimeEntriesQuery(
    'summary-billable',
    billableSummaryParams
);
const billableSeconds = computed(() => {
    const groups = billableSummaryResponse?.value?.data?.grouped_data ?? [];
    return groups.find((group) => group.key === '1')?.seconds ?? 0;
});
```

Notes:
- `filterParams` (line 118) already excludes group/sub_group and holds start/end +
  member/project/task/client/tag/billable/rounding. Top-level `seconds` is
  grouping-independent, so a minimal `group:'billable'` is enough.
- `useAggregatedTimeEntriesQuery(prefix, params)` — first arg is the query-key prefix;
  use a distinct `'summary-billable'` so it doesn't collide with `'graph'`/`'table'`.
- Response shape is `{ data: AggregatedTimeEntries }` (hence `.value?.data?.grouped_data`),
  matching `aggregateResponse?.value?.data` usage in Detailed.

## UI

Insert an inline strip **between the filter bar and the chart** — after
`</ReportingFilterBar>` (line 378), before the chart `MainContainer` (line 379) —
matching where Clockify places it.

Three KPIs, left-aligned, labels muted + values emphasized. Reuse existing formatters
and org format settings already in scope (`organization` inject line 84,
`showBillableRate` line 86, `formatReportingDuration`, `formatCents`,
`getOrganizationCurrencyString`).

```vue
<MainContainer
    class="py-3 border-b border-default-background-separator flex flex-wrap gap-x-8 gap-y-2 items-center">
    <span class="text-sm text-text-secondary">
        Total:
        <span class="font-medium text-text-primary">{{
            formatReportingDuration(
                aggregatedTableTimeEntries?.seconds ?? 0,
                organization?.interval_format,
                organization?.number_format
            )
        }}</span>
    </span>
    <span class="text-sm text-text-secondary">
        Billable:
        <span class="font-medium text-text-primary">{{
            formatReportingDuration(
                billableSeconds,
                organization?.interval_format,
                organization?.number_format
            )
        }}</span>
    </span>
    <span v-if="showBillableRate" class="text-sm text-text-secondary">
        Amount:
        <span class="font-medium text-text-primary">{{
            aggregatedTableTimeEntries?.cost
                ? formatCents(
                      aggregatedTableTimeEntries.cost,
                      getOrganizationCurrencyString(),
                      organization?.currency_format,
                      organization?.currency_symbol,
                      organization?.number_format
                  )
                : formatCents(0, getOrganizationCurrencyString(), organization?.currency_format, organization?.currency_symbol, organization?.number_format)
        }}</span>
    </span>
</MainContainer>
```

Decisions:
- **Amount gated on `showBillableRate`** — same rule the grouped table's Cost column
  uses (lines 408-411), so users without rate-visibility permission never see money.
- **Amount always renders a value when shown** (0 → `USD0.00`), matching Clockify
  (screenshot shows `USD0.00`), rather than the table's `--` placeholder.
- Duration formatting via `formatReportingDuration` with `organization.interval_format`
  + `number_format`, identical to the table Total row (lines 429-455) and the Detailed
  strip.
- `organization` is `inject<ComputedRef<Organization>>` — access as `organization?.…`
  exactly as the existing table Total row does.

## Loading / empty states

- While the billable query is pending, `billableSeconds` is `0` (nullish fallback);
  values fill in when data arrives. No extra skeleton — the KPI reads 0 briefly, same
  as the Detailed strip. Acceptable for a lightweight header.
- Empty filtered set → Total 0, Billable 0, Amount `USD0.00`. Coherent.

## Testing

- **No new backend test** — no backend change; `group:'billable'` bucketing is already
  asserted in `TimeEntryAggregationServiceTest`.
- **No component mount test** — mounting `ReportingOverview` requires mocking many
  queries (graph/table/billable/org/members/projects/…); Detailed added its equivalent
  strip without one. Verification is by `vue-tsc --noEmit` (types) + live check through
  the dev-loop (`bin/dev.sh`) on `http://localhost:8000/reporting`: confirm the three
  KPIs render, Billable ≤ Total, Amount matches the table Cost total, and Amount hides
  for a member without rate-visibility.
- If a regression guard is later wanted, extract a presentational `ReportingSummary.vue`
  (props: `totalSeconds`, `billableSeconds`, `cost`, `showAmount`, format fields) and
  unit-test that in isolation — out of scope here.

## Risks

- **Extra request per filter change:** one more aggregate call alongside graph+table.
  Small, cached by TanStack Query (30s staleTime), same pattern as Detailed. Acceptable.
- **Markup duplication with Detailed strip:** intentional; avoids refactoring working
  code. Revisit only if a third consumer appears.

## Files touched

- `resources/js/Components/Common/Reporting/ReportingOverview.vue` — add billable
  summary query + `billableSeconds` computed (script) and the KPI strip (template).
