# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足】
- 本件は既存アプリのバグ修正設計であり、UI/frontend の変更を含みません。
- 設計中の事実主張はすべて実測またはソース確認で裏取りされています。実測スクリプトは
  `devnotes/20260804-0900-sop-pdf-mojibake/probe/` に保存されており、読み込み可能です。
- 対象コードは `app/Services/Manual/SopTextExtractor.php`、
  `app/Exceptions/Manual/AnalysisFailedException.php`、`config/manual.php` です。

---

## 概念設計

# 概念設計: SOP PDF の文字化けが全ガードを素通りして LLM に渡る問題の是正

- task_key: P
- 優先度: Critical 相当 (North Star の起点である「SOP → AI がカット設計」が、
  実サンプルで**壊れたテキストのまま LLM に流れている**)
- 対象コード: `app/Services/Manual/SopTextExtractor.php` / `app/Exceptions/Manual/AnalysisFailedException.php` /
  `config/manual.php`

## 背景・課題

`smalot/pdfparser` v2.12.5 の `getText()` が、日本語 PDF に対して
**CP932 バイト列を Windows-1252 として解釈した mojibake** を返す。結果は**正当な UTF-8** になるため、
`SopTextExtractor::ensureUtf8()` の UTF-8 検証を素通りし、そのまま LLM に渡っている。

### 実測 (本設計で再実行して確認。スクリプトは `probe/` に保存)

同梱サンプル 5 本 (`doc/reference/sample-sop/`) の抽出結果:

| ファイル | 抽出 bytes | フォント解決 | 現状の帰結 |
|---|---|---|---|
| AP_オペレーション手順書.pdf | 0 | ページに font 0 個 | unextractable で正しく拒否 |
| AT_作業手順書.pdf | 0 | 同上 | 正しく拒否 |
| 作業要領書.pdf | 0 | 同上 | 正しく拒否 |
| **AS_作業手順書.pdf** | 6451 | Type0 / `90ms-RKSJ-H` | **mojibake が素通りして LLM へ** |
| **AW_作業手順書 (1).pdf** | 1661 | ページに font 0 個 (本文は glyph ノイズ) | **ノイズが素通りして LLM へ** |

**5 本中 1 本も正しい日本語を返さない。うち 2 本は全ガードを通過する。**

### 原因の所在 (vendor のコードを読んで特定済み。推測なし)

**(1) AS = 定義済み CJK CMap の未対応**

AS のフォント辞書 (実測):

```
[32_0] Subtype=Type0 BaseFont=MSGothic ToUnicode=no Encoding=ElementName(90ms-RKSJ-H)
[34_0] Subtype=Type0 BaseFont=MSMincho ToUnicode=no Encoding=ElementName(90ms-RKSJ-H)
[37_0] Subtype=Type0 BaseFont=HiddenHorzOCR ToUnicode=YES Encoding=ElementName(Identity-H)
```

`Font::decodeContent()` の分岐 (`vendor/smalot/pdfparser/src/Smalot/PdfParser/Font.php:501-`) は
`ToUnicode` → `Encoding` → autodetect の順。`Encoding` が `ElementName` の場合は
`decodeContentByEncodingElement()` (L643) が PDF encoding 名を iconv 名へ写像するが、その表 (L659) は

```php
'StandardEncoding' => 'ISO-8859-1',
'MacRomanEncoding' => 'MACINTOSH',
'WinAnsiEncoding'  => 'CP1252',
```

の **3 件しかない**。`90ms-RKSJ-H` は未知 → `null` を返し、
`decodeContentByAutodetectIfNecessary()` (L676) に落ちる。そこは

```php
if (mb_check_encoding($text, 'UTF-8')) { return $text; }
return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');   // ← ここで CP932 バイト列が化ける
```

`vendor` 全体を検索しても `RKSJ` / `Adobe-Japan` / `UniJIS` の文字列は 1 件も存在しない
(= pdfparser 2.12.5 は**定義済み CJK CMap を一切サポートしていない**)。
`Config` クラスにも該当する設定項目は無い (fontSpaceLimit / horizontalOffset /
retainImageContent / decodeMemoryLimit / dataTmFontInfoHasToBeIncluded / ignoreEncryption のみ)。

