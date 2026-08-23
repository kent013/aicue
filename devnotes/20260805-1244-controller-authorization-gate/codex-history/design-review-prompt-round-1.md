# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: controller-authorization-gate

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory / 既存 helper で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く（Service 委譲）
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260805-1244-controller-authorization-gate/conceptual-design.md](./conceptual-design.md)
（Codex 概念設計レビュー **APPROVED (Round 3)**）

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | exemption 分類 enum の新設 | `app/Enums/Security/ControllerAuthorizationExemption.php` (新規) | 高 |
| 2 | `ControllerAuthorizationGateTest` 新設 (deny-by-default gate) | `tests/Architecture/ControllerAuthorizationGateTest.php` (新規) | 高 |
| 3 | `Api\V1\ItemController` に `Gate::forUser` 認可を追加 | `app/Http/Controllers/Api/V1/ItemController.php` | 高 |
| 4 | `ItemAuthorizationTest` 新設 (Feature) | `tests/Feature/Api/V1/ItemAuthorizationTest.php` (新規) | 高 |
| 5 | OAuth CLI セッション helper の Support 昇格 (施策 4 の前提) | `tests/Support/OAuthTestHelpers.php` / `tests/Feature/Api/OAuthDualGuardTest.php` | 中 |
| 6 | ドキュメント更新 (不変条件 + チェックリスト) | `docs/app-integration-guide.md` / `docs/architecture.md` | 中 |

**実装順序**: 3 → 4 は「テストファースト」の原則により **4 (fail を確認) → 3 (実装)** の順で行う。
1 → 2 は 2 が 1 に依存するため 1 が先。5 は 4 の前提。

---

## 施策 1: exemption 分類 enum の新設

### 変更箇所

- ファイル: `app/Enums/Security/ControllerAuthorizationExemption.php`（**新規**）

配置理由は概念設計 §enum の配置先を `app/Enums/Security/` にする理由。
既存の同ディレクトリには `NestedRouteDefenseMode.php` **1 件のみ**が存在し、
同じ「Architecture テストが使うセキュリティ不変条件の分類語彙」という責務である。

### 波及変更

- TypeScript 型定義: **なし**（サーバ内部の分類語彙。フロントに出ない）
- API Resource/DTO: **なし**
- テストファイル: 施策 2 が本 enum を参照する

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 変更系 (POST/PUT/PATCH/DELETE) route が「認可判断 (Gate) を持たないことが正しい」
 * と裁定された理由の分類。
 *
 * {@see \Tests\Architecture\ControllerAuthorizationGateTest} が deny-by-default で
 * 「認可あり」か「本 enum + 具体的根拠付きの exemption」かを機械強制する。
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

### PHPStan 適合チェック

- [x] backed enum (`string`) で値が明示されている
- [x] 戻り値の型が明示されている（enum のため不要）
- [x] null 安全（enum に null は存在しない）
- [x] DTO を返している（配列返却なし）

### テスト計画

- [x] 施策 2 の「inventory の各値は `ControllerAuthorizationExemption`」テストで型を固定
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（Architecture テストは DB 非依存）

### リスク

- **case が増えすぎて形骸化する**: docblock の「適用条件」を厳格に書くことで抑制する。
  レビュー時は「当てはまる case が無い = 認可を足すべき route」と読む規約を §6 のドキュメントに書く。

---

## 施策 2: `ControllerAuthorizationGateTest` 新設 (deny-by-default gate)

### 変更箇所

- ファイル: `tests/Architecture/ControllerAuthorizationGateTest.php`（**新規**）

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 本体が新規テスト。既存テストの変更は**なし**
  （`ManageRouteAuthGuardTest` / `NestedRouteIdorDefenseTest` は層が違うため**並存**。
   inventory も共有しない = テスト間の関数依存を作らない）

### 設計仕様

#### (a) 候補抽出

```
Route::getRoutes() を走査
  → methods() に POST/PUT/PATCH/DELETE のいずれかを含むものだけ残す
  → ハンドラを Reflection で解決 (下記 (b))
  → 定義ファイルが vendor/ 配下ならスキップ (パッケージ所有)
  → それ以外は「候補」
```

#### (b) ハンドラ解決 (fail-secure)

`$route->getAction('uses')` は `string` (`Class@method`) か `Closure`。

| 種別 | 解決 |
|---|---|
| `Class@method` (`__invoke` 含む。Laravel が `Class@__invoke` に正規化する) | `new ReflectionMethod($class, $method)` |
| `Closure` | `new ReflectionFunction($closure)` |
| それ以外 / 例外発生 / `getFileName()` が `false` | **即 fail**（合格側に倒さない） |

#### (c) 所有権判定 (パス境界込み)

```php
$vendorDir = realpath(base_path('vendor')).DIRECTORY_SEPARATOR;
$file = realpath($reflection->getFileName());
// str_starts_with($file, $vendorDir) なら vendor 所有 → スキップ
```

`realpath()` で正規化し、末尾にディレクトリ区切りを付けて**パス境界込み**で判定する
（`vendor-foo/` のような prefix 一致の誤判定を防ぐ。Codex Round 3 Suggestion 反映）。

#### (d) 認可マーカー検出 (トークンベース)

```php
$lines = file($file);
$fragment = implode('', array_slice($lines, $start - 1, $end - $start + 1));
// ★ 開始タグが無いと全体が T_INLINE_HTML になり検出が全滅する
$tokens = token_get_all('<?php '.$fragment);
```

除去するトークン: `T_COMMENT` / `T_DOC_COMMENT` / `T_CONSTANT_ENCAPSED_STRING` /
`T_ENCAPSED_AND_WHITESPACE` / `T_WHITESPACE`。

残りを空白 1 個区切りで連結し、以下を**認可あり**とする:

- `Gate :: authorize`
- `Gate :: forUser` … `-> authorize`（同一メソッド本体内。正規表現 `/Gate :: forUser .*?-> authorize/`）

**受理しないもの**（概念設計 §受理する認可手段は `Gate::` ファサード 1 系統だけにする）:
`can:` middleware / `$this->authorize()` / `FormRequest::authorize()` /
membership binder / `resolve*` 系 / `auth` `verified` `recent-auth`
`require-active-subscription` `api-key.ability:*` middleware。

#### (e) `Gate` が Facade であることの確認 (Codex Round 3 Suggestion)

同名の別クラスによる誤合格を防ぐため、`Gate` トークンを検出したファイルが
`Illuminate\Support\Facades\Gate` を import しているか
（または完全修飾で `\Illuminate\Support\Facades\Gate::` を書いているか）を検査する。
**import が無いのに `Gate::` を使っているファイルは fail** させる
（誤合格を防ぐと同時に、実在しない `Gate` を使う実装ミスも落ちる）。

#### (f) 「URL 整合 guard → 認可」の順序検証

ハンドラ本体に inline guard マーカー
（`resolveOrganizationProject` / `resolveProjectItem` / `resolveOrganizationMember`）と
認可マーカーの**両方**がある場合、トークン列上の位置で
**guard が認可より前**であることを検証する。
実査時点で違反 **0 件**（現行慣行の固定であり新規制約ではない）。

#### (g) drift ガード

| 検査 | 内容 |
|---|---|
| 候補数の下限 | 候補が下限を下回ったら fail。**下限は 40**（実測 61 に対し十分な余裕。上限は設けない = route 追加を妨げない） |
| 解決失敗 0 件 | (b)(c) で解決できない候補が 1 件でもあれば fail |
| inventory の網羅性 | 認可マーカーを持たない候補が exemption inventory に無ければ fail |
| stale 検出 | inventory の key が現存 route 名でなければ fail |
| 型固定 | inventory の値の第 1 要素が `ControllerAuthorizationExemption` であること |
| 理由の実質性 | 第 2 要素が **30 文字以上**の文字列であること（「同上」「N/A」で埋める運用を機械的に止める） |

> **数値をテストに固定値として埋め込まない**方針（概念設計）に従い、
> 「認可あり 46 / なし 15」はテストに書かない。書くのは上記 6 つだけ。

### 変更後コード（骨子）

