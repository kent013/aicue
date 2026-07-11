<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\Inquiry\InquirySource;
use Illuminate\Support\Facades\Log;

/**
 * 問い合わせ CTA の宛先を解決する。
 *
 * `/contact` をフロントに直書きする代わりに、`config('services.marketing.contact_url')` で
 * 内部 route / 外部 URL / mailto を切替可能にする (問い合わせを外部フォームサービスに
 * 委ねるアプリ向け)。未設定なら内部フォーム (/contact) を既定にする。
 * source attribution (どこからの導線か) は呼び出し側が query で付与する。
 */
final class ContactUrl
{
    public const INTERNAL_DEFAULT = '/contact';

    /** 解決後の宛先 URL。 */
    public function resolve(): string
    {
        $configured = config('services.marketing.contact_url');

        if (! is_string($configured) || $configured === '') {
            return self::INTERNAL_DEFAULT;
        }

        // scheme allowlist で fail-close。`javascript:` / `data:` 等の危険 scheme が
        // front の href に届くのを防ぐ (この値は Inertia prop として front に渡る)。
        if (! $this->isSafeDestination($configured)) {
            Log::warning('marketing.contact_url に許可外の値が設定されています。内部フォームへ fallback します。', [
                'configured' => $configured,
            ]);

            return self::INTERNAL_DEFAULT;
        }

        return $configured;
    }

    /**
     * source attribution 付きの宛先を解決する。
     *
     * 内部 path のときのみ `source={value}` を安全に付与する (既存 query の有無で
     * `?`/`&` を使い分け、`#fragment` があれば fragment 直前に挿入する)。
     * 外部 URL / mailto は先方フォームの query 契約が不明なため付与しない
     * (resolve() と同値を返す)。
     */
    public function resolveForSource(InquirySource $source): string
    {
        $url = $this->resolve();

        if ($this->kind() !== ContactDestinationKind::Internal) {
            return $url;
        }

        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'source='.$source->value.$fragment;
    }

    /**
     * 宛先として許可する形式か (内部 path / http(s) / mailto のみ)。
     */
    private function isSafeDestination(string $url): bool
    {
        // `//evil.com` / `/\evil.com` は内部 path に見えて外部遷移する (ブラウザは backslash を
        // slash に正規化する)。`/` 始まりでも 2 文字目が `/` または `\` のものは
        // protocol-relative 相当として内部 path から除外する。
        if (str_starts_with($url, '/')) {
            $second = $url[1] ?? '';

            return $second !== '/' && $second !== '\\';
        }

        return str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
            || str_starts_with($url, 'mailto:');
    }

    /** 宛先種別 (フロントの遷移方法分岐用)。 */
    public function kind(): ContactDestinationKind
    {
        $url = $this->resolve();

        if (str_starts_with($url, 'mailto:')) {
            return ContactDestinationKind::Mailto;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return ContactDestinationKind::External;
        }

        return ContactDestinationKind::Internal;
    }
}
