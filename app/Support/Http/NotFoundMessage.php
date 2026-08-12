<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * JSON 404 の body に載せる固定文言 (bug-hunt F-1-03 / T158)。
 *
 * **例外の message は載せない**。Laravel は `ModelNotFoundException` を
 * `NotFoundHttpException($e->getMessage())` へ変換するため、既定のままだと
 * `No query results for model [App\Models\Take] 1` のように内部の名前空間が漏れる。
 * HTML 経路は日本語の 404 画面なので、**同じ 404 でも経路によって露出が非対称**になっていた。
 *
 * 文言は面で変える (**collapse 自体は `api/*` 以外へ全面適用する = 除外は作らない**):
 * - 機械向け経路 (`oauth` / `.well-known` とその配下) … プロトコル中立の英語
 * - それ以外 (撮影 PWA / web 面の XHR / 未定義 URL) … 人間向けの日本語
 *
 * **prefix は「安全性」ではなく「文言」しか決めない**。分類から漏れても起きるのは
 * 「機械向けに日本語が返る」見た目の問題だけで、情報露出は起きない。
 */
final readonly class NotFoundMessage
{
    /**
     * 機械向け経路の prefix (文言選択専用。安全性には影響しない)。
     * **prefix 直下そのものも含める** — `is('oauth/*')` は `oauth` に一致しないため。
     *
     * @var list<string>
     */
    private const MACHINE_FACING_PATTERNS = ['oauth', 'oauth/*', '.well-known', '.well-known/*'];

    public const HUMAN_MESSAGE = 'お探しのページまたはデータは見つかりませんでした。';

    public const MACHINE_MESSAGE = 'Not Found';

    public function __construct(public string $message) {}

    public static function forRequest(Request $request): self
    {
        foreach (self::MACHINE_FACING_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return new self(self::MACHINE_MESSAGE);
            }
        }

        return new self(self::HUMAN_MESSAGE);
    }
}
