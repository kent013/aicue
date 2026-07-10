<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Support\LocalInitialsAvatarProvider;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Filament 管理画面 (/admin)。
 *
 * - 認証は admin guard (AdminUser モデル)。一般ユーザーの web guard とは分離する
 * - MFA (TOTP) は既定で必須 (ADMIN_MFA_REQUIRED=false で local / CI のみ無効化可)
 * - リソース / ページ / ウィジェットは app/Filament/ 配下から自動発見
 * - ダッシュボードは既定 widget のみ (アプリ固有の集計はアプリ側で追加する)
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('admin')
            ->login()
            ->profile()
            // MFA (TOTP)。運用者アカウントは既定で必須 (config/admin.php)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->recoveryCodeCount(8)
                    ->codeWindow(1),
            ], isRequired: (bool) config('admin.mfa_required', true))
            ->brandName(static fn (): string => config()->string('app.name'))
            ->colors([
                'primary' => Color::Slate,
            ])
            // 既定 UiAvatarsProvider の外部 ui-avatars.com への氏名送出を避け、ローカル initials avatar を使う
            ->defaultAvatarProvider(LocalInitialsAvatarProvider::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
