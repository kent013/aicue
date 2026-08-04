# Round 2: Round 1 指摘への対応

Round 1 の指摘に対する対応マトリクスと、修正後の概念設計全文を送ります。
再レビューして全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Warning] 観点3: `config('manual.analysis_min_japanese_ratio')` は mixed で PHPStan level 10 が荒れる
- 判断: **反論する** (根拠あり)
- 根拠: 本設計は `config()` ヘルパの mixed 戻り値ではなく、既存コードと同じ
  **型付きアクセサ** `config()->float('manual.analysis_min_japanese_ratio')` を使う。
  `vendor/laravel/framework/src/Illuminate/Config/Repository.php:134-146` の
  `float(string $key, $default = null): float` は `is_float()` でなければ
  `InvalidArgumentException` を投げてから `float` を返す実装であり、mixed は漏れない。
  既存の `SopTextExtractor` も `config()->integer('manual.analysis_min_text_bytes')` を
  同じ形で使っており (L49/L52)、専用アクセサを新設すると**同じ責務の二重管理**になる。
  値域検証 (`0.0 <= x <= 1.0`) を足す案も、既存の閾値 (`analysis_min_text_bytes` 等) が
  一切そうしていないため、本タスクだけ流儀を変えるのは一貫性を損なう (今必要なものだけ)。
- 対応内容: 概念設計に「型付きアクセサ `config()->float()` を使う」と明記して曖昧さを消す。

## [Warning] 観点4 / [Warning] 観点7: 「文字化け」と「日本語でない」を同じ例外・同じ文言に畳むのは事実とずれる
- 判断: **対応する** (指摘が正しい)
- 根拠: 判定しているのは「日本語の本文が一定量あるか」1 点であり、
  その不成立には (a) 文字が画像化 (b) PDF のテキスト埋め込みが壊れている
  (c) 日本語以外の手順書 の 3 通りがある。**この 3 つをアプリ側で識別する手段は無い**
  (言語判定器を持ち込むのはオーバーエンジニアリング)。したがって
  「例外を分ける」のではなく **factory 名とメッセージを『検証した事実』に一致させる**のが正しい。
- 対応内容:
  - factory 名を `unreadable()` → **`noJapaneseText()`** に変更 (検査内容と一対一)。
  - 文言を「手順書から**日本語の本文**を読み取れませんでした。文字が画像になっている /
    PDF のテキスト埋め込みが壊れている / 日本語以外の手順書、のいずれかの可能性があります。
    Excel・テキスト形式で保存し直してアップロードしてください。」へ。
    3 つの原因すべてに対して事実として正しく、次アクションも 1 つに定まる。
  - 「日本語の手順書のみ対応」がユーザーに伝わる文面にする (Codex の要求どおり)。

## [Warning] 観点5: 閾値 0.10 が将来「日本語比率の低い正当 SOP」を誤拒否するリスク
- 判断: **対応する**
- 根拠: 型番・設備コード主体の帳票系 SOP は現状の対照コーパスより低く出る可能性がある、
  という指摘は妥当。ただし閾値を下げると誤受理側 (AS 未修復 = 0.020) のマージンが縮む。
  **値を動かす前に「境界がどこか」をテストで固定し、運用で観測できるようにする**のが
  思考原則 (仕組みが機能していない段階で値を弄るな / データに真摯に向き合え) に合う。
- 対応内容:
  - テスト計画に「**有効だが日本語比率が低い合成 fixture**」を追加し、
    0.10 が通す側 / 落とす側の境界を明示的に固定する。
  - ゲート fail のログに **reason code** (`no_japanese_text`) と実測比率を含め、
    誤拒否が起きたら運用ログから検出できるようにする。
  - 閾値は config のままとし、**field データが出るまで値は動かさない**ことを設計に明記。

## [Suggestion] 観点6: 別 TODO 提案は「起票のみで実装しない」を実装計画上も明示せよ
- 判断: **対応する**
- 対応内容: 「スコープ外」節に、別 TODO 候補 (OCR/スキャン PDF の実態調査、
  T091 ceiling の再評価) は**本タスクでは起票提案までで実装しない**ことを明記。

## [Suggestion] 観点2: 期待値表を不変条件として十分に固定せよ
- 判断: **対応する**
- 対応内容: 同梱 5 本の期待値表 (AS=PASS + 日本語本文、他 4 本=例外) を
  データ駆動テストとして固定することを実装方針に明記済み。ラウンド 2 で明示化する。


---

## 修正後の概念設計 (全文)

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

### (B) 日本語本文ゲート (`assertJapaneseText`)

正規化後のテキストの**日本語文字比率**が
`config()->float('manual.analysis_min_japanese_ratio')` 未満なら
`AnalysisFailedException::noJapaneseText()` (新設) を投げ、**LLM に渡さない**。

> 名前は「検証した事実」と一対一にする。判定しているのは
> 「日本語の本文が一定量あるか」の 1 点であり、その不成立には
> (a) 文字が画像化 (b) テキスト埋め込みが壊れている (c) 日本語以外の手順書 の 3 通りがある。
> **この 3 つをアプリ側で識別する手段は無い** (言語判定器の持ち込みはオーバーエンジニアリング)。
> したがって例外を分けるのではなく、**文言側で 3 通りすべてを事実として正しく説明し、
> 次アクションを 1 つに定める**。

