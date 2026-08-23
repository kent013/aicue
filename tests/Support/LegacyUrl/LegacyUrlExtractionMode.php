<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * URL 文字列の抽出方式 (走査器共通規約 (e) の「区切りの宣言」に当たる)。
 *
 * ★方式は **2 つだけ**である。ファイル種別ごとに別々の抽出器を持つと、種別が増えるたびに
 *   抽出器が増えて検出力の裏取りが追いつかない。**どちらに割り当てるか**を
 *   `LegacyUrlScanRoots` が拡張子ごとに宣言し、割り当ての無い拡張子は未分類 = 赤になる。
 */
enum LegacyUrlExtractionMode: string
{
    /**
     * ソースコードの**文字列リテラルだけ**を見る (PHP / TypeScript / JavaScript / Svelte / Python)。
     *
     * ★コメントと識別子は見ない。散文で旧 URL に言及する行 (「/dashboard 配下に留まる」等) は
     *   **参照ではない**ので検出しない。参照として効くのは実行時に URL になる文字列だけである。
     * ★リテラルの区切りは `'` / `"` / `` ` `` の 3 つ。ヒアドキュメント本文・
     *   実行時に連結する形 (`'/dash'.$suffix`) には**無言で効かない** (docblock に明記)。
     */
    case SourceLiteral = 'source_literal';

    /**
     * 文書・データの**全文**を見る (Markdown / JSON / TOML / YAML / TSV / テキスト / Blade)。
     *
     * ★終端は空白文字全般 (半角空白・タブ・改行・全角空白) と、閉じ記号・句読点の**列挙集合**
     *   (`LegacyUrlScanner::PLAIN_TEXT_TERMINATORS`)。**この列挙が保証範囲**であり、
     *   ここに無い終端は保証しない。
     */
    case PlainText = 'plain_text';
}
