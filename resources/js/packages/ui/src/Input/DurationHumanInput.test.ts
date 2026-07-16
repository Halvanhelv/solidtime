import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import dayjs from 'dayjs';
import DurationHumanInput from './DurationHumanInput.vue';

describe('DurationHumanInput', () => {
    it('keeps start fixed and moves end when the duration is edited', async () => {
        const start = '2026-01-01T10:00:00Z';
        const end = '2026-01-01T11:00:00Z';
        const wrapper = mount(DurationHumanInput, {
            props: { start, end },
        });

        await wrapper.find('input').setValue('2h 30m');
        await wrapper.find('input').trigger('blur');

        expect(wrapper.emitted('update:start')).toBeUndefined();
        const emittedEnd = wrapper.emitted('update:end');
        expect(emittedEnd).toBeTruthy();
        const lastEmittedEnd = emittedEnd!.at(-1)!;
        const newEnd = dayjs(lastEmittedEnd[0] as string);
        expect(newEnd.diff(dayjs(start), 'seconds')).toBe(2 * 3600 + 30 * 60);
    });

    it('ignores a zero or invalid duration', async () => {
        const start = '2026-01-01T10:00:00Z';
        const end = '2026-01-01T11:00:00Z';
        const wrapper = mount(DurationHumanInput, {
            props: { start, end },
        });

        await wrapper.find('input').setValue('0h 00m');
        await wrapper.find('input').trigger('blur');

        expect(wrapper.emitted('update:start')).toBeUndefined();
        expect(wrapper.emitted('update:end')).toBeUndefined();
    });
});
