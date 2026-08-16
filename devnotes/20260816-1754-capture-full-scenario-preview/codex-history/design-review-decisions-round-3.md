# 対応マトリクス: design-review Round 3

## [Critical] S5: `clip → missing → clip` で次の clip に src を割り当てる主体が居ない (再生不能)
- 判断: 対応する (提案どおり)
- 根拠: 指摘のとおりの穴だった。先読みは「現在 clip が `playing` になったとき」しか走らず、
  `missing` は `playing` にならないため、`missing` を挟むと次の clip へ `src` が入らない。
  **先頭が missing の並び**と **missing 連続**も同じ理由で再生不能になる (機能が成立しない)。
- 対応内容: 実装仕様に「**進んだ先の同期 (先読みが無い経路の補完)**」の行を追加し、
  `advance` 直後の規則を 4 つに固定した —
  (i) destination が `clip` で active slot の `src + generation` が**一致すれば何もしない**
  (先読み成功経路 = 再取得しない)、
  (ii) 一致しないときだけ active slot へ `src + generation` を割り当てる
  (missing 後 / 初回 / 先読み失敗のフォールバック)、
  (iii) destination が `missing` なら active slot を teardown、
  (iv) 「無条件に再代入する `$effect`」は**禁止**のまま、「**台帳と一致しないときだけ補完する**」ことは
  **許可**する (この違いが二重取得の有無を分ける)。
  component テストに `missing → clip` / `clip → missing → clip` / `missing → missing → clip` /
  **先頭が missing** の 4 並びと、「先読み済み `clip → clip` では補完が再代入しない」を追加した。

## [Warning] S5: `pause` イベントが発生しない `pause()` で抑止が残存する
- 判断: 対応する (提案どおり)
- 根拠: 非表示処理で inactive の先読み video にも `pause()` すると、その要素は元から paused なので
  イベントが発火せず `suppressPause[inactive]` が残る。その slot が後で active になったとき、
  **本物の利用者 pause を誤って握り潰す**。
- 対応内容: 自分から止める唯一の入口として `pauseProgrammatically(slot, video)` を実装仕様に書き下ろし、
  **既に paused なら抑止を立てずに抜ける**形にした。併せて
  (b) slot へ新しい `src + generation` を割り当てるとき、(c) teardown のときにも抑止をクリアする、
  の 2 点を契約に加えた。component テストに
  「既に paused の inactive slot へ programmatic pause した後、その slot が active になってからの
  利用者 pause が抑止されない」を追加した。

## [Suggestion] S2: `readyTakeId()` の docblock が「eager load 済み」と adopt 経路の説明で矛盾する
- 判断: 対応する
- 根拠: 指摘のとおり、「eager load 済みで呼ぶこと」と「adopt 応答は未ロードから lazy load」は
  文言として矛盾していた。
- 対応内容: docblock の前提を 3 段に書き直した —
  (1) 一覧の直列化では N+1 防止のため eager load 必須、
  (2) 単一 Cut の直列化では**未ロードかつ最新の `adopted_take_id` を持つインスタンス**なら lazy load を許容、
  (3) **古い relation cache を持つインスタンスは不可** (呼び出し側の責務)。

## [Suggestion] S4: 「網羅は型で担保する」は不正確
- 判断: 対応する
- 根拠: `Set<PreviewEvent["type"]>` が担保するのは要素型の正当性だけで、登録漏れは検出しない。
- 対応内容: コメントを「**要素型の正当性だけを型が担保し、登録漏れは検出しない** (漏れは Vitest が拾う)」
  へ弱めた (保証範囲を誇張しない)。

## [APPROVE] S1 / S2 / S3 / S4 / S6 / S7 / S8
- 判断: 対応不要 (合意済み)
- S2 の `unsetRelation` 非採用 (gate との衝突) と behavioral テストでの担保は承認された。
