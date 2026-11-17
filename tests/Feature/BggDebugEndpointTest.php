<?php

use App\Models\User;

test('BGG debug configuration is not accessible to guests', function () {
    $this->get('/debug/bgg-config')->assertNotFound();
});

test('BGG debug configuration is not accessible to authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get('/debug/bgg-config')
        ->assertNotFound();
});
