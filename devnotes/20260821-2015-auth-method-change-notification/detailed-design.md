# 詳細設計: auth-method-change-notification (T110)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

本設計は使命への直接機能ではないが、業務データ (SOP・動画マニュアル) を守るアカウント保護の
基盤として位置づける。

### 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告をしない
2. PHPStan level 10 エラーの widen・baseline 化をしない
3. dev DB への破壊操作をエージェント判断で実行しない
4. `response()->json()` の直書きをしない (DTO / JsonResource / Inertia)
5. LLM 呼び出しの Prism 直呼びをしない (本設計は非該当)
6. prompt 文字列のコード直書きをしない (本設計は非該当)
7. 操作系 POST の応答で `redirect()->intended()` を使わない
8. 必須条件未充足を理由にボタンを disabled にする UI を作らない (本設計はフロント変更なし)
9. Artifact ツールでの成果物公開を行わない

### コーディングルール

- PHPStan level 10 必須 (`composer phpstan`)
- Pest (`composer test`)。RefreshDatabase はグローバル適用済み、個別 `DatabaseTransactions` 禁止
- テストデータは Factory 経由
- DTO + JsonResource パターン (本設計は API レスポンスを持たないため非該当)
- `declare(strict_types=1)` + 日本語コメント
- PHP 8.4 + Laravel 12 + Fortify + Laravel Passkeys + Socialite

## 概念設計リファレンス

`devnotes/20260821-2015-auth-method-change-notification/conceptual-design.md` (Codex 概念設計
レビュー 5 ラウンドを経て APPROVED)。決定の出所: オーナー (ishitoya@rio.ne.jp) が
2026-08-21 に「方針は任せる。一般的なものに倣う」と決定。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| 1 | 通知イベント enum・Notification・Notifier の新設 | `app/Enums/Auth/AuthMethodChangeEvent.php`, `app/Notifications/Auth/AuthMethodChangedNotification.php`, `app/Services/Security/AuthMethodChangeNotifier.php` | High |
| 2 | パスキー削除の commit 後発火機構 | `app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php`, `app/Http/Middleware/EnsureLoginMethodRemains.php` | High |
| 3 | イベント購読 listener と DI 配線 | `app/Listeners/Auth/NotifyAuthMethodChange.php`, `app/Providers/AppServiceProvider.php` | High |
| 4 | パスワード設定/変更・SSO 連携の直接呼び出し配線 | `app/Services/Auth/PasswordCredentialService.php`, `app/Services/Auth/SocialAccountService.php` | High |
| 5 | 既存 deny-by-default 目録への登録 | `tests/Architecture/JobExecutionDedupInventoryTest.php`, `tests/Architecture/JobDeferralTerminationGateTest.php` | High |
| 6 | テンプレート差分登録 (`EnsureLoginMethodRemains` の採用時債務→意図的逸脱への切替) | `docs/template-divergence.md`, `tests/Support/TemplateDivergence/adoption-debt.tsv`, `tests/Support/TemplateDivergence/LedgerPins.php` | High |
| 7 | 運用ドキュメント新設 | `docs/architecture.md` | Medium |
| 8 | テスト | `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` (新設) ほか既存テスト影響確認 | High |

---

## 施策 1: 通知イベント enum・Notification・Notifier の新設

### 変更箇所

新設 3 ファイル。既存参照実装: `App\Notifications\User\TwoFactorResetSecurityNotification`
(件名・本文の書き方), `App\Notifications\Billing\PaymentFailedNotification` (`ShouldQueue` +
`failed()` の書き方), `App\Services\Security\SecurityEventRecorder` (best-effort try/catch の
書き方)。

### 波及変更

- TypeScript 型定義: なし (フロント非公開の通知)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` (新設。施策 8)

### 新規コード

`app/Enums/Auth/AuthMethodChangeEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * 認証手段の変更を本人へメール通知する対象イベント (T110)。
 *
 * 発火点対応表 (どの vendor イベント / Service 呼び出しがどの case を発火させるか、
 * transaction の有無) は docs/architecture.md §認証手段変更のメール通知ポリシー が正本。
 * 対象は「本人が自分の認証手段を変更したとき」に限る。ログインのたびの通知・
 * 組織管理者によるメンバー操作 (別ポリシー。`TwoFactorResetSecurityNotification`) は含まない。
 */
enum AuthMethodChangeEvent: string
{
    case PasswordSet = 'password_set';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case PasskeyRegistered = 'passkey_registered';
    case PasskeyDeleted = 'passkey_deleted';
    case SocialAccountLinked = 'social_account_linked';

    /** メール本文の見出し文 (秘密情報は含めない)。 */
    public function headline(): string
    {
        return match ($this) {
            self::PasswordSet => 'パスワードが設定されました',
            self::PasswordChanged => 'パスワードが変更されました',
            self::PasswordReset => 'パスワードがリセットされました',
            self::TwoFactorEnabled => '2 段階認証が有効化されました',
            self::TwoFactorDisabled => '2 段階認証が無効化されました',
            self::RecoveryCodesRegenerated => '2 段階認証の回復コードが再発行されました',
            self::PasskeyRegistered => 'パスキーが追加されました',
            self::PasskeyDeleted => 'パスキーが削除されました',
            self::SocialAccountLinked => '外部ログインが連携されました',
        };
    }
}
```

`app/Notifications/Auth/AuthMethodChangedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\Auth\AuthMethodChangeEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * 認証手段 (パスワード・2FA・パスキー・SSO 連携) の変更を本人へ知らせるセキュリティ通知 (T110)。
 *
 * 対象・発火点・保証しないものの正本は docs/architecture.md
 * §認証手段変更のメール通知ポリシー。秘密情報 (トークン・コード・パスキーの識別子詳細) は
 * 一切載せない。配信先は送信時点 (worker 実行時) の現在の登録メールアドレス —
 * queued notification を包む queue job 側の直列化 (Illuminate の標準機構。個別の実装は
 * 持たない) が worker 実行時に User を ID から再取得するため、CipherSweet の復号も
 * 通常どおり働く (Codex 詳細設計レビュー Round 1 [Suggestion]: 直列化の主体は
 * `Queueable` トレイト自体ではなく job 側であるため表現を修正)。
 *
 * queue 投入自体の失敗を吸収する契約は本クラスではなく呼び出し元
 * (`App\Services\Security\AuthMethodChangeNotifier`) が持つ。
 */
class AuthMethodChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AuthMethodChangeEvent $event,
        private readonly CarbonImmutable $occurredAt,
        private readonly ?string $context = null,
    ) {}

    /** イベント種別。テストで enum とメール内容の対応を直接固定するための getter。 */
    public function event(): AuthMethodChangeEvent
    {
        return $this->event;
    }

    /** 発生時刻。テスト用 getter。 */
    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }

    /** SSO 連携時の provider 表示名等。テスト用 getter。 */
    public function context(): ?string
    {
        return $this->context;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = Config::string('app.name');
        $headline = $this->event->headline();
        $occurredAtLabel = $this->occurredAt->timezone('Asia/Tokyo')->isoFormat('YYYY-MM-DD HH:mm');

        $detail = $this->event === AuthMethodChangeEvent::SocialAccountLinked
            ? sprintf('外部ログイン (%s) が連携されました。', $this->context ?? '外部サービス')
            : "{$headline}。";

        return (new MailMessage)
            ->subject("【{$appName}】{$headline}")
            ->line("お使いの {$appName} アカウントで次の変更がありました。")
            ->line($detail)
            ->line("変更時刻: {$occurredAtLabel} (JST)")
            ->line('ご自身の操作であれば対応不要です。')
            ->line('心当たりがない場合は、直ちにパスワードを再設定し、サポートまでご連絡ください。')
            ->action('パスワードを再設定する', route('password.request'));
    }
}
```

`app/Services/Security/AuthMethodChangeNotifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Models\User;
use App\Notifications\Auth\AuthMethodChangedNotification;
use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * 認証手段変更通知 (T110) の発火の唯一の窓口。
 *
 * `SecurityEventRecorder::record()` と同型の best-effort 契約 — 通知の queue 投入失敗
 * (DB 接続断等) が呼び出し元の認証操作を失敗させないよう、例外は `report()` して継続する。
 */