```php
<?php

declare(strict_types=1);

use App\Enums\Security\ControllerAuthorizationExemption;
use Illuminate\Support\Facades\Route;

/*
 * 変更系 route の認可 invariant (deny-by-default)。
 *
 * 「状態を変える route (POST/PUT/PATCH/DELETE) のハンドラは、必ず認可判断を 1 回通る」
 * を機械強制する。通らないものは理由付きで exemption inventory へ明示登録させる。
 *
 * ★本テストの核心は「何を認可と認めないか」:
 *   membership binder (MembershipScopedOrganizationBinder) / resolveOrganization 系 /
 *   auth・verified・recent-auth・require-active-subscription・api-key.ability middleware /
 *   FormRequest::authorize() は **合格条件に数えない**。
 *   これらはテナント境界 (層 2) や認証・契約状態であって認可 (層 3) ではなく、
 *   数えると gate が形骸化する。
 *
 * ★受理する認可手段は Gate ファサード 1 系統のみ:
 *   - can: middleware は Controller より前に走るため、inline guard 方式の route で
 *     「認可より前に 404」(不変条件 2) を壊す (cross-org が 403 になり存在が漏れる)。
 *   - $this->authorize() は base Controller が AuthorizesRequests trait を持たず呼べない。
 *   いずれも使用実績 0 件のため受理しない (使いたくなったら本テストごと設計し直す)。
 *
 * 本テストは「認可判断の入口が存在しない route を作らせない」役割に限定する。
 * 認可の**内容**の正当性 (対象が正しいか / Policy が妥当か / actor が正しいか) は
 * 各 Feature / Policy テストの責務 (NestedRouteIdorDefenseTest と同じ責務設計)。
 */

/** 変更系 HTTP メソッド。 */
const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

/** 候補数の下限 (空振り drift ガード。実測 61 に対し余裕を持たせた値)。 */
const MUTATING_ROUTE_FLOOR = 40;

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const EXEMPTION_REASON_MIN_LENGTH = 30;

/**
 * 認可を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ControllerAuthorizationExemption, string}>
 */
function controllerAuthorizationExemptions(): array
{
    $membership = ControllerAuthorizationExemption::MembershipIsTheAuthorization;
    $noSubject = ControllerAuthorizationExemption::NoAuthorizableSubject;
    $selfScoped = ControllerAuthorizationExemption::SelfScopedResource;
    $tokenBearer = ControllerAuthorizationExemption::TokenBearerIsTheSubject;
    $scope = ControllerAuthorizationExemption::ScopeIsTheAuthorization;
    $public = ControllerAuthorizationExemption::PublicUnauthenticated;
    $signature = ControllerAuthorizationExemption::SignatureVerified;
    $localOnly = ControllerAuthorizationExemption::LocalOnlyDebugRoute;

    return [
        'organizations.switch' => [$membership,
            '{organization} は MembershipScopedOrganizationBinder が membership スコープで解決し、'
            .'非所属は認可より前に 404 (存在秘匿)。「所属組織なら誰でも current org を切り替えられる」が'
            .'ロール非依存の仕様であり、Policy を足すと membership の二重判定になるうえ、'
            .'404 の存在秘匿を 403 に劣化させる危険がある。守っているのは不変条件 2/3。'],

        'organizations.store' => [$noSubject,
            '新規組織の作成。判定対象となる既存リソースも親テナントも存在しない'
            .'(誰でも自分の組織を作れる)。制約は verified.or-back middleware と'
            .'StoreOrganizationRequest のバリデーションのみ。'],

        'invitations.accept.store' => [$tokenBearer,
            '認可主体は「有効な招待トークンの保持者」。OrganizationMembershipService::acceptInvitation が'
            .'token hash 照合と失効/期限/受諾済み判定を行う。受諾前の user は対象組織の非メンバーであり、'
            .'組織 Policy を通すと構造的に必ず拒否になる (機能が成立しない)。'],

        'settings.account.destroy' => [$selfScoped,
            '対象は $request->user() 自身のみ。route に他者を指せる parameter が 1 つも無く、'
            .'他人のアカウントへ到達する経路がコード上存在しない。'
            .'別軸の防御として recent-auth (step-up) middleware を必須にしている。'],

        'recent-auth.password' => [$selfScoped,
            '自分の再認証鮮度 (RecentAuthState) の更新。route に他者を指せる parameter が無く、'
            .'認証そのものが主体判定であるため Policy による再判定に意味がない。'
            .'総当り防御は throttle:6,1。'],

        'notifications.open' => [$selfScoped,
            'NotificationCenterService::findOwnOrFail($user, ...) が $user->notifications() 経由で'
            .'解決するため cross-user は構造的に 404 (存在オラクル封じ)。controller docblock が'
            .'「open は認可判断 (Gate) を一切複製しない」と明示し、遷移先 projects.manuals.show が'
            .'唯一の判断点、という設計を意図的に採っている。'],

        'notifications.read' => [$selfScoped,
            'notifications.open と同じく findOwnOrFail による自通知限定解決 (cross-user は 404)。'
            .'既読化は自分の通知状態の変更に閉じ、他者に影響しない。'],

        'notifications.read-all' => [$selfScoped,
            'markAllRead($user) で自分宛の通知のみを対象にする。route に parameter が無く、'
            .'他者の通知へ到達する経路が存在しない。'],

        'api.v1.me.session.revoke' => [$scope,
            '失効対象は actor 自身の OAuth session (ApiActorContext::$oauthSessionId と一致する 1 件) のみ。'
            .'加えて abort_unless($actor->hasScope(SessionRevoke), 403) という明示的な 403 判定が既にあり、'
            .'Policy 対象となる他者リソースが存在しない。'],

        'contact.store' => [$public,
            '公開問い合わせフォーム (auth 不要 = 認可すべき主体が存在しない)。'
            .'防御は throttle:inquiry (IP 単独 + IP+email の 2 系統) + honeypot + reCAPTCHA。'],

        'webhooks.ses' => [$signature,
            'SNS 署名検証 (sns.signature = VerifySnsSignature middleware) が唯一の防御線で、'
            .'TopicArn allowlist は空なら全拒否の fail-closed。人間の actor が存在しない'
            .'machine-to-machine 経路のため Policy 判定の主体を定義できない。'],

        'debug.login-as' => [$localOnly,
            'local / unit test 実行時のみ route が登録され staging/production では登録自体が起きない'
            .'(routes/web.php の if (app()->isLocal() || app()->runningUnitTests()) による fail-safe)。'
            .'加えて LocalOnly middleware (local 以外 404 + Basic 認証 + 未設定 404) が二重防御。'],
    ];
}
```

テスト本体は以下の 6 本:

