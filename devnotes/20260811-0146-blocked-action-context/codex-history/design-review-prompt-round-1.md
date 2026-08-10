# 前提 (アプリの使命・禁止事項・思考原則・ツール使用制限)

## アプリの使命 (AGENTS.md より転記)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 (AGENTS.md より転記)

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC
- 使命・禁止事項・思考原則は本プロンプト冒頭に転記済み。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest テスト、RefreshDatabase グローバル適用、Factory 使用）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テスト）
9. セキュリティ（認可チェック、AGENTS.md のセキュリティ不変条件。特に「救済 route を 2FA 必須ゲートに通す」判断の是非）
10. DESIGN.md 準拠 / 11. Atomic Design 準拠（本設計はフロント差分ゼロと主張している。その主張の妥当性も見ること）

【この設計固有の争点 — 必ず判定すること】
- 施策 1 (allowlist 追加) にセキュリティ上の後退がないか。見落とした攻撃面はないか。
- 施策 2 (非安全メソッドに「実行されていません」を付ける) は過剰か、それとも不足か。
- 施策 3 の Architecture gate の母集団定義 (自前 namespace 全件 + vendor 名指し 3 本) は妥当か。
  検査 1〜7 と mutation M1〜M8 は本当に赤くなるか (空振りしないか)。
- 「保証しないもの」の記述に誇張・抜けはないか。
- 既存テスト 1 本の期待値更新が禁止事項 3 (既存テストの削除・上書き) に抵触しないか。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: blocked-action-context (遮断時のメッセージに元操作の文脈を持たせる)

> 対象: bug-hunt run `20260811-003230` の **F-4-01 (High)**
> 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex 概念レビュー Round 1 で APPROVED)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。**RefreshDatabase は `tests/Pest.php` でグローバル適用**、`--parallel` 実行。
  個別 `DatabaseTransactions` は使わない
- **テストデータは必ず Factory** (`Model::create()` 手組み禁止)
- **DTO + JsonResource** パターン / アーリーリターン / `declare(strict_types=1)` + 日本語コメント
- フォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 現行の事実確認 (実コードを読んで確認済み)

| 事実 | 確認元 |
|---|---|
| 取消 route は `settings.account.deletion-request.destroy` (DELETE)、step-up なし | `routes/web.php` L236-237 |
| 2FA ゲートの allowlist に取消は**無い** (現在 20 件) | `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` |
| 凍結 allowlist には**ある** (`DeletionRequestDestroy`) | `App\Enums\Account\AccountDeletionFreezeAllowance` |
| 2FA ゲートは凍結より**前**に走る | `bootstrap/app.php` priority list (`SecurityHeaders → RequireTwoFactor… → … → RequireActiveSubscription → EnsureAccountNotPendingDeletion`) |
| 取消 controller は**冪等 no-op** (未予約なら何もせず `back()`) | `OrganizationMembershipService::cancelAccountDeletion()` L711-732 |
| クライアントは 409 の `code` だけを見る (message 文字列に依存しない) | `resources/js/lib/recent-auth.ts` L139 / `tests/js/lib/recent-auth.test.ts` |
| 取消 route の resolved middleware は 16 本 (下表) | `devnotes/20260811-0146-blocked-action-context/probe-middleware.php` の実行結果 |

取消 route の resolve 済み middleware (priority 適用後の実測):

```
Illuminate\Cookie\Middleware\EncryptCookies
Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse
Illuminate\Session\Middleware\StartSession
Illuminate\View\Middleware\ShareErrorsFromSession
Illuminate\Foundation\Http\Middleware\PreventRequestForgery
Illuminate\Auth\Middleware\Authenticate                       ← 名指し母集団
Illuminate\Session\Middleware\AuthenticateSession             ← 名指し母集団
Illuminate\Routing\Middleware\SubstituteBindings
App\Http\Middleware\HandleInertiaRequests                     ← 母集団 (自前)
App\Http\Middleware\SecurityHeaders                           ← 母集団 (自前)
App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations  ← 母集団 (自前・本件の主役)
App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations ← 母集団 (自前)
App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages  ← 母集団 (自前)
Inertia\Middleware\EncryptHistory
Illuminate\Auth\Middleware\EnsureEmailIsVerified              ← 名指し母集団
App\Http\Middleware\EnsureAccountNotPendingDeletion           ← 母集団 (自前)
```

> `RequireRecentAuth` は **store 側にだけ**付いている (取消には付かない = 「救済に関門を足さない」設計が
> 実コードで確認できた)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 退会取消を 2FA ゲートの allowlist へ入れる (主措置) | `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` | High |
| 2 | 遮断された**書き込み**に「実行されていません」を付ける | 同上 | High |
| 3 | 救済 route のゲート通過性を deny-by-default 目録で固定 | `app/Enums/Security/RescueRouteGateDisposition.php` (新) / `tests/Architecture/RescueRouteGateInventoryTest.php` (新) | Medium |
| 4 | allowlist の件数 exact-fit pin + 名指し pin | `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` | Medium |
| 5 | 契約変更に伴う既存テストの更新 + 新規テスト | `tests/Feature/Auth/AccountDeletionFreezeTest.php` / `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | High |
| 6 | ドキュメント更新 | `docs/architecture.md` / `docs/auth-security-mechanisms.md` | Medium |

---

## 施策 1: 退会取消を 2FA ゲートの allowlist へ入れる

### 変更箇所

- `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` L41-73 (`ALLOWED_ROUTE_NAMES`)

### 波及変更

- TypeScript 型定義: **なし** (メッセージ文字列のみで型は変わらない)
- API Resource/DTO: **なし** (`TwoFactorRequiredDto` / `TwoFactorRequiredResource` は無変更)
- テストファイル: 施策 4 / 5 (`TwoFactorEnforcementAllowlistTest` / `TwoFactorEnforcementTest` /
  `AccountDeletionFreezeTest`)。`TwoFactorEnforcementTest` の
  「allowlist の各 route はゲート中でも settings.security へ redirect されない」dataset は
  `array_keys(ALLOWED_ROUTE_NAMES)` を回すので**自動で 1 件増える** (冪等 no-op のため 500 にならないことを確認済み)
- フロントエンド: **なし** (`resources/js/pages/Settings/Index.svelte` の取消ボタンはそのまま成功するようになる)

### 現行コード

```php
    public const ALLOWED_ROUTE_NAMES = [
        'settings.security' => '準拠達成の入口 (2FA 設定ページ)',
        'settings' => '設定 index (2FA 設定ページへの導線)',
        // …(中略)…
        'logout' => '離脱は常に可能',
        'verification.notice' => 'verified middleware との redirect 競合回避',
        'verification.verify' => 'メール検証リンクの踏破',
        'verification.send' => '検証メール再送',
    ];
```

### 変更後コード

```php
        'logout' => '離脱は常に可能',
        // 退会予約の**取消**は「業務の利用」ではなく**誤操作の救済**である (F-4-01)。
        // 凍結側 (AccountDeletionFreezeAllowance::DeletionRequestDestroy) は同じ問いに
        // 「凍結中に必ず実行できなければ猶予期間を設けた意味が消える」と結論しており、
        // 実行順が先の本ゲートだけがそれを覆していた (priority list で本ゲートが凍結より前)。
        // 通しても (a) 業務面には到達できないまま (b) 認証手段は増減しない
        // (c) 準拠判定は two_factor_confirmed_at のみが決める、ため 2FA 必須の効力は変わらない。
        // 逆に塞ぐと deletion_purge_after (絶対時刻) が走り続け、本ゲートが
        // **不可逆な物理削除の後押し**になる (「使えない」を超えて「消える」に化ける)。
        // ★**予約 (…deletion-request.store) と即時削除 (settings.account.destroy) は入れない**
        //   — どちらも救済ではなく、遮断されても失われるのは意思表示だけである。
        'settings.account.deletion-request.destroy' => '退会予約の取消 (誤操作救済。凍結 allowlist と判断を揃える)',
        'verification.notice' => 'verified middleware との redirect 競合回避',
