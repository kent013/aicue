# impl-review Round 3

Round 2 の Warning / Suggestion に対応しました。対応マトリクスと**該当箇所の差分**を添えます
(Round 2 で「指摘なし」だったファイルは変更していません)。

---

# 対応マトリクス: impl-review Round 2

## [Warning] AGENTS.md「例外へ載せるのは区分ごとの固定文だけ」が `SchemaViolation` に成立しない
- 判断: 対応する
- 根拠: `LlmJson::schemaViolation($detail, $path)` は呼び出し側の `$detail` をそのまま例外へ渡す。
  非漏洩テストが固定しているのも復号に失敗した 6 区分だけであり、記述が実装より広かった
  (保証範囲の誇張)。
- 対応内容: 規約 21 を「**復号に失敗した 6 区分**では固定文だけ」に狭め、
  `schema_violation` の `detail` は呼び出し側が組み立てるものであり
  **応答由来の文字列を混ぜないのは呼び出し側の責務 (機械では見ていない)** と明記した。

## [Warning] AGENTS.md「応答は登録済み受け取り関数の直接の引数」が全分類に読める
- 判断: 対応する
- 根拠: 検査 3 が強制するのは `Decoded` 分類だけで、`FreeText` / `ProviderShape` は対象外。
- 対応内容: 「**`Decoded` 分類の**応答は…」へ限定し、他 2 分類が対象外である旨を書いた。
  併せて「加工してから渡す形」も赤になることを追記した (Round 1 で足した検出)。

## [Warning] docs/architecture.md の `getMessage()` の記述が `schemaViolation()` と矛盾
- 判断: 対応する
- 対応内容: 同節を「復号に失敗した 6 区分では固定文だけ (非漏洩テストが固定するのもこの 6 区分)」
  に狭め、`schema_violation` が例外であること・現在の呼び出しは応答由来の文字列を含まないが
  **機械では見ていない**ことを明記した。

## [Warning] `llmResponseOtherReceivers()` の双方向照合・理由長の分岐が本番テストで一度も通らない
- 判断: 対応する
- 根拠: 目録が 0 件なので、余剰登録 / 未登録の観測値 / 30 文字未満の理由のいずれも
  本番の実データでは踏まない = 共通規約 (c) の裏取りが無い。
- 対応内容: 判定を純関数 `Tests\Support\Llm\LlmSeamInventoryRules::otherReceiverViolations()`
  へ切り出し、gate はそれを呼ぶだけにした。自己検査で合成入力の**両方向**を固定した
  (正例 = 現行どおり空 / 負例 = 未登録の観測値・stale 登録・短すぎる根拠・
  **末尾一致では通さないこと** の 4 形)。

## [Warning] 免除の前提検査に負例が無い
- 判断: 対応する
- 対応内容: 同じく純関数 `LlmSeamInventoryRules::exemptionViolations()` へ切り出し、
  負例 3 形 (実在しないパス / 30 文字未満の根拠 / **前提 (`executeSync()` を持つ) が失われた免除**)
  を合成入力で固定した。gate 側は走査で得た site 数をそのまま渡す。

## [Suggestion] 名前付き引数の分岐に正例が無い
- 判断: 対応する
- 根拠: `resolveSeam()` に専用分岐を足した以上、その分岐の正例が無いと誤検出側を固定できない。
- 対応内容: 見本 `seam-named-argument.php.txt` を追加し、
  `ExtractedSopData::fromLlmText(text: …->executeSync())` が
  `ResolvedPromptFactory` + 登録済み receiver として解決されることを固定した。

## [Warning] `composer test` 全数の再実行が未完了
- 判断: 対応する
- 対応内容: 本ラウンドの修正を入れたうえで全数を実行し、結果を Round 3 に載せる
  (Round 2 実行中は他エージェントがグローバルテストロックを保持していたため待機になっていた)。

## [Warning] `pipeline-smoke --check` / 互換性確認 A・B が未実施
- 判断: 見送る (設計どおり)
- 根拠: A / B は課金を伴い、設計が「エージェント判断では実行しない」と定めている。
  `--check` は provision 済み bughunt 環境を要求し、同一ホストで他エージェントの shard が
  走行中のため provision できない (相手の走行を壊す)。preflight の内容も本変更の経路に触れない。
- 対応内容: TODO クローズ時に**外部確認待ち**として 3 件を明示する。


---

## 差分 (Round 2 以降に変えた箇所だけ)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 4b968a56..ae40645c 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -1045,3 +1045,29 @@ ## ドメイン固有規約
     - **走査対象と保証しないものの正本は `tests/js/support/file-input-scan.ts` の
       docblock** であり、本書には写さない (2 か所に書くと必ず食い違う)。
       件数も写さない (正本は目録側の pin)
+21. **LLM 応答の復号点の単一性と失敗の区分 (T257 / 家系の正典 v1)**:
+    LLM 応答を構造化データとして読む場所は `App\Support\Manual\LlmJson::decode()` の
+    **1 か所だけ**である。受理契約は**囲み (コードフェンス) ちょうど 1 つ**で、
+    緩い入口は持たない (公開面は `decode` / `schemaViolation` の 2 つに機械で pin してある)。
+    - 依頼文 (`app/Prompts/`) を足したら、`LlmResponseDecodePointGateTest` の目録へ
+      応答の扱い (`Decoded` / `ProviderShape` / `FreeText`) を登録する
+      (deny-by-default。`Decoded` 以外は 30 文字以上の根拠が要る)。
+      `Decoded` にしたら依頼文 YAML の `system_prompt` に**所定の出力指示**を書く
+      (書き忘れると同 gate の検査 6 が赤くなる = 受理契約と依頼文が黙って食い違わない)
+    - **`Decoded` 分類の**応答は `GuardedPrompt::executeSync()` の戻り値を
+      **登録済みの受け取り関数の直接の引数**に渡す形だけが認められる。変数へ束縛する形・
+      加工してから渡す形・別サービスへ回す形は構造で赤くなる
+      (受け手を解決できない書き方も**未解決として失敗**する = 無言で候補から外さない)。
+      `FreeText` / `ProviderShape` 分類は受け取り関数を持たないのでこの検査の対象外である
+    - 失敗区分の語彙の正本は `App\Enums\Manual\LlmOutputInvalidReason` である。
+      **再試行の可否は区分で分けない** (可否は `AnalysisPipeline::isTransient()` が
+      例外型 1 つで決める。区分は集計のためだけに存在する)。
+      `value_incomplete_inferred` は**切り詰めの推定**であって断定ではない
+      (提供元の停止の理由の正本は `llm_call_logs.finish_reason`)
+    - **復号に失敗した 6 区分**では例外へ載せるのは**区分ごとの固定文だけ**である
+      (応答本文・`json_last_error_msg()` / `JsonException::getMessage()` を入れない)。
+      `schema_violation` だけは呼び出し側が具体的な違反内容を `detail` として渡すので、
+      **そこに応答由来の文字列を混ぜないのは呼び出し側の責務**である (機械では見ていない)
+    - **保証しないものの正本は gate と `LlmJson` の docblock** であり、本書に写さない
+      (2 か所に書くと必ず食い違う)。受理文法・区分の決定順序・出荷後の観測と巻き戻しは
+      `docs/architecture.md` §LLM 応答の復号点 (単一) と失敗の区分
diff --git a/docs/architecture.md b/docs/architecture.md
index 08eb3aae..fd518187 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2886,6 +2886,106 @@ ### 媒体添付の窓口拡張 (OCR 経路。画像・スキャン SOP の OCR
   `route` (`text`/`ocr`) / `source_mime` / `outcome` / `failure_category` (固定語彙) /
   `media_size_bytes` / `media_pages` / `media_pixels` で記録する。本文・応答は一切含めない。
 
