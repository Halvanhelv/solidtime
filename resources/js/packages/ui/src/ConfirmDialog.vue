<script setup lang="ts">
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';

withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        message?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        destructive?: boolean;
    }>(),
    {
        message: '',
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        destructive: false,
    }
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();
</script>

<template>
    <DialogModal :show="show" max-width="md" @close="emit('cancel')">
        <template #title>{{ title }}</template>
        <template #content>
            <span v-if="message">{{ message }}</span>
        </template>
        <template #footer>
            <SecondaryButton data-testid="confirm-cancel-button" @click="emit('cancel')">
                {{ cancelLabel }}
            </SecondaryButton>
            <PrimaryButton
                data-testid="confirm-confirm-button"
                class="ml-3"
                :class="{
                    'bg-red-600 hover:bg-red-700 border-red-600 hover:border-red-700 text-white':
                        destructive,
                }"
                @click="emit('confirm')">
                {{ confirmLabel }}
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
