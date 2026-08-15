# 対応マトリクス: conceptual-review Round 3

## [Critical] 回収 cron の再処理が例外で失敗すると、その後の再試行が保証されない
- 判断: 対応する
- 根拠: 指摘のとおり。cron 起点の失敗を `failed` にすると、cron の対象は `received` だけなので
  二度と拾われず、Stripe も既に配信成功と認識しているため再送も来ない。
  「`failed` は Stripe の再送で再処理される」という前提は HTTP 経路にしか成立しない。
- 対応内容: **回収経路の失敗は終局させない**。条件付き UPDATE で
  `status` は `received` のまま `failure_reason` だけを書き (updated_at が更新される)、
  次回の滞留判定で再び拾わせる。`attempts` は `claimStale()` の時点で既に 1 進んでいるので
  上限 8 で必ず止まり、上限到達時は `recovery_pending` + `AttemptsExhausted` へ移る。
  - Codex 推奨の `RecoveryRetryPending` 状態は**追加しない**。
    その状態と `received` の違いは「どちらの入口が受理したか」だけで、
    次の行動 (閾値経過後に回収 cron が拾う) は同一である。クラッシュで残った行と
    回収失敗で残った行を**同じ形**にしておくほうが状態機械が小さく、
    「`received` = 受理済み・未終局 (実行中か次の回収待ちかは閾値で区別する)」という
    1 つの意味で読める。状態を増やす利益が無い (思考原則 2)
  - HTTP 経路の失敗は今までどおり `failed` + 再 throw のまま変えない
    (再試行の駆動者が Stripe の再送であり、cron ではないため)

## [Warning] `UnknownEventType` は「真に未知」と「既知だが処理対象外」を混ぜている
- 判断: 対応する (改名 + 理由の明記)
- 対応内容: `UnhandledEventType` に改名し、「本アプリが処理しない種類」という 1 つの意味に統一した。
  区別しない理由を書く — どちらも `process()` の `null` arm で受理のみ終わる種類であり、
  運用の次の行動 (確認のみ) が同じだから。
  併せて「`process()` の `null` arm は何もしないため、この分類の行が滞留すること自体が
  ほぼ起こらない (claim から終局までの窓が実質ゼロ)」も明記した。
  deny-by-default を保つ理由 (分類 `replaySafety()` を通っていない行を再実行経路に流さない) も書く。

## [Warning] `recovery_reason` の既存行 / 他状態への遷移の意味を固定せよ
- 判断: 対応する
- 対応内容: 不変条件を
  **`recovery_reason IS NOT NULL` ⟺ `status = recovery_pending`** と定義した。
  既存行はすべて NULL。終局の条件付き UPDATE (`processed` / 回収失敗の据え置き) でも
  `recovery_reason = NULL` を明示的に書く。Factory の state とテストで固定する。

## [Warning] `WebhookStaleClaimOutcome` (enum) だけでは commit 後の処理に必要な情報を返せない
- 判断: 対応する
- 根拠: Eloquent Model をトランザクション外へ持ち出すと、在メモリ状態と永続状態の区別が
  曖昧になるという指摘に同意する。
- 対応内容: `claimStale()` は読み取り専用 DTO (`StaleWebhookClaimDto`) を返す。
  フィールドは `outcome` / `eventId` / `type` / `attempts` (claim 後の値) / `payload` /
  `reason` (`?WebhookRecoveryReason`)。`reason` 以外は全 outcome で値が入るので
  nullable の氾濫にはならない (outcome 別 DTO には分けない = 型を 4 つに割る利益が無い)。

## [Warning] `WebhookRecoveryResultDto` の `rested` は意味が曖昧
- 判断: 対応する
- 対応内容: 4 件数に分けて名前で意味が分かる形にした。
  `replayed` (再実行して `processed` まで終局した) /
  `retryScheduled` (再実行が失敗し `received` のまま次回へ回した) /
  `movedToRecoveryPending` (自動再実行の対象外として回収待ちへ置いた) /
  `skipped` (競合等で何もしなかった)。

## [Suggestion] 「置いた瞬間に 1 回出す」は commit 後の失敗で 0 回になる
- 判断: 対応する
- 対応内容: 「状態遷移を確定した実行が commit 後に 1 回**送信を試みる**」に書き直した。
  厳密な一回配送 (outbox) はスコープ過大として導入しない。

## [Suggestion] (c) テスト観点の追加
- 判断: 対応する
- 対応内容: 観点を 2 つ追加した。
  - 回収の再実行が例外になっても行は次回の回収対象に残り、上限まで再試行され、
    上限で `recovery_pending` + `AttemptsExhausted` になる
  - `recovery_reason` は `recovery_pending` のときだけ非 NULL である
