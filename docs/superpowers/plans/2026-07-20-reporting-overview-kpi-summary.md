# Reporting Overview — KPI Summary Strip Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Total / Billable / Amount KPI strip above the chart on the `/reporting` Overview page.

**Architecture:** Frontend-only change to one Vue SFC. Total + Amount come from the existing table aggregation query already in the component; Billable duration comes from one new aggregation query using `group:'billable'` (bucket `'1'`), mirroring the proven pattern in `ReportingDetailed.vue`. An inline KPI strip renders the three figures between the filter bar and the chart.

**Tech Stack:** Vue 3 (`<script setup lang="ts">`), Inertia, TanStack Query via `useAggregatedTimeEntriesQuery`, existing `formatReportingDuration` / `formatCents` formatters, Vite dev-loop (`bin/dev.sh`) for live verification, `vue-tsc` for type checking.

## Global Constraints

- Frontend-only. No backend, API, OpenAPI, or zod-client changes.
- Touch exactly one file: `resources/js/Components/Common/Reporting/ReportingOverview.vue`.
- Do not modify `ReportingDetailed.vue` or extract a shared component (accept the small inline duplication).
- Money must never render for users without rate visibility: the Amount KPI is gated on the existing `showBillableRate` computed.
- Duration formatting must use `organization.interval_format` + `organization.number_format`, identical to the existing table Total row.
- Spec: `docs/superpowers/specs/2026-07-20-reporting-overview-kpi-summary-design.md`.

---

### Task 1: Billable summary query + KPI strip in ReportingOverview

**Files:**
- Modify: `resources/js/Components/Common/Reporting/ReportingOverview.vue`
  - script: insert after the `tableResponse` query line (currently line 152) and after the `aggregatedTableTimeEntries` computed (currently ends ~line 160)
  - template: insert after `</ReportingFilterBar>` (currently line 378), before the chart `MainContainer` (currently line 379)

**Interfaces:**
- Consumes (already defined in this file):
  - `filterParams: ComputedRef<AggregatedTimeEntriesQueryParams>` — base filters, no `group`/`sub_group` (line 118).
  - `aggregatedTableTimeEntries: ComputedRef<AggregatedTimeEntries | undefined>` — provides `.seconds` (Total) and `.cost` (Amount) (line 158).
  - `useAggregatedTimeEntriesQuery(prefix: string, params: ComputedRef<AggregatedTimeEntriesQueryParams>)` — returns `{ data }` where `data.value?.data` is `AggregatedTimeEntries` (imported line 58 equivalent; already used for `'graph'`/`'table'`).
  - `showBillableRate: ComputedRef<boolean>` (line 86).
  - `organization: ComputedRef<Organization> | undefined` via `inject` (line 84).
  - `formatReportingDuration`, `formatCents`, `getOrganizationCurrencyString` — already imported.
- Produces:
  - `billableSeconds: ComputedRef<number>` — billable-only duration in seconds for the current filters.

- [ ] **Step 1: Add the billable summary query and `billableSeconds` computed**

In `resources/js/Components/Common/Reporting/ReportingOverview.vue`, immediately after this existing line (currently 152):

```ts
const { data: tableResponse } = useAggregatedTimeEntriesQuery('table', tableQueryParams);
```

insert:

```ts
// Billable-only duration for the KPI strip. `group:'billable'` splits the same
// filtered set into buckets keyed '0' (non-billable) / '1' (billable) in a single
// call, which composes correctly with any billable filter state — unlike forcing
// `billable=true`, which would silently return 0 when the user's filter is 'false'.
const billableSummaryParams = computed<AggregatedTimeEntriesQueryParams>(() => ({
    ...filterParams.value,
    group: 'billable',
}));
const { data: billableSummaryResponse } = useAggregatedTimeEntriesQuery(
    'summary-billable',
    billableSummaryParams
);
const billableSeconds = computed<number>(() => {
    const groups = billableSummaryResponse.value?.data?.grouped_data ?? [];
    return groups.find((groupEntry) => groupEntry.key === '1')?.seconds ?? 0;
});
```

Note: `group: 'billable'` is a valid `TimeEntryAggregationType`. If TypeScript rejects the string literal on `AggregatedTimeEntriesQueryParams['group']`, mirror exactly how `ReportingDetailed.vue` (line ~211) types it — it uses the same literal without a cast, so no cast should be needed.

