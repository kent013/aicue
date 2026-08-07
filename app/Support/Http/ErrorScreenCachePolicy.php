<?php

declare(strict_types=1);

namespace App\Support\Http;

use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia の Error 画面差し替え応答に適用するキャッシュ表現の契約。
 *
 * 同一 URL の応答が **リクエストヘッダとセッション状態の両方**で分岐するため、
 * 共有キャッシュが別のクライアントへ誤った表現を返さないようにする:
 *   - Vary … ヘッダ由来の分岐入力を宣言する (X-Inertia / X-Inertia-Version / Accept)
 *   - no-store + private … セッション由来の分岐 (戻り先が /dashboard か /login か) を閉じる
 *
 * セッション由来の分岐は原理的には `Vary: Cookie` でも宣言できるが、キャッシュキーの爆発と
 * cookie 全体への依存を招くため採らない。guest の 4xx/5xx には
 * NoStoreCacheHeadersForAuthenticatedPages (認証済みのみが対象) が付かないため、
 * ここで閉じる必要がある。
 *
 * ★**加算方式**で適用する (set() で Cache-Control を丸ごと書き換えない)。
 *   呼び出し側が既に積んだ directive を落とさないことが本クラスの契約であり、
 *   独立した Unit テスト (tests/Unit/Http/ErrorScreenCachePolicyTest.php) がそれを固定する
 *   (Response::setPrivate() は public を remove して private を add する = 矛盾も残さない)。
 */
final class ErrorScreenCachePolicy
{
    public static function apply(Response $response): void
    {
        // replace: false = 既存の Vary を落とさない (加算)。
        // 既に宣言済みのヘッダ名は積み直さない (二重適用で Vary が膨らまない)。
        $declared = [];
        foreach ($response->getVary() as $name) {
            if (is_string($name)) {
                $declared[] = strtolower($name);
            }
        }

        $additions = array_values(array_filter(
            [Header::INERTIA, Header::VERSION, 'Accept'],
            static fn (string $name): bool => ! in_array(strtolower($name), $declared, true),
        ));
        if ($additions !== []) {
            $response->setVary($additions, replace: false);
        }

        $response->headers->addCacheControlDirective('no-store');
        $response->setPrivate();
    }
}