**(2) `ensureUtf8()` のフォールバックは原理的に発火しない**

pdfparser が既に Windows-1252 として decode 済みなので出力は valid UTF-8。
`mb_check_encoding($text,'UTF-8')` が真になり、`mb_detect_encoding` の救済分岐には到達しない。
docblock の「PDF の壊れた埋め込み対策」はこの種類を構造的に捕まえられない。

**(3) AS は mojibake と正規日本語の混在**

AS は**スキャン + 隠し OCR テキスト層**を持つ PDF (`HiddenHorzOCR` フォント = Identity-H + ToUnicode)。
実測: 全 3292 文字のうち **63 文字は正規の日本語** (OCR 層由来。`非鉄金属` `レスト台` `砥石` 等)、
残りが RKSJ 由来の mojibake。**文書全体を一括変換する素朴な修復はこの 63 文字を破壊する。**

**(4) AW は復元不能な別クラスの破損**

AW はページの Font リソースが 0 個で、本文は 1 文字ずつ空白区切りの glyph ノイズ
(`T R ” { ‘ 5 ? ‹ ‡ C a o …`)。CP1252→CP932 往復でも日本語は 1 文字も出ない (実測 ja=0)。
**つまり「化けを直す」だけでは足りず、「直らなかったものを渡さない」ゲートが要る。**

## 仮説

- **H1**: AS 系 (定義済み CJK CMap 未対応による Windows-1252 誤解釈) は、
  **CP1252 レパートリ内の極大連続区間を CP932 として読み直す**ことで、
  OCR 層の正規日本語を壊さずに復元できる。
- **H2**: 復元可否によらず、「抽出テキストが日本語の手順書本文として読めるか」を
  **positive gate** で確認すれば、AS 未修復 / AW / 欧文ノイズを一律に落とせる。
- **H3**: 日本語であることを要求する gate は新しい制約ではない。
  `doc/08 §182` が翻訳機能の入力を「**日本語原稿**」と定義しており (`feature_multilang` は
  出力字幕の多言語化)、`config/app.php` の `locale` も `ja`。
  **設計上すでに日本語前提であるものを、機械的に強制するだけ**である。

### 検証結果 (実測。`probe/probe-final.php`)

採用アルゴリズム (後述) をそのまま実装して 5 本 + 対照コーパスに適用:

| 入力 | 修復前 ja 比率 | 修復後 ja 比率 | 変化 | gate 判定 |
|---|---|---|---|---|
| AS_作業手順書.pdf | 0.020 | **0.661** | 有 | **PASS** |
| AW_作業手順書 (1).pdf | 0.000 | 0.000 | 無 | REJECT |
| AP / AT / 作業要領書 (0 byte) | 0.000 | 0.000 | 無 | REJECT (先に tooShort) |
| 正当な日本語 SOP | 0.762 | 0.762 | **無** | PASS |
| 日本語 (型番・数値多め) | 0.196 | 0.196 | **無** | PASS |
| 英語 SOP | 0.000 | 0.000 | **無** | REJECT |
| ドイツ語 SOP (ä ö ü ß) | 0.000 | 0.000 | **無** | REJECT |
| フランス語 SOP (é à è) | 0.000 | 0.000 | **無** | REJECT |
| 人工 SJIS 化け文字列 | 0.000 | 1.000 | 有 | PASS |

- AS 復元後の本文 (先頭): 「作業手順書 作業名 グラインダー研削作業 想定危険 はさまれ・巻き込まれ・飛散
  ・指の切創・研削屑が目に入る トラブル事例 … 1 I主スイッチの解放 2 Iトング・レスト・の確認 …」
  = **完全な日本語の手順書**。
- OCR 層由来の正規日本語 63 文字は **3/3 保存**された (欠落 0)。
- **正当な日本語・欧文コーパスは 1 文字も変化しない** (誤変換ゼロ)。
- 性能: 129KB を 0.014 秒。PDF パース自体も 5 本すべて 0.05 秒以内 / peak 20MB。

## 改善アイデア

