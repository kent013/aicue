【アプリの使命 (North Star) — AGENTS.md より】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項 — AGENTS.md より】
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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件の追加文脈】
- 本件は家系の機能台帳 lctl の正典 feature `auth-enterprise-oidc` (canonical_version v1 / 裁定 AG-200) への追従設計である。正典テンプレート (laravel-claude-template) の実装を土台に aicue の作法へ写すことが求められており、独自方式は原則採らない。
- 概念設計は別セッションで 5 ラウンドのレビューを経て APPROVED 済みである。
- aicue には現在この機能の実装が 0 行である。
- 前段依存として kent013/laravel-ssrf-pin の ^0.4 化が別 TODO で先行する。

---

## 詳細設計書

# 詳細設計: enterprise-oidc-sso-adoption

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計は 5・6 に該当する変更を持たない（LLM 呼び出しを一切足さない）。
> 7 は該当あり — 企業ログインの**確定時のみ** `redirect()->intended()` を使う（ログイン直後フローなので許される）。
> 組織側の接続管理の操作系はすべて `back()->with(...)` で完結させる。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用済（個別 `DatabaseTransactions` 禁止）、`--parallel` 実行
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。本設計は新モデル 3 本 → **Factory 3 本を施策に含む**
- **DTO + JsonResource** パターン / **アーリーリターン**
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260823-0015-enterprise-oidc-sso-adoption/conceptual-design.md`（APPROVED / Round 5）

正典: 家系の機能台帳 lctl `auth-enterprise-oidc`
（`feature_revision: 23-30a9407c8f19` / `canonical_version: v1 (AG-200)`）

## 正典の不変条件（全列挙。すべて本設計が満たす）

| # | 不変条件 | 本設計での保証機構 |
|---|---|---|
| I1 | **メールアドレスで利用者を引かない**（引き当ての鍵は接続 × subject） | 施策 A2 の列設計 + 施策 F1 の gate G1 |
| I2 | **身元表の申告メールに索引を付けない**（暗号化はする） | 施策 A2 + G1 の索引 0 本検査 |
| I3 | **外部取得は必ず SsrfPin の窓口経由**（接続先情報 / 鍵 / トークン交換の 3 経路） | 施策 B1・B2 + gate G2 |
| I4 | **接続の秘密を扱う前面は登録・更新フォーム 1 本のみ** | 施策 D1・D2 + gate G3 |
| I5 | **受け渡しの型・例外に接続の秘密が存在しない**（例外は機械可読な理由文字列のみ） | 施策 A1・D2 + gate G3 |
| I6 | **共通ログイン経路に 2 要素認証を挟まない**（AG-200） | 施策 C2 + gate G4 + 実挙動テスト 2 本 |
| I7 | **初回ログインでその場で利用者を作る (always-JIT)** | 施策 C1 |
| I8 | **メール昇格フローは `App\Services\Auth` 名前空間へ置く**（正典の設計判断ごと引き継ぐ） | 施策 E1 + gate G5 |

### AGENTS.md セキュリティ不変条件の対応

| AGENTS.md | 本設計での対応 |
|---|---|
| 不変条件 1（tenant キー不信） | 接続の組織は URL から解決。payload から `organization_id` を受けない（`MassAssignmentSafetyTest` の母集団に入る） |
| 不変条件 2 / 10（子は親に属する = 認可より前に 404 / 層 2 は binding 直後） | `{organization:slug}` → `{connection}` を `scopeBindings` で解決。施策 D4 で `NestedRouteDefenseInventory` へ登録 |
| 不変条件 3（cross-org 不可） | 接続・身元はすべて組織スコープ解決。クラス起点の主キー同一性クエリを書かない |
| 不変条件 6（PII は CipherSweet） | 身元表の申告メールを暗号化。**blind index は付けない**（I2） |
| 不変条件 8（SSRF 窓口） | I3。境界は `config/ssrf-pin.php` の pin をそのまま使う（**本設計は同ファイルを変更しない**） |
| 不変条件 9（変更系 route は認可を通る） | 組織側 7 route はすべて `Gate::authorize`。ログイン導線 3 route は未認証面なので施策 F2 で exemption 登録 |
| 不変条件 11（キャッシュは素のデータだけ） | 本設計はキャッシュに複合オブジェクトを入れない（discovery / JWKS の短期保存は素の配列のみ、読み戻しは DTO へ組み立て直す） |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A1 | 設定ファイルと値域 enum | `config/enterprise-sso.php`, `app/Enums/EnterpriseSso/*` | High |
| A2 | モデル 3 本 + 移行 3 本 + Factory 3 本 | `app/Models/*`, `database/migrations/*`, `database/factories/*` | High |
| B1 | 接続先情報と鍵の取得（SsrfPin 経由） | `app/Services/EnterpriseSso/OidcDiscoveryService.php` ほか DTO | High |
| B2 | トークン交換（body 付き POST・SsrfPin 経由） | `app/Services/EnterpriseSso/OidcTokenExchanger.php` ほか DTO | High |
| B3 | ID トークンの検証 | `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php` ほか DTO | High |
| B4 | ログイン試行の保管（原子的 consume + ブラウザ結合） | `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` ほか | High |
| C1 | 利用者の自動作成 (always-JIT) | `app/Services/EnterpriseSso/EnterpriseUserProvisioner.php` | High |
| C2 | 戻り口の組み立てと controller・route 3 本 | `app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php`, `app/Http/Controllers/Auth/EnterpriseSsoLoginController.php`, `routes/web.php` | High |
| D1 | 接続の状態遷移サービス | `app/Services/EnterpriseSso/OidcConnectionTransitionService.php` | High |
| D2 | 組織側の接続管理 controller・route 7 本・画面 | `app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php`, `resources/js/pages/...`, `routes/web.php` | High |
| E1 | メールアドレスの昇格フロー（**Auth 名前空間**） | `app/Services/Auth/EmailPromotionService.php` ほか | Medium |
| F1 | gate 5 本（G1〜G5） | `tests/Architecture/*`, `tests/Support/*` | High |
| F2 | aicue 側の目録登録 | `app/Enums/Security/*`, `tests/Support/*`, `tests/Architecture/*` | High |
| F3 | 逸脱の登録 D37 + 台帳件数の pin | `docs/template-divergence.md`, `tests/Support/TemplateDivergence/LedgerPins.php` | High |
| F4 | 試験用の偽 IdP と外部到達点の登録 | `app/Support/ExternalFakes/ExternalFakeDeclaration.php`, `app/Services/EnterpriseSso/Fakes/*`, `tests/Support/ExternalSeam/ExternalSeamInventory.php` | High |

---

## A1: 設定ファイルと値域 enum

### 変更箇所
- 新規: `config/enterprise-sso.php`
- 新規: `app/Enums/EnterpriseSso/OidcConnectionStatus.php`
- 新規: `app/Enums/EnterpriseSso/OidcSigningAlgorithm.php`

### 波及変更
- TypeScript型定義: **あり** — 接続の状態の値域を画面へ写す。
  正典は「画面直書きから TypeScript の定数へ切り出し、読み取り検査 1 本を `enum-ts-sync-gate` 側へ移した」形なので、
  aicue も **TS 側の定数**（`resources/js/components/features/sso/oidc-connection.ts`）を作り、
  既存の enum ↔ TS 同期 gate の母集団へ載せる（施策 F2）
- API Resource/DTO: なし（本施策は値域のみ）
- テストファイル: `tests/Architecture/ConfigHardeningTest` 系の pin 対象に**しない**
  （本設定は安全境界の pin ではないため。SSRF の境界は `config/ssrf-pin.php` が持ち、**本設計は同ファイルを変更しない**）

### 変更後コード（抜粋）

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| エンタープライズ OIDC SSO
|--------------------------------------------------------------------------
| ★外部 URL の安全境界は **ここに書かない**。SSRF の境界の正本は
|   config/ssrf-pin.php であり、本設計はそれを変更しない (同じ事実を 2 か所に置かない)。
*/

return [
    // 取得の時間予算 (秒)。接続 <= リクエスト全体。
    'discovery' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 5,
        // discovery 文書と JWKS の短期保存 (素の配列のみ。読み戻しは DTO へ組み立て直す)
        'cache_ttl_seconds' => 300,
        // 未知 kid での鍵の再取得の最小間隔 (増幅を防ぐ)
        'jwks_refetch_min_interval_seconds' => 60,
        'max_body_bytes' => 262144,
    ],

    'token' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 8,
        'max_body_bytes' => 65536,
    ],

    'id_token' => [
        // 許容する時刻ずれ。**顧客の入力では広げられない** (設定値のみ)。
        'leeway_seconds' => 60,
        // sub の許容長 (身元の主キー)
        'max_subject_length' => 255,
    ],

    'login_attempt' => [
        'ttl_seconds' => 600,
    ],
];
```

```php
enum OidcConnectionStatus: string
{
    case Draft = 'draft';       // 登録直後。ログインには使えない
    case Verified = 'verified'; // 接続先情報の取得に成功した。まだ使えない
    case Active = 'active';     // ログインに使える
    case Disabled = 'disabled'; // 運営が止めた
}
```

```php
/** ID トークンの署名方式の許可集合。`none` と対称鍵 (HMAC) は case に持たない。 */
enum OidcSigningAlgorithm: string
{
    case Rs256 = 'RS256';
    case Rs384 = 'RS384';
    case Rs512 = 'RS512';
    case Es256 = 'ES256';
    case Es384 = 'ES384';
}
```

> **`none` と HMAC を「拒否リスト」でなく「enum に持たない」形にする理由**:
> 許可集合を型で表せば、拒否漏れという失敗様式そのものが消える。
> 文字列の比較で弾く形は、比較の書き忘れ 1 つで通る。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（config は `array` のまま外へ出さず、読み出しは `Config::integer()` を使う）
- [x] null安全（`Config::integer()` は型を確定させる。準拠実装 `SnsCertificateFetcher`）
- [x] DTOを返している（本施策は値域のみ）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoConfigTest.php` — 時間の大小関係（接続 <= リクエスト全体）が成り立つ
- [ ] 新規 `tests/Unit/Enums/OidcSigningAlgorithmTest.php` — `none` と `HS256` が `tryFrom()` で null になる（負のコントロール）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 設定値を増やしすぎると「使われない旋回つまみ」になる。上記 12 項目はすべて本文中の判定で参照される。参照されない項目を足さない。

---

## A2: モデル 3 本 + 移行 3 本 + Factory 3 本