class AuthMethodChangeNotifier
{
    public function __construct(
        private readonly LoginMethodRemovalPostCommitCallbacks $postCommitCallbacks,
    ) {}

    /**
     * transaction 外で直ちに queue へジョブを投入する (best-effort)。
     * 実際のメール配送は worker が非同期に行う。
     */
    public function notify(User $user, AuthMethodChangeEvent $event, ?string $context = null): void
    {
        try {
            $user->notify(new AuthMethodChangedNotification($event, CarbonImmutable::now(), $context));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * `EnsureLoginMethodRemains` が開く transaction の内側からだけ呼ぶこと。
     * transaction の呼び出しが正常終了した後に `notify()` を呼ぶよう予約する best-effort
     * 契約 (rollback した場合は投入を試みない。「commit 成否と通知が 1:1」という厳密な
     * 保証ではない — flush 前のプロセス終了・queue 投入失敗時は通知が届かないことがある。
     * Codex 詳細設計レビュー Round 1 [Warning] を受けて表現を統一)。
     *
     * collector が非アクティブ (`EnsureLoginMethodRemains` の transaction 外) のときは
     * `push()` が `LogicException` を投げる (施策 2)。
     */
    public function notifyAfterCommit(User $user, AuthMethodChangeEvent $event, ?string $context = null): void
    {
        $this->postCommitCallbacks->push(
            function () use ($user, $event, $context): void {
                $this->notify($user, $event, $context);
            },
        );
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`void` / `MailMessage` / `list<string>`)
- [x] null 安全 (`?string $context` を明示。narrowing は不要な単純代入のみ)
- [x] DTO を返している (配列返却なし。`toMail()` は vendor 契約どおり `MailMessage` を返す)
- [x] Generics の型パラメータ (該当なし)

### テスト計画

- [ ] `AuthMethodChangedNotification` が `ShouldQueue` を実装していることの Unit テスト
- [ ] `AuthMethodChangeEvent::headline()` が全 case で空文字列を返さないことの Unit テスト
- [ ] `AuthMethodChangeNotifier::notify()` が例外時に `report()` して継続すること
      (通知送信で強制的に例外を発生させ、呼び出し元へ伝播しないことを確認)
- [ ] `AuthMethodChangedNotification::event()`/`context()` の getter が構築時の値を
      そのまま返すことの Unit テスト (enum とメール内容の対応を直接固定するため。
      Codex 詳細設計レビュー Round 1 [Warning] への対応)
- [ ] 個別の `DatabaseTransactions` を使っていないこと

### リスク

- 本文の日本語がユーザーに誤解を与えないか — 既存 2 通知と同じ「本人操作なら対応不要 /
  心当たりがなければ再設定+サポート連絡」の型を踏襲しており、リスクは低い

---

## 施策 2: パスキー削除の commit 後発火機構

### 変更箇所

- 新設: `app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php`
- 変更: `app/Http/Middleware/EnsureLoginMethodRemains.php` (全文)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` (施策 8) に
  「パスキー削除成功→1 通」「唯一の手段で拒否→0 通」「削除後の後続処理が例外で落ちた場合→0 通
  (rollback)」「アクティブ外での `push()` は `LogicException`」の 4 ケースを含める。既存の
  `tests/Architecture/LoginMethodRemovalRouteTest.php` 等が変わらず green であることを確認する
  (本施策は `handle()` の中身だけを変更し、route への付与・ロック順序・投影評価のロジックは
  一切変えない)。既存 `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php`
  (「HTTP 削除経路では同期購読の失敗で削除ごと巻き戻る」) が、本施策が追加する try/catch の
  外側でもロック取得〜同期 listener〜レスポンス生成が同一 transaction のままであることを
  実挙動で固定し続けることを確認する (Codex 詳細設計レビュー Round 1 [Warning] への対応)。

### 現行コード

`app/Http/Middleware/EnsureLoginMethodRemains.php` (該当部分):

```php
final class EnsureLoginMethodRemains
{
    public function __construct(
        private readonly LoginMethodInventory $inventory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->pass($next, $request);
        }

        return DB::transaction(function () use ($request, $next, $user): Response {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));

            if ($remaining->isEmpty()) {
                return $this->reject($request);
            }

            return $this->pass($next, $request);
        });
    }
    // ... removalFor() / reject() / pass() は変更なし
}
```

### 変更後コード

新設 `app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Closure;

/**
 * `App\Http\Middleware\EnsureLoginMethodRemains` が開く transaction の呼び出しが正常終了
 * した後に実行するコールバックを溜める request-scoped collector (T110)。
 *
 * **この middleware 専用**であり、アプリ全体の汎用 post-commit 基盤ではない
 * (Codex 詳細設計レビュー時に「機能の名前に立ち返れ」の観点で命名を確定。用途を広げる
 * ときは名前も見直すこと)。将来 password 削除 / SSO 解除の removal route が同じ
 * middleware に乗ったときは、そのまま同じ collector を使い続けてよい (「認証手段除去
 * transaction の post-commit callback」という意味は変わらない)。
 *
 * container binding は `scoped()` (`AppServiceProvider::register()`)。`singleton()` は
 * Octane 等の長寿命 worker でリクエストをまたいで同一インスタンスが再利用され得るため
 * 使わない。queue worker は別 container で起動するためこの collector は継承されない
 * (`App\Support\CriticalActionContext` と同じ前提)。
 *
 * **アクティブ状態を持つ** (Codex 詳細設計レビュー Round 1 [Warning] への対応)。
 * `EnsureLoginMethodRemains::handle()` の transaction 開始直前に `start()` を呼ぶ想定で、
 * 非アクティブ中の `push()` は `LogicException` で fail-fast する。これにより
 * 「`PasskeyDeleted` がこの middleware の transaction の外から発火した」という設計違反を
 * 実行時に検出できる (この middleware **専用**であることをコードでも強制する)。
 *
 * **状態遷移** (Codex 詳細設計レビュー Round 2 [Warning] への対応。表以外の遷移は無い):
 *
 * | 現在状態 | 操作 | 結果 |
 * |---|---|---|
 * | inactive | `start()` | active |
 * | active | `push()` | active のまま追加 |
 * | active | `flush()` | 実行して inactive |
 * | active | `discard()` | 破棄して inactive |
 * | inactive | `push()` | `LogicException` |
 * | active | `start()` | `LogicException` |
 * | inactive | `flush()` / `discard()` | no-op |
 *
 * 「active 中に `start()` を再度呼ぶと積んだ callback を無言で消す」という実装は選ばない
 * (nested middleware・同一 request scope 内の誤った再利用が起きたとき、検出すべき通知欠落を
 * 正常系に見せてしまうため)。
 */
final class LoginMethodRemovalPostCommitCallbacks
{
    /** @var list<Closure(): void> */
    private array $callbacks = [];

    private bool $active = false;

