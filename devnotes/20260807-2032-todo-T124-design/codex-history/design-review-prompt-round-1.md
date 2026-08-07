## アプリの使命（North Star）— AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

（アプリの使命・禁止事項は上記に挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- Laravel Fortify v1.37.2（2FA / passkey / password reset を提供）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【このアプリ固有の重要な既知制約】
- inline throttle (`throttle:6,1`) のキーは `sha1(actor id)` だけで route 名も limiter 名も入らないため、
  **同一 actor の inline throttle route は全て 1 bucket を共有**する (T121 実測・AGENTS.md 明記)。
  max 最小は `recent-auth.password` の 6。ここを巻き添えで 429 にすると再認証そのものが壊れる。
- 「閾値は既存値を変えない」が規約。
- 新しい不変条件は `tests/Architecture/` の deny-by-default 目録型 gate に登録する
  (免除は型付き enum + 30 文字以上の根拠)。見本は ThrottleCoverageInventoryTest /
  QueuedJobLeaseInventoryTest / BillingGatewayFailureTaxonomyInventoryTest。
- テストは Pest + RefreshDatabase グローバル適用 + `--parallel`。個別 DatabaseTransactions 禁止。
  テストデータは Factory。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: 2fa-secret-get-recent-auth (T124)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）。解析対象は `app` / `config` / `database` / `routes`
  （`phpstan.neon` 実査。`tests/` は対象外だが、`tests/Support/` の新規クラスも型は厳密に書く）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン。本設計では**新規レスポンスを 1 本も作らない**
  （遮断応答は既存 `RecentAuthRequiredResource` + `RecentAuthRequiredDto`）
- **アーリーリターン** 推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [概念設計](./conceptual-design.md)（Codex Round 4 で **APPROVED**）
- Codex 議論履歴: `conceptual-review-round-{1..4}.md` / `codex-history/`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 秘密 GET 2 本 + `two-factor.enable` へ recent-auth を後付け | `app/Providers/FortifyServiceProvider.php`, `tests/Architecture/RecentAuthRouteTest.php`, `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`(新), `tests/Feature/Auth/TwoFactorEnableStepUpTest.php`(新) | High |
| 2 | `two-factor` route の step-up deny-by-default 目録 gate を新設 | `app/Enums/Security/TwoFactorStepUpExemption.php`(新), `tests/Support/Security/RecentAuthMiddleware.php`(新), `tests/Architecture/TwoFactorStepUpInventoryTest.php`(新), `tests/Architecture/RecentAuthRouteTest.php` | High |
| 3 | 2FA 必須ゲートの allowlist に passkey satisfier 2 本を追加 | `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php`, `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | High |
| 4 | enrollment の step-up precheck と 409 再開（詰み防止） | `resources/js/lib/recent-auth.ts`, `resources/js/pages/Settings/Security.svelte`, `tests/js/pages/SettingsSecurity.test.ts`, `tests/js/lib/recent-auth.test.ts` | High |
| 5 | 運用契約の記録（AGENTS.md / architecture.md への追記） | `AGENTS.md`, `docs/architecture.md` | Medium |

施策 1〜4 は **1 つの変更単位**（片方だけ入ると詰みか偽グリーンになる）。実装順は
**2 → 1 → 3 → 4 → 5**（gate を先に置いて赤を実測してから配線する = テストファースト）。

---

## 施策 1: 秘密 GET 2 本 + `two-factor.enable` へ recent-auth を後付け

### 変更箇所
- `app/Providers/FortifyServiceProvider.php` L58-76（`RECENT_AUTH_ROUTE_NAMES` 定数と docblock）
- `tests/Architecture/RecentAuthRouteTest.php` L21-57（`recentAuthRequiredRouteNames()` の allowlist）

### 波及変更
- TypeScript 型定義: **なし**（Props もレスポンス shape も変わらない）
- API Resource/DTO: **なし**（遮断応答は既存 `RecentAuthRequiredResource` が返す）
- テストファイル:
  - `tests/Architecture/RecentAuthRouteTest.php`（allowlist 3 本追加）
  - `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`（新規）
  - `tests/Feature/Auth/TwoFactorEnableStepUpTest.php`（新規）
  - `tests/Feature/Security/AuthThrottleCoverageTest.php`（**変更不要**。L310-361 の 2FA 秘密 GET
    レーン検査は `withSession(['recent_auth_at' => ...])` を持たないため、recent-auth 追加で
    409/302 になり 429 の観測ができなくなる。**実装時に必ず実行して確認し**、落ちるなら
    「fresh セッションを与える」1 行の追加だけで直す。throttle 側の検査意図は変えない）
- フロントエンド: 施策 4 が対応（`two-factor.enable` に step-up が付くため precheck が必須になる）

### 現行コード

```php
/**
 * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
 * （中略）
 * @var list<string>
 */
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
];
```

### 変更後コード

```php
/**
 * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
 * いずれも「第二要素の秘密の露出」または「確立済み第二要素の除去・差し替え」経路であり、
 * 通常セッション認証だけで到達させない (姉妹操作: organizations.members.two-factor.reset /
 * settings.account.destroy 等と同基準)。
 * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
 * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
 *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
 *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
 *     self-disable が許可される非 enforced 組織のユーザー。
 * - qr-code / secret-key (GET, T124): TOTP seed そのものの露出。
 *   Fortify の TwoFactorSecretKeyController は two_factor_secret を**復号して平文で返し**、
 *   TwoFactorQrCodeController は otpauth:// URL (秘密を内包) を返す。どちらも
 *   two_factor_confirmed_at を見ないため、**確立済み**第二要素の seed が読める。
 *   throttle (two-factor-secret-read) は連続取得の回数上限であって step-up の代替ではない。
 * - enable (POST, T124): Fortify の TwoFactorAuthenticationController は
 *   `$request->boolean('force')` を EnableTwoFactorAuthentication へ渡す。
 *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
 *   two_factor_confirmed_at を触らない** (fortify v1.37.2 実査) ため、奪取セッションから
 *   1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」永久ロックアウトを作れる。
 *   秘密の**読み出し**だけ塞いで**差し替え**を開けたままにしない。
 * 付与漏れは RecentAuthRouteTest (allowlist) と TwoFactorStepUpInventoryTest
 * (deny-by-default 目録) の 2 枚で検出する。
 *
 * @var list<string>
 */
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
    'two-factor.qr-code',
    'two-factor.secret-key',
    'two-factor.enable',
];
```

`tests/Architecture/RecentAuthRouteTest.php`:

```php
        // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
        'two-factor.disable',
        // 2FA seed の露出 (T124)。qr-code は otpauth:// URL、secret-key は平文 seed を返す
        'two-factor.qr-code',
        'two-factor.secret-key',
        // 2FA enrollment 開始 (T124)。force=true が seed とリカバリコードを差し替える
        'two-factor.enable',
```

`attachRecentAuthToSensitiveRoutes()` 自体は**変更不要**（定数を回す booted callback のまま）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（定数のみの変更。`@var list<string>` を維持）
- [x] null 安全（`appendMiddlewareIfMissing()` は `$route !== null` を既にチェック）
- [x] DTO を返している（レスポンスに触れない）
- [x] Generics の型パラメータが正しい（`list<string>` のまま）

### テスト計画

**新規 `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`**
（`TwoFactorRecoveryCodesStepUpTest` と同じ 4 系統構成に揃える。データは `User::factory()->withTwoFactor()`）

- `test('鮮度なしの GET QR コード (Accept: application/json) は 409 recent_auth_required で svg も url も返さない')`
  - `->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])` で**実ヘッダ条件**を固定する
    （`getJson()` ヘルパ任せにしない。フロントの素 `fetch` が同じ条件で 409 契約へ入ることの証拠にする）
  - `assertStatus(409)` / `assertJsonPath('code', 'recent_auth_required')`
  - `assertJsonMissingPath('svg')` / `assertJsonMissingPath('url')`
- `test('鮮度なしの GET セットアップキーは 409 で secretKey を返さない')` — 同上、`secretKey` の不在を検査
- `test('鮮度なしの通常 GET (Accept: text/html) は recent-auth confirm へ 302 する')`
  - `assertRedirect(route('recent-auth.confirm'))`（302 分岐の契約も固定する）
- `test('fresh なら QR とセットアップキーが実際に秘密を返す (負のコントロール)')`
  - `withSession(['recent_auth_at' => time()])` で 200
  - `secretKey` が `$user->two_factor_secret` の復号値と**一致**することを検査
    （「常に失敗するから green」という空振りを排除する。遮断に意味があることの証拠）
- `test('Cache-Control: no-store が 409 応答に付く')` — 既存 `RequireRecentAuth` 契約の回帰

**新規 `tests/Feature/Auth/TwoFactorEnableStepUpTest.php`**

- `test('鮮度なしの POST enable (XHR) は 409 で two_factor_secret を作らない')`
  - `User::factory()->create()`（2FA 未設定）→ `postJson('/user/two-factor-authentication')`
  - 409 / `two_factor_secret` が null のままであること
- `test('鮮度なしの POST enable force=true は確立済み seed とリカバリコードを差し替えない (ロックアウト回帰)')`
  - `User::factory()->withTwoFactor()->create()` → `$before = [two_factor_secret, two_factor_recovery_codes]`
  - `postJson('/user/two-factor-authentication', ['force' => true])` → 409
  - `$user->refresh()` 後に **両カラムが不変**であること（本施策の中心的な回帰テスト）
- `test('鮮度なしの通常 POST enable は recent-auth confirm へ 302 する')`
- `test('fresh なら force=true が seed を実際に差し替える (負のコントロール)')`
  - `withSession(['recent_auth_at' => time()])` → 両カラムが**変化する**こと
- `test('2FA 必須組織の未準拠メンバーでも enable は 2FA ゲートに阻まれない')`
  - `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` に `two-factor.enable` が
    元から入っているため、**recent-auth 追加後も遮断の理由が step-up 側だけ**であることを固定する
    （409 の `code` が `recent_auth_required` であって `two_factor_required` でないこと）

**既存テストの更新**
- `tests/Architecture/RecentAuthRouteTest.php`: allowlist に 3 本追加（表駆動のため他は自動）
- `tests/Feature/Security/AuthThrottleCoverageTest.php`: 実行して落ちる場合のみ、
  2FA 秘密 GET レーン検査に `withSession(['recent_auth_at' => time()])` を足す
  （**検査意図・閾値・limiter 名は 1 文字も変えない**）
- 既存テストの削除・無効化・緩和は**ゼロ**

**個別 `DatabaseTransactions` は使わない**（`tests/Pest.php` のグローバル `RefreshDatabase` に従う）

### リスク
- **`AuthThrottleCoverageTest` の巻き添え赤**: 上記のとおり 1 行で解消できる想定だが、
  実装時に確認する。もし throttle 検査が recent-auth 通過を前提にできない構造なら、
  そのテストの意図（レーン分離の behavioral 固定）を壊さない形で fresh session を与える。
- **route:cache との関係**: `attachRecentAuthToSensitiveRoutes()` は cached 起動でも
  `CompiledRouteCollection` の nameCache 経由で同一 instance に効く（既存 docblock の主張）。
  本施策で前提を増やさない。ただし throttle 後付け側の運用要件
  （`php artisan route:cache` を毎デプロイ再生成）は引き続き有効。
- **enrollment 導線の摩擦**: ログイン直後 15 分（`auth.recent_auth_timeout`）は
  `StampRecentAuthOnLogin` により fresh のため、多くの利用者はモーダルを見ない。
  15 分超で放置した利用者だけが 1 回の再認証を求められる（施策 4 が詰みを防ぐ）。

---

## 施策 2: `two-factor` route の step-up deny-by-default 目録 gate

### 変更箇所
- `app/Enums/Security/TwoFactorStepUpExemption.php`（新規）
- `tests/Support/Security/RecentAuthMiddleware.php`（新規。判定点の単一化）
- `tests/Architecture/TwoFactorStepUpInventoryTest.php`（新規）
- `tests/Architecture/RecentAuthRouteTest.php`（`routeHasRecentAuth()` を共有判定へ委譲）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `RecentAuthRouteTest.php`（判定の重複を消す。**削除ではなく委譲**）

### 現行コード

`tests/Architecture/RecentAuthRouteTest.php`（判定が 1 箇所に閉じている＝新 gate から呼べない）:

```php
function routeHasRecentAuth(RoutingRoute $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }
        // alias 'recent-auth' / 'recent-auth:param' / 完全クラス名のいずれかを許容 (堅牢化)
        if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
            return true;
        }
    }

    return false;
}
```

`app/Enums/Security/TwoFactorStepUpExemption.php`: **存在しない**（実査で確認）。
`app/Enums/Security/` には `JobDedupExemption` / `GatewayFailureObservationExemption` /
`ControllerAuthorizationExemption` / `ThrottleCoverageExemption` の 4 本がある。

### 変更後コード

**`app/Enums/Security/TwoFactorStepUpExemption.php`（新規）**

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「route 名に `two-factor` を含む route が recent-auth (step-up) を持たないことが正しい」と
 * 裁定された理由の分類。
 *
 * `tests/Architecture/TwoFactorStepUpInventoryTest.php` が deny-by-default で
 * 「recent-auth を持つ」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★case は「route の識別子」ではなく「**免除してよい理由の型**」である
 *   (ThrottleCoverageExemption と同じ流儀。1 route 1 case にすると enum が route 名の
 *    写しになり、「同じ理由の免除が増えていないか」という目録の主目的が消える)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「recent-auth を貼るべき route」である。
 */
enum TwoFactorStepUpExemption: string
{
    /**
     * 未認証 (guest) で到達する第二要素チャレンジ面。
     *
     * 適用条件 (すべて満たすこと):
     *  - route middleware に `guest:` guard を持ち、認証済みでは到達できない
     *  - session に認証主体が存在せず、**step-up の概念が定義不能**である
     *  - その route 自体が第二要素の検証側 (satisfier) であり、
     *    自分自身に step-up を要求すると構造的に詰む
     */
    case PreAuthChallengeSurface = 'pre_auth_challenge_surface';

    /**
     * 成立に「その場では生成できない秘密の所持証明」を要求する route。
     *
     * 適用条件 (すべて満たすこと):
     *  - 成立条件が TOTP コード等の**所持証明**であり、session 保持だけでは成立しない
     *  - 応答が秘密を**開示しない**
     *  - 既存の第二要素を**除去・差し替えしない**
     */
    case ProofOfSecretPossessionRequired = 'proof_of_secret_possession_required';
}
```