```php
test('変更系 route の候補は下限を下回らない (空振り drift ガード)', ...);
test('変更系 route のハンドラはすべて Reflection で解決できる', ...);
test('変更系 route は認可を持つか exemption inventory に明示分類されている (未知は fail)', ...);
test('exemption inventory の key は現存 named route (逆方向整合・stale 検出)', ...);
test('exemption inventory の値は enum + 実質的な理由文字列', ...);
test('URL 整合 guard は認可より前に置かれている (不変条件 2)', ...);
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`controllerAuthorizationExemptions(): array` + PHPDoc shape）
- [x] null 安全（`getFileName()` の `false`、`getName()` の `null` を明示分岐して fail に倒す）
- [x] DTO を返している（enum を使用。生文字列の分類にしない）
- [x] Generics の型パラメータが正しい（`array<string, array{ControllerAuthorizationExemption, string}>`）
- [x] `token_get_all()` の戻り値 `array<int, string|array{int, string, int}>` を `is_array()` で narrowing

### テスト計画

- [x] 本施策自体がテスト。**テストファースト**: 先に `Api\V1\ItemController` を未修正のまま
      gate を書き、`api.v1.projects.items.*` の 3 本が**未分類で fail する**ことを確認する
      （= gate が実害を実際に検出できることの証明）。その後 施策 3 で認可を足すと green になる
- [x] 空振りしないことの証明: `MUTATING_ROUTE_FLOOR` を一時的に 200 に上げて fail することを確認
- [x] 誤合格しないことの証明: 任意の controller の `Gate::authorize` 行を一時的に
      コメントアウトし、その route が fail することを確認（トークン化が効いている証明）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（DB 非依存の Architecture テスト）

### リスク

| リスク | 対策 |
|---|---|
| Laravel の route action 正規化仕様に依存する（`__invoke` の `Class@__invoke` 化） | 解決失敗を即 fail にしているため、仕様が変わればテストが赤くなって気づける（沈黙しない） |
| `token_get_all()` が PHP バージョン差でトークン種別を変える | 除去対象は PHP 8 系で安定した基本トークンのみ。`T_NAME_QUALIFIED` 等の新トークンは連結対象に残るだけで判定に影響しない |
| exemption が将来増えて形骸化 | enum の適用条件 docblock + 理由 30 文字以上 + レビュー規約（§6 のドキュメント）で抑制 |

---

## 施策 3: `Api\V1\ItemController` に `Gate::forUser` 認可を追加

### 変更箇所

- ファイル: `app/Http/Controllers/Api/V1/ItemController.php`
  - `store` (L46-59) / `update` (L62-76) / `destroy` (L82-91)

### 波及変更

- TypeScript 型定義: **なし**（REST API v1 は機械向け。Inertia props に出ない）
- API Resource/DTO: **なし**。403 は既存 `ApiExceptionRenderer` が
  `AuthorizationException` → `ApiErrorCode::Forbidden` の統一 envelope に変換する（実装済み経路）
- テストファイル: 施策 4（新規）。既存 `tests/Feature/Api/ApiEndpointTest.php` /
  `IdempotencyTest.php` / `OAuthDualGuardTest.php` は
  `createOrganizationWithOwner` 由来の **owner** を actor にしているため
  `ProjectPolicy::update` を通り**影響しない見込み**。実装時に全件実走して確認する

### 現行コード

```php
/** POST /api/v1/projects/{project}/items — 親 FK は URL から導出し relation 経由で代入 */
public function store(StoreItemRequest $request, Project $project): JsonResponse
{
    $organization = $this->resolveOrganization($request);
    $this->resolveOrganizationProject($organization, $project);

    $name = $request->validated('name');
    Assert::string($name);
    // ...
}
```

（`update` / `destroy` も同様に URL 整合 guard のみで `Gate` を通らない）

### 変更後コード

```php
use App\Http\Controllers\Api\V1\Concerns\ReadsApiActor;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
use Illuminate\Support\Facades\Gate;

class ItemController extends Controller
{
    use ReadsApiActor;
    use ResolvesApiOrganization;

    /** POST /api/v1/projects/{project}/items — 親 FK は URL から導出し relation 経由で代入 */
    public function store(StoreItemRequest $request, Project $project): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        // URL 整合 guard: 認可より前に 404 (cross-org を 403 で漏らさない)
        $this->resolveOrganizationProject($organization, $project);
        // 認可: web 側 Projects\ItemController と同一の ItemPolicy 境界に揃える。
        // ★Gate::authorize は使えない: dual guard では API キー経路の default guard が
        //   api-key になり Auth::user() が App\Models\ApiKey を返すため、
        //   ItemPolicy::create(User $user, ...) が TypeError = 500 になる。
        //   認可主体は resolve.api-actor が解決済みの ApiActorContext::$user
        //   (API キー = 発行者 / OAuth = トークン所有者。非 null 保証) を明示的に渡す。
        Gate::forUser($this->apiActor($request)->user)
            ->authorize('create', [Item::class, $project]);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        $item = $project->items()->create(['name' => $name, 'note' => $note]);

        return ItemResource::make($item)->response()->setStatusCode(201);
    }

    /** PATCH /api/v1/projects/{project}/items/{item} */
    public function update(UpdateItemRequest $request, Project $project, Item $item): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        // URL 整合 guard 2 段: いずれも認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        $this->resolveProjectItem($project, $item);
        Gate::forUser($this->apiActor($request)->user)->authorize('update', $item);

        // ... (以下現行どおり)
    }

    /** DELETE /api/v1/projects/{project}/items/{item} */
    public function destroy(Request $request, Project $project, Item $item): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        $this->resolveProjectItem($project, $item);
        Gate::forUser($this->apiActor($request)->user)->authorize('delete', $item);

        $item->delete();

        return JsonResource::make(['deleted' => true])->response();
    }
}
```

**順序が本質**: `resolveOrganizationProject` / `resolveProjectItem`（404）を
**必ず `Gate::forUser(...)` より前**に置く。逆にすると cross-org が 403 を返し、
「そのリソースが存在する」ことを漏らす（不変条件 2 違反）。
施策 2 の順序検証テストがこれを機械固定する。

class docblock も更新する（「認可は Gate::forUser 経由。web 側と同一の ItemPolicy 境界」）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`JsonResponse`）
- [x] null 安全: `ApiActorContext::$user` は `public User $user` の**ネイティブ非 null 型**、
      `ReadsApiActor::apiActor()` の戻り値も `ApiActorContext`（非 null）。
      `Gate::forUser(Authenticatable)` に `User` を渡せることが型で保証される
- [x] DTO を返している（`ItemResource` / `JsonResource`。`response()->json()` 直書きなし）
- [x] Generics の型パラメータが正しい（変更なし）

### テスト計画

- [x] **バグ修正のため再現テストを先に書く**（施策 4）。viewer が 200/201 を得てしまう
      現状を先に fail させてから本施策を実装する
- [x] 既存テスト `tests/Feature/Api/ApiEndpointTest.php`（items CRUD / cross-org 404）が
      引き続き green であることを確認（cross-org が **404 のまま**であることの回帰確認を兼ねる）
- [x] 既存テスト `tests/Feature/Api/IdempotencyTest.php` / `OAuthDualGuardTest.php` の green 確認
- [x] 新規テスト: 施策 4 参照
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

| リスク | 影響 | 対策 |
|---|---|---|
| **OAuth CLI トークンの後方非互換** | CLI セッションは組織メンバーなら誰でも開始できる。`organization_member` かつ `project_admin` でない利用者の Item write が **403 になる** | 意図的な是正。統一エラー envelope の `code: "forbidden"` で返り、`insufficient_ability` (ability 不足) とは**別コード**で区別できるため、クライアントは「権限不足」と「トークン設定不足」を判別できる。リリースノート + `docs/app-integration-guide.md` §5 に権限境界を明記 |
| API キー発行者の降格 | 発行者が member へ降格するとそのキーの write が 403 | API キーを発行できるのは `manageApiKeys` = owner/admin のみ。降格後に権限が落ちるのは**是正**であって退行ではない |
| `Gate::authorize` を誤って使う実装ミス | 403 ではなく **500 (TypeError)** | 施策 4 で API キー経路・OAuth 経路の**両方**で 403 を assert する（500 なら即座に落ちる） |
| cross-org が 403 に劣化 | 情報漏洩（不変条件 2 違反） | guard を authorize より前に置く + 施策 2 の順序検証 + 施策 4 の cross-org 404 ケース + 既存 `ApiEndpointTest` の 3 重で固定 |

---

## 施策 4: `ItemAuthorizationTest` 新設 (Feature)

### 変更箇所

- ファイル: `tests/Feature/Api/V1/ItemAuthorizationTest.php`（**新規**）

`tests/Feature/Api/V1/` ディレクトリは新規作成（既存 API テストは `tests/Feature/Api/` 直下だが、
v1 固有の認可契約として分離する。`app/Http/Controllers/Api/V1/` の構造と対応させる）。

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 施策 5（OAuth helper の Support 昇格）が前提

### テストケース

| # | ケース | 期待 |
|---|---|---|
| 1 | viewer (組織 member / project ロールなし) の **API キー**で `store` | **403** + `error.code = forbidden` |
| 2 | viewer の API キーで `update` | **403** |
| 3 | viewer の API キーで `destroy` | **403** + Item が消えていないこと |
| 4 | editor (`project_admin`) の API キーで `store` / `update` / `destroy` | **201 / 200 / 200** |
| 5 | 組織 admin (project ロールなし) の API キーで `store` | **201**（組織管理者の継承規則） |
| 6 | viewer の **OAuth CLI トークン**で `store` | **403**（actor 解決が両経路で一本化されていることの固定） |
| 7 | editor の OAuth CLI トークンで `store` | **201** |
| 8 | **cross-org**: 他組織の project に対し `store` / `update` / `destroy` | **404**（403 ではない = 情報漏洩防止） |
| 9 | **cross-org かつ viewer**: 権限も無く組織も違う | **404**（認可より前に 404 = 認可判断に到達しない） |
| 10 | actor の `current_organization_id` が**別組織**でも、URL 上の `{project}` の組織で判定される | editor なら **201**（Laratrust team 文脈が current org に汚染されない） |
| 11 | viewer で `store` したとき **Item が作成されていない** | `$project->items()->count()` が 0 |

> ケース 8/9 が本設計の**セキュリティ回帰テスト**。認可を足したことで
> cross-org が 403 に変わっていないことを直接固定する。
> ケース 9 は「認可より前に 404」の順序そのものを検証する（viewer かつ cross-org で 403 が
> 返ったら、認可が guard より前に走っている証拠になる）。

### 変更後コード（骨子）

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Item;
use App\Models\Project;

/*
 * REST API v1 Item の認可境界 (web 側 Projects\ItemController と同一の ItemPolicy 境界)。
 *
 * ★不変条件: cross-org は認可より前に 404 (403 で存在を漏らさない)。
 *   認可を足したことで 404 が 403 に劣化していないことを本テストが固定する。
 * ★actor は ApiActorContext::$user (API キー = 発行者 / OAuth = トークン所有者)。
 *   Gate::authorize (default guard) を使うと API キー経路で ApiKey が渡り 500 になるため、
 *   API キー経路と OAuth 経路の両方で 403 を assert する。
 */

/** viewer (組織 member かつ project ロールなし) を作り API キーを発行する */
function viewerApiKey(App\Models\Organization $organization): string
{
    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
    [, $plain] = issueApiKey($organization, $viewer, ['read', 'write']);

    return $plain;
}

test('viewer の API キーでは Item を作成できない (403)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $plain = viewerApiKey($organization);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '侵入'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($project->items()->count())->toBe(0);
});

// ... (update / destroy / editor / admin / OAuth / cross-org / current-org 汚染)

test('cross-org は認可より前に 404 (viewer でも 403 にしない)', function (): void {
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    $itemA = Item::factory()->forProject($projectA)->create();
    // 組織 B の viewer キー (権限も無く組織も違う)
    $plain = viewerApiKey($organizationB);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", ['name' => '更新'])
        ->assertNotFound()          // ★403 ではない
        ->assertJsonPath('error.code', 'not_found');
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（各 `test()` クロージャは `void`、helper は `string`）
- [x] null 安全（factory 生成物は非 null。`Assert` 不要）
- [x] **テストデータは全て Factory / 既存 helper** で生成
      （`createOrganizationWithOwner` / `attachOrganizationMember` / `attachProjectMember` /
       `issueApiKey` / `Project::factory()` / `Item::factory()`。`Model::create()` 手組みなし）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] **バグ修正の再現テストを先に書く**: 施策 3 の実装前に本テストを走らせ、
      ケース 1/2/3/6/11 が **fail する**（現状 viewer が 201/200 を得る）ことを確認する
- [x] 施策 3 実装後に全ケース green
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Pest.php` のグローバル `RefreshDatabase` に従う）
- [x] `--parallel` で走ることを前提に、共有状態を持たない（global helper 関数の再宣言に注意 → 施策 5）

