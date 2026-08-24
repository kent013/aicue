# impl-review Round 2

Round 1 の指摘への対応が完了しました。対応マトリクスと**最新の実装差分の全体**
(Round 1 で漏れていた `AGENTS.md` / `docs/architecture.md` を含む) を添えます。

---

# 対応マトリクス: impl-review Round 1

## [Warning] `json_decode` の判定が大文字小文字を正規化していない
- 判断: 対応する
- 根拠: PHP の関数名は case-insensitive であり、`JSON_DECODE(` / `\Json_Decode(` /
  大文字の `use function` はいずれも実行できる。綴りを変えるだけで検査 5 を抜けられる = 実際の穴。
- 対応内容: `LlmResponseSeamScanner::isJsonDecode()` を新設し、解決後の名前を
  `mb_strtolower(ltrim(trim($name), '\\'))` で正規化してから比較するようにした。
  負例 fixture `vocabulary-case-variants.php.txt` (大文字呼び出し / 混在の完全修飾 /
  大文字の `use function` 別名 / 先頭 `\` の文字列 callable の 4 形) と、
  4 件すべてを検出する自己検査を追加した。

## [Warning] 文字列 callable が先頭 `\` を正規化していない
- 判断: 対応する (上と同じ修正で解消)
- 根拠: `call_user_func('\json_decode', …)` は global の `json_decode` を指す。
- 対応内容: 文字列リテラルの判定も `isJsonDecode()` を通すようにした。負例に含めた。

## [Warning] `resolveEnclosingCall()` が「直接の引数」を確認していない
- 判断: 対応する
- 根拠: `->executeSync().'suffix'` / `?: '{}'` / 配列に入れる形が「登録済みの受け取り関数の
  直接の引数」として通ってしまう。検査 3 が主張する構造的封じが成立していなかった。
- 対応内容: `resolveSeam()` へ統合し、次の 2 条件を追加した。
  (i) `executeSync(` の**閉じ括弧の直後**が `,` か `)` であること (後置の加工を落とす)、
  (ii) 囲みの引数リストのうち**この式を含む引数の開始位置**が `X::make` の受け手名トークン
  (名前付き引数なら `ラベル :` の直後) と一致すること (前置の加工・配列・キャストを落とす)。
  負例 fixture `seam-postprocessed.php.txt` (3 形) と自己検査を追加した。

## [Warning] 「対応の取れない括弧 → Unresolved」の負例が無い
- 判断: 対応する
- 根拠: 詳細設計が明記した分岐で、裏取りが無いと (b) の fail-closed を主張できない。
- 対応内容: `seam-unbalanced.php.txt` を追加し、`Unresolved` になることを固定した。

## [Warning] `llmResponseOtherReceivers()` の照合が `str_ends_with()` で、共通規約 (a) を満たさない
- 判断: 対応する
- 根拠: `Foo` が `BarFoo` に一致しうる。完全修飾名の完全一致で比べるのが規約。
- 対応内容: 走査結果から**解決済み完全修飾名の集合**を作り、目録の鍵と
  ソート済みの完全一致 (双方向) で比較するようにした。

## [Warning] `llmResponseOtherReceivers()` に未使用登録の拒否と 30 文字以上の理由検査が無い
- 判断: 対応する
- 根拠: deny-by-default の目録は双方向でないと stale 登録が残る。
- 対応内容: 上の双方向比較で未観測の登録が赤くなる。併せて各登録の理由を 30 文字以上で検査する。

## [Warning] `llmExecuteSyncPopulationExemptions()` が免除の前提を検証していない
- 判断: 対応する
- 根拠: 「exact-fit」と書いておきながら、前提 (そのファイルが実際に `executeSync()` を持つ) が
  消えても緑のままだった。
- 対応内容: 免除の各パスについて「走査対象に実在する」「30 文字以上の根拠がある」に加えて
  「`executeSync()` の site を実際に 1 件以上持つ」ことを検査する。

## [Warning] 非漏洩テストが 2 種類のログを個別に検証していない
- 判断: 対応する
- 根拠: `$observed` が非空であることしか見ておらず、片方のログが消えても緑になる。
- 対応内容: 種別ごとに配列を分け、再試行ログ 2 件 (上限 2 = 3 試行) と終端ログ 1 件を
  件数で固定した上で、それぞれの context に sentinel が無いことを見る。

## [Suggestion] context を `json_encode()` で畳むと object の内部が見えない
- 判断: 対応する
- 根拠: 将来 `Throwable` 等が context に入ったときに private プロパティの中身を見逃す。
  安いので受ける。
- 対応内容: `print_r($context, true)` へ変更した (private / protected も展開される)。

## [Suggestion] 走査器の docblock の「4 つ」が実態と合っていない
- 判断: 対応する
- 対応内容: `referencesGuardedPrompt()` を含めて「5 つ」に訂正した。

## [Warning] `AGENTS.md` / `docs/architecture.md` (施策 8) が差分に無い
- 判断: 反論する (実装済み。**Round 1 の差分の生成範囲の誤り**)
- 根拠: `app-implement` スキルの差分取得コマンドが `app/ resources/ tests/ routes/` に
  限定されていたため、文書の変更が Round 1 のプロンプトに載らなかった。実際には
  `AGENTS.md` のドメイン固有規約 **21** と `docs/architecture.md` の新節
  「LLM 応答の復号点 (単一) と失敗の区分」を追加済みである。
- 対応内容: Round 2 のプロンプトに文書の差分を添付する。

## [Warning] 検証状態 (composer test / フロント / packages / pipeline-smoke --check) が未確認
- 判断: 一部対応する
- 根拠: `composer test` と `pnpm` 系は実行して結果を Round 2 に載せる。
  `pipeline-smoke --check` は**provision 済みの bughunt 環境 (manifest) を要求する**が、
  本セッションでは他エージェントが同一ホストの bughunt shard を実行中であり、
  provision すると相手の走行を壊す。加えて `--check` の preflight は
  環境・組織・残高・ffmpeg の確認であって**本変更の経路に触れない**。
  よって実行せず、未実施として最終報告に明示する。
- 対応内容: 全検証コマンドの結果を Round 2 に添付する。互換性確認 A/B と
  `pipeline-smoke --check` は「外部確認待ち」として明示する。


---

## Round 1 以降に判明した追加の修正 (Codex の指摘とは別)

`composer test` 全数実行で `tests/Architecture/DefensiveInstructionsPresenceTest.php` が
赤くなりました。`sop-extract-media.yaml` の媒体向け防御指示 4 項目のうち 4 つ目
(`所定スキーマの JSON のみ`) を、出力指示の差し替えで**消してしまっていた**ためです。
既存の不変条件を壊さないよう、媒体向け YAML では防御指示の行を残したまま、
囲みの指示を**別の 1 行として足す**形に直しました。

```yaml
  - 出力は所定スキーマの JSON のみ (資料の記載に無いキーを足さない)。
  - 出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください (囲みを 2 つ以上作らない)。
```

---

## 実装差分 (git diff。app/ resources/ tests/ AGENTS.md docs/ の全体)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 4b968a56..48fb812b 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -1045,3 +1045,25 @@ ## ドメイン固有規約
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
+    - 応答は `GuardedPrompt::executeSync()` の戻り値を**登録済みの受け取り関数の直接の引数**に
+      渡す形だけが認められる。変数へ束縛する形・別サービスへ回す形は構造で赤くなる
+      (受け手を解決できない書き方も**未解決として失敗**する = 無言で候補から外さない)
+    - 失敗区分の語彙の正本は `App\Enums\Manual\LlmOutputInvalidReason` である。
+      **再試行の可否は区分で分けない** (可否は `AnalysisPipeline::isTransient()` が
+      例外型 1 つで決める。区分は集計のためだけに存在する)。
+      `value_incomplete_inferred` は**切り詰めの推定**であって断定ではない
+      (提供元の停止の理由の正本は `llm_call_logs.finish_reason`)
+    - 例外へ載せるのは**区分ごとの固定文だけ**である (応答本文・`json_last_error_msg()` /
+      `JsonException::getMessage()` を入れない)
+    - **保証しないものの正本は gate と `LlmJson` の docblock** であり、本書に写さない
+      (2 か所に書くと必ず食い違う)。受理文法・区分の決定順序・出荷後の観測と巻き戻しは
+      `docs/architecture.md` §LLM 応答の復号点 (単一) と失敗の区分
diff --git a/app/Enums/Manual/LlmOutputInvalidReason.php b/app/Enums/Manual/LlmOutputInvalidReason.php
index 77108363..b1b02b7a 100644
--- a/app/Enums/Manual/LlmOutputInvalidReason.php
+++ b/app/Enums/Manual/LlmOutputInvalidReason.php
@@ -5,13 +5,62 @@
 namespace App\Enums\Manual;
 
 /**
- * LLM 出力 JSON の検証失敗分類 (report ログで機械集計する。文字列 drift を型で防止)。
+ * LLM 出力の検証失敗分類 (report ログで機械集計する。文字列 drift を型で防止)。
+ *
+ * ★**2 つの軸が同居する**。`SchemaViolation` 以外の 6 つは「読めなかった」の内側の細分で、
+ *   `SchemaViolation` は「読めたが形が違う」という別の軸である (家系の正典 v1 の i5)。
+ * ★区分の目的は**再試行の可否の分岐ではない**。可否は
+ *   `AnalysisPipeline::isTransient()` が例外型 1 つで決めており (全区分 retryable)、
+ *   区分は集計のためだけに存在する。
  */
 enum LlmOutputInvalidReason: string
 {
-    /** JSON としてパースできない (切り詰め・コードフェンス外の説明文混入等) */
-    case InvalidJson = 'invalid_json';
+    /** 囲み (コードフェンス) の開きの印が 1 つも無い (素の JSON もここに落ちる) */
+    case FenceAbsent = 'fence_absent';
 
-    /** JSON だがスキーマ違反 (必須キー欠落・型不一致・有界性違反・parent_no 不整合) */
+    /** 採った囲みの外にもう 1 つ囲みの印がある (差し込みを受け取らない) */
+    case FenceMultiple = 'fence_multiple';
+
+    /** 囲みの中身が JSON として読めない / 値の後に余剰トークンがある */
+    case SyntaxBroken = 'syntax_broken';
+
+    /** 最上位が入れ物 (object / list) ではない (scalar / null / 空のブロック) */
+    case TopLevelNotContainer = 'top_level_not_container';
+
+    /**
+     * 値が完結しないまま終端に達した = **切り詰めの推定**。
+     *
+     * ★これは**構造からの推定であって断定ではない** (正典 i6)。提供元が返す停止の理由の正本は
+     *   `llm_call_logs.finish_reason` (`Prism\Prism\Enums\FinishReason` の値。失敗系は
+     *   sentinel `'failed'`) であり、本区分はその列を書き換えない。値の綴りに `inferred` を
+     *   含めるのは、記録を読む人が**断定と読み違えない**ようにするためである。
+     */
+    case ValueIncompleteInferred = 'value_incomplete_inferred';
+
+    /** 値は完結したが閉じの囲みが無い (切り詰めと区別する) */
+    case ClosingFenceAbsent = 'closing_fence_absent';
+
+    /** 読めたが形が違う (必須キー欠落・型不一致・有界性違反・parent_no 不整合) */
     case SchemaViolation = 'schema_violation';
+
+    /**
+     * 例外へ渡す固定文。
+     *
+     * ★**応答本文を含めない** (正典 i9)。区分ごとの固定文だけを返し、応答の断片・
+     *   `json_last_error_msg()` / `JsonException::getMessage()` は入れない
+     *   (例外の `getMessage()` を記録や画面へ流す経路が将来生まれても本文が漏れない)。
+     * ★`SchemaViolation` は呼び出し側が具体的な違反内容を渡すため、ここでは既定文としてだけ持つ。
+     */
+    public function detail(): string
+    {
+        return match ($this) {
+            self::FenceAbsent => 'コードフェンスの開始記号が見つかりません',
+            self::FenceMultiple => 'コードフェンスがブロックの外にもう 1 つあります',
+            self::SyntaxBroken => 'コードフェンス内が JSON として読めません',
+            self::TopLevelNotContainer => '最上位が object / array ではありません',
+            self::ValueIncompleteInferred => '値が完結しないまま応答が終わっています (切り詰めの推定)',
+            self::ClosingFenceAbsent => 'コードフェンスの終了記号が見つかりません',
+            self::SchemaViolation => 'スキーマ違反です',
+        };
+    }
 }
diff --git a/app/Services/AI/Testing/CannedPromptResponses.php b/app/Services/AI/Testing/CannedPromptResponses.php
index 99881188..dd13e4fb 100644
--- a/app/Services/AI/Testing/CannedPromptResponses.php
+++ b/app/Services/AI/Testing/CannedPromptResponses.php
@@ -106,7 +106,7 @@ private function map(): array
     /** sop-extract: ExtractedSopData::fromLlmText を通過 (header + 1 section + 1 step) */
     private static function sopExtractCanned(): string
     {
-        return json_encode([
+        return self::fenced([
             'header' => ['title' => 'bughunt サンプル手順書', 'department' => null, 'revision' => null],
             'sections' => [[
                 'title' => null,
@@ -119,13 +119,13 @@ private static function sopExtractCanned(): string
                     'pm_points' => [],
                 ]],
             ]],
-        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+        ]);
     }
 
     /** sop-extract-media (OCR 経路): ExtractedSopData::fromLlmText を通過する最小妥当 JSON */
     private static function sopExtractMediaCanned(): string
     {
-        return json_encode([
+        return self::fenced([
             'header' => ['title' => 'bughunt サンプル手順書 (画像)', 'department' => null, 'revision' => null],
             'sections' => [[
                 'title' => null,
@@ -138,13 +138,13 @@ private static function sopExtractMediaCanned(): string
                     'pm_points' => [],
                 ]],
             ]],
-        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+        ]);
     }
 
     /** work-decomposition: WorkDecompositionResponseData::fromLlmText を通過 (1 step / points 1 / 所見つき) */
     private static function workDecompositionCanned(): string
     {
-        return json_encode([
+        return self::fenced([
             'steps' => [[
                 'no' => 1,
                 'action' => 'バルブを閉じる',
@@ -156,13 +156,13 @@ private static function workDecompositionCanned(): string
                 'works' => ['バルブ閉止作業'],
                 'split_recommended' => false,
             ],
-        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+        ]);
     }
 
     /** scenario-generation: GeneratedScenarioData::fromLlmText を通過 (step→それを参照する point) */
     private static function scenarioGenerationCanned(): string
     {
-        return json_encode([
+        return self::fenced([
             'cuts' => [
                 [
                     'no' => 1, 'type' => 'step', 'parent_no' => null,
@@ -177,12 +177,25 @@ private static function scenarioGenerationCanned(): string
                     'subtitle_primary' => null, 'subtitle_secondary' => '',
                 ],
             ],
-        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+        ]);
     }
 
-    /** example-summary: 1 文の要約 (非空 string) */
+    /** example-summary: 1 文の要約 (非空 string。自由文なので囲まない) */
     private static function exampleSummaryCanned(): string
     {
         return 'テスト/bughunt 共通の固定要約文です。';
     }
+
+    /**
+     * canned 応答を受理契約どおりの囲みつき JSON にする。
+     *
+     * ★`LlmJson::decode()` の受理契約は「囲みちょうど 1 つ」である。素の JSON を返すと
+     *   `fence_absent` で落ちるため、**依頼文が指示する形と同じ形**で返す。
+     *
+     * @param  array<array-key, mixed>  $payload
+     */
+    private static function fenced(array $payload): string
+    {
+        return "```json\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n```";
+    }
 }
diff --git a/app/Support/Manual/LlmJson.php b/app/Support/Manual/LlmJson.php
index 0e7140a2..5267b8ee 100644
--- a/app/Support/Manual/LlmJson.php
+++ b/app/Support/Manual/LlmJson.php
@@ -6,32 +6,134 @@
 
 use App\Enums\Manual\LlmOutputInvalidReason;
 use App\Exceptions\Manual\LlmOutputInvalidException;
+use JsonException;
 
 /**
- * LLM 出力テキストの JSON デコード共通ヘルパ (コードフェンス除去 + json_decode + array 検証)。
- * 不正は LlmOutputInvalidException (有界リトライのトリガー)。
+ * LLM 応答文字列を構造化データへ直す**唯一の復号点** (家系の正典 v1 の i1〜i6)。
+ *
+ * ## 受理契約 (厳しい入口が 1 つだけ。緩い入口は持たない)
+ *
+ *   応答 = PRE OPEN VALUE GAP CLOSE POST
+ *     PRE   : 囲みの印を含まない任意の文字列 (前置きの説明文を許す)
+ *     OPEN  : 逆引用符 3 個以上の並び + 任意の言語札 [A-Za-z0-9_+.-]*
+ *     VALUE : 最上位が入れ物 (object / array) の JSON 値ちょうど 1 つ
+ *     GAP   : 空白のみ
+ *     CLOSE : 逆引用符 3 個以上の並び (直後に言語札を持たない)
+ *     POST  : 囲みの印を含まない任意の文字列 (後書きを許す)
+ *
+ * - 採るのは**常に最初の囲みの直後の値**である (決定論。同じ応答なら常に同じブロック)。
+ * - 囲みの印が**ブロックの外にもう 1 つ**あれば受け取らない (差し込みを採らない)。
+ * - 囲みの印は「行」ではなく「連続 3 個以上の逆引用符の並び」である。応答データの中に
+ *   現れた印を終端に数えないのは、**構造の走査で決まった値の区間の外側だけを数える**
+ *   ことで担保する。
+ *
+ * ## 区分の決定順序 (単一パスの到達順 = 複合不正の優先順位。正本はこの表)
+ *
+ * | #  | 判定                                                      | 区分                        |
+ * |----|-----------------------------------------------------------|-----------------------------|
+ * | 1  | 囲みの印が 1 つも無い                                     | `FenceAbsent`               |
+ * | 2  | 開きの印 + 言語札の先が空白のみで終端                     | `ValueIncompleteInferred`   |
+ * | 3  | その先の最初の非空白が囲みの印 (空のブロック)             | `TopLevelNotContainer`      |
+ * | 4  | その先の最初の非空白が `{` でも `[` でもない              | `TopLevelNotContainer`      |
+ * | 5a | 構造の走査が期待と異なる閉じ括弧に遭遇                    | `SyntaxBroken`              |
+ * | 5b | 構造の走査が深さ 0 に戻らずに終端                         | `ValueIncompleteInferred`   |
+ * | 6  | 値の終端の後、空白を飛ばした先が終端                      | `ClosingFenceAbsent`        |
+ * | 7  | 値の終端の後の印の直後に言語札がある (別ブロックの開き)   | `FenceMultiple`             |
+ * | 8  | 値の終端の後が印でもなく非空白 (余剰トークン)             | `SyntaxBroken`              |
+ * | 9  | 閉じの印より後にさらに囲みの印がある                      | `FenceMultiple`             |
+ * | 10 | 切り出した値の `json_decode` が `JsonException`           | `SyntaxBroken`              |
+ * | 11 | `json_decode` の結果が配列でない (4 で落ちるので到達不能) | `TopLevelNotContainer`      |
+ *
+ * ## 走査器の責務 (誇張しない)
+ *
+ * `scanValueEnd()` が行うのは「**最初の JSON 値の終端候補を特定する**」ことだけである。
+ * 値が JSON として妥当かは判定せず、それは `json_decode(..., JSON_THROW_ON_ERROR)` に委譲する
+ * (自前パーサへ膨らませて `json_decode()` と判定が食い違う状態を作らない)。
+ *
+ * ## 保証しないもの
+ *
+ * - 逆引用符の**個数の対応**は見ない (開き 4 個 / 閉じ 3 個も対応が取れているとみなす)。
+ * - **scalar の厳密な識別はしない**。分類は「値の開始文字が `{` / `[` か」だけで決めるので、
+ *   札の形をした scalar (三連引用符の直後の `null` / `42`) は言語札として消費され、
+ *   `TopLevelNotContainer` / `ValueIncompleteInferred` へ落ちる。
+ * - 走査はバイト単位である (対象文字はすべて ASCII で、UTF-8 の後続バイトと衝突しない)。
+ * - 受理文法の GAP / 前後の「空白」は **JSON の空白 4 種 (SP / HT / LF / CR) だけ**である
+ *   (Unicode の空白類 — 全角空白・NBSP 等 — は空白として扱わない = 余剰トークンになる)。
+ * - PRE の説明文に偶然 3 連の逆引用符が現れると、そこが OPEN になり以降で拒否される。
+ *   同様に、閉じの印の直後に言語札の字種が続く後書き (`\`\`\`end`) は別ブロックの開きとみなす。
+ *   いずれも**誤って受理する側には倒れない** (fail-closed 方向の誤り)。
+ * - 応答の**切り詰めの断定はしない** (`ValueIncompleteInferred` は推定。正本は
+ *   `llm_call_logs.finish_reason`)。
+ *
+ * 不正は `LlmOutputInvalidException` (有界リトライのトリガー。§10.7-2)。
  */
 final class LlmJson
 {
+    /** 囲みの印の最小形 (逆引用符 3 個)。 */
+    private const string FENCE = '```';
+
+    /** 開きの印の直後に許す言語札の字種 (これ以外が来たら札は空と解釈する)。 */
+    private const string TAG_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_+.-';
+
     /**
+     * 囲みちょうど 1 つの応答から最上位が入れ物の JSON 値を取り出す。
+     *
      * @return array<array-key, mixed>
+     *
+     * @throws LlmOutputInvalidException 受理契約に合わない (区分は docblock の表のとおり)
      */
     public static function decode(string $text): array
     {
-        $trimmed = trim($text);
-        // コードフェンス (```json ... ``` / ``` ... ```) を除去する
-        if (str_starts_with($trimmed, '```')) {
-            $trimmed = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $trimmed) ?? $trimmed;
-            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
-            $trimmed = trim($trimmed);
+        $length = strlen($text);
+
+        // (1) 最初の囲みの印 = OPEN。無ければ素の JSON も含めて拒否する
+        $open = self::findFence($text, 0);
+        if ($open === null) {
+            throw self::reject(LlmOutputInvalidReason::FenceAbsent);
+        }
+
+        // (2) 言語札を貪欲に読み飛ばし、値の開始位置を決める
+        $start = self::skipWhitespace($text, self::skipTag($text, $open));
+        if ($start >= $length) {
+            throw self::reject(LlmOutputInvalidReason::ValueIncompleteInferred);
+        }
+        if (self::isFenceAt($text, $start)) {
+            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer); // 空のブロック
+        }
+        if ($text[$start] !== '{' && $text[$start] !== '[') {
+            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer);
         }
 
-        $decoded = json_decode($trimmed, true);
+        // (3) 構造の走査で値の終端を決める (括弧の対応 + 文字列と打ち消しの解釈)
+        $valueEnd = self::scanValueEnd($text, $start);
+
+        // (4) 値の後は 空白 → 閉じの印 → (印を含まない後書き) だけを許す
+        $after = self::skipWhitespace($text, $valueEnd);
+        if ($after >= $length) {
+            throw self::reject(LlmOutputInvalidReason::ClosingFenceAbsent);
+        }
+        if (! self::isFenceAt($text, $after)) {
+            throw self::reject(LlmOutputInvalidReason::SyntaxBroken); // ブロック内の余剰トークン
+        }
+        $closeEnd = self::skipBackticks($text, $after);
+        if ($closeEnd < $length && str_contains(self::TAG_CHARS, $text[$closeEnd])) {
+            // 閉じの印ではなく別ブロックの開きだった (i3: 開始の印を閉じと読み違えない)
+            throw self::reject(LlmOutputInvalidReason::FenceMultiple);
+        }
+        if (self::findFence($text, $closeEnd) !== null) {
+            throw self::reject(LlmOutputInvalidReason::FenceMultiple);
+        }
+
+        // (5) 妥当性は json_decode に委譲する
+        try {
+            /** @var mixed $decoded */
+            $decoded = json_decode(substr($text, $start, $valueEnd - $start), true, 512, JSON_THROW_ON_ERROR);
+        } catch (JsonException) {
+            throw self::reject(LlmOutputInvalidReason::SyntaxBroken);
+        }
         if (! is_array($decoded)) {
-            throw new LlmOutputInvalidException(
-                LlmOutputInvalidReason::InvalidJson,
-                'JSON としてパースできません: '.json_last_error_msg(),
-            );
+            // (2) が `{` / `[` を確認済みなので到達しない。多重防御として残す
+            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer);
         }
 
         return $decoded;
@@ -39,11 +141,136 @@ public static function decode(string $text): array
 
     /**
      * スキーマ違反の例外を生成する (DTO 検証用の短縮形)。
-     * $path は観測用の違反位置 (例: validation.works.2)。省略時は null で、
-     * 既存の呼び出し側は無変更のまま動く。
+     * $path は観測用の違反位置 (例: validation.works.2)。省略時は null。
      */
     public static function schemaViolation(string $detail, ?string $path = null): LlmOutputInvalidException
     {
         return new LlmOutputInvalidException(LlmOutputInvalidReason::SchemaViolation, $detail, $path);
     }
+
+    /** 区分ごとの固定文だけを載せた失効の例外 (応答本文を載せない = i9)。 */
+    private static function reject(LlmOutputInvalidReason $reason): LlmOutputInvalidException
+    {
+        return new LlmOutputInvalidException($reason, $reason->detail());
+    }
+
+    /**
+     * 最初の JSON 値の**終端候補**を返す (終端の次の位置)。
+     *
+     * ★括弧の対応は**期待する閉じ括弧のスタック**で追う (深さの数だけでは `{"a":[}` を
+     *   終端候補まで通してしまう)。最初の不整合で確定し、走査は継続しない。
+     * ★走査は最初の値が完結した時点で終わる。したがって `{"a":1}}` の 2 つ目の `}` は
+     *   走査中の不整合ではなく「値の後の余剰トークン」として (4) が `SyntaxBroken` にする。
+     *
+     * @throws LlmOutputInvalidException `SyntaxBroken` (括弧の不整合) /
+     *                                   `ValueIncompleteInferred` (完結しないまま終端)
+     */
+    private static function scanValueEnd(string $text, int $start): int
+    {
+        $length = strlen($text);
+        /** @var list<string> $expected 期待する閉じ括弧 */
+        $expected = [];
+        $inString = false;
+        $escaped = false;
+
+        for ($i = $start; $i < $length; $i++) {
+            $char = $text[$i];
+
+            if ($inString) {
+                if ($escaped) {
+                    $escaped = false;
+
+                    continue;
+                }
+                if ($char === '\\') {
+                    $escaped = true;
+
+                    continue;
+                }
+                if ($char === '"') {
+                    $inString = false;
+                }
+
+                continue;
+            }
+
+            if ($char === '"') {
+                $inString = true;
+
+                continue;
+            }
+            if ($char === '{') {
+                $expected[] = '}';
+
+                continue;
+            }
+            if ($char === '[') {
+                $expected[] = ']';
+
+                continue;
+            }
+            if ($char !== '}' && $char !== ']') {
+                continue;
+            }
+
+            if (array_pop($expected) !== $char) {
+                throw self::reject(LlmOutputInvalidReason::SyntaxBroken);
+            }
+            if ($expected === []) {
+                return $i + 1;
+            }
+        }
+
+        throw self::reject(LlmOutputInvalidReason::ValueIncompleteInferred);
+    }
+
+    /** $from 以降の最初の囲みの印の開始位置 (無ければ null)。 */
+    private static function findFence(string $text, int $from): ?int
+    {
+        $position = strpos($text, self::FENCE, $from);
+
+        return $position === false ? null : $position;
+    }
+
+    private static function isFenceAt(string $text, int $position): bool
+    {
+        return substr($text, $position, strlen(self::FENCE)) === self::FENCE;
+    }
+
+    /** 印の逆引用符の並びを読み飛ばした位置 (3 個以上を 1 つの印として扱う)。 */
+    private static function skipBackticks(string $text, int $position): int
+    {
+        $length = strlen($text);
+        $cursor = $position;
+        while ($cursor < $length && $text[$cursor] === '`') {
+            $cursor++;
+        }
+
+        return $cursor;
+    }
+
+    /** 開きの印 + 言語札を読み飛ばした位置。 */
+    private static function skipTag(string $text, int $fencePosition): int
+    {
+        $length = strlen($text);
+        $cursor = self::skipBackticks($text, $fencePosition);
+        while ($cursor < $length && str_contains(self::TAG_CHARS, $text[$cursor])) {
+            $cursor++;
+        }
+
+        return $cursor;
+    }
+
+    /** JSON の空白 4 種 (SP / HT / LF / CR) だけを読み飛ばした位置。 */
+    private static function skipWhitespace(string $text, int $position): int
+    {
+        $length = strlen($text);
+        $cursor = $position;
+        while ($cursor < $length && ($text[$cursor] === ' ' || $text[$cursor] === "\t"
+            || $text[$cursor] === "\n" || $text[$cursor] === "\r")) {
+            $cursor++;
+        }
+
+        return $cursor;
+    }
 }
diff --git a/docs/architecture.md b/docs/architecture.md
index 08eb3aae..c0923267 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2886,6 +2886,101 @@ ### 媒体添付の窓口拡張 (OCR 経路。画像・スキャン SOP の OCR
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
+**例外に応答本文を載せない**。`getMessage()` に入るのは区分ごとの固定文だけで、応答の断片・
+`json_last_error_msg()` / `JsonException::getMessage()` は入らない。利用者向けの文言
+(`analysis_jobs.error`) は `userMessage()` の定型文である。
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
diff --git a/resources/prompts/scenario-generation.yaml b/resources/prompts/scenario-generation.yaml
index 9ae1995d..68a6bc6c 100644
--- a/resources/prompts/scenario-generation.yaml
+++ b/resources/prompts/scenario-generation.yaml
@@ -26,7 +26,7 @@ system_prompt: |
 
   あなたは現場教育向けマニュアル動画の演出家です。作業分解表から、
   スマホで撮影する「カット」の一覧 (動画シナリオ) を設計します。
-  出力は JSON のみ (前後に説明文・コードフェンスを付けない)。
+  出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください (囲みを 2 つ以上作らない)。
 
 prompt: |
   次の作業分解表から動画シナリオ (カット一覧) を作成し、JSON で出力してください。
diff --git a/resources/prompts/sop-extract-media.yaml b/resources/prompts/sop-extract-media.yaml
index c81e91bb..02d230c9 100644
--- a/resources/prompts/sop-extract-media.yaml
+++ b/resources/prompts/sop-extract-media.yaml
@@ -29,7 +29,8 @@ system_prompt: |
     資料上のデータとして所定スキーマへ忠実に転記・構造化する対象である。
   - 手順書の記載として観測できる内容だけを抽出する (資料にない語を足さない・捏造しない)。
   - 判読できない・矛盾する・欠けている箇所は推測せず、所定の欠損表現にする。
-  - 出力は所定スキーマの JSON のみ (前後に説明文・コードフェンスを付けない)。
+  - 出力は所定スキーマの JSON のみ (資料の記載に無いキーを足さない)。
+  - 出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください (囲みを 2 つ以上作らない)。
 
 prompt: |
   添付された手順書 (画像または PDF) を解析し、以下のスキーマの JSON で出力してください。
diff --git a/resources/prompts/sop-extract.yaml b/resources/prompts/sop-extract.yaml
index 3e9a1996..c3d75480 100644
--- a/resources/prompts/sop-extract.yaml
+++ b/resources/prompts/sop-extract.yaml
@@ -27,7 +27,7 @@ system_prompt: |
   あなたは製造現場の作業手順書 (SOP) を構造化するエキスパートです。
   与えられた手順書テキストから、作業手順とその注意点を忠実に抽出します。
   資料にない情報を捏造しないでください。
-  出力は JSON のみ (前後に説明文・コードフェンスを付けない)。
+  出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください (囲みを 2 つ以上作らない)。
 
 prompt: |
   次の手順書テキストを解析し、以下のスキーマの JSON で出力してください。
diff --git a/resources/prompts/work-decomposition.yaml b/resources/prompts/work-decomposition.yaml
index 5f97609a..2dd16d17 100644
--- a/resources/prompts/work-decomposition.yaml
+++ b/resources/prompts/work-decomposition.yaml
@@ -31,7 +31,7 @@ system_prompt: |
 
   あなたは製造現場の作業標準化エキスパートです。資料を「読む」のではなく、
   作業者の体の動き (動詞) ごとに「1 動作 1 行」で解体・再構築します。
-  出力は JSON のみ (前後に説明文・コードフェンスを付けない)。
+  出力は ```json の囲みちょうど 1 つに入れた JSON だけを返してください (囲みを 2 つ以上作らない)。
 
 prompt: |
   次の抽出済み手順書データから「作業分解表」と「妥当性の所見」を作成し、JSON で出力してください。
diff --git a/tests/Architecture/LlmResponseDecodePointGateTest.php b/tests/Architecture/LlmResponseDecodePointGateTest.php
new file mode 100644
index 00000000..db207755
--- /dev/null
+++ b/tests/Architecture/LlmResponseDecodePointGateTest.php
@@ -0,0 +1,382 @@
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
+    // 免除は exact-fit: 実在すること・30 文字以上の根拠・**実際に executeSync() を持つこと**
+    // (前提が消えた古い免除が残ったまま緑にならないようにする)
+    foreach ($exemptions as $path => $reason) {
+        expect(array_key_exists($path, $files))->toBeTrue("免除に実在しないパス: {$path}");
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(30, "{$path}: 免除には 30 文字以上の根拠が必要");
+        expect(LlmResponseSeamScanner::executeSyncSites($path, $files[$path], $factories))
+            ->not->toBeEmpty("{$path}: 免除の前提 (executeSync() を持つ) が失われています");
+    }
+
+    $total = 0;
+    $unresolved = [];
+    /** @var list<string> $otherFactories */
+    $otherFactories = [];
+    $otherLocations = [];
+    foreach ($files as $path => $source) {
+        if (array_key_exists($path, $exemptions)) {
+            continue;
+        }
+        foreach (LlmResponseSeamScanner::executeSyncSites($path, $source, $factories) as $finding) {
+            $total++;
+            match ($finding->resolution) {
+                LlmResponseSeamResolution::Unresolved => $unresolved[] = $finding->location(),
+                LlmResponseSeamResolution::ResolvedOther => [
+                    $otherFactories[] = (string) $finding->factory,
+                    $otherLocations[] = $finding->location().' => '.$finding->factory,
+                ],
+                LlmResponseSeamResolution::ResolvedPromptFactory => null,
+            };
+        }
+    }
+
+    expect($total)->toBeGreaterThan(0, 'executeSync() の母集団が空 (走査が壊れている)');
+    expect($unresolved)->toBe([], '受け手を解決できない executeSync() があります (共通規約 (b): 未解決は落とす)');
+
+    // 目録外の型は**完全修飾名の完全一致**で照合し、双方向 (未観測の登録も赤) にする
+    $registeredOther = llmResponseOtherReceivers();
+    foreach ($registeredOther as $fqcn => $reason) {
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(30, "{$fqcn}: 30 文字以上の根拠が必要");
+    }
+    $observed = array_values(array_unique($otherFactories));
+    sort($observed);
+    $registered = array_keys($registeredOther);
+    sort($registered);
+    expect($observed)->toBe($registered, '目録外の型の登録が実態と一致しません: '.implode(', ', $otherLocations));
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
diff --git a/tests/Architecture/fixtures/llm-seam/receiver-flow-clean.php.txt b/tests/Architecture/fixtures/llm-seam/receiver-flow-clean.php.txt
new file mode 100644
index 00000000..f8929a99
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/receiver-flow-clean.php.txt
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\Support\Manual\LlmJson;
+
+// 正例: 生の応答は復号点へ**直接 1 回だけ**渡る。
+final class ReceiverFlowClean
+{
+    /** @param array<array-key, mixed> $decoded */
+    private function __construct(private array $decoded) {}
+
+    public static function fromLlmText(string $text): self
+    {
+        $decoded = LlmJson::decode($text);
+
+        return new self($decoded);
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/receiver-flow-missing-decode.php.txt b/tests/Architecture/fixtures/llm-seam/receiver-flow-missing-decode.php.txt
new file mode 100644
index 00000000..522c0fe1
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/receiver-flow-missing-decode.php.txt
@@ -0,0 +1,16 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+// 負例: 復号点を通していない (自前で読んでいる)。
+final class ReceiverFlowMissingDecode
+{
+    public static function fromLlmText(string $text): self
+    {
+        unserialize($text);
+
+        return new self();
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/receiver-flow-rebound.php.txt b/tests/Architecture/fixtures/llm-seam/receiver-flow-rebound.php.txt
new file mode 100644
index 00000000..2b0c1c1e
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/receiver-flow-rebound.php.txt
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\Support\Manual\LlmJson;
+
+// 負例: 生の応答を別変数へ移してから渡している (別サービスへ回せる形)。
+final class ReceiverFlowRebound
+{
+    public static function fromLlmText(string $text): self
+    {
+        $copy = $text;
+        $decoded = LlmJson::decode($copy);
+
+        return new self($decoded);
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/receiver-flow-reused.php.txt b/tests/Architecture/fixtures/llm-seam/receiver-flow-reused.php.txt
new file mode 100644
index 00000000..2d8283b3
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/receiver-flow-reused.php.txt
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\Support\Manual\LlmJson;
+
+// 負例: 生の応答を 2 回使っている (復号点の外でも触っている)。
+final class ReceiverFlowReused
+{
+    public static function fromLlmText(string $text): self
+    {
+        $decoded = LlmJson::decode($text);
+        Sink::consume($text);
+
+        return new self($decoded);
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/seam-postprocessed.php.txt b/tests/Architecture/fixtures/llm-seam/seam-postprocessed.php.txt
new file mode 100644
index 00000000..705db370
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-postprocessed.php.txt
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
+use App\Prompts\SopExtractPrompt;
+
+// 負例: 受け手は解決できるが、応答を**加工してから**渡している。
+// 「登録済みの受け取り関数の直接の引数」ではないので囲みは解決しない。
+final class Postprocessed
+{
+    public function suffixed(string $text): ExtractedSopData
+    {
+        return ExtractedSopData::fromLlmText(
+            SopExtractPrompt::make($text)->executeSync().'suffix',
+        );
+    }
+
+    public function coalesced(string $text): ExtractedSopData
+    {
+        return ExtractedSopData::fromLlmText(
+            SopExtractPrompt::make($text)->executeSync() ?: '{}',
+        );
+    }
+
+    public function boxed(string $text): ExtractedSopData
+    {
+        return ExtractedSopData::fromLlmText(
+            [SopExtractPrompt::make($text)->executeSync()][0],
+        );
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/seam-resolved-other.php.txt b/tests/Architecture/fixtures/llm-seam/seam-resolved-other.php.txt
new file mode 100644
index 00000000..602ee792
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-resolved-other.php.txt
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+// 負例: 目録の鍵でない型が受け手。解決はできるので `ResolvedOther` になる
+// (未解決と混ぜない = 理由つきの別目録が要る、という分類)。
+final class ResolvedOther
+{
+    public function run(string $text): string
+    {
+        return Unregistered::make($text)->executeSync();
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/seam-resolved-receiver.php.txt b/tests/Architecture/fixtures/llm-seam/seam-resolved-receiver.php.txt
new file mode 100644
index 00000000..0a2d93c6
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-resolved-receiver.php.txt
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
+use App\Prompts\SopExtractPrompt;
+
+// 正例: 応答が登録済みの受け取り関数の**直接の引数**になっている。
+// make() の引数に入れ子の呼び出し・配列・名前付き引数・クロージャ・改行があっても
+// 受け手の解決 (段 3 の括弧の対応) は成立する。
+final class ResolvedReceiver
+{
+    public function run(string $text): ExtractedSopData
+    {
+        return ExtractedSopData::fromLlmText(
+            SopExtractPrompt::make(
+                untrusted: trim($text),
+                options: ['retries' => 1, 'tags' => ['a', 'b']],
+                decorate: static fn (string $value): string => $value,
+            )->executeSync(),
+        );
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/seam-unbalanced.php.txt b/tests/Architecture/fixtures/llm-seam/seam-unbalanced.php.txt
new file mode 100644
index 00000000..734f39b6
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-unbalanced.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+// 負例: 走査上、対応する開き括弧に到達しないまま列の先頭に達する形。
+// 括弧の対応が取れないので**未解決**として落とす (fail-closed)。
+) -> executeSync ();
diff --git a/tests/Architecture/fixtures/llm-seam/seam-unresolved-static.php.txt b/tests/Architecture/fixtures/llm-seam/seam-unresolved-static.php.txt
new file mode 100644
index 00000000..62648a17
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-unresolved-static.php.txt
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+// 負例: 遅延静的束縛 / 括弧で包んだ形。いずれも受け手を確定できないので**未解決**。
+final class UnresolvedStatic
+{
+    public function late(string $text): string
+    {
+        return static::make($text)->executeSync();
+    }
+
+    public function wrapped(string $text): string
+    {
+        return (Factory::make($text))->executeSync();
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/seam-unresolved-variable.php.txt b/tests/Architecture/fixtures/llm-seam/seam-unresolved-variable.php.txt
new file mode 100644
index 00000000..8d659901
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-unresolved-variable.php.txt
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\Prompts\SopExtractPrompt;
+
+// 負例: 応答を変数へ束縛する形。受け手が静的に決まらないので**未解決**になる。
+final class UnresolvedVariable
+{
+    public function run(string $text): string
+    {
+        $prompt = SopExtractPrompt::make($text);
+
+        return $prompt->executeSync();
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/seam-wrong-enclosing.php.txt b/tests/Architecture/fixtures/llm-seam/seam-wrong-enclosing.php.txt
new file mode 100644
index 00000000..7d2e8e96
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/seam-wrong-enclosing.php.txt
@@ -0,0 +1,16 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use App\Prompts\SopExtractPrompt;
+
+// 負例: 受け手は解決できるが、応答が**登録されていない関数**の引数になっている。
+final class WrongEnclosing
+{
+    public function run(string $text): string
+    {
+        return Sink::consume(SopExtractPrompt::make($text)->executeSync());
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/vocabulary-case-variants.php.txt b/tests/Architecture/fixtures/llm-seam/vocabulary-case-variants.php.txt
new file mode 100644
index 00000000..9582ea62
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/vocabulary-case-variants.php.txt
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use function JSON_DECODE as decodeJson;
+
+// 負例: PHP の関数名は大文字小文字を区別しないので、綴りを変えた形もすべて実行できる。
+// 文字列 callable の先頭 `\` も global を指す。いずれも違反として拾う。
+final class VocabularyCaseVariants
+{
+    public function upper(string $text): mixed
+    {
+        return JSON_DECODE($text, true);
+    }
+
+    public function mixedFullyQualified(string $text): mixed
+    {
+        return \Json_Decode($text, true);
+    }
+
+    public function upperAlias(string $text): mixed
+    {
+        return decodeJson($text, true);
+    }
+
+    public function leadingBackslashCallable(string $text): mixed
+    {
+        return call_user_func('\json_decode', $text, true);
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/vocabulary-clean.php.txt b/tests/Architecture/fixtures/llm-seam/vocabulary-clean.php.txt
new file mode 100644
index 00000000..b5000f85
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/vocabulary-clean.php.txt
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use function Foo\{json_decode as decodeJson};
+
+// 正例: いずれも違反にしない。
+// - 接頭辞つき / 打ち消しつき / 接尾辞つきの 3 形は別トークンなので誤検出しない
+// - メソッド呼び出しは関数呼び出しではない
+// - group use の別名は `Foo\json_decode` へ解決されるので global の json_decode ではない
+final class VocabularyClean
+{
+    public function prefixed(string $text): mixed
+    {
+        return my_json_decode($text);
+    }
+
+    public function suffixed(string $text): mixed
+    {
+        return json_decode_all($text);
+    }
+
+    public function negated(string $text): mixed
+    {
+        return not_json_decode($text);
+    }
+
+    public function method(object $reader, string $text): mixed
+    {
+        return $reader->json_decode($text);
+    }
+
+    public function namespaced(string $text): mixed
+    {
+        return decodeJson($text);
+    }
+}
diff --git a/tests/Architecture/fixtures/llm-seam/vocabulary-violations.php.txt b/tests/Architecture/fixtures/llm-seam/vocabulary-violations.php.txt
new file mode 100644
index 00000000..8de168bd
--- /dev/null
+++ b/tests/Architecture/fixtures/llm-seam/vocabulary-violations.php.txt
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Fixture\LlmSeam;
+
+use function json_decode as decodeJson;
+
+// 負例: 復号語彙を自前で書いた形。回避経路 (完全修飾 / use function の別名 /
+// 文字列リテラル経由) もすべて違反として拾う。
+final class VocabularyViolations
+{
+    public function bare(string $text): mixed
+    {
+        return json_decode($text, true);
+    }
+
+    public function fullyQualified(string $text): mixed
+    {
+        return \json_decode($text, true);
+    }
+
+    public function aliased(string $text): mixed
+    {
+        return decodeJson($text, true);
+    }
+
+    public function indirect(string $text): mixed
+    {
+        return call_user_func('json_decode', $text, true);
+    }
+
+    public function fence(): string
+    {
+        return '```json';
+    }
+}
diff --git a/tests/Feature/Llm/CannedPromptResponsesTest.php b/tests/Feature/Llm/CannedPromptResponsesTest.php
index a0402cae..54bb4932 100644
--- a/tests/Feature/Llm/CannedPromptResponsesTest.php
+++ b/tests/Feature/Llm/CannedPromptResponsesTest.php
@@ -140,6 +140,16 @@ function makeRegisteredPrompt(string $key): GuardedPrompt
     expect($dto->steps[0]->points)->toHaveCount(1);
 });
 