+## LLM 応答の復号点 (単一) と失敗の区分 (T257 / 家系の正典 v1)
+
+窓口方式が**入口** (untrusted 文字列が prompt へ入る道) を 1 本道にしたのに対し、本節は
+**出口** (LLM 応答が構造化データとして app/ へ入る道) を 1 点に畳む。復号点は
+`App\Support\Manual\LlmJson::decode()` **ただ 1 つ**で、緩い入口は持たない。
+
+### 受理契約 (厳しい入口が 1 つだけ)
+
+```
+応答 = PRE OPEN VALUE GAP CLOSE POST
+  PRE   : 囲みの印を含まない任意の文字列 (前置きの説明文を許す)
+  OPEN  : 逆引用符 3 個以上の並び + 任意の言語札 [A-Za-z0-9_+.-]*
+  VALUE : 最上位が入れ物 (object / array) の JSON 値ちょうど 1 つ
+  GAP   : 空白のみ (JSON の空白 4 種 = SP / HT / LF / CR)
+  CLOSE : 逆引用符 3 個以上の並び (直後に言語札を持たない)
+  POST  : 囲みの印を含まない任意の文字列 (後書きを許す)
+```
+
+- 採るのは**常に最初の囲みの直後の値**である (決定論。同じ応答なら常に同じブロック)。
+- 囲みの印が**ブロックの外にもう 1 つ**あれば受け取らない。
+- 印は「行」ではなく「連続 3 個以上の逆引用符の並び」である。応答データの中に現れた印を
+  終端に数えないのは、**構造の走査で決まった値の区間の外側だけを数える**ことで担保する。
+- 値が JSON として妥当かは自前で判定せず `json_decode(..., JSON_THROW_ON_ERROR)` に委譲する
+  (自前パーサへ膨らませて `json_decode()` と判定が食い違う状態を作らない)。
+
+依頼文 4 本 (`sop-extract` / `sop-extract-media` / `work-decomposition` /
+`scenario-generation`) の `system_prompt` は、この契約と同じ形を指示する。
+指示文と受理契約の同期は `LlmResponseDecodePointGateTest` の検査 6 が deny-by-default で固定する。
+
+### 区分の決定順序 (単一パスの到達順 = 複合不正の優先順位)
+
+| #  | 判定 | 区分 |
+|----|------|------|
+| 1  | 囲みの印が 1 つも無い | `fence_absent` |
+| 2  | 開きの印 + 言語札の先が空白のみで終端 | `value_incomplete_inferred` |
+| 3  | その先の最初の非空白が囲みの印 (空のブロック) | `top_level_not_container` |
+| 4  | その先の最初の非空白が `{` でも `[` でもない | `top_level_not_container` |
+| 5a | 構造の走査が期待と異なる閉じ括弧に遭遇 | `syntax_broken` |
+| 5b | 構造の走査が深さ 0 に戻らずに終端 | `value_incomplete_inferred` |
+| 6  | 値の終端の後、空白を飛ばした先が終端 | `closing_fence_absent` |
+| 7  | 値の終端の後の印の直後に言語札がある (別ブロックの開き) | `fence_multiple` |
+| 8  | 値の終端の後が印でもなく非空白 (余剰トークン) | `syntax_broken` |
+| 9  | 閉じの印より後にさらに囲みの印がある | `fence_multiple` |
+| 10 | 切り出した値の `json_decode` が `JsonException` (深さ超過を含む) | `syntax_broken` |
+| 11 | `json_decode` の結果が配列でない (4 で落ちるので到達不能。多重防御) | `top_level_not_container` |
+
+`schema_violation` は上の 6 区分と**直交する別の軸**である (「読めたが形が違う」)。
+違反位置 `path` を持つのはこの区分だけである。
+
+**切り詰めは推定であって断定ではない**。`value_incomplete_inferred` は「値が完結しないまま
+終端に達した」という構造からの推定で、提供元が返す停止の理由の正本は
+`llm_call_logs.finish_reason` (`Prism\Prism\Enums\FinishReason` の値。失敗系は sentinel
+`'failed'`) である。復号点はこの列に触らないので、推定が正本を上書きすることは構造的に起きない
+(疑いは事後にこの列と突合できる)。値の綴りに `inferred` を含めるのは、記録を読む人が
+**断定と読み違えない**ようにするためである。
+
+**再試行の可否は区分で分けない**。可否は `AnalysisPipeline::isTransient()` が例外型 1 つで
+決めており (全区分 retryable)、区分は集計のためだけに存在する。復号の失敗はすべてモデルの
+出力の書式の問題で、次試行は再サンプリングなので出力が変わる (「決定論的」なのは復号の判定で
+あって応答の生成ではない)。
+
+**例外に応答本文を載せない**。**復号に失敗した 6 区分**では `getMessage()` に入るのは
+区分ごとの固定文だけで、応答の断片・`json_last_error_msg()` / `JsonException::getMessage()` は
+入らない (単体・統合の非漏洩テストが固定するのもこの 6 区分である)。利用者向けの文言
+(`analysis_jobs.error`) は区分によらず `userMessage()` の定型文である。
+**`schema_violation` は例外**で、`LlmJson::schemaViolation($detail, $path)` の `$detail` は
+呼び出し側 (DTO の検証) が組み立てる。現在の呼び出しはどれもキー名・型名・上限値だけを
+書いており応答由来の文字列を含まないが、**そこは機械では見ていない**ので、
+検証を足すときに応答の断片を `detail` へ混ぜないのは書き手の責務である。
+
+### 単一性の機械検査
+
+`tests/Architecture/LlmResponseDecodePointGateTest.php` が 8 つの検査を deny-by-default で
+持つ (依頼文の全数分類 / 受け取り口の全数分類 / 応答の流れの構造的封じ /
+`GuardedPrompt` の参照者の分類 / 復号語彙の不在 / 依頼文と受理契約の同期 /
+復号点の公開面の pin / 受け取り関数が復号点に直結していること)。
+**保証しないものの正本は同 gate と走査器 (`Tests\Support\Llm\LlmResponseSeamScanner`) の
+docblock** であり、本書には写さない (2 か所に書くと必ず食い違う)。
+
+### 観測の読み方と巻き戻し
+
+- 数えられるのは**件数**である。再試行 1 回ごとに
+  `AI 解析の LLM 呼び出しを再試行します` の `failure_category` に区分が出て、最終失敗は
+  extract 段の終端ログの `failure_category` (`llm_output_invalid_{区分}`) に出る。
+- **率は現行 Log からは出せない** (再試行ログは失敗時にだけ出るので分母が無い)。
+  分母が要るときは `llm_call_logs` の `prompt_template` 別の行数と突合する
+  (その表は LLM コストレポートの持ち分で、本節は新しい観測点を作らない)。
+- **巻き戻し**: `llm_output_invalid_fence_absent` / `_fence_multiple` が終端失敗の主因として
+  現れたら、一手目は**依頼文の出力指示の修正**である。それで回復しない場合は
+  **本変更を導入したリリースの変更一式を revert** する。
+  **復号点だけを部分的に緩める形は採らない** (受理契約を緩める並走を作らない = 思考原則 3)。
+- **過去の記録との非連続**: 旧語彙 `invalid_json` は本変更で消えた。
+  **本変更の本番デプロイを境界として**、それ以前の記録では同じ事象が `invalid_json` である。
+  境界の実値 (日時・リリース識別子) はデプロイ記録 / リリースノートが正本で、本書は書かない
+  (実装時点では未確定であり、コミットに自分自身の識別子は書けない)。
+- **実 provider での互換性確認は本書の保証範囲ではない**。自動テストは canned / fixture を
+  使うので、「モデルが実際に囲みつきで返すか」は
+  `dev:pipeline-smoke` の実走 (課金あり) と画像 SOP 1 件の解析でしか確かめられない。
+  未実施なら「確認済み」と書かないこと。
+
 ## 組織アクセスの失効 (T174 / 家系の正典 v2)
 
 組織の中で誰かの役割が変わったとき、その人がその組織で持っている「人に委ねられた資格情報」を
