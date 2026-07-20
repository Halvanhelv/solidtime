<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { User } from '@/types/models';
import TimezoneMismatchModal from '@/packages/ui/src/TimezoneMismatchModal.vue';
import { useUpdateUserMutation } from '@/utils/useUserQuery';

const show = defineModel('show', { default: false });

const page = usePage<{
    auth: {
        user: User;
    };
}>();

const updateUser = useUpdateUserMutation();
const saving = updateUser.isPending;

async function handleUpdate(timezone: string) {
    try {
        await updateUser.mutateAsync({
            userId: page.props.auth.user.id,
            body: { timezone },
        });
        show.value = false;
        location.reload();
    } catch {
        // error toast handled by the mutation
    }
}
</script>

<template>
    <TimezoneMismatchModal v-model:show="show" :saving="saving" @update="handleUpdate" />
</template>

<style scoped></style>
