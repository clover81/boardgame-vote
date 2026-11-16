<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Solo las cuentas autenticadas distintas de la principal reciben
            // la paleta azul. Invitados y propietario mantienen el diseño actual.
            'ui_palette' => $request->user()
                && strcasecmp(trim($request->user()->email), 'alejandroruedamar@gmail.com') !== 0
                    ? 'calm-blue'
                    : 'original',
            'auth' => [
                'user' => $request->user(),
            ],
            // Una cuenta puede representar de forma explícita un jugador de
            // otra colección. Solo compartimos su color, nunca sus datos privados.
            'profile_player_color' => fn () => $request->user()?->profilePlayer?->color,

            // Colores guardados de los jugadores propios para toda la interfaz privada.
            'player_colors' => fn () => $request->user()
                ? \App\Models\Player::query()
                    ->where('created_by', $request->user()->id)
                    ->get(['name', 'color'])
                    ->filter(fn ($player) => $player->color !== null)
                    ->mapWithKeys(fn ($player) => [mb_strtolower(trim($player->name), 'UTF-8') => $player->color])
                    ->all()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
