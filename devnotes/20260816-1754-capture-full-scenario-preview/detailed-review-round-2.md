## Round 2 判定

反論した3点は、提示された既存コードの事実を前提にすれば納得できます。

- S2 DTO → Service 依存: **納得**。既存先例があり、`fromCut()` 内で必ず canonical predicate を通すことで渡し忘れも防げます。
- S5 非 `NotAllowedError` の stall 回収: **条件付きで納得**。interval が生存し、`loading` が監視対象である限り有限時間で回収されます。
- S6 `releaseForPreview()` の同期性: **納得**。既存実装が `void` で内部ガードを持つなら、既存の個別 preview と同じ扱いが妥当です。

ただし、S5に新たな状態遷移上の問題が残っています。

### S1: APPROVE

Round 1から変更なし。判定式の単一化は妥当です。

### S2: REQUEST_CHANGES

[Warning] `CaptureCutData::fromCut()` の全呼び出し経路で、`adoptedTake` relation が最新である保証がまだ明文化されていません。

詳細画面は `with('adoptedTake')` で保証されますが、adopt応答でも同じDTOを使います。採用処理前に `$cut->adoptedTake` が未採用としてロード済みだった場合、relation cacheが古いままなら、採用直後にも `adopted_ready_take_id` が `null` になり得ます。逆にlazy loading禁止環境では未ロード参照が失敗する可能性があります。

修正案:

- `CaptureCutData::fromCut()` の事前条件を「最新の `adoptedTake` をロード済み」と明記する。
- 詳細画面だけでなく、store/adopt応答を生成する全経路で採用更新後に `unsetRelation('adoptedTake')` と `load('adoptedTake')`、またはモデルの再取得を行う。
- adopt応答について、`ready` 採用直後に `adopted_ready_take_id` が採用IDになるFeatureテストを追加する。
- 既にnullとしてrelationがロードされた状態から採用するケースもテストする。

S2bの非ready URL/ACK抑止、inventory変更、array shape同期自体は妥当です。

### S3: APPROVE

1以上のintをprops契約として固定し、現段階で新規config gateを作らない判断は妥当です。

### S4: REQUEST_CHANGES

[Critical] 非表示中の`ended`を止めるというS5のテスト計画と、reducer実装が矛盾しています。

現在の`reducePreview()`は`state.visible === false`でも`ended`をそのまま`advance()`へ渡します。S5には次の記述があります。

> 発火した場合も reducer の `visible=false` で進まない

しかし、その防御は実装されていません。`pause()`しても、既にキューへ入った`ended`や競合したイベントは到着し得るため、実メディア操作だけに依存できません。

修正案として、少なくとも非表示中のメディア由来イベントをreducer側でも拒否してください。利用者操作の`skip`などと区別するため、イベント単位で扱うのが安全です。

```ts
if (
    !state.visible &&
    ["progress", "playing", "paused", "resumed", "ended", "error", "blocked"].includes(event.type)
) {
    return state;
}
```

実際には型安全なhelperまたは`switch`内の明示分岐がPHPStan相当のTS厳格性には適しています。`hidden → ended`でindex/generationが変わらない純関数テストをS4へ追加してください。

### S5: REQUEST_CHANGES

[Critical] 2つのvideo要素に対する「世代」の台帳がありません。

`slotSrc`だけでは、各video要素から届いたイベントへどの`generation`を付けるべきか決められません。先読み要素は「次世代」、active要素は「現世代」であり、slot反転後に旧要素から遅延イベントが届くケースを正しく捨てるには、URLとは別に世代をslotへ固定する必要があります。

修正案:

```ts
let slotGeneration = $state<[number | null, number | null]>([null, null]);
```

- active割当時は現在の`state.generation`
- 次クリップの先読み時は`state.generation + 1`
- event handlerは発火時の`slotGeneration[slot]`を渡す
- teardown時は`slotSrc`と`slotGeneration`を同時にnullへ戻す

同じURLが連続して現れる可能性も考えると、`src`の一致だけで世代割当を省略してはいけません。台帳の同一性は少なくとも`src + generation`で判断する必要があります。

[Warning] `programmaticPause: boolean`を`pause()`呼び出し直後に戻す設計では、非同期に配送される`pause`イベントを抑止できない可能性があります。またvideoが2枚あるため、単一booleanでは発生元を区別できません。

修正案:

- slot別に抑止状態を持つ。
- `pause`イベントを受けた時点で、そのslotの抑止を消費する。
- `pause()`直後にフラグを戻さない。

例:

```ts
let suppressPause = $state<[boolean, boolean]>([false, false]);

function pauseProgrammatically(slot: 0 | 1, video: HTMLVideoElement): void {
    suppressPause[slot] = true;
    video.pause();
}

function handlePause(slot: 0 | 1): void {
    if (suppressPause[slot]) {
        suppressPause[slot] = false;
        return;
    }

    dispatch({ type: "paused", generation: slotGeneration[slot] });
}
```

既にpausedで`pause`イベントが発生しない場合もあるため、teardown時には抑止状態を明示的にクリアする必要があります。

### S6: APPROVE

同期性と既存条件の根拠が明確になりました。共通関数参照への切り出しと`flex-wrap`も妥当です。

ただしS2で指摘したadopt応答のrelation鮮度は、S6ではなくDTO生成側の責務として閉じてください。

### S7: APPROVE

変更なし。実装契約と「保証しないもの」の記録方針は妥当です。

### S8: APPROVE

既存権限テストとArchitecture inventoryによる非回帰確認で十分です。

## 全体判定: CHANGES_REQUESTED

Round 1の指摘は概ね適切に解消されています。残件は次の3点です。

- 非表示中の`ended`が実際にはadvanceする
- 2枚のvideoに対応するslot別generation台帳がない
- `programmaticPause`が単一booleanで、非同期イベントと2要素を安全に扱えない

加えて、adopt応答での`adoptedTake` relation鮮度を契約・テストへ追加すれば、設計としてAPPROVEDにできます。