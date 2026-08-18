<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースから抽出した 1 つの参照 site (走査器に依存しない中立表現)。
 *
 * ★`tokenIndex` を持たせるのは、呼び出し引数の分類 (`ExternalClientBoundaryScanner` の
 *   disk 名判定) のように「site の直後のトークン列」を見たい利用者があるため。
 *   走査器の内部表現を漏らさずに済ませる唯一の実用的な逃げ道である。
 * ★`receiver` は**解決状態つきの値** (`ReceiverName`) である。「受け手が無い」と
 *   「解決できなかった」を 1 つの null へ潰さないため、利用側の判定を読めば
 *   未解決をどう扱っているかが分かる。**未解決を拾う側へ倒すかどうかは利用側の判断**であり、
 *   型がそれを強制するわけではない (`ReceiverName` の docblock を参照)。
 */
final readonly class ReferenceSite
{
    public function __construct(
        public string $path,
        public int $line,
        public int $tokenIndex,
        public ReferenceKind $kind,
        /** 名前参照 / 構築なら解決済み FQCN、呼び出しならメソッド名 */
        public string $name,
        /** 静的呼び出しの受け手 (解決結果。受け手を持たない種別は `ReceiverName::absent()`) */
        public ReceiverName $receiver,
        /** 名前が完全修飾 / 修飾名として書かれていたか (alias 経由なら false) */
        public bool $qualified,
        public ScanScopeKind $scopeKind,
        public ?string $class,
        public ?string $callable,
    ) {}
}
