# 概念設計: auth-method-change-notification (T110)

## 決定の出所

TODO T110「認証手段変更のメール通知ポリシーの統一設計」について、オーナー (ishitoya@rio.ne.jp)
は 2026-08-21 に「方針は任せる。一般的なものに倣う」と決定した。本設計はこの一文を根拠として、
GitHub / Google 等が採る一般的な「認証手段が変わったら本人の登録メールへ通知する」慣行に倣い、
以下の骨子 (T110 起票時点で既に確定していた大枠) の範囲内で詳細を決める。

- 通知対象: パスワードの変更・リセット / パスキーの追加・削除 / 2 段階認証の有効化・無効化 /
  回復コードの再発行 / 外部ログイン (ソーシャル・SSO) の連携・解除
- メールアドレス変更の通知は既存 (T031 / T211 系) を変えずに並べて整理する
- 送り先はアカウントの登録メールアドレス。内容は「何が・いつ変わったか」+
  「心当たりがない場合の対処案内」。秘密情報 (トークン・コード・パスキーの識別子詳細) は載せない
- 送信は非同期 (queue 経由)。送信失敗が元の操作を失敗させない
- スコープ外: ログインのたびの通知 / アプリ内通知センターへの複製 / 管理者向け通知。
  既存の監査ログ (T108 S7) は変えない

## 背景・課題

現状、認証手段の変更を検知して本人に知らせる通知は 2 件だけ実装されている
(`App\Notifications\EmailChangedSecurityNotification` = メールアドレス変更時に旧アドレスへ、
`App\Notifications\User\TwoFactorResetSecurityNotification` = 組織管理者がメンバーの 2FA を
解除したときに本人へ)。一方、本人が自分でパスワードを変更した・パスキーを削除した・2FA を無効化した・
SSO を連携した、といった**同じくらい重要な自己操作**には通知が無く、セッション奪取後にこれらを
静かに変更されても本人が気づく手段が無い。監査ログ (T108 S7) には記録が残るが、これは事後調査用で
「今すぐ気づく」ための経路にはならない。

また、これらの発火点は Fortify のイベント (2FA) / Laravel Passkeys のイベント (パスキー) /
自前の Service 直接呼び出し (パスワード変更・SSO 連携) という異なる形で存在しており、
「新しい認証手段の増減が発生したら必ず本人に届く」という不変条件を場当たり的に各経路へ書くと、
将来経路が増えたときに漏れが生まれる。T110 はこれを一貫したポリシーとして設計することを求めている。

## 改善アイデア

> 本セクションは Codex 概念設計レビュー Round 1 / Round 2
> (`codex-history/conceptual-review-round-{1,2}.md`) の Critical 指摘を反映済み。
> 対応の根拠は `codex-history/conceptual-review-decisions-round-{1,2}.md` を参照。

1. **通知内容を 1 つの Notification クラスへ統一する**。
   対象イベントは 9 種 (`App\Enums\Auth\AuthMethodChangeEvent` の case として):
   `PasswordSet` (パスワード設定) / `PasswordChanged` (パスワード変更) /
   `PasswordReset` (パスワードリセット) / `TwoFactorEnabled` (2FA 有効化) /
   `TwoFactorDisabled` (2FA 無効化) / `RecoveryCodesRegenerated` (回復コード再発行) /
   `PasskeyRegistered` (パスキー追加) / `PasskeyDeleted` (パスキー削除) /
   `SocialAccountLinked` (SSO 連携)。この enum を受け取って
   件名・本文を組み立てる単一の `Notification` クラスとする。これらは概念として
   「認証手段が変わったことを本人に知らせる」という**同一の通知ポリシー**であり、
   AGENTS.md 思考原則 4 (別物の概念を「似ているから」で統合しない) には抵触しない —
   むしろ T110 が要求している「統一設計」そのものである。
