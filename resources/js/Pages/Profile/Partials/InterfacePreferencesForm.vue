<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import FormSection from '@/Components/FormSection.vue';
import { Field, FieldLabel, FieldDescription } from '@/packages/ui/src/field';
import { Checkbox } from '@/packages/ui/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import ActionMessage from '@/Components/ActionMessage.vue';
import { useUserQuery, useUpdateUserMutation } from '@/utils/useUserQuery';
import { HIDEABLE_NAV_ITEMS, type HideableNavItem } from '@/utils/navVisibility';

const { user } = useUserQuery();
const updateUser = useUpdateUserMutation();

const rows: { key: HideableNavItem; label: string; description: string }[] = [
    { key: 'time', label: 'Time', description: 'Show the Time tracker in the sidebar.' },
    { key: 'projects', label: 'Projects', description: 'Show Projects in the sidebar.' },
    { key: 'members', label: 'Members', description: 'Show Members in the sidebar.' },
    { key: 'calendar', label: 'Calendar', description: 'Show Calendar in the sidebar.' },
    { key: 'timesheet', label: 'Timesheet', description: 'Show Timesheet in the sidebar.' },
    { key: 'clients', label: 'Clients', description: 'Show Clients in the sidebar.' },
    {
        key: 'import',
        label: 'Import / Export',
        description: 'Show Import / Export in the sidebar.',
    },
    {
        key: 'tags',
        label: 'Tags',
        description: 'Show the Tags section, tag pickers, and tag reporting.',
    },
    {
        key: 'reporting_shared',
        label: 'Shared reports',
        description: 'Show the Shared tab in Reporting.',
    },
    {
        key: 'dashboard_billable_widgets',
        label: 'Billable widgets',
        description: 'Show Billable Time / Billable Amount on the dashboard.',
    },
];

// Checkbox state = "visible" (checked = shown). We persist the inverse: the set
// of hidden keys. Default every item visible until the user's prefs load.
const visible = reactive<Record<HideableNavItem, boolean>>(
    Object.fromEntries(HIDEABLE_NAV_ITEMS.map((key) => [key, true])) as Record<
        HideableNavItem,
        boolean
    >
);

let seeded = false;
watch(
    () => user.value,
    (u) => {
        if (u && !seeded) {
            const hidden = u.hidden_nav_items ?? [];
            for (const key of HIDEABLE_NAV_ITEMS) {
                visible[key] = !hidden.includes(key);
            }
            seeded = true;
        }
    },
    { immediate: true }
);

const recentlySaved = ref(false);

async function save() {
    if (!user.value) return;
    const hidden_nav_items = HIDEABLE_NAV_ITEMS.filter((key) => !visible[key]);
    try {
        await updateUser.mutateAsync({ userId: user.value.id, body: { hidden_nav_items } });
        // Refresh Inertia auth.user so the sidebar reflects the change immediately.
        router.reload({ only: ['auth'] });
        recentlySaved.value = true;
        setTimeout(() => (recentlySaved.value = false), 2000);
    } catch {
        // toast handled by the mutation
    }
}
</script>

<template>
    <FormSection @submitted="save">
        <template #title>Sidebar &amp; features</template>
        <template #description>
            Hide features you don't use. This only affects your own view.
        </template>
        <template #form>
            <Field
                v-for="row in rows"
                :key="row.key"
                class="col-span-6 sm:col-span-4"
                orientation="horizontal">
                <Checkbox
                    :id="`pref-${row.key}`"
                    v-model:checked="visible[row.key]"
                    :data-testid="`pref-${row.key}`" />
                <div>
                    <FieldLabel :for="`pref-${row.key}`">{{ row.label }}</FieldLabel>
                    <FieldDescription>{{ row.description }}</FieldDescription>
                </div>
            </Field>
        </template>
        <template #actions>
            <ActionMessage :on="recentlySaved" class="me-3">Saved.</ActionMessage>
            <PrimaryButton
                data-testid="pref-save"
                :class="{ 'opacity-25': updateUser.isPending.value }"
                :disabled="updateUser.isPending.value"
                @click.prevent="save">
                Save
            </PrimaryButton>
        </template>
    </FormSection>
</template>
