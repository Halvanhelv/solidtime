import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import TimeEntryEditModal from './TimeEntryEditModal.vue';
import TagDropdown from '@/packages/ui/src/Tag/TagDropdown.vue';
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
} as unknown as TimeEntry;

function mountModal(props: Record<string, unknown> = {}) {
    return mount(TimeEntryEditModal, {
        props: {
            show: true,
            timeEntry,
            enableEstimatedTime: false,
            updateTimeEntry: vi.fn(),
            deleteTimeEntry: vi.fn(),
            createClient: vi.fn(),
            createProject: vi.fn(),
            createTag: vi.fn(),
            tags: [] as Tag[],
            projects: [] as Project[],
            tasks: [] as Task[],
            clients: [] as Client[],
            currency: 'EUR',
            organizationBillableRate: null,
            canCreateProject: false,
            ...props,
        },
        global: {
            stubs: {
                TimeTrackerProjectTaskDropdown: true,
                DurationHumanInput: true,
                TimePickerSimple: true,
                DatePicker: true,
            },
        },
    });
}

describe('TimeEntryEditModal', () => {
    it('hides the tag dropdown when tagsEnabled is false', async () => {
        const wrapper = mountModal({ tagsEnabled: false });
        await nextTick();
        await nextTick();

        expect(wrapper.findComponent(TagDropdown).exists()).toBe(false);
    });

    it('shows the tag dropdown by default (tagsEnabled omitted)', async () => {
        const wrapper = mountModal();
        await nextTick();
        await nextTick();

        expect(wrapper.findComponent(TagDropdown).exists()).toBe(true);
    });
});