### 変更箇所
- 新規: `app/Models/OrganizationOidcConnection.php` / `EnterpriseIdentity.php` / `EnterpriseSsoLoginAttempt.php`
- 新規: `database/migrations/2026_08_23_000100_create_organization_oidc_connections_table.php`
- 新規: `database/migrations/2026_08_23_000200_create_enterprise_identities_table.php`
- 新規: `database/migrations/2026_08_23_000300_create_enterprise_sso_login_attempts_table.php`
- 新規: `database/factories/OrganizationOidcConnectionFactory.php` / `EnterpriseIdentityFactory.php` / `EnterpriseSsoLoginAttemptFactory.php`
- 新規: `app/Casts/EncryptedSecretStringCast.php`（伏字の値型。aigenba の上積みと同型）

### 波及変更
- TypeScript型定義: なし（画面へ出すのは D2 の DTO 経由）
- API Resource/DTO: D2 で作る接続の一覧・詳細 DTO が本モデルを入力にする
- テストファイル: `MassAssignmentSafetyTest` の母集団に 3 モデルが入る（`$fillable` の設計が検査される）

### 変更後コード（列設計の要点）

```php
// 2026_08_23_000200_create_enterprise_identities_table.php
Schema::create('enterprise_identities', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // IdP の subject。**これが身元の主キーである**。
    $table->string('subject');

    // ★申告メール: 暗号化して持つが **索引を意図的に付けない**。
    //   索引を付けると「メールで引ける」経路が実装として復活し、正典 v1 の
    //   不変条件 I1/I2 が崩れる。blind index も付けない (CipherSweet の
    //   blind_indexes 表にも入れない = configureCipherSweet で addBlindIndex を呼ばない)。
    $table->text('claimed_email_encrypted')->nullable();

    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();

    // 並行初回ログインでの二重作成を DB で止める (C1 の競合対策の本体)。
    $table->unique(['organization_oidc_connection_id', 'subject']);
});
```

```php
// 2026_08_23_000300_create_enterprise_sso_login_attempts_table.php
Schema::create('enterprise_sso_login_attempts', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();

    // state の **指紋だけ**を持つ (原文を保存しない)。一意制約が使用権の唯一性の根拠。
    $table->string('state_fingerprint', 64)->unique();

    // nonce も **指紋だけ**。ID トークンの nonce を同じ関数へ通して定時間比較すれば足りる。
    $table->string('nonce_fingerprint', 64);

    // 開始したブラウザとの結合 (login CSRF を塞ぐ本体)。
    // セッションへ置いた「結び付けの秘密」の HMAC 指紋。session ID は保存しない。
    $table->string('browser_binding_fingerprint', 64);

    // PKCE の検証子だけは token 交換でそのまま送るので原文が要る → 暗号化して保存。
    $table->text('pkce_verifier_encrypted');

    $table->timestamp('expires_at');
    $table->timestamps();

    // 期限切れ掃除の走査用。
    $table->index('expires_at');
});
```

```php
// app/Models/EnterpriseIdentity.php (要点)
final class EnterpriseIdentity extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<EnterpriseIdentityFactory> */
    use HasFactory, UsesCipherSweet;

    /**
     * ★**メールアドレスで利用者を引かない** (正典 v1 / 不変条件 I1)。
     *   引き当ての鍵は (organization_oidc_connection_id, subject) だけである。
     *   申告メールは暗号化して持つが **blind index を付けない** —
     *   索引があると「メールで引ける」経路が復活する。
     *   これは tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest が
     *   記法の走査と **索引 0 本のスキーマ検査** の二層で固定する。
     */
    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        // addBlindIndex を **呼ばない**。これが不変条件の実体である。
        $encryptedRow->addField('claimed_email_encrypted');
    }

    /** @var list<string> */
    protected $fillable = [];  // 生成は Provisioner が明示的に組み立てる (mass assignment を作らない)
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`claimed_email_encrypted` は nullable。読み出しは明示的に分岐）
- [x] DTOを返している（モデルは境界の外へ出さない。D2 で DTO へ畳む）
- [x] Genericsの型パラメータが正しい（`@use HasFactory<XxxFactory>` を 3 モデルとも書く）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityIsolationTest.php` — 身元表の索引が
      `(organization_oidc_connection_id, subject)` の一意制約と外部キーの分だけで、
      **申告メールを含む索引が 0 本**である（スキーマの読み取りのみ。`migrate:fresh` を呼ばない = 禁止事項 3）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityCipherSweetTest.php` — 申告メールが平文で保存されない
- [ ] Factory 3 本が `RefreshDatabase` 下で動く（既存の Factory 規約に従う）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **移行 3 本目（試行表）はテンプレートに無い上積み**である → 施策 F3 で逸脱 D37 として登録する。
- `cascadeOnDelete` により組織削除で接続・身元が消える。**利用者は消えない**（企業 SSO 以外のログイン手段が残る場合がある）。この非対称は意図であり、E1 のメール昇格が「離脱後も自分のアカウントを保てる」ための前提になる。

---

## B1: 接続先情報と鍵の取得（SsrfPin 経由）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcDiscoveryService.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/OidcProviderMetadata.php` / `OidcJsonWebKeySet.php`

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: 上記 2 DTO を新設
- テストファイル: gate G2（施策 F1）の母集団に本ファイルが入る

### 変更後コード（要点）

```php
final readonly class OidcDiscoveryService
{
    public function __construct(
        private HttpFactory $http,
        private UrlSafetyInspector $inspector,
    ) {}

    /**
     * discovery 文書の取得と検証。
     *
     * 防御:
     *  1. **SSRF 検査** — UrlSafetyInspector (AGENTS.md 不変条件 8)。境界の正本は config/ssrf-pin.php
     *  2. **リダイレクトを追従しない** — 追従すると転送先が未検査のまま取得される。
     *     2xx 以外は一様に拒否する (`->throw()` は 3xx を通すので使わない)
     *  3. **issuer の完全一致** — 文書の issuer が登録済み issuer と一致すること
     *  4. **endpoint の同一 origin** — authorization / token / jwks_uri が issuer と同一 origin
     *  5. **応答サイズ上限** — 期待と違う応答を DTO に固定しない
     *
     * 保証しないもの: **DNS rebinding は解消しない** (検査時と接続時で名前解決が変わる
     * TOCTOU は残る)。準拠実装 SnsCertificateFetcher と同じ限界である。
     *
     * @throws EnterpriseSsoAttemptRejectedException 機械可読な理由文字列のみを持つ
     */
    public function fetchMetadata(OrganizationOidcConnection $connection): OidcProviderMetadata
    {
        $issuer = $connection->issuer;
        $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

        $this->inspect($url);

        try {
            $response = $this->http
                ->connectTimeout(Config::integer('enterprise-sso.discovery.connect_timeout_seconds'))
                ->timeout(Config::integer('enterprise-sso.discovery.request_timeout_seconds'))
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException $e) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_unreachable', 0, $e);
        }

        if (! $response->successful()) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_not_successful');
        }

        return OidcProviderMetadata::fromResponseBody($response->body(), expectedIssuer: $issuer);
    }
}
```

```php
final readonly class OidcProviderMetadata
{
    private function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
    ) {}

    /**
     * ★**未知の要素を array<string, mixed> のまま内側へ出さない**。
     *   必要な 4 要素だけを「存在」と「具体型」を検査してから組み立てる。
     */
    public static function fromResponseBody(string $body, string $expectedIssuer): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_not_json', 0, $e);
        }

        if (! is_array($decoded)) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_not_object');
        }

        $issuer = self::requireString($decoded, 'issuer');
        if (! hash_equals($expectedIssuer, $issuer)) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_issuer_mismatch');
        }

        $authorization = self::requireHttpsUrl($decoded, 'authorization_endpoint');
        $token = self::requireHttpsUrl($decoded, 'token_endpoint');
        $jwks = self::requireHttpsUrl($decoded, 'jwks_uri');

        foreach ([$authorization, $token, $jwks] as $endpoint) {
            if (! self::isSameOrigin($issuer, $endpoint)) {
                throw new EnterpriseSsoAttemptRejectedException('discovery_endpoint_foreign_origin');
            }
        }

        return new self($issuer, $authorization, $token, $jwks);
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`requireString` / `requireHttpsUrl` が存在と型を確定させる。`Webmozart\Assert\Assert` も可）
- [x] DTOを返している（配列返却なし）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php`
  - issuer 不一致を拒否する
  - endpoint が別 origin なら拒否する
  - 3xx 応答を**成功として扱わない**
  - サイズ上限超過を拒否する
  - JSON でない応答を拒否する
  - SSRF 検査で拒否された URL を取りに行かない
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **DNS rebinding は解消しない**（docblock に明記する。誇張しない）。
- discovery の短期保存は素の配列のみに限る（不変条件 11）。読み戻しは DTO へ組み立て直し、失敗したら `forget` する。

---

## B2: トークン交換（body 付き POST・SsrfPin 経由）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcTokenExchanger.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/OidcTokenResponse.php`

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: 上記 1 DTO / テストファイル: gate G2 の母集団

### 変更後コード（要点）

```php
/**
 * 認可コードとトークンの交換。
 *
 * ★**本サービスは kent013/laravel-ssrf-pin ^0.4 の「要求 body を運べる取得」を必要とする**。
 *   v0.2 系では実装そのものが成立しない (正典が明記)。前段 TODO ssrf-pin-v04-upgrade が先行する。
 *
 * 防御: SSRF 検査 / リダイレクト追従なし / 2xx 以外は拒否 / 応答サイズ上限 /
 *       client secret は**要求にだけ**載せ、応答・ログ・例外に出さない。
 */
public function exchange(
    OrganizationOidcConnection $connection,
    OidcProviderMetadata $metadata,
    string $code,
    string $codeVerifier,
): OidcTokenResponse {
    $this->inspect($metadata->tokenEndpoint);

    $response = $this->pinnedPost($metadata->tokenEndpoint, [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => route('enterprise-sso.callback'),
        'client_id' => $connection->client_id,
        'client_secret' => $connection->client_secret,  // ★要求にだけ載る
        'code_verifier' => $codeVerifier,               // ★PKCE の往復の片端
    ]);

    return OidcTokenResponse::fromResponseBody($response);
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている / null安全 / DTOを返している / Generics 正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcTokenExchangerTest.php`
  - `code_verifier` が要求に載る（PKCE の往復の片端）
  - 3xx を成功として扱わない
  - **例外文言・ログに client secret / 認可コード / トークンが出ない**（G3 の実挙動側の裏取り）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 前段の版上げが未了だと本施策は着手できない（依存を TODO に明記する）。

---