`SopTextExtractor` に **2 つの独立した関門**を入れる。順序は
`ensureUtf8` → **(A) 化けの復元** → `normalize` → `tooShort` / `tooLarge` → **(B) 可読性ゲート**。

### (A) SJIS 誤解釈の復元 (`repairSjisMojibake`)

**「CP1252 の 256 バイトと 1:1 対応する文字だけで構成された極大連続区間」**を単位として、
その区間を CP932 として読み直す。日本語が有意に出現した区間だけ採用する。

- 対象文字集合は**推測ではなく実測で確定した 256 文字の全単射**:
  `U+0000-U+007F` ∪ `{U+0081, U+008D, U+008F, U+0090, U+009D}` ∪ `U+00A0-U+00FF` ∪
  CP1252 固有の 27 文字 (`U+20AC U+201A U+0192 U+201E U+2026 U+2020 U+2021 U+02C6 U+2030
  U+0160 U+2039 U+0152 U+017D U+2018 U+2019 U+201C U+201D U+2022 U+2013 U+2014 U+02DC
  U+2122 U+0161 U+203A U+0153 U+017E U+0178`)。
  BMP 全 65,536 コードポイントを走査して「mbstring の CP1252 往復が同一になる集合」と
  この文字クラスが**完全一致 (不一致 0 件)** であることを検証済み (`probe/probe-cp1252-table.php`)。
  ※ `U+0081/008D/008F/0090/009D` は CP1252 未定義バイトだが mbstring が素通しする。
  これらは **Shift_JIS の主要な lead byte** (`0x81` = JIS 記号行, `0x8D`=作, `0x8F`=順, `0x90`=書)
  であり、この 5 文字を集合に含めないと復元は成立しない (実測で確認)。
- 区間ごとに 3 段の**検証付き**変換 (推測変換をしない):
  1. `mb_convert_encoding(run,'CP1252','UTF-8')` の**逆変換が元と一致**する (可逆 = CP1252 由来)
  2. 得たバイト列が **`SJIS-win` として妥当**である (`mb_check_encoding`)
  3. 復号結果の**日本語文字数が増え、かつ日本語比率が閾値以上**である
  → 3 つすべてを満たした区間のみ置換。1 つでも欠けたら**その区間は原文のまま**。
- 非 CP1252 文字 (= OCR 層の正規日本語) は区間の外にあるため**触らない**。

### (B) 可読性ゲート (`assertReadable`)

正規化後のテキストの**日本語文字比率**が `config('manual.analysis_min_japanese_ratio')` 未満なら
`AnalysisFailedException::unreadable()` (新設) を投げ、**LLM に渡さない**。

- 日本語文字 = かな (`U+3040-U+30FF`) / 漢字 (`U+4E00-U+9FFF`, `U+3400-U+4DBF`, `U+F900-U+FAFF`) /
  全角句読点 (`U+3001-U+303F`) / 全角英数記号 (`U+FF01-U+FF60`) / 半角カナ (`U+FF66-U+FF9D`)
- 分母は**空白を除いた文字数** (レイアウト由来の空白量に判定が引きずられないため)
- 閾値 **0.10** の導出:
  - 誤受理側: 観測された全破損クラスは 0.000 (AW / 欧文) 〜 0.020 (AS 未修復)。**5 倍のマージン**
  - 誤拒否側: 実データ AS 復元後 0.661。数値・型番を極端に詰めた対照でも 0.196。**約 2 倍のマージン**
  - 非対称 (拒否側に厳しい) にする理由: 誤受理 = 本タスクが潰そうとしている Critical そのもの
    (チケット消費 + 無意味なシナリオ生成)。誤拒否 = 明確な次アクション付きメッセージで受け止められる
  - 値は `config/manual.php` に置き、既存の閾値 (`analysis_min_text_bytes` 等) と同じ運用にする

### (C) 文言の次アクション化

| 例外 | 現在 | 変更後 |
|---|---|---|
| `unextractable()` | 「テキストを抽出できません。画像・スキャンの手順書は現在未対応です。」 | 上記 + 「Excel・テキスト形式か、文字が選択できる PDF をアップロードしてください。」 |
| `unreadable()` (新設) | — | 「手順書の文字を正しく読み取れませんでした。PDF のテキスト埋め込みに問題がある可能性があります。Excel・テキスト形式でアップロードし直してください。」 |