```

### PHPStan 適合チェック

- [x] 定数配列の shape は `array<string, string>` のまま (docblock 既存)
- [x] null 安全性の新規論点なし
- [x] DTO 返却の論点なし (定数追加のみ)

### リスク

- **未準拠ユーザーが取消できるようになる** = 意図した変更。取消後も全業務面は遮断されたまま
  (施策 5 の負のコントロールで実測固定)。
- **セッション奪取者が取消できる** = `AccountDeletionRequestController` docblock で既に受容済みのリスク。
  2FA ゲート下で新たに増えるものはない (奪取者は取消以外に何もできない)。
- CSRF 保護は変わらない (`PreventRequestForgery` は本ゲートより前で走る)。

---

## 施策 2: 遮断された書き込みに「実行されていません」を付ける

### 変更箇所

- `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` L94 (メッセージ生成)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし (`message` は string のまま)
- クライアントは `code` 厳格一致で分岐するため文言変更の影響を受けない
  (`tests/js/lib/recent-auth.test.ts` が固定済み)
- テストファイル: 施策 5

### 現行コード

```php
        $message = "組織「{$enforcingOrganization->name}」は 2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。";
```

### 変更後コード

```php
    /**
     * 遮断されたのが**書き込み要求**だったときに文頭へ付ける固定文。
     *
     * 本 middleware は controller より前で短絡するため、この主張は構造的に真である
     * (= 対象 controller に到達しておらず、ドメイン状態は変化していない)。
     * ★**「副作用が一切ない」ことは主張しない** — session 書き込み・throttle 記録・
     *   CSRF 検証はこの短絡でも起こりうる。ここで言う「操作」は controller が行う業務処理を指す。
     * ★route 名 → 日本語の操作名の写像表は**持たない** (二重管理になり route 追加のたびに腐る)。
     *   伝えるのは「起きなかった」ことだけで、「何をしようとしたか」は伝えない。
     */
    public const string BLOCKED_WRITE_PREFIX = '直前の操作は実行されていません。';

    // …handle() 内…
        $message = "組織「{$enforcingOrganization->name}」は 2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。";

        // 安全メソッド (GET/HEAD/OPTIONS/TRACE) は「ページに来られない」だけなので既存文言のまま。
        // 非安全メソッドはユーザーがボタンを押した結果なので、**何が起きなかったか**を先に言う
        // (F-4-01: 押した結果が分からないまま予約が生き残る、が High の理由)。
        if (! $request->isMethodSafe()) {
            $message = self::BLOCKED_WRITE_PREFIX.$message;
        }
