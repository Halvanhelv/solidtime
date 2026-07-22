import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils';
import { computed } from 'vue';
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';

const getAggregatedTimeEntries = vi.fn();

vi.mock('@/packages/api/src', () => ({
    api: {
        getAggregatedTimeEntries: (...args: unknown[]) => getAggregatedTimeEntries(...args),
    },
}));

vi.mock('@/utils/useUser', () => ({
    getCurrentRole: () => 'manager',
    getCurrentOrganizationId: () => 'org-1',
    getCurrentMembershipId: () => 'mem-1',
    getCurrentUser: () => ({ name: 'Me' }),
}));

vi.mock('@/utils/money', () => ({
    getOrganizationCurrencyString: () => 'USD',
}));

// Keep the real terminal/distinctness helpers; only stub the pinia store so we
// don't have to spin up every query composable it depends on.
vi.mock('@/utils/useReporting', async (importActual) => {
    const actual = await importActual<typeof import('@/utils/useReporting')>();
    return {
        ...actual,
        useReportingStore: () => ({
            groupByOptions: [
                { label: 'Members', value: 'user', icon: {} },
                { label: 'Projects', value: 'project', icon: {} },
                { label: 'Tasks', value: 'task', icon: {} },
                { label: 'Description', value: 'description', icon: {} },
            ],
            getNameForReportingRowEntry: (key: string | null, type: string | null) =>
                key ?? `no-${type}`,
        }),
    };
});

import ThisWeekReportingTable from './ThisWeekReportingTable.vue';

// Components stay subscribed to the shared localStorage keys until unmounted, so
// track and tear them down between tests to stop stale group state from leaking.
const mountedWrappers: VueWrapper[] = [];

function mountTable() {
    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });
    const wrapper = mount(ThisWeekReportingTable, {
        global: {
            plugins: [[VueQueryPlugin, { queryClient }]],
            provide: {
                organization: computed(() => ({
                    currency: 'USD',
                    interval_format: undefined,
                    number_format: undefined,
                })),
            },
        },
    });
    mountedWrappers.push(wrapper);
    return wrapper;
}

// The header renders one "and" separator before each visible sub-group select,
// so counting them is a direct proxy for how many grouping slots are shown.
function andSeparatorCount(wrapper: ReturnType<typeof mountTable>): number {
    return wrapper.findAll('span').filter((el) => el.text() === 'and').length;
}

function lastQueries() {
    return getAggregatedTimeEntries.mock.calls.at(-1)?.[0]?.queries;
}

describe('ThisWeekReportingTable grouping', () => {
    beforeEach(() => {
        getAggregatedTimeEntries.mockReset();
        getAggregatedTimeEntries.mockResolvedValue({
            data: { seconds: 0, cost: null, grouped_type: null, grouped_data: [] },
        });
        localStorage.clear();
    });

    afterEach(() => {
        while (mountedWrappers.length) {
            mountedWrappers.pop()?.unmount();
        }
    });

    it('shows a third grouping slot and queries all three levels', async () => {
        localStorage.setItem('dashboard-reporting-group', 'project');
        localStorage.setItem('dashboard-reporting-sub-group', 'user');
        localStorage.setItem('dashboard-reporting-sub-sub-group', 'description');

        const wrapper = mountTable();
        await flushPromises();

        expect(andSeparatorCount(wrapper)).toBe(2);
        expect(lastQueries()).toMatchObject({
            group: 'project',
            sub_group: 'user',
            sub_sub_group: 'description',
        });
        // Manager, not employee → no self-scoping member_id.
        expect(lastQueries()?.member_id).toBeUndefined();
    });

    it('keeps the (None) third level as a sentinel and omits sub_sub_group', async () => {
        localStorage.setItem('dashboard-reporting-group', 'project');
        localStorage.setItem('dashboard-reporting-sub-group', 'user');
        localStorage.setItem('dashboard-reporting-sub-sub-group', '__none__');

        const wrapper = mountTable();
        await flushPromises();

        // Slot 3 select is still shown (slot 2 is non-terminal) but resolves to None.
        expect(andSeparatorCount(wrapper)).toBe(2);
        expect(lastQueries()?.sub_group).toBe('user');
        expect(lastQueries()?.sub_sub_group).toBeUndefined();
    });

    it('collapses sub-slots and drops sub_sub_group under a terminal primary group', async () => {
        localStorage.setItem('dashboard-reporting-group', 'description');
        localStorage.setItem('dashboard-reporting-sub-group', 'user');
        localStorage.setItem('dashboard-reporting-sub-sub-group', 'task');

        const wrapper = mountTable();
        await flushPromises();

        // 'description' is terminal → no sub-group / sub-sub-group selects at all.
        expect(andSeparatorCount(wrapper)).toBe(0);
        expect(lastQueries()).toMatchObject({ group: 'description' });
        expect(lastQueries()?.sub_group).toBeUndefined();
        expect(lastQueries()?.sub_sub_group).toBeUndefined();
    });

    it('renders one row per top-level group with the total', async () => {
        localStorage.setItem('dashboard-reporting-group', 'project');
        localStorage.setItem('dashboard-reporting-sub-group', '__none__');
        getAggregatedTimeEntries.mockResolvedValue({
            data: {
                seconds: 3600,
                cost: null,
                grouped_type: 'project',
                grouped_data: [
                    { key: 'p1', seconds: 3600, cost: null, grouped_type: null, grouped_data: [] },
                ],
            },
        });

        const wrapper = mountTable();
        await flushPromises();

        expect(wrapper.text()).toContain('p1');
        expect(wrapper.text()).toContain('Total');
    });
});
