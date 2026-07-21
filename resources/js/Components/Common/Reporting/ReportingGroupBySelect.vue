<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/packages/ui/src';
import { NoSymbolIcon } from '@heroicons/vue/20/solid';
import { type Component, computed } from 'vue';

// Reka-ui's SelectItem forbids an empty-string value (that value is reserved to
// clear the selection), so "(None)" is represented by this sentinel internally
// and translated to/from `null` at the model boundary.
const NONE_SENTINEL = '__none__';

const model = defineModel<string | null>({ default: null });
const props = withDefaults(
    defineProps<{
        groupByOptions: { value: string; label: string; icon: Component }[];
        // Slot 1 (the primary group) must not offer "(None)"; slots 2 and 3 do.
        allowNone?: boolean;
    }>(),
    {
        allowNone: false,
    }
);
const emit = defineEmits<{
    changed: [];
}>();

const noneOption = { value: NONE_SENTINEL, label: '(None)', icon: NoSymbolIcon };

const selectableOptions = computed(() =>
    props.allowNone ? [noneOption, ...props.groupByOptions] : props.groupByOptions
);

// The underlying Select always needs a non-empty string value, so map `null`
// (our "(None)" state) to the sentinel for the Select's own v-model.
const selectModel = computed<string>({
    get: () => model.value ?? NONE_SENTINEL,
    set: (value) => {
        model.value = value === NONE_SENTINEL ? null : value;
    },
});

const icon = computed(() => {
    if (model.value === null && props.allowNone) {
        return noneOption.icon;
    }
    return props.groupByOptions.find((option) => option.value === model.value)?.icon;
});
const title = computed(() => {
    if (model.value === null && props.allowNone) {
        return noneOption.label;
    }
    return props.groupByOptions.find((option) => option.value === model.value)?.label;
});
</script>

<template>
    <Select v-model="selectModel" @update:model-value="emit('changed')">
        <SelectTrigger size="sm" :show-chevron="false">
            <SelectValue class="flex items-center gap-2">
                <component :is="icon" class="h-4 text-icon-default" />
                <span>{{ title }}</span>
            </SelectValue>
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in selectableOptions"
                :key="option.value"
                :value="option.value">
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>

<style scoped></style>
