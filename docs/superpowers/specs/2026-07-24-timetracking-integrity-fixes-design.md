# Time-tracking integrity fixes — design

**Date:** 2026-07-24
**Status:** approved (design), pending implementation
**Context:** Manual QA (`solidtime-checks.md`) plus code + live-browser verification surfaced 8 defects
in the time-entry flow. Points 7 (collapsed group span) and 8a (Enter does not start timer) were
**refuted** — the code already behaves correctly — and are out of scope. This spec covers the 8
confirmed defects.

## Goals

Make time-entry data trustworthy enough to bill from: no silent absurd durations, no accidental
double-counting, no destructive one-click deletion, no lost edits on validation failure, honest
sub-minute display, no empty-template pollution, and a non-error "no active timer" signal.

## Non-goals

- Reworking the whole time-entry form.
- Changing the grouped/collapsed duration display (point 7 — already correct).
- Changing timer start-on-Enter (point 8a — already works).
- Unrelated refactoring.

---

## Fix 1 — Duration upper bound (threshold + confirm)

**Defect:** Duration accepts `9999h`; the form silently recomputes end to 2027 and keeps Save
enabled; the server accepts it. No cap anywhere.

**Design — two independent layers:**

- **UX threshold + confirm (frontend).** When the computed duration exceeds **24h**, the edit modal
  (`resources/js/packages/ui/src/TimeEntry/TimeEntryEditModal.vue`) shows an inline warning and
  requires an explicit confirmation (a "Verify long entry" checkbox) before the Save/`Update Time
  Entry` button enables. Under 24h: unchanged (silent). The threshold constant lives in the modal.
- **Absolute hard cap (backend abuse ceiling).** A config value
  `config('time.max_duration_hours')` (default **168** = 7 days) drives a validation rule in both
  `app/Http/Requests/V1/TimeEntry/TimeEntryStoreRequest.php` and `TimeEntryUpdateRequest.php`:
  reject when `end - start > max_duration_hours`. This kills 417-day / 9999h regardless of the UI
  and is enforced on the API directly. Error message: field-bound on `end`.

The two layers are intentionally different numbers: 24h is a "did you really mean this?" nudge; 168h
is a hard "this is not a real single entry" ceiling.

**Tests:** FormRequest unit test (reject > cap, accept ≤ cap, boundary). Frontend: button stays
disabled until confirm when duration > 24h.

## Fix 2 — Duplicate shifts the interval

**Defect:** Duplicate clones onto the exact same `start`/`end`; two entries occupy one interval, day
total doubles. Overlap validation exists but is off by default; we do **not** change the org
setting.

**Design:** In `resources/js/packages/ui/src/TimeEntry/TimeEntryGroupedTable.vue` (the
`:duplicate-time-entry` wiring at lines ~175 and ~219), route duplication through a new
`duplicateTimeEntry(entry)` that creates the copy shifted to *after* the original:

- `newStart = entry.end`
- `newEnd = entry.end + (entry.end - entry.start)` (preserve duration)

All other fields copied as today. A running entry (`end === null`) is duplicated as-is (no shift —
nothing to anchor to); acceptable edge case.

**Tests:** unit test on the shift helper (start/end math, duration preserved, running-entry
passthrough).

## Fix 3 — Delete confirmation

**Defect:** Row-menu Delete, bulk delete, and the modal's red Delete all fire instantly; only a
toast, no confirm, no undo.

**Design:** Add a small reusable `ConfirmDialog.vue` built on the existing
`resources/js/packages/ui/src/DialogModal.vue`. Props: title, message, confirm-label, destructive
flag. Wire it in front of the three delete paths:

- row-menu Delete (`TimeEntryRow` / `TimeEntryGroupedTable`)
- bulk `deleteSelected()` (`resources/js/Pages/Time.vue`) — message includes the count
- red Delete in `TimeEntryEditModal.vue`

Copy: singular/plural "Delete N time entr(y/ies)? This cannot be undone." No undo mechanism (out of
scope); the dialog is the safeguard.

**Tests:** component test — confirm invokes the delete callback, cancel does not.

## Fix 4 — Client-side time validation, keep edits on failure

**Defect:** end < start yields a negative duration with Save still enabled; on server reject the
modal closes (edits lost) and the raw backend string appears as a corner toast unbound to any field.

**Design (`TimeEntryEditModal.vue` + `TimeEntryRow.vue`):**

