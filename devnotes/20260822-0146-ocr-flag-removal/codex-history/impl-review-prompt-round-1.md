【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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

## system

あなたはコードレビュアーとして、Laravel + Svelte の改善実装をレビューする。

対象: TODO T242「OCR 機能フラグの完全撤去 (常時有効化)」の実装。
`manual.ocr_analysis_enabled` (env: `MANUAL_OCR_ANALYSIS_ENABLED`、既定 `false`) という
rollout gate フラグを完全撤去し、画像・スキャン SOP の OCR 対応を常時有効化する変更である。
設計 (`detailed-design.md`) は Codex 側で Round 4 まで APPROVED 済み。今回はその設計に
忠実に実装されているかを確認する。

レビュー観点:
1. **設計との一致性**: 詳細設計書の「変更後コード」と実装差分が一致しているか。逸脱があれば
   理由の妥当性を確認する。
2. **正確性**: config キー削除・`AcceptedSourceDocumentTypes` の固定値化・
   `AnalysisPipeline::resolveExtractInput()` の無条件化・Inertia props / Svelte props 撤去に
   ロジックの取りこぼしや副作用がないか。
3. **PHPStan 適合性**: level 10 相当の型安全性 (union 型・null 安全・戻り値型) が保たれているか。
4. **DTO/JsonResource パターン**: 本実装は既存の Inertia props (素の配列) パターンを変更しない
   前提だが、それが妥当か。
5. **テスト網羅性**: 削除したテスト (無効状態を固定するテスト) が本当に「検査対象の状態が
   構造的に作れなくなった」ものだけであり、有効状態側の挙動 (画像アップロード成功・
   PDF OCR フォールバック・OCR 対象外の失敗・容量上限・1 枚制約・422 文言の経路一致等) を
   1 件も失っていないかを厳密に確認する。
6. **セキュリティ**: 受理形式判定 (`AcceptedSourceDocumentTypes`) や容量上限判定
   (`SourceDocumentSizeLimit`) が sniff MIME に依存し続けているか (クライアント申告への
   依存が紛れ込んでいないか)。
7. **DESIGN.md 準拠**: color/radius/typography は token 経由か (今回は主にテキスト・props
   撤去なので新規 UI 追加は無い前提)。
8. **Atomic Design 準拠**: `resources/js/components/features/manual/` の階層責務が保たれているか。

出力形式: ファイルごとに判定し、Critical / Warning / Suggestion に分類する。最後に
全体判定を **APPROVED** または **CHANGES_REQUESTED** の一言で明記する。

---

## user

### 詳細設計書

`devnotes/20260822-0146-ocr-flag-removal/detailed-design.md` (全 1233 行) の要旨:
本 TODO の実装は施策 1〜10 で構成される。config からのフラグキー削除、
`AcceptedSourceDocumentTypes` の固定値化 (`imagesEnabled()` メソッド撤去)、
`AnalysisPipeline::runExtractStage()`/`resolveExtractInput()` の無条件化、
docblock 更新 2 件、`VideoManualController` + 4 Svelte ファイルの
`imageSourceDocumentsEnabled` prop 撤去、バックエンド 3 テストファイル・フロント 3 テスト
ファイルの畳み込み (無効状態テストの削除、有効状態テストの改名維持)、
`docs/rollout-checklists.md` の記録追記と運用手順節の「実行不可の過去の記録」化、
`docs/architecture.md` の rollout gate 記述更新、残存確認 (grep) が対象。
詳細設計書自体は worktree 内 `devnotes/20260822-0146-ocr-flag-removal/detailed-design.md`
として読めるので、必要なら参照すること。

### 実装中に発見した設計外の追加修正 (要レビュー)

`composer test` フルスイート実行で、設計書が挙げた 3 テストファイル (施策 6) 以外の
既存テスト `tests/Feature/Projects/SourceDocumentUploadTest.php` で 3 件の回帰を検出した。
このファイルは OCR 対応と無関係な一般的な SOP アップロードのテスト (作成時 multipart /
専用 route / polyglot 対策等) だが、「png は許可外拡張子である」という**画像常時受理化前の
前提**を fixture に使っていたため、画像が常時受理されるようになった結果、以下 3 件が
「422 を期待したが 302 (成功) を返す」/「例外を期待したが投げられない」で fail した:
- `許可外拡張子 (png) は 422`
- `拡張子偽装 (.pdf だが内容が PNG) は 422 (polyglot 対策)`
- `Service の内容 sniff 二層目: 許可外 mime は appendDocument が拒否する (行・ファイルなし)`

対応: fixture の mime を `image/png` → `image/gif` (依然として許可集合に無い形式) へ変更し、
テスト名もそれに合わせて改名した。**検査対象 (拡張子偽装・polyglot 対策そのものが機能して
いること) は変更していない** — 「許可外の mime なら拒否される」という契約自体は
画像常時受理化と無関係に成立し続けるべきものであり、単に「許可外の具体例」として
png ではなく gif を使うようにしただけである。この 3 件の修正はレビュー対象に含めてほしい
(diff内の `tests/Feature/Projects/SourceDocumentUploadTest.php` を参照)。

### 実装差分 (git diff)

以下は `git diff HEAD -- app/ resources/ tests/ routes/ config/ docs/` の全文。

