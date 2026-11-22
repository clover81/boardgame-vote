export const getPollReturnUrl = (pageUrl: string): string | null => {
    const raw = new URLSearchParams(
        pageUrl.split('?')[1] ?? ''
    ).get('poll_return');

    if (!raw) return null;

    const match = raw.match(/^\/polls\/[1-9]\d*(?:\?([^#]*))?$/);
    if (!match) return null;

    const input = new URLSearchParams(match[1] ?? '');
    const clean = new URLSearchParams();

    if (input.get('from') === 'history') {
        clean.set('from', 'history');
    }

    const back = input.get('back');
    if (back?.startsWith('/polls?')) {
        clean.set('back', back);
    }

    const path = raw.split('?')[0];
    return `${path}${clean.size ? `?${clean}` : ''}`;
};