## B3: ID トークンの検証

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/VerifiedIdTokenClaims.php`

### 変更後コード（検証項目。概念設計の表をそのまま実装する）

```php
/**
 * ID トークンの検証。**ここを通ったものだけが身元として扱われる**。
 *
 * 検証項目 (すべて必須。1 つでも欠けたらその試行を拒否する):
 *  - 署名: JWKS の kid で鍵を選ぶ。**未知の kid は鍵の再取得を 1 回だけ**行う
 *          (ローテーション追従。再取得には最小間隔を設けて増幅を防ぐ)
 *  - alg:  OidcSigningAlgorithm の case のみ (`none` と対称鍵は enum に持たない)。
 *          鍵種別と alg の整合も確認する
 *  - iss:  登録済み issuer と完全一致
 *  - aud:  **文字列または文字列配列**の両形を受け、自分の client_id を含むこと
 *  - azp:  audience が複数、または azp が存在する場合、azp = 自分の client_id
 *  - sub:  存在し、非空で、max_subject_length 以内 (身元の主キーそのもの)
 *  - exp / iat / nbf: leeway_seconds の範囲。**顧客の入力では広げられない**
 *  - nonce: 保管した試行の指紋と **hash_equals による定時間比較**
 *
 * ★**復号器・検証器の戻り値も信頼済みの型と見なさない**。具体型を再検査してから DTO を組み立てる。
 */
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`VerifiedIdTokenClaims`）
- [x] null安全（claim ごとに存在と型を検査してから構築）
- [x] DTOを返している
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdTokenVerifierTest.php` — 検証項目 8 種それぞれの負例で拒否されること:
      `alg: none` / HMAC 署名 / `iss` 不一致 / `aud` に自分がいない / 複数 audience で `azp` 不一致 /
      `sub` 欠落・空文字 / `exp` 超過 / `nonce` 不一致
- [ ] 鍵ローテーション: 未知 `kid` で**再取得が 1 回だけ**起き、最小間隔の内側では再取得しない
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 時刻ずれの許容を広げたくなる圧力が運用で生じる。設定値の上限として持ち、**接続の登録項目にはしない**（顧客が広げられない）。

---

## B4: ログイン試行の保管（原子的 consume + ブラウザ結合）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php`
- 新規: `app/Console/Commands/EnterpriseSso/PruneLoginAttempts.php`
- 変更: `routes/console.php`（日次の掃除を登録。準拠実装 `idempotency:prune`）

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: `ConsumedLoginAttempt`
- テストファイル: `tests/Architecture/StuckWorkRecoveryInventoryTest` 等の掃除系目録があれば登録を確認する

### 変更後コード（要点）

```php
/**
 * ログイン試行の保管。
 *
 * ## 不変条件 (これが正本。「1 文で書く」は手段であって不変条件ではない)
 *
 *   **同じ試行の使用権を、ちょうど 1 つの要求だけが得る。**
 *   **かつ、その試行を開始したブラウザだけが使える。**
 *
 * ## なぜセッションに置かないか
 *
 * 同一セッションへの並行要求は route 側で `->block()` を書かない限り直列化が
 * 保証されず、「普通の get() + forget() を書いても契約を満たしたと誤認できる」形になる。
 * DB の一意制約と行ロックは**ドライバの種別にも書き忘れにも依存しない**。
 *
 * ## なぜブラウザ結合が要るか (login CSRF)
 *
 * state の役割は「推測不能であること」だけではない。**その認可要求を開始した
 * ユーザーエージェントに結び付いていること**が要る。グローバルな表だけを根拠にすると、
 * 攻撃者が自分のブラウザで開始し自分の IdP アカウントで認可した callback URL を
 * 被害者に開かせることで、**被害者のブラウザが攻撃者のアカウントへログインする**。
 *
 * ## 保存の形
 *
 * | 項目 | 形 |
 * |---|---|
 * | state | 指紋だけ (原文を保存しない) |
 * | nonce | 指紋だけ |
 * | ブラウザ結合 | セッションへ置いた秘密の HMAC 指紋 (session ID は保存しない) |
 * | PKCE の検証子 | 交換でそのまま送るので原文が要る → 暗号化して保存 |
 *
 * 指紋は**用途別の識別子を入力へ付けて導出する** (state / nonce / ブラウザ結合を
 * 相互に使い回せない形にする)。鍵は用途を明示したサーバー側の秘密から導出する。
 *
 * ## 保証しないもの
 *
 * - セッション cookie ごと奪われた場合のブラウザ結合は破れる (結合はセッションの秘密に依存する)
 * - 期限切れ行の掃除は日次の実行点とオンアクセスの二段であり、**即時削除ではない**
 */
final readonly class EnterpriseLoginAttemptStore
{
    /**
     * 使用権を取得する。取得できた要求だけが値を読み出せる。
     *
     * 手順 (トランザクション内):
     *   1. state の指紋で SELECT ... FOR UPDATE
     *   2. 期限とブラウザ結合を検査する
     *   3. DELETE (影響行数がちょうど 1)
     *   4. commit
     *
     * @throws EnterpriseSsoAttemptRejectedException 一様な理由文字列 (行の存在も理由も漏らさない)
     */
    public function consume(string $state, string $browserBindingSecret): ConsumedLoginAttempt
    {
        return DB::transaction(function () use ($state, $browserBindingSecret): ConsumedLoginAttempt {
            $row = EnterpriseSsoLoginAttempt::query()
                ->where('state_fingerprint', $this->fingerprint('state', $state))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new EnterpriseSsoAttemptRejectedException('attempt_not_found');
            }

            if ($row->expires_at->isPast()) {
                $row->delete();
                throw new EnterpriseSsoAttemptRejectedException('attempt_expired');
            }

            if (! hash_equals($row->browser_binding_fingerprint, $this->fingerprint('binding', $browserBindingSecret))) {
                // ★行を消さない (攻撃者が被害者の試行を消せる形にしない)。
                throw new EnterpriseSsoAttemptRejectedException('attempt_binding_mismatch');
            }

            if ($row->delete() !== true) {
                throw new EnterpriseSsoAttemptRejectedException('attempt_consume_failed');
            }

            // 行をそのまま外へ出さない。具体型・期限・復号結果を検査して DTO へ畳む。
            return ConsumedLoginAttempt::fromModel($row);
        });
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`first()` の null を早期に落とす。アーリーリターン）
- [x] DTOを返している（`ConsumedLoginAttempt`。Eloquent モデルを外へ出さない）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php`
  - **1 本目が使用権を得る前に 2 本目を開始し、片方だけが成功する**（単に順へ 2 回送るだけの検査にしない）
  - **別のブラウザで callback URL を開くと失敗する**（login CSRF。結合不一致で拒否）
  - 結合不一致では**行が消えない**（他人の試行を消せない）
  - 期限切れの `state` が拒否される
  - 指紋が用途別に導出され、`state` の指紋を結合の指紋として使えない
- [ ] 新規 `tests/Feature/EnterpriseSso/PruneLoginAttemptsTest.php` —
      期限切れ行だけが消え、**進行中の通常の試行を巻き込まない**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 行ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
  **consume はトランザクションを閉じてから外向き取得を始める**（C2 の並び順で保証する）。

---

## C1: 利用者の自動作成 (always-JIT)

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseUserProvisioner.php`

### 変更後コード（要点）

```php
/**
 * 初回ログインでの利用者の自動作成 (always-JIT)。
 *
 * ★**メールアドレスで利用者を引かない** (正典 v1 / 不変条件 I1)。
 *   引き当ての鍵は (接続 id, IdP の subject) だけである。
 *   申告メールは EnterpriseIdentity に暗号化して持つが、**引き当てには使わない**。
 *   これにより「登録済みメールかどうかが応答から読み取れる」経路と
 *   「他所で確認済みと主張されたメールで乗っ取る」経路の両方が消える。
 *
 * ## 並行初回ログインの競合
 *
 * 事前検索だけでは、同一 (接続 id, subject) の並行 callback で
 * 利用者・身元・所属が二重に作られる。したがって:
 *  - 身元表の (organization_oidc_connection_id, subject) の DB 一意制約を根拠にする
 *  - 利用者の作成・身元の作成・組織所属の作成を **1 トランザクション**に束ねる
 *  - 一意制約違反は**トランザクション全体をロールバック**してから引き当てへ倒す
 *    (例外を握るだけでは、先に作った利用者が**孤児として残る**)
 */
