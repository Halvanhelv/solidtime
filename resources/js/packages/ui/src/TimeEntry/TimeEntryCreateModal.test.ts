import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import TimeEntryCreateModal from './TimeEntryCreateModal.vue';
import TagDropdown from '@/packages/ui/src/Tag/TagDropdown.vue';
import type { Client, Project, Tag, Task } from '@/packages/api/src';

function mountModal(props: Record<string, unknown> = {}) {
    return mount(TimeEntryCreateModal, {
        props: {
            show: true,
            enableEstimatedTime: false,
            createTimeEntry: vi.fn(),
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

describe('TimeEntryCreateModal', () => {
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
