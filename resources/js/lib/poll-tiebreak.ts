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