```

XHR (409) の本文も同じ `$message` を使うため、HTML / JSON で文言が分岐しない。

### PHPStan 適合チェック

- [x] `Request::isMethodSafe(): bool` (Symfony 由来) — 型は確定
- [x] `public const string` (PHP 8.3+ の型付き定数。既存 `TwoFactorRequiredDto::CODE` と同じ流儀)
- [x] 文字列連結のみで nullable 混入なし

### リスク

- **既存の flash 文言を前提にしたテストへの影響**: 既存アサーションは
  `expect(session('info'))->toContain('2 段階認証を必須としています')` の**部分一致**なので、
  接頭辞を足しても緑のまま (実コード確認済み)。
- **文言が長くなる**: トースト 1 行が伸びる。UI の破綻はしない (`ToastContainer` は複数行を許容)。

---

## 施策 3: 救済 route のゲート通過性を deny-by-default 目録で固定

### 新しい不変条件

> **退会予約の取消 (`settings.account.deletion-request.destroy`) の経路上にある「自前 middleware すべて」と
> 「短絡しうる vendor 認証ゲート 3 本」は、救済 route に対する扱いを宣言してあること。**

### 変更ファイル (新規 2 本)

- `app/Enums/Security/RescueRouteGateDisposition.php`
- `tests/Architecture/RescueRouteGateInventoryTest.php`

### 母集団の定義 (機械的・framework 構成変更に強い)

```
U = ( 取消 route の gatherRouteMiddleware() ∩ 名前空間 "App\Http\Middleware\" )
    ∪ { Illuminate\Auth\Middleware\Authenticate,
        Illuminate\Session\Middleware\AuthenticateSession,
        Illuminate\Auth\Middleware\EnsureEmailIsVerified }
```

- 自前 middleware は**全件**入る → web group に新しい自前ゲートを足したら必ず分類が要る (F-4-01 の再発経路)
- vendor は**この route を実際に短絡させる 3 本だけ**を名指し。cookie / session 開始 / CSRF / binding /
  Inertia の履歴暗号化は入れない (Laravel 側のクラス名変更や構成変更で偽赤にしない。
  実際 CSRF は L12 で `PreventRequestForgery` に改名されている)
- 中間文字列にパラメータが付く形 (`throttle:60,1`) は `:` の前で正規化してから照合する

現時点の母集団は **9 件** (自前 6 + vendor 名指し 3)。

### enum の形

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

use App\Enums\Account\AccountDeletionFreezeAllowance;
use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;

/**
 * 救済 route (退会予約の取消) の経路上にある middleware が、その救済 route をどう扱うかの宣言。
 * **deny-by-default** (RescueRouteGateInventoryTest が母集団と exact-fit で照合する)。
 *
 * 背景: bug-hunt F-4-01。凍結 middleware は取消を通していたのに、実行順が先の 2FA 強制ゲートが
 * 弾いていた = **ゲート同士で判断が食い違っていた**。人の記憶ではなく目録で守る。
 */
enum RescueRouteGateDisposition: string
{
    /** 救済経路そのもの (この route 名で照合する)。 */
    public const string RESCUE_ROUTE_NAME = 'settings.account.deletion-request.destroy';

    case Authenticate = 'Illuminate\Auth\Middleware\Authenticate';
    case AuthenticateSession = 'Illuminate\Session\Middleware\AuthenticateSession';
    case EnsureEmailIsVerified = 'Illuminate\Auth\Middleware\EnsureEmailIsVerified';
    case HandleInertiaRequests = 'App\Http\Middleware\HandleInertiaRequests';
    case SecurityHeaders = 'App\Http\Middleware\SecurityHeaders';
    case RequireTwoFactor = 'App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations';
    case BlockTwoFactorDisable = 'App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations';
    case NoStoreCacheHeaders = 'App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages';
    case NotPendingDeletion = 'App\Http\Middleware\EnsureAccountNotPendingDeletion';

    public function disposition(): RescueRouteGateKind { /* match */ }

    /** 30 文字以上。「なぜ救済が失われないか」を 1 case ずつ書く。 */
    public function rationale(): string { /* match */ }

    /**
     * PassesRescueRoute の case だけが true/false を返せる (実装の allowlist を実際に引く)。
     * それ以外の disposition では LogicException を投げる (宣言だけで緑にならないための構造)。
     */
    public function permitsRouteName(string $routeName): bool
    {
        return match ($this) {
            self::RequireTwoFactor => array_key_exists(
                $routeName,
                RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES,
            ),
            self::NotPendingDeletion => AccountDeletionFreezeAllowance::tryFrom($routeName) !== null,
            default => throw new LogicException(
                'permitsRouteName は PassesRescueRoute の case でのみ使える: '.$this->value,
            ),
        };
    }
}
```

`RescueRouteGateKind` (同ディレクトリの補助 enum) は 3 値:

| case | 意味 | 検証方法 |
|---|---|---|
| `PassesRescueRoute` | 短絡しうるゲートだが、救済 route を allowlist で明示的に通す | **機械照合** (`permitsRouteName()` が実定数を引く) |
| `ShortCircuitsButEscapable` | 救済 route を短絡させうるが、短絡先で本人が条件を解消でき、その解消面は救済経路の外にある | rationale に**解消面の route 名**を書く (人手申告) |
| `NeverShortCircuitsRescueRoute` | この route を短絡させない (応答加工のみ / 他 route 名限定の判定) | 人手申告 |

各 case の分類 (rationale は実装時に 30 文字以上で書く):

| middleware | disposition | 根拠の要点 |
|---|---|---|
| `Authenticate` | ShortCircuitsButEscapable | 未認証は `login` へ。ログイン面は凍結母集団の外・2FA ゲートの対象外 |
| `AuthenticateSession` | ShortCircuitsButEscapable | パスワード変更等でセッション失効 → 再ログインで取消に戻れる (既存テストが固定) |
| `EnsureEmailIsVerified` | ShortCircuitsButEscapable | 未検証は `verification.notice` へ。同 route は凍結対象外かつ 2FA allowlist 内 |
| `HandleInertiaRequests` | NeverShortCircuitsRescueRoute | 共有 props 組み立て。asset 版不一致の 409 強制再読込は **GET のみ**で、取消は DELETE |
| `SecurityHeaders` | NeverShortCircuitsRescueRoute | 応答ヘッダ付与のみ |
| `RequireTwoFactorForEnforcedOrganizations` | **PassesRescueRoute** | 施策 1 で allowlist に登録。**non-exemptible** |
| `BlockTwoFactorDisableForEnforcedOrganizations` | NeverShortCircuitsRescueRoute | 判定対象は `two-factor.disable` 1 本のみ |
| `NoStoreCacheHeadersForAuthenticatedPages` | NeverShortCircuitsRescueRoute | 応答ヘッダ付与のみ |
| `EnsureAccountNotPendingDeletion` | **PassesRescueRoute** | `AccountDeletionFreezeAllowance` で登録済み。**non-exemptible** |

### 検査 (`tests/Architecture/RescueRouteGateInventoryTest.php`)

| # | 検査 | 何を守るか |
|---|---|---|
| 1 | 母集団 `U` と enum values が **exact-fit** (両方向 diff が空) | 自前ゲートの新設・撤去に必ず分類判断を強制する |
| 2 | `PassesRescueRoute` の全 case で `permitsRouteName(RESCUE_ROUTE_NAME) === true` | **宣言と実装の一致** (allowlist から消したら赤) |
| 3 | 2FA ゲートと凍結 middleware は `PassesRescueRoute` から**動かせない** (名指し) | 「通さない」に格下げして緑にする逃げ道を塞ぐ |
| 4 | 全 case の `rationale()` が 30 文字以上 | 運用劣化の防止 (既存 gate と同じ作法) |
| 5 | 名指し vendor 3 本が**実際に route に付いている** | route から `verified` 等が外れたら分類の前提が変わるので気づかせる |
| 6 | 空振り検知: 母集団件数 **exact 9** / 母集団 0 件 (route 不在) で明示 fail | vacuous green の排除 |
| 7 | 負のコントロール: `permitsRouteName('settings.account.destroy')` が両ゲートで **false** | 照合器が本当に判別しているかの実測 (常に true を返す実装なら赤) |

### PHPStan 適合チェック

- [x] `gatherRouteMiddleware()` の戻りは `mixed` を含むため `is_string()` で絞ってから使う
- [x] `Route` が null のとき (route 不在) は `Assert::notNull` ではなく**明示 fail** (テスト側で expect)
- [x] `permitsRouteName()` の `default` 分岐は `LogicException` を投げる (戻り値型 `bool` を満たす)
- [x] enum の `match` は全 case 網羅 (level 10 が漏れを検出する)

### リスク

- **母集団が Laravel 側の変更で動く**リスクは、自前 namespace + vendor 名指しに絞ったことで最小化した。
  ただし `verified` / `auth` が route から外れれば検査 5 が赤くなる (= 意図した検出)。
- **人手申告の分類 2 種**は middleware 本体の改変に沈黙する (保証しないものへ明記)。

---

## 施策 4: allowlist の件数 exact-fit pin + 名指し pin

### 変更箇所

- `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` (追記)

### 内容

```php
/** ALLOWED_ROUTE_NAMES の件数 (exact-fit。増減させたらこの数値も同じ diff で書き換える) */
const TWO_FACTOR_ALLOWLIST_COUNT = 21;   // 施策 1 で 20 → 21

test('allowlist の件数を exact-fit で pin する (根拠なく通す余裕枠を作らない)', …);

test('救済 route は allowlist にあり、予約・即時削除は無い (名指し pin)', function (): void {
    $names = array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES);
    expect($names)->toContain('settings.account.deletion-request.destroy');   // 救済は通す
    expect($names)->not->toContain('settings.account.deletion-request.store'); // 予約は救済ではない
    expect($names)->not->toContain('settings.account.destroy');               // 即時削除は猶予の迂回口
    expect($names)->not->toContain('two-factor.disable');                     // 既存判断の維持
});
```

### リスク

なし (テストのみ)。件数 pin は allowlist が「なんとなく増える」ことを止める。

---

## 施策 5: 契約変更に伴う既存テストの更新 + 新規テスト

### 波及変更 (禁止事項 3 との関係を明示)

`tests/Feature/Auth/AccountDeletionFreezeTest.php` の
`test('2FA 未準拠ユーザーは 2FA ゲートが先に効くが、設定画面へ到達できる (詰みではない)')` は
**現行の遮断挙動を意図的に pin している**ため、施策 1 で契約が変わると赤くなる。

- これは「検証を消して緑にする」行為 (禁止事項 3 の趣旨) ではなく、**契約変更に伴う期待値の更新**である。
- 更新後も、元テストが守っていた主張 (「詰みではない」= `settings.security` / `settings` に到達できる、
  同一ユーザーで脱出の連鎖が成立する) は**すべて残す**。加えて負のコントロール (2FA 強制は緩んでいない) を
  **足す**ので、検証は減らない。

### テスト計画 (ファイル名 + テストケース名まで)

#### `tests/Feature/Auth/AccountDeletionFreezeTest.php` (更新 1 / 追加 1)

- [更新] `test('2FA 未準拠でも退会予約を取り消せる (救済は 2FA ゲートも凍結も通る)')`
  - 2FA 必須組織 + 未準拠 + 予約中の**同一ユーザー**で `DELETE /settings/account/deletion-request`
  - `assertRedirect('/settings')` / `deletion_requested_at` が **null**
  - 負のコントロール (同一テスト内): 取消の**前後**とも `GET /dashboard` は `settings.security` へ 302
    (= 2FA 強制は 1mm も緩んでいない)
  - `GET /settings` / `GET /settings/security` が 200 (旧テストの「詰みではない」主張を保持)
- [追加] `test('2FA 未準拠ユーザーの即時削除は通らない (救済だけを通す非対称)')`
  - `DELETE /settings/account` → `settings.security` へ 302 / user 行が残っている
  - 「削除系なら何でも通す」になっていないことの負のコントロール

#### `tests/Feature/Organizations/TwoFactorEnforcementTest.php` (追加 4)

- [追加] `test('遮断された書き込みには「実行されていません」が付き、実際に何も起きていない')`
  - 2FA 必須 + 未準拠 + **未予約**ユーザーで `POST /settings/account/deletion-request`
    (`withSession(freshRecentAuthSession())`)
  - `assertRedirect(route('settings.security'))` / `session('info')` が
    `RequireTwoFactorForEnforcedOrganizations::BLOCKED_WRITE_PREFIX` で**始まる**
  - **`deletion_requested_at` が null のまま** (= 文言の主張が事実であることの実測)
- [追加] `test('遮断された GET には「実行されていません」を付けない (安全メソッドの負のコントロール)')`
  - `GET /dashboard` → `session('info')` が prefix を**含まない**が、既存文言は含む
- [追加] `test('XHR で遮断された書き込みも 409 本文に「実行されていません」を含む')`
  - `postJson('/settings/account/deletion-request')` → 409 / `message` が prefix で始まる /
    `code` = `two_factor_required` / `redirect` = `settings.security` / `Cache-Control: no-store, private`
- [追加] `test('退会取消は allowlist 経由でゲートを通り 2FA 状態を変えない')`
  - 未準拠 + 未予約で `DELETE` → `settings.security` へ redirect **されない** (冪等 no-op)
  - `two_factor_confirmed_at` が null のまま (通しても準拠判定は動かない)

> 既存の dataset テスト `allowlist の各 route はゲート中でも settings.security へ redirect されない` は
> `array_keys(ALLOWED_ROUTE_NAMES)` を回すため、施策 1 で自動的に取消 route を 1 件検証する。
> 冪等 no-op のため 500 にならないことは `cancelAccountDeletion()` の実装で確認済み。

#### `tests/Architecture/RescueRouteGateInventoryTest.php` (新規)

- `test('検査 1: 救済 route のゲート母集団と目録が exact-fit である')`
- `test('検査 2: PassesRescueRoute の宣言は実装の allowlist と一致する')`
- `test('検査 3: 2FA ゲートと凍結 middleware は PassesRescueRoute から動かせない')`
- `test('検査 4: 各 case の rationale が 30 文字以上ある')`
- `test('検査 5: 名指しした vendor 認証ゲート 3 本が実際に route に付いている')`
- `test('空振り検知: 母集団件数の exact pin と、照合器の負のコントロール')`

#### `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` (追加 2 — 施策 4)

- `test('allowlist の件数を exact-fit で pin する (根拠なく通す余裕枠を作らない)')`
- `test('救済 route は allowlist にあり、予約・即時削除は無い (名指し pin)')`

#### Browser lane (Chromium + WebKit)

- **追加しない**。理由: **フロント差分がゼロ**であり、flash → toast の描画経路は
  `tests/Browser/FlashToastTest.php` が既にカバーしている。本設計が変えるのは
  サーバが積む文字列と middleware の分岐だけで、描画側の契約は変わらない。
- この判断は「保証しないもの」に明記する (実ブラウザでの文言表示は本設計では再確認しない)。

### 検査が空振りしないことの保証

| 仕掛け | 効果 |
|---|---|
| 母集団 0 件 (route 不在) で明示 fail | route 名リネームで gate が黙って緑になるのを防ぐ |
| 母集団件数 **exact 9** の pin | middleware の増減を必ずレビューに出す |
| `permitsRouteName('settings.account.destroy') === false` の負のコントロール | 照合器が常に true を返す実装になったら赤 |
| GET に prefix が**付かない**ことの負のコントロール | 条件分岐を「常に付ける」に潰したら赤 |
| 遮断後に `deletion_requested_at` が変わっていないことの assert | 文言の主張が事実であることを実測 (文字列一致だけで満足しない) |
| 取消**後**も `/dashboard` が 302 であることの assert | 「2FA 強制が緩んでいない」の実測 (allowlist 過剰追加の検出) |

### mutation で赤化を確認する手順

実装完了後、以下を **1 つずつ** 当てて赤くなることを確認し、確認後に必ず戻す
(`git stash` ではなく手で戻し、`git diff` が空になることを確認する)。

| # | mutation | 期待される赤 |
|---|---|---|
| M1 | `ALLOWED_ROUTE_NAMES` から `settings.account.deletion-request.destroy` を削除 | Feature「2FA 未準拠でも取消できる」/ Arch 検査 2 / 施策 4 の名指し pin・件数 pin |
| M2 | 施策 2 の条件を `if (false)` に固定 | Feature「書き込みには実行されていませんが付く」/ XHR 版 |
| M3 | 施策 2 の条件を常時 true に | Feature「GET には付けない」(負のコントロール) |
| M4 | enum から `RequireTwoFactor` case を削除 | Arch 検査 1 (exact-fit) / 検査 3 (名指し) / 件数 pin |
| M5 | enum の `RequireTwoFactor` を `NeverShortCircuitsRescueRoute` に変更 | Arch 検査 3 |
| M6 | 母集団抽出から vendor 名指し 3 本を外す | Arch 検査 1 / 検査 5 / 件数 pin |
| M7 | `AccountDeletionFreezeAllowance` から `DeletionRequestDestroy` を削除 | Arch 検査 2 + 既存 `AccountDeletionFreezeRouteGateTest` の件数 pin |
| M8 | `permitsRouteName()` を `return true;` に置換 | Arch 検査 7 (負のコントロール) |

---

## 施策 6: ドキュメント更新

### `docs/architecture.md` §退会の猶予期間つき削除 (凍結方式・30 日)

- 「**2FA 必須組織との相互作用**」の記述を**書き換える**。現行は
  「未準拠ユーザーの取消 DELETE は 2FA ゲートが `settings.security` へ倒す」= 遮断が正であると読める。
  変更後は:
  - 取消は **両ゲートの allowlist に入っている** (救済は業務ではない)
  - `settings.security` を凍結 allowlist に置く理由 (T142 の相互ブロック回避) は**そのまま残す**
    (2FA 設定に到達できることは依然として必要)
  - この一致を守る gate として `RescueRouteGateInventoryTest` を挙げる
- 「保証しないもの」に「**遮断メッセージが元操作を名指しすることは保証しない**」を追加。

### `docs/auth-security-mechanisms.md`

- `ALLOWED_ROUTE_NAMES` の説明 (L112 付近) に「**+ 救済経路 (退会予約の取消)**」を追加し、
  非安全メソッドの遮断時に `BLOCKED_WRITE_PREFIX` が付くことを 1 行で記述する。

### AGENTS.md

- **変更しない**。凍結・2FA ゲートの契約は `docs/architecture.md` が正本であり、
  AGENTS.md に新しい規約行を足す必要はない (思考原則 2)。

---

## 保証しないもの (誇張しない)

1. **メッセージが「何をしようとしたか」を伝えることは保証しない**。route 名 → 操作名の写像を持たないため、
   伝わるのは「**起きなかった**」ことだけである。F-4-01 の「押した操作が何だったかの手がかり」は
   **主措置 (取消が成功する) によって不要になった**のであって、文言で解決したのではない。
2. **「副作用が一切ない」ことは保証しない**。session 書き込み・throttle 記録・CSRF 検証は短絡時にも起こりうる。
   主張の範囲は「controller に到達していない = ドメイン状態が変わっていない」までである。
3. **他の遮断 middleware は対象外**。凍結 (`EnsureAccountNotPendingDeletion`) /
   課金ゲート (`RequireActiveSubscription`) / `RequireRecentAuth` の文言は変えない。
   同種の分かりにくさが残っている可能性はあるが、**この run で再現された事実がない** (思考原則 2)。
4. **目録の母集団は取消 route 1 本だけ**。他の救済的 route (`billing.portal` 等) のゲート通過性は見ない。
5. **vendor middleware は名指し 3 本のみ**。vendor 側に新しい短絡 middleware が増えても gate は沈黙する。
   また cookie / session 開始 / CSRF / binding / Inertia 履歴暗号化は母集団に入れていない。
6. **人手申告の分類 2 種** (`ShortCircuitsButEscapable` / `NeverShortCircuitsRescueRoute`) は
   middleware 本体の改変 (新しい短絡分岐の追加) に沈黙する。機械照合できるのは
   `PassesRescueRoute` の allowlist 一致だけである。
7. **route cache 済み起動での配線は見ない**。group 直付けなので cache に焼き込まれるが、
   「毎デプロイ `route:cache` を再生成する」運用要件そのものは機械化できない (AGENTS.md の運用要件)。
8. **実ブラウザでの文言表示は本設計では再確認しない** (フロント差分ゼロ。描画経路は
   `tests/Browser/FlashToastTest.php` の既存カバレッジに委ねる)。
9. **api/v1 / MCP / OAuth 経路は 2FA ゲートの母集団外** (web group のみ)。凍結と同じ非対称が残る。
10. **30 日の執行そのもの**は変えない。取消が通るようになっても、取消し忘れた予約は期日に執行される。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更が middleware 1 本 + 新 enum 1 本 + テスト 4 本 + docs 2 本に閉じ、独立して緑にできる。並列で走る他 2 設計 (`preview-render-parity` = レンダ経路 / `state-aware-messaging` = Dashboard props) とアプリコードの重複が無い |
| 競合リスク | `docs/architecture.md` を他設計も触る可能性がある (触る**節が違う**ため conflict は行単位で軽微)。`tests/Feature/Organizations/TwoFactorEnforcementTest.php` / `AccountDeletionFreezeTest.php` は本設計の専有 |

## 使命・禁止事項の最終チェック

- **使命**: アカウント消失は SOP・撮影素材・シナリオの喪失であり、「現場作業者がマニュアル動画を作れる」
  という前提そのものを壊す。誤操作救済を実効にすることは使命に直接寄与する。
- **禁止事項 1**: 全施策に Pest テスト (Feature 6 本 + Architecture 8 本) を計画済み。
- **禁止事項 2**: PHPStan の widen / baseline なし。
- **禁止事項 3 (既存テスト)**: 契約変更に伴う 1 本の期待値更新のみ。守っていた主張は全て残し、負のコントロールを追加。
- **禁止事項 4**: `response()->json()` 直書きなし (既存 `TwoFactorRequiredResource` をそのまま使う)。
- **禁止事項 8**: 「押せるのに必ず失敗するボタン」を**なくす**方向の変更である (取消ボタンが実際に効くようになる)。
- **RefreshDatabase / Factory**: 新規テストは既存 helper (`createOrganizationWithOwner` / `tfeAddMember` /
  `User::factory()`) を使い、`Model::create()` 手組みをしない。`DatabaseTransactions` は使わない。

---

## 関連する現行コード

### app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\TwoFactorRequiredDto;
use App\Enums\TwoFactorStatus;
use App\Http\Resources\Auth\TwoFactorRequiredResource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「2FA 必須」組織に所属する未準拠ユーザーのアカウント全体ゲート。
 *
 * 契約: 1 つでも two_factor_required な組織に所属する 2FA 未完了 (disabled/pending)
 * ユーザーは ALLOWED_ROUTE_NAMES 以外の web 経路すべてから 2FA 設定ページ
 * (settings.security) へ 302 (XHR は 409 + {code, message, redirect}) される。
 * 組織スコープの部分制限は採らない (2FA はアカウント全体の属性のため)。
 *
 * 評価コスト: 準拠 (enabled) ユーザーは attribute 判定のみで追加クエリゼロ。未準拠
 * ユーザーのみ所属組織の 1 クエリ (flash 用に組織名が要るため first)。
 *
 * web group append (= StartSession 後)。auth は route middleware だが session guard は
 * lazy 解決のため $request->user() はここで利用可能。未認証は素通し (login 等は対象外)。
 */
final class RequireTwoFactorForEnforcedOrganizations
{
    /**
     * ゲート中でも到達可能な route name => 必要理由。
     * この表が正であり、(a) 全 name の実在 + 理由非空 (TwoFactorEnforcementAllowlistTest)、
     * (b) ゲート中の到達可能性 (TwoFactorEnforcementTest dataset) を同表から検証する。
     * two-factor.disable は意図的に含めない (ゲート解除手段にならず、pending 巻き戻しの
     * 濫用面になる。self-disable は BlockTwoFactorDisableForEnforcedOrganizations も参照)。
     *
     * @var array<string, string>
     */
    public const ALLOWED_ROUTE_NAMES = [
        'settings.security' => '準拠達成の入口 (2FA 設定ページ)',
        'settings' => '設定 index (2FA 設定ページへの導線)',
        'two-factor.enable' => 'enrollment 開始 (POST /user/two-factor-authentication)',
        'two-factor.confirm' => 'TOTP 確認 = 準拠達成 (POST /user/confirmed-two-factor-authentication)',
        'two-factor.qr-code' => 'QR 表示 (設定ページの fetch)',
        'two-factor.secret-key' => '手動入力キー表示 (設定ページの fetch)',
        'two-factor.recovery-codes' => 'リカバリコード表示 (設定完了直後の保存)',
        'two-factor.regenerate-recovery-codes' => 'リカバリコード再生成',
        // 応答は { authenticated: bool } のみ (PII も操作も含まない) ため、ゲート中に
        // 200 を返しても情報露出にならない。逆に遮断すると bfcache 復元後の guard が
        // 「プローブ失敗」に倒れ、秘匿が解除できないまま再試行ループになる
        'session.status' => 'bfcache 復元時のセッション有効性プローブ (秘匿解除の唯一の判定源)',
        'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
        'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
        'recent-auth.password' => 'password による step-up 完了',
        // passkey による step-up (T124)。2FA 必須ゲート下の未準拠ユーザーは enrollment
        // (two-factor.enable / qr-code / secret-key) に step-up を要求されるため、
        // satisfier を password と再SSO だけに絞ると **passkey-only ユーザー**
        // (password 未設定・SSO 未連携) が enrollment の入口で手段ゼロになり詰む。
        // これらは satisfier 側であり、通すこと自体は 2FA ゲートの解除にならない
        // (準拠判定は two_factor_confirmed_at のみが決める)。
        'passkey.confirm-options' => 'passkey による step-up の challenge 発行',
        'passkey.confirm' => 'passkey による step-up 完了',
        // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
        // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
        'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
        'social.callback' => 'SSO step-up の callback',
        'logout' => '離脱は常に可能',
        'verification.notice' => 'verified middleware との redirect 競合回避',
        'verification.verify' => 'メール検証リンクの踏破',
        'verification.send' => '検証メール再送',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->twoFactorStatus() === TwoFactorStatus::Enabled) {
            return $this->proceed($request, $next);
        }

        $routeName = $request->route()?->getName();
        if ($routeName !== null && array_key_exists($routeName, self::ALLOWED_ROUTE_NAMES)) {
            return $this->proceed($request, $next);
        }

        // ここに到達するのは未準拠 (disabled/pending) ユーザーのみ。
        // 状態非依存の単一述語 firstTwoFactorRequiringOrganization() で必須組織を引く
        $enforcingOrganization = $user->firstTwoFactorRequiringOrganization();
        if ($enforcingOrganization === null) {
            return $this->proceed($request, $next);
        }

        $message = "組織「{$enforcingOrganization->name}」は 2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。";

        // XHR/JSON は RequireRecentAuth と同形の 409 + { code, message, redirect } (no-store)。
        // SPA の非画面 fetch に HTML リダイレクトを返さない
        if ($request->expectsJson()) {
            return TwoFactorRequiredResource::make(new TwoFactorRequiredDto(
                message: $message,
                redirect: route('settings.security'),
            ))
                ->response()
                ->setStatusCode(409)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return redirect()
            ->route('settings.security')
            ->with('info', $message);
    }

    /** @param  Closure(Request): mixed  $next */
    private function proceed(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }
}
```

### app/Http/Middleware/EnsureAccountNotPendingDeletion.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Account\AccountDeletionStateDto;
use App\Enums\Account\AccountDeletionFreezeAllowance;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 退会予約中 (猶予期間つき削除・**凍結方式**) のアクセス制限。alias: `not-pending-deletion`。
 *
 * users 行の生死は変えず (SoftDeletes を使わない)、予約中は業務面を止めて
 * **取消と、退会ブロッカーの解消**だけを通す。通す route は
 * {@see AccountDeletionFreezeAllowance} の **exact case** のみ (deny-by-default・wildcard 禁止)。
 *
 * ★**403 で突き放さない**。遮断時は取消ボタンのある `/settings` へ 302 する
 *   (AGENTS.md ドメイン規約 4 と同じ思想 = 行き先のない詰みを作らない)。
 *   遮断理由の flash は積まない — 理由は着地ページ (/settings の予約バナー) が持つ
 *   (課金ゲート `RequireActiveSubscription` と同じ契約)。
 *
 * ★**実行位置は `bootstrap/app.php` の priority list が正本**で、テナント境界 404
 *   (`EnsureProjectBelongsToCurrentOrganization`) より**必ず後**に置く。前に置くと
 *   「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
 *   (AGENTS.md セキュリティ不変条件 10)。
 *
 * ★配線は `routes/web.php` の group への**直付け**である。`RouteMiddlewareBinder` の後付けは
 *   使わない (route cache 済みの起動では 1 本も効かず、無音で保護が外れる = T135)。
 *
 * ★母集団の外 (= 凍結されない): Fortify / Passkeys が登録するログイン・ログアウト・
 *   パスワード再設定・メール確認・2FA challenge・passkey ログインと `session.status`。
 *   **認証回復と離脱の手段は構造的に凍結されない**。
 */
final class EnsureAccountNotPendingDeletion
{
    /** 凍結中に遮断されたことを機械可読に伝える文言 (JSON/XHR の 409 本文)。 */
    public const FROZEN_MESSAGE = '退会予約中のため、この操作はできません。設定画面から退会を取り消してください。';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request); // 未認証は auth middleware の責務 (ここでは判定しない)
        }

        if (! AccountDeletionStateDto::fromUser($user)->isPending()) {
            return $next($request);
        }

        $name = $request->route()?->getName();
        if ($name !== null && AccountDeletionFreezeAllowance::tryFrom($name) !== null) {
            return $next($request);
        }

        // JSON/XHR は 409 Conflict (状態が操作と矛盾している)。課金ゲートの 402 とは別事由。
        if ($request->expectsJson()) {
            abort(Response::HTTP_CONFLICT, self::FROZEN_MESSAGE);
        }

        // 直前の flash (他画面の success/error) を着地先まで保つ。理由の flash は積まない。
        $request->session()->reflash();

        return redirect()->route('settings');
    }
}
```

### app/Enums/Account/AccountDeletionFreezeAllowance.php (docblock + case 定義部)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Account;

/**
 * 退会予約中 (凍結) に**通してよい route 名**の目録。**deny-by-default**。
 *
 * ここに無い route は予約中に遮断され `/settings` (取消ボタンのある画面) へ 302 する。
 *
 * ★**wildcard を書かない** (route 名の exact case のみ)。`billing.*` のような namespace 指定を
 *   許すと購入・新規契約・自動チャージ有効化まで一緒に通り、凍結の意味が消える。
 * ★母集団 (`U` = 凍結 middleware が付いた全 route) との関係は **`A ⊆ U`**。
 *   `U` に無い route 名は書けない (死に登録の防止)。実装と宣言の一致・母集団の内外は
 *   `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` が機械固定する。
 *
 * ★**`settings.account.destroy` (即時削除) は入れない**。予約中のユーザーが表明した意思は
 *   「30 日後に削除」であり、その状態で即時削除の口を開けておくと**猶予が守ろうとしているもの
 *   (誤操作) をそのまま通してしまう** (30 日猶予の迂回口になる)。「今すぐ消したい」なら
 *   **取消 → 即時削除**の 2 手を踏む (一貫した状態機械でありユーザーに説明できる)。
 * ★**`notifications.open` は入れない**。POST + 303 で通知の遷移先へ飛ばす route であり、
 *   入れると「通知経由なら業務 route / dashboard / checkout に到達できる」抜け道になる。
 *   通知は `notifications.index` で読めるので rescue surface の役割は満たされる
 *   (「遷移先ごとに判定する」分岐は作らない = 凍結の判定点を 2 箇所に増やさない)。
 * ★**`billing.auto-recharge.update` は入れない**。同じ更新 endpoint が有効化・閾値変更・
 *   数量変更を受けるため、通すと**新しい課金責務を作る入口**になる。凍結中に自動チャージが
 *   発火する経路は構造的に存在しない (`AutoRechargeTriggerJob` を dispatch するのは
 *   `TicketLedgerService::reserve()` だけで、それを呼ぶ業務 route は凍結で全部止まる)。
 */
enum AccountDeletionFreezeAllowance: string
{
    // --- 取消に到達するための step-up (satisfier) ---
    case RecentAuthConfirm = 'recent-auth.confirm';
    case RecentAuthStatus = 'recent-auth.status';
    case RecentAuthPassword = 'recent-auth.password';
    // --- 取消 UI と取消そのもの ---
    case Settings = 'settings';
    case SettingsSecurity = 'settings.security';
    case DeletionRequestDestroy = 'settings.account.deletion-request.destroy';
    // --- 退会ブロッカー (生きた課金責務) の解消 ---
    case BillingIndex = 'billing.index';
    case BillingPortal = 'billing.portal';
    // --- 退会ブロッカー (孤児メンバー) の解消 ---
    case OrganizationSwitch = 'organizations.switch';
    case OrganizationSettings = 'organizations.settings';
    case TransferOwnership = 'organizations.transfer-ownership';
    case MemberUpdate = 'organizations.members.update';
    case MemberDestroy = 'organizations.members.destroy';
    case InvitationRevoke = 'organizations.invitations.revoke';
    // --- 予約・執行不能を知る手段 (読むだけ) ---
    case NotificationsIndex = 'notifications.index';
    case NotificationsReadAll = 'notifications.read-all';
    case NotificationsRead = 'notifications.read';

    /**
     * 通す根拠 (**30 文字以上**。gate が長さを検査する)。
     *
     * 「凍結中でもこれが無いと詰む」を 1 case ずつ書く。書けないなら通してはいけない。
     */
```

### routes/web.php (退会まわり抜粋)

```php
    // 2FA / ソーシャル連携 / パスキーの管理面 (passkey 一覧の組み立てに DI が要るため Controller)
    Route::get('/settings/security', SecurityController::class)->name('settings.security');

    // アカウント削除 (即時・取り消せない) は step-up (recent-auth) 必須。
    // 猶予期間つきの予約 (下記) が UI の主導線で、こちらは**副導線として併存**させる
    // (標準形 v1 は「猶予つき予約と即時削除の両方」を必須にしている)。
    Route::delete('/settings/account', [AccountController::class, 'destroy'])
        ->middleware('recent-auth')
        ->name('settings.account.destroy');

    // 退会の予約 (猶予 30 日)。**UI の主導線**。即時削除と同水準の機微操作のため step-up 必須。
    Route::post('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'store'])
        ->middleware('recent-auth')
        ->name('settings.account.deletion-request.store');
    // 退会予約の取消。**誤操作救済の本体**なので step-up を課さない
    // (救済経路に関門を足すと「取り消せない」詰みの再生産になる。取消は権限を増やす操作ではない)。
    Route::delete('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'destroy'])
        ->name('settings.account.deletion-request.destroy');

    /*
    | 組織。`{organization}` / `{organization:slug}` は MembershipScopedOrganizationBinder
```

### app/Http/Controllers/Settings/AccountDeletionRequestController.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * 退会の予約 (猶予期間つき削除) と、その取消。
 *
 * 対象は常に `$request->user()` **自身**である。route に他者を指せる parameter が 1 つも無く、
 * 他人のアカウントへ到達する経路がコード上存在しない (`ControllerAuthorizationGateTest` の
 * `SelfScopedResource` で登録済み)。
 *
 * ★**予約 (store) には step-up (recent-auth) を課し、取消 (destroy) には課さない**。
 *   取消は**誤操作救済の本体**であり、救済経路に関門を足すと「取り消せない」詰みの再生産になる
 *   (取消は権限を増やす操作ではない)。
 *   受け入れるリスク: セッション奪取者が予約を取り消せる。しかし奪取者が取り消しても
 *   失われるのは「退会の意思」だけで、本人は再度予約できる。逆に取消に関門を付けると
 *   **本人が救済できない**方が重い被害になる。これは設計判断である。
 */
final class AccountDeletionRequestController extends Controller
{
    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // ブロッカーは評価しない (予約は意思表示であって削除ではない)。権威判定は執行時。
        $state = $membership->requestAccountDeletion($user);

        // 操作系 POST は back() で完結させる (禁止事項 7: intended() を使わない)
        return back()->with(
            'success',
            "退会を予約しました。{$state->purgeAfterLabel()}までは取り消せます。",
        );
    }

    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $membership->cancelAccountDeletion($user);

        return back()->with('success', '退会の予約を取り消しました。');
    }
}
```

### tests/Feature/Auth/AccountDeletionFreezeTest.php (更新対象の既存テスト)

```php
test('recent-auth の鮮度が切れていても取消できる (救済経路に step-up を課さない)', function (): void {
    [, $owner] = frozenUser();

    // recent_auth_at を一切持たないセッションで取消する
    $this->actingAs($owner)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');
    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
});

test('2FA 必須組織のユーザーでも取消できる (satisfier の到達性)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();

    // 2FA 準拠済みユーザー (未準拠だと 2FA 強制ゲートが先に短絡し、凍結の検証にならない)
    $user = User::factory()->withTwoFactor()->create();
    $organization->users()->attach($user);
    $user->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
    $user->forceFill(['current_organization_id' => $organization->id])->save();
    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
    $user->refresh();

    $this->actingAs($user)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');
    expect($user->fresh()?->deletion_requested_at)->toBeNull();
});

test('2FA 未準拠ユーザーは 2FA ゲートが先に効くが、設定画面へ到達できる (詰みではない)', function (): void {
    // ★凍結より **前** に走る 2FA 強制ゲート (priority list) の方が優先されるため、
    //   未準拠ユーザーは取消 DELETE に直接到達できない。これは詰みではなく、
    //   2FA 設定を済ませれば取消できる (準拠済みの取消は上のテストが固定している)。
    //   この非対称を「取消はいつでもできる」と誇張しないために明示的に固定する。
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();
    $user = User::factory()->create(); // 2FA 未準拠
    $organization->users()->attach($user);
    $user->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
    $user->forceFill(['current_organization_id' => $organization->id])->save();
    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
    $user->refresh();

    // 取消は 2FA ゲートに阻まれる (凍結の 302 先ではなく 2FA 設定ページへ倒れる)
    $this->actingAs($user)->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings/security');
    expect($user->fresh()?->deletion_requested_at)->not->toBeNull();

    // ★準拠達成の入口 (settings.security) に到達できることが**詰みでないことの条件**。
    //   ここを凍結すると「取消は 2FA ゲート / 2FA 設定は凍結」の相互ブロックになる。
    $this->actingAs($user)->get('/settings/security')->assertOk();
    $this->actingAs($user)->get('/settings')->assertOk();

    // ★**同一ユーザー**で脱出の連鎖を固定する
    //   (未準拠 → settings.security → 2FA 準拠 → 取消)。別ユーザーで代用すると
    //   「元のユーザーが本当に脱出できるか」を証明しないため、詰みの回帰防止にならない。
    //   準拠状態への遷移は UserFactory::withTwoFactor() と同一実装を共有する helper で行う。
    UserFactory::enableTwoFactorFor($user);
    $user->refresh();

    $this->actingAs($user)->from('/settings')
        ->delete('/settings/account/deletion-request')
        ->assertRedirect('/settings');
    expect($user->fresh()?->deletion_requested_at)->toBeNull();
});

test('XHR は 409 Conflict で遮断される (302 に倒さない)', function (): void {
    [, $owner] = frozenUser();
```

### tests/Feature/Organizations/TwoFactorEnforcementTest.php (allowlist dataset とゲート挙動)

```php
test('必須組織の未準拠メンバーは dashboard から settings.security へ 302 + flash に組織名', function (string $status): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, $status);

    $this->actingAs($member)
        ->get('/dashboard')
        ->assertRedirect(route('settings.security'));

    expect(session('info'))->toContain($organization->name);
    expect(session('info'))->toContain('2 段階認証を必須としています');
})->with(['disabled', 'pending']);

test('enabled メンバーは素通し', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'enabled');

    $this->actingAs($member)->get('/dashboard')->assertOk();
});

