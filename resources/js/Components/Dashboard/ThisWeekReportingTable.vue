<script setup lang="ts">
import ReportingRow from '@/Components/Common/Reporting/ReportingRow.vue';
import ReportingGroupBySelect from '@/Components/Common/Reporting/ReportingGroupBySelect.vue';
import {
    formatReportingDuration,
    getDayJsInstance,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';
import { formatCents } from '@/packages/ui/src/utils/money';
import { getOrganizationCurrencyString } from '@/utils/money';
import {
    type GroupingOption,
    isTerminalGroupOption,
    nextDistinctOption,
    useReportingStore,
} from '@/utils/useReporting';
import { getCurrentMembershipId, getCurrentRole } from '@/utils/useUser';
import {
    type AggregatedTimeEntries,
    type AggregatedTimeEntriesQueryParams,
    type Organization,
} from '@/packages/api/src';
import { useAggregatedTimeEntriesQuery } from '@/utils/useAggregatedTimeEntriesQuery';
import { useStorage } from '@vueuse/core';
import { computed, inject, type ComputedRef, watch } from 'vue';

const organization = inject<ComputedRef<Organization>>('organization');

const group = useStorage<GroupingOption>('dashboard-reporting-group', 'project');
// vueuse's useStorage removes the key and reverts to its default when the ref is
// set to null, which would make the "(None)" state impossible to keep for the
// sub-group selects. Persist a sentinel string instead and expose a null-based
// computed at the boundary so the rest of the component keeps using `null`.
const SUB_GROUP_NONE = '__none__';
const subGroupStorage = useStorage<GroupingOption | typeof SUB_GROUP_NONE>(
    'dashboard-reporting-sub-group',
    'task'
);
const subSubGroupStorage = useStorage<GroupingOption | typeof SUB_GROUP_NONE>(
    'dashboard-reporting-sub-sub-group',
    SUB_GROUP_NONE
);
const subGroup = computed<GroupingOption | null>({
    get: () => (subGroupStorage.value === SUB_GROUP_NONE ? null : subGroupStorage.value),
    set: (value) => {
        subGroupStorage.value = value ?? SUB_GROUP_NONE;
    },
});
const subSubGroup = computed<GroupingOption | null>({
    get: () => (subSubGroupStorage.value === SUB_GROUP_NONE ? null : subSubGroupStorage.value),
    set: (value) => {
        subSubGroupStorage.value = value ?? SUB_GROUP_NONE;
    },
});

const reportingStore = useReportingStore();
const { groupByOptions, getNameForReportingRowEntry } = reportingStore;

// Keep group, sub-group and sub-sub-group distinct, Clockify-style: slot 2 cannot
// exist under a terminal slot 1 (e.g. 'description'), and slot 3 cannot exist
// without slot 2 or under a terminal slot 2.
watch(
    [group, subGroup],
    () => {
        const options = groupByOptions.map((o) => o.value);
        if (isTerminalGroupOption(group.value)) {
            subGroup.value = null;
        } else if (subGroup.value === group.value) {
            subGroup.value = nextDistinctOption([group.value], options) ?? null;
        }
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

const weekStartUtc = computed(() => {
    return getLocalizedDayJs(getDayJsInstance()().format())
        .startOf('week')
        .startOf('day')
        .utc()
        .format();
});

const weekEndUtc = computed(() => {
    return getLocalizedDayJs(getDayJsInstance()().format()).endOf('day').utc().format();
});

const queryParams = computed<AggregatedTimeEntriesQueryParams>(() => {
    return {
        start: weekStartUtc.value,
        end: weekEndUtc.value,
        group: group.value,
        sub_group: subGroup.value ?? undefined,
        sub_sub_group: subSubGroup.value ?? undefined,
        member_id: getCurrentRole() === 'employee' ? getCurrentMembershipId() : undefined,
    };
});

// Shared helper keeps the previous result on screen while refetching (placeholderData
// + staleTime), so changing a grouping slot swaps the table in place instead of
// flashing the "Loading" / "No time entries" state between requests.
const { data: reportingResponse, isLoading } = useAggregatedTimeEntriesQuery(
    'dashboard-this-week',
    queryParams
);

const aggregatedTableTimeEntries = computed<AggregatedTimeEntries | null>(() => {
    return (reportingResponse.value?.data as AggregatedTimeEntries | undefined) ?? null;
});

// Structural (not the generated OpenAPI response) type: the response schema only
// spells out grouped_data recursion up to the depth that existed before the third
// grouping level, but the backend can now nest one level deeper. Declaring our own
// self-referential shape lets the mapper recurse to any depth while remaining
// structurally assignable from the generated response type.
interface GroupedDataEntry {
    key: string | null;
    seconds: number;
    cost: number | null;
    grouped_type?: string | null;
    grouped_data?: GroupedDataEntry[] | null;
}

type TableRow = {
    seconds: number;
    cost: number | null;
    description: string | null | undefined;
    grouped_data: TableRow[];
};

function mapGroupedData(
    entries: GroupedDataEntry[] | null | undefined,
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

const tableData = computed<TableRow[]>(() => {
    const root = aggregatedTableTimeEntries.value;
    return root ? mapGroupedData(root.grouped_data ?? null, root.grouped_type ?? null) : [];
});

const showBillableRate = computed(() => {
    return !!(
        getCurrentRole() !== 'employee' || organization?.value?.employees_can_see_billable_rates
    );
});
</script>

<template>
    <div class="rounded-lg bg-card-background border border-card-border">
        <div
            class="text-sm flex text-text-primary pt-3 items-center space-x-3 font-medium px-6 border-b border-card-background-separator pb-3">
            <span>Group by</span>
            <ReportingGroupBySelect
                v-model="group"
                :group-by-options="groupByOptions"></ReportingGroupBySelect>
            <template v-if="!isTerminalGroupOption(group)">
                <span>and</span>
                <ReportingGroupBySelect
                    v-model="subGroup"
                    allow-none
                    :group-by-options="
                        groupByOptions.filter(
                            (el) => el.value !== group && el.value !== subSubGroup
                        )
                    "></ReportingGroupBySelect>
            </template>
            <template v-if="subGroup && !isTerminalGroupOption(subGroup)">
                <span>and</span>
                <ReportingGroupBySelect
                    v-model="subSubGroup"
                    allow-none
                    :group-by-options="
                        groupByOptions.filter((el) => el.value !== group && el.value !== subGroup)
                    "></ReportingGroupBySelect>
            </template>
        </div>

        <div
            class="grid items-center"
            :style="`grid-template-columns: 1fr 100px ${showBillableRate ? '150px' : ''}`">
            <div
                class="contents [&>*]:border-card-background-separator [&>*]:border-b [&>*]:pb-1.5 [&>*]:pt-1 text-text-tertiary text-sm">
                <div class="pl-6">Name</div>
                <div class="text-right" :class="!showBillableRate ? 'pr-6' : ''">Duration</div>
                <div v-if="showBillableRate" class="text-right pr-6">Cost</div>
            </div>

            <div
                v-if="isLoading"
                class="flex justify-center py-10 text-text-tertiary"
                :class="showBillableRate ? 'col-span-3' : 'col-span-2'">
                Loading reporting data…
            </div>

            <template
                v-else-if="
                    aggregatedTableTimeEntries?.grouped_data &&
                    aggregatedTableTimeEntries.grouped_data?.length > 0
                ">
                <ReportingRow
                    v-for="entry in tableData"
                    :key="entry.description ?? 'none'"
                    :currency="getOrganizationCurrencyString()"
                    :show-cost="showBillableRate"
                    :entry="entry"></ReportingRow>
                <div class="contents [&>*]:transition text-text-tertiary [&>*]:h-[50px]">
                    <div class="flex items-center pl-6 font-medium">
                        <span>Total</span>
                    </div>
                    <div
                        class="justify-end flex items-center font-medium"
                        :class="!showBillableRate ? 'pr-6' : ''">
                        {{
                            formatReportingDuration(
                                aggregatedTableTimeEntries.seconds,
                                organization?.interval_format,
                                organization?.number_format
                            )
                        }}
                    </div>
                    <div
                        v-if="showBillableRate"
                        class="justify-end pr-6 flex items-center font-medium">
                        {{
                            aggregatedTableTimeEntries.cost
                                ? formatCents(
                                      aggregatedTableTimeEntries.cost,
                                      getOrganizationCurrencyString(),
                                      organization?.currency_format,
                                      organization?.currency_symbol,
                                      organization?.number_format
                                  )
                                : '--'
                        }}
                    </div>
                </div>
            </template>

            <div
                v-else
                class="chart flex flex-col items-center justify-center py-12"
                :class="showBillableRate ? 'col-span-3' : 'col-span-2'">
                <p class="text-lg text-text-primary font-medium">No time entries found</p>
                <p>Try to track some time entries this week</p>
            </div>
        </div>
    </div>
</template>

<style scoped></style>
