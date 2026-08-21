【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則】
1. フレームワークのレンジ内でやる 2. 今必要なものだけ作る(オーバーエンジニアリング禁止)
3. 後方互換の並走を残さない 4. 別物の概念を「似ているから」で統合しない
5. テストファースト 6. タコツボ実装を避ける

【禁止事項 (抜粋)】
1. PHPStan level 10 の widen・baseline 化をしない
2. テストなしの実装完了報告をしない
3. dev DB への破壊操作をエージェント判断で実行しない
4. `response()->json()` の直書きをしない
7. 操作系 POST の応答で `redirect()->intended()` を使わない
8. 必須条件未充足を理由にボタンを disabled にする UI を作らない
9. Artifact ツールでの成果物公開を行わない

【思考原則・ツール使用制限 (本スキル規定)】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の
詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Fortify + Laravel Passkeys + Socialite
- PHPStan level 10 / Pest / RefreshDatabase グローバル適用 (`--parallel`)
- DTO + JsonResource パターン
- 本設計は概念設計フェーズで Codex (gpt-5.6-terra) と 5 ラウンドの合議を経て APPROVED 済み
  (`devnotes/20260821-2015-auth-method-change-notification/conceptual-design.md` および
  `codex-history/conceptual-review-round-{1..5}.md`)。特に「パスキー削除の通知だけ
  `EnsureLoginMethodRemains` の transaction の内側で発火する」問題への対処方式は、
  再クエリ方式 → `TransactionCommitted` 動的購読方式 → 最終的に「request-scoped collector +
  transaction 正常終了後の明示 flush / rollback 時の discard」方式、の順で 3 回作り直された
  経緯がある (今回の詳細設計はこの最終方式を実装レベルまで詳細化したもの)。

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性
4. テスト計画の網羅性
5. DTO/JsonResource パターンの遵守 (本設計は該当薄いが確認)
6. 副作用・後退リスク — 特に `EnsureLoginMethodRemains` への変更が既存の
   ロック順序・投影評価・reject/pass 分岐に影響しないか
7. 波及変更の網羅性 (既存 deny-by-default gate 2 つへの登録、テンプレート差分台帳の更新)
8. セキュリティ (認可チェック、CipherSweet の PII 制約、AGENTS.md のセキュリティ不変条件)
9. AGENTS.md ドメイン規約 11 (キュー投入の原子性。`DB::afterCommit()` 系 0 件 pin) との整合
10. AGENTS.md ドメイン規約 6/18 (JobExecutionDedupInventoryTest / JobDeferralTerminationGateTest
    への登録) の記載が正確か

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類。Critical/Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

