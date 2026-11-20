<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('inertia comparte solo los datos necesarios del usuario', function () {
    $this->withoutVite();

    $user = User::factory()->create();
    $user->forceFill([
        'catalog_share_token' => '12345678-1234-4abc-8abc-1234567890ab',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.catalog_share_token')
            ->missing('auth.user.profile_player_id')
            ->missing('auth.user.two_factor_confirmed_at')
            ->missing('auth.user.two_factor_secret')
            ->etc()
        );
});
