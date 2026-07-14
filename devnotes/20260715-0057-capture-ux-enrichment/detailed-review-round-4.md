## 施策別判定

- S1 phaseマシン: **APPROVE**
- S2 異常終了処理: **REQUEST_CHANGES**
- S3〜S5: **APPROVE**
- S6 テスト: **REQUEST_CHANGES**

### [Critical] `inactive` 即時収束と遅延 `onstop` が競合する

`MediaRecorder` はerror発生時、既に`inactive`になっていても、その後に`dataavailable` / `stop`イベントを配送し得ます。

現在の`handleAbort()`は即座にidleへ戻すため、次の競合が可能です。

1. 旧recorderがerror、stateは`inactive`
2. `handleAbort()`がidleへ収束
3. ユーザーが新しい録画を開始し、共有`chunks`や`accumulatedMs`を初期化
4. 旧recorderの遅延`onstop`が発火
5. 新録画の共有状態を使って`onCaptured`し、新録画のphaseまでidleへ戻す

修正案は、録画セッション単位の一度限りのfinalizerを設けることです。

- 各recorder生成時にセッションIDまたはrecorder参照を固定
- `onstop`とabort fallbackを同じ`finalizeOnce()`へ集約
- `chunks`、duration状態をセッションローカルに保持
- finalization済みの遅延イベントはno-op
- active解除はfinalization完了時のみ行う
- `inactive`時は通常の`onstop`を待ち、必要なら短いwatchdogでfinalize fallbackする

S6には「inactive abort後、旧onstopより先に新録画操作を試みる」「遅延した旧onstopが新セッションを変更しない」を追加してください。

## 全体判定

**CHANGES_REQUESTED**

`abort`遷移自体は適切です。残る問題は旧recorderイベントと新録画の世代分離だけです。