diff --git a/tests/Architecture/LlmResponseDecodePointGateTest.php b/tests/Architecture/LlmResponseDecodePointGateTest.php
new file mode 100644
index 00000000..8ae709ac
--- /dev/null
+++ b/tests/Architecture/LlmResponseDecodePointGateTest.php
@@ -0,0 +1,369 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Exceptions\Manual\LlmOutputInvalidException;
+use App\Prompts\ExampleSummaryPrompt;
+use App\Prompts\ScenarioGenerationPrompt;
+use App\Prompts\SopExtractFromMediaPrompt;
+use App\Prompts\SopExtractPrompt;
+use App\Prompts\WorkDecompositionPrompt;
+use App\Support\Llm\GuardedPrompt;
+use App\Support\Manual\LlmJson;
+use Tests\Support\Llm\DecodePointPublicSurface;
+use Tests\Support\Llm\Fixtures\LenientDecodePointProbe;
+use Tests\Support\Llm\LlmResponseHandling;
+use Tests\Support\Llm\LlmResponseSeamResolution;
+use Tests\Support\Llm\LlmResponseSeamScanner;
+use Tests\Support\Llm\LlmSeamInventoryRules;
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\Prompts\PromptFactoryPopulation;
+use Tests\Support\PromptYaml;
+
+/**
+ * LLM 応答の**復号点の単一性** gate (家系の正典 v1 の i1)。
+ *
+ * 土台にするのは「**LLM 応答が app/ に入る唯一の入口は `GuardedPrompt::executeSync()` である**」
+ * という既存の事実 (窓口方式 T169。`PromptGuardrailTest` / `PromptDefenseWindowGateTest` が
+ * Prism 直呼びの不在と窓口の公開面を既に固定している)。本 gate はその入口を**全数分類**し、
+ * 応答が復号点以外へ流れない形を構造で閉じる。
+ *
+ * ## 8 つの検査
+ *
+ * 1. 依頼文の全数分類 + 目録項目の妥当性 (双方向・deny-by-default)
+ * 2. 応答の受け取り口の全数分類 (3 分類。**未解決は 1 件でも失敗**)
+ * 3. 応答の流れの構造的封じ (`executeSync()` は登録済みの受け取り関数の**直接の引数**)
+ * 4. `GuardedPrompt` の参照者の分類
+ * 5. 復号語彙 (`json_decode` / 囲みの印) の不在
+ * 6. 依頼文 YAML と受理契約の同期
+ * 7. 復号点の公開面の pin (緩い入口を持たない = i4)
+ * 8. 受け取り関数が復号点に直結していること
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - 反射・動的に組み立てたクラス名・文字列キーだけの container 解決の経路は見えない。
+ * - **動的な関数呼び出し** (`$fn($text)` / 変数に入れた callable) は見えない
+ *   (文字列リテラルの完全一致だけは拾う)。走査器の docblock が正本。
+ * - `vendor/` 配下と `tests/` 配下は走査しない。
+ * - `json_decode` の不在を保証するのは**宣言した 4 つの走査根の中だけ**である
+ *   (`app/` の他の `json_decode` は OIDC メタデータ・webhook 署名・冪等キー等の
+ *    LLM と無関係な経路であり対象外。応答をそこへ運ぶ経路の側は検査 2・3 が塞ぐ)。
+ * - `app/Services/AI/Testing/` は**応答を作る側**なので走査根に入れない
+ *   (囲みの印を持つのが正しい)。
+ * - 「復号点を通す」以外の 2 分類 (`ProviderShape` / `FreeText`) は**目録の宣言を信じる**
+ *   (宣言と実装の食い違いを機械で見てはいない)。
+ * - 検査 7 が見るのは**そのクラス自身が宣言した public メソッド**だけである
+ *   (`DecodePointPublicSurface` の docblock が正本)。
+ *
+ * 負例は検出器の自己検査 (`tests/Unit/Architecture/LlmResponseSeamScannerTest.php`) と
+ * 見本ファイル (`tests/Architecture/fixtures/llm-seam/`) に置く。
+ */
+
+/** 復号語彙を書いてよい唯一のファイル (完全一致 1 件)。 */
+const LLM_DECODE_POINT_PATH = 'app/Support/Manual/LlmJson.php';
+
+/** 復号語彙の不在を見る走査根 (存在しない根は fail-fast)。 */
+const LLM_DECODE_VOCABULARY_ROOTS = [
+    'app/Support/Manual',
+    'app/DataTransferObjects/Manual/Analysis',
+    'app/Services/Manual',
+    'app/Prompts',
+];
+
+/** 依頼文 YAML に必ず書く出力指示 (受理契約と依頼文が黙って食い違う状態を作らない)。 */
+const LLM_FENCE_INSTRUCTION = '出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください';
+
+/**
+ * 依頼文 factory の応答の扱い (deny-by-default)。
+ *
+ * `reason` は `Decoded` のときだけ空文字列で、それ以外は 30 文字以上の根拠が要る。
+ *
+ * @return array<class-string, array{kind: LlmResponseHandling, template: string, reason: string}>
+ */
+function llmResponseHandlingInventory(): array
+{
+    return [
+        SopExtractPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'sop-extract', 'reason' => ''],
+        SopExtractFromMediaPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'sop-extract-media', 'reason' => ''],
+        WorkDecompositionPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'work-decomposition', 'reason' => ''],
+        ScenarioGenerationPrompt::class => ['kind' => LlmResponseHandling::Decoded, 'template' => 'scenario-generation', 'reason' => ''],
+        ExampleSummaryPrompt::class => ['kind' => LlmResponseHandling::FreeText, 'template' => 'example-summary',
+            'reason' => '見本の依頼文で応答は 1 文の要約 (文章) であり、構造化データとして読む経路を持たない'],
+    ];
+}
+
+/**
+ * 登録済みの受け取り関数 (`{FQCN}::{method}`)。
+ *
+ * `executeSync()` の応答はこの引数として**直接**渡されなければならない
+ * (変数へ束縛する形は検査 3 で赤くなる)。
+ *
+ * @return list<string>
+ */
+function llmResponseReceivers(): array
+{
+    return [
+        'App\DataTransferObjects\Manual\Analysis\ExtractedSopData::fromLlmText',
+        'App\DataTransferObjects\Manual\Analysis\WorkDecompositionResponseData::fromLlmText',
+        'App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData::fromLlmText',
+    ];
+}
+
+/**
+ * 目録外の型に解決された `executeSync()` の受け手 (理由つき)。**現在 0 件**。
+ *
+ * @return array<string, string> 完全修飾名 => 30 文字以上の根拠
+ */
+function llmResponseOtherReceivers(): array
+{
+    return [];
+}
+
+/**
+ * `executeSync()` の母集団から外すファイル (理由つき。deny-by-default の exact-fit)。
+ *
+ * @return array<string, string> 相対パス => 30 文字以上の根拠
+ */
+function llmExecuteSyncPopulationExemptions(): array
+{
+    return [
+        'app/Support/Llm/GuardedPrompt.php' => '実行単位そのもの。vendor の Prompt へ委譲する内側の呼び出しであり、'
+            .'応答を受け取る側ではない (窓口の公開面は PromptDefenseWindowGateTest が pin する)',
+    ];
+}
+
+/**
+ * 走査対象の app/ ファイル (相対パス => ソース)。
+ *
+ * @return array<string, string>
+ */
+function llmSeamAppFiles(): array
+{
+    return PhpReferenceScanner::phpFiles(base_path('app'), 'app');
+}
+
+// ---- 検査 1: 依頼文の全数分類 ----
+
+test('検査 1: app/Prompts/ の依頼文は全数が応答の扱いの目録に載る (双方向)', function (): void {
+    $population = PromptFactoryPopulation::classes();
+    expect($population)->not->toBeEmpty('依頼文 factory の母集団が空 (走査が壊れている)');
+
+    $registered = array_keys(llmResponseHandlingInventory());
+    sort($registered);
+    expect(array_values($population))->toBe($registered);
+});
+
+test('検査 1: 目録の項目が妥当 (template の実在 / Decoded 以外は 30 文字以上の根拠)', function (): void {
+    foreach (llmResponseHandlingInventory() as $class => $entry) {
+        expect($entry['template'])->not->toBe('', "{$class}: template が空");
+        expect(file_exists(resource_path("prompts/{$entry['template']}.yaml")))
+            ->toBeTrue("{$class}: 依頼文 YAML {$entry['template']}.yaml が実在しない");
+
+        if ($entry['kind'] === LlmResponseHandling::Decoded) {
+            expect($entry['reason'])->toBe('', "{$class}: Decoded は reason を空にする");
+
+            continue;
+        }
+        expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
+            30,
+            "{$class}: Decoded 以外は 30 文字以上の根拠が必要",
+        );
+    }
+});
+
+// ---- 検査 2: 応答の受け取り口の全数分類 ----
+
+test('検査 2: executeSync() の呼び出し点は全数が解決でき、目録の依頼文 factory である', function (): void {
+    $factories = array_keys(llmResponseHandlingInventory());
+    $exemptions = llmExecuteSyncPopulationExemptions();
+    $files = llmSeamAppFiles();
+
+    $total = 0;
+    $unresolved = [];
+    /** @var list<string> $otherFactories */
+    $otherFactories = [];
+    /** @var array<string, int> $siteCounts */
+    $siteCounts = [];
+    foreach ($files as $path => $source) {
+        $findings = LlmResponseSeamScanner::executeSyncSites($path, $source, $factories);
+        $siteCounts[$path] = count($findings);
+        if (array_key_exists($path, $exemptions)) {
+            continue; // 免除の前提検査 (site を持つこと) は下の目録判定が見る
+        }
+        foreach ($findings as $finding) {
+            $total++;
+            match ($finding->resolution) {
+                LlmResponseSeamResolution::Unresolved => $unresolved[] = $finding->location(),
+                LlmResponseSeamResolution::ResolvedOther => $otherFactories[] = (string) $finding->factory,
+                LlmResponseSeamResolution::ResolvedPromptFactory => null,
+            };
+        }
+    }
+
+    expect($total)->toBeGreaterThan(0, 'executeSync() の母集団が空 (走査が壊れている)');
+    expect($unresolved)->toBe([], '受け手を解決できない executeSync() があります (共通規約 (b): 未解決は落とす)');
+
+    // 免除は exact-fit (実在 / 30 文字以上の根拠 / **前提が生きていること**)。
+    // 目録外の型は**完全修飾名の完全一致**で双方向に照合する。
+    // ★判定は純関数へ切り出してあり、負例は同じ関数を通して裏取りしてある (共通規約 (c))
+    expect(LlmSeamInventoryRules::exemptionViolations($exemptions, array_keys($files), $siteCounts))->toBe([]);
+    expect(LlmSeamInventoryRules::otherReceiverViolations($otherFactories, llmResponseOtherReceivers()))->toBe([]);
+});
+
+// ---- 検査 3: 応答の流れの構造的封じ ----
+
+test('検査 3: 復号点を通す依頼文の応答は登録済みの受け取り関数の直接の引数である', function (): void {
+    $inventory = llmResponseHandlingInventory();
+    $factories = array_keys($inventory);
+    $receivers = llmResponseReceivers();
+    $exemptions = llmExecuteSyncPopulationExemptions();
+
+    $checked = 0;
+    $violations = [];
+    foreach (llmSeamAppFiles() as $path => $source) {
+        if (array_key_exists($path, $exemptions)) {
+            continue;
+        }
+        foreach (LlmResponseSeamScanner::executeSyncSites($path, $source, $factories) as $finding) {
+            if ($finding->resolution !== LlmResponseSeamResolution::ResolvedPromptFactory) {
+                continue;
+            }
+            $factory = $finding->factory;
+            if ($factory === null || ($inventory[$factory]['kind'] ?? null) !== LlmResponseHandling::Decoded) {
+                continue; // free_text / provider_shape は受け取り関数を持たない
+            }
+            $checked++;
+            if ($finding->enclosingCall === null || ! in_array($finding->enclosingCall, $receivers, true)) {
+                $violations[] = $finding->location().' => '.($finding->enclosingCall ?? '(解決できない形)');
+            }
+        }
+    }
+
+    expect($checked)->toBeGreaterThan(0, '復号点を通す executeSync() が 1 件も無い (走査が壊れている)');
+    expect($violations)->toBe([], '応答が登録済みの受け取り関数以外へ渡っています');
+});
+
+// ---- 検査 4: GuardedPrompt の参照者の分類 ----
+
+test('検査 4: GuardedPrompt を参照する app/ のファイルは依頼文 factory か窓口・実行単位だけ', function (): void {
+    $allowedPrefix = 'app/Support/Llm/';
+    $factories = array_keys(llmResponseHandlingInventory());
+
+    $referencing = [];
+    $violations = [];
+    foreach (llmSeamAppFiles() as $path => $source) {
+        if (! LlmResponseSeamScanner::referencesGuardedPrompt($path, $source, GuardedPrompt::class)) {
+            continue;
+        }
+        $referencing[] = $path;
+        if (str_starts_with($path, $allowedPrefix)) {
+            continue;
+        }
+        $class = 'App\\'.str_replace('/', '\\', substr($path, strlen('app/'), -4));
+        if (! in_array($class, $factories, true)) {
+            $violations[] = $path;
+        }
+    }
+
+    expect($referencing)->not->toBeEmpty('GuardedPrompt の参照が 1 件も無い (走査が壊れている)');
+    expect($violations)->toBe([], 'GuardedPrompt を参照する未登録のファイルがあります');
+});
+
+// ---- 検査 5: 復号語彙の不在 ----
+
+test('検査 5: 走査根に json_decode と囲みの印の文字列リテラルが無い (復号点の 1 件を除く)', function (): void {
+    $scanned = 0;
+    $violations = [];
+    foreach (LLM_DECODE_VOCABULARY_ROOTS as $root) {
+        $absolute = realpath(base_path($root));
+        expect($absolute)->toBeString("走査根を解決できません: {$root}");
+        /** @var string $absolute */
+        $files = PhpReferenceScanner::phpFiles($absolute, $root);
+        expect($files)->not->toBeEmpty("走査根が空です: {$root}");
+
+        foreach ($files as $path => $source) {
+            if ($path === LLM_DECODE_POINT_PATH) {
+                continue;
+            }
+            $scanned++;
+            $violations = [...$violations, ...LlmResponseSeamScanner::decodeVocabularyViolations($path, $source)];
+        }
+    }
+
+    expect($scanned)->toBeGreaterThan(0, '走査対象が空 (走査が壊れている)');
+    expect($violations)->toBe([], '復号点以外で応答を自前で読む語彙が現れています');
+});
+
+test('検査 5: 除外している復号点は実在し、実際に復号語彙を持つ (負のコントロール)', function (): void {
+    $source = file_get_contents(base_path(LLM_DECODE_POINT_PATH));
+    expect($source)->toBeString();
+    /** @var string $source */
+    expect(LlmResponseSeamScanner::decodeVocabularyViolations(LLM_DECODE_POINT_PATH, $source))
+        ->not->toBe([], '復号点が復号語彙を持たない = 検出条件が壊れている');
+});
+
+// ---- 検査 6: 依頼文と受理契約の同期 ----
+
+test('検査 6: 復号点を通す依頼文 YAML は囲みちょうど 1 つを指示している', function (): void {
+    $checked = 0;
+    foreach (llmResponseHandlingInventory() as $class => $entry) {
+        if ($entry['kind'] !== LlmResponseHandling::Decoded) {
+            continue;
+        }
+        $path = resource_path("prompts/{$entry['template']}.yaml");
+        /** @var list<string> $problems */
+        $problems = [];
+        $parsed = PromptYaml::parseOrFail($path, $problems);
+        expect($problems)->toBe([]);
+        expect($parsed)->toBeArray();
+        /** @var array<string, mixed> $parsed */
+        $systemPrompt = $parsed['system_prompt'] ?? null;
+        expect($systemPrompt)->toBeString("{$class}: system_prompt がありません");
+        /** @var string $systemPrompt */
+        expect(str_contains($systemPrompt, LLM_FENCE_INSTRUCTION))->toBeTrue(
+            "{$class}: 依頼文 {$entry['template']}.yaml に所定の出力指示がありません",
+        );
+        $checked++;
+    }
+
+    expect($checked)->toBeGreaterThan(0, '復号点を通す依頼文が 1 件も無い (目録が壊れている)');
+});
+
+// ---- 検査 7: 復号点の公開面の pin ----
+
+test('検査 7: 復号点の公開面は decode / schemaViolation の 2 つだけ (緩い入口を持たない)', function (): void {
+    expect(DecodePointPublicSurface::violations(LlmJson::class, LlmOutputInvalidException::class))->toBe([]);
+});
+
+test('検査 7 の負例: 緩い入口を足した見本は同じ判定経路で赤くなる', function (): void {
+    // ★本番と**同一の判定関数**へ渡す (負例が別ロジックで数えると検出力を証明しない)
+    expect(DecodePointPublicSurface::violations(LenientDecodePointProbe::class, LlmOutputInvalidException::class))
+        ->not->toBe([]);
+});
+
+// ---- 検査 8: 受け取り関数が復号点に直結していること ----
+
+test('検査 8: 受け取り関数は生の応答を復号点へ直接 1 回だけ渡す', function (): void {
+    $violations = [];
+    foreach (llmResponseReceivers() as $receiver) {
+        [$class, $method] = explode('::', $receiver, 2);
+        $relative = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
+        $absolute = base_path($relative);
+        expect(file_exists($absolute))->toBeTrue("受け取り関数のファイルが実在しません: {$relative}");
+
+        $source = file_get_contents($absolute);
+        expect($source)->toBeString();
+        /** @var string $source */
+        $violations = [...$violations, ...LlmResponseSeamScanner::receiverFlowViolations(
+            $relative,
+            $source,
+            $class,
+            $method,
+            LlmJson::class,
+            'decode',
+        )];
+    }
+
+    expect(llmResponseReceivers())->not->toBeEmpty('受け取り関数の目録が空');
+    expect($violations)->toBe([], '受け取り関数の中で生の応答が復号点以外へ流れています');
+});
diff --git a/tests/Architecture/fixtures/llm-seam/seam-named-argument.php.txt b/tests/Architecture/fixtures/llm-seam/seam-named-argument.php.txt
new file mode 100644
index 00000000..eb1ae770
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-named-argument.php.txt
@@ -0,0 +1,20 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
+use App\Prompts\SopExtractPrompt;
+
+// 正例: 受け取り関数へ**名前付き引数**で渡す形も「直接の引数」と認める
+// (ラベル `text:` の直後から式が始まる)。
+final class NamedArgument
+{
+    public function run(string $text): ExtractedSopData
+    {
+        return ExtractedSopData::fromLlmText(
+            text: SopExtractPrompt::make($text)->executeSync(),
+        );
+    }
+}
diff --git a/tests/Support/Llm/LlmSeamInventoryRules.php b/tests/Support/Llm/LlmSeamInventoryRules.php
new file mode 100644
index 00000000..5acf1229
--- /dev/null
+++ b/tests/Support/Llm/LlmSeamInventoryRules.php
@@ -0,0 +1,86 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Llm;
+
+/**
+ * `LlmResponseDecodePointGateTest` の**目録の判定**を純関数として切り出したもの。
+ *
+ * ★切り出す理由は (c) の裏取りである。目録が現在 0 件 / 免除が 1 件だけだと、
+ *   本番 gate は「余剰登録」「未登録の観測値」「30 文字未満の理由」「前提の失われた免除」の
+ *   分岐を一度も通らない。合成入力を同じ関数へ流して**両方向**を固定する
+ *   (自己検査: `tests/Unit/Architecture/LlmResponseSeamScannerTest.php`)。
+ *
+ * ## 保証しないもの
+ *
+ * - ここが見るのは**目録どうしの整合**だけである。走査そのもの (どの site を観測したか) は
+ *   `LlmResponseSeamScanner` の担当で、本クラスはその結果を受け取るだけである。
+ */
+final class LlmSeamInventoryRules
+{
+    /** 目録の根拠に要求する最小文字数。 */
+    public const int MINIMUM_REASON_LENGTH = 30;
+
+    /**
+     * 目録外の型に解決された受け手の登録が実態と一致するか (deny-by-default・双方向)。
+     *
+     * @param  list<string>  $observedFactories  走査で観測した完全修飾名 (重複可)
+     * @param  array<string, string>  $registered  完全修飾名 => 根拠
+     * @return list<string> 違反の説明 (空なら整合)
+     */
+    public static function otherReceiverViolations(array $observedFactories, array $registered): array
+    {
+        $violations = [];
+        foreach ($registered as $fqcn => $reason) {
+            if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
+                $violations[] = "{$fqcn}: 目録外の型の登録には ".self::MINIMUM_REASON_LENGTH.' 文字以上の根拠が必要';
+            }
+        }
+
+        $observed = array_values(array_unique($observedFactories));
+        sort($observed);
+        $keys = array_keys($registered);
+        sort($keys);
+
+        foreach (array_diff($observed, $keys) as $missing) {
+            $violations[] = "{$missing}: 目録外の型が executeSync() の受け手だが登録が無い";
+        }
+        foreach (array_diff($keys, $observed) as $stale) {
+            $violations[] = "{$stale}: 登録されているが観測されない (stale)";
+        }
+
+        return $violations;
+    }
+
+    /**
+     * `executeSync()` の母集団から外す免除が exact-fit か。
+     *
+     * 「実在する」「根拠が十分」だけでなく、**免除の前提** (そのファイルが実際に
+     * `executeSync()` の site を持つ) が生きていることまで見る。前提が消えた古い免除は赤にする。
+     *
+     * @param  array<string, string>  $exemptions  相対パス => 根拠
+     * @param  list<string>  $scannedPaths  走査対象に実在するパス
+     * @param  array<string, int>  $siteCounts  相対パス => その file の executeSync() site 数
+     * @return list<string> 違反の説明 (空なら整合)
+     */
+    public static function exemptionViolations(array $exemptions, array $scannedPaths, array $siteCounts): array
+    {
+        $violations = [];
+        foreach ($exemptions as $path => $reason) {
+            if (! in_array($path, $scannedPaths, true)) {
+                $violations[] = "{$path}: 免除に実在しないパス";
+
+                continue;
+            }
+            if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
+                $violations[] = "{$path}: 免除には ".self::MINIMUM_REASON_LENGTH.' 文字以上の根拠が必要';
+            }
+            if (($siteCounts[$path] ?? 0) < 1) {
+                $violations[] = "{$path}: 免除の前提 (executeSync() を持つ) が失われている";
+            }
+        }
+
+        return $violations;
+    }
+}
diff --git a/tests/Unit/Architecture/LlmResponseSeamScannerTest.php b/tests/Unit/Architecture/LlmResponseSeamScannerTest.php
new file mode 100644
index 00000000..550582fb
--- /dev/null
+++ b/tests/Unit/Architecture/LlmResponseSeamScannerTest.php
@@ -0,0 +1,258 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Exceptions\Manual\LlmOutputInvalidException;
+use App\Support\Manual\LlmJson;
+use Tests\Support\Llm\DecodePointPublicSurface;
+use Tests\Support\Llm\Fixtures\LenientDecodePointProbe;
+use Tests\Support\Llm\LlmResponseSeamResolution;
+use Tests\Support\Llm\LlmResponseSeamScanner;
+use Tests\Support\Llm\LlmSeamInventoryRules;
+use Tests\Support\Prompts\PromptFactoryPopulation;
+
+/*
+ * 検出器の自己検査 (AGENTS.md §走査器・gate を新設するときに揃える 4 点の (1) と (2))。
+ *
+ * 見本ファイルは `tests/Architecture/fixtures/llm-seam/*.php.txt` に置く
+ * (`.php` にすると他 gate の母集団 (strict_types 全数宣言・禁止する文) へ混ざるため)。
+ * **正例と負例の両方向**を固定し、解決できない形が `Unresolved` として落ちることを確かめる。
+ */
+
+/** 見本ファイルの中身。 */
+function llmSeamFixture(string $name): string
+{
+    $path = base_path("tests/Architecture/fixtures/llm-seam/{$name}.php.txt");
+    $source = file_get_contents($path);
+    if ($source === false) {
+        throw new RuntimeException("見本ファイルを読めません: {$path}");
+    }
+
+    return $source;
+}
+
+/** @return list<string> 目録の鍵に見立てた依頼文 factory */
+function llmSeamFactories(): array
+{
+    return ['App\Prompts\SopExtractPrompt'];
+}
+
+test('正例: make(...)->executeSync() が受け取り関数の引数にある形は解決できる', function (): void {
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-resolved-receiver.php',
+        llmSeamFixture('seam-resolved-receiver'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(1);
+    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
+    expect($findings[0]->factory)->toBe('App\Prompts\SopExtractPrompt');
+    expect($findings[0]->enclosingCall)
+        ->toBe('App\DataTransferObjects\Manual\Analysis\ExtractedSopData::fromLlmText');
+});
+
+test('正例 1b: 名前付き引数で渡す形も「直接の引数」と認める', function (): void {
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-named-argument.php',
+        llmSeamFixture('seam-named-argument'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(1);
+    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
+    expect($findings[0]->enclosingCall)
+        ->toBe('App\DataTransferObjects\Manual\Analysis\ExtractedSopData::fromLlmText');
+});
+
+test('負例 1: 応答を変数へ束縛する形は未解決になる', function (): void {
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-unresolved-variable.php',
+        llmSeamFixture('seam-unresolved-variable'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(1);
+    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::Unresolved);
+    expect($findings[0]->factory)->toBeNull();
+    expect($findings[0]->enclosingCall)->toBeNull();
+});
+
+test('負例 2: 遅延静的束縛・括弧で包んだ形も未解決になる', function (): void {
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-unresolved-static.php',
+        llmSeamFixture('seam-unresolved-static'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(2);
+    foreach ($findings as $finding) {
+        expect($finding->resolution)->toBe(LlmResponseSeamResolution::Unresolved);
+    }
+});
+
+test('負例 3: 目録外の型は ResolvedOther (未解決と混ぜない)', function (): void {
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-resolved-other.php',
+        llmSeamFixture('seam-resolved-other'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(1);
+    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedOther);
+    expect($findings[0]->factory)->toBe('Fixture\LlmSeam\Unregistered');
+});
+
+test('負例 4: 受け取り関数でない関数の引数に渡す形は囲みの解決先が異なる', function (): void {
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-wrong-enclosing.php',
+        llmSeamFixture('seam-wrong-enclosing'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(1);
+    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
+    expect($findings[0]->enclosingCall)->toBe('Fixture\LlmSeam\Sink::consume');
+});
+
+test('負例 4b: 応答を加工してから渡す形は「直接の引数」と認めない', function (): void {
+    // `->executeSync().'suffix'` / `?: '{}'` / 配列に入れる形。受け手は解決できるが囲みは解決しない。
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-postprocessed.php',
+        llmSeamFixture('seam-postprocessed'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(3);
+    foreach ($findings as $finding) {
+        expect($finding->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
+        expect($finding->enclosingCall)->toBeNull();
+    }
+});
+
+test('負例 4c: 括弧の対応が取れない形は未解決として落とす', function (): void {
+    $findings = LlmResponseSeamScanner::executeSyncSites(
+        'fixtures/seam-unbalanced.php',
+        llmSeamFixture('seam-unbalanced'),
+        llmSeamFactories(),
+    );
+
+    expect($findings)->toHaveCount(1);
+    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::Unresolved);
+});
+
+test('負例 5b: 大文字小文字を変えた綴りと先頭 \\ の文字列 callable も検出する', function (): void {
+    // PHP の関数名は大文字小文字を区別しないので、綴りを変えるだけで抜けられてはいけない。
+    $violations = LlmResponseSeamScanner::decodeVocabularyViolations(
+        'fixtures/vocabulary-case-variants.php',
+        llmSeamFixture('vocabulary-case-variants'),
+    );
+
+    expect($violations)->toHaveCount(4);
+});
+
+test('負例 5: 復号語彙の回避経路をすべて検出する', function (): void {
+    $violations = LlmResponseSeamScanner::decodeVocabularyViolations(
+        'fixtures/vocabulary-violations.php',
+        llmSeamFixture('vocabulary-violations'),
+    );
+
+    // 素の呼び出し / 完全修飾 / use function の別名 / 文字列リテラル経由 / 囲みの印
+    expect($violations)->toHaveCount(5);
+    expect(implode("\n", $violations))->toContain('関数呼び出しの json_decode');
+    expect(implode("\n", $violations))->toContain('文字列リテラルの json_decode');
+    expect(implode("\n", $violations))->toContain('囲みの印を含む文字列リテラル');
+});
+
+test('正例 5b: 接頭辞・打ち消し・接尾辞つきの語とメソッド呼び出しと名前空間つきの別名は誤検出しない', function (): void {
+    expect(LlmResponseSeamScanner::decodeVocabularyViolations(
+        'fixtures/vocabulary-clean.php',
+        llmSeamFixture('vocabulary-clean'),
+    ))->toBe([]);
+});
+
+test('正例 6: 生の応答が復号点へ直接 1 回だけ渡る形は違反にならない', function (): void {
+    expect(LlmResponseSeamScanner::receiverFlowViolations(
+        'fixtures/receiver-flow-clean.php',
+        llmSeamFixture('receiver-flow-clean'),
+        'Fixture\LlmSeam\ReceiverFlowClean',
+        'fromLlmText',
+        LlmJson::class,
+        'decode',
+    ))->toBe([]);
+});
+
+test('負例 6: 復号点を通さない / 別変数へ移す / 2 回使う形はいずれも違反になる', function (
+    string $fixture,
+    string $class,
+): void {
+    expect(LlmResponseSeamScanner::receiverFlowViolations(
+        "fixtures/{$fixture}.php",
+        llmSeamFixture($fixture),
+        $class,
+        'fromLlmText',
+        LlmJson::class,
+        'decode',
+    ))->not->toBe([]);
+})->with([
+    'decode を通さない' => ['receiver-flow-missing-decode', 'Fixture\LlmSeam\ReceiverFlowMissingDecode'],
+    '別変数へ移す' => ['receiver-flow-rebound', 'Fixture\LlmSeam\ReceiverFlowRebound'],
+    '2 回使う' => ['receiver-flow-reused', 'Fixture\LlmSeam\ReceiverFlowReused'],
+]);
+
+test('負例 7: 公開面を 1 つ増やした見本は本番と同じ判定関数で赤くなる', function (): void {
+    expect(DecodePointPublicSurface::violations(LlmJson::class, LlmOutputInvalidException::class))->toBe([]);
+    expect(DecodePointPublicSurface::violations(LenientDecodePointProbe::class, LlmOutputInvalidException::class))
+        ->not->toBe([]);
+});
+
+test('母集団: 依頼文 factory の走査根は実在し、母集団は空でない', function (): void {
+    expect(is_dir(PromptFactoryPopulation::root()))->toBeTrue();
+    expect(PromptFactoryPopulation::classes())->not->toBeEmpty();
+});
+
+test('母集団: 存在しない走査根は fail-fast で落ちる (無言で空にしない)', function (): void {
+    expect(fn (): string => PromptFactoryPopulation::resolve('app/PromptsThatDoNotExist'))
+        ->toThrow(RuntimeException::class);
+});
+
+// ---- 目録判定 (検査 2 が使う純関数) の両方向 ----
+
+test('目録判定: 現行どおりの入力は違反にならない (正例)', function (): void {
+    expect(LlmSeamInventoryRules::otherReceiverViolations([], []))->toBe([]);
+    expect(LlmSeamInventoryRules::exemptionViolations(
+        ['app/Support/Llm/GuardedPrompt.php' => str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH)],
+        ['app/Support/Llm/GuardedPrompt.php', 'app/Prompts/SopExtractPrompt.php'],
+        ['app/Support/Llm/GuardedPrompt.php' => 1],
+    ))->toBe([]);
+});
+
+test('目録判定: 未登録の観測値 / stale 登録 / 短すぎる根拠はいずれも違反になる', function (): void {
+    // 未登録の観測値
+    expect(LlmSeamInventoryRules::otherReceiverViolations(['Foo\Bar'], []))->not->toBe([]);
+    // stale 登録 (登録されているが観測されない)
+    expect(LlmSeamInventoryRules::otherReceiverViolations(
+        [],
+        ['Foo\Bar' => str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH)],
+    ))->not->toBe([]);
+    // 30 文字未満の根拠
+    expect(LlmSeamInventoryRules::otherReceiverViolations(['Foo\Bar'], ['Foo\Bar' => '短い理由']))->not->toBe([]);
+    // 末尾一致では通さない (完全修飾名の完全一致。共通規約 (a))
+    expect(LlmSeamInventoryRules::otherReceiverViolations(
+        ['Foo\BarBaz'],
+        ['Foo\Baz' => str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH)],
+    ))->not->toBe([]);
+});
+
+test('目録判定: 免除の実在 / 根拠 / 前提のいずれが欠けても違反になる', function (): void {
+    $reason = str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH);
+
+    // 実在しないパス
+    expect(LlmSeamInventoryRules::exemptionViolations(['app/Gone.php' => $reason], ['app/Here.php'], []))
+        ->not->toBe([]);
+    // 30 文字未満の根拠
+    expect(LlmSeamInventoryRules::exemptionViolations(['app/Here.php' => '短い'], ['app/Here.php'], ['app/Here.php' => 1]))
+        ->not->toBe([]);
+    // 前提 (executeSync() を持つ) が失われた免除
+    expect(LlmSeamInventoryRules::exemptionViolations(['app/Here.php' => $reason], ['app/Here.php'], ['app/Here.php' => 0]))
+        ->not->toBe([]);
+});

```

---

## 検証結果 (Round 3 時点)

- main (T254 マージ済み 8d980eb7) を task branch へ取り込み済み。競合なし。
- `composer phpstan`: OK (エラー 0)
- `vendor/bin/pint --test` (リポジトリ全体): passed
  (Round 2 で「main 側に元からある未整形ファイル」と述べた
   `devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/verify-predicate.php` は
   T254 のマージで解消済み)
- `pnpm lint` / `pnpm typecheck` / `pnpm typecheck:packages` / `pnpm build` / `pnpm build:packages`: 成功
- `pnpm test`: 179 files / 2398 tests passed
- `pnpm test:packages`: 10 files / 106 tests passed
- 個別: 復号点契約 30 passed / 走査器の自己検査 21 passed / 単一性 gate 11 passed /
  AnalysisPipeline 50 passed / OCR 5 passed / canned 18 passed
- `composer test` (全数) を main 取り込み後に実行中。結果は最終報告に載せる。
  Round 1 の 3 failed のうち `DefensiveInstructionsPresenceTest` は修正済み、
  `BughuntSelfTestExecutionTest` 2 件は負荷起因のタイムアウトで、負荷が下がった状態で
  3 tests passed を確認済み。
- `pipeline-smoke --check` / 互換性確認 A・B は外部確認待ち (理由は対応マトリクスのとおり)。

残る指摘があれば挙げてください。無ければ全体判定を 1 行で明示してください。
