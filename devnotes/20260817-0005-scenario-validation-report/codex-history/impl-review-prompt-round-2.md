# 実装レビュー Round 2 (T200)

Round 1 の指摘 4 件への対応です。対応マトリクスの全文は
`devnotes/20260817-0005-scenario-validation-report/codex-history/impl-review-decisions-round-1.md`
に保存済みで、要約は以下です。

## [Warning] works の keyed each — 対応した

指摘のとおり `works` は `SopValidationData` が重複を禁止しておらず、値を一意 key にできません。
DTO 側で重複を弾く方向は「表示のために保存値の受理条件を狭める」ことになり、所見を表示専用に
留める設計と噛み合わないため、表示側を直しました。

なお最初に unkeyed each にしたところ、本リポジトリの eslint 規約 (`svelte/require-each-key`) が
エラーになりました。そこで **index を key** にしています (値の重複に影響されず一意)。
理由はコメントで残しました。

## [Warning] M9 (ドキュメント更新) が diff に無い — 実装済みでした (diff の切り出し漏れ)

M9 は実装済みです。Round 1 の diff を `app/ resources/ tests/ routes/ database/` に限って
切り出したため `docs/` と `doc/` が範囲外になっていました。該当 diff を下に添付します。
コードは変更していません。

## [Suggestion] 3 verdict の tone も固定する — 対応した

`ScenarioReportPanel.test.ts` の it.each に期待 tone class
(`text-success` / `text-warning` / `text-danger`) を足し `toHaveClass` で固定しました
(Badge atom が `TONE_CLASSES` を class に出すため DOM から観測できます)。

## [Suggestion] AnalysisPipelineTest の名前と fixture の不一致 — 対応した

既存テストを「validation **不正** は…」に改名し、**キー欠落そのもの**を踏むテストを 1 件
追加しました (旧プロンプト時代の `{steps}` だけの応答形が返ってきた状況。
`failure_path` が `validation` ちょうどになることを固定)。

---

## 修正差分 (コード + テスト)

