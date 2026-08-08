<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースから抽出した 1 つの参照 site (走査器に依存しない中立表現)。
 *
 * ★`tokenIndex` を持たせるのは、呼び出し引数の分類 (`ExternalClientBoundaryScanner` の
 *   disk 名判定) のように「site の直後のトークン列」を見たい利用者があるため。
 *   走査器の内部表現を漏らさずに済ませる唯一の実用的な逃げ道である。
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
        /** 呼び出しの receiver を解決できた場合の FQCN (できなければ null) */
        public ?string $receiver,
        /** 名前が完全修飾 / 修飾名として書かれていたか (alias 経由なら false) */
        public bool $qualified,
        public ScanScopeKind $scopeKind,
        public ?string $class,
        public ?string $callable,
    ) {}
}
