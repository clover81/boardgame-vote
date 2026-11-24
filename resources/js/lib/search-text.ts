export const normalizeSearchText = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/['‘’]/g, '')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .toLocaleLowerCase()
        .trim();
