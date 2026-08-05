<?php

use App\Providers\AppServiceProvider;
use App\Providers\FakeExternalsServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\McpPassportServiceProvider;
use App\Providers\PasskeyServiceProvider;
use App\Providers\SeoServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    // passkey (laravel/passkeys) の app アダプタ。Fortify が feature flag で route を
    // 登録するため **FortifyServiceProvider より後**に置く。ただし binder / middleware の
    // 後付けは provider 順序に依存しないよう $app->booted() 内で最終上書きする
    PasskeyServiceProvider::class,
    // Passport は composer.json の dont-discover で自動 discovery を無効化し、
    // grant / repository を差し替えた本 Provider を唯一の登録点にする (WP23)
    McpPassportServiceProvider::class,
    SeoServiceProvider::class,
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    FakeExternalsServiceProvider::class,
];
