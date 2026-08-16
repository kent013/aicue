<script lang="ts">
    import { Check } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import TakeThumbnail from "@/components/features/manual/TakeThumbnail.svelte";
    import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
    import {
        TAKE_ADOPTABLE_BY_STATUS,
        TAKE_STATUS_LABELS,
        type SelectableTake,
        type TakeSelectionCut,
        type VideoManualStatus,
    } from "@/types/manual";

    /**
     * 選択中テイクのプレビューと採用 (PC テイク選択画面の中央ペイン)。
     * 再生は capture.takes.playback (302 + no-store) 経由で、署名 URL を props に載せない。
     * 採用は撮影 PWA と同じ capture.takes.adopt を叩き、409 (書き出し中/解析中) や
     * 422 (未処理テイク) はサーバ供給の文言をそのまま表示する。
     */
    interface Props {
        take: SelectableTake | null;
        /** 一覧内の 0 始まり位置 (表示は +1)。未選択なら null */
        takeIndex: number | null;
        cut: TakeSelectionCut;
        /** 書き出し中 / 解析中は採用が 409 になることの事前告知に使う */
        manualStatus: VideoManualStatus;
        projectId: number;
        manualId: number;
        onChanged: () => void;
    }

    let { take, takeIndex, cut, manualStatus, projectId, manualId, onChanged }: Props = $props();

    // doc/04 「プレビューにナレーション/字幕を ON/OFF (初期は両方オフ)」。
    // v1 は TTS 非実装のため、ナレーションは**原稿テキストの表示**の切替である
    // (音声は再生しない)。ラベルにも「原稿」と書き、音が出ると誤解させない。
    let showSubtitles = $state(false);
    let showNarrationScript = $state(false);

    let error = $state<string | null>(null);
    let busy = $state(false);

    // ready 以外はサーバが 404 を返すため src を張らず <video> 自体を描かない
    // (無駄な要素とネットワーク要求を出さない)
    const playbackUrl = $derived(
        take !== null && take.status === "ready"
            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/playback")
            : null,
    );

    const thumbnailUrl = $derived(
        take !== null && take.has_thumbnail
            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/thumbnail")
            : null,
    );

    const busyStatusNotice = $derived(
        manualStatus === "rendering"
            ? "書き出し中は採用を確定できません。完了後にもう一度お試しください。"
            : manualStatus === "analyzing"
              ? "AI 解析中は採用を確定できません。完了後にもう一度お試しください。"
              : null,
    );

    /** 採用の確定。押下は常に受け、条件未充足はエラー表示で返す (disabled にしない) */
    async function adopt(): Promise<void> {
        error = null;
        if (take === null) {
            error = "左の一覧からテイクを選んでください。";
            return;
        }
        if (!TAKE_ADOPTABLE_BY_STATUS[take.status]) {
            error = `「${TAKE_STATUS_LABELS[take.status]}」のテイクは採用できません。`;
            return;
        }
        busy = true;
        try {
            const response = await captureJson(
                buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/adopt"),
                "POST",
            );
            if (!response.ok) {
                // 409 (書き出し中/解析中) / 422 (未処理) はサーバ供給の文言をそのまま出す
                error = await extractErrorMessage(response);
                return;
            }
            onChanged();
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
        } finally {
            busy = false;
        }
    }
</script>

<Card padding="md" testId="take-preview-panel">
    <div class="relative w-full overflow-hidden rounded-md bg-text/5">
        {#if playbackUrl !== null && take !== null}
            {#key take.id}
                <!-- svelte-ignore a11y_media_has_caption -->
                <video
                    controls
                    playsinline
                    src={playbackUrl}
                    class="w-full"
                    aria-label={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
                    data-testid="take-preview-video"
                ></video>
            {/key}
            <SubtitleOverlay
                primary={cut.subtitle_primary}
                secondary={cut.subtitle_secondary}
                visible={showSubtitles}
            />
        {:else}
            <TakeThumbnail
                index={takeIndex ?? 0}
                status={take?.status ?? "processing"}
                durationMs={take?.duration_ms ?? null}
                thumbnailUrl={take === null ? null : thumbnailUrl}
                size="lg"
                testId="take-preview-placeholder"
            />
        {/if}
    </div>

    {#if playbackUrl === null}
        <p class="mt-2 text-caption text-text-secondary" data-testid="take-not-playable">
            {take === null
                ? "左の一覧からテイクを選ぶと再生できます。"
                : `このテイクはまだ再生できません（${TAKE_STATUS_LABELS[take.status]}）。`}
        </p>
    {/if}

    <div class="mt-3 flex flex-col gap-2">
        <Checkbox
            id="take-preview-subtitles"
            bind:checked={showSubtitles}
            label="字幕を表示"
            testId="toggle-subtitles"
        />
        <Checkbox
            id="take-preview-narration"
            bind:checked={showNarrationScript}
            label="ナレーション原稿を表示"
            testId="toggle-narration-script"
        />
        {#if showNarrationScript}
            <p class="text-body text-text-secondary" data-testid="narration-script">
                {cut.narration}
            </p>
        {/if}
    </div>

    {#if busyStatusNotice !== null}
        <p class="mt-3 text-caption text-text-secondary" data-testid="take-adopt-status-notice">
            {busyStatusNotice}
        </p>
    {/if}

    <div class="mt-3 flex items-center gap-2">
        <Button variant="primary" loading={busy} onclick={adopt} testId="take-adopt">
            <Check class="size-4" aria-hidden="true" />
            このテイクを採用する
        </Button>
        {#if take !== null && cut.adopted?.id === take.id}
            <span class="text-caption text-text-secondary" data-testid="take-already-adopted">
                このテイクを採用中です。
            </span>
        {/if}
    </div>

    {#if error}
        <p class="mt-2 text-caption text-danger" role="alert" data-testid="take-preview-error">
            {error}
        </p>
    {/if}
</Card>
