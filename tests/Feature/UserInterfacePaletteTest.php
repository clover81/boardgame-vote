<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('visitors keep the original palette', function () {
    $this->withoutVite();

    $this->get('/login')
        ->assertOk()
        ->assertSee('data-ui-palette="original"', false)
        ->assertInertia(fn (Assert $page) => $page->where('ui_palette', 'original'));
});

test('the principal account always keeps the original palette', function () {
    $this->withoutVite();
    $owner = User::factory()->create([
        'email' => 'AlejandroRuedaMar@GMAIL.COM',
    ]);

    $this->actingAs($owner)->get('/games')
        ->assertOk()
        ->assertSee('data-ui-palette="original"', false)
        ->assertInertia(fn (Assert $page) => $page->where('ui_palette', 'original'));
});

test('other authenticated accounts receive the calm blue palette', function () {
    $this->withoutVite();
    $other = User::factory()->create();

    $this->actingAs($other)->get('/games')
        ->assertOk()
        ->assertSee('data-ui-palette="calm-blue"', false)
        ->assertInertia(fn (Assert $page) => $page->where('ui_palette', 'calm-blue'));
});

test('the configured account keeps the original palette after changing its email', function () {
    $this->withoutVite();
    $owner = User::factory()->create();
    config(['app.original_palette_user_id' => (string) $owner->id]);

    $owner->update(['email' => 'cambio-de-correo@example.test']);
    $this->actingAs($owner->fresh())->get('/games')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ui_palette', 'original'));

    $this->actingAs(User::factory()->create())->get('/games')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ui_palette', 'calm-blue'));
});