test('必須でない組織のみ所属の未準拠ユーザーは素通し', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: false);
    $member = tfeAddMember($organization, 'disabled');

    $this->actingAs($member)->get('/dashboard')->assertOk();
});

test('未認証リクエスト (GET /login) にゲートは干渉しない', function (): void {
    tfeCreateOrganization(twoFactorRequired: true);

    $this->get('/login')->assertOk();
});

test('allowlist の各 route はゲート中でも settings.security へ redirect されない', function (string $routeName): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'pending');

    $route = app('router')->getRoutes()->getByName($routeName);
    expect($route)->not->toBeNull();

    // URI パラメータを持つ route (verification.verify / social.*) はダミー値で充足
    $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
    $method = in_array('GET', $route->methods(), true) ? 'get' : strtolower($route->methods()[0]);

    $response = $this->actingAs($member)
        ->withSession(['recent_auth_at' => time()])
        ->{$method}($uri);

    // ゲートによる settings.security への redirect でないことのみ検証
    // (本来の応答 / 別 redirect / 4xx は許容。settings.security 自身の GET は 200)
    if ($routeName === 'settings.security') {
        $response->assertOk();
    } else {
        expect($response->headers->get('Location'))->not->toBe(route('settings.security'));
    }
})->with(array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES));

test('2FA 必須ゲート下の passkey-only ユーザーは passkey step-up の challenge を取得できる (T124)', function (): void {
    // enrollment (two-factor.enable / qr-code / secret-key) に step-up が課された結果、
    // satisfier の到達性が enrollment の前提になった。password / 再SSO / passkey の
    // どれか 1 つでも allowlist から漏れると、その手段しか持たないユーザーが入口で詰む。
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'pending');
    // 「passkey-only」をテスト名だけの主張にしない: password を実際に外す
    // (users.password は SSO-only ユーザーのため nullable)。SSO 連携も張らないので
    // このユーザーの step-up 手段は passkey 1 本だけになる。
    $member->forceFill(['password' => null])->save();
    Passkey::factory()->for($member)->create();

    $member->refresh();
    expect($member->password)->toBeNull();
    expect($member->socialAccounts()->count())->toBe(0);

    $response = $this->actingAs($member)->getJson('/passkeys/confirm/options');

    // 本施策の直接の回帰: ゲートによる settings.security への redirect でないこと
    expect($response->headers->get('Location'))->not->toBe(route('settings.security'));
    // 期待値は vendor controller の正常契約から確定している:
    // Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController::index() は
    // response()->json(['options' => ...]) を返す = 200。
    // (「allowlist は通ったが実用上は壊れている」空振りを排除する)
    $response->assertOk()->assertJsonStructure(['options']);
});