**`tests/Support/Security/RecentAuthMiddleware.php`（新規）**

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Http\Middleware\RequireRecentAuth;
use Illuminate\Routing\Route as RoutingRoute;

/**
 * route に recent-auth (step-up) middleware が付いているかの**唯一の判定点**。
 *
 * RecentAuthRouteTest (allowlist 型) と TwoFactorStepUpInventoryTest (deny-by-default 目録型)
 * の 2 つの gate が同じ述語を使う。判定を各テストに複製すると、片方だけ堅牢化されて
 * 「一方は付いていると言い、他方は付いていないと言う」ドリフトが起きる。
 */
final class RecentAuthMiddleware
{
    /**
     * alias `recent-auth` / `recent-auth.on-email-change` / `recent-auth:param` /
     * 完全クラス名のいずれかを許容する (配線側の表記ゆれで空振りしないため)。
     */
    public static function isAttached(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
                return true;
            }
        }

        return false;
    }
}
```

`tests/Architecture/RecentAuthRouteTest.php` は委譲に変える（**振る舞い不変**）:

```php
use Tests\Support\Security\RecentAuthMiddleware;

function routeHasRecentAuth(RoutingRoute $route): bool
{
    return RecentAuthMiddleware::isAttached($route);
}
```

**`tests/Architecture/TwoFactorStepUpInventoryTest.php`（新規）**

```php
<?php

declare(strict_types=1);

use App\Enums\Security\TwoFactorStepUpExemption;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\RecentAuthMiddleware;

/*
 * 2FA 面の step-up (recent-auth) 付与漏れ invariant (deny-by-default)。
 *
 * 「route 名に `two-factor` を含む route は recent-auth を持つか、
 *   TwoFactorStepUpExemption + 30 文字以上の根拠で免除登録されている」を機械強制する。
 *
 * ★保証範囲 (誇張しない): セレクタは**名前ベース**である。`mfa.*` / `security.*` のような
 *   別名で第二要素の状態に触る route を将来足した場合、本 gate は**沈黙する**。
 *   別名で第二要素へ触る route を足すときは、この inventory の母集団設計も同時に見直すこと。
 *   (命名規約そのものを強制する仕組みは意図的に作っていない = 過大)
 *
 * ★RecentAuthRouteTest (allowlist 型) との役割分担:
 *   あちらは「機微操作の名指し表に付いているか」、こちらは「2FA 名前空間に未分類が無いか」。
 *   判定述語は Tests\Support\Security\RecentAuthMiddleware に単一化してドリフトを防ぐ。
 */

/** 母集団セレクタ: route 名に `two-factor` を含む全 route。 */
function twoFactorStepUpPopulation(): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    /** @var array<string, RoutingRoute> $matched */
    $matched = [];
    foreach ($routes as $route) {
        $name = $route->getName();
        if (is_string($name) && str_contains($name, 'two-factor')) {
            $matched[$name] = $route;   // route 名は一意
        }
    }
    ksort($matched);

    return $matched;
}

/**
 * 母集団件数の **exact fit**。
 * ★下限だけでは「セレクタが壊れて 0 件」は検出できても「Fortify が 1 本足した」を見逃す。
 *   exact なら増減のどちらも必ず差分として現れ、分類の再検討を強制できる。
 *   実測値 (php artisan route:list --json): Fortify 9 本 + アプリ 2 本 = 11 本。
 */
function twoFactorStepUpPopulationSize(): int
{
    return 11;
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function twoFactorStepUpReasonMinLength(): int
{
    return 30;
}

/** exemption 件数の上限。**現在値ちょうど** (exact fit)。上げる前に必ず再検討すること。 */
function twoFactorStepUpExemptionCap(): int
{
    return 3;
}

/**
 * case 別上限 (分類の偏り検出)。全体 cap とは役割が違うので array_sum で導出しない。
 *
 * @return array<string, int>
 */
function twoFactorStepUpExemptionCapByCase(): array
{
    return [
        TwoFactorStepUpExemption::PreAuthChallengeSurface->value => 2,
        // ★ここが膨らむ = 「秘密を開示する route を所持証明つきとして逃がした」疑い。
        TwoFactorStepUpExemption::ProofOfSecretPossessionRequired->value => 1,
    ];
}

/**
 * **秘密を開示する route** の名指し固定。この 3 本が exemption 側へ移されたら fail する
 * (この gate の存在理由そのものを守る = 空振り防止の核)。
 *
 * @return list<string>
 */
function twoFactorSecretDisclosingRoutes(): array
{
    return [
        'two-factor.qr-code',        // otpauth:// URL (秘密を内包) と QR SVG
        'two-factor.secret-key',     // 平文 TOTP seed
        'two-factor.recovery-codes', // TOTP を伴わないログイン成立手段
    ];
}

/**
 * recent-auth を持たないことが正しいと裁定した route の inventory。
 *
 * @return array<string, array{TwoFactorStepUpExemption, string}>
 */
function twoFactorStepUpExemptions(): array
{
    $preAuth = TwoFactorStepUpExemption::PreAuthChallengeSurface;
    $possession = TwoFactorStepUpExemption::ProofOfSecretPossessionRequired;

    return [
        'two-factor.login' => [$preAuth,
            'guest:web guard 配下の未認証チャレンジ画面。session に認証主体が存在しないため '
            .'step-up の鮮度判定 (recent_auth_at) が定義不能であり、ここに recent-auth を課すと '
            .'ログインそのものが成立しなくなる。流量制限は fortify.limiters.two-factor が担当する。'],

        'two-factor.login.store' => [$preAuth,
            'two-factor.login と同一 URI の検証側。これ自体が第二要素の検証 = satisfier であり、'
            .'自分自身に step-up を要求すると構造的に詰む。guest:web + throttle:two-factor で '
            .'総当りは有界化されている。'],

        'two-factor.confirm' => [$possession,
            'enrollment の確認。成立には認証アプリが生成した TOTP コードの提示が必要で、'
            .'session 保持だけでは成立しない (秘密の所持証明が前提)。応答は秘密を開示せず、'
            .'既存の第二要素を除去も差し替えもしない (two_factor_confirmed_at を立てるだけ)。'
            .'秘密の入手経路である qr-code / secret-key / enable 側に step-up を課してある。'],
    ];
}

test('母集団が exact fit である (セレクタの空振り / vendor の route 追加を検出)', function (): void {
    $population = twoFactorStepUpPopulation();
    $expected = twoFactorStepUpPopulationSize();

    expect(count($population))->toBe($expected,
        '2FA route の母集団が '.count($population)."件 (期待 {$expected} 件) です。"
        .'セレクタの空振り、または Fortify / アプリ側の route 増減が起きています。'
        .'増えた route を分類してからこの数値を更新してください。'
        .PHP_EOL.implode(PHP_EOL, array_keys($population)));
});

test('母集団の各 route は recent-auth を持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = twoFactorStepUpExemptions();
    $violations = [];

    foreach (twoFactorStepUpPopulation() as $name => $route) {
        if (RecentAuthMiddleware::isAttached($route)) {
            continue;
        }
        if (array_key_exists($name, $inventory)) {
            continue;
        }
        $violations[] = "{$name}: recent-auth が無く exemption inventory にも未登録";
    }

    expect($violations)->toBe([],
        '2FA 面の step-up 付与が不正です。recent-auth を貼るか、貼らないことが正しい理由を '
        .'twoFactorStepUpExemptions() に TwoFactorStepUpExemption + 具体的根拠付きで'
        .'登録してください。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption inventory の key は現存する母集団 route (stale 検出)', function (): void {
    $population = twoFactorStepUpPopulation();

    $stale = [];
    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
        if (! array_key_exists($name, $population)) {
            $stale[] = $name;
        }
    }

    expect($stale)->toBe([],
        'exemption inventory に現存しない route 名があります (削除/rename 済み): '.implode(', ', $stale));
});

test('exemption 登録された route は recent-auth を持たない (死んだ exemption の検出)', function (): void {
    $population = twoFactorStepUpPopulation();
    $dead = [];

    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
        $route = $population[$name] ?? null;
        if ($route instanceof RoutingRoute && RecentAuthMiddleware::isAttached($route)) {
            $dead[] = $name;
        }
    }

    expect($dead)->toBe([],
        'recent-auth が付いているのに exemption が残っています (免除が形骸化しています)。'
        .'inventory から削除してください: '.implode(', ', $dead));
});