```diff
diff --git a/resources/js/components/features/manual/ScenarioReportPanel.svelte b/resources/js/components/features/manual/ScenarioReportPanel.svelte
new file mode 100644
index 0000000..7f7c3cb
--- /dev/null
+++ b/resources/js/components/features/manual/ScenarioReportPanel.svelte
@@ -0,0 +1,106 @@
+<script lang="ts">
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import {
+        SCENARIO_RULE_LABELS,
+        SCENARIO_VERDICT_LABELS,
+        SCENARIO_VERDICT_TONES,
+        formatPositions,
+    } from "@/components/features/manual/scenario-report";
+    import type { ScenarioReportProps } from "@/types/manual";
+
+    /**
+     * 生成結果の確認パネル (doc/03 §3.4 のバリデーション結果)。
+     * - 所見 (LLM・解析時点のスナップショット) と 検査 (現在の cuts から決定的に算出) を
+     *   **見出しで分けて**出す (鮮度が違うため)
+     * - 判定は表示のみ。ボタンを disabled にしない / 保存・撮影を止めない
+     * - 「有効でない」ときの行き先を必ず添える (編集する / 手順書を差し替えて再解析する)
+     */
+    interface Props {
+        projectId: number;
+        manualId: number;
+        report: ScenarioReportProps;
+        canManage: boolean;
+    }
+
+    let { projectId, manualId, report, canManage }: Props = $props();
+</script>
+
+<Card padding="lg" testId="scenario-report">
+    <h2 class="text-h3">生成結果の確認</h2>
+
+    {#if report.verdict}
+        {@const verdict = report.verdict}
+        <div class="mt-4">
+            <h3 class="text-body font-medium">手順書への所見 (AI 解析時点)</h3>
+            <div class="mt-2 flex items-center gap-3">
+                <Badge tone={SCENARIO_VERDICT_TONES[verdict.verdict]} testId="scenario-verdict">
+                    {SCENARIO_VERDICT_LABELS[verdict.verdict]}
+                </Badge>
+                <span class="text-caption text-text-secondary" data-testid="scenario-work-count">
+                    作業 {verdict.work_count} 件
+                </span>
+            </div>
+            <p class="mt-2 text-body" data-testid="scenario-verdict-reason">{verdict.reason}</p>
+            {#if !verdict.is_current_document}
+                <p class="mt-2 text-caption text-text-secondary" data-testid="scenario-verdict-stale">
+                    この所見は解析時の手順書に対するものです。手順書を差し替えた場合は AI
+                    解析をやり直してください。
+                </p>
+            {/if}
+            <ul class="mt-2 list-disc pl-5 text-caption text-text-secondary" data-testid="scenario-works">
+                <!-- key は index にする: works は LLM 由来で重複を禁止しておらず値は一意 key にできない -->
+                {#each verdict.works as work, index (index)}
+                    <li>{work}</li>
+                {/each}
+            </ul>
+            {#if verdict.split_recommended}
+                <div class="mt-3">
+                    <Alert type="info" testId="scenario-split-recommended">
+                        この手順書には複数の作業が含まれています。作業ごとにマニュアルを分けると撮影とナビゲーションが短くなります (「複製」から作業ごとに分けられます)。
+                    </Alert>
+                </div>
+            {/if}
+        </div>
+    {/if}
+
+    <div class="mt-4">
+        <h3 class="text-body font-medium">シナリオの検査 (現在の内容)</h3>
+        <p class="mt-2 text-body" data-testid="scenario-counts">
+            カット構成: 手順 {report.counts.steps} / 急所 {report.counts.points} (合計 {report.counts
+                .total})
+        </p>
+
+        {#if report.findings.length > 0}
+            <ul class="mt-2 list-disc pl-5" data-testid="scenario-findings">
+                {#each report.findings as finding (finding.code)}
+                    <li class="text-body">
+                        {SCENARIO_RULE_LABELS[finding.code]}: {finding.count} 件
+                        <span class="text-caption text-text-secondary">
+                            {formatPositions(finding.positions, finding.count)}
+                        </span>
+                    </li>
+                {/each}
+            </ul>
+        {:else}
+            <p class="mt-2 text-body" data-testid="scenario-findings-empty">
+                シナリオの書式に関する指摘はありません。
+            </p>
+        {/if}
+    </div>
+
+    {#if canManage}
+        <div class="mt-4">
+            <Button
+                variant="ghost"
+                href={`/projects/${projectId}/manuals/${manualId}/edit`}
+                inertia
+                testId="scenario-report-edit-link"
+            >
+                シナリオを編集して確認する
+            </Button>
+        </div>
+    {/if}
+</Card>
diff --git a/tests/Feature/Projects/AnalysisPipelineTest.php b/tests/Feature/Projects/AnalysisPipelineTest.php
index 96ffaf4..0aeb6d7 100644
--- a/tests/Feature/Projects/AnalysisPipelineTest.php
+++ b/tests/Feature/Projects/AnalysisPipelineTest.php
@@ -36,6 +36,7 @@
 use Prism\Prism\Exceptions\PrismProviderOverloadedException;
 use Prism\Prism\Exceptions\PrismRateLimitedException;
 use Prism\Prism\Exceptions\PrismRequestTooLargeException;
+use Prism\Prism\ValueObjects\Messages\UserMessage;
 use Tests\Support\PrismHttpExceptionFactory;
 use Tests\Support\ThrowingPromptFake;
 
@@ -96,13 +97,25 @@ function extractFixture(): string
     ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
 }
 
-function decompositionFixture(): string
+/**
+ * work-decomposition 応答 ({steps, validation})。上書きしたいキーだけ差し替える
+ * (validation を欠落・破損させたケースを組み立てるため)。
+ *
+ * @param  array<string, mixed>  $overrides
+ */
+function decompositionFixture(array $overrides = []): string
 {
-    return json_encode([
+    return json_encode([...[
         'steps' => [
             ['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']],
         ],
-    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+        'validation' => [
+            'verdict' => 'needs_review',
+            'reason' => 'トルク値は読み取れましたが工具の指定が曖昧です。',
+            'works' => ['ネジ締め作業'],
+            'split_recommended' => false,
+        ],
+    ], ...$overrides], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
 }
 
 function scenarioFixture(): string
@@ -215,6 +228,119 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     // 監査スナップショット
     expect($document->refresh()->extracted_json)->toHaveKey('sections');
     expect($job->result_json)->toHaveKey('steps');
+
+    // 手順書への所見 (表示契約)。result_json とは別カラムで、互いに混ざらない
+    expect($job->validation_json)->toBe([
+        'verdict' => 'needs_review',
+        'reason' => 'トルク値は読み取れましたが工具の指定が曖昧です。',
+        'works' => ['ネジ締め作業'],
+        'split_recommended' => false,
+    ]);
+    expect($job->result_json)->not->toHaveKey('validation');
+});
+
+test('3 段目へ渡す入力 JSON に validation は含まれない (所見を次段に混ぜない)', function (): void {
+    [, , , , , $job] = pipelineContext();
+    fakeSuccessfulLlm();
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+    // 3 段目 (scenario-generation) のプロンプトへ実際に載った本文を読む
+    $fake = Prompt::getFake();
+    expect($fake)->not->toBeNull();
+    $recorded = $fake->recorded();
+    expect($recorded)->toHaveCount(3);
+
+    $generateText = '';
+    foreach ($recorded[2]['messages'] as $message) {
+        if ($message instanceof UserMessage) {
+            $generateText .= $message->text()."\n";
+        }
+    }
+
+    expect($generateText)->toContain('ネジを締める');       // 作業分解表は渡る
+    expect($generateText)->not->toContain('needs_review');  // 所見は渡らない
+    expect($generateText)->not->toContain('split_recommended');
+});
+
+test('validation 不正は有界リトライののち failed (validation_json は NULL のまま)', function (): void {
+    [, , , , , $job] = pipelineContext();
+    $brokenDecomposition = decompositionFixture(['validation' => ['verdict' => 'unknown']]);
+    Prompt::fake([
+        TextResponseFake::make()->withText(extractFixture()),
+        TextResponseFake::make()->withText($brokenDecomposition),
+        TextResponseFake::make()->withText($brokenDecomposition),
+        TextResponseFake::make()->withText($brokenDecomposition),
+    ]);
+    Log::spy();
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->validation_json)->toBeNull();
+    expect($job->result_json)->toBeNull(); // 所見が通らない限り作業分解表も保存しない (1 応答 1 保存)
+
+    // 再試行ログに違反位置が載る (validation 起因かを集計で分けられる)
+    Log::shouldHaveReceived('warning')->withArgs(
+        fn (string $message, array $context): bool => $message === 'AI 解析の LLM 呼び出しを再試行します'
+            && $context['failure_category'] === 'schema_violation'
+            && is_string($context['failure_path'])
+            && str_starts_with($context['failure_path'], 'validation.'),
+    );
+    // 最終失敗にも同じ観測キーが残る (再試行ログとは別の 1 行)
+    Log::shouldHaveReceived('warning')->withArgs(
+        fn (string $message, array $context): bool => $message === 'AI 解析が LLM 応答のスキーマ違反で失敗しました'
+            && $context['analysis_job_id'] === $job->id
+            && $context['failure_category'] === 'schema_violation'
+            && $context['failure_path'] === 'validation.verdict',
+    )->once();
+});
+
+test('validation キーの欠落そのものも failed になる (failure_path=validation)', function (): void {
+    [, , , , , $job] = pipelineContext();
+    // 旧プロンプト時代の応答形 ({steps} だけ) が返ってきた状況
+    $withoutValidation = json_encode([
+        'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']]],
+    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    Prompt::fake([
+        TextResponseFake::make()->withText(extractFixture()),
+        TextResponseFake::make()->withText($withoutValidation),
+        TextResponseFake::make()->withText($withoutValidation),
+        TextResponseFake::make()->withText($withoutValidation),
+    ]);
+    Log::spy();
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->validation_json)->toBeNull();
+    Log::shouldHaveReceived('warning')->withArgs(
+        fn (string $message, array $context): bool => $message === 'AI 解析が LLM 応答のスキーマ違反で失敗しました'
+            && $context['failure_path'] === 'validation',
+    )->once();
+});
+
+test('steps 側の違反は failure_path が steps. で始まる (validation 側と識別できる)', function (): void {
+    [, , , , , $job] = pipelineContext();
+    $brokenSteps = decompositionFixture(['steps' => [['no' => 1, 'action' => '', 'points' => []]]]);
+    Prompt::fake([
+        TextResponseFake::make()->withText(extractFixture()),
+        TextResponseFake::make()->withText($brokenSteps),
+        TextResponseFake::make()->withText($brokenSteps),
+        TextResponseFake::make()->withText($brokenSteps),
+    ]);
+    Log::spy();
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Failed);
+    Log::shouldHaveReceived('warning')->withArgs(
+        fn (string $message, array $context): bool => $message === 'AI 解析が LLM 応答のスキーマ違反で失敗しました'
+            && $context['failure_path'] === 'steps.0.action',
+    )->once();
 });
 
 test('再試行で二重予約しない (有効な Reserved は再利用) + queued guard の no-op', function (): void {
diff --git a/tests/js/components/features/manual/ScenarioReportPanel.test.ts b/tests/js/components/features/manual/ScenarioReportPanel.test.ts
new file mode 100644
index 0000000..5bdcfe5
--- /dev/null
+++ b/tests/js/components/features/manual/ScenarioReportPanel.test.ts
@@ -0,0 +1,172 @@
+import { afterEach, describe, expect, it } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import ScenarioReportPanel from "@/components/features/manual/ScenarioReportPanel.svelte";
+import type { ScenarioReportProps } from "@/types/manual";
+
+/*
+ * 生成結果の確認パネル (T200):
+ * - 所見 (LLM・解析時点) と 検査 (現在の cuts) を分けて出す
+ * - 鮮度が落ちた所見には注記を添える (隠さない)
+ * - 指摘 0 件でも「指摘なし」を明示する / 位置は「手順 N」「急所 N-M」
+ * - 判定でボタンを disabled にしない (編集導線は canManage のみ)
+ */
+
+const baseReport: ScenarioReportProps = {
+    verdict: null,
+    counts: { steps: 2, points: 3, total: 5 },
+    findings: [],
+};
+
+const baseProps = {
+    projectId: 1,
+    manualId: 5,
+    report: baseReport,
+    canManage: true,
+};
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("ScenarioReportPanel", () => {
+    it("カット構成と「指摘なし」を描画する", () => {
+        render(ScenarioReportPanel, { props: baseProps });
+
+        expect(screen.getByTestId("scenario-counts")).toHaveTextContent("手順 2");
+        expect(screen.getByTestId("scenario-counts")).toHaveTextContent("急所 3");
+        expect(screen.getByTestId("scenario-counts")).toHaveTextContent("合計 5");
+        expect(screen.getByTestId("scenario-findings-empty")).toHaveTextContent(
+            "シナリオの書式に関する指摘はありません。",
+        );
+        expect(screen.queryByTestId("scenario-verdict")).toBeNull();
+    });
+
+    it.each([
+        ["valid" as const, "マニュアルとして有効", "text-success"],
+        ["needs_review" as const, "確認が必要な箇所があります", "text-warning"],
+        ["invalid" as const, "このままでは元資料として不十分", "text-danger"],
+    ])("verdict=%s のラベルと tone を出す", (verdict, label, toneClass) => {
+        render(ScenarioReportPanel, {
+            props: {
+                ...baseProps,
+                report: {
+                    ...baseReport,
+                    verdict: {
+                        verdict,
+                        reason: "判定の理由です。",
+                        works: ["バルブ閉止作業"],
+                        work_count: 1,
+                        split_recommended: false,
+                        is_current_document: true,
+                    },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("scenario-verdict")).toHaveTextContent(label);
+        // tone は Badge atom の TONE_CLASSES 経由で class に現れる (表示語彙 helper の回帰固定)
+        expect(screen.getByTestId("scenario-verdict")).toHaveClass(toneClass);
+        expect(screen.getByTestId("scenario-verdict-reason")).toHaveTextContent("判定の理由です。");
+        expect(screen.getByTestId("scenario-work-count")).toHaveTextContent("1");
+        expect(screen.getByTestId("scenario-works")).toHaveTextContent("バルブ閉止作業");
+        expect(screen.queryByTestId("scenario-verdict-stale")).toBeNull();
+        expect(screen.queryByTestId("scenario-split-recommended")).toBeNull();
+    });
+
+    it("is_current_document=false では所見を隠さず注記を添える", () => {
+        render(ScenarioReportPanel, {
+            props: {
+                ...baseProps,
+                report: {
+                    ...baseReport,
+                    verdict: {
+                        verdict: "needs_review",
+                        reason: "確認すべき箇所があります。",
+                        works: ["バルブ閉止作業"],
+                        work_count: 1,
+                        split_recommended: false,
+                        is_current_document: false,
+                    },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("scenario-verdict")).toBeInTheDocument();
+        expect(screen.getByTestId("scenario-verdict-stale")).toHaveTextContent(
+            "解析時の手順書に対するもの",
+        );
+    });
+
+    it("split_recommended=true で分割の案内を出す", () => {
+        render(ScenarioReportPanel, {
+            props: {
+                ...baseProps,
+                report: {
+                    ...baseReport,
+                    verdict: {
+                        verdict: "valid",
+                        reason: "2 つの作業が含まれています。",
+                        works: ["バルブ閉止作業", "点検作業"],
+                        work_count: 2,
+                        split_recommended: true,
+                        is_current_document: true,
+                    },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("scenario-split-recommended")).toHaveTextContent("複製");
+    });
+
+    it("指摘の件数と位置 (手順 N / 急所 N-M / ほか) を描画する", () => {
+        render(ScenarioReportPanel, {
+            props: {
+                ...baseProps,
+                report: {
+                    ...baseReport,
+                    findings: [
+                        {
+                            code: "narration_missing",
+                            count: 2,
+                            positions: [
+                                { step: 2, point: null },
+                                { step: 2, point: 3 },
+                            ],
+                        },
+                        {
+                            code: "subtitle_secondary_missing",
+                            count: 7,
+                            positions: [
+                                { step: 1, point: null },
+                                { step: 1, point: 1 },
+                            ],
+                        },
+                    ],
+                },
+            },
+        });
+
+        const findings = screen.getByTestId("scenario-findings");
+        expect(findings).toHaveTextContent("ナレーションが空のカット: 2 件");
+        expect(findings).toHaveTextContent("手順 2 / 急所 2-3");
+        // count が positions より多いときだけ「ほか」を添える
+        expect(findings).toHaveTextContent("手順 1 / 急所 1-1 ほか");
+        expect(screen.queryByTestId("scenario-findings-empty")).toBeNull();
+    });
+
+    it("canManage=false では編集導線を出さない (表示は止めない)", () => {
+        render(ScenarioReportPanel, { props: { ...baseProps, canManage: false } });
+
+        expect(screen.getByTestId("scenario-report")).toBeInTheDocument();
+        expect(screen.queryByTestId("scenario-report-edit-link")).toBeNull();
+    });
+
+    it("canManage=true では編集導線を出す", () => {
+        render(ScenarioReportPanel, { props: baseProps });
+
+        // Inertia の Link は絶対 URL へ解決されるため末尾一致で見る
+        expect(screen.getByTestId("scenario-report-edit-link").getAttribute("href")).toMatch(
+            /\/projects\/1\/manuals\/5\/edit$/,
+        );
+    });
+});
```

