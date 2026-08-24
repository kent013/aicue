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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
> を壊すため、どちらの側も renumber しない。

> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。`trustProxies(at: '*')` はレート制限を
> 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
> `docs/trusted-proxies-runbook.md` が正本。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。数値を見て即座に閾値を弄るな。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / Laratrust RBAC

【本件の前提 — 蒸し返し禁止】
- 複数リポジトリ共有の機能台帳 c2c における 2026-08-04 のオーナー裁定済み案件。gate の導入是非は論点ではない。
- 本タスクは設計のみ (コード変更なし)。成果物は Architecture テスト一式。
- 概念設計は Codex レビュー 3 ラウンドを消化済み。詳細設計のレビューは最大 3 ラウンド。

【レビュー観点】
1. 実装可能性: token_get_all ベースでこの走査器が本当に書けるか。特に provenance 証明・alias 追跡・メソッド境界確定
2. PHPStan level 10 適合性 (generics、token_get_all の戻り値 narrowing、readonly class、literal union)
3. 既存コードとの整合性 (AuthorizationMarkerScanner / NestedRouteDefenseInventory / ControllerAuthorizationExemption の作法)
4. テスト計画の網羅性。gate が静かに無力化する経路が残っていないか
5. 候補 key の設計 (行番号を使わずメソッド内出現順) の妥当性・安定性
6. セキュリティ: この gate 設計で守れない経路が具体的にあるか
7. 実装コストが過大でないか (AGENTS.md 思考原則「今必要なものだけ作る」)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には必ず修正案
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: ModelDirectFetchInvariantTest (直 find 禁止 gate) の追従

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

> 本タスクは **Architecture テストの追加のみ**。4/5/6/7/8 は該当しない。
> 1 は本タスクの主題そのもの (走査器の Unit テストまで含めて完了)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)、`RefreshDatabase` は `tests/Pest.php` でグローバル適用(個別 `DatabaseTransactions` 禁止)
- `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `vendor/bin/pint` / `composer fix`
- PHP 8.4 + Laravel 12

> **本タスクは DB を一切触らない** (静的走査 + route 走査のみ)。Factory / migration の追加は無い。

## 概念設計リファレンス

`devnotes/20260805-2311-model-direct-fetch-gate/conceptual-design.md`
(Codex レビュー 3 ラウンド消化済み。残存リスクは `codex-history/conceptual-review-decisions-round-3.md` 末尾)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 分類 enum の追加 | `app/Enums/Security/DirectFetchJustification.php` (新規) | 高 |
| 2 | inventory エントリ型の追加 | `tests/Support/Security/DirectFetchJustificationEntry.php` (新規) | 高 |
| 3 | 走査器の追加 | `tests/Support/Security/PrimaryKeyStaticQueryScanner.php` (新規) | 高 |
| 4 | inventory 本体の追加 | `tests/Support/Security/DirectFetchInventory.php` (新規) | 高 |
| 5 | gate 本体の追加 | `tests/Architecture/ModelDirectFetchInvariantTest.php` (新規) | 高 |
| 6 | 走査器の Unit テスト | `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php` (新規) | 高 |
| 7 | 規約ドキュメントへの gate 名登録 | `AGENTS.md` / `docs/app-integration-guide.md` / `docs/architecture.md` (変更) | 中 |

**アプリのコード (`app/Http`, `app/Services` 等) は 1 行も変更しない。**
施策 1 の enum は `app/Enums/Security/` に置くが、これは既存 `ControllerAuthorizationExemption` と同じ
「アプリが持つ裁定語彙」であり、振る舞いには一切関与しない (テストのみが参照)。

### 波及変更 (全施策共通)

- TypeScript 型定義: **なし** (frontend に波及しない)
- API Resource / DTO: **なし**
- Inertia Props: **なし**
- 既存テストの変更: **なし** (新規追加のみ。既存 Architecture テストとは母集団が交わらない)

---

## 施策 1: 分類 enum `DirectFetchJustification`

### 変更箇所

新規 `app/Enums/Security/DirectFetchJustification.php`

### 設計

`ControllerAuthorizationExemption` と同じ作法 —
**汎用に見える case ほど適用条件を狭く書く**。当てはまる case が無ければ「直すべきコード」である。

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * クラス起点の主キー同一性クエリ (ClassRootedPrimaryKeyQuery) が
 * 「テナントスコープ外で id からモデルを引いてよい」と裁定された理由の分類。
 *
 * `tests/Architecture/ModelDirectFetchInvariantTest.php` が deny-by-default で
 * 「候補でない」か「本 enum + 具体的根拠 + 構造化 field」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★ここに無い形は「例外に足す」のではなく「relation 起点に直す」が既定である。
 */
enum DirectFetchJustification: string
{
    /**
     * 同一クエリ内で所有者/テナントに閉じている。
     *
     * 適用条件 (全て満たすこと):
     * - identity 述語と**同じ chain** に所有者/テナント制約がある
     *   (`where('user_id'|'organization_id'|'team_id'|'project_id', …)` /
     *    `whereHas('users'|'organizations'|'projects', …)` / `whereBelongsTo(…)`)
     * - その制約の**右辺が解決済みモデル由来**である (request 由来の値では不可)
     * - 取得**後**に弾く形ではない (後段で弾くと 403/404 差で存在が漏れる)
     */
    case OwnerScopedQueryConstraint = 'owner_scoped_query_constraint';

    /**
     * identity が同一メソッド内のテナントスコープ済みクエリで確定している。
     *
     * 適用条件: 当該変数への代入が relation 起点 (`$organization->projects()->value('id')` 等) で、
     * 代入と使用の間に再代入が無い。
     */
    case IdDerivedFromTenantScopedQuery = 'id_derived_from_tenant_scoped_query';

    /**
     * identity が認証済み actor / 検証済み token claim 由来である。
     *
     * 適用条件 (全て満たすこと):
     * - identity が request payload・query string 由来で**ない**
     * - 同一メソッド内に request accessor が 1 つも無い
     * - `actorSource` を明示できる (どの middleware / claim が actor を確定したか)
     *
     * ★本 case のみ機械証明ができない (provenance のデータフロー解析は走査器の範囲外)。
     *   最終的に人手の根拠文に依存することを承知の上で使う。
     */
    case AuthenticatedActorScope = 'authenticated_actor_scope';

    /**
     * identity が enqueue 時にサーバが確定した job property である。
     *
     * 適用条件: `app/Jobs/**` 配下で identity が `$this->{…Id}`。
     * `enqueuedBy` に dispatch 元を書く。
     *
     * ★actor/token とは信頼境界が違う (過去のリクエストがシリアライズした値であり、
     *   dispatch 元が誤っていれば汚染されうる) ため AuthenticatedActorScope と分けている。
     */
    case QueuePayloadRehydration = 'queue_payload_rehydration';

    /** local 専用の診断経路。route 登録自体が local 限定で production から到達不能。 */
    case LocalOnlyDiagnostics = 'local_only_diagnostics';

    /** 人間の運用者が CLI で明示実行する。HTTP から到達不能。 */
    case OperatorInvokedConsoleCommand = 'operator_invoked_console_command';

    /**
     * **債務**: payload 由来 id を global に引いており、補償チェックが fetch の後段にある。
     *
     * 他の case が「fetch 時点でスコープが閉じている」のに対し、本 case は
     * 「引いた後で弾く」形であり**安全性の質が違う**。準拠形と同列に扱わないために分けてある。
     * 新規コードで本 case を使ってはならない (既存 2 件の可視化のためだけに存在する)。
     */
    case PayloadIdWithGlobalExistenceRuleDebt = 'payload_id_with_global_existence_rule_debt';
}
```

### PHPStan 適合チェック

- [x] backed enum (`string`)。戻り値型は不要
- [x] app → tests の import を作らない (docblock で参照のみ)

---

## 施策 2: inventory エントリ型 `DirectFetchJustificationEntry`

### 変更箇所

新規 `tests/Support/Security/DirectFetchJustificationEntry.php`

### 設計判断: 名前付きコンストラクタで「必須 field の抜け」を型で殺す

構造化 field は case ごとに異なる (`actorSource` / `enqueuedBy` / `routeName` /
`commandSignature` / `verifiedBy` / `validationRule` / `todoRef`)。
すべて nullable プロパティにして実行時に検査すると、**検査漏れがそのまま抜け道**になる。

そこで **case ごとの名前付きコンストラクタ**だけを public にし、コンストラクタは private にする。
「case を選んだ時点で必須 field が型として要求される」形にすれば、
実行時チェックより先にコンパイル (PHPStan) 段で止まる。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Enums\Security\DirectFetchJustification;
use Webmozart\Assert\Assert;

/**
 * 直 fetch 候補 1 件分の裁定エントリ。
 *
 * case ごとに必須の構造化 field が違うため、**名前付きコンストラクタ経由でのみ**生成できる。
 * nullable プロパティ + 実行時検査にすると検査漏れが抜け道になるため、
 * 「case を選んだ時点で必須 field が型として要求される」形にしてある。
 */
final readonly class DirectFetchJustificationEntry
{
    public const int REASON_MIN_LENGTH = 30;

    /**
     * @param  array<string, string>  $metadata  case 固有の構造化 field
     */
    private function __construct(
        public DirectFetchJustification $case,
        public string $reason,
        public array $metadata,
    ) {
        Assert::minLength($this->reason, self::REASON_MIN_LENGTH);
    }

    public static function ownerScopedQuery(string $reason): self
    {
        return new self(DirectFetchJustification::OwnerScopedQueryConstraint, $reason, []);
    }

    public static function idFromTenantScopedQuery(string $reason): self
    {
        return new self(DirectFetchJustification::IdDerivedFromTenantScopedQuery, $reason, []);
    }

    /** @param  'authenticated_user'|'validated_token_claim'|'passport_token_record'  $actorSource */
    public static function authenticatedActor(string $reason, string $actorSource): self
    {
        return new self(DirectFetchJustification::AuthenticatedActorScope, $reason, [
            'actorSource' => $actorSource,
        ]);
    }

    /** @param  string  $enqueuedBy  dispatch 元の `Class::method` */
    public static function queuePayload(string $reason, string $enqueuedBy): self
    {
        return new self(DirectFetchJustification::QueuePayloadRehydration, $reason, [
            'enqueuedBy' => $enqueuedBy,
        ]);
    }

    /** @param  string  $routeName  route 走査で LocalOnly middleware を照合する対象 */
    public static function localOnly(string $reason, string $routeName): self
    {
        return new self(DirectFetchJustification::LocalOnlyDiagnostics, $reason, [
            'routeName' => $routeName,
        ]);
    }

    public static function operatorConsole(string $reason, string $commandSignature): self
    {
        return new self(DirectFetchJustification::OperatorInvokedConsoleCommand, $reason, [
            'commandSignature' => $commandSignature,
        ]);
    }

    /**
     * **債務**エントリ。新規コードで使わない。
     *
     * @param  string  $verifiedBy      補償チェックを行う `Class::method`
     * @param  string  $validationRule  当該 id に掛けている validation rule (例 `exists:users,id`)
     * @param  string  $todoRef         後続 TODO の ID (例 `aicue:T120`)
     */
    public static function globalExistenceRuleDebt(
        string $reason,
        string $verifiedBy,
        string $validationRule,
        string $todoRef,
    ): self {
        return new self(DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt, $reason, [
            'verifiedBy' => $verifiedBy,
            'validationRule' => $validationRule,
            'todoRef' => $todoRef,
        ]);
    }
}
```

