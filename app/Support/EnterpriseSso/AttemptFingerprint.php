<?php

declare(strict_types=1);

namespace App\Support\EnterpriseSso;

use App\Enums\EnterpriseSso\FingerprintPurpose;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use SensitiveParameter;

/**
 * **一時値**の指紋の導出。用途ごとに domain separation する。
 *
 * 鍵は **APP_KEY から用途別ラベル付きで導出する** (HKDF)。専用の秘密を新設しない —
 * 運用要件を 1 つ増やす価値が無い (思考原則 2)。判断の根拠:
 *   APP_KEY をローテートして失効するのは **進行中の試行 (10 分) と未確認の昇格 (60 分) だけ**である。
 *   ★**身元・接続・利用者はどれも指紋に依存しない** (subject は指紋を使わない) ので、
 *     ローテートで失われる永続的なものが無い。
 *   (対比: パスキーの利用者ハンドルは APP_KEY 由来だと**登録済みパスキーが全件無効**になるため
 *    専用の秘密を要求している。ここはその条件に当たらない。)
 *
 * ★**この型に永続する値の用途を足さない**。足すと上の根拠が崩れる。
 *
 * ## 鍵の導出の契約 (実装差を残さないために書く)
 *
 *  - 入力鍵: `config('app.key')` の **`base64:` 接頭辞を外して base64 復号したバイト列**
 *    (復号できない設定は例外。黙って文字列のまま使わない)
 *  - salt:   空 (アプリ内で 1 つの入力鍵しか使わないので salt に載せる情報が無い)
 *  - info:   **用途の値そのもの** (`FingerprintPurpose::value`)。これが domain separation の実体
 *  - 出力長: 32 バイト
 */
final class AttemptFingerprint
{
    /** 指紋の 16 進表記の長さ (DB の `char(64)` と対)。 */
    public const int HEX_LENGTH = 64;

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /** 用途つきの指紋 (16 進 64 文字)。 */
    public static function of(FingerprintPurpose $purpose, #[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, self::key($purpose));
    }

    /** CSPRNG で一時値を作る (base64url。パディングなし)。 */
    public static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function key(FingerprintPurpose $purpose): string
    {
        return hash_hkdf('sha256', self::inputKeyingMaterial(), 32, $purpose->value);
    }

    private static function inputKeyingMaterial(): string
    {
        $key = Config::string('app.key');

        if (! str_starts_with($key, 'base64:')) {
            throw new RuntimeException('APP_KEY は base64: 接頭辞つきで宣言されている必要があります。');
        }

        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('APP_KEY を base64 復号できませんでした。');
        }

        return $decoded;
    }
}
