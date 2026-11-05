<?php

return [

    'bgg' => [
        'token' => env('BGG_API_TOKEN'),
        'collection_username' => env('BGG_COLLECTION_USERNAME'),
        'base_url' => 'https://boardgamegeek.com/xmlapi2',
    ],

    'game_descriptions' => [
        'enabled' => env('GAME_DESCRIPTIONS_AI_ENABLED', false),

        'api_key' => env('OPENAI_API_KEY'),

        'model' => env(
            'OPENAI_DESCRIPTION_MODEL',
            'gpt-6-luna'
        ),
    ],

];