### PHPStan 適合チェック

- [x] `final readonly class` + private constructor
- [x] `array<string, string>` の型パラメータ明示
- [x] null 安全 (`Assert::minLength`)
- [x] `$actorSource` は literal union で型を絞る (PHPStan が誤値を検出)

---

## 施策 3: 走査器 `PrimaryKeyStaticQueryScanner`

### 変更箇所

新規 `tests/Support/Security/PrimaryKeyStaticQueryScanner.php`

### 責務

**「解析器 = 本 helper / 母集団走査と突合 = テスト」**という `AuthorizationMarkerScanner` と同じ分離。
走査器の positive/negative は施策 6 が恒久固定する。

### 公開シグネチャ

```php
final class PrimaryKeyStaticQueryScanner
{
    /**
     * ファイル 1 本から候補 (ClassRootedPrimaryKeyQuery) を抽出する。
     *
     * @param  string  $source         PHP ソース全文
     * @param  string  $relativePath   リポジトリ相対パス (候補 key の生成に使う)
     * @return list<PrimaryKeyStaticQueryCandidate>
     */
    public static function candidates(string $source, string $relativePath): array;

    /** 候補が「同一 chain に所有者/テナント制約 (右辺 provenance 込み)」を持つか。 */
    public static function hasOwnerScopedConstraint(PrimaryKeyStaticQueryCandidate $candidate): bool;

    /** 候補のメソッド本文に request accessor が 1 つも無いか (AuthenticatedActorScope の negative check)。 */
    public static function methodIsFreeOfRequestAccessors(PrimaryKeyStaticQueryCandidate $candidate): bool;

    /** 候補の identity 変数が、同一メソッド内で relation 起点クエリから代入されているか。 */
    public static function identityAssignedFromRelationQuery(PrimaryKeyStaticQueryCandidate $candidate): bool;

    /** ソース中に `whereRaw('id` / `whereIntegerInRaw('id'` があるか (範囲外経路の 0 件 assertion 用)。 */
    public static function containsRawPrimaryKeyPredicate(string $source): bool;

    /** 指定 `Class::method` の**メソッド本文だけ**を切り出す (債務 case の検証に使う)。 */
    public static function methodBody(string $source, string $methodName): ?string;
}
```

候補の値オブジェクト (同ファイル内 or 別ファイル):

```php
final readonly class PrimaryKeyStaticQueryCandidate
{
    public function __construct(
        /** `Http/Controllers/Projects/ProjectMemberController.php#store#1` 形式の安定 key */
        public string $key,
        public string $relativePath,
        public string $methodName,
        /** identity 述語に渡された引数のソース断片 (例 `(int) $userId`) */
        public string $identityArgument,
        /** 候補式を構成する chain のトークン列 (副条件判定に使う) */
        public string $chainSource,
        /** 候補が属するメソッド本文 */
        public string $methodSource,
    ) {}
}
```

### 候補 key の設計 (行番号を使わない)

`{app 相対パス}#{メソッド名}#{メソッド内の出現順}`
例: `Http/Controllers/Projects/ProjectMemberController.php#store#1`

行番号を key にすると**無関係な編集で inventory が全崩れ**して形骸化する。
メソッド内の出現順なら、当該メソッドを触らない限り安定する。

### 検出アルゴリズム (token_get_all)

1. `token_get_all($source)` → **コメント / docblock / inline HTML を除去**
   (文字列リテラルは「トークンとして残すが**内容は照合しない**」)
2. `use` 文と `namespace` を走査し、**`App\Models\*` に解決できるクラス短縮名の集合**を作る
3. メソッド境界 (`T_FUNCTION` から波括弧深さで対応する `}` まで) を確定する
4. メソッド本文内で **chain root** を探す:
   - `T_STRING`(Models 集合に含まれる) + `T_DOUBLE_COLON`
   - `\App\Models\…` の FQCN 直書き / 同一 namespace 参照
   - `self` / `static` (ファイルが `app/Models/` 配下のとき)
   - `T_NEW` + Models 集合のクラス
   - `DB` + `::table(` / `::connection(` → `->table(`
5. root から `;` または文末までを **chain** として切り出す (括弧深さで引数内の別 chain と区別)
6. chain 内に**主キー同一性述語**があるか判定 (§概念設計 4-2(b) の表)
   - `where` の 3 引数形は**第 2 引数が `'='` / `'in'` のときのみ**候補 (順序比較を除外)
   - array 形 `where(['id' => …])` / `where([['id','=',…]])` も対象
7. identity 引数を取り出し、**provenance 証明**を適用 (下記) → 証明できたら候補から外す
8. 残ったものを `PrimaryKeyStaticQueryCandidate` として返す

### provenance 証明 (概念設計 §4-2(c) の実装)

identity 引数が次のいずれかなら候補から**外す**。証明できなければ**候補に残す (fail-closed)**。

| 証明 | 判定方法 |
|---|---|
| 型付き引数が `App\Models\*` | 当該メソッドのシグネチャを走査し、`$var` の型宣言が Models 集合に含まれる |
| PHPDoc で明示 | メソッド本文直前 / 直上の `/** @var Project $x */` を照合 |
| 同一メソッド内で relation 起点クエリから代入 | `$var = $y->rel()->…` の代入を検出 |

対象となる引数の形は `$var->getKey()` / `$var->id` / `$var->{snake}_id` のみ。
**`$dto->user_id` のように `$dto` の型が証明できないものは候補に残る** (Round 3 Critical)。

### builder alias 追跡 (概念設計 §4-3)

- `$var = <chain root 式>` の**単純代入**のみ、`$var` を chain root として伝播
- `$var` への**再代入**で伝播を打ち切る
- 引数渡し・プロパティ代入・分岐をまたぐ伝播は**追跡しない**

### 走査器の限界 (docblock に明記する)

- **到達可能性を判定しない** (`if (false) { … }` 中の候補も候補になる)
- `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`) は**範囲外**
- alias 追跡は同一メソッド内の単純代入のみ
- `AuthenticatedActorScope` の provenance は機械証明できない
- 非 bracketed namespace (`namespace App\Foo;` 形式) を前提とする
  (`AuthorizationMarkerScanner` と同じ前提。Pint が強制している)

### PHPStan 適合チェック

- [x] `list<PrimaryKeyStaticQueryCandidate>` の generics 明示
- [x] `token_get_all` の戻り値 (`array<int, string|array{int, string, int}>`) を明示的に narrowing
- [x] `methodBody()` は `?string` を返し、呼び出し側で null 分岐

---

## 施策 4: inventory 本体 `DirectFetchInventory`

### 変更箇所

新規 `tests/Support/Security/DirectFetchInventory.php`

### 設計

`NestedRouteDefenseInventory` と同じく **静的クラス**に置く
(Pest のファイル読み込み順に依存する global 関数にしない)。

```php
final class DirectFetchInventory
{
    /** 走査対象。 */
    public static function scannedPaths(): array   // ['app', 'routes']

    /**
     * 候補 key => 裁定エントリ。
     *
     * @return array<string, DirectFetchJustificationEntry>
     */
    public static function inventory(): array;
}
```

### 初期 inventory (実装時に走査器を流して確定する)

概念設計 §2-3 の実測では **33 件** (旧 syntactic フィルタ)。
型証明を要求する新フィルタでは増えるため、見積り **33〜40 件**。
群ごとの登録方針は次のとおり (代表例):

