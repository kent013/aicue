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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 実装レビュアー

あなたは Laravel 12 + Svelte 5 + Inertia アプリ (aicue) のコードレビュアーである。
以下の**詳細設計書**(Codex 合議で APPROVED 済み)に対する**実装差分**をレビューせよ。

## レビュー観点

1. **設計との一致性** — 設計の施策 1〜6 が実装されているか。設計が**明示的に却下したもの**
   (route→操作名の写像表 / メッセージレジストリ / 共通サービス / 他 middleware への波及) を
   勝手に作っていないか。
2. **正確性** — middleware の分岐、allowlist の内容、enum の分類が正しいか。
   セキュリティ境界を意図せず広げていないか。
3. **PHPStan level 10 適合性** (`@phpstan-ignore` / baseline / 型の widen は禁止)。
4. **DTO / JsonResource パターン** の遵守 (`response()->json()` 直書き禁止)。
5. **テスト網羅性** — 施策ごとにテストがあるか。**空振り (vacuous green)** しないか。
   負のコントロールが効いているか。mutation evidence の主張が妥当か。
6. **セキュリティ** — 2FA 強制ゲートの allowlist に「退会予約の取消」を 1 本足したことの是非。
   救済と業務の非対称が維持されているか。
7. **ドキュメントの正確性** — `docs/architecture.md` / `docs/auth-security-mechanisms.md` の記述が
   **誇張していないか** (「2FA 未準拠は取消以外の状態変更を一切できない」は**誤り**。
   既存 allowlist には `two-factor.enable` / `recent-auth.password` / `passkey.confirm` 等の
   変更系が元から含まれる)。

## 出力形式

- ファイルごとに判定
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類
- 最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記

## 補足 (前提を誤解しないために)

- フロントエンド差分は**ゼロ**である (`resources/` に変更なし)。DESIGN.md / Atomic Design の
  観点はこの diff に適用対象が無い。
- 設計は Browser lane (Chromium + WebKit) の追加を**しない**と決めている
  (フロント差分ゼロ・描画経路は既存 `tests/Browser/FlashToastTest.php` がカバー)。

---

# user

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


## 実装差分 (git diff)

