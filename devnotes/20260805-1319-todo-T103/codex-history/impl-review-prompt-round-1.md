## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

---

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

---

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

---

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel 12 + Svelte 5 + Inertia のアプリ改善実装のコードレビュアー。
本 diff は **T103: 変更系 route の認可 gate 新設 + API 認可漏れ/存在オラクルの是正** の実装である。

## レビュー観点

1. **設計との一致性**: 詳細設計書 (APPROVED Round 3) の 7 施策が忠実に実装されているか。
   意図的な逸脱があるならそれが正当化されるか
2. **正確性**: 特にセキュリティ機構としての正しさ
   - 層 2 (テナント境界 = 404) が層 3 (認可 = 403) より前か
   - 存在オラクル (cross-org の実在/不在が 422/404 の差分で漏れる) が本当に閉じたか
   - `Gate::forUser` を使う理由 (dual guard で `Auth::user()` が ApiKey を返し Policy が TypeError=500)
   - gate (`ControllerAuthorizationGateTest`) が **誤合格 (false negative)** しない設計か。
     deny-by-default の gate では誤合格が最悪の失敗モードである
   - `AuthorizationMarkerScanner` のトークン解析にバイパス経路がないか
     (合格させてはいけない書き方で合格しないか / 逆に正当な書き方を落としすぎないか)
3. **PHPStan level 10 適合性** (widen / baseline / @phpstan-ignore は禁止)
4. **DTO / JsonResource パターン** (`response()->json()` 直書き禁止)
5. **テスト網羅性**: 各施策にテストがあるか。drift ガード (空振り検出) が実効か
6. **セキュリティ**: AGENTS.md のセキュリティ不変条件を壊していないか
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 本 diff に frontend 変更は無い (resources/js は無変更) ため該当なし

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical: セキュリティ・正確性の欠陥、設計との重大な乖離
  - Warning: 品質・保守性の問題
  - Suggestion: 改善提案
- 最後に **全体判定: APPROVED / CHANGES_REQUESTED** を明記する


---

## 詳細設計書 (APPROVED Round 3)

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
| 3 | **API `{project}` の存在オラクル封じ** (URL 整合 guard を FormRequest より前へ) | `app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php` (新規) / `bootstrap/app.php` / `routes/api.php` / `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` / `tests/Architecture/NestedRouteIdorDefenseTest.php` | **最高** |
| 4 | `Api\V1\ItemController` に `Gate::forUser` 認可を追加 | `app/Http/Controllers/Api/V1/ItemController.php` | 高 |
| 5 | `ItemAuthorizationTest` 新設 (Feature) | `tests/Feature/Api/V1/ItemAuthorizationTest.php` (新規) | 高 |
| 6 | OAuth CLI セッション helper の Support 昇格 (施策 5 の前提) | `tests/Support/OAuthTestHelpers.php` / `tests/Feature/Api/OAuthDualGuardTest.php` | 中 |
| 7 | ドキュメント更新 (不変条件 + チェックリスト) | `docs/app-integration-guide.md` / `docs/architecture.md` | 中 |

**実装順序**: 「テストファースト」の原則により **5 (fail を確認) → 4 (実装)**。
1 → 2 は 2 が 1 に依存するため 1 が先。6 は 5 の前提。
**施策 3 は施策 4 より先**に入れる（層 2 を先に閉じてから層 3 を足す = 順序不変条件を守る）。

> ### 施策 3 が追加された経緯（Codex 詳細設計レビュー Round 1 [Critical]）
>
> 「URL 整合 guard は controller inline で認可より前」という当初の前提は**不十分**だった。
> **FormRequest のバリデーションは controller メソッド解決時 = inline guard より前**に走るため、
> cross-org でも FormRequest が先に 422 を返してしまう。実際に probe テストで実測した:
>
> | ケース | 現状の API | 現状の web |
> |---|---|---|
> | cross-org の**実在** project + 不正 payload | **422** | 404 |
> | **存在しない** project id + 不正 payload | 404 | 404 |
> | cross-org の実在 project + 正常 payload | 404 | 404 |
> | cross-org + protected key payload (`project_id`) | **422** | 404 |
> | cross-org item update + 不正 payload | **422** | 404 |
>
> **422 と 404 の差分が「その project が実在するか」の存在オラクル**になっている
> (不変条件 3「cross-org 不可」/ 存在秘匿の違反)。
> これは本設計の変更で生じたものではなく**既存の穴**だが、
> 本設計の中心的な主張が「cross-org は 404 のままでなければならない」である以上、
> 「valid payload に限り 404」という但し書きで妥協することはできない。
>
> web 側は既に **`project.in-route-org` route middleware**
> (`EnsureProjectBelongsToRouteOrganization`) でこの順序ハザードを閉じている
> (middleware は FormRequest 解決より前に走る)。同 middleware の docblock は
> 「API v1 は org を API キーから確定する別レイヤーの責務のため対象外」としており、
> **API 側には等価の防御が用意されないまま残っていた**。施策 3 はこの穴を、
> web と同じ構造 (route middleware) で塞ぐ。

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
  レビュー時は「当てはまる case が無い = 認可を足すべき route」と読む規約を施策 7 のドキュメントに書く。

---

## 施策 2: `ControllerAuthorizationGateTest` 新設 (deny-by-default gate)

### 変更箇所

- ファイル: `tests/Architecture/ControllerAuthorizationGateTest.php`（**新規**）
- ファイル: `tests/Support/AuthorizationMarkerScanner.php`（**新規**。字句解析の純粋 helper）
- ファイル: `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php`（**新規**。helper の positive/negative テスト）

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

**fail-secure の集約**（Codex Round 1 指摘）: 以下はすべて「認可なし」ではなく
**解決失敗として violation に積む**。violation には
**route 名 / URI / HTTP メソッド / 原因**を含めて出力し、沈黙させない:

| 失敗点 | 扱い |
|---|---|
| `getAction('uses')` が `string` でも `Closure` でもない | 解決失敗 |
| `ReflectionMethod` / `ReflectionFunction` の生成が例外 | 解決失敗（例外メッセージを原因に含める） |
| `getFileName()` が `false` (内部関数等) | 解決失敗 |
| `realpath($file)` が `false` (ファイル不在) | 解決失敗 |
| `file($file)` が `false` (読み取り不可) | 解決失敗 |
| `getStartLine()` / `getEndLine()` が `false` | 解決失敗 |
| 切り出した断片が空文字 | 解決失敗 |
| 合格判定したのに `use Illuminate\Support\Facades\Gate;` が無い | 解決失敗（誤合格防止） |

「解決失敗が 1 件でもあれば fail」という専用テストを 1 本立てる（§(g)）。

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

残った**トークン列**に対し、以下を**認可あり**とする:

**(d-1) `Gate::authorize(`** — トークン 4 連 `Gate` `::` `authorize` `(` の完全一致。

**(d-2) `Gate::forUser(...)->authorize(`** — **正規表現は使わない**。
当初案の `/Gate :: forUser .*?-> authorize/` は
`Gate::forUser($u); $other->authorize();` のような**無関係な 2 文でも合格する**
（Codex 指摘。deny-by-default では誤合格が最悪の失敗モード）。
代わりに**トークンの状態機械**で「同一メソッドチェーン」であることを確認する:

```
1. トークン列から `Gate` `::` `forUser` `(` の並びを探す
2. その `(` から括弧の深さを数え、深さが 0 に戻る位置（対応する `)`）を求める
   （引数内のネストした括弧・配列・クロージャを正しくスキップする）
3. その `)` の**直後**のトークンが `->` であり、さらに次が `authorize` であることを要求する
   （`->` と `authorize` の間には何も挟まらない）
4. 途中に `;` が現れたらチェーンは切れているので不合格
```

これにより「`forUser()` の戻り値に対して直接 `authorize()` を呼んでいる」場合だけが合格する。

**(e) `Gate` が Facade であることの確認**
**受理しないもの**（概念設計 §受理する認可手段は `Gate::` ファサード 1 系統だけにする）:
`can:` middleware / `$this->authorize()` / `FormRequest::authorize()` /
membership binder / `resolve*` 系 / `auth` `verified` `recent-auth`
`require-active-subscription` `api-key.ability:*` middleware。

同名の別クラスによる誤合格を防ぐため、`Gate` トークンで合格判定したファイルが
**`use Illuminate\Support\Facades\Gate;` を import していること**を必須とする。
import が無ければ **fail**（合格に倒さない）。

**解析範囲が違う点に注意**（Codex Round 2 指摘）:
`use` 文は**メソッド断片には含まれない**（ファイル冒頭にある）。
したがって解析対象が 2 つに分かれる:

| 検出対象 | 解析範囲 |
|---|---|
| 認可マーカー（`Gate::authorize` 等） | **メソッド断片**（`getStartLine()`〜`getEndLine()`） |
| `use Illuminate\Support\Facades\Gate;` | **ファイル全文**を別途トークン化 |

さらに `T_USE` は 3 用途あり、**名前空間 import だけ**を拾う必要がある:

| 用途 | 形 | 判別 |
|---|---|---|
| 名前空間 import ✔ | `use Illuminate\Support\Facades\Gate;` | 波括弧の深さ **0** かつ直後が `(` ではない |
| クロージャの lexical use ✘ | `function ($x) use ($y) {}` | 直後のトークンが **`(`** |
| trait use ✘ | `class A { use SomeTrait; }` | 波括弧の深さ **1 以上**（クラス本体の中） |

判定手順: ファイル全文をトークン化 → `{` `}` で深さを追跡 → 深さ 0 の `T_USE` で
直後（空白を除く）が `(` でないものだけを名前空間 import とみなし、
続くトークン列が `Illuminate` `\` `Support` `\` `Facades` `\` `Gate`
（PHP 8 では `T_NAME_QUALIFIED` 1 個にまとまる場合もあるため**両形に対応**）であれば合格。

> **前提**: 本リポジトリは**非 bracketed namespace**（`namespace App\Foo;` のセミコロン形式）で
> 統一されている。bracketed namespace（`namespace App { ... }`）を使うと
> 名前空間 import の波括弧深さが 1 になり上記判定が崩れる。
> 現状 1 件も存在せず Pint も非 bracketed を強制するため対応しないが、
> **もし将来 bracketed namespace を導入するなら本 helper の深さ判定を見直すこと**を
> `AuthorizationMarkerScanner` の docblock に注記する。

**完全修飾名 (`\Illuminate\Support\Facades\Gate::authorize`) は受理しない**
（Codex Round 1 指摘: 検出仕様が `Gate :: authorize` 前提なのに FQCN 許容と書くのは矛盾）。
実査した全 46 箇所が import 形式で統一されており、FQCN を許容する必要がない。
検出仕様を単純に保つ方が gate として安全である
（`use` 文の有無という 1 行の検査で Facade 同一性が保証される）。

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

### 解析器を純粋 helper として切り出す（Codex Round 2 指摘）

検出ロジック（(d) マーカー検出 / (e) import 検査）は **gate 自体がセキュリティ機構**であるため、
「一時的にコメントアウトして落ちるか確認する」手動検証では**後の改修に対する回帰が効かない**。
route inventory に依存しない**純粋 helper**として切り出し、直接テストする。

- `tests/Support/AuthorizationMarkerScanner.php`（**新規**。`Tests\Support` 名前空間の final class）

```php
final class AuthorizationMarkerScanner
{
    /** メソッド本体のソース断片に認可マーカー (Gate::authorize / Gate::forUser()->authorize) があるか */
    public static function hasAuthorizationMarker(string $methodSource): bool;

    /** ファイル全文に `use Illuminate\Support\Facades\Gate;` の名前空間 import があるか */
    public static function importsGateFacade(string $fileSource): bool;
}
```

`ControllerAuthorizationGateTest` は本 helper を呼ぶだけにする
（route 走査 = テスト、字句解析 = helper、と責務を分ける）。

- `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php`（**新規**）

| # | 入力 | 期待 |
|---|---|---|
| 1 | `Gate::authorize('update', $item);` | **合格** |
| 2 | `Gate::forUser($user)->authorize('update', $item);` | **合格** |
| 3 | 複数行チェーン（`Gate::forUser($u)\n    ->authorize(...)`） | **合格** |
| 4 | 引数に配列・クロージャ・ネスト括弧（`Gate::forUser($a->b(c($d)))->authorize(...)`） | **合格** |
| 5 | `Gate::forUser($user); $other->authorize('x');` | **不合格**（← 旧正規表現が誤合格していた形） |
| 6 | `// 認可は controller の Gate::authorize が行う`（コメント） | **不合格** |
| 7 | `/** Gate::authorize を通す */`（docblock） | **不合格** |
| 8 | `$msg = 'Gate::authorize';`（文字列リテラル） | **不合格** |
| 9 | `"prefix {$x} Gate::authorize";`（可変長文字列） | **不合格** |
| 10 | `Gate::allows('update', $item);`（authorize でない） | **不合格** |
| 11 | `Gate::forUser($user)->allows(...);` | **不合格** |
| 12 | import 検査: `use Illuminate\Support\Facades\Gate;` | **合格** |
| 13 | import 検査: `use App\Support\Gate;`（同名の別クラス） | **不合格** |
| 14 | import 検査: `function ($x) use ($gate) {}`（lexical use のみ） | **不合格** |
| 15 | import 検査: `class A { use SomeTrait; }`（trait use のみ） | **不合格** |
| 16 | import 検査: import 無しで `Gate::authorize` を書いたファイル | **不合格** |

> ケース 5 と 13-15 が本 helper の**存在理由**。
> ケース 5 は Codex Round 1 が指摘した正規表現の誤合格、
> 13-15 は Round 2 が指摘した `T_USE` の 3 用途混同を、それぞれ恒久的に固定する。

このテストは **DB を使わない Unit テスト**（`tests/Unit/` 配下）で、
文字列を入力に取るため `RefreshDatabase` も route 登録も不要。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`controllerAuthorizationExemptions(): array` + PHPDoc shape、
      `AuthorizationMarkerScanner` の 2 メソッドは `bool`）
- [x] null 安全（`getFileName()` の `false`、`getName()` の `null` を明示分岐して fail に倒す）
- [x] DTO を返している（enum を使用。生文字列の分類にしない）
- [x] Generics の型パラメータが正しい（`array<string, array{ControllerAuthorizationExemption, string}>`）
- [x] `token_get_all()` の戻り値 `array<int, string|array{int, string, int}>` を `is_array()` で narrowing

### テスト計画

- [x] 本施策自体がテスト。**テストファースト**: 先に `Api\V1\ItemController` を未修正のまま
      gate を書き、`api.v1.projects.items.*` の 3 本が**未分類で fail する**ことを確認する
      （= gate が実害を実際に検出できることの証明）。その後 **施策 4** で認可を足すと green になる
- [x] **解析器の positive/negative は恒久自動テスト**
      （`AuthorizationMarkerScannerTest` の 16 ケース。手動のコメントアウト検証に頼らない）
- [x] 空振りしないことの証明: `MUTATING_ROUTE_FLOOR` を一時的に 200 に上げて fail することを
      実装時に 1 度確認する（閾値そのものは恒久テストとして残る）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（DB 非依存の Architecture / Unit テスト）

### リスク

| リスク | 対策 |
|---|---|
| Laravel の route action 正規化仕様に依存する（`__invoke` の `Class@__invoke` 化） | 解決失敗を即 fail にしているため、仕様が変わればテストが赤くなって気づける（沈黙しない） |
| `token_get_all()` が PHP バージョン差でトークン種別を変える | 除去対象は PHP 8 系で安定した基本トークンのみ。`T_NAME_QUALIFIED` 等の新トークンは連結対象に残るだけで判定に影響しない |
| exemption が将来増えて形骸化 | enum の適用条件 docblock + 理由 30 文字以上 + レビュー規約（§6 のドキュメント）で抑制 |

---

## 施策 3: API `{project}` の存在オラクル封じ (URL 整合 guard を FormRequest より前へ)

### 変更箇所

- `app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php`（**新規**）
- `bootstrap/app.php`（middleware alias 登録。L139 付近の `project.in-route-org` の隣）
- `routes/api.php`（`{project}` を持つ group への middleware 付与 + item routes の `scopeBindings()`）
- `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`（API 側の要求を追加）
- `tests/Architecture/NestedRouteIdorDefenseTest.php`（item 2 route の防御方式を更新）

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**（404 は既存 `ApiExceptionRenderer` が
  `NotFoundHttpException` → `ApiErrorCode::NotFound` の固定文言に collapse 済み）
- テストファイル:
  - `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` — **既存テストの意味を変更**
    （API `{project}` route は「middleware を持たない」→「API 用 middleware を**持つ**」）
  - `tests/Architecture/NestedRouteIdorDefenseTest.php` — `api.v1.projects.items.update` /
    `.destroy` の防御方式を `UrlIntegrityGuard` → `ScopeBindings` へ更新（理由コメントも更新）
  - `tests/Feature/Api/ApiEndpointTest.php` — cross-org 404 の既存ケースは**引き続き green**
  - 施策 5 が本施策の挙動（invalid payload でも 404）を Feature で固定する

### 現行の問題（実測値）

