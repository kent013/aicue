# Round 2: Round 1 の指摘への対応

Round 1 の [Warning] 2 件はどちらも**対応した**。反論・見送りは無い。

## 1. ログ漏洩テストの回帰検出力 (tests/Feature/Llm/PromptDefenseTest.php)

指摘のとおり `in_array(..., true)` は完全一致なので `input => '機密の手順です'` のような
部分文字列としての混入を見逃していた。context を `json_encode` で 1 本の文字列へ畳み、
message も含めて**部分一致**で検査する形へ変えた。

**実測**: 修正後の状態で `PromptDefense::build()` の `Log::info` の context へ
`'input' => $value` を仕込むと、このテストが赤くなることを確認した
(仕込み前は緑・仕込み後は「info が 0 回」で失敗 = 期待どおり検出している)。仕込みは元に戻してある。

## 2. docs/architecture.md の見出しが強すぎる

見出しを「経路 (これ以外の道は構造的に存在しない)」→「経路 (静的に書ける道はこれだけ)」へ変え、
直後に限定を明記した。同節末尾の「保証しないもの」と表現が一致するようにした。

## 変更後の差分

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index 76cb422..a7712a4 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1908,7 +1908,7 @@ ### 失敗分類 (`SmokeFailureClass`。観測のためであり制御フロー
 ### LLM 呼び出しの帰属 (記録側の配線)
 
 **実行経路を持つ** `app/Prompts/` の factory は `LlmCallContextData` を**必須引数**で受け、
-`->withMetadata($context->toMetadata())` で `organization_id` / `user_id` /
+窓口 (`PromptDefense::load()`) へ渡す。窓口が `withMetadata()` で `organization_id` / `user_id` /
 `subject_type` / `subject_id` を載せる。AI 解析では subject = **`VideoManual`**
 (費用を知りたい単位は成果物であって job ではない)。禁止事項 5 (LLM 呼び出しは factory 経由のみ) が
 既に強制しているため、**帰属を迂回する経路が構造的に存在しない**。記録層の列は 1 本も増やしていない。
@@ -2065,3 +2065,107 @@ ### 保証しないもの (誇張しない)
 - **撮影 PWA からの戻り導線は `Capture/Show` ヘッダーの常設リンクとして実装済み** (T155。
   §撮影 PWA の運用契約)。ただし**完成動画へ直接着地するわけではない** — 行き先はマニュアル
   詳細画面で、そこに完成動画が出るかは本節の認可条件がそのまま決める (撮影者には出ない)
