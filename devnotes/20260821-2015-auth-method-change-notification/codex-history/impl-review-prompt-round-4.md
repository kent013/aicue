【使命 (AGENTS.md North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 (AGENTS.md)】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## 役割・タスク

あなたは TODO T110「認証手段変更のメール通知ポリシー」の実装レビューを、前回セッション
(Round 1〜3、いずれも CHANGES_REQUESTED) から引き継いで再開する。これは新しい Codex
セッションであり、前回セッションの会話文脈は持たないため、以下に必要な背景をすべて示す。

### 前回セッション (Round 1〜3) の要約

Round 1〜3 で一貫して残った Critical (1 件):

> `App\Http\Middleware\EnsureLoginMethodRemains` のパスキー削除経路が、
> `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` という middleware 専用の
> post-commit callback collector を自作し、transaction 呼び出しの**正常終了後にだけ**
> `AuthMethodChangeNotifier::notifyAfterCommit()` 経由で通知を queue へ投入していた。
> これは AGENTS.md ドメイン規約 11「キュー投入の原子性」(業務状態の保存とキュー投入は
> 同一トランザクション内で行い `afterCommit` に依存しない。免除機構を持たない) と
> 字義上衝突する。静的検査 (`QueueDispatchAtomicityInventoryTest`) が collector を
> 検出しないのは検出範囲の外にあるだけで、規約の意味上の適用除外にはならない、という
> 指摘だった (Round 2 で確定、Round 3 でも解消せず「唯一の残存ブロッカー」と評価された)。

Round 1〜3 で解消済みだった Warning/Suggestion (再掲しない。今回のレビュー対象ではない):
enqueue 失敗の実経路テスト、`report()` 検証、秘密情報非掲載の負例、collector 後始末、
SocialAccountLinked のテスト境界分離、フルスイート green 化。**これらは今回のセッションでは
再チェック不要**(前回で解消済みという前提で進めてよい。ただし今回の diff で明らかに
再発していたら指摘してよい)。

### 監督セッション (2026-08-21) の裁定

上記 Critical に対し、人間の監督セッションが次の裁定を下した。実装エージェント (私) は
この裁定に従って diff を作成した。

> 選択肢 (a) を採る: `LoginMethodRemovalPostCommitCallbacks` (collector) を撤去し、
> パスキー削除の通知も他と同じく**業務トランザクションの内側で dispatch する**。
>
> 根拠: AGENTS.md ドメイン規約 11 は原子性の前提として driver=database / キュー DB 接続 =
> 業務 DB / after_commit=false を `QueueDispatchAtomicityGuard` で全環境の起動時に強制している。
> この前提の下ではキュー行が業務トランザクションに参加するため、トランザクション内 dispatch は
> (1) rollback でキュー行ごと消え、取り消された変更の誤報が構造的に出ない (collector が
> 実現したかった性質)、(2) commit と同時に耐久化され規約 11 の原子性を字義どおり満たす。
> collector は afterCommit の意味論を手作りで再現するもので規約の趣旨と衝突し、撤去は
> 思考原則 2 (今必要なものだけ) にも沿う。規約 11 の免除追加 (AGENTS.md 変更) は不要になる。

この裁定は「`PasskeyDeleted` は `EnsureLoginMethodRemains` が課す transaction (ロック取得〜
controller〜同期 listener〜レスポンス生成まで丸ごと) の内側で既に同期発火している」という
既存の事実 (`tests/Architecture/PasskeyPackageContractTest.php` が同期購読者の顔ぶれと順序を
pin している) を前提にしている。したがって listener が collector を経由せず、他の 7 イベント
(PasswordSet/PasswordChanged/PasswordReset/TwoFactorEnabled/TwoFactorDisabled/
RecoveryCodesRegenerated/PasskeyRegistered) と全く同じ `AuthMethodChangeNotifier::notify()`
(その場で `$user->notify()` を呼ぶだけ) を呼べば、dispatch は自然に業務トランザクションの
内側で起きる。

**この裁定のスコープは passkey 削除経路 (collector) のみ**である。
`PasswordCredentialService::afterPersist()` (パスワード保存の transaction 終了後に
`notify()` を呼ぶ) と `SocialAccountService::linkToUser()` (連携保存の後に `notify()` を
呼ぶ。連携保存自体は transaction で包んでいない) の構造は、Round 1 の critical では
同じ論点で「要修正」と指摘されていたが、**今回の裁定はこの 2 か所を変更しない**
(監督セッションの明示的な決定であり、実装エージェントが独自にスコープを広げた結果ではない)。

### 適用した変更 (今回の diff)

1. `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` とその単体テストを削除
2. `App\Http\Middleware\EnsureLoginMethodRemains` を collector 配線 (try/catch +
   start/flush/discard) が無い元の姿へ完全に戻した (`sha256sum` が採用時債務の pin 値
   `233399c242c2ec55fd1226a78686dab4ff4f889287cf01c4254bc8112c189aab` と再び一致することを確認済み)
3. `App\Listeners\Auth\NotifyAuthMethodChange::handlePasskeyDeleted()` を他の 7 イベントと
   同じ private `notify()` helper 経由にし、`AuthMethodChangeNotifier::notifyAfterCommit()`
   も削除した
4. `App\Services\Security\AuthMethodChangeNotifier` からコンストラクタ引数
   (collector) と `notifyAfterCommit()` を削除
5. `App\Providers\AppServiceProvider` から collector の `scoped()` bind を削除
6. rollback したら通知が出ないことのテスト
   (`tests/Feature/Auth/AuthMethodChangeNotificationTest.php`) は元から
   `config(['queue.default' => 'database'])` + 実 `jobs` テーブル件数観測で書かれていた
   ため (`Queue::fake()` は使っていない)、collector 依存の後始末コードだけを削除した
7. 既存パスキーテスト 3 本 (`PasskeyAuditTrailTest` / `PasskeyDeletionAtomicityTest` /
   `PasskeyRecentAuthInvalidationTest`) に足していた `start()`/`discard()` 呼び出しを削除
   (3 ファイルとも前回セッション導入前の姿へバイト単位で一致する形に戻った)
8. `docs/template-divergence.md` の D36 (collector を正当化していた登録) を削除し、
   `tests/Support/TemplateDivergence/adoption-debt.tsv` へ `EnsureLoginMethodRemains.php`
   の行を採用時ハッシュ付きで復活、`LedgerPins::DIVERGENCE_ENTRY_COUNT` (37→36) /
   `ADOPTION_DEBT_COUNT` (170→171) を更新した。D37 (`JobExecutionDedupInventoryTest.php`)・
   D38 (`QueuedJobLeaseInventoryTest.php`)・D39 (`PasskeyPackageContractTest.php`) は
   `AuthMethodChangedNotification` クラス自体・新設 listener の登録に起因する差分であり
   collector とは無関係のため維持した
9. `docs/architecture.md` の発火点対応表を更新 (`PasskeyDeleted` の発火方法を
   `notifyAfterCommit()` から `notify()` へ)、裁定の要約を追記
10. `devnotes/20260821-2015-auth-method-change-notification/detailed-design.md` へ
    「実装レビューの裁定」節、`devnotes/20260821-2147-todo-T110/implementation-notes.md`
    へ適用内容の記録を追記

フルスイート (`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages`) は全 green を確認済み。

## 質問

以下に今回の diff 全文 (`git diff -- app/ tests/Architecture tests/Feature/Auth tests/Unit
tests/Support/TemplateDivergence docs/ tests/js/architecture`) を貼る。

1. 上記の変更で、前回 Round 1〜3 で維持された Critical (規約 11 との衝突) は解消したか。
   解消したと判断する場合、その理由を規約 11 の原文 (「業務状態の保存とキュー投入は
   同一トランザクション内で行う」「免除機構を持たない」) に照らして述べよ。
   解消していないと判断する場合、具体的にどの構造がまだ規約 11 と衝突するかを述べよ
   (「静的検査を通過しているだけ」という指摘であれば、実際に規約 11 の意味上どこが
   満たされていないかを具体的に指摘すること)。
2. 裁定のスコープを passkey 削除経路のみに限定し、`PasswordCredentialService` /
   `SocialAccountService` の既存の「commit 後に `notify()` を呼ぶ」構造を変更しない
   ことについて、今回の diff の判定 (APPROVED か CHANGES_REQUESTED か) に影響するか。
   影響すると判断する場合、それは今回の Critical (collector 撤去) の解消可否とは
   独立した新しい論点として扱ってよいか、もしくは今回のブロッカーとして扱うべきか、
   立場を明示すること。
3. その他、今回の diff で新たに気づいた Warning/Suggestion があれば指摘してよい
   (ただし Round 1〜3 で既に解消済みとされた論点の再指摘は不要)。

最後に必ず `APPROVED` か `CHANGES_REQUESTED` のいずれかを明記すること。

---

## diff 全文

```diff
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
diff --git a/app/Listeners/Auth/NotifyAuthMethodChange.php b/app/Listeners/Auth/NotifyAuthMethodChange.php
new file mode 100644
index 00000000..51a69fe8
--- /dev/null
+++ b/app/Listeners/Auth/NotifyAuthMethodChange.php
@@ -0,0 +1,97 @@
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
+     * `PasskeyDeleted` は `EnsureLoginMethodRemains` が課す transaction (ロック取得〜
+     * controller〜同期 listener〜レスポンス生成まで) の内側で同期発火する
+     * (`tests/Architecture/PasskeyPackageContractTest.php` が同期購読者の顔ぶれと
+     * 購読順を pin する)。したがって本ハンドラも他イベントと同様にその場で
+     * `notify()` を呼べばよい — キュー投入 (`jobs` 行の INSERT) はこの listener を
+     * 呼び出している業務トランザクションに自然に参加し、rollback すれば jobs 行ごと
+     * 消え、commit と同時に耐久化される (AGENTS.md ドメイン規約 11)。
+     */
+    public function handlePasskeyDeleted(PasskeyDeleted $event): void
+    {
+        $this->notify($event->user, AuthMethodChangeEvent::PasskeyDeleted);
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
index c504bf91..c9ad56a5 100644
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
@@ -213,6 +214,9 @@ public function boot(): void
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
index 00000000..95fcaf59
--- /dev/null
+++ b/app/Services/Security/AuthMethodChangeNotifier.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Security;
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Models\User;
+use App\Notifications\Auth\AuthMethodChangedNotification;
+use Carbon\CarbonImmutable;
+use Throwable;
+
+/**
+ * 認証手段変更通知 (T110) の発火の唯一の窓口。
+ *
+ * `SecurityEventRecorder::record()` と同型の best-effort 契約 — 通知の queue 投入失敗
+ * (DB 接続断等) が呼び出し元の認証操作を失敗させないよう、例外は `report()` して継続する。
+ *
+ * **呼び出し元がどの transaction 文脈にいるかは本クラスの関心事ではない**。
+ * `notify()` はその場で `$user->notify()` を呼ぶだけであり、キュー投入の原子性
+ * (AGENTS.md ドメイン規約 11) を満たすかどうかは呼び出し元の文脈で決まる —
+ * 業務トランザクションの内側から呼ばれれば queue の `jobs` 行がそのトランザクションに
+ * 参加し (`config/queue.php` の既定接続は `after_commit=false`)、トランザクション外
+ * (vendor イベントが業務トランザクションの外で発火する経路) から呼ばれれば
+ * その場で即時 enqueue される。いずれの場合も afterCommit の類には依存しない。
+ */
+class AuthMethodChangeNotifier
+{
+    /**
+     * 受けた文脈のままその場で queue へジョブを投入する (best-effort)。
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
+}
diff --git a/app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php b/app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php
deleted file mode 100644
index e69de29b..00000000
diff --git a/docs/architecture.md b/docs/architecture.md
index 9a62f192..d5c990b7 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -3098,3 +3098,62 @@ ### 保証しないもの (誇張しない)
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
+- **窓口**: 発火は `App\Services\Security\AuthMethodChangeNotifier` (`notify()` = 受けた
+  文脈のままその場で `$user->notify()` を呼ぶ。queue 投入自体の失敗は `report()` して
+  認証操作を巻き込まない) の 1 経路に統一する。呼び出し元は `App\Listeners\Auth\NotifyAuthMethodChange`
+  (vendor イベント購読) と、イベント化されていない `App\Services\Auth\PasswordCredentialService` /
+  `App\Services\Auth\SocialAccountService` の直接呼び出しの 2 種類。
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
+| `PasskeyDeleted` | Laravel Passkeys `PasskeyDeleted` | **是** (`EnsureLoginMethodRemains` が課す) | `notify()` |
+| `SocialAccountLinked` | `SocialAccountService::linkToUser()` (`register()` 内部の初回連携では発火しない) | 否 | `notify()` |
+
+`notify()` はどの経路でも同じ 1 メソッドであり、受けた文脈のままその場で `$user->notify()`
+を呼ぶだけである (呼び出し元の transaction 文脈は関心事にしない)。実際のメール配送は
+worker が非同期に行う。`PasskeyDeleted` だけが「transaction 内か」で「是」なのは、
+本 listener 自身が何か特別な機構を持つからではなく、`EnsureLoginMethodRemains` が課す
+transaction (ロック取得〜controller〜同期 listener〜レスポンス生成まで丸ごと) の**内側で
+`PasskeyDeleted` が同期発火する**ため、`notify()` の呼び出しが自然にその transaction の
+内側で起きるからである。queue 投入 (`jobs` 行の INSERT) は既定接続 (`after_commit=false`)
+なのでその場で業務トランザクションへ参加し、rollback すれば jobs 行ごと消え、commit と
+同時に耐久化される (AGENTS.md ドメイン規約 11「キュー投入の原子性」に字義どおり従う)。
+2026-08-21 の実装レビューで指摘された Critical (transaction 呼び出しの正常終了後にだけ
+実行する専用の post-commit callback collector を自作していた) は、この collector を撤去し
+listener がイベントの文脈のままその場で dispatch する形へ変更して解消した。詳細は
+`devnotes/20260821-2015-auth-method-change-notification/detailed-design.md`
+「実装レビューの裁定」節。
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
index be26af1b..14198914 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 33 件
+登録エントリ: 36 件
 
 ## 記録の原則
 
@@ -2119,3 +2119,143 @@ ### 関連
 
 - 実装: `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md`
 - 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`
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
index 00000000..5d8b7ed2
--- /dev/null
+++ b/tests/Feature/Auth/AuthMethodChangeNotificationTest.php
@@ -0,0 +1,330 @@
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
+    // 通知 job も 0 件のまま (state の保存とキュー投入が同一トランザクションに乗るため、
+    // rollback で jobs 行ごと消える。AGENTS.md ドメイン規約 11)
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
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index a6a4e8b0..80e882c9 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 33;
+    public const int DIVERGENCE_ENTRY_COUNT = 36;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 174;
+    public const int ADOPTION_DEBT_COUNT = 171;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 2aafce07..1f239ab2 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -106,7 +106,6 @@ tests/Architecture/FormRequestProhibitedKeyTest.php	48ddf301c269a64cba4945b86d9d
 tests/Architecture/IdempotentRouteCoverageTest.php	88382e657dadb0259a76f81e70616ca598934fd8781f462bfe358abf9450c445
 tests/Architecture/InertiaRenderPageExistsInvariantTest.php	5b835756760d1fdc678e036a722fa88f73592c73e7da8e6dd36bd5571a24df1b
 tests/Architecture/JobExclusionOrderingInvariantTest.php	a0160c28779932b9008ff769f7afcdeee82c2e3d813f565f3340cc9d33723a50
-tests/Architecture/JobExecutionDedupInventoryTest.php	371513580feabad57c8c118d9bab61f75e72de12aecd4e6b264a256d9228b811
 tests/Architecture/LegalConsentVersionSingleSourceTest.php	3a7a3dcb63ae95d503575c0ec43ea9d6d3d515b398c78ff173fcd398f9b349bd
 tests/Architecture/LlmDefenseConfigGateTest.php	ac34fefca4dcfa7abe13604bc8195e77fcb7683c9626a00b1548bd48574b1f49
 tests/Architecture/MassAssignmentSafetyTest.php	9d1c76815492c5ede97d3df7e7714977d974c6d972331a55267568566dcb5a7d
@@ -115,7 +114,6 @@ tests/Architecture/ModelDirectFetchInvariantTest.php	5b3078e050f00044156437ca74a
 tests/Architecture/NestedRouteIdorDefenseTest.php	57f5cf1ba2fe3620cbdb21c90db9bfe29a16b63e987c4b9474e92272efc71c51
 tests/Architecture/NoNonCompoundGlobalUseTest.php	f461c835d75087223fe7d6b0247bd817b7618c606435dd3bf9827f579935b7e2
 tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest.php	1949770e31c5c074648b582a8c770722589c3da963d7610984f6fb74ce849b23
-tests/Architecture/PasskeyPackageContractTest.php	2fc532f0b689bf9e9bdb58192a0ecc39f984f7cb0acf1ff2e4f758d98450396f
 tests/Architecture/PasskeyRouteProtectionTest.php	28c96525164530963804e722db3da0aecdc7841efa81babbbbbe0fd91b0aa2f5
 tests/Architecture/PastDueSinceWriteInvariantTest.php	568d9cd1052dbeb0c4a0b00e5202cffce9a07a75405258997dd3f4958a134d4b
 tests/Architecture/PhpstanWrapperInvariantTest.php	06f1309cba2c3bb0c1f1b71691c3ecc0141ec5b63ad4492455bea4f8d9e76747
@@ -125,7 +123,6 @@ tests/Architecture/PromptUntrustedInputContractTest.php	7c63bbd7bbde9e3aaa99965d
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
index 00000000..edfafc3b
--- /dev/null
+++ b/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php
@@ -0,0 +1,164 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Models\User;
+use App\Notifications\Auth\AuthMethodChangedNotification;
+use App\Services\Auth\SocialAccountService;
+use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Support\Facades\Notification;
+use Laravel\Socialite\Contracts\User as SocialiteUserContract;
+use Mockery\MockInterface;
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
+ * (Codex 実装レビュー Round 1 [Warning] への対応。Round 2 [Warning] を受けてテスト名・
+ * docblock・検証範囲を実際の契約に合わせて絞った)。
+ *
+ * **本テストが主張する範囲は次の 3 つだけである** (Round 2 の指摘どおり、
+ * `SocialAccountLinked` を含めて「秘密情報を含まない」と主張することはできない —
+ * このイベントだけは provider 表示名を本文へ載せる契約が意図的にあるため):
+ *
+ * 1. `SocialAccountLinked` 以外の 8 case は、`$context` に何を渡しても本文へ一切出さない
+ *    (`toMail()` がそもそも `$context` を参照しない実装であることの裏取り)
+ * 2. `SocialAccountLinked` は `$context` (provider 表示名) を意図的に本文へ出す
+ * 3. 実際の呼び出し元 (`SocialAccountService::linkToUser()`) が `$context` へ渡すのは
+ *    `providerLabel()` の戻り値 (config の表示名 or provider 識別子文字列) だけであり、
+ *    Socialite の provider user ID (`$socialiteUser->getId()`) を渡していないこと
+ *    (呼び出し境界のテスト。`toMail()` 自身が secret を無視する実装だという主張はしない)
+ */
+test('SocialAccountLinked 以外の 8 case は context を本文へ一切出さない', function (): void {
+    $suspiciousContext = 'reset-token-abc123 recovery-code-XYZ789 totp-secret-000000 '
+        .'credential-id-deadbeef provider-user-id-999999';
+
+    foreach (AuthMethodChangeEvent::cases() as $event) {
+        if ($event === AuthMethodChangeEvent::SocialAccountLinked) {
+            continue;
+        }
+
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
+        expect($rendered)->not->toContain('reset-token');
+        expect($rendered)->not->toContain('recovery-code');
+        expect($rendered)->not->toContain('totp-secret');
+        expect($rendered)->not->toContain('credential-id');
+        expect($rendered)->not->toContain('provider-user-id');
+    }
+});
+
+test('SocialAccountLinked は context をそのまま本文へ出す (意図的な契約であることの明示)', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::SocialAccountLinked,
+        CarbonImmutable::now(),
+        'provider-user-id-999999',
+    );
+
+    $mail = $notification->toMail(new stdClass);
+    $rendered = collect($mail->introLines)->implode(' ');
+
+    // 本 case だけは表示用途で context を本文に載せる契約であることの確認。
+    // 「安全である」ことの根拠は本テストではなく、呼び出し境界テスト
+    // (下記 'SocialAccountService は provider 表示名だけを context へ渡す') が担う。
+    expect($rendered)->toContain('provider-user-id-999999');
+});
+
+test('SocialAccountService は provider 表示名だけを context へ渡す (provider user ID は渡さない)', function (): void {
+    Notification::fake();
+
+    $user = User::factory()->create(['email' => 'social-boundary@example.com']);
+    /** @var SocialiteUserContract&MockInterface $socialiteUser */
+    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
+    $socialiteUser->shouldReceive('getId')->andReturn('super-secret-provider-user-id-12345');
+    $socialiteUser->shouldReceive('getEmail')->andReturn('social-boundary@example.com');
+    $socialiteUser->shouldReceive('getName')->andReturn('Boundary User');
+
+    app(SocialAccountService::class)->linkToUser('google', $socialiteUser, $user);
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        function (AuthMethodChangedNotification $n): bool {
+            // provider 表示名 (config の label または provider 識別子文字列) であり、
+            // Socialite の provider user ID ではないことを固定する。
+            expect($n->context())->not->toBeNull();
+            expect($n->context())->not->toContain('super-secret-provider-user-id-12345');
+
+            return true;
+        },
+    );
+});
diff --git a/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php b/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php
new file mode 100644
index 00000000..ab28995c
--- /dev/null
+++ b/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Models\User;
+use App\Services\Security\AuthMethodChangeNotifier;
+use Illuminate\Contracts\Notifications\Dispatcher;
+use Illuminate\Support\Facades\Exceptions;
+
+test('notify() は通知送信で例外が起きても吸収し呼び出し元へ伝播しない', function (): void {
+    $dispatcher = Mockery::mock(Dispatcher::class);
+    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('boom'));
+    app()->instance(Dispatcher::class, $dispatcher);
+
+    $user = User::factory()->create();
+    $notifier = new AuthMethodChangeNotifier;
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
+    $notifier = new AuthMethodChangeNotifier;
+
+    $notifier->notify($user, AuthMethodChangeEvent::PasswordChanged);
+
+    Exceptions::assertReported(RuntimeException::class);
+});
diff --git a/tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php b/tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php
deleted file mode 100644
index e69de29b..00000000
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
```