test('allowlist 外の passkey 管理 route はゲート中に settings.security へ 302 (T124 の負のコントロール)', function (): void {
    // 「passkey なら何でも通す」になっていないことの証拠。registration-options は
    // credential を**増やす**管理経路であり satisfier ではない。
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'pending');

    $this->actingAs($member)
        ->withSession(freshRecentAuthSession())
        ->get('/user/passkeys/options')
        ->assertRedirect(route('settings.security'));
});

test('非許可 route の代表はゲート中必ず settings.security へ 302', function (string $path): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'disabled');

    $this->actingAs($member)
        ->withSession(['recent_auth_at' => time()])
        ->get($path)
        ->assertRedirect(route('settings.security'));
})->with([
    'dashboard' => ['/dashboard'],
    'billing' => ['/billing'],
    'projects' => ['/projects'],
]);

test('XHR (Accept: json) でゲート → 409 + code/message/redirect + no-store', function (): void {
    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
    $member = tfeAddMember($organization, 'disabled');

    $response = $this->actingAs($member)->getJson('/dashboard');

    $response->assertStatus(409)
        ->assertJsonStructure(['code', 'message', 'redirect'])
        // code 判別子 (recent_auth_required 409 との誤食防止。クライアントは code 厳格一致で処理)
        ->assertJsonPath('code', 'two_factor_required')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('redirect'))->toBe(route('settings.security'));
});

