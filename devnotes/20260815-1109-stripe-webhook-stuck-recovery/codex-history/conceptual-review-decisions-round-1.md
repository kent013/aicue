# 対応マトリクス: conceptual-review Round 1

## [Critical] `recovery_pending` を `claim()` が無条件受理すると OrderSensitive が自動再実行される
- 判断: 対応する
- 根拠: 指摘のとおり。回収 cron が `customer.subscription.*` を `recovery_pending` に落とした後、
  Stripe の後着再送が `claim()` に入ると古い snapshot が `applySubscriptionSnapshot` に流れて
  `plan_code` / `current_period_end` / `stripe_status` が巻き戻る。
  今日の挙動 (`received` に当たると `null` = 何もしない) より悪化する = 後退を新設することになる。
- 対応内容: 設計を作り替えた。
  - `claim()` は**一切変更しない** (`recovery_pending` からの再受理 arm を作らない)。
    後着再送が `recovery_pending` の行に当たっても `null` = 現行と同値
  - 滞留の判定と受理は新しい `claimStale()` が 1 トランザクションで行う。
    `OrderSensitive` と上限到達は `recovery_pending` へ置いて `null` を返す (再実行しない)
  - `RecoveryPending` は「自動再実行の対象外と判定して置いた**静止状態**」と定義し直した
    (途中経過ではない = 二度と自動では動かない)

## [Critical] `OrderSensitive` の扱いが課金状態の巻き戻りという後退を生む
- 判断: 対応する (上と同一の修正)
- 根拠: 同上。
- 対応内容: `WebhookReplaySafety` を分類 enum としてだけ持つのをやめ、
  `claimStale()` の**状態遷移の分岐そのもの**に使う設計にした。
  `SafeToReplay` 以外は再実行経路に入らない。

## [Warning] 使命貢献の中心を付与・台帳系に絞れ / スコープを SafeToReplay に絞れ
- 判断: 対応する
- 根拠: 指摘に同意。順序安全化は別問題で、本 TODO の目的 (付与の無音喪失を止める) に不要。
- 対応内容: スコープ外セクションに「`customer.subscription.*` の自動再実行 / 順序判定列の追加」を
  明記済み。改善アイデアの (0) を先頭に置いて、分類が設計の前提であることを明示した。

## [Warning] `received` は「受信済み」と「処理中」を兼ねており、生存中の worker との競合がある
- 判断: 対応する
- 根拠: 実在する競合。閾値 15 分を超えて生きている worker は考えにくいが、
  遅れて完了した worker が回収側の結果を古い在メモリ状態で上書きする経路は塞げる。
  AGENTS.md ドメイン規約 6 が「条件付き UPDATE にする」と既に要求している。
- 対応内容: 施策 (3) を追加。終局書き込みを
  `status=received AND attempts=受理時の値` の条件付き UPDATE にする。
  回収側が `attempts` を進めているため、遅れてきた worker の書き込みは 0 行になる。
  競合テスト (遅れて完了する worker) は詳細設計のテスト計画へ入れる。

## [Warning] 「事故が構造的に消える」は言い過ぎ
- 判断: 対応する
- 根拠: 上限到達・payload 不整合・実装不具合による未付与は残るので事実として誇張。
- 対応内容: 期待効果を「クラッシュで `received` に残った付与系イベントが無音で失われる**経路を塞ぐ**」
  に書き換え、「付与漏れを全部消すものではない」を明記した。

## [Warning] tenant キー不信を明記せよ
- 判断: 対応する
- 対応内容: 制約・前提に「回収は保存済み payload を既存 `process()` に渡すだけで、
  組織の解決は自 DB 行 / `stripe_id` 照合のまま。payload の `metadata` を組織解決・認可に使う
  経路を新しく作らない」を追加した。

## [Warning] 監視対象・閾値・対応手順・対象種別を docs へ明記 / ログに event_id 等を載せよ
- 判断: 対応する
- 対応内容: 実装方針の表に `docs/architecture.md` の追記項目 (回収経路・閾値・監視対象・
  運用手順・保証しないもの) を明示し、制約・前提に
  「ログ / report には `event_id` / `type` / `attempts` / `status` を必ず載せる
  (payload 本体は載せない)」を追加した。

## [Warning] 型安全性: 保存 payload を直接添字参照しない
- 判断: 対応する (詳細設計で具体化)
- 根拠: `payload` は `array<mixed>` cast のため添字直参照は PHPStan level 10 で落ちる。
- 対応内容: 回収経路は行の `type` 列 (string) と `payload` 列を既存 `process()` にそのまま渡す形にし、
  payload の読み出しは今までどおり `stringAt()` / `data_get` 経由に限る。
  `recoverStale()` の戻り値は件数 `int`。詳細設計のコードで固定する。

## [Warning] 禁止事項: 回収結果を JSON endpoint で出す等の拡張はしない
- 判断: 対応する (設計として endpoint を作らない)
- 対応内容: 回収は Artisan コマンドのみ。route も Inertia 画面も追加しない。
