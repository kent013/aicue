<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use JsonException;

/**
 * SeoMeta を <head> に入れる安全な HTML 文字列へ描画する。
 * エスケープ (HTML / JSON) の責務は本クラスに一元化する。
 */
final class SeoRenderer
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /** 公開ページ (full / minimal 分類) の完全な SEO ヘッド。 */
    public function render(SeoMeta $meta, ?string $routeName = null): HtmlString
    {
        $html = [];
        $html[] = '<title>'.e($meta->title).'</title>';
        $html[] = '<meta name="description" content="'.e($meta->description).'">';
        $html[] = '<link rel="canonical" href="'.e($meta->canonical).'">';
        $html[] = '<meta property="og:title" content="'.e($meta->title).'">';
        $html[] = '<meta property="og:description" content="'.e($meta->description).'">';
        $html[] = '<meta property="og:type" content="'.e($meta->ogType).'">';
        $html[] = '<meta property="og:url" content="'.e($meta->canonical).'">';
        $html[] = '<meta property="og:image" content="'.e($meta->ogImage).'">';
        $html[] = '<meta property="og:site_name" content="'.e(Config::string('seo.site_name')).'">';
        $html[] = '<meta property="og:locale" content="'.e(Config::string('seo.locale')).'">';
        $html[] = '<meta name="twitter:card" content="'.e($meta->twitterCard).'">';
        $html[] = '<meta name="twitter:title" content="'.e($meta->title).'">';
        $html[] = '<meta name="twitter:description" content="'.e($meta->description).'">';
        $html[] = '<meta name="twitter:image" content="'.e($meta->ogImage).'">';

        foreach ($meta->jsonLd as $node) {
            $rendered = $this->renderJsonLdNode($node, $routeName);
            if ($rendered !== null) {
                $html[] = $rendered;
            }
        }

        return new HtmlString(implode("\n    ", $html));
    }

    /**
     * SEO 非対象 route (認証配下のアプリ画面等) 向けの最小ヘッド。
     * canonical / og を出さず、title + noindex のみ (private URL のメタ漏れ・誤インデックス防止)。
     *
     * $titleFragment が与えられれば `{固有名}{separator}{サイト名}` を描画してタブ識別性を上げる
     * (未指定 = サイト名のみ)。noindex は常に維持。
     */
    public function renderPrivate(?string $titleFragment = null): HtmlString
    {
        $html = [
            '<title>'.e(SeoTitle::compose($titleFragment)).'</title>',
            '<meta name="robots" content="noindex">',
        ];

        return new HtmlString(implode("\n    ", $html));
    }

    /** @param array<string, mixed> $node */
    private function renderJsonLdNode(array $node, ?string $routeName): ?string
    {
        try {
            // JSON_HEX_* で </script> や属性破壊を無害化する。
            // 握る例外は JsonException のみ (実装バグの Throwable は握りつぶさない)。
            $json = json_encode($node, self::JSON_FLAGS);

            return '<script type="application/ld+json">'.$json.'</script>';
        } catch (JsonException $e) {
            // production 判定は config('app.env') を直接読む (app()->environment() は bootstrap 時の
            // キャッシュ値のため、テストの config(['app.env' => ...]) 上書きが反映されない)。
            // 非 production (testing/local 等) では skip せず即 fail (構造化データ欠損を検知)。
            if (config('app.env') !== 'production') {
                throw $e;
            }
            // production のみ可用性維持で当該ノードのみ skip + warning log (route 名 + 種別付き)
            $type = is_string($node['@type'] ?? null) ? $node['@type'] : 'unknown';
            Log::warning('SEO JSON-LD node skipped', ['route' => $routeName, 'type' => $type, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