```diff
diff --git a/app/Enums/Security/RescueRouteGateDisposition.php b/app/Enums/Security/RescueRouteGateDisposition.php
new file mode 100644
index 0000000..96af7d7
--- /dev/null
+++ b/app/Enums/Security/RescueRouteGateDisposition.php
@@ -0,0 +1,114 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+use App\Enums\Account\AccountDeletionFreezeAllowance;
+use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
+use LogicException;
+
+/**
+ * 救済 route (退会予約の取消) の経路上にある middleware が、その救済 route をどう扱うかの宣言。
+ * **deny-by-default** (`RescueRouteGateInventoryTest` が母集団と exact-fit で照合する)。
+ *
+ * 背景: bug-hunt F-4-01。凍結 middleware は取消を通していたのに、実行順が先の 2FA 強制ゲートが
+ * 弾いていた = **ゲート同士で判断が食い違っていた**。人の記憶ではなく目録で守る。
+ *
+ * ★保証する不変条件は「**分類の網羅**」であって「route 上の全 middleware を通過できること」ではない。
+ *   母集団は「自前 middleware 全件 + 名指しした認証系 vendor gate 3 本」であり、
+ *   cookie / session 開始 / CSRF / binding / Inertia 履歴暗号化は**入れていない**
+ *   (Laravel 側のクラス名変更・構成変更で偽赤にしないため。実際 CSRF は L12 で
+ *   `PreventRequestForgery` に改名されている)。
+ */
+enum RescueRouteGateDisposition: string
+{
+    /** 救済経路そのもの (この route 名で照合する)。 */
+    public const string RESCUE_ROUTE_NAME = 'settings.account.deletion-request.destroy';
+
+    case Authenticate = 'Illuminate\Auth\Middleware\Authenticate';
+    case AuthenticateSession = 'Illuminate\Session\Middleware\AuthenticateSession';
+    case EnsureEmailIsVerified = 'Illuminate\Auth\Middleware\EnsureEmailIsVerified';
+    case HandleInertiaRequests = 'App\Http\Middleware\HandleInertiaRequests';
+    case SecurityHeaders = 'App\Http\Middleware\SecurityHeaders';
+    case RequireTwoFactor = 'App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations';
+    case BlockTwoFactorDisable = 'App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations';
+    case NoStoreCacheHeaders = 'App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages';
+    case NotPendingDeletion = 'App\Http\Middleware\EnsureAccountNotPendingDeletion';
+
+    /** この middleware が救済 route をどう扱うかの分類。 */
+    public function disposition(): RescueRouteGateKind
+    {
+        return match ($this) {
+            self::RequireTwoFactor, self::NotPendingDeletion => RescueRouteGateKind::PassesRescueRoute,
+            self::Authenticate, self::AuthenticateSession, self::EnsureEmailIsVerified => RescueRouteGateKind::ShortCircuitsButEscapable,
+            self::HandleInertiaRequests, self::SecurityHeaders, self::BlockTwoFactorDisable,
+            self::NoStoreCacheHeaders => RescueRouteGateKind::NeverShortCircuitsRescueRoute,
+        };
+    }
+
+    /**
+     * 分類の根拠 (**30 文字以上**。gate が長さを検査する)。
+     *
+     * 「なぜ救済 (誤操作した退会予約の取消) が失われないか」を 1 case ずつ書く。
+     */
+    public function rationale(): string
+    {
+        return match ($this) {
+            self::Authenticate => '未認証セッションは login へ倒れる。ログイン面は凍結母集団の外かつ '
+                .'2FA 強制ゲートの対象外 (未認証は素通し) なので、ログインし直せば救済 route へ戻れる。'
+                .'救済の権利は失われず、遅延するだけである。',
+            self::AuthenticateSession => 'パスワード変更等で他セッションが失効すると login へ倒れるが、'
+                .'再ログインで救済 route に戻れる (予約は users 行に永続しており消えない)。'
+                .'凍結方式は users 行の生死を変えないため、待っている間も取消の対象は残る。',
+            self::EnsureEmailIsVerified => 'メール未検証なら verification.notice へ倒れる。同 route は'
+                .'凍結 allowlist の対象外 (母集団に入っていない) かつ 2FA ゲートの allowlist 内なので、'
+                .'検証を済ませれば救済 route へ到達できる。',
+            self::HandleInertiaRequests => '共有 props の組み立てとバージョン整合のみ。asset 版不一致に'
+                .'よる 409 強制再読込は **GET のみ**が対象で、救済 route は DELETE のため該当しない。'
+                .'よってこの middleware が救済を短絡させる経路は存在しない。',
+            self::SecurityHeaders => '応答ヘッダ (CSP 等) を付けるだけで、リクエストを短絡させる分岐を'
+                .'持たない。$next の戻り値を加工する後段処理のみであり、救済 route の到達性に影響しない。',
+            self::RequireTwoFactor => '2FA 必須組織の未準拠ユーザーを settings.security へ倒すゲート。'
+                .'救済 route は ALLOWED_ROUTE_NAMES に明示登録して通す (F-4-01 の主措置)。'
+                .'**non-exemptible** — ここを「通さない」に格下げすると finding が再発する。',
+            self::BlockTwoFactorDisable => '判定対象は two-factor.disable 1 本のみで、他の route 名では'
+                .'無条件に素通しする。救済 route の名前とは一致しないため短絡経路が構造的に無い。',
+            self::NoStoreCacheHeaders => '認証済みページに Cache-Control: no-store を付けるだけの'
+                .'応答加工であり、リクエストを短絡させる分岐を持たない。救済の到達性に影響しない。',
+            self::NotPendingDeletion => '退会予約中の凍結ゲート。救済 route は '
+                .'AccountDeletionFreezeAllowance::DeletionRequestDestroy として登録済みで、'
+                .'凍結中に必ず実行できなければ猶予期間の意味が消える。**non-exemptible**。',
+        };
+    }
+
+    /**
+     * 実装側の allowlist を実際に引いて、この middleware が当該 route 名を通すかを返す。
+     *
+     * `PassesRescueRoute` の case だけが true/false を返せる (宣言だけで緑にならないための構造)。
+     * それ以外の disposition では `LogicException` を投げる。
+     */
+    public function permitsRouteName(string $routeName): bool
+    {
+        return match ($this) {
+            self::RequireTwoFactor => array_key_exists(
+                $routeName,
+                RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES,
+            ),
+            self::NotPendingDeletion => AccountDeletionFreezeAllowance::tryFrom($routeName) !== null,
+            default => throw new LogicException(
+                'permitsRouteName は PassesRescueRoute の case でのみ使える: '.$this->value,
+            ),
+        };
+    }
+
+    /**
+     * 目録に登録された middleware クラス名の集合。
+     *
+     * @return list<string>
+     */
+    public static function values(): array
+    {
+        return array_map(static fn (self $case): string => $case->value, self::cases());
+    }
+}
diff --git a/app/Enums/Security/RescueRouteGateKind.php b/app/Enums/Security/RescueRouteGateKind.php
new file mode 100644
index 0000000..8ccdc58
--- /dev/null
+++ b/app/Enums/Security/RescueRouteGateKind.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 救済 route に対する middleware の扱いの分類 (3 値)。
+ *
+ * `RescueRouteGateDisposition` の各 case がこのどれかを宣言する。
+ * ★**機械照合できるのは `PassesRescueRoute` だけ**である (実装の allowlist を実際に引く)。
+ *   残る 2 値は**人手申告**であり、middleware 本体に新しい短絡分岐が足されても沈黙する。
+ *   この非対称を「経路上の全 middleware を通過できることの保証」と誇張しない。
+ */
+enum RescueRouteGateKind: string
+{
+    /** 短絡しうるゲートだが、救済 route を allowlist で明示的に通す (機械照合の対象)。 */
+    case PassesRescueRoute = 'passes_rescue_route';
+
+    /** 救済 route を短絡させうるが、短絡先で本人が条件を解消でき、その解消面は救済経路の外にある。 */
+    case ShortCircuitsButEscapable = 'short_circuits_but_escapable';
+
+    /** この route を短絡させない (応答加工のみ / 他 route 名限定の判定)。 */
+    case NeverShortCircuitsRescueRoute = 'never_short_circuits_rescue_route';
+}
diff --git a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
index bd2c378..b6bf21b 100644
--- a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
+++ b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
@@ -67,11 +67,34 @@ final class RequireTwoFactorForEnforcedOrganizations
         'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
         'social.callback' => 'SSO step-up の callback',
         'logout' => '離脱は常に可能',
+        // 退会予約の**取消**は「業務の利用」ではなく**誤操作の救済**である (bug-hunt F-4-01)。
+        // 凍結側 (AccountDeletionFreezeAllowance::DeletionRequestDestroy) は同じ問いに
+        // 「凍結中に必ず実行できなければ猶予期間を設けた意味が消える」と結論しており、
+        // 実行順が先の本ゲートだけがそれを覆していた (priority list で本ゲートが凍結より前)。
+        // 通しても (a) 業務面には到達できないまま (b) 認証手段は増減しない
+        // (c) 準拠判定は two_factor_confirmed_at のみが決める、ため 2FA 必須の効力は変わらない。
+        // 逆に塞ぐと deletion_purge_after (絶対時刻) が走り続け、本ゲートが
+        // **不可逆な物理削除の後押し**になる (「使えない」を超えて「消える」に化ける)。
+        // ★**予約 (…deletion-request.store) と即時削除 (settings.account.destroy) は入れない**
+        //   — どちらも救済ではなく、遮断されても失われるのは意思表示だけである。
+        'settings.account.deletion-request.destroy' => '退会予約の取消 (誤操作救済。凍結 allowlist と判断を揃える)',
         'verification.notice' => 'verified middleware との redirect 競合回避',
         'verification.verify' => 'メール検証リンクの踏破',
         'verification.send' => '検証メール再送',
     ];
 
+    /**
+     * 遮断されたのが**書き込み要求**だったときに文頭へ付ける固定文。
+     *
+     * 本 middleware は controller より前で短絡するため、この主張は構造的に真である
+     * (= 対象 controller に到達しておらず、ドメイン状態は変化していない)。
+     * ★**「副作用が一切ない」ことは主張しない** — session 書き込み・throttle 記録・
+     *   CSRF 検証はこの短絡でも起こりうる。ここで言う「操作」は controller が行う業務処理を指す。
+     * ★route 名 → 日本語の操作名の写像表は**持たない** (二重管理になり route 追加のたびに腐る)。
+     *   伝えるのは「起きなかった」ことだけで、「何をしようとしたか」は伝えない。
+     */
+    public const string BLOCKED_WRITE_PREFIX = '直前の操作は実行されていません。';
+
     public function handle(Request $request, Closure $next): Response
     {
         $user = $request->user();
@@ -93,6 +116,13 @@ public function handle(Request $request, Closure $next): Response
 
         $message = "組織「{$enforcingOrganization->name}」は 2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。";
 
+        // 安全メソッド (GET/HEAD/OPTIONS/TRACE) は「ページに来られない」だけなので既存文言のまま。
+        // 非安全メソッドはユーザーがボタンを押した結果なので、**何が起きなかったか**を先に言う
+        // (F-4-01: 押した結果が分からないまま予約が生き残る、が High の理由)。
+        if (! $request->isMethodSafe()) {
+            $message = self::BLOCKED_WRITE_PREFIX.$message;
+        }
+
         // XHR/JSON は RequireRecentAuth と同形の 409 + { code, message, redirect } (no-store)。
         // SPA の非画面 fetch に HTML リダイレクトを返さない
         if ($request->expectsJson()) {
diff --git a/docs/architecture.md b/docs/architecture.md
index 7454eb4..86473cd 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1469,9 +1469,24 @@ ## 退会の猶予期間つき削除 (凍結方式・30 日)
   T142 で実測)。保留が滞留すると 30 日を過ぎた予約が消えないままになるので、
   `blocked` の継続・増加を正常成功として扱わない。
 - **2FA 必須組織との相互作用**: 2FA 強制ゲートは priority list で凍結より**前**に走る。
-  未準拠ユーザーの取消 DELETE は 2FA ゲートが `settings.security` へ倒すため、
-  **`settings.security` を凍結の allowlist に入れないと「取消は 2FA ゲート / 2FA 設定は凍結」の
-  相互ブロックで詰む** (T142 で実測して発見)。allowlist に入っているのはこの理由である。
+  取消は**業務の利用ではなく誤操作の救済**なので、**両ゲートの allowlist に入っている**
+  (凍結側 = `AccountDeletionFreezeAllowance::DeletionRequestDestroy`、2FA 側 =
+  `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`)。かつては 2FA 側にだけ
+  無く、未準拠ユーザーの取消 DELETE が `settings.security` へ倒れて
+  「取り消したつもりで取り消せていない」状態になっていた (T149 / bug-hunt F-4-01 で実測)。
+  通しても業務面には到達できないまま・認証手段は増減しない・準拠判定
+  (`two_factor_confirmed_at`) も動かないため、2FA 必須の効力は変わらない。
+  なお **`settings.security` を凍結の allowlist に入れる理由は据え置き**である
+  (未準拠ユーザーが 2FA 設定に到達できないと準拠達成そのものが詰む。T142 で実測して発見)。
+  この**ゲート間の判断の一致**は `RescueRouteGateInventoryTest` (救済 route の経路上の
+  ゲートに分類の宣言を強制する deny-by-default 目録) が機械固定する。
+  ⚠ 同目録が守るのは**分類の網羅**であって「経路上の全 middleware を通過できる」ことではない。
+- **遮断メッセージ**: 2FA ゲートが**非安全メソッド**を短絡したときだけ、文頭に固定文
+  `RequireTwoFactorForEnforcedOrganizations::BLOCKED_WRITE_PREFIX` (「直前の操作は
+  実行されていません。」) を付ける。**遮断メッセージが元操作を名指しすることは保証しない**
+  (route 名 → 操作名の写像表は持たない = 二重管理を作らない)。また
+  「副作用が一切ない」ことも主張しない (session 書き込み・throttle 記録・CSRF 検証は
+  短絡時にも起こりうる)。主張の範囲は「controller に到達していない」までである。
 - **保証しないもの (誇張しない)**: 凍結は**アプリの web route だけ**に効く。
   `api/v1` / MCP / OAuth token 経由の経路には**沈黙する** (母集団に入っていない)。
   通知の重複配送も止めない (保証しているのは「予約操作からの job 生成は最大 1 件」まで)。
diff --git a/docs/auth-security-mechanisms.md b/docs/auth-security-mechanisms.md
index bac4be7..80e5db5 100644
--- a/docs/auth-security-mechanisms.md
+++ b/docs/auth-security-mechanisms.md
@@ -109,9 +109,12 @@ ### 2 段防御の契約
 
 **(1) 未準拠ユーザーの全画面ゲート** (`RequireTwoFactorForEnforcedOrganizations`):
 1 つでも `two_factor_required` な組織に所属する **2FA 未完了 (disabled / pending)** ユーザーは、
-`ALLOWED_ROUTE_NAMES` (2FA 設定達成に必要な route + logout / メール検証 / step-up 等) 以外の全 web 経路から
+`ALLOWED_ROUTE_NAMES` (2FA 設定達成に必要な route + logout / メール検証 / step-up
+**+ 救済経路 (退会予約の取消 `settings.account.deletion-request.destroy`)** 等) 以外の全 web 経路から
 `settings.security` へ `302` (XHR は `409` + `{ code, message, redirect }`) される。組織スコープの部分制限は採らない
 (2FA はアカウント全体の属性のため)。準拠 (enabled) ユーザーは attribute 判定のみで追加クエリゼロ。
+遮断されたのが**非安全メソッド** (`! isMethodSafe()`) のときだけ、302 / 409 の文言の先頭に
+`BLOCKED_WRITE_PREFIX` (「直前の操作は実行されていません。」) が付く (元操作の名指しはしない)。
 
 **(2) 準拠ユーザーの self-disable 禁止** (`BlockTwoFactorDisableForEnforcedOrganizations`):
 準拠ユーザーが `DELETE /user/two-factor-authentication` (`two-factor.disable`) を打つと action 自体は通ってしまい、
diff --git a/tests/Architecture/RescueRouteGateInventoryTest.php b/tests/Architecture/RescueRouteGateInventoryTest.php
new file mode 100644
index 0000000..07ff915
--- /dev/null
+++ b/tests/Architecture/RescueRouteGateInventoryTest.php
@@ -0,0 +1,199 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\RescueRouteGateDisposition;
+use App\Enums\Security\RescueRouteGateKind;
+use Illuminate\Routing\Router;
+
+/*
+ * Architecture invariant: **救済 route (退会予約の取消) の経路上にあるゲートは、
+ * その救済 route に対する扱いを目録に宣言してあること** (deny-by-default)。
+ *
+ * SoT = devnotes/20260811-0146-blocked-action-context/detailed-design.md の施策 3。
+ * 背景 = bug-hunt run 20260811-003230 の F-4-01: 凍結 middleware は取消を通していたのに、
+ * priority list で先に走る 2FA 強制ゲートが弾いていた (**ゲート同士で判断が食い違っていた**)。
+ *
+ * 記号: `U` = ( 救済 route の resolve 済み middleware ∩ App\Http\Middleware\ )
+ *              ∪ { Authenticate, AuthenticateSession, EnsureEmailIsVerified }
+ *       `D` = RescueRouteGateDisposition の値集合。**`U` と `D` は exact-fit**。
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: `U` と `D` の exact-fit (自前ゲートの新設・撤去に必ず分類判断を強制する)
+ *   - 検査 2: `PassesRescueRoute` の宣言が**実装の allowlist と一致**する (機械照合)
+ *   - 検査 3: 2FA ゲートと凍結 middleware は `PassesRescueRoute` から**動かせない** (名指し)
+ *   - 検査 4: 全 case の `rationale()` が 30 文字以上
+ *   - 検査 5: 名指しした vendor 認証ゲート 3 本が**実際に route に付き、母集団に入る**
+ *   - 空振り検知: 母集団件数 exact / route 不在で明示 fail / 照合器の負のコントロール
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **「route 上の全 middleware を通過できる」ことは保証しない**。守るのは**分類の網羅**で
+ *     あって通過の全称証明ではない (だから名前は …PassageTest ではなく …InventoryTest)。
+ *     CSRF 419 / session 開始 / binding 404 / Inertia 履歴暗号化は母集団に入れていない。
+ *   - **vendor は名指し 3 本のみ**。vendor 側に新しい短絡 middleware が増えても沈黙する。
+ *   - **人手申告の分類 2 種** (ShortCircuitsButEscapable / NeverShortCircuitsRescueRoute) は
+ *     middleware 本体の改変 (新しい短絡分岐の追加) に沈黙する。機械照合できるのは
+ *     `PassesRescueRoute` の allowlist 一致だけである。
+ *   - **母集団は救済 route 1 本だけ**。他の救済的 route のゲート通過性は見ない。
+ *
+ * DB 不使用 (Architecture lane は TestCase のみ)。
+ */
+
+/** 母集団 `U` の件数 (exact。middleware の増減を必ずレビューに出す)。 */
+const RESCUE_GATE_POPULATION_COUNT = 9;
+
+/**
+ * 母集団に名指しで加える vendor 認証ゲート。
+ *
+ * この 3 本だけがこの route を実際に短絡させうる vendor middleware である。
+ * cookie / session 開始 / CSRF / binding / Inertia 履歴暗号化は入れない
+ * (Laravel 側のクラス名変更・構成変更で偽赤にしないため)。
+ *
+ * @var list<string>
+ */
+const RESCUE_GATE_NAMED_VENDOR = [
+    'Illuminate\Auth\Middleware\Authenticate',
+    'Illuminate\Session\Middleware\AuthenticateSession',
+    'Illuminate\Auth\Middleware\EnsureEmailIsVerified',
+];
+
+/**
+ * 救済 route の resolve 済み middleware (priority 適用後) を返す。
+ *
+ * route が存在しなければ**明示的に fail** させる (route リネームで gate が黙って緑になるのを防ぐ)。
+ *
+ * @return list<string>
+ */
+function rescueRouteResolvedMiddleware(): array
+{
+    /** @var Router $router */
+    $router = app('router');
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    $route = $routes->getByName(RescueRouteGateDisposition::RESCUE_ROUTE_NAME);
+    expect($route)->not->toBeNull(
+        '救済 route "'.RescueRouteGateDisposition::RESCUE_ROUTE_NAME.'" が存在しません。'
+        .'リネーム / 削除で目録の母集団が空になると、この gate は何も守らなくなります。');
+
+    $resolved = [];
+    foreach ($router->gatherRouteMiddleware($route) as $middleware) {
+        if (! is_string($middleware)) {
+            continue;
+        }
+        // 'throttle:60,1' のようなパラメータ付きは ':' の前で正規化する
+        $resolved[] = str_contains($middleware, ':') ? strstr($middleware, ':', true) : $middleware;
+    }
+
+    return array_values(array_unique(array_filter($resolved, static fn (string $m): bool => $m !== '')));
+}
+
+/**
+ * 母集団 `U` = 自前 middleware 全件 + 名指し vendor 3 本。
+ *
+ * @return list<string>
+ */
+function rescueRouteGatePopulation(): array
+{
+    $own = array_values(array_filter(
+        rescueRouteResolvedMiddleware(),
+        static fn (string $m): bool => str_starts_with($m, 'App\Http\Middleware\\'),
+    ));
+
+    return array_values(array_unique([...$own, ...RESCUE_GATE_NAMED_VENDOR]));
+}
+
+test('検査 1: 救済 route のゲート母集団と目録が exact-fit である', function (): void {
+    $population = rescueRouteGatePopulation();
+    $declared = RescueRouteGateDisposition::values();
+
+    $undeclared = array_values(array_diff($population, $declared));
+    $stale = array_values(array_diff($declared, $population));
+
+    expect($undeclared)->toBe([],
+        '救済 route の経路上に、目録へ分類が宣言されていない middleware があります。'
+        .'新しいゲートを web group に足したら「この救済 route をどう扱うか」を必ず宣言してください '
+        .'(F-4-01 はこの宣言が無かったために起きた): '.implode(', ', $undeclared));
+    expect($stale)->toBe([],
+        '目録に、救済 route の経路上に存在しない middleware が残っています (死に登録): '
+        .implode(', ', $stale));
+});
+
+test('検査 2: PassesRescueRoute の宣言は実装の allowlist と一致する', function (): void {
+    $notPermitting = [];
+    foreach (RescueRouteGateDisposition::cases() as $case) {
+        if ($case->disposition() !== RescueRouteGateKind::PassesRescueRoute) {
+            continue;
+        }
+        if (! $case->permitsRouteName(RescueRouteGateDisposition::RESCUE_ROUTE_NAME)) {
+            $notPermitting[] = $case->value;
+        }
+    }
+
+    expect($notPermitting)->toBe([],
+        'PassesRescueRoute と宣言しているのに、実装の allowlist が救済 route を通していません。'
+        .'allowlist から救済 route を消すと「取り消したつもりで取り消せていない」詰みが再発します: '
+        .implode(', ', $notPermitting));
+});
+
+test('検査 3: 2FA ゲートと凍結 middleware は PassesRescueRoute から動かせない', function (): void {
+    // ★「通さない」に格下げして緑にする逃げ道を塞ぐ (名指し)。
+    $nonExemptible = [
+        RescueRouteGateDisposition::RequireTwoFactor,
+        RescueRouteGateDisposition::NotPendingDeletion,
+    ];
+
+    foreach ($nonExemptible as $case) {
+        expect($case->disposition())->toBe(RescueRouteGateKind::PassesRescueRoute,
+            "{$case->value} は救済 route を必ず allowlist で通す側でなければなりません "
+            .'(分類を格下げすると F-4-01 が再発します)。');
+    }
+});
+
+test('検査 4: 各 case の rationale が 30 文字以上ある', function (): void {
+    $short = [];
+    foreach (RescueRouteGateDisposition::cases() as $case) {
+        $length = mb_strlen($case->rationale());
+        if ($length < 30) {
+            $short[] = "{$case->value}: {$length} 文字";
+        }
+    }
+
+    expect($short)->toBe([], '分類の根拠は 30 文字以上で書くこと: '.implode(' / ', $short));
+});
+
+test('検査 5: 名指しした vendor 認証ゲート 3 本が実際に route に付き、母集団に入る', function (): void {
+    $resolved = rescueRouteResolvedMiddleware();
+    $population = rescueRouteGatePopulation();
+
+    $detached = array_values(array_diff(RESCUE_GATE_NAMED_VENDOR, $resolved));
+    expect($detached)->toBe([],
+        '名指しした vendor 認証ゲートが救済 route から外れました。分類 '
+        .'(ShortCircuitsButEscapable) の前提が変わるので、目録側も同じ diff で見直してください: '
+        .implode(', ', $detached));
+
+    $missing = array_values(array_diff(RESCUE_GATE_NAMED_VENDOR, $population));
+    expect($missing)->toBe([],
+        '名指し vendor が母集団の導出から抜け落ちています (母集団を絞ると gate が空振りします): '
+        .implode(', ', $missing));
+});
+
+test('空振り検知: 母集団件数の exact pin と、照合器の負のコントロール', function (): void {
+    expect(rescueRouteGatePopulation())->toHaveCount(RESCUE_GATE_POPULATION_COUNT,
+        '救済 route のゲート母集団の件数が変わりました。middleware の増減は救済の到達性に'
+        .'直結するため、この数値を同じ diff で書き換えてレビューに出してください。');
+
+    // ★照合器が「常に true」を返す実装に潰されていないことの実測。
+    //   即時削除 (settings.account.destroy) は救済ではないのでどちらのゲートも通さない。
+    foreach ([RescueRouteGateDisposition::RequireTwoFactor, RescueRouteGateDisposition::NotPendingDeletion] as $case) {
+        expect($case->permitsRouteName('settings.account.destroy'))->toBeFalse(
+            "{$case->value} の照合器が救済でない route まで true を返しています "
+            .'(allowlist を実際に引いていない疑い)。');
+    }
+
+    // ★PassesRescueRoute 以外で permitsRouteName を使うと LogicException になる
+    //   (宣言だけで緑にならないための構造)。
+    expect(fn (): bool => RescueRouteGateDisposition::SecurityHeaders
+        ->permitsRouteName(RescueRouteGateDisposition::RESCUE_ROUTE_NAME))
+        ->toThrow(LogicException::class);
+});
diff --git a/tests/Architecture/TwoFactorEnforcementAllowlistTest.php b/tests/Architecture/TwoFactorEnforcementAllowlistTest.php
index 46a30ab..825efde 100644
--- a/tests/Architecture/TwoFactorEnforcementAllowlistTest.php
+++ b/tests/Architecture/TwoFactorEnforcementAllowlistTest.php
@@ -13,6 +13,9 @@
  * 無いエントリは過剰許可の温床になる。実際の到達可能性 (ゲート中に 302 されない) は
  * tests/Feature/Organizations/TwoFactorEnforcementTest.php の dataset が担保する。
  */
+
+/** ALLOWED_ROUTE_NAMES の件数 (exact-fit。増減させたらこの数値も同じ diff で書き換える)。 */
+const TWO_FACTOR_ALLOWLIST_COUNT = 21;
 test('allowlist の route name は全て実在する named route である', function (): void {
     /** @var Router $router */
     $router = app('router');
@@ -30,3 +33,22 @@
         expect(trim($reason))->not->toBe('', "route '{$name}' の必要理由が空 (運用劣化)");
     }
 });
+
+test('allowlist の件数を exact-fit で pin する (根拠なく通す余裕枠を作らない)', function (): void {
+    expect(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES)
+        ->toHaveCount(TWO_FACTOR_ALLOWLIST_COUNT,
+            'ALLOWED_ROUTE_NAMES の件数が変わりました。増減は「未準拠ユーザーに何を許すか」の'
+            .'契約変更なので、この数値を同じ diff で書き換えてレビューに出してください。');
+});
+
+test('救済 route は allowlist にあり、予約・即時削除は無い (名指し pin)', function (): void {
+    $names = array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES);
+
+    // 救済 (誤操作した退会予約の取消) は通す。凍結側 allowlist と判断を揃える (F-4-01)
+    expect($names)->toContain('settings.account.deletion-request.destroy');
+
+    // ★以下は「救済ではない」ため通さない。個別に expect して失敗時に対象が特定できるようにする
+    expect($names)->not->toContain('settings.account.deletion-request.store'); // 予約は意思表示であり救済ではない
+    expect($names)->not->toContain('settings.account.destroy');                // 即時削除は 30 日猶予の迂回口
+    expect($names)->not->toContain('two-factor.disable');                      // 既存判断の維持 (pending 巻き戻しの濫用面)
+});
diff --git a/tests/Feature/Auth/AccountDeletionFreezeTest.php b/tests/Feature/Auth/AccountDeletionFreezeTest.php
index 87a9560..31834f4 100644
--- a/tests/Feature/Auth/AccountDeletionFreezeTest.php
+++ b/tests/Feature/Auth/AccountDeletionFreezeTest.php
@@ -279,11 +279,9 @@ function queuedJobClassesExceptDeletionNotice(): array
     expect($user->fresh()?->deletion_requested_at)->toBeNull();
 });
 
-test('2FA 未準拠ユーザーは 2FA ゲートが先に効くが、設定画面へ到達できる (詰みではない)', function (): void {
-    // ★凍結より **前** に走る 2FA 強制ゲート (priority list) の方が優先されるため、
-    //   未準拠ユーザーは取消 DELETE に直接到達できない。これは詰みではなく、
-    //   2FA 設定を済ませれば取消できる (準拠済みの取消は上のテストが固定している)。
-    //   この非対称を「取消はいつでもできる」と誇張しないために明示的に固定する。
+/** 2FA 必須組織に所属する**未準拠**ユーザーを作り、退会予約中にして返す。 */
+function twoFactorPendingFrozenUser(): User
+{
     [$organization] = createOrganizationWithOwner();
     $organization->forceFill(['two_factor_required' => true])->save();
     $user = User::factory()->create(); // 2FA 未準拠
@@ -293,27 +291,56 @@ function queuedJobClassesExceptDeletionNotice(): array
     app(OrganizationMembershipService::class)->requestAccountDeletion($user);
     $user->refresh();
 
-    // 取消は 2FA ゲートに阻まれる (凍結の 302 先ではなく 2FA 設定ページへ倒れる)
-    $this->actingAs($user)->delete('/settings/account/deletion-request')
-        ->assertRedirect('/settings/security');
-    expect($user->fresh()?->deletion_requested_at)->not->toBeNull();
+    return $user;
+}
+
+test('2FA 未準拠でも退会予約を取り消せる (救済は 2FA ゲートも凍結も通る)', function (): void {
+    // ★bug-hunt F-4-01 の再現条件。凍結側 allowlist は取消を通しているのに、priority list で
+    //   **前**に走る 2FA 強制ゲートが取消 DELETE を settings.security へ倒していたため、
+    //   「取り消したつもりで取り消せていない」状態が生まれていた。
+    //   救済 (誤操作の取消) は業務の利用ではないので、両ゲートの判断を揃えて通す。
+    $user = twoFactorPendingFrozenUser();
+
+    // 負のコントロール (取消の**前**): 業務面は 2FA ゲートで遮断されている
+    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('settings.security'));
+
+    // 救済そのもの: 取消は通り、予約は実際に消える
+    $this->actingAs($user)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($user->fresh()?->deletion_requested_at)->toBeNull();
+
+    // 負のコントロール (取消の**後**): 2FA 強制は 1mm も緩んでいない
+    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('settings.security'));
+    // 準拠判定 (two_factor_confirmed_at) も動かない = allowlist 通過はゲート解除ではない
+    expect($user->fresh()?->two_factor_confirmed_at)->toBeNull();
 
     // ★準拠達成の入口 (settings.security) に到達できることが**詰みでないことの条件**。
     //   ここを凍結すると「取消は 2FA ゲート / 2FA 設定は凍結」の相互ブロックになる。
     $this->actingAs($user)->get('/settings/security')->assertOk();
     $this->actingAs($user)->get('/settings')->assertOk();
 
-    // ★**同一ユーザー**で脱出の連鎖を固定する
-    //   (未準拠 → settings.security → 2FA 準拠 → 取消)。別ユーザーで代用すると
-    //   「元のユーザーが本当に脱出できるか」を証明しないため、詰みの回帰防止にならない。
-    //   準拠状態への遷移は UserFactory::withTwoFactor() と同一実装を共有する helper で行う。
+    // ★**同一ユーザー**で脱出の連鎖を固定する (未準拠 → settings.security → 2FA 準拠 → 業務面)。
+    //   別ユーザーで代用すると「元のユーザーが本当に脱出できるか」を証明しないため、
+    //   詰みの回帰防止にならない。準拠状態への遷移は UserFactory::withTwoFactor() と
+    //   同一実装を共有する helper で行う。
     UserFactory::enableTwoFactorFor($user);
     $user->refresh();
 
-    $this->actingAs($user)->from('/settings')
-        ->delete('/settings/account/deletion-request')
-        ->assertRedirect('/settings');
-    expect($user->fresh()?->deletion_requested_at)->toBeNull();
+    $this->actingAs($user)->get('/dashboard')->assertOk();
+});
+
+test('2FA 未準拠ユーザーの即時削除は通らない (救済だけを通す非対称)', function (): void {
+    // 「削除系なら何でも通す」になっていないことの負のコントロール。
+    // 即時削除 (settings.account.destroy) は救済ではなく 30 日猶予の迂回口である。
+    $user = twoFactorPendingFrozenUser();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/settings/account')
+        ->assertRedirect(route('settings.security'));
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
 });
 
 test('XHR は 409 Conflict で遮断される (302 に倒さない)', function (): void {
diff --git a/tests/Feature/Organizations/TwoFactorEnforcementTest.php b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
index 086265a..4c335a3 100644
--- a/tests/Feature/Organizations/TwoFactorEnforcementTest.php
+++ b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
@@ -300,6 +300,74 @@ function tfeResetUrl(Organization $organization, User $member): string
     expect($response->json('redirect'))->toBe(route('settings.security'));
 });
 
+// ──────────────── 遮断された「書き込み」への「実行されていません」 ────────────────
+
+test('遮断された書き込みには「実行されていません」が付き、実際に何も起きていない', function (): void {
+    // F-4-01: 押した結果が分からないまま状態が残る、が High の理由。
+    // 非安全メソッドが短絡されたときは「何が起きなかったか」を先に伝える。
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'disabled');
+
+    // ★2FA ゲートと RequireRecentAuth の resolve 順に**依存しない**テストにするため
+    //   step-up 済みセッションを与える (順序が入れ替わっても主張が壊れない)。
+    $this->actingAs($member)
+        ->withSession(freshRecentAuthSession())
+        ->post('/settings/account/deletion-request')
+        ->assertRedirect(route('settings.security'));
+
+    expect(session('info'))
+        ->toStartWith(RequireTwoFactorForEnforcedOrganizations::BLOCKED_WRITE_PREFIX)
+        ->toContain('2 段階認証を必須としています');
+
+    // ★文言の主張が事実であることの実測 (文字列一致だけで満足しない)
+    expect($member->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('遮断された GET には「実行されていません」を付けない (安全メソッドの負のコントロール)', function (): void {
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'disabled');
+
+    $this->actingAs($member)->get('/dashboard')->assertRedirect(route('settings.security'));
+
+    // 安全メソッドは「ページに来られない」だけなので既存文言のまま
+    expect(session('info'))
+        ->not->toContain(RequireTwoFactorForEnforcedOrganizations::BLOCKED_WRITE_PREFIX);
+    expect(session('info'))->toContain('2 段階認証を必須としています');
+});
+
+test('XHR で遮断された書き込みも 409 本文に「実行されていません」を含む', function (): void {
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'disabled');
+
+    $response = $this->actingAs($member)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/settings/account/deletion-request');
+
+    $response->assertStatus(409)
+        ->assertJsonPath('code', 'two_factor_required')
+        ->assertHeader('Cache-Control', 'no-store, private');
+    expect($response->json('message'))
+        ->toStartWith(RequireTwoFactorForEnforcedOrganizations::BLOCKED_WRITE_PREFIX);
+    expect($response->json('redirect'))->toBe(route('settings.security'));
+
+    expect($member->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('退会取消は allowlist 経由でゲートを通り 2FA 状態を変えない', function (): void {
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'pending');
+
+    // 未予約でも controller は冪等 no-op で back() を返す
+    // (Referer を明示して UI 導線 = /settings 上の取消ボタンと一致させる)。
+    $this->actingAs($member)
+        ->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+
+    // 通しても準拠判定 (two_factor_confirmed_at) は動かない
+    expect($member->fresh()?->two_factor_confirmed_at)->toBeNull();
+});
+
 test('状態遷移 (Fortify 実経路): ゲート → enable → confirm → ゲート解除', function (): void {
     [$organization] = tfeCreateOrganization(twoFactorRequired: true);
     $member = tfeAddMember($organization, 'disabled');

```

