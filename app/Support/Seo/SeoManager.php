<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * リクエスト単位で現在の SEO メタを保持する。SeoServiceProvider で scoped 束縛する
 * (singleton にしない = Octane 等の長寿命プロセスでリクエスト間に状態が漏れない)。
 */
final class SeoManager
{
    private ?SeoMeta $meta = null;

    private ?string $privateTitle = null;

    public function set(SeoMeta $meta): void
    {
        $this->meta = $meta;
    }

    public function get(): ?SeoMeta
    {
        return $this->meta;
    }

    /**
     * SEO 非対象 (noindex) なアプリ画面の per-page タイトル固有名を上書きする。
     * 動的な固有名 (プロジェクト名等) を controller から供給する用途。
     * config('seo.app_titles') の route 既定より優先される (SeoComposer が解決)。
     */
    public function setPrivateTitle(string $fragment): void
    {
        $this->privateTitle = $fragment;
    }

    public function getPrivateTitle(): ?string
    {
        return $this->privateTitle;
    }

    /**
     * 現在のリクエストで `<title>` に描画される最終文字列を解決する。
     *
     * SeoComposer が `<title>` を描画するのと**同一の優先順位**で完成タイトルを返す単一経路。
     * Blade 描画 (SeoComposer) と Inertia 共有 prop (HandleInertiaRequests) が本メソッドを共有し、
     * フルロード時と SPA 遷移時で title が一致するようにする (二重 SoT を作らない)。
     *
     * 優先順位:
     *   1. controller 供給メタ (full 分類 / 明示供給) → 既に SeoTitle::compose 済みの完成 title
     *   2. minimal 分類 → config('seo.minimal_titles')[route] を compose
     *   3. それ以外 (認証配下 private) → setPrivateTitle 上書き or config('seo.app_titles')[route] を compose
     */
    public function resolveDocumentTitle(?string $routeName): string
    {
        if ($this->meta !== null) {
            return $this->meta->title;
        }

        if ($routeName !== null && $this->isMinimal($routeName)) {
            return SeoTitle::compose($this->minimalTitle($routeName));
        }

        return SeoTitle::compose($this->resolvePrivateTitle($routeName));
    }

    /**
     * SEO 非対象 (noindex) ページの per-page タイトル固有名を解決する。
     * 優先順位: setPrivateTitle 動的上書き → config('seo.app_titles')[route] → null。
     */
    public function resolvePrivateTitle(?string $routeName): ?string
    {
        if ($this->privateTitle !== null) {
            return $this->privateTitle;
        }

        if ($routeName === null) {
            return null;
        }

        /** @var array<string, string> $appTitles */
        $appTitles = config('seo.app_titles', []);

        return $appTitles[$routeName] ?? null;
    }

    private function minimalTitle(string $routeName): ?string
    {
        /** @var array<string, string> $minimalTitles */
        $minimalTitles = config('seo.minimal_titles', []);

        return $minimalTitles[$routeName] ?? null;
    }

    private function isMinimal(string $routeName): bool
    {
        /** @var list<string> $minimal */
        $minimal = config('seo.route_classification.minimal', []);

        return in_array($routeName, $minimal, true);
    }
}
