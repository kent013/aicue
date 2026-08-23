<?php

declare(strict_types=1);

namespace App\ValueObjects\EnterpriseSso;

use App\Casts\EncryptedSecretCast;
use SensitiveParameter;

/**
 * 接続の秘密 (client secret) の値型。
 *
 * ★**暗黙の文字列化を持たない** — `__toString()` を実装しない。
 *   これにより「うっかり文字列連結・ログ・例外・DTO へ載る」経路が**型で消える**。
 * ★平文の取り出し口は **用途ごとに分かれた 2 つだけ**である。
 *   {@see self::revealForTokenExchange()} を呼んでよいのは OidcTokenExchanger だけ、
 *   {@see self::revealForEncryptionAtRest()} を呼んでよいのは EncryptedSecretCast だけであり、
 *   tests/Architecture/EnterpriseSsoSecretExposureGateTest が**それぞれ** exact-fit で pin する。
 *
 * ## 保証する範囲 (誇張しない)
 *
 * `__debugInfo()` が効くのは **`var_dump()` 系だけ**である。
 * ★**`var_export()` / `serialize()` / Reflection からは平文が見える**。
 *   任意の PHP の内省に対して隠せるとは**主張しない**。
 *   したがって守りは 3 層に分ける:
 *     1. 型 — 暗黙の文字列化を持たない (うっかりの連結・出力を消す)
 *     2. gate — **この値型をログ・dump・直列化の関数へ渡す記法**を G3 が禁じる
 *     3. **主たる証明** — 実挙動の漏洩テスト (例外・監査・ログ・要求の記録に出ない)
 */
final readonly class ConnectionSecret
{
    private function __construct(private string $plaintext) {}

    public static function fromPlaintext(#[SensitiveParameter] string $plaintext): self
    {
        return new self($plaintext);
    }

    /** ★token 交換だけが呼ぶ。他所からの呼び出しは gate が落とす。 */
    public function revealForTokenExchange(): string
    {
        return $this->plaintext;
    }

    /**
     * ★**保存のための暗号化だけ**が呼ぶ ({@see EncryptedSecretCast})。
     *
     * 用途を `revealForTokenExchange()` と分けているのは、**呼び出し元をそれぞれ
     * exact-fit で pin できる**ようにするためである。1 つの口にまとめると
     * 「保存のために要る」という理由で外向きの利用まで通ってしまう。
     */
    public function revealForEncryptionAtRest(): string
    {
        return $this->plaintext;
    }

    /** 空でないか (画面が「秘密が在るか」だけを知るための述語。平文を返さない)。 */
    public function isPresent(): bool
    {
        return $this->plaintext !== '';
    }

    /**
     * ★`var_dump()` 系にだけ効く。`var_export()` / `serialize()` / Reflection には効かない。
     *
     * @return array{client_secret: string}
     */
    public function __debugInfo(): array
    {
        return ['client_secret' => '********'];
    }
}
