<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { ChevronDown, ChevronUp, ListPlus, Plus, Trash2 } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import { csrfToken } from "@/lib/csrf";
    import { addToast } from "@/lib/stores/toast";
    import type {
        DraftPoint,
        DraftStep,
        ScenarioConflictBody,
        ScenarioDocument,
        ScenarioStep,
    } from "@/types/manual";

    /**
     * シナリオ (手順 step / 急所 point の 2 階層ツリー) の document 編集エディタ。
     * クライアントは作業コピー (DraftStep[]) を編集し、「シナリオを更新」で document 全体を
     * 1 回の PUT で送信する (doc/09 §9.4)。楽観ロックの 409 / 行別 422 は自前で描画する。
     * parent_cut_id / sort_order / type は payload に含めない (サーバ導出。doc/10 §10.8-5)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        scenario: ScenarioDocument;
    }

    let { projectId, manualId, scenario }: Props = $props();

    /** サーバ shape → 編集用作業コピー (新しい配列/オブジェクトに clone し props と分離する) */
    function toDraftSteps(steps: ScenarioStep[]): DraftStep[] {
        return steps.map((step) => ({
            ...rowOf(step),
            id: step.id,
            points: step.points.map((point) => ({ ...rowOf(point), id: point.id })),
        }));
    }

    /** 本文フィールドのみの正規形 (キー順固定。payload / dirty 比較の共通基盤) */
    function rowOf(row: Omit<DraftPoint, "id">): Omit<DraftPoint, "id"> {
        return {
            scene: row.scene,
            shot_type: row.shot_type,
            shooting_point: row.shooting_point,
            narration: row.narration,
            subtitle_primary: row.subtitle_primary,
            subtitle_secondary: row.subtitle_secondary,
            material_type: row.material_type,
            static_display_seconds: row.static_display_seconds,
        };
    }

    /**
     * PUT payload の steps を組み立てる (呼び出しごとに新しい配列/オブジェクトを生成)。
     * parent_cut_id / sort_order / type は含めない (サーバ導出)。
     */
    function payloadSteps(): Array<Record<string, unknown>> {
        return steps.map((step) => ({
            id: step.id,
            ...rowOf(step),
            points: step.points.map((point) => ({ id: point.id, ...rowOf(point) })),
        }));
    }

    /** 正規化シリアライザ (キー順固定・payload 対象フィールドのみ)。比較と送信の正規形を一本化する */
    function serializeSteps(list: DraftStep[]): string {
        return JSON.stringify(
            list.map((step) => ({
                id: step.id,
                ...rowOf(step),
                points: step.points.map((point) => ({ id: point.id, ...rowOf(point) })),
            })),
        );
    }

    let version = $state(scenario.scenario_version);
    let steps = $state<DraftStep[]>(toDraftSteps(scenario.steps));
    /** 保存済みスナップショット (正規形の JSON 文字列。$state proxy と参照を共有しない) */
    let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
    let saving = $state(false);
    let errors = $state<Record<string, string[]>>({});
    let conflict = $state<ScenarioConflictBody | null>(null);
    let genericError = $state<string | null>(null);
    let confirmingStepIndex = $state<number | null>(null);
    let confirmingReload = $state(false);

    const dirty = $derived(serializeSteps(steps) !== snapshot);

    /** 新規行の空値 (scene のみ必須のため空で作る) */
    function emptyRow(shotType: "hiki" | "yori"): Omit<DraftPoint, "id"> {
        return {
            scene: "",
            shot_type: shotType,
            shooting_point: null,
            narration: "",
            subtitle_primary: null,
            subtitle_secondary: "",
            material_type: null,
            static_display_seconds: null,
        };
    }

    function addStep(): void {
        steps.push({ ...emptyRow("hiki"), id: null, points: [] });
    }

    function addPoint(stepIndex: number): void {
        steps[stepIndex].points.push({ ...emptyRow("yori"), id: null });
    }

    function removeStep(index: number): void {
        steps.splice(index, 1);
        confirmingStepIndex = null;
    }

    function removePoint(stepIndex: number, pointIndex: number): void {
        steps[stepIndex].points.splice(pointIndex, 1);
    }

    /** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
    function moveStep(index: number, delta: -1 | 1): void {
        const next = index + delta;
        if (next < 0 || next >= steps.length) return;
        [steps[index], steps[next]] = [steps[next], steps[index]];
    }

    function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
        const points = steps[stepIndex].points;
        const next = index + delta;
        if (next < 0 || next >= points.length) return;
        [points[index], points[next]] = [points[next], points[index]];
    }

    async function save(): Promise<void> {
        if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
        saving = true;
        errors = {};
        conflict = null;
        genericError = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
        try {
            const res = await putScenario();
            await handleResponse(res);
        } catch {
            // ネットワーク断・fetch reject (419 回復 GET / 再試行 PUT の reject も含む)。
            // 作業コピーは保持したまま汎用エラーを表示 (未処理 Promise を漏らさない)
            genericError = "通信に失敗しました。接続を確認して再度お試しください。";
        } finally {
            saving = false;
        }
    }

    async function putScenario(): Promise<Response> {
        return fetch(`/projects/${projectId}/manuals/${manualId}/scenario`, {
            method: "PUT",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": csrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({ expected_version: version, steps: payloadSteps() }),
        });
    }

    async function handleResponse(res: Response, retried = false): Promise<void> {
        if (res.ok) {
            // 成功応答も実行時検証 (JSON 破損・期待外 shape は汎用エラーへフォールバック)
            const body = (await res.json().catch(() => null)) as unknown;
            if (isScenarioDocument(body)) {
                applySaved(body);
                return;
            }
            genericError = "保存結果の取得に失敗しました。画面を再読み込みしてください。";
            return;
        }
        if (res.status === 419 && !retried) {
            // CSRF 失効: cookie を再取得して 1 回だけ自動リトライ (doc/10 §10.8-3 の共通処理方針)
            await fetch(window.location.pathname, {
                credentials: "same-origin",
                headers: { Accept: "text/html" },
            });
            await handleResponse(await putScenario(), true);
            return;
        }
        if (res.status === 401 || res.status === 419) {
            // セッション失効: 作業コピーは破棄せず、別タブでの再ログインを案内 (リダイレクトしない)
            genericError =
                "セッションが切れました。別のタブでログインし直してから、もう一度保存してください。";
            return;
        }
        if (res.status === 409) {
            const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
            if (body?.code === "scenario_conflict") {
                conflict = body; // 作業コピーは保持 (黙って編集内容を失わない)
                return;
            }
        }
        if (res.status === 422) {
            // Laravel 標準 { errors: Record<string, string[]> } を実行時に判別 (防御的パース)
            const body = (await res.json().catch(() => null)) as { errors?: unknown } | null;
            if (body !== null && isValidationErrors(body.errors)) {
                errors = body.errors; // "steps.0.points.1.scene" 形式のキーを行別セルに表示
                return;
            }
        }
        genericError = "保存に失敗しました。時間をおいて再度お試しください。";
    }

    /** 成功応答の取り込み: 確定 id + version + スナップショット更新 + 成功トースト */
    function applySaved(document: ScenarioDocument): void {
        version = document.scenario_version;
        steps = toDraftSteps(document.steps);
        snapshot = serializeSteps(steps);
        addToast("success", "シナリオを保存しました");
    }

    /** Record<string, string[]> かを実行時検証する type guard */
    function isValidationErrors(value: unknown): value is Record<string, string[]> {
        if (value === null || typeof value !== "object" || Array.isArray(value)) return false;
        return Object.values(value).every(
            (messages) =>
                Array.isArray(messages) && messages.every((m) => typeof m === "string"),
        );
    }

    /** 成功応答 (scenario_version: number + steps 配列) の type guard */
    function isScenarioDocument(value: unknown): value is ScenarioDocument {
        if (value === null || typeof value !== "object") return false;
        const doc = value as { scenario_version?: unknown; steps?: unknown };
        return typeof doc.scenario_version === "number" && Array.isArray(doc.steps);
    }

    /** 409 バナーからの明示同意リロード (編集内容の破棄を ConfirmDialog で確認済み) */
    function reloadScenario(): void {
        confirmingReload = false;
        conflict = null;
        router.reload({ only: ["scenario", "manual"] });
    }

    // dirty 離脱警告: beforeunload + Inertia before イベント (dirty 時 confirm)
    $effect(() => {
        const onBeforeUnload = (event: BeforeUnloadEvent): void => {
            if (dirty) event.preventDefault();
        };
        window.addEventListener("beforeunload", onBeforeUnload);
        const offBefore = router.on("before", (event) => {
            if (
                dirty &&
                !window.confirm("シナリオの変更が保存されていません。ページを離れますか?")
            ) {
                event.preventDefault();
            }
        });
        return () => {
            window.removeEventListener("beforeunload", onBeforeUnload);
            offBefore();
        };
    });

    function fieldError(prefix: string, field: string): string | undefined {
        return errors[`${prefix}.${field}`]?.[0];
    }

    /** 数値 input (string) → number | null 変換 (空文字は null) */
    function toSeconds(value: string): number | null {
        const parsed = Number.parseInt(value, 10);
        return Number.isNaN(parsed) ? null : parsed;
    }

    const CONFLICT_TITLES: Record<ScenarioConflictBody["conflict_type"], string> = {
        version_mismatch: "他の編集と競合しました",
        rendering: "処理中のため保存できません",
        analyzing: "処理中のため保存できません",
    };
