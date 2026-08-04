**`app/Services/Manual/SopTextExtractor.php` — APPROVE**
- [Critical] `ratioBefore < analysis_min_japanese_ratio` のときだけ復元する構造により、**日本語本文ゲートを通る正当文書は復元処理に入らず不変**です。Round 2〜4 系列（`©`/`àé`/`àéàé`）の「正当文書誤変換」は閉じています。
- [Warning] `plain/spreadsheet` でも復元が動くため、`str_repeat('àé', 60)` のような低日本語比率入力は復元後に日本語比率が上がって通過し得ます（設計上許容なら問題なし、非許容なら別途ガード要）。
- [Suggestion] `source_kind != pdf` で復元発火した件数を運用監視指標として分離すると、逸脱検知が速くなります。

**`tests/Unit/Manual/SopTextExtractorTest.php` — APPROVE**
- [Critical] 実 PDF (`AS_作業手順書.pdf`) と人工化け復元、`àéàé` 系の不変ケース、閾値境界、媒体別エラー分岐まで押さえており、今回論点に対する回帰網羅は十分です。
- [Suggestion] 上記 Warning を仕様として固定するなら、`低比率 + àé反復` の期待動作（受理/拒否）を1本明示すると将来の解釈ぶれを防げます。

**`app/Exceptions/Manual/AnalysisFailedException.php` — APPROVE**
- [Suggestion] `unextractable` と `insufficientJapaneseText` の分離はUX上妥当で、次アクションも具体的です。

**`config/manual.php` — APPROVE**
- [Suggestion] `analysis_min_japanese_ratio=0.10` の根拠・運用方針（field data まで固定）が明文化されており妥当です。

**`docs/architecture.md` — APPROVE**
- [Suggestion] 実装逸脱点（0.50 / 2文字 / ゲート未満限定）が反映されており整合しています。必要なら `doc/10` 側にも同旨を同期すると設計追跡がさらに明確です。

**全体判定: APPROVED**