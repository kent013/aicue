# 対応マトリクス: design-review Round 1

## [Warning] 施策3: `bytes === 0` を一律 `unextractable()` にすると plain / spreadsheet の空ファイルまで「画像・スキャン未対応」文言になる
- 判断: **対応する** (指摘が正しい)
- 根拠: 0 バイトの意味は媒体で異なる。PDF の 0 バイトは「テキスト層が無い = 文字が画像」
  (同梱サンプル 3 本が実際にこれ) だが、`text/plain` / spreadsheet の 0 バイトは
  単に中身が空のファイルであり、「画像・スキャンは未対応」は事実と異なる案内になる。
- 対応内容: 媒体非依存の新例外は**作らない** (原因が違うものを 1 文言に畳むと
  どちらにも不正確な案内になる)。代わりに **`$kind === 'pdf'` のときだけ** 0 バイトを
  `unextractable()` に寄せ、plain / spreadsheet の 0 バイトは従来どおり `tooShort()`
  (「本文が短すぎます」= 空はその極端ケースであり事実として正しい) にする。
  条件 1 つの追加で済み、例外体系を増やさない。

## [Warning] 施策5: 0 byte の plain / spreadsheet の期待例外がテストで固定されていない
- 判断: **対応する**
- 対応内容: 追加テストに以下 2 件を足す。
  - T9 `空の text/plain は tooShort (画像未対応と弁別)` → `'本文が短すぎます'`
  - T10 `空の Spreadsheet は tooShort` → `'本文が短すぎます'`
  既存の T7 (空 PDF → `'テキストを抽出できません'`) と合わせて、
  **媒体ごとの 0 バイト文言体系が 3 本のテストで固定される**。

## [Suggestion] 施策1: 閾値を `extract()` でローカル変数化して渡す
- 判断: **対応する**
- 根拠: 「区間の採否」と「文書ゲート」が**同じ閾値**を使うという設計意図が、
  呼び出し側 1 箇所で読み取れるようになる。config 読み出しの重複もなくなる。
- 対応内容: `repairSjisMojibake(string $text, float $minJapaneseRatio)` /
  `decodeRunAsSjis(string $run, float $minJapaneseRatio)` に引数を追加し、
  `extract()` 冒頭で `$minJapaneseRatio = config()->float('manual.analysis_min_japanese_ratio');`
  を 1 回だけ読む。

## [Suggestion] 施策2 / 施策6: `analysis_min_japanese_ratio` の運用契約と評価対象を docs に明記
- 判断: **対応する**
- 対応内容: `docs/architecture.md` の `SopTextExtractor` 行に
  「評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率」
  「閾値変更は TODO 起票 + 実測の再提出を必須とする」を明記する。

## [Suggestion] 施策4: ログキーに `manual_stage` を追加する
- 判断: **見送る**
- 根拠: 既存のログ規約に `manual_stage` という語彙は存在せず
  (`AnalysisPipeline` は `step` => `AnalysisStep` の enum 値を使う)、
  本設計で新語彙を発明すると**消費者のいないキーの二重管理**になる。
  横断集計に必要な識別は `reason` (`sjis_mojibake_repaired` /
  `insufficient_japanese_text`) が既に一意に担っており、
  「今必要なものだけ作る」(思考原則 2) に照らして追加しない。
