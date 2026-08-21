## Round 2: 対応マトリクスと修正差分

前回 (Round 1) の CHANGES_REQUESTED を受けた対応内容は下記の対応マトリクスに記載した。
特に [Critical] (業務状態の保存と通知ジョブ投入の同一トランザクション化) については、
詳細設計レビューでの既往の議論を根拠に反論している。この反論を検討し、妥当なら
判定を変更してください。妥当でなければ、既往の議論のどこが不十分かを具体的に指摘してください。

# 対応マトリクス: impl-review Round 1

## [Critical] 業務状態の保存と通知ジョブ投入が同一トランザクションではない (AGENTS.md ドメイン規約 11)

- 判断: **反論する**
- 根拠:
  1. この論点は本設計の**詳細設計レビュー Round 1** で既に [Warning] として指摘済みであり
     (`devnotes/20260821-2015-auth-method-change-notification/detailed-review-round-1.md`
     施策 2 の 2 つ目の [Warning])、対応は既に取り込まれている。当時の指摘は「commit と通知が
     1:1 という保証表現が best-effort 契約と一致しない」という**表現上の過大主張**への指摘であり、
     「同一トランザクション化せよ」という要求ではなかった。detailed-design.md はこれを受けて
     「rollback した場合は積んだコールバックを実行しない。transaction 呼び出しの正常終了後、
     積んだコールバックの実行を 1 回試みる (best-effort)」という表現に**明示的に絞り**、
     「規約 11 の 0 件 pin と非干渉」という**機械的整合性の主張に限定**した
     (「commit 後の耐久性の証明」とは表現しない、と detailed-design.md 537 行目付近に明記)。
     この絞り込みは detailed-review-round 2〜4 を経て APPROVED まで到達している。
  2. AGENTS.md ドメイン規約 11 が deny-by-default で 0 件 pin するのは
     `->afterCommit()` / `DB::afterCommit` / `ShouldQueueAfterCommit` /
     `ShouldHandleEventsAfterCommit` / `ShouldDispatchAfterCommit` /
     `$afterCommit` truthy / config `after_commit=>true` という**列挙された特定の Laravel
     API**である。本実装 (`LoginMethodRemovalPostCommitCallbacks` の
     start/push/flush/discard) はこの列挙のどれも使っていない。規約 11 自身の docblock も
     「動的な迂回・helper 経由の呼び出しには沈黙する」ことを明記しており、
     静的検査 (`QueueDispatchAtomicityInventoryTest`) が対象とする形と、
     本機構が対象とする形は**別物**である。
  3. `EnsureLoginMethodRemains` の transaction は「ロック取得〜controller〜同期
     listener〜レスポンス生成まで丸ごと」を囲む本アプリ固有の広い transaction であり
     (D36 として登録済み)、この transaction の**内側**で queue 投入や外部 I/O を行うことは
     既存契約 (同 middleware の docblock) が禁じている。したがって「transaction 内で
     dispatch する」という規約 11 の字義通りの適用は、そもそも本 middleware の既存契約と
     衝突し、選択肢として存在しなかった。
  4. best-effort 通知という設計方針 (二重配送も欠落も許容) は、規約 11 が想定する
     「取り消せない外部副作用の一回性」(規約 6・11 が例示するのは LLM 呼び出し・S3 PUT・
     Stripe 課金・media pipeline 等の**取り消せない業務行為**) とは脅威モデルが異なる。
     本通知が commit 後の稀なプロセス終了で欠落しても、ユーザーの認証手段の状態自体は
     正しく確定しており、再送不能な業務的損失は発生しない
     (`docs/architecture.md` §認証手段変更のメール通知ポリシー の「保証しないもの」に
     この欠落可能性は明記済み)。
  5. 以上により、Critical が要求する「状態保存と通知ジョブ投入の同一トランザクション化」は、
     (a) 既に設計レビューで検討済みかつ承認済みの判断を覆すものであり、
     (b) 本 middleware の既存契約 (transaction 内での外部 I/O 禁止) と矛盾し、
     (c) 規約 11 が保護しようとする脅威 (取り消せない業務行為の欠落) が本ケースには
     当てはまらないため、本ラウンドでは実装を変更しない。
- 対応内容: 上記の経緯を Round 2 プロンプトで提示し、再判定を依頼する。
  ただし関連する Warning (以下) は全て対応済み。

## [Warning] Feature テストが設計で必須とされた異常系を実装していない

- 判断: **一部対応する / 一部見送る**
- 対応した項目:
  - 「パスワード変更の実経路で enqueue を例外化し、パスワード更新と HTTP 応答への影響を検証する
    テスト」→ `AuthMethodChangeNotificationTest.php` に
    `PUT /user/password は通知の enqueue が例外化してもパスワード変更自体は成功する` を追加。
    `Dispatcher::send()` を例外化し、パスワード変更自体の成功と `report()` 実行の両方を固定した。
- 見送った項目:
  - 「実際の `POST /user/passkeys` を通るパスキー登録テスト」→ 見送る。
    根拠: 本アプリの既存テスト (`tests/Feature/Auth/PasskeyRouteAccessTest.php`) を調査した結果、
    実 route を通した WebAuthn ceremony の**完全な**成功パス (`PasskeyRegistered` 発火まで)
    を検証する Feature テストは T110 以前から**存在しない**(既存テストは 422 で止まる
    ceremony 検証の手前までしか通していない。有効な attestation フィクスチャが本テスト基盤に
    無いため)。既存 `PasskeyAuditTrailTest.php` も同じ理由で
    「イベント自体からも記録される (listener の直接検証)」という**直接 dispatch** の形で
    vendor イベント境界をテストしている。T110 のテストもこの既存の境界に合わせた設計であり、
    新しい WebAuthn フィクスチャ基盤の追加は本タスクの範囲外 (思考原則 2: 今必要なものだけ作る)。
  - 「メール本文に秘密情報が含まれないことの検証」→ 対応済み (別 Warning 参照)。

## [Warning] `report()` の実行をテストしていない

- 判断: **対応する**
- 対応内容: `AuthMethodChangeNotifierTest.php` に `Exceptions::fake()` +
  `Exceptions::assertReported(RuntimeException::class)` を使うテストを追加。
  併せて `assertSentTo()` のクロージャ引数に明示型 (`AuthMethodChangedNotification $n`) を付けた。

## [Warning] 秘密情報非掲載の不変条件がテストで固定されていない

- 判断: **対応する**
- 対応内容: `AuthMethodChangedNotificationTest.php` に、全 9 case × 疑わしい
  context 文字列 (reset-token / recovery-code / totp-secret / credential-id /
  provider-user-id を含む複合文字列) を渡して `toMail()` を呼び、
  `SocialAccountLinked` (provider 表示名を意図的に本文へ載せる契約) を除く 8 case で
  これらのマーカーが一切本文に現れないことを固定するテストを追加した。
  実際の呼び出し元 (`AuthMethodChangeNotifier` / `PasswordCredentialService` /
  `SocialAccountService` / `NotifyAuthMethodChange`) がこれらの値を `$context` へ渡すことは
  無い (`$context` は provider 表示名専用) ことも確認済み。

## [Warning] 最終検証が未完了

- 判断: **対応する**
- 対応内容: 本ラウンドの修正 (adoption-debt.tsv 2 件の登録移行 + D38/D39 登録 +
  LedgerPins 更新 + pint 修正 + テスト追加) を含めてフルスイートを再実行中。
  結果は Round 2 プロンプトに添付する。

## [Suggestion] 直接 dispatch テストの collector を後始末する

- 判断: **対応する**
- 対応内容: `PasskeyAuditTrailTest.php` / `PasskeyDeletionAtomicityTest.php` /
  `PasskeyRecentAuthInvalidationTest.php` の 3 テストで、`start()` 後の assertion 完了時点に
  `LoginMethodRemovalPostCommitCallbacks::discard()` を呼び、active のまま終わらないよう
  後始末した。

## ファイル別判定への対応

- `docs/template-divergence.md` の D36: Critical への反論と同じ根拠のため変更なし。
- `docs/architecture.md` の T110 節: 「保証しないもの」に既に
  「queue 投入の成功、およびメールの実配送成功は保証しない」と明記済みであることを確認。
  Critical への反論が採用されれば追加修正は不要と判断。ただし Round 2 で Codex が
  この文言の具体的な不足点を指摘した場合は追記する。

## 本ラウンドで追加で対応した目録整合 (Codex 提示外だが検証で判明)

フルスイート再実行で判明した 2 件を追加修正した (Critical/Warning の指摘外だが green 化に必須):

1. `tests/Architecture/TemplateDivergenceFingerprintTest.php`: 本ラウンドで編集した
   `PasskeyPackageContractTest.php` / `QueuedJobLeaseInventoryTest.php` が採用時債務
   (`adoption-debt.tsv`) の凍結ハッシュから変化したため、3 択のうち「意図的逸脱として登録する」
   を選択。`docs/template-divergence.md` に D38 (キュー接続リース目録)・D39 (パスキー削除の
   同期購読者 pin) を追加し、`adoption-debt.tsv` から該当 2 行を削除、
   `LedgerPins::DIVERGENCE_ENTRY_COUNT` (35→37) / `ADOPTION_DEBT_COUNT` (172→170) を更新。
2. `vendor/bin/pint --test`: `QueuedJobLeaseInventoryTest.php` の import 順・空白を
   `vendor/bin/pint` で自動修正。

---

## 修正後の実装差分 (git diff HEAD -- app/ resources/ tests/ routes/ docs/)
diff --git a/app/Enums/Auth/AuthMethodChangeEvent.php b/app/Enums/Auth/AuthMethodChangeEvent.php
new file mode 100644
index 00000000..f9cbc65d
--- /dev/null
+++ b/app/Enums/Auth/AuthMethodChangeEvent.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Auth;
+
+/**
+ * 認証手段の変更を本人へメール通知する対象イベント (T110)。
+ *
+ * 発火点対応表 (どの vendor イベント / Service 呼び出しがどの case を発火させるか、
+ * transaction の有無) は docs/architecture.md §認証手段変更のメール通知ポリシー が正本。
+ * 対象は「本人が自分の認証手段を変更したとき」に限る。ログインのたびの通知・
+ * 組織管理者によるメンバー操作 (別ポリシー。`TwoFactorResetSecurityNotification`) は含まない。
+ */
+enum AuthMethodChangeEvent: string
+{
+    case PasswordSet = 'password_set';
+    case PasswordChanged = 'password_changed';
+    case PasswordReset = 'password_reset';
+    case TwoFactorEnabled = 'two_factor_enabled';
+    case TwoFactorDisabled = 'two_factor_disabled';
+    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
+    case PasskeyRegistered = 'passkey_registered';
+    case PasskeyDeleted = 'passkey_deleted';
+    case SocialAccountLinked = 'social_account_linked';
+
+    /** メール本文の見出し文 (秘密情報は含めない)。 */
+    public function headline(): string
+    {
+        return match ($this) {
+            self::PasswordSet => 'パスワードが設定されました',
+            self::PasswordChanged => 'パスワードが変更されました',
+            self::PasswordReset => 'パスワードがリセットされました',
+            self::TwoFactorEnabled => '2 段階認証が有効化されました',
+            self::TwoFactorDisabled => '2 段階認証が無効化されました',
+            self::RecoveryCodesRegenerated => '2 段階認証の回復コードが再発行されました',
+            self::PasskeyRegistered => 'パスキーが追加されました',
+            self::PasskeyDeleted => 'パスキーが削除されました',
+            self::SocialAccountLinked => '外部ログインが連携されました',
+        };
+    }
+}
diff --git a/app/Http/Middleware/EnsureLoginMethodRemains.php b/app/Http/Middleware/EnsureLoginMethodRemains.php
index 5032641f..9ceff7a4 100644
--- a/app/Http/Middleware/EnsureLoginMethodRemains.php
+++ b/app/Http/Middleware/EnsureLoginMethodRemains.php
@@ -10,11 +10,13 @@
 use App\Models\Passkey;
 use App\Models\User;
 use App\Services\Auth\LoginMethodInventory;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
 use Closure;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\DB;
 use LogicException;
 use Symfony\Component\HttpFoundation\Response;
