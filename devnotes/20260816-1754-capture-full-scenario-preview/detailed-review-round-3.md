## Round 3 判定

S2の判断は概ね妥当です。現在のadopt経路がトランザクション内で取得し直した`Cut`を返す事実と、採用直後・付け替え後のbehavioral testがあれば、仮定上の事故のためにArchitecture gateを緩める必要はありません。

ただし、S5に再生列の構成によって実際に再生不能になるCriticalが残っています。

### S1: APPROVE

変更ありません。

### S2: APPROVE

`unsetRelation('adoptedTake')`を追加せず、現在の2経路の鮮度を明文化してbehavioral testで固定する判断を承認します。

ただし、`readyTakeId()`の「eager load済み」という説明と、adopt応答の「未ロードからlazy load」という説明は文言上矛盾します。実装前にdocblockを次の意味へ修正してください。

- 一覧直列化ではN+1防止のためeager load必須
- 単一Cutの直列化では、relation未ロードかつ最新の`adopted_take_id`を持つインスタンスならlazy loadを許容
- 古いrelation cacheを持つインスタンスは不可

これは設計の明確化であり、判定をREQUEST_CHANGESにする問題ではありません。

### S3: APPROVE

変更ありません。

### S4: APPROVE

非表示時のメディア由来イベント拒否により、前回の矛盾は解消されています。`skip`を処理可能なまま残したテストも境界を適切に固定しています。

[Suggestion] 「`MEDIA_ORIGIN_EVENTS`の網羅は型で担保する」という説明は正確ではありません。`Set<PreviewEvent["type"]>`は不正な値を防ぎますが、必要なイベントの登録漏れは検出しません。「要素型の正当性を型で担保する」程度へ表現を弱めてください。

### S5: REQUEST_CHANGES

[Critical] `clip → missing → clip`の並びで、missing後のclipへ`src`を割り当てる経路がありません。

現在の契約は次のようになっています。

1. 再生中clipの次がmissingなら、inactive slotをteardownする
2. advanceではslotを反転するだけで、active側の`src`に触れない
3. 先読みは現在clipが`playing`になったときだけ行う
4. missingは`playing`にならない

したがって、例えば`clip A → missing B → clip C`では、BからCへ進んだ時点でCの`src`を設定する主体が存在しません。先頭がmissingの場合や、missingが連続する場合も同様です。

修正案は、destination entryへ進んだ直後に次の規則でactive slotを同期することです。

- destinationが`clip`で、active slotの`src + generation`が一致する場合は何もしない。これは先読み成功経路。
- destinationが`clip`で一致しない場合だけ、active slotへ`src + generation`を割り当てる。これはmissing後・初回・先読み失敗時のフォールバック経路。
- destinationが`missing`ならactive slotをteardownする。
- 「active entryを見て無条件にsrcを再代入するeffectは禁止」とし、「台帳不一致時だけ補完する」ことは許可する。

この契約なら、先読み済み要素は再取得せず、未先読みclipにも必ず到達できます。

最低限、次のcomponent testを追加してください。

- `missing → clip`
- `clip → missing → clip`
- `missing → missing → clip`
- 先読み済み`clip → clip`では補完処理がsrcを再代入しない

[Warning] `suppressPause`が、`pause`イベントの発生しない`pause()`によって通常再生中も残存する可能性があります。

設計では「既にpausedなら抑止が残り、teardownでクリア」としています。しかし非表示処理でinactiveの先読みvideoにも無条件で`pause()`する場合、そのvideoは元からpausedなのでイベントが発生せず、`suppressPause[inactive]`が残ります。そのslotが後でactiveになり、利用者が再生後にpauseすると、本物の利用者pauseが誤って抑止されます。

修正案:

```ts
function pauseProgrammatically(slot: 0 | 1, video: HTMLVideoElement): void {
    if (video.paused) {
        suppressPause[slot] = false;
        return;
    }

    suppressPause[slot] = true;
    video.pause();
}
```

加えて、slotへ新しい`src + generation`を割り当てる際にも、そのslotの古い`suppressPause`をクリアしてください。テストには「既にpausedのinactive slotへprogrammatic pauseした後、そのslotがactiveになった際の利用者pauseが抑止されない」を追加する必要があります。

### S6: APPROVE

変更ありません。

### S7: APPROVE

変更ありません。

### S8: APPROVE

変更ありません。

## 全体判定: CHANGES_REQUESTED

relation鮮度への対応は妥当で、Round 2の指摘は解消されています。残る必須修正はS5の2点です。

- missingを挟んだ後のclipへ`src`を割り当てるフォールバック経路
- `pause()`がイベントを発生させない場合に`suppressPause`を残さない処理

これらを契約とcomponent testへ追加すれば、全体をAPPROVEDと判定できます。