    /**
     * `EnsureLoginMethodRemains` の transaction を開始する直前に呼ぶこと。
     *
     * @throws \LogicException 既に active 中 (二重 `start()`) に呼ばれた場合。
     *                          積んでいた callback を無言で消さないための fail-fast
     *                          (Codex 詳細設計レビュー Round 2 [Warning] への対応)
     */
    public function start(): void
    {
        if ($this->active) {
            throw new \LogicException(
                'LoginMethodRemovalPostCommitCallbacks::start() は既に active 中に'
                .'呼ばれました (二重 start)。',
            );
        }

        $this->callbacks = [];
        $this->active = true;
    }

    /**
     * @param  Closure(): void  $callback
     *
     * @throws \LogicException 非アクティブ中 (`start()` を呼んでいない、または
     *                          既に `flush()`/`discard()` 済み) に呼ばれた場合
     */
    public function push(Closure $callback): void
    {
        if (! $this->active) {
            throw new \LogicException(
                'LoginMethodRemovalPostCommitCallbacks::push() は '
                .'EnsureLoginMethodRemains の transaction 中にのみ呼べます。',
            );
        }

        $this->callbacks[] = $callback;
    }

    /**
     * `EnsureLoginMethodRemains` の transaction 呼び出しが正常終了した後にだけ呼ぶこと
     * (呼び出しの正常終了 = 本 middleware にとっての commit。best-effort 契約であり、
     * 「commit 成否と通知が 1:1」という厳密な保証ではない — 実行後のプロセス終了・
     * queue 投入失敗時は通知が届かないことがある。Codex 詳細設計レビュー Round 1
     * [Warning] を受けて表現を統一)。
     *
     * 実行前に保持配列を空へ移し非アクティブへ戻すため、2 回呼んでも 2 回目は何もしない。
     * **1 件目のコールバックが例外を投げれば後続は実行されない** (`foreach` の通常の挙動。
     * 保証を誇張しない)。現在の利用者 (`AuthMethodChangeNotifier::notify()`) は例外を
     * 内部で吸収するため実害はないが、本クラス自体はそれを保証しない。
     */
    public function flush(): void
    {
        $pending = $this->callbacks;
        $this->callbacks = [];
        $this->active = false;

        foreach ($pending as $callback) {
            $callback();
        }
    }

    /** transaction が rollback したときに呼ぶこと。積んだコールバックを実行せずに破棄する。 */
    public function discard(): void
    {
        $this->callbacks = [];
        $this->active = false;
    }
}
```

`app/Http/Middleware/EnsureLoginMethodRemains.php` (変更後):

```php
final class EnsureLoginMethodRemains
{
    public function __construct(
        private readonly LoginMethodInventory $inventory,
        private readonly LoginMethodRemovalPostCommitCallbacks $postCommitCallbacks,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->pass($next, $request);
        }

        $this->postCommitCallbacks->start();

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
    }
    // ... removalFor() / reject() / pass() は変更なし (import に Throwable を追加)
}
```

**この変更が保つ既存の不変条件**: ロック取得順序 (User → credential)・投影評価の位置
(ロック取得後)・`$next()` を transaction 内で実行すること、のいずれも変更しない。
追加したのは「transaction 呼び出しの開始前に collector を `start()` し、結果に応じて
`flush()` / `discard()` を呼ぶ」という外側の 1 層だけである。`reject()` 分岐は例外を投げずに
正常な値を返すため `DB::transaction()` の呼び出しは正常終了するが、この分岐では `$next()`
が呼ばれず `PasskeyDeleted` も発火していないため、`flush()` は実質的に no-op になる。

**「commit」という言葉の使い方について**: 本 middleware の `DB::transaction()` 呼び出しが
正常終了することは、それが**最外 transaction である場合にのみ**物理的な commit を意味する。
production の Web 経路ではこの middleware が最外 transaction を持つ (前段に他の
`DB::transaction()` がない) ことを前提とする。`RefreshDatabase` を使う本設計のテストでは
グローバルな外側 transaction が既に開いているため、テスト中の `flush()` は物理 commit の
**前**に起きる (Codex 詳細設計レビュー Round 1 [Warning] への対応)。したがって以降の
テスト計画・D36 の記述は「物理 commit 後の耐久性の証明」という表現を使わず、「rollback
した経路では投入を試みない」「`DB::afterCommit()` 系のフックを追加していない (規約 11 の
0 件 pin と非干渉)」という、テストで実際に固定できる範囲に限定する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`Response`)
- [x] null 安全 (該当なし)
- [x] DTO を返している (該当なし。middleware は Response を返す契約)
- [x] `Throwable` の import 漏れが無いこと (PHPStan level 10 で未解決クラス参照は検出される)
- [x] `LoginMethodRemovalPostCommitCallbacks::push()` は `\LogicException` を投げるため
      呼び出し元 (`AuthMethodChangeNotifier::notifyAfterCommit()`) の戻り値型は変わらない
      (未catchの例外は呼び出し経路をそのまま伝播する契約で問題ない)

### テスト計画

- [ ] `EnsureLoginMethodRemains` を通る削除が成功したとき、`jobs` テーブルに通知ジョブが
      1 件積まれる (Feature テスト。実 route `DELETE /user/passkeys/{passkey}` を通す)
- [ ] 唯一のログイン手段のパスキーを削除しようとして 422/302 (reject) になったとき、
      通知ジョブが 0 件のまま (= flush が no-op)
- [ ] `flush()` を 2 回呼んでも 2 回目は何もしないことの Unit テスト
- [ ] `discard()` 後は `flush()` が何も実行しないことの Unit テスト
- [ ] `start()` を呼ばずに `push()` した場合 `LogicException` になることの Unit テスト
      (Codex 詳細設計レビュー Round 1 [Warning] への対応。middleware 専用であることの
      実行時強制)
- [ ] active 中に `start()` を再度呼ぶと `LogicException` になることの Unit テスト
      (Codex 詳細設計レビュー Round 2 [Warning] への対応)
- [ ] 二重 `start()` が失敗した後も、先に積んだ callback が失われていない (例外前に
      積んだ callback を消していないこと) の Unit テスト
- [ ] `flush()` した後は再度 `start()` できることの Unit テスト
- [ ] `discard()` した後は再度 `start()` できることの Unit テスト
- [ ] inactive 状態で `flush()` を呼んでも no-op であり、既存の「2 回目の `flush()` は
      何もしない」契約と整合することの Unit テスト
- [ ] inactive 状態で `discard()` を呼んでも例外にならず no-op であること (二重
      `discard()` も no-op)、その後 `start()` して通常どおり再利用できることの Unit テスト
      (Codex 詳細設計レビュー Round 3 [Warning] への対応。状態遷移表の
      「inactive | `flush()`/`discard()` | no-op」のうち `discard()` 側の未検証を埋める)
- [ ] 削除成功 → 後続の同期処理 (listener) が例外を投げる → passkey 削除自体が rollback
      → 通知 job が 0 件 → 同じ request 内で後から `flush()` しても通知されないこと
      (Feature テスト。既存 `PasskeyDeletionAtomicityTest` の「HTTP 削除経路では同期購読の
      失敗で削除ごと巻き戻る」と同じ状況を、通知 job の件数観点で追加検証する)
- [ ] 既存の `tests/Architecture/LoginMethodRemovalRouteTest.php`,
      `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` ほか `EnsureLoginMethodRemains`
      関連の既存 Architecture/Feature テストが変わらず green (ロック順序・投影評価・
      transaction 境界の後退が無いことは、値ではなくこれら既存の実挙動テストで検出する)

### リスク

- `EnsureLoginMethodRemains` は将来 password 削除 / SSO 解除の removal route にも
  適用される想定の共有 middleware であり、`docs/template-fingerprints.json` に
  登録されたテンプレート共有ファイルである。**テンプレート差分登録が必須** (施策 6)。
- 変更はロジックの追加のみで既存分岐を書き換えていないため、既存の振る舞い
  (投影評価・ロック順序・reject/pass の分岐) への影響は無い

---

## 施策 3: イベント購読 listener と DI 配線

### 変更箇所

- 新設: `app/Listeners/Auth/NotifyAuthMethodChange.php`
- 変更: `app/Providers/AppServiceProvider.php` (import 追加 + `register()`/`boot()` に 1 行ずつ)

### 波及変更

- テストファイル: `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` (施策 8)

### 新規コード

`app/Listeners/Auth/NotifyAuthMethodChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Models\User;
use App\Services\Security\AuthMethodChangeNotifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * 認証手段変更 → 本人へのメール通知 (T110)。
 *
 * `App\Listeners\RecordSecurityEvent` と同じ構成 (vendor イベント購読 + イベント化
 * できない経路は Service から直接呼ぶ) に倣う。イベント化できない経路
 * (パスワード設定/変更・SSO 連携) は `PasswordCredentialService` / `SocialAccountService`
 * から直接 `AuthMethodChangeNotifier` を呼ぶ (本 listener の対象外)。
 *
 * `Event::subscribe` で明示登録する (`AppServiceProvider::boot()`)。
 */
