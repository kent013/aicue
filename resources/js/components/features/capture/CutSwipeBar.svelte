<script lang="ts">
    import { ChevronLeft, ChevronRight } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import {
        resolveSwipe,
        swipeDirection,
        type NavigationDirection,
    } from "@/lib/capture/landscape-capture";

    /**
     * 横持ち全画面の上部カット名エリア (doc/05 §5.2)。
     * **左右スワイプ / 前後ボタン / 左右矢印キー**の 3 手段でカットを前後に移動する。
     * スワイプだけにしないのは、キーボード・スクリーンリーダー利用者に到達不能であり、
     * 手袋を着けた現場作業者にも失敗しやすいためである。
     *
     * ラベル (手順 N / 急所 N-M) は **受け取るだけ**で自前では組み立てない
     * (lib/capture/cut-labels.ts の buildCutLabels() が唯一の導出元。二重管理を作らない)。
     * 端に着いたときの告知は親が持つ (判断の置き場所を 1 か所に保つ) ため、
     * 本 component は端かどうかを知らない = ボタンを disabled にする理由も持たない。
     */
    interface Props {
        /** 例: "手順 2" / "急所 2-1"。buildCutLabels() の結果をそのまま受ける */
        label: string;
        /** カット内容 (CutNavigator の行と同じ出所) */
        scene: string;
        /** 現在位置。index は 1 起点 (表示にそのまま使う) */
        position: { index: number; total: number };
        onNavigate: (direction: NavigationDirection) => void;
    }

    let { label, scene, position, onNavigate }: Props = $props();

    /** 進行中のポインタ ID と始点。pointerdown で採り、pointerup / cancel で捨てる */
    let gesture: { pointerId: number; startX: number; startY: number } | null = null;

    /**
     * 画面端の除外判定に使う viewport 幅。非ブラウザ実行では 0 を返す。
     * 0 のとき resolveSwipe は必ず "none" を返す = **移動しない側へ倒れる**
     * (panel-navigation.ts の prefersReducedMotion() が非対応環境で「動かさない」へ
     * 倒すのと同じ思想。安全側は常に「何もしない」)。
     */
    function viewportWidth(): number {
        return typeof window === "undefined" ? 0 : window.innerWidth;
    }

    /**
     * ボタンの上で始まった操作はスワイプとして扱わない。
     * 扱ってしまうと「ボタンを押しながら 48px 以上動かす」で
     * 親の pointerup による移動と button の click による移動が**二重発火**し、
     * 1 操作で 2 カット進んでしまう。
     */
    function startedOnButton(event: PointerEvent): boolean {
        const target = event.target;

        return target instanceof Element && target.closest("button") !== null;
    }

    function handlePointerDown(event: PointerEvent): void {
        if (startedOnButton(event)) {
            gesture = null;

            return;
        }
        gesture = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY };
    }

    function handlePointerUp(event: PointerEvent): void {
        const started = gesture;
        gesture = null;
        if (started === null || started.pointerId !== event.pointerId) return;
        const direction = swipeDirection(
            resolveSwipe({
                startX: started.startX,
                startY: started.startY,
                endX: event.clientX,
                endY: event.clientY,
                viewportWidth: viewportWidth(),
            }),
        );
        if (direction === null) return;
        onNavigate(direction);
    }

    /** ジェスチャ中断 (別要素へ持って行かれた等) は始点ごと捨てる */
    function handlePointerCancel(): void {
        gesture = null;
    }

    function handleKeydown(event: KeyboardEvent): void {
        if (event.key === "ArrowLeft") {
            event.preventDefault();
            onNavigate(-1);

            return;
        }
        if (event.key === "ArrowRight") {
            event.preventDefault();
            onNavigate(1);
        }
    }
</script>

<!--
  touch-pan-y: 横方向のブラウザ既定スクロールを止め、縦スクロールは残す
  (静的 inline style を書かずに touch-action を指定する。ds-purity)。

  **このバー自体はフォーカス対象にしない** (tabindex を持たない)。
  キーイベントは内側の前後ボタンからバブルしてくるので、
  「前後ボタンにフォーカスがある状態で左右キー」は tabindex 無しでも成立する。
  バーを Tab 停止にすると、同じ目的の停止が 3 つ (バー + 前 + 次) に増えて操作が冗長になる。
  svelte-ignore: 非対話要素へのイベントだが、**操作の入口は内側の 2 つの button** であり、
  ここのハンドラはそれを補うだけ (キーはバブル、ポインタは帯全体を当たり判定にするため)。
-->
<!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
<div
    class="flex touch-pan-y items-center gap-2 rounded-md border border-border bg-surface/90 px-2 py-1"
    role="group"
    aria-label="カットの移動"
    onpointerdown={handlePointerDown}
    onpointerup={handlePointerUp}
    onpointercancel={handlePointerCancel}
    onkeydown={handleKeydown}
    data-testid="cut-swipe-bar"
>
    <Button
        variant="ghost"
        size="sm"
        iconOnly
        ariaLabel="前のカット"
        onclick={() => onNavigate(-1)}
        testId="cut-swipe-previous"
    >
        <ChevronLeft class="size-5" aria-hidden="true" />
    </Button>
    <div class="min-w-0 flex-1 text-center">
        <p class="text-caption text-text-secondary" data-testid="cut-swipe-label">
            {label}
            <span class="ml-1">{position.index} / {position.total}</span>
        </p>
        <p class="truncate text-body" data-testid="cut-swipe-scene">{scene}</p>
    </div>
    <Button
        variant="ghost"
        size="sm"
        iconOnly
        ariaLabel="次のカット"
        onclick={() => onNavigate(1)}
        testId="cut-swipe-next"
    >
        <ChevronRight class="size-5" aria-hidden="true" />
    </Button>
</div>
