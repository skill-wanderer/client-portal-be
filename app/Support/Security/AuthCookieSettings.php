<?php

namespace App\Support\Security;

use Illuminate\Http\Request;

final class AuthCookieSettings
{
    public static function path(): string
    {
        $path = config('session.path', '/');

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function domain(): ?string
    {
        $domain = config('session.domain');

        return is_string($domain) && trim($domain) !== '' ? trim($domain) : null;
    }

    public static function sameSite(): ?string
    {
        $sameSite = config('session.same_site', 'lax');

        return is_string($sameSite) && trim($sameSite) !== ''
            ? strtolower(trim($sameSite))
            : null;
    }

    public static function secure(?Request $request = null): bool
    {
        $configuredSecure = config('session.secure');

        if (is_bool($configuredSecure)) {
            return $configuredSecure;
        }

        $request ??= request();
        $appUrl = strtolower((string) config('app.url', ''));

        return ($request instanceof Request && $request->isSecure()) || str_starts_with($appUrl, 'https://');
    }
}