2. **発火点は既存の監査記録 (`RecordSecurityEvent`) と同じ構成に倣う**。
   既存の監査は「vendor イベントを購読する 1 subscriber」+「イベント化できない経路
   (Service 内の直接呼び出し) のみ個別に `SecurityEventRecorder::record()` を呼ぶ」という
   2 層構成になっている。通知もこれに倣い、
   - 新規 subscriber (`App\Listeners\Auth\NotifyAuthMethodChange`) が Fortify /
     Laravel Passkeys の既存イベント (`TwoFactorAuthenticationConfirmed` /
     `TwoFactorAuthenticationDisabled` / `RecoveryCodesGenerated` /
     `Illuminate\Auth\Events\PasswordReset` / `PasskeyRegistered` / `PasskeyDeleted`)
     を購読して通知を dispatch する
   - イベント化されていない経路 (パスワード設定・変更の `PasswordCredentialService::
     afterPersist()`、SSO 連携の `SocialAccountService::linkToUser()`) だけ、その場で
     直接呼ぶ
3. **通知の実発火は専用の薄い Service (`App\Services\Security\AuthMethodChangeNotifier`)
   1 つへ必ず経由させる**。Round 1 の Critical 指摘により判明した通り、`ShouldQueue`
   Notification でも「queue へのジョブ投入」自体はリクエスト内で同期的に行われるため、
   投入失敗 (DB 接続断等の稀な障害) が認証操作そのものを失敗させ得る。
   `SecurityEventRecorder::record()` と同型の try/catch + `report()` (握り潰して継続) を
   この Service 1 つに持たせ、全ての発火点 (subscriber 側・Service 直接呼び出し側の両方) は
   必ずこの Service を経由する。「発火点を 1 つの窓口へ寄せるか」という論点への回答は
   **寄せる**であり、理由は「best-effort 契約を 1 か所にだけ書く」ためである
   (通知内容の組み立て自体を共通化する意味ではなく、失敗を握り潰す契約を共通化する意味)。
4. **送信は既存の queue 設定に倣い非同期化する**。既存の `PaymentFailedNotification` /
   `AccountDeletionRequestedNotification` と同じく `ShouldQueue` を実装し、既定の
   `database` 接続 (`after_commit => false`) にそのまま乗せる (専用 queue 接続は起こさない)。
   配信先は **送信時点の現在の登録メールアドレス** (queued notification が worker 実行時に
   User モデルを ID から再取得し、その時点の CipherSweet 復号値を使う。既存の
   `AccountDeletionRequestedNotification` 等と同じ挙動であり、操作時点のアドレスを
   スナップショットする追加機構は作らない)。
5. **メールアドレス変更の通知はそのまま**。`EmailChangedSecurityNotification` の実装
   (旧アドレスへ on-demand 通知・同期送信) は変更しない。今回追加する通知群と
   「認証手段の変更を本人に知らせる」という目的は共通するため、詳細設計の文書内では
   同じセクションに並べて整理する (実装は触らない)。

## 期待効果

- セッション奪取・内部不正・誤操作によって認証手段が静かに変更されたとき、本人が
  ほぼリアルタイムで気づける経路が増え、被害拡大前の対処 (パスワード再設定・サポート連絡) が
  可能になる (使命への直接寄与ではないが、動画マニュアル作成という業務データを守る
  土台としてのアカウントセキュリティを強化する)。
- 発火点を「vendor イベント購読 + 直接呼び出しの必要最小数」に集約することで、将来
  認証手段が増えても (使命ドキュメントにある v1 スコープの範囲内で) 通知漏れが起きにくい
  構造になる。

## 実装方針 (概要)

- 新設: `App\Enums\Auth\AuthMethodChangeEvent` (通知対象イベントの列挙。9 種。上記参照)
- 新設: `App\Notifications\Auth\AuthMethodChangedNotification` (`ShouldQueue`)
- 新設: `App\Services\Security\AuthMethodChangeNotifier` (通知発火の唯一の窓口。2 メソッド:
  `notify()` = transaction 外で直ちに enqueue する best-effort 版
  (`SecurityEventRecorder::record()` と同型の try/catch + `report()`。実際のメール配送は
  worker が非同期に行う)、`notifyAfterCommit()` = 「今の transaction が commit したら
  `notify()` を呼ぶ」を予約する版。詳細は下記「パスキー削除の発火タイミング」)