- [ ] **Step 2: Add the KPI strip to the template**

Find this block in the template (currently lines 366-379):

```vue
    <ReportingFilterBar
        v-model:selected-members="selectedMembers"
        v-model:selected-projects="selectedProjects"
        v-model:selected-tasks="selectedTasks"
        v-model:selected-clients="selectedClients"
        v-model:selected-tags="selectedTags"
        v-model:tag-match-type="tagMatchType"
        v-model:billable="billable"
        v-model:rounding-enabled="roundingEnabled"
        v-model:rounding-type="roundingType"
        v-model:rounding-minutes="roundingMinutes"
        v-model:start-date="startDate"
        v-model:end-date="endDate" />
    <MainContainer>
```

Insert a new `MainContainer` between `</ReportingFilterBar />` and the existing `<MainContainer>` that wraps the chart, so it reads:

```vue
        v-model:start-date="startDate"
        v-model:end-date="endDate" />
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
                formatCents(
                    aggregatedTableTimeEntries?.cost ?? 0,
                    getOrganizationCurrencyString(),
                    organization?.currency_format,
                    organization?.currency_symbol,
                    organization?.number_format
                )
            }}</span>
        </span>
    </MainContainer>
    <MainContainer>
```

Notes:
- `aggregatedTableTimeEntries?.cost ?? 0` renders `USD0.00` when cost is null/0, matching the Clockify reference (which shows `USD0.00`).
- `organization?.…` optional-chaining matches the existing table Total row (lines 429-455) — `organization` is an injected `ComputedRef` accessed the same way elsewhere in this template.

- [ ] **Step 3: Type-check the change**

Run: `npx vue-tsc --noEmit`
Expected: exit `0`, `0` errors. (Full-project check; ignore unrelated pre-existing output only if it was already failing — a clean tree should be 0.)

- [ ] **Step 4: Lint the changed file**

Run: `npx eslint resources/js/Components/Common/Reporting/ReportingOverview.vue`
Expected: `0 errors` (pre-existing warnings, if any, are acceptable — do not introduce new errors).

- [ ] **Step 5: Live verification through the dev-loop**

Ensure the dev-loop is running (`./bin/dev.sh`), then open `http://localhost:8000/reporting`. Confirm:
1. A KPI strip shows **Total**, **Billable**, and (for an admin/owner with rate visibility) **Amount**, above the bar chart.
2. `Billable ≤ Total` for any date range; with the billable filter set to `false` the Billable figure reads `0`; set to `true`, Billable equals Total.
3. **Amount** equals the Cost total shown at the bottom of the grouped table.
4. For a member without rate visibility (`showBillableRate` false), the **Amount** KPI is absent while Total and Billable still render.

There is no automated component test (mounting `ReportingOverview` needs many query mocks; the equivalent Detailed strip shipped without one — see spec "Testing"). This live check is the acceptance gate.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Common/Reporting/ReportingOverview.vue
git commit -m "feat(reporting): add Total/Billable/Amount KPI strip to Overview"
```

---

## Self-Review

**1. Spec coverage:**
- KPI strip Total/Billable/Amount above chart → Task 1 Step 2. ✓
- Total from `aggregatedTableTimeEntries.seconds`, Amount from `.cost` → Step 2. ✓
- Billable via `group:'billable'` bucket `'1'` → Step 1. ✓
- Amount gated on `showBillableRate` → Step 2 (`v-if`). ✓
- Amount always renders `USD0.00` when 0 → Step 2 (`?? 0`). ✓
- No backend/API change, one file → Global Constraints + single task. ✓
- No component test; verify via vue-tsc + dev-loop → Steps 3-5. ✓

**2. Placeholder scan:** No TBD/TODO; all code blocks contain real content. ✓

**3. Type consistency:** `billableSeconds` typed `ComputedRef<number>`, consumed in template as a number by `formatReportingDuration`. `billableSummaryParams` typed `AggregatedTimeEntriesQueryParams` like the existing `graphQueryParams`/`tableQueryParams`. Query prefix `'summary-billable'` is distinct from `'graph'`/`'table'`. Response access `billableSummaryResponse.value?.data?.grouped_data` matches the `useAggregatedTimeEntriesQuery` return shape used for `graphResponse`/`tableResponse`. ✓
