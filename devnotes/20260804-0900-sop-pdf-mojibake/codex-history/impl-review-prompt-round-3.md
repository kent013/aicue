## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

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

## あなたの役割

あなたは Laravel + Svelte アプリのコードレビュアーである。TODO T096 の実装差分をレビューせよ。
これは **Round 3** である (Round 1/2 は別セッションのため文脈が無い。必要な履歴は下に再掲する)。

### 背景 (T096 が塞ぐ問題)

`smalot/pdfparser` v2.12.5 が定義済み CJK CMap (`90ms-RKSJ-H` 等) を一切サポートしておらず、
CP932 バイト列を Windows-1252 として decode する。結果は**正当な UTF-8** になるため
`SopTextExtractor::ensureUtf8()` の検証を素通りし、**文字化けテキストがそのまま LLM に渡っている**
(North Star の入口が壊れている Critical)。

対策は 2 つの独立した関門:
- (A) **区間単位**の SJIS 誤解釈復元。3 段の検証を**すべて**満たした区間だけ置換する。
  文書一括変換は禁止 (実サンプル `AS_作業手順書.pdf` は mojibake と隠し OCR 層由来の正規日本語 63 文字の混在)。
- (B) 日本語本文比率ゲート (`config('manual.analysis_min_japanese_ratio')` = 0.10 未満は LLM に渡さず拒否)。

**絶対条件**: 正当な日本語テキストを 1 文字も変えない / 正当な欧文を復元対象にしない。

### Round 2 で指摘され、Round 3 で修正した点 (最重要の確認対象)

Round 2 の指摘「CP1252 高位単バイト (`©`=0xA9 / `À`=0xC0 …) は CP932 では半角カナ 1 バイトなので、
復号すると `JAPANESE_PATTERN` の FF66-FF9D に写り、正当テキストを誤変換しうる」は**実測で再現し、
指摘どおり不具合だった**。修正内容は下記の対応マトリクスと差分を参照。

### レビュー観点

1. **Round 2 指摘が本当に閉じたか** — 修正基準に穴が残っていないか。特に
   「復元されるべき本物の化けが復元されなくなった」退行が無いか
2. **正確性** — アルゴリズムの正しさ、境界条件、例外経路、副作用
3. **設計との一致性** — 逸脱 (区間閾値を文書ゲートから分離した点) が妥当か
4. **PHPStan level 10 適合性** — mixed の漏れ、型の widen、@phpstan-ignore の混入がないか
5. **テスト網羅性** — 不変条件がテストで固定されているか
6. **セキュリティ** — untrusted な顧客手順書テキストの扱い、ログへの本文漏洩、ReDoS 等

### 出力形式

- ファイルごとに判定 (APPROVE / CHANGES_REQUESTED) と指摘
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- Round 2 指摘が解消されたかを明記する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

---

## Round 2 の Codex レビュー結果 (全文)

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
## Round 2 に対する対応マトリクス

# Codex 実装レビュー Round 2 — 対応マトリクス (T096)

レビュー返答: `../impl-review-round-2.md` (全体判定: CHANGES_REQUESTED)
プロンプト: `impl-review-prompt-round-2.md`

## [Warning] CP1252 高位単バイト (`©`=0xA9 / `À`=0xC0 等) が半角カナ帯へ写り、正当テキストを誤変換しうる

- **判断: 対応する (指摘は正しい。Round 1 の私の反論が不十分だった)**
- **根拠**: `probe/probe-run-criteria.php` で実測して**再現した**。Codex の指摘どおりであり、
  さらに実際にはより広い範囲に影響していた:

  | 文字 | CP932 | 復号結果 | JAPANESE_PATTERN 判定 |
  |---|---|---|---|
  | `©` | 0xA9 | `ｩ` (U+FF69) | **日本語とカウントされる** |
  | `°` | 0xB0 | `ｰ` (U+FF70) | 同上 |
  | `±` | 0xB1 | `ｱ` (U+FF71) | 同上 |
  | `À` | 0xC0 | `ﾀ` (U+FF80) | 同上 |
  | `½` | 0xBD | `ｽ` (U+FF7D) | 同上 |

  CP932 の半角カナは**単バイト 0xA1-0xDF** であり、これは CP1252 の Latin-1 補助と同じバイト帯である。
  つまり「半角カナが増えた」は 2 バイト列誤解釈の証拠にならない。現行実装 (基準 A) は
  **正当な日本語手順書を実際に壊していた**:

  ```
  作業手順書 © 2026 株式会社サンプル    → 作業手順書 ｩ 2026 株式会社サンプル   ★変化★
  温度は 25° 前後、公差 ±0.5mm         → 温度は 25ｰ 前後、公差 ｱ0.5mm        ★変化★
  ```

  著作権表記・温度・公差は**現場の SOP に日常的に出る**。設計の絶対条件
  「正当な日本語テキストを 1 文字も変えない」に対する Critical 級の違反である。

  さらに実測中に Codex が挙げていない第 2 のクラスも見つけた: 仏語 `créé` (0xE9 0xE9) は
  偶然 CP932 の妥当な 2 バイト列として成立し、半角カナを除いても漢字 1 文字を生む
  (`créé` → `cr鳬`)。**半角カナの除外だけでは塞がらない。**

