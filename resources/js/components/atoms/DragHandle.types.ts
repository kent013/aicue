/**
 * 並べ替えの取っ手 (DragHandle) の props。
 * 「掴む」ことが役割であり、押しても何も起きない点で Button とは別物なので atom を分ける。
 *
 * **`onclick` は意図的に定義しない**。ドラッグの後には click が発火しうるため、
 * click ハンドラを付けられる口を残すと「ドラッグしたのに別の操作が走る」経路が生まれる。
 * props に無ければ呼び出し側は付けられない = 型で経路を消す (design-review R1)。
 */
export interface DragHandleProps {
    /**
     * 何を掴んでいるかの読み上げ。
     * 例: 「手順 2 の並び順を変更 (ドラッグ、または上下キー)」
     */
    ariaLabel: string;
    /** ドラッグ開始 (PointerDragController.start へ中継する) */
    onpointerdown: (event: PointerEvent) => void;
    /** キーボードでの 1 段移動 (ArrowUp / ArrowDown)。呼び出し側が既存の移動関数へ写す */
    onkeydown?: (event: KeyboardEvent) => void;
    testId?: string;
    class?: string;
}