class NotifyAuthMethodChange
{
    public function __construct(
        private readonly AuthMethodChangeNotifier $notifier,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PasswordReset::class, [self::class, 'handlePasswordReset']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
        $events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
        $events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::PasswordReset);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::TwoFactorEnabled);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::TwoFactorDisabled);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::RecoveryCodesRegenerated);
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->notify($event->user, AuthMethodChangeEvent::PasskeyRegistered);
    }

    /**
     * `EnsureLoginMethodRemains` の transaction 内で発火するため
     * `notifyAfterCommit()` を使う (`notify()` の即時 enqueue は使わない)。
     *
     * この前提 (「`PasskeyDeleted` は必ず `EnsureLoginMethodRemains` の transaction 内で
     * 発火する」) を本 listener 自身は検証できないが、`notifyAfterCommit()` の先にある
     * collector が非アクティブ中の `push()` を `LogicException` で拒否する (施策 2)。
     * deny-by-default route gate の対象外の経路から `PasskeyDeleted` が直接 dispatch
     * された場合はこの例外で検出される (Codex 詳細設計レビュー Round 1 [Warning] への対応)。
     */
    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $user = $this->asUser($event->user);
        if ($user === null) {
            return;
        }

        $this->notifier->notifyAfterCommit($user, AuthMethodChangeEvent::PasskeyDeleted);
    }

    private function notify(mixed $user, AuthMethodChangeEvent $event): void
    {
        $user = $this->asUser($user);
        if ($user === null) {
            return;
        }

        $this->notifier->notify($user, $event);
    }

    private function asUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
```

`app/Providers/AppServiceProvider.php` の変更点 (既存の関連行の隣に追記。全文は転記しない):

```php
// import 追加
use App\Listeners\Auth\NotifyAuthMethodChange;
use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;

// register() 内、既存の CriticalActionContext::class scoped bind の隣
$this->app->scoped(LoginMethodRemovalPostCommitCallbacks::class);

// boot() 内、既存の Event::subscribe(RecordSecurityEvent::class) の隣
Event::subscribe(NotifyAuthMethodChange::class);
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`void`)
- [x] null 安全 (`asUser()` で narrowing。既存 `RecordSecurityEvent::asUser()` と同型)
- [x] `subscribe()` の第二引数配列記法 `[self::class, 'method']` は既存 `RecordSecurityEvent`
      と同じ書き方 (PHPStan level 10 で通ることを確認済みの既存パターン)

### テスト計画

各イベントについて「発火 → 期待した `AuthMethodChangeEvent` の通知が 1 件 queue に積まれる」
ことを `Notification::fake()->assertSentTo(...)` で固定する (施策 8 のテストファイルへ集約)。
特にパスワードリセット経路は `PasswordReset` 通知のみが 1 件送られ、`PasswordChanged` との
二重通知にならないことを確認する (次項参照)。

### 実装時の確認事項 (設計時点で確認済み)

`Illuminate\Auth\Events\PasswordReset` (forgot-password 経路) と
`App\Services\Auth\PasswordCredentialService::change()` (current_password 必須の変更経路) が
二重に発火しないか — 両方が同じパスワード更新処理を経由すると `PasswordReset` と
`PasswordChanged` が両方発火し二重通知になる。`app/Actions/Fortify/ResetUserPassword.php`
(Fortify の `ResetsUserPasswords` 実装) を確認したところ、`$user->forceFill(['password' =>
...])->save()` のみで `PasswordCredentialService` を経由しない。したがって現時点で二重通知は
起きない (Codex 詳細設計レビュー Round 1 [Suggestion] への対応)。施策 8 のテストでは
「forgot-password 経路で送られる通知の総数が 1 件であること」まで固定し、将来
`ResetUserPassword` が `PasswordCredentialService` を経由する形へ変わったときに検出できる
ようにする。

### リスク

- `subscribe()` の文字列/配列によるイベント登録は PHPStan では取り違えを検出できない
  (既存 `RecordSecurityEvent` と同じ限界)。Feature テストで実際の発火を固定することで担保する。

---

## 施策 4: パスワード設定/変更・SSO 連携の直接呼び出し配線

### 変更箇所

- `app/Services/Auth/PasswordCredentialService.php` (`afterPersist()` / 構造体コンストラクタ)
- `app/Services/Auth/SocialAccountService.php` (`linkToUser()` / 構造体コンストラクタ)

### 波及変更

- テストファイル: `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` (施策 8)。
  特に「`register()` (新規 SSO 登録) では通知しない」「`linkToUser()` (既存アカウントへの
  追加連携) でのみ通知する」ことを明示的に区別するテストを含める。

### 現行コード

`app/Services/Auth/PasswordCredentialService.php`:

```php
final class PasswordCredentialService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    // ...

    private function afterPersist(User $user, string $plain, SecurityEventType $event): void
    {
        $this->recorder->record($event, $user);

        Auth::logoutOtherDevices($plain);

        $this->deleteOtherSessionRecords($user);
    }
}
```

`app/Services/Auth/SocialAccountService.php`:

```php
class SocialAccountService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly OrganizationProvisioningService $provisioning,
        private readonly EmailTrustPolicyResolver $emailTrust,
    ) {}

    // ...

    public function linkToUser(string $provider, SocialiteUser $socialiteUser, User $user): bool
    {
        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if ($existing !== null) {
            return $existing->user_id === $user->id;
        }

        $this->link($provider, $socialiteUser, $user);

        return true;
    }
}
```

### 変更後コード

`app/Services/Auth/PasswordCredentialService.php`:

```php
final class PasswordCredentialService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly AuthMethodChangeNotifier $notifier,
    ) {}

    // ...

    /**
     * 保存 **commit 後**の副作用: 監査記録 → 通知 → 他デバイス失効 → DB session 行削除。
     * 通知は「本人が自分の認証手段を変更したことに気づく」導線であり (T110)、
     * 対象は `setInitial()` (SSO のみのアカウントへ password を追加する = パスキー追加と
     * 同じ脅威モデル) と `change()` の両方。
     */
    private function afterPersist(User $user, string $plain, SecurityEventType $event): void
    {
        $this->recorder->record($event, $user);

        $this->notifier->notify($user, match ($event) {
            SecurityEventType::PasswordSet => AuthMethodChangeEvent::PasswordSet,
            SecurityEventType::PasswordChanged => AuthMethodChangeEvent::PasswordChanged,
            default => throw new LogicException(
                'PasswordCredentialService::afterPersist() は PasswordSet / PasswordChanged 以外の'
                .'SecurityEventType で呼ばれない想定です。',
            ),
        });

        Auth::logoutOtherDevices($plain);

        $this->deleteOtherSessionRecords($user);
    }
}
```

