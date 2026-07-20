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
                calendar_enabled: true,
                timesheet_enabled: false,
                tags_enabled: true,
                dashboard_billable_widgets_enabled: true,
            },
        },
    }),
    useUpdateUserMutation: () => ({ mutateAsync, isPending: { value: false } }),
}));

describe('InterfacePreferencesForm', () => {
    it('seeds checkboxes from the user and submits changed flags', async () => {
        const wrapper = mount(InterfacePreferencesForm);
        // Timesheet starts disabled for this user
        const timesheet = wrapper.get('[data-testid="pref-timesheet_enabled"]');
        expect((timesheet.element as HTMLInputElement).checked).toBe(false);

        await wrapper.get('[data-testid="pref-save"]').trigger('click');
        expect(mutateAsync).toHaveBeenCalledWith(
            expect.objectContaining({
                userId: 'u1',
                body: expect.objectContaining({ timesheet_enabled: false }),
            })
        );
    });
});