- 新設: `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` (`EnsureLoginMethodRemains`
  専用。container には `scoped()` で bind する — `singleton()` は Octane 等の長寿命
  worker でリクエストをまたいで同一インスタンスが再利用され得るため使わない。
  `push()` / `flush()` / `discard()` の 3 メソッドのみ。詳細は下記「パスキー削除の発火タイミング」)
- 新設: `App\Listeners\Auth\NotifyAuthMethodChange` (イベント購読。
  `AppServiceProvider::boot()` の `Event::subscribe(RecordSecurityEvent::class)` の隣に
  `Event::subscribe(NotifyAuthMethodChange::class)` を追加)
- 変更: `App\Services\Auth\PasswordCredentialService::afterPersist()` — 既存の監査記録
  呼び出しの隣で `AuthMethodChangeNotifier::notify()` を呼ぶ (`setInitial()` / `change()` の
  両方をこの共有窓口でカバーする。初回設定を対象にする理由は下記「制約・前提」)
- 変更: `App\Services\Auth\SocialAccountService::linkToUser()` — 連携成功時に
  `AuthMethodChangeNotifier::notify()` を追加 (`register()` 内部の初回連携では呼ばない。
  理由は下記「制約・前提」)
- 変更: `App\Http\Middleware\EnsureLoginMethodRemains::handle()` — `DB::transaction()` を
  try/catch で包み、正常終了時は `LoginMethodRemovalPostCommitCallbacks::flush()` を、
  例外 (rollback) 時は `discard()` を呼んでから再送出する (詳細は下記
  「パスキー削除の発火タイミング」)

### 発火点対応表 (イベント → transaction の有無 → 発火方法)

Codex Round 2 の Warning を受け、各発火点の transaction 有無を実装・route middleware で
確認した結果を確定する (詳細設計への先送りにしない)。

| イベント | 発火元 | transaction 内か | 根拠 (vendor ソースを直接確認済み) | 発火方法 |
|---|---|---|---|---|
| `PasswordSet` / `PasswordChanged` | `PasswordCredentialService::afterPersist()` | 否 | `change()` は元々 transaction を開かない。`setInitial()` は `$hash = DB::transaction(...)` の**戻り値を受けた次の行**で `afterPersist()` を呼ぶため、その時点で既に commit 済み。呼び出し元 route (`POST /settings/password` = `recent-auth`,`throttle:password-set` / `PUT /user/password` = `throttle:password-verify`) はいずれも `EnsureLoginMethodRemains` を含まず他のラップも無い (`route:list` で確認済み) | `notify()` |
| `PasswordReset` | `Illuminate\Auth\Events\PasswordReset` | 否 | `vendor/laravel/fortify/src/Actions/CompletePasswordReset.php` は `save()` + `event()` のみ。呼び出し元の `Illuminate\Auth\Passwords\PasswordBroker::reset()` も `Timebox::call()` (タイミング攻撃対策のラッパ。transaction ではない) の中で callback を呼ぶだけで `DB::transaction()` は無い | `notify()` |
| `TwoFactorEnabled` (`TwoFactorAuthenticationConfirmed`) | `vendor/laravel/fortify/src/Actions/ConfirmTwoFactorAuthentication.php` | 否 | `forceFill()->save()` + `event::dispatch()` のみ | `notify()` |
| `TwoFactorDisabled` (`TwoFactorAuthenticationDisabled`) | `vendor/laravel/fortify/src/Actions/DisableTwoFactorAuthentication.php` | 否 | 同上。route `DELETE /user/two-factor-authentication` middleware は `recent-auth` のみ | `notify()` |
| `RecoveryCodesRegenerated` (`RecoveryCodesGenerated`) | `vendor/laravel/fortify/src/Actions/GenerateNewRecoveryCodes.php` | 否 | 同上 | `notify()` |
| `PasskeyRegistered` | `vendor/laravel/passkeys/src/Actions/StorePasskey.php` | 否 | `$user->passkeys()->create([...])` (単一 INSERT) + `PasskeyRegistered::dispatch()` のみ。controller (`PasskeyRegistrationController::store()`) にも transaction 無し。route `POST /user/passkeys` middleware は `recent-auth` のみ (`EnsureLoginMethodRemains` 無し。`route:list` で確認済み) | `notify()` |
| `PasskeyDeleted` | `vendor/laravel/passkeys/src/Actions/DeletePasskey.php` | **是** (vendor Action 自体は transaction を持たないが、**外側**の route middleware `EnsureLoginMethodRemains` が課す) | `$passkey->delete()` (単一 DELETE) + `PasskeyDeleted::dispatch()` のみ。route `DELETE /user/passkeys/{passkey}` middleware に `EnsureLoginMethodRemains` あり (`route:list` で確認済み)。同 middleware が `DB::transaction()` で `$next()` (controller・同期 listener・レスポンス生成まで) を丸ごとラップする | `notifyAfterCommit()` (下記) |
| `SocialAccountLinked` | `SocialAccountService::linkToUser()` | 否 | `link()` は単一 INSERT のみで transaction を開かない。呼び出し元 route (`GET /auth/{provider}/callback` = `throttle:social-callback`) にも無い | `notify()` |