- **対応内容**: 区間の採用基準を「文書ゲートと同じ閾値の流用」から分離し、2 点を変更した。

  1. **採否の count-increase を全角日本語 (`MULTIBYTE_JAPANESE_PATTERN` = 半角カナ除外) で判定**。
     CP932 の 2 バイト列からしか出ない文字が増えたことだけを証拠に採る。
  2. **区間の下限比率を `RUN_MIN_JAPANESE_RATIO = 0.50` (過半数) として文書ゲートから分離**。
     文書ゲート (`manual.analysis_min_japanese_ratio` = 0.10) は「この手順書を受け入れるか」の
     **運用ポリシーの下限**であり、区間採否は「この区間が CP932 の誤解釈だと断定してよいか」の
     **証拠の強さ**である。問いが違うものを 1 つの値に畳んでいたのが誤りだった
     (Round 1 で私が「同じ基準」と書いた点を撤回する)。

  Codex の代替案「`pdf` 限定に戻す」は**この不具合を直さない** — 正しく decode された日本語 PDF に
  `© 2026` があれば同じく壊れる。原因は媒体ではなく採用基準にあるため、基準側を直した。

### 実測による分離 (probe/probe-run-criteria.php)

| 入力 | 基準 A (round 1) | 基準 C (採用) |
|---|---|---|
| `作業手順書 © 2026 株式会社サンプル …` | ★変化★ | 不変 |
| `作業手順書 © 株式会社サンプル` (孤立) | ★変化★ | 不変 |
| `… 2020 年に créé された。…` | ★変化★ | 不変 |
| `温度は 25° 前後、公差 ±0.5mm …` | ★変化★ | 不変 |
| `担当 André Müller。…` | 不変 | 不変 |
| `(Café ラインの Größe 点検) … à la carte …` | 不変 | 不変 |
| 人工 SJIS 化け | 復元 OK | **復元 OK** |
| 実 PDF `AS_作業手順書.pdf` | ja 0.661 / 本文 OK | **ja 0.661 / 本文 OK (同一)** |
| AS の隠し OCR 層 (`非鉄金属` `レスト台` `砥石`) | 保存 | **保存** |

閾値 0.50 の位置づけ: **実 PDF AS の採用区間の比率は 0.819〜0.874**、
**誤発火候補の比率は 0.20〜0.33**。両者の間に大きな空きがあり、
過半数 (0.50) はその空きの中にある**原理的な境界** (「この区間は日本語文である」と言い切れる線) であって、
データに合わせて刻んだ値ではない。config には出さない (運用者が回す knob ではなく、
アルゴリズムが成立するための証拠基準であるため)。

### 受容した副作用

半角カナ**だけ**で構成された化け区間 (単バイト 0xA1-0xDF のみ) は復元されず原文のまま残る。
このバイト列は正当な Latin-1 テキストと**原理的に区別できない**ため、
設計方針「1 つでも検証が欠けたら原文のまま (推測変換をしない)」に従い、直さないことを選ぶ。
残った場合は文書ゲート (B) が日本語不足として拒否するので、壊れたテキストが LLM に渡ることはない。

---

## [Warning] `©/À/Á` 等を固定する回帰テストが不足

- **判断: 対応する**
- **対応内容**: `正当な日本語に CP1252 文字が混在しても復元は発火しない (非 PDF)` を dataset 化し、
  4 ケース (アクセント記号 / `©` / `°` `±` / 仏語 `créé`) で「1 文字も変化しない」を固定した。

---

## その他 (APPROVE 項目)

`AnalysisFailedException` / `config/manual.php` / `docs/architecture.md` は Round 2 で APPROVE。
Round 1 の #2 (ログ比率の基準ずれ) と #4 (`file_get_contents` の失敗握り潰し) は解消済みと確認された。

## 未反映の指摘

なし。

## 判断根拠となった probe の実測出力 (probe/probe-run-criteria.php)