```

### tests/Architecture/TwoFactorEnforcementAllowlistTest.php (全文)

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use Illuminate\Routing\Router;

/**
 * 組織 2FA 強制ゲートの allowlist (ALLOWED_ROUTE_NAMES) の鮮度 invariant。
 *
 * allowlist は「準拠達成に必要な最小経路」の正であり、route リネーム・削除で名前が
 * 浮く (= 実は通していない) と未準拠ユーザーが 2FA を設定できなくなる。逆に理由の
 * 無いエントリは過剰許可の温床になる。実際の到達可能性 (ゲート中に 302 されない) は
 * tests/Feature/Organizations/TwoFactorEnforcementTest.php の dataset が担保する。
 */
test('allowlist の route name は全て実在する named route である', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES) as $name) {
        expect($routes->getByName($name))
            ->not->toBeNull("allowlist の route '{$name}' が存在しない (リネーム/削除に追従していない)");
    }
});

test('allowlist の各エントリは必要理由 (非空) を持つ', function (): void {
    foreach (RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES as $name => $reason) {
        expect(trim($reason))->not->toBe('', "route '{$name}' の必要理由が空 (運用劣化)");
    }
});
```

### tests/Architecture/AccountDeletionFreezeRouteGateTest.php (既存 gate の作法。冒頭の契約記述と空振り検知)

