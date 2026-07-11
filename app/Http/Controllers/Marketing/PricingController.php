<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\DataTransferObjects\Marketing\PricingPageDto;
use App\Enums\Inquiry\InquirySource;
use App\Http\Controllers\Controller;
use App\Services\Billing\TicketPricingService;
use App\Services\Marketing\ContactDestinationKind;
use App\Services\Marketing\ContactUrl;
use App\Services\Marketing\PricingService;
use App\Support\Seo\JsonLd;
use App\Support\Seo\SeoManager;
use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Webmozart\Assert\Assert;

/**
 * 公開料金表 (/pricing)。プラン基本料 + チケット傾斜単価 + FAQ を供給する。
 * SEO は full 供給 (HomeController と同じ SeoMeta 方式。lowPriceJpy=0 = Free)。
 */
class PricingController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly TicketPricingService $ticketPricing,
        private readonly ContactUrl $contact,
        private readonly SeoManager $seo,
        private readonly SeoUrl $url,
    ) {}

    public function __invoke(Request $request): InertiaResponse
    {
        $analysisCost = config('manual.analysis_ticket_cost');
        $renderCost = config('manual.render_ticket_cost');
        Assert::integer($analysisCost);
        Assert::integer($renderCost);

        $dto = new PricingPageDto(
            plans: $this->pricing->listPublicPlans(),
            ticketTiers: $this->ticketPricing->volumeTiersForDisplay(),
            spotUnitAmountJpy: $this->ticketPricing->spotUnitAmount(),
            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
            signupGrantExpiryDays: $this->ticketPricing->signupGrantExpiryDays(),
            analysisTicketCost: $analysisCost,
            renderTicketCost: $renderCost,
            isAuthenticated: $request->user() !== null,
            contactUrl: $this->contact->resolveForSource(InquirySource::Pricing),
            contactIsExternal: $this->contact->kind() === ContactDestinationKind::External,
        );

        $siteName = Config::string('seo.site_name');
        $this->seo->set(
            SeoMeta::default($this->url, '/pricing')
                ->withTitle('料金プラン')
                ->withDescription('AI-CUE の料金プラン。無料で始められる Free プランと、チームで使う Standard プラン。AI 解析・動画レンダは共通チケット制で、必要な分だけ購入できます。')
                ->withJsonLd([
                    JsonLd::softwareApplication(
                        $siteName,
                        $this->url->to('/pricing'),
                        Config::string('seo.default_description'),
                        0,
                    ),
                ]),
        );

        return Inertia::render('Pricing', [
            'page' => $dto->toArray(),
        ]);
    }
}