### リスク

- **global helper 関数名の衝突**: `viewerApiKey()` のような global 関数を test ファイルに
  定義すると、他ファイルに同名があれば fatal error になる。
  Pest の慣行に従い**十分に固有な名前**にするか、`tests/Pest.php` へ昇格させる。
  本設計では `viewerApiKey` は本ファイル固有として定義し（既存に同名なしを確認済み）、
  OAuth 側は施策 5 でクラス static へ逃がす

---

## 施策 5: OAuth CLI セッション helper の Support 昇格

### 変更箇所

- ファイル: `tests/Support/OAuthTestHelpers.php`（静的メソッド追加）
- ファイル: `tests/Feature/Api/OAuthDualGuardTest.php`（既存 global 関数を委譲に変更）

### 背景（なぜ必要か）

施策 4 のケース 6/7 は OAuth CLI トークンで API を叩く必要がある。
その手順（token 交換 → `oauth_access_tokens` 行の特定 → `OauthSession` 作成 → `session_id` 束縛）は
現在 `tests/Feature/Api/OAuthDualGuardTest.php:34` に **global function `issueCliSessionTokens()`** として
定義されている。**global 関数は再宣言できない**ため、施策 4 のファイルで同じものを定義すると
fatal error になる。かつ Pest のファイル読み込み順に依存して「たまたま使える」状態に頼るのは脆い。

### 変更後コード

```php
// tests/Support/OAuthTestHelpers.php に追加
/**
 * OAuth flow で token を取得し、CLI セッション行を作って access token に束縛する。
 * (REST API v1 の actor 解決 = resolve.api-actor の前提条件を満たす)
 *
 * @return array{access_token: string, refresh_token: string, session: OauthSession}
 */
public static function issueCliSessionTokens(
    TestCase $test,
    User $user,
    Organization $organization,
    Client $client,
    string $scope = 'cli:use read write session.revoke',
): array {
    // (既存 issueCliSessionTokens の本体をそのまま移設)
}
```

```php
// tests/Feature/Api/OAuthDualGuardTest.php — 既存 global 関数は薄い委譲に置き換える
function issueCliSessionTokens(object $test, string $scope = 'cli:use read write session.revoke'): array
{
    return OAuthTestHelpers::issueCliSessionTokens(
        $test, $test->user, $test->org, $test->client, $scope,
    );
}
```

> **後方互換の並走を残さない**（思考原則 3）: 本体を移設し、
> 呼び出し側 global 関数は**委譲 1 行のみ**にする（ロジックの二重管理をしない）。
> `$test` の magic property (`$test->user` / `$test->org` / `$test->client`) への
> 暗黙依存を、明示引数に引き上げる副次効果もある。

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Api/OAuthDualGuardTest.php` の既存 12 テストが
  引き続き green であること（**振る舞いは不変**、置き場所のみ変更）

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（PHPDoc の array shape 付き）
- [x] null 安全（`$tokenId` の null を `Assert` / `expect` で潰す。移設前と同じ）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] 既存テスト `tests/Feature/Api/OAuthDualGuardTest.php` の全ケース green（回帰確認）
- [x] 新規テスト 施策 4 のケース 6/7 が本 helper 経由で動く

### リスク

- 移設ミスで OAuth 系の既存テストが壊れる → 既存テストがそのまま回帰検知になる（振る舞い不変）

---

## 施策 6: ドキュメント更新

### 変更箇所

- `docs/app-integration-guide.md` §7（セキュリティ不変条件）
- `docs/architecture.md`（Architecture テスト一覧に新テストを追記）

### 追記内容

**§7 に追加する不変条件**:

> **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE のアプリ所有 route は
> `Gate::authorize` / `Gate::forUser(...)->authorize` を持つか、
> `ControllerAuthorizationGateTest` の exemption inventory に
> `ControllerAuthorizationExemption` + 具体的根拠付きで登録する
> （`ControllerAuthorizationGateTest` が deny-by-default で強制）。
> **層 2（テナント境界 = 404）と層 3（認可 = 403）の順序は不可侵**。
> inline guard は必ず `Gate` より前に置く。

**新規 route 追加時のチェックリスト**:

1. ハンドラ冒頭（URL 整合 guard の**後**）に `Gate::authorize(...)` を置く。
   **REST API v1 では `Gate::forUser($this->apiActor($request)->user)->authorize(...)`**
   （dual guard では `Auth::user()` が `ApiKey` を返すため `Gate::authorize` は TypeError = 500 になる）
2. 認可が不要なら `ControllerAuthorizationGateTest` の exemption inventory に
   enum + 「**何が代わりに守っているか**」を 30 文字以上で登録する。
   当てはまる enum case が無ければ、それは**認可を足すべき route** である
3. 2+param route なら `NestedRouteIdorDefenseTest` の inventory にも防御方式を登録する
4. `composer test` で両 gate が green であることを確認する

### 波及変更

- TypeScript 型定義 / API Resource / テスト: **なし**（ドキュメントのみ）

### テスト計画

- [x] ドキュメントのため自動テストなし。`docs/architecture.md` の記述と実ファイル名の
      一致は目視確認（既存の Architecture テスト一覧と同じ運用）

### リスク

- なし

---

## 3 層の関係（本設計の要約図）

```
リクエスト
  │
  ├─ [層1] 認証        auth / auth:api-key,api-oauth
  │                    … ManageRouteAuthGuardTest / ApiGuardAllowlistInvariantTest
  │
  ├─ [層2] テナント境界 MembershipScopedOrganizationBinder / scopeBindings /
  │                    resolveOrganizationProject / resolveProjectItem
  │                    … NestedRouteIdorDefenseTest        ← 不整合は 404
  │
  └─ [層3] 認可        Gate::authorize / Gate::forUser(...)->authorize
                       … ★ ControllerAuthorizationGateTest (新設)  ← 不足は 403