```diff
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 1da8d26c..75e2a669 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -71,7 +71,6 @@ public function create(Request $request, Project $project): Response
             // StoreVideoManualRequest と同じ AcceptedSourceDocumentTypes を情報源にする
             // = ダイアログに出る形式とサーバが受理する形式が構造的に一致する。
             'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
-            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
             // help 文言用の受理形式ラベル (422 文言と同一の情報源)
             'sourceDocumentFormatsLabel' => AcceptedSourceDocumentTypes::formatsLabel(),
         ]);
@@ -201,9 +200,8 @@ public function show(
             'canManage' => $user->can('update', $manual),
             'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
             // SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
-            // AcceptedSourceDocumentTypes が単一の情報源 (フラグに連動)
+            // AcceptedSourceDocumentTypes が単一の情報源
             'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
-            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
         ]);
     }
 
diff --git a/app/Rules/SourceDocumentSizeLimit.php b/app/Rules/SourceDocumentSizeLimit.php
index 4fb81b74..64535c0d 100644
--- a/app/Rules/SourceDocumentSizeLimit.php
+++ b/app/Rules/SourceDocumentSizeLimit.php
@@ -20,11 +20,10 @@
  * 偽装できるため、上限選択の材料にしない (JPEG バイトを `.pdf` にリネームして
  * 20MB 上限側へ迂回する攻撃を防ぐ)。
  *
- * 「画像かどうか」はファイルの実バイトの性質であり、`ocr_analysis_enabled` フラグで
- * 変わる「現在の許可集合」(`AcceptedSourceDocumentTypes`) とは別概念。
- * ここではフラグに依存しない固定の判定を使う (許可判定と容量分類の責務を混同しない。
- * MIME の受理可否そのものは `mimes:` ルールが担当し、本 Rule は「受理された後の
- * 容量分類」だけを担当する)。
+ * 「画像かどうか」はファイルの実バイトの性質であり、受理可否そのもの
+ * (`AcceptedSourceDocumentTypes`) とは別概念。ここでは受理可否の判定に依存しない
+ * 固定の判定を使う (許可判定と容量分類の責務を混同しない。MIME の受理可否そのものは
+ * `mimes:` ルールが担当し、本 Rule は「受理された後の容量分類」だけを担当する)。
  */
 final class SourceDocumentSizeLimit implements ValidationRule
 {
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index 21288b06..7b404ddc 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -210,18 +210,17 @@ private function runExtractStage(
         LlmCallContextData $context,
     ): ExtractedSopData {
         $isImage = in_array($document->mime, ['image/jpeg', 'image/png'], true);
-        $ocrEnabled = config()->boolean('manual.ocr_analysis_enabled');
-        // 初期値: 画像 + フラグ有効なら最初から 'ocr'、それ以外は 'text'。
+        // 初期値: 画像なら最初から 'ocr'、それ以外は 'text'。
         // PDF が品質ゲート失敗から OCR フォールバックへ入る場合は、resolveExtractInput()
         // が参照渡しでこの値を 'ocr' へ更新する (media 検証を試みる直前に更新するため、
         // 検証が失敗して例外が飛んでも route は正しく 'ocr' のまま catch へ渡る)。
-        $route = ($isImage && $ocrEnabled) ? 'ocr' : 'text';
+        $route = $isImage ? 'ocr' : 'text';
         // 媒体検証が成功した後に LLM 呼び出しが失敗した場合でも、検証済みの媒体メタデータ
         // (容量・ページ数・画素数) をログへ残すため、$input をこのスコープで保持し続ける。
         $input = null;
 
         try {
-            $input = $this->resolveExtractInput($document, $isImage, $ocrEnabled, $route);
+            $input = $this->resolveExtractInput($document, $isImage, $route);
             $extracted = $this->runExtractStep($job, $document, $input, $deadline, $context);
 
             $this->logExtractStageTerminal($job, $document, $route, $input, null);
@@ -248,10 +247,9 @@ private function runExtractStage(
     private function resolveExtractInput(
         SourceDocument $document,
         bool $isImage,
-        bool $ocrEnabled,
         string &$route,
     ): ExtractedText|ImageAnalysisMediaData|PdfAnalysisMediaData {
-        if ($isImage && $ocrEnabled) {
+        if ($isImage) {
             // 画像は SopTextExtractor::kindFor() の default 分岐が unextractable を投げる
             // (テキスト抽出は元々試みない対象)。ここで直接 media 検証へ回す
             // ($route は呼び出し元で既に 'ocr' に初期化済み)。
@@ -262,13 +260,13 @@ private function resolveExtractInput(
             return $this->extractor->extract($document);
         } catch (AnalysisFailedException $exception) {
             $isPdf = $document->mime === 'application/pdf';
-            if ($ocrEnabled && $isPdf && $exception->reason->isOcrEligibleForPdf()) {
+            if ($isPdf && $exception->reason->isOcrEligibleForPdf()) {
                 $route = 'ocr'; // media 検証を試みる直前に更新 (この後の呼び出しが失敗しても正しい)
 
                 return $this->mediaValidator->validatePdfForOcr($document);
             }
 
-            throw $exception; // OCR 対象外、またはフラグ無効時はそのまま失敗 (既存の catch → failJob)
+            throw $exception; // OCR 対象外はそのまま失敗 (既存の catch → failJob)
         }
     }
 
diff --git a/app/Services/Manual/SopTextExtractor.php b/app/Services/Manual/SopTextExtractor.php
index 6b066157..fc5f86ad 100644
--- a/app/Services/Manual/SopTextExtractor.php
+++ b/app/Services/Manual/SopTextExtractor.php
@@ -20,9 +20,9 @@
  * SOP (SourceDocument) からのテキスト抽出。doc/10 §10.7。
  *
  * テキスト抽出できない・日本語比率が不足する PDF は `AnalysisPipeline` が
- * `AnalysisMediaValidator` 経由の OCR 経路 (画像・スキャン SOP の OCR 対応) へ回す
- * (`manual.ocr_analysis_enabled` フラグが有効な場合のみ)。本クラスの責務は
- * あくまで「テキストを抽出できるか」の判定であり、OCR 経路の判断はここでは行わない。
+ * `AnalysisMediaValidator` 経由の OCR 経路 (画像・スキャン SOP の OCR 対応) へ回す。
+ * 本クラスの責務はあくまで「テキストを抽出できるか」の判定であり、OCR 経路の判断は
+ * ここでは行わない。
  *
  * - 分岐はアップロード時に内容 sniff 済みの mime を使う (クライアント拡張子は信頼しない)
  * - 抽出不能/実質空/バイト上限超過は AnalysisFailedException (ユーザー向け文言)
diff --git a/app/Support/Manual/AcceptedSourceDocumentTypes.php b/app/Support/Manual/AcceptedSourceDocumentTypes.php
index c504d784..e4951bf1 100644
--- a/app/Support/Manual/AcceptedSourceDocumentTypes.php
+++ b/app/Support/Manual/AcceptedSourceDocumentTypes.php
@@ -5,10 +5,14 @@
 namespace App\Support\Manual;
 
 /**
- * 受理する SourceDocument の形式の唯一の情報源 (画像・スキャン SOP の OCR 対応)。
- * config の静的な拡張子リストと `manual.ocr_analysis_enabled` フラグを合成し、
+ * 受理する SourceDocument の形式の唯一の情報源。
+ * config の静的な拡張子リストに画像拡張子 (jpg/jpeg/png) を加えた固定集合を返し、
  * FormRequest / Service / フロント Props の全てがここを経由することで、
- * 画像受理の有効・無効が 1 箇所で一貫する。
+ * 受理形式が 1 箇所で一貫する。
+ *
+ * 画像・スキャン SOP の OCR 対応 (旧 `manual.ocr_analysis_enabled` フラグ) は
+ * オーナー決定により撤去済みで、画像受理は常時有効である
+ * (経緯は docs/rollout-checklists.md 「画像・スキャン SOP の OCR 対応」節)。
  */
 final class AcceptedSourceDocumentTypes
 {
@@ -22,20 +26,19 @@ public static function extensions(): array
         /** @var list<string> $base */
         $base = config()->array('manual.source_document_mimes');
 
-        return self::imagesEnabled() ? [...$base, ...self::IMAGE_EXTENSIONS] : $base;
+        return [...$base, ...self::IMAGE_EXTENSIONS];
     }
 
     /** @return list<string> 内容 sniff MIME (SourceDocumentService::allowedMimeTypes 相当) */
     public static function mimes(): array
     {
-        $base = [
+        return [
             'application/pdf',
             'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
             'application/vnd.ms-excel',
             'text/plain',
+            ...self::IMAGE_MIMES,
         ];
-
-        return self::imagesEnabled() ? [...$base, ...self::IMAGE_MIMES] : $base;
     }
 
     /**
@@ -48,28 +51,16 @@ public static function acceptAttribute(): string
         return implode(',', $parts);
     }
 
-    /**
-     * フロントの画像対応可否表示用 (accept 属性の文字列を解析して画像対応可否を
-     * 判定させないための専用の真偽値)。
-     */
-    public static function imagesEnabled(): bool
-    {
-        return config()->boolean('manual.ocr_analysis_enabled');
-    }
-
     /**
      * 受理形式の人間向けラベル (法務確認を経た文面。FormRequest の 422 文言と
      * 作成画面の help 文言が共有する)。
      *
      * **機械導出しない**: 拡張子リストから日本語の文を組み立てる形にすると
-     * config を触った副作用で文面が変わりうるため、承認済みの 2 文をそのまま持つ。
-     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin (基底拡張子集合・
-     * 画像拡張子集合が現在値ちょうど) が検出する。
+     * config を触った副作用で文面が変わりうるため、承認済みの文をそのまま持つ。
+     * 乖離は AcceptedSourceDocumentTypesTest の前提 pin (拡張子集合が現在値ちょうど) が検出する。
      */
     public static function formatsLabel(): string
     {
-        return self::imagesEnabled()
-            ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
-            : 'PDF・Excel・テキスト形式';
+        return 'PDF・Excel・テキスト形式、または JPEG・PNG の画像';
     }
 }
diff --git a/config/manual.php b/config/manual.php
index 0b07d12c..80730d9b 100644
--- a/config/manual.php
+++ b/config/manual.php
@@ -54,11 +54,9 @@
     'source_document_mimes' => ['pdf', 'xlsx', 'xls', 'txt'],
 
     // ── 画像・スキャン SOP の OCR 対応 ──────────────────────────────
-    // 画像受理 + PDF の OCR フォールバックの単一 gate。既定 false = 施策 1〜9 のコードは
-    // デプロイされていても無害 (画像は 422 のまま、PDF 品質ゲート失敗は即時失敗のまま)。
-    // true にするのは法務確認・画像内 prompt injection の手動評価・責任者承認が
-    // 完了した後の独立した運用操作とする (docs/rollout-checklists.md)。
-    'ocr_analysis_enabled' => env('MANUAL_OCR_ANALYSIS_ENABLED', false),
+    // 画像受理 + PDF の OCR フォールバックは無条件で有効 (旧 rollout gate
+    // `manual.ocr_analysis_enabled` はオーナー決定により撤去済み。
+    // 経緯は docs/rollout-checklists.md 「画像・スキャン SOP の OCR 対応」節)。
 
     // 画像専用の容量上限 (既存の source_document_max_bytes とは別枠、より小さい値)。
     // 一次情報: Anthropic Vision ドキュメント (platform.claude.com/docs/en/build-with-claude/vision
diff --git a/docs/architecture.md b/docs/architecture.md
index d5c990b7..98766764 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2774,10 +2774,11 @@ ### 媒体添付の窓口拡張 (OCR 経路。画像・スキャン SOP の OCR
   出力は所定スキーマのみ) を明記する (`DefensiveInstructionsPresenceTest` が固定)。
   判読不能箇所は ASCII マーカー `[UNREADABLE]` で明示させ (日本語文字を含まないため
   `AnalysisAcceptanceGate` の日本語比率ゲートが正しく機能する)。
-- **rollout gate**: `config('manual.ocr_analysis_enabled')` (既定 `false`) が
-  画像受理 (アップロード層) と OCR フォールバック (`AnalysisPipeline::resolveExtractInput()`) の
-  両方を一貫してゲートする (`AcceptedSourceDocumentTypes` が単一の情報源)。
-  フラグを `true` にする前提条件はチェックリスト (`docs/rollout-checklists.md`) を参照。
+- **rollout gate は撤去済み**: 画像受理 (アップロード層) と OCR フォールバック
+  (`AnalysisPipeline::resolveExtractInput()`) は無条件で有効である (`AcceptedSourceDocumentTypes`
+  が単一の情報源)。旧 rollout gate `config('manual.ocr_analysis_enabled')` はオーナー決定
+  (2026-08-21〜22、JST) により撤去した。有効化に至った経緯・既存の承認記録・
+  再評価義務は `docs/rollout-checklists.md` を参照。
 - **観測ログ**: `AnalysisPipeline::logExtractStageTerminal()` が extract 段の終端
   (成功・失敗を問わず、`run()` の 1 回の実行につきちょうど 1 回。永続化された冪等キーは
   持たないため、同じジョブが stale 回復等で再実行されれば行が増える) を
diff --git a/docs/rollout-checklists.md b/docs/rollout-checklists.md
index 72304cc6..06a368d3 100644
--- a/docs/rollout-checklists.md
+++ b/docs/rollout-checklists.md
@@ -6,6 +6,32 @@ # rollout チェックリスト
 
 ## 画像・スキャン SOP の OCR 対応 (`MANUAL_OCR_ANALYSIS_ENABLED`)
 
+> **フラグは撤去済み (2026-08-21〜22、JST。オーナー決定)**: 本チェックリストが定めていた
+> rollout gate `config('manual.ocr_analysis_enabled')` は、本節が定める前提条件 (項目 1 の
+> 完了、項目 2 の例外決定) が満たされたことを踏まえ、オーナーが「フラグを既定 `true` にして
+> 緊急停止手段として残す」案を提示された上で、B 案 (フラグの完全撤去 = 常時有効化) を
+> 「いらないので。」という理由で選択したため、コードから撤去した
+> (`devnotes/20260822-0146-ocr-flag-removal/`)。対応する config キー・env 変数はコードに
+> 存在しない。画像・スキャン SOP の OCR 対応は**現在は無条件で有効**であり、以下の
+> チェックリストは**その有効化に至った経緯の記録**として残す。
+>
+> **フラグ撤去後の障害対応**: 環境変数の切替による即時停止はできなくなった (この手段は
+> フラグ撤去前から「単独の運用操作」であり本リポジトリの変更ではなかった点は変わらないが、
+> 撤去後はその操作対象自体が無い)。問題発覚時の復旧手段は、**本番環境を運用する側が持つ
+> デプロイ手順に依存する** (本リポジトリには `deploy/` / terraform / k8s / CI デプロイ job の
+> いずれも存在しない — AGENTS.md 「運用要件 (route:cache)」節が明記するとおり — ため、
+> 本チェックリストは復旧手段そのものを保証しない)。運用手順が承認済みリリースへの
+> rollback に対応している場合はそれを行い、対応していない場合、またはこの場面で
+> rollback が適切でない場合は、無効化・修正する patch を通常のデプロイ手順で反映する。
+> どちらを選ぶかは運用する側の判断であり、本チェックリストは選択肢を限定しない。
+>
+> 項目 3 (再評価対象の棚卸し) が定める再評価義務は、フラグの有無と無関係に存続する。
+>
+> **リポジトリ外の残置確認 (運用側の作業として引き継ぐ)**: 本番環境の環境変数・Secret 管理に
+> `MANUAL_OCR_ANALYSIS_ENABLED` の設定が残っていても、コードがこのキーを参照しなくなった
+> ため実害は無い。ただし整理は別途、本番環境を運用する側の作業として残す
+> (管理主体・削除確認の方法は本チェックリストの対象外であり、運用側が判断する)。
+
 `config('manual.ocr_analysis_enabled')` (既定 `false`) を `true` にする前に、以下の
 各項目について、**充足しているか、または責任者がその項目を要求しないという例外決定を
 下したか**を責任者が確認し、記録を残す(例外決定は「基準を満たした」ことにはならない。
@@ -73,36 +99,43 @@ ## 承認記録
 `MANUAL_OCR_ANALYSIS_ENABLED=true` への切替を進めることを決定したものである。
 項目 3 の再評価義務は前述のとおり存続する。
 
-## 反映の運用手順
-
-- **フラグを `true` にする変更そのものはリポジトリ変更ではない**。`config/manual.php` の
-  既定値 (`env('MANUAL_OCR_ANALYSIS_ENABLED', false)`) はこのままにし、`true` への切替は
-  本番環境変数 `MANUAL_OCR_ANALYSIS_ENABLED` の設定という単独の運用操作で行う
-  (施策 11 の設計どおり。コード変更を伴わない)。
-  **本リポジトリにはデプロイ定義が無い** (AGENTS.md が定める経路キャッシュ関連の運用要件節が明記する
-  とおり `deploy/` / terraform / k8s / CI デプロイ job のいずれも存在しない) ため、
-  この環境変数設定・`config:cache` 再生成・プロセス再起動は**このリポジトリ内の変更としては
-  実行できない**。承認記録が揃った時点で、本番環境を運用する側が上記手順を人手で実施する
-  (残タスクは summary で明示的に引き継ぐ)。
-- production が `config:cache` を使う場合、`.env` の変更だけでは反映されない。
-  `MANUAL_OCR_ANALYSIS_ENABLED=true` の設定後、`config:cache` の再生成とプロセス再起動が
-  別途必要 (既存運用の一般論であり、AGENTS.md が定める経路キャッシュ関連の運用要件
-  そのものを変更するものではない)。
-- フラグ有効化直後の確認は「制御された synthetic 確認」(実際にアップロード・DB 書き込み・
-  外部 LLM 呼び出し・チケット消費を伴う) と呼ぶ (read-only ではない)。専用の検証用組織・
-  使い捨てのテスト SOP (PII を含まない fixture) を用いる。消費したチケットは通常の grant
-  または検証費用として計上し、既存の課金履歴 (`ticket_reservations` / `llm_call_logs` 等の
-  ledger) を削除・巻き戻す形にはしない。生成された `VideoManual`/`SourceDocument` 等の
-  テストデータは確認後に削除する。
-- **フラグを `false` へ戻す (無効化) 時の挙動**: フラグは DB へスナップショットせず、
-  `AnalysisPipeline::resolveExtractInput()` がジョブ実行の瞬間に都度 `config()` から読む値を
-  そのまま使う。
+## 旧・反映の運用手順 (廃止済み・実行不可。フラグ撤去前の記録)
+
+**本節は実行できない**。`MANUAL_OCR_ANALYSIS_ENABLED` フラグと対応する config キーは
+撤去済みでコードに存在しないため、以下の手順をそのまま実行する対象は無い。
+本節はフラグが存在していた当時の計画 (実際に有効化した経緯を含む) を、
+意思決定の記録として保存するものである。
+
+- (計画として記録) **フラグを `true` にする変更そのものはリポジトリ変更ではない**という
+  前提で運用する計画だった。`config/manual.php` の既定値
+  (`env('MANUAL_OCR_ANALYSIS_ENABLED', false)`) はそのままにし、`true` への切替は
+  本番環境変数 `MANUAL_OCR_ANALYSIS_ENABLED` の設定という単独の運用操作で行う想定だった
+  (コード変更を伴わない)。**本リポジトリにはデプロイ定義が無い** (AGENTS.md が定める
+  経路キャッシュ関連の運用要件節が明記するとおり `deploy/` / terraform / k8s /
+  CI デプロイ job のいずれも存在しない) ため、この環境変数設定・`config:cache` 再生成・
+  プロセス再起動は**このリポジトリ内の変更としては実行できない**という前提も、
+  当時からそのまま変わっていない。
+- (計画として記録) production が `config:cache` を使う場合、`.env` の変更だけでは
+  反映されない。`MANUAL_OCR_ANALYSIS_ENABLED=true` の設定後、`config:cache` の再生成と
+  プロセス再起動が別途必要という計画だった (既存運用の一般論であり、AGENTS.md が定める
+  経路キャッシュ関連の運用要件そのものを変更するものではない)。
+- (計画として記録。実施記録は無い) フラグ有効化直後の確認は「制御された synthetic 確認」
+  (実際にアップロード・DB 書き込み・外部 LLM 呼び出し・チケット消費を伴う) と呼ぶ
+  (read-only ではない) 計画だった。専用の検証用組織・使い捨てのテスト SOP
+  (PII を含まない fixture) を用いる想定だった。この synthetic 確認を実際に実施したという
+  記録は本タスクへの入力として与えられていない (無い情報をここで作文しない)。
+- (計画として記録) **フラグを `false` へ戻す (無効化) 時の挙動**: フラグは DB へ
+  スナップショットせず、`AnalysisPipeline::resolveExtractInput()` がジョブ実行の瞬間に
+  都度 `config()` から読む値をそのまま使う設計だった。
   - `queued` のジョブは、無効化後に実行されると、その時点のフラグ値 (`false`) で判定される
     (画像は 422 相当の `unextractable`、PDF 品質ゲート失敗も OCR フォールバックなしで即時失敗。
-    フラグが最初から false だった場合と同じ既存の失敗経路)。
+    フラグが最初から false だった場合と同じ既存の失敗経路) という設計だった。
   - 既に `run()` を実行中で `resolveExtractInput()` を通過済みのジョブは、
-    その 1 回の `run()` 呼び出しの中では config を再読込しないため、最後まで OCR 経路で完走する。
-  - この挙動は追加の実装を要しない (kill switch としての目的にはこの挙動が自然に適合する)。
+    その 1 回の `run()` 呼び出しの中では config を再読込しないため、最後まで OCR 経路で
+    完走する設計だった。
+  - この挙動は追加の実装を要しない (kill switch としての目的にはこの挙動が自然に適合する)
+    という判断だった。**フラグ撤去後は、この kill switch 自体が存在しない**
+    (本節冒頭の追記を参照)。
 
 ## 観測・課金の評価 (rollout 後)
 
diff --git a/resources/js/components/features/manual/SourceDocumentUpload.svelte b/resources/js/components/features/manual/SourceDocumentUpload.svelte
index c93d4aef..c7e0d63b 100644
--- a/resources/js/components/features/manual/SourceDocumentUpload.svelte
+++ b/resources/js/components/features/manual/SourceDocumentUpload.svelte
@@ -17,10 +17,9 @@
         manualId: number;
         hasDocument: boolean;
         sourceDocumentAccept: string;
-        imageSourceDocumentsEnabled: boolean;
     }
 
-    let { projectId, manualId, hasDocument, sourceDocumentAccept, imageSourceDocumentsEnabled }: Props = $props();
+    let { projectId, manualId, hasDocument, sourceDocumentAccept }: Props = $props();
 
     const form = useForm<{ document: File | null }>({ document: null });
 
@@ -38,7 +37,7 @@
 </script>
 
 <form novalidate onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
-    <SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />
+    <SourceDocumentUploadNotice />
     <FormField
         label={hasDocument ? "手順書を差し替える" : "手順書 (SOP) をアップロード"}
         id="source-document"
diff --git a/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte b/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
index 18e28977..bb54811a 100644
--- a/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
+++ b/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
@@ -4,27 +4,19 @@
      * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
      * component 1 つへ集約している)。
      *
-     * 一般案内はフラグの真偽に関わらず常時表示する (テキスト・Excel・通常 PDF にも
-     * 等しく当てはまる事実のため)。OCR 固有警告だけを imageSourceDocumentsEnabled で
-     * 出し分ける (画像・スキャン SOP の OCR 対応の方針)。
+     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 `manual.ocr_analysis_enabled` フラグは
+     * オーナー決定により撤去済み) なので、OCR 固有警告も常時表示する。props は持たない。
      *
      * **wrapper 要素を作らない**: 呼び出し側の flex 列 (gap) が案内の各段落へ直接効く
      * 前提で描画順・間隔が決まっているため、fragment として 2 要素を返す。
      */
-    interface Props {
-        imageSourceDocumentsEnabled: boolean;
-    }
-
-    let { imageSourceDocumentsEnabled }: Props = $props();
 </script>
 
 <p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
     アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
 </p>
-{#if imageSourceDocumentsEnabled}
-    <p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
-        画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
-        不要な個人情報や機密情報が写っていないか特に確認してください。
-        画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
-    </p>
-{/if}
+<p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
+    画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
+    不要な個人情報や機密情報が写っていないか特に確認してください。
+    画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
+</p>
diff --git a/resources/js/pages/Manuals/Create.svelte b/resources/js/pages/Manuals/Create.svelte
index 987febb9..472957ba 100644
--- a/resources/js/pages/Manuals/Create.svelte
+++ b/resources/js/pages/Manuals/Create.svelte
@@ -28,8 +28,6 @@
         categories: CategoryOption[];
         /** SOP アップロードの `<input accept>` 属性値 (画像・スキャン SOP の OCR 対応) */
         sourceDocumentAccept: string;
-        /** 画像・スキャン PDF の OCR 対応が有効か (フラグ連動の案内出し分け専用) */
-        imageSourceDocumentsEnabled: boolean;
         /** 受理形式の人間向けラベル (422 文言と同一の情報源) */
         sourceDocumentFormatsLabel: string;
     }
@@ -38,7 +36,6 @@
         project,
         categories,
         sourceDocumentAccept,
-        imageSourceDocumentsEnabled,
         sourceDocumentFormatsLabel,
     }: Props = $props();
 
@@ -114,7 +111,7 @@
                         {/snippet}
                     </FormField>
                     <!-- ファイルを選ぶ前に外部送信の事実が見えている必要があるため file input の直前に置く -->
-                    <SourceDocumentUploadNotice {imageSourceDocumentsEnabled} />
+                    <SourceDocumentUploadNotice />
                     <FormField
                         label="手順書 (SOP・任意)"
                         id="manual-document"
diff --git a/resources/js/pages/Manuals/Show.svelte b/resources/js/pages/Manuals/Show.svelte
index a0a8b9fc..2df11d62 100644
--- a/resources/js/pages/Manuals/Show.svelte
+++ b/resources/js/pages/Manuals/Show.svelte
@@ -40,8 +40,6 @@
         categories: CategoryOption[];
         /** SOP アップロードの `<input accept>` 属性値 (画像・スキャン SOP の OCR 対応) */
         sourceDocumentAccept: string;
-        /** 画像・スキャン PDF の OCR 対応が有効か (フラグ連動の案内出し分け専用) */
-        imageSourceDocumentsEnabled: boolean;
     }
 
     let {
@@ -52,7 +50,6 @@
         canManage,
         categories,
         sourceDocumentAccept,
-        imageSourceDocumentsEnabled,
     }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
@@ -201,7 +198,6 @@
                             manualId={manual.id}
                             hasDocument={analysis.hasDocument}
                             {sourceDocumentAccept}
-                            {imageSourceDocumentsEnabled}
                         />
                     </div>
                 </Card>
diff --git a/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php b/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php
index 34bb8de9..69c07160 100644
--- a/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php
+++ b/tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php
@@ -20,18 +20,17 @@
 use Tests\Support\Manual\MinimalPdfFixture;
 
 /*
- * AI 解析パイプラインの OCR 経路 (画像・スキャン SOP の OCR 対応。施策 6):
+ * AI 解析パイプラインの OCR 経路 (画像・スキャン SOP の OCR 対応。常時有効。
+ * 旧 `manual.ocr_analysis_enabled` フラグはオーナー決定により撤去済み):
  * - 画像アップロード → OCR 経路 → 成功
  * - テキスト層の無い PDF → OCR フォールバック → 成功
  * - OCR 対象外の失敗 (tooLarge) はそのまま失敗する (回帰)
- * - フラグ無効時は画像・PDF 品質ゲート失敗のどちらも OCR フォールバックが一切発火しない (回帰)
  * - extract 段の終端ログがジョブにつきちょうど 1 回だけ出ること・route/failure_category/
  *   media メタデータが正しいこと
  */
 
 beforeEach(function (): void {
     Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
-    config()->set('manual.ocr_analysis_enabled', true);
 });
 
 /** @return array{Organization, Project, VideoManual} */
@@ -110,7 +109,7 @@ function fakeSuccessfulOcrScript(): void
     ]);
 }
 
-test('画像アップロード (フラグ有効) は OCR 経路で成功する', function (): void {
+test('画像アップロードは OCR 経路で成功する', function (): void {
     Storage::fake();
     [$organization, , $manual] = ocrPipelineOrg();
     $document = imageSourceDocument($manual, MinimalImageFixture::jpeg(10, 10));
@@ -126,7 +125,7 @@ function fakeSuccessfulOcrScript(): void
     expect($manual->status)->toBe(VideoManualStatus::Ready);
 });
 
-test('テキスト層の無い PDF (フラグ有効) は OCR フォールバックで成功する', function (): void {
+test('テキスト層の無い PDF は OCR フォールバックで成功する', function (): void {
     Storage::fake();
     [$organization, , $manual] = ocrPipelineOrg();
     $document = unreadablePdfSourceDocument($manual);
@@ -140,7 +139,7 @@ function fakeSuccessfulOcrScript(): void
     expect($job->status)->toBe(JobStatus::Succeeded);
 });
 
-test('OCR 対象外の失敗 (tooLarge) はそのまま失敗する (フラグ有効でも回帰)', function (): void {
+test('OCR 対象外の失敗 (tooLarge) はそのまま失敗する (回帰)', function (): void {
     Storage::fake();
     config()->set('manual.analysis_max_text_bytes', 10);
     [$organization, , $manual] = ocrPipelineOrg();
@@ -165,40 +164,6 @@ function fakeSuccessfulOcrScript(): void
     })->once();
 });
 
-test('フラグ無効時は画像アップロードが OCR フォールバックなしで即時失敗する (回帰)', function (): void {
-    Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', false);
-    [$organization, , $manual] = ocrPipelineOrg();
-    $document = imageSourceDocument($manual, MinimalImageFixture::jpeg(10, 10));
-    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
-    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
-
-    Log::spy();
-    app(AnalysisPipeline::class)->run($job->id);
-
-    $job->refresh();
-    expect($job->status)->toBe(JobStatus::Failed);
-    expect($job->error)->toContain('テキストを抽出できません');
-
-    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
-        return $message === 'AI 解析の抽出段 (終端)' && $context['route'] === 'text';
-    })->once();
-});
-
-test('フラグ無効時はテキスト品質ゲート失敗 PDF も OCR フォールバックなしで即時失敗する (回帰)', function (): void {
-    Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', false);
-    [$organization, , $manual] = ocrPipelineOrg();
-    $document = unreadablePdfSourceDocument($manual);
-    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
-    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
-
-    app(AnalysisPipeline::class)->run($job->id);
-
-    $job->refresh();
-    expect($job->status)->toBe(JobStatus::Failed);
-});
-
 test('画像の media 検証失敗 (画素数上限超過) は route=ocr で 1 回だけログされ LLM は呼ばれない', function (): void {
     Storage::fake();
     config()->set('manual.analysis_ocr_max_pixels', 1);
diff --git a/tests/Feature/Projects/SourceDocumentUploadOcrTest.php b/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
index f5b787b4..16d123df 100644
--- a/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
+++ b/tests/Feature/Projects/SourceDocumentUploadOcrTest.php
@@ -11,13 +11,13 @@
 use App\Support\Manual\AcceptedSourceDocumentTypes;
 use Illuminate\Http\UploadedFile;
 use Illuminate\Support\Facades\Storage;
-use Illuminate\Validation\ValidationException;
 use Inertia\Testing\AssertableInertia as Assert;
 use Tests\Support\Manual\MinimalImageFixture;
 
 /*
- * 画像・スキャン SOP の OCR 対応 (施策 1/11):
- * - フラグ true 時のみ jpg/png アップロードが成功する (先に赤くする: フラグ既定 false で 422)
+ * 画像・スキャン SOP の OCR 対応 (常時有効。旧 `manual.ocr_analysis_enabled` フラグは
+ * オーナー決定により撤去済み):
+ * - jpg/png アップロードが成功する
  * - HEIC は引き続き拒否され、文言に「JPEG / PNG で保存し直す」と出る
  * - 画像専用の容量上限は sniff MIME だけで判定される (偽装で迂回できない)
  * - webp/gif は引き続き拒否される (回帰)
@@ -47,22 +47,8 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     return UploadedFile::fake()->createWithContent($name, MinimalImageFixture::png(10, 10));
 }
 
-test('先に赤くする: フラグ既定 (false) では jpg アップロードが 422 になる', function (): void {
+test('jpg/png アップロードが成功する', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', false);
-    [, $owner, $project, $manual] = ocrUploadContext();
-
-    $this->actingAs($owner)->postJson(
-        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
-        ['document' => fakeJpegFile()],
-    )->assertUnprocessable()->assertJsonValidationErrors(['document']);
-
-    expect(SourceDocument::query()->count())->toBe(0);
-});
-
-test('フラグ true では jpg/png アップロードが成功する', function (): void {
-    Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     $this->actingAs($owner)->post(
@@ -74,9 +60,8 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     expect($document->mime)->toBe('image/jpeg');
 });
 
-test('フラグ true でも HEIC は引き続き 422 で拒否される', function (): void {
+test('HEIC は引き続き 422 で拒否される', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     // finfo は HEIC を image/heic (または application/octet-stream) と判定する。
@@ -93,20 +78,12 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
         .'PDF・Excel・テキスト形式、または JPEG・PNG の画像でアップロードし直してください。']]);
 });
 
-test('新規マニュアル作成時 (StoreVideoManualRequest) もフラグに応じて jpg 受理可否が変わる', function (): void {
+test('新規マニュアル作成時 (StoreVideoManualRequest) でも jpg アップロードが成功する', function (): void {
     Storage::fake();
     [, $owner, $project] = ocrUploadContext();
 
-    config()->set('manual.ocr_analysis_enabled', false);
-    $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
-        'title' => 'フラグ無効テスト',
-        'category' => null,
-        'document' => fakeJpegFile('create-false.jpg'),
-    ])->assertUnprocessable()->assertJsonValidationErrors(['document']);
-
-    config()->set('manual.ocr_analysis_enabled', true);
     $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
-        'title' => 'フラグ有効テスト',
+        'title' => '画像アップロードテスト',
         'category' => null,
         'document' => fakeJpegFile('create-true.jpg'),
     ])->assertRedirect();
@@ -114,9 +91,8 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     expect(SourceDocument::query()->where('mime', 'image/jpeg')->count())->toBe(1);
 });
 
-test('webp/gif はフラグ true でも引き続き拒否される (回帰)', function (): void {
+test('webp/gif は引き続き拒否される (回帰)', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     foreach (['image/webp' => 'sop.webp', 'image/gif' => 'sop.gif'] as $mime => $name) {
@@ -131,7 +107,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
 
 test('画像の容量上限超過 (source_document_image_max_bytes 基準) は 422', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     config()->set('manual.source_document_image_max_bytes', 1024); // 1KB に絞る
     [, $owner, $project, $manual] = ocrUploadContext();
 
@@ -145,7 +120,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
 
 test('容量上限の判定材料は sniff MIME である (偽装で迂回できない)', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
     config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
     [, $owner, $project, $manual] = ocrUploadContext();
@@ -166,7 +140,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     // 'image/jpeg' を返し、getClientMimeType()/getClientOriginalExtension() は 'application/pdf'
     // 側を返す (この乖離を実ファイルで固定する)。
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     config()->set('manual.source_document_image_max_bytes', 1024); // 画像上限 1KB
     config()->set('manual.source_document_max_bytes', 5 * 1024 * 1024); // 既定上限 5MB
     [, $owner, $project, $manual] = ocrUploadContext();
@@ -187,125 +160,80 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
     @unlink($tmpPath);
 });
 
-test('公開面の一貫性: FormRequest / Service / Inertia Props がフラグに応じて同じ集合を表す', function (): void {
+test('公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す', function (): void {
     Storage::fake();
     [, $owner, $project] = ocrUploadContext();
 
-    foreach ([false, true] as $flag) {
-        config()->set('manual.ocr_analysis_enabled', $flag);
-        // 各分岐を独立したマニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
-        $httpManual = VideoManual::factory()->forProject($project)->create();
-        $serviceManual = VideoManual::factory()->forProject($project)->create();
-
-        // FormRequest: jpg 受理可否
-        $response = $this->actingAs($owner)->postJson(
-            "/projects/{$project->id}/manuals/{$httpManual->id}/source-documents",
-            ['document' => fakeJpegFile("sop-{$flag}.jpg")],
-        );
-        if ($flag) {
-            $response->assertRedirect();
-        } else {
-            $response->assertUnprocessable();
-        }
-
-        // Service: allowedMimeTypes に image/jpeg が含まれるか (appendDocument の成否で確認)
-        if ($flag) {
-            $doc = app(SourceDocumentService::class)->appendDocument(
-                $serviceManual,
-                fakeJpegFile("service-{$flag}.jpg"),
-            );
-            expect($doc->mime)->toBe('image/jpeg');
-        } else {
-            expect(fn () => app(SourceDocumentService::class)->appendDocument(
-                $serviceManual,
-                fakeJpegFile("service-{$flag}.jpg"),
-            ))->toThrow(ValidationException::class);
-        }
-
-        // Inertia Props (詳細画面): sourceDocumentAccept / imageSourceDocumentsEnabled
-        $showResponse = $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]));
-        $showResponse->assertInertia(fn (Assert $page) => $page
-            ->where('imageSourceDocumentsEnabled', $flag)
-            ->where('sourceDocumentAccept', $flag
-                ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
-                : '.pdf,.xlsx,.xls,.txt'));
-
-        // Inertia Props (作成画面): 同じ単一の情報源を経由する 3 件
-        $createResponse = $this->actingAs($owner)->get(route('projects.manuals.create', [$project]));
-        $createResponse->assertInertia(fn (Assert $page) => $page
-            ->where('imageSourceDocumentsEnabled', $flag)
-            ->where('sourceDocumentAccept', $flag
-                ? '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'
-                : '.pdf,.xlsx,.xls,.txt')
-            ->where('sourceDocumentFormatsLabel', $flag
-                ? 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'
-                : 'PDF・Excel・テキスト形式'));
-
-        // 面をまたいだ同値性。リテラル pin は「両面とも同じ間違い」を検出できるが、
-        // 「面ごとに違う」ケースはこの比較が担う。
-        // **比較対象は両面に存在する 2 件だけ**である: sourceDocumentFormatsLabel は
-        // 詳細画面に形式ラベルを表示する UI が無く props を配っていないため含めない。
-        $sharedKeys = ['sourceDocumentAccept', 'imageSourceDocumentsEnabled'];
-        $showProps = Assert::fromTestResponse($showResponse)->toArray();
-        $createProps = Assert::fromTestResponse($createResponse)->toArray();
-        foreach ($sharedKeys as $key) {
-            expect($createProps[$key] ?? null)->toBe(
-                $showProps[$key] ?? null,
-                "作成画面と詳細画面で props {$key} が食い違っている (単一の情報源を経由していない)",
-            );
-        }
-    }
+    // 各分岐を独立したマニュアルで検証する (1 手順書 1 枚制約の干渉を避ける)
+    $httpManual = VideoManual::factory()->forProject($project)->create();
+    $serviceManual = VideoManual::factory()->forProject($project)->create();
+
+    // FormRequest 境界: jpg が StoreSourceDocumentRequest の mimes: ルールを通過する
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$httpManual->id}/source-documents",
+        ['document' => fakeJpegFile('sop.jpg')],
+    )->assertRedirect();
+
+    // Service 境界: appendDocument() が例外を投げずに image/jpeg の SourceDocument を返す
+    $doc = app(SourceDocumentService::class)->appendDocument(
+        $serviceManual,
+        fakeJpegFile('service.jpg'),
+    );
+    expect($doc->mime)->toBe('image/jpeg');
+
+    // Inertia Props 境界: create()/show() 両方の sourceDocumentAccept が画像込み固定値で一致する
+    $showResponse = $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $httpManual]));
+    $showResponse->assertInertia(fn (Assert $page) => $page
+        ->where('sourceDocumentAccept', '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png'));
+
+    $createResponse = $this->actingAs($owner)->get(route('projects.manuals.create', [$project]));
+    $createResponse->assertInertia(fn (Assert $page) => $page
+        ->where('sourceDocumentAccept', '.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png')
+        ->where('sourceDocumentFormatsLabel', 'PDF・Excel・テキスト形式、または JPEG・PNG の画像'));
+
+    // 面をまたいだ同値性 (作成画面と詳細画面が同じ情報源を経由していること)
+    $showProps = Assert::fromTestResponse($showResponse)->toArray();
+    $createProps = Assert::fromTestResponse($createResponse)->toArray();
+    expect($createProps['sourceDocumentAccept'] ?? null)->toBe(
+        $showProps['sourceDocumentAccept'] ?? null,
+        '作成画面と詳細画面で props sourceDocumentAccept が食い違っている (単一の情報源を経由していない)',
+    );
 });
 
 /*
  * StoreVideoManualRequest (作成と同時のアップロード経路) の 422 出力契約。
  *
  * **このテストが保証する範囲 (誇張しない)**: 固定できるのは両エンドポイントの 422 出力契約
- * である。「formatsLabel() を実際に呼んでいること」は保証しない — 置換前の三項演算子を
- * 残しても両フラグで同じ文言を返すため本テストは緑になる。中央メソッドへの構造的な結線は
+ * である。「formatsLabel() を実際に呼んでいること」は保証しない — 置換前の文字列を
+ * 残しても同じ文言を返すため本テストは緑になる。中央メソッドへの構造的な結線は
  * コードレビューで確認する。逆に **文面が経路ごとにずれたら** 本テストが検出する。
  */
-test('作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す (両フラグ)', function (): void {
+test('作成と同時のアップロード経路も後付け経路と同じ 422 文言を返す', function (): void {
     Storage::fake();
     [, $owner, $project, $manual] = ocrUploadContext();
+    $makeFile = fn (): UploadedFile => UploadedFile::fake()->create('rejected.heic', 10, 'image/heic');
+    $expected = '対応していないファイル形式です。'
+        .AcceptedSourceDocumentTypes::formatsLabel()
+        .'でアップロードし直してください。';
 
-    $cases = [
-        // フラグ false: jpeg は受理外
-        [false, fn (): UploadedFile => fakeJpegFile('rejected.jpg')],
-        // フラグ true: heic は受理外 (画像を受理してもなお外)
-        [true, fn (): UploadedFile => UploadedFile::fake()->create('rejected.heic', 10, 'image/heic')],
-    ];
-
-    foreach ($cases as [$flag, $makeFile]) {
-        config()->set('manual.ocr_analysis_enabled', $flag);
-
-        // 期待文はリテラルを書かず中央ラベルから組み立てる (文面そのものの pin は Unit テスト側)
-        $expected = '対応していないファイル形式です。'
-            .AcceptedSourceDocumentTypes::formatsLabel()
-            .'でアップロードし直してください。';
-
-        // 作成と同時 (StoreVideoManualRequest): title は有効値を渡し document.mimes だけを発火させる
-        $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
-            'title' => '422 文言の経路差テスト',
-            'category' => null,
-            'document' => $makeFile(),
-        ])->assertUnprocessable()
-            ->assertJsonValidationErrors(['document'])
-            ->assertJsonFragment(['document' => [$expected]]);
-
-        // 後付け (StoreSourceDocumentRequest): 同じ文面であること
-        $this->actingAs($owner)->postJson(
-            "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
-            ['document' => $makeFile()],
-        )->assertUnprocessable()
-            ->assertJsonValidationErrors(['document'])
-            ->assertJsonFragment(['document' => [$expected]]);
-    }
+    $this->actingAs($owner)->postJson("/projects/{$project->id}/manuals", [
+        'title' => '422 文言の経路差テスト',
+        'category' => null,
+        'document' => $makeFile(),
+    ])->assertUnprocessable()
+        ->assertJsonValidationErrors(['document'])
+        ->assertJsonFragment(['document' => [$expected]]);
+
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
+        ['document' => $makeFile()],
+    )->assertUnprocessable()
+        ->assertJsonValidationErrors(['document'])
+        ->assertJsonFragment(['document' => [$expected]]);
 });
 
 test('画像の手順書は 1 枚まで (2 枚目は明示的に拒否される)', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     $this->actingAs($owner)->post(
@@ -323,7 +251,6 @@ function fakePngFile(string $name = 'sop.png'): UploadedFile
 
 test('非画像 (PDF) の 2 枚目は画像制約の対象外で受理される', function (): void {
     Storage::fake();
-    config()->set('manual.ocr_analysis_enabled', true);
     [, $owner, $project, $manual] = ocrUploadContext();
 
     $this->actingAs($owner)->post(
diff --git a/tests/Feature/Projects/SourceDocumentUploadTest.php b/tests/Feature/Projects/SourceDocumentUploadTest.php
index bfcc5f6d..b21ac690 100644
--- a/tests/Feature/Projects/SourceDocumentUploadTest.php
+++ b/tests/Feature/Projects/SourceDocumentUploadTest.php
@@ -110,28 +110,28 @@ function fakeTxtFile(string $name = 'sop.txt', string $body = "手順1 部品を
     expect(SourceDocument::query()->count())->toBe(0);
 });
 
-test('許可外拡張子 (png) は 422', function (): void {
+test('許可外拡張子 (gif) は 422', function (): void {
+    // png/jpg/jpeg は画像・スキャン SOP の OCR 対応 (常時有効) により受理対象になったため、
+    // ここでは許可集合に残らない gif を「許可外拡張子」の代表として使う
+    // (AcceptedSourceDocumentTypesTest の「webp/gif は含まれない」pin と対応する)。
     Storage::fake();
     [, $owner, $project, $manual] = sourceDocumentTestContext();
 
-    $png = UploadedFile::fake()->createWithContent(
-        'image.png',
-        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '',
-    );
+    $gif = UploadedFile::fake()->create('image.gif', 10, 'image/gif');
 
     $this->actingAs($owner)->postJson(
         "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
-        ['document' => $png],
+        ['document' => $gif],
     )->assertUnprocessable()->assertJsonValidationErrors(['document']);
 });
 
-test('拡張子偽装 (.pdf だが内容が PNG) は 422 (polyglot 対策)', function (): void {
+test('拡張子偽装 (.pdf だが内容が GIF) は 422 (polyglot 対策)', function (): void {
     Storage::fake();
     [, $owner, $project, $manual] = sourceDocumentTestContext();
 
     // fake UploadedFile は getMimeType() が宣言 mime を返すため、「内容 sniff が
-    // image/png を検出した」状況を mime 指定で再現する (.pdf 拡張子 + PNG 内容)
-    $polyglot = UploadedFile::fake()->create('fake.pdf', 10, 'image/png');
+    // image/gif を検出した」状況を mime 指定で再現する (.pdf 拡張子 + GIF 内容)
+    $polyglot = UploadedFile::fake()->create('fake.pdf', 10, 'image/gif');
 
     $this->actingAs($owner)->postJson(
         "/projects/{$project->id}/manuals/{$manual->id}/source-documents",
@@ -144,7 +144,7 @@ function fakeTxtFile(string $name = 'sop.txt', string $body = "手順1 部品を
 test('Service の内容 sniff 二層目: 許可外 mime は appendDocument が拒否する (行・ファイルなし)', function (): void {
     Storage::fake();
     [, , , $manual] = sourceDocumentTestContext();
-    $polyglot = UploadedFile::fake()->create('fake.pdf', 10, 'image/png');
+    $polyglot = UploadedFile::fake()->create('fake.pdf', 10, 'image/gif');
 
     expect(fn () => app(SourceDocumentService::class)->appendDocument($manual, $polyglot))
         ->toThrow(ValidationException::class);
diff --git a/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php b/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php
index 774d7c1b..2527ca8b 100644
--- a/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php
+++ b/tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php
@@ -6,27 +6,11 @@
 
 /*
  * AcceptedSourceDocumentTypes (画像・スキャン SOP の OCR 対応): 受理する SourceDocument
- * 形式の唯一の情報源。フラグ true/false それぞれの extensions()/mimes()/
- * acceptAttribute()/imagesEnabled() を固定する。
+ * 形式の唯一の情報源。旧 `manual.ocr_analysis_enabled` フラグは撤去済みで、
+ * 画像受理は常時有効。extensions()/mimes()/acceptAttribute() を固定する。
  */
 
-test('フラグ false のとき画像を含まない', function (): void {
-    config()->set('manual.ocr_analysis_enabled', false);
-
-    expect(AcceptedSourceDocumentTypes::extensions())->toBe(['pdf', 'xlsx', 'xls', 'txt']);
-    expect(AcceptedSourceDocumentTypes::mimes())->toBe([
-        'application/pdf',
-        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
-        'application/vnd.ms-excel',
-        'text/plain',
-    ]);
-    expect(AcceptedSourceDocumentTypes::acceptAttribute())->toBe('.pdf,.xlsx,.xls,.txt');
-    expect(AcceptedSourceDocumentTypes::imagesEnabled())->toBeFalse();
-});
-
-test('フラグ true のとき画像 (jpg/jpeg/png) を含む', function (): void {
-    config()->set('manual.ocr_analysis_enabled', true);
-
+test('画像 (jpg/jpeg/png) を含む (常時有効)', function (): void {
     expect(AcceptedSourceDocumentTypes::extensions())->toBe(['pdf', 'xlsx', 'xls', 'txt', 'jpg', 'jpeg', 'png']);
     expect(AcceptedSourceDocumentTypes::mimes())->toBe([
         'application/pdf',
@@ -37,48 +21,32 @@
         'image/png',
     ]);
     expect(AcceptedSourceDocumentTypes::acceptAttribute())->toBe('.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png');
-    expect(AcceptedSourceDocumentTypes::imagesEnabled())->toBeTrue();
 });
 
-test('formatsLabel はフラグ false のとき画像を含まない文面を返す', function (): void {
-    config()->set('manual.ocr_analysis_enabled', false);
-
-    expect(AcceptedSourceDocumentTypes::formatsLabel())->toBe('PDF・Excel・テキスト形式');
-});
-
-test('formatsLabel はフラグ true のとき画像を含む文面を返す', function (): void {
-    config()->set('manual.ocr_analysis_enabled', true);
-
+test('formatsLabel は画像を含む文面を返す (常時有効)', function (): void {
     expect(AcceptedSourceDocumentTypes::formatsLabel())
         ->toBe('PDF・Excel・テキスト形式、または JPEG・PNG の画像');
 });
 
 /*
  * ラベルの前提の pin。formatsLabel() は拡張子リストから機械導出せず、法務確認を経た
- * 2 文をそのまま持つ。したがって「拡張子集合が変わったのにラベルが据え置き」という
+ * 文をそのまま持つ。したがって「拡張子集合が変わったのにラベルが据え置き」という
  * 乖離は本テストだけが検出できる。
  *
  * 集合の差分ではなく **順序込みの完全一致** で書く: acceptAttribute() は extensions() の
  * 順序に依存して文字列を組むため、集合比較では表示順の変更を見逃す。
  */
-test('前提の pin: 基底拡張子集合と画像込み拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)', function (): void {
+test('前提の pin: 拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)', function (): void {
     $failure = 'ラベル (AcceptedSourceDocumentTypes::formatsLabel) の見直しが必要です。'
         .'受理拡張子の集合または順序が変わったのに、人間向けの文面は機械導出していないため追随しません。';
 
-    config()->set('manual.ocr_analysis_enabled', false);
     expect(config()->array('manual.source_document_mimes'))
         ->toBe(['pdf', 'xlsx', 'xls', 'txt'], $failure);
-    expect(AcceptedSourceDocumentTypes::extensions())
-        ->toBe(['pdf', 'xlsx', 'xls', 'txt'], $failure);
-
-    config()->set('manual.ocr_analysis_enabled', true);
     expect(AcceptedSourceDocumentTypes::extensions())
         ->toBe(['pdf', 'xlsx', 'xls', 'txt', 'jpg', 'jpeg', 'png'], $failure);
 });
 
-test('webp/gif はフラグに関わらず含まれない (スコープ外)', function (): void {
-    config()->set('manual.ocr_analysis_enabled', true);
-
+test('webp/gif は含まれない (スコープ外)', function (): void {
     expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('webp');
     expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('gif');
     expect(AcceptedSourceDocumentTypes::mimes())->not->toContain('image/webp');
diff --git a/tests/js/components/features/manual/SourceDocumentUpload.test.ts b/tests/js/components/features/manual/SourceDocumentUpload.test.ts
index df5d4f23..b28eeaac 100644
--- a/tests/js/components/features/manual/SourceDocumentUpload.test.ts
+++ b/tests/js/components/features/manual/SourceDocumentUpload.test.ts
@@ -3,10 +3,10 @@ import { cleanup, render, screen } from "@testing-library/svelte";
 import SourceDocumentUpload from "@/components/features/manual/SourceDocumentUpload.svelte";
 
 /*
- * SOP アップロード (画像・スキャン SOP の OCR 対応。施策 1/10):
+ * SOP アップロード (画像・スキャン SOP の OCR 対応。常時有効):
  * - accept 属性はサーバ Props (sourceDocumentAccept) をそのまま使う (フロントで解析しない)
- * - 送信案内 (外部 LLM provider への送信) は imageSourceDocumentsEnabled の真偽に関わらず常時表示
- * - OCR 固有の警告・1 枚制約の明示は imageSourceDocumentsEnabled=true のときだけ表示
+ * - 送信案内 (外部 LLM provider への送信) は常時表示
+ * - OCR 固有の警告・1 枚制約の明示も常時表示 (旧 imageSourceDocumentsEnabled フラグは撤去済み)
  */
 
 vi.mock("@inertiajs/svelte", () => ({
@@ -24,28 +24,11 @@ const baseProps = {
 };
 
 describe("SourceDocumentUpload", () => {
-    it("imageSourceDocumentsEnabled=false では accept が画像を含まず OCR 固有文言が出ない", () => {
-        render(SourceDocumentUpload, {
-            props: {
-                ...baseProps,
-                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt",
-                imageSourceDocumentsEnabled: false,
-            },
-        });
-
-        const input = screen.getByTestId("source-document-input") as HTMLInputElement;
-        expect(input.accept).toBe(".pdf,.xlsx,.xls,.txt");
-        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
-        // 一般的な外部送信案内は false のときも表示され続ける
-        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent("外部の LLM provider");
-    });
-
-    it("imageSourceDocumentsEnabled=true では accept に画像拡張子を含み OCR 固有文言が出る", () => {
+    it("accept に画像拡張子を含み OCR 固有文言が出る", () => {
         render(SourceDocumentUpload, {
             props: {
                 ...baseProps,
                 sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
-                imageSourceDocumentsEnabled: true,
             },
         });
 
@@ -67,7 +50,6 @@ describe("SourceDocumentUpload", () => {
             props: {
                 ...baseProps,
                 sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
-                imageSourceDocumentsEnabled: true,
             },
         });
 
diff --git a/tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts b/tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts
index 6e984092..ad06b1a1 100644
--- a/tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts
+++ b/tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts
@@ -5,8 +5,7 @@ import { normalizedTextOf } from "../../../support/normalizeText";
 
 /*
  * SOP アップロードの外部送信案内 (文言の唯一の出現箇所。作成画面と詳細画面が共有する):
- * - 一般案内はフラグの真偽に関わらず常時表示 (テキスト・Excel・通常 PDF にも等しく当てはまる事実)
- * - OCR 固有警告だけを imageSourceDocumentsEnabled で出し分ける
+ * - 一般案内・OCR 固有警告ともに常時表示 (旧 imageSourceDocumentsEnabled フラグは撤去済み)
  * - 文言は **全文一致** で固定する (部分一致では文面の劣化を見逃す)
  */
 
@@ -23,17 +22,8 @@ afterEach(() => {
 });
 
 describe("SourceDocumentUploadNotice", () => {
-    it("imageSourceDocumentsEnabled=false では一般案内だけを全文どおり描画する", () => {
-        render(SourceDocumentUploadNotice, { props: { imageSourceDocumentsEnabled: false } });
-
-        expect(normalizedTextOf(screen.getByTestId("source-document-send-notice"))).toBe(
-            SEND_NOTICE,
-        );
-        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
-    });
-
-    it("imageSourceDocumentsEnabled=true では OCR 固有警告も全文どおり描画する", () => {
-        render(SourceDocumentUploadNotice, { props: { imageSourceDocumentsEnabled: true } });
+    it("一般案内と OCR 固有警告を全文どおり描画する", () => {
+        render(SourceDocumentUploadNotice, {});
 
         expect(normalizedTextOf(screen.getByTestId("source-document-send-notice"))).toBe(
             SEND_NOTICE,
diff --git a/tests/js/pages/ManualsCreate.test.ts b/tests/js/pages/ManualsCreate.test.ts
index 32f61df1..968548a7 100644
--- a/tests/js/pages/ManualsCreate.test.ts
+++ b/tests/js/pages/ManualsCreate.test.ts
@@ -19,11 +19,10 @@ const baseProps = {
         { id: 1, name: "準備作業" },
         { id: 2, name: "仕上げ" },
     ],
-    // 受理形式・画像対応の出し分けはサーバの AcceptedSourceDocumentTypes 由来の props に従う
+    // 受理形式はサーバの AcceptedSourceDocumentTypes 由来の props に従う
     // (フロント側で accept 文字列を解析して画像対応可否を判定しない)
-    sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt",
-    imageSourceDocumentsEnabled: false,
-    sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式",
+    sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
+    sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式、または JPEG・PNG の画像",
 };
 
 /** FormField が描画する help 段落を入力要素から引く (FormField の id 規約 `{id}-help`)。 */
@@ -72,31 +71,9 @@ describe("Manuals/Create", () => {
         expect(screen.queryByRole("option", { name: "準備作業" })).toBeNull();
     });
 
-    it("手順書 (SOP) のファイル入力は accept をサーバ props からそのまま受ける (フラグ false 相当)", () => {
+    it("手順書 (SOP) のファイル入力は accept をサーバ props からそのまま受ける (画像拡張子を含む)", () => {
         render(Create, { props: baseProps });
 
-        const input = screen.getByTestId("manual-document-input");
-        expect(input).toBeInTheDocument();
-        expect(input.getAttribute("type")).toBe("file");
-        expect(input.getAttribute("accept")).toBe(".pdf,.xlsx,.xls,.txt");
-
-        // 一般的な外部送信案内はフラグの真偽に関わらず常時表示
-        expect(screen.getByTestId("source-document-send-notice")).toHaveTextContent(
-            "外部の LLM provider",
-        );
-        expect(screen.queryByTestId("source-document-image-notice")).toBeNull();
-    });
-
-    it("フラグ true 相当の props では accept に画像拡張子を含み OCR 固有警告が出る", () => {
-        render(Create, {
-            props: {
-                ...baseProps,
-                sourceDocumentAccept: ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
-                imageSourceDocumentsEnabled: true,
-                sourceDocumentFormatsLabel: "PDF・Excel・テキスト形式、または JPEG・PNG の画像",
-            },
-        });
-
         expect(screen.getByTestId("manual-document-input").getAttribute("accept")).toBe(
             ".pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png",
         );
@@ -120,7 +97,7 @@ describe("Manuals/Create", () => {
         render(Create, { props: baseProps });
 
         expect(normalizedTextOf(helpTextOf(screen.getByTestId("manual-document-input")))).toBe(
-            "PDF・Excel・テキスト形式。アップロードすると AI 解析でシナリオを生成できます。",
+            "PDF・Excel・テキスト形式、または JPEG・PNG の画像。アップロードすると AI 解析でシナリオを生成できます。",
         );
     });
 
@@ -129,9 +106,7 @@ describe("Manuals/Create", () => {
      * 案内へ直接効く親子構造を固定する (詳細画面側と同じ判定方法)。
      */
     it("案内は file input より前にあり、作成 form の直下に置かれる", () => {
-        const { container } = render(Create, {
-            props: { ...baseProps, imageSourceDocumentsEnabled: true },
-        });
+        const { container } = render(Create, { props: baseProps });
 
         const form = container.querySelector("form");
         const sendNotice = screen.getByTestId("source-document-send-notice");

```