## mutation evidence (実施記録。設計の予測とのずれも記載してある)

# mutation evidence — T149 (blocked-action-context / F-4-01)

> 詳細設計 `devnotes/20260811-0146-blocked-action-context/detailed-design.md` §「mutation で赤化を確認する手順」の実施記録。
> 各 mutation は **1 つずつ**当て、確認後に必ず戻した (`git status` で作業ツリーが意図した差分のみであることを確認済み)。
> 実行レーン: `composer test -- --filter=…` (worktree `.claude/worktrees/tasks/T149`)。

## 修正前の再現 (テストファースト)

施策 1/2 を入れる**前**に、新規・更新テストだけを走らせて赤を確認した
(`--filter="AccountDeletionFreeze|TwoFactorEnforcement"` → 92 tests / 4 failed / 3 errors)。

| 赤くなったテスト | 観測された値 |
|---|---|
| Feature `2FA 未準拠でも退会予約を取り消せる (救済は 2FA ゲートも凍結も通る)` | 取消 DELETE が `/settings` ではなく **`/settings/security`** へ 302 (= F-4-01 の再現そのもの) |
| Feature `退会取消は allowlist 経由でゲートを通り 2FA 状態を変えない` | 同上 (`/settings/security`) |
| Arch `allowlist の件数を exact-fit で pin する` | actual size **20** / expected 21 |
| Arch `救済 route は allowlist にあり、予約・即時削除は無い (名指し pin)` | `settings.account.deletion-request.destroy` を含まない |
| Feature 3 本 (書き込み prefix / GET 負のコントロール / XHR) | `Undefined constant …::BLOCKED_WRITE_PREFIX` |