`app/Services/Auth/SocialAccountService.php`:

```php
class SocialAccountService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly OrganizationProvisioningService $provisioning,
        private readonly EmailTrustPolicyResolver $emailTrust,
        private readonly AuthMethodChangeNotifier $notifier,
    ) {}

    // ...

    /**
     * 連携追加。既に他ユーザーに連携済みの場合は false を返す。
     *
     * **通知は本メソッドだけが行う** (`register()` 内部の初回連携では呼ばない)。
     * 新規 SSO 登録は「既存アカウントが新しい認証手段を獲得した」わけではなく、
     * 本人がその場で作ったばかりのアカウントに「連携しました」と知らせるのは
     * 一般的な慣行にも無い冗長な通知になるため (T110 概念設計「制約・前提」)。
     */
    public function linkToUser(string $provider, SocialiteUser $socialiteUser, User $user): bool
    {
        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if ($existing !== null) {
            return $existing->user_id === $user->id;
        }

        $this->link($provider, $socialiteUser, $user);

        $this->notifier->notify($user, AuthMethodChangeEvent::SocialAccountLinked, $this->providerLabel($provider));

        return true;
    }

    /** config の label を使う。未宣言なら provider 識別子そのものを使う (fail-closed ではなく表示のみのため許容)。 */
    private function providerLabel(string $provider): string
    {
        $label = config("template.social_providers.{$provider}.label");

        return is_string($label) && $label !== '' ? $label : $provider;
    }
}
```

(両ファイルとも `use App\Enums\Auth\AuthMethodChangeEvent;` /
`use App\Services\Security\AuthMethodChangeNotifier;` の追加が必要。
`PasswordCredentialService` は `use LogicException;` も追加する。)

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (`providerLabel()` は `is_string()` narrowing)
- [x] `match` は `default` 分岐で `LogicException` を投げ、到達不能ケースを型で塞ぐ
      (`SecurityEventType` の他 case が渡ることは呼び出し元の構造上あり得ないが、
      `afterPersist()` は private かつ呼び出し元 2 か所 [`setInitial()`/`change()`] が
      固定のため、fail-fast にして「新しい呼び出し元を追加したのに通知が対象外」の
      サイレント欠落を防ぐ)

### テスト計画

- [ ] `setInitial()` (パスワード初回設定) で `AuthMethodChangeEvent::PasswordSet` の通知が
      1 件 queue に積まれ、他の `AuthMethodChangedNotification` は送られていない
- [ ] `change()` (パスワード変更) で `AuthMethodChangeEvent::PasswordChanged` の通知が 1 件、
      他の `AuthMethodChangedNotification` (特に `PasswordReset`) は送られていない
      (Codex 詳細設計レビュー Round 1 [Suggestion] への対応。二重発火の検出用)
- [ ] `SocialAccountService::linkToUser()` (既存ログイン中ユーザーへの追加連携) で
      `AuthMethodChangeEvent::SocialAccountLinked` の通知が 1 件、`context` に provider の
      表示名 (`Google` 等) が入る
- [ ] `SocialAccountService::register()` (新規 SSO 登録) では通知が 0 件
      (`SecurityEventType::SocialAccountLinked` の監査記録は従来どおり残ることも確認し、
      監査と通知の対象範囲が意図的に異なることをテスト名で明示する)

### リスク

- `afterPersist()` の呼び出し元が将来増えた場合、`match` の `default` が fail-fast するため
  実装漏れが本番で無言にならない (テストで先に検出される)

---

## 施策 5: 既存 deny-by-default 目録への登録

`App\Notifications\Auth\AuthMethodChangedNotification` は `ShouldQueue` を実装するため、
以下 2 つの既存 gate へ登録が必須 (AGENTS.md ドメイン規約 6 / 18)。

### 実装順序 (テストファースト。値を先に固定しない)

Codex 詳細設計レビュー Round 1 [Warning]: 件数 pin を実装の最初に書き換えるのはテスト
ファーストに反する。以下の順序で行うこと。

1. `AuthMethodChangedNotification` (施策 1) を実装する
2. `composer test` の Architecture レーンを走らせ、上記 2 gate が「未登録の `ShouldQueue`
   クラスを検出した」として赤くなることを確認する (fail を確認してから直す)
3. 検出結果に基づき 5-a/5-b の exemption エントリを追加する
4. cap をそのときの実測値へ更新する (以下の 15/9/16/10 は**設計時点 (2026-08-21) の実測値**
   であり、他の並行実装の影響で実装時に変わっている可能性がある。固定的な真実として
   扱わず、実装直前に現在値を再確認すること)
5. green を確認する

### 5-a. `tests/Architecture/JobExecutionDedupInventoryTest.php`

`jobDedupExemptions()` の配列へ 1 エントリ追加し、`jobDedupExemptionCap()` を **15→16**、
`jobDedupExemptionCapByCase()` の `DuplicateDeliveryAccepted` を **9→10** に更新する
(設計時点の実測値。上記実装順序に従い実装時に再確認)。

```php
AuthMethodChangedNotification::class => new ExemptionEntry(
    JobDedupExemption::DuplicateDeliveryAccepted,
    '認証手段変更のお知らせ。ドメイン状態を一切書かず、重複受信しても同じ内容の'
    .'メールが 2 通届くだけで、受信者に新たな操作 (支払い・承認等) を要求しない。',
),
```

（`use App\Notifications\Auth\AuthMethodChangedNotification;` を import 追加）

### 5-b. `tests/Architecture/JobDeferralTerminationGateTest.php`

`jobDeferralTerminationInventory()` の配列へ 1 エントリ追加する (母集団は
`QueuedJobPopulation::shouldQueueClasses()` から自動抽出されるため、件数 pin の更新は
このファイル内のインベントリ配列のみで良い)。

```php
[
    'class' => AuthMethodChangedNotification::class,
    'mode' => 'NO_DEFERRAL',
    'reason' => '認証手段変更のお知らせを 1 通送るだけで、他の仕事と順番を争わない。',
    'coveredBy' => [],
],
```

### 5-c. 確認: `ExternalSeamInventoryTest` への登録は不要

`Illuminate\Support\Facades\Notification` / `Illuminate\Support\Facades\Mail` の facade を
**使わない** (`$user->notify(...)` という Notifiable インスタンスメソッドのみを使う)。
`tests/Support/ExternalSeam/ExternalSeamScanner.php` の `FACADE_RULES` は上記 2 facade の
FQCN 参照だけを検知するため、本設計の新規コードは走査対象にならない
(既存の `TwoFactorResetSecurityNotification` / `AccountDeletionRequestedNotification` 等の
`->notify()` 呼び出しが未登録なのと同じ扱い)。**実装時に `Notification::route()` や
`Notification::send()` を書かないこと** (書いた場合は登録が必要になる)。

### テスト計画

- [ ] `composer test` (Architecture レーン) で上記 2 gate が green になることを確認
      (件数 pin の更新漏れがあれば gate 自体が赤くなる = 実装時の fail-fast)

---

## 施策 6: テンプレート差分登録

`app/Http/Middleware/EnsureLoginMethodRemains.php` は `docs/template-fingerprints.json` に
登録されたテンプレート共有ファイルであり、かつ現在 `tests/Support/TemplateDivergence/
adoption-debt.tsv` の**採用時債務**として凍結登録されている
(`233399c242c2ec55fd1226a78686dab4ff4f889287cf01c4254bc8112c189aab`)。

