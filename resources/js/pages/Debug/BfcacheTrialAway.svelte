<script lang="ts">
    import { onMount } from "svelte";
    import { page } from "@inertiajs/svelte";
    import { SignpostBig } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import {
        deriveTrialPhase,
        loadTrials,
        type TrialScenario,
    } from "@/lib/debug/bfcache-trial";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * bfcache 実機受入確認 (T085) の相方ページ B。local / debug 限定。
     *
     * 責務は 2 つだけである (意図的に薄い):
     *   1. A から **full document navigation** で離脱した先として存在すること
     *      (これで A が bfcache に入る。Inertia visit では同一 Document のままで
     *       pagehide が起きず、経路 C になってしまう)
     *   2. 次に何をすべきかを画面に書くこと
     *
     * **logout 導線を新設しない。** AppLayout に元からあるユーザーメニューの logout
     * (tests/js/architecture/logout-call-site-inventory.test.ts に登録済みの既存 call site) を
     * そのまま使う。JSON 204 で完結する logout を足すと Inertia の履歴鍵が消えず、
     * 経路 C の保証が壊れる。
     *
     * **B では観測しない。** 判定対象は A の lifecycle だけである。
     */

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let activeTrialId = $state<string | null>(null);
    let activeScenario = $state<TrialScenario | null>(null);

    onMount(() => {
        for (const [trialId, events] of loadTrials()) {
            const phase = deriveTrialPhase(events);
            if (phase === "complete" || phase === "aborted" || phase === "invalid") {
                continue;
            }
            const started = events.find((event) => event.type === "trial-started");
            if (started === undefined || started.type !== "trial-started") continue;
            activeTrialId = trialId;
            activeScenario = started.scenario;
        }
    });
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="bfcache 検証: 相方ページ"
            description="ここに来た時点で検証ページ A が bfcache に入ります (local / debug 限定)"
            icon={SignpostBig}
        />
        <PageContent>
            <div class="space-y-6">
                {#if activeTrialId === null}
                    <Alert variant="warning" testId="bfcache-away-no-trial">
                        進行中の試行が見つかりません。検証ページ A で試行を開始してから、
                        A のリンク経由でこのページへ来てください。
                    </Alert>
                {:else}
                    <Alert variant="info" testId="bfcache-away-trial">
                        進行中の試行: <code>{activeTrialId.slice(0, 8)}</code>
                        {#if activeScenario !== null}
                            / シナリオ:
                            {activeScenario === "expired-session"
                                ? "失効セッション経路 (本試行)"
                                : "有効セッション経路 (正のコントロール)"}
                        {/if}
                    </Alert>
                {/if}

                <Card padding="lg">
                    <h2 class="text-h2">次の操作</h2>

                    {#if activeScenario === "active-session"}
                        <p class="mt-2 text-body">
                            <strong>ログアウトしません。</strong>このまま
                            <strong>履歴から検証ページ A を選んで復帰</strong>してください。
                        </p>
                        <p class="mt-2 text-caption text-text-secondary">
                            期待: guard が秘匿 → 検証 → 秘匿解除まで進み、DOM とフォーム状態が温存されること
                            (撮影導線を壊していないことの確認)。
                        </p>
                    {:else}
                        <ol class="mt-2 list-decimal space-y-2 pl-5 text-body">
                            <li>
                                左のサイドバー下部の<strong>ユーザーメニューからログアウト</strong>する
                                (このページ独自のログアウトボタンは用意していません)
                            </li>
                            <li><strong>履歴から検証ページ A を選んで復帰</strong>する</li>
                            <li>guard が /login へ倒したら、<strong>その画面を撮影</strong>する (証跡 1 枚目)</li>
                            <li><code>/debug/login</code> で入り直す</li>
                            <li>A を開き、<strong>「/login 到達を記録する」</strong>を押す</li>
                            <li>A の stored report を撮影する (証跡 2 枚目)</li>
                        </ol>
                    {/if}

                    <Alert variant="warning" class="mt-4" testId="bfcache-away-back-notice">
                        <strong>戻る 1 回では A に戻りません。</strong>
                        ログアウトは Inertia visit なので履歴が積まれます。履歴一覧から A を選んでください。
                    </Alert>
                </Card>

                <Card padding="lg">
                    <h2 class="text-h2">検証ページ A へのリンク</h2>
                    <p class="mt-2 text-caption text-text-secondary">
                        <strong>これは復帰手段ではありません。</strong>
                        クリックすると新しい履歴エントリになり、bfcache 復元になりません
                        (軸 1 が invalid-not-bfcache として機械的に検出します)。
                        試行をやり直すときだけ使ってください。
                    </p>
                    <p class="mt-3">
                        <a
                            href="/debug/bfcache-trial"
                            class="text-body text-primary underline"
                            data-testid="bfcache-away-restart-link"
                        >
                            検証ページ A を開き直す (試行のやり直し用)
                        </a>
                    </p>
                </Card>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
