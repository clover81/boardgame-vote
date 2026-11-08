/** Paleta segura y compartida para identificar jugadores. */
export const playerColorOptions = [
    { id: 'violet', label: 'Morado', swatch: 'bg-violet-500', text: 'text-violet-700 dark:text-violet-300' },
    { id: 'pink', label: 'Rosa', swatch: 'bg-pink-500', text: 'text-pink-700 dark:text-pink-300' },
    { id: 'yellow', label: 'Amarillo', swatch: 'bg-yellow-400', text: 'text-amber-700 dark:text-yellow-300' },
    { id: 'teal', label: 'Turquesa', swatch: 'bg-teal-400', text: 'text-teal-700 dark:text-teal-300' },
    { id: 'blue', label: 'Azul', swatch: 'bg-blue-500', text: 'text-blue-700 dark:text-blue-300' },
    { id: 'green', label: 'Verde', swatch: 'bg-green-500', text: 'text-green-700 dark:text-green-300' },
    { id: 'orange', label: 'Naranja', swatch: 'bg-orange-500', text: 'text-orange-700 dark:text-orange-300' },
    { id: 'red', label: 'Rojo', swatch: 'bg-red-500', text: 'text-red-700 dark:text-red-300' },
] as const;

const fallbackNames: Record<string, string> = {
    alejandro: 'violet',
    ana: 'pink',
    miki: 'yellow',
    alejo: 'teal',
};

// Se actualiza en cada navegación autenticada con los datos de la cuenta actual.
let assignedColors: Record<string, string> = {};

const normalize = (name: string | null | undefined): string =>
    (name ?? '').trim().toLocaleLowerCase('es');

export function setPlayerColors(colors: Record<string, string> | undefined): void {
    assignedColors = colors ?? {};
}

export function playerNameColor(name: string | null | undefined, savedColor?: string | null): string {
    const key = normalize(name);
    const color = savedColor || assignedColors[key] || fallbackNames[key] || '';
    return playerColorOptions.find((option) => option.id === color)?.text
        ?? 'text-zinc-700 dark:text-zinc-200';
}
