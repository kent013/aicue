## Round 4 判定

前回指摘したmissing後の割り当てとpause抑止の残存は解消されています。ただし、世代防御にまだ1つCriticalがあります。

### S1: APPROVE

docblockの3段階整理により、詳細画面とadopt応答の前提が整合しました。

### S2: APPROVE

relation鮮度の契約とbehavioral testで十分です。

### S3: APPROVE

変更ありません。

### S4: APPROVE

非表示中のイベント拒否とコメントの保証範囲は妥当です。

### S5: REQUEST_CHANGES

[Critical] `slotGeneration === null`のメディアイベントが、現在世代のイベントとして受理されます。

提示コードでは次のように送っています。

```ts
dispatch({
    type: "paused",
    generation: slotGeneration[slot] ?? undefined,
});
```

一方、reducerでは`generation`省略を「現在世代」とみなします。

```ts
if (
    event.generation !== undefined &&
    event.generation !== state.generation
) return state;
```

teardownでは`slotGeneration`を`null`へ戻すため、その後に遅延した`pause`、`error`、`ended`が届くと`generation: undefined`になり、現在のクリップへ誤適用されます。特に今回、`pause()`直後に抑止をクリアするteardown経路があるため、実際に発生し得る競合です。

修正案として、メディア由来イベントでは世代省略を禁止してください。

```ts
function dispatchMediaEvent(
    slot: 0 | 1,
    type: MediaOriginEventType,
): void {
    const generation = slotGeneration[slot];
    if (generation === null) return;

    dispatch({
        type,
        generation,
        at: Date.now(),
    });
}
```

`handlePause()`も、抑止判定後に`slotGeneration === null`なら何も送らない形にします。`undefined`を許すのは`skip`、`retry`、`hidden`など同期的な利用者・ページイベントだけです。

テストには次を追加してください。

- teardown後の遅延`pause` / `error` / `ended`が状態を変えない
- `slotGeneration === null`のメディアイベントはdispatchされない
- 新しいclipの割り当て前に旧slotから届いたイベントが現在世代扱いされない

[Warning] slotを別のsrcへ再割り当てた後、旧src由来のキュー済みイベントへ「新しい`slotGeneration`」を付ける可能性があります。

イベントハンドラが発火時に台帳を読むだけの場合、次の順序では世代台帳だけでは識別できません。

1. slotに旧src・旧世代を割り当てる
2. 旧src由来イベントがキューへ入る
3. 同じslotを新src・新世代へ再割り当てる
4. 旧イベントが配送され、ハンドラが新世代を読む

修正案は、srcを別資源へ割り当て直すときにvideo要素自体をassignment epochで再生成することです。先読み済みslotのactive昇格ではassignmentを変えないため、二重取得は発生しません。

例として、slotごとに`assignmentId`を持ち、`src + generation`が変わるときだけ増加させ、video要素を`{#key assignmentId}`で再生成します。旧要素はlistenerごと破棄されるため、旧資源の遅延イベントを新割り当てとして扱いません。

少なくとも設計には、次のどちらかを明示する必要があります。

- 再割り当て時にmedia elementを再生成してイベント源を分離する
- 旧資源の遅延イベントを新世代として受理しないことを保証できる、同等の割り当てトークン方式

テストには「同じslotへ新srcを割り当てた後、旧要素から届く`error` / `ended`が新クリップを壊さない」を追加してください。現在ある「slot反転後の旧slot」テストだけでは、同じslotの再利用後を検証できません。

### S6: APPROVE

変更ありません。

### S7: APPROVE

変更ありません。

### S8: APPROVE

変更ありません。

## 全体判定: CHANGES_REQUESTED

missingを含む再生列とpause抑止は閉じています。残る問題は、teardown・slot再利用後の遅延メディアイベントです。

- `null`世代を`undefined`へ変換せず破棄する
- 同一slotの別srcへの再割り当てで、旧要素のイベント源を新割り当てから分離する

この2点を実装契約とcomponent testへ追加すれば、詳細設計をAPPROVEDと判定できます。