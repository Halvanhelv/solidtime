import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import TimezoneMismatchModal from '@/packages/ui/src/TimezoneMismatchModal.vue';
import UserTimezoneMismatchModal from './UserTimezoneMismatchModal.vue';

const mutateAsync = vi.fn().mockResolvedValue({});
const isPending = ref(false);

vi.mock('@/utils/useUserQuery', () => ({
    useUpdateUserMutation: vi.fn(() => ({ mutateAsync, isPending })),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 'u1' } } } }),
}));

describe('UserTimezoneMismatchModal', () => {
    beforeEach(() => {
        mutateAsync.mockClear();
        mutateAsync.mockResolvedValue({});
        isPending.value = false;
        vi.stubGlobal('location', { reload: vi.fn() });
    });

    it('calls mutateAsync with the userId and timezone when the child emits update', async () => {
        const wrapper = mount(UserTimezoneMismatchModal, {
            props: { show: true },
        });

        await wrapper
            .findComponent(TimezoneMismatchModal)
            .vm.$emit('update', 'Europe/Kiev');

        expect(mutateAsync).toHaveBeenCalledWith({
            userId: 'u1',
            body: { timezone: 'Europe/Kiev' },
        });
    });

    it('closes the modal and reloads the page on success', async () => {
        const wrapper = mount(UserTimezoneMismatchModal, {
            props: { show: true },
        });

        await wrapper
            .findComponent(TimezoneMismatchModal)
            .vm.$emit('update', 'Europe/Kiev');
        await flushPromises();

        expect(wrapper.emitted('update:show')?.at(-1)).toEqual([false]);
        expect(location.reload).toHaveBeenCalled();
    });

    it('forwards isPending as the saving prop to the child', () => {
        isPending.value = true;
        const wrapper = mount(UserTimezoneMismatchModal, {
            props: { show: true },
        });

        expect(wrapper.findComponent(TimezoneMismatchModal).props('saving')).toBe(true);
    });

    it('keeps the modal open and does not throw when the mutation rejects', async () => {
        mutateAsync.mockRejectedValueOnce(new Error('failed'));
        const wrapper = mount(UserTimezoneMismatchModal, {
            props: { show: true },
        });

        wrapper.findComponent(TimezoneMismatchModal).vm.$emit('update', 'Europe/Kiev');
        await flushPromises();

        expect(wrapper.emitted('update:show')).toBeUndefined();
        expect(location.reload).not.toHaveBeenCalled();
    });
});

function flushPromises() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}
