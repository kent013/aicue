結論として、詳細設計の配線は概ね再現されていますが、上位規約である「業務状態の保存とキュー投入の原子性」に反するため承認できません。best-effort は配送失敗・重複を許す方針としては一貫していますが、現状は enqueue 前のプロセス終了による欠落まで通常設計として許容しています。

## Critical

### 業務状態の保存と通知ジョブ投入が同一トランザクションではない

対象:

- [EnsureLoginMethodRemains.php](/workspace/.claude/worktrees/tasks/T110/app/Http/Middleware/EnsureLoginMethodRemains.php:68)
- [LoginMethodRemovalPostCommitCallbacks.php](/workspace/.claude/worktrees/tasks/T110/app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php:93)
- [AuthMethodChangeNotifier.php](/workspace/.claude/worktrees/tasks/T110/app/Services/Security/AuthMethodChangeNotifier.php:29)
- [NotifyAuthMethodChange.php](/workspace/.claude/worktrees/tasks/T110/app/Listeners/Auth/NotifyAuthMethodChange.php:49)
- [PasswordCredentialService.php](/workspace/.claude/worktrees/tasks/T110/app/Services/Auth/PasswordCredentialService.php:96)
- [SocialAccountService.php](/workspace/.claude/worktrees/tasks/T110/app/Services/Auth/SocialAccountService.php:110)

AGENTS.md ドメイン規約11は、業務状態の保存とキュー投入を同一トランザクションに置き、`afterCommit` に依存しないことを要求しています。一方、実装は次の構造です。

- パスキー削除は、削除トランザクション終了後に `flush()` して enqueue
- パスワード設定・変更は、保存用 `DB::transaction()` の終了後に enqueue
- SSO連携も、`link()` の永続化終了後に enqueue
- vendorイベント経路も、状態変更後に listener から enqueue
- enqueue 例外はすべて吸収

したがって、状態だけ確定して通知ジョブが存在しない期間が意図的に発生します。特にパスキー削除では、commit と `flush()` の間でプロセスが終了すると確実に通知が欠落します。

これは「メール配送の成功を保証しない」という best-effort とは別の問題です。配送・再試行・重複を best-effort にしても、業務状態と「通知を試行すべき事実」の永続化は規約11に従う必要があります。

詳細設計自体がこの構造を指定しているため、局所修正ではなく設計の再判定が必要です。リポジトリ内の規約11準拠パターンに合わせ、少なくとも状態保存と耐久的な通知意図の記録を同一トランザクションに置いてください。単に現在の enqueue をトランザクション内へ移すだけでは、外部キュー利用時の原子性や例外吸収との矛盾が残るため不十分です。

このため、設計案の D36 と `docs/architecture.md` にある「正常終了後に enqueue」「enqueue 失敗も欠落として許容」という記述も同時に見直す必要があります。

## Warning

### Featureテストが設計で必須とされた異常系を実装していない

[AuthMethodChangeNotificationTest.php](/workspace/.claude/worktrees/tasks/T110/tests/Feature/Auth/AuthMethodChangeNotificationTest.php:1) — 要修正

不足しているケースがあります。

- パスワード変更の実経路で enqueue を例外化し、パスワード更新とHTTP応答への影響を検証するテスト
- 実際の `POST /user/passkeys` を通るパスキー登録テスト  
  現在は `PasskeyRegistered::dispatch()` の直接発火だけで、route/controller/package actionがイベントを発火することを固定していません。
- メール本文にリセットトークン、回復コード、TOTPコード、パスキー識別子が含まれないことの検証

原子性設計を修正した後は、rollback時に状態と通知意図がともに残らないこと、commit時にともに残ることを実経路で固定してください。

### `report()` の実行をテストしていない

[AuthMethodChangeNotifierTest.php](/workspace/.claude/worktrees/tasks/T110/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php:13) — 要修正

現在のテストが保証するのは「例外が伝播しない」ことだけです。詳細設計は「例外を `report()` して継続する」契約なので、例外報告も検証対象です。

また、`assertSentTo()` のクロージャ引数は次のように明示型を付けた方がPHPStan level 10上も確実です。

```php
fn (AuthMethodChangedNotification $notification): bool =>
    $notification->event() === AuthMethodChangeEvent::PasskeyDeleted
```

### 秘密情報非掲載の不変条件がテストで固定されていない

[AuthMethodChangedNotificationTest.php](/workspace/.claude/worktrees/tasks/T110/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php:43) — 要修正

現コードの本文に秘密情報は見当たりません。しかしセキュリティ要件として明示されている以上、件名・本文・action URLについて少なくとも以下を負例として固定すべきです。

- パスワードリセットトークン
- 2FA秘密鍵、TOTP、回復コード
- WebAuthn credential IDなどのパスキー識別子
- Socialiteのprovider user ID

### 最終検証が未完了