> 負のコントロール `2FA 未準拠ユーザーの即時削除は通らない` は**修正前から緑**である
> (これは「変わってはいけないこと」の固定であり、赤くなるべきテストではない)。

## mutation 実施結果

| # | mutation | 設計の予測 | 実測 | 一致 |
|---|---|---|---|---|
| M1 | `ALLOWED_ROUTE_NAMES` から `settings.account.deletion-request.destroy` を削除 | Feature「2FA 未準拠でも取消できる」/ Arch 検査 2 / 施策 4 の名指し pin・件数 pin | **5 failed**: Feature `2FA 未準拠でも退会予約を取り消せる` (`/settings/security`)、Feature `退会取消は allowlist 経由で…`、Arch 検査 2、件数 pin (20≠21)、名指し pin | ✅ |
| M2 | 施策 2 の条件を `if (false)` に固定 | Feature「書き込みには実行されていませんが付く」/ XHR 版 | **2 failed**: 同 2 本 (message が prefix で始まらない) | ✅ |
| M3 | 施策 2 の条件を常時 true (`if (true)`) に | Feature「GET には付けない」(負のコントロール) | **1 failed**: `遮断された GET には「実行されていません」を付けない` | ✅ |
| M4 | enum から `RequireTwoFactor` case を削除 (match 3 箇所も同時に削除) | Arch 検査 1 (exact-fit) / 検査 3 (名指し) / 件数 pin | **1 failed + 2 errors**: 検査 1 が `undeclared` を検出、検査 3 と空振り検知が `Undefined constant …::RequireTwoFactor` で error | ⚠ 下記「ずれ 1」 |
| M5 | enum の `RequireTwoFactor` を `NeverShortCircuitsRescueRoute` に変更 | Arch 検査 3 | **1 failed**: 検査 3 のみ | ✅ |
| M6 | 母集団抽出から vendor 名指し 3 本を外す | Arch 検査 1 / 検査 5 / 件数 pin | **3 failed**: 検査 1 (死に登録 3 件)、検査 5 (母集団から欠落)、件数 pin (6≠9) | ✅ |
| M7 | `AccountDeletionFreezeAllowance` から `DeletionRequestDestroy` を削除 | Arch 検査 2 + 既存 `AccountDeletionFreezeRouteGateTest` の件数 pin | **2 failed**: 検査 2 (`EnsureAccountNotPendingDeletion`)、既存 gate の件数 pin (16≠17) | ✅ |
| M8 | `permitsRouteName()` を `return true;` に置換 | Arch 検査 7 (負のコントロール) | **1 failed**: 空振り検知テスト内の負のコントロール (`settings.account.destroy` が true) | ✅ |