(表内の `notify()` は「transaction 外で直ちに queue へジョブを投入する」を指す。実際のメール
配送は worker が非同期に行うため、利用者への到達は queue の処理タイミング分遅れる。)

### パスキー削除の発火タイミング (`notifyAfterCommit()`)

`EnsureLoginMethodRemains` の docblock は「この transaction の中で外部 I/O や `afterCommit`
でない queue dispatch をしてはならない」と明記している (ロールバック時に外部だけ実行済みと
いう食い違いを避けるため)。一方、AGENTS.md ドメイン規約 11 により `DB::afterCommit()` /
`->afterCommit()` / `ShouldQueueAfterCommit` 等は app/ 全体で 0 件に固定
(`QueueDispatchAtomicityInventoryTest`。免除機構なし) されており使用できない。

Codex Round 2 で「`terminating()` 時点で対象行の不存在を再クエリする」案の TOCTOU (別リクエスト
が偶然同じ行を消した場合の誤通知) を指摘され撤回し、Round 3 で代わりに検討した
「`Illuminate\Database\Events\TransactionCommitted` をその場で購読する」案も、
**「次に発火する `TransactionCommitted` が必ずこの transaction のものである」という前提が
成立しない** (rollback 後に同一リクエスト内で無関係な別の transaction が commit すれば
誤発火する。transaction を一意に識別する ID が無いため対応づけを保証できない) との指摘を
受け、これも撤回した。最終的に次の方式を採用する。

- `EnsureLoginMethodRemains` **専用**の collector `App\Support\Auth\
  LoginMethodRemovalPostCommitCallbacks` を新設する (「アプリ全体の汎用 post-commit 基盤」を
  作るわけではない。名前で用途を明示する)。container binding は `scoped()`
  (`singleton()` は Octane 等の長寿命 worker でリクエストをまたいで同一インスタンスが
  再利用され得るため使わない)。
  - `push(Closure $callback): void` — 実行予定のコールバックを積む
  - `flush(): void` — 保持配列を**先に空へ移してから**溜まっていたコールバックを実行する
    (2 回呼んでも 2 回目は何もしない = 実行前に空へ移すため、例外が発生しても実行済み・
    未実行のコールバックが次回の `flush()` で再実行されることはない。**1 件目が例外を投げると
    後続コールバックは実行されない** [`foreach` の通常の挙動] が、`AuthMethodChangeNotifier::
    notify()` が例外を内部で吸収するため現スコープでは実害が無い。Codex Round 5 Suggestion)
  - `discard(): void` — 保持配列を実行せずに空にする (rollback 時に呼ぶ)
