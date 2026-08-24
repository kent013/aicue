<script lang="ts">
    import Alert from "@/components/atoms/Alert.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import {
        SCENARIO_RULE_LABELS,
        SCENARIO_VERDICT_LABELS,
        SCENARIO_VERDICT_TONES,
        formatPositions,
    } from "@/components/features/manual/scenario-report";
    import type { ScenarioReportProps } from "@/types/manual";
    import { currentOrgUrl } from "@/lib/org-url";

    /**
     * 生成結果の確認パネル (doc/03 §3.4 のバリデーション結果)。
     * - 所見 (LLM・解析時点のスナップショット) と 検査 (現在の cuts から決定的に算出) を
     *   **見出しで分けて**出す (鮮度が違うため)
     * - 判定は表示のみ。ボタンを disabled にしない / 保存・撮影を止めない
     * - 「有効でない」ときの行き先を必ず添える (編集する / 手順書を差し替えて再解析する)
     */
    interface Props {
        projectId: number;
        manualId: number;
        report: ScenarioReportProps;
        canManage: boolean;
    }

    let { projectId, manualId, report, canManage }: Props = $props();
</script>

<Card padding="lg" testId="scenario-report">
    <h2 class="text-h3">生成結果の確認</h2>

    {#if report.verdict}
        {@const verdict = report.verdict}
        <div class="mt-4">
            <h3 class="text-body font-medium">手順書への所見 (AI 解析時点)</h3>
            <div class="mt-2 flex items-center gap-3">
                <Badge tone={SCENARIO_VERDICT_TONES[verdict.verdict]} testId="scenario-verdict">
                    {SCENARIO_VERDICT_LABELS[verdict.verdict]}
                </Badge>
                <span class="text-caption text-text-secondary" data-testid="scenario-work-count">
                    作業 {verdict.work_count} 件
                </span>
            </div>
            <p class="mt-2 text-body" data-testid="scenario-verdict-reason">{verdict.reason}</p>
            {#if !verdict.is_current_document}
                <p class="mt-2 text-caption text-text-secondary" data-testid="scenario-verdict-stale">
                    この所見は解析時の手順書に対するものです。手順書を差し替えた場合は AI
                    解析をやり直してください。
                </p>
            {/if}
            <ul class="mt-2 list-disc pl-5 text-caption text-text-secondary" data-testid="scenario-works">
                <!-- key は index にする: works は LLM 由来で重複を禁止しておらず値は一意 key にできない -->
                {#each verdict.works as work, index (index)}
                    <li>{work}</li>
                {/each}
            </ul>
            {#if verdict.split_recommended}
                <div class="mt-3">
                    <Alert type="info" testId="scenario-split-recommended">
                        この手順書には複数の作業が含まれています。作業ごとにマニュアルを分けると撮影とナビゲーションが短くなります (「複製」から作業ごとに分けられます)。
                    </Alert>
                </div>
            {/if}
        </div>
    {/if}

    <div class="mt-4">
        <h3 class="text-body font-medium">シナリオの検査 (現在の内容)</h3>
        <p class="mt-2 text-body" data-testid="scenario-counts">
            カット構成: 手順 {report.counts.steps} / 急所 {report.counts.points} (合計 {report.counts
                .total})
        </p>

        {#if report.findings.length > 0}
            <ul class="mt-2 list-disc pl-5" data-testid="scenario-findings">
                {#each report.findings as finding (finding.code)}
                    <li class="text-body">
                        {SCENARIO_RULE_LABELS[finding.code]}: {finding.count} 件
                        <span class="text-caption text-text-secondary">
                            {formatPositions(finding.positions, finding.count)}
                        </span>
                    </li>
                {/each}
            </ul>
        {:else}
            <p class="mt-2 text-body" data-testid="scenario-findings-empty">
                シナリオの書式に関する指摘はありません。
            </p>
        {/if}
    </div>

    {#if canManage}
        <div class="mt-4">
            <Button
                variant="ghost"
                href={currentOrgUrl(`/projects/${projectId}/manuals/${manualId}/edit`)}
                inertia
                testId="scenario-report-edit-link"
            >
                シナリオを編集して確認する
            </Button>
        </div>
    {/if}
</Card>