test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
    $minLength = twoFactorStepUpReasonMinLength();
    $violations = [];

    foreach (twoFactorStepUpExemptions() as $name => [$exemption, $reason]) {
        if (! $exemption instanceof TwoFactorStepUpExemption) {
            $violations[] = "{$name}: 第 1 要素が TwoFactorStepUpExemption ではありません";
        }
        if (mb_strlen($reason) < $minLength) {
            $violations[] = "{$name}: 理由が {$minLength} 文字未満です";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption 件数が上限ちょうどを超えない (形骸化ガード)', function (): void {
    $count = count(twoFactorStepUpExemptions());

    expect($count)->toBeLessThanOrEqual(twoFactorStepUpExemptionCap(),
        "exemption が {$count} 件あります。免除を増やす前に、その route に step-up を"
        .'課せない構造的理由が本当にあるかを再検討してください。');
});

test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
    $caps = twoFactorStepUpExemptionCapByCase();
    $counts = [];

    foreach (twoFactorStepUpExemptions() as [$exemption, $reason]) {
        $counts[$exemption->value] = ($counts[$exemption->value] ?? 0) + 1;
    }

    // 全 case が cap 表に載っていること (case 追加時の登録漏れ検出)
    foreach (TwoFactorStepUpExemption::cases() as $case) {
        expect($caps)->toHaveKey($case->value, "case {$case->value} が cap 表に未登録です");
    }

    $violations = [];
    foreach ($counts as $value => $count) {
        $cap = $caps[$value] ?? 0;
        if ($count > $cap) {
            $violations[] = "{$value}: {$count} 件 (上限 {$cap})";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('秘密を開示する route は必ず recent-auth 側にある (免除側へ移されたら fail)', function (): void {
    $population = twoFactorStepUpPopulation();
    $inventory = twoFactorStepUpExemptions();
    $violations = [];

    foreach (twoFactorSecretDisclosingRoutes() as $name) {
        $route = $population[$name] ?? null;
        if (! $route instanceof RoutingRoute) {
            $violations[] = "{$name}: 母集団に存在しません (rename / 削除?)";

            continue;
        }
        if (! RecentAuthMiddleware::isAttached($route)) {
            $violations[] = "{$name}: 秘密を開示する route なのに recent-auth がありません";
        }
        if (array_key_exists($name, $inventory)) {
            $violations[] = "{$name}: 秘密を開示する route は exemption にできません";
        }
    }

    expect($violations)->toBe([],
        '秘密開示 route の step-up は免除できません (T124 の存在理由そのものです)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（enum は `: string` backed、`TwoFactorStepUpExemption` の
      case は全て string 値。`tests/Support` のクラスは `static function isAttached(): bool`）
- [x] null 安全（`$population[$name] ?? null` + `instanceof RoutingRoute` で narrowing。
      `Route::getFacadeRoot()` の戻りは `@var Router` で明示）
- [x] DTO を返している（本施策はレスポンスに触れない）
- [x] Generics の型パラメータが正しい（`array<string, RoutingRoute>` /
      `array<string, array{TwoFactorStepUpExemption, string}>` を docblock で明示）
- [x] `app/` に増えるのは enum 1 本のみ（PHPStan 解析対象）。`tests/` は解析対象外だが
      同水準の型注釈を書く

### テスト計画（gate 自身の赤化検証）

> **この施策は「テストを足す」施策なので、素の main では赤にならない。
> したがって「本当に検出できるのか」を実測で示す手順を設計に含める。**

**Step A: 未対応状態での自然な赤（テストファースト）**

施策 2 のファイル群だけを先に置き、**施策 1 の配線を入れる前に**実行する。

```bash
cd /workspace && vendor/bin/pest tests/Architecture/TwoFactorStepUpInventoryTest.php
```

期待: 「母集団の各 route は recent-auth を持つか…」が **`two-factor.enable` /
`two-factor.qr-code` / `two-factor.secret-key` の 3 本ちょうど**を列挙して fail する。
（`two-factor.confirm` / `two-factor.login` / `two-factor.login.store` は exemption 済みなので出ない。
 出るなら inventory の書き間違い）
実測出力を `devnotes/20260807-2032-todo-T124-design/impl-step2-fail-observation.md` に残す。

**Step B: 施策 1 を入れて green** — 同じコマンドで全 8 テスト passed。

**Step C: mutation で「空振り green ではない」ことを確認**

以下を **1 つずつ**適用 → 失敗を確認 → **必ず revert** する。結果は
`devnotes/20260807-2032-todo-T124-design/mutation-observation.md` に記録する。

| # | mutation | 期待する fail |
|---|---|---|
| m1 | `RECENT_AUTH_ROUTE_NAMES` から `'two-factor.secret-key'` を 1 本抜く | 「未分類」検査が `two-factor.secret-key` を列挙して fail + 「秘密を開示する route」検査も fail |
| m2 | `twoFactorStepUpExemptions()` に `'two-factor.nonexistent' => [...]` を足す | stale 検出が fail |
| m3 | `twoFactorStepUpExemptions()` に `'two-factor.disable' => [...]`（recent-auth 済み）を足す | 死んだ exemption 検出が fail |
| m4 | `two-factor.confirm` の理由を `'N/A'` に短縮 | 30 文字検査が fail |
| m5 | `two-factor.qr-code` を exemption inventory へ移し、`RECENT_AUTH_ROUTE_NAMES` から外す | 「秘密を開示する route は exemption にできません」が fail（cap 超過も同時に fail） |
| m6 | セレクタを `str_contains($name, 'two-factor.')`（ドット付き）に狭める | 母集団 exact fit が 9 件で fail（アプリ側 2 本の取りこぼしを検出） |
| m7 | `twoFactorStepUpPopulationSize()` を 12 にする | 母集団 exact fit が fail（数値だけ書き換える運用を防げることの確認） |

**空振り防止の総括（設計上の保証）**
- 母集団 0 件 → exact fit (11) が fail するので**必ず**気づく
- 母集団が増えた → exact fit が fail し、分類を強制する
- exemption が形骸化 → 死んだ exemption 検出と exact-fit cap が fail
- gate の目的が骨抜きに → 秘密開示 3 本の名指し固定が fail

### リスク
- **`str_contains($name, 'two-factor')` の過剰一致**: 将来 `two-factor-…` を含む無関係な
  route 名が生えると母集団に入る（例: 説明ページ）。その場合も exact fit が fail するので
  **沈黙はしない**。分類（recent-auth 不要なら exemption + 理由）を強制するだけで済む。
- **`gatherMiddleware()` が controller を container 解決する**: Architecture テスト実行時なので
  boot 中の副作用（`RouteThrottleBinder` の docblock が警告している問題）は起きない。
  既存 `RecentAuthRouteTest` と同条件。
- **Pest のグローバル関数名衝突**: 新規関数名は `twoFactorStepUp…` prefix で一意にする。
  `routeHasRecentAuth()` は既存の 1 箇所のみに残し、実体を `Tests\Support` へ移す。

---

## 施策 3: 2FA 必須ゲートの allowlist に passkey satisfier を追加

### 変更箇所
- `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` L41-65（`ALLOWED_ROUTE_NAMES`）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル:
  - `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` — **変更不要**
    （表駆動で「全 name の実在 + 理由非空」を検査する構造。2 本追加は自動でカバー）
  - `tests/Feature/Organizations/TwoFactorEnforcementTest.php` — dataset は
    `array_keys(ALLOWED_ROUTE_NAMES)` 由来なので自動。ただし**明示ケースを 1 本足す**（後述）

### 現行コード

```php
        'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
        'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
        'recent-auth.password' => 'password による step-up 完了',
        // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
        // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
        'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
        'social.callback' => 'SSO step-up の callback',
```

### 変更後コード

```php
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
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`@var array<string, string>` の定数を維持）
- [x] null 安全（`array_key_exists($routeName, self::ALLOWED_ROUTE_NAMES)` は既存のまま）
- [x] DTO を返している（`TwoFactorRequiredResource` は不変）
- [x] Generics の型パラメータが正しい

### テスト計画

- `tests/Feature/Organizations/TwoFactorEnforcementTest.php`
  - 既存 dataset テスト `'allowlist の各 route はゲート中でも settings.security へ redirect されない'`
    は `array_keys(ALLOWED_ROUTE_NAMES)` 駆動のため **2 本が自動で追加**される
    （`passkey.confirm-options` は GET、`passkey.confirm` は POST。
     ダミー URI 生成ロジックはパラメータ無しなのでそのまま通る）
  - **新規** `test('2FA 必須ゲート下の passkey-only ユーザーは passkey step-up の challenge を取得できる')`
    - `tfeCreateOrganization(twoFactorRequired: true)` + `tfeAddMember($organization, 'pending')`
    - `Passkey::factory()->for($member)->create()`
    - `actingAs($member)->getJson('/passkeys/confirm/options')` が
      **`settings.security` へ redirect されない**こと（本施策の直接の回帰）
  - **新規（負のコントロール）** `test('allowlist 外の passkey 管理 route はゲート中に settings.security へ 302')`
    - `passkey.registration-options`（= credential を**増やす**管理経路で satisfier ではない）が
      ゲートに阻まれること。「passkey なら何でも通す」になっていないことの証拠

### リスク
- **allowlist を広げすぎる懸念**: `passkey.confirm*` は step-up の satisfier であり、
  通しても `two_factor_confirmed_at` は立たない = **2FA 必須ゲートの解除にはならない**。
  `passkey.registration-options` / `passkey.store` / `passkey.destroy`（credential 集合を
  増減させる管理経路）は**入れない**。上の負のコントロールで固定する。
- **`passkey.confirm` の副作用**: 成立すると `StampRecentAuthOnPasskeyVerified` が
  `recent_auth_at` を stamp する。ゲート下でも step-up 鮮度が立つのは意図どおり
  （そうしないと enrollment に進めない）。

---

## 施策 4: enrollment の step-up precheck と 409 再開（詰み防止）

### 変更箇所
- `resources/js/lib/recent-auth.ts` L130-160 付近（409 判定の型ガードを export）
- `resources/js/pages/Settings/Security.svelte` L133-208（素材 fetch）、L295-315（`enableTwoFactor`）、
  L466-478（再試行ボタン）

### 波及変更
- TypeScript 型定義: `resources/js/lib/recent-auth.ts` に **型ガード関数 1 本を追加**
  （既存 interface は変更なし）
- API Resource/DTO: なし（サーバ側の応答契約は不変）
- テストファイル: `tests/js/lib/recent-auth.test.ts` / `tests/js/pages/SettingsSecurity.test.ts`
- `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`: **変更不要**
  （`Settings/Security.svelte` は登録済み。`withRecentAuth` / `onStale` 代入形も既に満たしている）

### 現行コード

```ts
/** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
```

```svelte
    async function fetchStringField(url: string, key: string): Promise<string | null> {
        try {
            return readStringField(await fetchJson<unknown>(url), key);
        } catch {
            return null;
        }
    }

    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;

        const [qr, secret] = await Promise.all([
            fetchStringField("/user/two-factor-qr-code", "svg"),
            fetchStringField("/user/two-factor-secret-key", "secretKey"),
        ]);

        if (generation !== enrollmentGeneration) return;

        qrSvg = qr;
        setupKey = secret;
        enrollmentAssetsFailed = qr === null && secret === null;
        loadingEnrollmentAssets = false;
    }
```

```svelte
    function enableTwoFactor(): void {
        // 再試行時に前回の素材・エラーを持ち越さない
        resetEnrollmentAssets();
        router.post(
            "/user/two-factor-authentication",
            {},
            { /* onStart / onSuccess: confirming = true; void loadEnrollmentAssets() / onFinish */ },
        );
    }
```

```svelte
                                    <Button
                                        variant="ghost"
                                        onclick={() => void loadEnrollmentAssets()}
                                        loading={loadingEnrollmentAssets}
                                        testId="retry-enrollment-assets-button"
                                    >
```

### 変更後コード

**`resources/js/lib/recent-auth.ts`**（`RECENT_AUTH_REQUIRED_CODE` は既存のまま使い回す）

```ts
/**
 * XHR 応答が recent-auth の 409 契約か。**status だけでは判定しない**。
 *
 * 同じ 409 を `RequireTwoFactorForEnforcedOrganizations` も返す
 * (`code: "two_factor_required"`) ため、status のみの判定は**誤食する**。
 * body の形状は信用せず unknown から絞り込む型ガードにする
 * (parseRecentAuthStatus と同じ流儀)。
 *
 * Inertia visit 側の判定 (recentAuthRedirectTarget) と同じ定数を共有し、
 * 判定点を 2 つ作らない。
 */
export function isRecentAuthRequiredPayload(status: number, body: unknown): boolean {
    if (status !== 409) return false;
    if (typeof body !== "object" || body === null) return false;
    return (body as Record<string, unknown>).code === RECENT_AUTH_REQUIRED_CODE;
}
```

**`resources/js/pages/Settings/Security.svelte`**

```svelte
    /**
     * enrollment 素材 1 本の取得結果。
     * `recentAuthRequired` は「取得失敗」とは**別事象**として上位へ返す
     * (409 を「取得失敗」に畳むと、原因と対処が一致しない表示になり再試行が無限に失敗する)。
     */
    interface EnrollmentField {
        value: string | null;
        recentAuthRequired: boolean;
    }

    /**
     * enrollment 素材の単一 endpoint を取得する。
     * ★`Accept: application/json` は**必須**。これが無いと RequireRecentAuth の
     *   expectsJson() が偽になり 302 が返って fetch がリダイレクトを追従するため、
     *   409 判定が一度も成立しない (サーバ側 Feature テストが同じヘッダ条件で固定している)。
     * 秘密が絡む経路なので失敗内容は console にも出さない。
     */
    async function fetchEnrollmentField(url: string, key: string): Promise<EnrollmentField> {
        try {
            const response = await fetch(url, { headers: { Accept: "application/json" } });
            if (!response.ok) {
                const body: unknown = await response.json().catch(() => null);
                return {
                    value: null,
                    recentAuthRequired: isRecentAuthRequiredPayload(response.status, body),
                };
            }
            return { value: readStringField(await response.json(), key), recentAuthRequired: false };
        } catch {
            return { value: null, recentAuthRequired: false };
        }
    }

    /**
     * enrollment 素材 (QR + 手動セットアップキー) を取得する。
     * ★409 の集約はここ 1 箇所。個別 fetch から guardWithRecentAuth を呼ばない
     *   (QR と secret-key は同一 session の同一鮮度判定なので**両方 409 になるのが通常**であり、
     *    個別に呼ぶとモーダル 2 重起動と pendingAction 上書きが常時発生する)。
     */
    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;

        const [qr, secret] = await Promise.all([
            fetchEnrollmentField("/user/two-factor-qr-code", "svg"),
            fetchEnrollmentField("/user/two-factor-secret-key", "secretKey"),
        ]);

        // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
        if (generation !== enrollmentGeneration) return;

        // 鮮度切れは「取得失敗」ではない。再認証モーダルを 1 回だけ開き、成立後に同じ取得を再開する
        if (qr.recentAuthRequired || secret.recentAuthRequired) {
            loadingEnrollmentAssets = false;
            void guardWithRecentAuth(() => void loadEnrollmentAssets());

            return;
        }

        qrSvg = qr.value;
        setupKey = secret.value;
        enrollmentAssetsFailed = qr.value === null && secret.value === null;
        loadingEnrollmentAssets = false;
    }
```

```svelte
    /**
     * 有効化開始。POST /user/two-factor-authentication は recent-auth 必須になった (T124) ため
     * precheck を前段に置く。
     * ★順序が重要: step-up を enrollment の**最初**の操作にすることで、inline throttle の
     *   共有 bucket (同一 actor の全 inline route が 1 bucket) の残量が最大の時点で
     *   recent-auth.password (max 6 = 最小) を通す。enable → confirm リトライの**後**に
     *   step-up が回ると、TOTP を数回打ち間違えた利用者が再認証で 429 になる。
     */
    function enableTwoFactor(): void {
        void guardWithRecentAuth(() => {
            // 再試行時に前回の素材・エラーを持ち越さない
            resetEnrollmentAssets();
            router.post(
                "/user/two-factor-authentication",
                {},
                {
                    preserveScroll: true,
                    onStart: () => {
                        enabling = true;
                    },
                    onSuccess: () => {
                        confirming = true;
                        void loadEnrollmentAssets();
                    },
                    onFinish: () => {
                        enabling = false;
                    },
                },
            );
        });
    }
```

再試行ボタンは `loadEnrollmentAssets()` が 409 を自前で処理するようになったため
**`onclick` の変更は不要**（そのまま `() => void loadEnrollmentAssets()`）。
コメントだけ「409 は関数内で step-up 再開に接続される」と追記する。

### PHPStan適合チェック
本施策は TypeScript のみ。対応する検証は:
- [x] `pnpm typecheck`（`tsc --noEmit`）— `EnrollmentField` interface / 型ガードの戻り型を明示
- [x] `pnpm lint`（eslint + prettier）
- [x] body の形状を `unknown` から絞り込む（`as` の乱用をしない。既存 `parseRecentAuthStatus` に倣う）
- [x] DESIGN.md 準拠: **UI の見た目・token を変更しない**（既存 Alert / Button をそのまま使う）
- [x] Atomic Design 準拠: 変更は `pages/` と `lib/` のみ。新規コンポーネント無し、SVG 直書き無し
- [x] 禁止事項 8: 条件未充足での `disabled` 化を**しない**（押下 → precheck → モーダル）

### テスト計画

**`tests/js/lib/recent-auth.test.ts`（追記）**
- `it('409 + code=recent_auth_required を true と判定する')`
- `it('409 + code=two_factor_required を false と判定する（2FA 必須ゲートの 409 を誤食しない）')`
  ← **負のコントロールの核**
- `it('200 + code=recent_auth_required を false と判定する')`
- `it('body が null / 文字列 / 配列でも例外を投げず false')`

**`tests/js/pages/SettingsSecurity.test.ts`（追記。既存の fetch mock ハーネスを流用）**
- `it('有効化ボタンは stale なら再認証モーダルを開き、enable を POST しない')`
  - `/recent-auth/status` を `{recent: false, ...}` に stub
  - `routerPostMock` が `/user/two-factor-authentication` で呼ばれて**いない**こと
  - `RecentAuthModal` が表示されること
- `it('有効化ボタンは fresh なら enable を POST する')`（負のコントロール）
- `it('素材取得が両方 409 でも再認証モーダルの起動は 1 回だけ')`
  - qr / secret 両方を `{ok:false, status:409, json: {code:"recent_auth_required"}}` に stub
  - `/recent-auth/status` の fetch 呼び出しが **1 回**、モーダルが 1 つだけ描画されること
  - 「設定情報を取得できませんでした」Alert が**出ない**こと（409 を取得失敗に畳まない）
- `it('片方だけ 409 でも再認証モーダルへ倒す')`（部分的鮮度切れの一貫性）
- `it('409 以外の失敗 (500) は従来どおり取得失敗 Alert を出し、モーダルを開かない')`
  ← **通常エラーを step-up へ誤分類しないことの負のコントロール**
- `it('再認証成立後に素材取得が再開され QR とセットアップキーが表示される')`
  - `pendingAction` の resume 契約（モーダルの `onConfirmed`）

**Browser テスト**: 追加しない（既存 Chromium/WebKit 2 レーンの契約は変えない。
本変更は JSON fetch の分岐でありブラウザ差の対象ではない）。

### リスク
- **`guardWithRecentAuth` の再帰**: `loadEnrollmentAssets` → 409 → `guardWithRecentAuth`
  → 成立 → `loadEnrollmentAssets` → また 409、という循環は
  「step-up 直後にまた鮮度切れ」が必要で現実には起きないが、**理論上は無限ループ**になる。
  `enrollmentGeneration` は世代を進めるだけで回数を持たない。
  → **対策**: `withRecentAuth` は `onStale` でモーダルを開くだけであり、
  成立しなければ再取得は走らない（ユーザー操作が挟まる）。自動再試行は
  「step-up が成立したとき 1 回」だけなので、暴走ループにはならない。
  この根拠をコードコメントに残す。
- **世代管理との相互作用**: 409 分岐は `generation !== enrollmentGeneration` の後に置くため、
  破棄済みの取得が再認証モーダルを開くことはない。
- **`enableTwoFactor` の precheck 遅延**: `/recent-auth/status` の往復ぶん押下から POST まで
  遅れる。既存の `regenerateRecoveryCodes` / `disableTwoFactor` と同じ体験であり、
  `enabling` loading 状態は `onStart` で立つ。**precheck 区間は loading に覆われない**
  （既存の `showRecoveryCodes` と同じ既知の挙動。ここだけ変えると一貫性が崩れるため揃える）。

---

## 施策 5: 運用契約の記録

### 変更箇所
- `AGENTS.md` §ドメイン固有規約（新項目を 1 つ追加）
- `docs/architecture.md`（2FA 面の step-up 契約の節を追加、または既存の認証節へ追記）

### 波及変更
- テストファイル: なし（ドキュメント）
- ただし `tests/js/architecture/verification-commands-doc-sync.test.ts` が守るマーカー領域には触れない

### 変更後コード（AGENTS.md 追記案）

```markdown
8. **2FA 面の step-up (recent-auth) 規約**: route 名に `two-factor` を含む route は
   **recent-auth をちょうど 1 本持つ**か、`TwoFactorStepUpExemption` + 30 文字以上の
   根拠付きで exemption inventory へ登録する (`TwoFactorStepUpInventoryTest` が
   deny-by-default で強制。母集団は **exact-fit**)。
   - 秘密を開示する 3 本 (`two-factor.qr-code` / `two-factor.secret-key` /
     `two-factor.recovery-codes`) は **exemption にできない** (gate が名指しで固定)。
     throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の
     代替ではない。
   - `two-factor.enable` も対象。Fortify の `force=true` は seed とリカバリコードを
     再生成する一方で `two_factor_confirmed_at` を触らないため、開けたままにすると
     **奪取セッションから永久ロックアウトを作れる**。
   - **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で
     第二要素へ触る route には**沈黙する**。別名の route を足すときは inventory の
     母集団設計も同時に見直すこと。
   - step-up を新しい面に課すときは **satisfier の到達性**を必ず確認する。
     2FA 必須組織のゲート (`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
     password / 再SSO / **passkey** の 3 satisfier をすべて通す (どれか 1 つでも欠けると
     その手段しか持たないユーザーが詰む)。
```

### PHPStan適合チェック
- 該当なし（ドキュメントのみ）

### テスト計画
- `composer test` / `pnpm test` の全 green を再確認（ドキュメント変更が
  `verification-commands-doc-sync` 等の doc 同期 gate に触れていないこと）

### リスク
- AGENTS.md のドメイン固有規約は既に 7 項目ある。8 項目目として追加する
  （既存項目の番号は**変えない**。他ドキュメントからの参照を壊さないため）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `FortifyServiceProvider` / `RequireTwoFactorForEnforcedOrganizations` / `Settings/Security.svelte` という**認証面の中核**を触るため、他施策と同じ worktree に混ぜると赤の切り分けが困難になる。(2) Architecture gate を新設する施策は「素の main では赤にならない」ため、Step A（配線前の自然な赤）を**単独コミットで実測**する必要があり、他の変更が混ざると観測が濁る。(3) `AuthThrottleCoverageTest` の巻き添え赤を確認する必要があり、テストレーンをグローバルロックで占有する時間が読めない。 |
| 競合リスク | 並列設計中の他 4 件（T125 / T126 / lctl 2 件）が `FortifyServiceProvider` / `tests/Architecture/` / `resources/js/pages/Settings/Security.svelte` を触る場合に衝突しうる。特に `tests/Architecture/` へ新規ファイルを足す設計は衝突しないが、`AGENTS.md` §ドメイン固有規約の**番号**は衝突する（施策 5）。マージ時に番号を振り直すのではなく、**後にマージする側が次の番号を取る**こと。 |

## 実装完了の定義（Definition of Done）

1. `composer test` 全 green（新規 Feature 2 本 + Architecture 1 本を含む）
2. `composer phpstan` level 10 No errors
3. `vendor/bin/pint --test` passed
4. `pnpm lint` / `pnpm typecheck` / `pnpm test` passed
5. Step A（配線前の自然な赤）の実測ログが
   `devnotes/20260807-2032-todo-T124-design/impl-step2-fail-observation.md` にある
6. Step C（mutation m1〜m7）の実測ログが
   `devnotes/20260807-2032-todo-T124-design/mutation-observation.md` にあり、
   **全 mutation が revert 済み**であること（`git status` clean で確認）
7. 既存テストの削除・無効化・緩和がゼロであること（diff で確認）


---

## 関連する現行コード（抜粋）

### FortifyServiceProvider — recent-auth 後付け配線 (`app/Providers/FortifyServiceProvider.php` L56-250)

```php
class FortifyServiceProvider extends ServiceProvider
{
    /**
     * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
     * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
     * 到達させない (姉妹操作: organizations.members.two-factor.reset /
     * settings.account.destroy 等と同基準)。
     * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
     * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
     *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
     *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
     *     self-disable が許可される非 enforced 組織のユーザー。
     * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
     *
     * @var list<string>
     */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.disable',
    ];

    /**
     * email 変更時のみ recent-auth を課す条件付き付与 (氏名のみ変更は素通し)。
     * profile 更新は Fortify 登録ルートのため booted で後付けする。
     *
     * @var array<string, string> route name => middleware alias
     */
    private const CONDITIONAL_RECENT_AUTH_ROUTES = [
        'user-profile-information.update' => 'recent-auth.on-email-change',
    ];

    public function register(): void
    {
        // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
        // 挙動の意図は各 Response クラスの docblock を参照。
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        // verify 完了着地: continuation があれば onboarding.checkout、無ければ Fortify 既定と同値。
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
        $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
        // profile / password 更新は success flash に統一し保存完了を toast 化する
        // (status キーは flash-to-toast が gating するため toast にならない)。
        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileUpdatedResponse::class);
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdatedResponse::class);
        // password reset は Fortify が constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(PasswordResetResponseContract::class, PasswordResetResponse::class);
        // forgot-password は成功/失敗の両契約を enumeration-safe な同一応答へ差し替える。
        // Fortify は constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        // ログアウト着地で Inertia::clearHistory() を発火させる (bug-hunt F-4-01)。
        // 着地 route を固定する理由と順序の前提は LogoutResponse の docblock を参照。
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->configureRateLimiters();
        $this->configureViews();
        $this->attachRecentAuthToSensitiveRoutes();
        $this->attachThrottleToFortifyRoutes();
    }

    /**
     * Fortify が登録する認証系 route への throttle 後付け表。
     *
     * config/fortify.php の `limiters` は login / two-factor / passkeys / verification の
     * 4 キーしか受け付けないため、それ以外は route 名での後付けで賄う
     * (「貼る仕組みの 3 段優先順」の第 2 段。第 1 段で貼れるものは第 1 段のまま)。
     *
     * 閾値の根拠:
     *  - password-reset-request / password-reset-submit / account-register は
     *    「未認証 + メール送信または credential 総当り」であり、**既に本番稼働中の
     *    同性質エンドポイント (inquiry / login) と同値**にする (新しい値を発明しない)。
     *  - `6,1` は recent-auth.password / settings.password.store と同値 (自分の credential 操作)。
     *  - `10,1` は onboarding.activate-personal と同値 (認証済みの管理操作)。
     *
     * ★inline (`6,1` / `10,1`) を使ってよいのは **認証済みかつ actor 自身に閉じる route** だけ。
     *   未認証面 / 主体が IP や email になる面は必ず named limiter を作ること。
     *   **さらに注意**: inline のキーは `sha1(user id)` だけで route も limiter 名も入らないため、
     *   **同一 actor の全 inline throttle route が 1 bucket を共有する**
     *   (ThrottleRequests::handle() の $prefix 既定 '' + resolveRequestSignature())。
     *   したがって inline は「その actor の全 inline 操作を合算して数えてよい」場合に限る。
     *   ページ描画のたびに飛ぶような高頻度レーンを inline で足すと、
     *   合算値が最小 max (recent-auth.password = 6) を先に食い潰して再認証を壊す。
     *   そういう面は named limiter でレーンを分ける (下記 two-factor-secret-read)。
     *
     * ★`feature` は Fortify の機能フラグ (config/fortify.php の `features`)。
     *   null = 常に必須 (route が無ければ起動時 fail-fast)。
     *   非 null = その機能が有効なときだけ必須 (無効なら route 自体が登録されないため skip)。
     *   **skip が穴にならない根拠**: 機能を再有効化して binder が skip したままなら、
     *   ThrottleCoverageInventoryTest が「throttle 無しの保護対象 route」として必ず fail する
     *   (binder の fail-fast と目録検査の二重の網で守る)。
     *
     * @return array<string, array{throttle: string, feature: string|null}>
     */
    private static function throttledFortifyRoutes(): array
    {
        return [
            'password.email' => ['throttle' => 'password-reset-request', 'feature' => Features::resetPasswords()],
            'password.update' => ['throttle' => 'password-reset-submit', 'feature' => Features::resetPasswords()],
            'register.store' => ['throttle' => 'account-register', 'feature' => Features::registration()],
            'password.confirm.store' => ['throttle' => '6,1', 'feature' => null],
            'user-password.update' => ['throttle' => '6,1', 'feature' => Features::updatePasswords()],
            'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            // ★秘密を返す GET 3 本 (T120 事後監査の是正)。
            //   named limiter を使う理由は configureRateLimiters() の
            //   two-factor-secret-read の docblock を参照 (inline は bucket を
            //   全 inline route で共有するため、描画 GET を足すと再認証を壊す)。
            'two-factor.qr-code' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.secret-key' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.recovery-codes' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
        ];
    }

    /**
     * Fortify 登録 route へ throttle を後付けする (設定で貼れないものだけ)。
     *
     * route 登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決する (attachRecentAuthToSensitiveRoutes と同じ流儀)。
     * 後付けは冪等で、route 名が消えていれば fail-fast する
     * (route:cache 起動時の扱いは RouteThrottleBinder::attachOnBooted の docblock を参照)。
     */
    private function attachThrottleToFortifyRoutes(): void
    {
        $routes = [];

        foreach (self::throttledFortifyRoutes() as $name => $spec) {
            if ($spec['feature'] !== null && ! Features::enabled($spec['feature'])) {
                continue; // 機能無効 = route 自体が存在しない (目録検査が二重の網)
            }

            $routes[$name] = $spec['throttle'];
        }

        RouteThrottleBinder::attachOnBooted($this->app, $routes);
    }

    /**
     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
     *
     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決して append する。route:cache 下でも
     * CompiledRouteCollection::getByName() が nameCache に memoize した同一 instance を
     * match() が返すため、この変更は dispatch にも有効。
     */
    private function attachRecentAuthToSensitiveRoutes(): void
    {
        $this->app->booted(static function (Application $app): void {
            $routes = $app->make(Router::class)->getRoutes();
            // fluent な ->name() 付与はコレクションの name index に遅延反映のため明示 refresh
            $routes->refreshNameLookups();

            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
            }

            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
                self::appendMiddlewareIfMissing($routes, $name, $alias);
            }
        });
    }

    /**
     * named route に middleware alias を idempotent に append する (未登録時のみ)。
     *
     * booted callback (static クロージャ) から呼ぶため **static** で定義し
     * `self::appendMiddlewareIfMissing(...)` で呼ぶ。長寿命プロセス等で callback が
     * 同一 Route instance に複数回届いても重複付与しない (idempotent)。
     */
    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }

    private function configureRateLimiters(): void
    {
```

### FortifyServiceProvider — two-factor-secret-read limiter (`app/Providers/FortifyServiceProvider.php` L290-320)

```php

        /*
         * 2FA の秘密を返す GET (qr-code / secret-key / recovery-codes) の読み取りレーン。
         *
         * ★inline (`10,1`) にしない: inline のキーは sha1(user id) だけで
         *   **同一ユーザーの全 inline route が 1 bucket を共有する**
         *   (ThrottleRequests::resolveRequestSignature)。ページ描画で 2 発飛ぶ GET を
         *   そこへ足すと、リロード数回で recent-auth.password (max 6) まで 429 にしてしまう。
         *   named limiter はキーに limiter 名が入るためレーンが独立する。
         *
         * ★閾値 10/min は姉妹の 2FA 管理操作 (two-factor.enable / .confirm / .disable /
         *   .regenerate-recovery-codes の `10,1`) と同値 (新しい値を発明しない)。
         *
         * ★throttle は auth middleware より先に走る (priority list) ため未認証でも
         *   closure が評価される。passkeys limiter と同じく IP へ倒す。
         *
         * ★これは**連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
         *   認証強度 (recent-auth 化) は aicue:T120 の後続 TODO B2 の担当。
         */
        RateLimiter::for('two-factor-secret-read', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return is_scalar($identifier)
                ? Limit::perMinute(10)->by('two-factor-secret-read:user:'.$identifier)
                : Limit::perMinute(10)->by('two-factor-secret-read:ip:'.($request->ip() ?? 'unknown'));
        });

        $this->configureAuthFormRateLimiters();
    }

    /**
```

### RequireRecentAuth middleware（遮断契約の正本） (`app/Http/Middleware/RequireRecentAuth.php`)

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\RecentAuthRequiredDto;
use App\Http\Resources\Auth\RecentAuthRequiredResource;
use App\Security\RecentAuthWindow;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 機微操作の前に generic recent-auth (step-up 再認証) を強制する単一ゲート。
 * alias: `recent-auth`。
 *
 * Fortify 生の `password.confirm` (password 専用・3h 窓) を置き換える。satisfier は
 * ConfirmRecentAuthController (password 再入力) と SocialAuthController の step-up intent
 * (再SSO) に集約され、SSO-only ユーザーも fail-closed で詰まずに再SSO へ誘導される。
 *
 * 判定:
 *   1. `recent_auth_at` が鮮度ウィンドウ内 (RecentAuthWindow) → 通過
 *   2. XHR (expectsJson) または Inertia の非 GET → 409 + { code, message, redirect }(no-store)。
 *      クライアント (素 fetch / recent-auth precheck) が再認証後に元操作を再送。
 *      Inertia mutation のときだけ 302 分岐と同じ着地情報 (url.intended /
 *      recent_auth.dropped_mutation) を残す (confirm 後に元画面へ戻すため)
 *   3. それ以外 (通常遷移) → 302 で recent-auth confirm 画面へ。元 URL を intended に保持
 */
final class RequireRecentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if (RecentAuthWindow::isFresh($session->get('recent_auth_at'))) {
            $response = $next($request);
            if (! $response instanceof Response) {
                throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
            }

            return $response;
        }

        $confirmUrl = route('recent-auth.confirm');

        // XHR (expectsJson) と Inertia の非 GET visit は 409 + code。クライアントが再認証後に
        // 元操作を再送する。Inertia GET は従来どおり 302 → confirm → intended GET replay が
        // 機能するため対象外。409 に x-inertia-location / x-inertia-redirect ヘッダを付けない
        // こと (Inertia core の external redirect 信号と衝突するため)。
        if ($request->expectsJson() || $this->isInertiaMutation($request)) {
            // Inertia mutation の 409 は、クライアント (lib/recent-auth.ts の単一ハンドラ) が
            // confirm 画面へ visit する。302 分岐と同じ着地契約に揃えるため、元 URL と
            // 「mutation body を落とした」flag をここでも残す (残さないと confirm 成功後に
            // dashboard へ落ち、操作がサイレントに失われる)。
            // 純 XHR (fetch + Accept: application/json) は**対象外**: クライアントが自前で
            // pending action を再開するため、intended を書くと他フローの intended を汚す。
            if ($this->isInertiaMutation($request)) {
                $session->put('url.intended', $this->sameOriginRefererOrDashboard($request));
                $session->put('recent_auth.dropped_mutation', true);
            }

            return RecentAuthRequiredResource::make(new RecentAuthRequiredDto(
                message: 'この操作には直近の再認証が必要です。',
                redirect: $confirmUrl,
            ))
                ->response()
                ->setStatusCode(409)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        // GET は fullUrl (自 origin 確定)、それ以外は遷移元が無いので referer を intended に。
        // referer はクライアント制御ヘッダで外部 URL になり得るため、same-origin のみ採用し
        // それ以外 (外部 origin / 不在) は dashboard へフォールバックする (open redirect 防止)。
        $intended = $request->isMethod('GET')
            ? $request->fullUrl()
            : $this->sameOriginRefererOrDashboard($request);
        $session->put('url.intended', $intended);

        // 非 GET の 302 fallback (非 Inertia の素フォーム POST 等) は mutation body を保持できない。
        // confirm 成功後に「もう一度操作してください」を案内するための one-shot flag
        // (サイレント喪失防止の defense-in-depth、satisfier 側が消費する)。
        if (! $request->isMethod('GET')) {
            $session->put('recent_auth.dropped_mutation', true);
        }

        return redirect()->route('recent-auth.confirm');
    }

    /**
     * Inertia protocol の mutation visit (X-Inertia ヘッダ + 非 GET)。
     * Accept は text/html のため expectsJson() では捕捉できない。
     */
    private function isInertiaMutation(Request $request): bool
    {
        return $request->hasHeader('X-Inertia') && ! $request->isMethod('GET');
    }

    private function sameOriginRefererOrDashboard(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if ($referer === null) {
            return route('dashboard');
        }

        // 完全一致 or 「origin + '/'」前置一致のみ same-origin と判定する。
        // 単純な str_starts_with($referer, $origin) だと https://app.host.evil.com を通すため、
        // 区切り '/' まで含めて比較する。
        $origin = $request->getSchemeAndHttpHost();
        if ($referer === $origin || str_starts_with($referer, $origin.'/')) {
            return $referer;
        }

        return route('dashboard');
    }
}

```

### RequireTwoFactorForEnforcedOrganizations（2FA 必須ゲート） (`app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` L30-80)

```php
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
```

### RecentAuthRouteTest（既存 allowlist 型 gate） (`tests/Architecture/RecentAuthRouteTest.php` L1-86)

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ConfirmRecentAuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Middleware\RequireRecentAuth;
use App\Listeners\Auth\StampRecentAuthOnLogin;
use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;

/*
 * 機微操作 route に recent-auth middleware が付与されていることを CI で担保する (付与漏れ検出)。
 * 新たな機微操作 route を追加した PR は本 allowlist の更新を PR review で判断すること。
 */

/**
 * @return list<string>
 */
function recentAuthRequiredRouteNames(): array
{
    return [
        // API キー (発行 / 失効)
        'organizations.api-keys.store',
        'organizations.api-keys.revoke',
        // OAuth セッション失効 (組織管理経路。API キー失効と同じ機微度)
        'organizations.api-keys.sessions.revoke',
        // アカウント削除
        'settings.account.destroy',
        // パスワード初回設定 (認証手段を増やす操作。セッション奪取からの永続化を防ぐため step-up 必須)
        'settings.password.store',
        // オーナー移譲
        'organizations.transfer-ownership',
        // 組織の 2FA 必須方針トグル (Owner 専権のセキュリティ方針変更)
        'organizations.two-factor-requirement.update',
        // メンバー 2FA リセット (アカウント全体の第二要素を外す機微操作)
        'organizations.members.two-factor.reset',
        // リカバリコード表示 / 再生成 (第二要素の bypass 経路。Fortify 登録ルートへ
        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が後付け配線)
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
        'two-factor.disable',
        // profile 更新 (email 変更時のみ条件付き step-up。配線は
        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
        // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
        'user-profile-information.update',
        // passkey 管理 (credential 集合を増減させる経路。配線は
        // App\Providers\PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes())。
        // passkey.confirm / passkey.confirm-options は **satisfier 側**のため対象外
        // (自分自身に step-up を要求すると詰む)。
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];
}

function routeHasRecentAuth(RoutingRoute $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }
        // alias 'recent-auth' / 'recent-auth:param' / 完全クラス名のいずれかを許容 (堅牢化)
        if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
            return true;
        }
    }

    return false;
}

test('機微操作 route 全件に recent-auth middleware が付与されている', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (recentAuthRequiredRouteNames() as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない (allowlist の更新漏れ?)");
        expect(routeHasRecentAuth($route))->toBeTrue("route '{$name}' に recent-auth middleware が付与されていない (付け忘れ)");
    }
});

```

### ThrottleCoverageInventoryTest（deny-by-default 目録の見本・冒頭） (`tests/Architecture/ThrottleCoverageInventoryTest.php` L1-95)

```php
<?php

declare(strict_types=1);

use App\Enums\Security\ThrottleCoverageExemption;
use App\Support\Http\RouteThrottleBinder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * 流量制限 (throttle) の付与漏れ invariant (deny-by-default)。
 *
 * 「保護対象群に属する route は throttle をちょうど 1 本持つ」を機械強制する。
 * 持たないものは理由付きで exemption inventory へ明示登録させる。
 *
 * ★保護対象群 (S1 ∪ S2 ∪ S3) は意図的に**過大に**取る:
 *   S1 は「未認証で本体に到達する」ことを主張しない。signed / 定数 405 スタブ /
 *   LocalOnly / 署名検証など、Authenticate 以外で本体到達を閉じる route も S1 に入る。
 *   **exemption の役割は「本体到達しない根拠を固定すること」**である
 *   (過小なセレクタはすり抜けを生むが、過大なセレクタは exemption 理由という形で
 *    根拠が文書化されるだけで済む)。
 *
 * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
 *   (`route:list --json` は group 名 'web' が展開されず誤判定するため使わない)。
 *   throttle 判定は RouteThrottleBinder::isThrottleEntry() を唯一の判定点として共有する。
 */

/** 変更系 HTTP メソッド。 */
function throttleCoverageMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/** 認証面の route 名パターン (S3)。 */
function throttleCoverageAuthSurfacePattern(): string
{
    return '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
        .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#';
}

/** 母集団件数の下限 (空振り drift ガード。実測 70 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 60;
}

/** exemption 件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function throttleCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。exact fit なら次の 1 本が必ず「この数値を変える差分」
    //   として現れ、個別理由・前提テスト追加要否・そもそも貼るべきでないかの
    //   再検討を強制できる。上げる前に必ず再検討すること。
    return 25;
}

/**
 * exemption の case 別上限 (分類の偏り検出)。全体 cap とは役割が違う
 * (全体 = セレクタの広さ / case 別 = どのカテゴリが膨らんだか)。
 * ★array_sum() で全体 cap を導出しない (両方を独立に検査する)。
 *
 * @return array<string, int> ThrottleCoverageExemption::value => 上限
 */
function throttleCoverageExemptionCapByCase(): array
{
    return [
        ThrottleCoverageExemption::StaticMetadataResponse->value => 4,
        ThrottleCoverageExemption::VendorMethodNotAllowedStub->value => 2,
        ThrottleCoverageExemption::SessionTeardownOnly->value => 2,
        ThrottleCoverageExemption::LocalOnlyDebugRoute->value => 1,
        ThrottleCoverageExemption::ComponentLevelLimiter->value => 1,
        ThrottleCoverageExemption::SignatureRequiredBeforeEffect->value => 1,
        // ★ここが膨らむ = 「貼るべき route を描画系として逃がした」疑い。
        ThrottleCoverageExemption::AuthViewRenderOnly->value => 13,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall->value => 1,
    ];
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function throttleCoverageReasonMinLength(): int
{
    return 30;
}

/**
 * throttle を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ThrottleCoverageExemption, string}>
 */
function throttleCoverageExemptions(): array
{
```

### ThrottleCoverageExemption（enum の見本） (`app/Enums/Security/ThrottleCoverageExemption.php` L1-60)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「保護対象群に属する route が throttle を持たないことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/ThrottleCoverageInventoryTest.php` が deny-by-default で
 * 「throttle ちょうど 1 本」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「throttle を貼るべき route」である。
 */
enum ThrottleCoverageExemption: string
{
    /**
     * 定数メタデータ応答。
     *
     * 適用条件: DB アクセス・暗号処理・外部呼び出し・メール送信・ファイル書込を一切伴わず、
     * 応答が config と url() だけで決まる。
     */
    case StaticMetadataResponse = 'static_metadata_response';

    /**
     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
     *
     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
     */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';

    /**
     * セッション破棄のみを行い、推測可能な秘密を一切扱わない route。
     *
     * 適用条件: 認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い。
     */
    case SessionTeardownOnly = 'session_teardown_only';

    /**
     * local / testing でのみ登録され、**production では route 登録自体が起きない**デバッグ用 route。
     *
     * 適用条件: `routes/*.php` 側で `app()->isLocal() || app()->runningUnitTests()` 等により
     * 登録が囲われ、かつ `LocalOnly` 相当の middleware が二重防御であること。
     * (Architecture テストは testing 環境で走るため、この route は**母集団に現れる**。
     *  「テストからは見えない」ではなく「本番には存在しない」が exemption の根拠である)
     */
    case LocalOnlyDebugRoute = 'local_only_debug_route';

    /**
     * 防御が route ではなく component 内にある。
     *
     * 適用条件: 単一 endpoint に多数の操作が相乗りしており、route 単位の bucket では
     * 無関係な操作を巻き添えにする。かつ component 側に実際の制限実装がある。
     */
    case ComponentLevelLimiter = 'component_level_limiter';

    /**
     * 有効な署名が無ければ本体処理に到達しない route。
```

### TwoFactorRecoveryCodesStepUpTest（Feature テストの見本） (`tests/Feature/Auth/TwoFactorRecoveryCodesStepUpTest.php`)

```php
<?php

declare(strict_types=1);

use App\Models\User;

/*
 * リカバリコード表示 (GET) / 再生成 (POST) の recent-auth (step-up) 配線。
 *
 * Fortify 登録ルートには FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が
 * booted callback で recent-auth middleware を後付けする。ここではその実効性
 * (stale で遮断 / fresh で通過) を HTTP 経由で検証する。allowlist の付与漏れ検出は
 * RecentAuthRouteTest (Architecture) 側。
 */

test('鮮度なしの GET リカバリコード (XHR) は 409 recent_auth_required でコードを返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->getJson('/user/two-factor-recovery-codes')
        ->assertStatus(409)
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);
});

