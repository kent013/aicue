<script lang="ts">
    import type { ToggleProps } from "./Toggle.types";

    /**
     * Toggle atom。オン/オフを即時切替するスイッチ (role="switch")。
     * フォーム送信を伴う選択には使わない (その場合は Select 等を使う)。
     */
    let {
        checked = $bindable(),
        ariaLabel,
        disabled = false,
        onChange,
        testId,
        class: extraClass = "",
    }: ToggleProps = $props();

    function toggle(): void {
        if (disabled) return;
        checked = !checked;
        onChange?.(checked);
    }

    // 空文字 ariaLabel は型 (string) では防げないため dev のみ警告で a11y 退行を防ぐ
    $effect(() => {
        if (import.meta.env.DEV && !ariaLabel) {
            console.warn(
                '[Toggle] ariaLabel が空です (role="switch" の accessible name が無いと支援技術で読み上げ不能)',
            );
        }
    });

    // トラック: On=bg-primary / Off=bg-border-strong。focus 規約は Button と統一。
    // rounded-full は真に円形な UI の例外 (ds-purity FILE_SCOPED_ALLOWLIST で管理)
    const trackClass = $derived(
        [
            "relative inline-flex h-6 w-11 shrink-0 items-center rounded-full",
            "transition-colors duration-150",
            "focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none",
            "disabled:cursor-not-allowed disabled:opacity-40",
            checked ? "bg-primary" : "bg-border-strong",
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );

    // つまみ: 影は使わず bg-surface とトラック色の明度差で表現する (DESIGN.md §Elevation)
    const knobClass = $derived(
        "inline-block size-5 transform rounded-full bg-surface transition-transform duration-150 " +
            (checked ? "translate-x-5" : "translate-x-0.5"),
    );
</script>

<!--
  ネイティブ <button> のため Space / Enter は標準で click を発火する (追加の keydown 不要)。
  role="switch" + aria-checked で支援技術にスイッチとして提示する。
-->
<button
    type="button"
    role="switch"
    aria-checked={checked}
    aria-label={ariaLabel}
    {disabled}
    data-testid={testId}
    onclick={toggle}
    class={trackClass}
>
    <span class={knobClass}></span>
</button>
