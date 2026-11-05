<?php

test('home redirects to games', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/games');
});
