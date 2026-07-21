import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TimeEntryAggregateRow from './TimeEntryAggregateRow.vue';
import TimeEntryRowTagDropdown from './TimeEntryRowTagDropdown.vue';
import type { Client, Project, Tag, Task, TimeEntry } from '@/packages/api/src';
import type { TimeEntriesGroupedByType } from '@/types/time-entries';

const subEntry = {
    id: 'time-entry-1',
    description: 'Working on tests',
    tags: [] as string[],
    billable: false,
    start: '2026-01-01T10:00:00Z',
    end: '2026-01-01T11:00:00Z',
    project_id: null,
    task_id: null,
} as unknown as TimeEntry;

const groupedTimeEntry = {
    ...subEntry,
    duration: 3600,
    timeEntries: [subEntry],
} as unknown as TimeEntriesGroupedByType;

function mountRow(props: Record<string, unknown> = {}) {
    return mount(TimeEntryAggregateRow, {
        props: {
            timeEntry: groupedTimeEntry,
            projects: [] as Project[],
            tasks: [] as Task[],
            tags: [] as Tag[],
            clients: [] as Client[],
            createTag: vi.fn(),
            createProject: vi.fn(),
            createClient: vi.fn(),
            onStartStopClick: vi.fn(),
            duplicateTimeEntry: vi.fn(),
            updateTimeEntries: vi.fn(),
            updateTimeEntry: vi.fn(),
            deleteTimeEntries: vi.fn(),
            currency: 'EUR',
            organizationBillableRate: null,
            selectedTimeEntries: [] as TimeEntry[],
            enableEstimatedTime: false,
            canCreateProject: false,
            ...props,
        },
        global: {
            stubs: {
                TimeTrackerProjectTaskDropdown: true,
                TimeEntryDescriptionInput: true,
                TimeEntryMoreOptionsDropdown: true,
                BillableToggleButton: true,
                TimeTrackerStartStop: true,
                Checkbox: true,
                GroupedItemsCountButton: true,
                TimeEntryRow: true,
                TagDropdown: true,
            },
        },
    });
}

describe('TimeEntryAggregateRow', () => {
    it('hides the tag dropdown when tagsEnabled is false', () => {
        const wrapper = mountRow({ tagsEnabled: false });

        expect(wrapper.findComponent(TimeEntryRowTagDropdown).exists()).toBe(false);
    });

    it('shows the tag dropdown by default (tagsEnabled omitted)', () => {
        const wrapper = mountRow();

        expect(wrapper.findComponent(TimeEntryRowTagDropdown).exists()).toBe(true);
    });
});
