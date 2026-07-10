<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Inquiry\CreateInquiryAction;
use App\DataTransferObjects\Inquiry\CreateInquiryData;
use App\Enums\Inquiry\InquirySource;
use App\Enums\Inquiry\InquiryType;
use App\Http\Requests\StoreInquiryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 公開問い合わせフォーム (auth 不要)。
 *
 * SEO: route 未分類 (config/seo.php の route_classification 外) のため
 * noindex + per-page title (seo.app_titles) が描画される。
 */
class ContactController extends Controller
{
    public function show(Request $request): Response
    {
        // 流入元 (?source=) は allowlist で正規化。該当しない値は null (自由入力を残さない)。
        $source = InquirySource::normalize($request->query('source') !== null
            ? (string) $request->query('source')
            : null);

        $siteKey = config('services.recaptcha.site_key');

        return Inertia::render('Contact/Index', [
            'types' => collect(InquiryType::cases())
                ->map(fn (InquiryType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->all(),
            'source' => $source?->value,
            'recaptchaSiteKey' => is_string($siteKey) ? $siteKey : '',
            'termsUrl' => '/terms',
            'privacyUrl' => '/privacy',
        ]);
    }

    public function store(StoreInquiryRequest $request, CreateInquiryAction $action): RedirectResponse
    {
        // honeypot ヒット時は保存・mail なしで、正常時と完全に同一のレスポンスを返す
        // (bot に成否を悟らせない)。
        if (! $request->isHoneypotFilled()) {
            $action(CreateInquiryData::fromRequest($request));
        }

        // 送信後はフォーム画面に戻さず thanks ページへ遷移する (フォームを残さない)。
        return redirect()->route('contact.thanks');
    }

    /**
     * 送信完了ページ。PII は持たない固定表示 (直接 GET でも 200)。
     */
    public function thanks(): Response
    {
        return Inertia::render('Contact/Thanks');
    }
}
