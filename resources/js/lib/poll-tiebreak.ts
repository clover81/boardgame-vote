export interface RankedPollResult {
    points: number;
    first_votes: number;
    second_votes: number;
    is_winner: boolean;
}

export interface SecondPreferenceTieBreak {
    winnerSecondVotes: number;
    nextBestSecondVotes: number;
}

export function getSecondPreferenceTieBreak(
    results: readonly RankedPollResult[],
): SecondPreferenceTieBreak | null {
    const winner = results.find(result => result.is_winner);
    if (!winner) return null;

    const rivals = results.filter(result =>
        result !== winner &&
        result.points === winner.points &&
        result.first_votes === winner.first_votes
    );

    if (!rivals.length) return null;

    const nextBestSecondVotes = Math.max(
        ...rivals.map(result => result.second_votes)
    );

    if (winner.second_votes <= nextBestSecondVotes) {
        return null;
    }

    return {
        winnerSecondVotes: winner.second_votes,
        nextBestSecondVotes,
    };
}

export function pendingTieBreakMessage(round: {
    status: 'draft' | 'open' | 'closed';
    has_counted_votes: boolean;
}): string {
    if (round.status === 'closed') {
        return round.has_counted_votes
            ? 'La ronda de desempate también terminó empatada.'
            : 'La ronda de desempate se cerró sin votos. El empate original sigue sin resolverse.';
    }

    return round.status === 'open'
        ? 'Hay una votación de desempate en marcha.'
        : 'Se está preparando una votación de desempate.';
}
