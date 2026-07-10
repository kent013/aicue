/**
 * Toggle atom の仕様の真実。意味論は DESIGN.md §Components > Toggle を参照。
 */
export interface ToggleProps {
    /** オン/オフ状態 ($bindable)。親は bind:checked / onChange のどちらでも受け取れる */
    checked: boolean;
    /**
     * accessible name。無名スイッチは支援技術で読み上げ不能なため型レベルで必須 (a11y)。
     */
    ariaLabel: string;
    disabled?: boolean;
    /** 切替直後の値を受け取る callback (bind:checked と併用可) */
    onChange?: (checked: boolean) => void;
    /** data-testid に反映するテスト用 ID */
    testId?: string;
    class?: string;
}
