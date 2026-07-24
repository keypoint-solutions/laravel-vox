type DateTimeValue = Date | string | null | undefined;

export function useDateTime() {
    const dateTimeFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function formatDateTime(value: DateTimeValue, fallback = '—'): string {
        if (!value) {
            return fallback;
        }

        const date = value instanceof Date ? value : new Date(value);

        if (Number.isNaN(date.getTime())) {
            return typeof value === 'string' ? value : fallback;
        }

        return dateTimeFormatter.format(date);
    }

    return {
        formatDateTime,
    };
}