```php
/*
 * Architecture invariant: 退会予約中の**凍結 (deny-by-default)** の母集団と allowlist を固定する。
 *
 * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-B (B4)。
 *
 * 記号: `U` = 凍結 middleware (EnsureAccountNotPendingDeletion) が付いた全 route、
 *       `A` = AccountDeletionFreezeAllowance の route 名集合。**`A ⊆ U`**。
 *
 * ★この gate が保証するもの:
 *   - 検査 1: `A ⊆ U` (allowlist に `U` 外の route 名を書けない = 死に登録を作らない)
 *   - 検査 2: enum の route 名が**実在し、凍結 middleware を実際に持つ**
 *   - 検査 3: **middleware が実際に bypass する集合と `A` が exact-fit** (実装と宣言の一致)
 *   - 検査 4: **`U` に無名 route があれば fail** (名前で allowlist を書けないため)
 *   - 検査 5: enum は wildcard (`*`) を持たない / 各 case の `rationale()` が 30 文字以上
 *   - 検査 6: 母集団の内外を両方向で固定する
 *       (a) `logout` / `session.status` が `U` に**含まれない** (認証回復・離脱を凍結させない)
 *       (b) `recent-auth.confirm` / `.status` / `.password` が `U` に**含まれる**
 *           (group の外へ移されたら allowlist が死に登録になる)
 *   - 検査 7: **`BillingPortal` を allowlist に置く前提の pin** —
 *       `PortalConfigurationSpec` の `subscription_update.enabled === false` /
 *       `subscription_cancel.enabled === true` / `subscription_cancel.mode === 'at_period_end'`。
 *       `billing:ensure-portal-configuration --verify` が保証するのは「Stripe 側設定と spec の
 *       **一致**」だけなので、**spec 自体を書き換えると正しい設定として受け入れられうる**。
 *       よって allowlist 登録の前提を behavioral に固定する
 *       (`ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同じ作法)
 *   - 検査 8: **即時削除 / 通知 open / 自動チャージ更新が `A` に無い**ことの名指し pin
 *   - 空振り検知: `U` の件数 floor / `A` の件数 exact / 母集団 0 件で fail
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **実 HTTP での遮断挙動**は見ない (route を実際に叩く全件 sweep と、
 *     取消・ブロッカー解消への到達性は `tests/Feature/Auth/AccountDeletionFreezeTest.php` の担当。
 *     Architecture lane は DB を使わないため、ここでは middleware を直接駆動して
 *     bypass 集合だけを測る)
 *   - **middleware の実行順序**は見ない (テナント境界 404 より後であることの固定は
 *     `TenantBoundaryOrderingTest` の担当)
 *   - route cache 済み起動での配線 (group 直付けなので cache に焼き込まれるが、
 *     「毎デプロイ route:cache を再生成する」運用要件そのものは機械化できない)
 *
 * DB 不使用 (Architecture lane は TestCase のみ)。
 */