test('鮮度なしの POST 再生成 (XHR) は 409 recent_auth_required で旧コードを失効させない', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();
    $before = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->postJson('/user/two-factor-recovery-codes')
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->two_factor_recovery_codes)->toBe($before);
});

test('鮮度なしの通常 POST 再生成は recent-auth confirm へ 302 する', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->post('/user/two-factor-recovery-codes')
        ->assertRedirect(route('recent-auth.confirm'));
});

test('fresh なら GET がコード一覧を返し POST が再生成する', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->refresh();
    $before = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->getJson('/user/two-factor-recovery-codes')
        ->assertOk()
        ->assertJsonCount(8);

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->postJson('/user/two-factor-recovery-codes')
        ->assertOk();

    $user->refresh();
    expect($user->two_factor_recovery_codes)->not->toBe($before);
});

```

### Settings/Security.svelte（変更対象の script 部） (`resources/js/pages/Settings/Security.svelte` L55-240)

```svelte
    /* ----------------------------------------------------------------
     * 2FA 管理
     * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
     * → リカバリコード表示 → 有効。無効化は ConfirmDialog 経由。
     * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
     * リカバリコード表示/再生成の endpoint は recent-auth 配線済み
     * (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes())。フロントは
     * guardWithRecentAuth で precheck し、stale なら再認証モーダルを挟んで再開する。
     * 残る 2FA endpoint の配線は config/fortify.php の TODO(template) 参照。
     * ---------------------------------------------------------------- */

    /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
    let recentAuthOpen = $state(false);
    let recentAuthStatus = $state<RecentAuthStatus | null>(null);
    let pendingAction: (() => void) | null = null;

    /**
     * precheck の結果 (fresh / stale / delegated) を **返す**。
     * PasskeySection は precheck 区間 (`/recent-auth/status` の待ち時間) を自前の loading で
     * 覆う必要があるため戻り値を待つ。結果に関心が無い呼び出し側は `void` で明示的に捨てる。
     */
    function guardWithRecentAuth(action: () => void): Promise<"fresh" | "stale" | "delegated"> {
        return withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
        });
    }

    function resumePendingAction(): void {
        const action = pendingAction;
        pendingAction = null;
        action?.();
    }

    $effect(() => {
        // 再認証モーダルが閉じたら pending の destructive closure を破棄 (キャンセル時の残置防止)。
        // onConfirmed 経由の resume は action をローカルへ退避してから pendingAction を null 化するため
        // (resumePendingAction: `const a = pendingAction; pendingAction = null; a?.();`)、
        // 本 effect と二重で走っても resume が先に action を握っており安全。
        if (!recentAuthOpen) {
            pendingAction = null;
        }
    });

    /** QR 確認待ち (有効化開始済みだが未確認) */
    let confirming = $state(false);
    let enabling = $state(false);
    /**
     * enrollment 素材。QR と手動セットアップキーは独立に失敗しうる
     * (片方でも enrollment は続行できる = カメラ不可端末 / QR 非対応アプリ / 支援技術利用者を詰ませない)。
     */
    let qrSvg = $state<string | null>(null);
    let setupKey = $state<string | null>(null);
    /** 両方の取得に失敗した = enrollment を続行できない (再試行導線を出す) */
    let enrollmentAssetsFailed = $state(false);
    let loadingEnrollmentAssets = $state(false);
    let recoveryCodes = $state<string[]>([]);
    let loadingRecoveryCodes = $state(false);
    /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
    let recoveryCodesPanel = $state<HTMLDivElement | null>(null);

    /**
     * Fortify の 2FA 確認アクション (ConfirmTwoFactorAuthentication) は検証失敗を
     * 名前付き error bag "confirmTwoFactorAuthentication" に投げる
     * (login チャレンジ側は default bag)。Inertia は default bag が無いと named bag を
     * ネストしたまま共有するため、client 側で同名の errorBag を指定しないと
     * confirmForm.errors.code が解決されず、誤コード時に無言失敗する (F-2-02)。
     */
    const CONFIRM_TWO_FACTOR_ERROR_BAG = "confirmTwoFactorAuthentication" as const;

    const confirmForm = useForm({
        code: "",
    });

    async function fetchJson<T>(url: string): Promise<T> {
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return (await response.json()) as T;
    }

    /**
     * JSON レスポンスから非空文字列の field を取り出す。
     * fetchJson の generic は型 assertion にすぎないため shape は信用せず narrowing する
     * (不正 shape は通信失敗と同じ「その手段が使えない」に畳む)。
     */
    function readStringField(payload: unknown, key: string): string | null {
        if (typeof payload !== "object" || payload === null) return null;
        const value = (payload as Record<string, unknown>)[key];
        return typeof value === "string" && value.trim() !== "" ? value : null;
    }

    /** 単一 endpoint から文字列 field を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
        表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。 */
    async function fetchStringField(url: string, key: string): Promise<string | null> {
        try {
            return readStringField(await fetchJson<unknown>(url), key);
        } catch {
            return null;
        }
    }

    /**
     * 取得世代。**後着優先**の判定に使う。
     * 破棄 (reset) と取得開始で進み、解決時に世代が変わっていれば結果を捨てる。
     * これが無いと (a) confirm/disable 成功で消したはずの secret が、遅れて解決した
     * fetch で再格納される (= サーバの新しい secret とは違うキーを認証アプリに登録させてしまう)
     * (b) 古い run が loading を握り続けて再有効化が始まらない、の 2 つの競合が起きる。
     */
    let enrollmentGeneration = 0;

    /**
     * enrollment 素材 (QR + 手動セットアップキー) を取得する。
     * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
     * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
     */
    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;

        const [qr, secret] = await Promise.all([
            fetchStringField("/user/two-factor-qr-code", "svg"),
            fetchStringField("/user/two-factor-secret-key", "secretKey"),
        ]);

        // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
        // (finally で戻すと古い run が新しい run の loading を消してしまう)
        if (generation !== enrollmentGeneration) return;

        qrSvg = qr;
        setupKey = secret;
        enrollmentAssetsFailed = qr === null && secret === null;
        loadingEnrollmentAssets = false;
    }

    /**
     * enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ)。
     * 世代を進めることで、進行中の取得結果が後から再格納されるのを防ぐ。
     * TOTP secret の残置時間を enrollment 中に限定する目的も兼ねる。
     */
    function resetEnrollmentAssets(): void {
        enrollmentGeneration += 1;
        qrSvg = null;
        setupKey = null;
        enrollmentAssetsFailed = false;
        loadingEnrollmentAssets = false;
    }

    /**
     * リカバリコードを取得する。成否を返し、失敗時の文言は呼び出し側が文脈に応じて出す
     * (通常表示: 単純な取得失敗 / 再生成直後: 旧コード失効済みの注意)。
     */
    async function loadRecoveryCodes(): Promise<boolean> {
        loadingRecoveryCodes = true;
        try {
            recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
            return true;
        } catch {
            return false;
        } finally {
            loadingRecoveryCodes = false;
        }
    }

    /**
     * 「リカバリコードを表示」押下時 (失敗は取得失敗トースト)。
     * GET も recent-auth 配線済みのため precheck を通す (stale なら再認証モーダル→再開)。
     */
    function showRecoveryCodes(): void {
        void guardWithRecentAuth(() => {
            void (async () => {
                if (!(await loadRecoveryCodes())) {
                    addToast("error", "リカバリコードの取得に失敗しました。");
                }
            })();
        });
    }

    /* ---- リカバリコード再生成 (F-10) ----
```

### Settings/Security.svelte（enableTwoFactor と再試行ボタン） (`resources/js/pages/Settings/Security.svelte` L293-320)

```svelte
    }

    function enableTwoFactor(): void {
        // 再試行時に前回の素材・エラーを持ち越さない
        resetEnrollmentAssets();
        router.post(
            "/user/two-factor-authentication",
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    enabling = true;
                },
                onSuccess: () => {
                    confirming = true;
                    void loadEnrollmentAssets();
                },
                onFinish: () => {
                    enabling = false;
                },
            },
        );
    }

    function confirmTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        confirmForm.post("/user/confirmed-two-factor-authentication", {
            preserveScroll: true,
```

### lib/recent-auth.ts（409 判定の既存実装） (`resources/js/lib/recent-auth.ts` L99-189)

```ts
/**
 * 保護操作の前段ゲート。
 *
 * - fresh: `onFresh` を実行して終了。
 * - stale: `onStale(status)` を呼び、呼び出し側が再認証モーダルを開く。
 * - status 取得失敗 (network/5xx/parse) = delegated: 状態不明でモーダルを出せないため
 *   middleware の最終ゲートに委譲する (既定は `onFresh` にフォールバック。
 *   別動作が必要な画面は `onDelegated` を明示指定する)。
 *
 * 戻り値は実行した分岐 (テスト・呼び出し側の分岐確認用)。
 */
