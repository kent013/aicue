# 対応マトリクス: design-review Round 4

## [Critical] S5: `slotGeneration === null` のメディアイベントが現在世代として受理される
- 判断: 対応する (提案どおり)
- 根拠: 指摘のとおり。`generation: slotGeneration[slot] ?? undefined` と書くと、
  reducer の「世代省略 = 現在世代」という規則に落ちるため、**teardown 後に遅延到着した
  `pause` / `error` / `ended` が現在のクリップへ誤適用される**。
  teardown で `slotGeneration` を null に戻す経路が実在するので、机上の懸念ではない。
- 対応内容: メディア由来イベントの**唯一の送出口** `dispatchMediaEvent(slot, type)` を実装仕様に置き、
  **`slotGeneration[slot] === null` なら何も送らない**形にした (`?? undefined` を禁止)。
  `handlePause()` も抑止判定の後にこの helper を通す。
  **`generation` の省略を許すのは `skip` / `retry` / `hidden` / `shown` / `tick`** (利用者操作・
  ページ・時間の同期イベント) だけであることを契約として明記した。
  component テストに「teardown 後の遅延 `pause`/`error`/`ended` が状態を変えない」
  「null 世代のメディアイベントは dispatch されない」を追加した。

## [Warning] S5: 同一 slot を別 src へ再割り当てした後、旧 src 由来の遅延イベントが新世代を読む
- 判断: 対応する (提案の「media element 再生成」を採る)
- 根拠: ハンドラが発火時に台帳を読む形では、
  (1) 旧 src・旧世代を割り当て → (2) 旧 src 由来イベントがキューへ → (3) 同じ slot を
  新 src・新世代へ割り当て直す → (4) 旧イベントが配送されハンドラが**新世代を読む**、
  という順序を世代台帳だけでは識別できない。**イベント源そのものを分離する**のが正しい解である。
- 対応内容: slot 別の**割り当て世代** `assignmentId: [number, number]` を追加し、
  **`src + generation` を別資源へ割り当て直すときだけ増やす**。`<video>` は
  `{#key assignmentId[slot]}` で包み、**要素ごと作り直す** (旧要素は listener ごと破棄される)。
  **先読み済み slot の active 昇格では `assignmentId` を変えない**ため、
  バッファを捨てず二重取得も起きない。
  component テストに「同じ slot へ新しい src を割り当てた後、旧要素から届く `error` / `ended` が
  新しいクリップを壊さない」「昇格では `assignmentId` が変わらない」を追加した
  (既存の「slot 反転後の旧 slot」テストでは同一 slot の再利用を検証できないという指摘も反映)。

## [APPROVE] S1 / S2 / S3 / S4 / S6 / S7 / S8
- 判断: 対応不要 (合意済み)