`routes/api.php` の write group には `{project}` の org 整合を
**FormRequest より前**に検査する層が無く、`ItemController` の inline guard
（`resolveOrganizationProject`）は FormRequest の**後**に走る。結果:

```
cross-org の実在 project + 不正 payload → 422  ← 存在オラクル
存在しない project id  + 不正 payload → 404
```

`{item}` も同様（`routes/api.php` は `scopeBindings()` を使っておらず、
`resolveProjectItem` の inline guard だけ = FormRequest の後）。

### 変更後コード

#### (1) API 用 middleware（新規）

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API v1 の `{project}` route の URL 整合 guard (middleware 層)。alias: api.project-in-org。
 *
 * web の {@see EnsureProjectBelongsToRouteOrganization} と同じ順序ハザードを API 側で閉じる。
 * cross-org の {project} を「FormRequest のバリデーションを含むあらゆるアプリコードより前に
 * 404」へ落とす。controller の inline guard (resolveOrganizationProject) は認可より前の 404 を
 * 担うが、**FormRequest は controller メソッド解決時 = inline guard より前**に走るため、
 * middleware が無いと「cross-org の実在 project + 不正 payload = 422」「不在 project = 404」の
 * 差分が存在オラクルになる (不変条件 3)。
 *
 * web 版との違いは組織の解決元だけ:
 *  - web: セッションの current org (ResolvesCurrentOrganization)
 *  - API: API キー / OAuth token から確定した request attribute 'organization'
 *         (ApiKeyGuard / ResolveApiActor が注入。ResolvesApiOrganization::resolveOrganization)
 *
 * 順序契約: api グループ (SubstituteBindings) → auth → throttle → resolve.api-actor
 *           → api-key.ability → **api.project-in-org** → idempotent → controller
 * `organization` attribute が前提のため **resolve.api-actor より後**に置く。
 * {project} を持たない route では no-op (group 一括付与を許容し、将来の route 追加時の
 * guard 漏れを防ぐ)。
 *
 * 網羅性は tests/Architecture/ProjectRouteCurrentOrgGuardTest が deny-by-default で固定する。
 * controller の inline guard は二重防御として残す (middleware の付け漏れ・
 * withoutMiddleware への最終防衛線)。
 */
class EnsureProjectBelongsToApiOrganization
{
    use ResolvesApiOrganization;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            $organization = $this->resolveOrganization($request);
            $this->resolveOrganizationProject($organization, $project);
        }

        return $next($request);
    }
}
```

> **trait の再利用について**: `ResolvesApiOrganization` は
> `App\Http\Controllers\Api\V1\Concerns` 名前空間にあるが、middleware から使っても
> 型・依存の問題はない（`Request` しか受け取らない純粋な helper）。
> web 側 `EnsureProjectBelongsToRouteOrganization` も
> `App\Http\Concerns\ResolvesCurrentOrganization` を同様に再利用しており、**先例と一致**する。
> trait を移動すると controller 側の import が全面変更になるため**移動しない**
> （思考原則 2「今必要なものだけ作る」）。

#### (2) alias 登録（`bootstrap/app.php`）

```php
'project.in-route-org' => EnsureProjectBelongsToRouteOrganization::class,
// REST API v1 用 (組織は API キー / OAuth token から確定する)。web 版とは解決元が違うため別 alias
'api.project-in-org' => EnsureProjectBelongsToApiOrganization::class,
```

#### (3) `routes/api.php`

`{project}` を持つ read / write の両 group に付与する（read 群は FormRequest を
持たないため現時点で 422 は起きないが、**将来 FormRequest が足されたときに穴が開かない**
ように group 単位で構造的に閉じる = web 側と同じ方針）。

```php
// 読み取り (read ability)
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor',
                  'api-key.ability:read', 'api.project-in-org'])
    ->group(function (): void { /* ... 変更なし ... */ });

// 書き込み (write ability)
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor',
                  'api-key.ability:write', 'api.project-in-org', 'idempotent'])
    ->group(function (): void {
        Route::post('/projects/{project}/items', [ItemController::class, 'store'])
            ->name('api.v1.projects.items.store');
        // {item} ∈ {project} を **routing 層** (SubstituteBindings) で解決する。
        // FormRequest より前に 404 が確定し、web 側 (routes/web.php) と同じ構造になる。
        Route::scopeBindings()->group(function (): void {
            Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
                ->name('api.v1.projects.items.update');
            Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
                ->name('api.v1.projects.items.destroy');
        });
    });
```

**middleware の位置**: `api.project-in-org` は `resolve.api-actor` より**後**
（`organization` attribute が必要）、`idempotent` より**前**
（cross-org リクエストで idempotency 行を作らせない）に置く。

#### (4) `ProjectRouteCurrentOrgGuardTest` の更新

現行は「API route に `project.in-route-org` が**付いていたら fail**」だけを見ている。
これを維持しつつ「API `{project}` route には `api.project-in-org` が**必ず付く**」を追加する。

```php
if (str_starts_with($route->uri(), 'api/')) {
    // web セッション (current org) 前提の middleware は API に付けてはならない
    if (in_array('project.in-route-org', $middleware, true)) {
        $violations[] = "API route {$name} に web セッション前提の project.in-route-org が付いている";
    }
    // API 版の URL 整合 guard は必須 (FormRequest より前に cross-org を 404 に落とす)
    if (! in_array('api.project-in-org', $middleware, true)) {
        $violations[] = "API route {$name} に api.project-in-org middleware が無い"
            .' (cross-org {project} が FormRequest より前に 404 になりません)';
    }
    $checked++;

    continue;
}
```

docblock の「API v1 は … 対象外」という記述も**書き換える**
（対象外ではなく「API 専用 middleware が必須」に変わるため。
思考原則 3「後方互換の並走を残さない」= 古い説明を残さない）。

**さらに「存在」だけでなく「順序」もテストで固定する**（Codex Round 2 指摘）。
以下はセキュリティ・動作上の契約でありながら、当初案では docblock にしか残っていなかった:

```
resolve.api-actor  <  api-key.ability:*  <  api.project-in-org  <  idempotent
```

| 契約 | 破ったときに起きること |
|---|---|
| `resolve.api-actor` が `api.project-in-org` より**前** | 破ると `organization` attribute が未設定で `Assert` が発火し **全 API `{project}` route が 500** |
| `api.project-in-org` が `idempotent` より**前** | 破ると **cross-org リクエストで idempotency 行が作られる**（cross-org の副作用 = 不変条件 3 に抵触） |

`gatherMiddleware()` は **宣言順**（group middleware → route middleware）の配列を返すので、
`array_search()` の index 比較で検証できる:

> **注意**: Laravel の middleware priority（`$middlewarePriority`）が設定されると
> **最終的な実行順が並べ替えられる**可能性がある。本テストが検証するのは
> **宣言順**であり、現行構成では今回検査する custom middleware
> （`resolve.api-actor` / `api.project-in-org` / `idempotent`）はいずれも
> priority リストに含まれないため宣言順 = 実行順である。
> priority を導入する際は本テストの前提が変わる旨を docblock に明記する。

```php
test('API の {project} route は middleware 順序契約を守る', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        if (! in_array('project', $route->parameterNames(), true)) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();
        $indexOf = static fn (string $needle): int|false => array_search($needle, $middleware, true);

        $guard = $indexOf('api.project-in-org');
        $actor = $indexOf('resolve.api-actor');
        $idempotent = $indexOf('idempotent');

        if ($guard === false) {
            $violations[] = "{$name}: api.project-in-org が無い";

            continue;
        }
        if ($actor === false || $actor > $guard) {
            $violations[] = "{$name}: resolve.api-actor が api.project-in-org より後 "
                .'(organization attribute 未設定で 500 になります)';
        }
        if ($idempotent !== false && $idempotent < $guard) {
            $violations[] = "{$name}: idempotent が api.project-in-org より前 "
                .'(cross-org リクエストで idempotency 行が作られます)';
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    expect($checked)->toBeGreaterThan(0); // 空振り drift ガード
});
```

配置は `ProjectRouteCurrentOrgGuardTest`（`{project}` route の guard 網羅性を担う既存テスト）に追加する。

#### (5) `NestedRouteIdorDefenseTest` inventory の更新

```php
// REST API v1: {item} は $project->items() 経由 (scopeBindings)。
// {project} ∈ API キーの組織は api.project-in-org middleware + controller inline guard の
// 2 層 (いずれも認可より前に 404)
'api.v1.projects.items.update' => $s,   // ← $g から変更
'api.v1.projects.items.destroy' => $s,  // ← $g から変更
```

> **概念設計との差分の明示**: 概念設計では
> 「`NestedRouteIdorDefenseTest` の inventory は変更しない」としていたが、
> 施策 3 で `{item}` の一次防御が inline guard から scopeBindings に変わるため、
> **分類を実態に合わせて更新する**。inventory が実装と乖離したまま残る方が有害である
> （このテストの目的が「分類漏れ・drift を落とす」ことなので、drift を作ってはならない）。
> 両テストが inventory 関数を共有しない方針は維持する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`handle(): Response`）
- [x] null 安全（`$request->route('project')` は `mixed` → `instanceof Project` で narrowing。
      非 Project（未 binding / 別型）なら no-op で素通し = web 版と同じ）
- [x] DTO を返している（middleware のため該当なし。404 は `abort_unless` → 既存 renderer）
- [x] Generics の型パラメータが正しい（`Closure(Request): Response` の PHPDoc）
- [x] trait `ResolvesApiOrganization` の private メソッドを middleware から呼べる
      （trait のメソッドは use した側のメソッドになる。web 版と同じ構造）

### テスト計画

- [x] **再現テストを先に書く**（施策 5 のケース 12-15）: cross-org の実在 project に
      不正 payload / protected key payload を送って **422 が返る**ことを先に確認 → 実装後 404 になる
- [x] 既存テスト `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` を更新
      （API 側の要求追加。更新前に「API route に `api.project-in-org` が無い」で fail することを確認）
- [x] **新規テスト（同ファイル）**: 「API の `{project}` route は middleware 順序契約を守る」。
      実装時に `api.project-in-org` を `resolve.api-actor` より前へ / `idempotent` より後へ
      一時的に動かして fail することを確認する
- [x] 既存テスト `tests/Architecture/NestedRouteIdorDefenseTest.php` の inventory 更新
- [x] 既存テスト `tests/Feature/Api/ApiEndpointTest.php`（cross-org 404 / items CRUD）が green
- [x] 既存テスト `tests/Feature/Api/IdempotencyTest.php` が green
      （`api.project-in-org` を `idempotent` より前に置いたことで、
       正常系の idempotency 挙動が変わらないことを確認）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

| リスク | 影響 | 対策 |
|---|---|---|
| middleware の位置を誤ると `organization` attribute が無く 500 | 全 API `{project}` route が 500 | `resolve.api-actor` より後に置く（`ResolvesApiOrganization::resolveOrganization` は `Assert` で fail-fast するため配線ミスは即座に露見）。加えて **`ProjectRouteCurrentOrgGuardTest` に順序検証テストを追加**して機械固定する（上記 (4)）。`routes/api.php` の順序契約 docblock にも追記する |
| `scopeBindings()` 追加で `{item}` の解決経路が変わる | cross-project item が 404 になる（**意図どおり**）。既存の正常系は relation 経由でも同じ行を返すため不変 | 既存 `ApiEndpointTest` の items CRUD / cross-org ケースが回帰検知 |
| read group への付与で GET が 404 になるケースが増える | cross-org GET は**元から 404**（inline guard）なので挙動不変 | `ApiEndpointTest` の GET ケースで確認 |
| **性能**: `{project}` 1 本につき `exists()` クエリ 1 回が増える | inline guard と合わせて 2 回になる | 二重防御の意図的なコスト（web 側も同じ 2 層構造で運用中）。index 済みの主キー検索のため実害なし |

---

## 施策 4: `Api\V1\ItemController` に `Gate::forUser` 認可を追加

### 変更箇所

- ファイル: `app/Http/Controllers/Api/V1/ItemController.php`
  - `store` (L46-59) / `update` (L62-76) / `destroy` (L82-91)

### 波及変更

- TypeScript 型定義: **なし**（REST API v1 は機械向け。Inertia props に出ない）
- API Resource/DTO: **なし**。403 は既存 `ApiExceptionRenderer` が
  `AuthorizationException` → `ApiErrorCode::Forbidden` の統一 envelope に変換する（実装済み経路）
- テストファイル: 施策 5（新規）。既存 `tests/Feature/Api/ApiEndpointTest.php` /
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

- [x] **バグ修正のため再現テストを先に書く**（施策 5）。viewer が 200/201 を得てしまう
      現状を先に fail させてから本施策を実装する
- [x] 既存テスト `tests/Feature/Api/ApiEndpointTest.php`（items CRUD / cross-org 404）が
      引き続き green であることを確認（cross-org が **404 のまま**であることの回帰確認を兼ねる）
- [x] 既存テスト `tests/Feature/Api/IdempotencyTest.php` / `OAuthDualGuardTest.php` の green 確認
- [x] 新規テスト: 施策 5 参照
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

| リスク | 影響 | 対策 |
|---|---|---|
| **OAuth CLI トークンの後方非互換** | CLI セッションは組織メンバーなら誰でも開始できる。`organization_member` かつ `project_admin` でない利用者の Item write が **403 になる** | 意図的な是正。統一エラー envelope の `code: "forbidden"` で返り、`insufficient_ability` (ability 不足) とは**別コード**で区別できるため、クライアントは「権限不足」と「トークン設定不足」を判別できる。リリースノート + `docs/app-integration-guide.md` §5 に権限境界を明記 |
| API キー発行者の降格 | 発行者が member へ降格するとそのキーの write が 403 | API キーを発行できるのは `manageApiKeys` = owner/admin のみ。降格後に権限が落ちるのは**是正**であって退行ではない |
| `Gate::authorize` を誤って使う実装ミス | 403 ではなく **500 (TypeError)** | 施策 5 で API キー経路・OAuth 経路の**両方**で 403 を assert する（500 なら即座に落ちる） |
| cross-org が 403 / 422 に劣化 | 情報漏洩（不変条件 2/3 違反） | **施策 3 の middleware で FormRequest より前に 404 を確定**させたうえで、guard を authorize より前に置く + 施策 2 の順序検証 + 施策 5 の cross-org 404 ケース（valid / invalid / protected key payload）+ 既存 `ApiEndpointTest` の 4 重で固定 |

---

## 施策 5: `ItemAuthorizationTest` 新設 (Feature)

### 変更箇所

- ファイル: `tests/Feature/Api/V1/ItemAuthorizationTest.php`（**新規**）

`tests/Feature/Api/V1/` ディレクトリは新規作成（既存 API テストは `tests/Feature/Api/` 直下だが、
v1 固有の認可契約として分離する。`app/Http/Controllers/Api/V1/` の構造と対応させる）。

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 施策 6（OAuth helper の Support 昇格）が前提

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
| 12 | **cross-org + 不正 payload**（`name` 欠落）で `store` | **404**（422 ではない = 施策 3 の存在オラクル封じ） |
| 13 | **cross-org + protected key payload**（`project_id` 同梱）で `store` | **404**（`ProhibitsProtectedKeys` の 422 より前に 404） |
| 14 | **cross-org + 空 payload** で `update` | **404**（422 ではない） |
| 15 | **存在しない project id** + 不正 payload で `store` | **404** かつ **ケース 12 と JSON body まで完全一致**（実在/不在が 1 bit も漏れない） |
| 16 | 403 応答は Idempotency-Key で**再生されない**（権限付与後に再送すると成功する） | 下記の 4 ステップで **201 + Item 作成**まで確認 |

> **ケース 8/9/12-15 が本設計のセキュリティ回帰テスト**。
> - ケース 8/9: 認可を足したことで cross-org が **403 に変わっていない**ことを固定
> - ケース 9: 「認可より前に 404」の順序そのものを検証（viewer かつ cross-org で 403 が
>   返ったら、認可が guard より前に走っている証拠になる）
> - ケース 12-15: 施策 3 の**存在オラクル封じ**（FormRequest の 422 より前に 404）を固定
> - ケース 15: ケース 12 と**同じ応答**であること = 「実在する cross-org project」と
>   「存在しない project」が**区別できない**ことの直接的な証明。
>   本設計で最も本質的な 1 本（オラクルが閉じたことの定義そのもの）

#### ケース 15/16 の設計意図（Codex Round 2 指摘の反映）

**ケース 12 と 15 は「同一の応答」であることを body 比較で assert する**。
「どちらも 404」だけでは主張（実在/不在を区別できない）の証明として弱いため、
status code と正規化した JSON body の**両方**が一致することを確認する:

```php
test('cross-org の実在 project と 存在しない project id は完全に同一応答', function (): void {
    // ... 準備 ...
    $crossOrg = $this->withHeaders($h)
        ->postJson("/api/v1/projects/{$projectB->id}/items", ['note' => 'name 欠落']);
    $missing = $this->withHeaders($h)
        ->postJson('/api/v1/projects/999999999/items', ['note' => 'name 欠落']);

    expect($crossOrg->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404)
        // ★ body まで完全一致 = 実在/不在の識別子が 1 bit も漏れていない
        ->and($crossOrg->json())->toBe($missing->json());
});
```

**ケース 16 は「403 が再生されない」ことを権限変更で証明する**。
当初案（同一 key で 2 回とも 403 を確認）では、2 回目が
「再実行されて 403」なのか「保存済み 403 を再生」なのかを**観測できない**（Codex 指摘）。
権限を途中で変えることで、保存されていた場合にだけ失敗する形にする:

```php
test('403 は Idempotency-Key で再生されない (権限付与後の再送は成功する)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
    [, $plain] = issueApiKey($organization, $viewer, ['read', 'write']);
    $headers = ['Authorization' => "Bearer {$plain}", 'Idempotency-Key' => 'fixed-key-001'];
    $payload = ['name' => 'アイテム'];

    // 1. viewer は 403
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertForbidden();

    // 2. 同じ user を editor (project_admin) に昇格
    attachProjectMember($project, $viewer, ProjectRole::Admin);
    // Laratrust / memberRole() の relation キャッシュでテストが偽陰性にならないよう明示的に落とす
    // (テスト失敗の原因がキャッシュ由来か本質かを切り分けられなくなるのを防ぐ)
    $viewer->refresh();
    $project->unsetRelations();

    // 3. 同一 key + 同一 payload で再送 → 保存済み 403 が再生されるなら 403 のまま
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
        ->assertCreated();   // ★ 201 = 再生されていない

    expect($project->items()->count())->toBe(1);
});
```

`IdempotentRequest` は 2xx のみ保存する仕様のため本テストは実装変更なしで green になる見込みだが、
**「権限回復後も 403 が返り続ける」という詰み**が将来の改修で生まれないことを恒久固定する。

#### `Idempotency-Key` header の扱い（Codex 指摘への回答）

レビューで「全 write request に `Idempotency-Key` を付けるべき」と指摘されたが、
`IdempotentRequest::handle()` を実査したところ

```php
$key = $request->header('Idempotency-Key');
if (! is_string($key) || trim($key) === '') {
    return $next($request);   // ← ヘッダ無しは素通し
}
```

と**ヘッダ無しは完全に素通し**であり、付けないことで別のエラーになることはない
（probe テストでも 422/404 が期待どおり観測された）。
既存の `ApiEndpointTest` も付けずに write を叩いている。
したがって**全件に付ける必要はない**が、指摘の趣旨（idempotency 層との相互作用の未検証）は
妥当なため、**ケース 16 を追加**して「403 が再生されない」ことを 1 本だけ固定する
（`IdempotentRequest` は 2xx のみ保存する仕様なので、403 がキャッシュされて
権限回復後も 403 が返り続ける、という事故が起きないことの担保）。

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

// --- OAuth CLI トークン経路 (ケース 6/7) ---
// 施策 6 で Support クラスへ昇格させた helper を使う (global 関数の再宣言を避ける)
test('viewer の OAuth CLI トークンでも Item を作成できない (403)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);

    $client = OAuthTestHelpers::createMcpClient(name: 'Test CLI Client');
    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $viewer,              // ★ token 所有者 = viewer
        organization: $organization,
        client: $client,
        scope: 'cli:use read write',
    );

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '侵入'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($project->items()->count())->toBe(0);
});

// ... (update / destroy / editor / admin / current-org 汚染)

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

- [x] **バグ修正の再現テストを先に書く**: 施策 3 / 施策 4 の実装前に本テストを走らせ、
      ケース 1/2/3/6/11 が **fail する**（現状 viewer が 201/200 を得る）ことを確認する
- [x] 施策 3 / 施策 4 実装後に全ケース green
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Pest.php` のグローバル `RefreshDatabase` に従う）
- [x] `--parallel` で走ることを前提に、共有状態を持たない（global helper 関数の再宣言に注意 → 施策 5）