(以下、detailed-design.md の全文)

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
 * `Illuminate\Queue\SerializesModels` (Queueable 経由) が worker 実行時に User を
 * ID から再取得するため、CipherSweet の復号も通常どおり働く。
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
     * transaction が commit した後に `notify()` を呼ぶよう予約する (rollback 時は
     * `LoginMethodRemovalPostCommitCallbacks::discard()` により実行されない)。
     */
    public function notifyAfterCommit(User $user, AuthMethodChangeEvent $event, ?string $context = null): void
    {
        $this->postCommitCallbacks->push(fn (): mixed => $this->notify($user, $event, $context));
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
  (rollback)」の 3 ケースを含める。既存の `tests/Architecture/LoginMethodRemovalRouteTest.php`
  等が変わらず green であることを確認する (本施策は `handle()` の中身だけを変更し、
  route への付与・ロック順序・投影評価のロジックは一切変えない)。

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
 * `App\Http\Middleware\EnsureLoginMethodRemains` が開く transaction の commit 後に
 * 実行するコールバックを溜める request-scoped collector (T110)。
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
 */
final class LoginMethodRemovalPostCommitCallbacks
{
    /** @var list<Closure(): void> */
    private array $callbacks = [];

    public function push(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * `EnsureLoginMethodRemains` の transaction が commit した後にだけ呼ぶこと。
     *
     * 実行前に保持配列を空へ移すため、2 回呼んでも 2 回目は何もしない。
     * **1 件目のコールバックが例外を投げれば後続は実行されない** (`foreach` の通常の挙動。
     * 保証を誇張しない)。現在の利用者 (`AuthMethodChangeNotifier::notify()`) は例外を
     * 内部で吸収するため実害はないが、本クラス自体はそれを保証しない。
     */
    public function flush(): void
    {
        $pending = $this->callbacks;
        $this->callbacks = [];

        foreach ($pending as $callback) {
            $callback();
        }
    }

    /** transaction が rollback したときに呼ぶこと。積んだコールバックを実行せずに破棄する。 */
    public function discard(): void
    {
        $this->callbacks = [];
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
追加したのは「transaction 呼び出しを try/catch で包み、結果に応じて `flush()` /
`discard()` を呼ぶ」という外側の 1 層だけである。`reject()` 分岐は例外を投げずに
正常な値を返すため transaction は commit するが、この分岐では `$next()` が呼ばれず
`PasskeyDeleted` も発火していないため、`flush()` は実質的に no-op になる。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`Response`)
- [x] null 安全 (該当なし)
- [x] DTO を返している (該当なし。middleware は Response を返す契約)
- [x] `Throwable` の import 漏れが無いこと (PHPStan level 10 で未解決クラス参照は検出される)

### テスト計画

- [ ] `EnsureLoginMethodRemains` を通る削除が成功したとき、`jobs` テーブルに通知ジョブが
      1 件積まれる (Feature テスト。実 route `DELETE /user/passkeys/{passkey}` を通す)
- [ ] 唯一のログイン手段のパスキーを削除しようとして 422/302 (reject) になったとき、
      通知ジョブが 0 件のまま (= flush が no-op)
- [ ] `flush()` を 2 回呼んでも 2 回目は何もしないことの Unit テスト
- [ ] `discard()` 後は `flush()` が何も実行しないことの Unit テスト
- [ ] 既存の `tests/Architecture/LoginMethodRemovalRouteTest.php` ほか
      `EnsureLoginMethodRemains` 関連の既存 Architecture/Feature テストが変わらず green

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
      1 件 queue に積まれる
- [ ] `change()` (パスワード変更) で `AuthMethodChangeEvent::PasswordChanged` の通知が 1 件
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

### 5-a. `tests/Architecture/JobExecutionDedupInventoryTest.php`

`jobDedupExemptions()` の配列へ 1 エントリ追加し、`jobDedupExemptionCap()` を **15→16**、
`jobDedupExemptionCapByCase()` の `DuplicateDeliveryAccepted` を **9→10** に更新する。

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
| 業務要件起因の説明 | 認証手段変更のメール通知 (T110) のうち、パスキー削除だけは本 middleware が課す transaction (ロック取得〜controller〜同期 listener〜レスポンス生成まで丸ごと) の内側で発火する。同 transaction の外部 I/O・非 afterCommit queue dispatch 禁止という既存契約 (本ファイルの docblock) と、AGENTS.md ドメイン規約 11 (`DB::afterCommit()` 系の 0 件 pin) の両方を満たしつつ「commit 成否と通知が 1:1」を実現するには、transaction 呼び出し側に「正常終了後にだけ実行する」明示的な分岐を持たせる必要がある |
| 揃え続ける不変条件と保証機構 | ロック取得順序 (User→credential)・投影評価の位置 (ロック取得後)・`$next()` を transaction 内で実行することは変更しない。追加したのは transaction 呼び出しを try/catch で包み、正常終了時は `LoginMethodRemovalPostCommitCallbacks::flush()`、例外 (rollback) 時は `discard()` を呼ぶ外側の 1 層だけ。既存 Architecture テスト (`LoginMethodRemovalRouteTest` 等) が変わらず green であることで揃え続ける |
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

> 「commit が成立した場合にのみ、積んだコールバックが実行される」

- rollback (例外) 時は `discard()` が呼ばれ、collector は空になってから例外が再送出される
- `flush()` は実行前に保持配列を空へ移すため、二重呼び出しで再実行されない

### 保証しないもの

- **1 件目のコールバックが例外を投げた場合、後続のコールバックは実行されない**
  (`foreach` の通常の挙動。現在の利用者は例外を内部で吸収するため実害は無いが、
  本機構自体はそれを保証しない)
- **queue worker からの利用は想定していない** (`scoped()` は HTTP request scope 限定。
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
`EnsureLoginMethodRemains` の transaction が commit した後にだけ `notify()` を呼ぶ
(rollback 時は発火しない。詳細は同クラスと `EnsureLoginMethodRemains` の docblock)。

### 保証しないもの (誇張しない)

- **配信先は送信時点の現在の登録メールアドレス** であり、操作時点のアドレスのスナップショット
  ではない (queued notification が worker 実行時に User を再取得するため)
- SSO の「解除」機能は本設計時点でアプリに実装されていない。実装されたときは
  `AuthMethodChangeEvent` へ case を追加し本ポリシーへ含めること (先回りして作らない)
- メールの実配送成功は保証しない (queue 投入の成功までが本ポリシーの保証範囲。既存の
  mailer driver 設定・SES バウンス処理等の一般的な配送信頼性の枠内)
- 詳細設計は `devnotes/20260821-2015-auth-method-change-notification/`
```

### 波及変更

なし (ドキュメントのみ)。

### テスト計画

該当なし (ドキュメント)。`docs/architecture.md` は機械検証の対象外。

---

## 施策 8: テスト

新設 `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` に以下を含める
(Pest。Factory 経由でテストデータ生成。個別 `DatabaseTransactions` は使わない)。

### テスト方針の役割分担 (Codex Round 2 Warning への対応を反映)

1. **イベント → enum 対応の正しさ**: `Notification::fake()->assertSentTo($user,
   AuthMethodChangedNotification::class, fn ($n) => ...)`
2. **`ShouldQueue` 実装であることの確認**: Unit テスト (instanceof)
3. **enqueue 失敗が元操作へ波及しないこと**: `AuthMethodChangeNotifier` を対象にした
   例外テスト (通知送信側で例外を強制発生させ、呼び出し元の認証操作が成功で返ることを確認)
4. **transaction 成否と投入件数の対応**: 実経路の Feature テストで `jobs` テーブルの件数
   (または `Queue::fake()`) を直接検証する

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
- [ ] `AuthMethodChangeNotifier::notify()` が通知送信例外を吸収し、呼び出し元の認証操作が
      成功で返ることの確認 (`Notification` の `send` をモックして例外を強制)
- [ ] `LoginMethodRemovalPostCommitCallbacks::flush()` / `discard()` の Unit テスト
      (2 重呼び出し安全性・discard 後の非実行)

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

## 関連する現行コード (変更対象ファイルの全文)

### app/Http/Middleware/EnsureLoginMethodRemains.php (現行)
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\DataTransferObjects\Auth\LoginMethodRequiredDto;
use App\Http\Resources\Auth\LoginMethodRequiredResource;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\LoginMethodInventory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する関門。
 * alias: `ensure-login-method`。
 *
 * **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。
 * 素朴に現在を数えると削除対象自身が残存手段として数えられ、
 * 「唯一の passkey を削除できてしまう」= 意図と正反対の挙動になる。
 *
 * **直列化規約 (TOCTOU 対策)**:
 *   投影が正しくても、確認と削除が別トランザクションなら破れる
 *   (passkey 2 件のユーザーが別々の passkey を同時削除 → 両方が「もう片方が残る」と判定 → 0 件)。
 *   そこで本 middleware が
 *     (1) DB::transaction() を開き
 *     (2) 対象 User 行を lockForUpdate() で取得し
 *     (3) **ロック取得後に** 投影を評価し
 *     (4) **同一トランザクション内で $next() を実行**して vendor の削除まで完了させる。
 *   ロック取得順序は User → credential に固定する。
 *   本アプリのドメイン固有規約 1「シナリオ整合の共有ロック規約」と同型の作法。
 *
 * **単一の直列化点であること**が不変条件であり、
 * tests/Architecture/LoginMethodRemovalRouteTest が deny-by-default で強制する
 * (付与漏れだけでなく **allowlist 外 route への付与**も fail させる)。
 *
 * ⚠ **適用条件 (この middleware を新しい route に付ける前に必ず読むこと)**:
 *   `$next()` を transaction 内で実行するため、controller だけでなく
 *   **同期 event listener / Responsable 変換 / redirect + flash** まで transaction に入る。
 *   したがって次を含む route には付けてはならない:
 *     - streamed / downloadable response (transaction を長時間保持する)
 *     - 外部 I/O (HTTP・S3 等。ロック保持中に外部レイテンシを持ち込む)
 *     - `afterCommit` でない queue dispatch (ロールバック時に job だけ残る)
 *   これらが必要な route を保護する場合は、本 middleware の transaction 方式を
 *   「Service 内 transaction + 判定の再評価」へ再設計すること。
 */
final class EnsureLoginMethodRemains
{
    public function __construct(
        private readonly LoginMethodInventory $inventory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->pass($next, $request);   // 未認証は auth middleware の責務
        }

        return DB::transaction(function () use ($request, $next, $user): Response {
            // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // (3) ロック取得後に投影を評価する
            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));

            if ($remaining->isEmpty()) {
                return $this->reject($request);
            }

            // (4) 同一トランザクション内で削除まで完了させる
            return $this->pass($next, $request);
        });
    }

    /**
     * route から「今から何を除去しようとしているか」を決める。
     *
     * 対象 passkey が当該 User に属することは **binder が 404 で確定済み**
     * (App\Http\Routing\SelfScopedPasskeyBinder)。DTO 側でも二重に assert する。
     */
    private function removalFor(Request $request, User $user): LoginMethodRemoval
    {
        $passkey = $request->route('passkey');
        if ($passkey instanceof Passkey) {
            return LoginMethodRemoval::passkey($passkey, $user);
        }

        // 将来の除去 route (password 削除 / SSO 解除) はここに分岐を足す。
        // 未知の除去 route を素通しさせないため fail-closed で落とす
        // (LoginMethodRemovalRouteTest が「middleware を付けたのに分岐が無い」を先に検出する)。
        throw new LogicException(
            'EnsureLoginMethodRemains: 除去対象を決定できない route です。removalFor() に分岐を追加してください。',
        );
    }

    /**
     * 拒否応答。
     *
     * **Inertia には 422 JSON を返さない** (Inertia protocol 違反になり、
     * router が応答を解釈できず無言失敗する)。Inertia は 302 + errors を native に
     * 処理するため `back()->withErrors()` にして Svelte 側は `$page.props.errors` で読む。
     * 禁止事項 7 (操作系 POST は `back()->with(...)` で完結) とも整合する。
     *
     * 判別子に `expectsJson()` を使えるのは、Inertia が
     * `Accept: text/html, application/xhtml+xml` を送るため (X-Inertia は立つが Accept は HTML)。
     * 純粋な XHR (fetch + Accept: application/json) のみ 422 JSON になる。
     */
    private function reject(Request $request): Response
    {
        // settingsUrl は持たせない (削除済み)。理由:
        // - Inertia 経路は back()->withErrors() で message しか運ばず、URL はどのクライアントも消費していない
        // - 指していた settings.security にはパスワード設定 UI が無く、フロントの遷移先 (/settings) とも
        //   食い違っていた (phantom 契約)。踏破可能な CTA は画面側 (PasskeySection → /settings) が持つ
        $dto = new LoginMethodRequiredDto(
            message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
        );

        if ($request->expectsJson()) {
            return LoginMethodRequiredResource::make($dto)
                ->response()
                ->setStatusCode(422)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return back()->withErrors(['login_method' => $dto->message]);
    }

    /**
     * @param  Closure(Request): mixed  $next
     */
    private function pass(Closure $next, Request $request): Response
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }
}
```

### app/Services/Auth/PasswordCredentialService.php (現行)
```php
<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * users.password の確定 (設定 / 変更) の単一窓口。
 *
 * 「確定後に何が起きるか」(監査記録・他デバイス失効) を 1 箇所に集約する。
 * 2 経路 (Fortify の変更 / 初回設定) に別々に書くと、片方だけ劣化する
 * (= 他デバイスのセッションが残る等のセキュリティ後退) ため統合する。
 *
 * **transaction 境界の設計**: transaction に入れるのは
 * 「ロック取得 → 前提の再確認 → password の保存」だけ。
 * best-effort な副作用 (監査記録 / DB session 行削除) は **commit 後**に実行する。
 * PostgreSQL は transaction 内で失敗した文があると以降 aborted 状態になり、
 * アプリ側で catch しても commit できない — best-effort のつもりの副作用が
 * 主処理 (パスワード保存) を巻き添えにする。既存 UpdateUserPassword もこれらを
 * transaction 外で行っており、その性質を保つ。
 */
final class PasswordCredentialService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * 初回設定 (current_password 不要)。
     *
     * 呼び出し側の契約: **recent-auth (step-up) 済みであること** (route の middleware で強制)。
     * password 設定済みユーザーの迂回は fail-closed で拒否する
     * (current_password 必須の変更経路を骨抜きにしない)。
     *
     * @throws ValidationException
     */
    public function setInitial(User $user, string $plain): void
    {
        // transaction は「ロック → 再確認 → 保存」だけ (副作用は commit 後)
        $hash = DB::transaction(function () use ($user, $plain): string {
            // 同時 2 リクエストで両方が「未設定」と判定するのを防ぐ (TOCTOU)。
            // ロック取得順序は User 単位 (EnsureLoginMethodRemains と同型の作法)。
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasPassword()) {
                throw ValidationException::withMessages([
                    'password' => 'すでにパスワードが設定されています。パスワード変更フォームから変更してください。',
                ]);
            }

            $hash = Hash::make($plain);
            $locked->forceFill(['password' => $hash])->save();

            return $hash;
        });

        // **呼び出し元が持つインスタンス (= guard が保持している認証済み User) にも反映する**。
        // 保存したのはロック取得のために引き直した別インスタンスであり、これを怠ると
        // Auth::logoutOtherDevices() が guard 上の古い hash と照合して
        // InvalidArgumentException を投げる (パスワードは保存済みなのに 500 になる)。
        // 既に永続化済みなので dirty 扱いにはしない。
        $user->forceFill(['password' => $hash])->syncOriginalAttribute('password');

        $this->afterPersist($user, $plain, SecurityEventType::PasswordSet);
    }

    /**
     * 変更 (current_password の検証は Fortify 契約側 UpdateUserPassword が行う)。
     * 単一 UPDATE のため transaction は開かない (既存挙動を変えない)。
     */
    public function change(User $user, string $plain): void
    {
        $user->forceFill(['password' => Hash::make($plain)])->save();

        $this->afterPersist($user, $plain, SecurityEventType::PasswordChanged);
    }

    /**
     * 保存 **commit 後**の副作用: 監査記録 → 他デバイス失効 → DB session 行削除。
     * transaction 内では実行しない (上記の PostgreSQL 事情)。
     * best-effort なのは **監査記録と DB session 行削除**の 2 つ (どちらも内部で例外を握る)。
     * `Auth::logoutOtherDevices()` は例外を捕捉しない (失敗は 500 として表面化させる。
     * 他デバイス失効は correctness 側の要求であり、既存 UpdateUserPassword の挙動を維持する)。
     */
    private function afterPersist(User $user, string $plain, SecurityEventType $event): void
    {
        // 「そのユーザーが自分でパスワードを設定/変更したか」の監査証跡。
        // 記録失敗は report のみ (SecurityEventRecorder が内包する)。
        $this->recorder->record($event, $user);

        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
        // 渡すのは current_password ではなく保存直後の新 password。
        Auth::logoutOtherDevices($plain);

        $this->deleteOtherSessionRecords($user);
    }

    /**
     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
     *
     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
     * (パスワードの確定自体は成功しているため正常応答を維持する)。
     */
    private function deleteOtherSessionRecords(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
        if (! session()->isStarted()) {
            return;
        }

        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        Assert::nullOrString($connection);
        Assert::string($table);

        try {
            DB::connection($connection)
                ->table($table)
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', session()->getId())
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
```

### app/Services/Auth/SocialAccountService.php (現行)
```php
<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\SecurityEventType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\EmailTrust\EmailTrustPolicyResolver;
use App\Services\Organization\OrganizationProvisioningService;
use App\Services\Security\SecurityEventRecorder;
use App\Support\Legal\LegalConsent;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * SSO (Socialite) の callback 処理。intent (login / register / link) ごとに
 * SocialAccount の解決・作成を行う。
 *
 * - login: 連携済みアカウントがあればその User を返す。なければ null (登録誘導)
 * - register: 連携済みならその User (ログイン扱い)。未連携ならメール一致ユーザーへの
 *   自動リンクはせず (アカウント乗っ取り防止)、新規 User + SocialAccount を作成
 * - link: ログイン中ユーザーに連携を追加 (他ユーザーに連携済みなら拒否)
 */
class SocialAccountService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly OrganizationProvisioningService $provisioning,
        private readonly EmailTrustPolicyResolver $emailTrust,
    ) {}

    public function findLinkedUser(string $provider, SocialiteUser $socialiteUser): ?User
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        return $account?->user;
    }

    /**
     * SSO 登録。利用規約同意の証跡は呼び出し側 (controller) が検証済みの前提。
     */
    public function register(string $provider, SocialiteUser $socialiteUser): User
    {
        return DB::transaction(function () use ($provider, $socialiteUser): User {
            $email = $socialiteUser->getEmail();
            if (! is_string($email) || $email === '') {
                throw new \RuntimeException('SSO プロバイダから email が取得できませんでした');
            }

            // IdP が email 所有を検証している provider のみ検証済みとして扱う
            // (nOAuth 対策の継ぎ目)。宣言は config('template.social_providers.*.email_trust') で、
            // 未宣言は Unconfirmed に倒れる (fail-closed)。google は confirmed 宣言のため
            // 従来どおり email_verified_at が立つ (挙動不変)。
            $verifiedAt = $this->emailTrust->for($provider)->trustsEmail($socialiteUser)
                ? now()
                : null;

            // SSO 登録は password を **持たない** (null のまま)。
            // users.password は nullable であり、password 経路の可否は User::hasPassword() が
            // fail-closed で判定する契約 (0001_01_01_000000_create_users_table.php)。
            // ランダム値 (旧 Str::password(32)) を入れると hasPassword() が常に true になり、
            // recent-auth の passwordSet と EnsureLoginMethodRemains の双方が形骸化する。
            // **前方修正のみ**: 既存 SSO ユーザーの phantom password は遡及是正しない
            // (password 登録後に SSO 連携したユーザーの実パスワード消失リスクのため)。
            // → docs/template-divergence.md D13。
            $user = (new User([
                'name' => $socialiteUser->getName() ?? $email,
                'email' => $email,
            ]))->forceFill([
                'terms_accepted_at' => now(),
                'consent_version' => LegalConsent::version(),
                'email_verified_at' => $verifiedAt,
            ]);
            $user->save();

            $this->link($provider, $socialiteUser, $user);

            $this->provisioning->provisionPersonalOrganization($user);

            return $user;
        });
    }

    /**
     * 連携追加。既に他ユーザーに連携済みの場合は false を返す。
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

        return true;
    }

    private function link(string $provider, SocialiteUser $socialiteUser, User $user): void
    {
        $account = new SocialAccount([
            'provider' => $provider,
            'provider_user_id' => (string) $socialiteUser->getId(),
        ]);
        $account->user()->associate($user);
        $account->save();

        $this->recorder->record(SecurityEventType::SocialAccountLinked, $user, [
            'provider' => $provider,
        ]);
    }
}
```

### app/Listeners/RecordSecurityEvent.php (参照実装)
```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * 認証系イベント → security_audit_events の記録 (subscriber)。
 * EventServiceProvider ではなく Event::subscribe で明示登録する。
 */
class RecordSecurityEvent
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(PasswordReset::class, [self::class, 'handlePasswordReset']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
        $events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
        $events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);
    }

    public function handleLogin(Login $event): void
    {
        $this->recorder->record(SecurityEventType::Login, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        // user が特定できた失敗のみ記録する (email 列挙の助けになる平文 email は残さない)
        $this->recorder->record(SecurityEventType::LoginFailed, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        $this->recorder->record(SecurityEventType::Logout, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->recorder->record(SecurityEventType::PasswordReset, $this->asUser($event->user));
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorEnabled, $this->asUser($event->user));
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorDisabled, $this->asUser($event->user));
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorEnabled, $this->asUser($event->user), [
            'action' => 'recovery_codes_generated',
        ]);
    }

    /**
     * パスキーは単独でログインできる強い資格のため、増減は監査上最重要事象として記録する
     * (セッション乗っ取り後の永続化を事後追跡できるようにする)。
     * credential 本体 (公開鍵 / signature counter) は metadata に載せない。
     */
    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->recorder->record(SecurityEventType::PasskeyRegistered, $this->asUser($event->user), [
            'passkey_id' => $event->passkey->getKey(),
        ]);
    }

    /**
     * 削除は EnsureLoginMethodRemains の transaction 内で発火するため、
     * rollback 時は監査行も消える (削除自体も消えるので整合。テストで固定済み)。
     *
     * 注記: SecurityEventRecorder は Throwable を catch して report() するが、
     * pgsql では transaction 内の失敗文が transaction 全体を abort させるため
     * 「catch したのに後続 SQL が全部落ちる」経路が理論上ある。これは既存の全 recorder
     * 呼び出しに共通する性質であり、本 handler で新設したものではない。
     */
    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->recorder->record(SecurityEventType::PasskeyDeleted, $this->asUser($event->user), [
            'passkey_id' => $event->passkey->getKey(),
        ]);
    }

    private function asUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
```

### app/Support/CriticalActionContext.php (scoped bind の参照実装)
```php
<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * 管理パネルの Critical Action 実行中だけ active になる per-request scope の context。
 *
 * `RejectNonCriticalAudit` listener がこの context を参照し、active なときだけ
 * `model_audits` への記録を通す (= 「critical 操作中のみ audit を記録」の gating)。
 *
 * ルール:
 *  1. ServiceProvider で `$this->app->scoped()` バインドし HTTP request scope 限定
 *  2. activate() / deactivate() は必ずペアリングする (推奨は run() scope API)
 *  3. queue worker は別 container で起動するため context は継承されない
 *     (queue 経由の critical mutation はこの基盤では audit されない)
 *  4. **ネスト activate() 禁止**: 単一スロット型のためネスト呼び出しは親 context を
 *     誤って解除する原因になる。active 状態で activate() を再呼び出しすると
 *     LogicException で fail-fast する
 */
final class CriticalActionContext
{
    private bool $active = false;

    private ?string $actionName = null;

    private ?string $reason = null;

    private ?string $ticketId = null;

    private ?string $adminActionId = null;

    /**
     * 低水準 API: context を active 状態にする。
     *
     * **新規 caller は `run()` scope API を使うこと**。手動の
     * `try { activate(); ... } finally { deactivate(); }` は activate() が
     * LogicException を投げた場合に外側 context を誤解除する pairing バグになる。
     * `run()` は構造的にそれを防ぐ。
     *
     * @see self::run() — 推奨経路
     */
    public function activate(
        string $actionName,
        string $reason,
        ?string $adminActionId = null,
        ?string $ticketId = null,
    ): void {
        if ($this->active) {
            throw new LogicException(sprintf(
                'CriticalActionContext::activate() called while already active (current actionName=%s, new actionName=%s). '
                .'Nested activation is not supported. Use try/finally pairing.',
                (string) $this->actionName,
                $actionName,
            ));
        }

        $this->active = true;
        $this->actionName = $actionName;
        $this->reason = $reason;
        $this->adminActionId = $adminActionId;
        $this->ticketId = $ticketId;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->actionName = null;
        $this->reason = null;
        $this->ticketId = null;
        $this->adminActionId = null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function actionName(): ?string
    {
        return $this->actionName;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function ticketId(): ?string
    {
        return $this->ticketId;
    }

    public function adminActionId(): ?string
    {
        return $this->adminActionId;
    }

    /**
     * scope API: callback の前後で activate / deactivate を pairing する。
     *
     * `try { activate(); ... } finally { deactivate(); }` の手書きは、activate() が
     * ネスト検知で LogicException を投げたとき finally 内 deactivate() が
     * **外側 context を誤って解除**する pairing バグになる。
     *
     * run() は activate() 成功後にのみ finally-deactivate に入るため、activate()
     * 失敗時は外側 context が無事に維持される。Critical Action の実装は必ず
     * この run() を介して書くこと。
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function run(
        string $actionName,
        string $reason,
        callable $callback,
        ?string $adminActionId = null,
        ?string $ticketId = null,
    ): mixed {
        $this->activate(
            actionName: $actionName,
            reason: $reason,
            adminActionId: $adminActionId,
            ticketId: $ticketId,
        );

        // activate() が LogicException で抜けた場合はここに到達しない → 外側 context は無事。
        try {
            return $callback();
        } finally {
            $this->deactivate();
        }
    }
}
```

