/** Color del nombre y meeple en etiquetas de participantes. */
export function playerNameColor(name: string | null | undefined): string {
    switch ((name ?? '').trim().toLocaleLowerCase('es')) {
        case 'miki':
            return 'text-amber-700 dark:text-yellow-300';
        case 'ana':
            return 'text-pink-700 dark:text-pink-300';
        case 'alejandro':
            return 'text-violet-700 dark:text-violet-300';
        case 'alejo':
            return 'text-teal-700 dark:text-teal-300';
        default:
            return 'text-zinc-700 dark:text-zinc-200';
    }
}