### (D) 観測 (データに真摯に向き合うため)

復元が発火したとき / ゲートで落としたときに `Log::info` を 1 行出す
(`source_document_id` / `sourceKind` / 修復前後の ja 比率 / byte 長のみ。**本文は出さない**)。
「この化けが現場でどれくらい起きているか」を後から測れるようにする。

## 期待効果

- **使命への貢献**: North Star の起点 (SOP → AI がカット設計) が、実サンプル AS で
  **0 → 正しい日本語手順書として機能する**ようになる。
- **Critical の解消**: 壊れたテキストが LLM に渡る経路が構造的に閉じる。
  無意味なシナリオ生成によるチケット浪費とユーザーの混乱を止める。
- **詰みを作らない**: 落とすときは必ず次アクション (別形式で保存し直す) を提示する。

## 実装方針 (概要)

| # | 施策 | 変更ファイル |
|---|---|---|
| 1 | SJIS 誤解釈の区間単位復元 | `app/Services/Manual/SopTextExtractor.php` |
| 2 | 可読性ゲート + 閾値 config | 同上 / `config/manual.php` |
| 3 | 例外文言 (unreadable 新設 + unextractable の次アクション追記) | `app/Exceptions/Manual/AnalysisFailedException.php` |
| 4 | テスト (合成 fixture + 同梱実 PDF 5 本の期待値表) | `tests/Unit/Manual/SopTextExtractorTest.php` |
| 5 | ドキュメント追記 | `docs/architecture.md` (L119 の SopTextExtractor 行) |

## 制約・前提

- **フレームワークのレンジ内での対処可能性は先に潰した** (思考原則 1):
  - pdfparser の `Config` に該当設定は無い (全 6 項目を確認)
  - `Font::getIconvEncodingNameOrNullByPdfEncodingName()` は `private`、
    `Font\FontType0` は空の継承クラスで、`PDFObject::factory()` (L1193) が
    `'\Smalot\PdfParser\Font\Font'.$subtype` を**ハードコードで new** するため、
    **DI・サブクラス・設定のいずれでも差し替え点が無い**
  - よって「vendor を触らずにアプリ側で後段修復する」以外に選択肢が無い
- 既存テストへの影響: 現行の SOP fixture はすべて日本語のため、
  新ゲートで落ちるものは無い (全 fixture を確認済み)
- `analysis_max_text_bytes` / `analysis_deadline_seconds` / prompt YAML の
  `client_options.timeout` には触れない (`AnalysisTokenBudgetInvariantTest` /
  `AnalysisTimeBudgetInvariantTest` が固定している値のため)

## T091 (時間 budget 是正) との関係

bug-hunt F-1-01 で 2/2 タイムアウトした SOP は本件の `AS_作業手順書.pdf` であり、
「サイズが原因」という観測は**サイズと文字化けが交絡**していた。ただし:

- T091 が入れた **360s ceiling の導出は AS に依存していない**。
  `devnotes/20260804-0021-analysis-provider-retry/conceptual-design.md` の実測
  (`max_tokens=16000` を飽和生成させて 273.9s / 58.4 token/s) から
  「`max_tokens` 上限までの 1 呼び出しに必要な時間」として導かれている。
  よって**交絡の判明は ceiling の根拠を毀損しない**。
- **結論: T091 を巻き戻さない。本設計では T091 が触れた値に一切手を入れない。**
- ただし本タスク完了後に **AS で再走行して (a) 解析が成功すること (b) 実所要時間**を測るべき。
  復元後の AS は 5006 bytes と小さく、出力 token も現実的な量に収まるはずで、
  もし ceiling に対して大幅な余裕が確認できたら **ceiling の再評価 (引き下げ) は別 TODO** とする。
  本設計はその提案までを行い、値の変更は行わない。

## 0 バイト 3 本 (スキャン PDF) の扱い

