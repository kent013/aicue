# 対応マトリクス: impl-review 確認ラウンド (Round 5 / one-shot)

全体判定: `CHANGES_REQUESTED` (Critical 0 / Warning 1 / Suggestion 1)

## [Warning] `ShouldDispatchAfterCommit` (event 側) が 0 件 pin の対象外
- 判断: **対応する** (新規論点。設計の D1〜D5 に無い第 4 の迂回路)
- 根拠: 指摘が正しい。`Events\Dispatcher::dispatch()` は
  `Illuminate\Contracts\Events\ShouldDispatchAfterCommit` を見て**イベント発火そのもの**を
  commit 後へ回す。queued listener がぶら下がっていれば **enqueue も commit 後になる**。
  この経路は D1〜D5 のどれにも映らず、event クラスは `ShouldQueue ∪ Mailable` の母集団にも
  入らないため、「どの層からも迂回できない」という docblock の主張が成立していなかった。
- 対応内容: **D6 を新設**した。
  - `QueuedJobPopulation::appClasses()` を追加 (app/ の読み込み可能な全クラス。
    event には marker interface が無く母集団を静的に絞れないため、**超集合**を
    deny-by-default で見る = event 検出のヒューリスティクスを持たない)。
  - `QueueDispatchDeferralInventory::detectDispatchAfterCommitEvents()` を追加。
  - 0 件 pin テスト 1 本 + 負のコントロール 1 本 + 母集団の超集合性テスト 1 本を追加。
  - `docs/architecture.md` の検出表に D6 行と根拠を、`AGENTS.md` にも interface を追記。
  - 現行 `app/` の実装数は 0 件 (grep 実測)。

## [Suggestion] D2 の表示契約が実装より狭い (実装は静的 `::afterCommit()` 全般を検出)
- 判断: **対応する** (実装を狭めるのではなく、表示を実装に合わせる)
- 根拠: 妥当。実装は `T_DOUBLE_COLON + afterCommit(` なので `SomeClass::afterCommit()` も検出する。
  実装を DB facade へ絞ると「facade alias を経由した別名」で抜けられるため、
  **広いまま**にして名前と説明を実装へ合わせるのが安全側。
- 対応内容: テスト名を
  `D2: first-party ランタイム PHP に静的 ::afterCommit() の呼び出しは 1 件も無い (DB:: を含む)` へ、
  失敗メッセージも「静的 ::afterCommit() (DB::afterCommit 等)」へ変更した。