+
+## LLM プロンプト防御の窓口方式 (T169 / 家系の裁定 AG-028)
+
+外部由来の文字列 (SOP 本文と、そこから生まれた前段 LLM 出力の JSON) が prompt へ入る経路を
+**1 本道**に畳み、その道の上で無害化・境界化・応答検査を行う。
+
+### 経路 (静的に書ける道はこれだけ)
+
+```
+app/Prompts/{Sop,WorkDecomposition,ScenarioGeneration,ExampleSummary}Prompt
+        │  make(生 string, LlmCallContextData)
+        ▼
+App\Support\Llm\PromptDefense                 ← 窓口 (唯一の入口)
+        │  無害化 (UntrustedTextSanitizer)
+        │  タグ境界化 (Kent013\PrismPrompt\Values\UserInput)
+        │  合言葉の合流 (PromptCanary → system_prompt の {{ $llm_canary }})
+        │  帰属の付与 (withMetadata。loadUnattributed だけが例外)
+        ▼
+App\Support\Llm\GuardedPrompt                 ← 実行単位 (唯一の出口)
+        │  executeSync(): vendor 実行 → 応答の合言葉検査
+        ▼  漏洩していれば PromptResponseRejectedException (応答は呼び出し元へ渡さない)
+```
+
+窓口の引数は**生の string の連想配列**である。呼び出し側が自分でタグ境界化の値オブジェクトを
+作って渡す経路が型で消えており、実行単位は vendor prompt を返す公開メソッドを 1 つも持たない
+ので、応答検査の迂回経路も型で消えている。
+
+**限定**: 「これだけ」と言えるのは**静的に書ける経路**についてである。反射・動的に組み立てた
+クラス名・文字列キーだけの container 解決で作った経路には gate が沈黙する
+(本節末尾の「保証しないもの」)。
+
+### 入力の無害化の分類 (**構造だけ**を扱う)
+
+| 分類 | 対象 | 理由 |
+|------|------|------|
+| 保持 | 改行 `U+000A` / タブ `U+0009` / 通常の空白 | SOP の本文構造そのもの。消すと手順の区切りが失われる |
+| 改行へ正規化 | `U+000D` (単独 / CRLF) / `U+2028` / `U+2029` | 行の区切りという意味は保つ (行数を変えない) |
+| 除去 | その他の C0 / C1 / 双方向制御 (`U+200E` `U+200F` `U+202A`–`U+202E` `U+2066`–`U+2069`) / ゼロ幅 (`U+200B`–`U+200D`) / BOM | 人間には見えないのにモデルには渡る = 見えない指示の運び手になる |
+| 拒否 | 無害化後の長さが `llm-defense.max_untrusted_bytes` 超過 / 不正な UTF-8 | 切り詰めると**黙って内容が変わる**。長さと壊れた符号化は拒否で扱う |
+
+**「ignore previous instructions」等の文言は除去しない**。偽陰性と回避のいたちごっこになり、
+正当な SOP 本文 (「前の指示は破棄する」という作業手順) を壊すためである。
+
+### 長さ上限は 2 段で、順序を固定する
+
+1. `manual.analysis_max_text_bytes` (150,000) — SOP 経路の運用上限。
+   利用者向け文言「手順書が大きすぎます。分割してアップロードしてください。」が**先に**出る
+2. `llm-defense.max_untrusted_bytes` (200,000) — 窓口の最後の砦。
+   ここに当たること自体が異常事態の合図である
+
+`LlmDefenseConfigGateTest` が **1 ≦ 2** を機械的に固定する (逆転すると分割案内が出なくなる)。
+
+### 拒否の写り方 (`AnalysisPipeline::userMessageFor`)
+
+| 例外 | 再試行 | 利用者向け文言 |
+|------|--------|---------------|
+| `UntrustedInputRejectedException` (`TooLarge`) | しない | `AnalysisFailedException::tooLarge()` |
+| `UntrustedInputRejectedException` (`InvalidEncoding`) | しない | `AnalysisFailedException::unreadableEncoding()` |
+| `PromptResponseRejectedException` | しない | `AnalysisFailedException::unsafeResponse()` |
+
+`isTransient()` は deny-by-default なので 3 つとも自動的に非 retryable である。
+合言葉の漏洩を再試行しないのは「同じ結果になるから」ではない (合言葉は毎回変わる)。
+**安全性の違反が疑われる状態で、課金してまでもう一度モデルへ投げない**という判断である。
+`unsafeResponse()` の文言が**原因を断定しない**のも同じ理由で、検知した事実は
+「system 側の内容が応答に出た」ことだけであり、手順書が原因とは限らない
+(原因を手順書だと書くと、正当な SOP の記述を利用者に削らせる誘導になる)。
+
+### 集約設定 (`config/llm-defense.php`)
+
+キーは `max_untrusted_bytes` / `canary_bytes` の 2 つだけで、**防御指示の文言も on/off スイッチも
+env も置かない** (切れる防御は防御ではない / 環境ごとに緩められる経路を作らない)。
+`LlmDefenseConfigGateTest` がキー集合・値の型・読み手クラスまでの双方向 pin・`env(` の字句不在を
+固定する。env 検査を**字句**で行うのは、素の正規表現だと gate 自身やファイル冒頭の説明文の
+"env" に反応するためである (家系の先行実装で実際に起きた事故)。
+
+### gate の走査母集団 (検査ごとに違う。一括で「app/ だけ」とは言わない)
+
+| 検査 | 母集団 | 理由 |
+|------|--------|------|
+| 呼び出し site (窓口 / vendor prompt 読み込み / 実行単位構築) | `app/` `routes/` `database/` `config/` `bootstrap/` の 5 根 | `routes/` のクロージャや seeder からの直接呼び出しは Prism 直呼びではないため、Prism 直呼び禁止の検査では捕まらない |
+| 所有権 (内部部品を誰が参照してよいか) と reflection 系 | `app/` | アプリのクラス配置の問題である |
+| — | `tests/` は常に母集団外 | テストが内部へ触るのは正当で、触る場所は `tests/Support/Llm/GuardedPromptInspector.php` 1 箇所に閉じている |
+
+`PromptDefenseWindowGateTest` の変数集合突き合わせは YAML の `{{ $name }}` を正規表現で拾う。
+これが成立するのは `PromptYamlContractTest` が prompt YAML に書ける Blade 式を
+**単純変数展開と防御指示の静的呼び出しの 2 形**へ絞っているからである。
+**構文契約が先、抽出は後**であり、契約側を緩めるなら抽出も同じ PR で見直す。
+
+### 保証しないもの (誇張しない。**本節が正本**)
+
+- **合言葉は「漏洩の検知」であって、プロンプトインジェクション一般の検出器ではない**。
+  system 側の内容を吐かせずに悪性のシナリオを JSON として返させる攻撃は検知できない
+- **非空白文字を挟んで分割された合言葉は検出しない** (`ab-cd…`)。検知は
+  大小無視 + 空白除去の 2 パスまでである (この限界は単体テストで明示的に pin してある)
+- **無害化は構造だけを扱う**。指示に見える文言は 1 文字も消さない
+- **`max_untrusted_bytes` は上界の証明ではない**。2・3 段目の入力は前段 LLM 出力由来の JSON で、
+  token 数からバイト数の上界は tokenizer 依存のため厳密には示せない。
+  正常系の実測より十分大きい**防御上限**として置いている
+- **gate が見るのは静的な出現だけ**である。文字列キーの container 解決だけの経路、
+  動的に組み立てたクラス名、vendor 内部から出る呼び出しには沈黙する
+- **窓口が守るのは prompt へ入る文字列まで**である。4 段目 (シナリオの反映) と
+  ffmpeg 側の字幕描画は本節の対象外
+- **trusted 変数の入口は存在しない**。作る必要が出たときの義務は
+  `docs/template-divergence.md` D16 が正本
diff --git a/tests/Feature/Llm/PromptDefenseTest.php b/tests/Feature/Llm/PromptDefenseTest.php
new file mode 100644
index 0000000..6635083
--- /dev/null
+++ b/tests/Feature/Llm/PromptDefenseTest.php
@@ -0,0 +1,328 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\LlmCallContextData;
+use App\DataTransferObjects\Manual\Analysis\ExtractedText;
+use App\Enums\Billing\TicketReservationStatus;
+use App\Enums\Llm\UntrustedInputRejectionReason;
+use App\Enums\Manual\JobStatus;
+use App\Exceptions\Llm\PromptResponseRejectedException;
+use App\Exceptions\Llm\UntrustedInputRejectedException;
+use App\Exceptions\Manual\AnalysisFailedException;
+use App\Models\AnalysisJob;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\VideoManual;
+use App\Prompts\ExampleSummaryPrompt;
+use App\Prompts\ScenarioGenerationPrompt;
+use App\Prompts\SopExtractPrompt;
+use App\Prompts\WorkDecompositionPrompt;
+use App\Services\AI\Testing\CannedPromptResponses;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\AnalysisPipeline;
+use App\Services\Manual\SopTextExtractor;
+use App\Support\Llm\GuardedPrompt;
+use App\Support\Llm\PromptDefense;
+use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Storage;
+use Kent013\PrismPrompt\Prompt;
+use Kent013\PrismPrompt\Testing\TextResponseFake;
+use Prism\Prism\Contracts\Message;
+use Prism\Prism\ValueObjects\Messages\SystemMessage;
+use Tests\Support\Llm\CanaryEchoPromptFake;
+use Tests\Support\Llm\GuardedPromptInspector;
+use Tests\Support\Llm\PromptInjectionCorpus;
+use Webmozart\Assert\Assert;
+
+/*
+ * 窓口 (PromptDefense) と実行単位 (GuardedPrompt) の**実行時**の振る舞い
+ * (裁定 AG-028 の窓口方式一式)。構造の検査は PromptDefenseWindowGateTest が担う。
+ *
+ * ここで固定するのは 3 つ:
+ *  (1) untrusted がタグ境界化され、不可視文字が prompt に載らないこと
+ *  (2) 拒否が fail-closed であること (LLM を呼ばない / 応答を返さない)
+ *  (3) 拒否がパイプラインの利用者向け文言・再試行しない扱い・チケット release に写ること
+ */
+
+beforeEach(function (): void {
+    // executeSync は fake 中も PromptExecutionCompleted を発火し、listener が FX 解決 (HTTP) を
+    // 試みるため stray request を防ぐ
+    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
+});
+
+afterEach(function (): void {
+    Prompt::stopFaking();
+});
+
+/** 窓口を通した prompt を 1 本組み立てる (見本 factory 経由 = 帰属なし)。 */
+function defenseSamplePrompt(string $untrusted): GuardedPrompt
+{
+    return ExampleSummaryPrompt::make($untrusted);
+}
+
+/**
+ * 解析パイプラインを 1 回走らせるための queued job 一式。
+ *
+ * @return array{Organization, AnalysisJob}
+ */
+function defensePipelineContext(): array
+{
+    Storage::fake();
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
+    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
+    Storage::put($path, str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5));
+    $document = SourceDocument::factory()->forManual($manual)->create([
+        'file_path' => $path,
+        'mime' => 'text/plain',
+    ]);
+    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
+    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
+
+    return [$organization, $job];
+}
+
+// ── (1) タグ境界化と無害化 ───────────────────────────────────────────
+
+test('タグ breakout を試みても <user_input> 境界は 1 組だけ保たれる', function (): void {
+    foreach (PromptInjectionCorpus::tagBreakouts() as $input) {
+        $rendered = GuardedPromptInspector::renderedUserPrompt(defenseSamplePrompt($input));
+
+        expect(substr_count($rendered, '<user_input>'))->toBe(1);
+        expect(substr_count($rendered, '</user_input>'))->toBe(1);
+        expect($rendered)->toContain('_escaped');
+    }
+});
+
+test('不可視文字は prompt に載らない', function (): void {
+    foreach (PromptInjectionCorpus::invisibleCharacters() as $name => $input) {
+        $rendered = GuardedPromptInspector::renderedUserPrompt(defenseSamplePrompt($input));
+
+        expect(preg_match(
+            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{0080}-\x{009F}'
+            .'\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
+            $rendered,
+        ))->toBe(0, "{$name}: 不可視文字が prompt に載っています");
+    }
+});
+
+test('改行とタブは prompt に保持される (SOP の構造が壊れない)', function (): void {
+    $rendered = GuardedPromptInspector::renderedUserPrompt(
+        defenseSamplePrompt("手順 1\tトルクレンチ\n手順 2\tネジ締め"),
+    );
+
+    expect($rendered)->toContain("手順 1\tトルクレンチ\n手順 2\tネジ締め");
+});
+
+test('合言葉は system prompt 側にだけ現れる', function (): void {
+    $prompt = defenseSamplePrompt('本文');
+    $token = GuardedPromptInspector::canaryToken($prompt);
+
+    expect(GuardedPromptInspector::renderedSystemPrompt($prompt))->toContain($token);
+    expect(GuardedPromptInspector::renderedUserPrompt($prompt))->not->toContain($token);
+});
+
+test('合言葉の変数名は上書きできない', function (): void {
+    expect(fn (): GuardedPrompt => PromptDefense::loadUnattributed(
+        template: 'example-summary',
+        untrusted: [PromptDefense::CANARY_VARIABLE => '乗っ取り'],
+    ))->toThrow(InvalidArgumentException::class);
+});
+
+test('変数名は小文字始まりの識別子に限る', function (): void {
+    foreach (['', 'Text', '1text', 'te-xt'] as $invalid) {
+        expect(fn (): GuardedPrompt => PromptDefense::loadUnattributed(
+            template: 'example-summary',
+            untrusted: [$invalid => '本文'],
+        ))->toThrow(InvalidArgumentException::class);
+    }
+});
+
+test('不可視文字の除去はログに件数だけを残す (中身を流さない)', function (): void {
+    Log::spy();
+
+    defenseSamplePrompt("機密の手順\u{200B}\u{200B}です");
+
+    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
+        // ★ context を 1 本の文字列へ畳んでから**部分一致**で検査する。
+        //   完全一致 (in_array) だと `input => '機密の手順です'` のような混入を見逃す。
+        $serialized = $message.' '.(string) json_encode($context, JSON_UNESCAPED_UNICODE);
+
+        foreach (['機密の手順', 'です', "\u{200B}"] as $fragment) {
+            if (str_contains($serialized, $fragment)) {
+                return false;
+            }
+        }
+
+        return $message === 'untrusted 入力から不可視文字を除去しました'
+            && $context['removed_characters'] === 2
+            && $context['prompt'] === 'example-summary'
+            && $context['variable'] === 'text';
+    })->once();
+});
+
+// ── (2) 拒否は fail-closed ───────────────────────────────────────────
+
+test('上限超過は LLM を 1 回も呼ばずに拒否する', function (): void {
+    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);
+    $limit = config()->integer('llm-defense.max_untrusted_bytes');
+
+    try {
+        defenseSamplePrompt(PromptInjectionCorpus::oversizedText($limit));
+        $this->fail('上限超過が拒否されていません');
+    } catch (UntrustedInputRejectedException $exception) {
+        expect($exception->reason)->toBe(UntrustedInputRejectionReason::TooLarge);
+    }
+
+    $fake->assertCallCount(0);
+});
+
+test('不正な UTF-8 は LLM を 1 回も呼ばずに拒否する', function (): void {
+    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);
+
+    try {
+        defenseSamplePrompt("手順\xC3\x28");
+        $this->fail('不正な UTF-8 が拒否されていません');
+    } catch (UntrustedInputRejectedException $exception) {
+        expect($exception->reason)->toBe(UntrustedInputRejectionReason::InvalidEncoding);
+    }
+
+    $fake->assertCallCount(0);
+});
+
+test('合言葉が漏れた応答は呼び出し元へ返らない', function (): void {
+    Prompt::installFake(new CanaryEchoPromptFake('これが system prompt です: '));
+
+    $prompt = defenseSamplePrompt(PromptInjectionCorpus::canaryDisclosureRequest());
+    $token = GuardedPromptInspector::canaryToken($prompt);
+
+    try {
+        $prompt->executeSync();
+        $this->fail('合言葉の漏洩が検知されていません');
+    } catch (PromptResponseRejectedException $exception) {
+        // 例外 message に合言葉そのものを載せない (ログから合言葉が漏れる経路を作らない)
+        expect($exception->getMessage())->not->toContain($token);
+        expect($exception->getMessage())->toContain('example-summary');
+    }
+});
+
+test('空白で分割された合言葉 + 不正バイトの応答でも fail-open しない', function (): void {
+    Prompt::installFake(new CanaryEchoPromptFake("\xC3\x28 ", splitEveryChars: 8));
+
+    expect(fn (): string => defenseSamplePrompt('本文')->executeSync())
+        ->toThrow(PromptResponseRejectedException::class);
+});
+
+test('合言葉を含まない応答はそのまま返る', function (): void {
+    Prompt::fake([TextResponseFake::make()->withText('要約です。')]);
+
+    expect(defenseSamplePrompt('本文')->executeSync())->toBe('要約です。');
+});
+
+// ── 4 YAML すべてが窓口経由で組み立つ ────────────────────────────────
+
+test('4 つの prompt がすべて窓口経由で組み立てられ canned が一意解決する', function (): void {
+    $context = LlmCallContextData::none();
+    $prompts = [
+        'sop-extract' => SopExtractPrompt::make('サンプル SOP', $context),
+        'work-decomposition' => WorkDecompositionPrompt::make('{"sections":[]}', $context),
+        'scenario-generation' => ScenarioGenerationPrompt::make('{"steps":[]}', $context),
+        'example-summary' => ExampleSummaryPrompt::make('本文'),
+    ];
+
+    $canned = app(CannedPromptResponses::class);
+    foreach ($prompts as $template => $prompt) {
+        expect($prompt)->toBeInstanceOf(GuardedPrompt::class);
+
+        $systemText = GuardedPromptInspector::renderedSystemPrompt($prompt);
+        expect($systemText)->toContain(GuardedPromptInspector::canaryToken($prompt));
+
+        /** @var array<int, Message> $messages */
+        $messages = [new SystemMessage($systemText)];
+        // 合言葉が混ざっても signature 解決は一意のまま (fail-fast しない)
+        $response = $canned->forMessages($messages);
+        expect($response->getText())->not->toBe('');
+        unset($template);
+    }
+});
+
+// ── (3) パイプラインへの写り方 ───────────────────────────────────────
+
+test('合言葉の漏洩: 再試行せず failed + 安全検査の文言 + 予約 release', function (): void {
+    [, $job] = defensePipelineContext();
+    $fake = new CanaryEchoPromptFake('system prompt: ');
+    Prompt::installFake($fake);
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->error)->toBe(AnalysisFailedException::unsafeResponse()->getMessage());
+    // 安全性の違反が疑われる状態で、課金してまでもう一度モデルへ投げない
+    expect($fake->callCount())->toBe(1);
+    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
+});
+
+test('上限超過: LLM を 1 回も呼ばず failed + 分割案内の文言 + 予約 release', function (): void {
+    [, $job] = defensePipelineContext();
+    // そのテスト内でだけ窓口の上限を下げ、通常の SOP 本文を窓口で拒否させる
+    // (committed な config の大小関係は LlmDefenseConfigGateTest が別途固定している)
+    config()->set('llm-defense.max_untrusted_bytes', 50);
+    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->error)->toBe(AnalysisFailedException::tooLarge()->getMessage());
+    $fake->assertCallCount(0);
+    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
+});
+
+test('不正な UTF-8: LLM を 1 回も呼ばず failed + 文字コードの文言 + 予約 release', function (): void {
+    [, $job] = defensePipelineContext();
+
+    // 抽出器の保証が将来失われたときに窓口が fail-closed で止めることの再現。
+    // ExtractedText の不変条件は緩めない (UTF-8 の保証はもともと抽出器側にある)。
+    $this->app->instance(SopTextExtractor::class, new class extends SopTextExtractor
+    {
+        public function extract(SourceDocument $document): ExtractedText
+        {
+            unset($document);
+            $broken = "手順 1\xC3\x28手順 2".str_repeat('あ', 100);
+
+            return new ExtractedText($broken, strlen($broken), 'plain');
+        }
+    });
+    $fake = Prompt::fake([TextResponseFake::make()->withText('呼ばれてはいけない')]);
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->error)->toBe(AnalysisFailedException::unreadableEncoding()->getMessage());
+    $fake->assertCallCount(0);
+    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
+});
+
+test('窓口の拒否は transient ではない (isTransient を deny-by-default のまま保つ)', function (): void {
+    $method = new ReflectionMethod(AnalysisPipeline::class, 'isTransient');
+    $pipeline = app(AnalysisPipeline::class);
+
+    $rejected = PromptResponseRejectedException::canaryLeaked('sop-extract');
+    $tooLarge = null;
+    try {
+        config()->set('llm-defense.max_untrusted_bytes', 1);
+        defenseSamplePrompt('本文が上限を超える');
+    } catch (UntrustedInputRejectedException $exception) {
+        $tooLarge = $exception;
+    }
+    Assert::notNull($tooLarge);
+
+    expect($method->invoke($pipeline, $rejected))->toBeFalse();
+    expect($method->invoke($pipeline, $tooLarge))->toBeFalse();
+});
```

(上記は Round 1 時点からの累積差分である。Round 1 との違いは
 `tests/Feature/Llm/PromptDefenseTest.php` のログ検査ブロックと
 `docs/architecture.md` の「経路」見出し + 限定段落の 2 箇所だけである。)

## 再検証結果

- `composer test`: 4907 tests / 4905 passed / 0 failed / 2 skipped
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed

他に指摘があれば挙げてほしい。無ければ全体判定を示してほしい。