### リスク

- **global helper 関数名の衝突**: `viewerApiKey()` のような global 関数を test ファイルに
  定義すると、他ファイルに同名があれば fatal error になる。
  Pest の慣行に従い**十分に固有な名前**にするか、`tests/Pest.php` へ昇格させる。
  本設計では `viewerApiKey` は本ファイル固有として定義し（既存に同名なしを確認済み）、
  OAuth 側は施策 5 でクラス static へ逃がす

---

## 施策 6: OAuth CLI セッション helper の Support 昇格

### 変更箇所

- ファイル: `tests/Support/OAuthTestHelpers.php`（静的メソッド追加）
- ファイル: `tests/Feature/Api/OAuthDualGuardTest.php`（既存 global 関数を**削除**し、全呼び出しを静的メソッドへ置換）

### 背景（なぜ必要か）

施策 5 のケース 6/7 は OAuth CLI トークンで API を叩く必要がある。
その手順（token 交換 → `oauth_access_tokens` 行の特定 → `OauthSession` 作成 → `session_id` 束縛）は
現在 `tests/Feature/Api/OAuthDualGuardTest.php:34` に **global function `issueCliSessionTokens()`** として
定義されている。**global 関数は再宣言できない**ため、施策 5 のファイルで同じものを定義すると
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
// tests/Feature/Api/OAuthDualGuardTest.php
// ★ global 関数 issueCliSessionTokens() は **削除**し、呼び出し側を静的呼び出しへ置換する
//   (旧: $issued = issueCliSessionTokens($this);)
$issued = OAuthTestHelpers::issueCliSessionTokens(
    test: $this,
    user: $this->user,
    organization: $this->org,
    client: $this->client,
);
```

> **後方互換の並走を残さない**（思考原則 3 / Codex Round 1 指摘）:
> 当初案では global 関数を「委譲 1 行」で残す予定だったが、
> - `function issueCliSessionTokens(object $test, ...)` の `object $test` から
>   `$test->user` / `$test->org` / `$test->client` を読む形は **PHPStan level 10 と相性が悪い**
>   （`object` に対する未定義プロパティアクセス）
> - 旧関数を残すと「global 関数版」と「静的メソッド版」の 2 経路が並走する
>
> ため、**global 関数は削除**し、`OAuthDualGuardTest.php` 内の全呼び出し箇所
> （実査で 6 箇所）を名前付き引数の静的呼び出しへ置き換える。
> これにより `$test` の magic property への暗黙依存も解消され、型が明示される。

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Api/OAuthDualGuardTest.php` の既存テストが
  引き続き green であること（**振る舞いは不変**、置き場所と呼び出し形のみ変更）。
  global 関数の削除に伴い、同ファイル内の全呼び出し箇所を静的呼び出しへ置換する

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（PHPDoc の array shape 付き）
- [x] null 安全（`$tokenId` の null を `Assert` / `expect` で潰す。移設前と同じ）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] 既存テスト `tests/Feature/Api/OAuthDualGuardTest.php` の全ケース green（回帰確認）
- [x] 新規テスト 施策 5 のケース 6/7 が本 helper 経由で動く

### リスク

- 移設ミスで OAuth 系の既存テストが壊れる → 既存テストがそのまま回帰検知になる（振る舞い不変）

---

## 施策 7: ドキュメント更新

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
>
> **層 2 は FormRequest より前で閉じる**: controller の inline guard は
> **FormRequest のバリデーションより後**に走る。inline guard だけに頼ると
> 「cross-org の実在リソース + 不正 payload = 422 / 不在リソース = 404」の差分が
> **存在オラクル**になる。`{project}` を持つ route は
> web = `project.in-route-org` / **API = `api.project-in-org`** middleware を必ず付け、
> 子リソースは `Route::scopeBindings()` で routing 層に解決させる
> （`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` が強制）。

**新規 route 追加時のチェックリスト**:

1. **層 2（テナント境界）が FormRequest より前に閉じているか**を確認する。
   controller の inline guard は **FormRequest の後**に走るため、それだけでは不十分。
   - `{project}` を持つ route → web は `project.in-route-org`、
     **API は `api.project-in-org`** middleware が付いていること
   - 子リソース（`{item}` 等）→ `Route::scopeBindings()` で routing 層に解決させること
   - 確認方法: **cross-org の実在リソース + 不正 payload** を送って
     **404**（422 ではない）が返ること
2. ハンドラ冒頭（URL 整合 guard の**後**）に `Gate::authorize(...)` を置く。
   **REST API v1 では `Gate::forUser($this->apiActor($request)->user)->authorize(...)`**
   （dual guard では `Auth::user()` が `ApiKey` を返すため `Gate::authorize` は TypeError = 500 になる）
3. 認可が不要なら `ControllerAuthorizationGateTest` の exemption inventory に
   enum + 「**何が代わりに守っているか**」を 30 文字以上で登録する。
   当てはまる enum case が無ければ、それは**認可を足すべき route** である
   （特に `NoAuthorizableSubject` は「親テナントすら無い新規作成」限定。
     親テナントがある create は**対象外** = `Gate::authorize('create', [Model::class, $parent])` を書く）
4. 2+param route なら `NestedRouteIdorDefenseTest` の inventory にも防御方式を登録する
5. `composer test` で 3 つの gate
   （`ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` /
   `ProjectRouteCurrentOrgGuardTest`）が green であることを確認する

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
  ├─ [層2a] テナント境界 (middleware / routing 層) ★FormRequest より前
  │                    project.in-route-org (web) / api.project-in-org (API) /
  │                    MembershipScopedOrganizationBinder / Route::scopeBindings()
  │                    … ProjectRouteCurrentOrgGuardTest / NestedRouteIdorDefenseTest
  │                                                        ← 不整合は 404
  │
  ├─ [FormRequest] バリデーション (422)  ※層2a より後・層2b より前
  │
  ├─ [層2b] テナント境界 (controller inline = 二重防御)
  │                    resolveOrganizationProject / resolveProjectItem
  │                                                        ← 不整合は 404
  │
  └─ [層3] 認可        Gate::authorize / Gate::forUser(...)->authorize
                       … ★ ControllerAuthorizationGateTest (新設)  ← 不足は 403
```

- 本 gate は**層 3 専任**。層 2 の手段を層 3 の合格条件に**数えない**のが核心
- **層 2 → 層 3 の順序は不可侵**（施策 2 (f) が機械固定）
- **層 2a が無いと FormRequest の 422 が存在オラクルになる**（施策 3 が閉じる）。
  層 2b（inline guard）だけでは不十分、というのが本設計の最大の学び
- 各テストは inventory を共有しない（テスト間の関数依存を作らない）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 2 の gate が施策 3 の実害検出を前提にしており（テストファースト: gate を書く → `api.v1.projects.items.*` が fail する → 認可を足す → green）、施策 4 も施策 3 の再現テストとして先行する。**施策間の依存が strong に連鎖**しており、分割すると「gate は入ったが認可漏れは残っている」という中間状態が main に乗る。セキュリティ変更のため中間状態を作らない。施策 6 は施策 5 の前提（global 関数衝突の回避）で同一 PR に含める必要がある。さらに**施策 3（層 2a）と施策 4（層 3）は分割不可**: 層 3 だけ先に入れると cross-org が 403 を返す中間状態、層 2a だけ入れると認可漏れが残る中間状態になる |
| 競合リスク | **低**。変更ファイルは新規 4 本 + 既存 3 本（`Api\V1\ItemController` / `OAuthDualGuardTest` / docs）で、他の進行中タスクと重複する可能性が低い。ただし**新しい変更系 route を追加する他タスクと同時進行すると gate の inventory で衝突**する（相手側が exemption 登録か認可追加を要求される）。マージ順序を意識すること |

### 実装手順（テストファーストの具体順）

1. 施策 1（enum）
2. 施策 2a（`AuthorizationMarkerScanner` + その Unit テスト 16 ケース）を先に書く
   ← *解析器そのものの正しさを route から独立して固定する*
3. 施策 2b（gate テスト本体）を書き、`api.v1.projects.items.store/update/destroy` の 3 本が
   **未分類で fail する**ことを確認 ← *gate が実害を検出できることの証明*
4. 施策 6（OAuth helper の Support 昇格 + global 関数削除）+ 既存 OAuth テスト green 確認
5. 施策 5（`ItemAuthorizationTest`）を書き、**2 種類の fail** を確認 ← *実害の再現*
   - ケース 1/2/3/6/11（viewer が 201/200 を得る）= 認可漏れ
   - ケース 12/13/14/15（cross-org + 不正 payload が **422**）= 存在オラクル
6. **施策 3**（`api.project-in-org` middleware + `scopeBindings()` +
   `ProjectRouteCurrentOrgGuardTest` の更新 2 本（存在 + 順序）+
   `NestedRouteIdorDefenseTest` 更新）を実装
   → ケース 12/13/14/15 が green（**層 2 を先に閉じる**）
7. **施策 4**（`Api\V1\ItemController` に `Gate::forUser`）を実装
   → 施策 2 とケース 1-11/16 が green（**層 3 を後から足す**）
8. 施策 7（ドキュメント）
9. `composer test` / `composer phpstan` / `vendor/bin/pint --test` 全 green

> **6 → 7 の順序が本質**: 層 2（404）を閉じる前に層 3（403）を足すと、
> 一時的に「cross-org + viewer」が 403 を返す中間状態が生まれうる。
> standalone モードで 1 PR に収めるため実際には外部に露出しないが、
> 実装中の混乱を避けるため順序を固定する。

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

## 実装差分 (git diff)

```diff
diff --git a/app/Enums/Security/ControllerAuthorizationExemption.php b/app/Enums/Security/ControllerAuthorizationExemption.php
new file mode 100644
index 0000000..0633510
--- /dev/null
+++ b/app/Enums/Security/ControllerAuthorizationExemption.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 変更系 (POST/PUT/PATCH/DELETE) route が「認可判断 (Gate) を持たないことが正しい」
+ * と裁定された理由の分類。
+ *
+ * `tests/Architecture/ControllerAuthorizationGateTest.php` が deny-by-default で
+ * 「認可あり」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
+ * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   条件に当てはまらない route を無理に既存 case へ押し込むと gate が形骸化する。
+ *   当てはまる case が無ければ、それは「認可を足すべき route」である。
+ */
+enum ControllerAuthorizationExemption: string
+{
+    /**
+     * membership 判定そのものが認可である。
+     *
+     * 適用条件 (全て満たすこと):
+     * - 対象リソースが `MembershipScopedOrganizationBinder` 等で membership スコープ解決される
+     * - 「所属していれば誰でもよい」がロール非依存の**仕様**である
+     *   (owner/admin/member を区別する必要が無い)
+     * - Policy を足すと membership の**二重判定**にしかならない
+     */
+    case MembershipIsTheAuthorization = 'membership_is_the_authorization';
+
+    /**
+     * 認可の対象となる既存リソースが存在しない (新規作成そのもの)。
+     *
+     * 適用条件: route に対象リソースを指す parameter が無く、
+     * 作成対象の親テナントも存在しない (= 誰の何に対する権限か、が定義できない)。
+     */
+    case NoAuthorizableSubject = 'no_authorizable_subject';
+
+    /**
+     * 対象が常に「認証中の自分自身」に閉じる。
+     *
+     * 適用条件 (全て満たすこと):
+     * - route に**他者を指せる parameter が 1 つも無い**、または
+     *   parameter が `$user->relation()` 経由でのみ解決され cross-user が構造的に 404
+     * - 他者のリソースへ到達する経路がコード上存在しない
+     */
+    case SelfScopedResource = 'self_scoped_resource';
+
+    /**
+     * 認可主体が「有効なトークンの保持者」であり、トークン検証が認可を兼ねる。
+     *
+     * 適用条件: 対象組織の**非メンバー**が正当に実行する操作であり、
+     * 組織 Policy を通すと構造的に必ず拒否になる (招待受諾など)。
+     */
+    case TokenBearerIsTheSubject = 'token_bearer_is_the_subject';
+
+    /**
+     * API トークンの scope 判定が明示的な 403 を担っている。
+     *
+     * 適用条件: controller 内に `abort_unless($actor->hasScope(...), 403)` 等の
+     * **明示的な 403 判定**があり、かつ対象が actor 自身のリソースに閉じる。
+     */
+    case ScopeIsTheAuthorization = 'scope_is_the_authorization';
+
+    /** 未認証の公開エンドポイント (認可すべき主体が存在しない)。 */
+    case PublicUnauthenticated = 'public_unauthenticated';
+
+    /**
+     * 署名検証済みの machine-to-machine webhook (人間の actor が存在しない)。
+     *
+     * 適用条件: 署名検証 middleware + 送信元 allowlist (fail-closed) が防御線であること。
+     */
+    case SignatureVerified = 'signature_verified';
+
+    /**
+     * local / テスト実行時のみ **route 登録自体が起きない**デバッグ用 route。
+     *
+     * 適用条件: `routes/*.php` 側で `app()->isLocal() || app()->runningUnitTests()`
+     * 等により登録が囲われ、かつ `LocalOnly` 相当の middleware が二重防御であること。
+     */
+    case LocalOnlyDebugRoute = 'local_only_debug_route';
+}
diff --git a/app/Http/Controllers/Api/V1/ItemController.php b/app/Http/Controllers/Api/V1/ItemController.php
index 620eaa8..1a8169b 100644
--- a/app/Http/Controllers/Api/V1/ItemController.php
+++ b/app/Http/Controllers/Api/V1/ItemController.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Controllers\Api\V1;
 
