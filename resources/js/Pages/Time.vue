<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import TimeTracker from '@/Components/TimeTracker.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import { storeToRefs } from 'pinia';
import type {
    CreateClientBody,
    CreateProjectBody,
    CreateTimeEntryBody,
    Project,
    TimeEntry,
    Client,
} from '@/packages/api/src';
import { useElementVisibility } from '@vueuse/core';
import { ClockIcon } from '@heroicons/vue/20/solid';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { groupSimilarTimeEntriesSetting } from '@/utils/timeEntryGrouping';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import TimeEntryGroupedTable from '@/packages/ui/src/TimeEntry/TimeEntryGroupedTable.vue';
import { useTagsQuery } from '@/utils/useTagsQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { getOrganizationCurrencyString } from '@/utils/money';
import TimeEntryMassActionRow from '@/packages/ui/src/TimeEntry/TimeEntryMassActionRow.vue';
import ConfirmDialog from '@/packages/ui/src/ConfirmDialog.vue';
import type { UpdateMultipleTimeEntriesChangeset } from '@/packages/api/src';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { canCreateProjects } from '@/utils/permissions';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useTagsStore } from '@/utils/useTags';
import { useProjectsStore } from '@/utils/useProjects';
import { useClientsStore } from '@/utils/useClients';
import { useTimeEntriesInfiniteQuery } from '@/utils/useTimeEntriesInfiniteQuery';
import { useTimeEntriesMutations } from '@/utils/useTimeEntriesMutations';

const page = usePage<{ auth: { user: { tags_enabled: boolean } } }>();

const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isPending } =
    useTimeEntriesInfiniteQuery();
const {
    createTimeEntry: createTimeEntryMutation,
    updateTimeEntry,
    updateTimeEntries: updateTimeEntriesMutation,
    deleteTimeEntries: deleteTimeEntriesMutation,
} = useTimeEntriesMutations();

const timeEntries = computed(() => data.value?.pages.flatMap((page) => page.data) || []);

async function updateTimeEntries(ids: string[], changes: UpdateMultipleTimeEntriesChangeset) {
    await updateTimeEntriesMutation({ ids, changes });
}

const loadMoreContainer = ref<HTMLDivElement | null>(null);
const isLoadMoreVisible = useElementVisibility(loadMoreContainer);
const currentTimeEntryStore = useCurrentTimeEntryStore();
const { currentTimeEntry } = storeToRefs(currentTimeEntryStore);
const { setActiveState } = currentTimeEntryStore;

async function startTimeEntry(timeEntry: Omit<CreateTimeEntryBody, 'member_id'>) {
    if (currentTimeEntry.value.id) {
        await setActiveState(false);
    }
    await createTimeEntryMutation(timeEntry);
    useCurrentTimeEntryStore().fetchCurrentTimeEntry();
}

const pendingDeleteEntries = ref<TimeEntry[]>([]);
const showDeleteConfirm = ref(false);

function deleteTimeEntries(timeEntries: TimeEntry[]) {
    if (timeEntries.length === 0) {
        return;
    }
    // Guard every delete path (row menu, edit modal, bulk) behind a confirmation.
    pendingDeleteEntries.value = timeEntries;
    showDeleteConfirm.value = true;
}

async function confirmDeleteTimeEntries() {
    if (pendingDeleteEntries.value.length > 0) {
        await deleteTimeEntriesMutation(pendingDeleteEntries.value);
    }
    showDeleteConfirm.value = false;
    pendingDeleteEntries.value = [];
    selectedTimeEntries.value = [];
}

function cancelDeleteTimeEntries() {
    showDeleteConfirm.value = false;
    pendingDeleteEntries.value = [];
}

const deleteConfirmMessage = computed(() => {
    const count = pendingDeleteEntries.value.length;
    return count === 1
        ? 'Delete this time entry? This cannot be undone.'
        : `Delete ${count} time entries? This cannot be undone.`;
});

watch(isLoadMoreVisible, async (isVisible) => {
    if (isVisible && hasNextPage.value) {
        await fetchNextPage();
    }
});

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { clients } = useClientsQuery();

