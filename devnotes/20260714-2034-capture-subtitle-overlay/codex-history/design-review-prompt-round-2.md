# 詳細設計レビュー Round 2（Warning 反映の確認）

Round 1 は全施策 APPROVE / 全体 APPROVED でした。指摘された Warning 3 件と安価な Suggestion 1 件を反映しました。反映内容が妥当か、追加の Critical/Warning が無いかの確認をお願いします。

## 反映内容

- **[Warning] S2 aria-controls**: `SubtitleOverlay` ルート div に `id="subtitle-overlay-panel"` を付与。トグルボタンに `aria-controls="subtitle-overlay-panel"` を追加。OFF 時は overlay 非描画で id 対象が一時不在になるが `aria-pressed` が状態を補完する旨を設計に明記。
- **[Warning] S4 空白検証の安定化**: 「描画文字列が trim で書き換えられない」検証を、`"  a  "` を渡して**要素が描画される** + `textContent` が `toContain("a")` に変更（空白完全一致は避ける）。加えて `visible=false` で `subtitle-overlay`/`subtitle-primary`/`subtitle-secondary` すべて不在を追加。
- **[Warning] S5 アイコン依存の脆さ回避**: 主アサーションを `aria-pressed` / `aria-label` の状態遷移に変更。アイコンは補助（存在確認のみ）。「disabled 不在 + 実クリックで状態遷移」を同一ケースで確認し禁止事項 8 の証跡を強化。
- **[Suggestion] S1 防御的 nullish**: `hasSecondary = $derived((secondary ?? "").trim() !== "")` に変更。

## 見送り（根拠付き）

- **[Suggestion] S1 line-clamp 非対応時の max-h 強保証**: 見送り。line-clamp は主要モバイルブラウザで広くサポート。非対応時も折返し + max-w で致命破綻せず、DS に max-h トークンが無く追加は過剰。
- **[Suggestion] S3 selectedCut ローカル束縛**: 見送り。既存 `{#if selectedCut === null}{:else}` 構造の non-null 参照で足りる。

## 反映後の該当コード

### SubtitleOverlay.svelte（script + root 抜粋）
```svelte
let { primary, secondary, visible }: Props = $props();
const hasPrimary = $derived((primary ?? "").trim() !== "");
const hasSecondary = $derived((secondary ?? "").trim() !== "");
const shown = $derived(visible && (hasPrimary || hasSecondary));
```
```svelte
{#if shown}
    <div
        id="subtitle-overlay-panel"
        class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3"
        data-testid="subtitle-overlay"
    >
        …（primary: line-clamp-2 / secondary: line-clamp-3 の 2 スロット）…
    </div>
{/if}
```

### CameraRecorder.svelte（トグルボタン抜粋）
```svelte
<button
    type="button"
    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
    aria-label={subtitleToggleLabel}
    aria-pressed={showSubtitles}
    aria-controls="subtitle-overlay-panel"
    onclick={() => (showSubtitles = !showSubtitles)}
    data-testid="toggle-subtitles"
>
    {#if showSubtitles}<Captions class="size-5" aria-hidden="true" />{:else}<CaptionsOff class="size-5" aria-hidden="true" />{/if}
</button>
```

追加の Critical/Warning が無ければ APPROVED をお願いします。