+use App\Http\Controllers\Api\V1\Concerns\ReadsApiActor;
 use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Projects\StoreItemRequest;
@@ -15,6 +16,7 @@
 use Illuminate\Http\Request;
 use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
 use Illuminate\Http\Resources\Json\JsonResource;
+use Illuminate\Support\Facades\Gate;
 use Webmozart\Assert\Assert;
 
 /**
@@ -23,9 +25,20 @@
  * FormRequest は web と同じ StoreItemRequest / UpdateItemRequest を再利用する
  * (ProhibitsProtectedKeys = project_id を payload で送ると 422)。
  * URL 整合 guard 2 段 ({project} ∈ org, {item} ∈ project) はいずれも認可より前に 404。
+ *
+ * 変更系 (store/update/destroy) の認可は `Gate::forUser(...)` 経由で、web 側
+ * {@see \App\Http\Controllers\Projects\ItemController} と同一の ItemPolicy 境界に揃える。
+ * ★`Gate::authorize` は使えない: dual guard (auth:api-key,api-oauth) は通過した guard を
+ *   default に昇格させるため、API キー経路では Auth::user() が App\Models\ApiKey を返し
+ *   ItemPolicy::create(User $user, ...) が TypeError = HTTP 500 になる。
+ *   認可主体は resolve.api-actor が解決済みの ApiActorContext::$user
+ *   (API キー = 発行者 / OAuth = トークン所有者。非 null 保証) を明示的に渡す。
+ * 契約は tests/Feature/Api/V1/ItemAuthorizationTest と
+ * tests/Architecture/ControllerAuthorizationGateTest が固定する。
  */
 class ItemController extends Controller
 {
+    use ReadsApiActor;
     use ResolvesApiOrganization;
 
     /** GET /api/v1/projects/{project}/items */
@@ -46,7 +59,10 @@ public function index(Request $request, Project $project): AnonymousResourceColl
     public function store(StoreItemRequest $request, Project $project): JsonResponse
     {
         $organization = $this->resolveOrganization($request);
+        // URL 整合 guard: 認可より前に 404 (cross-org を 403 で漏らさない)
         $this->resolveOrganizationProject($organization, $project);
+        Gate::forUser($this->apiActor($request)->user)
+            ->authorize('create', [Item::class, $project]);
 
         $name = $request->validated('name');
         Assert::string($name);
@@ -62,8 +78,10 @@ public function store(StoreItemRequest $request, Project $project): JsonResponse
     public function update(UpdateItemRequest $request, Project $project, Item $item): JsonResponse
     {
         $organization = $this->resolveOrganization($request);
+        // URL 整合 guard 2 段: いずれも認可より前に 404
         $this->resolveOrganizationProject($organization, $project);
         $this->resolveProjectItem($project, $item);
+        Gate::forUser($this->apiActor($request)->user)->authorize('update', $item);
 
         $name = $request->validated('name');
         Assert::string($name);
@@ -84,6 +102,7 @@ public function destroy(Request $request, Project $project, Item $item): JsonRes
         $organization = $this->resolveOrganization($request);
         $this->resolveOrganizationProject($organization, $project);
         $this->resolveProjectItem($project, $item);
+        Gate::forUser($this->apiActor($request)->user)->authorize('delete', $item);
 
         $item->delete();
 
diff --git a/app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php b/app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php
new file mode 100644
index 0000000..ac72fa7
--- /dev/null
+++ b/app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
+use App\Models\Project;
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * REST API v1 の `{project}` route の URL 整合 guard (middleware 層)。alias: api.project-in-org。
+ *
+ * web の {@see EnsureProjectBelongsToRouteOrganization} と同じ順序ハザードを API 側で閉じる。
+ * cross-org の {project} を「FormRequest のバリデーションを含むあらゆるアプリコードより前に
+ * 404」へ落とす。controller の inline guard (resolveOrganizationProject) は認可より前の 404 を
+ * 担うが、**FormRequest は controller メソッド解決時 = inline guard より前**に走るため、
+ * middleware が無いと「cross-org の実在 project + 不正 payload = 422」「不在 project = 404」の
+ * 差分が存在オラクルになる (不変条件 3)。
+ *
+ * web 版との違いは組織の解決元だけ:
+ *  - web: セッションの current org (ResolvesCurrentOrganization)
+ *  - API: API キー / OAuth token から確定した request attribute 'organization'
+ *         (ApiKeyGuard / ResolveApiActor が注入。ResolvesApiOrganization::resolveOrganization)
+ *
+ * 順序契約: api グループ (SubstituteBindings) → auth → throttle → resolve.api-actor
+ *           → api-key.ability → **api.project-in-org** → idempotent → controller
+ * `organization` attribute が前提のため **resolve.api-actor より後**、
+ * cross-org リクエストで idempotency 行を作らせないため **idempotent より前**に置く。
+ * {project} を持たない route では no-op (group 一括付与を許容し、将来の route 追加時の
+ * guard 漏れを防ぐ)。
+ *
+ * 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest が deny-by-default で固定する。
+ * controller の inline guard は二重防御として残す (middleware の付け漏れ・
+ * withoutMiddleware への最終防衛線)。
+ */
+class EnsureProjectBelongsToApiOrganization
+{
+    use ResolvesApiOrganization;
+
+    /**
+     * @param  Closure(Request): Response  $next
+     */
+    public function handle(Request $request, Closure $next): Response
+    {
+        $project = $request->route('project');
+
+        if ($project instanceof Project) {
+            $organization = $this->resolveOrganization($request);
+            $this->resolveOrganizationProject($organization, $project);
+        }
+
+        return $next($request);
+    }
+}
diff --git a/bootstrap/app.php b/bootstrap/app.php
index b226506..450881a 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -7,6 +7,7 @@
 use App\Http\Middleware\BughuntCoverageMiddleware;
 use App\Http\Middleware\EnforceMcpTransport;
 use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
+use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
 use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
@@ -137,6 +138,11 @@
             // FormRequest の DB ルール (unique/exists) より前に 404 へ落とす
             // (存在オラクル防止。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
             'project.in-route-org' => EnsureProjectBelongsToRouteOrganization::class,
+            // REST API v1 用の同等 guard (組織は API キー / OAuth token から確定するため
+            // web セッションの current org とは解決元が違う = 別 alias)。
+            // resolve.api-actor より後・idempotent より前に置くこと (順序契約は
+            // routes/api.php と ProjectRouteCurrentOrgGuardTest)
+            'api.project-in-org' => EnsureProjectBelongsToApiOrganization::class,
             'resolve.api-actor' => ResolveApiActor::class,
             'api-key.ability' => RequireApiKeyAbility::class,
             'idempotent' => IdempotentRequest::class,
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 977f4bf..cd78403 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -66,11 +66,13 @@ ### 見本: Item リソース(この手順の実演)
 | FormRequest(`ProhibitsProtectedKeys` + missing rule) | `app/Http/Requests/Projects/StoreItemRequest.php` / `UpdateItemRequest.php` |
 | nested route(Team セグメントなし = Default Team パターン) | `routes/web.php` の `/projects/{project}/items` 系 |
 | URL 整合 guard(認可より**前**に 404) | {project} ∈ current org は 2 層: `project.in-route-org` middleware(`app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php`。FormRequest の DB ルールより**前**に cross-org を 404 に落とす = 存在オラクル防止。web の {project} route group に一括付与、網羅性は `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`)+ `app/Http/Concerns/ResolvesCurrentOrganization.php` の `resolveOrganizationProject()`(inline guard、二重防御)。{item} ∈ {project} は `routes/web.php` の `Route::scopeBindings()`(`$project->items()` 経由で解決) |
-| guard inventory への登録 | `tests/Architecture/NestedRouteIdorDefenseTest.php`(Web の `projects.items.update/destroy` = ScopeBindings、API の `api.v1.projects.items.update/destroy` = UrlIntegrityGuard) |
-| REST API v1 controller(Web と同じ FormRequest 再利用、org-scoped 解決) | `app/Http/Controllers/Api/V1/ItemController.php`(`ResolvesApiOrganization`) |
+| API 側の URL 整合 guard(認可より**前**に 404、**FormRequest より前**) | {project} ∈ actor の組織は 2 層: `api.project-in-org` middleware(`app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php`。組織は API キー / OAuth token から確定。網羅性と middleware 順序契約は `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`)+ `ResolvesApiOrganization::resolveOrganizationProject()`(inline guard、二重防御)。{item} ∈ {project} は `routes/api.php` の `Route::scopeBindings()` |
+| guard inventory への登録 | `tests/Architecture/NestedRouteIdorDefenseTest.php`(Web の `projects.items.update/destroy`、API の `api.v1.projects.items.update/destroy` = いずれも ScopeBindings) |
+| 変更系 route の認可 gate | `tests/Architecture/ControllerAuthorizationGateTest.php`(POST/PUT/PATCH/DELETE は `Gate` を通るか exemption inventory に理由付き登録。§7 不変条件 8) |
+| REST API v1 controller(Web と同じ FormRequest 再利用、org-scoped 解決、`Gate::forUser` 認可) | `app/Http/Controllers/Api/V1/ItemController.php`(`ResolvesApiOrganization` + `ReadsApiActor`) |
 | API リソース(レスポンス整形) | `app/Http/Resources/Api/V1/ItemResource.php` |
 | API ルート(nested + dual guard + ability + idempotent) | `routes/api.php` の `api.v1.projects.items.{index,store,update,destroy}` |
-| API Feature テスト | `tests/Feature/Api/{ApiEndpointTest,ApiKeyTest,IdempotencyTest,OAuthDualGuardTest}.php` |
+| API Feature テスト | `tests/Feature/Api/{ApiEndpointTest,ApiKeyTest,IdempotencyTest,OAuthDualGuardTest}.php` + `tests/Feature/Api/V1/ItemAuthorizationTest.php`(認可境界 / cross-org 404 / 存在オラクル封じ) |
 | Policy(親 Policy へ委譲、直 fetch 禁止) | `app/Policies/ItemPolicy.php` → `app/Policies/ProjectPolicy.php` |
 | Service(transaction + 所有権キーの明示代入) | 親側の見本: `app/Services/Project/ProjectService.php`(Default Team 自動割当)。Item は単一 insert のため relation 経由で Controller 直書き |
 | Factory(親 Factory 連鎖) | `database/factories/ItemFactory.php`(project 未指定なら `ProjectFactory` 連鎖) |
@@ -145,6 +147,14 @@ ## 5. API・外部公開面のマッピング
 - REST API: nested route + flat ability。新リソースの ability は `{resource}:read` /
   `{resource}:write` / 動詞付き(`evaluations:run` 型)で定義し、ability 定義 1 箇所に追記。
 - すべての書き込みエンドポイントに Idempotency-Key を配線する(テンプレの middleware を使う)。
+- **API の権限境界は ability(トークンの能力)と Policy(actor の権限)の 2 段**。
+  ability 不足は `code: "insufficient_ability"`、Policy 不足は `code: "forbidden"` で返り、
+  クライアントは「トークン設定不足」と「権限不足」を判別できる。
+  認可の主体は `ApiActorContext::$user`(API キー = 発行者 / OAuth = トークン所有者)であり、
+  controller では `Gate::forUser($this->apiActor($request)->user)->authorize(...)` を使う
+  (`Gate::authorize` は dual guard 下で `ApiKey` を Policy に渡してしまい 500 になる)。
+  OAuth CLI セッションは**組織メンバーなら誰でも開始できる**ため、
+  組織メンバーであることは書き込み権限を意味しない(Policy が別途判定する)。
 - rate limit は既存 4 バケット(api-read / api-write / api-status / api-mcp)に割り当てる。
   新バケットを増やすのは要件に明示的な根拠があるときだけ。
 - MCP tool: whoami / list-projects / show-project / list-items の雛形に倣う。書き込み tool は McpIdempotencyService 経由。
@@ -191,8 +201,48 @@ ## 7. 守るべき不変条件(チェックリスト)
    課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止。
    AI-CUE の判定は billing entitlement: `state()` が Subscribed / ActiveFreePlan なら許可。
    plan_code は判定に使わない — 無料枠は free_plan_code='personal' の明示申告)
-8. **テストなしの実装完了はない**(不変条件 1-7 はそれぞれ対応する Architecture/Feature
-   テストに新リソースを登録して初めて「実装済み」)
+8. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE のアプリ所有 route は
+   `Gate::authorize` / `Gate::forUser(...)->authorize` を持つか、
+   `tests/Architecture/ControllerAuthorizationGateTest.php` の exemption inventory に
+   `App\Enums\Security\ControllerAuthorizationExemption` + 具体的根拠(30 文字以上)付きで
+   登録する(deny-by-default で強制)。**層 2(テナント境界 = 404)と層 3(認可 = 403)の
+   順序は不可侵** — inline guard は必ず `Gate` より前に置く(逆にすると cross-org が
+   403 を返し、リソースの存在が漏れる)。
+   なお `can:` middleware / `FormRequest::authorize()` / membership binder /
+   `auth`・`verified`・`recent-auth`・`require-active-subscription`・`api-key.ability`
+   middleware は**認可(層 3)として数えない**(数えると gate が形骸化する)
+9. **層 2 は FormRequest より前で閉じる**: controller の inline guard は
+   **FormRequest のバリデーションより後**に走る。inline guard だけに頼ると
+   「cross-org の実在リソース + 不正 payload = 422 / 不在リソース = 404」の差分が
+   **存在オラクル**になる。`{project}` を持つ route は
+   web = `project.in-route-org` / **API = `api.project-in-org`** middleware を必ず付け、
+   子リソースは `Route::scopeBindings()` で routing 層に解決させる
+   (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` が強制)
+10. **テストなしの実装完了はない**(不変条件 1-9 はそれぞれ対応する Architecture/Feature
+    テストに新リソースを登録して初めて「実装済み」)
+
+### 新規 route(特に変更系)を足すときのチェックリスト
+
+1. **層 2(テナント境界)が FormRequest より前に閉じているか**を確認する。
+   controller の inline guard は **FormRequest の後**に走るため、それだけでは不十分。
+   - `{project}` を持つ route → web は `project.in-route-org`、
+     **API は `api.project-in-org`** middleware が付いていること
+   - 子リソース(`{item}` 等)→ `Route::scopeBindings()` で routing 層に解決させること
+   - 確認方法: **cross-org の実在リソース + 不正 payload** を送って
+     **404**(422 ではない)が返ること
+2. ハンドラ冒頭(URL 整合 guard の**後**)に `Gate::authorize(...)` を置く。
+   **REST API v1 では `Gate::forUser($this->apiActor($request)->user)->authorize(...)`**
+   (dual guard では通過した guard が default に昇格し `Auth::user()` が `ApiKey` を返すため、
+   `Gate::authorize` は Policy の `User $user` 型に対して TypeError = 500 になる)
+3. 認可が不要なら `ControllerAuthorizationGateTest` の exemption inventory に
+   enum + 「**何が代わりに守っているか**」を 30 文字以上で登録する。
+   当てはまる enum case が無ければ、それは**認可を足すべき route** である
+   (特に `NoAuthorizableSubject` は「親テナントすら無い新規作成」限定。
+   親テナントがある create は**対象外** = `Gate::authorize('create', [Model::class, $parent])` を書く)
+4. 2+param route なら `NestedRouteIdorDefenseTest` の inventory にも防御方式を登録する
+5. `composer test` で 3 つの gate
+   (`ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` /
+   `ProjectRouteCurrentOrgGuardTest`)が green であることを確認する
 
 ## 8. 設計ドキュメントの書き方(このテンプレ上の流儀)
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 2fccc20..cf64878 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -22,6 +22,45 @@ ## 層構造
 DataTransferObjects / Http/Resources (応答形の単一定義)
 ```
 
+### 変更系 route の 3 層 (認証 → テナント境界 → 認可)
+
+状態を変える route (POST/PUT/PATCH/DELETE) は次の 3 層を**この順序で**通る。
+順序が本質で、層 2 と層 3 を入れ替えると cross-org が 404 ではなく 403 を返し、
+リソースの存在が漏れる (セキュリティ不変条件 2/3)。
+
+```
+リクエスト
+  ├─ [層1] 認証         auth / auth:api-key,api-oauth
+  │                     … ManageRouteAuthGuardTest / ApiGuardAllowlistInvariantTest
+  ├─ [層2a] テナント境界 (middleware / routing 層) ★FormRequest より前
+  │                     project.in-route-org (web) / api.project-in-org (API) /
+  │                     MembershipScopedOrganizationBinder / Route::scopeBindings()
+  │                     … ProjectRouteCurrentOrgGuardTest / NestedRouteIdorDefenseTest
+  │                                                       ← 不整合は 404
+  ├─ [FormRequest] バリデーション (422)  ※層2a より後・層2b より前
+  ├─ [層2b] テナント境界 (controller inline = 二重防御)
+  │                     resolveOrganizationProject / resolveProjectItem  ← 不整合は 404
+  └─ [層3] 認可         Gate::authorize / Gate::forUser(...)->authorize
+                        … ControllerAuthorizationGateTest               ← 不足は 403
+```
+
+- **層 2a が無いと FormRequest の 422 が存在オラクルになる**。inline guard (層 2b) は
+  FormRequest より**後**に走るため、「cross-org の実在リソース + 不正 payload = 422 /
+  不在リソース = 404」の差分でリソースの実在が漏れる。層 2b は二重防御として残す
+- **`ControllerAuthorizationGateTest`** (Architecture) が層 3 を deny-by-default で強制する。
+  合格条件は `Gate` ファサード 1 系統のみで、membership binder / `resolve*` 系 /
+  `auth`・`verified`・`recent-auth`・`require-active-subscription`・`api-key.ability`
+  middleware / `FormRequest::authorize()` は**認可として数えない** (数えると gate が形骸化する)。
+  `can:` middleware も受理しない (controller より前に走るため層 2 → 層 3 の順序を壊す)。
+  認可を持たない route は `App\Enums\Security\ControllerAuthorizationExemption` +
+  30 文字以上の具体的根拠付きで exemption inventory に登録する。
+  字句解析は `tests/Support/AuthorizationMarkerScanner` に切り出し、
+  解析器自体の positive/negative は `AuthorizationMarkerScannerTest` (Unit) が固定する
+- **REST API v1 の認可主体**は `ApiActorContext::$user`。dual guard は通過した guard を
+  default に昇格させるため `Auth::user()` は API キー経路で `ApiKey` を返す。
+  `Gate::authorize` を使うと Policy の `User $user` 型に対して TypeError = 500 になるので、
+  必ず `Gate::forUser($this->apiActor($request)->user)->authorize(...)` を使う
+
 ## route binding の型制約 (ドメイン制約: route key は最大 18 桁)
 
 `app/Http/Routing/RouteBindingTypes` が **全 binding param の型 inventory (単一 SoT)**。
diff --git a/routes/api.php b/routes/api.php
index 0d76dc9..9625c7d 100644
--- a/routes/api.php
+++ b/routes/api.php
@@ -19,7 +19,16 @@
 | api-key 先 = 自動化トラフィックが先に解決)。middleware の順序契約:
 |
 |   auth:api-key,api-oauth → throttle:{bucket} → resolve.api-actor
-|     → api-key.ability:{ability} → (idempotent) → controller
+|     → api-key.ability:{ability} → api.project-in-org → (idempotent) → controller
+|
+| api.project-in-org (EnsureProjectBelongsToApiOrganization) は URL 上の {project} が
+| actor の組織に属さなければ 404 にする層 2a。**FormRequest より前**に走ることが本質で、
+| これが無いと「cross-org の実在 project + 不正 payload = 422 / 不在 project = 404」の
+| 差分が project の存在オラクルになる。順序契約は 2 点とも不可侵:
+|   - resolve.api-actor **より後** ('organization' attribute が前提。前に置くと全 project
+|     route が Assert 発火で 500)
+|   - idempotent **より前** (cross-org リクエストで idempotency 行を作らせない)
+| 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest が機械固定する。
 |
 | resolve.api-actor が両経路を ApiActorContext (request attribute 'api_actor') に
 | 正規化する (OAuth 経路の cli:use scope / session 束縛 / membership 再検証もここ)。
@@ -57,7 +66,8 @@
 
 // 読み取り (read ability)
 Route::prefix('v1')
-    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor', 'api-key.ability:read'])
+    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor', 'api-key.ability:read',
+        'api.project-in-org'])
     ->group(function (): void {
         Route::get('/me', [MeController::class, 'show'])
             ->name('api.v1.me');
@@ -73,12 +83,17 @@
 
 // 書き込み (write ability)。全 write エンドポイントに Idempotency-Key を配線する
 Route::prefix('v1')
-    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write', 'idempotent'])
+    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write',
+        'api.project-in-org', 'idempotent'])
     ->group(function (): void {
         Route::post('/projects/{project}/items', [ItemController::class, 'store'])
             ->name('api.v1.projects.items.store');
-        Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
-            ->name('api.v1.projects.items.update');
-        Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
-            ->name('api.v1.projects.items.destroy');
+        // {item} ∈ {project} を **routing 層** (SubstituteBindings) で解決する。
+        // FormRequest より前に 404 が確定し、web 側 (routes/web.php) と同じ構造になる。
+        Route::scopeBindings()->group(function (): void {
+            Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
+                ->name('api.v1.projects.items.update');
+            Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
+                ->name('api.v1.projects.items.destroy');
+        });
     });