```
===== (1) Codex round 2 の指摘 (半角カナ帯) の再現 =====
  © (0xA9) → SJIS-win 復号 = ｩ (U+FF69) / 日本語判定=YES 全角判定=no
  À (0xC0) → SJIS-win 復号 = ﾀ (U+FF80) / 日本語判定=YES 全角判定=no
  Á (0xC1) → SJIS-win 復号 = ﾁ (U+FF81) / 日本語判定=YES 全角判定=no
  ± (0xB1) → SJIS-win 復号 = ｱ (U+FF71) / 日本語判定=YES 全角判定=no
  ½ (0xBD) → SJIS-win 復号 = ｽ (U+FF7D) / 日本語判定=YES 全角判定=no

===== (2) 正当な日本語テキストに対する 3 基準の挙動 =====
  著作権表記      A:★変化★ → 作業手順書 ｩ 2026 株式会社サンプル 無断転載を禁ず。安全確認を徹底すること。 B:不変 C:不変
  著作権表記 (孤立) A:★変化★ → 作業手順書 ｩ 株式会社サンプル B:不変 C:不変
  仏語の混在 (créé) A:★変化★ → 作業手順書 この設備は 2020 年に cr鳬 された。ネジを締める。安全確認。 B:★変化★ → 作業手順書 この設備は 2020 年に cr鳬 された。ネジを締める。安全確認。 C:不変
  度・単位記号   A:★変化★ → 作業手順書 温度は 25ｰ 前後、公差 ｱ0.5mm とする。ネジを締める。 B:不変 C:不変
  欧文人名の混在 A:不変 B:不変 C:不変
  Café / Größe      A:不変 B:不変 C:不変

===== (3) 実 PDF AS_作業手順書.pdf の区間統計 =====
  区間数: 5
  採用区間数: A=2 B=2 C=2
  C 採用区間の ratio: min=0.819 max=0.874
  基準 A: ja比率=0.661 / 'グラインダー研削作業'=OK / '保護メガネ'=OK
  基準 B: ja比率=0.661 / 'グラインダー研削作業'=OK / '保護メガネ'=OK
  基準 C: ja比率=0.661 / 'グラインダー研削作業'=OK / '保護メガネ'=OK
  OCR 層 '非鉄金属' 保存 (基準 C): OK
  OCR 層 'レスト台' 保存 (基準 C): OK
  OCR 層 '砥石' 保存 (基準 C): OK

===== (4) 人工 SJIS 化けが 3 基準とも復元されること =====
  A:OK B:OK C:OK
  半角カナのみの化け: A:復元 B:不変 C:不変
```

## 現在の実装差分 (Round 2 の修正を反映済み・全文)