- **OCR / マルチモーダル入力は明確にスコープ外**。`doc/10 §10.7` オープン項目 1 が
  「v1 はテキスト抽出可能な手順書に限定して回避」と既に決めている。
- 本タスクで行うのは**文言の次アクション化のみ** (施策 3)。
- ただし「同梱サンプル 5 本中 3 本がスキャン PDF、残り 2 本も現行では使い物にならない」は
  **プロダクトとして無視できないシグナル**である。
  「実運用の SOP の何割がスキャン PDF か」を確かめ、OCR / マルチモーダル取り込みの
  優先度を判断するための**別 TODO の起票を提案する** (本設計では実装しない)。

## 検討したが採用しなかった案

| 案 | 却下理由 |
|---|---|
| **`mb_detect_encoding` の候補に CP932 を足すだけ** | 発火しない。pdfparser の出力は valid UTF-8 であり、既存フォールバックはそこに到達しない (原因の所在 (2)) |
| **文書全体を一括で CP1252→CP932 変換** | AS の OCR 層由来の正規日本語 63 文字が `?` に化けて失われる (実測)。区間単位でなければならない |
| **poppler (`pdftotext`) 等の外部バイナリへ切替** | 定義済み CMap を正しく扱える可能性は高いが、(a) 新しい system 依存とサブプロセス実行面の追加、(b) それでも AW / スキャン 3 本は救えない、(c) 可読性ゲートは結局必要 — で、本タスクの Critical (壊れたテキストを LLM に渡さない) に対して費用が過大。**将来 AS 系の PDF が多いと分かった時点で別タスクとして評価する** |
| **vendor の fork / composer patch** | 保守コストと供給網リスクに対して見返りが小さい。アプリ側 60 行で同等の結果が得られることを実測で確認済み |
| **EUC-JP / ISO-2022-JP も復元候補に加える** | 実測サンプルは 100% `90ms-RKSJ-H` (Shift_JIS)。候補を増やすほど誤変換確率が上がる。今必要なものだけ作る (思考原則 2)。EUC-JP バイト列を CP932 として読んでも日本語比率は上がらず**採用されない** = 可読性ゲートで安全に落ちる |
| **「文字化けらしさ」を負の指標で検出 (Latin-1 補助の比率が高い等)** | **AW を捕まえられない** (AW は Latin-1 補助が 1% しかない ASCII ノイズ)。破損パターンの列挙は網羅できない。positive gate (日本語であることを要求) の方が単純かつ網羅的 |
| **言語非依存の「可読性」判定 (単語長分布・エントロピー等)** | 辞書や統計モデルが要る。日本語前提は `doc/08 §182` で既に決まっており、そこに乗るのが正しい (オーバーエンジニアリング禁止) |
| **`ExtractedText` に `repaired` フラグを追加** | 消費者が居ない。今必要なものだけ作る |

## スコープ外

- OCR / スキャン PDF のマルチモーダル取り込み (`doc/10 §10.7` オープン項目 1)
- 非日本語 SOP の対応 (v1 は日本語原稿前提。`doc/08 §182`)
- pdfparser の差し替え / fork / upstream への PR
- T091 が設定した時間 budget の値の変更
- 抽出テキストの構造化 (表・段組みの復元) — 本タスクは「読める文字にする」までを扱う

## リスクと受容

| リスク | 評価 |
|---|---|
| 復元の誤変換で正当なテキストを壊す | 対照コーパス (日本語 / 英 / 独 / 仏) で**変化ゼロ**を実測。3 段検証すべてを満たさない区間は原文のまま |
| 日本語比率ゲートの誤拒否 | 実測最悪ケース 0.196 に対し閾値 0.10 (約 2 倍のマージン)。閾値は config なので運用で調整可能 |
| 非日本語 SOP を使っている既存ユーザーの後退 | 観測実績なし。`locale=ja` / プロンプト日本語 / 字幕フォント `Noto Sans CJK JP` / `doc/08 §182`「日本語原稿」から、**元々サポート範囲外**。落とすときは次アクションを提示する |
| pdfparser を上げたら挙動が変わる | 同梱 5 本の期待値表をテストに固定するため、変化は CI で検出される (施策 4) |

