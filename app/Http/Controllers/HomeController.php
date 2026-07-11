<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\Marketing\LandingPageDto;
use App\Enums\Inquiry\InquirySource;
use App\Services\Billing\TicketPricingService;
use App\Services\Marketing\ContactDestinationKind;
use App\Services\Marketing\ContactUrl;
use App\Support\Seo\JsonLd;
use App\Support\Seo\SeoManager;
use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * トップページ (route: home) = LP。SEO full 分類ページの参考実装:
 * controller が SeoManager にメタを供給すると SeoComposer が完全な SEO ヘッド
 * (canonical / og / JSON-LD) をサーバ描画する。
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly SeoManager $seo,
        private readonly SeoUrl $url,
        private readonly ContactUrl $contact,
        private readonly TicketPricingService $ticketPricing,
    ) {}

    public function __invoke(Request $request): InertiaResponse
    {
        $siteName = Config::string('seo.site_name');

        $this->seo->set(
            SeoMeta::default($this->url, '/')
                ->withTitle($siteName)
                ->withJsonLd([
                    // logo はアプリ側で public/images/logo.svg を配置して差し替える (placeholder)
                    JsonLd::organization($siteName, $this->url->base(), $this->url->to('/images/logo.svg')),
                    JsonLd::website($siteName, $this->url->base()),
                    // Free プランで開始可能 = lowPriceJpy 0 (「無料開始 + チケット制」訴求と一致させる)
                    JsonLd::softwareApplication(
                        $siteName,
                        $this->url->base(),
                        Config::string('seo.default_description'),
                        0,
                    ),
                ]),
        );

        $dto = new LandingPageDto(
            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
            // 内部 path のときのみ source を安全に付与 (外部 URL / mailto には付与しない)
            contactUrl: $this->contact->resolveForSource(InquirySource::Landing),
            contactIsExternal: $this->contact->kind() === ContactDestinationKind::External,
            isAuthenticated: $request->user() !== null,
        );

        return Inertia::render('Welcome', [
            'appName' => config('app.name'),
            'page' => $dto->toArray(),
        ]);
    }
}