```php
// queue payload 再水和 (app/Jobs/** の 8 件前後)
'Jobs/Manual/RunManualRender.php#handle#1' => DirectFetchJustificationEntry::queuePayload(
    'render job id は RenderJobService::trigger() が採番し dispatch 時に確定させた値で、'
    .'HTTP 入力を経由しない。worker 側は再水和のみ行う',
    enqueuedBy: 'App\Services\Manual\RenderJobService::trigger',
),

// token / actor 由来 (8 件前後)
'Http/Middleware/ResolveApiActor.php#resolveOrganization#1' => DirectFetchJustificationEntry::authenticatedActor(
    'organization id は Passport の access token レコードに紐づく claim であり、'
    .'request payload からは受け取らない (resolve.api-actor が token を検証済み)',
    actorSource: 'passport_token_record',
),

// 同一クエリ内で所有者スコープ
'Http/Routing/SelfScopedPasskeyBinder.php#resolve#1' => DirectFetchJustificationEntry::ownerScopedQuery(
    '所有者スコープの where を解決クエリ自体に含めている (取得後に弾くと 403/404 差で存在が漏れるため)。'
    .'relation を使わないのは PasskeyUser interface が vendor 型で解決するため',
),

// テナントスコープ済みクエリで確定した id
'Services/Project/DefaultProjectResolver.php#resolveForUpdate#1' => DirectFetchJustificationEntry::idFromTenantScopedQuery(
    'id は直前の $organization->projects() で組織スコープ済み。HasManyThrough に lockForUpdate を'
    .'掛けると JOIN 先までロックするため単一テーブルの主キーロックに落としている',
),

// local 限定
'Http/Controllers/DebugLoginController.php#login#1' => DirectFetchJustificationEntry::localOnly(
    'local 専用のデバッグログイン。route 登録自体が isLocal/runningUnitTests 限定で、'
    .'production では route が存在しない',
    routeName: 'debug.login-as',
),

// 運用コマンド
'Console/Commands/ResetAdminMfaCommand.php#handle#1' => DirectFetchJustificationEntry::operatorConsole(
    '運用者が CLI で admin を名指しして MFA をリセットする保守コマンド。'
    .'HTTP から到達不能で scheduler / queue からも呼ばれない',
    commandSignature: 'admin:reset-mfa {id}',
),

// ★債務 (新規コードで使わない。既存 2 件のみ)
'Http/Controllers/Organizations/OrganizationOwnershipController.php#store#1'
    => DirectFetchJustificationEntry::globalExistenceRuleDebt(
        'payload の user_id を組織スコープ外で引いている。移譲先が組織メンバーであることの検証は'
        .'Service のロック下で行われるが、fetch 時点ではスコープが閉じていない',
        verifiedBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
        validationRule: 'exists:users,id',
        todoRef: '<TODO 登録後に採番>',
    ),
```

> **`todoRef`**: 後続 TODO (概念設計 §7-1) を先に `app-todo-add` で起票し、その ID を書く。
> 実装時点で未起票なら、実装 PR 内で TODO を起票してから ID を埋める
> (プレースホルダのままコミットしない — 債務が追跡不能になる)。

---

## 施策 5: gate 本体 `ModelDirectFetchInvariantTest`

### 変更箇所

新規 `tests/Architecture/ModelDirectFetchInvariantTest.php`

### テスト一覧 (Pest)

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | 全候補が inventory に明示分類されている (未知は fail) | `app/**` + `routes/*.php` を走査し、候補 key が inventory に無ければ fail |
| 2 | inventory の key は現存候補 (stale 検出) | inventory にあって候補に無い key を fail (双方向整合) |
| 3 | `OwnerScopedQueryConstraint` の機械副条件 | 同一 chain の所有者制約 + 右辺 provenance を照合 |
| 4 | `IdDerivedFromTenantScopedQuery` の機械副条件 | identity 変数が relation 起点クエリから代入されている |
| 5 | `AuthenticatedActorScope` の機械副条件 | 同一メソッドに request accessor が無い + `actorSource` が既定値集合 |
| 6 | `QueuePayloadRehydration` の機械副条件 | `app/Jobs/**` 配下 + identity が `$this->{…Id}` + `enqueuedBy` あり |
| 7 | `LocalOnlyDiagnostics` の機械副条件 | `routeName` の route が現存し `LocalOnly` middleware を持つ (route 走査と照合) |
| 8 | `OperatorInvokedConsoleCommand` の機械副条件 | `app/Console/Commands/` 配下 + `commandSignature` あり |
| 9 | **債務 case の機械副条件** | `verifiedBy` の `Class::method` が実在し、**当該メソッド本文**に membership/tenant marker がある。かつ**呼び出し側が exact method を呼んでいる** |
| 10 | 債務 case の増殖防止 | `PayloadIdWithGlobalExistenceRuleDebt` の件数が **2 以下**であること |
| 11 | 範囲外経路の 0 件固定 | `whereRaw('id` / `whereIntegerInRaw('id'` が `app/` + `routes/` に 0 件 |
| 12 | **degenerate PASS 防止** | 走査器が現行コードベースから**候補を 1 件以上検出する**こと |

### テスト 10 (債務の増殖防止) の意図

`PayloadIdWithGlobalExistenceRuleDebt` は green にできてしまう case なので、
**放置が常態化するリスク**がある (Codex Round 3 Warning)。
件数に上限 2 を置くことで、3 件目を足そうとした瞬間に CI が落ち、
「debt を増やす」判断がレビューの俎上に必ず乗る。

### テスト 12 (degenerate PASS 防止) の意図

`ScenarioWritePathInventoryTest` の同名テストと同じ思想。
走査器が壊れて**何も検出しなくなった**とき、テスト 1/2 は両方 green になり
**gate が静かに無力化する**。「現行コードベースに候補が実在すること」を固定して防ぐ。

### 実装スケッチ (テスト 1)

