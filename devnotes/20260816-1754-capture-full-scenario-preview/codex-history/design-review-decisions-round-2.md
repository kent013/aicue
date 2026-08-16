# 対応マトリクス: design-review Round 2

## [Critical] S4: 非表示中の `ended` が実際には advance する (実装と S5 のテスト計画が矛盾)
- 判断: 対応する
- 根拠: 指摘のとおり。`pause()` しても既にキューへ入った `ended` / `error` は到着しうるため、
  実メディアの操作だけに依存した「非表示中は進まない」は成立していなかった。
- 対応内容: reducer の先頭に **メディア由来イベントの拒否**を追加した。
  `MEDIA_ORIGIN_EVENTS` (progress / playing / paused / resumed / ended / error / blocked) を
  `Set<PreviewEvent["type"]>` で持ち、`!state.visible` のとき早期 return する。
  利用者操作 (skip / retry)・可視性 (hidden / shown)・時間 (tick) は**常に処理する**。
  Vitest に「`hidden` の後の `ended` で index / generation が変わらない」と
  「`hidden` の後でも `skip` は進む」を追加した (メディア由来と利用者由来の取り違えの固定)。

## [Critical] S5: slot 別の世代台帳が無く、遅延イベントに正しい世代を付けられない
- 判断: 対応する (提案どおり)
- 根拠: `slotSrc` だけでは、どの video 要素から届いたイベントにどの `generation` を付けるかを
  決められない。先読み要素は「次世代」、active 要素は「現世代」であり、slot 反転後に
  旧要素から届く遅延イベントを捨てるには世代を slot へ固定する必要がある。
- 対応内容: `slotGeneration: [number | null, number | null]` を実装仕様へ追加した。
  active 割当時は現在の `generation`、先読み時は `generation + 1`。
  イベントハンドラは発火 slot の `slotGeneration[slot]` を `event.generation` として渡す。
  teardown では `slotSrc` / `slotGeneration` / `suppressPause` を同時に初期化する。
  **台帳の同一性判定を `src` 単独から `src + generation` へ変更**した
  (同じ URL が続けて現れても割当を省略しない。ドメイン上はテイクが 2 つのカットに属さないため
  連続同一 URL は起きないが、その事実に**依存しない**形にする)。
  component テストに「slot 反転後に旧 slot から届く `ended` / `error` が状態を変えない」を追加。

## [Warning] S5: `programmaticPause` が単一 boolean で、非同期イベントと 2 要素を扱えない
- 判断: 対応する (提案どおり)
- 根拠: `pause()` の直後にフラグを戻すと、非同期に配送される `pause` イベントを抑止できない。
  また video が 2 枚あるため単一 boolean では発生元を区別できない。
- 対応内容: `suppressPause: [boolean, boolean]` の **slot 別**抑止へ変更し、
  「**`pause` イベントを受けた時点で消費する** (`pause()` 直後には戻さない)」を契約にした。
  既に paused でイベントが発火しない場合に抑止が残るため、**teardown で明示的にクリア**する。
  component テストに「抑止は slot 別」「抑止は消費されるまで残る (microtask をまたぐ)」を追加。

## [Warning] S2: `CaptureCutData::fromCut()` の `adoptedTake` 鮮度が明文化されていない
- 判断: 一部対応 (事前条件の明文化 + behavioral テスト) / 一部反論 (`unsetRelation` の追加は採れない)
- 根拠:
  - 現在の 2 経路はどちらも鮮度を満たしている。詳細画面は `with('adoptedTake')`、
    adopt 応答は `CaptureTakeService::adopt()` が **tx 内で `cuts()->whereKey(...)` から取り直した
    Cut を返す**ため relation は未ロードで、`forceFill` 後に新しい id で lazy load される
    (controller が bind した `$cut` インスタンスは返らない)。
  - **提案された `unsetRelation('adoptedTake')` の追加は gate と衝突して採れない**:
    `CaptureTakeService` と `CaptureTakeController` はどちらも `TakeStatus::Ready` を含むため、
    `'adoptedTake'` の文字列を足すと `AdoptedReadyTakeCriterionInventoryTest` の
    **検出 B (判定式の同居)** に該当する。名指し免除の前提 2
    (`'adoptedTake'` の出現がすべて `->doesntHave('adoptedTake')` の単独引数形) も満たせないため、
    **gate を弱めない限り登録できない**。仮定の将来事故のために不変条件の gate を緩めるのは
    本末転倒である。
- 対応内容: `fromCut()` の**事前条件と、それを満たす 2 経路の根拠**を設計へ明記した。
  そのうえで指摘された 2 つの Feature テスト
  (「adopt 直後に `adopted_ready_take_id` が採用 id になる」「採用の付け替えで新しい方になる」) を
  テスト計画へ追加し、**鮮度を behavioral に守る**形にした
  (将来 `adopt()` が bind 済み Cut を返す形へ変わればその瞬間に赤くなる)。
  「relation がロード済み null の状態から採用するケース」は、`adopt()` が返すのが
  常に tx 内で取り直したインスタンスであるため**外から構成できない**。この構造上の理由を明記した。

## [APPROVE] S1 / S3 / S6 / S7 / S8
- 判断: 対応不要 (合意済み)
- 反論 3 点 (DTO→Service 依存 / 非 NotAllowedError の stall 回収 / `releaseForPreview()` の同期性) は
  いずれも受け入れられた。
