<script lang="ts">
    import { Lightbulb } from "@lucide/svelte";

    /**
     * 撮影ガイド (撮影方法 = cuts.shooting_point) の透過オーバーレイ (doc/05 §5.2:
     * 「電球アイコンの横に、そのカットの撮影方法（構図指示）を表示」)。
     * 焼込ではなく撮影ガイド overlay で、MediaRecorder が録る MediaStream には含まれない。
     *
     * **表示可否は親が決める** — 「非空の shooting_point があり、かつ全画面のとき」だけ親が描画する。
     * GridOverlay の `visible` 形には揃えない: グリッドは内容を持たない装飾だが、
     * こちらはカットごとに変わる文字列であり、「空文字列」と「非表示」の 2 状態を
     * 子に持ち込む理由が無いため (型で不正状態を減らす)。
     *
     * **レーンは三分割の上ライン (`top-1/3`)**。SubtitleOverlay は
     * `absolute inset-0 p-3 flex flex-col justify-between` で **上端帯 = primary /
     * 下端帯 = secondary** を占めるため、上端に置くと primary と帯を奪い合い、
     * DOM 順で字幕が上になる以上**撮影ガイドが隠れて読めなくなる**。
     * 中間帯なら上下どちらの字幕帯とも交差しない。
     * 三分割線に沿う位置は構図指示として意味があり、GridOverlay の線とも一致する。
     * 非交差は Browser テストで矩形を実測して固定する (jsdom はレイアウトを持たない)。
     *
     * z 順は 映像 < グリッド < **撮影ガイド** < 字幕帯 (DOM 順で表現する)。
     * レーンが分かれているので通常は重ならないが、極端に長い字幕で万一重なった場合は
     * 字幕が上になる (v1 の中核価値が字幕であるため)。
     */
    interface Props {
        text: string;
    }

    let { text }: Props = $props();
</script>

<!--
  幅の制限は**任意値を使わず**コンテナの px-3 と max-w-full で行う
  (DESIGN.md の「token / 既存 utility の範囲で表現する」に寄せる。
  既存 SubtitleOverlay の max-w-[90%] には倣わない = 新設分で任意値を増やさない)。
-->
<div
    class="pointer-events-none absolute inset-x-0 top-1/3 flex justify-center px-3"
    data-testid="shooting-guide-overlay"
>
    <!--
      line-clamp-* は display: -webkit-box を敷くため **flex と同じ要素には置けない**
      (どちらか一方しか効かず、長い撮影ガイドが帯からはみ出して字幕帯と交差しうる)。
      レイアウトは外側の <p> が flex で持ち、行数制限はテキストの <span> 側に置く。
    -->
    <p
        class="flex max-w-full items-start gap-1 rounded-sm bg-text/70 px-3 py-1 text-caption text-surface"
    >
        <Lightbulb class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span class="line-clamp-2 min-w-0">{text}</span>
    </p>
</div>