+test('構造化応答の canned は囲みちょうど 1 つで返る (素の JSON へ戻す改変を赤にする)', function (string $key): void {
+    // 受理契約が「囲みちょうど 1 つ」なので、canned も**依頼文が指示する形と同じ形**で返す。
+    $text = makeRegisteredPrompt($key)->executeSync();
+    Assert::string($text);
+
+    expect($text)->toStartWith("```json\n");
+    expect($text)->toEndWith("\n```");
+    expect(substr_count($text, '```'))->toBe(2);
+})->with(['sop-extract', 'sop-extract-media', 'work-decomposition', 'scenario-generation']);
+
 test('example-summary の canned は非空 string を返す', function (): void {
     $text = ExampleSummaryPrompt::make('本文')->executeSync();
     expect($text)->toBeString();
diff --git a/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php b/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php
index 69c07160..b247327b 100644
--- a/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php
+++ b/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php
@@ -16,6 +16,7 @@
 use Illuminate\Support\Facades\Storage;
 use Kent013\PrismPrompt\Prompt;
 use Kent013\PrismPrompt\Testing\TextResponseFake;
+use Tests\Support\Manual\FencedLlmResponse;
 use Tests\Support\Manual\MinimalImageFixture;
 use Tests\Support\Manual\MinimalPdfFixture;
 
@@ -64,7 +65,7 @@ function ocrExtractFixture(): string
 {
     // AnalysisAcceptanceGate の実質空判定 (manual.analysis_min_text_bytes) を
     // 既定値のまま安全に上回るよう、実際の SOP らしい分量の本文にする。
-    return json_encode([
+    return FencedLlmResponse::wrapArray([
         'header' => ['title' => 'OCR サンプル手順書', 'department' => null, 'revision' => null],
         'sections' => [[
             'title' => null,
@@ -77,27 +78,27 @@ function ocrExtractFixture(): string
                 'pm_points' => [],
             ]],
         ]],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 }
 
 function decompositionFixtureOcr(): string
 {
-    return json_encode([
+    return FencedLlmResponse::wrapArray([
         'steps' => [['no' => 1, 'action' => 'バルブを閉じる', 'points' => ['ハンドルが止まるまで回す']]],
         'validation' => [
             'verdict' => 'valid', 'reason' => '妥当です。', 'works' => ['バルブ閉止'], 'split_recommended' => false,
         ],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 }
 
 function scenarioFixtureOcr(): string
 {
-    return json_encode([
+    return FencedLlmResponse::wrapArray([
         'cuts' => [
             ['no' => 1, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'hiki',
                 'shooting_point' => null, 'narration' => 'バルブを閉じます', 'subtitle_primary' => null, 'subtitle_secondary' => 'バルブ閉'],
         ],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 }
 
 function fakeSuccessfulOcrScript(): void
diff --git a/tests/Feature/Notifications/ManualAnalysisNotificationTest.php b/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
index c635c01b..e63e2cca 100644
--- a/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
+++ b/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
@@ -19,6 +19,7 @@
 use Illuminate\Support\Facades\Storage;
 use Kent013\PrismPrompt\Prompt;
 use Kent013\PrismPrompt\Testing\TextResponseFake;
+use Tests\Support\Manual\FencedLlmResponse;
 
 /*
  * 解析ジョブ terminal 遷移の通知配線 (施策3/4):
@@ -67,7 +68,7 @@ function analysisNotificationContext(?User $creator = null, ?User $triggeredBy =
 function fakeAnalysisLlmSuccess(): void
 {
     Prompt::fake([
-        TextResponseFake::make()->withText(json_encode([
+        TextResponseFake::make()->withText(FencedLlmResponse::wrapArray([
             'header' => ['title' => 'SOP', 'department' => null, 'revision' => null],
             'sections' => [[
                 'title' => null,
@@ -76,8 +77,8 @@ function fakeAnalysisLlmSuccess(): void
                     'safety_points' => [], 'quality_points' => [], 'pm_points' => [],
                 ]],
             ]],
-        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
-        TextResponseFake::make()->withText(json_encode([
+        ])),
+        TextResponseFake::make()->withText(FencedLlmResponse::wrapArray([
             'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => []]],
             'validation' => [
                 'verdict' => 'valid',
@@ -85,14 +86,14 @@ function fakeAnalysisLlmSuccess(): void
                 'works' => ['ネジ締め作業'],
                 'split_recommended' => false,
             ],
-        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
-        TextResponseFake::make()->withText(json_encode([
+        ])),
+        TextResponseFake::make()->withText(FencedLlmResponse::wrapArray([
             'cuts' => [[
                 'no' => 1, 'type' => 'step', 'parent_no' => null,
                 'scene' => 'ネジ締め', 'shot_type' => 'hiki', 'shooting_point' => null,
                 'narration' => 'ネジを締めます', 'subtitle_primary' => null, 'subtitle_secondary' => null,
             ]],
-        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
+        ])),
     ]);
 }
 
diff --git a/tests/Feature/Projects/AnalysisPipelineTest.php b/tests/Feature/Projects/AnalysisPipelineTest.php
index 0aeb6d71..956ceae2 100644
--- a/tests/Feature/Projects/AnalysisPipelineTest.php
+++ b/tests/Feature/Projects/AnalysisPipelineTest.php
@@ -26,6 +26,7 @@
 use App\Services\Manual\ScenarioService;
 use Carbon\CarbonImmutable;
 use Illuminate\Http\Client\ConnectionException;
+use Illuminate\Log\Events\MessageLogged;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Http;
 use Illuminate\Support\Facades\Log;
@@ -37,6 +38,8 @@
 use Prism\Prism\Exceptions\PrismRateLimitedException;
 use Prism\Prism\Exceptions\PrismRequestTooLargeException;
 use Prism\Prism\ValueObjects\Messages\UserMessage;
+use Tests\Support\Manual\FencedLlmResponse;
+use Tests\Support\Manual\LlmJsonRejection;
 use Tests\Support\PrismHttpExceptionFactory;
 use Tests\Support\ThrowingPromptFake;
 
@@ -81,7 +84,7 @@ function pipelineContext(int $tickets = 1): array
 
 function extractFixture(): string
 {
-    return json_encode([
+    return FencedLlmResponse::wrapArray([
         'header' => ['title' => 'ネジ締め SOP', 'department' => null, 'revision' => null],
         'sections' => [[
             'title' => null,
@@ -94,7 +97,7 @@ function extractFixture(): string
                 'pm_points' => [],
             ]],
         ]],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 }
 
 /**
@@ -105,7 +108,7 @@ function extractFixture(): string
  */
 function decompositionFixture(array $overrides = []): string
 {
-    return json_encode([...[
+    return FencedLlmResponse::wrapArray([...[
         'steps' => [
             ['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']],
         ],
@@ -115,12 +118,12 @@ function decompositionFixture(array $overrides = []): string
             'works' => ['ネジ締め作業'],
             'split_recommended' => false,
         ],
-    ], ...$overrides], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ], ...$overrides]);
 }
 
 function scenarioFixture(): string
 {
-    return json_encode([
+    return FencedLlmResponse::wrapArray([
         'cuts' => [
             [
                 'no' => 1, 'type' => 'step', 'parent_no' => null,
@@ -133,7 +136,7 @@ function scenarioFixture(): string
                 'narration' => 'トルクは 5Nm です', 'subtitle_primary' => '5Nm', 'subtitle_secondary' => '締め付けトルク',
             ],
         ],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 }
 
 /**
@@ -301,9 +304,9 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
 test('validation キーの欠落そのものも failed になる (failure_path=validation)', function (): void {
     [, , , , , $job] = pipelineContext();
     // 旧プロンプト時代の応答形 ({steps} だけ) が返ってきた状況
-    $withoutValidation = json_encode([
+    $withoutValidation = FencedLlmResponse::wrapArray([
         'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']]],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
     Prompt::fake([
         TextResponseFake::make()->withText(extractFixture()),
         TextResponseFake::make()->withText($withoutValidation),
@@ -415,19 +418,78 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
 });
 
-test('コードフェンス付き JSON も受理する (LlmJson::decode)', function (): void {
+test('囲みが 2 つある応答は fence_multiple で拒否され有界リトライに乗る', function (): void {
+    // 受理側 (囲みちょうど 1 つ) は全 fixture が常時証明するので、ここは拒否側を固定する。
+    // 差し込まれた後続ブロックを採らず、決定論的に拒否したことが区分として数えられる。
     [, , , , , $job] = pipelineContext();
+    $injected = extractFixture()."\n```json\n{\"header\": {}, \"sections\": []}\n```";
     Prompt::fake([
-        TextResponseFake::make()->withText("```json\n".extractFixture()."\n```"),
+        TextResponseFake::make()->withText($injected),
+        TextResponseFake::make()->withText(extractFixture()), // 2 回目は正常 = 有界リトライで復帰
         TextResponseFake::make()->withText(decompositionFixture()),
         TextResponseFake::make()->withText(scenarioFixture()),
     ]);
+    Log::spy();
 
     app(AnalysisPipeline::class)->run($job->id);
 
     expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+    Log::shouldHaveReceived('warning')->withArgs(
+        fn (string $message, array $context): bool => $message === 'AI 解析の LLM 呼び出しを再試行します'
+            && $context['failure_category'] === 'fence_multiple',
+    )->once();
 });
 
+dataset('sentinel を含む 6 区分の LLM 応答', [
+    'fence_absent' => ['プレーンな応答 '.LlmJsonRejection::SENTINEL],
+    'fence_multiple' => [
+        "```json\n{\"header\": \"".LlmJsonRejection::SENTINEL."\"}\n```\n```json\n{\"b\": 2}\n```",
+    ],
+    'syntax_broken' => ["```json\n{\"header\": \"".LlmJsonRejection::SENTINEL."\",}\n```"],
+    'top_level_not_container' => ["```json\n\"".LlmJsonRejection::SENTINEL."\"\n```"],
+    'value_incomplete_inferred' => ["```json\n{\"header\": \"".LlmJsonRejection::SENTINEL],
+    'closing_fence_absent' => ["```json\n{\"header\": \"".LlmJsonRejection::SENTINEL."\"}\n"],
+]);
+
+test('復号の失敗では応答本文が記録にも error 列にも出ない', function (string $response): void {
+    // 単体層 (LlmJsonTest) は例外の message / userMessage だけを見る。ここは統合層として
+    // **再試行ログ・終端ログ・analysis_jobs.error** の 3 か所に本文が出ないことを固定する (正典 i9)。
+    [, , , , , $job] = pipelineContext();
+    Prompt::fake([
+        TextResponseFake::make()->withText($response),
+        TextResponseFake::make()->withText($response),
+        TextResponseFake::make()->withText($response),
+    ]);
+
+    $retryMessage = 'AI 解析の LLM 呼び出しを再試行します';
+    $terminalMessage = 'AI 解析の抽出段 (終端)';
+    /** @var array<string, list<string>> $observed 監視対象ログの context を種別ごとに畳んだもの */
+    $observed = [$retryMessage => [], $terminalMessage => []];
+    Log::listen(function (MessageLogged $logged) use (&$observed): void {
+        if (! array_key_exists($logged->message, $observed)) {
+            return;
+        }
+        // ★`print_r` は object の private / protected も展開するので、context に将来
+        //   Throwable 等が入っても中身まで見える (json_encode は public しか見ない)
+        $observed[$logged->message][] = print_r($logged->context, true);
+    });
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Failed);
+    // 利用者向け文言は定型文と完全一致する (内部 detail も応答本文も入らない)
+    expect($job->error)->toBe('AI の応答を解釈できませんでした。再実行してください。');
+    // 監視対象の 2 種類が**それぞれ**出ていること (片方が消えてももう片方で緑にならない)
+    expect($observed[$retryMessage])->toHaveCount(2); // 上限 2 = 3 試行の間に 2 回
+    expect($observed[$terminalMessage])->toHaveCount(1);
+    foreach ($observed as $lines) {
+        foreach ($lines as $line) {
+            // ★`toContain` は可変長 needle なので、説明文は引数に混ぜない (needle として扱われる)
+            expect($line)->not->toContain(LlmJsonRejection::SENTINEL);
+        }
+    }
+})->with('sentinel を含む 6 区分の LLM 応答');
+
 test('残高不足で startJob が失敗 → failed (予約なし・LLM 呼び出しなし)', function (): void {
     [, , , $manual, , $job] = pipelineContext(tickets: 0);
 
diff --git a/tests/Feature/Projects/ScenarioBookendMaterializeTest.php b/tests/Feature/Projects/ScenarioBookendMaterializeTest.php
index 88e00dd3..2500afc5 100644
--- a/tests/Feature/Projects/ScenarioBookendMaterializeTest.php
+++ b/tests/Feature/Projects/ScenarioBookendMaterializeTest.php
@@ -20,6 +20,7 @@
 use Illuminate\Support\Facades\Storage;
 use Kent013\PrismPrompt\Prompt;
 use Kent013\PrismPrompt\Testing\TextResponseFake;
+use Tests\Support\Manual\FencedLlmResponse;
 
 /*
  * 導入/総括カットの materialize 不変条件 (T046)。
@@ -61,7 +62,7 @@ function bookendPipelineContext(string $title = 'ネジ締め作業'): array
 
 function bookendExtractJson(): string
 {
-    return json_encode([
+    return FencedLlmResponse::wrapArray([
         'header' => ['title' => 'SOP', 'department' => null, 'revision' => null],
         'sections' => [[
             'title' => null,
@@ -74,12 +75,12 @@ function bookendExtractJson(): string
                 'pm_points' => [],
             ]],
         ]],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 }
 
 function bookendDecomposeJson(): string
 {
-    return json_encode([
+    return FencedLlmResponse::wrapArray([
         'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']]],
         'validation' => [
             'verdict' => 'valid',
@@ -87,7 +88,7 @@ function bookendDecomposeJson(): string
             'works' => ['ネジ締め作業'],
             'split_recommended' => false,
         ],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 }
 
 /**
@@ -119,7 +120,7 @@ function bookendScenarioJson(array $steps): string
         }
     }
 
-    return json_encode(['cuts' => $cuts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    return FencedLlmResponse::wrapArray(['cuts' => $cuts]);
 }
 
 /** 3 段 (extract / decompose / generate) の Prompt fake を張る (generate は与えた scenario JSON)。 */
diff --git a/tests/Support/Llm/DecodePointPublicSurface.php b/tests/Support/Llm/DecodePointPublicSurface.php
new file mode 100644
index 00000000..56eb91e8
--- /dev/null
+++ b/tests/Support/Llm/DecodePointPublicSurface.php
@@ -0,0 +1,126 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Llm;
+
+use ReflectionClass;
+use ReflectionMethod;
+use ReflectionNamedType;
+use ReflectionParameter;
+use ReflectionType;
+
+/**
+ * 復号点の**公開面**を正規化して判定する純関数 (正典 i4 の「緩い入口を持たない」の実体)。
+ *
+ * ★**gate と負例が同じ経路を通る**ことが本クラスの存在理由である。gate だけが
+ *   `LlmJson::class` を直接 Reflection し、負例 fixture が別ロジックでメソッド数を数える形にすると、
+ *   **負例が本番 gate の検出力を証明しない** (詳細設計 §施策 7 の検査 7)。
+ *
+ * ## 保証しないもの
+ *
+ * - 見るのは**そのクラス自身が宣言した public メソッド**だけである
+ *   (継承したメソッド・protected / private・プロパティ・定数は見ない)。
+ * - 引数は**型と必須性**だけを比べる (名前・既定値・参照渡し・可変長は見ない)。
+ * - 交差型 / 合併型は `ReflectionType` の文字列表現で比べる (構造では比べない)。
+ */
+final class DecodePointPublicSurface
+{
+    /**
+     * 公開面 (メソッド名 => static か / 戻り値型 / 引数の型と必須性)。
+     *
+     * @param  class-string  $class
+     * @return array<string, array{static: bool, returnType: string, parameters: list<array{type: string, optional: bool}>}>
+     */
+    public static function of(string $class): array
+    {
+        $reflection = new ReflectionClass($class);
+
+        $surface = [];
+        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
+            if ($method->getDeclaringClass()->getName() !== $class) {
+                continue; // 継承したメソッドは公開面の宣言ではない
+            }
+            $surface[$method->getName()] = [
+                'static' => $method->isStatic(),
+                'returnType' => self::typeName($method->getReturnType()),
+                'parameters' => array_values(array_map(
+                    static fn (ReflectionParameter $parameter): array => [
+                        'type' => self::typeName($parameter->getType()),
+                        'optional' => $parameter->isOptional(),
+                    ],
+                    $method->getParameters(),
+                )),
+            ];
+        }
+        ksort($surface);
+
+        return $surface;
+    }
+
+    /**
+     * 復号点の受理契約に合う公開面かを判定する (deny-by-default)。
+     *
+     * - public メソッドは `decode` / `schemaViolation` の 2 つ**だけ** (完全一致)
+     * - `decode` は `public static`・**必須の `string` 引数ちょうど 1 つ**・戻り値型 `array`
+     * - `schemaViolation` は `public static`・戻り値型が指定の例外型
+     *
+     * @param  class-string  $class
+     * @return list<string> 違反の説明 (空なら契約どおり)
+     */
+    public static function violations(string $class, string $expectedSchemaViolationReturn): array
+    {
+        $surface = self::of($class);
+        $violations = [];
+
+        $names = array_keys($surface);
+        if ($names !== ['decode', 'schemaViolation']) {
+            $violations[] = "{$class}: public メソッドが [".implode(', ', $names)
+                .'] (decode / schemaViolation の 2 つと完全一致であること)';
+        }
+
+        $decode = $surface['decode'] ?? null;
+        if ($decode === null) {
+            $violations[] = "{$class}: decode が公開されていません";
+        } else {
+            if (! $decode['static']) {
+                $violations[] = "{$class}::decode が static ではありません";
+            }
+            if ($decode['returnType'] !== 'array') {
+                $violations[] = "{$class}::decode の戻り値型が {$decode['returnType']} (array であること)";
+            }
+            if ($decode['parameters'] !== [['type' => 'string', 'optional' => false]]) {
+                $violations[] = "{$class}::decode の引数が「必須の string ちょうど 1 つ」ではありません";
+            }
+        }
+
+        $schemaViolation = $surface['schemaViolation'] ?? null;
+        if ($schemaViolation === null) {
+            $violations[] = "{$class}: schemaViolation が公開されていません";
+        } else {
+            if (! $schemaViolation['static']) {
+                $violations[] = "{$class}::schemaViolation が static ではありません";
+            }
+            if ($schemaViolation['returnType'] !== $expectedSchemaViolationReturn) {
+                $violations[] = "{$class}::schemaViolation の戻り値型が {$schemaViolation['returnType']}"
+                    ." ({$expectedSchemaViolationReturn} であること)";
+            }
+        }
+
+        return $violations;
+    }
+
+    /** 型の正規化 (null 許容は先頭に `?` を付ける。型無しは `(none)`)。 */
+    private static function typeName(?ReflectionType $type): string
+    {
+        if ($type === null) {
+            return '(none)';
+        }
+        if (! $type instanceof ReflectionNamedType) {
+            return (string) $type;
+        }
+
+        return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '')
+            .$type->getName();
+    }
+}
diff --git a/tests/Support/Llm/Fixtures/LenientDecodePointProbe.php b/tests/Support/Llm/Fixtures/LenientDecodePointProbe.php
new file mode 100644
index 00000000..4f23f01f
--- /dev/null
+++ b/tests/Support/Llm/Fixtures/LenientDecodePointProbe.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Llm\Fixtures;
+
+use App\Exceptions\Manual\LlmOutputInvalidException;
+use App\Support\Manual\LlmJson;
+
+/**
+ * 検査 7 (復号点の公開面の pin) の**負例**。
+ *
+ * 「緩い入口を後から足した」状態を再現する見本で、`DecodePointPublicSurface::violations()`
+ * という**本番 gate と同一の判定経路**へ渡して赤くなることを確かめる
+ * (負例が別ロジックで数える形だと、負例が本番 gate の検出力を証明しない)。
+ *
+ * ★実行経路は持たない (どこからも呼ばれない)。中身は公開面の形だけが意味を持つ。
+ */
+final class LenientDecodePointProbe
+{
+    /**
+     * @return array<array-key, mixed>
+     */
+    public static function decode(string $text): array
+    {
+        return LlmJson::decode($text);
+    }
+
+    public static function schemaViolation(string $detail, ?string $path = null): LlmOutputInvalidException
+    {
+        return LlmJson::schemaViolation($detail, $path);
+    }
+
+    /**
+     * 緩い入口 (これが増えたら赤くなる、が検査 7 の主張)。
+     *
+     * @return array<array-key, mixed>
+     */
+    public static function decodeLenient(string $text): array
+    {
+        return LlmJson::decode($text);
+    }
+}
diff --git a/tests/Support/Llm/LlmResponseHandling.php b/tests/Support/Llm/LlmResponseHandling.php
new file mode 100644
index 00000000..4af1e77d
--- /dev/null
+++ b/tests/Support/Llm/LlmResponseHandling.php
@@ -0,0 +1,23 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Llm;
+
+/**
+ * 依頼文 factory ごとの「応答の扱い」の分類 (テスト側 enum)。
+ *
+ * ★`string` にすると綴り間違いがどの検査にも当たらず**分類漏れ**になるため型で閉じる。
+ *   正本は `tests/Architecture/LlmResponseDecodePointGateTest.php` の目録。
+ */
+enum LlmResponseHandling
+{
+    /** 応答を復号点 (`App\Support\Manual\LlmJson::decode`) 経由で構造化データとして読む */
+    case Decoded;
+
+    /** 提供元が形を保証する経路 (構造化出力)。**現在 0 件** (枠だけ持つ) */
+    case ProviderShape;
+
+    /** 応答を構造化データとして読まない (自由文) */
+    case FreeText;
+}
diff --git a/tests/Support/Llm/LlmResponseSeamFinding.php b/tests/Support/Llm/LlmResponseSeamFinding.php
new file mode 100644
index 00000000..06742ffa
--- /dev/null
+++ b/tests/Support/Llm/LlmResponseSeamFinding.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Llm;
+
+/**
+ * `executeSync()` の呼び出し点 1 件の走査結果 (解決状態つき)。
+ *
+ * ★`factory` / `enclosingCall` は**解決できたときだけ**値を持つ。解決できない形は
+ *   `resolution === Unresolved` で表し、利用側 gate が失敗させる (共通規約 (b))。
+ */
+final readonly class LlmResponseSeamFinding
+{
+    public function __construct(
+        public string $path,
+        public int $line,
+        public LlmResponseSeamResolution $resolution,
+        /** 直前の `X::make(...)` の `X` の完全修飾名 (解決できたときだけ)。 */
+        public ?string $factory,
+        /**
+         * この呼び出しを直接の引数として囲む静的呼び出し `{FQCN}::{method}`
+         * (囲みが `名前トークン :: メソッド名 (` の形でないときは null)。
+         */
+        public ?string $enclosingCall,
+    ) {}
+
+    /** 失敗メッセージ用の位置表現。 */
+    public function location(): string
+    {
+        return $this->path.':'.$this->line;
+    }
+}
diff --git a/tests/Support/Llm/LlmResponseSeamResolution.php b/tests/Support/Llm/LlmResponseSeamResolution.php
new file mode 100644
index 00000000..9a59569f
--- /dev/null
+++ b/tests/Support/Llm/LlmResponseSeamResolution.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Llm;
+
+/**
+ * `GuardedPrompt::executeSync()` の呼び出し点で、応答の**受け手**を解決できたか。
+ *
+ * ★**未解決を「目録外」と同じ値へ潰さない**。潰すと変数へ束縛する書き方
+ *   (`$prompt = X::make(...); $prompt->executeSync();`) が無言で候補から外れる
+ *   (`AGENTS.md` の共通規約 (b) が禁じる形)。
+ */
+enum LlmResponseSeamResolution
+{
+    /** 直前が `X::make(...)` で、`X` が目録の鍵に解決できた。 */
+    case ResolvedPromptFactory;
+
+    /** 直前が `X::make(...)` だが、`X` が目録の鍵ではない。 */
+    case ResolvedOther;
+
+    /** それ以外の書き方 (変数への束縛 / container 解決 / 式)。**gate は失敗させる**。 */
+    case Unresolved;
+}
diff --git a/tests/Support/Llm/LlmResponseSeamScanner.php b/tests/Support/Llm/LlmResponseSeamScanner.php
new file mode 100644
index 00000000..cb4967e0
--- /dev/null
+++ b/tests/Support/Llm/LlmResponseSeamScanner.php
@@ -0,0 +1,670 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Llm;
+
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReferenceKind;
+use Tests\Support\ReferenceSite;
+
+/**
+ * 「LLM 応答が app/ に入る点」を列挙する走査器 (純関数)。
+ *
+ * `tests/Architecture/LlmResponseDecodePointGateTest.php` の 8 検査のうち、
+ * ソースを読む必要のある 5 つ (受け取り口の分類 / 応答の流れ / `GuardedPrompt` の参照者 /
+ * 復号語彙の不在 / 受け取り関数の中の流れ) を提供する。
+ *
+ * ## 走査対象
+ *
+ * - `executeSyncSites()`: `$x->executeSync(` の**メソッド呼び出し**すべて
+ *   (母集団はメソッド名で採る = 拾いすぎる方向にだけ倒れる)。
+ * - `decodeVocabularyViolations()`: 関数呼び出しとして解決される `json_decode` と、
+ *   逆引用符 3 連を含む**文字列リテラル**。
+ * - `referencesGuardedPrompt()`: `App\Support\Llm\GuardedPrompt` の参照 (import を含む)。
+ * - `receiverFlowViolations()`: 登録済みの受け取り関数の中で、生の応答文字列が
+ *   復号点へ**直接 1 回だけ**渡ることの検査。
+ *
+ * ## 名前解決 (共通規約 (a))
+ *
+ * クラス名の解決は `PhpReferenceScanner` に委譲する (`use` / group use / 別名 /
+ * 部分修飾を解いた完全修飾名)。**同じ解決を 2 本持たない**。
+ * 関数名は本クラスが `use function` の別名表を作って解決し、
+ * **解決後の完全修飾名が global の `json_decode`** のものだけを違反にする
+ * (`use function Foo\{json_decode as decodeJson};` は `Foo\json_decode` なので違反ではない)。
+ * PHP の関数名は**大文字小文字を区別しない**ので、比較は小文字化してから行う。
+ *
+ * ## 判定は区切りで割ったトークンの完全一致で行う (共通規約 (e))
+ *
+ * 区切りは PHP の字句 (トークン) そのものである。したがって `my_json_decode(` /
+ * `json_decode_all(` / `$o->json_decode(` はいずれも別トークン・別文脈として扱われ、
+ * 違反にならない (負例で裏取りする)。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - **動的な関数呼び出し** (`$fn($text)` / 変数に入れた callable / `Closure::fromCallable($var)`) は
+ *   見えない。名前が静的に決まらないためである
+ *   (文字列リテラルの完全一致 `'json_decode'` だけは拾う)。
+ * - 反射・動的に組み立てたクラス名・文字列キーだけの container 解決の経路は見えない。
+ * - `vendor/` 配下と `tests/` 配下は走査しない (利用側 gate が走査根を宣言する)。
+ * - 逆引用符 3 連の検出は**文字列リテラルの中身だけ**を見る
+ *   (コメント / docblock は `PhpTokenScan::normalize()` が落としている)。
+ * - `executeSync` の母集団は**メソッド名**で採る。同名の別メソッドがあれば母集団に入るが、
+ *   受け手の解決は下の規則だけで行うので「解決できたことにする」方向へは倒れない。
+ */
+final class LlmResponseSeamScanner
+{
+    /** 囲みの印 (逆引用符 3 連)。**本ファイルは走査根の外**なのでここに書いてよい。 */
+    private const string FENCE_MARK = '```';
+
+    /**
+     * `executeSync()` の呼び出し点を解決状態つきで列挙する。
+     *
+     * 受け手の解決は次の 5 段で、**どの段でも条件を満たさなければ `Unresolved`** である。
+     * 解決の規則は `resolveSeam()` の docblock を参照 (受け手と囲みの呼び出しを同時に決める)。
+     *
+     * @param  list<string>  $promptFactories  目録の鍵 (依頼文 factory の完全修飾名)
+     * @return list<LlmResponseSeamFinding>
+     */
+    public static function executeSyncSites(string $relativePath, string $phpSource, array $promptFactories): array
+    {
+        $tokens = PhpReferenceScanner::tokens($phpSource);
+        $scan = PhpReferenceScanner::references($relativePath, $phpSource);
+
+        /** @var array<int, ReferenceSite> $staticCalls */
+        $staticCalls = [];
+        foreach ($scan->sites as $site) {
+            if ($site->kind === ReferenceKind::StaticCall) {
+                $staticCalls[$site->tokenIndex] = $site;
+            }
+        }
+
+        $findings = [];
+        foreach ($scan->sites as $site) {
+            if ($site->kind !== ReferenceKind::MethodCall || $site->name !== 'executeSync') {
+                continue;
+            }
+
+            [$factory, $enclosing] = self::resolveSeam($tokens, $staticCalls, $site->tokenIndex);
+            $resolution = match (true) {
+                $factory === null => LlmResponseSeamResolution::Unresolved,
+                in_array($factory, $promptFactories, true) => LlmResponseSeamResolution::ResolvedPromptFactory,
+                default => LlmResponseSeamResolution::ResolvedOther,
+            };
+
+            $findings[] = new LlmResponseSeamFinding(
+                path: $relativePath,
+                line: $site->line,
+                resolution: $resolution,
+                factory: $factory,
+                enclosingCall: $enclosing,
+            );
+        }
+
+        return $findings;
+    }
+
+    /** `App\Support\Llm\GuardedPrompt` を参照している (import だけの場合も含む)。 */
+    public static function referencesGuardedPrompt(string $relativePath, string $phpSource, string $fqcn): bool
+    {
+        $scan = PhpReferenceScanner::references($relativePath, $phpSource);
+
+        if (in_array($fqcn, array_values($scan->imports), true)) {
+            return true;
+        }
+        foreach ($scan->sites as $site) {
+            if ($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction) {
+                if ($site->name === $fqcn) {
+                    return true;
+                }
+            }
+            if ($site->receiver->is($fqcn)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 復号語彙 (関数としての `json_decode` / 逆引用符 3 連の文字列リテラル) の出現。
+     *
+     * @return list<string> 違反の説明 (空なら違反なし)
+     */
+    public static function decodeVocabularyViolations(string $relativePath, string $phpSource): array
+    {
+        $tokens = PhpReferenceScanner::tokens($phpSource);
+        $scan = PhpReferenceScanner::references($relativePath, $phpSource);
+        $functionImports = self::functionImports($tokens);
+        $namespace = self::namespaceOf($tokens);
+
+        $violations = [];
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+            $id = $token['id'];
+
+            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
+                $content = $id === T_CONSTANT_ENCAPSED_STRING
+                    ? substr($token['text'], 1, -1)
+                    : $token['text'];
+                if (str_contains($content, self::FENCE_MARK)) {
+                    $violations[] = "{$relativePath}:{$token['line']} 囲みの印を含む文字列リテラル";
+                }
+                if ($id === T_CONSTANT_ENCAPSED_STRING && self::isJsonDecode($content)) {
+                    $violations[] = "{$relativePath}:{$token['line']} 文字列リテラルの json_decode";
+                }
+
+                continue;
+            }
+
+            if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED && $id !== T_NAME_QUALIFIED) {
+                continue;
+            }
+            $next = $tokens[$i + 1] ?? null;
+            if ($next === null || $next['id'] !== null || $next['text'] !== '(') {
+                continue;
+            }
+            $previousId = $tokens[$i - 1]['id'] ?? null;
+            if (in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
+                continue; // メソッド呼び出し / メソッド宣言 / 構築であって関数呼び出しではない
+            }
+            if (self::isJsonDecode(self::resolveFunctionName($token, $functionImports, $scan->imports, $namespace))) {
+                $violations[] = "{$relativePath}:{$token['line']} 関数呼び出しの json_decode";
+            }
+        }
+
+        return $violations;
+    }
+
+    /**
+     * 登録済みの受け取り関数の中で、生の応答文字列が復号点へ**直接 1 回だけ**渡ること。
+     *
+     * @return list<string> 違反の説明 (空なら違反なし)
+     */
+    public static function receiverFlowViolations(
+        string $relativePath,
+        string $phpSource,
+        string $class,
+        string $method,
+        string $decodeClass,
+        string $decodeMethod,
+    ): array {
+        $tokens = PhpReferenceScanner::tokens($phpSource);
+        $scan = PhpReferenceScanner::references($relativePath, $phpSource);
+        $label = "{$class}::{$method}";
+
+        $decodeIndexes = [];
+        foreach ($scan->sites as $site) {
+            if ($site->kind === ReferenceKind::StaticCall
+                && $site->class === $class
+                && $site->callable === $method
+                && $site->name === $decodeMethod
+                && $site->receiver->is($decodeClass)) {
+                $decodeIndexes[] = $site->tokenIndex;
+            }
+        }
+        if (count($decodeIndexes) !== 1) {
+            return ["{$label}: {$decodeClass}::{$decodeMethod} の静的呼び出しが ".count($decodeIndexes).' 件 (1 件であること)'];
+        }
+
+        $declaration = self::methodDeclarationIndex($tokens, $method);
+        if ($declaration === null) {
+            return ["{$label}: メソッド宣言を一意に特定できません (未解決)"];
+        }
+
+        $parametersOpen = $declaration + 2;
+        if (($tokens[$parametersOpen]['text'] ?? null) !== '(') {
+            return ["{$label}: 引数リストの開き括弧を特定できません (未解決)"];
+        }
+        $parametersClose = self::matchForward($tokens, $parametersOpen);
+        if ($parametersClose === null) {
+            return ["{$label}: 引数リストの対応が取れません (未解決)"];
+        }
+
+        $parameterName = null;
+        for ($i = $parametersOpen + 1; $i < $parametersClose; $i++) {
+            if ($tokens[$i]['id'] === T_VARIABLE) {
+                $parameterName = $tokens[$i]['text'];
+                break;
+            }
+        }
+        if ($parameterName === null) {
+            return ["{$label}: 第 1 引数の変数を特定できません (未解決)"];
+        }
+
+        $body = self::bodyRange($tokens, $parametersClose);
+        if ($body === null) {
+            return ["{$label}: メソッド本体を特定できません (未解決)"];
+        }
+
+        $occurrences = [];
+        for ($i = $body[0]; $i <= $body[1]; $i++) {
+            if ($tokens[$i]['id'] === T_VARIABLE && $tokens[$i]['text'] === $parameterName) {
+                $occurrences[] = $i;
+            }
+        }
+        if (count($occurrences) !== 1) {
+            return ["{$label}: 生の応答 {$parameterName} の出現が ".count($occurrences).' 件 (1 件であること)'];
+        }
+
+        $occurrence = $occurrences[0];
+        $expected = $decodeIndexes[0] + 2; // `LlmJson` `::` `decode` `(` `$text`
+        $following = $tokens[$occurrence + 1]['text'] ?? null;
+        if ($occurrence !== $expected || ($following !== ')' && $following !== ',')) {
+            return ["{$label}: 生の応答 {$parameterName} が {$decodeMethod}() の直接の引数になっていません"];
+        }
+
+        return [];
+    }
+
+    /**
+     * 呼び出し点 `i` の**受け手**と**囲みの呼び出し**を同時に解決する。
+     *
+     * 受け手 (`X::make(...)`) の解決は次の 4 段で、どの段でも条件を満たさなければ `null` である。
+     *  1. 呼び出しの手前が `)`
+     *  2. そこから後方へ括弧の対応を数えて `make(` の開き括弧を決める
+     *     (対応が取れないまま列の先頭に達したら `null`)
+     *  3. 開き括弧の直前が `名前トークン :: make` の形である
+     *  4. `名前トークン` が完全修飾名まで解決できる
+     *
+     * 囲みの呼び出しは「**応答が丸ごと 1 つの引数になっている**」ときだけ返す。
+     * 加工して渡す形 (`->executeSync().'x'` / 三項 / null 合体 / キャスト / 配列に入れる)
+     * では引数の開始または終端が一致しないので `null` になり、利用側 gate が赤くなる。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<int, ReferenceSite>  $staticCalls
+     * @return array{0: string|null, 1: string|null} [受け手の完全修飾名, 囲みの `{FQCN}::{method}`]
+     */
+    private static function resolveSeam(array $tokens, array $staticCalls, int $index): array
+    {
+        $previous = $tokens[$index - 2] ?? null;
+        if ($previous === null || $previous['id'] !== null || $previous['text'] !== ')') {
+            return [null, null];
+        }
+        $makeOpen = self::matchBackward($tokens, $index - 2);
+        if ($makeOpen === null || $makeOpen < 3) {
+            return [null, null];
+        }
+        $makeSite = $staticCalls[$makeOpen - 1] ?? null;
+        if ($makeSite === null || $makeSite->name !== 'make' || ! $makeSite->receiver->isResolved()) {
+            return [null, null];
+        }
+        $factory = $makeSite->receiver->fqcn();
+        $receiverNameIndex = $makeOpen - 3;
+
+        // `executeSync(` … `)` の範囲。閉じ括弧の直後が引数の区切りでなければ「加工して渡した」形である
+        $callOpen = $tokens[$index + 1] ?? null;
+        if ($callOpen === null || $callOpen['id'] !== null || $callOpen['text'] !== '(') {
+            return [$factory, null];
+        }
+        $callClose = self::matchForward($tokens, $index + 1);
+        if ($callClose === null) {
+            return [$factory, null];
+        }
+        $following = $tokens[$callClose + 1] ?? null;
+        if ($following === null || $following['id'] !== null || ($following['text'] !== ',' && $following['text'] !== ')')) {
+            return [$factory, null];
+        }
+
+        $enclosingOpen = self::innermostUnclosedParen($tokens, $index - 1);
+        if ($enclosingOpen === null) {
+            return [$factory, null];
+        }
+        $argumentStart = self::argumentStart($tokens, $enclosingOpen, $index);
+        $label = $tokens[$argumentStart] ?? null;
+        $colon = $tokens[$argumentStart + 1] ?? null;
+        $named = $label !== null && $label['id'] === T_STRING
+            && $colon !== null && $colon['id'] === null && $colon['text'] === ':';
+        $expected = $named ? $argumentStart + 2 : $argumentStart;
+        if ($expected !== $receiverNameIndex) {
+            return [$factory, null];
+        }
+
+        $site = $staticCalls[$enclosingOpen - 1] ?? null;
+        if ($site === null || ! $site->receiver->isResolved()) {
+            return [$factory, null];
+        }
+
+        return [$factory, $site->receiver->fqcn().'::'.$site->name];
+    }
+
+    /**
+     * `index` を囲む**最内の未閉じ `(`** の位置 (無ければ null)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function innermostUnclosedParen(array $tokens, int $index): ?int
+    {
+        $depth = 0;
+        for ($k = $index; $k >= 0; $k--) {
+            if ($tokens[$k]['id'] !== null) {
+                continue;
+            }
+            if ($tokens[$k]['text'] === ')') {
+                $depth++;
+
+                continue;
+            }
+            if ($tokens[$k]['text'] !== '(') {
+                continue;
+            }
+            if ($depth === 0) {
+                return $k;
+            }
+            $depth--;
+        }
+
+        return null;
+    }
+
+    /**
+     * `open` で始まる引数リストのうち、`before` を含む引数の**開始添字**。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function argumentStart(array $tokens, int $open, int $before): int
+    {
+        $start = $open + 1;
+        $depth = 0;
+        for ($k = $open + 1; $k < $before; $k++) {
+            if ($tokens[$k]['id'] !== null) {
+                continue;
+            }
+            $text = $tokens[$k]['text'];
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+
+                continue;
+            }
+            if ($depth === 0 && $text === ',') {
+                $start = $k + 1;
+            }
+        }
+
+        return $start;
+    }
+
+    /**
+     * `)` の位置から対応する `(` の位置を後方に探す。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchBackward(array $tokens, int $closeIndex): ?int
+    {
+        $depth = 0;
+        for ($k = $closeIndex; $k >= 0; $k--) {
+            if ($tokens[$k]['id'] !== null) {
+                continue;
+            }
+            if ($tokens[$k]['text'] === ')') {
+                $depth++;
+
+                continue;
+            }
+            if ($tokens[$k]['text'] === '(') {
+                $depth--;
+                if ($depth === 0) {
+                    return $k;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * `(` の位置から対応する `)` の位置を前方に探す。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchForward(array $tokens, int $openIndex): ?int
+    {
+        $depth = 0;
+        $count = count($tokens);
+        for ($k = $openIndex; $k < $count; $k++) {
+            if ($tokens[$k]['id'] !== null) {
+                continue;
+            }
+            if ($tokens[$k]['text'] === '(') {
+                $depth++;
+
+                continue;
+            }
+            if ($tokens[$k]['text'] === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    return $k;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 指定名のメソッド宣言 `function {name}` の `function` トークン位置 (一意でなければ null)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function methodDeclarationIndex(array $tokens, string $method): ?int
+    {
+        $found = [];
+        $count = count($tokens);
+        for ($k = 0; $k < $count; $k++) {
+            if ($tokens[$k]['id'] !== T_FUNCTION) {
+                continue;
+            }
+            $next = $tokens[$k + 1] ?? null;
+            if ($next !== null && $next['id'] === T_STRING && $next['text'] === $method) {
+                $found[] = $k;
+            }
+        }
+
+        return count($found) === 1 ? $found[0] : null;
+    }
+
+    /**
+     * 引数リストの `)` の後にある本体 `{` … `}` の内側の範囲 (開始添字, 終了添字)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{int, int}|null
+     */
+    private static function bodyRange(array $tokens, int $parametersClose): ?array
+    {
+        $count = count($tokens);
+        $open = null;
+        for ($k = $parametersClose + 1; $k < $count; $k++) {
+            if ($tokens[$k]['id'] === null && $tokens[$k]['text'] === '{') {
+                $open = $k;
+                break;
+            }
+            if ($tokens[$k]['id'] === null && $tokens[$k]['text'] === ';') {
+                return null; // 本体を持たない宣言
+            }
+        }
+        if ($open === null) {
+            return null;
+        }
+
+        $depth = 0;
+        for ($k = $open; $k < $count; $k++) {
+            $id = $tokens[$k]['id'];
+            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
+                $depth++;
+
+                continue;
+            }
+            if ($id !== null) {
+                continue;
+            }
+            if ($tokens[$k]['text'] === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($tokens[$k]['text'] === '}') {
+                $depth--;
+                if ($depth === 0) {
+                    return [$open + 1, $k - 1];
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 解決後の名前が global の `json_decode` か。
+     *
+     * ★PHP の**関数名は大文字小文字を区別しない**ので、比較の前に小文字化する
+     *   (`JSON_DECODE(` / `\Json_Decode(` / 大文字の `use function` はいずれも実行できる)。
+     *   先頭の `\` も落とす (文字列 callable の `'\json_decode'` は global を指す)。
+     */
+    private static function isJsonDecode(string $resolvedName): bool
+    {
+        return mb_strtolower(ltrim(trim($resolvedName), '\\')) === 'json_decode';
+    }
+
+    /**
+     * 名前トークンを関数の完全修飾名へ解決する。
+     *
+     * - `T_NAME_FULLY_QUALIFIED` (`\json_decode`): 先頭の `\` を落とす
+     * - `T_STRING`: `use function` の別名表を引き、無ければ**global へ落ちる** (PHP の規則)
+     * - `T_NAME_QUALIFIED` (`Foo\json_decode`): 先頭要素をクラス / 名前空間の import 表で置き換え、
+     *   無ければ現在の名前空間の下に置く
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     * @param  array<string, string>  $functionImports
+     * @param  array<string, string>  $classImports
+     */
+    private static function resolveFunctionName(array $token, array $functionImports, array $classImports, string $namespace): string
+    {
+        $text = $token['text'];
+
+        if ($token['id'] === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($text, '\\');
+        }
+
+        if ($token['id'] === T_STRING) {
+            return $functionImports[mb_strtolower($text)] ?? $text;
+        }
+
+        $separator = strpos($text, '\\');
+        $head = $separator === false ? $text : substr($text, 0, $separator);
+        $resolvedHead = $classImports[mb_strtolower($head)] ?? null;
+        if ($resolvedHead !== null) {
+            return $separator === false ? $resolvedHead : $resolvedHead.substr($text, $separator);
+        }
+
+        return $namespace === '' ? $text : $namespace.'\\'.$text;
+    }
+
+    /**
+     * `use function` の別名表 (小文字の短縮名 => 完全修飾の関数名)。
+     *
+     * group use (`use function Foo\{json_decode as decodeJson};`) にも対応する。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array<string, string>
+     */
+    private static function functionImports(array $tokens): array
+    {
+        $imports = [];
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_USE || ($tokens[$i + 1]['id'] ?? null) !== T_FUNCTION) {
+                continue;
+            }
+
+            $prefix = '';
+            $current = '';
+            $alias = null;
+            $expectAlias = false;
+
+            for ($k = $i + 2; $k < $count; $k++) {
+                $id = $tokens[$k]['id'];
+                $text = $tokens[$k]['text'];
+
+                if ($id === T_AS) {
+                    $expectAlias = true;
+
+                    continue;
+                }
+                if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NS_SEPARATOR) {
+                    if ($expectAlias) {
+                        $alias = $text;
+
+                        continue;
+                    }
+                    $current .= $text;
+
+                    continue;
+                }
+                if ($id !== null) {
+                    continue;
+                }
+
+                if ($text === '{') {
+                    $prefix = $current;
+                    $current = '';
+                    $alias = null;
+                    $expectAlias = false;
+
+                    continue;
+                }
+                if ($text === ',' || $text === '}' || $text === ';') {
+                    if ($current !== '') {
+                        $fqn = ltrim($prefix.$current, '\\');
+                        $short = $alias ?? self::shortName($fqn);
+                        $imports[mb_strtolower($short)] = $fqn;
+                    }
+                    $current = '';
+                    $alias = null;
+                    $expectAlias = false;
+
+                    if ($text === ';') {
+                        $i = $k;
+                        break;
+                    }
+                }
+            }
+        }
+
+        return $imports;
+    }
+
+    /**
+     * ファイル先頭の `namespace` 宣言 (無ければ空文字列)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function namespaceOf(array $tokens): string
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_NAMESPACE) {
+                continue;
+            }
+            $next = $tokens[$i + 1] ?? null;
+            if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
+                return $next['text'];
+            }
+        }
+
+        return '';
+    }
+
+    private static function shortName(string $fqn): string
+    {
+        $position = strrpos($fqn, '\\');
+
+        return $position === false ? $fqn : substr($fqn, $position + 1);
+    }
+}
diff --git a/tests/Support/Manual/FencedLlmResponse.php b/tests/Support/Manual/FencedLlmResponse.php
new file mode 100644
index 00000000..3f56caf6
--- /dev/null
+++ b/tests/Support/Manual/FencedLlmResponse.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Manual;
+
+/**
+ * LLM 応答の fake / fixture を**受理契約どおりの囲みつき**に包むヘルパ。
+ *
+ * ★`LlmJson::decode()` の受理契約は「囲みちょうど 1 つ」なので、素の JSON を渡す fake は
+ *   `fence_absent` で落ちる。fixture 側の包み方を 1 か所に集めて、
+ *   契約が変わったときに直す場所を 1 つにする。
+ */
+final class FencedLlmResponse
+{
+    /** 与えた JSON 文字列を ```json … ``` で包む。 */
+    public static function wrap(string $json): string
+    {
+        return "```json\n".$json."\n```";
+    }
+
+    /**
+     * 配列を JSON へ直してから包む (fixture の定型)。
+     *
+     * @param  array<array-key, mixed>  $payload
+     */
+    public static function wrapArray(array $payload): string
+    {
+        return self::wrap(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
+    }
+}
diff --git a/tests/Support/Manual/LlmJsonRejection.php b/tests/Support/Manual/LlmJsonRejection.php
new file mode 100644
index 00000000..28eacdfe
--- /dev/null
+++ b/tests/Support/Manual/LlmJsonRejection.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Manual;
+
+use App\Exceptions\Manual\LlmOutputInvalidException;
+use App\Support\Manual\LlmJson;
+use RuntimeException;
+
+/**
+ * 復号点の拒否ケースを組み立てるテスト共有ヘルパ。
+ *
+ * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
+ *   `Tests\Support\QueueLeaseConfig` 等と同じくクラスの static メソッドへ集約する
+ *   (AGENTS.md §走査器・gate の置き方に倣う)。
+ */
+final class LlmJsonRejection
+{
+    /** 応答本文が例外へ漏れていないことを見るための目印 (正典 i9)。 */
+    public const string SENTINEL = 'SENTINEL-SOP-BODY-9f2c';
+
+    /**
+     * `LlmJson::decode()` が拒否したときの例外を返す。受理してしまったら失敗させる。
+     */
+    public static function capture(string $text): LlmOutputInvalidException
+    {
+        try {
+            LlmJson::decode($text);
+        } catch (LlmOutputInvalidException $exception) {
+            return $exception;
+        }
+
+        throw new RuntimeException('LlmOutputInvalidException が投げられていない (受理された)');
+    }
+}
diff --git a/tests/Support/Prompts/PromptFactoryPopulation.php b/tests/Support/Prompts/PromptFactoryPopulation.php
new file mode 100644
index 00000000..bd4054ed
--- /dev/null
+++ b/tests/Support/Prompts/PromptFactoryPopulation.php
@@ -0,0 +1,90 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Prompts;
+
+use FilesystemIterator;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use RuntimeException;
+use SplFileInfo;
+
+/**
+ * 依頼文 factory (`app/Prompts/`) の母集団を**再帰**で全数列挙する。
+ *
+ * ★`tests/Architecture/PromptUntrustedInputContractTest.php` も同種の列挙を持つが、
+ *   同ファイルは**採用時債務一覧に採用時 sha 付きで凍結**されているため触らない
+ *   (触ると「戻す / テンプレートへ同期 / 逸脱登録」のいずれかが必須になり、
+ *    20 行の重複を消す利得に見合わない = 思考原則 2)。よって本クラスは
+ *   `LlmResponseDecodePointGateTest` 専用の列挙として独立して持つ。
+ *
+ * ★**走査根の不在は fail-fast** で落とす (根の移動 / typo で黙って PASS しない)。
+ *   母集団の非空は**利用側 gate** が検査する (共通規約 (b) の「母集団 0 件と違反 0 件を区別する」)。
+ *
+ * ## 保証しないもの
+ *
+ * - 1 ファイル 1 クラス・PSR-4 (`App\Prompts\` => `app/Prompts/`) を前提にパスから
+ *   クラス名を作る。1 ファイルに複数クラスを書いた場合・名前空間がパスと一致しない場合は
+ *   その差を見ない (本リポジトリの `app/Prompts/` は全件この前提を満たす)。
+ * - 抽象クラス / trait / interface を区別しない (実在するクラスかどうかだけを見る)。
+ */
+final class PromptFactoryPopulation
+{
+    /** 走査根 (リポジトリルートからの相対パス)。 */
+    private const string ROOT = 'app/Prompts';
+
+    /** 走査根に対応する名前空間。 */
+    private const string NAMESPACE_PREFIX = 'App\\Prompts\\';
+
+    /** 走査根の絶対パス。**存在しなければ例外**。 */
+    public static function root(): string
+    {
+        return self::resolve(self::ROOT);
+    }
+
+    /**
+     * リポジトリルートからの相対パスを絶対パスへ解決する。**存在しなければ例外** (fail-fast)。
+     *
+     * ★自己検査が「根の不在で実際に落ちること」を確かめられるよう public にしてある
+     *   (根を消して確かめる手段が他に無い)。
+     */
+    public static function resolve(string $relativeRoot): string
+    {
+        $absolute = realpath(dirname(__DIR__, 3).'/'.$relativeRoot);
+        if (! is_string($absolute)) {
+            throw new RuntimeException('走査根を解決できません: '.$relativeRoot);
+        }
+
+        return $absolute;
+    }
+
+    /**
+     * 依頼文 factory の完全修飾名 (昇順)。
+     *
+     * @return list<class-string>
+     */
+    public static function classes(): array
+    {
+        $root = self::root();
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+        );
+
+        $classes = [];
+        foreach ($iterator as $file) {
+            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
+            $class = self::NAMESPACE_PREFIX.str_replace('/', '\\', substr($relative, 0, -4));
+            if (! class_exists($class)) {
+                throw new RuntimeException("依頼文 factory のクラスを解決できません: {$class}");
+            }
+            $classes[] = $class;
+        }
+        sort($classes);
+
+        return $classes;
+    }
+}
diff --git a/tests/Unit/Architecture/LlmResponseSeamScannerTest.php b/tests/Unit/Architecture/LlmResponseSeamScannerTest.php
new file mode 100644
index 00000000..5b7c5c1f
--- /dev/null
+++ b/tests/Unit/Architecture/LlmResponseSeamScannerTest.php
@@ -0,0 +1,202 @@
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
diff --git a/tests/Unit/Manual/AnalysisDtoTest.php b/tests/Unit/Manual/AnalysisDtoTest.php
index fa5c291e..d828b3ea 100644
--- a/tests/Unit/Manual/AnalysisDtoTest.php
+++ b/tests/Unit/Manual/AnalysisDtoTest.php
@@ -10,29 +10,32 @@
 use App\Exceptions\Manual\LlmOutputInvalidException;
 use App\Support\Manual\LlmJson;
 use App\Support\Manual\ScenarioLimits;
+use Tests\Support\Manual\FencedLlmResponse;
+use Tests\Support\Manual\LlmJsonRejection;
 
 /*
  * LLM 出力 DTO の fromLlmText 検証 (施策 8):
- * - コードフェンス除去 / 不正 JSON / スキーマ違反 / 有界性 / parent_no 整合
+ * - 受理契約 (囲みちょうど 1 つ) / 区分つきの拒否 / スキーマ違反 / 有界性 / parent_no 整合
  * - 違反は LlmOutputInvalidException (有界リトライのトリガー)
+ *
+ * ★受理契約そのものの網羅は tests/Unit/Manual/LlmJsonTest.php が持つ。ここは
+ *   「DTO の入口が復号点の契約に乗っている」ことだけを固定する。
  */
 
-test('LlmJson::decode はコードフェンスを除去して JSON を返す', function (): void {
+test('LlmJson::decode は囲みちょうど 1 つの応答を受理し、素の JSON は fence_absent で拒否する', function (): void {
     expect(LlmJson::decode("```json\n{\"a\": 1}\n```"))->toBe(['a' => 1]);
-    expect(LlmJson::decode('{"a": 1}'))->toBe(['a' => 1]);
+
+    expect(LlmJsonRejection::capture('{"a": 1}')->reason)
+        ->toBe(LlmOutputInvalidReason::FenceAbsent);
 });
 
-test('LlmJson::decode は不正 JSON を InvalidJson で拒否する', function (): void {
-    try {
-        LlmJson::decode('これは JSON ではない');
-        $this->fail('LlmOutputInvalidException が投げられていない');
-    } catch (LlmOutputInvalidException $exception) {
-        expect($exception->reason)->toBe(LlmOutputInvalidReason::InvalidJson);
-    }
+test('LlmJson::decode は囲みの無い文章を fence_absent で拒否する', function (): void {
+    expect(LlmJsonRejection::capture('これは JSON ではない')->reason)
+        ->toBe(LlmOutputInvalidReason::FenceAbsent);
 });
 
 test('ExtractedSopData: 正常系 + 手順 0 件は SchemaViolation', function (): void {
-    $valid = ExtractedSopData::fromLlmText(json_encode([
+    $valid = ExtractedSopData::fromLlmText(FencedLlmResponse::wrapArray([
         'header' => ['title' => 'SOP'],
         'sections' => [[
             'title' => null,
@@ -41,11 +44,11 @@
                 'work_points' => [], 'safety_points' => [], 'quality_points' => [], 'pm_points' => [],
             ]],
         ]],
-    ], JSON_THROW_ON_ERROR));
+    ]));
     expect($valid->sections)->toHaveCount(1);
     expect($valid->toJsonString())->toContain('締める');
 
-    expect(fn (): ExtractedSopData => ExtractedSopData::fromLlmText('{"header": {}, "sections": []}'))
+    expect(fn (): ExtractedSopData => ExtractedSopData::fromLlmText(FencedLlmResponse::wrap('{"header": {}, "sections": []}')))
         ->toThrow(LlmOutputInvalidException::class);
 });
 
@@ -63,12 +66,12 @@
 });
 
 test('GeneratedScenarioData: steps ツリーへ変換される (id=null / shot_type enum)', function (): void {
-    $data = GeneratedScenarioData::fromLlmText(json_encode(['cuts' => [
+    $data = GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
         ['no' => 1, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'hiki',
             'shooting_point' => null, 'narration' => 'やります', 'subtitle_primary' => null, 'subtitle_secondary' => '字幕'],
         ['no' => 2, 'type' => 'point', 'parent_no' => 1, 'scene' => '手元', 'shot_type' => 'yori',
             'shooting_point' => '寄る', 'narration' => null, 'subtitle_primary' => '要点', 'subtitle_secondary' => null],
-    ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
+    ]]));
 
     $steps = $data->toScenarioSteps();
     expect($steps)->toHaveCount(1);
@@ -83,29 +86,29 @@
 
 test('GeneratedScenarioData: parent_no の前方参照・無参照は SchemaViolation', function (): void {
     // 前方参照 (point が後出の step を参照)
-    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(json_encode(['cuts' => [
+    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
         ['no' => 1, 'type' => 'point', 'parent_no' => 2, 'scene' => '手元', 'shot_type' => 'yori',
             'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
         ['no' => 2, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'hiki',
             'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
-    ]], JSON_THROW_ON_ERROR)))->toThrow(LlmOutputInvalidException::class);
+    ]])))->toThrow(LlmOutputInvalidException::class);
 
     // step が parent_no を持つ
-    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(json_encode(['cuts' => [
+    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
         ['no' => 1, 'type' => 'step', 'parent_no' => 5, 'scene' => '全体', 'shot_type' => 'hiki',
             'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
-    ]], JSON_THROW_ON_ERROR)))->toThrow(LlmOutputInvalidException::class);
+    ]])))->toThrow(LlmOutputInvalidException::class);
 });
 
 test('GeneratedScenarioData: 文字数上限・不正 shot_type は SchemaViolation', function (): void {
-    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(json_encode(['cuts' => [
+    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
         ['no' => 1, 'type' => 'step', 'parent_no' => null,
             'scene' => str_repeat('あ', ScenarioLimits::MAX_SCENE_CHARS + 1), 'shot_type' => 'hiki',
             'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
-    ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))->toThrow(LlmOutputInvalidException::class);
+    ]])))->toThrow(LlmOutputInvalidException::class);
 
-    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(json_encode(['cuts' => [
+    expect(fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(FencedLlmResponse::wrapArray(['cuts' => [
         ['no' => 1, 'type' => 'step', 'parent_no' => null, 'scene' => '全体', 'shot_type' => 'zoom',
             'shooting_point' => null, 'narration' => '', 'subtitle_primary' => null, 'subtitle_secondary' => ''],
-    ]], JSON_THROW_ON_ERROR)))->toThrow(LlmOutputInvalidException::class);
+    ]])))->toThrow(LlmOutputInvalidException::class);
 });
diff --git a/tests/Unit/Manual/LlmJsonTest.php b/tests/Unit/Manual/LlmJsonTest.php
new file mode 100644
index 00000000..8da4cca2
--- /dev/null
+++ b/tests/Unit/Manual/LlmJsonTest.php
@@ -0,0 +1,113 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\LlmOutputInvalidReason;
+use App\Support\Manual\LlmJson;
+use Tests\Support\Manual\LlmJsonRejection;
+
+/*
+ * 復号点 `LlmJson::decode()` の受理契約 (家系の正典 v1 の i2〜i6)。
+ *
+ * 受理文法: 応答 = PRE OPEN VALUE GAP CLOSE POST
+ * 区分の決定順序は `LlmJson` の docblock が正本。ここではその表の各行を 1 ケースずつ固定する。
+ */
+
+// ---- 受理 (6 件) ----
+
+test('A1: 言語札つきの囲みちょうど 1 つを受理する', function (): void {
+    expect(LlmJson::decode("```json\n{\"a\": 1}\n```"))->toBe(['a' => 1]);
+});
+
+test('A2: 言語札の無い囲みを受理する', function (): void {
+    expect(LlmJson::decode("```\n{\"a\": 1}\n```"))->toBe(['a' => 1]);
+});
+
+test('A3: 印を含まない前置き・後書きがあっても受理する', function (): void {
+    expect(LlmJson::decode("解析結果は次のとおりです。\n```json\n{\"a\": 1}\n```\n以上です。"))->toBe(['a' => 1]);
+});
+
+test('A4: 最上位が list でも受理する (正典 q3 は据え置き)', function (): void {
+    expect(LlmJson::decode("```json\n[1, 2]\n```"))->toBe([1, 2]);
+});
+
+test('A5: 値の中に現れた印は終端に数えない', function (): void {
+    expect(LlmJson::decode("```json\n{\"a\": \"``` inside\"}\n```"))->toBe(['a' => '``` inside']);
+});
+
+test('A6: 逆引用符の個数の対応は見ない (開き 4 個 + 閉じ 3 個)', function (): void {
+    expect(LlmJson::decode("````json\n{\"a\": 1}\n```"))->toBe(['a' => 1]);
+});
+
+// ---- 拒否 (17 件。区分まで検証する) ----
+
+dataset('受理契約に合わない応答', [
+    'R1: 素の JSON' => ['{"a": 1}', LlmOutputInvalidReason::FenceAbsent],
+    'R2: JSON でない文章' => ['これは JSON ではありません', LlmOutputInvalidReason::FenceAbsent],
+    'R3: 閉じの印より後にもう 1 つ囲みがある' => [
+        "```json\n{\"a\": 1}\n``` そして ```json\n{\"b\": 2}\n```",
+        LlmOutputInvalidReason::FenceMultiple,
+    ],
+    'R4: 値の後の印が別言語の開き' => [
+        "```json\n{\"a\": 1}\n```python\nprint()\n",
+        LlmOutputInvalidReason::FenceMultiple,
+    ],
+    'R5: 括弧の対応が取れない' => ["```json\n{\"a\": [}\n```", LlmOutputInvalidReason::SyntaxBroken],
+    'R6: 値の後の余剰トークン' => ["```json\n{\"a\": 1}}\n```", LlmOutputInvalidReason::SyntaxBroken],
+    'R7: json_decode が落ちる値' => ["```json\n{\"a\": }\n```", LlmOutputInvalidReason::SyntaxBroken],
+    'R8: 最上位が数値' => ["```json\n42\n```", LlmOutputInvalidReason::TopLevelNotContainer],
+    'R9: 空のブロック' => ["```json\n```", LlmOutputInvalidReason::TopLevelNotContainer],
+    'R10: 最上位が文字列' => ["```json\n\"文字列\"\n```", LlmOutputInvalidReason::TopLevelNotContainer],
+    'R11: 値が完結しないまま終端' => ["```json\n{\"a\": 1", LlmOutputInvalidReason::ValueIncompleteInferred],
+    'R12: 文字列の途中で終端' => ["```json\n{\"a\": \"未閉", LlmOutputInvalidReason::ValueIncompleteInferred],
+    'R13: 開きの印の直後で終端' => ['```json', LlmOutputInvalidReason::ValueIncompleteInferred],
+    'R14: 閉じの印が無い' => ["```json\n{\"a\": 1}\n", LlmOutputInvalidReason::ClosingFenceAbsent],
+    'R16: 不正な UTF-8 を含む値' => ["```json\n{\"a\": \"\xC3\x28\"}\n```", LlmOutputInvalidReason::SyntaxBroken],
+    'R17a: GAP に全角空白' => ["```json\n{\"a\": 1}\u{3000}\n```", LlmOutputInvalidReason::SyntaxBroken],
+    'R17b: GAP に NBSP' => ["```json\n{\"a\": 1}\u{00A0}\n```", LlmOutputInvalidReason::SyntaxBroken],
+]);
+
+test('受理契約に合わない応答は区分つきで拒否される', function (string $text, LlmOutputInvalidReason $reason): void {
+    expect(LlmJsonRejection::capture($text)->reason)->toBe($reason);
+})->with('受理契約に合わない応答');
+
+test('R15: 入れ子の深さ超過は委譲先の JsonException で syntax_broken', function (): void {
+    $deep = str_repeat('[', 513).str_repeat(']', 513);
+
+    expect(LlmJsonRejection::capture("```json\n".$deep."\n```")->reason)
+        ->toBe(LlmOutputInvalidReason::SyntaxBroken);
+});
+
+// ---- 非漏洩 (6 区分。i9) ----
+
+dataset('sentinel を含む 6 区分の応答', [
+    'fence_absent' => ['プレーンな応答 '.LlmJsonRejection::SENTINEL, LlmOutputInvalidReason::FenceAbsent],
+    'fence_multiple' => [
+        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL."\"}\n```\n```json\n{\"b\": 2}\n```",
+        LlmOutputInvalidReason::FenceMultiple,
+    ],
+    'syntax_broken' => [
+        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL."\",}\n```",
+        LlmOutputInvalidReason::SyntaxBroken,
+    ],
+    'top_level_not_container' => [
+        "```json\n\"".LlmJsonRejection::SENTINEL."\"\n```",
+        LlmOutputInvalidReason::TopLevelNotContainer,
+    ],
+    'value_incomplete_inferred' => [
+        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL,
+        LlmOutputInvalidReason::ValueIncompleteInferred,
+    ],
+    'closing_fence_absent' => [
+        "```json\n{\"a\": \"".LlmJsonRejection::SENTINEL."\"}\n",
+        LlmOutputInvalidReason::ClosingFenceAbsent,
+    ],
+]);
+
+test('例外の message / userMessage に応答本文が漏れない', function (string $text, LlmOutputInvalidReason $reason): void {
+    $exception = LlmJsonRejection::capture($text);
+
+    expect($exception->reason)->toBe($reason);
+    expect($exception->getMessage())->not->toContain(LlmJsonRejection::SENTINEL);
+    expect($exception->userMessage())->not->toContain(LlmJsonRejection::SENTINEL);
+})->with('sentinel を含む 6 区分の応答');
diff --git a/tests/Unit/Manual/WorkDecompositionResponseDataTest.php b/tests/Unit/Manual/WorkDecompositionResponseDataTest.php
index 6a7052d9..dfb30607 100644
--- a/tests/Unit/Manual/WorkDecompositionResponseDataTest.php
+++ b/tests/Unit/Manual/WorkDecompositionResponseDataTest.php
@@ -5,6 +5,7 @@
 use App\DataTransferObjects\Manual\Analysis\WorkDecompositionResponseData;
 use App\Enums\Manual\ScenarioVerdict;
 use App\Exceptions\Manual\LlmOutputInvalidException;
+use Tests\Support\Manual\FencedLlmResponse;
 
 /*
  * WorkDecompositionResponseData: work-decomposition 応答全体 ({steps, validation}) を
@@ -19,7 +20,7 @@
  */
 function decompositionResponseText(array $overrides = []): string
 {
-    return json_encode([...[
+    return FencedLlmResponse::wrapArray([...[
         'steps' => [['no' => 1, 'action' => 'バルブを閉じる', 'points' => ['止まるまで回す']]],
         'validation' => [
             'verdict' => 'needs_review',
@@ -27,7 +28,7 @@ function decompositionResponseText(array $overrides = []): string
             'works' => ['バルブ閉止作業', '点検作業'],
             'split_recommended' => true,
         ],
-    ], ...$overrides], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ], ...$overrides]);
 }
 
 test('steps と validation の両方が揃った応答を組み立てる', function (): void {
@@ -43,9 +44,9 @@ function decompositionResponseText(array $overrides = []): string
 });
 
 test('validation 欠落は path=validation の LlmOutputInvalidException になる', function (): void {
-    $text = json_encode([
+    $text = FencedLlmResponse::wrapArray([
         'steps' => [['no' => 1, 'action' => 'バルブを閉じる', 'points' => []]],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    ]);
 
     try {
         WorkDecompositionResponseData::fromLlmText($text);
@@ -66,7 +67,7 @@ function decompositionResponseText(array $overrides = []): string
     }
 });
 
-test('JSON として壊れている応答は path=null のまま落ちる (既存経路は無変更)', function (): void {
+test('囲みの無い応答は path=null のまま落ちる (復号の失敗に違反位置は無い)', function (): void {
     try {
         WorkDecompositionResponseData::fromLlmText('これは JSON ではない');
         expect(false)->toBeTrue(); // 到達しない
diff --git a/tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php b/tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php
index 8a7dd60a..de32cf3a 100644
--- a/tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php
+++ b/tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php
@@ -7,6 +7,7 @@
 use App\Exceptions\Manual\AnalysisFailedException;
 use App\Exceptions\Manual\LlmOutputInvalidException;
 use App\Support\Manual\AnalysisAcceptanceGate;
+use Tests\Support\Manual\FencedLlmResponse;
 
 /*
  * AnalysisAcceptanceGate (画像・スキャン SOP の OCR 対応): OCR 経路の成功条件。
@@ -24,7 +25,7 @@
 /** @param  list<string>  $workProcesses */
 function ocrResult(array $workProcesses): ExtractedSopData
 {
-    return ExtractedSopData::fromLlmText(json_encode([
+    return ExtractedSopData::fromLlmText(FencedLlmResponse::wrapArray([
         'header' => ['title' => null, 'department' => null, 'revision' => null],
         'sections' => [[
             'title' => null,
@@ -41,7 +42,7 @@ function ocrResult(array $workProcesses): ExtractedSopData
                 array_keys($workProcesses),
             ),
         ]],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
+    ]));
 }
 
 test('先に赤くする: [UNREADABLE] のみの結果は現状のスキーマ検証だけでは拒否されない', function (): void {
@@ -131,7 +132,7 @@ function ocrResult(array $workProcesses): ExtractedSopData
 });
 
 test('検証順序: スキーマ違反 (空文字列 work_process) は日本語比率チェックまで到達せず schemaViolation になる', function (): void {
-    expect(fn () => ExtractedSopData::fromLlmText(json_encode([
+    expect(fn () => ExtractedSopData::fromLlmText(FencedLlmResponse::wrapArray([
         'header' => [],
         'sections' => [[
             'title' => null,

```

---

## 検証結果 (Round 2 時点)

- `composer phpstan`: OK (エラー 0)
- `vendor/bin/pint --test app tests resources`: passed
  (`devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/verify-predicate.php` は
   **main 側に元からある未整形ファイル** (未着手の T254 の設計証跡) で、本変更とは無関係のため触っていない)
- `pnpm lint` / `pnpm typecheck` / `pnpm typecheck:packages` / `pnpm build` / `pnpm build:packages`: すべて成功
- `pnpm test`: 179 files / 2398 tests passed
- `pnpm test:packages`: 10 files / 106 tests passed
- `composer test` (全数): 7435 tests。Round 1 時点の 3 failed は
  (a) 上記 `DefensiveInstructionsPresenceTest` 1 件 = **修正済み**、
  (b) `BughuntSelfTestExecutionTest` 2 件 = **他エージェントの bug-hunt shard が同一ホストで
  走行中だったことによる負荷起因のタイムアウト / pid 所有確認の失敗**。
  負荷が下がった状態で再実行して 3 tests passed を確認済み (本変更は
  `scripts/bug-hunt-shard.sh` に一切触れていない)。全数の再実行を現在進行中。
- 感度の実測 (いずれも赤になることを確認して戻した): 目録に無い依頼文を 1 本足す (検査 1) /
  依頼文 YAML の出力指示を戻す (検査 6) / 走査根に `json_decode` を置く (検査 5) /
  応答を変数へ束縛する (検査 3)。
- **`pipeline-smoke --check` は未実施**。provision 済み bughunt 環境 (manifest) を要求し、
  他エージェントの shard が走行中で provision できないため。加えて `--check` の preflight は
  環境・組織・残高・ffmpeg の確認であり本変更の経路に触れない。
- **互換性確認 A (pipeline-smoke 実走) / B (画像 SOP 解析) は課金を伴うため設計どおり実行せず、
  外部確認待ちとして TODO クローズ時に明示する。**

上記を踏まえて再レビューをお願いします。全体判定を 1 行で明示してください。
