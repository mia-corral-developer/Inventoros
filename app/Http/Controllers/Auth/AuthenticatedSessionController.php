<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for user authentication sessions.
 *
 * Handles displaying login form, authenticating users,
 * and destroying sessions (logout).
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return Response
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * @param LoginRequest $request The validated login request
     * @return RedirectResponse
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended($this->homeFor($request->user()));
    }

    /**
     * Where a freshly logged-in user lands.
     *
     * Warehouse counters hold only counting permissions, so the office
     * dashboard is useless to them — send them straight to their pending
     * counts. Everyone else keeps the dashboard.
     */
    private function homeFor(?User $user): string
    {
        if ($user === null) {
            return route('dashboard', absolute: false);
        }

        // Permissions that mean "this person works in the office app".
        $officePermissions = [
            'view_products', 'view_orders', 'manage_stock', 'manage_users',
            'manage_roles', 'view_reports', 'view_dashboard',
        ];

        $isCounterOnly = $user->hasAnyPermission([Permission::COUNT_STOCK_AUDITS->value])
            && ! $user->hasAnyPermission($officePermissions);

        return route($isCounterOnly ? 'my-counts' : 'dashboard', absolute: false);
    }

    /**
     * Destroy an authenticated session.
     *
     * @param Request $request The incoming HTTP request
     * @return RedirectResponse
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
