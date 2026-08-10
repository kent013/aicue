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
| 3 | 救済 route まわりのゲート分類を deny-by-default 目録で固定 | `app/Enums/Security/RescueRouteGateDisposition.php` (新) / `app/Enums/Security/RescueRouteGateKind.php` (新) / `tests/Architecture/RescueRouteGateInventoryTest.php` (新) | Medium |
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

### セキュリティ境界の差分 (この施策が変えるもの / 変えないもの)

- **本施策によって新たに allowlist へ追加する変更系 route は、退会予約の取消 1 本だけ**である。
- 既存の「2FA 準拠達成・認証回復・離脱」に必要な allowlist route
  (`two-factor.enable` / `two-factor.confirm` / `two-factor.regenerate-recovery-codes` /
  `recent-auth.password` / `passkey.confirm` / `verification.send` / `logout` 等) は**一切変更しない**
  — これらは元から変更系を含んでおり、本施策の前後で同じである。
- 予約作成 (`…deletion-request.store`) / 即時削除 (`settings.account.destroy`) /
  2FA 無効化 (`two-factor.disable`) は**施策 4 の名指し pin で機械固定する**。
  その他の業務 route を追加しないことは、**本施策の allowlist 差分が退会取消 1 本だけである**ことによって
  確認する (施策 4 は業務 route 全般を網羅的に分類する検査ではない — ここを誇張しない)。
- この差分を「**未準拠でもアカウント操作全般ができるようになる**」と要約しない。

### PHPStan 適合チェック

- [x] 定数配列の shape は `array<string, string>` のまま (docblock 既存)
- [x] null 安全性の新規論点なし
- [x] DTO 返却の論点なし (定数追加のみ)

### リスク

- **能力は 1 つ増える (過小評価しない)**。これまで遮断されていた「2FA 必須組織の未準拠ユーザーの
  認証済みセッションによる退会取消」が実行できるようになる。**新しい能力付与であることを認めた上で受容する**。
  受容の根拠:
  - 対象は **self スコープの取消 1 操作のみ** (route parameter が無く他者に届かない。
    `ControllerAuthorizationGateTest` の `SelfScopedResource` 登録済み)
  - **CSRF 保護下**にある (`PreventRequestForgery` は本ゲートより前で走る)
  - **権限・認証手段・2FA 準拠判定 (`two_factor_confirmed_at`) を一切増減させない**
  - 取消後も全業務面は遮断されたまま (施策 5 の負のコントロールで実測固定)
  - セッション奪取者が取消できる点は `AccountDeletionRequestController` docblock で既に受容済み
    (失われるのは意思表示だけで本人は再予約できる。逆に本人が救済できない方が被害が重い)

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

## 施策 3: 救済 route まわりのゲート分類を deny-by-default 目録で固定

### 新しい不変条件 (名前と保証範囲を一致させる)

> **救済 route (`settings.account.deletion-request.destroy`) の経路上にある**
> **「自前 middleware すべて」と「名指しした認証系 vendor gate 3 本」は、その救済 route に対する扱いを**
> **目録に宣言してあること。**

⚠ これは「**route 上の全 middleware を通過できる**」という保証**ではない** (CSRF 419 / session 開始 /
binding 404 / Inertia 履歴暗号化は母集団外)。目録が守るのは**分類の網羅**であって通過の全称証明ではない。
命名もそれに合わせる (gate 名 = `RescueRouteGateInventoryTest` = **目録**であり `…PassageTest` ではない)。

### 変更ファイル (新規 3 本)

- `app/Enums/Security/RescueRouteGateDisposition.php`
- `app/Enums/Security/RescueRouteGateKind.php` (**独立ファイル**。同一ファイルへの 2 enum 定義は
  autoload 順に依存しうるため分ける)
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
use LogicException;

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

`RescueRouteGateKind` (`app/Enums/Security/RescueRouteGateKind.php` — **独立ファイル**) は 3 値:

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

### 波及変更 (「既存テストの削除・上書き」禁止との関係を明示)

> ⚠ 番号の混線に注意: ここで言う禁止は **`app-design` スキルの禁止事項表 #3「既存テストの削除・上書き」**である
> (AGENTS.md の禁止事項 3 は dev DB への破壊操作であり別物)。

`tests/Feature/Auth/AccountDeletionFreezeTest.php` の
`test('2FA 未準拠ユーザーは 2FA ゲートが先に効くが、設定画面へ到達できる (詰みではない)')` は
**現行の遮断挙動を意図的に pin している**ため、施策 1 で契約が変わると赤くなる。

- これは「検証を消して緑にする」行為 (同禁止の趣旨) ではなく、**契約変更に伴う期待値の更新**である。
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
  - `withSession(freshRecentAuthSession())->postJson('/settings/account/deletion-request')` → 409 /
    `message` が prefix で始まる / `code` = `two_factor_required` / `redirect` = `settings.security` /
    `Cache-Control: no-store, private`
  - ★実測した resolve 順では 2FA ゲートが `RequireRecentAuth` より**先**に走る (probe 出力を参照) が、
    **順序に依存しないテストにする**ため step-up 済みセッションを与える (上の HTML 版も同様)
- [追加] `test('退会取消は allowlist 経由でゲートを通り 2FA 状態を変えない')`
  - 未準拠 + 未予約で `from('/settings')->delete(...)` → `assertRedirect('/settings')`
    (controller は `back()` を返すため Referer を明示して UI 導線と一致させる。冪等 no-op)
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
11. **2FA 未準拠セッションで状態変更が一切起きないことは保証しない**。準拠達成・認証回復・離脱に必要な
    既存 allowlist route (`two-factor.enable` / `two-factor.confirm` /
    `two-factor.regenerate-recovery-codes` / `recent-auth.password` / `passkey.confirm` /
    `verification.send` / `logout` など) は**従来どおり許可される**。
    本施策が変えるのは「その集合に退会取消を 1 本足した」ことだけである
    (変更範囲の正確な記述は施策 1 の「セキュリティ境界の差分」節)。

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
