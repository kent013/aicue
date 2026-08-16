# 対応マトリクス: impl-review Round 1

## [Critical] close/reopen または replay をまたいだ古い `play()` rejection が新しい再生セッションへ混入する

- 判断: **対応する**
- 根拠: 指摘のとおりである。`startPreview()` は毎回 `generation: 0` から引き直すため、
  世代だけでは「閉じて開き直した直後」を識別できない。`play()` の Promise は
  Modal の unmount を越えて生き残るので、旧セッションの `NotAllowedError` が
  新セッションを `blocked` にできる (component テストが空白だった経路)。
- 対応内容:
  - `ScenarioPreviewDialog.svelte` に**単調増加する `sessionId`** を追加した。
    `startPreview()` と `stopPreview()` の両方で +1 する (開始側だけだと、
    閉じたまま到着した結果が次の open を待って適用されうる)。
  - `playActive()` は呼び出し時点の `sessionId` を closure へ退避し、
    (a) `await tick()` の後 (= 待っている間に閉じた / 開き直した場合の再生そのものを止める)、
    (b) `catch` の中 (= 遅延 rejection の受理) の**両方**で照合する。
    世代の照合は従来どおり併用する (同一セッション内の入れ替えを守るのはこちら)。
  - テスト追加 (`ScenarioPreviewDialog.test.ts`):
    「閉じて開き直した後に届く旧セッションの拒否は新セッションを blocked にしない」。
    1 本目の `play()` を保留したまま閉じ、unmount → 再 render の後に reject させる系列で固定した。

## [Critical] `failed` が表示待ちとして terminal になっておらず、同一世代の `progress` / `playing` で延命・復帰できる

- 判断: **対応する**
- 根拠: 指摘のとおりである。停滞で `failed` にしたクリップの要素は、バッファリングが進めば
  `progress` を出し続けうる。`progressAt` が更新され続けると `placeholderSeconds` の
  満了判定が永久に成立せず、**「有限時間で必ず次へ進む」という本設計の中心契約が破れる**
  (停滞監視が回収装置として空転する)。`playing` による復帰も、一度失敗と告知した区間を
  無言で再生し直すことになり告知と実挙動が食い違う。
- 対応内容:
  - `scenario-preview.ts` の `reducePreview` に、非表示ガードと同じ位置で
    **「`failed` / `placeholder` の間はメディア由来イベントを受け付けない」**ガードを足した
    (`isWaitingState()`)。利用者操作 (`skip` / `retry`) と可視性と時間は従来どおり処理する。
  - テスト追加 (lib): 「failed 中の progress / playing は待ちを延ばさない・復帰させない」
    「placeholder 中のメディア由来イベントも待ちを延ばさない」。どちらも
    **尺の満了で次へ進むこと**まで固定した (ガードが前進を止めていないことの確認)。
  - テスト追加 (component): 「failed 中に progress が届き続けても placeholderSeconds で次へ進む」
    (実際に 1 秒ごとに `timeupdate` を注入する系列で配線ごと固定)。

## [Warning] close/reopen 後の遅延 `play()` rejection を固定するテストが無い

- 判断: **対応する** (上の Critical 1 の対応内容に含む)
- 根拠: 指摘のとおり、既存の「拒否後も閉じられる」は即時 rejection しか通らず、
  世代 0 の再利用を検出できない。
- 対応内容: 上記の新規テストが、保留中の Promise を閉じた後に reject させる形で検出する。

## [Warning] `failed` 後の `progress` / `playing` で待ちが延びないことのテストが無い

- 判断: **対応する** (上の Critical 2 の対応内容に含む)
- 根拠: 同上。既存テストは `failed → tick → advance` の素直な系列だけを見ていた。
- 対応内容: lib 2 本 + component 1 本を追加した。

## [Suggestion] 録画中エラー文言のテストが末尾の共通節だけを見ている

- 判断: **対応する**
- 根拠: 「同じ制約を同じ言葉で説明する」ことが意図なので、共通節の一致だけでは
  通し再生側の全文が変わっても赤くならない。ただし 2 つの文言は前半 (何ができないか) が
  意図的に異なるため**全文の完全一致は不可能**であり、共通定数化は 1 文字列のために
  実装間の依存を増やすので採らない。
- 対応内容: 通し再生側の**全文を完全一致で固定**し、加えて共通節で終わることを固定した。
  個別 preview 側の文言は `TakeStrip.test.ts` が固定しており、どちらかが分岐したら
  いずれかのテストが赤くなる。
