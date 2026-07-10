<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Seo\SeoManager;
use App\Support\Seo\SeoUrl;
use App\View\Composers\SeoComposer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: リクエスト単位で状態保持 (Octane 等の長寿命プロセスでも状態が漏れない)。
        $this->app->scoped(SeoManager::class);
        // base_url (= APP_URL) の検証は SeoUrl のコンストラクタに集約 (Host ヘッダ非依存)。
        $this->app->bind(SeoUrl::class, fn (): SeoUrl => SeoUrl::fromConfig());
    }

    public function boot(): void
    {
        // Blade が読む $seoHead の供給経路を 1 本に固定する。root view 名は inertia 設定に追従させ、
        // 将来 root view が変わっても $seoHead 注入が外れないようにする。
        View::composer(Config::string('inertia.root_view', 'app'), SeoComposer::class);
    }
}
