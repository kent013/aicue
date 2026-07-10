<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Contracts\UserResolver;
use Throwable;

/**
 * laravel-auditing の causer (実行者) 解決。
 *
 * config('audit.user.guards') に列挙された guard を順に照合し、最初に認証済みの
 * ユーザを返す (admin を web より先に置くことで、Filament 運営操作の audit が
 * AdminUser に紐付く)。guard 解決の失敗は warning ログのみで次 guard に進む
 * (audit の causer 解決失敗で本体の save() を落とさない)。
 */
class ResolveAuditCauser implements UserResolver
{
    public static function resolve(): ?Authenticatable
    {
        /** @var mixed $raw */
        $raw = Config::get('audit.user.guards', [Config::get('auth.defaults.guard')]);

        /** @var list<string> $guards */
        $guards = is_array($raw)
            ? array_values(array_filter($raw, static fn (mixed $v): bool => is_string($v)))
            : [];

        foreach ($guards as $guard) {
            try {
                if (Auth::guard($guard)->check()) {
                    return Auth::guard($guard)->user();
                }
            } catch (AuthenticationException) {
                continue;
            } catch (Throwable $e) {
                Log::warning('ResolveAuditCauser: unexpected error while resolving guard', [
                    'guard' => $guard,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return null;
    }
}
