import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TimeEntryRow from './TimeEntryRow.vue';
import TimeEntryRowTagDropdown from './TimeEntryRowTagDropdown.vue';
import type { Client, Project, Tag, Task, TimeEntry } from '@/packages/api/src';

const timeEntry = {
    id: 'time-entry-1',
    description: 'Working on tests',
    tags: [] as string[],
    billable: false,
    start: '2026-01-01T10:00:00Z',
    end: '2026-01-01T11:00:00Z',
    project_id: null,
    task_id: null,
    user_id: 'user-1',
} as unknown as TimeEntry;

function mountRow(props: Record<string, unknown> = {}) {
    return mount(TimeEntryRow, {
        props: {
            timeEntry,
            projects: [] as Project[],
            tasks: [] as Task[],
            tags: [] as Tag[],
            clients: [] as Client[],
            createTag: vi.fn(),
            createProject: vi.fn(),
            createClient: vi.fn(),
            onStartStopClick: vi.fn(),
            deleteTimeEntry: vi.fn(),
            updateTimeEntry: vi.fn(),
            currency: 'EUR',
            organizationBillableRate: null,
            canCreateProject: false,
            enableEstimatedTime: false,
            ...props,
        },
        global: {
            stubs: {
                TimeTrackerProjectTaskDropdown: true,
                TimeEntryRangeSelector: true,
                TimeEntryDescriptionInput: true,
                TimeEntryRowDurationInput: true,
                TimeEntryMoreOptionsDropdown: true,
                TimeEntryEditModal: true,
                BillableToggleButton: true,
                TimeTrackerStartStop: true,
                Checkbox: true,
                // TimeEntryRowTagDropdown's own heavy child - keep the wrapper real, stub what it renders internally
                TagDropdown: true,
            },
        },
    });
}

describe('TimeEntryRow', () => {
    it('hides the tag dropdown when tagsEnabled is false', () => {
        const wrapper = mountRow({ tagsEnabled: false });

        expect(wrapper.findComponent(TimeEntryRowTagDropdown).exists()).toBe(false);
    });

    it('shows the tag dropdown by default (tagsEnabled omitted)', () => {
        const wrapper = mountRow();

        expect(wrapper.findComponent(TimeEntryRowTagDropdown).exists()).toBe(true);
    });
});
