<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\BughuntFakesServiceProvider;
use App\Providers\ExternalClientTimeoutServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\McpPassportServiceProvider;
use App\Providers\PasskeyServiceProvider;
use App\Providers\SeoServiceProvider;

return [
    AppServiceProvider::class,
    // 外部 SDK (Stripe) のプロセス大域 timeout pin。他の provider の副作用と混ぜないため
    // 専用に切り出す (テストが boot() を単独で再実行できるようにする)
    ExternalClientTimeoutServiceProvider::class,
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
    BughuntFakesServiceProvider::class,
];