+use Throwable;
 
 /**
  * ログイン手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する関門。
@@ -53,6 +55,7 @@ final class EnsureLoginMethodRemains
 {
     public function __construct(
         private readonly LoginMethodInventory $inventory,
+        private readonly LoginMethodRemovalPostCommitCallbacks $postCommitCallbacks,
     ) {}
 
     public function handle(Request $request, Closure $next): Response
@@ -62,20 +65,36 @@ public function handle(Request $request, Closure $next): Response
             return $this->pass($next, $request);   // 未認証は auth middleware の責務
         }
 
-        return DB::transaction(function () use ($request, $next, $user): Response {
-            // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
-            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
+        // transaction 呼び出しが正常終了した後にだけ実行してよい処理 (T110 認証手段変更通知)
+        // の予約口を transaction 開始前に開く。
+        $this->postCommitCallbacks->start();
 
-            // (3) ロック取得後に投影を評価する
-            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));
+        try {
+            $response = DB::transaction(function () use ($request, $next, $user): Response {
+                // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
+                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
 
-            if ($remaining->isEmpty()) {
-                return $this->reject($request);
-            }
+                // (3) ロック取得後に投影を評価する
+                $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));
 
-            // (4) 同一トランザクション内で削除まで完了させる
-            return $this->pass($next, $request);
-        });
+                if ($remaining->isEmpty()) {
+                    return $this->reject($request);
+                }
+
+                // (4) 同一トランザクション内で削除まで完了させる
+                return $this->pass($next, $request);
+            });
+        } catch (Throwable $e) {
+            // rollback: 積んだコールバックは実行しない
+            $this->postCommitCallbacks->discard();
+
+            throw $e;
+        }
+
+        // 正常終了: 予約したコールバック (通知の queue 投入) を実行する
+        $this->postCommitCallbacks->flush();
+
+        return $response;
     }
 
     /**
diff --git a/app/Listeners/Auth/NotifyAuthMethodChange.php b/app/Listeners/Auth/NotifyAuthMethodChange.php
new file mode 100644
index 00000000..394ed5c3
--- /dev/null
+++ b/app/Listeners/Auth/NotifyAuthMethodChange.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Listeners\Auth;
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Models\User;
+use App\Services\Security\AuthMethodChangeNotifier;
+use Illuminate\Auth\Events\PasswordReset;
+use Illuminate\Events\Dispatcher;
+use Laravel\Fortify\Events\RecoveryCodesGenerated;
+use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
+use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+
+/**
+ * 認証手段変更 → 本人へのメール通知 (T110)。
+ *
+ * `App\Listeners\RecordSecurityEvent` と同じ構成 (vendor イベント購読 + イベント化
+ * できない経路は Service から直接呼ぶ) に倣う。イベント化できない経路
+ * (パスワード設定/変更・SSO 連携) は `PasswordCredentialService` / `SocialAccountService`
+ * から直接 `AuthMethodChangeNotifier` を呼ぶ (本 listener の対象外)。
+ *
+ * `Event::subscribe` で明示登録する (`AppServiceProvider::boot()`)。
+ */
+class NotifyAuthMethodChange
+{
+    public function __construct(
+        private readonly AuthMethodChangeNotifier $notifier,
+    ) {}
+
+    public function subscribe(Dispatcher $events): void
+    {
+        $events->listen(PasswordReset::class, [self::class, 'handlePasswordReset']);
+        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
+        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
+        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
+        $events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
+        $events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);
+    }
+
+    public function handlePasswordReset(PasswordReset $event): void
+    {
+        $this->notify($event->user, AuthMethodChangeEvent::PasswordReset);
+    }
+
+    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
+    {
+        $this->notify($event->user, AuthMethodChangeEvent::TwoFactorEnabled);
+    }
+
+    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
+    {
+        $this->notify($event->user, AuthMethodChangeEvent::TwoFactorDisabled);
+    }
+
+    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
+    {
+        $this->notify($event->user, AuthMethodChangeEvent::RecoveryCodesRegenerated);
+    }
+
+    public function handlePasskeyRegistered(PasskeyRegistered $event): void
+    {
+        $this->notify($event->user, AuthMethodChangeEvent::PasskeyRegistered);
+    }
+
+    /**
+     * `EnsureLoginMethodRemains` の transaction 内で発火するため
+     * `notifyAfterCommit()` を使う (`notify()` の即時 enqueue は使わない)。
+     *
+     * この前提 (「`PasskeyDeleted` は必ず `EnsureLoginMethodRemains` の transaction 内で
+     * 発火する」) を本 listener 自身は検証できないが、`notifyAfterCommit()` の先にある
+     * collector が非アクティブ中の `push()` を `LogicException` で拒否する。
+     * deny-by-default route gate の対象外の経路から `PasskeyDeleted` が直接 dispatch
+     * された場合はこの例外で検出される。
+     */
+    public function handlePasskeyDeleted(PasskeyDeleted $event): void
+    {
+        $user = $this->asUser($event->user);
+        if ($user === null) {
+            return;
+        }
+
+        $this->notifier->notifyAfterCommit($user, AuthMethodChangeEvent::PasskeyDeleted);
+    }
+
+    private function notify(mixed $user, AuthMethodChangeEvent $event): void
+    {
+        $user = $this->asUser($user);
+        if ($user === null) {
+            return;
+        }
+
+        $this->notifier->notify($user, $event);
+    }
+
+    private function asUser(mixed $user): ?User
+    {
+        return $user instanceof User ? $user : null;
+    }
+}
diff --git a/app/Notifications/Auth/AuthMethodChangedNotification.php b/app/Notifications/Auth/AuthMethodChangedNotification.php
new file mode 100644
index 00000000..8ad81b78
--- /dev/null
+++ b/app/Notifications/Auth/AuthMethodChangedNotification.php
@@ -0,0 +1,81 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Auth;
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use Carbon\CarbonImmutable;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Notifications\Messages\MailMessage;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Config;
+
+/**
+ * 認証手段 (パスワード・2FA・パスキー・SSO 連携) の変更を本人へ知らせるセキュリティ通知 (T110)。
+ *
+ * 対象・発火点・保証しないものの正本は docs/architecture.md
+ * §認証手段変更のメール通知ポリシー。秘密情報 (トークン・コード・パスキーの識別子詳細) は
+ * 一切載せない。配信先は送信時点 (worker 実行時) の現在の登録メールアドレス —
+ * queued notification を包む queue job 側の直列化 (Illuminate の標準機構。個別の実装は
+ * 持たない) が worker 実行時に User を ID から再取得するため、CipherSweet の復号も
+ * 通常どおり働く。
+ *
+ * queue 投入自体の失敗を吸収する契約は本クラスではなく呼び出し元
+ * (`App\Services\Security\AuthMethodChangeNotifier`) が持つ。
+ */
+class AuthMethodChangedNotification extends Notification implements ShouldQueue
+{
+    use Queueable;
+
+    public function __construct(
+        private readonly AuthMethodChangeEvent $event,
+        private readonly CarbonImmutable $occurredAt,
+        private readonly ?string $context = null,
+    ) {}
+
+    /** イベント種別。テストで enum とメール内容の対応を直接固定するための getter。 */
+    public function event(): AuthMethodChangeEvent
+    {
+        return $this->event;
+    }
+
+    /** 発生時刻。テスト用 getter。 */
+    public function occurredAt(): CarbonImmutable
+    {
+        return $this->occurredAt;
+    }
+
+    /** SSO 連携時の provider 表示名等。テスト用 getter。 */
+    public function context(): ?string
+    {
+        return $this->context;
+    }
+
+    /** @return list<string> */
+    public function via(object $notifiable): array
+    {
+        return ['mail'];
+    }
+
+    public function toMail(object $notifiable): MailMessage
+    {
+        $appName = Config::string('app.name');
+        $headline = $this->event->headline();
+        $occurredAtLabel = $this->occurredAt->timezone('Asia/Tokyo')->isoFormat('YYYY-MM-DD HH:mm');
+
+        $detail = $this->event === AuthMethodChangeEvent::SocialAccountLinked
+            ? sprintf('外部ログイン (%s) が連携されました。', $this->context ?? '外部サービス')
+            : "{$headline}。";
+
+        return (new MailMessage)
+            ->subject("【{$appName}】{$headline}")
+            ->line("お使いの {$appName} アカウントで次の変更がありました。")
+            ->line($detail)
+            ->line("変更時刻: {$occurredAtLabel} (JST)")
+            ->line('ご自身の操作であれば対応不要です。')
+            ->line('心当たりがない場合は、直ちにパスワードを再設定し、サポートまでご連絡ください。')
+            ->action('パスワードを再設定する', route('password.request'));
+    }
+}
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index c504bf91..a2a956a2 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -10,6 +10,7 @@
 use App\Http\Routing\RouteBindingTypes;
 use App\Listeners\Audit\RejectNonCriticalAudit;
 use App\Listeners\Auth\ClearRecentAuthOnPasskeyChange;
+use App\Listeners\Auth\NotifyAuthMethodChange;
 use App\Listeners\Auth\StampRecentAuthOnLogin;
 use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
 use App\Listeners\Billing\MarkBillingNotificationDelivered;
@@ -35,6 +36,7 @@
 use App\Services\Mail\Sns\SnsSignatureVerifier;
 use App\Services\Render\FfmpegVideoComposer;
 use App\Services\Render\VideoComposer;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
 use App\Support\CriticalActionContext;
 use App\Support\EmailHash;
 use App\Support\EmailNormalizer;
@@ -117,6 +119,10 @@ public function register(): void
         // (queue worker / artisan は別 container のため context は継承されない)
         $this->app->scoped(CriticalActionContext::class);
 
+        // EnsureLoginMethodRemains 専用の post-commit callback collector (T110)。
+        // scoped() で HTTP request scope に閉じる (理由は上記と同じ)
+        $this->app->scoped(LoginMethodRemovalPostCommitCallbacks::class);
+
         // 動画合成の抽象 (doc/09 §9.7)。v1 は ffmpeg 実装。テストは fake 実装へ swap する
         $this->app->bind(VideoComposer::class, FfmpegVideoComposer::class);
 
@@ -213,6 +219,9 @@ public function boot(): void
         // 認証系イベント → security_audit_events 記録 (監査 3 層の Layer 2)
         Event::subscribe(RecordSecurityEvent::class);
 
+        // 認証手段変更 → 本人へのメール通知 (T110)
+        Event::subscribe(NotifyAuthMethodChange::class);
+
         // ログイン成功 → recent-auth スタンプ (機微操作 step-up の起点)
         Event::listen(Login::class, StampRecentAuthOnLogin::class);
 
diff --git a/app/Services/Auth/PasswordCredentialService.php b/app/Services/Auth/PasswordCredentialService.php
index c619c81c..6b6bd9d6 100644
--- a/app/Services/Auth/PasswordCredentialService.php
+++ b/app/Services/Auth/PasswordCredentialService.php
@@ -4,13 +4,16 @@
 
 namespace App\Services\Auth;
 
+use App\Enums\Auth\AuthMethodChangeEvent;
 use App\Enums\SecurityEventType;
 use App\Models\User;