const { tags } = useTagsQuery();

async function createTag(name: string) {
    return await useTagsStore().createTag(name);
}
async function createProject(project: CreateProjectBody): Promise<Project | undefined> {
    return await useProjectsStore().createProject(project);
}
async function createClient(body: CreateClientBody): Promise<Client | undefined> {
    return await useClientsStore().createClient(body);
}

const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);

const selectedTimeEntries = ref([] as TimeEntry[]);

async function clearSelectionAndState() {
    selectedTimeEntries.value = [];
}

function deleteSelected() {
    deleteTimeEntries(selectedTimeEntries.value);
}
</script>

<template>
    <AppLayout title="Dashboard" data-testid="time_view">
        <MainContainer class="pt-5 lg:pt-8 pb-4 lg:pb-6">
            <TimeTracker></TimeTracker>
        </MainContainer>
        <TimeEntryMassActionRow
            :selected-time-entries="selectedTimeEntries"
            :enable-estimated-time="isAllowedToPerformPremiumAction()"
            :can-create-project="canCreateProjects()"
            :all-selected="selectedTimeEntries.length === timeEntries.length"
            :delete-selected="deleteSelected"
            :projects="projects"
            :tasks="tasks"
            :tags="tags"
            :tags-enabled="page.props.auth.user.tags_enabled"
            :currency="getOrganizationCurrencyString()"
            :clients="clients"
            :organization-billable-rate="organization?.billable_rate ?? null"
            class="border-t border-default-background-separator hidden sm:block"
            :update-time-entries="
                (args) =>
                    updateTimeEntries(
                        selectedTimeEntries.map((timeEntry) => timeEntry.id),
                        args
                    )
            "
            :create-project="createProject"
            :create-client="createClient"
            :create-tag="createTag"
            @submit="clearSelectionAndState"
            @select-all="selectedTimeEntries = [...timeEntries]"
            @unselect-all="selectedTimeEntries = []"></TimeEntryMassActionRow>
        <TimeEntryGroupedTable
            v-model:selected="selectedTimeEntries"
            :create-project
            :enable-estimated-time="isAllowedToPerformPremiumAction()"
            :can-create-project="canCreateProjects()"
            :organization-billable-rate="organization?.billable_rate ?? null"
            :clients
            :create-client
            :update-time-entry
            :update-time-entries
            :delete-time-entries
            :create-time-entry="startTimeEntry"
            :create-tag
            :projects="projects"
            :tasks="tasks"
            :currency="getOrganizationCurrencyString()"
            :time-entries="timeEntries"
            :group-similar-time-entries="groupSimilarTimeEntriesSetting"
            :tags="tags"
            :tags-enabled="page.props.auth.user.tags_enabled"></TimeEntryGroupedTable>
        <div v-if="isPending" class="flex justify-center items-center py-12">
            <LoadingSpinner></LoadingSpinner>
        </div>
        <div v-else-if="timeEntries.length === 0" class="text-center pt-12">
            <ClockIcon class="w-8 text-icon-default inline pb-2"></ClockIcon>
            <h3 class="text-text-primary font-semibold">No time entries found</h3>
            <p class="pb-5">Create your first time entry now!</p>
        </div>
        <div ref="loadMoreContainer">
            <div
                v-if="isFetchingNextPage"
                class="flex justify-center items-center py-5 text-sm text-text-primary font-medium">
                <LoadingSpinner></LoadingSpinner>
                <span> Loading more time entries... </span>
            </div>
            <div
                v-else-if="!hasNextPage && timeEntries.length > 0"
                class="flex justify-center items-center py-5 text-sm text-text-tertiary">
                All time entries are loaded!
            </div>
        </div>
        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete time entries"
            :message="deleteConfirmMessage"
            confirm-label="Delete"
            destructive
            @confirm="confirmDeleteTimeEntries"
            @cancel="cancelDeleteTimeEntries"></ConfirmDialog>
    </AppLayout>
</template>