</script>

{#snippet rowFields(row: DraftPoint, prefix: string, idPrefix: string)}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="シーン (何を撮るか)" id="{idPrefix}-scene" error={fieldError(prefix, "scene")} required>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    bind:value={row.scene}
                    error={invalid}
                    aria-describedby={describedBy}
                    testId="{idPrefix}-scene"
                />
            {/snippet}
        </FormField>
        <div class="grid grid-cols-2 gap-3">
            <FormField label="画角" id="{idPrefix}-shot-type" error={fieldError(prefix, "shot_type")}>
                {#snippet children({ id, describedBy, invalid })}
                    <Select
                        {id}
                        bind:value={row.shot_type}
                        error={invalid}
                        aria-describedby={describedBy}
                    >
                        <option value="hiki">引き (全体)</option>
                        <option value="yori">寄り (手元)</option>
                    </Select>
                {/snippet}
            </FormField>
            <FormField label="素材" id="{idPrefix}-material" error={fieldError(prefix, "material_type")}>
                {#snippet children({ id, describedBy, invalid })}
                    <Select
                        {id}
                        bind:value={
                            () => row.material_type ?? "",
                            (next) =>
                                (row.material_type = next === "" ? null : (next as "video" | "still"))
                        }
                        error={invalid}
                        aria-describedby={describedBy}
                    >
                        <option value="">未指定</option>
                        <option value="video">動画</option>
                        <option value="still">静止画</option>
                    </Select>
                {/snippet}
            </FormField>
        </div>
        <FormField
            label="撮影ポイント"
            id="{idPrefix}-shooting-point"
            error={fieldError(prefix, "shooting_point")}
        >
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    bind:value={
                        () => row.shooting_point ?? "",
                        (next) => (row.shooting_point = next === "" ? null : next)
                    }
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        <FormField
            label="字幕① (要点・100文字まで)"
            id="{idPrefix}-subtitle-primary"
            error={fieldError(prefix, "subtitle_primary")}
        >
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    maxlength={100}
                    bind:value={
                        () => row.subtitle_primary ?? "",
                        (next) => (row.subtitle_primary = next === "" ? null : next)
                    }
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        <FormField label="ナレーション" id="{idPrefix}-narration" error={fieldError(prefix, "narration")}>
            {#snippet children({ id, describedBy, invalid })}
                <Textarea
                    {id}
                    rows={2}
                    bind:value={row.narration}
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        <FormField
            label="字幕② (補足)"
            id="{idPrefix}-subtitle-secondary"
            error={fieldError(prefix, "subtitle_secondary")}
        >
            {#snippet children({ id, describedBy, invalid })}
                <Textarea
                    {id}
                    rows={2}
                    bind:value={row.subtitle_secondary}
                    error={invalid}
                    aria-describedby={describedBy}
                />
            {/snippet}
        </FormField>
        {#if row.material_type === "still"}
            <FormField
                label="静止表示秒数 (1〜60)"
                id="{idPrefix}-static-seconds"
                error={fieldError(prefix, "static_display_seconds")}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="number"
                        min={1}
                        max={60}
                        bind:value={
                            () =>
                                row.static_display_seconds === null
                                    ? ""
                                    : String(row.static_display_seconds),
                            (next) => (row.static_display_seconds = toSeconds(next))
                        }
                        error={invalid}
                        aria-describedby={describedBy}
                    />
                {/snippet}
            </FormField>
        {/if}
    </div>
{/snippet}

<section aria-label="シナリオ編集">
    {#if conflict}
        <Alert type="warning" title={CONFLICT_TITLES[conflict.conflict_type]} testId="scenario-conflict-banner">
            <p>{conflict.message}</p>
            {#snippet action()}
                {#if conflict?.conflict_type === "version_mismatch"}
                    <Button
                        variant="neutral"
                        size="sm"
                        onclick={() => (confirmingReload = true)}
                        testId="scenario-conflict-reload"
                    >
                        サーバの最新を取得
                    </Button>
                {/if}
            {/snippet}
        </Alert>
    {/if}
    {#if genericError}
        <div class="mt-3">
            <Alert type="danger" testId="scenario-generic-error">
                <p>{genericError}</p>
            </Alert>
        </div>
    {/if}

    {#if steps.length === 0}
        <div class="mt-4">
            <EmptyState
                title="シナリオがまだありません"
                description="手順を追加して、マニュアル動画の台本を組み立てましょう。"
                icon={ListPlus}
                bordered
                cta={{ kind: "action", label: "最初の手順を追加", onclick: addStep }}
                testId="scenario-empty-state"
            />
        </div>
    {:else}
        <ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
            {#each steps as step, stepIndex (step)}
                <li>
                    <Card padding="md">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を上へ移動`}
                                    onclick={() => moveStep(stepIndex, -1)}
                                    testId="step-{stepIndex}-move-up"
                                >
                                    <ChevronUp class="size-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を下へ移動`}
                                    onclick={() => moveStep(stepIndex, 1)}
                                    testId="step-{stepIndex}-move-down"
                                >
                                    <ChevronDown class="size-4" aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="danger-ghost"
                                    size="sm"
                                    iconOnly
                                    ariaLabel={`手順 ${stepIndex + 1} を削除`}
                                    onclick={() => (confirmingStepIndex = stepIndex)}
                                    testId="step-{stepIndex}-remove"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </div>
                        <div class="mt-3">
                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                        </div>

                        {#if step.points.length > 0}
                            <ol class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4">
                                {#each step.points as point, pointIndex (point)}
                                    <li>
                                        <div class="flex items-start justify-between gap-2">
                                            <h4 class="text-caption font-medium text-text-secondary">
                                                急所 {stepIndex + 1}-{pointIndex + 1}
                                            </h4>
                                            <div class="flex items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を上へ移動`}
                                                    onclick={() => movePoint(stepIndex, pointIndex, -1)}
                                                    testId="point-{stepIndex}-{pointIndex}-move-up"
                                                >
                                                    <ChevronUp class="size-4" aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を下へ移動`}
                                                    onclick={() => movePoint(stepIndex, pointIndex, 1)}
                                                    testId="point-{stepIndex}-{pointIndex}-move-down"
                                                >
                                                    <ChevronDown class="size-4" aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    variant="danger-ghost"
                                                    size="sm"
                                                    iconOnly
                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
                                                    onclick={() => removePoint(stepIndex, pointIndex)}
                                                    testId="point-{stepIndex}-{pointIndex}-remove"
                                                >
                                                    <Trash2 class="size-4" aria-hidden="true" />
                                                </Button>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            {@render rowFields(
                                                point,
                                                `steps.${stepIndex}.points.${pointIndex}`,
                                                `point-${stepIndex}-${pointIndex}`,
                                            )}
                                        </div>
                                    </li>
                                {/each}
                            </ol>
                        {/if}

                        <div class="mt-4">
                            <Button
                                variant="ghost"
                                size="sm"
                                onclick={() => addPoint(stepIndex)}
                                testId="step-{stepIndex}-add-point"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                急所を追加
                            </Button>
                        </div>
                    </Card>
                </li>
            {/each}
        </ol>

        <div class="mt-4">
            <Button variant="neutral" onclick={addStep} testId="scenario-add-step">
                <Plus class="size-4" aria-hidden="true" />
                手順を追加
            </Button>
        </div>
    {/if}

    <div class="mt-6 flex items-center gap-2">
        <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
        {#if dirty}
            <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
                未保存の変更があります
            </span>
        {/if}
    </div>
</section>

<ConfirmDialog
    open={confirmingStepIndex !== null}
    title="手順を削除しますか?"
    message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
    confirmLabel="削除する"
    confirmVariant="danger"
    onConfirm={() => confirmingStepIndex !== null && removeStep(confirmingStepIndex)}
    onCancel={() => (confirmingStepIndex = null)}
    testId="scenario-step-remove-dialog"
/>

<ConfirmDialog
    bind:open={confirmingReload}
    title="サーバの最新を取得しますか?"
    message="現在編集中の内容は破棄され、サーバに保存されている最新のシナリオに置き換わります。"
    confirmLabel="破棄して最新を取得"
    confirmVariant="danger"
    onConfirm={reloadScenario}
    testId="scenario-reload-dialog"
/>