提示された結果では、修正後のフルスイートがまだ実行中で、`composer phpstan` の結果もありません。規約上、少なくともフルテストとPHPStan level 10の完了結果が必要です。対象2ファイルの30テスト成功だけでは全体のgreenを証明しません。

## Suggestion

### 直接dispatchテストのcollectorを後始末する

対象:

- [PasskeyAuditTrailTest.php](/workspace/.claude/worktrees/tasks/T110/tests/Feature/Auth/PasskeyAuditTrailTest.php:96)
- [PasskeyDeletionAtomicityTest.php](/workspace/.claude/worktrees/tasks/T110/tests/Feature/Auth/PasskeyDeletionAtomicityTest.php:33)
- [PasskeyRecentAuthInvalidationTest.php](/workspace/.claude/worktrees/tasks/T110/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php:76)

`start()` だけを呼び、`flush()` / `discard()` せずにテストを終了しています。通常はテストごとにコンテナが破棄されるとしても、request-scopedオブジェクトをactiveのまま残す形は状態遷移契約とずれます。

対象listenerだけを分離するか、`try/finally` で `discard()` してください。なお、原子性設計の変更後は、このテスト補助自体が不要になる可能性があります。

## ファイル別判定

| ファイル | 判定 | 理由 |
|---|---|---|
| `app/Enums/Auth/AuthMethodChangeEvent.php` | OK | 9イベント、値、見出しが設計どおり。秘密情報なし |
| `app/Http/Middleware/EnsureLoginMethodRemains.php` | 要修正 | transaction終了後enqueueが規約11違反 |
| `app/Listeners/Auth/NotifyAuthMethodChange.php` | 要修正 | 状態保存とenqueueの原子性を保証しない |
| `app/Notifications/Auth/AuthMethodChangedNotification.php` | OK | `ShouldQueue`、本文、現在アドレスへの配送設計は妥当。秘密情報の直接掲載なし |
| `app/Providers/AppServiceProvider.php` | OK | scoped bindingと購読登録は設計どおり |
| `app/Services/Auth/PasswordCredentialService.php` | 要修正 | パスワード保存commit後にenqueue |
| `app/Services/Auth/SocialAccountService.php` | 要修正 | SSO連携保存後にenqueue |
| `app/Services/Security/AuthMethodChangeNotifier.php` | 要修正 | enqueue失敗吸収とpost-transaction予約が規約11と矛盾 |
| `app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php` | 要修正 | 状態機械単体は正しいが、禁止された原子性分離を実現する機構になっている |
| `tests/Architecture/JobDeferralTerminationGateTest.php` | OK | `NO_DEFERRAL` 登録は通知の性質と整合 |
| `tests/Architecture/JobExecutionDedupInventoryTest.php` | OK | 二重メールを許容する理由と件数pinが整合 |
| `tests/Architecture/PasskeyPackageContractTest.php` | OK | 同期購読者3件と順序の完全一致pinはdeny-by-defaultの趣旨に整合 |
| `tests/Architecture/QueuedJobLeaseInventoryTest.php` | OK | `null` 登録は、状態を書かず重複を許容する通知との分類として整合 |
| `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` | 要修正 | 実パスキー登録経路、enqueue失敗実経路、秘密情報負例が不足。原子性前提も要変更 |
| `tests/Feature/Auth/PasskeyAuditTrailTest.php` | OK | 本来の監査主張は維持。ただしcollector後始末を推奨 |
| `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` | OK | 削除rollbackの主張は維持。ただしcollector後始末を推奨 |
| `tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php` | OK | recent-authの主張は維持。ただしcollector後始末を推奨 |
| `tests/Support/TemplateDivergence/LedgerPins.php` | OK | 提示差分上、債務2件削除と逸脱2件増加の算術は一致 |
| `tests/Support/TemplateDivergence/adoption-debt.tsv` | OK | 変更した共有ファイル2件を債務から外す操作自体は規約に整合 |
| `tests/Unit/Enums/Auth/AuthMethodChangeEventTest.php` | OK | 全caseの非空性を固定 |
| `tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php` | 要修正 | 基本契約は確認済みだが秘密情報非掲載の負例が不足 |
| `tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php` | 要修正 | 例外吸収のみで`report()`を検証していない |
| `tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php` | OK | 宣言された状態遷移を十分に固定 |
| `docs/template-divergence.md` のD36 | 要修正 | 規約11に反するpost-transaction enqueueを恒久逸脱として正当化している |
| `docs/architecture.md` のT110節 | 要修正 | enqueue欠落を通常のbest-effort範囲として記述しており、原子性規約と矛盾 |

DTO / JsonResourceはレスポンス追加がないため非該当です。提示差分内にPHPStanを型のwidenやbaselineで回避した箇所は見当たりませんが、PHPStan完走結果は別途必要です。

CHANGES_REQUESTED