```php
test('クラス起点の主キー同一性クエリが全て inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = DirectFetchInventory::inventory();
    $violations = [];

    foreach (DirectFetchInventory::candidates() as $candidate) {
        if (! array_key_exists($candidate->key, $inventory)) {
            $violations[] = $candidate->key.' ('.$candidate->identityArgument.' で引いている)';
        }
    }

    expect($violations)->toBe([],
        'テナントスコープ外で id からモデルを引いている箇所があります。'
        .'まず relation 起点 ($organization->users()->whereKey(...)) に直せないか検討し、'
        .'直せない場合のみ DirectFetchInventory へ DirectFetchJustification + 具体的根拠を登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

失敗メッセージは**「例外に足せ」ではなく「まず直せ」を先に言う**
(`NestedRouteIdorDefenseTest` の失敗メッセージと同じ作法)。

### テスト計画チェック

- [x] 個別の `DatabaseTransactions` を使っていない (DB を触らない)
- [x] Factory 不要 (静的走査 + route 走査のみ)
- [x] 既存テストの削除・上書きなし

---

## 施策 6: 走査器の Unit テスト

### 変更箇所

新規 `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php`
(既存 `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php` と同じ場所・同じ流儀)

### positive fixture (**検出されなければならない** — 概念設計 §8-2)

| # | fixture | 塞ぐ抜け道 |
|---|---|---|
| 1 | `User::query()->where('id', $payloadId)->firstOrFail()` | 述語アンカー |
| 2 | `$q = User::query(); $q->where('id', $payloadId)->first()` | builder alias |
| 3 | Service メソッドが scalar `$userId` を受け `User::findOrFail($userId)` | Service 委譲 |
| 4 | `User::query()->where('users.id', $id)` | qualified 列 |
| 5 | `User::query()->where(['id' => $id])` | array 形 |
| 6 | `User::destroy($id)` | destroy |
| 7 | `DB::table('users')->where('id', $payloadId)` | DB::table |
| 8 | `\App\Models\User::query()->whereKey($id)` | FQCN 起点 |
| 9 | `DB::table('users as u')->where('u.id', $id)` | alias 付き qualified |
| 10 | `User::whereId($id)` / `User::query()->where('id', '=', $id)` / `User::query()->whereIn('users.id', $ids)` | 文法バリエーション |
| 11 | `(new User())->newQuery()->whereKey($id)` | `new` 起点 |
| 12 | **`User::query()->whereKey($dto->user_id)`** (`$dto` の型が証明できない) | **provenance フィルタの誤除外** |

### negative fixture (**検出してはならない**)

| fixture | 理由 |
|---|---|
| `$organization->users()->whereKey($id)` | relation 起点 |
| 型付き引数 `Project $project` の `Project::whereKey($project->id)->lockForUpdate()` | provenance 証明あり |
| `$manual->renderJobs()->where('id', '>', $cursor)` | 順序比較 (主キー同一性でない) |
| `Plan::query()->where('code', $code)` | 主キーでない |
| **docblock 中の `Foo::destroy()`** | コメント除去 (実在の誤検出例) |
| `$q = User::query(); $q = $other; $q->whereKey($id)` | alias invalidation |
| `SomeOtherPackage\User::find($id)` (Models 集合に無い) | import 裏取り |

### `outOfScope_*` fixture (「保証」ではなく「既知の範囲外」)

| fixture |
|---|
| `User::query()->whereRaw('id = ?', [$id])` |
| `User::query()->where($col, $id)` (動的列名) |

> 名前を `outOfScope_` 接頭辞にすることで、「検出しないことを保証している」と
> 誤読されないようにする (Codex Round 3 Warning)。範囲外の実コード出現は
> 施策 5 のテスト 11 が 0 件 assertion で検知する。

---

## 施策 7: 規約ドキュメントへの gate 名登録

### 変更箇所

| ファイル | 変更内容 |
|---|---|
| `AGENTS.md` セキュリティ不変条件 **3** | 末尾に `(ModelDirectFetchInvariantTest)` を追記 |
| `docs/app-integration-guide.md` §7 不変条件 **3** | 同上 + 「新規 route を足すときのチェックリスト」に 1 行追加 |
| `docs/architecture.md` L38 付近 | 既存の gate 列挙 (`ProjectRouteCurrentOrgGuardTest / NestedRouteIdorDefenseTest`) に併記 |

### 重要: 番号を振り直さない

AGENTS.md の注意書きどおり、**AGENTS.md §セキュリティ不変条件の番号と
`docs/app-integration-guide.md` §7 の番号は 1:1 対応しない**。
本施策は不変条件 3 (両者とも「cross-org 不可」) に**追記するだけ**で、
**どちらの側も renumber しない** (既存の相互参照が壊れるため)。

### 波及変更

- `docs/TODO.md`: **触らない** (登録は `app-todo-add` スキルの責務)
- `docs/template-divergence.md`: 変更不要 (テンプレートからの逸脱ではなく、テンプレート t1 への**追従**)

---

## 段階分け

### このタスクでやる

施策 1〜7 すべて。分割すると「enum だけあって gate が無い」中途半端な状態が main に入るため、
**1 コミット単位 (1 worktree) で完結させる**。

### 後続 TODO 候補 (このタスクではやらない)

1. **payload 由来 `user_id` 2 箇所の org 相対化 + `exists:users,id` の見直し**
   — 振る舞い変更 (403/404/422 の変化) を伴うため別 TODO。
   本タスクの `todoRef` field がこの TODO を指すので、**本タスクの実装前に起票**しておく。
2. **`whereRaw` / 動的列名の検出** — 現状 0 件のため作らない (施策 5 テスト 11 が見張る)。
3. **c2c 台帳への `status_reported` 書き戻し** — main マージ + push 後。
   `refs` は `aicue@<commit>` 形式が必須。

---

## リスク

| リスク | 影響 | 緩和 |
|---|---|---|
| **走査器の誤検出で無関係な箇所が候補化** | 実装者が意味の無い分類を強いられ inventory が形骸化 | import 裏取り + コメント除去 + 等価/IN 限定。施策 6 の negative fixture で固定 |
| **走査器の検出漏れで gate が静かに無力化** | 最悪の失敗モード | 施策 5 テスト 12 (degenerate PASS 防止) + 施策 6 の positive fixture 12 種 |
| **初期 inventory が想定より大幅に多い** | 実装コストが膨らむ | 見積り 33〜40。**50 件を大きく超えたら分類粒度を再検討**し、設計に戻ること (実装者への申し送り) |
| **provenance 証明器の実装が過度に複雑化** | 実装が終わらない | 証明手段を「型付き引数のみ」に絞る後退が可能。**fail-closed 側への後退なので安全** (候補が増えるだけ) |
| 債務 case の放置 | cross-org 存在オラクルが残り続ける | `todoRef` 必須 + 件数上限 2 (施策 5 テスト 10) |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規ファイル 6 本 + docs 追記のみで、既存コードの変更が無い。他 TODO と競合するのは `AGENTS.md` / `docs/app-integration-guide.md` の追記 3 行だけで、conflict しても解決は自明 |
| 競合リスク | 低。ただし**他タスクが `app/` に新しい直 fetch を足すと本タスクの inventory が不足して fail する**。main マージ時に走査器を再実行して差分を取り込むこと |


---

## 参考: 概念設計 (確定版)

# 概念設計: ModelDirectFetchInvariantTest (直 find 禁止 gate) の追従

- c2c feature id: `nested-route-idor-defense`
- 裁定: 【2026-08-04 AG-005】正典 t1 = aicue の total inventory + cross-org guard + **t0 の ModelDirectFetchInvariantTest**。
  aicue は total inventory 部を正典 origin へ昇格させた側だが、**t1 に含まれる ModelDirectFetchInvariantTest が不在のため追従要**。
- 本設計のスコープ: 「aicue でこの gate をどう実装するか」だけ。裁定そのものは与件として蒸し返さない。
- 改訂履歴: Round 1 で検出アンカーを述語側へ移動 / **Round 2 で母集団の絞り方を根本的に作り直した** (§4-1) /
  Round 3 で **provenance フィルタに型証明を要求**するよう修正 (§4-2(c))。
  Codex レビューは規定上限の 3 ラウンドを消化済み。残存リスクは
  `codex-history/conceptual-review-decisions-round-3.md` の末尾に記録。

---

## 1. 仮説

**仮説**: この gate は `NestedRouteIdorDefenseTest` の重複ではない。両者が守る母集団は**素で交わらない**。

| gate | id の出所 | 現状 |
|---|---|---|
| `NestedRouteIdorDefenseTest` | **route parameter** (`/projects/{project}/manuals/{manual}`) | 実装済み (1+param の total inventory) |
| `ModelDirectFetchInvariantTest` | **route parameter 以外**の id (POST payload / query / MCP tool 引数 / token claim / queue payload) | **不在** |

`NestedRouteDefenseInventory::candidates()` の母集団は `parameterNames() !== []` の named route である。
`POST /organizations/{organization}/transfer-ownership` の `user_id` のように **body で id を受け取る**経路は、
route parameter を 1 つも増やさないため **inventory に何も現れない**。したがって
「payload の id をテナントに閉じない global クエリでモデル化する」経路は、現状**どの Architecture テストにも捕捉されない**。

**成功条件**: 新しく「テナント/所有者スコープ外のクエリで id からモデルを取得するコード」を書いたとき、
**レビューを通り抜けても CI が落ちる**こと。かつ既存の正当な経路を分類するコストが実装者にとって現実的であること。

成功条件は「**書き方を問わず**落ちる」でなければ意味がない。`Model::find()` だけを禁じても
`Model::query()->where('id', $payloadId)->firstOrFail()` や
`$q = User::query(); $q->where('id', $payloadId)` で等価なことができる。
よって検出規則はメソッド名の列挙ではなく**「静的起点 + 主キー同一性」という意味**に対して張る (§4-2)。

---

## 2. 現状 (実査結果)

ブリーフ・台帳の記述を鵜呑みにせず、実コードを数えた結果。

### 2-1. gate の不在は事実

`tests/Architecture/ModelDirectFetchInvariantTest.php` は存在しない。
`rg 'ModelDirectFetch' .` のヒットは **devnotes と過去監査メモの 4 件のみ**で、実装・テスト・docs には無い。

### 2-2. 過去に「入れない」と判断した記録がある

`devnotes/20260802-1548-aigenba-alignment-audit/audit.md` L163-166:

> **注意**: `ModelDirectFetchInvariantTest` / … は**思想は汎用だが inventory がドメイン固有**。
> AI-CUE には既に等価物がある (`NestedRouteIdorDefenseTest` / …)。**重複導入しない**。

**この判断は §1 の実査で否定される**。`NestedRouteIdorDefenseTest` は route parameter しか見ておらず、
payload / queue payload / token claim 由来 id の global fetch を 1 件も検査していない。
2026-08-04 の c2c 裁定はこの局所判断を上書きしており、本設計は**裁定側に従う**。

### 2-3. ノイズは「ディレクトリの形」ではなく「id の出所の形」をしている ★本設計の中核

素朴に「`app/` 全体で直 fetch 禁止」とすると分類対象が 100 件超になり、inventory が形骸化する。
しかし実際に数えると、その大半は**同じ 1 つの理由**で本 gate の関心外だった。

| | 件数 |
|---|---|
| `app/` 全体の static-rooted 主キー同一性クエリ | **70** |
| うち識別子引数が `$model->getKey()` / `$model->id` / `$model->{fk}_id` = **解決済みモデル由来** | **37** |
| **分類が必要な候補** (旧 syntactic フィルタでの実測) | **33** |

> §4-2(c) で provenance に**型証明**を要求する修正を入れたため、実際の候補数はこれより増える
> (見積り **33〜40**)。正確な初期 inventory は実装者が走査器を流して確定する。

除外される 37 件の実体は `Project::whereKey($project->id)->lockForUpdate()->firstOrFail()` 型、
すなわち**既にテナント検証済みのモデルを同一 tx 内で行ロック再取得する**形である。

**この 37 件を除外するのが正当な理由**: 識別子が解決済みモデル由来なら、
**その元モデルの解決自体が候補として別途検査される**。provenance は候補へ遡及するので取りこぼしにならない。
`$project` が正しく解決されているなら `$project->id` も正しく、`$project` の解決が怪しいなら
**`$project` を作った行が候補として捕まる**。

> **Round 2 の設計転換**: 初期案はこのノイズを避けるために母集団を entrypoint 層 (`app/Http` + `app/Mcp`) に
> 絞っていた。しかしそれは「Controller が scalar id を Service に渡し Service 側で global fetch する」
> という明白な抜け道を生む。フィルタを**ディレクトリではなく識別子引数の出所**に掛け直したところ、
> **母集団を `app/` 全体に広げても候補は 33 件**に収まることが分かった。
> 母集団を絞る理由が消えたので絞るのをやめた。

### 2-4. 候補の内訳 (全件読んだ結果)

| 群 | 件数 | 実体 | 代表 |
|---|---|---|---|
| queue payload 再水和 | 8 | `Model::query()->find($this->xxxId)` — id は enqueue 時にサーバが確定 | `Jobs/Manual/RunManualRender.php` |
| token / actor 由来 | 8 | Passport grant・`ResolveApiActor`・`McpAuthorizationContext`・`RevokeSessionController` | `Http/Middleware/ResolveApiActor.php` |
| 同一クエリ内で所有者スコープ | 1 | `Passkey::query()->whereKey($id)->where('user_id', $user->getKey())` (意図的設計) | `Http/Routing/SelfScopedPasskeyBinder.php` |
| テナントスコープ済みクエリで確定した id | 〜9 | `$id = $organization->projects()->value('id')` の直後に `Project::whereKey($id)->lockForUpdate()` | `Services/Project/DefaultProjectResolver.php` |
| **payload 由来 id の global fetch** | **2** | **`User::query()->findOrFail((int) $request->input('user_id'))`** | `OrganizationOwnershipController` / `ProjectMemberController` |
| request 由来だが membership 検証が直後にある | 1 | `Organization::query()->find($orgId)` → `$user->organizations()->whereKey()->exists()` | `Http/Middleware/McpConsentOrganizationBinder.php` |
| local 限定 | 1 | route 登録自体が local + `LocalOnly` middleware | `Http/Controllers/DebugLoginController.php` |
| 運用コマンド | 1 | 対話的 admin MFA リセット | `Console/Commands/ResetAdminMfaCommand.php` |

**本当に注視すべきは 2 件**である。この 2 件は request payload の `user_id` を
**テナントに一切関係しない global クエリ**でモデル化しており、両者とも
`'user_id' => ['required', 'integer', 'exists:users,id']` という**グローバルなユーザー存在検証**を伴う。

MCP tool (`ShowProjectTool` / `ListItemsTool`) は `$ctx->organization->projects()->whereKey($projectId)` と
**relation 起点**で書かれており候補にすら上がらない。この層は既に正しい。

### 2-5. `routes/*.php` は候補 0 件 (だが母集団に入れる)

route closure は 29 個あるが、model / 主キーアクセスは **0 件** (全て middleware / grouping)。
「route closure に業務ロジックを書けない」ことを保証する既存 gate は本リポジトリに**存在しない**ため、
`User::find($request->input('user_id'))` を closure に書けば素通りする。
**コスト 0 で穴が 1 つ閉じる**ので母集団に含める。

### 2-6. 規約は文章としては既に存在する (機械強制だけが無い)

`docs/app-integration-guide.md` §7 不変条件 3 / AGENTS.md セキュリティ不変条件 3:

> **cross-org 不可**: いかなる経路でも組織を跨いだ read/write が起きない
> (Service 層 + DB CHECK の多層。**直 fetch せず relation/Builder スコープ経由**)

**「直 fetch せず」は既に宣言済みでありながら、対応する Architecture テストが無い唯一の不変条件**である
(不変条件 1/2/5/8/9 はすべて対応 gate を持つ)。AGENTS.md 禁止事項 1 に照らすと不変条件 3 は未完了である。

---

## 3. 課題

1. **id → global モデル化**の経路が機械検出されない。実害のある 2 件が
   「安全である理由」をコードコメントにしか持たず、レビュアーの注意力に依存している。
2. 新しい payload id 受け口を後から足したとき、**relation 起点で書かなかったことに誰も気付けない**。
   route を増やさないため `NestedRouteIdorDefenseTest` も `TenantBoundaryOrderingTest` も沈黙する。
3. 逆に `SelfScopedPasskeyBinder` / `MembershipScopedOrganizationBinder` のように
   **静的起点だが同一クエリでスコープを閉じている正しい実装**が存在するため、
   「`Model::` 起点を一律禁止」という素朴な規則は使えない。分類が要る。

---

## 4. 方針

**deny-by-default の inventory 型 Architecture テストを 1 本追加する。**
本リポジトリに既にある同型の gate (`ControllerAuthorizationGateTest` + `ControllerAuthorizationExemption` enum、
`ScenarioWritePathInventoryTest` の token 走査、`AuthorizationMarkerScanner` の分離) の作法を踏襲する。

### 4-1. 母集団

```
app/**/*.php        routes/*.php
```

**全層**。層で絞らない (§2-3)。ノイズは §4-2 の provenance フィルタで落とす。

### 4-2. 候補の定義

候補 = 次の 3 条件をすべて満たす式。

**(a) 静的起点である**

- `User::…` / `self::…` / `static::…` — ただし**そのクラス名が `App\Models\*` に解決できる場合に限る**。
  解決経路は 3 つとも対応する: (i) ファイルの `use` import、(ii) FQCN 直書き (`\App\Models\User::…`)、
  (iii) 同一 namespace 参照 (`app/Models/` 配下のファイル内)
- `new App\Models\*` 起点 (`(new User)->newQuery()->whereKey($id)` / `(new User)->query()->…`)
- `DB::table('users')->…` / `DB::table('users as u')->…` / `DB::connection(…)->table(…)->…`

> 内部概念名を「静的起点」ではなく **`ClassRootedPrimaryKeyQuery`** とするのは、
> `new` 起点を含むためである (Round 3 Warning 対応。`new` 起点は書く頻度こそ低いが
> gate の回避としては最も簡単な部類なので対象に含める)。

> `use` import による裏取りは `AuthorizationMarkerScanner::importsGateFacade()` と同じ作法。
> これが無いと同名の別クラスで誤検出する。実際、素朴な正規表現による事前調査では
> `LogoutResponse` の**docblock 中の `AuthenticatedSessionController::destroy()`** を誤検出した
> (トークン段でのコメント除去 + import 裏取りの両方が要ることの実例)。

**(b) 主キー同一性述語を含む**

| 対応する構文 | 例 |
|---|---|
| find 系 | `find(` / `findOrFail(` / `findOrNew(` / `findMany(` / `destroy(` |
| key 述語 | `whereKey(` / `whereKeyNot(` |
| 列指定 (**等価・IN のみ**) | `where('id', $x)` / `where('id', '=', $x)` / `whereIn('id', $xs)` / `firstWhere('id', $x)` |
| qualified 列 | `where('users.id', $x)` / `where($m->getQualifiedKeyName(), $x)` / `where($m->getKeyName(), $x)` |
| magic where | `whereId(` |
| array 形 | `where(['id' => $x])` / `where([['id', '=', $x]])` |

- **等価・IN に限る**。`where('id', '>', $cursor)` は主キー同一性ではなく順序比較であり候補にしない
  (`ManualRenderController:122` に実在する正当なカーソル処理を誤検出しないため)。
- **非対応 (範囲外)**: `whereRaw` / `whereIntegerInRaw` / 動的列名 (`where($col, $x)`)。
  これらは fixture 名を `outOfScope_*` とし、「検出しないことを**保証**する」ではなく
  「**既知の範囲外**である」と読める形で固定する。
  加えて、範囲外を放置しないために **`whereRaw('id` / `whereIntegerInRaw('id'` が
  app 全体で 0 件であることを本テスト内の別 assertion で固定する** (現状 0 件。
  出現した時点で fail し、範囲外の経路が実際に生えたことを検知できる)。

**(c) 識別子引数が「保証済み provenance」でない**

`$model->getKey()` / `$model->id` / `$model->{fk}_id` の**形をしている**だけでは除外しない。
`$dto->user_id` / `$payload->project_id` / `$requestData->organization_id` はトークン上まったく同じ形であり、
形だけで除外すると **payload object 由来 id の global fetch が静かに消える**。

除外は「**変数が Eloquent モデルであると証明できる場合**」に限る。証明手段は次の 3 つだけ:

1. **型付き引数**が `App\Models\*` (`public function foo(Project $project)`)
2. **PHPDoc で明示**された `App\Models\*` (`/** @var Project $locked */`)
3. **同一メソッド内で** relation 起点クエリ (`$x->rel()->…`) または本 gate の候補式から代入された変数

証明できなければ**候補に残す** (fail-closed)。

> **除外の正当性が成り立つ条件** (Round 3 Critical 対応):
> 「識別子が解決済みモデル由来なら、その元モデルの解決自体が候補として捕まる」という遡及は
> **無条件には成立しない**。元モデルが `where('uuid', $requestUuid)` / `where('slug', …)` /
> 外部 DTO / 手動 `new Model([...])` で解決されていれば、主キー同一性の候補には現れない。
>
> したがって除外してよいのは、元モデルが**別の保証済み provenance に属する**場合に限る:
>
> | 保証済み provenance | 誰が保証するか |
> |---|---|
> | route binding で解決された model | `NestedRouteIdorDefenseTest` + `TenantBoundaryOrderingTest` |
> | `{project}` の org 帰属 | `ProjectRouteCurrentOrgGuardTest` (aicue:D4 middleware) |
> | relation 起点クエリの結果 | 構造的にテナントに閉じている |
> | 本 gate の候補として分類済みの式の結果 | 本 gate 自身 |
>
> **上記のいずれでもない model-derived 引数は除外しない**。この条件を走査器の docblock と
> テストの失敗メッセージに明記し、「モデルっぽい形」で逃げられないようにする。

**この変更により候補数は §2-3 の 33 件より増える** (型証明できない `$x->{fk}_id` が候補に戻るため)。
実測ベースの見積りは **33〜40 件**。正確な初期 inventory は実装者が走査器を実際に流して確定する。

### 4-3. builder alias の追跡

`$q = User::query();` `$q->where('id', $payloadId)->firstOrFail();` は (a) の「同一 chain」を満たさない。
これを許すと規則が空洞化するため、**同一メソッド内に限った保守的な alias 追跡**を行う:

- `$var = <静的起点式>` の**単純代入**のみ静的起点として伝播する
- `$var` への**再代入**で追跡を打ち切る (invalidate)
- 引数渡し・プロパティ代入・条件分岐をまたぐ伝播は**追跡しない** (限界として明記)

完全なデータフロー解析はしない。「単純代入で逃げる」という最も安易な回避だけを塞ぐ。

### 4-4. 分類 (deny-by-default)

全候補は `App\Enums\Security\DirectFetchJustification` の case + **30 文字以上の具体的根拠**を
対で登録しなければ fail する。未登録は fail。登録があるのに実コードに無い (stale) 場合も fail する。

**根拠文の文字数だけでは case は守れない**ため、case ごとに**機械副条件**を課す:

| case | 適用条件 | 機械副条件 |
|---|---|---|
| `OwnerScopedQueryConstraint` | **同一クエリ内**に所有者/テナント制約があり、取得後に弾いていない | (a) 同一 chain に**許可 signature が列挙一致**: `where('organization_id'\|'user_id'\|'team_id'\|'project_id', …)` / `whereHas('users'\|'organizations'\|'projects', …)` / `whereBelongsTo($user\|$organization)` (`where('active', true)` では通らない)。(b) **その右辺が §4-2(c) の provenance 証明を満たすこと** (`where('organization_id', $requestOrgId)` では通らない) |
| `IdDerivedFromTenantScopedQuery` | 識別子が**同一メソッド内のテナントスコープ済みクエリ**で確定している | 同一メソッド本文で当該変数への代入式が relation 起点 (`$x->rel()->…`) であること |
| `AuthenticatedActorScope` | id が**認証済み actor / 検証済み token claim** 由来 | (a) 同一メソッド内に request accessor が**存在しない** (negative check)、(b) 構造化 field `actorSource` (`authenticated_user` / `validated_token_claim` / `passport_token_record`) を必須 |
| `QueuePayloadRehydration` | id が **enqueue 時にサーバが確定した job property** 由来 | (a) ファイルが `app/Jobs/**` 配下、(b) 識別子引数が `$this->{名前が Id で終わる property}`、(c) 構造化 field `enqueuedBy` に **dispatch 元の `Class::method`** を必須 |
| `LocalOnlyDiagnostics` | route 登録自体が local 限定で production から到達不能 | 構造化 field `routeName` を必須とし、**route 走査で当該 route に `LocalOnly` middleware が付いている**ことを照合する (ファイル内文字列一致では弱いため) |
| `OperatorInvokedConsoleCommand` | 人間の運用者が CLI で明示実行する。HTTP から到達不能 | (a) ファイルが `app/Console/Commands/` 配下、(b) 構造化 field `commandSignature` を必須 (scheduler / queue から呼ばれる command と区別するため、根拠文に呼び出し主体を書かせる) |
| **`PayloadIdWithGlobalExistenceRuleDebt`** | **payload 由来 id を global に引いており、補償チェックは fetch の後段にある = 準拠形ではなく債務** | (a) 構造化 field `verifiedBy` に検証を行う `Class::method` を必須、(b) **呼び出し側がその exact method を呼んでいる**こと、(c) **当該メソッド本文** (クラス全体ではない) に membership/tenant marker があること、(d) 構造化 field `validationRule` (例 `exists:users,id`) と **`todoRef` (後続 TODO の ID) を必須** |

> **`PayloadIdWithGlobalExistenceRuleDebt` を準拠 case と分けた理由**: §2-4 の 2 件は
> 「fetch **後**に補償する」形であり、「fetch **時点で**スコープが閉じている」他 case と
> 安全性の質が違う。同列に並べると「補償チェックがあれば OK」という運用に流れる。
> **debt であることを case 名で可視化**し、後続 TODO (§7-1) の入口にする。

> **`AuthenticatedActorScope` は完全な機械証明ができない**。「id の出所が認証済み actor か」は
> データフロー解析であり token 走査の範囲外である。negative check と構造化 field で
> 濫用を抑えるが、**最終的には人手の根拠文に依存する**と明示的に記録する
> (限界を曖昧にしないことが deny-by-default 運用の前提)。
>
> **`QueuePayloadRehydration` を `AuthenticatedActorScope` から分けた理由** (Round 3 Warning 対応):
> actor/token と queue payload は**信頼境界が違う**。前者は「リクエストごとに検証される認証情報」、
> 後者は「過去のリクエストが確定してシリアライズした値」であり、
> dispatch 元が間違っていれば queue payload は汚染されうる。同じ case に混ぜると
> 「job だから安全」という誤った一般化を生むため、`enqueuedBy` を名指しさせる別 case にする。

### 4-5. 走査器は独立させ、走査器自体をテストする

`AuthorizationMarkerScanner` と同じ思想。正規表現ではなく `token_get_all` の状態機械にし、
**コメント / docblock / 文字列リテラル中の出現を除去する** (§4-2 の誤検出実例)。
走査器の positive/negative は `tests/Unit/Architecture/` の専用テストで恒久固定する
(gate 自体がセキュリティ機構であり、走査器が壊れたら gate は静かに無力化するため)。

内部概念名は **`PrimaryKeyConstrainedStaticQuery`**、走査器は `PrimaryKeyStaticQueryScanner` とする
(テストクラス名 `ModelDirectFetchInvariantTest` は c2c 台帳上の gate 識別子なので変えない。
両者の関係は docblock に書く)。

### 4-6. 本 gate が保証しないこと (主張範囲)

「不変条件 3 を全面的に機械強制する」とは**主張しない**。本 gate が保証するのは
「**静的起点 + 主キー同一性によるモデル取得**」という具体的経路が漏れなく分類されていることだけである。
relation/org-scoped 解決の一般的強制、到達可能性、`whereRaw` 等の動的クエリ、
`exists:` validation rule による存在漏れは**範囲外**であり、範囲外であることをテストの docblock に書く。

---

## 5. 代替案と却下理由

| # | 案 | 却下理由 |
|---|---|---|
| A | **allowlist 方式** (ファイル単位で「このファイルは直 fetch してよい」) | ファイル単位 allowlist は**そのファイル内の新しい違反を丸ごと免除**する。`ScenarioWritePathInventoryTest` が検出 3/5 でわざわざ「宣言元も呼び出し元も名指しする」形にしているのと同じ理由。候補単位 + 根拠 + 機械副条件にする |
| B | **母集団を entrypoint 層に絞る** (Round 1-2 の旧案) | Controller が scalar id を Service に渡し Service 側で global fetch すると沈黙する。§2-3 の実測により**絞らなくても 33 件**と分かったため、絞る理由が消えた |
| C | **gate を入れず、該当 2 件をリファクタして終わり** | 「今あるものを直す」だけで**将来の混入を止めない**。裁定の要求 (gate の追従) を満たさない |
| D | **PHPStan のカスタムルールで実装** | 本リポジトリにカスタムルール基盤が無く extension 登録が要る。既存の不変条件はすべて `tests/Architecture/` (60 本超) に集約されており、置き場所を割るとレビュー時に発見されない |
| E | **nikic/php-parser で AST 解析** | 直接依存ではなく推移依存。既存走査器 (`ScenarioWritePathScanner` / `AuthorizationMarkerScanner`) は全て `token_get_all` 流儀で、ここだけ流儀を割る利得が無い |
| F | **route parameter も本 gate の母集団に含めて一本化** | `NestedRouteIdorDefenseTest` と母集団が重なり同じ経路を 2 か所に登録させる。思考原則 4。route param 側は既に total inventory 済み |
| G | **`Model::` 起点を一律禁止 (分類なし)** | `SelfScopedPasskeyBinder` は **static 起点であることが正しい設計** (relation は vendor 型で解決されるため `App\Models\Passkey` 型を返せない、という明示コメントがある)。一律禁止は正しい実装を壊す |

---

## 6. スコープに入れないもの (と理由)

1. **該当 2 件 (`OrganizationOwnershipController` / `ProjectMemberController`) の実装リファクタ**
   — 本タスクの目的は「機械検出を入れる」こと。`PayloadIdWithGlobalExistenceRuleDebt` として
   **根拠付きで可視化するところまで**で止め、振る舞いを変えない。
   `exists:users,id` の見直しとセットでないと存在オラクルは閉じず、403/404/422 の変化を伴うため
   単独 TODO として切り出すべき別課題である (§7-1)。
2. **`exists:` validation rule による存在漏れ一般の統制** — 攻撃面は隣接するが機構が別
   (validation rule の検査は route/FormRequest 側の話)。§4-6 のとおり本 gate の主張範囲外。
   ただし該当 2 件は `PayloadIdWithGlobalExistenceRuleDebt` の `validationRule` field に必ず現れる。
3. **route closure から helper `request('user_id')` を経て raw SQL へ至る経路**
   — `DB::select` / `whereRaw` は §4-2(b) の非対応構文であり本 gate の範囲外。
   現状 routes に該当 0 件であり、`whereRaw('id` の 0 件 assertion (§4-2) が生えたときに気付く。
4. **`NestedRouteIdorDefenseTest` / `NestedRouteDefenseInventory` への変更**
   — 正典 t1 の total inventory 部は aicue が origin 側であり既に要件を満たしている。触らない。
5. **cross-org 存在オラクル封じ middleware (aicue:D4 / `EnsureProjectBelongsToRouteOrganization`)**
   — t1 の構成要素だが aicue には既に実装済み (`ProjectRouteCurrentOrgGuardTest` が固定)。追従不要。
6. **`app/Filament/**` の Filament リソース** — 母集団には入るが、admin パネルは
   `/admin` 配下で別の認可体系。候補が出たら通常どおり分類する (特別扱いしない = 除外もしない)。
7. **c2c 台帳への `status_reported` 書き戻し** — 実装が main にマージされ commit が push された後の作業。
8. **frontend の変更** — 一切無い。Svelte / DS token / Inertia props に波及しない。

---

## 7. 後続 TODO 候補 (本タスクでは実施しない)

1. **payload 由来 `user_id` 2 箇所の org 相対化 + `exists:users,id` の見直し**
   — `User::query()->findOrFail($userId)` を `$organization->users()->whereKey($userId)->firstOrFail()` へ。
   **fetch 側だけ直しても validation が同じ情報を漏らす**ためセットで扱う。
   振る舞い変更 (403 → 404 等) を伴うため別 TODO。本 gate 導入後は当該 2 箇所が
   `PayloadIdWithGlobalExistenceRuleDebt` として inventory に載るので、起票の材料が揃った状態になる。
2. **`whereRaw` / 動的列名の検出** — 現状 0 件のため作らない。出現したら再検討。
3. **template / 他リポジトリへの還流** — 「provenance フィルタで候補を 1/2 に落とす」着想は
   aigenba の allowlist 方式より運用コストが低い可能性がある。c2c 側の議題としてキュレーターに委ねる。

---

## 8. 検証方法

### 8-1. 通常の検証

| 段階 | コマンド | 期待 |
|---|---|---|
| 走査器の単体 | `composer test -- --filter=PrimaryKeyStaticQueryScanner` | positive/negative fixture が全 green |
| gate 本体 | `composer test -- --filter=ModelDirectFetchInvariant` | 初期 inventory (約 33 件) で green |
| 型 | `composer phpstan` | level 10 green |
| 整形 | `vendor/bin/pint --test` | green |
| 全体 | `composer test` | green (app/ のコードを 1 行も変えないため回帰面は無い) |

### 8-2. **抜け道 fixture が fail すること** (これが本体)

inventory が green になることは gate が効いている証明にならない。
**次の 7 種を走査器の positive fixture として持ち、すべて検出されることをテストで固定する**:

| # | fixture | 塞ぐ指摘 |
|---|---|---|
| 1 | `User::query()->where('id', $payloadId)->firstOrFail()` | Round 1 Critical (述語アンカー) |
| 2 | `$q = User::query(); $q->where('id', $payloadId)->first()` | Round 2 Critical (builder alias) |
| 3 | Service のメソッドが scalar `$userId` を受け `User::findOrFail($userId)` | Round 2 Critical (Service 委譲) |
| 4 | `User::query()->where('users.id', $id)` (qualified 列) | Round 2 Warning (文法) |
| 5 | `User::query()->where(['id' => $id])` (array 形) | Round 2 Warning (文法) |
| 6 | `User::destroy($id)` | Round 2 Warning (文法) |
| 7 | `DB::table('users')->where('id', $payloadId)` | Round 1 自己検出 |
| 8 | `\App\Models\User::query()->whereKey($id)` (FQCN 起点) | Round 3 Warning |
| 9 | `DB::table('users as u')->where('u.id', $id)` (alias 付き qualified) | Round 3 Warning |
| 10 | `User::whereId($id)` / `User::query()->where('id', '=', $id)` / `User::query()->whereIn('users.id', $ids)` | Round 3 Warning |
| 11 | `(new User())->newQuery()->whereKey($id)` (`new` 起点) | Round 3 Warning |
| 12 | **`$dto->user_id` を識別子引数に持つ global fetch** (型証明できない `->{fk}_id`) | **Round 3 Critical** |

加えて **negative fixture** (検出してはならないもの) も固定する:

- `$organization->users()->whereKey($id)` — relation 起点
- **型付き引数 `Project $project` の** `Project::whereKey($project->id)->lockForUpdate()` — provenance 証明あり
- `$manual->renderJobs()->where('id', '>', $cursor)` — 順序比較で主キー同一性でない
- `Plan::query()->where('code', $code)` — 主キーでない
- **docblock 中の `Foo::destroy()`** — コメント除去 (実在の誤検出例)
- `$q = User::query(); $q = $other; $q->whereKey($id)` — alias invalidation が効いて**検出しない**

`outOfScope_*` fixture: `User::query()->whereRaw('id = ?', [$id])` / `User::query()->where($col, $id)`。

### 8-3. deny-by-default が生きていることの確認

| 操作 | 期待 |
|---|---|
| inventory から 1 件削って再実行 | **fail** |
| 実在しない箇所を inventory に足して再実行 | **fail** (双方向整合) |
| `OwnerScopedQueryConstraint` を機械副条件を満たさない箇所に付ける | **fail** |
| `AuthenticatedActorScope` を request accessor のあるメソッドに付ける | **fail** |

---

## 9. 使命との整合

AI-CUE は SOP / 動画マニュアルという**組織の資産**を扱う。組織を跨いだ read/write は
「現場のノウハウが他社に漏れる」ことと同義であり、機能の魅力以前の前提条件である。
本 gate は新機能を足さないが、**「静的起点 + 主キー同一性による直 fetch が、分類なしにコードへ入り込まない」**
という土台を機械化し、使命の前提を守り続けるコストを人間のレビューから CI へ移す。

(§4-6 のとおり、これは「cross-org read/write が起きない」ことの**全面的な証明ではない**。
本 gate は不変条件 3 のうち機械化しうる具体的経路 1 本を受け持つ。過大に主張しない。)


---

## 参考: 既存の同型実装 (作法の正本)

### tests/Support/AuthorizationMarkerScanner.php
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 認可マーカー (`Gate::authorize` / `Gate::forUser(...)->authorize`) の字句解析器。
 *
 * `ControllerAuthorizationGateTest` (変更系 route の deny-by-default 認可 gate) の
 * 検出ロジックを route 走査から切り離した純粋 helper。
 * 「route 走査 = テスト、字句解析 = 本 helper」と責務を分け、解析器そのものの
 * positive/negative を `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php` が
 * 恒久固定する (gate 自体がセキュリティ機構であり、手動のコメントアウト検証では
 * 後の改修に対する回帰が効かないため)。
 *
 * ★設計判断:
 *  - 正規表現は使わない。`/Gate::forUser.*?->authorize/` は
 *    `Gate::forUser($u); $other->authorize();` のような無関係な 2 文でも合格してしまう
 *    (deny-by-default では誤合格が最悪の失敗モード)。括弧の深さを数える状態機械で
 *    「同一メソッドチェーン」であることを確認する。
 *  - コメント / 文字列リテラルはトークン段階で除去する
 *    (`// Gate::authorize を通す` のような記述で誤合格させない)。
 *  - 完全修飾名 (`\Illuminate\Support\Facades\Gate::authorize`) は受理しない。
 *    同名の別クラスによる誤合格を防ぐため、合格判定したファイルには
 *    `use Illuminate\Support\Facades\Gate;` の名前空間 import を必須とする
 *    ({@see self::importsGateFacade()})。
 *  - **ネストしたクロージャ / arrow function の中のマーカーは数えない**
 *    ({@see self::nestedFunctionMask()})。`$authorize = fn () => Gate::authorize(...);` は
 *    「その場では実行されない認可式」であり、これを合格させると gate の主張
 *    (「ハンドラは必ず認可判断を 1 回通る」) が崩れる。
 *
 * ★本 helper の限界 (意図的な線引き):
 *  トークン走査は**到達可能性を判定しない**。`if (false) { Gate::authorize(...); }` のような
 *  到達不能分岐に置かれたマーカーは合格する。制御フロー解析まで踏み込むのは本 gate の
 *  役割を超える (思考原則「今必要なものだけ作る」) ため実装しない。
 *  本 gate の役割は「**認可判断の入口が存在しない route を作らせない**」ことに限定し、
 *  認可が実際に効いているか (viewer が 403 になるか) は Feature テストの責務である
 *  (REST API v1 Item なら tests/Feature/Api/V1/ItemAuthorizationTest)。
 *  この 2 層で「入口の存在 = Architecture テスト / 実挙動 = Feature テスト」を分担する。
 *
 * ★前提 (将来 bracketed namespace を導入する場合は要見直し):
 *  本リポジトリは非 bracketed namespace (`namespace App\Foo;` のセミコロン形式) で
 *  統一されている。bracketed namespace (`namespace App { ... }`) を使うと
 *  名前空間 import の波括弧深さが 0 でなくなり {@see self::importsGateFacade()} の
 *  深さ判定が崩れる。Pint も非 bracketed を強制するため現状は対応しない。
 */