---

## M9 のドキュメント差分 (Round 1 で添付し損ねた分)

```diff
diff --git "a/doc/03_AI\350\247\243\346\236\220\343\201\250\343\202\267\343\203\212\343\203\252\343\202\252\347\224\237\346\210\220.md" "b/doc/03_AI\350\247\243\346\236\220\343\201\250\343\202\267\343\203\212\343\203\252\343\202\252\347\224\237\346\210\220.md"
index eead184..81117e8 100644
--- "a/doc/03_AI\350\247\243\346\236\220\343\201\250\343\202\267\343\203\212\343\203\252\343\202\252\347\224\237\346\210\220.md"
+++ "b/doc/03_AI\350\247\243\346\236\220\343\201\250\343\202\267\343\203\212\343\203\252\343\202\252\347\224\237\346\210\220.md"
@@ -87,6 +87,13 @@ ### 成果物の構成
 - `scenarios/scenario_{engine}.{txt,xlsx}` — 上記から生成したシナリオ（初版）。
 - `scenarios/scenario_v2_{engine}.{txt,xlsx}` — 改良版プロンプトによるシナリオ。`v2` ではシナリオの手前に**バリデーション結果**（マニュアルとして有効か、文末解析/語彙解析/構造解析、作業数、仮タイトル一覧、ノード数＝手順数/急所数、分割要否）を出力する構成。
 
+> **実装での分担（T200）**: この v2 のバリデーション結果は、AI-CUE では**出所を 2 つに分けて**詳細画面の「生成結果の確認」パネルに出している。
+>
+> - **LLM が出すもの**（作業分解プロンプトの `validation`）: マニュアルとして有効か（3 値）・判定理由・仮タイトル一覧・分割要否。**LLM にしか判断できない項目だけ**を載せる。
+> - **PHP が決定的に算出するもの**（`App\Support\Manual\ScenarioRuleCheck`）: 作業数・ノード数（手順数/急所数）と、文末解析・語彙解析にあたる書式検査（ナレーションの空/文体/「ください」、字幕①が文になっていないか、字幕②の空）。件数と文体は LLM に数えさせない。
+>
+> 「ノード数」は導入・総括カットを含む**カット構成**として出す（この 2 つは DB 上に識別子を持たないため、普通の手順カットとして数える）。どちらの結果も**表示専用で制御フローには使わない**（保存・撮影・レンダを止めない）。契約と保証しないものは `docs/architecture.md` §シナリオ生成の妥当性所見と規約検査 が正本。
+
 ### 観察された差異（構造化 JSON の比較より）
 
 同一手順書に対し、エンジンごとに読み取り精度が異なる。実例（No.1「主スイッチの解放」）:
diff --git a/docs/architecture.md b/docs/architecture.md
index 846d651..03aa6ed 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -580,6 +580,40 @@ ### AI 解析ジョブの運用契約
 - ローカル/テストの検証: パイプラインの同期実行は `AnalysisPipeline::run()` の直接呼び出し、
   dispatch の検証は `Queue::fake()` (sync ドライバの自動実行には依存しない)
 
+### シナリオ生成の妥当性所見と規約検査 (T200)
+
+詳細画面の「生成結果の確認」パネルは、**出所と鮮度が違う 2 つ**を見出しで分けて出す。
+どちらも**表示専用であり制御フローには使わない** (保存・撮影・レンダを止めない。
+判定を理由にボタンを disabled にもしない = 禁止事項 8)。
+
+- **手順書への所見 (LLM 判断)**: work-decomposition プロンプトの応答に含まれる `validation`
+  (`verdict` / `reason` / `works` / `split_recommended`) を `SopValidationData` で検証し、
+  `analysis_jobs.validation_json` へ保存する。`result_json` (作業分解表の write-only 監査
+  スナップショット) とは**別カラム**である (こちらは画面が読む表示契約で寿命も契約も違う)。
+  所見は**次段 (scenario-generation) の入力には混ぜない**
+- **出所は「最新の succeeded ジョブ」**であって「最新のジョブ」ではない。いま画面にある cuts を
+  作ったのは最後に成功した解析だからである (再解析が失敗しても前回の所見と現在のシナリオの
+  対応は保たれる)。NULL = 所見なし (本機能より前のジョブ / decompose 段に到達しなかったジョブ)
+- **鮮度は `analysis_jobs.source_document_id` と最新 `source_documents.id` の一致**で見る。
+  これは **source_document が追記型 (append-only) である前提**に立つ (差し替えは新しい行の
+  INSERT で、解析対象は常に最新 id の 1 件)。将来ファイルを in-place 更新する経路を作るなら、
+  id ではなく内容の版で比較する必要がある。不一致でも所見は隠さず、注記を添えて再解析へ誘導する
+- **規約検査 (`App\Support\Manual\ScenarioRuleCheck`)** は現在の cuts から決定的に算出する
+  純関数で、DB に触らない (取得は呼び出し側の 1 本)。件数・文体は LLM に数えさせない
+- **観測**: LLM 応答のスキーマ違反は再試行ログと最終失敗ログの両方に
+  `failure_category` (分類) と `failure_path` (違反位置。例 `validation.works.2`) を残す
+  (`steps.` 始まりか `validation.` 始まりかで起因を集計で分けられる)。
+  **応答本文はログに載せない** (LLM 由来の可変文字列)
+
+#### 保証しないもの (誇張しない)
+
+- 規約検査は**書式の検査**であって内容の正しさ (OCR 誤読・手順の欠落) は検出しない
+- 所見は**解析時点の手順書に対する LLM の判断**であり、その後の手動編集は反映されない
+  (だから件数・文体は別建てで常に再計算している)
+- 検査は導入/総括カットを普通の手順カットとして数える (DB 上に識別子を持たないため)。
+  同じ理由で「急所が 0 件の手順」の検査は入れていない (恒常的な偽陽性になる)
+- props は**描画時点のスナップショット**であり常に最新ではない (`render.coverage` と同じ性質)
+
 ### レンダジョブの運用契約
 
 - レンダジョブ (`RunManualRender`) は専用 queue connection **`database-render`**
```

---

## 再検証結果 (AGENTS.md の検証コマンド全量。すべて green)

- `composer test`: 5588 tests / 5586 passed / 0 failed / 2 skipped (24249 assertions)
- `composer phpstan`: level 10 で No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 160 files / 1963 tests passed
- `pnpm build`: 成功
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed

残っている指摘があれば挙げてください。無ければ全体判定を APPROVED で返してください。