### 残存確認 (grep) の結果

```
app/Support/Manual/AcceptedSourceDocumentTypes.php:13: * 画像・スキャン SOP の OCR 対応 (旧 `manual.ocr_analysis_enabled` フラグ) は
config/manual.php:58:    // `manual.ocr_analysis_enabled` はオーナー決定により撤去済み。
docs/TODO-closed.md:238:| T234 | 画像・スキャン SOP の OCR 対応。テキスト層の無い PDF・画像 (jpg/png) を LLM のマルチモーダル読み取りで直接構造化する OCR 経路を、既存の AI 解析パイプライン (extract → decompose → generate) の 1 段目に追加した (OCR 専用エンジンは採らず既存の LLM 経路を延長。doc/03 §3.4 の実測に基づく)。**施策 1**: `AcceptedSourceDocumentTypes` を「受理形式」の単一の情報源にし、FormRequest / Service / Inertia Props が同じ集合に従う。容量上限は `SourceDocumentSizeLimit` (サーバー側 sniff mime だけで判定。クライアント申告での迂回を防ぐ)。画像は 1 手順書 1 枚まで。**施策 2**: `AnalysisFailureReason` enum で抽出失敗理由を型で持つ。**施策 3**: 検証済み媒体 DTO (`ImageAnalysisMediaData`/`PdfAnalysisMediaData`。private constructor + `fromValidated()`) と `AnalysisMediaValidator` (画像は persisted mime と `getimagesizefromstring()` の mime 一致確認、PDF は `finfo` による実バイト sniff。容量・画素数・辺長・ページ数の上限判定)。**施策 4**: 窓口 `PromptDefense::loadWithMedia()` を新設 (既存 `load()` は変更しない)。vendor prompt (`TextPrompt`) を継承する無名クラスを窓口ファイル内だけで宣言・生成し (宣言と生成が同一言語構文である点を利用して生成箇所の目録を不要にする)、コンストラクタ内で `Prompt::load()` と同じ初期化 (templatePath 代入→`loadMetadata()`) を行うことで provider/model/system prompt/canary/帰属を正しく引き継ぐ。**施策 5**: `sop-extract-media.yaml` + `SopExtractFromMediaPrompt` (媒体向け防御指示 4 項目、判読不能箇所は日本語比率を汚さない ASCII マーカー `[UNREADABLE]`)。**施策 6**: `AnalysisPipeline::runExtractStage()` が経路決定 (`resolveExtractInput()`) と LLM 呼び出しを包み、終端ログ (`route`/`source_mime`/`outcome`/`failure_category`/`media_size_bytes`/`media_pages`/`media_pixels`) を `run()` の 1 回の実行につきちょうど 1 回だけ出す。**施策 7**: `JapaneseTextRatio` (SJIS 復元判定と共有) + `AnalysisAcceptanceGate` (日本語比率 + 実質空判定の 2 条件。1 文字の手順が比率 1.0 で通過する欠陥を Codex レビューで発見し実質空判定を追加)。**施策 8**: `PromptWindowScanner`/`PromptWindowRule` を拡張し、vendor 媒体型 (`Image`/`Document`) の構築・vendor prompt 継承・vendor 媒体型の subclass 化・`loadWithMedia`/`fromValidated` の呼び出しを deny-by-default で pin する 5 ルールを追加。中括弧による動的メソッド名 (`Image::{$method}(...)`) と配列 callable の構築 (`[Image::class, 'method']`) による迂回も、データフロー解析なしで構文レベルの検出器 (`dynamicMethodNameCalls()`/`arrayCallableConstructions()`) を追加して塞いだ (Codex レビューで発見)。**施策 9**: Anthropic 公式ドキュメント (Vision/PDF support、2026-08-19 参照) の一次情報調査に基づき、画像辺長 8000px・PDF ページ数 100 (pin モデルの context 200k 相当) という provider hard limit を送信前上限へ直接反映し、token 見積り式 (ページ/画素数あたりの見積り token 数) を `AnalysisTokenBudgetInvariantTest` へ追加。**施策 10**: アップロード画面に受理形式・外部送信案内 (一般案内は常時表示、画像固有警告はフラグ有効時のみ) を追加。**施策 11**: `config('manual.ocr_analysis_enabled')` (既定 false) が画像受理と OCR フォールバックの両方を一貫してゲートし、`docs/rollout-checklists.md` に法務確認・画像内 prompt injection 手動評価の rollout 前提条件を明文化。Codex 実装レビューは 3 ラウンド実施 (gpt-5.6-sol / reasoning=high)。Round 1: PDF 側の MIME sniff 欠落 (画像側との非対称)・容量上限テストの頑健性・HEIC 拒否文言の欠如・ログ回数の主張過大 (「ジョブにつき」ではなく「run() の 1 回につき」が正確) 等を是正。Round 2: OCR 成功条件が日本語比率だけで実質空判定を欠き `tooShort` を実質迂回できる欠陥を是正 (施策 7 に反映)。Round 3: 静的 gate の迂回経路 (中括弧動的メソッド名・配列 callable の構築) を追加の検出器で解消 (施策 8 に反映)。Round 3 は合議上限 (3 ラウンド) に達したため、対応内容を手動検証で裏取りして実装完了とした (devnotes/20260819-1053-sop-image-ocr-support/ に全ラウンドの記録)。検証: `composer test` 6071 tests / 6069 passed / 2 skipped / 0 failed (29054 assertions)、`composer phpstan` level 10 No errors、`vendor/bin/pint --test` passed、`pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` green、`pnpm test` 166 files pass、`pnpm test:packages` 106 tests pass | backend | 2026-08-19 14:30 |
docs/TODO-closed.md:248:| T235 | 新規動画作成画面の SOP ファイル入力を受理形式の単一情報源 (`App\Support\Manual\AcceptedSourceDocumentTypes`) へ揃えた。作成画面 (Manuals/Create) だけが accept 属性と案内文言を直書きしていたため、画像・スキャン SOP の OCR 対応フラグ (`manual.ocr_analysis_enabled`) を立てても主導線から画像を投入できず、外部送信の開示も欠けていた。**施策 1**: `AcceptedSourceDocumentTypes::formatsLabel()` を追加し、2 つの FormRequest (`StoreSourceDocumentRequest` / `StoreVideoManualRequest`) が複写していた 422 文言の三項演算子を中央へ寄せた (文面は拡張子リストから機械導出せず承認済みの 2 文をそのまま持ち、基底拡張子集合と画像拡張子集合を現在値ちょうどで pin する前提検査が乖離を検出する)。**施策 2**: `VideoManualController::create()` の Inertia props へ sourceDocumentAccept / imageSourceDocumentsEnabled / sourceDocumentFormatsLabel の 3 件を追加し、ダイアログに出る形式とサーバが受理する形式を構造的に一致させた。**施策 3**: 外部送信の案内を `SourceDocumentUploadNotice.svelte` へ集約し、文言の物理的な出現箇所を 1 つにした (作成画面と詳細画面が共有)。**施策 4**: 作成画面の accept 直書きと help 文言を props 由来へ揃えた。**施策 5**: 再発防止として `resources/js` 配下の `.svelte` の file input と accept 供給元の目録 (`tests/js/support/file-input-accept-inventory.ts` + `tests/js/architecture/file-input-accept-source-inventory.test.ts`) を新設し、deny-by-default + 両方向 + 件数の完全一致で強制した (実測構文 static-text / expression は走査器が svelte の AST から実測し、供給元 server-prop / client-owned は人が 30 文字以上の理由付きで宣言する。AGENTS.md ドメイン固有規約 20 として明文化)。詳細設計から 1 点逸脱 — 設計は「走査器の診断は無条件で違反 (免除の概念は無い)」としていたが、その前提 (実リポジトリの診断 0 件) が実測で成り立たず (`components/atoms/Input.svelte` が type 指定と残り属性の一括転送を持ち、静的には file input になりうる形が正当に実在した)、免除できる理由を属性の一括転送の 1 つに限った上で件数の完全一致つき免除目録で扱う形にし、解析失敗と accept 欠落は免除不可とした。保証しない範囲 (同一ファイル・同一理由・同数の置き換えは検出しない) は走査器と目録の docblock に明記し、負のコントロールで機械 pin した。テストファースト: 施策 1/2 で 4 件赤 (`formatsLabel()` 未定義 3 件 / 作成画面 props 不在 1 件) から 18 tests green、施策 3 は新規 component の import 解決失敗で赤から green、施策 4 で 4 件赤 (props 由来の accept / 案内の出し分け / help 全文一致 / 配置) から 9 tests green、施策 5 はモジュール不在で赤から 62 tests green。加えて目録を空にすると gate が赤くなること (deny-by-default の実効) を実装前後 2 回実測して復元した。Codex 実装レビューは Round 3 で APPROVED (Round 1 の Critical 4 件 = 診断免除の理由が広すぎて解析失敗と accept 欠落まで免除できた / 免除の鍵が実装より強い精度を主張していた / 属性名の照合が大小文字を区別していて大文字の type 指定が母集団から漏れる fail-open / それらを捕捉する負例が無い、Round 2 の Critical 1 件 = 大小文字違いの重複属性は svelte の重複検査を通るのに走査器が正規化後の先頭だけ採って後続を無言で捨てていた fail-open、をいずれも実測と負例付きで修正。Warning 3 件と Suggestion 1 件も対応済み)。検証 (main 取り込み後に再実行): `composer test` 6153 tests / 6151 passed / 2 skipped / 5 risky / 0 failed (29396 assertions)、`composer phpstan` level 10 No errors、`vendor/bin/pint --test` passed、`pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` green、`pnpm test` 172 files / 2351 tests passed、`pnpm test:packages` 10 files / 106 tests passed | frontend | 2026-08-21 02:02 |
docs/TODO-closed.md:255:| T241 | OCR 解析ロールアウト (施策 11) の前提条件記録。オーナーの決定 (2026-08-21) 2 件を `docs/rollout-checklists.md` へ日付・出所付きで記録した。**法務確認 (項目 1)**: オーナー自身が確認主体となり、現行の利用規約・顧客契約の文言が画像を含む文書の外部送信と「矛盾しない」ことを確認した (項目 1 本来の文言「適切」「カバー済み」より弱い結論であることを明記し、その確認をもって責任者であるオーナー自身が項目 1 を完了として扱うと決定した、と正確に記録する)。アップロード画面の送信案内文言 (`source-document-send-notice` / `source-document-image-notice`) も同様に「矛盾しない」ことを確認した。**画像内 prompt injection の手動評価 (項目 2)**: 評価セットの用意・突合・責任者承認のいずれも実施していない。オーナーが、自身が責任者として持つ判断権限に基づき、本ロールアウトに限り本項目を実施しないことを明示的に決定した (リスクを認識した上での意図的な例外決定であり、見落としではない。「評価済みで合格した」という記録は書かない)。項目 3 (再評価対象の棚卸し) の将来の再評価義務はこの例外決定で免除されないことも明記した。**フラグの有効化について**: `config('manual.ocr_analysis_enabled')` の既定値 (`env('MANUAL_OCR_ANALYSIS_ENABLED', false)`) は変更しない (この既定値を pin する既存テストも無いことを確認済み)。施策 11 の設計どおり、`true` への切替は本番環境変数の設定という単独の運用操作であり、コード変更を伴わない。本リポジトリにはデプロイ定義が存在しない (`deploy/` / terraform / k8s / CI デプロイ job のいずれも無い。AGENTS.md の `route:cache` 運用要件節に既述) ため、この環境変数設定・`config:cache` 再生成・プロセス再起動はリポジトリ内の変更としては実行できず、本番環境を運用する側が承認記録を踏まえて人手で実施する残作業として引き継ぐ。Codex 実装レビューは 3 ラウンド実施 (gpt-5.6-sol / reasoning=high)。Round 1: 評価未実施を rollout gate の充足と混同していた誤りを是正。Round 2: 項目 3 の再評価義務の無効化・法務確認の確認主体の記録不足を是正。Round 3 (合議上限) で新たに指摘された、法務確認結果「矛盾しない」を項目 1 本来の基準より弱いまま完了扱いしていた点と、チェックリスト冒頭の総論と項目 2 の記録内容の矛盾を是正した。残る Warning (承認主体の個人識別・原記録への参照) は本タスクへの入力に存在しない情報であり、作文せず「出所についての限界」として明記する対応とした (`devnotes/20260821-2017-todo-T241/` に全ラウンドの記録)。ドキュメントのみの変更のため、コード・設定・テストファイルの変更は無い。検証: `composer test` 6402 tests / 6400 passed / 2 skipped / 5 risky / 0 failed (30684 assertions)、`composer phpstan` level 10 No errors、`vendor/bin/pint --test` passed、`pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` green、`pnpm test` 173 files passed、`pnpm test:packages` 10 files / 106 tests passed | backend | 2026-08-21 20:39 |
docs/architecture.md:2779:  が単一の情報源)。旧 rollout gate `config('manual.ocr_analysis_enabled')` はオーナー決定
docs/rollout-checklists.md:7:## 画像・スキャン SOP の OCR 対応 (`MANUAL_OCR_ANALYSIS_ENABLED`)
docs/rollout-checklists.md:10:> rollout gate `config('manual.ocr_analysis_enabled')` は、本節が定める前提条件 (項目 1 の
docs/rollout-checklists.md:31:> `MANUAL_OCR_ANALYSIS_ENABLED` の設定が残っていても、コードがこのキーを参照しなくなった
docs/rollout-checklists.md:35:`config('manual.ocr_analysis_enabled')` (既定 `false`) を `true` にする前に、以下の
docs/rollout-checklists.md:99:`MANUAL_OCR_ANALYSIS_ENABLED=true` への切替を進めることを決定したものである。
docs/rollout-checklists.md:104:**本節は実行できない**。`MANUAL_OCR_ANALYSIS_ENABLED` フラグと対応する config キーは
docs/rollout-checklists.md:111:  (`env('MANUAL_OCR_ANALYSIS_ENABLED', false)`) はそのままにし、`true` への切替は
docs/rollout-checklists.md:112:  本番環境変数 `MANUAL_OCR_ANALYSIS_ENABLED` の設定という単独の運用操作で行う想定だった
docs/rollout-checklists.md:119:  反映されない。`MANUAL_OCR_ANALYSIS_ENABLED=true` の設定後、`config:cache` の再生成と
resources/js/components/features/manual/SourceDocumentUploadNotice.svelte:7:     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 `manual.ocr_analysis_enabled` フラグは
tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php:24: * 旧 `manual.ocr_analysis_enabled` フラグはオーナー決定により撤去済み):
tests/Feature/Projects/SourceDocumentUploadOcrTest.php:18: * 画像・スキャン SOP の OCR 対応 (常時有効。旧 `manual.ocr_analysis_enabled` フラグは
tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php:9: * 形式の唯一の情報源。旧 `manual.ocr_analysis_enabled` フラグは撤去済みで、
tests/js/components/features/manual/SourceDocumentUpload.test.ts:9: * - OCR 固有の警告・1 枚制約の明示も常時表示 (旧 imageSourceDocumentsEnabled フラグは撤去済み)
tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts:8: * - 一般案内・OCR 固有警告ともに常時表示 (旧 imageSourceDocumentsEnabled フラグは撤去済み)

(上記のうち config/manual.php・AcceptedSourceDocumentTypes.php・SourceDocumentUploadNotice.svelte・tests/* のコメントは "旧フラグは撤去済み" という説明であり、docs/rollout-checklists.md・docs/architecture.md は履歴記述として許容範囲。zero-tolerance 範囲 (app/ config/ resources/js/ routes/ database/ bootstrap/ tests/) には字面としてのフラグ参照は残っていない)
```

### テスト結果サマリー

`composer phpstan`: level 10, No errors
`vendor/bin/pint --test`: passed
`pnpm lint` / `pnpm lint:fix`: clean
`pnpm typecheck`: clean

`composer test` フルスイート (1回目、SourceDocumentUploadTest.php 修正前): 6422 tests / 6417 passed / 3 failed (上記「実装中に発見した設計外の追加修正」で fail 内容と対応を記載) / 2 skipped / 5 risky。
2回目 (修正後) は実行中。