final class AuthorizationMarkerScanner
{
    /** 受理する Facade の完全修飾名 (これ以外の `Gate` は同名の別クラスとして扱う)。 */
    private const GATE_FACADE = 'Illuminate\Support\Facades\Gate';

    /**
     * メソッド本体のソース断片に認可マーカーがあるか。
     *
     * @param  string  $methodSource  `ReflectionMethod` の開始行〜終了行を切り出した PHP 断片
     */
    public static function hasAuthorizationMarker(string $methodSource): bool
    {
        return self::authorizationMarkerOffset($methodSource) !== null;
    }

    /**
     * 認可マーカーが最初に現れるトークン位置 (無ければ null)。
     *
     * 「URL 整合 guard → 認可」の順序検証 (不変条件 2) に使う。
     */
    public static function authorizationMarkerOffset(string $methodSource): ?int
    {
        $tokens = self::significantTokens($methodSource);
        $nested = self::nestedFunctionMask($tokens);
        $count = count($tokens);
        $offsets = [];

        for ($i = 0; $i < $count; $i++) {
            // ネストしたクロージャ / arrow function の中のマーカーは
            // 「ハンドラが必ず 1 回通る認可」ではないため数えない
            if ($nested[$i]) {
                continue;
            }
            if ($tokens[$i] !== 'Gate' || ($tokens[$i + 1] ?? '') !== '::') {
                continue;
            }

            // (d-1) Gate :: authorize (
            if (($tokens[$i + 2] ?? '') === 'authorize' && ($tokens[$i + 3] ?? '') === '(') {
                $offsets[] = $i;

                continue;
            }

            // (d-2) Gate :: forUser ( ... ) -> authorize
            if (($tokens[$i + 2] ?? '') !== 'forUser' || ($tokens[$i + 3] ?? '') !== '(') {
                continue;
            }

            $close = self::matchingParenthesis($tokens, $i + 3);
            if ($close === null) {
                continue;
            }
            // forUser() の戻り値に対して**直接** authorize() を呼んでいる形だけを合格とする
            // (間に `;` や別の式が挟まればチェーンは切れており不合格)。
            // 末尾の `(` は必須: `->authorize;` のような「呼んでいない」記述で合格させない
            if (($tokens[$close + 1] ?? '') === '->'
                && ($tokens[$close + 2] ?? '') === 'authorize'
                && ($tokens[$close + 3] ?? '') === '(') {
                $offsets[] = $i;
            }
        }

        return $offsets === [] ? null : min($offsets);
    }

