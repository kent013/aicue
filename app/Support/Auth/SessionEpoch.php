<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * セッション世代の印 (bfcache 秘匿・再検証で使う) の単一の出所。
 *
 * 「印」は *いまのセッションを一意に指す短い文字列* で、セッション ID から
 * 鍵付きハッシュで導出する。**セッション ID そのものは画面側へ出さない**
 * (世代 cookie は画面側から読めるため、生の ID を載せると XSS で
 * セッションの乗っ取りに直結する)。
 *
 * 印が変わる契機はセッション ID が変わる契機と 1 対 1 である
 * (ログイン・ログアウト・セッション再生成)。これが「別の利用者の画面が
 * 復元された」ことを検出できる根拠になる。
 *
 * **APP_KEY を入れ替えると全ての印が一斉に変わる**。復元済み文書は
 * すべて読み直しへ倒れるだけで、開示側へは倒れない。
 */
final class SessionEpoch
{
    /** 画面側から読める cookie の名前 (暗号化の除外登録が必須)。 */
    public const COOKIE_NAME = 'session_epoch';

    /** プローブ要求が描画世代を運ぶヘッダの名前。 */
    public const HEADER_NAME = 'X-Session-Epoch';

    /** Inertia 共有 prop のキー (描画世代の運び方)。 */
    public const SHARED_PROP_KEY = 'sessionEpoch';

    /**
     * 印の書式 (32 文字の 16 進小文字)。空文字と null を同一視しない。
     *
     * 末尾の `D` は「`$` を文字列の最後だけに一致させる」指定である。付けないと
     * PHP の `$` は**末尾の改行 1 個を許す**ため、`…ef\n` が書式として通ってしまう
     * (画面側の JavaScript の `$` は改行を許さないので、付けないと 2 つの実装がずれる)。
     */
    public const VALUE_PATTERN = '/^[0-9a-f]{32}$/D';

    /** 同じ鍵を他用途と共有しないための区切り (用途の分離)。 */
    private const PURPOSE = 'bfcache-session-epoch:v1';

    /** セッション ID から印を導出する。 */
    public static function forSession(string $sessionId): string
    {
        $digest = hash_hmac('sha256', self::PURPOSE.'|'.$sessionId, Config::string('app.key'));

        return substr($digest, 0, 32);
    }

    /** その要求の現世代。session を持たない要求では null。 */
    public static function current(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        return self::forSession($request->session()->getId());
    }

    /** 書式が正しいか (長さ・文字種)。 */
    public static function isWellFormed(?string $value): bool
    {
        return $value !== null && preg_match(self::VALUE_PATTERN, $value) === 1;
    }

    /**
     * 描画世代と現世代が一致するか。
     *
     * **どちらかが無い / 書式が違うときは一致としない** (fail-closed)。
     * 比較は hash_equals で行い、値はログにも応答にも出さない。
     */
    public static function matches(?string $submitted, ?string $current): bool
    {
        if (! self::isWellFormed($submitted) || ! self::isWellFormed($current)) {
            return false;
        }

        return hash_equals((string) $current, (string) $submitted);
    }
}