diff --git a/tests/Architecture/ControllerAuthorizationGateTest.php b/tests/Architecture/ControllerAuthorizationGateTest.php
new file mode 100644
index 0000000..1432e7e
--- /dev/null
+++ b/tests/Architecture/ControllerAuthorizationGateTest.php
@@ -0,0 +1,367 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\ControllerAuthorizationExemption;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\AuthorizationMarkerScanner;
+
+/*
+ * 変更系 route の認可 invariant (deny-by-default)。
+ *
+ * 「状態を変える route (POST/PUT/PATCH/DELETE) のハンドラは、必ず認可判断を 1 回通る」
+ * を機械強制する。通らないものは理由付きで exemption inventory へ明示登録させる。
+ *
+ * ★本テストの核心は「何を認可と認めないか」:
+ *   membership binder (MembershipScopedOrganizationBinder) / resolveOrganization 系 /
+ *   auth・verified・recent-auth・require-active-subscription・api-key.ability middleware /
+ *   FormRequest::authorize() は **合格条件に数えない**。
+ *   これらはテナント境界 (層 2) や認証・契約状態であって認可 (層 3) ではなく、
+ *   数えると gate が形骸化する。
+ *
+ * ★受理する認可手段は Gate ファサード 1 系統のみ:
+ *   - can: middleware は Controller より前に走るため、inline guard 方式の route で
+ *     「認可より前に 404」(不変条件 2) を壊す (cross-org が 403 になり存在が漏れる)。
+ *   - $this->authorize() は base Controller が AuthorizesRequests trait を持たず呼べない。
+ *   いずれも使用実績 0 件のため受理しない (使いたくなったら本テストごと設計し直す)。
+ *
+ * 本テストは「認可判断の入口が存在しない route を作らせない」役割に限定する。
+ * 認可の**内容**の正当性 (対象が正しいか / Policy が妥当か / actor が正しいか) は
+ * 各 Feature / Policy テストの責務 (NestedRouteIdorDefenseTest と同じ責務設計)。
+ *
+ * 字句解析は tests/Support/AuthorizationMarkerScanner に切り出し、解析器自体の
+ * positive/negative は tests/Unit/Architecture/AuthorizationMarkerScannerTest が固定する。
+ */
+
+/** 変更系 HTTP メソッド。 */
+function controllerAuthorizationMutatingMethods(): array
+{
+    return ['POST', 'PUT', 'PATCH', 'DELETE'];
+}
+
+/** 候補数の下限 (空振り drift ガード。実測に対し余裕を持たせた値。上限は設けない)。 */
+function controllerAuthorizationRouteFloor(): int
+{
+    return 40;
+}
+
+/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+function controllerAuthorizationReasonMinLength(): int
+{
+    return 30;
+}
+
+/** inline URL 整合 guard とみなすメソッド名 (認可より前に 404 を返す層 2b)。 */
+function controllerAuthorizationInlineGuards(): array
+{
+    return ['resolveOrganizationProject', 'resolveProjectItem', 'resolveOrganizationMember'];
+}
+
+/**
+ * 認可を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
+ *
+ * @return array<string, array{ControllerAuthorizationExemption, string}>
+ */
+function controllerAuthorizationExemptions(): array
+{
+    $membership = ControllerAuthorizationExemption::MembershipIsTheAuthorization;
+    $noSubject = ControllerAuthorizationExemption::NoAuthorizableSubject;
+    $selfScoped = ControllerAuthorizationExemption::SelfScopedResource;
+    $tokenBearer = ControllerAuthorizationExemption::TokenBearerIsTheSubject;
+    $scope = ControllerAuthorizationExemption::ScopeIsTheAuthorization;
+    $public = ControllerAuthorizationExemption::PublicUnauthenticated;
+    $signature = ControllerAuthorizationExemption::SignatureVerified;
+    $localOnly = ControllerAuthorizationExemption::LocalOnlyDebugRoute;
+
+    return [
+        'organizations.switch' => [$membership,
+            '{organization} は MembershipScopedOrganizationBinder が membership スコープで解決し、'
+            .'非所属は認可より前に 404 (存在秘匿)。「所属組織なら誰でも current org を切り替えられる」が'
+            .'ロール非依存の仕様であり、Policy を足すと membership の二重判定になるうえ、'
+            .'404 の存在秘匿を 403 に劣化させる危険がある。守っているのは不変条件 2/3。'],
+
+        'organizations.store' => [$noSubject,
+            '新規組織の作成。判定対象となる既存リソースも親テナントも存在しない'
+            .'(誰でも自分の組織を作れる)。制約は verified.or-back middleware と'
+            .'StoreOrganizationRequest のバリデーションのみ。'],
+
+        'invitations.accept.store' => [$tokenBearer,
+            '認可主体は「有効な招待トークンの保持者」。OrganizationMembershipService::acceptInvitation が'
+            .'token hash 照合と失効/期限/受諾済み判定を行う。受諾前の user は対象組織の非メンバーであり、'
+            .'組織 Policy を通すと構造的に必ず拒否になる (機能が成立しない)。'],
+
+        'settings.account.destroy' => [$selfScoped,
+            '対象は $request->user() 自身のみ。route に他者を指せる parameter が 1 つも無く、'
+            .'他人のアカウントへ到達する経路がコード上存在しない。'
+            .'別軸の防御として recent-auth (step-up) middleware を必須にしている。'],
+
+        'recent-auth.password' => [$selfScoped,
+            '自分の再認証鮮度 (RecentAuthState) の更新。route に他者を指せる parameter が無く、'
+            .'認証そのものが主体判定であるため Policy による再判定に意味がない。'
+            .'総当り防御は throttle:6,1。'],
+
+        'notifications.open' => [$selfScoped,
+            'NotificationCenterService::findOwnOrFail($user, ...) が $user->notifications() 経由で'
+            .'解決するため cross-user は構造的に 404 (存在オラクル封じ)。controller docblock が'
+            .'「open は認可判断 (Gate) を一切複製しない」と明示し、遷移先 projects.manuals.show が'
+            .'唯一の判断点、という設計を意図的に採っている。'],
+
+        'notifications.read' => [$selfScoped,
+            'notifications.open と同じく findOwnOrFail による自通知限定解決 (cross-user は 404)。'
+            .'既読化は自分の通知状態の変更に閉じ、他者に影響しない。'],
+
+        'notifications.read-all' => [$selfScoped,
+            'markAllRead($user) で自分宛の通知のみを対象にする。route に parameter が無く、'
+            .'他者の通知へ到達する経路が存在しない。'],
+
+        'api.v1.me.session.revoke' => [$scope,
+            '失効対象は actor 自身の OAuth session (ApiActorContext::$oauthSessionId と一致する 1 件) のみ。'
+            .'加えて abort_unless($actor->hasScope(SessionRevoke), 403) という明示的な 403 判定が既にあり、'
+            .'Policy 対象となる他者リソースが存在しない。'],
+
+        'contact.store' => [$public,
+            '公開問い合わせフォーム (auth 不要 = 認可すべき主体が存在しない)。'
+            .'防御は throttle:inquiry (IP 単独 + IP+email の 2 系統) + honeypot + reCAPTCHA。'],
+
+        'webhooks.ses' => [$signature,
+            'SNS 署名検証 (sns.signature = VerifySnsSignature middleware) が唯一の防御線で、'
+            .'TopicArn allowlist は空なら全拒否の fail-closed。人間の actor が存在しない'
+            .'machine-to-machine 経路のため Policy 判定の主体を定義できない。'],
+
+        'debug.login-as' => [$localOnly,
+            'local / unit test 実行時のみ route が登録され staging/production では登録自体が起きない'
+            .'(routes/web.php の if (app()->isLocal() || app()->runningUnitTests()) による fail-safe)。'
+            .'加えて LocalOnly middleware (local 以外 404 + Basic 認証 + 未設定 404) が二重防御。'],
+    ];
+}
+
+/** @return list<Illuminate\Routing\Route> 変更系 HTTP メソッドを持つ全 route。 */
+function controllerAuthorizationMutatingRoutes(): array
+{
+    $methods = controllerAuthorizationMutatingMethods();
+    $routes = [];
+
+    foreach (Route::getRoutes() as $route) {
+        if (array_intersect($methods, $route->methods()) !== []) {
+            $routes[] = $route;
+        }
+    }
+
+    return $routes;
+}
+
+/** route の識別子 (violation メッセージ用: 名前 / URI / HTTP メソッド)。 */
+function controllerAuthorizationRouteLabel(Illuminate\Routing\Route $route): string
+{
+    $methods = implode('|', array_intersect($route->methods(), controllerAuthorizationMutatingMethods()));
+
+    return ($route->getName() ?? '(無名)').' ['.$methods.' '.$route->uri().']';
+}
+
+/**
+ * route ハンドラのソース断片を fail-secure に解決する。
+ *
+ * 解決できない場合は「認可なし」ではなく **解決失敗** として返す (合格側に倒さない)。
+ *
+ * @return array{status: 'ok', file: string, fragment: string}|array{status: 'vendor'}|array{status: 'fail', reason: string}
+ */
+function controllerAuthorizationHandlerSource(Illuminate\Routing\Route $route): array
+{
+    $uses = $route->getAction('uses');
+
+    try {
+        if (is_string($uses)) {
+            $parts = explode('@', $uses);
+            if (count($parts) !== 2) {
+                return ['status' => 'fail', 'reason' => "action 'uses' が Class@method 形式でない: {$uses}"];
+            }
+            $reflection = new ReflectionMethod($parts[0], $parts[1]);
+        } elseif ($uses instanceof Closure) {
+            $reflection = new ReflectionFunction($uses);
+        } else {
+            return ['status' => 'fail', 'reason' => "action 'uses' が string でも Closure でもない: ".get_debug_type($uses)];
+        }
+    } catch (Throwable $e) {
+        return ['status' => 'fail', 'reason' => 'Reflection の生成に失敗: '.$e->getMessage()];
+    }
+
+    $file = $reflection->getFileName();
+    if ($file === false) {
+        return ['status' => 'fail', 'reason' => 'getFileName() が false (内部関数)'];
+    }
+    $real = realpath($file);
+    if ($real === false) {
+        return ['status' => 'fail', 'reason' => "realpath() が false (ファイル不在): {$file}"];
+    }
+
+    // パッケージ所有 route はパッケージ側が防御を担う。パス境界込みで判定し
+    // `vendor-foo/` のような prefix 一致の誤判定を防ぐ
+    $vendorDir = realpath(base_path('vendor'));
+    if (is_string($vendorDir) && str_starts_with($real, $vendorDir.DIRECTORY_SEPARATOR)) {
+        return ['status' => 'vendor'];
+    }
+
+    $lines = file($real);
+    if ($lines === false) {
+        return ['status' => 'fail', 'reason' => "file() が false (読み取り不可): {$real}"];
+    }
+
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    if ($start === false || $end === false) {
+        return ['status' => 'fail', 'reason' => 'getStartLine()/getEndLine() が false'];
+    }
+
+    $fragment = implode('', array_slice($lines, $start - 1, $end - $start + 1));
+    if (trim($fragment) === '') {
+        return ['status' => 'fail', 'reason' => "切り出したハンドラ断片が空: {$real}:{$start}-{$end}"];
+    }
+
+    return ['status' => 'ok', 'file' => $real, 'fragment' => $fragment];
+}
+
+test('変更系 route の候補は下限を下回らない (空振り drift ガード)', function (): void {
+    $candidates = 0;
+
+    foreach (controllerAuthorizationMutatingRoutes() as $route) {
+        if (controllerAuthorizationHandlerSource($route)['status'] === 'vendor') {
+            continue;
+        }
+        $candidates++;
+    }
+
+    expect($candidates)->toBeGreaterThanOrEqual(
+        controllerAuthorizationRouteFloor(),
+        "アプリ所有の変更系 route が {$candidates} 件しか検出されませんでした。"
+        .'route 走査/解決ロジックが空振りしている可能性があります。',
+    );
+});
+
+test('変更系 route のハンドラはすべて Reflection で解決できる (fail-secure)', function (): void {
+    $violations = [];
+
+    foreach (controllerAuthorizationMutatingRoutes() as $route) {
+        $resolved = controllerAuthorizationHandlerSource($route);
+        if ($resolved['status'] === 'fail') {
+            $violations[] = controllerAuthorizationRouteLabel($route).': '.$resolved['reason'];
+        }
+    }
+
+    expect($violations)->toBe([],
+        'ハンドラを解決できない変更系 route があります (認可の有無を判定できないため fail させます)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('変更系 route は認可を持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
+    $inventory = controllerAuthorizationExemptions();
+    $violations = [];
+    $checked = 0;
+
+    foreach (controllerAuthorizationMutatingRoutes() as $route) {
+        $resolved = controllerAuthorizationHandlerSource($route);
+        if ($resolved['status'] === 'vendor') {
+            continue;
+        }
+        if ($resolved['status'] === 'fail') {
+            // 解決失敗は専用テストが詳細を出す。ここでは合格に倒さないことだけ担保する
+            $violations[] = controllerAuthorizationRouteLabel($route).': ハンドラを解決できませんでした';
+
+            continue;
+        }
+        $checked++;
+
+        $name = $route->getName();
+
+        if (AuthorizationMarkerScanner::hasAuthorizationMarker($resolved['fragment'])) {
+            // 同名の別クラスによる誤合格を防ぐ: Facade の名前空間 import を必須にする
+            $source = file_get_contents($resolved['file']);
+            if ($source === false || ! AuthorizationMarkerScanner::importsGateFacade($source)) {
+                $violations[] = controllerAuthorizationRouteLabel($route)
+                    .': Gate:: の認可マーカーはあるが use Illuminate\Support\Facades\Gate; の'
+                    .' import がありません (同名の別クラスの可能性があるため合格にしません)';
+            }
+
+            continue;
+        }
+
+        if ($name !== null && array_key_exists($name, $inventory)) {
+            continue;
+        }
+
+        $violations[] = controllerAuthorizationRouteLabel($route).' が未分類';
+    }
+
+    expect($violations)->toBe([],
+        '認可判断 (Gate::authorize / Gate::forUser(...)->authorize) を持たない変更系 route があります。'
+        .'ハンドラに認可を足すか、認可が不要な理由を controllerAuthorizationExemptions() に'
+        .'ControllerAuthorizationExemption + 具体的根拠付きで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+
+    expect($checked)->toBeGreaterThan(0);
+});
+
+test('exemption inventory の key は現存 named route (逆方向整合・stale 検出)', function (): void {
+    $named = [];
+    foreach (Route::getRoutes() as $route) {
+        $n = $route->getName();
+        if ($n !== null) {
+            $named[$n] = true;
+        }
+    }
+
+    $stale = [];
+    foreach (array_keys(controllerAuthorizationExemptions()) as $key) {
+        if (! isset($named[$key])) {
+            $stale[] = $key;
+        }
+    }
+
+    expect($stale)->toBe([],
+        'exemption inventory に現存しない route 名 (削除/rename 済) があります: '.implode(', ', $stale));
+});
+
+test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
+    $minLength = controllerAuthorizationReasonMinLength();
+    $violations = [];
+
+    foreach (controllerAuthorizationExemptions() as $name => [$exemption, $reason]) {
+        if (! $exemption instanceof ControllerAuthorizationExemption) {
+            $violations[] = "{$name}: 第 1 要素が ControllerAuthorizationExemption ではありません";
+        }
+        if (mb_strlen($reason) < $minLength) {
+            $violations[] = "{$name}: 理由が {$minLength} 文字未満です (「同上」「N/A」で埋める運用を止めます)";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('URL 整合 guard は認可より前に置かれている (不変条件 2)', function (): void {
+    $guards = controllerAuthorizationInlineGuards();
+    $violations = [];
+    $checked = 0;
+
+    foreach (controllerAuthorizationMutatingRoutes() as $route) {
+        $resolved = controllerAuthorizationHandlerSource($route);
+        if ($resolved['status'] !== 'ok') {
+            continue;
+        }
+
+        $guardOffset = AuthorizationMarkerScanner::guardMarkerOffset($resolved['fragment'], $guards);
+        $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($resolved['fragment']);
+        if ($guardOffset === null || $authOffset === null) {
+            continue;
+        }
+        $checked++;
+
+        if ($guardOffset > $authOffset) {
+            $violations[] = controllerAuthorizationRouteLabel($route)
+                .': URL 整合 guard が認可 (Gate) より後に置かれています'
+                .' (cross-org が 404 ではなく 403 を返し、リソースの存在が漏れます)';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+    // guard と認可の両方を持つ route が 1 本も無い = 順序検証が空振りしている
+    expect($checked)->toBeGreaterThan(0);
+});
diff --git a/tests/Architecture/NestedRouteIdorDefenseTest.php b/tests/Architecture/NestedRouteIdorDefenseTest.php
index 984687f..11198dd 100644
--- a/tests/Architecture/NestedRouteIdorDefenseTest.php
+++ b/tests/Architecture/NestedRouteIdorDefenseTest.php
@@ -82,6 +82,11 @@ function nestedRouteIdorInventory(): array
         'capture.takes.adopt' => $s,
         'capture.takes.downloaded' => $s,
         'capture.takes.playback' => $s,
+        // REST API v1: {item} は $project->items() 経由 (scopeBindings)。
+        // {project} ∈ actor の組織は api.project-in-org middleware + controller inline guard の
+        // 2 層 (いずれも認可より前に 404。middleware は FormRequest より前に走る)
+        'api.v1.projects.items.update' => $s,
+        'api.v1.projects.items.destroy' => $s,
         // --- inline 親子整合 guard (authorize 前に 子∈親テナント を検査、不整合は 404) ---
         // OrganizationMemberController::resolveOrganizationMember (非 member は 404)
         'organizations.members.update' => $g,
@@ -89,10 +94,6 @@ function nestedRouteIdorInventory(): array
         'organizations.members.two-factor.reset' => $g,
         // ProjectMemberController::destroy (org 越境 {user} は 404)
         'projects.members.destroy' => $g,
-        // REST API v1: API キーの組織 relation からの org-scoped 解決
-        // (ResolvesApiOrganization。cross-org は認可より前に 404)
-        'api.v1.projects.items.update' => $g,
-        'api.v1.projects.items.destroy' => $g,
     ];
 }
 
diff --git a/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php b/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
index 465e455..42d1ad8 100644
--- a/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
+++ b/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
@@ -5,22 +5,25 @@
 use Illuminate\Support\Facades\Route;
 
 /*
- * web の `{project}` route は project.in-route-org middleware
- * (EnsureProjectBelongsToRouteOrganization) を必ず持つ invariant。
+ * `{project}` を受ける route は URL 整合 guard を **middleware 層**に必ず持つ invariant。
  *
  * cross-org の {project} は「FormRequest の DB ルール (unique/exists) を含む
  * あらゆるアプリコードより前に 404」でなければならない (存在オラクル防止)。
  * controller の inline guard (resolveOrganizationProject) は認可より前の 404 を担うが、
  * FormRequest のバリデーションは controller メソッド解決時 (= inline guard より前) に走るため、
- * middleware 層の guard が無いと project スコープの DB ルールがクロステナントの
- * 存在オラクルになる (T001 レビュー指摘)。本テストは deny-by-default で
- * 「{project} を受ける web route に middleware が付いていること」を機械検証し、
- * 将来の route 追加での guard 漏れを構造的に落とす。
+ * middleware 層の guard が無いと「cross-org の実在 project + 不正 payload = 422 /
+ * 不在 project = 404」の差分がクロステナントの存在オラクルになる (T001 / T103 レビュー指摘)。
+ * 本テストは deny-by-default で「{project} を受ける route に middleware が付いていること」を
+ * 機械検証し、将来の route 追加での guard 漏れを構造的に落とす。
  *
- * API v1 (`api/*`) は org を API キーから確定する別レイヤー (ResolvesApiOrganization) の
- * 責務のため対象外 (web セッション前提の本 middleware を付けてはならない)。
+ * 組織の解決元が違うため middleware は web / API で 2 本立てになる:
+ *  - web (`project.in-route-org` = EnsureProjectBelongsToRouteOrganization):
+ *    セッションの current org。API に付けてはならない (API はセッションを持たない)
+ *  - API v1 (`api.project-in-org` = EnsureProjectBelongsToApiOrganization):
+ *    API キー / OAuth token から確定した request attribute 'organization'
  */
-test('web の {project} route は project.in-route-org middleware を必ず持つ (API は持たない)', function (): void {
+
+test('web の {project} route は project.in-route-org / API は api.project-in-org を必ず持つ', function (): void {
     $checked = 0;
     $violations = [];
 
@@ -38,6 +41,11 @@
             if (in_array('project.in-route-org', $middleware, true)) {
                 $violations[] = "API route {$name} に web セッション前提の project.in-route-org が付いている";
             }
+            // API 版の URL 整合 guard は必須 (FormRequest より前に cross-org を 404 に落とす)
+            if (! in_array('api.project-in-org', $middleware, true)) {
+                $violations[] = "API route {$name} に api.project-in-org middleware が無い"
+                    .' (cross-org {project} が FormRequest より前に 404 になりません)';
+            }
             $checked++;
 
             continue;
@@ -55,3 +63,61 @@
     // テスト自体の空振り drift を検知する
     expect($checked)->toBeGreaterThan(0);
 });
+
+/*
+ * API の middleware 順序契約 (docblock ではなく機械で固定する):
+ *
+ *   resolve.api-actor  <  api.project-in-org  <  idempotent
+ *
+ * | 契約 | 破ったときに起きること |
+ * |---|---|
+ * | resolve.api-actor が api.project-in-org より前 | 'organization' attribute 未設定で Assert が
+ *   発火し **全 API {project} route が 500** |
+ * | api.project-in-org が idempotent より前 | **cross-org リクエストで idempotency 行が作られる**
+ *   (cross-org の副作用 = 不変条件 3 に抵触) |
+ *
+ * 注意: gatherMiddleware() が返すのは **宣言順** (group middleware → route middleware)。
+ * Laravel の middleware priority ($middlewarePriority) を導入すると最終的な実行順が
+ * 並べ替えられうるが、現行構成では本テストが検査する custom middleware
+ * (resolve.api-actor / api.project-in-org / idempotent) はいずれも priority リストに
+ * 含まれないため宣言順 = 実行順である。priority を導入する際は本テストの前提を見直すこと。
+ */
+test('API の {project} route は middleware 順序契約を守る', function (): void {
+    $checked = 0;
+    $violations = [];
+
+    foreach (Route::getRoutes() as $route) {
+        if (! str_starts_with($route->uri(), 'api/')) {
+            continue;
+        }
+        if (! in_array('project', $route->parameterNames(), true)) {
+            continue;
+        }
+
+        $name = $route->getName() ?? $route->uri();
+        $middleware = $route->gatherMiddleware();
+        $indexOf = static fn (string $needle): int|false => array_search($needle, $middleware, true);
+
+        $guard = $indexOf('api.project-in-org');
+        $actor = $indexOf('resolve.api-actor');
+        $idempotent = $indexOf('idempotent');
+
+        if ($guard === false) {
+            $violations[] = "{$name}: api.project-in-org が無い";
+
+            continue;
+        }
+        if ($actor === false || $actor > $guard) {
+            $violations[] = "{$name}: resolve.api-actor が api.project-in-org より後 "
+                .'(organization attribute 未設定で 500 になります)';
+        }
+        if ($idempotent !== false && $idempotent < $guard) {
+            $violations[] = "{$name}: idempotent が api.project-in-org より前 "
+                .'(cross-org リクエストで idempotency 行が作られます)';
+        }
+        $checked++;
+    }
+
+    expect($violations)->toBe([]);
+    expect($checked)->toBeGreaterThan(0); // 空振り drift ガード
+});
diff --git a/tests/Feature/Api/OAuthDualGuardTest.php b/tests/Feature/Api/OAuthDualGuardTest.php
index fc1efb4..3731a28 100644
--- a/tests/Feature/Api/OAuthDualGuardTest.php
+++ b/tests/Feature/Api/OAuthDualGuardTest.php
@@ -5,7 +5,6 @@
 use App\Models\OauthSession;
 use App\Models\Project;
 use Illuminate\Support\Facades\Auth;
-use Illuminate\Support\Facades\DB;
 use Tests\Support\OAuthTestHelpers;
 
 /*
@@ -24,40 +23,13 @@
     $this->redirectUri = 'https://test.example/callback';
 });
 
-/**
- * OAuth flow で token を取得し、CLI セッション行を作って access token に束縛する。
- * (CLI client の consent フローでの session 自動発行は WP25。ここでは actor 解決の
- * 前提条件を DB 直接で満たす)
- *
- * @return array{access_token: string, refresh_token: string, session: OauthSession}
- */
-function issueCliSessionTokens(object $test, string $scope = 'cli:use read write session.revoke'): array
-{
-    $tokens = OAuthTestHelpers::exchangeForTokens($test, scope: $scope);
-    expect($tokens['access_token'])->not->toBe('');
-
-    $tokenId = DB::table('oauth_access_tokens')
-        ->where('user_id', $test->user->id)
-        ->orderByDesc('created_at')
-        ->value('id');
-    expect($tokenId)->not->toBeNull();
-
-    /** @var OauthSession $session */
-    $session = OauthSession::factory()->cli()->create([
-        'user_id' => $test->user->id,
-        'organization_id' => $test->org->id,
-        'client_id' => (string) $test->client->id,
-    ]);
-
-    DB::table('oauth_access_tokens')->where('id', $tokenId)->update(['session_id' => $session->id]);
-
-    Auth::forgetGuards();
-
-    return [...$tokens, 'session' => $session];
-}
-
 test('OAuth user token は dual guard で GET /api/v1/me を叩ける (session セクション出し分け)', function (): void {
-    $issued = issueCliSessionTokens($this);
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+    );
 
     $response = $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
         ->getJson('/api/v1/me');
@@ -103,7 +75,12 @@ function issueCliSessionTokens(object $test, string $scope = 'cli:use read write
 });
 
 test('session 失効後は同じ token が 401 になる (chain 失効)', function (): void {
-    $issued = issueCliSessionTokens($this);
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+    );
     $issued['session']->revoke();
     Auth::forgetGuards();
 
@@ -113,7 +90,12 @@ function issueCliSessionTokens(object $test, string $scope = 'cli:use read write
 });
 
 test('組織から外れた user の token は 401 (membership 毎リクエスト再検証)', function (): void {
-    $issued = issueCliSessionTokens($this);
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+    );
     $this->org->users()->detach($this->user->id);
     Auth::forgetGuards();
 
@@ -123,7 +105,13 @@ function issueCliSessionTokens(object $test, string $scope = 'cli:use read write
 });
 
 test('write scope の無い OAuth token は write endpoint で 403 insufficient_ability', function (): void {
-    $issued = issueCliSessionTokens($this, scope: 'cli:use read');
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+        scope: 'cli:use read',
+    );
     $project = Project::factory()->forOrganization($this->org)->create();
 
     $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
@@ -134,7 +122,12 @@ function issueCliSessionTokens(object $test, string $scope = 'cli:use read write
 });
 
 test('write scope 付き OAuth token は write endpoint を叩けて Idempotency-Key も機能する', function (): void {
-    $issued = issueCliSessionTokens($this);
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+    );
     $project = Project::factory()->forOrganization($this->org)->create();
 
     $first = $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
@@ -153,7 +146,12 @@ function issueCliSessionTokens(object $test, string $scope = 'cli:use read write
 });
 
 test('DELETE /api/v1/me/session は自セッションを失効させ、以降の token を 401 にする', function (): void {
-    $issued = issueCliSessionTokens($this);
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+    );
 
     $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
         ->deleteJson('/api/v1/me/session')
@@ -171,7 +169,13 @@ function issueCliSessionTokens(object $test, string $scope = 'cli:use read write
 });
 
 test('session.revoke scope の無い token は DELETE /me/session が 403', function (): void {
-    $issued = issueCliSessionTokens($this, scope: 'cli:use read');
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+        scope: 'cli:use read',
+    );
 
     $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
         ->deleteJson('/api/v1/me/session')
@@ -190,7 +194,12 @@ function issueCliSessionTokens(object $test, string $scope = 'cli:use read write
 });
 
 test('OauthSession::revoke は冪等 (再実行しても初回の失効時刻を保持)', function (): void {
-    $issued = issueCliSessionTokens($this);
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $this->user,
+        organization: $this->org,
+        client: $this->client,
+    );
     $session = $issued['session'];
 
     $session->revoke();
diff --git a/tests/Feature/Api/V1/ItemAuthorizationTest.php b/tests/Feature/Api/V1/ItemAuthorizationTest.php
new file mode 100644
index 0000000..83f9784
--- /dev/null
+++ b/tests/Feature/Api/V1/ItemAuthorizationTest.php
@@ -0,0 +1,307 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Models\Item;
+use App\Models\Organization;
+use App\Models\Project;
+use Tests\Support\OAuthTestHelpers;
+
+/*
+ * REST API v1 Item の認可境界 (web 側 Projects\ItemController と同一の ItemPolicy 境界)。
+ *
+ * ★不変条件: cross-org は認可より前に 404 (403 で存在を漏らさない)。
+ *   認可を足したことで 404 が 403 に劣化していないことを本テストが固定する。
+ * ★actor は ApiActorContext::$user (API キー = 発行者 / OAuth = トークン所有者)。
+ *   Gate::authorize (default guard) を使うと API キー経路で ApiKey が渡り 500 になるため、
+ *   API キー経路と OAuth 経路の両方で 403 を assert する。
+ * ★存在オラクル封じ: cross-org の実在 project は「FormRequest のバリデーションより前」に
+ *   404 でなければならない (api.project-in-org middleware)。不正 payload で 422、
+ *   不在 id で 404 になると、その差分が project の実在を漏らす。
+ */
+
+/** viewer (組織 member かつ project ロールなし) を作り API キー平文を返す。 */
+function viewerApiKey(Organization $organization): string
+{
+    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
+    [, $plain] = issueApiKey($organization, $viewer, ['read', 'write']);
+
+    return $plain;
+}
+
+/** Bearer ヘッダ。 */
+function apiBearer(string $plain): array
+{
+    return ['Authorization' => "Bearer {$plain}"];
+}
+
+// --- API キー経路: 権限不足は 403 (ケース 1/2/3/11) ---
+
+test('viewer の API キーでは Item を作成できない (403)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $this->withHeaders(apiBearer(viewerApiKey($organization)))
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '侵入'])
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'forbidden');
+
+    // ケース 11: 副作用が起きていないこと
+    expect($project->items()->count())->toBe(0);
+});
+
+test('viewer の API キーでは Item を更新できない (403)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $item = Item::factory()->forProject($project)->create(['name' => '元の名前']);
+
+    $this->withHeaders(apiBearer(viewerApiKey($organization)))
+        ->patchJson("/api/v1/projects/{$project->id}/items/{$item->id}", ['name' => '書き換え'])
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'forbidden');
+
+    expect($item->fresh()?->name)->toBe('元の名前');
+});
+
+test('viewer の API キーでは Item を削除できない (403) かつ Item が残る', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $item = Item::factory()->forProject($project)->create();
+
+    $this->withHeaders(apiBearer(viewerApiKey($organization)))
+        ->deleteJson("/api/v1/projects/{$project->id}/items/{$item->id}")
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'forbidden');
+
+    expect($item->fresh())->not->toBeNull();
+});
+
+// --- API キー経路: 権限のある actor は通る (ケース 4/5) ---
+
+test('project_admin の API キーは store / update / destroy を通る', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $editor = attachOrganizationMember($organization, OrganizationRole::Member);
+    attachProjectMember($project, $editor, ProjectRole::Admin);
+    [, $plain] = issueApiKey($organization, $editor, ['read', 'write']);
+
+    $created = $this->withHeaders(apiBearer($plain))
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '作成'])
+        ->assertCreated();
+
+    $itemId = $created->json('data.id');
+
+    $this->withHeaders(apiBearer($plain))
+        ->patchJson("/api/v1/projects/{$project->id}/items/{$itemId}", ['name' => '更新'])
+        ->assertOk();
+
+    $this->withHeaders(apiBearer($plain))
+        ->deleteJson("/api/v1/projects/{$project->id}/items/{$itemId}")
+        ->assertOk();
+
+    expect($project->items()->count())->toBe(0);
+});
+
+test('組織 admin の API キーは project ロールが無くても store を通る (継承規則)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
+    [, $plain] = issueApiKey($organization, $admin, ['read', 'write']);
+
+    $this->withHeaders(apiBearer($plain))
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '管理者作成'])
+        ->assertCreated();
+
+    expect($project->items()->count())->toBe(1);
+});
+
+// --- OAuth CLI トークン経路 (ケース 6/7) ---
+
+test('viewer の OAuth CLI トークンでも Item を作成できない (403)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $viewer,
+        organization: $organization,
+        client: OAuthTestHelpers::createMcpClient(name: 'Test CLI Client'),
+        scope: 'cli:use read write',
+    );
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '侵入'])
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'forbidden');
+
+    expect($project->items()->count())->toBe(0);
+});
+
+test('project_admin の OAuth CLI トークンは Item を作成できる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $editor = attachOrganizationMember($organization, OrganizationRole::Member);
+    attachProjectMember($project, $editor, ProjectRole::Admin);
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $editor,
+        organization: $organization,
+        client: OAuthTestHelpers::createMcpClient(name: 'Test CLI Client'),
+        scope: 'cli:use read write',
+    );
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'CLI作成'])
+        ->assertCreated();
+
+    expect($project->items()->count())->toBe(1);
+});
+
+// --- cross-org は認可より前に 404 (ケース 8/9) ---
+
+test('cross-org の store / update / destroy は 404 (403 ではない)', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+    $itemA = Item::factory()->forProject($projectA)->create();
+    // 組織 B の owner (= 組織 B では最強権限) でも、組織 A のリソースには到達できない
+    [, $plain] = issueApiKey($organizationB, $ownerB, ['read', 'write']);
+
+    $this->withHeaders(apiBearer($plain))
+        ->postJson("/api/v1/projects/{$projectA->id}/items", ['name' => '越境'])
+        ->assertNotFound()
+        ->assertJsonPath('error.code', 'not_found');
+
+    $this->withHeaders(apiBearer($plain))
+        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", ['name' => '越境'])
+        ->assertNotFound()
+        ->assertJsonPath('error.code', 'not_found');
+
+    $this->withHeaders(apiBearer($plain))
+        ->deleteJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}")
+        ->assertNotFound()
+        ->assertJsonPath('error.code', 'not_found');
+
+    expect($itemA->fresh())->not->toBeNull();
+});
+
+test('cross-org かつ viewer は認可より前に 404 (403 が返るなら順序が壊れている)', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+    $itemA = Item::factory()->forProject($projectA)->create();
+
+    $this->withHeaders(apiBearer(viewerApiKey($organizationB)))
+        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", ['name' => '更新'])
+        ->assertNotFound()
+        ->assertJsonPath('error.code', 'not_found');
+});
+
+// --- 判定は URL 上の {project} の組織で行う (ケース 10) ---
+
+test('actor の current_organization_id が別組織でも URL の {project} の組織で判定される', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+
+    $editor = attachOrganizationMember($organizationA, OrganizationRole::Member);
+    attachProjectMember($projectA, $editor, ProjectRole::Admin);
+    // Laratrust team 文脈が current org に汚染されないことの固定
+    $editor->forceFill(['current_organization_id' => $organizationB->id])->save();
+
+    [, $plain] = issueApiKey($organizationA, $editor, ['read', 'write']);
+
+    $this->withHeaders(apiBearer($plain))
+        ->postJson("/api/v1/projects/{$projectA->id}/items", ['name' => '別orgカレント'])
+        ->assertCreated();
+
+    expect($projectA->items()->count())->toBe(1);
+});
+
+// --- 存在オラクル封じ (ケース 12-15) ---
+
+test('cross-org + 不正 payload の store は 422 ではなく 404', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+
+    $this->withHeaders(apiBearer(viewerApiKey($organizationB)))
+        ->postJson("/api/v1/projects/{$projectA->id}/items", ['note' => 'name 欠落'])
+        ->assertNotFound()
+        ->assertJsonPath('error.code', 'not_found');
+});
+
+test('cross-org + protected key payload の store は 422 ではなく 404', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+
+    $this->withHeaders(apiBearer(viewerApiKey($organizationB)))
+        ->postJson("/api/v1/projects/{$projectA->id}/items", [
+            'name' => '越境',
+            'project_id' => $projectA->id,
+        ])
+        ->assertNotFound()
+        ->assertJsonPath('error.code', 'not_found');
+});
+
+test('cross-org + 空 payload の update は 422 ではなく 404', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+    $itemA = Item::factory()->forProject($projectA)->create();
+
+    $this->withHeaders(apiBearer(viewerApiKey($organizationB)))
+        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", [])
+        ->assertNotFound()
+        ->assertJsonPath('error.code', 'not_found');
+});
+
+test('cross-org の実在 project と 存在しない project id は完全に同一応答', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+    $headers = apiBearer(viewerApiKey($organizationB));
+    $payload = ['note' => 'name 欠落'];
+
+    $crossOrg = $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$projectA->id}/items", $payload);
+    $missing = $this->withHeaders($headers)
+        ->postJson('/api/v1/projects/999999999/items', $payload);
+
+    // status も body も一致 = 実在/不在の識別子が 1 bit も漏れていない
+    expect($crossOrg->getStatusCode())->toBe(404)
+        ->and($missing->getStatusCode())->toBe(404)
+        ->and($crossOrg->json())->toBe($missing->json());
+});
+
+// --- idempotency 層との相互作用 (ケース 16) ---
+
+test('403 は Idempotency-Key で再生されない (権限付与後の再送は成功する)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
+    [, $plain] = issueApiKey($organization, $viewer, ['read', 'write']);
+    $headers = ['Authorization' => "Bearer {$plain}", 'Idempotency-Key' => 'fixed-key-001'];
+    $payload = ['name' => 'アイテム'];
+
+    $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertForbidden();
+
+    attachProjectMember($project, $viewer, ProjectRole::Admin);
+    // relation キャッシュ由来の偽陰性でテスト失敗の原因が切り分けられなくなるのを防ぐ
+    $viewer->refresh();
+    $project->unsetRelations();
+
+    // 保存済み 403 が再生されるなら 403 のまま = 権限回復後も詰む
+    $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertCreated();
+
+    expect($project->items()->count())->toBe(1);
+});
diff --git a/tests/Support/AuthorizationMarkerScanner.php b/tests/Support/AuthorizationMarkerScanner.php
new file mode 100644
index 0000000..ed3b987
--- /dev/null
+++ b/tests/Support/AuthorizationMarkerScanner.php
@@ -0,0 +1,286 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * 認可マーカー (`Gate::authorize` / `Gate::forUser(...)->authorize`) の字句解析器。
+ *
+ * `ControllerAuthorizationGateTest` (変更系 route の deny-by-default 認可 gate) の
+ * 検出ロジックを route 走査から切り離した純粋 helper。
+ * 「route 走査 = テスト、字句解析 = 本 helper」と責務を分け、解析器そのものの
+ * positive/negative を `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php` が
+ * 恒久固定する (gate 自体がセキュリティ機構であり、手動のコメントアウト検証では
+ * 後の改修に対する回帰が効かないため)。
+ *
+ * ★設計判断:
+ *  - 正規表現は使わない。`/Gate::forUser.*?->authorize/` は
+ *    `Gate::forUser($u); $other->authorize();` のような無関係な 2 文でも合格してしまう
+ *    (deny-by-default では誤合格が最悪の失敗モード)。括弧の深さを数える状態機械で
+ *    「同一メソッドチェーン」であることを確認する。
+ *  - コメント / 文字列リテラルはトークン段階で除去する
+ *    (`// Gate::authorize を通す` のような記述で誤合格させない)。
+ *  - 完全修飾名 (`\Illuminate\Support\Facades\Gate::authorize`) は受理しない。
+ *    同名の別クラスによる誤合格を防ぐため、合格判定したファイルには
+ *    `use Illuminate\Support\Facades\Gate;` の名前空間 import を必須とする
+ *    ({@see self::importsGateFacade()})。
+ *
+ * ★前提 (将来 bracketed namespace を導入する場合は要見直し):
+ *  本リポジトリは非 bracketed namespace (`namespace App\Foo;` のセミコロン形式) で
+ *  統一されている。bracketed namespace (`namespace App { ... }`) を使うと
+ *  名前空間 import の波括弧深さが 0 でなくなり {@see self::importsGateFacade()} の
+ *  深さ判定が崩れる。Pint も非 bracketed を強制するため現状は対応しない。
+ */
+final class AuthorizationMarkerScanner
+{
+    /** 受理する Facade の完全修飾名 (これ以外の `Gate` は同名の別クラスとして扱う)。 */
+    private const GATE_FACADE = 'Illuminate\Support\Facades\Gate';
+
+    /**
+     * メソッド本体のソース断片に認可マーカーがあるか。
+     *
+     * @param  string  $methodSource  `ReflectionMethod` の開始行〜終了行を切り出した PHP 断片
+     */
+    public static function hasAuthorizationMarker(string $methodSource): bool
+    {
+        return self::authorizationMarkerOffset($methodSource) !== null;
+    }
+
+    /**
+     * 認可マーカーが最初に現れるトークン位置 (無ければ null)。
+     *
+     * 「URL 整合 guard → 認可」の順序検証 (不変条件 2) に使う。
+     */
+    public static function authorizationMarkerOffset(string $methodSource): ?int
+    {
+        $tokens = self::significantTokens($methodSource);
+        $count = count($tokens);
+        $offsets = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i] !== 'Gate' || ($tokens[$i + 1] ?? '') !== '::') {
+                continue;
+            }
+
+            // (d-1) Gate :: authorize (
+            if (($tokens[$i + 2] ?? '') === 'authorize' && ($tokens[$i + 3] ?? '') === '(') {
+                $offsets[] = $i;
+
+                continue;
+            }
+
+            // (d-2) Gate :: forUser ( ... ) -> authorize
+            if (($tokens[$i + 2] ?? '') !== 'forUser' || ($tokens[$i + 3] ?? '') !== '(') {
+                continue;
+            }
+
+            $close = self::matchingParenthesis($tokens, $i + 3);
+            if ($close === null) {
+                continue;
+            }
+            // forUser() の戻り値に対して**直接** authorize() を呼んでいる形だけを合格とする
+            // (間に `;` や別の式が挟まればチェーンは切れており不合格)。
+            if (($tokens[$close + 1] ?? '') === '->' && ($tokens[$close + 2] ?? '') === 'authorize') {
+                $offsets[] = $i;
+            }
+        }
+
+        return $offsets === [] ? null : min($offsets);
+    }
+
+    /**
+     * inline URL 整合 guard (`$this->resolveOrganizationProject(...)` 等) の最初のトークン位置。
+     *
+     * @param  list<string>  $guardMethods  guard とみなすメソッド名
+     */
+    public static function guardMarkerOffset(string $methodSource, array $guardMethods): ?int
+    {
+        $tokens = self::significantTokens($methodSource);
+        $count = count($tokens);
+
+        for ($i = 1; $i < $count; $i++) {
+            if ($tokens[$i - 1] === '->'
+                && in_array($tokens[$i], $guardMethods, true)
+                && ($tokens[$i + 1] ?? '') === '(') {
+                return $i;
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * ファイル全文に `use Illuminate\Support\Facades\Gate;` の名前空間 import があるか。
+     *
+     * `T_USE` は 3 用途 (名前空間 import / クロージャの lexical use / trait use) あるため、
+     * **波括弧の深さ 0** かつ **直後が `(` でない** ものだけを名前空間 import とみなす。
+     * alias 付き (`... Gate as G;`) と group use (`...Facades\{Gate, Auth};`) は
+     * `Gate::` が本 Facade を指す保証が無いため受理しない (deny-by-default)。
+     */
+    public static function importsGateFacade(string $fileSource): bool
+    {
+        $tokens = token_get_all(self::withOpenTag($fileSource));
+        $count = count($tokens);
+        $depth = 0;
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+
+            if (is_array($token)) {
+                // 文字列内の `{$var}` / `${var}` も対応する `}` は生トークンのため深さに数える
+                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
+                    $depth++;
+                }
+                if ($token[0] !== T_USE || $depth !== 0) {
+                    continue;
+                }
+                if (self::matchesGateImport($tokens, $i)) {
+                    return true;
+                }
+
+                continue;
+            }
+
+            if ($token === '{') {
+                $depth++;
+            } elseif ($token === '}') {
+                $depth--;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * `use` トークン位置から Gate Facade の名前空間 import かを判定する。
+     *
+     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
+     */
+    private static function matchesGateImport(array $tokens, int $useIndex): bool
+    {
+        $count = count($tokens);
+        $i = self::skipInsignificant($tokens, $useIndex + 1);
+
+        // クロージャの lexical use (`function ($x) use ($y) {}`)
+        if ($i >= $count || $tokens[$i] === '(') {
+            return false;
+        }
+
+        $name = '';
+        for (; $i < $count; $i++) {
+            $token = $tokens[$i];
+            if (is_array($token)) {
+                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
+                    continue;
+                }
+                if (in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+                    $name .= $token[1];
+
+                    continue;
+                }
+
+                // `use function ...` / `use const ...` / `... as Alias` 等
+                return false;
+            }
+
+            if ($token === '\\') {
+                $name .= '\\';
+
+                continue;
+            }
+
+            // alias (`as`) も group use (`{`) も無い、素の import だけを受理する
+            return $token === ';' && ltrim($name, '\\') === self::GATE_FACADE;
+        }
+
+        return false;
+    }
+
+    /**
+     * 空白・コメントを読み飛ばした次のトークン位置。
+     *
+     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
+     */
+    private static function skipInsignificant(array $tokens, int $from): int
+    {
+        $count = count($tokens);
+        for ($i = $from; $i < $count; $i++) {
+            $token = $tokens[$i];
+            if (is_array($token)
+                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+                continue;
+            }
+
+            return $i;
+        }
+
+        return $count;
+    }
+
+    /**
+     * 意味のあるトークンだけをテキスト列に正規化する。
+     *
+     * コメント / 文字列リテラル / 可変長文字列の中身 / 空白を除去することで
+     * 「コメントに書かれた Gate::authorize」を誤検出しない。
+     *
+     * @return list<string>
+     */
+    private static function significantTokens(string $source): array
+    {
+        $ignored = [
+            T_COMMENT,
+            T_DOC_COMMENT,
+            T_CONSTANT_ENCAPSED_STRING,
+            T_ENCAPSED_AND_WHITESPACE,
+            T_WHITESPACE,
+        ];
+
+        $result = [];
+        // ★開始タグを付けないと断片全体が T_INLINE_HTML になり検出が全滅する
+        foreach (token_get_all(self::withOpenTag($source)) as $token) {
+            if (is_array($token)) {
+                if (in_array($token[0], $ignored, true)) {
+                    continue;
+                }
+                $result[] = $token[1];
+
+                continue;
+            }
+
+            $result[] = $token;
+        }
+
+        return $result;
+    }
+
+    /**
+     * `(` の位置から対応する `)` の位置を返す (引数内のネスト括弧を正しくスキップする)。
+     *
+     * @param  list<string>  $tokens
+     */
+    private static function matchingParenthesis(array $tokens, int $open): ?int
+    {
+        $count = count($tokens);
+        $depth = 0;
+
+        for ($i = $open; $i < $count; $i++) {
+            if ($tokens[$i] === '(') {
+                $depth++;
+            } elseif ($tokens[$i] === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /** 断片を token_get_all にかけられる形 (開始タグ付き) にする。 */
+    private static function withOpenTag(string $source): string
+    {
+        return str_starts_with(ltrim($source), '<?php') ? $source : '<?php '.$source;
+    }
+}
diff --git a/tests/Support/OAuthTestHelpers.php b/tests/Support/OAuthTestHelpers.php
index c3222d8..b5eb065 100644
--- a/tests/Support/OAuthTestHelpers.php
+++ b/tests/Support/OAuthTestHelpers.php
@@ -4,9 +4,12 @@
 
 namespace Tests\Support;
 
+use App\Models\OauthSession;
 use App\Models\Organization;
 use App\Models\User;
 use Illuminate\Foundation\Testing\TestCase;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Str;
 use Illuminate\Testing\TestResponse;
 use Laravel\Passport\Client;
@@ -139,11 +142,12 @@ public static function exchangeTokenForm(TestCase $test, array $params): TestRes
     }
 
     /**
-     * authorize → consent → token exchange を 1 helper にまとめる。
+     * authorize → consent → token exchange を 1 helper にまとめる (Pest beforeEach 規約版)。
      *
-     * HTTP boundary を超えて access_token / refresh_token を取得する。
      * caller 側 TestCase の `user` / `org` / `client` / `pkce` / `redirectUri`
-     * プロパティを参照する (Pest beforeEach パターン)。
+     * プロパティを読み {@see self::exchangeForTokensUsing()} へ委譲する薄い adapter。
+     * beforeEach で文脈を組み立てない呼び出し側 (helper から helper を呼ぶ場合など) は
+     * 明示引数版を直接使うこと。
      *
      * `$scope` で要求 scope を差し替えられる (既定は MCP の 'mcp:use'。
      * CLI dual guard テストは 'cli:use read ...' を渡す)。
@@ -166,6 +170,36 @@ public static function exchangeForTokens(
         /** @var string $redirectUri */
         $redirectUri = $test->redirectUri;
 
+        return self::exchangeForTokensUsing(
+            test: $test,
+            user: $user,
+            organization: $org,
+            client: $client,
+            pkce: $pkce,
+            redirectUri: $redirectUri,
+            state: $state,
+            scope: $scope,
+        );
+    }
+
+    /**
+     * authorize → consent → token exchange の本体 (明示引数版)。
+     *
+     * HTTP boundary を超えて access_token / refresh_token を取得する。
+     *
+     * @param  array{code_verifier: string, code_challenge: string}  $pkce
+     * @return array{access_token: string, refresh_token: string}
+     */
+    public static function exchangeForTokensUsing(
+        TestCase $test,
+        User $user,
+        Organization $organization,
+        Client $client,
+        array $pkce,
+        string $redirectUri,
+        string $state = 'helper-state',
+        string $scope = 'mcp:use',
+    ): array {
         $test->actingAs($user);
 
         $test->get(self::buildAuthorizeUrl(
@@ -178,7 +212,7 @@ public static function exchangeForTokens(
 
         $approve = $test->post('/oauth/authorize', [
             'auth_token' => session('authToken'),
-            'organization_id' => $org->id,
+            'organization_id' => $organization->id,
         ]);
         $callback = self::parseCallbackParams($approve);
         $code = $callback['code'] ?? '';
@@ -199,4 +233,58 @@ public static function exchangeForTokens(
             'refresh_token' => (string) ($decoded['refresh_token'] ?? ''),
         ];
     }
+
+    /**
+     * OAuth flow で token を取得し、CLI セッション行を作って access token に束縛する。
+     *
+     * REST API v1 の actor 解決 (resolve.api-actor) は
+     * 「cli:use scope + 有効な OauthSession に束縛された access token」を前提にする。
+     * CLI client の consent フローでの session 自動発行は WP25 のため、
+     * ここでは前提条件を DB 直接で満たす。
+     *
+     * global 関数ではなく静的メソッドに置く理由: global 関数は再宣言できず、
+     * 複数のテストファイルから同じ手順を使えない (Pest のファイル読み込み順に依存した
+     * 「たまたま使える」状態に頼らない)。
+     *
+     * @return array{access_token: string, refresh_token: string, session: OauthSession}
+     */
+    public static function issueCliSessionTokens(
+        TestCase $test,
+        User $user,
+        Organization $organization,
+        Client $client,
+        string $scope = 'cli:use read write session.revoke',
+        string $redirectUri = 'https://test.example/callback',
+    ): array {
+        $tokens = self::exchangeForTokensUsing(
+            test: $test,
+            user: $user,
+            organization: $organization,
+            client: $client,
+            pkce: self::generatePkcePair(),
+            redirectUri: $redirectUri,
+            scope: $scope,
+        );
+        expect($tokens['access_token'])->not->toBe('');
+
+        $tokenId = DB::table('oauth_access_tokens')
+            ->where('user_id', $user->id)
+            ->orderByDesc('created_at')
+            ->value('id');
+        expect($tokenId)->not->toBeNull();
+
+        /** @var OauthSession $session */
+        $session = OauthSession::factory()->cli()->create([
+            'user_id' => $user->id,
+            'organization_id' => $organization->id,
+            'client_id' => (string) $client->id,
+        ]);
+
+        DB::table('oauth_access_tokens')->where('id', $tokenId)->update(['session_id' => $session->id]);
+
+        // guard キャッシュを落として次リクエストで Bearer token を再解決させる
+        Auth::forgetGuards();
+
+        return [...$tokens, 'session' => $session];
+    }
 }
diff --git a/tests/Unit/Architecture/AuthorizationMarkerScannerTest.php b/tests/Unit/Architecture/AuthorizationMarkerScannerTest.php
new file mode 100644
index 0000000..fa202be
--- /dev/null
+++ b/tests/Unit/Architecture/AuthorizationMarkerScannerTest.php
@@ -0,0 +1,196 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\AuthorizationMarkerScanner;
+
+/*
+ * 認可マーカー解析器そのものの positive/negative 固定。
+ *
+ * ControllerAuthorizationGateTest (変更系 route の deny-by-default 認可 gate) の
+ * 検出ロジックは **gate 自体がセキュリティ機構**であり、
+ * 「一時的にコメントアウトして落ちるか確認する」手動検証では後の改修に対する回帰が効かない。
+ * route inventory に依存しない純粋 helper として切り出し、直接テストする。
+ *
+ * ★ケース「チェーンが切れている 2 文」と「T_USE の 3 用途」が本テストの存在理由。
+ *   前者は正規表現実装が誤合格していた形、後者は名前空間 import と
+ *   lexical use / trait use の混同を、それぞれ恒久的に固定する。
+ *
+ * DB 非依存の Unit テスト (route 登録も RefreshDatabase も不要)。
+ */
+
+test('Gate::authorize は認可マーカーとして検出される', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::authorize('update', \$item);"
+    ))->toBeTrue();
+});
+
+test('Gate::forUser(...)->authorize は認可マーカーとして検出される', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::forUser(\$user)->authorize('update', \$item);"
+    ))->toBeTrue();
+});
+
+test('複数行のメソッドチェーンでも検出される', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::forUser(\$user)\n    ->authorize('update', \$item);"
+    ))->toBeTrue();
+});
+
+test('引数に配列・クロージャ・ネスト括弧があっても対応括弧を正しく追える', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::forUser(\$a->b(c(\$d)))->authorize('create', [Item::class, \$project]);"
+    ))->toBeTrue();
+
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::forUser(array_map(static fn (\$x) => \$x, [1, 2])[0])->authorize('x', \$y);"
+    ))->toBeTrue();
+});
+
+test('チェーンが切れた 2 文は認可マーカーにならない (正規表現の誤合格を封じる)', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::forUser(\$user); \$other->authorize('x');"
+    ))->toBeFalse();
+});
+
+test('行コメント内の Gate::authorize は検出されない', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "// 認可は controller の Gate::authorize が行う\n\$item->save();"
+    ))->toBeFalse();
+});
+
+test('docblock 内の Gate::authorize は検出されない', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "/** Gate::authorize を通す */\n\$item->save();"
+    ))->toBeFalse();
+});
+
+test('文字列リテラル内の Gate::authorize は検出されない', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "\$msg = 'Gate::authorize';"
+    ))->toBeFalse();
+});
+
+test('可変長文字列内の Gate::authorize は検出されない', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        '$msg = "prefix {$x} Gate::authorize";'
+    ))->toBeFalse();
+});
+
+test('Gate::allows は認可マーカーにならない (例外を投げないため)', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::allows('update', \$item);"
+    ))->toBeFalse();
+});
+
+test('Gate::forUser(...)->allows は認可マーカーにならない', function (): void {
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
+        "Gate::forUser(\$user)->allows('update', \$item);"
+    ))->toBeFalse();
+});
+
+test('use Illuminate\Support\Facades\Gate; は名前空間 import として検出される', function (): void {
+    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
+        <?php
+
+        namespace App\Http\Controllers;
+
+        use Illuminate\Support\Facades\Gate;
+
+        class Foo {}
+        PHP))->toBeTrue();
+});
+
+test('同名の別クラス (App\Support\Gate) の import は受理しない', function (): void {
+    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
+        <?php
+
+        namespace App\Http\Controllers;
+
+        use App\Support\Gate;
+
+        class Foo {}
+        PHP))->toBeFalse();
+});
+
+test('クロージャの lexical use は名前空間 import と混同されない', function (): void {
+    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
+        <?php
+
+        namespace App\Http\Controllers;
+
+        $fn = function ($x) use ($gate) {
+            return $gate;
+        };
+        PHP))->toBeFalse();
+});
+
+test('trait use は名前空間 import と混同されない', function (): void {
+    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
+        <?php
+
+        namespace App\Http\Controllers;
+
+        class Foo
+        {
+            use Illuminate\Support\Facades\Gate;
+        }
+        PHP))->toBeFalse();
+});
+
+test('import 無しで Gate::authorize を書いたファイルは import 検査に落ちる', function (): void {
+    $source = <<<'PHP'
+        <?php
+
+        namespace App\Http\Controllers;
+
+        class Foo
+        {
+            public function bar(): void
+            {
+                Gate::authorize('update', $item);
+            }
+        }
+        PHP;
+
+    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeTrue();
+    expect(AuthorizationMarkerScanner::importsGateFacade($source))->toBeFalse();
+});
+
+test('alias 付き import / group use は受理しない (Gate:: が Facade を指す保証が無い)', function (): void {
+    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
+        <?php
+
+        use Illuminate\Support\Facades\Gate as LaravelGate;
+        PHP))->toBeFalse();
+
+    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
+        <?php
+
+        use Illuminate\Support\Facades\{Auth, Gate};
+        PHP))->toBeFalse();
+});
+
+test('inline URL 整合 guard の位置は認可マーカーより前であることを比較できる', function (): void {
+    $guards = ['resolveOrganizationProject', 'resolveProjectItem'];
+
+    $correct = <<<'PHP'
+        $this->resolveOrganizationProject($organization, $project);
+        Gate::forUser($actor->user)->authorize('create', [Item::class, $project]);
+        PHP;
+
+    $inverted = <<<'PHP'
+        Gate::forUser($actor->user)->authorize('create', [Item::class, $project]);
+        $this->resolveOrganizationProject($organization, $project);
+        PHP;
+
+    $guardOffset = AuthorizationMarkerScanner::guardMarkerOffset($correct, $guards);
+    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($correct);
+    expect($guardOffset)->not->toBeNull()
+        ->and($authOffset)->not->toBeNull()
+        ->and($guardOffset)->toBeLessThan($authOffset);
+
+    $guardOffset = AuthorizationMarkerScanner::guardMarkerOffset($inverted, $guards);
+    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($inverted);
+    expect($guardOffset)->toBeGreaterThan($authOffset);
+});