- 日本語文字 = かな (`U+3040-U+30FF`) / 漢字 (`U+4E00-U+9FFF`, `U+3400-U+4DBF`, `U+F900-U+FAFF`) /
  全角句読点 (`U+3001-U+303F`) / 全角英数記号 (`U+FF01-U+FF60`) / 半角カナ (`U+FF66-U+FF9D`)
- 分母は**空白を除いた文字数** (レイアウト由来の空白量に判定が引きずられないため)
- 閾値 **0.10** の導出:
  - 誤受理側: 観測された全破損クラスは 0.000 (AW / 欧文) 〜 0.020 (AS 未修復)。**5 倍のマージン**
  - 誤拒否側: 実データ AS 復元後 0.661。数値・型番を極端に詰めた対照でも 0.196。**約 2 倍のマージン**
  - 非対称 (拒否側に厳しい) にする理由: 誤受理 = 本タスクが潰そうとしている Critical そのもの
    (チケット消費 + 無意味なシナリオ生成)。誤拒否 = 明確な次アクション付きメッセージで受け止められる
  - 値は `config/manual.php` に置き、既存の閾値 (`analysis_min_text_bytes` 等) と同じ運用にする。
    取得は既存コードと同じ**型付きアクセサ** `config()->float(...)`
    (`Illuminate\Config\Repository::float()` は非 float を `InvalidArgumentException` で弾いて
    `float` を返すため、PHPStan level 10 に mixed が漏れない)
  - **残る誤拒否リスクへの構え**: 型番・設備コード主体の帳票系 SOP は対照コーパスより
    低く出る可能性がある。ただし「仕組みが機能していない段階で値を弄るな」に従い、
    **まず境界をテストで固定し、運用で観測できるようにしてから**判断する:
    (1) 「有効だが日本語比率が低い」合成 fixture を境界テストとして持つ
    (2) ゲート fail のログに reason code (`no_japanese_text`) と実測比率を出す
    (3) **field データが出るまで閾値の値は動かさない**

### (C) 文言の次アクション化

| 例外 | 現在 | 変更後 |
|---|---|---|
| `unextractable()` | 「テキストを抽出できません。画像・スキャンの手順書は現在未対応です。」 | 上記 + 「Excel・テキスト形式か、文字が選択できる PDF をアップロードしてください。」 |
| `noJapaneseText()` (新設) | — | 「手順書から日本語の本文を読み取れませんでした。文字が画像になっている / PDF のテキスト埋め込みが壊れている / 日本語以外の手順書、のいずれかの可能性があります。Excel・テキスト形式で保存し直してアップロードしてください。」 |

`noJapaneseText()` の文言は上記 3 原因すべてに対して**事実として正しく**、
次アクション (別形式で保存し直す) が 1 つに定まる。
「日本語の手順書のみ対応」がユーザーに伝わる文面でもある。

### (D) 観測 (データに真摯に向き合うため)

復元が発火したとき / ゲートで落としたときに `Log::info` を 1 行出す
(`source_document_id` / `sourceKind` / **reason code** / 修復前後の ja 比率 / byte 長のみ。
**本文は出さない**)。「この化けが現場でどれくらい起きているか」
「ゲートの誤拒否が起きていないか」を後から測れるようにする。

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
| 2 | 日本語本文ゲート + 閾値 config | 同上 / `config/manual.php` |
| 3 | 例外文言 (noJapaneseText 新設 + unextractable の次アクション追記) | `app/Exceptions/Manual/AnalysisFailedException.php` |
| 4 | テスト (合成 fixture + 境界 fixture + 同梱実 PDF 5 本の期待値表) | `tests/Unit/Manual/SopTextExtractorTest.php` |
| 5 | ドキュメント追記 | `docs/architecture.md` (L119 の SopTextExtractor 行) |

施策 4 の「同梱実 PDF 5 本の期待値表」は**不変条件として固定する**
(AS = PASS かつ本文が日本語 / 他 4 本 = `AnalysisFailedException`)。
pdfparser の更新や実装変更で挙動が変わったら CI が落ちる。

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

### 別 TODO 候補 (本タスクでは**起票提案までで実装しない**)

1. 実運用 SOP に占めるスキャン PDF の割合調査 → OCR / マルチモーダル取り込みの優先度判断
2. 本タスク完了後の AS 再走行結果に基づく T091 の 360s ceiling 再評価

## リスクと受容

| リスク | 評価 |
|---|---|
| 復元の誤変換で正当なテキストを壊す | 対照コーパス (日本語 / 英 / 独 / 仏) で**変化ゼロ**を実測。3 段検証すべてを満たさない区間は原文のまま |
| 日本語比率ゲートの誤拒否 | 実測最悪ケース 0.196 に対し閾値 0.10 (約 2 倍のマージン)。境界を合成 fixture でテスト固定し、ゲート fail を reason code 付きでログに残して**観測してから**値を判断する (field データが出るまで動かさない) |
| 非日本語 SOP を使っている既存ユーザーの後退 | 観測実績なし。`locale=ja` / プロンプト日本語 / 字幕フォント `Noto Sans CJK JP` / `doc/08 §182`「日本語原稿」から、**元々サポート範囲外**。落とすときは次アクションを提示する |
| pdfparser を上げたら挙動が変わる | 同梱 5 本の期待値表をテストに固定するため、変化は CI で検出される (施策 4) |