export async function withRecentAuth(handlers: {
    onFresh: () => void;
    onStale: (status: RecentAuthStatus) => void;
    /** status 取得失敗時の委譲動作。未指定なら onFresh にフォールバック (最終ゲート委譲)。 */
    onDelegated?: () => void;
}): Promise<"fresh" | "stale" | "delegated"> {
    const status = await fetchRecentAuthStatus();
    if (status === null) {
        addToast("info", "再認証が必要な場合は確認ページへ移動します。");
        (handlers.onDelegated ?? handlers.onFresh)();
        return "delegated";
    }
    if (status.recent) {
        handlers.onFresh();
        return "fresh";
    }
    handlers.onStale(status);
    return "stale";
}

/** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
/** 遷移を許す唯一の着地 (サーバ由来 URL を無検証でグローバル遷移に使わない) */
const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";

/**
 * `httpException` の `event.detail.response` (Inertia core の `HttpExceptionResponse` =
 * `{ status, data, headers }`。`data` は JSON なら parse 済みオブジェクト、
 * それ以外は生文字列) から confirm 画面への遷移先を取り出す。
 *
 * 受入条件を満たさないものは null を返し、呼び出し側は preventDefault しない (fail-closed)。
 */
function recentAuthRedirectTarget(response: unknown): string | null {
    if (typeof response !== "object" || response === null) return null;
    const { status, data } = response as { status?: unknown; data?: unknown };
    if (status !== 409) return null;
    if (typeof data !== "object" || data === null) return null;
    const { code, redirect } = data as Record<string, unknown>;
    if (code !== RECENT_AUTH_REQUIRED_CODE) return null; // 他の 409 契約を誤食しない
    if (typeof redirect !== "string") return null;
    // same-origin かつ既知 path のみ (外部 URL / 別 route への誘導を構造的に不能にする)
    let url: URL;
    try {
        url = new URL(redirect, window.location.origin);
    } catch {
        return null;
    }
    if (url.origin !== window.location.origin) return null;
    if (url.pathname !== RECENT_AUTH_CONFIRM_PATH) return null;
    return url.pathname + url.search;
}

