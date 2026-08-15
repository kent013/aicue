<script lang="ts">
    import { onMount } from "svelte";
    import { page } from "@inertiajs/svelte";
    import { ShieldQuestion } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { BFCACHE_HIDDEN_ATTRIBUTE } from "@/lib/bfcache-guard";
    import {
        DEVICE_MODEL_MAX_LENGTH,
        TRIAL_SCHEMA_VERSION,
        TRIAL_STORAGE_KEY,
        VERIFIED_OS_VERSION_MAX_LENGTH,
        appendEvent,
        canAppend,
        deriveGuardVerdict,
        deriveOverallVerdict,
        deriveTrialPhase,
        deriveTrialVerdict,
        expectedGuardVerdict,
        isValidDeviceModel,
        isValidVerifiedOsVersion,
        loadTrials,
        nextSequence,
        normalizeUserReported,
        probeStorageWritable,
        readTrialLog,
        type GuardState,
        type TrialEvent,
        type TrialEventType,
        type TrialPhase,
        type TrialScenario,
    } from "@/lib/debug/bfcache-trial";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * bfcache 実機受入確認 (T085) の検証ページ A。local / debug 限定。
     *
     * **なぜ要るのか**: T085 の実機手順は素の目視確認であり、「guard が働いた」と
     * 「そもそも bfcache 復元が起きなかった」を区別できない。どちらも「PII が出ない」に
     * 見えるため、空振りを合格として記録しうる。同じ欠陥は Playwright レーンについては
     * 潰されている (「空振りを green と偽らない」)。その規律を実機レーンへ揃える。
     *
     * **観測するだけで、検証対象は一切変更しない**。guard は app.ts が登録した本物が
     * そのまま動く。ここでは documentElement の秘匿属性を MutationObserver で見るだけ。
     *
     * 判定は二軸 + 総合 (詳細は lib/debug/bfcache-trial.ts):
     *   軸 1 = bfcache 復元が本当に起きたか / 軸 2 = guard がどう振る舞ったか
     * 混ぜると受入失敗を PASS と読むので分けてある。
     *
     * **軸 2 の unauthenticated-redirected だけは自動判定できない。** guard は
     * location.replace('/login') を呼ぶだけで、A からは離脱先が観測できないため、
     * 利用者の手動記録 (manual confirmation) を必須にしている。
     */

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /**
     * JS 実行コンテキスト生存トークン。**onMount で 1 回だけ生成する**。
     * bfcache 復元では component が再生成されないため onMount は再実行されず値が残る =
     * 「Document が再実行されていない」ことの証拠になる。fresh load でのみ変わる。
     * (module scope で評価すると SSR / テスト import 時に壊れる)
     */
    let contextToken = $state<string | null>(null);
    let secureContextReady = $state(true);
    let storageWritable = $state(true);
    let logDiscarded = $state(false);

    let events = $state<TrialEvent[]>([]);
    let notice = $state<string | null>(null);

    let scenario = $state<TrialScenario>("expired-session");
    let deviceModel = $state("");
    let verifiedOsVersion = $state("");

    /** 進行中の試行 (phase が終端していないもの)。無ければ stored report モード。 */
    const activeTrialId = $derived.by(() => {
        let candidate: string | null = null;
        let bestSequence = -1;
        for (const [trialId, trialEvents] of groupEvents(events)) {
            const phase = deriveTrialPhase(trialEvents);
            if (phase === "complete" || phase === "aborted" || phase === "invalid") {
                continue;
            }
            const first = Math.min(...trialEvents.map((event) => event.sequence));
            if (first > bestSequence) {
                bestSequence = first;
                candidate = trialId;
            }
        }
        return candidate;
    });

    const mode = $derived(activeTrialId === null ? "stored report" : "live observation");
    const trials = $derived([...groupEvents(events)].reverse());

    /**
     * trialId ごとに分離する。**Map を返さない** — reactive な文脈で組み込み Map を
     * 持つと svelte/prefer-svelte-reactivity に触れる。順序が要るだけなので tuple 配列で足りる。
     */
    function groupEvents(all: TrialEvent[]): Array<[string, TrialEvent[]]> {
        const grouped: Array<[string, TrialEvent[]]> = [];
        for (const event of [...all].sort((a, b) => a.sequence - b.sequence)) {
            const bucket = grouped.find(([trialId]) => trialId === event.trialId);
            if (bucket === undefined) {
                grouped.push([event.trialId, [event]]);
                continue;
            }
            bucket[1].push(event);
        }
        return grouped;
    }

    function refresh(): void {
        const stored = readTrialLog();
        logDiscarded = stored === null && hasStoredPayload();
        events = [...loadTrials().values()].flat();
    }

    /**
     * 証跡キーが存在するか。**sessionStorage.length を見ない**
     * (Inertia など別キーがあるだけで「証跡が壊れていた」と誤表示してしまう)。
     */
    function hasStoredPayload(): boolean {
        try {
            return globalThis.sessionStorage.getItem(TRIAL_STORAGE_KEY) !== null;
        } catch {
            return false;
        }
    }

    function displayMode(): string {
        if (typeof globalThis.matchMedia !== "function") return "unknown";
        for (const candidate of ["standalone", "fullscreen", "minimal-ui", "browser"]) {
            if (globalThis.matchMedia(`(display-mode: ${candidate})`).matches) {
                return candidate;
            }
        }
        return "unknown";
    }

    /** iOS Safari の非標準 API。any に逃がさず型を切る。 */
    interface NavigatorWithStandalone extends Navigator {
        standalone?: boolean;
    }

    function navigatorStandalone(): boolean | null {
        const value = (navigator as NavigatorWithStandalone).standalone;
        return typeof value === "boolean" ? value : null;
    }

    /**
     * UA から読み取れる OS。**確定した OS バージョンとして扱わない**
     * (UA reduction / iPadOS の desktop-class UA / standalone と Safari の差がある)。
     * 確定値は利用者申告の verifiedOsVersion 側が持つ。
     */
    function uaReportedOs(): string {
        const match = navigator.userAgent.match(
            /(iPhone OS|CPU OS|Mac OS X|Android)\s+([0-9_.]+)/,
        );
        return match === null ? "unknown" : `${match[1]} ${match[2].replace(/_/g, ".")}`;
    }

    /** 現在の試行に 1 イベント追記する。phase で許可されない場合は理由を表示する。 */
    function record(
        trialId: string,
        build: (base: {
            schemaVersion: number;
            trialId: string;
            sequence: number;
            timestamp: string;
        }) => TrialEvent,
        type: TrialEventType,
        options: { silent?: boolean } = {},
    ): boolean {
        const stored = readTrialLog() ?? [];
        const trialEvents = stored.filter((event) => event.trialId === trialId);
        const phase = deriveTrialPhase(trialEvents);

        if (!canAppend(phase, type)) {
            if (options.silent !== true) {
                notice = `この試行では「${type}」を記録できません (状態: ${phaseLabel(phase)})。`;
            }
            return false;
        }

        const event = build({
            schemaVersion: TRIAL_SCHEMA_VERSION,
            trialId,
            sequence: nextSequence(stored, trialId),
            timestamp: new Date().toISOString(),
        });

        const saved = appendEvent(event);
        if (!saved) {
            notice = "証跡の保存に失敗しました。この試行は証跡を回収できません (unrecordable)。";
        }
        refresh();
        return saved;
    }

    function startTrial(): void {
        notice = null;

        if (!secureContextReady) {
            notice = "secure context が必要です。この環境では検証できません。";
            return;
        }

        const model = normalizeUserReported(deviceModel);
        const version = normalizeUserReported(verifiedOsVersion);

        if (model === "" || !isValidDeviceModel(model)) {
            notice = `端末モデルを英数字と - , ( ) . の範囲・${DEVICE_MODEL_MAX_LENGTH} 文字以内で入力してください。`;
            return;
        }
        if (version === "" || !isValidVerifiedOsVersion(version)) {
            notice = `OS バージョンを英数字と . の範囲・${VERIFIED_OS_VERSION_MAX_LENGTH} 文字以内で入力してください。`;
            return;
        }
        if (activeTrialId !== null) {
            notice = "進行中の試行があります。中止してから新しい試行を開始してください。";
            return;
        }
        if (!probeStorageWritable()) {
            storageWritable = false;
            notice =
                "sessionStorage に書き込めません (unrecordable)。この状態では試行を開始しません。";
            return;
        }

        const token = contextToken;
        if (token === null) return;

        const stored = readTrialLog() ?? [];
        const trialId = globalThis.crypto.randomUUID();
        const event: TrialEvent = {
            schemaVersion: TRIAL_SCHEMA_VERSION,
            trialId,
            sequence: nextSequence(stored, trialId),
            timestamp: new Date().toISOString(),
            type: "trial-started",
            scenario,
            contextToken: token,
            userAgent: navigator.userAgent,
            uaReportedOs: uaReportedOs(),
            displayMode: displayMode(),
            navigatorStandalone: navigatorStandalone(),
            deviceModel: model,
            verifiedOsVersion: version,
        };

        if (!appendEvent(event)) {
            notice = "証跡の保存に失敗しました (unrecordable)。試行を開始しません。";
            return;
        }
        refresh();
    }

    function leaveToAway(event: MouseEvent): void {
        const trialId = activeTrialId;
        if (trialId === null) {
            event.preventDefault();
            notice = "進行中の試行がありません。先に試行を開始してください。";
            return;
        }
        // 操作事実のみを同期記録する。page-hide の不在から離脱失敗を推論しない
        const saved = record(
            trialId,
            (base) => ({ ...base, type: "away-navigation-started" }),
            "away-navigation-started",
        );
        // 記録できないまま離脱すると証跡に穴が空いたまま A が bfcache に入る。
        // 証跡ツールとしては、そこで進ませない方が正しい
        if (!saved) event.preventDefault();
    }

    function recordManual(type: "redirect-observed" | "away-navigation-failed"): void {
        notice = null;
        const trialId = activeTrialId;
        if (trialId === null) {
            notice = "進行中の試行がありません。";
            return;
        }
        record(trialId, (base) => ({ ...base, type, observationMethod: "manual" }), type);
    }

    function abortTrial(): void {
        notice = null;
        const trialId = activeTrialId;
        if (trialId === null) {
            notice = "進行中の試行がありません。";
            return;
        }
        record(trialId, (base) => ({ ...base, type: "trial-aborted" }), "trial-aborted");
    }

    function copyReport(): void {
        notice = null;
        // 未提供環境では同期例外になりうるため、呼ぶ前に存在を確かめる
        if (typeof navigator.clipboard?.writeText !== "function") {
            notice = "この環境ではクリップボードを使えません。画面の内容を撮影してください。";
            return;
        }
        void navigator.clipboard
            .writeText(reportText())
            .then(() => {
                notice = "証跡テキストをコピーしました。";
            })
            .catch(() => {
                notice = "クリップボードにコピーできませんでした。";
            });
    }

    function reportText(): string {
        const lines: string[] = [`# bfcache 実機受入確認の証跡 (${mode})`, ""];
        for (const [trialId, trialEvents] of trials) {
            const started = trialEvents.find((event) => event.type === "trial-started");
            if (started === undefined || started.type !== "trial-started") continue;
            lines.push(`## trial ${trialId}`);
            lines.push(`- シナリオ: ${scenarioLabel(started.scenario)}`);
            lines.push(`- 自動観測 UA: ${started.userAgent}`);
            lines.push(`- 自動観測 UA reported OS: ${started.uaReportedOs}`);
            lines.push(`- 自動観測 display-mode: ${started.displayMode}`);
            lines.push(`- 自動観測 navigator.standalone: ${started.navigatorStandalone}`);
            lines.push(`- 利用者申告 端末モデル: ${started.deviceModel}`);
            lines.push(`- 利用者申告 OS バージョン: ${started.verifiedOsVersion}`);
            lines.push(`- 軸1 試行成立: ${deriveTrialVerdict(trialEvents)}`);
            lines.push(`- 軸2 guard 結果: ${deriveGuardVerdict(trialEvents)}`);
            lines.push(
                `- 総合: ${deriveOverallVerdict(started.scenario, deriveTrialVerdict(trialEvents), deriveGuardVerdict(trialEvents))}`,
            );
            lines.push(`- 期待 guard 結果: ${expectedGuardVerdict(started.scenario)}`);
            lines.push("- イベント:");
            for (const event of trialEvents) {
                lines.push(`  - [${event.sequence}] ${event.timestamp} ${event.type}`);
            }
            lines.push("");
        }
        return lines.join("\n");
    }

    function phaseLabel(phase: TrialPhase): string {
        const labels: Record<TrialPhase, string> = {
            invalid: "不正 (複数試行の混入)",
            "collecting-axis1": "軸1 観測中",
            "collecting-axis2": "軸2 観測中",
            "awaiting-manual-confirmation": "手動確認待ち",
            complete: "完了",
            aborted: "中止",
        };
        return labels[phase];
    }

    function scenarioLabel(value: TrialScenario): string {
        return value === "expired-session"
            ? "失効セッション経路 (本試行)"
            : "有効セッション経路 (正のコントロール)";
    }

    function guardStateOf(): GuardState {
        const value = document.documentElement.getAttribute(BFCACHE_HIDDEN_ATTRIBUTE);
        if (
            value === "pending" ||
            value === "verifying" ||
            value === "retry" ||
            value === "reloading"
        ) {
            return value;
        }
        return null;
    }

    onMount(() => {
        if (typeof globalThis.crypto?.randomUUID !== "function") {
            secureContextReady = false;
            return;
        }
        contextToken = globalThis.crypto.randomUUID();
        storageWritable = probeStorageWritable();
        refresh();

        const onPageHide = (event: Event): void => {
            const trialId = activeTrialId;
            if (trialId === null) return;
            record(
                trialId,
                (base) => ({
                    ...base,
                    type: "page-hide",
                    persisted: (event as PageTransitionEvent).persisted,
                    guardState: guardStateOf(),
                }),
                "page-hide",
                { silent: true },
            );
        };

        const onPageShow = (event: Event): void => {
            const trialId = activeTrialId;
            const token = contextToken;
            if (trialId === null || token === null) return;
            record(
                trialId,
                (base) => ({
                    ...base,
                    type: "page-show",
                    persisted: (event as PageTransitionEvent).persisted,
                    contextToken: token,
                    displayMode: displayMode(),
                }),
                "page-show",
                { silent: true },
            );
        };

        // 秘匿属性の変化を外から観測する (guard には手を入れない)
        const observer = new MutationObserver(() => {
            const trialId = activeTrialId;
            if (trialId === null) return;
            record(
                trialId,
                (base) => ({ ...base, type: "guard-state-changed", state: guardStateOf() }),
                "guard-state-changed",
                { silent: true },
            );
        });
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: [BFCACHE_HIDDEN_ATTRIBUTE],
        });

        // unload / beforeunload は使わない (bfcache の適格性を壊す。architecture テストが固定)
        window.addEventListener("pagehide", onPageHide);
        window.addEventListener("pageshow", onPageShow);

        return () => {
            observer.disconnect();
            window.removeEventListener("pagehide", onPageHide);
            window.removeEventListener("pageshow", onPageShow);
        };
    });
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="bfcache 実機受入確認"
            description="T085 の実機確認を空振りと区別するための観測ページ (local / debug 限定)"
            icon={ShieldQuestion}
        />
        <PageContent>
            {#if !secureContextReady}
                <Alert variant="danger" testId="bfcache-trial-insecure">
                    secure context が必要です。この環境では検証できません。HTTPS で開き直してください
                    (平文 http で試すと本番と違う条件を見て「確認済み」と記録する事故になります)。
                </Alert>
            {:else}
                <div class="space-y-6">
                    <Alert variant="info" testId="bfcache-trial-mode">
                        現在のモード: <strong>{mode}</strong>
                        {#if activeTrialId !== null}
                            / 進行中の試行: <code>{activeTrialId.slice(0, 8)}</code>
                        {/if}
                    </Alert>

                    {#if !storageWritable}
                        <Alert variant="danger" testId="bfcache-trial-unrecordable">
                            sessionStorage に書き込めません (unrecordable)。証跡を回収できないため
                            試行を開始しません。
                        </Alert>
                    {/if}

                    {#if logDiscarded}
                        <Alert variant="warning" testId="bfcache-trial-log-discarded">
                            保存済み証跡の形式が壊れていたため破棄しました (部分採用はしません)。
                        </Alert>
                    {/if}

                    {#if notice !== null}
                        <Alert variant="warning" testId="bfcache-trial-notice">{notice}</Alert>
                    {/if}

                    <Card padding="lg">
                        <h2 class="text-h2">新しい試行を開始する</h2>
                        <p class="mt-2 text-caption text-text-secondary">
                            端末モデルと OS バージョンは UA から確定できないため手入力します
                            (UA reduction / iPadOS の desktop-class UA があるため)。
                            <strong>氏名などの個人情報は入力しないでください。</strong>
                        </p>

                        <div class="mt-4 space-y-4">
                            <FormField label="検証シナリオ" htmlFor="bfcache-trial-scenario">
                                <Select id="bfcache-trial-scenario" bind:value={scenario}>
                                    <option value="expired-session"
                                        >失効セッション経路 (本試行)</option
                                    >
                                    <option value="active-session"
                                        >有効セッション経路 (正のコントロール)</option
                                    >
                                </Select>
                            </FormField>

                            <FormField label="端末モデル (利用者申告)" htmlFor="bfcache-trial-device">
                                <Input
                                    id="bfcache-trial-device"
                                    bind:value={deviceModel}
                                    placeholder="iPhone 15 Pro"
                                    testId="bfcache-trial-device"
                                />
                            </FormField>

                            <FormField
                                label="確認済み OS バージョン (利用者申告)"
                                htmlFor="bfcache-trial-os"
                            >
                                <Input
                                    id="bfcache-trial-os"
                                    bind:value={verifiedOsVersion}
                                    placeholder="18.2"
                                    testId="bfcache-trial-os"
                                />
                            </FormField>

                            <Button onclick={startTrial} testId="bfcache-trial-start">
                                試行を開始する
                            </Button>
                        </div>
                    </Card>

                    <Card padding="lg">
                        <h2 class="text-h2">操作</h2>
                        <p class="mt-2 text-caption text-text-secondary">
                            下のリンクは <strong>plain な a 要素</strong>です (Inertia の Link
                            ではありません)。full document navigation でないと A が bfcache に入らないためです。
                        </p>
                        <p class="mt-2 text-caption text-text-secondary">
                            戻るときは<strong>履歴から A を選んで復帰</strong>してください。
                            相方ページでログアウトすると Inertia が履歴を積むため、戻る 1 回では A に戻りません。
                        </p>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <a
                                href="/debug/bfcache-trial/away"
                                class="text-body text-primary underline"
                                data-testid="bfcache-trial-away-link"
                                onclick={leaveToAway}
                            >
                                相方ページへ移動する (full reload)
                            </a>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <Button
                                variant="ghost"
                                onclick={() => recordManual("redirect-observed")}
                                testId="bfcache-trial-record-redirect"
                            >
                                /login 到達を記録する (手動確認)
                            </Button>
                            <Button
                                variant="ghost"
                                onclick={() => recordManual("away-navigation-failed")}
                                testId="bfcache-trial-record-away-failed"
                            >
                                離脱失敗を記録する (手動確認)
                            </Button>
                            <Button
                                variant="ghost"
                                onclick={abortTrial}
                                testId="bfcache-trial-abort"
                            >
                                試行を中止する
                            </Button>
                            <Button
                                variant="neutral"
                                onclick={copyReport}
                                testId="bfcache-trial-copy"
                            >
                                証跡テキストをコピー
                            </Button>
                        </div>
                    </Card>

                    <!--
                        オーバーレイが覆う対象。明らかに偽物と分かる固定文字列にしてある
                        (証跡を devnotes に貼るため、本物めいた個人情報を写り込ませない)。
                        この文字列自体は sessionStorage に保存しない (allowlist 外)。
                    -->
                    <Card padding="lg" testId="bfcache-trial-fake-pii">
                        <h2 class="text-h2">ダミー PII (架空データ)</h2>
                        <dl class="mt-3 space-y-1 text-body">
                            <div><dt class="inline">氏名:</dt> <dd class="inline">架空 太郎</dd></div>
                            <div>
                                <dt class="inline">メール:</dt>
                                <dd class="inline">example-not-real@invalid.test</dd>
                            </div>
                            <div><dt class="inline">電話:</dt> <dd class="inline">000-0000-0000</dd></div>
                        </dl>
                    </Card>

                    {#each trials as [trialId, trialEvents] (trialId)}
                        {@const started = trialEvents.find((e) => e.type === "trial-started")}
                        {#if started !== undefined && started.type === "trial-started"}
                            {@const trialVerdict = deriveTrialVerdict(trialEvents)}
                            {@const guardVerdict = deriveGuardVerdict(trialEvents)}
                            <Card padding="lg">
                                <h2 class="text-h2">
                                    trial <code>{trialId.slice(0, 8)}</code>
                                    <span class="text-caption text-text-secondary">
                                        ({trialId === activeTrialId
                                            ? "live observation"
                                            : "stored report"})
                                    </span>
                                </h2>

                                <dl class="mt-3 grid gap-1 text-body">
                                    <div>
                                        <dt class="inline">シナリオ:</dt>
                                        <dd class="inline">{scenarioLabel(started.scenario)}</dd>
                                    </div>
                                    <div>
                                        <dt class="inline">状態:</dt>
                                        <dd class="inline">
                                            {phaseLabel(deriveTrialPhase(trialEvents))}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="inline">軸1 試行成立:</dt>
                                        <dd class="inline" data-testid="bfcache-trial-verdict">
                                            {trialVerdict}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="inline">軸2 guard 結果:</dt>
                                        <dd class="inline" data-testid="bfcache-guard-verdict">
                                            {guardVerdict}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="inline">総合:</dt>
                                        <dd class="inline" data-testid="bfcache-overall-verdict">
                                            {deriveOverallVerdict(
                                                started.scenario,
                                                trialVerdict,
                                                guardVerdict,
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="inline">期待 guard 結果:</dt>
                                        <dd class="inline">
                                            {expectedGuardVerdict(started.scenario)}
                                        </dd>
                                    </div>
                                </dl>

                                {#if guardVerdict === "unauthenticated-redirected"}
                                    <p class="mt-3 text-caption text-text-secondary">
                                        この判定は <strong>manual confirmation</strong> を含みます
                                        (guard の離脱先は A から観測できないため、/login 到達は利用者の確認記録によります)。
                                    </p>
                                {/if}
                                {#if guardVerdict === "hidden-then-left"}
                                    <p class="mt-3 text-caption text-text-secondary">
                                        秘匿を維持したまま A から離脱しました。<strong
                                            >/login に着地したことを確認して記録</strong
                                        >すると判定が確定します。
                                    </p>
                                {/if}
                                {#if guardVerdict === "stale-session-reloaded"}
                                    <p class="mt-3 text-caption text-text-secondary">
                                        秘匿を維持したまま同じ URL を読み直しました。<strong
                                            >/login に着地したことを確認して記録</strong
                                        >すると判定が確定します
                                        (読み直しの着地先は A から観測できません)。
                                    </p>
                                {/if}

                                <h3 class="mt-4 text-h3">自動観測</h3>
                                <ul class="mt-1 text-caption text-text-secondary">
                                    <li>UA: {started.userAgent}</li>
                                    <li>UA reported OS: {started.uaReportedOs}</li>
                                    <li>display-mode: {started.displayMode}</li>
                                    <li>navigator.standalone: {String(started.navigatorStandalone)}</li>
                                </ul>

                                <h3 class="mt-4 text-h3">利用者申告</h3>
                                <ul class="mt-1 text-caption text-text-secondary">
                                    <li>端末モデル: {started.deviceModel}</li>
                                    <li>確認済み OS バージョン: {started.verifiedOsVersion}</li>
                                </ul>

                                <h3 class="mt-4 text-h3">観測イベント</h3>
                                <ol class="mt-1 space-y-1 text-caption text-text-secondary">
                                    {#each trialEvents as event (event.sequence)}
                                        <li>
                                            [{event.sequence}] {event.timestamp} — {event.type}
                                            {#if event.type === "page-hide" || event.type === "page-show"}
                                                (persisted: {String(event.persisted)})
                                            {/if}
                                            {#if event.type === "guard-state-changed"}
                                                (state: {String(event.state)})
                                            {/if}
                                        </li>
                                    {/each}
                                </ol>
                            </Card>
                        {/if}
                    {/each}
                </div>
            {/if}
        </PageContent>
    </PageContainer>
</AppLayout>
