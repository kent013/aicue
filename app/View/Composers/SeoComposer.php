<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Support\Seo\SeoManager;
use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoRenderer;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * root view (app.blade.php) に $seoHead を供給する。
 * controller が SeoManager にメタを供給していないページは noindex に落とす
 * (供給漏れが「誤って index される」方向に倒れない設計)。
 */
class SeoComposer
{
    public function __construct(
        private readonly SeoManager $manager,
        private readonly SeoRenderer $renderer,
        private readonly SeoUrl $url,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $routeName = $this->request->route()?->getName();

        // controller が供給済 (full 分類 / または明示供給) ならそれを描画。
        $supplied = $this->manager->get();
        if ($supplied !== null) {
            $view->with('seoHead', $this->renderer->render($supplied, $routeName));

            return;
        }

        // minimal 分類はページ固有 title + 最低限の canonical / og を描画。
        if (is_string($routeName) && $this->isMinimal($routeName)) {
            $view->with('seoHead', $this->renderer->render($this->buildMinimal($routeName), $routeName));

            return;
        }

        // それ以外 (認証配下のアプリ画面等、SEO 非対象 route) は full メタ (canonical/og) を出さず、
        // title + noindex のみ描画して private URL のメタ漏れ・誤インデックスを防ぐ。
        // per-page タイトル固有名の解決は SeoManager::resolvePrivateTitle に一元化
        // (Inertia 共有 prop と同一経路を読ませ、SPA 遷移時の document.title と <title> を一致させる)。
        $view->with('seoHead', $this->renderer->renderPrivate($this->manager->resolvePrivateTitle($routeName)));
    }

    /** minimal 分類の default メタ (ページ固有 title は minimal_titles map から)。 */
    private function buildMinimal(string $routeName): SeoMeta
    {
        // request->path() は先頭スラッシュ無し ('help' 等) なので正規化
        $meta = SeoMeta::default($this->url, '/'.ltrim($this->request->path(), '/'));

        /** @var array<string, string> $minimalTitles */
        $minimalTitles = config('seo.minimal_titles', []);
        if (isset($minimalTitles[$routeName])) {
            $meta = $meta->withTitle($minimalTitles[$routeName]);
        }

        return $meta;
    }

    private function isMinimal(string $routeName): bool
    {
        /** @var list<string> $minimal */
        $minimal = config('seo.route_classification.minimal', []);

        return in_array($routeName, $minimal, true);
    }
}
