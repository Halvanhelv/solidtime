<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import FormSection from '@/Components/FormSection.vue';
import { Field, FieldLabel, FieldDescription } from '@/packages/ui/src/field';
import { Checkbox } from '@/packages/ui/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import ActionMessage from '@/Components/ActionMessage.vue';
import { useUserQuery, useUpdateUserMutation } from '@/utils/useUserQuery';

const { user } = useUserQuery();
const updateUser = useUpdateUserMutation();

const prefs = reactive({
    calendar_enabled: true,
    timesheet_enabled: true,
    tags_enabled: true,
    dashboard_billable_widgets_enabled: true,
});

let seeded = false;
watch(
    () => user.value,
    (u) => {
        if (u && !seeded) {
            prefs.calendar_enabled = u.calendar_enabled;
            prefs.timesheet_enabled = u.timesheet_enabled;
            prefs.tags_enabled = u.tags_enabled;
            prefs.dashboard_billable_widgets_enabled = u.dashboard_billable_widgets_enabled;
            seeded = true;
        }
    },
    { immediate: true }
);

const recentlySaved = ref(false);

async function save() {
    if (!user.value) return;
    try {
        await updateUser.mutateAsync({ userId: user.value.id, body: { ...prefs } });
        // Refresh Inertia auth.user so the sidebar reflects the change immediately.
        router.reload({ only: ['auth'] });
        recentlySaved.value = true;
        setTimeout(() => (recentlySaved.value = false), 2000);
    } catch {
        // toast handled by the mutation
    }
}

const rows: { key: keyof typeof prefs; label: string; description: string }[] = [
    { key: 'calendar_enabled', label: 'Calendar', description: 'Show Calendar in the sidebar.' },
    {
        key: 'timesheet_enabled',
        label: 'Timesheet',
        description: 'Show Timesheet in the sidebar.',
    },
    {
        key: 'tags_enabled',
        label: 'Tags',
        description: 'Show the Tags section, tag pickers, and tag reporting.',
    },
    {
        key: 'dashboard_billable_widgets_enabled',
        label: 'Billable widgets',
        description: 'Show Billable Time / Billable Amount on the dashboard.',
    },
];
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
                    v-model:checked="prefs[row.key]"
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