- `computed isTimeRangeInvalid = end < start`. When true: disable Save and render a field-bound
  error under the End input ("End must be after start"). No toast needed for this case.
- Fix the fire-and-forget close: `TimeEntryRow.vue:116-119` `handleUpdateTimeEntry` currently calls
  `props.updateTimeEntry(updatedEntry)` without awaiting, then closes unconditionally. Change to
  `await` the mutation and close **only on success**; on rejection keep the modal open with the
  typed values intact. The 422 toast path (`resources/js/utils/notification.ts`) stays as a
  fallback but should rarely trigger now.

**Tests:** component test — Save disabled when end<start; modal stays open when the update promise
rejects; closes when it resolves.

## Fix 5 — Honest sub-minute display

**Defect:** A ~40s entry renders `0h 00min` in its row while still counting toward the day sum, so
the row contradicts the total.

**Design:** In `resources/js/packages/ui/src/utils/time.ts` `formatHumanReadableDuration`, for the
`default` and `hours-minutes` formats, when `0 < duration < 60` return `"<1min"` instead of
`"0h 00min"`. Colon-separated and decimal formats already show finer/whole values and are left
unchanged. This keeps the aggregate math (raw seconds summed then formatted) but stops a non-zero
entry from reading as an exact zero.

**Tests:** unit test — 40s → `"<1min"`; 0s → `"0h 00min"`; 60s → `"0h 01min"`; 90s → `"0h 01min"`.

## Fix 6 — No empty-entry pollution

**Defect:** An entry with no description, no project, zero duration saves silently and then appears
in "Recently Tracked Time Entries" as a reusable "No Description / No Project" template.

**Design (frontend only):**

- **Filter the dropdown.** In `resources/js/packages/ui/src/TimeTracker/TimeTrackerControls.vue`
  `filteredRecentlyTrackedTimeEntries` (~lines 135-163), drop items that have an empty description
  **and** no project **and** no task (nothing to reuse).
- **Do not create an empty start+stop.** When the timer is started and immediately stopped with no
  description, no project/task, and ~zero duration, skip creating the entry (guard in the
  start/stop path). A genuine short entry with a description or project is still created.

Zero-duration entries that carry a description/project are still allowed (legit "marker" entries).
No backend change.

**Tests:** unit test on the filter predicate (empty item excluded, described/projected item kept).

## Fix 8b — "No active timer" is not an error

**Defect:** `GET /api/v1/users/me/time-entries/active` returns **404** when no timer runs.

**Design:** In `app/Http/Controllers/Api/V1/UserTimeEntryController::myActive`, when no active entry
exists return **`204 No Content`** instead of throwing `ModelNotFoundException`. When an entry
exists, unchanged (`200` + `TimeEntryResource`). Update:

- frontend `resources/js/utils/useCurrentTimeEntry.ts` (and the Pinia fetch) to treat 204 as
  "no active timer" (null), not an error;
- affected tests expecting 404;
- `openapi.json` for the endpoint's responses.

**Contract change** — noted explicitly. 204 is chosen over `200 {data:null}` per decision.

## Fix 8c — De-duplicate fetches

**Defect:** The active-timer endpoint is called multiple times back-to-back per load; the
time-entries list is fetched via several distinct callers.

**Design (scoped, low-risk):** In the active-timer Pinia store, share the in-flight promise so
concurrent `getMyActiveTimeEntry` calls collapse into one request (return the pending promise if a
fetch is already running). Remove the clearly redundant mount-time callers (init + AppLayout +
TimeTracker all fetch on mount) so a single load triggers one active fetch. The list-query
deduplication (distinct TanStack keys) is **not** in scope for this pass — noted as follow-up.

**Tests:** unit test — two concurrent store fetches issue one underlying API call.

---

## Rollout / verification

- One branch, Conventional Commits per fix.
- Backend tests via `bin/dev-php.sh` (phpunit + phpstan + pint); frontend via `npm`/`npx vitest`.
- After green: rebuild the app image and run migrations (config default only; the 168h cap needs no
  migration), then live-verify each fix in the browser on the local stand.

## Risks

- **8b contract change** may affect external API consumers expecting 404. Mitigated: 204 is a
  standard "no content" and the only in-repo consumer is the SPA, updated here.
- **Fix 1 hard cap** could reject legitimate multi-day imported entries longer than 7 days. 168h is
  configurable; raise the config if such data is expected.
- **Fix 6 empty-start guard** must not swallow legitimate very short described entries — guard keys
  on empty description AND no project/task AND ~zero duration only.
