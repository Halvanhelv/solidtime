import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Nav/UI surfaces a user can hide for themselves. Must stay in sync with the
 * backend enum App\Enums\HideableNavItem. Adding a new hideable surface means
 * adding its key here and a `v-if="isVisible('<key>')"` at the site — no
 * migration or backend change needed.
 */
export const HIDEABLE_NAV_ITEMS = [
    'calendar',
    'timesheet',
    'tags',
    'dashboard_billable_widgets',
    'time',
    'clients',
    'import',
    'reporting_shared',
] as const;

export type HideableNavItem = (typeof HIDEABLE_NAV_ITEMS)[number];

/**
 * Reads the current user's hidden-nav set from the Inertia `auth.user` shared
 * prop. Undefined-safe: on unauthenticated pages (e.g. public shared reports)
 * `auth.user` is empty, so nothing is hidden.
 */
export function useNavVisibility() {
    const page = usePage<{ auth: { user?: { hidden_nav_items?: string[] } } }>();
    const hidden = computed<string[]>(() => page.props.auth.user?.hidden_nav_items ?? []);
    const isHidden = (key: HideableNavItem) => hidden.value.includes(key);
    const isVisible = (key: HideableNavItem) => !hidden.value.includes(key);
    return { hidden, isHidden, isVisible };
}
