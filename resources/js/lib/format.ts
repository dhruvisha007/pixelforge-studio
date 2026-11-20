const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
];

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/** "2 minutes ago", "just now" */
export function timeAgo(iso: string, now: number = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

    if (Math.abs(seconds) < 10) {
        return 'just now';
    }

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return '';
}

export const sizeLabels: Record<string, { name: string; ratio: string }> = {
    '1024x1024': { name: 'Square', ratio: '1:1' },
    '1536x1024': { name: 'Landscape', ratio: '3:2' },
    '1024x1536': { name: 'Portrait', ratio: '2:3' },
};

export const qualityLabels: Record<string, { name: string; hint: string }> = {
    low: { name: 'Low', hint: 'Fastest and cheapest. Good for trying ideas.' },
    medium: { name: 'Medium', hint: 'Balanced detail and price.' },
    high: { name: 'High', hint: 'Most detail. Costs the most.' },
};

/** Reads Laravel's XSRF-TOKEN cookie for fetch() calls. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}
