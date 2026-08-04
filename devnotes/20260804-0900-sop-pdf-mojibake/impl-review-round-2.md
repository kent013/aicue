**ファイル別判定**

- `app/Services/Manual/SopTextExtractor.php` — **CHANGES_REQUESTED**
  - [Warning] `repairSjisMojibake()` を媒体非依存で適用する方針自体は理解できますが、現行 3 段判定だと**正当な CP1252 文字の一部を非 PDF で誤変換**し得ます（`app/Services/Manual/SopTextExtractor.php:72`, `app/Services/Manual/SopTextExtractor.php:136`）。
  - [Warning] 具体例: `©`(0xA9), `À`(0xC0) などは `SJIS-win` で単バイト有効かつ復号後に半角カナ帯へ写り、`JAPANESE_PATTERN`（`FF66-FF9D`）にカウントされます。結果として「日本語が増えた + 比率>=0.10」を満たし、`"作業手順書 © 2026"` のような正当テキストでも置換発火余地があります。  
  - [Suggestion] 回避策は `pdf` 限定に戻すか、少なくとも採用条件を「CJK/かなの増加（半角カナ除外）」「SJIS 2 バイト列の成立を必須」などへ厳格化するのが安全です。

- `tests/Unit/Manual/SopTextExtractorTest.php` — **CHANGES_REQUESTED**
  - [Warning] Round 1 の回帰テスト追加は前進ですが、上記 false positive を塞ぐケース（例: `©`, `À`, `Á` 等の CP1252 高位単バイト）を未固定です（`tests/Unit/Manual/SopTextExtractorTest.php:182` 付近）。
  - [Suggestion] 「非 PDF の正当日本語 + `©/À/Á` 混在で 1 文字も変化しない」テストを追加すると、反論方針を CI で担保できます。

- `app/Exceptions/Manual/AnalysisFailedException.php` — **APPROVE**
  - [Suggestion] 文言整理と `insufficientJapaneseText()` 追加は意図に整合しています（`app/Exceptions/Manual/AnalysisFailedException.php:15`）。

- `config/manual.php` — **APPROVE**
  - [Suggestion] `analysis_min_japanese_ratio` の導入と根拠コメントは妥当です（`config/manual.php:34`）。

- `docs/architecture.md` — **APPROVE**
  - [Suggestion] 抽出→復元→日本語比率ゲートの責務追記は設計整合です（`docs/architecture.md:119`）。

**Round 1 指摘の解消状況**

- #1（非 PDF への適用リスク）: **未解消（部分対応）**  
  - 反論・追加テストは確認したが、CP1252 高位単バイト群による誤発火余地が残るため、根本リスクはまだ閉じていません。
- #2（ログ比率基準の不一致）: **解消済み**
- #3（非 PDF + CP1252 混在回帰テスト不足）: **概ね解消（ただし網羅不足）**
- #4（`file_get_contents` の失敗握り潰し）: **解消済み**

**全体判定: CHANGES_REQUESTED**