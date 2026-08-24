<script lang="ts" module>
    /**
     * `.svelte` を仮想 TS へ平坦化する見本 (module 文脈と実体文脈の両方を持つ)。
     * 値は現物の PHP 列挙と交差しない綴りにすること (fixtures/ は母集団に入る)。
     */
    export type SampleModuleKind = "zzz-svelte-1" | "zzz-svelte-2";
</script>

<script lang="ts">
    // 実体から module の宣言を参照できること (Svelte 本来の可視性と同じ)。
    type SampleInstanceKind = SampleModuleKind;

    const SAMPLE_LABELS = { "zzz-svelte-1": "one", "zzz-svelte-2": "two" };
    const SAMPLE_LIST = ["zzz-svelte-1", "zzz-svelte-2"] as const;

    const current: SampleInstanceKind = "zzz-svelte-1";

    // 分岐のラベル (4 形目) も `.svelte` の中から拾えること。
    const describe = (kind: SampleInstanceKind): string => {
        switch (kind) {
            case "zzz-svelte-1":
                return "one";
            case "zzz-svelte-2":
                return "two";
            default:
                return "other";
        }
    };
</script>

<span>{SAMPLE_LABELS[current]}{SAMPLE_LIST.length}{describe(current)}</span>