```

---

## テスト結果 (worktree でローカル実走)

- `composer test` (Pest 全件, --parallel, PostgreSQL 18.4): **2746 tests / 2744 passed / 0 failed / 2 skipped** (exit 0)
  - main の直近実測は 2704 passed / 0 failed / 2 skipped → 本実装で +40 テスト
- `composer phpstan` (level 10): **No errors** (749 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (110 files / 1006 tests) / `pnpm build`: 全て green

### テストファーストで確認した「実装前の fail」(実測)

1. `ControllerAuthorizationGateTest` を先に書いた時点で
   `api.v1.projects.items.store/update/destroy` の 3 本だけが「未分類」で fail
   (= gate が実害を検出できることの証明)。他 5 本のテストは green
2. `ItemAuthorizationTest` を先に書いた時点で **15 tests 中 9 fail**。内訳は 2 種類:
   - 認可漏れ 5 件: viewer が 201/200 を得る (store/update/destroy/OAuth store/idempotency)
   - 存在オラクル 4 件: cross-org + 不正 payload が **422** (404 であるべき)
3. 施策 3 (middleware + scopeBindings) 実装後 → 存在オラクル 4 件が green、認可漏れ 5 件は fail のまま
4. 施策 4 (`Gate::forUser`) 実装後 → 全 green

### drift ガードが空振りしないことの実測 (いずれも一時改変して fail を確認し、元に戻した)

| 改変 | 検出したテスト | 結果 |
|---|---|---|
| `MUTATING_ROUTE_FLOOR` 相当を 40 → 200 | 候補下限テスト | fail (実測候補数 **61** と報告。設計の実測値と一致) |
| `api.project-in-org` を `resolve.api-actor` より前へ | middleware 順序契約テスト | fail (3 route) |
| `api.project-in-org` を `idempotent` より後へ | middleware 順序契約テスト | fail (3 route) |
| read group から `api.project-in-org` を削除 | 存在テスト + 順序テスト | fail (2 route) |
| `ItemController::destroy` で `Gate` を inline guard より前へ | 「URL 整合 guard は認可より前」テスト | fail (1 route) |

`AuthorizationMarkerScanner` 自体の positive/negative 18 ケースは
`tests/Unit/Architecture/AuthorizationMarkerScannerTest.php` で恒久固定 (全 pass)。

## 設計から意図的に逸脱した点

1. **`AuthorizationMarkerScanner` に 2 メソッド追加**: 設計は
   `hasAuthorizationMarker` / `importsGateFacade` の 2 つだったが、
   施策 2 (f)「guard が認可より前」の順序検証にトークン位置が必要なため
   `authorizationMarkerOffset` / `guardMarkerOffset` を追加した
   (`hasAuthorizationMarker` は `authorizationMarkerOffset() !== null` に委譲)。
   字句解析をテスト本体に漏らさないための追加。
2. **import 検査で alias 付き import / group use を受理しない**:
   設計は素の `use Illuminate\Support\Facades\Gate;` のみを想定していた。
   `use ...\Gate as G;` や `use ...\Facades\{Auth, Gate};` は
   `Gate::` が当該 Facade を指す保証が無いため **不合格 (deny-by-default)** にした。
   現行コードベースに該当例は無い。
3. **`exchangeForTokens` を分割**: 施策 6 で `issueCliSessionTokens` を
   Support クラスへ昇格させる際、既存 `exchangeForTokens($test)` は
   TestCase の magic property (`$test->user` 等) を読む Pest beforeEach 規約に依存しており、
   新 helper からは使えなかった。明示引数版 `exchangeForTokensUsing(...)` を本体とし、
   `exchangeForTokens` はそこへ委譲する薄い adapter にした
   (実装は 1 本のまま。既存 14 箇所の呼び出し側は無変更)。
4. **enum の docblock から `{@see}` を削除**: Pint の `fully_qualified_strict_types` が
   `{@see \Tests\Architecture\ControllerAuthorizationGateTest}` を
   `use Tests\Architecture\...;` に変換し、**app/ が tests/ を import する**状態になったため、
   プレーンなファイルパス表記に変えた。
5. **`ControllerAuthorizationGateTest` の定数を関数化**: 設計は file-level `const` だったが、
   Pest のテストファイルは同一グローバル空間に読み込まれ定数名が衝突しうるため、
   既存の Architecture テスト (`nestedRouteIdorInventory()` 等) と同じ **global 関数**にした。

## 質問

1. gate の検出ロジックに **誤合格 (認可が無いのに合格する)** バイパスは残っていないか
2. 存在オラクルは本当に閉じたか。他に 422/404 差分が漏れる経路は無いか
   (例: `{item}` の scopeBindings 化で新たな差分が生まれていないか)
3. `EnsureProjectBelongsToApiOrganization` を read group にも付けたことで
   既存の GET 挙動が変わっていないか

