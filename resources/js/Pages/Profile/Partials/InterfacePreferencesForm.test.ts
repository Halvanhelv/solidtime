import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import InterfacePreferencesForm from './InterfacePreferencesForm.vue';

const mutateAsync = vi.fn().mockResolvedValue({});
vi.mock('@/utils/useUserQuery', () => ({
    useUserQuery: () => ({
        user: {
            value: {
                id: 'u1',
                name: 'A',
                email: 'a@b.c',
                timezone: 'UTC',
                week_start: 'monday',
                hidden_nav_items: ['timesheet'],
            },
        },
    }),
    useUpdateUserMutation: () => ({ mutateAsync, isPending: { value: false } }),
}));

describe('InterfacePreferencesForm', () => {
    it('seeds checkboxes from hidden_nav_items and submits the hidden set', async () => {
        const wrapper = mount(InterfacePreferencesForm);
        // Timesheet is hidden for this user → its "visible" checkbox is unchecked.
        const timesheet = wrapper.get('[data-testid="pref-timesheet"]');
        expect((timesheet.element as HTMLInputElement).checked).toBe(false);
        // Calendar is not hidden → checked.
        const calendar = wrapper.get('[data-testid="pref-calendar"]');
        expect((calendar.element as HTMLInputElement).checked).toBe(true);

        await wrapper.get('[data-testid="pref-save"]').trigger('click');
        expect(mutateAsync).toHaveBeenCalledWith(
            expect.objectContaining({
                userId: 'u1',
                body: { hidden_nav_items: ['timesheet'] },
            })
        );
    });
});