```

- 本 gate は**層 3 専任**。層 2 の手段を層 3 の合格条件に**数えない**のが核心
- **層 2 → 層 3 の順序は不可侵**（施策 2 (f) が機械固定）
- 両テストは inventory を共有しない（テスト間の関数依存を作らない）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 2 の gate が施策 3 の実害検出を前提にしており（テストファースト: gate を書く → `api.v1.projects.items.*` が fail する → 認可を足す → green）、施策 4 も施策 3 の再現テストとして先行する。**施策間の依存が strong に連鎖**しており、分割すると「gate は入ったが認可漏れは残っている」という中間状態が main に乗る。セキュリティ変更のため中間状態を作らない。施策 5 は施策 4 の前提（global 関数衝突の回避）で同一 PR に含める必要がある |
| 競合リスク | **低**。変更ファイルは新規 4 本 + 既存 3 本（`Api\V1\ItemController` / `OAuthDualGuardTest` / docs）で、他の進行中タスクと重複する可能性が低い。ただし**新しい変更系 route を追加する他タスクと同時進行すると gate の inventory で衝突**する（相手側が exemption 登録か認可追加を要求される）。マージ順序を意識すること |

### 実装手順（テストファーストの具体順）

1. 施策 1（enum）
2. 施策 2（gate テスト）を書き、`api.v1.projects.items.store/update/destroy` の 3 本が
   **未分類で fail する**ことを確認 ← *gate が実害を検出できることの証明*
3. 施策 5（OAuth helper 昇格）+ 既存 OAuth テスト green 確認
4. 施策 4（`ItemAuthorizationTest`）を書き、viewer ケースが **fail する**ことを確認
   ← *実害の再現*
5. 施策 3（`Api\V1\ItemController` に `Gate::forUser`）を実装 → 2 と 4 が green になる
6. 施策 6（ドキュメント）
7. `composer test` / `composer phpstan` / `vendor/bin/pint --test` 全 green

### 検証コマンド

```bash
composer test           # Pest 全件 (T099 グローバルロック経由で直列化)
composer phpstan        # level 10
vendor/bin/pint --test  # フォーマット
```

フロント変更は無いため `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` は
影響を受けないが、コミット前の全 green 規約に従い一通り実行する。

### DESIGN.md / Atomic Design 準拠

本設計に **UI / frontend の変更は一切含まれない**（`resources/js/` は無変更）。
design token / Atomic Design 階層への影響なし。


---

## 関連する現行コード

### app/Http/Controllers/Api/V1/ItemController.php (現行・全文)
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreItemRequest;
use App\Http\Requests\Projects\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use App\Models\Item;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Webmozart\Assert\Assert;

/**
 * REST API v1 の Item CRUD (nested route: /projects/{project}/items)。
 *
 * FormRequest は web と同じ StoreItemRequest / UpdateItemRequest を再利用する
 * (ProhibitsProtectedKeys = project_id を payload で送ると 422)。
 * URL 整合 guard 2 段 ({project} ∈ org, {item} ∈ project) はいずれも認可より前に 404。
 */
class ItemController extends Controller
{
    use ResolvesApiOrganization;

    /** GET /api/v1/projects/{project}/items */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);

        $items = $project->items()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return ItemResource::collection($items);
    }

    /** POST /api/v1/projects/{project}/items — 親 FK は URL から導出し relation 経由で代入 */
    public function store(StoreItemRequest $request, Project $project): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        $item = $project->items()->create(['name' => $name, 'note' => $note]);

        return ItemResource::make($item)->response()->setStatusCode(201);
    }

    /** PATCH /api/v1/projects/{project}/items/{item} */
    public function update(UpdateItemRequest $request, Project $project, Item $item): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        $this->resolveProjectItem($project, $item);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        $item->fill(['name' => $name, 'note' => $note])->save();

        return ItemResource::make($item)->response();
    }

    /**
     * DELETE /api/v1/projects/{project}/items/{item}
     * Idempotency-Key の再生対象にするため 204 ではなく JSON body を返す。
     */
    public function destroy(Request $request, Project $project, Item $item): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        $this->resolveProjectItem($project, $item);

        $item->delete();

        return JsonResource::make(['deleted' => true])->response();
    }
}
```

### app/Http/Controllers/Projects/ItemController.php (web 側・比較対象・全文)
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreItemRequest;
use App\Http\Requests\Projects\UpdateItemRequest;
use App\Models\Item;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Webmozart\Assert\Assert;

/**
 * Item (Project 配下サンプルリソース) の書き込み操作。
 * 一覧表示は Projects/Show が担うため Index / Show アクションは持たない。
 *
 * nested route (/projects/{project}/items/{item}) の URL 整合は 2 層:
 * 1. {project} が current org に属するか (resolveOrganizationProject = inline guard)
 * 2. {item} が {project} に属するか (routes 側の Route::scopeBindings() =
 *    $project->items() 経由で解決。不整合は routing 層で 404)
 * いずれも**認可より前に 404** (403 で存在を漏らさない)。
 */
class ItemController extends Controller
{
    use ResolvesCurrentOrganization;

    /** Item 作成。project_id は URL から導出し relation 経由で代入する (payload では 422) */
    public function store(StoreItemRequest $request, Project $project): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [Item::class, $project]);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        // 親 FK は relation 経由で明示代入 (mass assignment しない)
        $project->items()->create(['name' => $name, 'note' => $note]);

        return back()->with('success', 'アイテムを追加しました');
    }

    /** Item 更新 (name / note) */
    public function update(UpdateItemRequest $request, Project $project, Item $item): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({item} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $item);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        $item->fill(['name' => $name, 'note' => $note])->save();

        return back()->with('success', 'アイテムを更新しました');
    }

    /** Item 削除 */
    public function destroy(Request $request, Project $project, Item $item): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({item} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $item);

        $item->delete();

        return back()->with('success', 'アイテムを削除しました');
    }
}
```

### app/Http/Controllers/Api/V1/Concerns/ResolvesApiOrganization.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Item;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * REST API v1 の組織コンテキスト解決 + URL 整合 guard の helper 集。
 *
 * `organization` attribute は API キー経路では ApiKeyGuard が、OAuth user-token 経路では
 * ResolveApiActor middleware が注入する。attribute が無いのは配線ミス (route に
 * auth guard / resolve.api-actor が無い) であり、Assert で fail-fast させる。
 * actor 自体が必要な場合は ReadsApiActor (api_actor attribute) を使う。
 */
trait ResolvesApiOrganization
{
    private function resolveOrganization(Request $request): Organization
    {
        $organization = $request->attributes->get('organization');
        Assert::isInstanceOf(
            $organization,
            Organization::class,
            'Organization attribute missing. Ensure the auth guard / resolve.api-actor middleware runs first.',
        );

        return $organization;
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {project} が API キーの組織に属さなければ
     * **認可より前に 404** (cross-org は 404。403 で存在を漏らさない)。
     * 所属確認は relation (Organization::projects = CustomTeam 経由) のみで行う (直 fetch 禁止)。
     */
    private function resolveOrganizationProject(Organization $organization, Project $project): Project
    {
        abort_unless(
            $organization->projects()->whereKey($project->getKey())->exists(),
            404,
        );

        return $project;
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {item} が {project} に属さなければ 404。
     */
    private function resolveProjectItem(Project $project, Item $item): Item
    {
        abort_unless(
            $project->items()->whereKey($item->getKey())->exists(),
            404,
        );

        return $item;
    }
}
```

### app/Http/Controllers/Api/V1/Concerns/ReadsApiActor.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Auth\Context\ApiActorContext;
use App\Http\Middleware\ResolveApiActor;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * controller が解決済み {@see ApiActorContext} を request attribute から取り出す薄い helper。
 *
 * 再判定はしない (= actor 解決の single source は `resolve.api-actor` middleware)。
 * middleware を経ていなければ Assert で fail-secure に 500 になる (route 配線で必ず経る)。
 */
