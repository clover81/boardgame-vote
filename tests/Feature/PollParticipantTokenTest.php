<?php

use App\Models\Poll;
use App\Models\PollParticipant;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->poll = Poll::create([
        'created_by' => User::factory()->create()->id,
        'name' => 'Tokens automáticos',
        'token' => 'AUTOTOKEN',
        'status' => 'draft',
    ]);
});

test('participants receive tokens when created directly or through their poll', function ($method, $input) {
    $attributes = ['display_name' => 'Ana'];
    if ($input !== 'omitted') {
        $attributes['vote_token'] = $input === 'null' ? null : '';
    }

    $participant = $method === 'direct'
        ? PollParticipant::create(['poll_id' => $this->poll->id, ...$attributes])
        : $this->poll->participants()->create($attributes);

    expect($participant->vote_token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($participant->fresh()->vote_token)->toBe($participant->vote_token);
})->with(['direct', 'relation'])->with(['omitted', 'null', 'empty']);

test('an explicit participant token is preserved during creation and later updates', function () {
    $token = str_repeat('a', 40);
    $participant = $this->poll->participants()->create([
        'display_name' => 'Alejandro',
        'vote_token' => $token,
    ]);
    $participant->update(['display_name' => 'Alex']);

    expect($participant->fresh()->vote_token)->toBe($token);
});

test('automatically generated participant tokens are different', function () {
    $first = $this->poll->participants()->create(['display_name' => 'Alejandro']);
    $second = PollParticipant::create(['poll_id' => $this->poll->id, 'display_name' => 'Ana']);

    expect($first->vote_token)->not->toBe($second->vote_token);
});

test('automatic token generation retries when the generated value already exists', function () {
    $existingToken = str_repeat('a', 40);
    $availableToken = str_repeat('b', 40);
    $this->poll->participants()->create([
        'display_name' => 'Alejandro',
        'vote_token' => $existingToken,
    ]);

    Str::createRandomStringsUsingSequence([$existingToken, $availableToken]);
    try {
        $participant = $this->poll->participants()->create(['display_name' => 'Ana']);
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($participant->fresh()->vote_token)->toBe($availableToken);
    $this->assertDatabaseCount('poll_participants', 2);
});