/**
 * recent-auth 鮮度切れの 409 を confirm 画面への Inertia visit に変換する。
 *
 * precheck (withRecentAuth) を通れない経路 = status 取得失敗・契約不成立 (delegated) では
 * 元操作がそのまま飛び、サーバ (RequireRecentAuth) が 409 + `recent_auth_required` を返す。
 * 誰も拾わないと Inertia の既定 (エラーモーダル表示) になり **無言の行き止まり**になるため、
 * ここで単一のハンドラに集約する。
 *
 * **購読するイベントは `httpException`**: 詳細設計は Inertia v1/v2 の `invalid` を前提に
 * していたが、本リポジトリの @inertiajs/core 3.3.1 に `invalid` は存在せず、
 * 非 Inertia 応答 (4xx/5xx) の cancelable イベントは `httpException` に統合されている
 * (`Response#handleNonInertiaResponse`)。`preventDefault()` で既定のエラーモーダルを抑止する
 * 意味論は同一。
 *
 * サーバ側は 409 を返す際に `url.intended` と `recent_auth.dropped_mutation` を保存するため
 * (RequireRecentAuth)、confirm 成功後は元画面へ戻り「操作は未実行」の案内が出る。
 *
 * @returns 購読解除関数 (HMR の二重登録防止に使う)
 */