trait ReadsApiActor
{
    protected function apiActor(Request $request): ApiActorContext
    {
        $context = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        Assert::isInstanceOf(
            $context,
            ApiActorContext::class,
            'ResolveApiActor middleware must populate request attribute "api_actor".',
        );

        return $context;
    }
}
```

### app/Auth/Context/ApiActorContext.php
```php
<?php

declare(strict_types=1);

namespace App\Auth\Context;

use App\Http\Middleware\ResolveApiActor;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;

/**
 * 解決済みの REST API v1 actor。
 *
 * {@see ResolveApiActor} が actor 解決後に request attribute
 * `api_actor` へ staple する。controller / ability middleware は本 readonly
 * 値オブジェクトを読むだけで、`ApiKey|User` の union 生値を扱わない
 * (= 認可・tenancy 判断の single source)。
 *
 * `user` は **非 null**: API キーの issuedBy が削除済 (= null) の場合は middleware が
 * 403 で弾くため、ここに到達した時点で user は確定している。
 */
final readonly class ApiActorContext
{
    public function __construct(
        public ApiActorKind $kind,
        public User $user,
        public Organization $organization,
        public ApiScopeSet $scopes,
        public ?string $oauthSessionId,
        public ?ApiKey $apiKey,
    ) {}

    public function hasScope(string $scope): bool
    {
        return $this->scopes->has($scope);
    }

    public function organizationId(): int
    {
        return $this->organization->id;
    }

    public function isApiKey(): bool
    {
        return $this->kind === ApiActorKind::ApiKey;
    }

    public function isUserToken(): bool
    {
        return $this->kind === ApiActorKind::UserToken;
    }
}
```

### app/Policies/ItemPolicy.php
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\Project;
use App\Models\User;

/**
 * Item (Project 配下のサンプルリソース) の認可。
 * 子リソースは親 Policy に委譲する (07 ガイド §2: 親の Policy を経由して org 所属を確認)。
 *
 * - 閲覧: プロジェクトを閲覧できる人
 * - 作成・更新・削除: プロジェクトを操作 (update) できる人
 */
class ItemPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 閲覧: プロジェクトを閲覧できる人 */
    public function view(User $user, Item $item): bool
    {
        $project = $item->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }

    /** 作成: プロジェクトを操作できる人 (対象 Item が無いため Project を追加引数に取る) */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 更新: プロジェクトを操作できる人 */
    public function update(User $user, Item $item): bool
    {
        $project = $item->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 削除: プロジェクトを操作できる人 */
    public function delete(User $user, Item $item): bool
    {
        $project = $item->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }
}
```

### app/Policies/ProjectPolicy.php
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/**
 * プロジェクトの認可。組織所属の確認は親 (Organization) 経由で行う (直 fetch 禁止)。
 *
 * - 閲覧: 組織メンバーなら可 (組織管理者は配下プロジェクトに暗黙アクセス = 継承規則)
 * - 作成: 組織の owner / admin
 * - 更新・削除: 組織の owner / admin、または当該プロジェクトの project_admin
 *
 * viewAny / create は対象 Project が無いため Organization を追加引数に取る
 * (Gate::authorize('create', [Project::class, $organization]))。
 */
