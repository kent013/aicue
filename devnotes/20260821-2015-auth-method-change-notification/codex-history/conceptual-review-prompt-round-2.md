Round 1 の Critical 3 件・Warning 4 件・Suggestion 1 件への対応を記録した対応マトリクスと、
それを反映した概念設計の該当セクション (改善アイデア / 実装方針 / 制約・前提 / スコープ外) を
以下に示します。再レビューをお願いします。

## 対応マトリクス

(以下、codex-history/conceptual-review-decisions-round-1.md の全文)

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
  queue job) で「漏れなく届く」を実質的に検証できる。deny-by-default gate を新設するコストは
  既存の監査側 gate と二重に保守対象を増やすだけで (AGENTS.md 思考原則 2)、現時点では見合わない。

## [Warning] 配信先アドレスの決定時点 (操作時点 vs 送信時点) を明文化する
- 判断: 対応する (送信時点の現アドレスを採用し、明文化する)
- 対応内容: 詳細設計に「配信先は送信時点の現在の登録メールアドレス」であることを明記する。

## [Warning] subscriber の文字列イベント登録の型安全性
- 判断: 対応する
- 対応内容: 各イベントについて「発火 → 期待した AuthMethodChangeEvent の通知が 1 件 queue に
  積まれる」ことを Feature テストで固定する (`Notification::fake()` + queue 経由の検証)。

---

## 反映後の概念設計 (該当セクションのみ抜粋)

### 改善アイデア

1. 通知内容を 1 つの Notification クラスへ統一する。対象イベント: パスワード設定・変更・
   リセット・2FA 有効化・無効化・回復コード再発行・パスキー追加・削除・SSO 連携 (8 種)。
2. 発火点は既存の監査記録 (`RecordSecurityEvent`) と同じ 2 層構成に倣う:
   - 新規 subscriber `App\Listeners\Auth\NotifyAuthMethodChange` が Fortify /
     Laravel Passkeys の既存イベント (`TwoFactorAuthenticationConfirmed` /
     `TwoFactorAuthenticationDisabled` / `RecoveryCodesGenerated` /
     `Illuminate\Auth\Events\PasswordReset` / `PasskeyRegistered` / `PasskeyDeleted`) を購読
   - イベント化されていない経路 (`PasswordCredentialService::afterPersist()`,
     `SocialAccountService::linkToUser()`) だけ直接呼ぶ
3. 通知の実発火は専用の薄い Service `App\Services\Security\AuthMethodChangeNotifier` 1 つへ
   必ず経由させる (`SecurityEventRecorder::record()` と同型の try/catch + `report()`)。
   全ての発火点はこの Service を経由する。
4. 送信は既定の `database` queue 接続にそのまま乗せる。配信先は送信時点の現在の登録メール
   アドレス。
5. `EmailChangedSecurityNotification` は変更しない (整理のみ)。

### 実装方針 (概要)

- 新設: `App\Enums\Auth\AuthMethodChangeEvent` (8 種)
- 新設: `App\Notifications\Auth\AuthMethodChangedNotification` (`ShouldQueue`)
- 新設: `App\Services\Security\AuthMethodChangeNotifier` (通知発火の唯一の窓口)
- 新設: `App\Listeners\Auth\NotifyAuthMethodChange`
- 変更: `PasswordCredentialService::afterPersist()` — 監査記録の隣で Notifier を呼ぶ
  (`setInitial()` / `change()` の両方をカバー)
- 変更: `SocialAccountService::linkToUser()` — 連携成功時に Notifier を呼ぶ
  (`register()` 内部の初回連携では呼ばない)
- パスキー削除: `EnsureLoginMethodRemains` の transaction 内で `PasskeyDeleted` が発火するため、
  `NotifyAuthMethodChange::handlePasskeyDeleted()` は `app()->terminating(...)` で queue 投入を
  遅延させ、**実行時点で対象パスキーが実際に存在しないことを再クエリで確認してから**
  Notifier を呼ぶ (自己検証。`DB::afterCommit()` 系は使わない)。

### 制約・前提 (更新点)

- パスワードの「初回設定」(`PasswordCredentialService::setInitial()`) も対象に含める
  (`POST /settings/password` は認証済み・`recent-auth` 必須のセキュリティ設定画面からの操作で、
  パスキー追加と脅威モデルが同一)。
- 組織管理者によるメンバー 2FA 解除 (`TwoFactorResetSecurityNotification`) は既存のまま変更しない。
- SSO の「解除」機能は未実装のため今回はコードを書かない (将来実装時に本ポリシーへ追加する
  方針だけ明記)。

### スコープ外 (更新点: 「パスワード初回設定時の通知」を除外リストから削除)

- ログインのたびの通知 / アプリ内通知センターへの複製 / 管理者向け通知 /
  既存の監査ログ (T108 S7)・監査 HMAC (T211) の変更 / `EmailChangedSecurityNotification` の
  実装変更 (整理のみ) / SSO 連携の「解除」機能そのものの実装

---

上記の対応で Round 1 の Critical / Warning は解消できていますか。追加の懸念があれば
[Critical] [Warning] [Suggestion] で指摘してください。解消できていれば全体判定を
APPROVED としてください。
