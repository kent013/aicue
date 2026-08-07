<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 静的走査で検出した site の帰属 scope 種別。
 *
 * ★`null` に潰さない。「クラス本体の外」と「匿名クラスの中」は違反理由が異なる
 *   (前者はファイルスコープの抜け道、後者は目録を迂回する抜け道) ため、
 *   3 値で保持して失敗メッセージに理由を出せるようにする。
 */
enum ScanScopeKind
{
    /** 名前付きの型宣言 (class / interface / trait / enum) の本体。 */
    case NamedClass;

    /** `new class (...) { ... }` の本体。 */
    case AnonymousClass;

    /** クラスの外 (Pest のファイルスコープ closure を含む)。 */
    case FileScope;
}
