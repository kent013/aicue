<?php

declare(strict_types=1);

namespace App\Support\EnterpriseSso;

use SensitiveParameter;

/**
 * `client_secret_basic` の Authorization ヘッダの組み立て (RFC 6749 §2.3.1)。
 *
 * ★仕様は「client_id と client_secret を **application/x-www-form-urlencoded の規則で
 *   符号化してから** `:` で連結し base64 する」と定めている。
 *   自前の `rawurlencode` 連結にしない — 空白・`+`・`:`・非 ASCII で壊れる
 *   (`rawurlencode` は空白を `%20` にするが、この規則では `+` である)。
 */
final class BasicCredentials
{
    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    public static function header(
        #[SensitiveParameter] string $clientId,
        #[SensitiveParameter] string $clientSecret,
    ): string {
        // urlencode() が application/x-www-form-urlencoded の規則 (空白 → `+`)。
        return 'Basic '.base64_encode(urlencode($clientId).':'.urlencode($clientSecret));
    }
}
