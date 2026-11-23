<?php

use App\Services\GameDescriptionGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('el generador solicita textos naturales sin coletillas editoriales', function () {
    config()->set('services.game_descriptions.enabled', true);
    config()->set('services.game_descriptions.api_key', 'test-key');
    config()->set('services.game_descriptions.model', 'test-model');

    $short = 'Los jugadores exploran una ciudad fantástica y compiten por conseguir recursos valiosos.';
    $full = 'Los jugadores recorren una ciudad fantástica en busca de tesoros y nuevas oportunidades.'
        ."\n\n"
        .'En cada turno eligen sus movimientos, gestionan recursos y toman decisiones para obtener puntos.';

    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'short_description' => $short,
                        'full_description' => $full,
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ], 200),
    ]);

    $result = app(GameDescriptionGenerator::class)
        ->generate('Juego de prueba', 'Descripción de prueba BGG.');

    expect($result['short_description'])->toBe($short)
        ->and($result['full_description'])->toBe($full);

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request) {
        $instructions = (string) ($request['instructions'] ?? '');

        return str_contains($instructions, 'frase completa y natural')
            && str_contains($instructions, 'No añadas coletillas')
            && str_contains($instructions, 'fragmentos inconexos');
    });
});
