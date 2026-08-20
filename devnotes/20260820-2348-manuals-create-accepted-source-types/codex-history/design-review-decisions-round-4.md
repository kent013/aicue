# 対応マトリクス: design-review Round 4

## [Critical] 施策 5: `RAW_HTML_EXEMPTIONS` がファイル名だけを鍵にしているため、免除済みファイル内で `{@html}` が増えても検出できない

- 判断: **対応する (提案どおり `file` + `occurrence` を鍵にする)**
- 根拠: 指摘のとおり、ファイル名だけの鍵では `Security.svelte` に 2 件目の `{@html}` を
  足しても同じ免除に一致し、件数 pin も 1 のままで緑になる。
  「免除が増えれば必ずレビューに見える」という主張が成立していなかった。
- 対応内容:
  - `RawHtmlExemption` に `occurrence`(ファイル内の `{@html}` の 1 始まりの序数)を追加。
  - **生 HTML を診断から分離**し、走査結果に `rawHtml: RawHtmlRecord[]`
    (`file` / `occurrence` / AST 位置)を持たせた。
    `ScanDiagnosticReason` から `opaque-html` を削除した。
  - 判定関数は `rawHtml` を実測集合として免除目録と `file` + `occurrence` で
    **両方向**比較し、実測件数・免除配列長・一意キー数の 3 つを件数 pin と一致させる。
    `occurrence` の正整数・キー重複も検査する。
  - 自己検査へ 21b(同一ファイルの `{@html}` 2 件で序数が 1, 2 になること)と
    38(**免除済みファイルに 2 件目が増えたら未登録として違反**)を追加した。

## [Warning] 施策 5: 関数シグネチャ (5 引数) と gate の呼び出し例 (3 引数) が一致していない

- 判断: **対応する**
- 対応内容: gate の検査項目の記述を 5 引数
  (`scan` / `FILE_INPUT_ACCEPT_INVENTORY` / `FILE_INPUT_COUNT` /
  `RAW_HTML_EXEMPTIONS` / `RAW_HTML_EXEMPTION_COUNT`) の呼び出しへ揃えた。

## [Warning] 施策 5: diagnostic の扱いの説明が矛盾している (全件違反 vs 免除で許す)

- 判断: **対応する (提案どおり判定順序を明確化)**
- 根拠: `opaque-html` を診断に混ぜたまま「診断は全件違反」と書いたため矛盾していた。
- 対応内容: 判定の順序を明記した。
  1. `diagnostics` は**無条件で違反**(免除の概念を持たない)
  2. `rawHtml` は実測集合として免除目録と両方向比較
  3. 免除に一致した実測**だけ**が違反にならない

  併せて自己検査 33 を「生 HTML 以外の診断 (`unresolved-type` 等)」を使う形へ変更し、
  免除検査 (35〜39) との責務を分離した。

## [施策 1〜4 の APPROVE]

- 判断: 受領 (記述変更なし)
