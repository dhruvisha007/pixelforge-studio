<?php

namespace App\Http\Middleware;

use App\Support\Money;
use Brick\Math\RoundingMode;
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
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'is_admin', 'email_verified_at', 'created_at', 'updated_at']),
            ],
            // Closures are only run when needed, and can be refreshed on their own
            // (the studio polls "generations" and "credits" while images are in progress).
            'credits' => fn () => $user === null ? null : $this->credits($user),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }

    /**
     * The balance box in the sidebar and on the form. Balance and available round
     * down and reserved rounds up, so we never show more credit than there is.
     */
    private function credits($user): array
    {
        $balance = $user->balance();
        $reserved = $user->reservedCredit();

        return [
            'balance' => Money::format($balance, rounding: RoundingMode::FLOOR),
            'reserved' => Money::format($reserved, rounding: RoundingMode::CEILING),
            'available' => Money::format(max(0, $balance - $reserved), rounding: RoundingMode::FLOOR),
            'available_nanodollars' => $balance - $reserved,
            'reserved_nanodollars' => $reserved,
        ];
    }
}
