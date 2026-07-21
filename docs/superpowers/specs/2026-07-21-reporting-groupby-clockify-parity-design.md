# Reporting group-by: Clockify parity (gap #2 revision)

**Date:** 2026-07-21
**Status:** Approved design, revision of `2026-07-21-reporting-three-level-grouping-design.md`
**Builds on:** local `main` (three-level grouping v1, merged commit a0a1957f)

## Why this revision

v1 shipped three-level grouping but **excluded Tag from level 3** (and forbade tag
anywhere when a third level was used) to sidestep the tag double-count problem.
User feedback + live investigation of Clockify's Summary report show that is wrong:
Tag must be allowed at **any** level, and the "3rd level not always available"
behavior follows a specific rule, not a tag restriction.

## Clockify group-by rules (verified live 2026-07-21)

Reference: [[clockify-groupby-rules]] memory.

- Dimensions offered: Project, Task, Client, User, Tag, Description, Month, Week, Date
  (Clockify's "Group" = user-groups; solidtime has no equivalent — omit).
- **Slot 1** is mandatory (no "(None)").
- **A deeper slot appears** (with "(None)" as its first option) only when the previous
  slot holds a **non-terminal** dimension. Max depth 3.
- **Terminal dimension = Description only.** Nothing nests under a description.
  Every other dimension (incl. Date/Month/Week/Tag/User/Billable) is non-terminal.
  Verified: `Project→Description` shows 2 slots; `Project→Task` and `Project→Date`
  each reveal a 3rd `(None)` slot.
- Setting a slot to "(None)" removes it and every deeper slot.
- Per-slot options = all dimensions minus those chosen in other slots.
  (Clockify also applies a Client>Project>Task hierarchy exclusion — **solidtime will
  NOT replicate this**; any field is allowed at any level per user decision.)
- **Tag is allowed at any level.**

## Scope of this revision

**In:**
1. Remove all tag-exclusion logic added in v1 (validation, service guard, zod enum,
   frontend filtering).
2. Correct tag double-counting when Tag is the **third** group level.
3. Frontend: dynamic slots matching Clockify — 2nd level optional, 3rd level appears
   only when the 2nd is set to a non-terminal dimension; Description is terminal;
   "(None)" clears a slot and deeper slots; options exclude only already-chosen
   dimensions (no hierarchy rule); Tag available at every level.

**Out:**
- Clockify's Client>Project>Task hierarchy exclusion (explicitly not replicated).
- Any change to the 2-level export byte-identity guarantees from v1 (still hold).
- N-level beyond 3.

## Design

### Terminal dimensions
Define a single terminal dimension: `description`. A slot at depth d renders the
slot at depth d+1 (d < 3) **only if** the depth-d value is set and is not terminal.
This governs slot 2 (shown when group set & non-terminal) and slot 3 (shown when
sub_group set & non-terminal).

### Backend — validation
`TimeEntryAggregateRequest` / `TimeEntryAggregateExportRequest`:
- `sub_sub_group`: remove `Rule::notIn(['tag'])` and the closure branch that rejects
  tag at group/sub_group. Keep only: `nullable`, `Rule::enum(...)`, and the
  "requires sub_group" check.
- Remove the plan-v1 service guard entirely (see below).

### Backend — service (`TimeEntryAggregationService::getAggregatedTimeEntries`)
- Remove the `$group3Type !== Tag` guard (v1 commit 87024a7f) and its test.
- Extend the tag double-count correction to cover **Tag at level 3**.

  Current behavior (unchanged for levels 1–2):
  - Tag as group_2 → recompute each group_1 total from a base (non-tag-expanded)
    query grouped by group_1 (`$baseTotalsPerGroup1Map`).
  - Tag anywhere → recompute the overall total from an un-grouped base query.

  New behavior for Tag as group_3:
  - Compute a base (non-tag-expanded) query grouped by **(group_1, group_2)** →
    `$baseTotalsPerGroup1Group2Map` keyed by `"$g1\x1f$g2"`.
  - Each group_2 node's seconds/cost = its base (g1,g2) total (not the sum of its
    tag children, which double-counts).
  - Each group_1 node's seconds/cost = sum of its group_2 base totals.
  - Overall total = existing tag-anywhere base recomputation (already present).

  The tag LATERAL-expansion cross-join condition must also fire when
  `group3Type === Tag` (today it checks only group_1/group_2). Extend the condition
  at the top of the method.

### Backend — zod client
Add `'tag'` back into the `sub_sub_group` enum for both `getAggregatedTimeEntries`
and `exportAggregatedTimeEntries` in `openapi.json.client.ts`. (Now the frontend may
send tag as sub_sub_group and the type must allow it → the `!== 'tag'` narrow in
`tableQueryParams` is removed.)

### Frontend (`ReportingOverview.vue` + `useReporting.ts`)
- Add `TERMINAL_GROUP_OPTIONS = ['description']` (exported from `useReporting.ts`).
- Slot visibility:
  - Slot 2 (`subGroup` select) shown always after slot 1 (slot 1 mandatory), BUT its
    presence in the query only when set; and if `group` is terminal, slot 2 is hidden
    and `subGroup`/`subSubGroup` cleared.
  - Slot 3 (`subSubGroup` select) shown only when `subGroup` is set and non-terminal;
    otherwise hidden and `subSubGroup` cleared.
- Both slot 2 and slot 3 selects include a "(None)" choice mapping to `null`
  (`ReportingGroupBySelect` already models `null`).
- Options per slot = all group-by options minus the dimensions chosen in the other
  two slots. **Tag is no longer filtered out** of any slot.
- `tableQueryParams`: send `sub_group`/`sub_sub_group` as their values or `undefined`
  when null; remove the `!== 'tag'` narrow (tag now valid).
- Collision watcher: keep the three slots mutually distinct; drop the tag-clearing
  branch; add terminal-clearing (clear `subGroup` if `group` terminal; clear
  `subSubGroup` if `subGroup` terminal or unset).

## Testing

- **Service unit:** Tag at level 3 (e.g. User→Project→Tag) — group_2 and group_1
  totals equal the non-expanded base sums (not inflated by multi-tag entries);
  tag leaves sum per-tag correctly; overall total correct. Add an entry with 2 tags
  to force the double-count path.
- **Validation:** `sub_sub_group=tag` now **accepted** (200, not 422);
  `group=tag`+`sub_sub_group` now accepted; `sub_sub_group` without `sub_group`
  still 422.
- **Frontend:** terminal rule (Description hides deeper slot); "(None)" clears deeper
  slots; options no longer exclude tag; three-way distinctness holds.
- Remove/replace the v1 tests that asserted tag rejection and the service guard throw.

## Risks
- Tag-at-level-3 double-count is the subtle part — the new per-(g1,g2) base map must
  key group values identically to the main query (same timezone/rounding selects).
  Cover with a multi-tag fixture so a regression surfaces as a wrong total.
- Removing the guard + rejection tests must be done together with the new acceptance
  tests so the suite never asserts contradictory behavior.
