import { describe, expect, test } from 'vitest';
import {
    formatHumanReadableDuration,
    formatReportingDuration,
    shiftDuplicateInterval,
    getDayJsInstance,
    isReusableRecentEntry,
} from './time';

const seconds = 14 * 3600 + 45 * 60 + 6; // 14h 45m 06s

describe('formatHumanReadableDuration', () => {
    test('decimal', () => {
        expect(formatHumanReadableDuration(seconds, 'decimal', 'comma-point')).toBe('14.75 h');
    });

    test('hours-minutes', () => {
        expect(formatHumanReadableDuration(seconds, 'hours-minutes')).toBe('14h 45min');
    });

    test('hours-minutes-colon-separated', () => {
        expect(formatHumanReadableDuration(seconds, 'hours-minutes-colon-separated')).toBe('14:45');
    });

    test('hours-minutes-seconds-colon-separated', () => {
        expect(formatHumanReadableDuration(seconds, 'hours-minutes-seconds-colon-separated')).toBe(
            '14:45:06'
        );
    });
});

describe('formatHumanReadableDuration sub-minute', () => {
    test('40s default shows <1min instead of 0h 00min', () => {
        expect(formatHumanReadableDuration(40)).toBe('<1min');
    });

    test('40s hours-minutes shows <1min', () => {
        expect(formatHumanReadableDuration(40, 'hours-minutes')).toBe('<1min');
    });

    test('0s stays 0h 00min', () => {
        expect(formatHumanReadableDuration(0)).toBe('0h 00min');
    });

    test('60s is exactly 0h 01min', () => {
        expect(formatHumanReadableDuration(60)).toBe('0h 01min');
    });

    test('90s rounds down to 0h 01min', () => {
        expect(formatHumanReadableDuration(90, 'hours-minutes')).toBe('0h 01min');
    });

    test('sub-minute does not affect colon-separated format', () => {
        expect(formatHumanReadableDuration(40, 'hours-minutes-colon-separated')).toBe('0:00');
    });
});

describe('shiftDuplicateInterval', () => {
    const entry = { start: '2026-07-24T09:13:21Z', end: '2026-07-24T09:23:22Z' }; // 601s

    test('new start equals the original end', () => {
        const dup = shiftDuplicateInterval(entry);
        expect(getDayJsInstance()(dup.start).isSame(getDayJsInstance()(entry.end))).toBe(true);
    });

    test('preserves the original duration', () => {
        const dup = shiftDuplicateInterval(entry);
        const originalSecs = getDayJsInstance()(entry.end).diff(
            getDayJsInstance()(entry.start),
            'second'
        );
        const dupSecs = getDayJsInstance()(dup.end).diff(getDayJsInstance()(dup.start), 'second');
        expect(dupSecs).toBe(originalSecs);
    });

    test('does not overlap the original interval', () => {
        const dup = shiftDuplicateInterval(entry);
        expect(
            getDayJsInstance()(dup.start).isSameOrAfter(getDayJsInstance()(entry.end))
        ).toBe(true);
    });

    test('running entry (end null) is copied unshifted', () => {
        const running = { start: '2026-07-24T09:13:21Z', end: null };
        const dup = shiftDuplicateInterval(running);
        expect(dup.start).toBe(running.start);
        expect(dup.end).toBe(null);
    });

    test('preserves other fields', () => {
        const rich = { ...entry, description: 'Calls', project_id: 'p1', billable: true };
        const dup = shiftDuplicateInterval(rich);
        expect(dup.description).toBe('Calls');
        expect(dup.project_id).toBe('p1');
        expect(dup.billable).toBe(true);
    });
});

describe('isReusableRecentEntry', () => {
    const base = { description: null, project_id: null, task_id: null };

    test('empty entry (no description, project, task) is not reusable', () => {
        expect(isReusableRecentEntry(base)).toBe(false);
    });

    test('whitespace-only description is not reusable', () => {
        expect(isReusableRecentEntry({ ...base, description: '   ' })).toBe(false);
    });

    test('entry with a description is reusable', () => {
        expect(isReusableRecentEntry({ ...base, description: 'Calls' })).toBe(true);
    });

    test('entry with a project but no description is reusable', () => {
        expect(isReusableRecentEntry({ ...base, project_id: 'p1' })).toBe(true);
    });

    test('entry with a task but no description is reusable', () => {
        expect(isReusableRecentEntry({ ...base, task_id: 't1' })).toBe(true);
    });
});

describe('formatReportingDuration', () => {
    test('decimal', () => {
        expect(formatReportingDuration(seconds, 'decimal', 'comma-point')).toBe('14.75 h');
    });

    test('hours-minutes', () => {
        expect(formatReportingDuration(seconds, 'hours-minutes')).toBe('14:45:06');
    });

    test('hours-minutes-colon-separated', () => {
        expect(formatReportingDuration(seconds, 'hours-minutes-colon-separated')).toBe('14:45:06');
    });

    test('hours-minutes-seconds-colon-separated', () => {
        expect(formatReportingDuration(seconds, 'hours-minutes-seconds-colon-separated')).toBe(
            '14:45:06'
        );
    });
});