```diff
diff --git a/app/Exceptions/Manual/AnalysisFailedException.php b/app/Exceptions/Manual/AnalysisFailedException.php
index e6791df..e73983d 100644
--- a/app/Exceptions/Manual/AnalysisFailedException.php
+++ b/app/Exceptions/Manual/AnalysisFailedException.php
@@ -12,10 +12,28 @@
  */
 final class AnalysisFailedException extends RuntimeException
 {
-    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ) */
+    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ・PDF から 1 バイトも取れない) */
     public static function unextractable(): self
     {
-        return new self('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
+        return new self(
+            'テキストを抽出できません。画像・スキャンの手順書は現在未対応です。'
+            .'Excel・テキスト形式か、文字が選択できる PDF をアップロードしてください。'
+        );
+    }
+
+    /**
+     * 抽出はできたが日本語の本文が閾値に満たない
+     * (文字化け / テキスト埋め込みの破損 / 日本語以外の手順書)。
+     * 3 つの原因をアプリ側で識別する手段は無いため、どの原因でも実行できる次アクションを示す。
+     */
+    public static function insufficientJapaneseText(): self
+    {
+        return new self(
+            '手順書から十分な日本語の本文を読み取れませんでした。'
+            .'文字が画像になっている / PDF のテキスト埋め込みが壊れている / '
+            .'日本語以外の手順書、のいずれかの可能性があります。'
+            .'日本語の手順書を、Excel・テキスト形式か文字を選択できる PDF でアップロードしてください。'
+        );
     }
 
     /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
diff --git a/app/Services/Manual/SopTextExtractor.php b/app/Services/Manual/SopTextExtractor.php
index 7e2062c..d1a7518 100644
--- a/app/Services/Manual/SopTextExtractor.php
+++ b/app/Services/Manual/SopTextExtractor.php
@@ -7,6 +7,7 @@
 use App\DataTransferObjects\Manual\Analysis\ExtractedText;
 use App\Exceptions\Manual\AnalysisFailedException;
 use App\Models\SourceDocument;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Storage;
 use PhpOffice\PhpSpreadsheet\Cell\Cell;
 use PhpOffice\PhpSpreadsheet\IOFactory;
@@ -20,9 +21,59 @@
  * - 分岐はアップロード時に内容 sniff 済みの mime を使う (クライアント拡張子は信頼しない)
  * - 抽出不能/実質空/バイト上限超過は AnalysisFailedException (ユーザー向け文言)
  * - byteLength (strlen = UTF-8 bytes) が token budget 判定値 (config manual.analysis_max_text_bytes)
+ * - SJIS 誤解釈 (pdfparser の定義済み CJK CMap 非対応) を区間単位で復元し、
+ *   日本語本文が閾値未満のテキストは LLM に渡さない (manual.analysis_min_japanese_ratio)
  */
 class SopTextExtractor
 {
+    /**
+     * CP1252 の 256 バイトと 1:1 対応する文字だけからなる極大連続区間。
+     *
+     * pdfparser は定義済み CJK CMap (90ms-RKSJ-H 等) を知らないため、CP932 バイト列を
+     * Windows-1252 として decode してしまう (Font::decodeContentByAutodetectIfNecessary)。
+     * その化けを元バイト列へ戻せる文字集合が、この 256 文字の全単射である。
+     * U+0081/008D/008F/0090/009D は CP1252 未定義バイトだが mbstring が素通しし、かつ
+     * Shift_JIS の主要 lead byte (0x81 = JIS 記号行 / 0x8D / 0x8F / 0x90) なので必須。
+     * BMP 全走査で「mbstring の CP1252 往復が同一になる集合」と完全一致を検証済み
+     * (devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-cp1252-table.php)。
+     */
+    private const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
+        .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
+        .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
+        .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';
+
+    /** 日本語文字 (かな / 漢字 / 全角句読点 / 全角英数記号 / 半角カナ) */
+    private const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
+        .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';
+
+    /**
+     * CP932 の **2 バイト列からしか出ない**日本語文字 (JAPANESE_PATTERN から半角カナを除いたもの)。
+     *
+     * 半角カナ (U+FF66-FF9D) は CP932 では単バイト 0xA1-0xDF であり、これは CP1252 の
+     * Latin-1 補助 (`©`=0xA9 / `±`=0xB1 / `°`=0xB0 / `À`=0xC0 …) と同じバイト帯である。
+     * つまり「半角カナが増えた」ことは 2 バイト列の誤解釈の証拠にならない
+     * (正当な `作業手順書 © 2026` が `作業手順書 ｩ 2026` へ壊れる。probe/probe-run-criteria.php)。
+     * 区間の採否は必ずこちらで判定する。
+     */
+    private const MULTIBYTE_JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
+        .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}]/u';
+
+    /** 比率の分母 = 空白を除いた文字数 (レイアウト由来の空白量に判定を引きずられない) */
+    private const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';
+
+    /**
+     * 区間を復元済みへ差し替える下限比率 = **過半数が日本語文字であること**。
+     *
+     * 文書ゲート (manual.analysis_min_japanese_ratio) とは問いが違う。文書ゲートは
+     * 「この手順書を受け入れるか」の運用ポリシーの下限であり、こちらは
+     * 「この区間が CP932 の誤解釈であると断定してよいか」の証拠の強さである。
+     * 短い区間 (`créé` = 0xE9 0xE9 が偶然 CP932 の 2 バイト列として成立する等) は
+     * 低い比率でも偶然通ってしまうため、文書ゲートの閾値を流用してはならない。
+     * 実測 (probe/probe-run-criteria.php): 実 PDF AS の採用区間は 0.819〜0.874、
+     * 正当テキストの誤発火候補は 0.20〜0.33 で、過半数 (0.50) は両者の間にある。
+     */
+    private const RUN_MIN_JAPANESE_RATIO = 0.50;
+
     public function extract(SourceDocument $document): ExtractedText
     {
         $contents = Storage::get($document->file_path);
@@ -30,7 +81,7 @@ public function extract(SourceDocument $document): ExtractedText
 
         $kind = $this->kindFor($document->mime);
         try {
-            $text = match ($kind) {
+            $extracted = match ($kind) {
                 'pdf' => $this->fromPdf($contents),
                 'spreadsheet' => $this->fromSpreadsheet($contents),
                 'plain' => $contents,
@@ -42,10 +93,27 @@ public function extract(SourceDocument $document): ExtractedText
             throw AnalysisFailedException::unextractable();
         }
 
-        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
-        $text = $this->normalize($text);
+        $extracted = $this->ensureUtf8($extracted); // JSON 化・UserInput 生成を不正バイトで壊さない
+        $repaired = $this->repairSjisMojibake($extracted);
+        $text = $this->normalize($repaired);
+        if ($repaired !== $extracted) {
+            // 現場でこの化けがどれだけ起きているかを後から測れるようにする (本文は出さない)。
+            // 比率は下段の日本語本文ゲートと同じ normalize 後基準で出す (運用分析で直接比較できる)
+            Log::info('SOP テキストの SJIS 誤解釈を復元しました', [
+                'reason' => 'sjis_mojibake_repaired',
+                'source_document_id' => $document->id,
+                'source_kind' => $kind,
+                'japanese_ratio_before' => round($this->japaneseRatio($this->normalize($extracted)), 4),
+                'japanese_ratio_after' => round($this->japaneseRatio($text), 4),
+            ]);
+        }
 
         $bytes = strlen($text);
+        if ($bytes === 0 && $kind === 'pdf') {
+            // PDF から 1 バイトも取れない = 文字が画像 (スキャン手順書)。
+            // plain / spreadsheet の空ファイルは原因が違うので tooShort のままにする
+            throw AnalysisFailedException::unextractable();
+        }
         if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
             throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
         }
@@ -53,9 +121,89 @@ public function extract(SourceDocument $document): ExtractedText
             throw AnalysisFailedException::tooLarge();
         }
 
+        $ratio = $this->japaneseRatio($text);
+        if ($ratio < config()->float('manual.analysis_min_japanese_ratio')) {
+            Log::info('SOP テキストの日本語本文が不足しています', [
+                'reason' => 'insufficient_japanese_text',
+                'source_document_id' => $document->id,
+                'source_kind' => $kind,
+                'japanese_ratio' => round($ratio, 4),
+                'byte_length' => $bytes,
+            ]);
+
+            throw AnalysisFailedException::insufficientJapaneseText();
+        }
+
         return new ExtractedText($text, $bytes, $kind);
     }
 
+    /**
+     * CP932 バイト列を Windows-1252 として解釈された文字列 (mojibake) の復元。
+     *
+     * CP1252 レパートリ内の**極大連続区間**だけを単位に読み直す。区間外の文字
+     * (= 正しく decode された日本語。AS_作業手順書.pdf では隠し OCR 層由来の 63 文字)
+     * には一切触れないため、混在文書でも既存の正しいテキストを壊さない。
+     */
+    private function repairSjisMojibake(string $text): string
+    {
+        $repaired = preg_replace_callback(
+            self::CP1252_RUN_PATTERN,
+            fn (array $matches): string => $this->decodeRunAsSjis((string) $matches[0]),
+            $text,
+        );
+
+        return is_string($repaired) ? $repaired : $text;
+    }
+
+    /**
+     * 1 区間を SJIS-win として読み直す。3 段の検証をすべて満たしたときだけ置換し、
+     * 1 つでも欠けたら原文をそのまま返す (推測変換をしない)。
+     *   1. CP1252 へ可逆に戻せる (= この区間が CP1252 誤解釈由来である)
+     *   2. 得たバイト列が SJIS-win として妥当である
+     *   3. 復号で **2 バイト列由来の**日本語が増え、かつ区間の過半数が日本語文字である
+     */
+    private function decodeRunAsSjis(string $run): string
+    {
+        // encoding 名がリテラルのため mb_convert_encoding は string を返す (不正名は ValueError)
+        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
+        if (mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
+            return $run;
+        }
+        if (! mb_check_encoding($bytes, 'SJIS-win')) {
+            return $run;
+        }
+
+        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
+        if (! mb_check_encoding($decoded, 'UTF-8')) {
+            return $run;
+        }
+        // 半角カナ (CP932 では単バイト 0xA1-0xDF = CP1252 の Latin-1 補助と同じ帯) の増加は
+        // 2 バイト列誤解釈の証拠にならないため、採否の判定からは除く
+        if ($this->countBy(self::MULTIBYTE_JAPANESE_PATTERN, $decoded)
+            <= $this->countBy(self::MULTIBYTE_JAPANESE_PATTERN, $run)) {
+            return $run;
+        }
+
+        // 偶然 CP932 として成立しただけの短い区間を弾く (過半数が日本語文字であることを要求)
+        return $this->japaneseRatio($decoded) >= self::RUN_MIN_JAPANESE_RATIO ? $decoded : $run;
+    }
+
+    /** パターンに一致する文字数 */
+    private function countBy(string $pattern, string $text): int
+    {
+        $count = preg_match_all($pattern, $text);
+
+        return is_int($count) ? $count : 0;
+    }
+
+    /** 空白を除いた文字数に占める日本語文字の比率 (0.0〜1.0) */
+    private function japaneseRatio(string $text): float
+    {
+        $assessable = $this->countBy(self::NON_SPACE_PATTERN, $text);
+
+        return $assessable === 0 ? 0.0 : $this->countBy(self::JAPANESE_PATTERN, $text) / $assessable;
+    }
+
     /**
      * mime → 抽出方式。未知 mime はアップロード時 sniff で弾かれている前提だが、
      * 防御的に unextractable で落とす (LLM に渡さない)。
diff --git a/config/manual.php b/config/manual.php
index b899381..cda3f8c 100644
--- a/config/manual.php
+++ b/config/manual.php
@@ -27,9 +27,19 @@
     // 安全側上界のため strlen で保証する (AnalysisTokenBudgetInvariantTest が算術を固定)
     'analysis_max_text_bytes' => 150_000,
 
-    // 抽出テキストの実質空判定 (これ未満は「テキストを抽出できません」)
+    // 抽出テキストの実質空判定 (これ未満は「本文が短すぎます」。PDF の 0 バイトのみ unextractable)
     'analysis_min_text_bytes' => 100,
 
+    // 抽出テキストが「日本語の手順書本文」と言えるかの下限 (空白を除く文字数に占める
+    // かな/漢字/全角記号/半角カナの比率)。これ未満は LLM に渡さず insufficientJapaneseText。
+    // v1 の原稿は日本語 (doc/08 §182 / config/app.php の locale=ja) であることが前提。
+    // 導出 (devnotes/20260804-0900-sop-pdf-mojibake): 破損クラスの実測は 0.000 (glyph ノイズ /
+    // 欧文) 〜 0.020 (SJIS 化け未修復) で誤受理側に 5 倍、正当な日本語 SOP は復元後 0.661 /
+    // 型番を極端に詰めた対照でも 0.196 で誤拒否側に約 2 倍のマージンがある。
+    // 誤拒否は運用ログ (reason=insufficient_japanese_text) で観測できるようにしてあり、
+    // field データが出るまでこの値は動かさない。
+    'analysis_min_japanese_ratio' => 0.10,
+
     // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
     'analysis_stale_after_minutes' => 30,
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 7a7a534..4dc6e68 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -116,7 +116,7 @@ ## 主要 Service (テンプレート同梱)
 | `Manual/SourceDocumentService` | AI-CUE: SOP (SourceDocument) の保存。追記型 immutable (差し替え = 新規行)。専用 route 経路は VideoManual 行ロック + draft/ready guard、MIME は内容 sniff で再判定 (polyglot 対策) |
 | `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / recoverStale = stale 回復 cron 本体) |
 | `Manual/AnalysisPipeline` | AI-CUE: 解析パイプライン本体 (extract→decompose→generate→terminal tx)。チケット 2 フェーズ (予約冪等キー = analysis_jobs.ticket_reservation_id、materialize + commit + succeeded を単一 tx で原子化)。LLM 出力の有界リトライ (JSON 検証失敗のみ最大 2 回) |
-| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定) |
+| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** + **日本語本文ゲート** (`manual.analysis_min_japanese_ratio` 未満は LLM に渡さず insufficientJapaneseText。評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率。**閾値の変更は TODO 起票 + 実測の再提出を必須とする**) + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。0 バイトは媒体で弁別する (pdf = unextractable / plain・spreadsheet = tooShort) |
 | `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / recoverStale・reconcileOutputs = cron 本体) |
 | `Manual/RenderPipeline` | AI-CUE: レンダパイプライン本体 (startJob→buildManifest→compose→upload→finalize)。チケット 2 フェーズ (予約冪等キー = render_jobs.ticket_reservation_id、complete + commit + succeeded を terminal tx で原子化)。version スナップショット固定 (§10.8-6) |
 | `Manual/CutSequencer` | AI-CUE: カット表示順 (step→配下 point) と表示ラベル (手順N/急所N-M) の導出 (読み取り専用) |
diff --git a/tests/Unit/Manual/SopTextExtractorTest.php b/tests/Unit/Manual/SopTextExtractorTest.php
index 05e33eb..6c36eb5 100644
--- a/tests/Unit/Manual/SopTextExtractorTest.php
+++ b/tests/Unit/Manual/SopTextExtractorTest.php
@@ -8,11 +8,13 @@
 use Illuminate\Support\Facades\Storage;
 use PhpOffice\PhpSpreadsheet\Spreadsheet;
 use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
+use Webmozart\Assert\Assert;
 
 /*
  * SOP テキスト抽出 (施策 7):
  * - plain / xlsx の抽出、UTF-8 strict 検証 (SJIS 変換 / バイナリ拒否)
  * - 実質空 (min_text_bytes 未満) / バイト上限超過の明示エラー
+ * - SJIS 誤解釈 (pdfparser の CJK CMap 非対応) の区間単位復元 / 日本語本文ゲート (T096)
  */
 
 /** 保存済み SourceDocument (Storage::fake 上) を作る */
@@ -27,6 +29,32 @@ function storedDocument(string $contents, string $mime, string $ext): SourceDocu
     ]);
 }
 
+/**
+ * 同梱サンプル SOP の中身 (回帰コーパス)。
+ * fixture を複製せず参照するため、欠落は黙ってスキップせず明示的に失敗させる。
+ */
+function sampleSopContents(string $name): string
+{
+    $path = base_path("doc/reference/sample-sop/{$name}");
+    $contents = file_exists($path) ? file_get_contents($path) : false;
+    if (! is_string($contents)) {
+        throw new RuntimeException("回帰コーパスのサンプル SOP を読めません: {$path}");
+    }
+
+    return $contents;
+}
+
+/** CP932 バイト列を Windows-1252 として読んだときの化け (pdfparser が返すもの) を合成する */
+function sjisMojibake(string $japanese): string
+{
+    $sjis = mb_convert_encoding($japanese, 'CP932', 'UTF-8');
+    Assert::string($sjis);
+    $mojibake = mb_convert_encoding($sjis, 'UTF-8', 'CP1252');
+    Assert::string($mojibake);
+
+    return $mojibake;
+}
+
 test('plain テキストをそのまま抽出する (byteLength = strlen)', function (): void {
     Storage::fake();
     $text = str_repeat("手順1 部品を取り付ける\n", 10);
@@ -115,3 +143,170 @@ function storedDocument(string $contents, string $mime, string $ext): SourceDocu
     expect(fn () => app(SopTextExtractor::class)->extract($document))
         ->toThrow(AnalysisFailedException::class);
 });
+
+/*
+ * T096: SJIS 誤解釈の区間単位復元 + 日本語本文ゲート
+ */
+
+test('CP1252 として読まれた SJIS テキストは日本語へ復元される', function (): void {
+    Storage::fake();
+    $document = storedDocument(
+        sjisMojibake(str_repeat('作業手順書 ネジを締める 安全確認 保護メガネ着用。', 5)),
+        'text/plain',
+        'txt',
+    );
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toContain('ネジを締める');
+    expect($extracted->text)->toContain('保護メガネ');
+    expect($extracted->byteLength)->toBe(strlen($extracted->text));
+});
+
+test('正当な日本語テキストは 1 文字も変化しない', function (): void {
+    Storage::fake();
+    // normalize() で変化しない形 (連続空白・連続改行・前後空白なし) にして「復元による誤変換ゼロ」を固定する
+    $text = "作業手順書\n1. ネジを締める (トルク 5Nm)\n2. カバーを取り付ける\n"
+        ."3. 動作確認を行う\n安全: 保護メガネと手袋を着用する";
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toBe($text);
+});
+
+/*
+ * 復元段は媒体非依存だが、採否は 3 段検証 (CP1252 可逆 / SJIS-win 妥当 /
+ * 2 バイト列由来の日本語が増え、かつ過半数が日本語) が決める。
+ * 正当な日本語手順書に CP1252 文字が混ざっても 1 文字も変えないことを固定する。
+ * 特に © (0xA9) / ± (0xB1) / ° (0xB0) は CP932 では半角カナの単バイト帯に写るため、
+ * 半角カナを採否の根拠にすると正当テキストが壊れる (Codex impl-review round 1/2 の回帰。
+ * 実測は probe/probe-mixed-latin.php / probe/probe-run-criteria.php)。
+ */
+test('正当な日本語に CP1252 文字が混在しても復元は発火しない (非 PDF)', function (string $text): void {
+    Storage::fake();
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toBe($text);
+})->with([
+    'アクセント記号 (é ö ß à € ™)' => ["作業手順書 (Café ラインの Größe 点検)\n"
+        ."1. ネジを締める。トルクは 5Nm とする。\n"
+        ."2. カバーを取り付ける。€120 の部材と ™ 表示を確認する。\n"
+        .'備考: à la carte の設備は対象外とする。'],
+    '著作権表記 (© = 半角カナ帯)' => ['作業手順書 © 2026 株式会社サンプル 無断転載を禁ず。'
+        .'ネジを締める。安全確認を徹底すること。保護メガネを着用する。'],
+    '単位記号 (° ± = 半角カナ帯)' => ['作業手順書 温度は 25° 前後、公差は ±0.5mm とする。'
+        .'ネジを締める。安全確認を徹底すること。保護メガネを着用する。'],
+    '仏語 créé (偶然 CP932 の 2 バイト列として成立する)' => ['作業手順書 この設備は 2020 年に créé された。'
+        .'ネジを締める。安全確認を徹底すること。保護メガネを着用する。'],
+]);
+
+test('正当な欧文テキストは復元されず日本語不足で拒否される', function (string $text): void {
+    Storage::fake();
+    // いずれも analysis_min_text_bytes (100) 以上にして tooShort と競合させない
+    expect(strlen($text))->toBeGreaterThanOrEqual(100);
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
+})->with([
+    'en' => ['Work Instruction: 1. Tighten the screw to 5Nm. 2. Attach the cover plate. 3. Check the operation before use.'],
+    'de' => ['Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für die Straße. Öl nachfüllen. Weiß markieren.'],
+    'fr' => ['Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté gauche. Contrôler après usage.'],
+]);
+
+test('復元は混在文書の正規日本語を壊さない', function (): void {
+    Storage::fake();
+    // 正しく decode された日本語 (AS の隠し OCR 層に相当) と mojibake の混在
+    $document = storedDocument(
+        '非鉄金属はその特性に応じた研削をする。'.sjisMojibake('作業手順書 ネジを締める 安全確認 保護メガネ着用'),
+        'text/plain',
+        'txt',
+    );
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toContain('非鉄金属');
+    expect($extracted->text)->toContain('ネジを締める');
+});
+
+test('日本語比率が閾値未満のテキストは拒否される (境界: 下側)', function (): void {
+    Storage::fake();
+    config()->set('manual.analysis_min_japanese_ratio', 0.10);
+    // 空白を除く 100 文字中 日本語 9 文字 = 0.09
+    $document = storedDocument(str_repeat('A', 91).'安全確認手順書作業', 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
+});
+
+test('日本語比率が閾値以上のテキストは通る (境界: 上側)', function (): void {
+    Storage::fake();
+    config()->set('manual.analysis_min_japanese_ratio', 0.10);
+    // 空白を除く 100 文字中 日本語 11 文字 = 0.11
+    $document = storedDocument(str_repeat('A', 89).'安全確認手順書作業前点', 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->sourceKind)->toBe('plain');
+});
+
+test('抽出結果が空の PDF は unextractable (tooShort と弁別)', function (): void {
+    Storage::fake();
+    $document = storedDocument(sampleSopContents('AP_オペレーション手順書.pdf'), 'application/pdf', 'pdf');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
+});
+
+test('空の text/plain は tooShort (画像未対応と弁別)', function (): void {
+    Storage::fake();
+    $document = storedDocument('', 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
+});
+
+test('空の Spreadsheet は tooShort', function (): void {
+    Storage::fake();
+    $spreadsheet = new Spreadsheet;
+    $tmp = tempnam(sys_get_temp_dir(), 'sop-xlsx-');
+    Assert::string($tmp);
+    (new Xlsx($spreadsheet))->save($tmp);
+    $contents = file_get_contents($tmp);
+    @unlink($tmp);
+    Assert::string($contents, "一時 xlsx を読めません: {$tmp}"); // 失敗を空文字へ潰さない
+    $document = storedDocument(
+        $contents,
+        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
+        'xlsx',
+    );
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
+});
+
+test('同梱サンプル SOP 5 本の抽出結果は期待値表どおりである', function (string $file, ?string $expectedError): void {
+    Storage::fake();
+    $document = storedDocument(sampleSopContents($file), 'application/pdf', 'pdf');
+
+    if ($expectedError !== null) {
+        expect(fn () => app(SopTextExtractor::class)->extract($document))
+            ->toThrow(AnalysisFailedException::class, $expectedError);
+
+        return;
+    }
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+    expect($extracted->sourceKind)->toBe('pdf');
+    expect($extracted->text)->toContain('グラインダー研削作業');
+    expect($extracted->text)->toContain('保護メガネ');
+})->with([
+    ['AP_オペレーション手順書.pdf', 'テキストを抽出できません'],
+    ['AT_作業手順書.pdf', 'テキストを抽出できません'],
+    ['作業要領書.pdf', 'テキストを抽出できません'],
+    ['AW_作業手順書 (1).pdf', '十分な日本語の本文'],
+    ['AS_作業手順書.pdf', null],
+]);
```

## 検証結果

- `vendor/bin/pest tests/Unit/Manual/SopTextExtractorTest.php`: 28 passed / 60 assertions
- pint / phpstan level 10 は Round 2 時点で green (Round 3 の修正後に再実行する)
