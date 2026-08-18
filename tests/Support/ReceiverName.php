<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;

/**
 * 静的呼び出しの受け手 (receiver) の解決結果。
 *
 * ★**`string|null` にしない**。null は「受け手が無い」とも「解決できなかった」とも読めるため、
 *   利用側が `!== null` だけを見て未解決を落とす形 (= 無言の見逃し) を許してしまう。
 *   3 状態を型で持たせることで、利用側の判定を読んだときに
 *   **未解決をどう扱っているかが必ず目に見える**ようにする。
 *
 * ★**保証範囲を誇張しない**: 型は「未解決だと分かること」までを保証する。
 *   `is()` / `startsWith()` は未解決を `false` へ畳むので、**これらだけで書いた判定は
 *   未解決を落とす**。未解決を拾う側へ倒すかどうかは利用側の判断であり、
 *   その判断を書き忘れないことは型では強制できない (レビューで見る)。
 *   完全修飾名そのものを取り出す `fqcn()` だけは、未解決のまま呼ぶと例外になる。
 */
final readonly class ReceiverName
{
    private function __construct(
        public ReceiverResolution $resolution,
        private ?string $value,
    ) {}

    /** 完全修飾名まで解決できた受け手。 */
    public static function resolved(string $fqcn): self
    {
        return new self(ReceiverResolution::Resolved, $fqcn);
    }

    /** 受け手は書かれているが静的に確定できない (変数 / `static` / `parent` / 式)。 */
    public static function unresolved(): self
    {
        return new self(ReceiverResolution::Unresolved, null);
    }

    /** 受け手を持たない種別。 */
    public static function absent(): self
    {
        return new self(ReceiverResolution::Absent, null);
    }

    public function isResolved(): bool
    {
        return $this->resolution === ReceiverResolution::Resolved;
    }

    public function isUnresolved(): bool
    {
        return $this->resolution === ReceiverResolution::Unresolved;
    }

    /** 解決済みの完全修飾名。未解決 / 受け手なしで呼ぶのは利用側の誤りなので例外にする。 */
    public function fqcn(): string
    {
        if ($this->value === null) {
            throw new LogicException(
                '受け手が解決できていない site から完全修飾名を取り出そうとしました '
                .'(解決状態: '.$this->resolution->name.')。'
                .'照合の前に isResolved() / isUnresolved() で分岐してください。',
            );
        }

        return $this->value;
    }

    /** 解決済みで、かつ指定の完全修飾名と一致するか。 */
    public function is(string $fqcn): bool
    {
        return $this->value === $fqcn;
    }

    /** 解決済みで、かつ指定の名前空間接頭辞の下にあるか。 */
    public function startsWith(string $prefix): bool
    {
        return $this->value !== null && str_starts_with($this->value, $prefix);
    }
}