## 設計の予測と実測のずれ (辻褄を合わせずに記録する)

### ずれ 1 — M4 で「件数 pin」は点灯しない

設計は M4 の期待赤に「件数 pin」を挙げているが、**`RESCUE_GATE_POPULATION_COUNT` は母集団 `U`
(= route の resolve 済み middleware から導出) を数えており、enum の case 数は数えていない**。
よって enum から case を 1 つ消しても件数 pin 自体は動かない (実測: 空振り検知テストは赤くなったが、
理由は件数の不一致ではなく `RescueRouteGateDisposition::RequireTwoFactor` が
**存在しない定数**になったことによる error である)。

- 実装を設計に合わせて「enum の件数も pin する」ようにはしていない。**検査 1 の exact-fit が
  enum 側の増減を両方向で捕まえる**ため、件数 pin を二重に置くと同じことを 2 回書くだけになる
  (思考原則 2)。
- ただし「M4 の帰結として空振り検知テストも赤くなる」のは**定数消失の副作用**であって
  件数検査の成果ではない。ここを混同しないよう本節に残す。

### ずれ 2 — M4 は「case を消すだけ」では適用できない

`disposition()` / `rationale()` / `permitsRouteName()` の 3 つの `match` が全 case 網羅なので、
case だけを消すと **PHP の構文/網羅性で先に落ちる**。mutation は 4 箇所 (case + match 3 本) を
同時に削って適用した。「1 mutation = 1 箇所」の原則からはやや外れるため明記する。

