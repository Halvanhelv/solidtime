import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { computed, ref } from 'vue';
import { useNavVisibility } from '@/utils/navVisibility';
import {
    isTerminalGroupOption,
    nextDistinctOption,
    TERMINAL_GROUP_OPTIONS,
    useReportingStore,
} from './useReporting';

vi.mock('@/utils/navVisibility', () => ({
    useNavVisibility: vi.fn(),
}));
vi.mock('@/utils/useProjectsQuery', () => ({
    useProjectsQuery: () => ({ projects: ref([]) }),
}));
vi.mock('@/utils/useMembersQuery', () => ({
    useMembersQuery: () => ({ members: ref([]) }),
}));
vi.mock('@/utils/useTasksQuery', () => ({
    useTasksQuery: () => ({ tasks: ref([]) }),
}));
vi.mock('@/utils/useClientsQuery', () => ({
    useClientsQuery: () => ({ clients: ref([]) }),
}));
vi.mock('@/utils/useTagsQuery', () => ({
    useTagsQuery: () => ({ tags: ref([]) }),
}));

const mockedUseNavVisibility = vi.mocked(useNavVisibility);

function mockIsVisible(visible: boolean) {
    mockedUseNavVisibility.mockReturnValue({
        hidden: computed(() => []),
        isHidden: vi.fn(() => !visible),
        isVisible: vi.fn(() => visible),
    });
}

describe('useReportingStore groupByOptions', () => {
    it('excludes the tag option when tags are hidden', () => {
        mockIsVisible(false);
        setActivePinia(createPinia());
        const store = useReportingStore();
        expect(store.groupByOptions.some((option) => option.value === 'tag')).toBe(false);
    });

    it('includes the tag option when tags are visible', () => {
        mockIsVisible(true);
        setActivePinia(createPinia());
        const store = useReportingStore();
        expect(store.groupByOptions.some((option) => option.value === 'tag')).toBe(true);
    });

    it('offers day/week/month/year time-based group options', () => {
        mockIsVisible(true);
        setActivePinia(createPinia());
        const store = useReportingStore();
        const values = store.groupByOptions.map((option) => option.value);
        expect(values).toEqual(expect.arrayContaining(['day', 'week', 'month', 'year']));
    });
});

describe('useReportingStore getNameForReportingRowEntry time labels', () => {
    beforeEach(() => {
        mockIsVisible(true);
        setActivePinia(createPinia());
        // getDayJsInstance() reads the week-start setting off the window global.
        vi.stubGlobal('getWeekStartSetting', () => 'monday');
    });

    it('formats a day key as a readable date', () => {
        const store = useReportingStore();
        expect(store.getNameForReportingRowEntry('2024-01-15', 'day')).toBe('Mon, Jan 15, 2024');
    });

    it('formats a week key as "Week of ..."', () => {
        const store = useReportingStore();
        expect(store.getNameForReportingRowEntry('2024-01-15', 'week')).toBe(
            'Week of Jan 15, 2024'
        );
    });

    it('formats a month key as month + year', () => {
        const store = useReportingStore();
        expect(store.getNameForReportingRowEntry('2024-01', 'month')).toBe('January 2024');
    });

    it('returns a year key unchanged', () => {
        const store = useReportingStore();
        expect(store.getNameForReportingRowEntry('2024', 'year')).toBe('2024');
    });
});

describe('terminal group options', () => {
    it('marks description as terminal', () => {
        expect(isTerminalGroupOption('description')).toBe(true);
    });
    it('marks non-description dimensions as non-terminal', () => {
        expect(isTerminalGroupOption('user')).toBe(false);
        expect(isTerminalGroupOption('date' as never)).toBe(false);
        expect(isTerminalGroupOption(null)).toBe(false);
    });
    it('exposes description as the only terminal option', () => {
        expect(TERMINAL_GROUP_OPTIONS).toEqual(['description']);
    });
});

describe('nextDistinctOption', () => {
    it('returns the first option not already taken', () => {
        expect(nextDistinctOption(['user', 'project'], ['user', 'project', 'task'])).toBe('task');
    });
    it('returns undefined when all options are taken', () => {
        expect(nextDistinctOption(['user', 'project'], ['user', 'project'])).toBeUndefined();
    });
});