export function registerRecentAuthRedirectHandler(): () => void {
    return router.on("httpException", (event) => {
        const target = recentAuthRedirectTarget(event.detail.response);
        if (target === null) return;
        event.preventDefault();
        void router.visit(target);
    });
}

```

### vendor Fortify: 2FA route 定義 (`vendor/laravel/fortify/routes/routes.php` L132-178)

```php
    // Two Factor Authentication...
    if (Features::enabled(Features::twoFactorAuthentication())) {
        if ($enableViews) {
            Route::get(RoutePath::for('two-factor.login', '/two-factor-challenge'), [TwoFactorAuthenticatedSessionController::class, 'create'])
                ->middleware(['guest:'.config('fortify.guard')])
                ->name('two-factor.login');
        }

        Route::post(RoutePath::for('two-factor.login', '/two-factor-challenge'), [TwoFactorAuthenticatedSessionController::class, 'store'])
            ->middleware(array_filter([
                'guest:'.config('fortify.guard'),
                $twoFactorLimiter ? 'throttle:'.$twoFactorLimiter : null,
            ]))->name('two-factor.login.store');

        $twoFactorMiddleware = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
            ? [config('fortify.auth_middleware', 'auth').':'.config('fortify.guard'), 'password.confirm']
            : [config('fortify.auth_middleware', 'auth').':'.config('fortify.guard')];

        Route::post(RoutePath::for('two-factor.enable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.enable');

        Route::post(RoutePath::for('two-factor.confirm', '/user/confirmed-two-factor-authentication'), [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.confirm');

        Route::delete(RoutePath::for('two-factor.disable', '/user/two-factor-authentication'), [TwoFactorAuthenticationController::class, 'destroy'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.disable');

        Route::get(RoutePath::for('two-factor.qr-code', '/user/two-factor-qr-code'), [TwoFactorQrCodeController::class, 'show'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.qr-code');

        Route::get(RoutePath::for('two-factor.secret-key', '/user/two-factor-secret-key'), [TwoFactorSecretKeyController::class, 'show'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.secret-key');

        Route::get(RoutePath::for('two-factor.recovery-codes', '/user/two-factor-recovery-codes'), [RecoveryCodeController::class, 'index'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.recovery-codes');

        Route::post(RoutePath::for('two-factor.recovery-codes', '/user/two-factor-recovery-codes'), [RecoveryCodeController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.regenerate-recovery-codes');
    }

```

### vendor Fortify: 秘密を返す controller と enable action

```php
// TwoFactorSecretKeyController::show()
if (is_null($request->user()->two_factor_secret)) {
    abort(404, 'Two factor authentication has not been enabled.');
}
return response()->json([
    'secretKey' => Fortify::currentEncrypter()->decrypt($request->user()->two_factor_secret),
]);

// TwoFactorQrCodeController::show()
if (is_null($request->user()->two_factor_secret)) {
    return [];
}
return response()->json([
    'svg' => $request->user()->twoFactorQrCodeSvg(),
    'url' => $request->user()->twoFactorQrCodeUrl(),   // otpauth:// = 秘密を内包
]);

// TwoFactorAuthenticationController::store()  ← force がリクエストボディ由来
public function store(Request $request, EnableTwoFactorAuthentication $enable)
{
    $enable($request->user(), $request->boolean('force', false));
    return app(TwoFactorEnabledResponse::class);
}

// EnableTwoFactorAuthentication::__invoke()  ← two_factor_confirmed_at を触らない
public function __invoke($user, $force = false)
{
    if (empty($user->two_factor_secret) || $force === true) {
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->provider->generateSecretKey($secretLength)),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
        ])->save();
        TwoFactorAuthenticationEnabled::dispatch($user);
    }
}
```

### `php artisan route:list --json` の実測（母集団 11 本）

```
DELETE   | organizations.members.two-factor.reset      | organizations/{organization:slug}/members/{user}/two-factor
PATCH    | organizations.two-factor-requirement.update | organizations/{organization:slug}/two-factor-requirement
GET|HEAD | two-factor.login                            | two-factor-challenge
POST     | two-factor.login.store                      | two-factor-challenge
POST     | two-factor.confirm                          | user/confirmed-two-factor-authentication
POST     | two-factor.enable                           | user/two-factor-authentication
DELETE   | two-factor.disable                          | user/two-factor-authentication
GET|HEAD | two-factor.qr-code                          | user/two-factor-qr-code
GET|HEAD | two-factor.recovery-codes                   | user/two-factor-recovery-codes
POST     | two-factor.regenerate-recovery-codes        | user/two-factor-recovery-codes
GET|HEAD | two-factor.secret-key                       | user/two-factor-secret-key
```

### passkey route の実測

```
POST     | passkey.confirm             | passkeys/confirm
GET|HEAD | passkey.confirm-options     | passkeys/confirm/options
GET|HEAD | passkey.registration-options| user/passkeys/options
POST     | passkey.store               | user/passkeys
DELETE   | passkey.destroy             | user/passkeys/{passkey}
```

### RecentAuthWindow（鮮度ウィンドウ = config('auth.recent_auth_timeout') 既定 900 秒）

```php
public static function isFresh(mixed $recentAuthAt, ?int $timeoutSeconds = null): bool
{
    if (! is_int($recentAuthAt)) { return false; }
    $timeout = $timeoutSeconds ?? self::configuredTimeout();
    if ($timeout <= 0) { return false; }
    $elapsed = time() - $recentAuthAt;
    if ($elapsed < 0) { return false; }
    return $elapsed <= $timeout;
}
```
