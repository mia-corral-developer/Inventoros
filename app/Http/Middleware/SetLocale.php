<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = [
        'en', 'es', 'fr', 'de', 'pt-BR', 'it',
        'ja', 'ko', 'zh-CN', 'ar', 'ru', 'nl', 'tr', 'pl',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        if (in_array($locale, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * Resolve the active locale for this request.
     *
     * Precedence (single source of truth = the preference saved in
     * Settings → Account → Preferences):
     *   1. The authenticated user's saved language preference.
     *   2. The `locale` cookie, kept in sync by the frontend on every switch
     *      (also covers guests who change language before logging in).
     *   3. The application default (config('app.locale')).
     *
     * Previously this middleware only read the cookie, and nothing in the app
     * ever wrote that cookie for the settings flow — so the saved preference
     * had no effect and the UI never translated.
     */
    private function resolveLocale(Request $request): string
    {
        $user = $request->user();
        if ($user) {
            $preference = data_get($user->notification_preferences, 'preferences.language');
            if (is_string($preference) && in_array($preference, self::SUPPORTED_LOCALES, true)) {
                return $preference;
            }
        }

        $cookie = $request->cookie('locale');
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED_LOCALES, true)) {
            return $cookie;
        }

        return (string) config('app.locale');
    }
}