public function resolve(OrganizationOidcConnection $connection, VerifiedIdTokenClaims $claims): User
{
    $existing = $this->findIdentity($connection, $claims->subject);
    if ($existing !== null) {
        return $existing->user;  // アーリーリターン
    }

    try {
        return DB::transaction(fn (): User => $this->createUserWithIdentityAndMembership($connection, $claims));
    } catch (UniqueConstraintViolationException) {
        // 並行して別の要求が作り終えた。トランザクションはロールバック済み (孤児は残らない)。
        $identity = $this->findIdentity($connection, $claims->subject);
        if ($identity === null) {
            throw new EnterpriseSsoAttemptRejectedException('provision_conflict_unresolved');
        }

        return $identity->user;
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている / null安全（`findIdentity` の null を早期に処理）
- [x] DTOを返している（`User` モデルは認証境界の値。画面へは D2/C2 の DTO 経由で出す）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseUserProvisionerTest.php`
  - 初回で利用者・身元・所属が 1 件ずつできる
  - **同じ申告メールを持つ別 subject が別の利用者になる**（メールで引かないことの実挙動側の裏取り）
  - **並行初回ログインでも 1 利用者・1 身元・1 所属だけが成立する**
  - 競合で失敗した側に**孤児の利用者が残らない**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- always-JIT は「接続が有効な組織の IdP が subject を出せば誰でも入れる」ことを意味する。
  絞りは**接続の有効・無効**（D1）だけである。これは正典の形であり、追加の絞りを足さない。

---

## C2: 戻り口の組み立てと controller・route 3 本

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php`
- 新規: `app/Http/Controllers/Auth/EnterpriseSsoLoginController.php`
- 変更: `routes/web.php`（SSO (Socialite) の節の直後に企業 SSO の節を足す）

### 波及変更
- TypeScript型定義: ログイン画面へ「企業ログイン」の導線を足す（`resources/js/pages/Auth/Login.svelte` 相当）
- API Resource/DTO: なし（Inertia のリダイレクトのみ）
- テストファイル: `ThrottleCoverageInventoryTest` / `ControllerAuthorizationGateTest` /
  `NestedRouteDefenseInventory` / `RecentAuthRouteTest` / `TwoFactorEnforcementAllowlistTest` の各目録（施策 F2）

### 変更後コード（route）

```php
/*
|--------------------------------------------------------------------------
| エンタープライズ OIDC SSO (組織 OIDC 接続 + always-JIT)
|--------------------------------------------------------------------------
| 開始導線は GET の anchor リンク (form POST にしない。CSP form-action が
| リダイレクト先 IdP に適用されるため。social.redirect と同じ理由)。
|
| ★**この経路にアプリ側の 2 要素認証を挟まない** (家系の裁定 AG-200)。
|   確認できた時点でログインを確定させる。個人の 2 要素の入力はパスワードログインの
|   経路に限り、組織義務づけの強制は別関門 (RequireTwoFactorForEnforcedOrganizations)
|   が**ログイン確定後**にアカウント全体のゲートとして担う。
|   ここで独自の入力経路を作ると経路ごとに扱いが分かれる。
*/
// 組織の指定 (どの接続で入るかを選ぶ画面)。外向き通信をしないため throttle は
// exemption (AuthFlowInitiationWithoutOutboundCall) として理由を inventory に残す。
Route::get('/enterprise/login', [EnterpriseSsoLoginController::class, 'show'])
    ->name('enterprise-sso.login');

// 開始。接続の解決に DB を引くため named limiter で IP レーンを明示する。
Route::get('/enterprise/{connection:slug}/redirect', [EnterpriseSsoLoginController::class, 'redirect'])
    ->middleware('throttle:enterprise-sso-start')
    ->name('enterprise-sso.redirect');

// 戻り口。**未認証で外部へ HTTP を発射する経路**である (token 交換 + JWKS)。
// social.callback と同じ理由で named limiter を貼る。
Route::get('/enterprise/callback', [EnterpriseSsoLoginController::class, 'callback'])
    ->middleware('throttle:enterprise-sso-callback')
    ->name('enterprise-sso.callback');
```

### 変更後コード（戻り口。AG-200 の要）

```php
/**
 * 企業 SSO の戻り口。
 *
 * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点で Auth::login() で
 *   ログインを確定させ、画面へ送る。2 要素認証の入力画面へ転送する分岐を**持たない**。
 *   これは tests/Architecture/SsoTwoFactorInterpositionGateTest が
 *   企業・ソーシャルの両 controller に対して静的に裏当てし、
 *   主たる証明は tests/Feature/Auth/EnterpriseSsoLoginTest の実挙動側にある。
 *
 * 順序の理由:
 *   consume (行ロック) → **トランザクションを閉じてから** 外向き取得 → 検証 → JIT → 確定。
 *   行ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
 */
public function callback(Request $request, EnterpriseCallbackAuthenticator $authenticator): RedirectResponse
{
    $user = $authenticator->authenticate($request);   // ここまでで失敗はすべて一様に例外

    Auth::login($user, remember: true);
    $request->session()->regenerate();                 // ★照合と consume を終えた後に行う

    return redirect()->intended(route('dashboard'));
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`Assert::isInstanceOf` で `User` を確定させる。既存 `SocialAuthController` と同形）
- [x] DTOを返している（`response()->json()` を書かない）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EnterpriseSsoLoginTest.php`
  - **2 要素認証が有効な利用者も、企業ログインでそのままログインが確定する**（AG-200 の主証明①）
  - **組織義務づけの下でも、企業ログイン後に 2 要素の設定ページへ到達できる**（AG-200 の主証明②）
  - 無効・下書きの接続ではログインできない
  - 失敗の応答が**一様**で、接続や利用者の存在を読み取れない
  - `session()->regenerate()` が確定後に走る（セッション固定化）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseOidcRouteRoundTripTest.php` —
      偽の IdP（施策 F4）で route の往復を通しで検査する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **禁止事項 7**（操作系 POST で `redirect()->intended()`）に見えるが、本経路は**ログイン直後フロー**であり
  同項の明示的な適用範囲内である（既存 `SocialAuthController` と同じ形）。

---

## D1: 接続の状態遷移サービス

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcConnectionTransitionService.php`

### 変更後コード（要点）

```php
/**
 * 接続の状態遷移。
 *
 * 許す遷移 (これ以外は例外):
 *   Draft    → Verified  (接続先情報の取得に成功した)
 *   Verified → Active    (運営が有効にした)
 *   Active   → Disabled  (運営が止めた)
 *   Disabled → Active    (運営が戻した)
 *
 * ★**取得の失敗を理由に接続を自動で無効化しない**。IdP の 5xx・鍵ローテーションの途中・
 *   DNS の一時障害で接続を殺すのは可用性の後退である。失敗はすべて
 *   「そのログイン試行だけを fail-closed で拒否する」に留め、
 *   接続の状態を変えるのは**本サービスを通した運営操作だけ**である。
 */
```

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcConnectionTransitionServiceTest.php` — 定義外の遷移が例外になる
- [ ] **discovery の失敗で接続の状態が変わらない**（可用性の後退がないことの証明）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 状態が増えると画面の分岐が増える。4 状態で固定し、追加しない。

---

## D2: 組織側の接続管理 controller・route 7 本・画面

### 変更箇所
- 新規: `app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php`
- 新規: `app/Http/Requests/Organizations/StoreSsoConnectionRequest.php` / `UpdateSsoConnectionRequest.php`
- 新規: `app/DataTransferObjects/Organizations/SsoConnectionSummary.php`
- 新規: `app/Policies/OrganizationOidcConnectionPolicy.php`
- 新規: `resources/js/pages/Organizations/Sso/Index.svelte`
- 新規: `resources/js/components/features/sso/oidc-connection.ts`（状態の値域の TS 定数）
- 変更: `routes/web.php`

### 波及変更
- **TypeScript型定義: あり** — Inertia Props の型（接続の一覧）と状態の値域の定数
- **API Resource/DTO: あり** — `SsoConnectionSummary`（**秘密を持たない**）
- **テストファイル: あり** — `ControllerAuthorizationGateTest` / `RecentAuthRouteTest` /
  `ThrottleCoverageInventoryTest` / `NestedRouteDefenseInventory` / `TwoFactorEnforcementAllowlistTest`（登録しない判断の確認）/ `InertiaRenderPageExistsInvariantTest` / `DocumentTitleCoverageTest` / `ValidationAttributeCoverageTest`

### 変更後コード（route。**更新系はすべて再認証必須**）

```php
// 組織側の接続管理。{organization:slug} は MembershipScopedOrganizationBinder が
// membership スコープで解決する (非メンバー・不在は等しく 404 = テナント存在秘匿)。
// {connection} は scopeBindings で親 (organization) に属することを binding 段で閉じる
// (不変条件 2: 子は親に属する = **認可より前に 404**)。
Route::get('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'index'])
    ->name('organizations.sso.index');

Route::scopeBindings()->group(function (): void {
    // 登録・更新は**接続の秘密を扱う唯一の前面**である (正典 v1 / 不変条件 I4)。
    Route::post('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.store');

    Route::patch('/organizations/{organization:slug}/sso/{connection}', [OrganizationSsoConnectionController::class, 'update'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.update');

    // 確認 (接続先情報を実際に取りに行く)。**外向きの取得を伴う唯一の管理操作**なので
    // 専用の流量制限を持つ (他の管理操作と bucket を共有しない)。
    Route::post('/organizations/{organization:slug}/sso/{connection}/verify', [OrganizationSsoConnectionController::class, 'verify'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-verify'])
        ->name('organizations.sso.verify');

    Route::post('/organizations/{organization:slug}/sso/{connection}/activate', [OrganizationSsoConnectionController::class, 'activate'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.activate');

    Route::post('/organizations/{organization:slug}/sso/{connection}/disable', [OrganizationSsoConnectionController::class, 'disable'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.disable');

    Route::delete('/organizations/{organization:slug}/sso/{connection}', [OrganizationSsoConnectionController::class, 'destroy'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.destroy');
});
```

### 変更後コード（DTO。**秘密を持たない**）

```php
/**
 * 画面へ返す接続の要約。
 *
 * ★**接続の秘密 (client secret) を持たない**。前面で秘密を扱ってよいのは
 *   登録・更新フォーム 1 本だけであり、そこでも「入力する」だけで「読み戻さない」。
 *   再表示は伏字 ({@see EncryptedSecretStringCast}) に限る。
 *   これは tests/Architecture/EnterpriseSsoSecretExposureGateTest が
 *   受け渡しの型に秘密の項目が存在しないことで固定する。
 */
final readonly class SsoConnectionSummary
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $issuer,
        public string $clientId,
        public OidcConnectionStatus $status,
        public string $clientSecretMasked,   // ★伏字のみ
        public ?CarbonImmutable $verifiedAt,
    ) {}
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`verifiedAt` は nullable を型で明示）
- [x] DTOを返している（`response()->json()` なし。Inertia へ DTO を渡す）
- [x] Genericsの型パラメータが正しい（一覧は `list<SsoConnectionSummary>`）

### テスト計画
- [ ] 新規 `tests/Feature/Organizations/OrganizationSsoConnectionTest.php`
  - **他組織の接続 id を URL に入れると 403 ではなく 404**（不変条件 2 / 存在オラクル）
  - 権限のないメンバーは 403（`Gate::authorize`）
  - **更新系はすべて再認証がないと弾かれる**（7 route のうち更新系 6 本）
  - **応答・Inertia props に client secret の原文が出ない**（伏字のみ）
  - 確認 (`verify`) が専用の流量制限を持ち、他の管理操作と bucket を共有しない
- [ ] 新規 `tests/js/.../oidc-connection.test.ts` — 状態の値域の TS 定数が PHP enum と一致する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **7 route を一度に足す**ので目録の登録漏れが最大の赤要因。施策 F2 で 6 目録を明示的に潰す。
- 画面は 1 枚に収める（一覧 + 登録・更新フォーム）。秘密を扱う前面を 2 枚に割らない（I4）。

---

## E1: メールアドレスの昇格フロー（Auth 名前空間）

### 変更箇所
- 新規: `app/Services/Auth/EmailPromotionService.php`（**`App\Services\EnterpriseSso` ではない**）
- 新規: `app/Models/EmailPromotion.php` + 移行 1 本 + Factory 1 本
- 新規: `app/Http/Controllers/Auth/EmailPromotionController.php`
- 新規: `app/Mail/EmailPromotionMail.php`
- 新規: `app/Exceptions/Auth/EmailPromotionConflictException.php`
- 新規: `app/DataTransferObjects/Auth/VerifiedEmail.php`

### 波及変更
- TypeScript型定義: 設定画面に昇格の導線を足す
- API Resource/DTO: `VerifiedEmail`
- テストファイル: メール送信の既存の作法（送信基盤・目録・流量制限）への登録確認

### 設計の要点

```php
/**
 * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
 *
 * ## なぜ EnterpriseSso ではなく Auth の名前空間に置くか
 *
 * 正典 (laravel-claude-template) の設計判断をそのまま引き継ぐ。
 * 「メールでの引き当てを禁じる設計検査の走査範囲へ入れないための意図的な配置」である。
 *
 * ★**これは検査の回避ではない**。昇格フローも**メールで利用者を引かない** —
 *   引き当ての鍵は常に Auth::id() (自分自身) であり、メール文字列は
 *   「その利用者に紐づける値」としてしか現れない。走査から外すのは、
 *   **メール文字列を正当に扱う唯一の場所**を禁止語の走査へ巻き込まないためであって、
 *   引き当ての禁止を緩めるためではない。この主張は
 *   tests/Architecture/EmailPromotionIdentityGateTest (G5) が
 *   「メールから利用者を引く記法を持たない」「既存アカウントとの併合をしない」の
 *   2 点で固定する。
 *
 * ## 昇格の条件 (3 点だけ)
 *
 *  - **本人確認**: 確認メールのトークンを踏んだときにだけ確定する
 *    (IdP の申告メールをそのまま昇格させない = nOAuth の再現を防ぐ)
 *  - **認可**: 対象は認証済みの自分自身のみ
 *  - **監査**: 変更を記録する (既存の監査基盤へ載せる)
 *
 * ## 衝突 (確認済みメールが既存利用者のメールと重なる) の扱い
 *
 * **既存利用者を一切変更せず・併合せず・昇格も行わない**。
 * 外部へ返す応答は**一様**にして、衝突したという事実からメールの存在を読み取れないようにする。
 * users.email_index の partial unique 制約と確認完了時のトランザクションで固定する。
 */
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている / null安全 / DTOを返している / Generics 正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EmailPromotionTest.php`
  - トークンを踏むまで昇格しない
  - **他人のアカウントを併合しない**
  - **衝突時の応答が一様**（存在を漏らさない）
  - **競合実行**でも 1 件しか確定しない
- [ ] 新規 `tests/Feature/Auth/EnterpriseOnlyUserEmailTest.php` — 企業 SSO のみの利用者が
      昇格前は使えるメールを持たず、昇格後に持つ
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- メール送信経路が 1 本増える。既存の送信基盤・目録・流量制限へ載せる（独自機構を足さない）。

---

## F1: gate 5 本（G1〜G5）

### 変更箇所
- 新規: `tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest.php`（G1）
- 新規: `tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php`（G2）
- 新規: `tests/Architecture/EnterpriseSsoSecretExposureGateTest.php`（G3）
- 新規: `tests/Architecture/SsoTwoFactorInterpositionGateTest.php`（G4）
- 新規: `tests/Architecture/EmailPromotionIdentityGateTest.php`（G5）
- 新規: `tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php`（走査器の本体）
- 新規: `tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php`（走査器の自己検査 = 負例）
- 新規: `tests/Architecture/fixtures/enterprise-sso/*`（見本ファイル）

### 走査器共通規約（AGENTS.md）への適合

**発火条件に 5 本とも該当する**（走査ロジック・走査対象・名前解決・判定条件・目録の新設）。
正典からの移植であっても aicue の走査根・目録へ合わせる変更が入るため対象外にならない。

| 条 | 適用 | 本設計での形 |
|---|---|---|
| (a) クラス参照は完全修飾名で突き合わせる | G1〜G5 | `use` / group use / 別名つき取り込みを解いた完全修飾名で比べる。構文解析ライブラリは必須ではなく、字句走査 + 取り込み対応表でよい（家系の裁定 AG-154 (2)）。既存の `Tests\Support\PhpReferenceScanner` / `PhpTokenScan` を再利用する |
| (b) 解決できない形は落とす | G1〜G5 | **変数経由の間接呼び出しは保証範囲の外**だと docblock に明記し、その構文について**検出力を主張しない**（正典 G2 も同じ限界を自ら書いている）。それ以外の未解決は**無言で候補から外さず**、未解決と判別できる結果として gate を落とす |
| (c) 検出力は負例で裏取りする | G1〜G5 | 違反する入力を検出できること／規定どおりの入力を誤検出しないことの**両方向**。置き場は `tests/Architecture/fixtures/enterprise-sso/` と `tests/Unit/Architecture/` の 2 通りを使い、**gate の docblock から辿れる**ようにする |
| (d) 集めた結果を必ず判定に使う | G1〜G5 | 収集して参照しない出力・数えるだけで比べない目録を作らない |
| (e) 語彙一致はトークン完全一致 | G1・G3・G4・G5 | 区切り文字集合を**走査ごとに宣言**する。負例に**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く |

### 4 点（同じ変更で揃える）

1. **負例と正例。テストファーストで先に赤くしてから本体を書く**（移植で最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する）
2. **解決できない形を落とす分岐**（(b)）
3. **走査が空振りしていないことの検査** — 母集団が空でないこと／走査根がそれぞれ生きていること。
   走査根は `Tests\Support\TrackedPhpSourceFiles` を使い、同じ列挙を 2 本持たない。
   母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
4. **docblock に走査対象と保証しないものを書く**（正本は docblock 側。本設計へ写さない）

### 各 gate が固定するもの

| gate | 固定する内容 |
|---|---|
| G1 | 企業 SSO の名前空間・controller・身元モデルに**メールで利用者を引く記法**が無い（`whereBlind('email', …)` を含む）。加えて**身元表の索引が 0 本**であることを**スキーマの読み取りだけ**で確かめる（`migrate:fresh` 等の破壊操作を伴わない = 禁止事項 3） |
| G2 | `App\Services\EnterpriseSso` 配下に**素の HTTP 呼び出し**が無い（許可一覧を持たない）。**自動リダイレクト追従を有効にする記法**も無い |
| G3 | 接続の秘密が**受け渡しの型に存在しない**。例外が**機械可読な理由文字列だけ**を持つ。前面で秘密を扱うのは**登録・更新フォーム 1 本だけ** |
| G4 | 企業・ソーシャル**両方**の戻り口に、待機ログインを作る記述・2 要素の入力画面への転送が無い。**主たる証明は実挙動側（C2 のテスト）にあると gate 自身が宣言する** |
| G5 | 昇格フローが**メールから利用者を引かない** / **既存アカウントとの併合をしない** |

### テスト計画
- [ ] 各 gate に対応する負例（見本ファイル or 検出器の自己検査）を先に書き、**赤を確認してから**本体を書く
- [ ] 走査根が空でないことの検査を 5 本とも持つ
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **走査器を 1 本に共有する**ので、走査根の定義を誤ると 5 本が同時に空振りする。3 の空振り検査がその唯一の防波堤になる。

---

## F2: aicue 側の目録登録

### 変更箇所
- 変更: `app/Enums/Security/ThrottleCoverageExemption.php`（必要なら case を足す。既存で足りるなら足さない）
- 変更: `app/Enums/Security/ControllerAuthorizationExemption.php`（同上）
- 変更: `tests/Support/Routing/NestedRouteDefenseInventory.php`（新 route の parameter を分類）
- 変更: `tests/Architecture/RecentAuthRouteTest.php`（更新系 6 route を allowlist へ）
- 変更: `tests/Architecture/TwoFactorEnforcementAllowlistTest.php`（**件数は変えない** — 下記）
- 変更: `app/Enums/Account/AccountDeletionFreezeAllowance.php`（**変えない** — 下記）

### 登録の判断

| 目録 | 判断 |
|---|---|
| `RecentAuthRouteTest` の allowlist | **更新系 6 route を追加**（store / update / verify / activate / disable / destroy）。接続の秘密と組織のログイン経路を変える操作であり、既存の「API キー発行・失効」と同水準 |
| `ThrottleCoverageInventoryTest` | ログイン導線の `enterprise-sso.login` は**外向き通信をしない開始画面**なので、既存の `AuthFlowInitiationWithoutOutboundCall` 相当の exemption へ登録する。それ以外の 8 route は named limiter を持つ |
| `ControllerAuthorizationGateTest` | 組織側 6 変更系は `Gate::authorize`。**ログイン導線の `enterprise-sso.callback` は変更系ではない GET** なので母集団に入らない |
| `NestedRouteDefenseInventory` | `organizations.sso.*` の `{organization}` は `ScopedBinder`、`{connection}` は `ScopeBindings` |
| `TwoFactorEnforcementAllowlistTest` | **追加しない**（件数 21 のまま）。組織側の接続管理は業務面であり、2 要素義務づけの下で到達できなくてよい。ログイン導線は未認証面なのでゲートの母集団に入らない |
| `AccountDeletionFreezeAllowance` | **追加しない**。企業 SSO の接続管理は退会予約中に実行できなくてよい |
| `ExternalSeamInventory` | 施策 F4 で登録 |

### テスト計画
- [ ] 目録を触った各 gate が緑（`composer test -- --filter=Architecture`）
- [ ] `TwoFactorEnforcementAllowlistTest` の件数 pin が **21 のまま**であることを確認（意図せぬ追加をしていない）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- exemption の case を「汎用に見えるもの」へ押し込むと gate が形骸化する。当てはまる case が無ければ、それは throttle / 認可を足すべき route である。

---

## F3: 逸脱の登録 D37 + 台帳件数の pin

### 変更箇所
- 変更: `docs/template-divergence.md`（エントリ D37 を追加。冒頭の「登録エントリ: N 件」を 36 → 37）
- 変更: `tests/Support/TemplateDivergence/LedgerPins.php`（`DIVERGENCE_ENTRY_COUNT` を 36 → 37）

### 乖離台帳の確認（app-design Phase 3-0 の確認段）

`docs/template-fingerprints.json` の `entries`（281 件）を実読して突き合わせた:

- 本設計が**新規作成**するファイルは**いずれも `entries` に無い**（テンプレートに無い領域）
- 本設計が**変更**する既存ファイル（`routes/web.php` / `tests/Architecture/*` /
  `app/Enums/Security/*` / `tests/Support/*` / `docs/template-divergence.md` /
  `tests/Support/TemplateDivergence/LedgerPins.php`）も **`entries` に無い**
- `entries` に在る config は `audit` / `cache` / `ciphersweet` / `laratrust` / `ssrf-pin` の 5 本で、
  **本設計はどれも変更しない**（`config/enterprise-sso.php` は新規で、共有ファイルではない）
- したがって **`adoption-debt.tsv`（171 件）に触れるパスも無い**（`mutatedDebtPaths` で落ちない）

→ **形式上の登録義務は発生しない**。しかし記録の原則が
「**登録するか迷ったら登録する**」「テンプレートに無い領域への上積みは登録側へ倒す」と定めており、
ログイン試行表（モデル・移行・掃除コマンド）は**正典 v1 に無い上積み**である。よって登録する。

### D37 の内容（登録メタ表 9 行）

| 行 | 値 |
|---|---|
| 対象パス | `app/Models/EnterpriseSsoLoginAttempt.php` / `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` / `database/migrations/2026_08_23_000300_create_enterprise_sso_login_attempts_table.php` / `app/Console/Commands/EnterpriseSso/PruneLoginAttempts.php` |
| 業務要件起因の説明 | 正典はログイン試行の保管先を表として持たない。aicue は `state` の使用権の唯一性を**セッションドライバの種別と `->block()` の書き忘れに依存させない**ため、DB の一意制約と行ロックへ寄せた |
| 揃え続ける不変条件と保証機構 | 「同じ試行の使用権をちょうど 1 つの要求だけが得る」「その試行を開始したブラウザだけが使える」を `EnterpriseLoginAttemptStoreTest` の並行検査と別ブラウザ検査が固定する |
| 再判定の条件 | 本形が正典へ還流されて正典側の版が上がったら、独自差分ではなく**新しい正典追従**になるので登録を消す。また正典が同等の原子性とブラウザ結合を別方式で持ったときも見直す |
| 決めた日 | `2026-08-23` |
| 決めた人 | `開発者` |
| 根拠 | `devnotes/20260823-0015-enterprise-oidc-sso-adoption/` |
| 状態 | `監視中` |
| 見直し期限 | `2027-08-23`（基準日から 400 日以内） |

### テスト計画
- [ ] `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が緑（9 行ちょうど・順序・値域）
- [ ] `tests/Architecture/TemplateDivergenceFingerprintTest.php` が緑（件数 3 点一致）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 件数の pin は**宣言行・見出しの実数・定数の 3 点一致**なので、1 か所でも忘れると赤になる。同じ変更で 3 つとも書き換える。

---

## F4: 試験用の偽 IdP と外部到達点の登録

### 変更箇所
- 新規: `app/Services/EnterpriseSso/Fakes/FakeOidcDiscoveryService.php` ほか（正典の「試験用の接続先 4 クラス」に相当）
- 新規: `app/Http/Controllers/Testing/FakeIdpAuthorizeController.php`（aigenba の上積みと同型。**試験環境限定で登録される**）
- 変更: `app/Support/ExternalFakes/ExternalFakeDeclaration.php`（差し替えの宣言を足す）
- 変更: `tests/Support/ExternalSeam/ExternalSeamInventory.php`（外部到達点を登録）

### 設計の要点

- **テストレーンは外向き HTTP を既定で拒否する**（AGENTS.md）。実 IdP へ出ない。
- 偽の IdP の許可環境は**外部ログインと同じ `testing` / `bughunt.local`** に絞る
  （`local` を外す理由は既存の `SSO_ENVIRONMENTS` の docblock と同じ —
  未認証の GET で canned アカウントに入れる = 認証バイパスであり、
  `local` は実 IdP 連携を確かめる唯一の環境である）。
- **同じ事実を 2 か所に書かない**（AGENTS.md ドメイン規約 9）:
  差し替えの宣言は `ExternalFakeDeclaration`、外部到達点の目録は `ExternalSeamInventory` が持つ。
- 本番コードが偽の実装のクラス名を参照しないことは既存の `FakeClassReferenceInvariantTest` が全走査する。

### テスト計画
- [ ] `tests/Architecture/ExternalFakeWiringInvariantTest` / `ExternalSeamInventoryTest` /
      `LaneExternalFakeBindingTest` / `FakeClassReferenceInvariantTest` が緑
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseOidcFakeRoundTripTest.php` — 偽の IdP で往復が通る
- [ ] `ProductionEnvGuard` が本番での有効化を止める（既存機構に載るだけ）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 偽の IdP は**未認証の GET で任意の subject を名乗れる**。許可環境の絞りが唯一の防波堤なので、
  既存の `SSO_ENVIRONMENTS` と同じ集合を使い、独自に緩めない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規ファイルが 40 本前後、`routes/web.php` に 10 route を足し、6 つの目録と 2 つの台帳ファイルを触る。(2) **前段依存**（`kent013/laravel-ssrf-pin` の `^0.4` 化）が別 TODO で先行するため、その完了を待って独立ブランチで積む必要がある。(3) gate 5 本をテストファーストで赤→緑にする工程が長く、他施策と混ぜると赤の出所が分からなくなる |
| 競合リスク | `routes/web.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/RecentAuthRouteTest.php` / `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` は**他の TODO も触る中心ファイル**である。とくに `LedgerPins.php` の件数 pin は他の逸脱登録と衝突しやすいので、マージ直前に件数を取り直す |

### 段の順序（直列。前段が緑になってから次へ）

| 段 | 施策 | 前提 |
|---|---|---|
| 前段 | — | `ssrf-pin-v04-upgrade` の完了（受入条件 3 点: GET の本文取得 / body 付き POST の本文取得 / どちらも SSRF 判定を通る） |
| A | A1・A2・F3 | 前段 |
| B | B1・B2・B3・B4・F4 | A |
| C | C1・C2 | B |
| D | D1・D2 | C |
| E | E1 | D |
| F | F1・F2 | 各段が自分の gate を持って緑にしたうえで、取りまとめる |

> **gate は最後にまとめて足さない**。各段が自分の gate を同じ変更で持って緑にする
> （禁止事項 1: 不変条件は対応するテストへの登録まで含めて「実装済み」）。
> F は目録の登録漏れを閉じる取りまとめの段である。

## スコープ外（明記）

- **ソーシャル SSO の作り替え**（`auth-sso-social`）。既に AG-200 の形なので**挙動を変えない**。
  本設計が触るのは「その形を機械で固定する gate（G4）を 1 本足す」ことだけである。
- **運営側 SSO**（`auth-admin-sso`）。
- **`acr_values` による認証強度の要求**。AG-200 が「強度を上げたい要件はこれで行う」と書いているが、
  aicue に該当要件は無い（思考原則 2「今必要なものだけ作る」）。
- **SCIM / 自動デプロビジョニング**、および **IdP 側の停止に連動した既存セッションの即時失効**。
  入退社連動は「次回ログインができなくなる」までとする。
- **IdP 起点のログイン（IdP-initiated SSO）**。RP 起点のみ。
- **接続を無効にした後の猶予窓**（spirux にだけ設定値があるが強制する仕組みは未実装で、正典の形ではない）。
- **`kent013/laravel-ssrf-pin` の版上げそのもの**（別 TODO `ssrf-pin-v04-upgrade`）。
  本設計は `config/ssrf-pin.php` を**変更しない**。
- **既存ログイン手段の削除・変更**（`EnsureLoginMethodRemains` の意味論は変えない）。
- **refresh token の保存とバックグラウンドでの更新**。ログインの確定にのみトークンを使い、保存しない。

---

## 関連する現行コード（抜粋）

### app/Http/Controllers/Auth/SocialAuthController.php (先頭 120 行 — 既存 SSO の作法。AG-200 の形)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Security\RecentAuthState;
use App\Services\Auth\SocialAccountService;
use App\Services\Auth\SocialiteDriverResolver;
use App\Services\Onboarding\IntendedPlanResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Webmozart\Assert\Assert;

/**
 * SSO (Socialite) フロー。
 *
 * 開始導線は GET の anchor リンクであること (form POST にしない)。
 * form POST だと 302 リダイレクト先 (IdP) にも CSP form-action が適用され
 * ブロックされる (devnotes/20260611-template-extraction/14 §3)。
 *
 * intent (login / register / link / step-up) は session に保存し、callback で分岐する。
 * step-up は recent-auth (再認証) の SSO satisfier: ログイン済ユーザーが自分に連携済みの
 * provider で OAuth round-trip を完了すると RecentAuthState が鮮度を stamp する。
 */
class SocialAuthController extends Controller
{
    private const INTENTS = ['login', 'register', 'link', 'step-up'];

    public function __construct(
        private readonly IntendedPlanResolver $intendedPlanResolver,
        private readonly SocialiteDriverResolver $socialiteDriver,
    ) {}

    public function redirect(Request $request, string $provider, string $intent): RedirectResponse|SymfonyRedirectResponse
    {
        $this->ensureProviderEnabled($provider);
        abort_unless(in_array($intent, self::INTENTS, true), 404);

        if ($intent === 'link' || $intent === 'step-up') {
            abort_unless($request->user() !== null, 403);
        }

        // register のみ規約同意が必要 (query で受けて server 側でも検証)
        if ($intent === 'register' && $request->query('terms_accepted') !== '1') {
            return redirect()->route('register')
                ->withErrors(['terms_accepted' => '利用規約への同意が必要です。']);
        }

        // 料金表由来のプラン意図。register 開始では ?plan= を pending に書き換え (不在は forget)、
        // login 開始では常に forget する (前回中断の stale pending を次の登録へ持ち越さない)。
        // link / step-up は登録経路ではないため触らない。
        if ($intent === 'register') {
            $this->intendedPlanResolver->rememberPendingFromQuery($request);
        } elseif ($intent === 'login') {
            $this->intendedPlanResolver->forgetPending();
        }

        $request->session()->put('social_auth_intent', $intent);

        $driver = $this->socialiteDriver->driver($provider);

        // step-up は IdP に再認証を促す (OIDC 標準の prompt=login)。RP 側で auth_time は
        // 検証しない最小実装 (capability=fresh_auth_prompt_only)。対応しない provider では
        // 単に無視される。
        if ($intent === 'step-up' && method_exists($driver, 'with')) {
            $driver->with(['prompt' => 'login']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider, SocialAccountService $service, RecentAuthState $recentAuthState): RedirectResponse
    {
        $this->ensureProviderEnabled($provider);

        $intent = $request->session()->pull('social_auth_intent');
        if (! is_string($intent) || ! in_array($intent, self::INTENTS, true)) {
            return redirect()->route('login')->withErrors([
                'email' => 'ログインフローが無効です。もう一度お試しください。',
            ]);
        }

        $socialiteUser = $this->socialiteDriver->driver($provider)->user();

        if ($intent === 'step-up') {
            return $this->completeStepUp($request, $provider, $socialiteUser->getId(), $recentAuthState);
        }

        if ($intent === 'link') {
            $user = $request->user();
            Assert::isInstanceOf($user, User::class);
            $linked = $service->linkToUser($provider, $socialiteUser, $user);

            return $linked
                ? redirect()->route('settings.security')->with('success', 'アカウントを連携しました')
                : redirect()->route('settings.security')->withErrors([
                    'social' => 'このアカウントは既に別のユーザーに連携されています。',
                ]);
        }

        $linkedUser = $service->findLinkedUser($provider, $socialiteUser);

        if ($linkedUser !== null) {
            // login / register どちらの intent でも、連携済みならログイン扱い
            Auth::login($linkedUser, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        if ($intent === 'login') {
            // 未連携: 自動登録はしない (明示的な register 経由を要求)
            $this->intendedPlanResolver->forgetPending();

```

### app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php (先頭 45 行 — 転送先が「設定ページ」であること)

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
```

### app/Models/User.php (先頭 95 行 — CipherSweet の作法)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\TwoFactorStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Transformation\Lowercase;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * T142: 退会予約 (猶予期間つき削除・凍結方式) の予約列。**users 行の生死は変えない**ため
 * SoftDeletes は使わず、両列が揃っているときだけ「予約中」とみなす
 * (状態機械は DB の CHECK 制約 users_deletion_request_pair_check が閉じている)。
 * 保護列であり $fillable 外 (forceFill でのみ書く)。
 *
 * @property CarbonImmutable|null $deletion_requested_at
 * @property CarbonImmutable|null $deletion_purge_after
 */
class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    // Passport OAuth guard (mcp-oauth / api-oauth) が withAccessToken() / token() を要求する
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRolesAndPermissions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable, UsesCipherSweet;

    /**
     * PII (email / name) は CipherSweet で暗号化するため、平文 where() では検索できない。
     * email は whereBlind('email', 'email_index', $value)、name は
     * whereBlind('name', 'name_index', $value) を使うこと
     * (認証経路は App\Auth\EncryptedUserProvider が担う)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * 同意系 (terms_accepted_at / consent_version) は $fillable 外。
     * 登録 Action が forceFill で明示的に記録する。
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));

        // name も blind index 化し、管理画面 (Filament) の暗号化氏名検索を成立させる。
        // blind index は値全体ハッシュ = 完全一致のみ。Lowercase transformer で大文字小文字差を
        // 吸収する (case-insensitive 完全一致)。blind index は共有 blind_indexes morph テーブルに
        // 入るため列 migration は不要。unique 制約は email_index 限定の partial unique のため
        // 非ユニークな name_index (同姓同名) を追加しても安全。
        $encryptedRow
            ->addField('name')
            ->addBlindIndex('name', new BlindIndex('name_index', [new Lowercase]));
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
```

### app/Services/Mail/Sns/SnsCertificateFetcher.php (SSRF 経由の外部取得の準拠実装 — 抜粋)

```php
 *   「証明書 host の region を TopicArn の allowlist へ束縛する」
 *   「名前解決用の独立した同時実行制限を設ける」「解決器へ実効 timeout を入れる」である
 * - **応答サイズ上限も時間予算もメモリ使用量の上界ではない**。Laravel の HTTP client は
 *   既定で非 stream なので本文は先に全部メモリへ載り、長さを測る位置を変えても上界にならない。
 *   時間の上限も、帯域が大きければ受信バイト数を制限しない。上限の役割は
 *   「**期待と違う応答を検証・キャッシュに固定しない**」ことだけである
 * - **permit 1 は条件付き**である (上記のとおり後処理に強制上限が無い)。
 *   worker 停止やキャッシュ基盤の長時間停止で保持が伸びれば取得は重なりうる。
 *   所有者つきの解放で誤解放は防ぐが、重なり自体は防がない
 * - **キャッシュ store が共有されない構成 (file 等) ではホストごとに 1 回取りに行く**
 *   (既定 `database` は共有される)
 */
final readonly class SnsCertificateFetcher
{
    /** キャッシュキーの接頭辞 (URL は sha256 にする = キーに平文を残さない) */
    public const string CACHE_PREFIX = 'sns:cert:';

    /**
     * 同時取得数。**単一ロックキーと 1 対 1 に対応する** (上の docblock 参照)。
     * 2 以上へ増やすならロックキーの分割が同時に要る。
     */
    public const int CERT_FETCH_PERMITS = 1;

    /** 取得ロックのキー (1 本だけ持つ) */
    private const string CERT_FETCH_LOCK_KEY = 'sns:cert:fetch';

    public function __construct(
        private HttpFactory $http,
        private UrlSafetyInspector $inspector,
    ) {}

    /**
     * キャッシュ済みの PEM。無いとき / キャッシュ障害のとき / 読み戻せない値だったときは null。
     */
    public function cached(SnsCertificateUrl $url): ?string
    {
        $key = self::cacheKey($url);

        try {
            /** @var mixed $value */
            $value = Cache::get($key);
        } catch (Throwable) {
            // キャッシュは最適化である。読みの障害で署名検証を止めない (miss 扱い)。
            Log::warning('mail.sns.cert_cache_read_failed');

            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '' && self::isReadablePem($value)) {
            return $value;
        }

        // 読み戻せない値が残っていたら消して miss 扱いにする (不変条件 11)。
        $this->forgetQuietly($key);

        return null;
    }

    /**
     * SSRF 検査 → 同時 1 本に直列化した取得。
     *
     * 手順: SSRF 検査 (ロックの外) → 非ブロッキングでロック →
     * **ロック保持中にキャッシュ再確認** → 取得 → finally で所有者つき解放。
     *
     * @throws SnsSignatureInvalidException SSRF 判定 / サイズ / PEM 不正 (恒久 = 403)
     * @throws SnsVerificationUnavailableException 競合 / ロック基盤障害 / 取得失敗 / DNS 解決失敗 (503)
     */
    public function fetchSerialized(SnsCertificateUrl $url): SnsCertificate
    {
        // ★SSRF 検査はロックの**外**で行う。(a) DNS 解決に時間の上限が無くロック寿命の
        //   予算へ入れられない、(b) 拒否される要求にロックを触らせない、の 2 つが理由である。
        $this->inspect($url);

        // ★ここで投げるのは「ロック非対応 store」等の設定・実装の誤りだけなので**捕まえない**
        //   (可用性の退避に飲み込ませない = fail-fast)。
        $lock = Cache::lock(
            self::CERT_FETCH_LOCK_KEY,
            Config::integer('services.sns_certificate.lock_ttl_seconds'),
        );

        try {
            $acquired = $lock->get();
        } catch (Throwable $e) {
            // ロック基盤の一時障害。排他できない状態では取りに行かない
            // (同時取得数の上界を黙って壊すより、再送に任せるほうが安全である)。
            throw new SnsVerificationUnavailableException('certificate lock unavailable', 0, $e);
        }

        if ($acquired !== true) {
            // 待たない (上の docblock 参照)。
            throw new SnsVerificationUnavailableException('certificate fetch is busy');
        }

        try {
            // ロックを取るまでの間に別の要求が埋めているかもしれない。
            $cached = $this->cached($url);
            if ($cached !== null) {
                return SnsCertificate::fromCache($cached);
            }

            return SnsCertificate::fetched($this->fetchRemote($url));
        } finally {
```

### config/ssrf-pin.php (本設計は変更しない)

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SSRF 安全境界 pin (kent013/laravel-ssrf-pin)
|--------------------------------------------------------------------------
|
| 外部 URL 取得の SSRF 検査は `Kent013\SsrfPin\UrlSafetyInspector` が SSOT。
| パッケージは VCS 依存のため、package 側の既定値変更で外向き許可面が
| 広がらないよう、安全境界は必ず本 config で app 側に pin する
| (SsrfPinBoundaryTest が pin 値を固定)。
|
| 外部 URL (特にユーザ入力由来) を取得する機能を追加する場合は、
| 必ず UrlSafetyInspector / PinnedHttpClient を通すこと (AGENTS.md 参照)。
|
*/

return [
    // 許可するスキーム。
    'allowed_schemes' => ['http', 'https'],

    // 許可するポート。非標準ポート (内部サービス等) への到達を防ぐ。
    'allowed_ports' => [80, 443],

    // redirect 追従の最大 hop 数。
    'max_redirect_hops' => 5,

    // アプリ拡張用の追加 deny CIDR (例: 自社内部レンジ)。
    'additional_deny_cidrs' => [],

    // host が IP literal (例: http://93.184.216.34) の URL を一律拒否する。
    // パッケージ既定 (false) より厳しい保守既定。raw-IP URL を許可したい
    // アプリのみ意図的に false へ変更する (public IP のみ許可される)。
    'deny_ip_literals' => true,
];
```

### routes/web.php (SSO と組織 route の該当部分)

```php
| 2FA 強制ゲートは RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES で
| 明示的に免除している (免除しないと 2FA 強制中に秘匿が解除できず reload ループになる)。
*/
Route::get('/session/status', SessionStatusController::class)->name('session.status');

/*
|--------------------------------------------------------------------------
| SSO (Socialite)
|--------------------------------------------------------------------------
| 開始導線は GET の anchor リンク (form POST にしない。CSP form-action が
| リダイレクト先 IdP に適用されるため)。
*/
Route::get('/auth/{provider}/redirect/{intent}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
// callback は SocialAuthController::callback() 内の Socialite::driver()->user() で
// **IdP への外向き HTTP** が起きる (未認証で外部へ HTTP を発射できる唯一の経路)。
// 未認証面のため named limiter で IP レーンを明示する (閾値は passkeys guest と同値)。
// redirect 側は外向き通信をしないため throttle を貼らず、exemption
// (AuthFlowInitiationWithoutOutboundCall) として理由を inventory に残す。
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:social-callback')
    ->name('social.callback');

/*
|--------------------------------------------------------------------------
| 認証済み
|--------------------------------------------------------------------------
*/
/*
| 退会予約中 (猶予期間つき削除・凍結方式) の凍結対象は **この group 全体**である
| (`not-pending-deletion` = deny-by-default)。通す route は
| App\Enums\Account\AccountDeletionFreezeAllowance の exact case のみで、
| 母集団の内外と allowlist の一致は AccountDeletionFreezeRouteGateTest が固定する。
| ログイン・ログアウト・パスワード再設定・メール確認・2FA challenge・passkey ログインは
| **この group の外**にあるため、認証回復と離脱の手段は構造的に凍結されない。
| 実行位置 (テナント境界 404 より後) の正本は bootstrap/app.php の priority list。
*/
Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
    // ログイン直後の着地点 (課金ゲート外のまま。未契約でも状況把握と復帰導線を提供)
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
    | recent-auth (generic step-up 再認証)。機微操作 route の `recent-auth` middleware が
    | 鮮度切れ時にここへ誘導する。satisfier は password 再入力と再SSO
    | (/auth/{provider}/redirect/step-up)。allowlist は RecentAuthRouteTest が CI 固定。
    */
    Route::get('/recent-auth/confirm', [ConfirmRecentAuthController::class, 'show'])
        ->name('recent-auth.confirm');
    // クライアント主導 step-up の precheck (XHR, no-store)
    Route::get('/recent-auth/status', [ConfirmRecentAuthController::class, 'status'])
        ->name('recent-auth.status');
    // 再認証 (step-up) の password satisfier。**この route が 429 になると復帰導線が塞がる**ため、
    // 他の認証操作と bucket を共有しない named limiter を使う (T125。inline は共有される)。
    Route::post('/recent-auth/password', [ConfirmRecentAuthController::class, 'confirmPassword'])
        ->middleware('throttle:password-verify')
        ->name('recent-auth.password');

    Route::get('/settings', [ProfileController::class, 'index'])->name('settings');

    // パスワード**初回設定** (password 未設定ユーザー専用)。認証手段を増やす操作のため
    // step-up (recent-auth) 必須。変更 (current_password 必須) は Fortify の PUT /user/password。
    // EnsureLoginMethodRemains は付けない (手段を減らす操作の関門であり方向が逆)。
    // 初回設定は current_password を照合しない credential mutation のため
    // 照合面 (password-verify) とはレーンを分ける (T125)。閾値は 6/min のまま。
    Route::post('/settings/password', [PasswordSetupController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:password-set'])
        ->name('settings.password.store');

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
    | (AppServiceProvider で Route::bind 登録) が「認証済みユーザーが所属する組織」に
    | スコープして解決する。非メンバー・不在 slug/id は等しく 404 (テナント存在秘匿)。
    | same-org の権限不足 403 は従来どおり Policy の責務。
    */
    Route::get('/organizations/create', [OrganizationController::class, 'create'])
        ->name('organizations.create');
    // 未認証時は /email/verify への沈黙 302 ではなく back + error flash で戻す (verified.or-back)。
    // group の 'verified' を route 単位で外し (将来の group middleware 追加で取りこぼさないため
    // group 外出しではなく withoutMiddleware で override)、verified.or-back を個別付与する。
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->withoutMiddleware('verified')
        ->middleware('verified.or-back:organization-store')
        ->name('organizations.store');
    // 切替は field 無指定 (= id) binding。非所属組織は binder が 404 に倒す
    Route::post('/organizations/{organization}/switch', [OrganizationSwitchController::class, 'store'])
        ->name('organizations.switch');
    Route::get('/organizations/{organization:slug}/settings', [OrganizationController::class, 'settings'])
        ->name('organizations.settings');
    Route::patch('/organizations/{organization:slug}', [OrganizationController::class, 'update'])
        ->name('organizations.update');
    // 招待送信も未認証時は back + error flash で戻す (verified.or-back)。organizations.store と
    // 同様に group の 'verified' を route 単位で外し verified.or-back を個別付与する。
    Route::post('/organizations/{organization:slug}/invitations', [OrganizationInvitationController::class, 'store'])
        ->withoutMiddleware('verified')
        ->middleware('verified.or-back:invite')
        ->name('organizations.invitations.store');
    // 招待取り消し (論理失効)。{invitation} は scopeBindings で $organization->invitations()
    // 経由に解決され、組織を跨ぐ取り消しは認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
    Route::delete('/organizations/{organization:slug}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])
        ->scopeBindings()
        ->name('organizations.invitations.revoke');
    /*
    | {user} は scopeBindings で $organization->users() 経由に解決する。
    | 非メンバー / 不在 id は **binding 段で等しく 404** になり、recent-auth (302) を含む
    | binding 後のどの短絡 middleware よりも前に閉じる (audit-cycle-2 High-1 横断)。
    | implicit binding のままだと「不在 = binding 404 / 実在の非メンバー = 後段短絡の 302」と
    | 分岐し、users.id の存在オラクルになっていた。
    | controller の inline guard (resolveOrganizationMember) は二重防御として残す。
    | 親 {organization:slug} は MembershipScopedOrganizationBinder が引き続き担当する
    | (scopeBindings は子解決のみに作用)。
    */
    Route::scopeBindings()->group(function (): void {
        Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
            ->name('organizations.members.update');
        Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
            ->name('organizations.members.destroy');
        // メンバーの 2FA リセット (ロックアウト救済。Owner/Admin + step-up + 理由必須)
        Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
            ->middleware('recent-auth')
            ->name('organizations.members.two-factor.reset');
    });
    // 組織の 2FA 必須方針トグル (Owner 専権 + step-up)
    Route::patch('/organizations/{organization:slug}/two-factor-requirement', [OrganizationController::class, 'updateTwoFactorRequirement'])
        ->middleware('recent-auth')
        ->name('organizations.two-factor-requirement.update');
    // オーナー移譲は step-up (recent-auth) 必須
    Route::post('/organizations/{organization:slug}/transfer-ownership', [OrganizationOwnershipController::class, 'store'])
```

### app/Support/ExternalFakes/ExternalFakeDeclaration.php (先頭 82 行)

```php
<?php

declare(strict_types=1);

namespace App\Support\ExternalFakes;

use App\Services\Auth\Fakes\FakeSocialiteDriverResolver;
use App\Services\Auth\SocialiteDriverResolver;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use InvalidArgumentException;
use Kent013\SsrfPin\UrlSafetyInspector;

/**
 * 「どの外部到達点を、どのフラグと許可環境で、どの偽の実装へ差し替えるか」の唯一の正本。
 *
 * ★本番の読み込み対象 (app/) に置く。差し替えの配線 (BughuntFakesServiceProvider)・
 *   storage の有効化条件 (FakeStorageGate)・bug-hunt の投入データ (seeder)・
 *   本番混入防止 (ProductionEnvGuard) が**すべてここだけを読む** (同じ集合を 2 か所に書かない)。
 * ★本クラスは値を返すだけで判定を持たない。有効・無効の判定は
 *   BughuntFakesServiceProvider (container 差し替え) と FakeStorageGate (storage) が行う。
 *
 * 関連する目録との責務境界:
 * - 本番コードが偽の実装のクラス名を参照しないことの全走査は FakeClassReferenceInvariantTest
 * - 外部到達点そのものの目録は ExternalSeamInventory
 *   (同じ事実を 3 か所で宣言しない。AGENTS.md ドメイン規約 9)
 */
final class ExternalFakeDeclaration
{
    /** 外部サービス fake (決済 + 人間性確認 + 外部ログイン) の capability flag */
    public const string EXTERNALS_FLAG = 'testing.fake_externals';

    /** storage fake の capability flag */
    public const string STORAGE_FLAG = 'testing.fake_storage';

    /** LLM fake の capability flag (container 差し替えではないため swaps() には現れない) */
    public const string LLM_FLAG = 'testing.fake_llm';

    /**
     * capability flag の config キー => 対応する環境変数名。
     *
     * 本番混入防止 (ProductionEnvGuard) と bug-hunt の環境ひな型検査が読む。
     */
    public const array FLAG_ENVIRONMENT_VARIABLES = [
        self::EXTERNALS_FLAG => 'TESTING_FAKE_EXTERNALS',
        self::STORAGE_FLAG => 'TESTING_FAKE_STORAGE',
        self::LLM_FLAG => 'TESTING_FAKE_LLM',
    ];

    /**
     * 外部サービス fake の許可環境 (capability 全体。個々の差し替えはこれ以下に絞れる)。
     */
    public const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /**
     * 外部ログインの差し替えだけ `local` を外す。
     *
     * 未認証 GET 2 本で canned アカウントに入れる = 認証バイパスであり、かつ `local` は
     * 実 IdP 連携を確かめる唯一の環境である (無言で偽物が立つと本番 SSO の回帰を見逃す)。
     */
    public const array SSO_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /** storage fake の許可環境 (testing での追加条件は FakeStorageGate が持つ) */
    public const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /** LLM fake の許可環境 (Prompt::$fake はプロセス大域の static のため testing/local を外す) */
    public const array LLM_ENVIRONMENTS = ['bughunt.local'];

```

### tests/Support/TemplateDivergence/LedgerPins.php

```php
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録簿と指紋台帳の固定値 (不変の scalar 定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・git 実行を一切持たない**。値の置き場所を 1 か所にするための型である。
 *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
 *   2 つの gate (形式検査と突合) が同じ値を読むにはクラス定数である必要がある。
 * ★**これは免除の一覧ではない**。個別のパスや D 番号を名指しして規則を免除する仕組みは
 *   本機構のどこにも無い。
 */
final class LedgerPins
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
    public const int DIVERGENCE_ENTRY_COUNT = 36;

    /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
    public const int FINGERPRINT_POPULATION_COUNT = 281;

    /**
     * 採用時債務の件数。
     *
     * ★機械が保証するのは**無断の増減の検出**までである (一覧と本定数を同じ変更で
     *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
     *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
     */
    public const int ADOPTION_DEBT_COUNT = 171;

    /**
     * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
     *
     * ★掃除の判定は**登録の存在**で行う (対象パスだけを見ると、一覧ファイルを消して
     *   対象パス欄から一覧パスだけを削り登録を残す、という中途半端な掃除が緑になる)。
     *   同定に使うので番号を pin する。
     *   ★**引退時に外すのは対象パスの 1 行だけで、登録そのものは残る** —
     *   一覧が 0 件になっても判定機構 (`AdoptionDebtInventory`) は残り続けるので、
     *   本アプリ固有の追加としての説明は要る (詳しくは同クラスの docblock)。
     */
    public const int ADOPTION_DEBT_DIVERGENCE_ID = 34;

    /** 取り込んだ正典台帳の generated_at_commit (指紋台帳の出自 pin)。 */
    public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';

    /**
     * 取り込んだ正典台帳ファイル自身の sha256 (生成器の入力ガード)。
     *
     * 取得元は laravel-claude-template の `docs/template-fingerprints.json`
     * (読み取りコミット `0597a0c24d7fa7a054e3337704ccc97e4409b866` / 947 キー / 128420 バイト)。
     * 別の台帳を食わせるには生成器へ `--adopt-new-template-ledger` を明示する。
     */
    public const string TEMPLATE_LEDGER_SOURCE_SHA256 = '0c9add21dc79429f6d80e38cfeb95736af750bd760ee9584d2e2b8a1285c0c90';

    /** アプリ側の指紋台帳の置き場 (リポジトリ相対)。 */
    public const string FINGERPRINT_LEDGER_PATH = 'docs/template-fingerprints.json';
}
```
