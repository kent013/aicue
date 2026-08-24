<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

/**
 * 依頼文 factory ごとの「応答の扱い」の分類 (テスト側 enum)。
 *
 * ★`string` にすると綴り間違いがどの検査にも当たらず**分類漏れ**になるため型で閉じる。
 *   正本は `tests/Architecture/LlmResponseDecodePointGateTest.php` の目録。
 */
enum LlmResponseHandling
{
    /** 応答を復号点 (`App\Support\Manual\LlmJson::decode`) 経由で構造化データとして読む */
    case Decoded;

    /** 提供元が形を保証する経路 (構造化出力)。**現在 0 件** (枠だけ持つ) */
    case ProviderShape;

    /** 応答を構造化データとして読まない (自由文) */
    case FreeText;
}