施策 2 で本ファイルの内容を変更するため、`app-design` スキル 3-0 の 3 択のうち
**「(3) 意図的逸脱として登録を書き債務から削る」**を選ぶ (内容を採用時の姿へ戻す・
テンプレートへ同期する、のいずれも本設計の目的と矛盾するため不採用)。

### 変更内容

1. `tests/Support/TemplateDivergence/adoption-debt.tsv` から
   `app/Http/Middleware/EnsureLoginMethodRemains.php` の行を削除する
2. `tests/Support/TemplateDivergence/LedgerPins.php`:
   - `ADOPTION_DEBT_COUNT` を **174 → 173**
   - `DIVERGENCE_ENTRY_COUNT` を **33 → 34**
3. `docs/template-divergence.md`:
   - ヘッダ直下の「登録エントリ: 33 件」を「登録エントリ: 34 件」に更新
   - 新規エントリ `D36` を追記 (既存最大番号は D35。番号は再利用しない):

```markdown
## D36 パスキー等除去 middleware の transaction 正常終了後コールバック機構

| 行 | 内容 |
|---|---|
| 対象パス | `app/Http/Middleware/EnsureLoginMethodRemains.php` |
| 業務要件起因の説明 | 認証手段変更のメール通知 (T110) のうち、パスキー削除だけは本 middleware が課す transaction (ロック取得〜controller〜同期 listener〜レスポンス生成まで丸ごと) の内側で発火する。同 transaction の外部 I/O・非 afterCommit queue dispatch 禁止という既存契約 (本ファイルの docblock) と、AGENTS.md ドメイン規約 11 (`DB::afterCommit()` 系の 0 件 pin) の両方を満たしつつ、rollback した経路では通知投入を試みない best-effort 契約を実現するには、transaction 呼び出し側に「正常終了後にだけ実行する」明示的な分岐を持たせる必要がある |
| 揃え続ける不変条件と保証機構 | ロック取得順序 (User→credential)・投影評価の位置 (ロック取得後)・`$next()` を transaction 内で実行することは変更しない。追加したのは transaction 呼び出しの開始前に collector を `start()` し、正常終了時は `LoginMethodRemovalPostCommitCallbacks::flush()`、例外 (rollback) 時は `discard()` を呼ぶ外側の 1 層だけ。既存 `tests/Architecture/LoginMethodRemovalRouteTest.php` (route 分類の drift 検出) と `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` (「HTTP 削除経路では同期購読の失敗で削除ごと巻き戻る」— ロック取得〜同期 listener〜レスポンス生成が同一 transaction であることの実挙動固定) が変わらず green であること、および本設計が追加する rollback 統合テスト (施策 8) で揃え続ける |
| 再判定の条件 | 本 middleware をテンプレート側の姿へ戻す判断をしたとき / パスキー以外の除去 route (password 削除・SSO 解除) を追加する際に、同じ collector を使うか再設計するかを判断したとき |
| 決めた日 | 2026-08-21 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| `handle()` の構造 | `DB::transaction()` の戻り値をそのまま return | `DB::transaction()` を try/catch で包み、正常終了後に post-commit callback を flush、例外時は discard してから re-throw |
| post-commit callback | 概念が無い | `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` (この middleware 専用・`scoped()` bind) |

### なぜ正当な差分か (logic-driven)

本 middleware が課す transaction は「ロック取得〜レスポンス生成まで」を丸ごと囲む
特殊な形 (通常の業務 transaction より広い) であり、この形自体が既に本アプリ固有の設計
(採用時債務として凍結されていた理由もこの特殊性にある)。この上に「commit 後にだけ
実行してよい処理」を安全に載せる口が無かったため、汎用ではなく本 middleware 専用の
最小限の口を追加した。

### 揃えている不変条件 (これは保証し続ける)

> 「rollback した場合は積んだコールバックを実行しない。transaction 呼び出しの正常終了後、
> 積んだコールバックの実行を 1 回試みる」(Codex 詳細設計レビュー Round 1 [Warning] を受けて
> 「commit 成否と通知が 1:1」という言い切りから best-effort な表現へ修正)

- rollback (例外) 時は `discard()` が呼ばれ、collector は空になってから例外が再送出される
- `flush()` は実行前に保持配列を空へ移すため、二重呼び出しで再実行されない
- 本 middleware の transaction 呼び出しの「正常終了」は、それが最外 transaction である
  production の Web 経路でのみ物理 commit を意味する。`RefreshDatabase` 下のテストでは
  外側 transaction が既に開いているため、flush は物理 commit 前に起きる (誇張しない)

### 保証しないもの

- **transaction 呼び出しの正常終了後、通知投入が実際に成功すること** — flush 前のプロセス
  終了・queue 投入失敗・`AuthMethodChangeNotifier::notify()` 内の例外吸収により、
  通知が届かないことがある (best-effort)
- **1 件目のコールバックが例外を投げた場合、後続のコールバックは実行されない**
  (`foreach` の通常の挙動。現在の利用者は例外を内部で吸収するため実害は無いが、
  本機構自体はそれを保証しない)
- **queue worker からの利用は想定していない** (`scoped()` は HTTP リクエスト間・queue job
  間で共有しない仕組みであり、本機構の利用対象は HTTP middleware だけ。
  `App\Support\CriticalActionContext` と同じ前提)

### 関連

- 実装: `app/Http/Middleware/EnsureLoginMethodRemains.php`,
  `app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php`
- 設計: `devnotes/20260821-2015-auth-method-change-notification/`
```

### テスト計画

- [ ] `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が green (9 行の書式・
      件数 3 点一致)
- [ ] `tests/Architecture/TemplateDivergenceFingerprintTest.php` が green
      (`mutatedDebtPaths` に本パスが含まれないこと = 債務一覧から正しく外れたこと)

---

## 施策 7: 運用ドキュメント新設

`docs/architecture.md` の末尾に以下のセクションを追記する (既存の章立てスタイルに倣う)。

```markdown
## 認証手段変更のメール通知ポリシー (T110)

パスワード設定・変更・リセット / 2FA 有効化・無効化・回復コード再発行 / パスキー追加・削除 /
SSO 連携 (合計 9 種。`App\Enums\Auth\AuthMethodChangeEvent`) が起きたとき、本人の登録メール
アドレスへ「何が変わったか・いつ変わったか・心当たりが無い場合の対処」を通知する。
オーナー裁定 (2026-08-21「方針は任せる。一般的なものに倣う」) に基づく。

- **対象外 (スコープ外)**: ログインのたびの通知 / アプリ内通知センターへの複製 /
  管理者向け通知。既存の監査ログ (T108 S7) は変えない。組織管理者によるメンバー 2FA 解除
  (`TwoFactorResetSecurityNotification`) はこのポリシーが統一する対象ではない (加害者側ではなく
  組織管理者が正規に行う操作で読者・文脈が異なる別ポリシー)。メールアドレス変更の通知
  (`EmailChangedSecurityNotification`。T031/T211 系) も実装は変更しない。
- **窓口**: 発火は `App\Services\Security\AuthMethodChangeNotifier` (`notify()` =
  transaction 外で直ちに queue へジョブを投入する best-effort 版。queue 投入自体の失敗は
  `report()` して認証操作を巻き込まない) の 1 経路に統一する。呼び出し元は
  `App\Listeners\Auth\NotifyAuthMethodChange` (vendor イベント購読) と、イベント化されていない
  `App\Services\Auth\PasswordCredentialService` / `App\Services\Auth\SocialAccountService`
  の直接呼び出しの 2 種類。

### 発火点対応表

