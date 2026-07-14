## 施策別判定

### S1 — APPROVE

Round 1以降の修正内容で問題ありません。

### S2 — REQUEST_CHANGES

- [Critical] cleanup が `pause()` のみでは、Modal がDOMを保持する実装の場合、`src`・MediaElementのデコード資源・ネットワーク接続が残ります。当初の「video teardown／資源解放」契約を満たしません。

  修正案: video自体を `open` 条件下でのみ生成し、cleanupでは完全teardownしてください。再open時は新要素になるため宣言的`src`との競合もありません。

```svelte
{#if open && take !== null}
    {#key take.id}
        <video bind:this={video} src={playbackUrl ?? undefined} controls playsinline />
    {/key}
{/if}
```

```ts
function teardownVideo(): void {
    video?.pause();
    video?.removeAttribute("src");
    video?.load();
}
```

これで close、take変更、採用成功、component破棄の全経路をカバーできます。

### S3 — APPROVE

前回承認から追加の問題はありません。

### S4 — REQUEST_CHANGES

- [Critical] `onstop` 内でblob生成や`onCaptured`が例外を投げると、末尾の`setRecording(false)`へ到達せず、永久に録画中扱いになります。

  修正案: `onstop`内部だけは`try/finally`で終了通知を保証してください。禁止したのはstart処理の無条件finallyです。

```ts
recorder.onstop = async () => {
    try {
        await finalizeCapture();
    } finally {
        setRecording(false);
    }
};
```

- [Warning] `safeStop()`は`recorder.state === "recording"`の場合だけ`stop()`し、inactiveや例外時の終端処理を明確化してください。特に停止失敗後も`recording=true`のまま残すとUIが復旧不能になります。

  修正案: `active / stopping / idle / failed`の内部状態を持ち、`active`と`stopping`の両方でcamera解放を禁止。`onstop`または明示的なfatal cleanupだけがidle/failedへ遷移する設計にします。

### S5 — REQUEST_CHANGES

追加テストは適切です。S2/S4の修正に合わせて以下も必要です。

- [Critical] close後に`src`除去と`load()`が実行され、video要素が破棄される。
- [Critical] `onCaptured`がreject/throwしても録画状態が解除される。
- [Warning] stop要求中はpreview用camera解放が拒否される。
- [Warning] `safeStop()`多重呼び出しで`stop()`が重複しない。

## 全体判定

**CHANGES_REQUESTED**

残る本質的な課題は、S2の完全なメディア資源解放と、S4の「停止処理失敗・後処理例外でも録画排他状態が壊れない」保証です。