### ずれ 3 — 検査 5 は設計の記述より 1 段強い

設計の検査 5 は「名指し vendor 3 本が**実際に route に付いている**」だけだが、それだけだと
M6 (母集団抽出から vendor を外す) で検査 5 は緑のままになる (route は変わらないため)。
設計の M6 予測 (検査 5 も赤) を満たすため、実装では検査 5 を
「**route に付いている** かつ **母集団の導出に入っている**」の 2 段にした。
機能的な保証範囲は広がっていない (どちらも「分類の前提が崩れたら気づく」ための検査)。

## 戻し確認

全 mutation 適用後、各対象ファイルをバックアップから復元し `git status --porcelain` で
**実装差分 6 ファイル + 新規 3 ファイル**だけが残っていることを確認した
(mutation 対象だった `app/Enums/Account/AccountDeletionFreezeAllowance.php` は
modified に現れない = 完全に戻っている)。


## テスト結果

- `composer phpstan`: OK (886 files, no errors)
- `composer test`: **4415 tests / 4413 passed / 0 failed / 2 skipped** (18943 assertions)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 130 files / 1299 tests passed
- `pnpm build`: OK
- `pnpm typecheck:packages` / `build:packages` / `test:packages` (10 files / 106 tests): passed
- `composer test:browser`: **実行していない** (フロント差分ゼロのため。設計の判断に従った)

## 特に判断してほしい問い

1. 2FA 強制ゲートの allowlist に `settings.account.deletion-request.destroy` を足したことで、
   **意図しない能力の付与**が起きていないか (self スコープ / route parameter なし / CSRF 保護下 /
   認証手段と `two_factor_confirmed_at` を増減させない、を実装で確認できるか)。
2. `RescueRouteGateInventoryTest` が **vacuous green にならない**か。母集団の導出
   (`gatherRouteMiddleware` ∩ `App\Http\Middleware\` ∪ 名指し vendor 3 本) に穴はないか。
3. `BLOCKED_WRITE_PREFIX` の付与条件 (`! isMethodSafe()`) が、意図しない面
   (例: 既存の flash 文言を前提にした他テスト・他 UI) を壊していないか。
4. docs の記述が**保証範囲を誇張していない**か。
