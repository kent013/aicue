## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
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

## system

あなたはコードレビュアーです。Laravel + Svelte アプリ (AI-CUE) における
TODO T110「認証手段変更のメール通知ポリシーの統一設計」の実装差分をレビューしてください。

レビュー観点:
1. 詳細設計書 (下記) との一致性 — 施策 1〜8 が設計どおりに実装されているか
2. 正確性 — ロジックバグ・エッジケースの欠落
3. PHPStan level 10 適合性 (型の緩め・widen が無いか)
4. DTO / JsonResource パターン (該当する場合)
5. テスト網羅性 — 各施策に対応するテスト (正常系・異常系) があるか
6. セキュリティ — 秘密情報 (トークン・コード・パスキー識別子詳細) をメール本文へ漏らしていないか、
   CipherSweet 復号のタイミング、queue 投入の原子性 (AGENTS.md ドメイン規約 11: 業務状態の保存と
   キュー投入は同一トランザクション内、`afterCommit` に依存しない)
7. AGENTS.md のドメイン固有規約 (キューの重複実行と結果の一回性 = 規約 6、
   キュー投入の原子性 = 規約 11) との整合
8. best-effort 通知という設計方針 (二重配送・欠落を許容) が実装全体で一貫しているか
9. 本ラウンドで追加した 2 件の目録修正 (QUEUED_JOB_LEASE_INVENTORY への
   AuthMethodChangedNotification 登録、PasskeyPackageContractTest の同期購読者 pin を
   2→3 件へ更新) が、既存の deny-by-default 目録の趣旨に整合しているか

出力形式: ファイルごとに判定 (OK / 要修正)。指摘は Critical / Warning / Suggestion に分類。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 行で明記すること。

---

## user

## 詳細設計書
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

## 実装差分 (git diff HEAD -- app/ resources/ tests/ routes/)
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
index f9f423d0..106e89ce 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -21,6 +21,7 @@
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Notifications\Billing\PaymentFailedNotification;
+use App\Notifications\Auth\AuthMethodChangedNotification;
 use App\Notifications\Billing\RenewalReminderNotification;
 use App\Support\QueueDispatchAtomicityGuard;
 use Tests\Support\PhpTokenScan;
@@ -90,6 +91,7 @@
     PaymentFailedNotification::class => null,
     RenewalReminderNotification::class => null,
     AccountDeletionRequestedNotification::class => null,
+    AuthMethodChangedNotification::class => null,
 ];
 
 /**
diff --git a/tests/Feature/Auth/AuthMethodChangeNotificationTest.php b/tests/Feature/Auth/AuthMethodChangeNotificationTest.php
new file mode 100644
index 00000000..c4dd2c45
--- /dev/null
+++ b/tests/Feature/Auth/AuthMethodChangeNotificationTest.php
@@ -0,0 +1,310 @@
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
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Event;
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
index de4dcf2f..bfbacee4 100644
--- a/tests/Feature/Auth/PasskeyAuditTrailTest.php
+++ b/tests/Feature/Auth/PasskeyAuditTrailTest.php
@@ -6,6 +6,7 @@
 use App\Models\Passkey;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
+use App\Support\Auth\LoginMethodRemovalPostCommitCallbacks;
 use Laravel\Passkeys\Events\PasskeyDeleted;
 use Laravel\Passkeys\Events\PasskeyRegistered;
 
@@ -95,6 +96,11 @@ function passkeyAuditCount(SecurityEventType $type): int
     $user = passkeyAuditUser();
     $passkey = $user->passkeys()->firstOrFail();
 
+    // T110: NotifyAuthMethodChange も同じイベントを購読し notifyAfterCommit() を呼ぶため、
+    // EnsureLoginMethodRemains の transaction 外からの直接 dispatch では collector を
+    // 明示的に active化しておく必要がある (非アクティブ中の push() は LogicException)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->start();
+
     PasskeyDeleted::dispatch($user, $passkey);
 
     expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(1);
diff --git a/tests/Feature/Auth/PasskeyDeletionAtomicityTest.php b/tests/Feature/Auth/PasskeyDeletionAtomicityTest.php
index ef4cc993..db390586 100644
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
@@ -32,6 +33,12 @@
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
 
diff --git a/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php b/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
index 8a98156e..270cad98 100644
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
@@ -75,6 +76,11 @@
     $this->startSession();
     app(RecentAuthState::class)->confirm(method: 'password');
 
+    // T110: NotifyAuthMethodChange も同じイベントを購読し notifyAfterCommit() を呼ぶため、
+    // EnsureLoginMethodRemains の transaction 外からの直接 dispatch では collector を
+    // 明示的に active化しておく必要がある (非アクティブ中の push() は LogicException)。
+    app(LoginMethodRemovalPostCommitCallbacks::class)->start();
+
     PasskeyDeleted::dispatch($user, $passkey);
 
     expect(session()->has('recent_auth_at'))->toBeFalse();
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index a6a4e8b0..11dbfb9a 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 33;
+    public const int DIVERGENCE_ENTRY_COUNT = 35;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 174;
+    public const int ADOPTION_DEBT_COUNT = 172;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 2aafce07..ef9660de 100644
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
index 00000000..34e9b12b
--- /dev/null
+++ b/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php
@@ -0,0 +1,72 @@
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
diff --git a/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php b/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php
new file mode 100644
index 00000000..2c57a753
--- /dev/null
+++ b/tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php
@@ -0,0 +1,52 @@
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
+        fn ($n) => $n->event() === AuthMethodChangeEvent::PasskeyDeleted,
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

## テスト結果サマリー

- 修正前 (フルスイート): 6433 tests, 6428 passed, 3 failed。
  失敗は以下の 3 件 (うち 1 件は本タスクと無関係な別作業 T241 で main 側に修正済み):
  1. QueuedJobLeaseInventoryTest: AuthMethodChangedNotification が QUEUED_JOB_LEASE_INVENTORY に
     未登録 (本ラウンドで修正)
  2. RouteCacheExemptionPremiseTest: 別作業 (T241) の devnotes patch 文言問題。main 側で修正済み、
     本ラウンドで origin/main を merge して解消
  3. PasskeyPackageContractTest: パスキー削除イベントの同期購読者 pin が 2 件のままで、
     新設の NotifyAuthMethodChange が入って 3 件になったため不一致 (本ラウンドで修正)
- 修正後 (対象 2 ファイルのみ再実行): 30 tests, 30 passed
- フルスイート再実行は現在バックグラウンドで実行中 (完了後に EXIT=0 を別途確認する)
