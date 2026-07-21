import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import InterfacePreferencesForm from './InterfacePreferencesForm.vue';

const mutateAsync = vi.fn().mockResolvedValue({});
let hiddenNavItems: string[] = ['timesheet'];
vi.mock('@/utils/useUserQuery', () => ({
    useUserQuery: () => ({
        user: {
            get value() {
                return {
                    id: 'u1',
                    name: 'A',
                    email: 'a@b.c',
                    timezone: 'UTC',
                    week_start: 'monday',
                    hidden_nav_items: hiddenNavItems,
                };
            },
        },
    }),
    useUpdateUserMutation: () => ({ mutateAsync, isPending: { value: false } }),
}));

describe('InterfacePreferencesForm', () => {
    it('seeds checkboxes from hidden_nav_items and submits the hidden set', async () => {
        hiddenNavItems = ['timesheet'];
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

    it('seeds the projects and members rows correctly', () => {
        hiddenNavItems = ['members'];
        const wrapper = mount(InterfacePreferencesForm);
        const members = wrapper.get('[data-testid="pref-members"]');
        expect((members.element as HTMLInputElement).checked).toBe(false);
        const projects = wrapper.get('[data-testid="pref-projects"]');
        expect((projects.element as HTMLInputElement).checked).toBe(true);
    });

    it('round-trips a multi-item hidden set, toggling one back on before saving', async () => {
        mutateAsync.mockClear();
        hiddenNavItems = ['tags', 'import', 'members'];
        const wrapper = mount(InterfacePreferencesForm);

        // The three hidden items are unchecked; everything else stays checked.
        expect((wrapper.get('[data-testid="pref-tags"]').element as HTMLInputElement).checked).toBe(
            false
        );
        expect(
            (wrapper.get('[data-testid="pref-import"]').element as HTMLInputElement).checked
        ).toBe(false);
        expect(
            (wrapper.get('[data-testid="pref-members"]').element as HTMLInputElement).checked
        ).toBe(false);
        expect(
            (wrapper.get('[data-testid="pref-calendar"]').element as HTMLInputElement).checked
        ).toBe(true);

        // Re-enable "members" by checking it back on.
        await wrapper.get('[data-testid="pref-members"]').setValue(true);

        await wrapper.get('[data-testid="pref-save"]').trigger('click');
        expect(mutateAsync).toHaveBeenCalledWith(
            expect.objectContaining({
                userId: 'u1',
                body: { hidden_nav_items: expect.arrayContaining(['tags', 'import']) },
            })
        );
        const submittedHidden = mutateAsync.mock.calls.at(-1)?.[0].body.hidden_nav_items;
        expect(submittedHidden).not.toContain('members');
        expect(submittedHidden).toContain('tags');
        expect(submittedHidden).toContain('import');
    });
});
