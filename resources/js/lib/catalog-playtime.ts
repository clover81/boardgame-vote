type Playtime = {
    min_playtime: number | null;
    max_playtime: number | null;
};

function positiveMinutes(value: number | null): number | null {
    return value !== null && Number.isInteger(value) && value > 0
        ? value
        : null;
}

export function catalogPlaytime({ min_playtime, max_playtime }: Playtime) {
    const min = positiveMinutes(min_playtime);
    const max = positiveMinutes(max_playtime);

    if (min !== null && max !== null && min > max) {
        return { min: null, max: null };
    }

    return { min, max };
}

export function formatCatalogPlaytime(game: Playtime): string {
    const { min, max } = catalogPlaytime(game);

    if (min === null && max === null) return 'Sin datos';
    if (max === null) return `Desde ${min} min`;
    if (min === null) return `Hasta ${max} min`;
    if (min === max) return `${min} min`;

    return `${min}–${max} min`;
}