| イベント (`AuthMethodChangeEvent`) | 発火元 | transaction 内か | 発火方法 |
|---|---|---|---|
| `PasswordSet` / `PasswordChanged` | `PasswordCredentialService::afterPersist()` | 否 | `notify()` |
| `PasswordReset` | `Illuminate\Auth\Events\PasswordReset` | 否 | `notify()` |
| `TwoFactorEnabled` | Fortify `TwoFactorAuthenticationConfirmed` | 否 | `notify()` |
| `TwoFactorDisabled` | Fortify `TwoFactorAuthenticationDisabled` | 否 | `notify()` |
| `RecoveryCodesRegenerated` | Fortify `RecoveryCodesGenerated` | 否 | `notify()` |
| `PasskeyRegistered` | Laravel Passkeys `PasskeyRegistered` | 否 | `notify()` |
| `PasskeyDeleted` | Laravel Passkeys `PasskeyDeleted` | **是** (`EnsureLoginMethodRemains` が課す) | `notifyAfterCommit()` |
| `SocialAccountLinked` | `SocialAccountService::linkToUser()` (`register()` 内部の初回連携では発火しない) | 否 | `notify()` |

`notify()` は「transaction 外で直ちに queue へジョブを投入する」ことを指す。実際のメール
配送は worker が非同期に行う。`notifyAfterCommit()` は
`App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` へ予約し、
`EnsureLoginMethodRemains` の transaction 呼び出しが正常終了した後にだけ `notify()` を呼ぶ
(rollback 時は発火しない。best-effort 契約であり「正常終了後に必ず届く」ことまでは保証しない。
詳細は同クラスと `EnsureLoginMethodRemains` の docblock)。

### 保証しないもの (誇張しない)

- **配信先は送信時点の現在の登録メールアドレス** であり、操作時点のアドレスのスナップショット
  ではない (queued notification が worker 実行時に User を再取得するため)
- SSO の「解除」機能は本設計時点でアプリに実装されていない。実装されたときは
  `AuthMethodChangeEvent` へ case を追加し本ポリシーへ含めること (先回りして作らない)
- **queue 投入の成功、およびメールの実配送成功は保証しない**。本ポリシーの責務は
  「queue へジョブの投入を best-effort で試行するところまで」であり、
  `AuthMethodChangeNotifier::notify()` は投入時の例外を `report()` して吸収するため、
  投入成功そのものも保証範囲ではない (Codex 詳細設計レビュー Round 1 [Warning] を受けて
  「投入成功までが保証範囲」という言い切りから修正)。配送成功は既存の mailer driver
  設定・SES バウンス処理等の一般的な配送信頼性の枠内に委ねる
