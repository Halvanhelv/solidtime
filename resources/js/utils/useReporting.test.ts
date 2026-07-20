import { describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { computed, ref } from 'vue';
import { useNavVisibility } from '@/utils/navVisibility';
import { useReportingStore } from './useReporting';

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
});
