# 対応マトリクス: conceptual-review Round 1

## [Critical] `app()->terminating()` は commit 確定の安全境界にならない (パスキー削除)
- 判断: 対応する
- 根拠: 指摘は正しい。`PasskeyDeleted` 発火後、`EnsureLoginMethodRemains` の transaction closure が
  返るまでの間 (後続の同期 listener・レスポンス生成) で例外が起きれば transaction は rollback するが、
  `app()->terminating()` へ登録したコールバックは transaction の成否と無関係に実行される。
  「削除されていないのに削除通知だけ送る」不整合が起こり得る。
- 対応内容: `DB::afterCommit()` / `ShouldQueueAfterCommit` 系は AGENTS.md ドメイン規約 11 により
  0 件 pin (免除機構なし) のため使えない。代わりに **terminating コールバックの実行時点で
  「対象パスキーが実際に存在しないこと」を再クエリで確認してから通知する** (自己検証)。
  transaction が rollback していればパスキーは残っているため通知されず、commit していれば
  存在しないため通知される。追加の DB イベント購読 (`TransactionCommitted` 等) や
  `EnsureLoginMethodRemains` 自体の改造は不要で、既存の contract (transaction 成立 = 削除が
  実在する) をそのまま利用する最小の修正で解消できる。詳細設計に明記する。

## [Critical] キュー投入自体の失敗が元操作を失敗させ得る
- 判断: 対応する
- 根拠: `ShouldQueue` は「メール配送」を worker へ委ねるだけで、「queue へのジョブ投入 (database
  テーブルへの INSERT)」自体は呼び出し元のリクエスト内で同期的に実行される。DB 接続断等で
  この INSERT が例外を投げれば、$user->notify() を直接呼んでいる listener / Service の呼び出し元
  (認証操作そのもの) まで例外が伝播し得る。「送信失敗が元の操作を失敗させない」という T110 の
  要求 (概念設計時点の骨子) を満たすには enqueue 失敗も吸収する必要がある。
- 対応内容: 概念設計で「不要」と留保していた通知専用の薄い Service を採用する。
  `App\Services\Security\AuthMethodChangeNotifier` を新設し、`SecurityEventRecorder::record()`
  と同型の try/catch + `report()` (握り潰して継続) にする。**全ての呼び出し元はこの Service
  経由に統一する** (listener からも Service からも直接 `$user->notify()` を書かない)。
  これにより「窓口を 1 つに寄せるか」という論点にも明確な答えが出る (寄せる。理由は
  この best-effort 契約を 1 か所に持たせるため)。

## [Critical] 初回パスワード設定の除外理由がパスキー追加/SSO連携の扱いと非対称
- 判断: 対応する
- 根拠: `POST /settings/password` (`PasswordSetupController::store()`) は未認証時ではなく
  **認証済み・`recent-auth` (step-up 再認証) 必須**の設定画面から呼ばれる。既存の
  password 未設定アカウント (SSO のみ等) に対し、奪取済みセッションから新しい永続認証手段
  (パスワード) を追加できる操作であり、脅威モデルはパスキー追加と同一である。「変更・リセット」
  という T110 の文言だけを見て除外したのは誤り。
- 対応内容: 初回パスワード設定も通知対象に含める。実装上は
  `PasswordCredentialService::afterPersist()` が `setInitial()` / `change()` の**共有窓口**
  であることを活かし、既存の `SecurityEventType::PasswordSet` / `PasswordChanged` の分岐と
  同じ場所で通知も発火させる (`PasswordUpdatedViaController` イベント購読は不要になるため
  概念設計から削除する)。

## [Warning] 「他イベントは transaction 制約下にない」という前提を検証・固定する
- 判断: 対応する
- 根拠: 前提の言い切りだけでは再発防止にならない。
- 対応内容: 詳細設計に「イベント → 発火元 → transaction の有無 → 通知の発火方法」の対応表を明記し、
  各行についてルート middleware / vendor action を実際に読んだ根拠を残す。Feature テストで
  各操作 1 回につき queue job が期待どおりの件数 (0 または 1) になることを固定する。

## [Warning] 2FA 有効化と回復コード再発行が同一操作で連続発火し二重送信にならないか
- 判断: 反論する (実装上そのような連続発火は起こらない。ただし表で明記して裏取りする)
- 根拠: vendor 実装を確認済み。`EnableTwoFactorAuthentication` (2FA 有効化 = QR 表示前段階) は
  回復コードをその場で生成するが `RecoveryCodesGenerated` イベントは発火しない (dispatch するのは
  `TwoFactorAuthenticationEnabled` のみ。これは監査・通知いずれの購読対象にもしていない)。
  `RecoveryCodesGenerated` は明示的な再発行操作 (`POST /user/two-factor-recovery-codes`,
  `GenerateNewRecoveryCodes` action) からしか発火しない。`ConfirmTwoFactorAuthentication` も
  同様に `TwoFactorAuthenticationConfirmed` のみを dispatch する。よって「2FA 有効化」1 操作からは
  通知は 1 通のみで、回復コード再発行は利用者が別途明示的に行った場合のみ別の 1 通になる
  (これは意図した挙動であり二重送信ではない)。
- 対応内容: 上記の根拠 (vendor ソース該当行) を詳細設計の発火点対応表に注記として残す。

## [Suggestion] 対象イベントの inventory テスト (RecordSecurityEvent 同様) を追加する
- 判断: 見送る
- 根拠: 対象は 7〜8 イベントで増減の頻度が低く、発火点対応表 + Feature テスト (1 操作 = 期待件数の
  queue job) で「漏れなく届く」を実質的に検証できる。`SecurityEventCoverageTest` 相当の
  deny-by-default gate を新設するコストは、既存の監査側 gate と二重に保守対象を増やすだけで
  (AGENTS.md 思考原則 2: 今必要なものだけ作る)、現時点では見合わない。将来、認証手段の種類が
  増えて漏れが実際に問題化したら再検討する。

## [Warning] 配信先アドレスの決定時点 (操作時点 vs 送信時点) を明文化する
- 判断: 対応する (送信時点の現アドレスを採用し、明文化する)
- 根拠: `$user->notify()` の queued notification は `Illuminate\Queue\SerializesModels` により
  worker 実行時に User モデルを ID から再取得する (既存の `AccountDeletionRequestedNotification` /
  `PaymentFailedNotification` も同じ挙動)。CipherSweet の復号もこの再取得時に通常どおり働く。
  「登録メールアドレスへ送る」という T110 の要求は「その時点で有効な登録アドレス」と読むのが
  自然で、操作時点のアドレスをスナップショットする追加の仕組みは過剰 (かつメール変更処理自体は
  既存の `EmailChangedSecurityNotification` が別途カバーする)。
- 対応内容: 詳細設計に「配信先は送信時点の現在の登録メールアドレス」であることを明記する。

## [Warning] subscriber の文字列イベント登録の型安全性
- 判断: 対応する
- 根拠: `Event::subscribe()` の登録漏れ・取り違えは PHPStan では検出できない。
- 対応内容: 各イベントについて「発火 → 期待した AuthMethodChangeEvent の通知が 1 件 queue に
  積まれる」ことを Feature テストで固定する (`Notification::fake()` + queue 経由の検証)。
  テスト計画に明記する。
