<script lang="ts">
    import { Upload } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import {
        createMemoryPendingStore,
        generateClientTakeId,
        UploadQueue,
    } from "@/lib/capture/upload-queue";

    /**
     * PC ローカル動画の追加アップロード (doc/04)。
     * 既存の presigned フロー (upload-url → S3 PUT → POST takes) を UploadQueue ごと再利用する
     * (アップロード実装を 2 本にしない)。MediaRecorder の有無に依存しない file input を使い、
     * capture 属性は付けない (PC ではファイルダイアログを開く)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        cutId: number;
        onUploaded: () => void;
    }

    let { projectId, manualId, cutId, onUploaded }: Props = $props();

    // store を自前で保持するのは、queued (オフライン等) の Blob を PC 側に残さないため
    const store = createMemoryPendingStore();
    const queue = new UploadQueue({ store });
    let input: HTMLInputElement | null = $state(null);
    let uploading = $state(false);
    let error = $state<string | null>(null);

    /**
     * 尺の**事前チェック** (doc/04 「尺は 1 分まで」)。
     * これは保証ではない — サーバは尺を強制せず、duration_ms はクライアント申告値である。
     * metadata を読めない形式では判定自体が働かない。
     * 真の尺による拒否はエンコード段 (別タスク) の担当である。
     */
    const MAX_DURATION_MS = 60_000;

    /**
     * メタデータから尺を読む。読めなければ null を返し**事前チェックを行わない** (詰ませない)。
     * loadedmetadata / error / timeout(3s) の 3 経路をすべて閉じ、Object URL は必ず revoke する。
     */
    function readDurationMs(file: File): Promise<number | null> {
        return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const video = document.createElement("video");
            let settled = false;
            const finish = (value: number | null): void => {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                video.onloadedmetadata = null;
                video.onerror = null;
                video.removeAttribute("src");
                URL.revokeObjectURL(url); // 経路によらず必ず解放する
                resolve(value);
            };
            const timer = setTimeout(() => finish(null), 3_000);
            video.preload = "metadata";
            video.onloadedmetadata = () =>
                finish(Number.isFinite(video.duration) ? Math.round(video.duration * 1000) : null);
            video.onerror = () => finish(null);
            video.src = url;
        });
    }

    async function handleChange(): Promise<void> {
        error = null;
        const file = input?.files?.[0];
        // どの経路を通っても input を空に戻す (同じファイルの再選択で change が出ない問題を避ける)
        try {
            if (!file) return;
            if (!file.type.startsWith("video/")) {
                error = "動画ファイルを選択してください。";
                return;
            }
            const durationMs = await readDurationMs(file);
            // 押下は受けてからエラーを出す (disabled にしない)。
            // 断定形にしない = サーバ強制ではないため「登録できません」とは書かない
            if (durationMs !== null && durationMs > MAX_DURATION_MS) {
                error =
                    "動画の長さが 1 分を超えています。1 分以内に切り出してからアップロードしてください。";
                return; // upload-url を呼ばない = quota を消費しない
            }
            uploading = true;
            const clientTakeId = generateClientTakeId();
            const outcome = await queue.enqueue({
                clientTakeId,
                projectId,
                manualId,
                cutId,
                blob: file,
                contentType: file.type.split(";")[0],
                durationMs,
                capturedAt: new Date().toISOString(),
            });
            if (outcome.status === "uploaded") {
                onUploaded();
                return;
            }
            if (outcome.status === "quota_exceeded") {
                error = outcome.message; // 422 のサーバ文言をそのまま出す
                return;
            }
            // queued = オフライン等。PC は保持しない方針なので Blob を捨ててから理由を出す
            await store.delete(outcome.clientTakeId);
            error = "アップロードできませんでした。接続を確認して再度お試しください。";
        } catch {
            // ネットワーク断 / presigned PUT の例外 / metadata 読み取りの reject。
            // 無反応にしない (即時アップロード経路は store.put() を通らないので Blob も残らない)
            error = "アップロードできませんでした。接続を確認して再度お試しください。";
        } finally {
            uploading = false;
            if (input) input.value = "";
        }
    }
</script>

<Card padding="md" testId="take-file-upload">
    <h2 class="text-body font-medium text-text">動画ファイルを追加</h2>
    <p class="mt-1 text-caption text-text-secondary">
        PC にある動画を、このカットのテイクとして追加できます (1 分以内が目安です)。
    </p>
    <!--
      file input は視覚的に隠し、押下導線は Button atom に寄せる
      (DESIGN.md: 素の input を画面に置かない)。capture 属性は付けない = PC では
      ファイルダイアログが開く。
    -->
    <input
        bind:this={input}
        type="file"
        accept="video/*"
        class="hidden"
        onchange={handleChange}
        data-testid="take-file-input"
    />
    <div class="mt-3">
        <Button
            variant="neutral"
            loading={uploading}
            onclick={() => input?.click()}
            testId="take-file-select"
        >
            <Upload class="size-4" aria-hidden="true" />
            動画ファイルを選ぶ
        </Button>
    </div>
    {#if error}
        <p class="mt-2 text-caption text-danger" role="alert" data-testid="take-upload-error">
            {error}
        </p>
    {/if}
</Card>