class ProjectPolicy
{
    /** 一覧閲覧: 組織メンバーなら可 */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization) !== null;
    }

    /** 閲覧: 所属組織のメンバーなら可 */
    public function view(User $user, Project $project): bool
    {
        $organization = $project->organization;

        return $organization !== null && $user->organizationRole($organization) !== null;
    }

    /** 作成: 組織の owner / admin */
    public function create(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization)?->canManage() ?? false;
    }

    /** 更新: 組織の owner / admin または project_admin */
    public function update(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /** 削除: 組織の owner / admin または project_admin */
    public function delete(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /**
     * 撮影 (take の capture/upload/adopt): 管理権限者または project メンバー
     * (doc/10 §10.5 撮影者)。TakePolicy が全 ability を本メソッドへ委譲する。
     */
    public function capture(User $user, Project $project): bool
    {
        if ($this->canManageProject($user, $project)) {
            return true;
        }

        $organization = $project->organization;
        if ($organization === null || $user->organizationRole($organization) === null) {
            return false; // cross-org 不変条件
        }

        return $project->memberRole($user) !== null; // Admin / Member どちらも撮影可
    }

    /**
     * プロジェクト管理権限の判定。
     * 組織ロールは laratrust_team_id 明示 (organizationRole)、
     * プロジェクトロールは project_members pivot (memberRole) で判定する。
     */
    private function canManageProject(User $user, Project $project): bool
    {
        $organization = $project->organization;
        if ($organization === null) {
            return false;
        }

        if ($user->organizationRole($organization)?->canManage() ?? false) {
            return true;
        }

        // 組織メンバーでなければ project ロールがあっても不可 (cross-org 不変条件)
        if ($user->organizationRole($organization) === null) {
            return false;
        }

        return $project->memberRole($user) === ProjectRole::Admin;
    }
}
```

### tests/Architecture/NestedRouteIdorDefenseTest.php (踏襲すべき既存 inventory 作法・全文)
```php
<?php

declare(strict_types=1);

use App\Enums\Security\NestedRouteDefenseMode;
use Illuminate\Support\Facades\Route;

/**
 * nested route (親子) IDOR 防御の網羅性 invariant。
 *
 * 「子リソースを URL で受ける route は、子が必ず URL 親/テナントに属することを構造的に担保し、
 * 不整合は認可より前に 404 (403 で存在を漏らさない)」という不変条件を、各 route が
 * どの防御方式 (NestedRouteDefenseMode) で守っているかを deny-by-default で機械検証する。
 *
 * 本テストは「分類漏れ・drift を落とす」役割に限定する (inline guard の静的正当性は証明しない)。
 * 実挙動 (不整合→404 等) は scopeBindings の Routing 層 enforcement と各 Feature テスト
 * (UrlIntegrityGuardTest / OrganizationBoundaryNotFoundTest 等) が担保する。
 *
 * 2 個以上の route パラメータを取る named route を全て候補とし、inventory (防御方式付き) か
 * prefixExemptAllowlist (親子テナントでない理由付き) のどちらかに必ず分類させる。
 */

/**
 * route 名 => 防御方式の明示 inventory (型付き)。
 *
 * @return array<string, NestedRouteDefenseMode>
 */
function nestedRouteIdorInventory(): array
{
    $s = NestedRouteDefenseMode::ScopeBindings;
    $g = NestedRouteDefenseMode::UrlIntegrityGuard;

    return [
        // --- Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404) ---
        // {apiKey} は $organization->apiKeys() 経由 ({organization} 自体は
        // MembershipScopedOrganizationBinder が membership スコープで解決)
        'organizations.api-keys.revoke' => $s,
        // {oauthSession} は $organization->oauthSessions() 経由 (WP24。controller 内の
        // organization_id 再検査は二重防御)
        'organizations.api-keys.sessions.revoke' => $s,
        // {invitation} は $organization->invitations() 経由 (招待取り消し。cross-org は 404)
        'organizations.invitations.revoke' => $s,
        // {item} は $project->items() 経由 ({project} ∈ current org は
        // project.in-route-org middleware + controller inline guard の 2 層)
        'projects.items.update' => $s,
        'projects.items.destroy' => $s,
        // {category} は $project->categories() 経由 ({project} ∈ current org は
        // project.in-route-org middleware + controller inline guard の 2 層。
        // FormRequest の DB ルール (unique) より前の 404 は ProjectRouteCurrentOrgGuardTest 参照)
        'projects.categories.update' => $s,
        'projects.categories.destroy' => $s,
        // {manual} は $project->manuals() 経由 (relation 名は route パラメータ {manual} の
        // scopeBindings 推論と一致させた manuals()。{project} ∈ current org は
        // project.in-route-org middleware + inline guard の 2 層)
        'projects.manuals.show' => $s,
        'projects.manuals.edit' => $s,
        'projects.manuals.update' => $s,
        // シナリオ document 保存 (PUT)。{manual} は $project->manuals() 経由 (scopeBindings)
        'projects.manuals.scenario.update' => $s,
        'projects.manuals.destroy' => $s,
        'projects.manuals.duplicate' => $s, // {manual} は $project->manuals() 経由 (保存済み cuts を複製)
        // SOP アップロード / AI 解析 / job ポーリング ({manual} は $project->manuals()、
        // {analysisJob} は $manual->analysisJobs() 経由。不整合は認可より前に 404)
        'projects.manuals.source-documents.store' => $s,
        'projects.manuals.analyze' => $s,
        'projects.manuals.jobs.show' => $s,
        // レンダ/プレビュー/ポーリング/再生/DL ({manual} は $project->manuals()、
        // {renderJob} は $manual->renderJobs() 経由。不整合は認可より前に 404。§10.3)
        'projects.manuals.render' => $s,
        'projects.manuals.preview' => $s,
        'projects.manuals.render-jobs.show' => $s,
        'projects.manuals.render-jobs.playback' => $s,
        'projects.manuals.download' => $s,
        // 撮影 PWA (/app/*。doc/10 §10.8-3)。{manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は
        // scopeBindings + 各書き込み Service の tx 内連鎖再解決 (二重防御)。
        // {project} ∈ current org は project.in-route-org middleware + inline guard の 2 層
        'capture.manuals.show' => $s,
        'capture.takes.upload-url' => $s,
        'capture.takes.store' => $s,
        'capture.takes.update' => $s,
        'capture.takes.destroy' => $s,
        'capture.takes.adopt' => $s,
        'capture.takes.downloaded' => $s,
        'capture.takes.playback' => $s,
        // --- inline 親子整合 guard (authorize 前に 子∈親テナント を検査、不整合は 404) ---
        // OrganizationMemberController::resolveOrganizationMember (非 member は 404)
        'organizations.members.update' => $g,
        'organizations.members.destroy' => $g,
        'organizations.members.two-factor.reset' => $g,
        // ProjectMemberController::destroy (org 越境 {user} は 404)
        'projects.members.destroy' => $g,
        // REST API v1: API キーの組織 relation からの org-scoped 解決
        // (ResolvesApiOrganization。cross-org は認可より前に 404)
        'api.v1.projects.items.update' => $g,
        'api.v1.projects.items.destroy' => $g,
    ];
}

/**
 * 2+param だが「親子 IDOR の対象外」と明示する route (理由付き、真の deny-by-default sentinel)。
 *
 * @return array<string, string>
 */
function nestedRoutePrefixExemptAllowlist(): array
{
    return [
        'social.redirect' => 'auth/{provider}/redirect/{intent}: いずれも config 由来の固定集合で検証・テナント親子でない',
        'verification.verify' => 'email/verify/{id}/{hash}: 署名付き URL (MustVerifyEmail)・テナント親子でない',
    ];
}

/** @return list<Illuminate\Routing\Route> parameterNames>=2 の候補 route (パッケージ内部 route は除外)。 */
function nestedRouteCandidates(): array
{
    $candidates = [];
    foreach (Route::getRoutes() as $route) {
        if (count($route->parameterNames()) < 2) {
            continue;
        }

        // パッケージ管理ルート (Filament/Livewire/Passport 内部) はパッケージ側が防御を担うため
        // 対象外。アプリが定義するルートのみ検査する。
        $name = $route->getName();
        if (str_starts_with($route->uri(), 'livewire')
            || ($name !== null && (str_starts_with($name, 'filament.')
                || str_starts_with($name, 'livewire.')
                || str_starts_with($name, 'passport.')))) {
            continue;
        }

        $candidates[] = $route;
    }

    return $candidates;
}

test('2+param 候補 route は inventory か exemptAllowlist に明示分類されている (未知は fail)', function (): void {
    $inventory = nestedRouteIdorInventory();
    $allow = nestedRoutePrefixExemptAllowlist();
    $violations = [];

    foreach (nestedRouteCandidates() as $route) {
        $name = $route->getName();
        if ($name === null) {
            $violations[] = '無名の 2+param route: '.$route->uri().' (name を付け inventory 登録してください)';

            continue;
        }
        if (array_key_exists($name, $inventory) || array_key_exists($name, $allow)) {
            continue;
        }
        $violations[] = $name.' ('.$route->uri().') が未分類';
    }

    expect($violations)->toBe([],
        '未分類の親子候補 route があります。nestedRouteIdorInventory() に防御方式を登録するか、'
        .'親子テナントでなければ nestedRoutePrefixExemptAllowlist() に理由付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('inventory/allowlist の key は現存 named route (逆方向整合・stale 検出)', function (): void {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        $n = $route->getName();
        if ($n !== null) {
            $named[$n] = true;
        }
    }

    $stale = [];
    foreach ([
        ...array_keys(nestedRouteIdorInventory()),
        ...array_keys(nestedRoutePrefixExemptAllowlist()),
    ] as $key) {
        if (! isset($named[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'inventory/allowlist に現存しない route 名 (削除/rename 済): '.implode(', ', $stale));
});

test('inventory の各値は NestedRouteDefenseMode', function (): void {
    foreach (nestedRouteIdorInventory() as $mode) {
        expect($mode)->toBeInstanceOf(NestedRouteDefenseMode::class);
    }
});
```

### tests/Architecture/ManageRouteAuthGuardTest.php (既存・層1)
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * 管理メニュー (/manage/*) の guard invariant (deny-by-default)。
 *
 * /manage/ 配下の全 named route は auth + verified middleware を持たなければならない
 * (管理メニューは PII (メンバー email) を含む管理者専用画面群。将来 /manage/ 配下へ
 * route を足したときの guard 漏れを構造的に落とす)。
 * 認可 (manageMembers 等) は各 Controller の Gate::authorize の責務 (Feature テストで固定)。
 */
test('/manage/ 配下の全 route は auth + verified middleware を持つ', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'manage/')) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();

        foreach (['auth', 'verified'] as $required) {
            if (! in_array($required, $middleware, true)) {
                $violations[] = "route {$name} に {$required} middleware が無い";
            }
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    // route が 1 本も検査されない (= /manage/ route が消えた/リネームされた) 場合も fail させ、
    // テスト自体の空振り drift を検知する
    expect($checked)->toBeGreaterThan(0);
});
```

### app/Enums/Security/NestedRouteDefenseMode.php (enum 配置の先例)
```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * nested route (親子) の IDOR 防御方式。
 *
 * 子リソースを URL で受ける route が「子は必ず URL 上の親 (またはテナント) に属する」不変条件を
 * どの機構で担保しているかを明示分類する。`NestedRouteIdorDefenseTest` の inventory が本 enum を
 * 値に持ち、2 個以上の route パラメータを取る named route を deny-by-default で分類漏れ・drift
 * から守る。
 *
 * テンプレートは `Route::scopeBindings()` を既定 (主防御) とする (親 relation 経由で子を解決し、
 * 不整合は認可より前に 404)。model binding にならない子 (payload 由来・文字列 token 等) や
 * 解決順序の都合で scopeBindings に乗らない route のみ inline guard を使う。
 * アプリ固有の防御方式が必要になったら case を追加し、docs/template-divergence.md に記録する。
 */
enum NestedRouteDefenseMode: string
{
    /** Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404)。テンプレートの主防御。 */
    case ScopeBindings = 'scope_bindings';

    /** route-model binding + inline 親子整合 guard (authorize より前に子∈親/テナントを検査し不整合は 404)。 */
    case UrlIntegrityGuard = 'url_integrity_guard';
}
```

### app/Exceptions/ApiExceptionRenderer.php (403/404 の envelope 変換。抜粋)
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Http\Resources\ApiErrorResource;
use App\Support\Api\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * framework 例外 → 統一 REST API v1 エラー envelope ({error: {code, message, status, details?}})。
 *
 * `api/*` パスのリクエストのみ書き換える。Inertia / web リクエストは既存の
 * レンダリング経路 (Error ページ / redirect) を保つため対象外。
 * bootstrap/app.php の withExceptions で render に配線する。
 */
final class ApiExceptionRenderer
{
    public static function shouldHandle(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldHandle($request)) {
            return null;
        }

        // HttpResponseException は自前の response を持つ (FormRequest 由来等) ため尊重する
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $error = self::toApiError($e);

        return ApiErrorResource::make($error)
            ->response()
            ->setStatusCode($error->status)
            ->withHeaders(self::extraHeaders($e));
    }

    private static function toApiError(Throwable $e): ApiError
    {
        if ($e instanceof AuthenticationException) {
            return ApiError::fromCode(
                ApiErrorCode::Unauthenticated,
                message: $e->getMessage() !== '' ? $e->getMessage() : null,
            );
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return ApiError::fromCode(
                ApiErrorCode::Forbidden,
                message: $e->getMessage() !== '' ? $e->getMessage() : null,
            );
        }

        // cross-org は認可より前に 404 (存在を漏らさない) — メッセージも固定文言に collapse する
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiError::fromCode(ApiErrorCode::NotFound);
        }

        if ($e instanceof ValidationException) {
            /** @var array<string, mixed> $errors */
            $errors = $e->errors();

            return ApiError::fromCode(
```

### routes/api.php (全文)
```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\Me\RevokeSessionController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\VersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API v1 Routes
|--------------------------------------------------------------------------
|
| `/api` prefix 配下 (bootstrap/app.php の withRouting)。認証は dual guard
| `auth:api-key,api-oauth` (組織スコープの API キー + OAuth user token。guard 順序は
| api-key 先 = 自動化トラフィックが先に解決)。middleware の順序契約:
|
|   auth:api-key,api-oauth → throttle:{bucket} → resolve.api-actor
|     → api-key.ability:{ability} → (idempotent) → controller
|
| resolve.api-actor が両経路を ApiActorContext (request attribute 'api_actor') に
| 正規化する (OAuth 経路の cli:use scope / session 束縛 / membership 再検証もここ)。
| throttle を ability の前に置くことで、ability 不足のリクエストも throttle
| カウントに入る (認証通過後のコスト系エンドポイントへの DoS 耐性)。
| rate limit バケットは 4 つ (AppServiceProvider 定義): api-read / api-write /
| api-status / api-mcp。新バケットの追加は要件に明示的根拠があるときだけ
| (docs/app-integration-guide.md §5)。
|
| guard 3 分類 (dual / oauth 単独 / public) は
| tests/Architecture/ApiGuardAllowlistInvariantTest が deny-by-default で固定する。
| route 名規約: `api.v1.{resource}.{action}`。パラメータ付き route は
| tests/Architecture/NestedRouteIdorDefenseTest の inventory に防御モードを登録する。
| binding param の型制約 (旧 ->whereNumber) は App\Http\Routing\RouteBindingTypes に集約
| (route 個別の where は書かない。18 桁上限で 22P02 / 22003 の両方を塞ぐ)。
| MCP エンドポイント (/api/v1/mcp) は routes/ai.php で登録される。
*/

// 公開 (未認証) エンドポイント
Route::prefix('v1')
    ->middleware(['throttle:api-status'])
    ->group(function (): void {
        Route::get('/version', [VersionController::class, 'show'])
            ->name('api.v1.version');
    });

// OAuth user token 単独 (API キーにセッション概念は無いため dual guard に含めない。
// API キー Bearer は guard 段 401 でエラー契約を混線させない)
Route::prefix('v1')
    ->middleware(['auth:api-oauth', 'throttle:api-write', 'resolve.api-actor'])
    ->group(function (): void {
        Route::delete('/me/session', [RevokeSessionController::class, 'destroy'])
            ->name('api.v1.me.session.revoke');
    });

// 読み取り (read ability)
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor', 'api-key.ability:read'])
    ->group(function (): void {
        Route::get('/me', [MeController::class, 'show'])
            ->name('api.v1.me');

        Route::get('/projects', [ProjectController::class, 'index'])
            ->name('api.v1.projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->name('api.v1.projects.show');

        Route::get('/projects/{project}/items', [ItemController::class, 'index'])
            ->name('api.v1.projects.items.index');
    });

// 書き込み (write ability)。全 write エンドポイントに Idempotency-Key を配線する
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write', 'idempotent'])
    ->group(function (): void {
        Route::post('/projects/{project}/items', [ItemController::class, 'store'])
            ->name('api.v1.projects.items.store');
        Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
            ->name('api.v1.projects.items.update');
        Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
            ->name('api.v1.projects.items.destroy');
    });
```

### tests/Pest.php の helper 抜粋 (L130-260)
```php
 * 有償プラン契約状態を検証するテストは contractPaidPlan() を併用する。
 *
 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($grandfatherFreePlan) {
        $organization->forceFill([
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
        ])->save();
    }

    return [$organization, $owner];
}

/**
 * recent-auth (step-up) を確実に満たす fresh session 値。
 * 窓は config('auth.recent_auth_timeout')(既定 900s)。注入時点の elapsed≈0 で窓に対し十分 fresh。
 * recent-auth を要する route を「step-up 済み相当」で叩くテストは withSession() でこれを注入する。
 *
 * @return array{recent_auth_at: int}
 */
function freshRecentAuthSession(): array
{
    return ['recent_auth_at' => now()->timestamp];
}

/**
 * 組織を有償プラン契約状態にする (plan_code + Cashier subscription 行)。
 * plan_code は $fillable 外の状態キー (webhook 同期のみ) のため forceFill で明示代入。
 * BillingAccess は plan_code 非 null の組織にのみ active/trialing subscription を要求する。
 *
 * plan_code は PlanSeeder が投入する有償プラン code ('standard') を使う
 * (プラン名分岐ではなく seeded fixture の参照。アプリコードには入らない)。
 */
function contractPaidPlan(Organization $organization, string $status = 'active'): Subscription
{
    $organization->forceFill(['plan_code' => 'standard'])->save();

    return createFakeSubscription($organization, status: $status);
}

/**
 * テスト用の Cashier subscription 行を直接作成する (Stripe には到達しない)。
 * BillingAccess (課金ゲート) は plan_code 非 null の組織に対して stripe_status が
 * active / trialing のとき許可する (plan_code null = free tier は行の有無に依らず許可)。
 */
function createFakeSubscription(
    Organization $organization,
    string $status = 'active',
    string $type = 'default',
): Subscription {
    /** @var Subscription $subscription */
    $subscription = $organization->subscriptions()->create([
        'type' => $type,
        'stripe_id' => 'sub_test_'.Str::random(24),
        'stripe_status' => $status,
        'quantity' => 1,
    ]);

    return $subscription;
}

/**
 * 組織にメンバーを追加する (attach + laratrust_team_id 明示のロール付与)。
 */
function attachOrganizationMember(
    Organization $organization,
    OrganizationRole $role = OrganizationRole::Member,
): User {
    $user = User::factory()->create();
    $organization->users()->attach($user);
    $user->addRole($role->value, $organization->laratrust_team_id);

    return $user;
}

/**
 * 組織スコープの API キーを発行する (REST API / MCP テスト用。平文付きで返す)。
 *
 * @param  list<string>  $abilities
 * @return array{ApiKey, string} [apiKey, plainKey]
 */
function issueApiKey(
    Organization $organization,
    User $createdBy,
    array $abilities = ['read', 'write'],
    string $name = 'テストキー',
): array {
    $generated = ApiKey::generatePlainKey();
    $apiKey = ApiKey::createForOrganization(
        $organization,
        $createdBy,
        $name,
        $abilities,
        $generated['prefix'],
        ApiKey::hashSecret($generated['secret']),
    );

    return [$apiKey, $generated['plain']];
}

/**
 * プロジェクトにメンバーを追加する (project_members pivot にロール付きで attach)。
 * プロジェクトロールは組織メンバーであることが前提 (Policy 側でも組織所属を確認する)。
 */
function attachProjectMember(
    Project $project,
    User $user,
    ProjectRole $role = ProjectRole::Member,
): void {
    $project->members()->attach($user, ['role' => $role->value]);
}

/**
 * storage fake を有効化する (Feature テスト用)。
 *
 * config('testing.fake_storage')=true にした上で **provider 自身を再実走** させ、
 * bind と signed route を確立する (手動 bind/route 再実装は provider の欠陥を隠すため禁止)。
 * app env は phpunit.xml の testing + runningUnitTests()===true のため FakeStorageGate が成立する。
 * s3_fake disk は Storage::fake で tmp へ隔離し、実 s3 disk は放置 =
 * もし実 S3 に触れたら即例外になる (fake が実 S3 非依存であることの negative 担保)。
 *
 * 各テストは setUp の refreshApplication で fresh app + fresh config を得るため、
 * 明示的な env/config の後始末は不要 (テスト間リークしない)。
 */
function enableFakeStorage(): void
```

