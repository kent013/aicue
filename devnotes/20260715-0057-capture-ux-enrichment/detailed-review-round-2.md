## 施策別判定

- S1 phase・純関数: **REQUEST_CHANGES**
- S2 一時停止・再開: **REQUEST_CHANGES**
- S3 カメラ反転: **REQUEST_CHANGES**
- S4 タイマー: **APPROVE**
- S5 グリッド: **APPROVE**
- S6 テスト: **REQUEST_CHANGES**

### [Critical] イベント受理判定が no-op と区別できない

次の判定は「遷移が成立した」ことではなく、単に戻り値が目的 phase であることしか確認していません。

```ts
if (transition(phase, "onresume") === "recording")
```

例えば `phase === "recording"` で重複 `onresume` が届くと、不正イベントは現 phase を維持するため条件が成立します。その結果、`segmentStart` が現在時刻に上書きされ、録画時間が欠落します。

`onpause` も `phase === "paused"` の重複イベントで副作用部分へ入ります。

修正案:

```ts
recorder.onpause = () => {
    if (phase !== "pausing") return;
    closeSegment();
    dispatch("onpause");
    stopTimer();
};

recorder.onresume = () => {
    if (phase !== "resuming") return;
    segmentStart = performance.now();
    dispatch("onresume");
    startTimer();
};
```

自然発生した `recording → onpause → paused` を受理する要件があるなら、`transition()` とは別に `acceptsEvent(phase,event)` を用意してください。少なくとも「戻り値が目的 phase」という判定は不可です。

### [Critical] `switching` が既存の排他状態に含まれていない

設計上、`switchCamera()` 実行中も以下が成立します。

```ts
phase === "idle"
starting === false
previewResuming === false
```

そのため、切替の `getUserMedia()` 待機中に次が並行実行できます。

- `startRecording()` による録画開始
- `releaseForPreview()` による stream 解放
- 親側での preview 開始

「`switching` と既存 `starting` ガードで抑止」というリスク記述は、提示コードとは一致しません。

修正案:

- `active` を `starting || previewResuming || switching || phase !== "idle"` にする
- `startRecording()` に `switching` ガードを追加
- `releaseForPreview()` と `resumeAfterPreview()` にも `switching` ガードを追加
- `switching=true/false` の直後に `syncActive()` を呼ぶ
- 親への active 通知順と並行操作拒否をVitestで固定する

### [Warning] cancel eventを省略する判断

直接代入でも現在の2経路だけなら動作しますが、「phase遷移は `transition()` が単一真実源」という設計記述と矛盾します。

修正案は `pauseFailed` / `resumeFailed` を追加し、巻き戻しも `dispatch()` 経由にすることです。イベント数の増加より、状態機械外の遷移経路が残るリスクの方が大きいと判断します。

### [Warning] S3 段階2で切替成立を検証していない

段階1では `switchSucceeded()` を使いますが、旧stream解放後の段階2では取得成功だけで `switched` としています。`exact` を信用する設計なら、その方針を明記すべきです。

修正案: 段階2でも `switchSucceeded()` を適用し、不成立streamを停止して段階3へ進めます。

## 確認事項への回答

- 同期例外での直接代入: **動作上は可能ですが、設計上は非推奨**です。cancel event追加を推奨します。
- `stream !== null` の反転ボタン条件: **妥当**です。対象previewがない初回録画前に表示しない判断に異論はありません。

## 全体判定

**CHANGES_REQUESTED**

特にイベント副作用の受理判定と、カメラ切替中のpreview・録画排他は実装前に修正が必要です。