<?php

use App\Models\Poll;

test('el sistema recomienda las preferencias según jugadores y candidatos', function (
    int $players,
    int $games,
    int $expected
) {
    expect(Poll::suggestedRankingSlots($players, $games))->toBe($expected);
})->with([
    'dos juegos' => [4, 2, 1],
    'tres juegos' => [4, 3, 2],
    'cinco juegos' => [6, 5, 3],
    'seis juegos y tres personas' => [3, 6, 2],
    'seis juegos y cuatro personas' => [4, 6, 3],
    'ocho juegos y cinco personas' => [5, 8, 3],
]);

test('las nuevas puntuaciones respetan uno dos y tres puestos', function () {
    $one = new Poll(['ranking_slots' => 1]);
    $two = new Poll(['ranking_slots' => 2]);
    $three = new Poll(['ranking_slots' => 3]);

    expect($one->requiredVoteCount(8))->toBe(1);
    expect($two->requiredVoteCount(8))->toBe(2);
    expect($three->requiredVoteCount(8))->toBe(3);

    expect($one->votePoints(2, 1, 1))->toBe(2);
    expect($two->votePoints(2, 1, 1))->toBe(5);
    expect($three->votePoints(2, 1, 1))->toBe(9);
});

test('las votaciones anteriores conservan su puntuación', function () {
    $single = new Poll(['single_choice' => true]);
    $legacy = new Poll(['single_choice' => false]);

    expect($single->requiredVoteCount(6))->toBe(1);
    expect($single->votePoints(2, 1))->toBe(2);

    expect($legacy->requiredVoteCount(6))->toBe(2);
    expect($legacy->votePoints(2, 1))->toBe(5);
});
