# 詳細設計レビュー Round 3（aria-controls 撤回の確認）

Round 2 の S2 [Warning]（`aria-controls` が条件付き描画で不在 IDREF を参照 + 固定 id 重複リスク）に対応しました。

## 対応
- トグルボタンから `aria-controls="subtitle-overlay-panel"` を**削除**。
- `SubtitleOverlay` ルート div から `id="subtitle-overlay-panel"` を**削除**。
- トグルの状態・操作目的は `aria-pressed={showSubtitles}` + 状態連動 `aria-label`（ON「字幕を非表示」/ OFF「字幕を表示」）のみで表現。
- 設計本文（S1 設計・S2 a11y チェック）に「aria-controls は使わない」理由を明記。

## 反映後のトグルボタン
```svelte
<button
    type="button"
    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
    aria-label={subtitleToggleLabel}
    aria-pressed={showSubtitles}
    onclick={() => (showSubtitles = !showSubtitles)}
    data-testid="toggle-subtitles"
>
    {#if showSubtitles}<Captions class="size-5" aria-hidden="true" />{:else}<CaptionsOff class="size-5" aria-hidden="true" />{/if}
</button>
```
## 反映後の SubtitleOverlay ルート
```svelte
{#if shown}
    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3" data-testid="subtitle-overlay">
```

これで残 Critical/Warning は解消の想定です。APPROVED をお願いします。
