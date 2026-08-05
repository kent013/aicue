<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * ログインに使える手段の集合 (LoginMethodInventory の戻り値)。
 *
 * 要素は 'password' / 'social:{provider}' / 'passkey' の識別子文字列。
 * **要素の種別で分岐する呼び出し側は現時点で存在しない** (使うのは「空か / 何個か」だけ)
 * ため、LoginMethodKind のような列挙は作らない (AGENTS.md 思考原則 2)。
 * UI に手段の内訳を出す要件が生まれたときに導入する。
 *
 * HTTP 応答には出さない内部 DTO (露出するのは LoginMethodRequiredDto のメッセージのみ)。
 */
final class LoginMethodSet
{
    /** @param list<string> $methods 'password' / 'social:google' / 'passkey' */
    public function __construct(public readonly array $methods) {}

    public function isEmpty(): bool
    {
        return $this->methods === [];
    }

    public function count(): int
    {
        return count($this->methods);
    }
}