- 詳細設計は `devnotes/20260821-2015-auth-method-change-notification/`
```

### 波及変更

なし (ドキュメントのみ)。

### テスト計画

該当なし (ドキュメント)。`docs/architecture.md` は機械検証の対象外。

---

## 施策 8: テスト

新設 `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` (イベント→enum 対応の
Feature テスト) に加え、次の Unit テストファイルも新設する
(Pest。Factory 経由でテストデータ生成。個別 `DatabaseTransactions` は使わない)。

### 波及変更

- Unit テスト (新設): `tests/Unit/Enums/Auth/AuthMethodChangeEventTest.php`
  (`headline()` の全 case 確認)、`tests/Unit/Notifications/Auth/
  AuthMethodChangedNotificationTest.php` (`ShouldQueue` 実装・getter 確認)、
  `tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php`
  (`start()`/`push()`/`flush()`/`discard()` の状態遷移) — 施策 1・2 の PHPStan 適合チェック・
  テスト計画で個別に挙げていたものをここへ集約 (Codex 詳細設計レビュー Round 1 [Warning]
  「波及変更ファイル一覧に Unit テストファイルが無い」への対応)

### テスト方針の役割分担・レーン分離 (Codex 詳細設計レビュー Round 1 [Warning] への対応)

`Notification::fake()` は実際の queued dispatch を止めるため、`jobs` テーブルの件数検証とは
同一テスト内で両立しない。レーンを分ける。

1. **イベント → enum 対応の正しさ**: `Notification::fake()->assertSentTo($user,
   AuthMethodChangedNotification::class, fn ($n) => $n->event() === ...)` を使うテスト群。
   このレーンでは `jobs` テーブルは見ない
2. **`ShouldQueue` 実装であることの確認**: Unit テスト (instanceof)
3. **queue 投入失敗 (enqueue 失敗) が元操作へ波及しないこと**: 主張を 2 段に分ける —
   (a) Unit テストでは `AuthMethodChangeNotifier::notify()` 単体が通知送信の例外を吸収する
   ことだけを確認する (`Notification` の送信をモックして例外を強制)。(b) 実際のパスワード
   変更等の Feature テストで dispatcher を例外化し、DB 変更 (パスワード更新自体) が確定し
   応答が成功で返ることを別途確認する。単体テストの「例外が伝播しない」だけでは (b) の
   要求 (実際の認証操作が成功する) を証明しないため分離する
4. **queue 投入件数の確認**: `Notification::fake()` を使わないテストで検証する。
   `Queue::fake()` で `Illuminate\Notifications\SendQueuedNotifications` job の投入を検査する
   か、database queue driver を局所設定した Feature テストで `jobs` テーブルを見る
   (総件数ではなく、対象 `AuthMethodChangedNotification` を積む job であることまで確認する)

### テストファーストの赤確認 (実装前に確認する失敗)

実装に着手する前に、少なくとも次の失敗を確認してから直す (Codex 詳細設計レビュー Round 1
[Warning] への対応。「テストなしの実装完了報告をしない」の実践)。

- [ ] 各実経路 (パスワード変更・2FA・パスキー・SSO 連携) で通知が 0 件になる失敗
- [ ] rollback (パスキー削除後の同期処理例外) 後に `LoginMethodRemovalPostCommitCallbacks` に
      コールバックが残ってしまう失敗、またはそれが誤って実行されてしまう失敗
- [ ] `AuthMethodChangedNotification` が施策 5 の 2 つの deny-by-default gate で
      未登録として検出され赤くなる失敗

### テストケース一覧

- [ ] `PUT /user/password` (current_password 必須の変更) → `PasswordChanged` 通知 1 件
- [ ] `POST /settings/password` (初回設定・recent-auth 必須) → `PasswordSet` 通知 1 件
- [ ] forgot-password フロー (`POST /forgot-password` → `POST /reset-password`) →
      `PasswordReset` 通知 1 件
- [ ] `POST /user/confirmed-two-factor-authentication` → `TwoFactorEnabled` 通知 1 件
- [ ] `DELETE /user/two-factor-authentication` → `TwoFactorDisabled` 通知 1 件
- [ ] `POST /user/two-factor-recovery-codes` → `RecoveryCodesRegenerated` 通知 1 件
      (2FA 有効化直後の自動生成では発火しないことも確認: vendor `EnableTwoFactorAuthentication`
      は `RecoveryCodesGenerated` を dispatch しないため、有効化 1 操作からの通知は
      `TwoFactorEnabled` の 1 通のみであることを固定する)
- [ ] `POST /user/passkeys` (登録) → `PasskeyRegistered` 通知 1 件
- [ ] `DELETE /user/passkeys/{passkey}` (複数手段が残る場合の削除成功) →
      `PasskeyDeleted` 通知 1 件 (jobs テーブルで確認)
- [ ] `DELETE /user/passkeys/{passkey}` (唯一のログイン手段。422/302 で reject) →
      通知 0 件
- [ ] `GET /auth/{provider}/callback` (intent=link。既存ログイン中ユーザーへの追加連携) →
      `SocialAccountLinked` 通知 1 件、`context` に provider 表示名
- [ ] `GET /auth/{provider}/callback` (intent=register。新規 SSO 登録) → 通知 0 件
      (監査記録 `SecurityEventType::SocialAccountLinked` は従来どおり記録されることも確認)
- [ ] `DELETE /user/passkeys/{passkey}` (削除成功後、後続の同期処理が例外を投げる) →
      passkey 削除自体が rollback される → 通知 job が 0 件 → 同じ request 内で後から
      `flush()` を呼んでも通知されない (5 段の rollback 統合テスト。施策 2・6 と共通。
      Codex 詳細設計レビュー Round 1 [Warning] への対応で新規に追加)
- [ ] (Unit) `AuthMethodChangeNotifier::notify()` が通知送信例外を吸収して `report()` し、
      戻り値が `void` のまま呼び出し元へ例外が伝播しないことの確認 (`Notification` の
      `send` をモックして例外を強制。「Notifier 単体が例外を吸収する」ことだけを主張する)
- [ ] (Feature) パスワード変更経路で通知の queue 投入自体を例外化しても、パスワードは
      更新済みで応答が成功で返ることの確認 (上記 Unit テストとは別に、実際の認証操作が
      通知失敗の影響を受けないことを実経路で固定する)
- [ ] (Unit) `LoginMethodRemovalPostCommitCallbacks` の `start()`/`push()`/`flush()`/
      `discard()` の状態遷移テスト (施策 2 の状態遷移表どおり) — 2 重 `flush()` 安全性・
      `discard()` 後の非実行・`start()` を呼ばない `push()` が `LogicException` になること・
      active 中の二重 `start()` が `LogicException` になり先に積んだ callback を消さないこと・
      `flush()`/`discard()` 後に再度 `start()` できること・inactive 状態の `discard()`
      (二重呼び出しを含む) が no-op であること

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `EnsureLoginMethodRemains` (共有 middleware) とテンプレート差分台帳の同時更新を
  含み、他の並行実装と同じ PR 内でコンフリクトしやすいファイル (`AppServiceProvider.php`,
  `docs/template-divergence.md`, `tests/Support/TemplateDivergence/LedgerPins.php`) に触れる
  ため、個別セッションで一括実装するほうが安全 |
| 競合リスク | `AppServiceProvider.php` への Event::subscribe 追記は他の認証系施策と
  競合しやすい。`docs/template-divergence.md` の件数 pin は他の乖離登録と同時進行だと
  番号衝突しうる (登録直前に最新の D 番号を再確認すること) |

---

## 実装レビューの裁定 (監督セッション 2026-08-21)

### Critical (Codex 実装レビュー Round 1〜3、解消せず CHANGES_REQUESTED)

`App\Http\Middleware\EnsureLoginMethodRemains` のパスキー削除経路が採っていた設計
(施策 2: `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` という
transaction 呼び出し専用の post-commit callback collector を自作し、
`start()` で有効化 → transaction 呼び出しの正常終了後に `flush()` して
`AuthMethodChangeNotifier::notifyAfterCommit()` 経由で通知を queue へ投入する。
rollback (例外) 時は `discard()` して投入しない) は、
AGENTS.md ドメイン規約 11「キュー投入の原子性」(業務状態の保存とキュー投入は
**同一トランザクション内**で行い `afterCommit` に依存しない。`->afterCommit()` /
`ShouldQueueAfterCommit` 等の**列挙 API は 0 件 pin**・**免除機構を持たない**) と
字義上衝突する、として 3 ラウンドとも CHANGES_REQUESTED になった。

Codex の指摘の要旨 (Round 2 で確定):

1. 詳細設計レビュー Round 1 の既往 [Warning] は「commit と通知が 1:1 という過大な
   保証表現を best-effort へ絞る」表現の適正化であり、「規約 11 の対象からこの通知を
   除外してよいか」という規約適用の裁定そのものではなかった
2. 静的検査 (`QueueDispatchAtomicityInventoryTest`) が collector を検出しないのは
   検査の検出範囲 (列挙された特定 API の 0 件 pin) の外にあるだけであり、
   規約 11 の意味上の適用除外を意味しない。collector は名前も動作も
   post-transaction callback であり、既知 API を使わず同じ順序を自作したことは
   規約適合の根拠ではなく静的検査の盲点を示すにすぎない
3. `EnsureLoginMethodRemains` 既存契約 (transaction 内での外部 I/O・非 afterCommit
   queue dispatch 禁止) と規約 11 が衝突するなら、必要なのは規約 11 準拠パターンへの
   再設計・通知意図の同一トランザクションでの耐久化・規約 11 への正式な適用除外・
   設計不採用のいずれかであり、「transaction 内に置けないので transaction 後に自作
   callback で投入する」は衝突の片側だけを選んだ状態である

### 裁定: 選択肢 (a) — collector を撤去し、パスキー削除の通知も業務トランザクションの
内側で dispatch する

**出所**: 監督セッション (2026-08-21)。

**内容**: `LoginMethodRemovalPostCommitCallbacks` を撤去し、`EnsureLoginMethodRemains`
を collector 配線 (try/catch + start/flush/discard) が無い元の姿へ戻す。
`App\Listeners\Auth\NotifyAuthMethodChange::handlePasskeyDeleted()` は他の 7 イベントと
同じ `notify()` (その場で `$user->notify()` を呼ぶだけ) を呼ぶ。

**根拠**: AGENTS.md ドメイン規約 11 は原子性の前提として driver=database /
キュー DB 接続 = 業務 DB / after_commit=false を `QueueDispatchAtomicityGuard` が
**全環境の起動時**に fail-closed で強制している。この前提の下では、
業務トランザクションの内側から dispatch すれば queue の `jobs` 行がそのトランザクションに
**構造的に参加する**。したがって:

1. rollback すれば jobs 行ごと消える。「取り消された変更について誤って通知が届く」
   ケースが構造的に発生しない — これはまさに collector が実現したかった性質である
2. commit と同時に jobs 行が耐久化される。「業務状態の保存とキュー投入は同一
   トランザクション内で行う」という規約 11 を**字義どおり**満たす

`PasskeyDeleted` は `EnsureLoginMethodRemains` が課す transaction (ロック取得〜
controller〜同期購読〜レスポンス生成まで丸ごと) の内側で既に同期発火しているため
(`tests/Architecture/PasskeyPackageContractTest.php` が同期購読者の顔ぶれと順序を pin する)、
listener がその場で `notify()` を呼ぶだけで dispatch は自然に業務トランザクションの
内側に位置する。collector は「transaction 呼び出しの正常終了後にだけ実行する」という
`afterCommit` の意味論を**手作りで再現するもの**であり、規約 11 が禁止している構造
(状態の保存とキュー投入を分離し、後者を「呼び出しの正常終了後」という条件で遅延する)
そのものを自作 API で再現していた。これは規約の趣旨と衝突するのであって、
静的検査の検出範囲がたまたま及んでいなかっただけである。撤去は思考原則 2
(今必要なものだけ作る。オーバーエンジニアリング禁止) にも沿う。

**規約 11 の免除追加 (AGENTS.md 変更) は不要になる** — 免除ではなく、規約 11 が
要求する形そのもの (`Manual/AnalysisJobService::trigger()` 等、本リポジトリの
既存準拠パターンと同型) へ実装を合わせたことで解消したため。

**スコープ**: 本裁定が対象とするのはパスキー削除経路 (collector) のみである。
`PasswordCredentialService::afterPersist()` / `SocialAccountService::linkToUser()` の
「保存 (commit) の後で `notify()` を呼ぶ」構造は変更しない (本裁定のスコープ外)。
