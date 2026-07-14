## 施策別判定

- セッション世代分離: **APPROVE**
- 通常終了・abort finalizer: **APPROVE**
- 例外・破棄経路: **REQUEST_CHANGES**
- S6テスト: **REQUEST_CHANGES**

### [Warning] 既存の致命cleanupがセッション管理に統合されていない

`safeStop()`の例外経路は依然として`fatalStopCleanup()`を呼びます。

```ts
try {
    activeSession.recorder.stop();
} catch {
    fatalStopCleanup();
}
```

旧実装の`fatalStopCleanup()`はphaseとcameraだけを処理するため、`activeSession`が未確定のまま残ります。遅延`onstop`が来ると、idleへ戻した後に`finalizeSession()`が実行される可能性があります。

同様に以下も明示が必要です。

- `recorder.start()`失敗時に、構築済みsessionを無効化する
- `onDestroy`時にsessionを無効化し、遅延イベントと`onCaptured`を抑止する
- `activeSession`を正常finalize後にいつ`null`へ戻すか固定する

修正案として、破棄専用処理を追加してください。

```ts
function discardSession(session: RecordingSession): void {
    if (session !== activeSession) return;
    session.finalized = true;
    clearFinalizeWatchdog();
    stopTimer();
    activeSession = null;
}
```

`stop()`例外、`start()`例外、`onDestroy`からこれを呼び、全ハンドラは`session.finalized`も確認します。部分テイクを救出する方針なら、`stop()`例外だけwatchdog finalizerへ渡す設計でも構いません。

S6には`start()` throw、`stop()` throw、録画中unmount後の遅延イベントを追加してください。

## 全体判定

**CHANGES_REQUESTED**

通常終了と世代競合は解消済みです。残るのは既存の例外・component破棄経路を新しいsession lifecycleへ接続する点だけです。