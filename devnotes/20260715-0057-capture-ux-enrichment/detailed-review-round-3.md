## 施策別判定

- S1 phase マシン: **REQUEST_CHANGES**
- S2 一時停止・再開: **REQUEST_CHANGES**
- S3 カメラ反転: **APPROVE**
- S4 タイマー: **APPROVE**
- S5 グリッド: **APPROVE**
- S6 テスト: **REQUEST_CHANGES**

### [Critical] 過渡 phase 中の recorder error / track終了で復旧不能

現在は以下の配線です。

```ts
recorder.onerror = () => safeStop();
track.onended = () => safeStop();
```

しかし `safeStop()` は `canStop(phase)` を通すため、`pausing` / `resuming` では no-op です。次のケースで active が永久に残ります。

1. `pause()` または `resume()` を発行
2. phase が `pausing` / `resuming`
3. 確定イベント前に recorder error または track終了
4. `safeStop()` が何もせず、`onpause` / `onresume` も届かない

これはカメラ不調時に操作不能となるため、v1の「詰みを作らない」要件上 Critical です。

修正案:

- ユーザー操作の `stop` と異常終了の `abort` を分離する
- `CaptureEvent` に `abort` を追加し、`idle` 以外の全phaseから `stopping` へ遷移可能にする
- recorderが既に `inactive` なら資源解放して `idle` へ収束する
- `pause/resume` 確定イベントが届かない場合も考慮し、異常イベント経路では必ずactiveを解除する

```ts
case "abort":
    return phase === "idle" ? "idle" : "stopping";
```

S6には最低限、以下を追加してください。

- `pausing → recorder.onerror → idle`
- `resuming → track.onended → idle`
- 異常終了後にactiveが`false`となり再撮影可能
- 遅延した`onpause/onresume`が異常終了を巻き戻さない

Round 2の4点については、提示された修正で適切に解消されています。特にsource phaseの直接ガード、cancel event、`switching`の排他統合、段階2の成立検証はいずれも問題ありません。

## 全体判定

**CHANGES_REQUESTED**

異常終了時の過渡phase収束を追加できれば、APPROVED相当です。