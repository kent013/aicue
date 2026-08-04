`app/Services/Manual/SopTextExtractor.php:188` — **CHANGES_REQUESTED**
- [Critical] Round 3 の穴は**完全には閉じていません**。`RUN_MIN_MULTIBYTE_JAPANESE = 2` でも、`àéàé` のような「高位バイト2組」は `SJIS-win` で日本語2文字に偶発復号され、`gained=2` かつ `japaneseRatio=1.0` で採用されます。  
  具体例: `研削àéàé作業の手順書。ネジを締める。` → `研削琺琺作業の手順書。ネジを締める。`（正当テキスト破壊）。
- [Warning] `repairSjisMojibake()` が `pdf` 限定でなく `plain/spreadsheet` にも常時適用されるため、上記誤変換の適用面が広いです（`extract()` 内の呼び出し位置: `app/Services/Manual/SopTextExtractor.php:108`）。
- [Suggestion] 判定強化（例: 連続区間長の下限追加、証拠条件の追加）か、少なくとも復元適用範囲を `pdf` に限定する方向を検討してください。

`tests/Unit/Manual/SopTextExtractorTest.php:186` — **CHANGES_REQUESTED**
- [Warning] `àé` / `Àéé` / `©éé`（1文字偶発）回帰は固定できていますが、**2文字偶発**（`àéàé` 等）の不変テストが未追加です。今回の残存経路を検知できません。
- [Suggestion] `ASCII を挟まない高位バイト列 àéàé / ÀéÀé / ©é©é` を dataset に追加し、「正当テキスト不変」を固定してください。

`app/Exceptions/Manual/AnalysisFailedException.php:15` — **APPROVE**
- [Suggestion] エラー文言の分離（`unextractable` と `insufficientJapaneseText`）は運用上わかりやすく妥当です。

`config/manual.php:34` — **APPROVE**
- [Suggestion] `analysis_min_japanese_ratio` の導入意図と運用コメントは一貫しています。

`docs/architecture.md:119` — **APPROVE**
- [Suggestion] 抽出→復元→日本語ゲートの責務分離が明確です。

**全体判定: CHANGES_REQUESTED**