- `EnsureLoginMethodRemains::handle()` を次のように変更する:
  ```php
  try {
      $response = DB::transaction(function () use ($request, $next, $user): Response {
          $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
          $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));
          if ($remaining->isEmpty()) {
              return $this->reject($request);
          }
          return $this->pass($next, $request);
      });
  } catch (Throwable $e) {
      $this->postCommitCallbacks->discard();
      throw $e;
  }
  $this->postCommitCallbacks->flush();
  return $response;
  ```
  rollback (例外) 時は `discard()` が呼ばれ、collector は空になってから再送出される
  (= commit 成否とコールバック実行が 1:1。TOCTOU も対応づけの曖昧さも生じない)。
  `reject()` 分岐 (唯一のログイン手段を消そうとした 422/302 応答) は例外を投げずに
  正常な値を返すため transaction は commit するが、この分岐では `$next()` が呼ばれず
  `PasskeyDeleted` も発火していないため、collector には何も積まれておらず `flush()` は
  実質的に no-op になる。既存の Architecture テスト (`LoginMethodRemovalRouteTest` 等) が
  変わらず green であることを実装時に確認する。
- `AuthMethodChangeNotifier::notifyAfterCommit(User $user, AuthMethodChangeEvent $event)` は
  `$this->postCommitCallbacks->push(fn () => $this->notify($user, $event));` を行うだけ。
- `TransactionCommitted` 購読・`app()->terminating()` はいずれも不要になった。
- 他のイベントはこの制約下に無いため `notify()` (即時 enqueue) を使う。

## 制約・前提

- **SSO 連携 (`SocialAccountService::link()`)** は `register()` (新規アカウント作成に伴う
  初回連携) と `linkToUser()` (ログイン中ユーザーが既存アカウントへ後から連携を追加) の
  両方から呼ばれる共有 private メソッドである。通知対象は「既存アカウントが新しい認証手段を
  獲得した」ことであり、新規登録時点の初回連携はこれに当たらない (本人がその場で登録した
  ばかりのアカウントに「連携しました」と通知するのは典型的な一般慣行にも無い冗長な通知)。
  したがって通知呼び出しは `linkToUser()` 側だけに置く。監査記録
  (`SecurityEventType::SocialAccountLinked`) は既存どおり両方から記録され続ける
  (T108 S7 を変えないため)。
- **SSO の「解除」機能は現在アプリに実装されていない** (該当する route / controller が
  存在しない。`grep` で確認済み)。AGENTS.md 思考原則 2 (今必要なものだけ作る) に従い、
  存在しない機能のためのコードは書かない。本設計は「解除が実装されたら、この通知ポリシーの
  対象イベントとして扱う」という方針だけを明記し、実装は解除機能自体を追加する将来の TODO の
  スコープとする。
- **パスワードの「初回設定」は対象に含める**。T110 のスコープ文言は「変更・リセット」だが、
  `PasswordCredentialService::setInitial()` の呼び出し元 `POST /settings/password` は
  未認証時の新規登録専用ルートではなく、**認証済み・`recent-auth` (step-up 再認証) 必須**の
  セキュリティ設定画面から呼ばれる (SSO のみで password 未設定のアカウントへ、後から
  password を追加する操作)。奪取済みセッションから新しい永続認証手段を追加できる点で
  パスキー追加と脅威モデルが同一であり、除外する理由が無い (Codex Round 1 Critical の指摘を
  受けて訂正。当初は文言だけを見て対象外としていたが誤りだった)。
- **組織管理者によるメンバー 2FA 解除** (`TwoFactorResetSecurityNotification`) は既存の
  別ポリシー (加害者側ではなく組織管理者が正規に行う操作) であり、本設計が統一するのは
  「本人が自分の認証手段を変更したときの通知」である。両者は対象読者・文脈が異なるため
  統合しない (思考原則 4)。既存のまま変更しない。
- 対象イベントは Fortify / Laravel Passkeys の vendor イベント発火点をそのまま使う
  (新しいドメインイベントを自前で作らない。既にある発火点で十分カバーできるため)。

## スコープ外

- ログインのたびの通知
- アプリ内通知センターへの複製
- 管理者向け通知
- 既存の監査ログ (T108 S7)・監査 HMAC (T211) の変更
- `EmailChangedSecurityNotification` の実装変更 (整理のみ)
- SSO 連携の「解除」機能そのものの実装 (機能が存在しないため)