/** `U` の件数下限 (空振り防止)。業務 route が丸ごと group から外れたら赤くなる。 */
const FREEZE_POPULATION_FLOOR = 60;

/** `A` の件数 (exact-fit。増減させたらこの数値も同じ diff で書き換わる)。 */
const FREEZE_ALLOWANCE_COUNT = 17;

test('空振り検知: 母集団と allowlist の件数を pin する', function (): void {
    $population = freezePopulation();

    expect(count($population))->toBeGreaterThan(FREEZE_POPULATION_FLOOR,
        '凍結母集団が想定より小さいです。group から middleware が外れていないか確認してください '
        .'(母集団が 0 件でも検査 1 は緑になるため、下限で pin します)。');
    expect(AccountDeletionFreezeAllowance::cases())->toHaveCount(FREEZE_ALLOWANCE_COUNT,
        '通す route を増減させたら FREEZE_ALLOWANCE_COUNT も同じ差分で書き換えてください '
        .'(「以下」ではなく「一致」で固定するのは、根拠なしに通せる余裕枠を作らないため)。');

    // 検査 3 の駆動器が死んでいたら vacuous green になる。正負を 1 本ずつ実測する。
    $user = freezePendingUser();
    expect(freezeMiddlewarePasses('settings', $user))->toBeTrue();
    expect(freezeMiddlewarePasses('dashboard', $user))->toBeFalse();

    // 未予約ユーザーは何も凍結されない (middleware が常時 deny になっていないことの対照)
    expect(freezeMiddlewarePasses('dashboard', new User))->toBeTrue();
});
```

### bug-hunt F-4-01 の再現手順 (shard-4 レポート抜粋)

## F-4-01: 2FA必須組織の未準拠ユーザーが「退会を取り消す」を押すと、取消と無関係な2FA案内へ無言で差し替わり、取消できたかどうか分からない
- severity: High
- story/step: S6-4 (アカウント削除 猶予期間) × S6-2 (2FA必須) の相互作用
- 再現手順:
  1. owner-personal@example.com でログイン (password123)。`/organizations/personal-mi2f5h/settings` で
     2要素認証を有効化 (TOTP)。同じ組織設定画面で「2 段階認証の必須化」を有効にする。
  2. 別アカウント member-personal@example.com でログイン (2FA 必須化の**前**に、`/settings` で
     「30日後に削除 (取り消せます)」を押して退会を予約しておく)。
  3. member-personal でいったんログアウトし、owner が 2FA 必須化を ON にした**後**に
     member-personal で再ログインする (この時点で member はまだ 2FA 未設定 = 非準拠)。
     ログイン直後 `/settings/security` へ強制遷移し「組織「Personalプラン組織」は
     2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。」と出る
     (ここまでは説明があり OK)。
  4. `/settings` に直接遷移すると (route 名 `settings` は 2FA ゲートの許可リストに入っているため) 到達でき、
     「退会を予約しています」の状態表示と「退会を取り消す」ボタンが**普通に操作可能な見た目で**表示される。
  5. 「退会を取り消す」ボタンをクリックする
     (`DELETE /settings/account/deletion-request` → `RequireTwoFactorForEnforcedOrganizations` が
     このルート名を許可リストに含まないため 303 で `/settings/security` へリダイレクト)。
  6. 画面は `/settings/security` に切り替わり、toast/info で
     「組織「Personalプラン組織」は 2 段階認証を必須としています。設定が完了するまで他のページは
     ご利用いただけません。」とだけ出る。**「退会」「取消」という語は一切出てこない。**
     退会予約が取り消されたのか、まだ有効なのかはこの画面から判断できない。
- 期待: 「取消」ボタンを押した結果として出るメッセージは、少なくとも
  (a) 退会予約はまだ有効であること、(b) なぜ今操作できないか (2FA未設定) の**両方**を伝えるべき。
  可能なら「2FA を設定すると退会の取消操作を続けられます」のように、押した操作へ話をつなげる文言が要る。
- 実際: 押した操作 (退会取消) と表示される案内 (2FA必須化) が文言上まったく接続されておらず、
  ユーザーは「取り消せた」のか「取り消せていない」のか、この画面のどこにも書かれていないため
  分からない (実際には取り消されておらず、予約は生きたまま)。H1 (説明なしリダイレクト) 相当。
  同じ文言 (組織「〜」は2要素認証を必須としています…) が退会取消のときも 2FA が要る他の操作のときも
  一律で出るため、**押した操作が何だったかの手がかりが画面から失われる**。
- 阻害されたユーザージョブ: 「予約してしまった退会を取り消したい」という、猶予期間つき削除の設計が
  最も重視している救済導線 (「取消に step-up を課さない」という設計判断がある領域) が、
  2FA 必須組織下では実質的に見た目上「押しても反応が説明されないボタン」になる。
  ユーザーは自分の退会予約が有効なままだと気づかず 30 日後に削除されるリスク、または
  何度も無意味に「取消」ボタンを押し続けるリスクがある。
- 改善アクション候補:
  - `RequireTwoFactorForEnforcedOrganizations` のリダイレクト/409 メッセージに、元々何をしようとしていたか
    (route 名やコンテキスト) を埋め込めるようにする、または
  - `settings.account.deletion-request.destroy` (取消) を 2FA ゲートの許可リストに追加する
    (取消は「離脱系」操作であり、`logout` 等と同様に 2FA 未設定でも通す設計判断もありうる。
    ただし `two-factor.disable` を意図的に許可リストへ入れていない既存判断
    「ゲート解除手段の濫用防止」との整合は要検討)、または
  - 少なくともクライアント側で「取消を試みたが 2FA 未設定でブロックされた」ことを検知し、
    `/settings` 側の退会バナーに「2FA 設定が必要です」という文言を先出しして
    ボタンを押す前に気づかせる (`disabled` にしてツールチップで理由を示す等)。
- 証跡: network: `DELETE http://127.0.0.1:8014/settings/account/deletion-request => 303`
  (直後 `GET /settings/security => 200`),
  feedback-probe (取消クリック直後): `installed_now=true seen(visible:true)=1 present_new=2 pending=0 errors=0`
  (present_new の内訳は toast-info の「2段階認証を必須としています」のみで、退会/取消に言及する文言なし)。
  ※ 本 finding 発生後、member-personal は 2FA 準拠済みに変わってしまい (テスト手順上必要だったため)、
  再現には別途「2FA必須組織×未準拠×退会予約中」の状態を作り直す必要がある。screenshot は未取得
  (取得を試みた回で `playwright-cli screenshot` の引数仕様 (`--filename` 必須) を誤り撮り損ねた)。
  再現手順は上記で全ステップ記載済みのため、network trace と probe 証跡で代替する。
- 推定原因: `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` の
  `ALLOWED_ROUTE_NAMES` に `settings.account.deletion-request.destroy` (取消) が含まれていないため、
  凍結許可リスト (`AccountDeletionFreezeAllowance`) 側では通っていても、優先度が先に走る 2FA ゲートで
  弾かれる。ゲートのリダイレクト先メッセージが汎用文言のみで、遮断された元操作のコンテキストを持たない。
  (docs/architecture.md の「2FA 必須組織との相互作用」節が明示的に「取消 DELETE は 2FA ゲートが
  settings.security へ倒す」設計だと記述しており、**永久な詰みではない** — 2FA を完了すれば取消できることは
  実機で確認済み。ただし「取消と無関係に見えるメッセージだけが出る」UX 上の分かりにくさは
  ドキュメントが明言していない残存論点)。
- 関連既知情報: `docs/architecture.md` §退会の猶予期間つき削除 (凍結方式・30 日) 「2FA 必須組織との相互作用」
  (T142 で発見・修正されたのは「settings.security 自体への到達性」であり、
  今回の finding はその一段先の「取消操作の結果文言の分かりにくさ」で、既存記述からは
  fix 済みと明言されていない別論点)。
