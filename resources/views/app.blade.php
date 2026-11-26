<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark']) data-ui-palette="{{ ($page['props']['ui_palette'] ?? 'original') === 'calm-blue' ? 'calm-blue' : 'original' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico?v=6" sizes="any">
        <link rel="icon" href="/favicon.svg?v=6" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=6">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        {{-- Metadatos enviados por Laravel para las vistas previas de WhatsApp. --}}
        @php
            $publicPoll = ($page['component'] ?? null) === 'vote/show'
                ? ($page['props']['poll'] ?? null)
                : null;

            $previewTitle = 'Juegos de mesa · Clover';
            $previewDescription = null;

            if (is_array($publicPoll)) {
                $pollName = trim((string) ($publicPoll['name'] ?? 'Votación'));
                $position = $publicPoll['session_position'] ?? null;
                $closed = ($publicPoll['status'] ?? null) === 'closed';

                if (in_array($position, [1, 2], true)) {
                    // Evitar "Juego 2 · Juego 2 de 2".
                    $baseName = preg_replace(
                        '/\\s*[·–—-]\\s*Juego\\s*[12]\\s*$/iu',
                        '',
                        $pollName
                    );

                    $baseName = trim($baseName) ?: $pollName;

                    $previewTitle = "🎲 {$baseName} · Juego {$position} de 2";

                    $previewDescription = $closed
                        ? "Juego {$position} de 2: consulta los resultados y los tutoriales."
                        : "Juego {$position} de 2: vota y consulta los juegos y sus tutoriales.";
                } else {
                    $previewTitle = "🎲 {$pollName}";

                    $previewDescription = $closed
                        ? 'Consulta los resultados y tutoriales de la votación.'
                        : 'Participa en la votación y descubre los juegos y sus tutoriales.';
                }
            }
            // Vista previa de las fichas del catálogo público.
            $publicCatalogGame = ($page['component'] ?? null) === 'catalog/show'
                ? ($page['props']['game'] ?? null)
                : null;

            $previewImage = null;

            if (is_array($publicCatalogGame)) {
                $gameName = trim(
                    (string) ($publicCatalogGame['name'] ?? 'Juego de mesa')
                );

                $previewTitle = "🎲 {$gameName} · Clover";
                $gameSummary = \Illuminate\Support\Str::squish(strip_tags(html_entity_decode(
                    (string) ($publicCatalogGame['short_description'] ?? ''),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )));
                $previewDescription = $gameSummary !== ''
                    ? \Illuminate\Support\Str::limit($gameSummary, 160)
                    : "Consulta la ficha de {$gameName} y accede a sus tutoriales.";

                $candidateImage = $publicCatalogGame['image_url'] ?? null;

                if (
                    is_string($candidateImage)
                    && filter_var($candidateImage, FILTER_VALIDATE_URL)
                    && in_array(
                        parse_url($candidateImage, PHP_URL_SCHEME),
                        ['http', 'https'],
                        true
                    )
                ) {
                    $previewImage = $candidateImage;
                }
            }
            // Vista previa al compartir el catálogo completo.
            $publicCatalogIndex = ($page['component'] ?? null) === 'catalog/index';

            if ($publicCatalogIndex) {
                $previewTitle = '🎲 Catálogo de juegos · Clover';
                $previewDescription = 'Explora los juegos de mesa de Clover, consulta sus fichas, filtra por jugadores y encuentra tutoriales.';
            }

            if (request()->routeIs('catalog.public', 'catalog.games.show')) {
                $previewImage ??= asset('images/catalog-shelf-comic.webp');
            }
        @endphp

        @if (is_array($publicPoll) || is_array($publicCatalogGame) || $publicCatalogIndex)
            <meta name="description" content="{{ $previewDescription }}">
            @if (is_array($publicPoll))
                <meta name="robots" content="noindex, nofollow">
            @endif
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Juegos de mesa · Clover">
            <meta property="og:title" content="{{ $previewTitle }}">
            <meta property="og:description" content="{{ $previewDescription }}">
            <meta property="og:url" content="{{ $publicCatalogIndex && request()->query('view') === 'filtered' ? request()->fullUrl() : request()->url() }}">
            @if ($previewImage)
                <meta property="og:image" content="{{ $previewImage }}">
            @endif
        @endif

        <x-inertia::head>
            <title>{{ $previewTitle }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
