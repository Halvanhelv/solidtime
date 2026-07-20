import { describe, expect, it, vi } from 'vitest';
import { usePage } from '@inertiajs/vue3';
import { HIDEABLE_NAV_ITEMS, useNavVisibility } from './navVisibility';

vi.mock('@inertiajs/vue3', () => ({
    usePage: vi.fn(),
}));

const mockedUsePage = vi.mocked(usePage);

function mockPage(hiddenNavItems?: string[], userPresent = true) {
    mockedUsePage.mockReturnValue({
        props: {
            auth: {
                user: userPresent ? { hidden_nav_items: hiddenNavItems } : undefined,
            },
        },
    } as unknown as ReturnType<typeof usePage>);
}

describe('useNavVisibility', () => {
    it('treats everything as visible when hidden_nav_items is empty', () => {
        mockPage([]);
        const { isVisible } = useNavVisibility();
        expect(isVisible('tags')).toBe(true);
    });

    it('treats everything as visible when the key/user is absent', () => {
        mockPage(undefined);
        const { isVisible } = useNavVisibility();
        expect(isVisible('tags')).toBe(true);
    });

    it('hides only the items listed in hidden_nav_items', () => {
        mockPage(['calendar']);
        const { isHidden, isVisible } = useNavVisibility();
        expect(isHidden('calendar')).toBe(true);
        expect(isVisible('calendar')).toBe(false);
        expect(isVisible('tags')).toBe(true);
    });

    it('is undefined-safe when auth.user itself is undefined', () => {
        mockedUsePage.mockReturnValue({
            props: { auth: { user: undefined } },
        } as unknown as ReturnType<typeof usePage>);
        const { isVisible, isHidden } = useNavVisibility();
        expect(() => isVisible('tags')).not.toThrow();
        expect(isVisible('tags')).toBe(true);
        expect(isHidden('tags')).toBe(false);
    });

    it('keeps HIDEABLE_NAV_ITEMS in sync with the backend enum', () => {
        expect([...HIDEABLE_NAV_ITEMS]).toEqual([
            'projects',
            'members',
            'calendar',
            'timesheet',
            'tags',
            'dashboard_billable_widgets',
            'time',
            'clients',
            'import',
            'reporting_shared',
        ]);
    });
});