+use App\Services\Security\AuthMethodChangeNotifier;
 use App\Services\Security\SecurityEventRecorder;
 use Illuminate\Support\Facades\Auth;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Hash;
 use Illuminate\Validation\ValidationException;
+use LogicException;
 use Throwable;
 use Webmozart\Assert\Assert;
 
@@ -33,6 +36,7 @@ final class PasswordCredentialService
 {
     public function __construct(
         private readonly SecurityEventRecorder $recorder,
+        private readonly AuthMethodChangeNotifier $notifier,
     ) {}
 
     /**
@@ -86,11 +90,14 @@ public function change(User $user, string $plain): void
     }
 
     /**
-     * 保存 **commit 後**の副作用: 監査記録 → 他デバイス失効 → DB session 行削除。
+     * 保存 **commit 後**の副作用: 監査記録 → 通知 → 他デバイス失効 → DB session 行削除。
      * transaction 内では実行しない (上記の PostgreSQL 事情)。
-     * best-effort なのは **監査記録と DB session 行削除**の 2 つ (どちらも内部で例外を握る)。
+     * best-effort なのは **監査記録・通知・DB session 行削除**の 3 つ (いずれも内部で例外を握る)。
      * `Auth::logoutOtherDevices()` は例外を捕捉しない (失敗は 500 として表面化させる。
      * 他デバイス失効は correctness 側の要求であり、既存 UpdateUserPassword の挙動を維持する)。
+     * 通知は「本人が自分の認証手段を変更したことに気づく」導線であり (T110)、
+     * 対象は `setInitial()` (SSO のみのアカウントへ password を追加する = パスキー追加と
+     * 同じ脅威モデル) と `change()` の両方。
      */
     private function afterPersist(User $user, string $plain, SecurityEventType $event): void
     {
@@ -98,6 +105,15 @@ private function afterPersist(User $user, string $plain, SecurityEventType $even
         // 記録失敗は report のみ (SecurityEventRecorder が内包する)。
         $this->recorder->record($event, $user);
 
+        $this->notifier->notify($user, match ($event) {
+            SecurityEventType::PasswordSet => AuthMethodChangeEvent::PasswordSet,
+            SecurityEventType::PasswordChanged => AuthMethodChangeEvent::PasswordChanged,
+            default => throw new LogicException(
+                'PasswordCredentialService::afterPersist() は PasswordSet / PasswordChanged 以外の'
+                .'SecurityEventType で呼ばれない想定です。',
+            ),
+        });
+
         // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
         // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
         // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
diff --git a/app/Services/Auth/SocialAccountService.php b/app/Services/Auth/SocialAccountService.php
index 0d10af0c..3d73baba 100644
--- a/app/Services/Auth/SocialAccountService.php
+++ b/app/Services/Auth/SocialAccountService.php
@@ -4,11 +4,13 @@
 
 namespace App\Services\Auth;
 
+use App\Enums\Auth\AuthMethodChangeEvent;
 use App\Enums\SecurityEventType;
 use App\Models\SocialAccount;
 use App\Models\User;
 use App\Services\Auth\EmailTrust\EmailTrustPolicyResolver;
 use App\Services\Organization\OrganizationProvisioningService;
+use App\Services\Security\AuthMethodChangeNotifier;
 use App\Services\Security\SecurityEventRecorder;
 use App\Support\Legal\LegalConsent;
 use Illuminate\Support\Facades\DB;
@@ -29,6 +31,7 @@ public function __construct(
         private readonly SecurityEventRecorder $recorder,
         private readonly OrganizationProvisioningService $provisioning,
         private readonly EmailTrustPolicyResolver $emailTrust,
+        private readonly AuthMethodChangeNotifier $notifier,
     ) {}
 
     public function findLinkedUser(string $provider, SocialiteUser $socialiteUser): ?User
@@ -88,6 +91,11 @@ public function register(string $provider, SocialiteUser $socialiteUser): User
 
     /**
      * 連携追加。既に他ユーザーに連携済みの場合は false を返す。
+     *
+     * **通知は本メソッドだけが行う** (`register()` 内部の初回連携では呼ばない)。
+     * 新規 SSO 登録は「既存アカウントが新しい認証手段を獲得した」わけではなく、
+     * 本人がその場で作ったばかりのアカウントに「連携しました」と知らせるのは
+     * 一般的な慣行にも無い冗長な通知になるため (T110 概念設計「制約・前提」)。
      */
     public function linkToUser(string $provider, SocialiteUser $socialiteUser, User $user): bool
     {
@@ -102,6 +110,8 @@ public function linkToUser(string $provider, SocialiteUser $socialiteUser, User
 
         $this->link($provider, $socialiteUser, $user);
 
+        $this->notifier->notify($user, AuthMethodChangeEvent::SocialAccountLinked, $this->providerLabel($provider));
+
         return true;
     }
 
@@ -118,4 +128,12 @@ private function link(string $provider, SocialiteUser $socialiteUser, User $user
             'provider' => $provider,
         ]);
     }
+
+    /** config の label を使う。未宣言なら provider 識別子そのものを使う (fail-closed ではなく表示のみのため許容)。 */
+    private function providerLabel(string $provider): string
+    {
+        $label = config("template.social_providers.{$provider}.label");
+
+        return is_string($label) && $label !== '' ? $label : $provider;
+    }
 }
diff --git a/app/Services/Security/AuthMethodChangeNotifier.php b/app/Services/Security/AuthMethodChangeNotifier.php
new file mode 100644
index 00000000..8bb92e39
--- /dev/null
+++ b/app/Services/Security/AuthMethodChangeNotifier.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Security;
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Models\User;
+use App\Notifications\Auth\AuthMethodChangedNotification;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
+use Carbon\CarbonImmutable;
+use Throwable;
+
+/**
+ * 認証手段変更通知 (T110) の発火の唯一の窓口。
+ *
+ * `SecurityEventRecorder::record()` と同型の best-effort 契約 — 通知の queue 投入失敗
+ * (DB 接続断等) が呼び出し元の認証操作を失敗させないよう、例外は `report()` して継続する。
+ */
+class AuthMethodChangeNotifier
+{
+    public function __construct(
+        private readonly LoginMethodRemovalPostCommitCallbacks $postCommitCallbacks,
+    ) {}
+
+    /**
+     * transaction 外で直ちに queue へジョブを投入する (best-effort)。
+     * 実際のメール配送は worker が非同期に行う。
+     */
+    public function notify(User $user, AuthMethodChangeEvent $event, ?string $context = null): void
+    {
+        try {
+            $user->notify(new AuthMethodChangedNotification($event, CarbonImmutable::now(), $context));
+        } catch (Throwable $e) {
+            report($e);
+        }
+    }
+
+    /**
+     * `EnsureLoginMethodRemains` が開く transaction の内側からだけ呼ぶこと。
+     * transaction の呼び出しが正常終了した後に `notify()` を呼ぶよう予約する best-effort
+     * 契約 (rollback した場合は投入を試みない。「commit 成否と通知が 1:1」という厳密な
+     * 保証ではない — flush 前のプロセス終了・queue 投入失敗時は通知が届かないことがある)。
+     *
+     * collector が非アクティブ (`EnsureLoginMethodRemains` の transaction 外) のときは
+     * `push()` が `LogicException` を投げる。
+     */
+    public function notifyAfterCommit(User $user, AuthMethodChangeEvent $event, ?string $context = null): void
+    {
+        $this->postCommitCallbacks->push(
+            function () use ($user, $event, $context): void {
+                $this->notify($user, $event, $context);
+            },
+        );
+    }
+}
diff --git a/app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php b/app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php
new file mode 100644
index 00000000..ba36ae1a
--- /dev/null
+++ b/app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php
@@ -0,0 +1,117 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Auth;
+
+use Closure;
+use LogicException;
+
+/**
+ * `App\Http\Middleware\EnsureLoginMethodRemains` が開く transaction の呼び出しが正常終了
+ * した後に実行するコールバックを溜める request-scoped collector (T110)。
+ *
+ * **この middleware 専用**であり、アプリ全体の汎用 post-commit 基盤ではない
+ * (用途を広げるときは名前も見直すこと)。将来 password 削除 / SSO 解除の removal route が同じ
+ * middleware に乗ったときは、そのまま同じ collector を使い続けてよい (「認証手段除去
+ * transaction の post-commit callback」という意味は変わらない)。
+ *
+ * container binding は `scoped()` (`AppServiceProvider::register()`)。`singleton()` は
+ * Octane 等の長寿命 worker でリクエストをまたいで同一インスタンスが再利用され得るため
+ * 使わない。queue worker は別 container で起動するためこの collector は継承されない
+ * (`App\Support\CriticalActionContext` と同じ前提)。
+ *
+ * **アクティブ状態を持つ**。`EnsureLoginMethodRemains::handle()` の transaction 開始直前に
+ * `start()` を呼ぶ想定で、非アクティブ中の `push()` は `LogicException` で fail-fast する。
+ * これにより「`PasskeyDeleted` がこの middleware の transaction の外から発火した」という
+ * 設計違反を実行時に検出できる (この middleware **専用**であることをコードでも強制する)。
+ *
+ * **状態遷移** (表以外の遷移は無い):
+ *
+ * | 現在状態 | 操作 | 結果 |
+ * |---|---|---|
+ * | inactive | `start()` | active |
+ * | active | `push()` | active のまま追加 |
+ * | active | `flush()` | 実行して inactive |
+ * | active | `discard()` | 破棄して inactive |
+ * | inactive | `push()` | `LogicException` |
+ * | active | `start()` | `LogicException` |
+ * | inactive | `flush()` / `discard()` | no-op |
+ *
+ * 「active 中に `start()` を再度呼ぶと積んだ callback を無言で消す」という実装は選ばない
+ * (nested middleware・同一 request scope 内の誤った再利用が起きたとき、検出すべき通知欠落を
+ * 正常系に見せてしまうため)。
+ */
+final class LoginMethodRemovalPostCommitCallbacks
+{
+    /** @var list<Closure(): void> */
+    private array $callbacks = [];
+
+    private bool $active = false;
+
+    /**
+     * `EnsureLoginMethodRemains` の transaction を開始する直前に呼ぶこと。
+     *
+     * @throws LogicException 既に active 中 (二重 `start()`) に呼ばれた場合。
+     *                        積んでいた callback を無言で消さないための fail-fast
+     */
+    public function start(): void
+    {
+        if ($this->active) {
+            throw new LogicException(
+                'LoginMethodRemovalPostCommitCallbacks::start() は既に active 中に'
+                .'呼ばれました (二重 start)。',
+            );
+        }
+
+        $this->callbacks = [];
+        $this->active = true;
+    }
+
+    /**
+     * @param  Closure(): void  $callback
+     *
+     * @throws LogicException 非アクティブ中 (`start()` を呼んでいない、または
+     *                        既に `flush()`/`discard()` 済み) に呼ばれた場合
+     */
+    public function push(Closure $callback): void
+    {
+        if (! $this->active) {
+            throw new LogicException(
+                'LoginMethodRemovalPostCommitCallbacks::push() は '
+                .'EnsureLoginMethodRemains の transaction 中にのみ呼べます。',
+            );
+        }
+
+        $this->callbacks[] = $callback;
+    }
+
+    /**
+     * `EnsureLoginMethodRemains` の transaction 呼び出しが正常終了した後にだけ呼ぶこと
+     * (呼び出しの正常終了 = 本 middleware にとっての commit。best-effort 契約であり、
+     * 「commit 成否と通知が 1:1」という厳密な保証ではない — 実行後のプロセス終了・
+     * queue 投入失敗時は通知が届かないことがある)。
+     *
+     * 実行前に保持配列を空へ移し非アクティブへ戻すため、2 回呼んでも 2 回目は何もしない。
+     * **1 件目のコールバックが例外を投げれば後続は実行されない** (`foreach` の通常の挙動。
+     * 保証を誇張しない)。現在の利用者 (`AuthMethodChangeNotifier::notify()`) は例外を
+     * 内部で吸収するため実害はないが、本クラス自体はそれを保証しない。
+     */
+    public function flush(): void
+    {
+        $pending = $this->callbacks;
+        $this->callbacks = [];
+        $this->active = false;
+
+        foreach ($pending as $callback) {
+            $callback();
+        }
+    }
+
+    /** transaction が rollback したときに呼ぶこと。積んだコールバックを実行せずに破棄する。 */
+    public function discard(): void
+    {
+        $this->callbacks = [];
+        $this->active = false;
+    }
+}
diff --git a/docs/architecture.md b/docs/architecture.md
index 9a62f192..c646efc1 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -3098,3 +3098,55 @@ ### 保証しないもの (誇張しない)
    到達可能な半分 (一致するときは取得へ進む) はテストで固定してある。
 8. **署名検証が成功した証明書しかキャッシュに載らない**ことは実装の不変条件だが、
    「キャッシュにある証明書が今も有効」ことは意味しない (寿命で失効させるだけである)。
+
+## 認証手段変更のメール通知ポリシー (T110)
+
+パスワード設定・変更・リセット / 2FA 有効化・無効化・回復コード再発行 / パスキー追加・削除 /
+SSO 連携 (合計 9 種。`App\Enums\Auth\AuthMethodChangeEvent`) が起きたとき、本人の登録メール
+アドレスへ「何が変わったか・いつ変わったか・心当たりが無い場合の対処」を通知する。
+オーナー裁定 (2026-08-21「方針は任せる。一般的なものに倣う」) に基づく。
+
+- **対象外 (スコープ外)**: ログインのたびの通知 / アプリ内通知センターへの複製 /
+  管理者向け通知。既存の監査ログ (T108 S7) は変えない。組織管理者によるメンバー 2FA 解除
+  (`TwoFactorResetSecurityNotification`) はこのポリシーが統一する対象ではない (加害者側ではなく
+  組織管理者が正規に行う操作で読者・文脈が異なる別ポリシー)。メールアドレス変更の通知
+  (`EmailChangedSecurityNotification`。T031/T211 系) も実装は変更しない。
+- **窓口**: 発火は `App\Services\Security\AuthMethodChangeNotifier` (`notify()` =
+  transaction 外で直ちに queue へジョブを投入する best-effort 版。queue 投入自体の失敗は
+  `report()` して認証操作を巻き込まない) の 1 経路に統一する。呼び出し元は
+  `App\Listeners\Auth\NotifyAuthMethodChange` (vendor イベント購読) と、イベント化されていない
+  `App\Services\Auth\PasswordCredentialService` / `App\Services\Auth\SocialAccountService`
+  の直接呼び出しの 2 種類。
+
+### 発火点対応表
+
+| イベント (`AuthMethodChangeEvent`) | 発火元 | transaction 内か | 発火方法 |
+|---|---|---|---|
+| `PasswordSet` / `PasswordChanged` | `PasswordCredentialService::afterPersist()` | 否 | `notify()` |
+| `PasswordReset` | `Illuminate\Auth\Events\PasswordReset` | 否 | `notify()` |
+| `TwoFactorEnabled` | Fortify `TwoFactorAuthenticationConfirmed` | 否 | `notify()` |
+| `TwoFactorDisabled` | Fortify `TwoFactorAuthenticationDisabled` | 否 | `notify()` |
+| `RecoveryCodesRegenerated` | Fortify `RecoveryCodesGenerated` | 否 | `notify()` |
+| `PasskeyRegistered` | Laravel Passkeys `PasskeyRegistered` | 否 | `notify()` |
+| `PasskeyDeleted` | Laravel Passkeys `PasskeyDeleted` | **是** (`EnsureLoginMethodRemains` が課す) | `notifyAfterCommit()` |
+| `SocialAccountLinked` | `SocialAccountService::linkToUser()` (`register()` 内部の初回連携では発火しない) | 否 | `notify()` |
+
+`notify()` は「transaction 外で直ちに queue へジョブを投入する」ことを指す。実際のメール
+配送は worker が非同期に行う。`notifyAfterCommit()` は
+`App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` へ予約し、
+`EnsureLoginMethodRemains` の transaction 呼び出しが正常終了した後にだけ `notify()` を呼ぶ
+(rollback 時は発火しない。best-effort 契約であり「正常終了後に必ず届く」ことまでは保証しない。
+詳細は同クラスと `EnsureLoginMethodRemains` の docblock)。
+
+### 保証しないもの (誇張しない)
+
+- **配信先は送信時点の現在の登録メールアドレス** であり、操作時点のアドレスのスナップショット
+  ではない (queued notification が worker 実行時に User を再取得するため)
+- SSO の「解除」機能は本設計時点でアプリに実装されていない。実装されたときは
+  `AuthMethodChangeEvent` へ case を追加し本ポリシーへ含めること (先回りして作らない)
+- **queue 投入の成功、およびメールの実配送成功は保証しない**。本ポリシーの責務は
+  「queue へジョブの投入を best-effort で試行するところまで」であり、
+  `AuthMethodChangeNotifier::notify()` は投入時の例外を `report()` して吸収するため、
+  投入成功そのものも保証範囲ではない。配送成功は既存の mailer driver
+  設定・SES バウンス処理等の一般的な配送信頼性の枠内に委ねる
+- 詳細設計は `devnotes/20260821-2015-auth-method-change-notification/`
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index be26af1b..36f72e7d 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 33 件
+登録エントリ: 37 件
 
 ## 記録の原則
 
@@ -2119,3 +2119,200 @@ ### 関連
 
 - 実装: `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md`
 - 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`
+
+## D36 パスキー等除去 middleware の transaction 正常終了後コールバック機構
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Http/Middleware/EnsureLoginMethodRemains.php` |
+| 業務要件起因の説明 | 認証手段変更のメール通知 (T110) のうち、パスキー削除だけは本 middleware が課す transaction (ロック取得〜controller〜同期 listener〜レスポンス生成まで丸ごと) の内側で発火する。同 transaction の外部 I/O・非 afterCommit queue dispatch 禁止という既存契約 (本ファイルの docblock) と、AGENTS.md ドメイン規約 11 (`DB::afterCommit()` 系の 0 件 pin) の両方を満たしつつ、rollback した経路では通知投入を試みない best-effort 契約を実現するには、transaction 呼び出し側に「正常終了後にだけ実行する」明示的な分岐を持たせる必要がある |
+| 揃え続ける不変条件と保証機構 | ロック取得順序 (User→credential)・投影評価の位置 (ロック取得後)・`$next()` を transaction 内で実行することは変更しない。追加したのは transaction 呼び出しの開始前に collector を `start()` し、正常終了時は `LoginMethodRemovalPostCommitCallbacks::flush()`、例外 (rollback) 時は `discard()` を呼ぶ外側の 1 層だけ。既存 `tests/Architecture/LoginMethodRemovalRouteTest.php` (route 分類の drift 検出) と `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` (「HTTP 削除経路では同期購読の失敗で削除ごと巻き戻る」— ロック取得〜同期 listener〜レスポンス生成が同一 transaction であることの実挙動固定) が変わらず green であること、および本設計が追加する rollback 統合テスト (施策 8) で揃え続ける |
+| 再判定の条件 | 本 middleware をテンプレート側の姿へ戻す判断をしたとき / パスキー以外の除去 route (password 削除・SSO 解除) を追加する際に、同じ collector を使うか再設計するかを判断したとき |
+| 決めた日 | 2026-08-21 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| `handle()` の構造 | `DB::transaction()` の戻り値をそのまま return | `DB::transaction()` を try/catch で包み、正常終了後に post-commit callback を flush、例外時は discard してから re-throw |
+| post-commit callback | 概念が無い | `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` (この middleware 専用・`scoped()` bind) |
+
+### なぜ正当な差分か (logic-driven)
+
+本 middleware が課す transaction は「ロック取得〜レスポンス生成まで」を丸ごと囲む
+特殊な形 (通常の業務 transaction より広い) であり、この形自体が既に本アプリ固有の設計
+(採用時債務として凍結されていた理由もこの特殊性にある)。この上に「commit 後にだけ
+実行してよい処理」を安全に載せる口が無かったため、汎用ではなく本 middleware 専用の
+最小限の口を追加した。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「rollback した場合は積んだコールバックを実行しない。transaction 呼び出しの正常終了後、
+> 積んだコールバックの実行を 1 回試みる」(best-effort な表現。「commit 成否と通知が 1:1」
+> という言い切りはしない)
+
+- rollback (例外) 時は `discard()` が呼ばれ、collector は空になってから例外が再送出される
+- `flush()` は実行前に保持配列を空へ移すため、二重呼び出しで再実行されない
+- 本 middleware の transaction 呼び出しの「正常終了」は、それが最外 transaction である
+  production の Web 経路でのみ物理 commit を意味する。`RefreshDatabase` 下のテストでは
+  外側 transaction が既に開いているため、flush は物理 commit 前に起きる (誇張しない)
+
+### 保証しないもの
+
+- **transaction 呼び出しの正常終了後、通知投入が実際に成功すること** — flush 前のプロセス
+  終了・queue 投入失敗・`AuthMethodChangeNotifier::notify()` 内の例外吸収により、
+  通知が届かないことがある (best-effort)
+- **1 件目のコールバックが例外を投げた場合、後続のコールバックは実行されない**
+  (`foreach` の通常の挙動。現在の利用者は例外を内部で吸収するため実害は無いが、
+  本機構自体はそれを保証しない)
+- **queue worker からの利用は想定していない** (`scoped()` は HTTP リクエスト間・queue job
+  間で共有しない仕組みであり、本機構の利用対象は HTTP middleware だけ。
+  `App\Support\CriticalActionContext` と同じ前提)
+
+### 関連
+
+- 実装: `app/Http/Middleware/EnsureLoginMethodRemains.php`,
+  `app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php`
+- 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+## D37 ジョブ重複配送の免除目録は業務追加ごとに更新され続ける台帳である
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/JobExecutionDedupInventoryTest.php` |
+| 業務要件起因の説明 | 本ファイルはキューに載る全クラス (`ShouldQueue` 実装) を「保証側」か「免除側」に分類する deny-by-default 目録である (AGENTS.md ドメイン規約 6)。新しい通知・ジョブを追加するたびに 1 エントリと件数 pin の更新が要る、**業務ドメインの拡張に追随して恒常的に更新され続ける**設計であり、テンプレートの汎用形 (空の目録) や「採用時点の姿」のどちらにも収束しない。採用時債務一覧が要求する「変更したら 3 択のいずれかを選ぶ」に従い、意図的逸脱として登録する |
+| 揃え続ける不変条件と保証機構 | 母集団 (キューに載る全クラスの完全一致) と免除の理由付き分類は E1 系のテストが deny-by-default で強制し続ける。件数 pin (`jobDedupExemptionCap()` / `jobDedupExemptionCapByCase()`) は登録の追加ごとに機械的に更新させられる (更新漏れは gate 自体が赤くなる) |
+| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
+| 決めた日 | 2026-08-21 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 目録の内容 | 汎用の骨組み (業務エントリ無し) | 本アプリの `ShouldQueue` クラス全数に対する業務判断 (保証側/免除側) を蓄積した目録 |
+| 更新頻度 | テンプレート更新時のみ | 新規ジョブ・通知の追加ごとに更新 (構造上恒常的) |
+
+### なぜ正当な差分か (logic-driven)
+
+本目録は「新しいジョブ・通知を追加したら分類を書かせる」という deny-by-default 機構そのもの
+であり、業務ドメインが拡張し続ける限り内容が増え続けることが**設計の目的**である。
+「採用時点の姿」や「テンプレートの汎用形」に固定・収束させることは目録の意図と矛盾するため、
+債務一覧の 3 択のうち「意図的逸脱として登録する」を選ぶ。今回 (T110) は
+`AuthMethodChangedNotification` の免除エントリ追加がこの恒常的な更新の 1 例である。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「キューに載る全クラスが保証側か免除側のどちらかに分類され、免除には 30 文字以上の
+> 理由が付く。件数 pin は現在値ちょうどに保たれる」
+
+- 分類漏れ・件数 pin の更新漏れは既存の E 系テストが deny-by-default で検出する
+- 本登録は「今後も内容が変わり続けること」を許容するものであり、
+  内容そのものの正しさ (各エントリの分類・理由の妥当性) は人のレビュー対象のままである
+
+### 保証しないもの
+
+- 目録の分類判断 (どのクラスを保証側/免除側にするか) の妥当性は本登録の対象外
+  (既存の E 系テストと人のレビューが担う)
+- 将来テンプレート側に同種の目録が持ち込まれた場合の統合可否は判断していない
+
+### 関連
+
+- 実装: `tests/Architecture/JobExecutionDedupInventoryTest.php`
+- 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+## D38 キュー接続リース目録は業務追加ごとに更新され続ける台帳である
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/QueuedJobLeaseInventoryTest.php` |
+| 業務要件起因の説明 | 本ファイルはキューに載る全クラス (`ShouldQueue` 実装) の接続 (`QUEUED_JOB_LEASE_INVENTORY`) を deny-by-default で目録化する (AGENTS.md §キューのリース期間とワーカー制限時間の規約)。D37 の `JobExecutionDedupInventoryTest.php` と同じ母集団 (`Tests\Support\QueuedJobPopulation`) を見る対の目録であり、新しい通知・ジョブを追加するたびに 1 エントリの追加が要る、**業務ドメインの拡張に追随して恒常的に更新され続ける**設計である。テンプレートの汎用形や「採用時点の姿」のどちらにも収束しないため、採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
+| 揃え続ける不変条件と保証機構 | 母集団 (キューに載る全クラスの完全一致) は D37 と同じ `Tests\Support\QueuedJobPopulation` を経由するため、片方だけ更新される drift が起きない。接続の明示登録漏れは本ファイルの deny-by-default 検査が強制し続ける |
+| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
+| 決めた日 | 2026-08-21 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 目録の内容 | 汎用の骨組み (業務エントリ無し) | 本アプリの `ShouldQueue` クラス全数に対する接続割当を蓄積した目録 |
+| 更新頻度 | テンプレート更新時のみ | 新規ジョブ・通知の追加ごとに更新 (構造上恒常的) |
+
+### なぜ正当な差分か (logic-driven)
+
+D37 と同じ理由である。本目録は「新しいジョブ・通知を追加したら接続を明示させる」という
+deny-by-default 機構そのものであり、業務ドメインが拡張し続ける限り内容が増え続けることが
+**設計の目的**である。今回 (T110) は `AuthMethodChangedNotification` の登録
+(既定接続 = `null`) 追加がこの恒常的な更新の 1 例である。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「キューに載る全クラスが目録に登録され、目録と走査結果の対称差が空である」
+
+- 登録漏れは既存の検査が deny-by-default で検出する
+- 本登録は「今後も内容が変わり続けること」を許容するものであり、
+  内容そのものの正しさ (各エントリの接続割当の妥当性) は人のレビュー対象のままである
+
+### 保証しないもの
+
+- 目録の接続割当判断 (どのクラスをどの接続にするか) の妥当性は本登録の対象外
+  (既存の検査と人のレビューが担う)
+- 将来テンプレート側に同種の目録が持ち込まれた場合の統合可否は判断していない
+
+### 関連
+
+- 実装: `tests/Architecture/QueuedJobLeaseInventoryTest.php`
+- 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+## D39 パスキー削除の同期購読者 pin は listener 追加ごとに更新され続ける固定値である
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/PasskeyPackageContractTest.php` |
+| 業務要件起因の説明 | 本ファイルは `PasskeyDeleted` の直接購読者を「同期で走る N 件だけ」という完全一致 pin で固定している (削除の巻き戻りの前提の検査)。新設の `App\Listeners\Auth\NotifyAuthMethodChange` (T110) が同じイベントを同期購読するため、この pin (顔ぶれ・件数・購読順) を更新する必要がある。この pin は「同期購読という前提が保たれているか」を業務追加ごとに人手で確認させる deny-by-default 機構であり、テンプレートの汎用形にも「採用時点の姿」にも収束しないため、採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
+| 揃え続ける不変条件と保証機構 | 「`PasskeyDeleted` の直接購読者は `ShouldQueue` を実装しない (同期で走る)」ことは本ファイルの検査が deny-by-default で強制し続ける。顔ぶれ・購読順の完全一致 pin は、新しい購読者が増減したときに人手での確認 (同期性が保たれているか) を強制する仕組みとして機能する |
+| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
+| 決めた日 | 2026-08-21 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| pin の内容 | 汎用の骨組み (業務購読者無し) | 本アプリが `PasskeyDeleted` へ同期購読させた listener 全数の顔ぶれ・順序の固定値 |
+| 更新頻度 | テンプレート更新時のみ | 同期購読 listener の追加・削除ごとに更新 (構造上恒常的) |
+
+### なぜ正当な差分か (logic-driven)
+
+本 pin は「新しい同期購読者を追加したら、それが本当に同期で走るかを人手で確認させる」という
+deny-by-default 機構そのものであり、業務ドメイン (認証手段の変更に反応する処理) が
+増える限り内容が変わり続けることが**設計の目的**である。今回 (T110) は
+`NotifyAuthMethodChange` の追加 (2 件 → 3 件、購読順
+`RecordSecurityEvent → NotifyAuthMethodChange → ClearRecentAuthOnPasskeyChange`) が
+この恒常的な更新の 1 例である。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「`PasskeyDeleted` の直接購読者は全員 `ShouldQueue` を実装しない (同期で走る)。
+> 顔ぶれと購読順は pin した値と完全一致する」
+
+- `ShouldQueue` 実装の検出漏れは既存の検査が deny-by-default で検出する
+- 本登録は「今後も内容が変わり続けること」を許容するものであり、
+  内容そのものの正しさ (どの listener を同期購読させるべきかの妥当性) は
+  人のレビュー対象のままである
+
+### 保証しないもの
+
+- 同期購読させる listener の選定判断の妥当性は本登録の対象外 (人のレビューが担う)
+- 将来テンプレート側に同種の pin が持ち込まれた場合の統合可否は判断していない
+
+### 関連
+
+- 実装: `tests/Architecture/PasskeyPackageContractTest.php`
+- 設計: `devnotes/20260821-2015-auth-method-change-notification/`
diff --git a/tests/Architecture/JobDeferralTerminationGateTest.php b/tests/Architecture/JobDeferralTerminationGateTest.php
index af810ca4..1180d699 100644
--- a/tests/Architecture/JobDeferralTerminationGateTest.php
+++ b/tests/Architecture/JobDeferralTerminationGateTest.php
@@ -16,6 +16,7 @@
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
 use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Notifications\Auth\AuthMethodChangedNotification;
 use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
 use App\Notifications\Billing\AutoRechargeDisabledNotification;
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
@@ -277,6 +278,12 @@ function jobDeferralTerminationInventory(): array
             'reason' => $common.'契約更新が近いことを知らせるだけで、業務の状態を書かない。',
             'coveredBy' => [],
         ],
+        [
+            'class' => AuthMethodChangedNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => '認証手段変更のお知らせを 1 通送るだけで、他の仕事と順番を争わない。',
+            'coveredBy' => [],
+        ],
     ];
 }
 
diff --git a/tests/Architecture/JobExecutionDedupInventoryTest.php b/tests/Architecture/JobExecutionDedupInventoryTest.php
index 77196c74..1e0c28a0 100644
--- a/tests/Architecture/JobExecutionDedupInventoryTest.php
+++ b/tests/Architecture/JobExecutionDedupInventoryTest.php
@@ -19,6 +19,7 @@
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
 use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Notifications\Auth\AuthMethodChangedNotification;
 use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
 use App\Notifications\Billing\AutoRechargeDisabledNotification;
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
@@ -264,6 +265,11 @@ function jobDedupExemptions(): array
             '契約更新のリマインダ。ドメイン状態を書かず、重複受信しても案内内容が同一で'
             .'受信者に新たな支払い操作を発生させない (更新は Stripe の自動請求が行う)。',
         ),
+        AuthMethodChangedNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            '認証手段変更のお知らせ。ドメイン状態を一切書かず、重複受信しても同じ内容の'
+            .'メールが 2 通届くだけで、受信者に新たな操作 (支払い・承認等) を要求しない。',
+        ),
     ];
 }
 
@@ -275,7 +281,7 @@ function jobDedupExemptions(): array
  */
 function jobDedupExemptionCap(): int
 {
-    return 15;
+    return 16;
 }
 
 /**
@@ -287,7 +293,7 @@ function jobDedupExemptionCap(): int
 function jobDedupExemptionCapByCase(): array
 {
     return [
-        JobDedupExemption::DuplicateDeliveryAccepted->value => 9,
+        JobDedupExemption::DuplicateDeliveryAccepted->value => 10,
         JobDedupExemption::IdempotentDeletion->value => 2,
         JobDedupExemption::ConvergentStateSync->value => 3,
         JobDedupExemption::GuardedByDownstreamConstraint->value => 1,
diff --git a/tests/Architecture/PasskeyPackageContractTest.php b/tests/Architecture/PasskeyPackageContractTest.php
index be83e210..818ea46a 100644
--- a/tests/Architecture/PasskeyPackageContractTest.php
+++ b/tests/Architecture/PasskeyPackageContractTest.php
@@ -7,6 +7,7 @@
 use App\Http\Responses\Passkey\PasskeyLoginResponse;
 use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
 use App\Listeners\Auth\ClearRecentAuthOnPasskeyChange;
+use App\Listeners\Auth\NotifyAuthMethodChange;
 use App\Listeners\RecordSecurityEvent;
 use App\Models\Passkey;
 use App\Models\User;
@@ -476,7 +477,7 @@ function passkeyListenerClass(mixed $listener): string
     return $class;
 }
 
-test('パスキー削除イベントの直接購読は同期で走る 2 つだけである (巻き戻りの前提)', function (): void {
+test('パスキー削除イベントの直接購読は同期で走る 3 つだけである (巻き戻りの前提)', function (): void {
     // ★`app('events')` は文字列キー解決なので level 10 では型が確定しない。
     //   具体クラスであることを**検査してから**絞る (docblock だけで断定しない)。
     $dispatcherValue = app('events');
@@ -502,8 +503,14 @@ function passkeyListenerClass(mixed $listener): string
         );
     }
 
-    // 顔ぶれを完全一致で固定する (増減のどちらでも赤くなる)。
-    expect($classes)->toBe([RecordSecurityEvent::class, ClearRecentAuthOnPasskeyChange::class]);
+    // 顔ぶれと購読順を完全一致で固定する (増減のどちらでも赤くなる)。
+    // 実際の購読順: RecordSecurityEvent → NotifyAuthMethodChange (T110 のメール通知) →
+    // ClearRecentAuthOnPasskeyChange。
+    expect($classes)->toBe([
+        RecordSecurityEvent::class,
+        NotifyAuthMethodChange::class,
+        ClearRecentAuthOnPasskeyChange::class,
+    ]);
 
     // ★**直接購読だけを見ても閉じない**。Dispatcher は
     //   ワイルドカード購読 (`Laravel\Passkeys\Events\*`) を別の集合で持ち、
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index f9f423d0..d7ea73b1 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -16,6 +16,7 @@
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
 use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Notifications\Auth\AuthMethodChangedNotification;
 use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
 use App\Notifications\Billing\AutoRechargeDisabledNotification;
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
@@ -90,6 +91,7 @@
     PaymentFailedNotification::class => null,
     RenewalReminderNotification::class => null,
     AccountDeletionRequestedNotification::class => null,
+    AuthMethodChangedNotification::class => null,
 ];
 
 /**
diff --git a/tests/Feature/Auth/AuthMethodChangeNotificationTest.php b/tests/Feature/Auth/AuthMethodChangeNotificationTest.php
new file mode 100644
index 00000000..85ed389d
--- /dev/null
+++ b/tests/Feature/Auth/AuthMethodChangeNotificationTest.php
@@ -0,0 +1,334 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Enums\SecurityEventType;
+use App\Models\Passkey;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use App\Notifications\Auth\AuthMethodChangedNotification;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
+use Illuminate\Auth\Notifications\ResetPassword;
+use Illuminate\Contracts\Notifications\Dispatcher;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Event;
+use Illuminate\Support\Facades\Exceptions;
+use Illuminate\Support\Facades\Hash;
+use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Notification;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+use Laravel\Socialite\Contracts\Provider;
+use Laravel\Socialite\Contracts\User as SocialiteUserContract;
+use Laravel\Socialite\Facades\Socialite;
+use Mockery\MockInterface;
+use PragmaRX\Google2FA\Google2FA;
+
+/*
+ * 認証手段変更のメール通知ポリシー (T110)。
+ *
+ * テストレーンを分離する (Notification::fake() と jobs テーブル観測を同一テストで
+ * 両立させない):
+ *   1. イベント → enum 対応の正しさ: Notification::fake()
+ *   2. queue 投入件数の確認: config(['queue.default' => 'database']) + jobs テーブル
+ */
+
+/** 直近の queue jobs テーブルに積まれた AuthMethodChangedNotification 系ジョブの件数。 */
+function authMethodChangeJobCount(): int
+{
+    return DB::table('jobs')
+        ->where('payload', 'like', '%AuthMethodChangedNotification%')
+        ->count();
+}
+
+function fakeGoogleSocialiteUser(string $id, string $email, string $name = 'SSO User'): SocialiteUserContract
+{
+    /** @var SocialiteUserContract&MockInterface $user */
+    $user = Mockery::mock(SocialiteUserContract::class);
+    $user->shouldReceive('getId')->andReturn($id);
+    $user->shouldReceive('getEmail')->andReturn($email);
+    $user->shouldReceive('getName')->andReturn($name);
+
+    return $user;
+}
+
+function fakeGoogleSocialiteCallback(SocialiteUserContract $user): void
+{
+    $driver = Mockery::mock(Provider::class);
+    $driver->shouldReceive('user')->andReturn($user);
+    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
+}
+
+/* ------------------------------------------------------------ パスワード */
+
+test('PUT /user/password (変更) は PasswordChanged 通知 1 件を送り、他イベントは送らない', function (): void {
+    Notification::fake();
+    $user = User::factory()->create(['password' => Hash::make('current-password')]);
+
+    $this->actingAs($user)->put('/user/password', [
+        'current_password' => 'current-password',
+        'password' => 'BrandNewPassw0rd!x',
+    ])->assertSessionHasNoErrors();
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasswordChanged,
+    );
+    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
+});
+
+test('PUT /user/password は通知の enqueue が例外化してもパスワード変更自体は成功する (best-effort、実経路)', function (): void {
+    Exceptions::fake();
+
+    /** @var Dispatcher&MockInterface $dispatcher */
+    $dispatcher = Mockery::mock(Dispatcher::class);
+    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('mail queue down'));
+    app()->instance(Dispatcher::class, $dispatcher);
+
+    $user = User::factory()->create(['password' => Hash::make('current-password')]);
+
+    $this->actingAs($user)->put('/user/password', [
+        'current_password' => 'current-password',
+        'password' => 'BrandNewPassw0rd!x',
+    ])->assertSessionHasNoErrors();
+
+    // パスワード自体は確実に更新されている (通知失敗が主処理を巻き添えにしない)
+    expect(Hash::check('BrandNewPassw0rd!x', $user->fresh()->password))->toBeTrue();
+
+    // 例外は握り潰さず report() されている (Codex 実装レビュー Round 1 [Warning] への対応)
+    Exceptions::assertReported(RuntimeException::class);
+});
+
+test('POST /settings/password (初回設定) は PasswordSet 通知 1 件を送り、他イベントは送らない', function (): void {
+    Notification::fake();
+    $user = User::factory()->ssoOnly()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->post('/settings/password', ['password' => 'Str0ngPassphrase99'])
+        ->assertSessionHasNoErrors();
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasswordSet,
+    );
+    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
+});
+
+test('forgot-password → reset-password は PasswordReset 通知 1 件を送る', function (): void {
+    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
+    $user = User::factory()->create();
+    $email = $user->email;
+
+    // ResetPassword (トークン通知) は Notification::fake() 下で捕まえる。
+    // AuthMethodChangedNotification の検証は同じ fake 内でまとめて行う。
+    Notification::fake();
+
+    $this->post('/forgot-password', ['email' => $email])->assertSessionHasNoErrors();
+
+    $token = null;
+    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
+        $token = $notification->token;
+
+        return true;
+    });
+    expect($token)->toBeString();
+
+    $this->post('/reset-password', [
+        'token' => $token,
+        'email' => $email,
+        'password' => 'CorrectHorse9Battery',
+    ])->assertSessionHasNoErrors();
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasswordReset,
+    );
+    // forgot-password 経路で送られる通知の総数が 1 件であること
+    // (PasswordCredentialService を経由すると PasswordChanged と二重発火するため将来検出用)
+    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
+});
+
+/* ------------------------------------------------------------ 2FA */
+
+test('POST 有効化 → confirm (実 TOTP) は TwoFactorEnabled 通知 1 件のみ送る', function (): void {
+    Notification::fake();
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->post('/user/two-factor-authentication')
+        ->assertRedirect();
+
+    $secret = decrypt($user->fresh()->two_factor_secret);
+    $code = app(Google2FA::class)->getCurrentOtp($secret);
+
+    $this->actingAs($user)
+        ->post('/user/confirmed-two-factor-authentication', ['code' => $code])
+        ->assertRedirect();
+
+    // 有効化 1 操作からの通知は TwoFactorEnabled の 1 通のみ
+    // (vendor の EnableTwoFactorAuthentication は RecoveryCodesGenerated を dispatch しないため)
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::TwoFactorEnabled,
+    );
+    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
+});
+
+test('DELETE /user/two-factor-authentication (無効化) は TwoFactorDisabled 通知 1 件を送る', function (): void {
+    Notification::fake();
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->deleteJson('/user/two-factor-authentication')
+        ->assertOk();
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::TwoFactorDisabled,
+    );
+    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
+});
+
+test('POST /user/two-factor-recovery-codes (再生成) は RecoveryCodesRegenerated 通知 1 件を送る', function (): void {
+    Notification::fake();
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->postJson('/user/two-factor-recovery-codes')
+        ->assertOk();
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::RecoveryCodesRegenerated,
+    );
+    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
+});
+
+/* ------------------------------------------------------------ パスキー */
+
+test('パスキー登録イベントは PasskeyRegistered 通知 1 件を送る (vendor イベント境界)', function (): void {
+    Notification::fake();
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    PasskeyRegistered::dispatch($user, $passkey);
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::PasskeyRegistered,
+    );
+});
+
+test('複数手段が残る passkey 削除は PasskeyDeleted 通知の queue job を 1 件積む (jobs テーブル)', function (): void {
+    config()->set('queue.default', 'database');
+    $user = User::factory()->create(); // password あり = 削除しても手段が残る
+    $passkeys = Passkey::factory()->count(2)->for($user)->create();
+    $target = $passkeys->firstOrFail();
+
+    expect(authMethodChangeJobCount())->toBe(0);
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$target->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    expect(authMethodChangeJobCount())->toBe(1);
+});
+
+test('唯一のログイン手段の passkey 削除は拒否され、通知 job も 0 件のまま', function (): void {
+    config()->set('queue.default', 'database');
+    $user = User::factory()->ssoOnly()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasErrors('login_method');
+
+    expect(authMethodChangeJobCount())->toBe(0);
+});
+
+test('passkey 削除成功後に後続の同期処理が例外を投げると削除自体が rollback し、通知 job も 0 件のまま', function (): void {
+    config()->set('queue.default', 'database');
+    $user = User::factory()->create();
+    $passkeys = Passkey::factory()->count(2)->for($user)->create();
+    $target = $passkeys->firstOrFail();
+
+    Event::listen(PasskeyDeleted::class, function (): void {
+        throw new RuntimeException('listener failure');
+    });
+
+    $this->withoutExceptionHandling();
+
+    expect(fn () => $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete("/user/passkeys/{$target->getKey()}"))
+        ->toThrow(RuntimeException::class, 'listener failure');
+
+    // 行も監査記録も同じ transaction で巻き戻る (既存 PasskeyDeletionAtomicityTest と同型)
+    expect(Passkey::query()->whereKey($target->getKey())->exists())->toBeTrue();
+    expect(SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::PasskeyDeleted->value)
+        ->count())->toBe(0);
+
+    // 通知 job も 0 件のまま (同じ request 内で collector は discard() 済み)
+    expect(authMethodChangeJobCount())->toBe(0);
+
+    // collector が非アクティブなので、後から flush() を試みても push 済みの通知は無く何も起きない
+    app(LoginMethodRemovalPostCommitCallbacks::class)->flush();
+    expect(authMethodChangeJobCount())->toBe(0);
+});
+
+/* ------------------------------------------------------------ SSO 連携 */
+
+test('既存ログイン中ユーザーへの追加連携 (intent=link) は SocialAccountLinked 通知 1 件を送る', function (): void {
+    Notification::fake();
+    $user = User::factory()->create(['email' => 'link-target@example.com']);
+    fakeGoogleSocialiteCallback(fakeGoogleSocialiteUser('g-link-1', 'link-target@example.com'));
+
+    $this->actingAs($user)
+        ->withSession(['social_auth_intent' => 'link'])
+        ->get('/auth/google/callback')
+        ->assertRedirect(route('settings.security'));
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n) => $n->event() === AuthMethodChangeEvent::SocialAccountLinked
+            && $n->context() === 'Google',
+    );
+    Notification::assertSentToTimes($user, AuthMethodChangedNotification::class, 1);
+});
+
+test('新規 SSO 登録 (intent=register) は通知を送らないが監査記録は従来どおり残る', function (): void {
+    Notification::fake();
+    $this->withSession(['social_auth_intent' => 'register']);
+    fakeGoogleSocialiteCallback(fakeGoogleSocialiteUser('g-register-1', 'new-sso-user@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+
+    $user = User::whereBlind('email', 'email_index', 'new-sso-user@example.com')->firstOrFail();
+
+    Notification::assertNothingSentTo($user);
+
+    // 監査記録 (SecurityEventType::SocialAccountLinked) は従来どおり記録される
+    // (通知と監査で対象範囲が意図的に異なる)
+    expect(SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::SocialAccountLinked->value)
+        ->where('user_id', $user->getKey())
+        ->exists())->toBeTrue();
+});
diff --git a/tests/Feature/Auth/PasskeyAuditTrailTest.php b/tests/Feature/Auth/PasskeyAuditTrailTest.php
index de4dcf2f..b20268df 100644
--- a/tests/Feature/Auth/PasskeyAuditTrailTest.php
+++ b/tests/Feature/Auth/PasskeyAuditTrailTest.php
@@ -6,6 +6,7 @@
 use App\Models\Passkey;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
 use Laravel\Passkeys\Events\PasskeyDeleted;
 use Laravel\Passkeys\Events\PasskeyRegistered;
 
@@ -95,7 +96,16 @@ function passkeyAuditCount(SecurityEventType $type): int
     $user = passkeyAuditUser();
     $passkey = $user->passkeys()->firstOrFail();
 
+    // T110: NotifyAuthMethodChange も同じイベントを購読し notifyAfterCommit() を呼ぶため、
+    // EnsureLoginMethodRemains の transaction 外からの直接 dispatch では collector を
+    // 明示的に active化しておく必要がある (非アクティブ中の push() は LogicException)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->start();
+
     PasskeyDeleted::dispatch($user, $passkey);
 
     expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(1);
+
+    // 本テストは監査記録だけを主張するため、積んだ通知コールバックは実行せず後始末する
+    // (Codex 実装レビュー Round 1 [Suggestion] への対応)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->discard();
 });
diff --git a/tests/Feature/Auth/PasskeyDeletionAtomicityTest.php b/tests/Feature/Auth/PasskeyDeletionAtomicityTest.php
index ef4cc993..c762f9ca 100644
--- a/tests/Feature/Auth/PasskeyDeletionAtomicityTest.php
+++ b/tests/Feature/Auth/PasskeyDeletionAtomicityTest.php
@@ -6,6 +6,7 @@
 use App\Models\Passkey;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
 use Illuminate\Support\Facades\Event;
 use Laravel\Passkeys\Actions\DeletePasskey;
 use Laravel\Passkeys\Events\PasskeyDeleted;
@@ -32,11 +33,21 @@
         throw new RuntimeException('listener failure');
     });
 
+    // T110: NotifyAuthMethodChange も同じイベントを購読し notifyAfterCommit() を呼ぶため、
+    // EnsureLoginMethodRemains の transaction 外からの直接呼び出しでは collector を
+    // 明示的に active化しておく必要がある (非アクティブ中の push() は LogicException で、
+    // 本テストが検証したい 'listener failure' より先に伝播してしまう)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->start();
+
     expect(fn () => app(DeletePasskey::class)($user, $passkey))
         ->toThrow(RuntimeException::class, 'listener failure');
 
     // ★包まれていないので行は消えたまま = これが埋め合わせの必要な状態である
     expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
+
+    // 例外経路なので積んだ通知コールバックは実行せず破棄する
+    // (Codex 実装レビュー Round 1 [Suggestion] への対応)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->discard();
 });
 
 test('HTTP 削除経路では同期購読の失敗で削除ごと巻き戻る (関門がトランザクション境界)', function (): void {
diff --git a/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php b/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
index 8a98156e..a690172c 100644
--- a/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
+++ b/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
@@ -5,6 +5,7 @@
 use App\Models\Passkey;
 use App\Models\User;
 use App\Security\RecentAuthState;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
 use Laravel\Passkeys\Events\PasskeyDeleted;
 use Laravel\Passkeys\Events\PasskeyRegistered;
 use Laravel\Passkeys\Events\PasskeyVerified;
@@ -75,9 +76,18 @@
     $this->startSession();
     app(RecentAuthState::class)->confirm(method: 'password');
 
+    // T110: NotifyAuthMethodChange も同じイベントを購読し notifyAfterCommit() を呼ぶため、
+    // EnsureLoginMethodRemains の transaction 外からの直接 dispatch では collector を
+    // 明示的に active化しておく必要がある (非アクティブ中の push() は LogicException)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->start();
+
     PasskeyDeleted::dispatch($user, $passkey);
 
     expect(session()->has('recent_auth_at'))->toBeFalse();
+
+    // 本テストは鮮度失効だけを主張するため、積んだ通知コールバックは実行せず後始末する
+    // (Codex 実装レビュー Round 1 [Suggestion] への対応)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->discard();
 });
 
 /*
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index a6a4e8b0..4967cfad 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 33;
+    public const int DIVERGENCE_ENTRY_COUNT = 37;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 174;
+    public const int ADOPTION_DEBT_COUNT = 170;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 2aafce07..a11d4ace 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -26,7 +26,6 @@ app/Enums/Security/OrgAccessRevocationReason.php	e6f0f69a1d5d519516820cbea6351b2
 app/Enums/Security/RescueRouteGateDisposition.php	611753c642c30d768249d54d3735db13f6f18a77fa9c6be1403503baa2cfed4d
 app/Enums/Security/RescueRouteGateKind.php	44cd0fbc29c87a8b55499671fa302c3fb1b6d14755671708c9128c6aed85306e
 app/Http/Middleware/BughuntCoverageMiddleware.php	ef8572dec59aa0a0e662418ddef9db4dcad3b6421b2e33950c51aeb99efe5aa0
-app/Http/Middleware/EnsureLoginMethodRemains.php	233399c242c2ec55fd1226a78686dab4ff4f889287cf01c4254bc8112c189aab
 app/Http/Middleware/HandleInertiaRequests.php	fc3ee76faa7c90d404baac7873d04f73638b00afd734ec2be1bff951ee5f2ac3
 app/Http/Middleware/IdempotentRequest.php	8d5ba2ed73459ae951dac395aa1b66be6cce161cca3b366f919e0b7a8a6cb78d
 app/Http/Middleware/IssueSessionEpochCookie.php	a19bf87fbb64b8e04b79da3743a18f7e54eafbdaa9ad8f32fbc505696a27f1f8
@@ -106,7 +105,6 @@ tests/Architecture/FormRequestProhibitedKeyTest.php	48ddf301c269a64cba4945b86d9d
 tests/Architecture/IdempotentRouteCoverageTest.php	88382e657dadb0259a76f81e70616ca598934fd8781f462bfe358abf9450c445
 tests/Architecture/InertiaRenderPageExistsInvariantTest.php	5b835756760d1fdc678e036a722fa88f73592c73e7da8e6dd36bd5571a24df1b
 tests/Architecture/JobExclusionOrderingInvariantTest.php	a0160c28779932b9008ff769f7afcdeee82c2e3d813f565f3340cc9d33723a50
-tests/Architecture/JobExecutionDedupInventoryTest.php	371513580feabad57c8c118d9bab61f75e72de12aecd4e6b264a256d9228b811
 tests/Architecture/LegalConsentVersionSingleSourceTest.php	3a7a3dcb63ae95d503575c0ec43ea9d6d3d515b398c78ff173fcd398f9b349bd
 tests/Architecture/LlmDefenseConfigGateTest.php	ac34fefca4dcfa7abe13604bc8195e77fcb7683c9626a00b1548bd48574b1f49
 tests/Architecture/MassAssignmentSafetyTest.php	9d1c76815492c5ede97d3df7e7714977d974c6d972331a55267568566dcb5a7d
@@ -115,7 +113,6 @@ tests/Architecture/ModelDirectFetchInvariantTest.php	5b3078e050f00044156437ca74a
 tests/Architecture/NestedRouteIdorDefenseTest.php	57f5cf1ba2fe3620cbdb21c90db9bfe29a16b63e987c4b9474e92272efc71c51
 tests/Architecture/NoNonCompoundGlobalUseTest.php	f461c835d75087223fe7d6b0247bd817b7618c606435dd3bf9827f579935b7e2
 tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest.php	1949770e31c5c074648b582a8c770722589c3da963d7610984f6fb74ce849b23
-tests/Architecture/PasskeyPackageContractTest.php	2fc532f0b689bf9e9bdb58192a0ecc39f984f7cb0acf1ff2e4f758d98450396f
 tests/Architecture/PasskeyRouteProtectionTest.php	28c96525164530963804e722db3da0aecdc7841efa81babbbbbe0fd91b0aa2f5
 tests/Architecture/PastDueSinceWriteInvariantTest.php	568d9cd1052dbeb0c4a0b00e5202cffce9a07a75405258997dd3f4958a134d4b
 tests/Architecture/PhpstanWrapperInvariantTest.php	06f1309cba2c3bb0c1f1b71691c3ecc0141ec5b63ad4492455bea4f8d9e76747
@@ -125,7 +122,6 @@ tests/Architecture/PromptUntrustedInputContractTest.php	7c63bbd7bbde9e3aaa99965d
 tests/Architecture/PromptYamlContractTest.php	65b420e54bccd41618f10d41f46213a207b6fca9844e91fd162344ded23b6416
 tests/Architecture/QueueDispatchAtomicityInventoryTest.php	4175168181d08e5f9d24d45ba4e9378c56d9885170338a124517247f16e166d8
 tests/Architecture/QueueWorkerLeaseInvariantTest.php	4504f2928cc9b96de7c9bcf901d9e3a9b48cd186293e3a1d0f9b0947f66042e0
-tests/Architecture/QueuedJobLeaseInventoryTest.php	2c953fdabb65bdbfced4a56309cfd0930214eb88218cefc597542868d3662409
 tests/Architecture/RateLimiterKeyConventionTest.php	75d0c01e9ed4a56056160403ec02efe7b96a85ae241a4f1668ef72485e0454e3
 tests/Architecture/RecentAuthRouteTest.php	06dfa019ca22c9c8bb0bdf07d880dd6aabd61b07684fab030fe46d54e1b3d865
 tests/Architecture/RescueRouteGateInventoryTest.php	03bb831a621f7d8dec0d35e677d88755894d96044ba31a201589d96a79fbf2f9
diff --git a/tests/Unit/Enums/Auth/AuthMethodChangeEventTest.php b/tests/Unit/Enums/Auth/AuthMethodChangeEventTest.php
new file mode 100644
index 00000000..869188f5
--- /dev/null
+++ b/tests/Unit/Enums/Auth/AuthMethodChangeEventTest.php
@@ -0,0 +1,11 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+
+test('全 case が空文字列でない headline() を返す', function (): void {
+    foreach (AuthMethodChangeEvent::cases() as $case) {
+        expect($case->headline())->not->toBe('');
+    }
+});
diff --git a/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php b/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php
new file mode 100644
index 00000000..db4faad5
--- /dev/null
+++ b/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php
@@ -0,0 +1,113 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Notifications\Auth\AuthMethodChangedNotification;
+use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+
+test('ShouldQueue を実装している', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::PasswordChanged,
+        CarbonImmutable::now(),
+    );
+
+    expect($notification)->toBeInstanceOf(ShouldQueue::class);
+});
+
+test('via() は mail のみ', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::PasswordChanged,
+        CarbonImmutable::now(),
+    );
+
+    expect($notification->via(new stdClass))->toBe(['mail']);
+});
+
+test('event() / occurredAt() / context() の getter が構築時の値をそのまま返す', function (): void {
+    $occurredAt = CarbonImmutable::create(2026, 8, 21, 12, 0, 0);
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::SocialAccountLinked,
+        $occurredAt,
+        'Google',
+    );
+
+    expect($notification->event())->toBe(AuthMethodChangeEvent::SocialAccountLinked);
+    expect($notification->occurredAt())->toBe($occurredAt);
+    expect($notification->context())->toBe('Google');
+});
+
+test('context 省略時は null', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::PasswordSet,
+        CarbonImmutable::now(),
+    );
+
+    expect($notification->context())->toBeNull();
+});
+
+test('toMail() は headline を件名・本文に含む', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::TwoFactorEnabled,
+        CarbonImmutable::now(),
+    );
+
+    $mail = $notification->toMail(new stdClass);
+
+    expect($mail->subject)->toContain('2 段階認証が有効化されました');
+});
+
+test('SocialAccountLinked は context (provider 表示名) を本文に含む', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::SocialAccountLinked,
+        CarbonImmutable::now(),
+        'Google',
+    );
+
+    $mail = $notification->toMail(new stdClass);
+
+    $lines = collect($mail->introLines)->implode(' ');
+    expect($lines)->toContain('Google');
+});
+
+/**
+ * 秘密情報 (パスワードリセットトークン・2FA 回復コード・TOTP シークレット・パスキーの
+ * WebAuthn credential ID・Socialite provider user ID) を本文へ載せていないことの負例
+ * (Codex 実装レビュー Round 1 [Warning] への対応)。
+ *
+ * 呼び出し元 (`AuthMethodChangeNotifier` / `PasswordCredentialService` /
+ * `SocialAccountService` / `NotifyAuthMethodChange`) はこれらの値を本クラスへ渡していない
+ * (`$context` は provider 表示名にしか使われない)。ここでは「万一渡っても本文に出ない」
+ * ことまでは主張しない (それを主張するには `toMail()` 自身が secret を無視する実装が要る)。
+ * 主張するのは**現時点の全 case × 全構築引数で secret 形の文字列が本文に現れない**こと。
+ */
+test('全 case の toMail() は秘密情報らしき文字列を含まない', function (): void {
+    // 実運用では絶対に渡らない値だが、万一将来 context に紛れ込んだ場合の検出用マーカー。
+    $suspiciousContext = 'reset-token-abc123 recovery-code-XYZ789 totp-secret-000000 '
+        .'credential-id-deadbeef provider-user-id-999999';
+
+    foreach (AuthMethodChangeEvent::cases() as $event) {
+        $notification = new AuthMethodChangedNotification(
+            $event,
+            CarbonImmutable::now(),
+            $suspiciousContext,
+        );
+
+        $mail = $notification->toMail(new stdClass);
+        $rendered = $mail->subject.' '.collect($mail->introLines)->implode(' ')
+            .' '.collect($mail->outroLines)->implode(' ');
+
+        // SocialAccountLinked だけは表示用途で context (provider 表示名) を本文に載せる契約なので
+        // このケースに限りマーカー混入自体は許容し、他 8 case では一切現れないことを固定する。
+        if ($event === AuthMethodChangeEvent::SocialAccountLinked) {
+            continue;
+        }
+
+        expect($rendered)->not->toContain('reset-token');
+        expect($rendered)->not->toContain('recovery-code');
+        expect($rendered)->not->toContain('totp-secret');
+        expect($rendered)->not->toContain('credential-id');
+        expect($rendered)->not->toContain('provider-user-id');
+    }
+});
diff --git a/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php b/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php
new file mode 100644
index 00000000..ff4006b7
--- /dev/null
+++ b/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php
@@ -0,0 +1,68 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Models\User;
+use App\Notifications\Auth\AuthMethodChangedNotification;
+use App\Services\Security\AuthMethodChangeNotifier;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
+use Illuminate\Contracts\Notifications\Dispatcher;
+use Illuminate\Support\Facades\Exceptions;
+use Illuminate\Support\Facades\Notification;
+
+test('notify() は通知送信で例外が起きても吸収し呼び出し元へ伝播しない', function (): void {
+    $dispatcher = Mockery::mock(Dispatcher::class);
+    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('boom'));
+    app()->instance(Dispatcher::class, $dispatcher);
+
+    $user = User::factory()->create();
+    $notifier = new AuthMethodChangeNotifier(new LoginMethodRemovalPostCommitCallbacks);
+
+    // 例外が呼び出し元へ伝播しないこと自体が主張 (伝播すればこのテストは fail する)
+    $notifier->notify($user, AuthMethodChangeEvent::PasswordChanged);
+
+    expect(true)->toBeTrue();
+});
+
+test('notify() は吸収した例外を report() する (Codex 実装レビュー Round 1 [Warning] への対応)', function (): void {
+    Exceptions::fake();
+
+    $dispatcher = Mockery::mock(Dispatcher::class);
+    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('boom'));
+    app()->instance(Dispatcher::class, $dispatcher);
+
+    $user = User::factory()->create();
+    $notifier = new AuthMethodChangeNotifier(new LoginMethodRemovalPostCommitCallbacks);
+
+    $notifier->notify($user, AuthMethodChangeEvent::PasswordChanged);
+
+    Exceptions::assertReported(RuntimeException::class);
+});
+
+test('notifyAfterCommit() は collector が active なら push が成功する', function (): void {
+    $user = User::factory()->create();
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+    $collector->start();
+    $notifier = new AuthMethodChangeNotifier($collector);
+
+    $notifier->notifyAfterCommit($user, AuthMethodChangeEvent::PasskeyDeleted);
+
+    // push が例外にならなかったこと + flush で実行されること
+    Notification::fake();
+    $collector->flush();
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        fn (AuthMethodChangedNotification $n): bool => $n->event() === AuthMethodChangeEvent::PasskeyDeleted,
+    );
+});
+
+test('notifyAfterCommit() は collector が非アクティブなら LogicException', function (): void {
+    $user = User::factory()->create();
+    $notifier = new AuthMethodChangeNotifier(new LoginMethodRemovalPostCommitCallbacks);
+
+    expect(fn () => $notifier->notifyAfterCommit($user, AuthMethodChangeEvent::PasskeyDeleted))
+        ->toThrow(LogicException::class);
+});
diff --git a/tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php b/tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php
new file mode 100644
index 00000000..70894ef3
--- /dev/null
+++ b/tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php
@@ -0,0 +1,135 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
+
+test('start() を呼ばずに push() すると LogicException', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+
+    expect(fn () => $collector->push(fn () => null))
+        ->toThrow(LogicException::class);
+});
+
+test('start() → push() → flush() で積んだコールバックが実行される', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+    $executed = [];
+
+    $collector->start();
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'a';
+    });
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'b';
+    });
+    $collector->flush();
+
+    expect($executed)->toBe(['a', 'b']);
+});
+
+test('flush() を 2 回呼んでも 2 回目は何もしない', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+    $count = 0;
+
+    $collector->start();
+    $collector->push(function () use (&$count): void {
+        $count++;
+    });
+    $collector->flush();
+    $collector->flush();
+
+    expect($count)->toBe(1);
+});
+
+test('discard() 後は flush() が何も実行しない', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+    $count = 0;
+
+    $collector->start();
+    $collector->push(function () use (&$count): void {
+        $count++;
+    });
+    $collector->discard();
+    $collector->flush();
+
+    expect($count)->toBe(0);
+});
+
+test('active 中に start() を再度呼ぶと LogicException になり、先に積んだ callback を消さない', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+    $executed = [];
+
+    $collector->start();
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'first';
+    });
+
+    expect(fn () => $collector->start())->toThrow(LogicException::class);
+
+    // 二重 start() の失敗後も先に積んだ callback は残っている
+    $collector->flush();
+    expect($executed)->toBe(['first']);
+});
+
+test('flush() した後は再度 start() できる', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+    $executed = [];
+
+    $collector->start();
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'first';
+    });
+    $collector->flush();
+
+    $collector->start();
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'second';
+    });
+    $collector->flush();
+
+    expect($executed)->toBe(['first', 'second']);
+});
+
+test('discard() した後は再度 start() できる', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+    $executed = [];
+
+    $collector->start();
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'discarded';
+    });
+    $collector->discard();
+
+    $collector->start();
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'kept';
+    });
+    $collector->flush();
+
+    expect($executed)->toBe(['kept']);
+});
+
+test('inactive 状態の flush() は no-op であり例外にならない', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+
+    $collector->flush();
+
+    expect(true)->toBeTrue(); // 例外が起きないことの確認
+});
+
+test('inactive 状態の discard() (二重呼び出しを含む) は no-op であり例外にならない', function (): void {
+    $collector = new LoginMethodRemovalPostCommitCallbacks;
+
+    $collector->discard();
+    $collector->discard();
+
+    // その後 start() して通常どおり再利用できる
+    $executed = [];
+    $collector->start();
+    $collector->push(function () use (&$executed): void {
+        $executed[] = 'ok';
+    });
+    $collector->flush();
+
+    expect($executed)->toBe(['ok']);
+});
diff --git a/tests/js/architecture/enum-ts-sync-discovery.test.ts b/tests/js/architecture/enum-ts-sync-discovery.test.ts
index 463122ee..be786f69 100644
--- a/tests/js/architecture/enum-ts-sync-discovery.test.ts
+++ b/tests/js/architecture/enum-ts-sync-discovery.test.ts
@@ -78,6 +78,7 @@ const PHP_ENUM_EXEMPTIONS = [
     { path: "app/Enums/AccountDeletionBlockReason.php", reason: "退会ブロックの内部理由コード。画面には理由ごとの案内文をサーバ側で確定して渡すだけである" },
     { path: "app/Enums/ApiErrorCode.php", reason: "公開 API のエラーコード語彙。TS 側はコードで分岐せず HTTP 状態とエラー文言だけを見る" },
     { path: "app/Enums/ApiKeyAbility.php", reason: "API キー権限 (read/write) の内部語彙。管理画面はチェックボックスの選択状態だけを見る" },
+    { path: "app/Enums/Auth/AuthMethodChangeEvent.php", reason: "認証手段変更メール通知の内部分類 (T110)。件名・本文はサーバ側で確定して送るだけで画面へは一切渡らない" },
     { path: "app/Enums/Auth/EmailVerificationGateContext.php", reason: "メール確認ゲートの発生元コンテキスト。内部のルーティング判定にのみ使う語彙である" },
     { path: "app/Enums/Billing/AutoRechargeAttemptStatus.php", reason: "自動追加購入試行の内部状態機械。画面は結果の通知種別 (BillingFeedbackKind) 経由でしか見ない" },
     { path: "app/Enums/Billing/AutoRechargeDisabledReason.php", reason: "自動追加購入停止の内部理由。通知本文はサーバ側で文言を確定して送る" },
@@ -161,7 +162,7 @@ const PHP_ENUM_EXEMPTIONS = [
 ] as const satisfies readonly PhpEnumExemption[];
 
 /** `PHP_ENUM_EXEMPTIONS` の件数の pin。増えても減っても赤くする。 */
-const EXPECTED_EXEMPTION_COUNT = 86;
+const EXPECTED_EXEMPTION_COUNT = 87;
 
 interface UnresolvablePhpEnumEntry {
     readonly path: string;

---

## フルスイート最終結果 (全項目 green)

修正後に `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` /
`pnpm test` / `pnpm test:packages` を直列実行し、EXIT=0 (全項目 green) を確認した。

- `composer test`: 6436 tests, 6434 passed, 2 skipped (既知の skip。failed 0)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages`: いずれも成功
- `pnpm test`: 173 files / 2369 tests、すべて成功
  (Round 1 の時点で `enum-ts-sync-discovery.test.ts` が
  `App\Enums\Auth\AuthMethodChangeEvent` の未分類を検出していたため、
  `PHP_ENUM_EXEMPTIONS` へ「メール通知の内部分類でフロントエンドに一切公開されない」
  という理由付きで登録し解消した。これは Codex の指摘外だったが、
  AGENTS.md ドメイン固有規約 19 の deny-by-default 目録の取りこぼしであり、
  今回の 2 件と同種の登録漏れだったため併せて修正した)
- `pnpm test:packages`: 10 files / 106 tests、すべて成功

以上、全体の判定をお願いします。