    /**
     * inline URL 整合 guard (`$this->resolveOrganizationProject(...)` 等) の**全**トークン位置。
     *
     * ★最初の 1 件だけを返してはならない: guard が 2 段ある route
     * (`resolveOrganizationProject` + `resolveProjectItem`) で、片方だけが `Gate` より
     * 後ろに移動した壊れ方を見逃す (誤合格)。呼び出し側は全件が認可より前であることを検証する。
     *
     * @param  list<string>  $guardMethods  guard とみなすメソッド名
     * @return list<int>
     */
    public static function guardMarkerOffsets(string $methodSource, array $guardMethods): array
    {
        $tokens = self::significantTokens($methodSource);
        $nested = self::nestedFunctionMask($tokens);
        $count = count($tokens);
        $offsets = [];

        for ($i = 1; $i < $count; $i++) {
            if ($nested[$i]) {
                continue;
            }
            if ($tokens[$i - 1] === '->'
                && in_array($tokens[$i], $guardMethods, true)
                && ($tokens[$i + 1] ?? '') === '(') {
                $offsets[] = $i;
            }
        }

        return $offsets;
    }

    /**
     * 各トークンが「ネストしたクロージャ / arrow function の内部」かのマスク。
     *
     * 断片の先頭に現れる `function` / `fn` はハンドラ本体そのもの (メソッド宣言、または
     * Closure route) なのでネスト扱いしない。それ以降に現れる `function` / `fn` の
     * 本体は「その場では実行されないコード」であり、
     * `$authorize = fn () => Gate::authorize(...);` のような**呼ばれない認可式**で
     * gate を誤合格させないために除外する。
     *
     * 判定は保守的 (迷ったら除外) にしてある。除外しすぎた場合の結果は
     * 「認可なし」= gate が fail して人間が気づく方向であり、誤合格 (沈黙) にはならない。
     *
     * @param  list<string>  $tokens
     * @return list<bool>
     */
    private static function nestedFunctionMask(array $tokens): array
    {
        $count = count($tokens);
        /** @var list<bool> $mask */
        $mask = array_fill(0, $count, false);

        $outer = null;
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i] === 'function' || $tokens[$i] === 'fn') {
                $outer = $i;

                break;
    
```

### tests/Support/Routing/NestedRouteDefenseInventory.php
```php
<?php

declare(strict_types=1);

namespace Tests\Support\Routing;

use App\Enums\Security\NestedRouteDefenseMode;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * route parameter ごとの IDOR / 存在オラクル 防御方式の inventory (単一 source of truth)。
 *
 * 2 つの Architecture テストが同じ inventory を読むため、Pest のファイル読み込み順に
 * 依存する global 関数ではなく静的クラスに置く:
 *   - NestedRouteIdorDefenseTest    … 分類漏れ・stale・理由の突合 (deny-by-default)
 *   - TenantBoundaryOrderingTest    … モードごとの解決後 middleware 順序不変条件
 *
 * **母集団は 1 個以上の parameter を持つ named route** (旧実装は 2 個以上だった)。
 * 2+param に絞ると単独 param の route (`projects/{project}` / `user/passkeys/{passkey}` 等) が
 * 母集団から丸ごと外れ、テナント越境が分類対象にならない。audit-cycle-2 High-1 で
 * 実際に穴が残ったのはこの層である。
 *
 * **分類は route 単位ではなく parameter 単位**。同じ param 名でも route ごとに防御方式が
 * 違いうる (例: {user} は organizations.members.* が scopeBindings、
 * projects.members.destroy が手動解決)。route 単位の allowlist は param 1 つの分類漏れを
 * 丸ごと免除してしまうため使わない。
 */
final class NestedRouteDefenseInventory
{
    /**
     * route 名 => (parameter 名 => 防御方式)。
     *
     * @return array<string, array<string, NestedRouteDefenseMode>>
     */
    public static function inventory(): array
    {
        $scoped = NestedRouteDefenseMode::ScopeBindings;
        $binder = NestedRouteDefenseMode::ScopedBinder;
        $tenant = NestedRouteDefenseMode::TenantGuardMiddleware;
        $manual = NestedRouteDefenseMode::ManualOwnerScopedResolution;
        $nonRes = NestedRouteDefenseMode::NonResourceParameter;

        // {project} は web/API とも テナント guard middleware が binding 直後に走る (T108 S2)
        $project = ['project' => $tenant];

        return [
            // --- REST API v1 ---
            'api.v1.projects.show' => $project,
            'api.v1.projects.items.index' => $project,
            'api.v1.projects.items.store' => $project,
            // {item} は $project->items() 経由 (scopeBindings)
            'api.v1.projects.items.update' => [...$project, 'item' => $scoped],
            'api.v1.projects.items.destroy' => [...$project, 'item' => $scoped],

            // --- 撮影 PWA (/app/*。{manual}∈{project}, {cut}∈{manual}, {take}∈{cut}) ---
            'capture.manuals.index' => $project,
            'capture.manuals.show' => [...$project, 'manual' => $scoped],
            'capture.takes.upload-url' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
            'capture.takes.store' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
            'capture.takes.update' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.destroy' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.adopt' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.downloaded' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.playback' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],

            // --- 業務 route (web) ---
            'projects.show' => $project,
            'projects.edit' => $project,
            'projects.update' => $project,
            'projects.destroy' => $project,
            'projects.items.store' => $project,
            'projects.items.update' => [...$project, 'item' => $scoped],
            'projects.items.destroy' => [...$project, 'item' => $scoped],
            'projects.categories.index' => $project,
            'projects.categories.store' => $project,
            'projects.categories.reorder' => $project,
            'projects.categories.update' => [...$project, 'category' => $scoped],
            'projects.categories.destroy' => [...$project, 'category' => $scoped],
            'projects.manuals.create' => $project,
            'projects.manuals.store' => $project,
            'projects.manuals.show' => [...$project, 'manual' => $scoped],
            'projects.manuals.edit' => [...$project, 'manual' => $scoped],
            'projects.manuals.update' => [...$project, 'manual' => $scoped],
            'projects.manuals.destroy' => [...$project, 'manual' => $scoped],
            'projects.manuals.duplicate' => [...$project, 'manual' => $scoped],
            'projects.manuals.scenario.update' => [...$project, 'manual' => $scoped],
            'projects.manuals.source-documents.store' => [...$project, 'manual' => $scoped],
            'projects.manuals.analyze' => [...$project, 'manual' => $scoped],
            // {analysisJob} は $manual->analysisJobs() 経由
            'projects.manuals.jobs.show' => [...$project, 'manual' => $scoped, 'analysisJob' => $scoped],
            'projects.manuals.render' => [...$project, 'manual' => $scoped],
            'projects.manuals.preview' => [...$project, 'manual' => $scoped],
            'projects.manuals.download' => [...$project, 'manual' => $scoped],
            // {renderJob} は $manual->renderJobs() 経由
            'projects.manuals.render-jobs.show' => [...$project, 'manual' => $scoped, 'renderJob' => $scoped],
            'projects.manuals.render-jobs.playback' => [...$project, 'manual' => $scoped, 'renderJob' => $scoped],
            'projects.members.store' => $project,
            // {user} は ProjectMemberController::destroy が $organization->users() から手動解決する
            // (implicit binding を外して不在 id と実在の非メンバーを同一経路に落とす。T108 S3-b)
            'projects.members.destroy' => [...$project, 'user' => $manual],

            // --- 組織 (親 {organization} は MembershipScopedOrganizationBinder が membership スコープで解決) ---
            'organizations.settings' => ['organization' => $binder],
            'organizations.update' => ['organization' => $binder],
            'organizations.switch' => ['organization' => $binder],
            'organizations.onboarding.cli' => ['organization' => $binder],
            'organizations.onboarding.mcp' => ['organization' => $binder],
            'organizations.transfer-ownership
```

### app/Enums/Security/ControllerAuthorizationExemption.php
```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 変更系 (POST/PUT/PATCH/DELETE) route が「認可判断 (Gate) を持たないことが正しい」
 * と裁定された理由の分類。
 *
 * `tests/Architecture/ControllerAuthorizationGateTest.php` が deny-by-default で
 * 「認可あり」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   条件に当てはまらない route を無理に既存 case へ押し込むと gate が形骸化する。
 *   当てはまる case が無ければ、それは「認可を足すべき route」である。
 */
enum ControllerAuthorizationExemption: string
{
    /**
     * membership 判定そのものが認可である。
     *
     * 適用条件 (全て満たすこと):
     * - 対象リソースが `MembershipScopedOrganizationBinder` 等で membership スコープ解決される
     * - 「所属していれば誰でもよい」がロール非依存の**仕様**である
     *   (owner/admin/member を区別する必要が無い)
     * - Policy を足すと membership の**二重判定**にしかならない
     */
    case MembershipIsTheAuthorization = 'membership_is_the_authorization';

    /**
     * 認可の対象となる既存リソースが存在しない (新規作成そのもの)。
     *
     * 適用条件: route に対象リソースを指す parameter が無く、
     * 作成対象の親テナントも存在しない (= 誰の何に対する権限か、が定義できない)。
     */
    case NoAuthorizableSubject = 'no_authorizable_subject';

    /**
     * 対象が常に「認証中の自分自身」に閉じる。
     *
     * 適用条件 (全て満たすこと):
     * - route に**他者を指せる parameter が 1 つも無い**、または
     *   parameter が `$user->relation()` 経由でのみ解決され cross-user が構造的に 404
     * - 他者のリソースへ到達する経路がコード上存在しない
     */
    case SelfScopedResource = 'self_scoped_resource';

    /**
     * 認可主体が「有効なトークンの保持者」であり、トークン検証が認可を兼ねる。
     *
     * 適用条件: 対象組織の**非メンバー**が正当に実行する操作であり、
     * 組織 Policy を通すと構造的に必ず拒否になる (招待受諾など)。
     */
    case TokenBearerIsTheSubject = 'token_bearer_is_the_subject';

    /**
     * API トークンの scope 判定が明示的な 403 を担っている。
     *
     * 適用条件: controller 内に `abort_unless($actor->hasScope(...), 403)` 等の
     * **明示的な 403 判定**があり、かつ対象が actor 自身のリソースに閉じる。
     */
    case ScopeIsTheAuthorization = 'scope_is_the_authorization';

    /** 未認証の公開エンドポイント (認可すべき主体が存在しない)。 */
    case PublicUnauthenticated = 'public_unauthenticated';

    /**
     * 署名検証済みの machine-to-machine webhook (人間の actor が存在しない)。
     *
     * 適用条件: 署名検証 middleware + 送信元 allowlist (fail-closed) が防御線であること。
     */
    case SignatureVerified = 'signature_verified';

    /**
     * local / テスト実行時のみ **route 登録自体が起きない**デバッグ用 route。
     *
     * 適用条件: `routes/*.php` 側で `app()->isLocal() || app()->runningUnitTests()`
     * 等により登録が囲われ、かつ `LocalOnly` 相当の middleware が二重防御であること。
     */
    case LocalOnlyDebugRoute = 'local_only_debug_route';
}

```
