<script lang="ts">
    /**
     * 干渉の相方。`SampleInstanceKind` は Sample.svelte にも在るが、仮想 TS を
     * モジュール文脈にしてあるので**混ざらない** (値集合が別になる)。
     */
    type SampleInstanceKind = "zzz-svelte-3";

    const current: SampleInstanceKind = "zzz-svelte-3";
</script>

<span>{current}</span>
