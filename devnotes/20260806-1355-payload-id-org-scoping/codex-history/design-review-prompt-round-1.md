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



【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可 (リポジトリルートは /workspace)。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（存在オラクルの閉じ方、認可順序、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠 / Atomic Design 準拠（今回はコメント変更のみのはず。過剰変更が混ざっていないか）

【この設計固有の確認事項】
- 「実在する非メンバー id」と「不在 id」の応答が本当に一致するか (Laravel の ValidationException → redirect back の挙動を踏まえて)
- 層 2 (404) → 層 3 (403) → payload 検証 の順序が保たれているか
- テストが実装前に fail し、実装後に緑になる構成か
- 見落としている波及 (他の呼び出し元・他のテスト) が無いか。必要ならリポジトリのファイルを読んで確認してよい

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: payload 由来 id の org 相対化 (直 fetch 債務 3 件の解消) — aicue:T118

概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex 合議 Round 3 で APPROVED)

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> 本施策は機能追加ではなく、**組織の SOP を預かる前提 (テナント境界) を守るための債務返済**である。

### 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。型を緩めて黙らせない
- **Pest** + `RefreshDatabase` グローバル適用 (個別 `DatabaseTransactions` 禁止)、`--parallel`
- テストデータは Factory / `tests/Pest.php` のヘルパ経由
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く、transaction は Service 内
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | オーナー移譲の payload user_id を org 相対化 | `app/Http/Controllers/Organizations/OrganizationOwnershipController.php`, `app/Services/Organization/OrganizationMembershipService.php` (定数化のみ) | 高 |
| B | プロジェクトメンバー追加の payload user_id を org 相対化 (403 → 422) | `app/Http/Controllers/Projects/ProjectMemberController.php` | 高 |
| C | MCP consent の organization_id 直 fetch 撤去 (422/403 → 403 統一) | `app/Http/Middleware/McpConsentOrganizationBinder.php` | 高 |
| D | gate の債務エントリ削除と cap 0 化 | `tests/Support/Security/DirectFetchInventory.php`, `tests/Architecture/ModelDirectFetchInvariantTest.php` | 高 |
| E | 存在オラクル不成立の Feature テスト新設 + 既存テスト更新 | `tests/Feature/Security/PayloadIdExistenceOracleTest.php` (新規), `tests/Feature/Projects/ProjectMemberTest.php`, `tests/Feature/Organization/OwnershipTransferTest.php`, `tests/Feature/Mcp/ConsentOrganizationBinderTest.php` | 高 |
| F | コメント同期 (陳腐化の除去) | `resources/js/pages/Organizations/Settings.svelte`, 上記 controller の docblock | 中 |

**実装順序**: E (失敗するテストを先に書く = テストファースト) → A → B → C → D → F。

---

## 施策 A: オーナー移譲の payload user_id を org 相対化

### 変更箇所

- `app/Http/Controllers/Organizations/OrganizationOwnershipController.php` (L21-40 全体)
- `app/Services/Organization/OrganizationMembershipService.php` (L498-501 の文言を定数化)

### 波及変更

- TypeScript 型定義: **なし** (props / route / payload 形状は不変)
- API Resource/DTO: **なし** (Inertia の `back()->with(...)` 経路のみ)
- テストファイル: `tests/Feature/Organization/OwnershipTransferTest.php` (文言一致の表明を追加)、
  `tests/Feature/Security/PayloadIdExistenceOracleTest.php` (新規)
- Svelte: `resources/js/pages/Organizations/Settings.svelte` のコメントのみ (施策 F)

### 現行コード

```php
$request->validate([
    'user_id' => ['required', 'integer', 'exists:users,id'],
]);
$userId = $request->input('user_id');
Assert::integerish($userId);

/** @var User $to */
$to = User::query()->findOrFail((int) $userId);   // ★ 組織スコープ外の直 fetch

$membership->transferOwnership($organization, $from, $to);
```

### 変更後コード

```php
public function store(Request $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
{
    // 層 3 (認可) を payload に触れる前に通す。ここが後段へ移ると、
    // 権限の無い actor が user_id の応答差を観測できるようになる (PayloadIdExistenceOracleTest が固定)
    Gate::authorize('transferOwnership', $organization);

    $from = $request->user();
    Assert::isInstanceOf($from, User::class);

    // 形式検証のみ。`exists:users,id` は「その id が全体で実在するか」を
    // 組織の非メンバーにも 422 の文言差で答えてしまうため使わない (aicue:T118)
    $request->validate([
        'user_id' => ['required', 'integer'],
    ]);
    $userId = $request->input('user_id');
    Assert::integerish($userId);

    // 移譲先は組織 relation から解決する。不在 id も実在の非メンバー id も
    // ここで同一の field error になる (存在オラクル不成立)
    $to = $organization->users()->whereKey((int) $userId)->first();
    if (! $to instanceof User) {
        throw ValidationException::withMessages([
            'user_id' => [OrganizationMembershipService::MEMBER_REQUIRED_MESSAGE],
        ]);
    }

    $membership->transferOwnership($organization, $from, $to);

    return back()->with('success', 'オーナーを移譲しました');
}
```

import の増減:

- 追加: `use Illuminate\Validation\ValidationException;`
- 維持: `use App\Models\User;` (`Assert::isInstanceOf` / `instanceof` で使用)

### Service 側 (文言の単一ソース化)

```php
class OrganizationMembershipService   // ← 既存宣言 (L37)。final 化などの変更はしない
{
    private const EXPIRES_DAYS = 7;   // ← 既存


    /**
     * 移譲先が組織メンバーでないときの文言。Controller の org 相対解決と
     * ロック下の再検証が**同一文言**であることが存在オラクル不成立の条件なので、
     * 文字列リテラルを 2 箇所に置かない (aicue:T118)。
     */
    public const MEMBER_REQUIRED_MESSAGE = '移譲先は組織のメンバーである必要があります。';
```

`transferOwnership()` 内の該当箇所 (現 L498-501):

```php
if (count($memberUserIds) < 2) {
    throw ValidationException::withMessages([
        'user_id' => [self::MEMBER_REQUIRED_MESSAGE],
    ]);
}
```

> Service のロック下再検証は**残す**。Controller の解決と Service のロック取得の間に
> メンバーが外れる競合を閉じるのは Service の責務であり、存在確認の重複ではない。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`RedirectResponse`)
- [x] null 安全: `$organization->users()` は `BelongsToMany<User, $this>` (Organization L103-108) なので
      `first()` は `User|null`。`! $to instanceof User` で narrowing され、`$membership->transferOwnership()`
      の `User` 引数に mixed が流れない
- [x] `$request->input()` の mixed は `Assert::integerish()` + `(int)` cast で確定 (既存 controller と同形)
- [x] DTO 返却の対象外 (Inertia の redirect 応答)

### テスト計画

- [x] 既存 `tests/Feature/Organization/OwnershipTransferTest.php`
  - 「移譲で両者のロールが入れ替わる」— 変更なしで緑 (正常系不変)
  - 「非メンバーへは移譲できない」— `assertSessionHasErrors(['user_id' => OrganizationMembershipService::MEMBER_REQUIRED_MESSAGE])` へ強化
  - 「自分自身へは移譲できない」— 変更なしで緑 (owner は member なので解決でき、Service が弾く)
  - 「非 Owner は移譲できない (403)」— 変更なしで緑
- [x] 新規 `PayloadIdExistenceOracleTest`: 不在 id と実在非メンバー id の応答一致 (施策 E)
- [x] 個別 `DatabaseTransactions` は使わない

### リスク

- 文言定数を Service に置くことで Controller → Service の参照が 1 本増える。
  既に `OrganizationMembershipService` を DI している経路なので依存の向きは変わらない。
- `exists:users,id` を落とすため、「id が数値だが存在しない」ケースの応答文言が変わる
  (rule 既定文言 → メンバー要求文言)。**これは意図した変更**であり、UI は同じ field error に描画する。

---

## 施策 B: プロジェクトメンバー追加の payload user_id を org 相対化

### 変更箇所

- `app/Http/Controllers/Projects/ProjectMemberController.php` (クラス docblock + `store()` L32-64)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Projects/ProjectMemberTest.php` (403 → 422 の期待値更新 + 冒頭コメント + 新規 1 件)

### 現行コード

```php
$request->validate([
    'user_id' => ['required', 'integer', 'exists:users,id'],
    'role' => ['required', 'string', Rule::enum(ProjectRole::class)],
]);
...
/** @var User $target */
$target = User::query()->findOrFail((int) $userId);     // ★ 組織スコープ外の直 fetch

if ($target->organizationRole($organization) === null) {
    throw new AuthorizationException('Target user is not a member of this organization.');  // 403
}
```

### 変更後コード

```php
class ProjectMemberController extends Controller
{
    use ResolvesCurrentOrganization;

    /**
     * 追加対象が現在組織のメンバーとして解決できないときの文言。
     * 「不在 id」「他組織のユーザー」「pivot 在籍だがロール未付与の異常行」を
     * **同一文言**へ落とすことで users.id の存在オラクルを閉じる (aicue:T118)。
     */
    private const NOT_ORGANIZATION_MEMBER_MESSAGE = '追加できるのは組織のメンバーだけです。';

    public function store(Request $request, Project $project): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // 層 2: URL 整合 guard (認可より前に 404)
        $this->resolveOrganizationProject($organization, $project);
        // 層 3: 認可。payload に触れる前に通す (順序は PayloadIdExistenceOracleTest が固定)
        Gate::authorize('update', $project);

        // 形式検証のみ (`exists:users,id` は全体実在を漏らすため使わない)
        $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', 'string', Rule::enum(ProjectRole::class)],
        ]);
        $userId = $request->input('user_id');
        Assert::integerish($userId);
        $role = $request->input('role');
        Assert::string($role);

        // 追加対象は現在組織の relation から解決する。
        // pivot 在籍 (organization_user) と Laratrust ロール付与は同値ではない
        // (OrganizationMembershipService の「ロール未付与の異常行」修復契約) ため、
        // ロール判定も残す。両者の失敗は同一 field error に落とす。
        $target = $organization->users()->whereKey((int) $userId)->first();
        if (! $target instanceof User || $target->organizationRole($organization) === null) {
            throw ValidationException::withMessages([
                'user_id' => [self::NOT_ORGANIZATION_MEMBER_MESSAGE],
            ]);
        }

        // pivot ロールは明示代入 (既存メンバーはロール更新)
        $project->members()->syncWithoutDetaching([
            $target->id => ['role' => ProjectRole::from($role)->value],
        ]);

        return back()->with('success', 'プロジェクトメンバーを追加しました');
    }
```

クラス docblock の該当行を差し替える:

```php
/**
 * プロジェクトメンバー管理 (project_members pivot の追加・削除)。
 *
 * - 追加対象は payload の user_id で受ける。URL 上の子リソース指定ではないので
 *   404 秘匿ではなく **field error (422)** に倒す。不在 id・他組織ユーザーを
 *   同一文言に揃えることで存在オラクルを閉じている (aicue:T118)
 * - 削除対象は URL の {user}。org member でなければ**認可より前に 404**
 *   (cross-tenant の存在を漏らさない)
 * - ロール変更は削除→追加でなく store の再実行 (syncWithoutDetaching + pivot 更新) で行う
 */
```

import の増減:

- 追加: `use Illuminate\Validation\ValidationException;`
- **削除**: `use Illuminate\Auth\Access\AuthorizationException;` (未使用になる)
- 維持: `use App\Models\User;` (`instanceof` で使用)

### PHPStan 適合チェック

- [x] `first()` の `User|null` を `instanceof` で narrowing (`$target->organizationRole()` / `$target->id` が安全)
- [x] `$userId` / `$role` は `Assert::integerish()` / `Assert::string()` で確定 (既存と同形)
- [x] 未使用 import を残さない (`AuthorizationException` を削除する)

### テスト計画

- [x] 更新 `tests/Feature/Projects/ProjectMemberTest.php`
  - 冒頭コメント: 「追加対象 (payload user_id) の cross-org は 403」→ 「…は 422 (field error)」
  - 「他組織のユーザーは追加できない (cross-org は 403)」→ 名称と期待値を
    `assertSessionHasErrors(['user_id' => ...])` へ変更 (削除ではなく期待値更新)
  - 「管理権限のない project member はメンバーを追加できない (403)」— 変更なしで緑
  - **新規**: 「pivot 在籍だがロール未付与のユーザーは追加できない」
    (`$organization->users()->attach($user)` のみ行い `addRole` しない状態で 422 になること。
    現行の `organizationRole()` 判定が失われていないことの固定)
- [x] 新規 `PayloadIdExistenceOracleTest` (施策 E)

### リスク

- **403 → 422 の挙動変更**。UI は `memberForm.errors.user_id` を描画するため後退しない
  (`resources/js/pages/Projects/Show.svelte` L578)。外部 API 経路は無い (web route のみ)。
- ロール変更 (`changeMemberRole`) も同じ endpoint を使うが、送る id は表示中のメンバーなので
  正常系は不変。失敗時は `onError` で props を再取得する既存挙動のまま。

---

## 施策 C: MCP consent の organization_id 直 fetch 撤去

### 変更箇所

- `app/Http/Middleware/McpConsentOrganizationBinder.php` (L41-70)

### 波及変更

- TypeScript 型定義: **なし** (consent 画面は Blade)
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Mcp/ConsentOrganizationBinderTest.php`

### 現行コード

```php
$organization = Organization::query()->find($orgId);       // ★ 結果は id にしか使われていない
if ($organization === null) {
    throw new HttpException(422, 'Unknown organization.');  // 不在 → 422
}
if (! $user->organizations()->whereKey($organization->id)->exists()) {
    throw new HttpException(403, 'You are not a member of the selected organization.');
}
$request->attributes->set('mcp_selected_organization_id', $orgId);
```

### 変更後コード

```php
// `is_numeric('1.5')` 由来の truncation 事故防止。
// filter_var(FILTER_VALIDATE_INT) は `1.5` / `1e5` / `abc` / 先頭ゼロ (`001`) を reject し、
// `min_range => 1` で 0 / 負数も拒否する。前後空白と符号 (`' 1'` / `'1 '` / `'+1'`) は
// 許容され int へ正規化される (PHP 8.4 実測。ConsentOrganizationBinderTest が固定)。
$orgId = filter_var($raw, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($orgId === false) {
    throw new HttpException(422, 'Invalid organization_id.');
}

$user = $request->user();
if (! $user instanceof User) {
    return redirect()->guest(route('login'));
}

// membership は organization_user pivot が単一ソース。**組織を fetch してから**
// 判定すると「不在 = 422 / 実在の非 member = 403」で組織の実在が 1 bit 漏れるため、
// 整数として受理した id は 1 つ残らずここへ流し、同一の 403 に落とす (aicue:T118)。
if (! $user->organizations()->whereKey($orgId)->exists()) {
    // consent 画面から非 member 組織を選べない UI ガードを迂回した場合の最終防御。
    throw new HttpException(403, 'You are not a member of the selected organization.');
}

$request->attributes->set('mcp_selected_organization_id', $orgId);
```

import の増減:

- **削除**: `use App\Models\Organization;` (未使用になる)

### PHPStan 適合チェック

- [x] `filter_var` の戻りは `int|false`。`=== false` で早期 return するため以降 `int` に確定
- [x] `$user->organizations()` は `BelongsToMany<Organization, $this>` で `exists()` は `bool`
- [x] 未使用 import を残さない

### テスト計画

- [x] 更新 `tests/Feature/Mcp/ConsentOrganizationBinderTest.php`
  - 「非 member 組織の id を body に仕込むと 403」— 変更なしで緑
  - 「存在しない organization_id は 422」→ **「存在しない organization_id も 403 (実在を漏らさない)」**へ
    期待値変更 (status + message の両方を検証)
  - **新規**: 「非 member 組織と不在 id の (status, message) が完全一致する」
  - **新規**: 「形式不正の分類契約」— `'0'` / `'-1'` / `'1.5'` / `'1e5'` / `'001'` / `[]` / `true` は 422
  - **新規**: 「前後空白付きの member 組織 id は受理され attribute に int が入る」
    (`' '.$id` と `$id.' '` の 2 ケース。`filter_var` の実挙動の固定)
- [x] `Organization::query()->find()` が消えることを `ModelDirectFetchInvariantTest` が確認 (施策 D)

### リスク

- 不在 id の応答が 422 → 403 に変わる。この経路に到達するのは body 改ざん時のみで、
  正規の consent 画面 (Blade dropdown) は自分の組織 id しか送らない。
- MCP クライアント (CLI) は `/oauth/authorize` の HTML consent を経由するため影響なし。

---

## 施策 D: gate の債務エントリ削除と cap 0 化

### 変更箇所

- `tests/Support/Security/DirectFetchInventory.php` (L316-337 の「★債務」節を丸ごと削除)
- `tests/Architecture/ModelDirectFetchInvariantTest.php` (L48-57 の `modelDirectFetchDebtCap()`)

### 変更後コード

```php
/**
 * 債務 case の件数上限。
 *
 * **実測 0 件**。aicue:T118 で payload 由来 id 3 件 (org 移譲 / project メンバー追加 /
 * MCP consent) を relation 起点へ寄せ、`exists:` rule とセットで存在オラクルを閉じたため。
 * 0 のまま維持する — 1 件足すには inventory 登録と本 cap の引き上げの
 * **2 つ**が要り、必ずレビューの俎上に乗る。
 * 分類 case (`PayloadIdWithGlobalExistenceRuleDebt`) と
 * `DirectFetchJustificationEntry::globalExistenceRuleDebt()` は
 * 「この形は債務である」という裁定語彙として**残す** (消すと再発時の分類が失われる)。
 */
function modelDirectFetchDebtCap(): int
{
    return 0;
}
```

### 影響の確認 (実査済み)

| テスト | 影響 |
|---|---|
| 「inventory の key は全て現存する候補である (stale 検出)」 | 3 エントリを**削除しないと fail**。削除すれば緑 |
| 「クラス起点の主キー同一性クエリが全て inventory に明示分類されている」 | 候補が 3 件消えるので緑のまま |
| 「債務 case は補償チェックの実在を伴う」 | 対象 0 件 → 空ループで緑 |
| 「債務 case が増殖していない」 | `0 <= 0` で緑 |
| 「走査器が十分な数の候補を検出している (floor 20)」 | 候補 **34 → 31** で floor を下回らない |
| 「同一 fingerprint の候補が重複していない」 | `reviewedDuplicateFingerprints()` は現状 `[]` で、削除で stale は発生しない |
| `modelDirectFetchMembershipMarkers()` | 債務テスト専用だが関数は残す (case を残す方針と対) |

> 変更後の候補数は実行して確認する
> (`php artisan test tests/Architecture/ModelDirectFetchInvariantTest.php` が floor 違反を検出する)。

### テスト計画

- [x] `php artisan test tests/Architecture/ModelDirectFetchInvariantTest.php` が全件緑
- [x] 3 エントリの削除漏れ / cap 未変更のいずれでも赤くなることを確認 (どちらか片方では緑にできない設計)

### リスク

- なし (テスト専用資産の変更)。ただし **A/B/C の実装より後に行う**こと
  (先に消すと stale ではなく unknown 側で赤くなり、原因が読みにくい)。

---

## 施策 E: 存在オラクル不成立のテスト新設 + 既存テスト更新

### 新規ファイル: `tests/Feature/Security/PayloadIdExistenceOracleTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Testing\TestResponse;
use Tests\Support\ResponseSignature;

/*
 * payload 由来 id (POST body の user_id) の存在オラクル不成立 (aicue:T118)。
 *
 * route parameter 版 (`MemberRouteExistenceOracleTest`) と同じ主張を payload 経路で行う:
 *   「実在するが非メンバーの id」と「不在の id」の応答が観測上まったく同じであること。
 * URL 子リソースではないので 404 ではなく **422 相当の field error** に統一している
 * (統一先が何かではなく、**分岐しないこと**が不変条件)。
 *
 * 併せて「層 3 (認可) は payload 検証より前」を固定する。ここが入れ替わると
 * 権限の無い actor が user_id の差を観測できるようになる。
 */

/** 不在の user id (18 桁 pattern 内・実在しない)。 */
const PIEO_MISSING_USER_ID = 999999999;

/** 応答 signature と field error の文言を 1 つにまとめた観測値。 */
function pieoObserve(TestResponse $response): array
{
    $errors = session('errors');

    return [
        'signature' => ResponseSignature::of($response),
        'user_id_errors' => $errors === null ? [] : $errors->getBag('default')->get('user_id'),
    ];
}

/**
 * 与えた id で 2 回叩き、観測値が完全一致することを表明する。
 *
 * @param  callable(int): TestResponse  $request
 */
function pieoAssertNoOracle(callable $request, int $existingNonMemberId): void
{
    $existing = pieoObserve($request($existingNonMemberId));
    $missing = pieoObserve($request(PIEO_MISSING_USER_ID));

    expect($existing)->toBe($missing, '実在の非メンバー id と 不在 id の応答が一致しない (存在オラクル)');
}
```

テストケース:

| # | テスト名 | 内容 |
|---|---|---|
| 1 | `transfer-ownership の非メンバーと不在 id は同一応答` | owner + recent-auth。`from()` を固定して Location を揃える。観測値一致 + 文言が `MEMBER_REQUIRED_MESSAGE` |
| 2 | `projects.members.store の非メンバーと不在 id は同一応答` | owner。role は正しい値。観測値一致 |
| 3 | `transfer-ownership は権限が無ければ user_id によらず同一 403` | 非 Owner(Admin) + recent-auth で「実在メンバー / 実在非メンバー / 不在」の 3 パターンを送り、3 つの `ResponseSignature` が一致し status 403 |
| 4 | `projects.members.store は権限が無ければ user_id によらず同一 403` | project 更新権限の無い member (要 `current_organization_id` 設定) で同上 |

補足:

- ケース 1 は `withSession(['recent_auth_at' => time()])` と
  `->from("/organizations/{$organization->slug}/settings")` を付ける
  (recent-auth の 302 で短絡させない / Location を揃える)。
- ケース 3/4 は field error が出ないため `pieoObserve` の `user_id_errors` は空配列で一致する。
- **session の errors は次のリクエストで上書きされる**ため、観測は必ず「リクエスト直後」に取る
  (`pieoObserve` を呼ぶ順序に依存する。ヘルパ内で 1 リクエスト = 1 観測にしてある)。

### 既存テストの更新一覧

| ファイル | 変更内容 |
|---|---|
| `tests/Feature/Projects/ProjectMemberTest.php` | 冒頭コメントの「cross-org は 403」→ 「cross-org は 422 (field error)」。テスト「他組織のユーザーは追加できない (cross-org は 403)」→ 「…(cross-org は 422)」に改名し `assertSessionHasErrors(['user_id' => '追加できるのは組織のメンバーだけです。'])` + `expect($project->members()->count())->toBe(0)` を維持。**新規**「pivot 在籍だがロール未付与のユーザーは追加できない」を追加 |
| `tests/Feature/Organization/OwnershipTransferTest.php` | 「非メンバーへは移譲できない」に `assertSessionHasErrors(['user_id' => OrganizationMembershipService::MEMBER_REQUIRED_MESSAGE])` を追加 (存在漏れの回帰点を文言まで固定) |
| `tests/Feature/Mcp/ConsentOrganizationBinderTest.php` | 「存在しない organization_id は 422」→ 「…も 403 (実在を漏らさない)」。新規 3 件 (応答一致 / 形式不正の分類 / 前後空白の受理) |

### テスト計画チェック

- [x] バグ修正 (存在オラクル) の再現テストを**先に**書き、fail を確認してから A/B/C を実装する
- [x] 既存テストは削除せず期待値を更新する
- [x] Factory / `tests/Pest.php` ヘルパのみでデータ生成 (`Model::create()` 手組みなし)
- [x] 個別 `DatabaseTransactions` を使わない

---

## 施策 F: コメント同期

| ファイル | 現行 | 変更後 |
|---|---|---|
| `resources/js/pages/Organizations/Settings.svelte` L124-126 | 「最終ゲートはサーバ (Policy + exists:users,id + Service のメンバーシップ検証)」 | 「最終ゲートはサーバ (Policy + 組織 relation での解決 + Service のロック下再検証)」 |
| `app/Http/Controllers/Projects/ProjectMemberController.php` docblock | 「同一組織メンバーでなければ 403」 | 施策 B に記載の文言 |
| `app/Http/Middleware/McpConsentOrganizationBinder.php` L41-43 | 「`"1 "` を reject」(実挙動と逆) | 施策 C に記載の文言 (先頭ゼロを reject / 前後空白は受理) |

> Svelte 側は**コメントのみ**の変更。DS token / Atomic Design への影響なし
> (`DESIGN.md` 準拠の観点で変更点なし)。

---

## 検証コマンドと期待結果

| コマンド | 期待 |
|---|---|
| `php artisan test tests/Feature/Security/PayloadIdExistenceOracleTest.php` | 実装前は 1・2 が fail (403/422 の分岐)、実装後は全緑 |
| `php artisan test tests/Feature/Projects/ProjectMemberTest.php` | 全緑 (期待値更新後) |
| `php artisan test tests/Feature/Organization/OwnershipTransferTest.php` | 全緑 |
| `php artisan test tests/Feature/Mcp/ConsentOrganizationBinderTest.php` | 全緑 |
| `php artisan test tests/Architecture/ModelDirectFetchInvariantTest.php` | 全緑 (候補 31 件 / debt 0 件 / floor 20 を満たす) |
| `php artisan test tests/Feature/Security/MemberRouteExistenceOracleTest.php` | 全緑 (非回帰。route param 側の契約は不変) |
| AGENTS.md の `VERIFICATION_COMMANDS` 全件 | すべて green で完了 (`composer test` はグローバルロック待ちが正常。30 秒ごとの heartbeat が出ている間は kill しない) |

手動確認 (任意・UI 非後退の確認):

1. 組織設定 → オーナー移譲 → 候補を選んで実行 → 成功 flash。
2. プロジェクト詳細 → メンバー追加 / ロール変更 → 成功。
3. DevTools で `user_id` を他組織のユーザー id に書き換えて送信 → **画面遷移せず** select 下に
   「追加できるのは組織のメンバーだけです。」が出る (エラーページに飛ばない)。

---

## 段階分け

### このタスクでやる

施策 A / B / C / D / E / F (上記すべて)。**D は A/B/C の後**に行う。

### 後続 TODO 候補 (今は作らない)

| 候補 | 今やらない理由 |
|---|---|
| `exists:` rule 全般の deny-by-default gate 化 (validation rule 層の機械検出) | 現状 `exists:users,id` は本件の 2 箇所のみで、他は既に親スコープ付き (`Rule::exists('categories','id')->where('project_id', …)`)。母集団が無いところに gate を作るのはオーバーエンジニアリング (思考原則 2) |
| タイミング差による存在オラクルの遮断 | 一定時間応答などの機構が要り、脅威モデル (認証済み組織管理者) に対して費用対効果が合わない |
| payload 経路の 404 統一 (起票時方針) | 概念設計 §5-1 で不採用。UI の着地が無くなるため。再検討するなら「フォーム POST の 404 着地」設計が先 |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | テスト先行 → 実装 → gate の cap 変更、という順序依存があり、途中状態では必ずテストが赤くなる (中途半端な緑を作れない設計)。1 worktree で一気通貫に行う |
| 競合リスク | `docs/TODO.md` は触らない (TODO 担当が設計列を差し替える)。`tests/Support/Security/DirectFetchInventory.php` と `tests/Architecture/ModelDirectFetchInvariantTest.php` は T116 で確定した資産で、他タスクが同時に触る予定はない。`OrganizationMembershipService` は定数追加のみで既存メソッドの署名を変えない |

---

## 実装者への注意 (落とし穴)

1. **D を先にやらない**。A/B/C を入れる前に inventory を消すと「unknown 候補」で赤くなり、
   何が原因か読みにくい。逆に A/B/C だけ入れて D を忘れると「stale 検出」で赤くなる。
   **どちらか片方では緑にならない**のが T116 の設計意図。
2. `session('errors')` は次のリクエストで上書きされる。観測は 1 リクエストごとに取る。
3. `attachOrganizationMember()` は pivot attach + `addRole` の両方を行う。
   「ロール未付与の異常行」テストは `$organization->users()->attach($user)` を直接使う。
4. `projects.members.store` のテストで actor に `current_organization_id` を設定し忘れると
   `resolveCurrentOrganization()` が 404 を返し、意図と違う理由で緑/赤になる。
5. `docs/TODO.md` / `docs/TODO-closed.md` は実装者が触らない
   (`ModelDirectFetchInvariantTest` の todoRef 検証は債務エントリ削除により参照されなくなる)。

## 参考: 概念設計 (合意済み)

# 概念設計: payload 由来 id の org 相対化 (直 fetch 債務 3 件の解消) — aicue:T118

対応 TODO: `aicue:T118` (`docs/TODO.md`)
前提となる機械検出: `aicue:T116` (`devnotes/20260805-2311-model-direct-fetch-gate/`)
c2c feature: `nested-route-idor-defense` (aicue は t1 追従済み)

---

## 1. 仮説

**仮説**: 3 件の直 fetch 債務が漏らしているのは「cross-org のデータ」ではなく
**「その id が全体で実在するか」という 1 bit** である。したがって是正の成否は
「fetch を relation 起点に寄せたか」ではなく、
**「実在する非メンバー id」と「不在 id」の応答が観測上まったく同一になったか**で判定できる。

**成功条件 (検証可能な形)**:

1. 対象 3 経路それぞれで、「実在するが非メンバーの id」と「不在の id」の応答が
   status / ヘッダ / body まで一致する (`Tests\Support\ResponseSignature` で機械比較)。
2. 正常系 (UI が実際に送る id) の応答が変わらない。
3. `ModelDirectFetchInvariantTest` の債務 3 件が inventory から消え、
   `modelDirectFetchDebtCap()` が 0 になっても全テストが緑。

**失敗と判定する状態**: 応答は揃ったが正常系のフォーム UX が壊れた (フォーム POST が
エラー画面に落ちる等)。これは「行き先のない詰みを作らない」に反するため後退とみなす。

---

## 2. 現状 (実コードで実査した結果)

### 2-1. `OrganizationOwnershipController::store` (`app/Http/Controllers/Organizations/OrganizationOwnershipController.php`)

```php
Gate::authorize('transferOwnership', $organization);          // 層 3
$request->validate(['user_id' => ['required','integer','exists:users,id']]);
$to = User::query()->findOrFail((int) $userId);                // ★ 組織スコープ外の直 fetch
$membership->transferOwnership($organization, $from, $to);     // 補償チェックはここ (ロック下)
```

`OrganizationMembershipService::transferOwnership` は行ロック取得後に
`organization_user` を引いて 2 行揃わなければ
`ValidationException(['user_id' => ['移譲先は組織のメンバーである必要があります。']])` を投げる。

**観測される分岐**:

| 送った user_id | 応答 |
|---|---|
| 不在 id | 422 / `errors.user_id` = exists rule の既定メッセージ |
| 実在するが非メンバー | 422 / `errors.user_id` = 「移譲先は組織のメンバーである必要があります。」 |

status は同じ 422 でも **メッセージが違う** = 1 bit 漏れる。

### 2-2. `ProjectMemberController::store` (`app/Http/Controllers/Projects/ProjectMemberController.php`)

```php
$organization = $this->resolveCurrentOrganization($request);
$this->resolveOrganizationProject($organization, $project);    // 層 2 (404)
Gate::authorize('update', $project);                           // 層 3
$request->validate(['user_id' => ['required','integer','exists:users,id'], 'role' => ...]);
$target = User::query()->findOrFail((int) $userId);            // ★ 組織スコープ外の直 fetch
if ($target->organizationRole($organization) === null) {
    throw new AuthorizationException(...);                     // 403
}
```

| 送った user_id | 応答 |
|---|---|
| 不在 id | 422 (`errors.user_id`) |
| 実在するが非メンバー | **403** |

status レベルで分岐している = 最も明瞭な存在オラクル。

### 2-3. `McpConsentOrganizationBinder::handle` (`app/Http/Middleware/McpConsentOrganizationBinder.php`)

```php
$orgId = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$organization = Organization::query()->find($orgId);           // ★ 組織スコープ外の直 fetch
if ($organization === null)  throw new HttpException(422, 'Unknown organization.');
if (! $user->organizations()->whereKey($organization->id)->exists()) {
    throw new HttpException(403, 'You are not a member of the selected organization.');
}
$request->attributes->set('mcp_selected_organization_id', $orgId);
```

| 送った organization_id | 応答 |
|---|---|
| 不在 id | 422 `Unknown organization.` |
| 実在するが非メンバー組織 | 403 `You are not a member...` |

さらに **fetch 結果は `$organization->id` (= 引数の $orgId と同値) にしか使われていない**。
つまりこの fetch はオラクルを生むだけで機能上は不要である。

### 2-4. 既存の防御・機械強制の実査

- `ModelDirectFetchInvariantTest` (`tests/Architecture/`): 候補は現状 **34 件**、
  inventory は 34 エントリで完全一致 (unknown = fail / stale = fail の双方向)。
  債務 3 件は `DirectFetchInventory::inventory()` の L317-337。
  `modelDirectFetchDebtCap()` = 3、`modelDirectFetchCandidateFloor()` = 20。
- 応答一致の比較器 `Tests\Support\ResponseSignature` と、その利用例
  `tests/Feature/Security/MemberRouteExistenceOracleTest.php` の `mreoAssertNoOracle()`
  が既に存在する (route parameter 版の同型問題を T108 S3 で閉じたときの資産)。
  **正規化範囲を実査した**: 比較対象は `status` + 非 volatile ヘッダ + `body`。
  `set-cookie` / `date` / `retry-after` / `expires` / `age` / `x-ratelimit-*` /
  `x-request-id` 系は除外され、`location` / `content-type` / `content-length` は
  比較対象に残る。validation 失敗は **302 redirect (body 空)** で返るため、
  flash された old input が body に混ざることはなく、session cookie 差も除外される
  = セッションを持つ Inertia フォーム経路でも安定して比較できる。
  ただし signature だけでは**エラー文言の差**を検出できないので、本件では
  `session('errors')` の文言一致を併せて表明する (§7-1)。
- 組織スコープ付き `exists` の前例あり:
  `Rule::exists('categories', 'id')->where('project_id', $projectId)`
  (`StoreVideoManualRequest` / `UpdateVideoManualRequest` / `DuplicateVideoManualRequest`)。
- **pivot 在籍とロール付与は別物**: `OrganizationMembershipService` L351-353 に
  「attach 済みかつ Laratrust ロール未付与の異常行」を管理画面から修復する契約が明記されている。
  したがって `$organization->users()` (pivot) と `organizationRole()` (Laratrust) は
  **同値ではない**。

### 2-5. UI 側の実査 (403/422 を期待している導線があるか)

| 画面 | 送信 | エラー表示 |
|---|---|---|
| `resources/js/pages/Organizations/Settings.svelte` | `transferForm.post('/organizations/{slug}/transfer-ownership')` | `FormField error={transferClientError ?? transferForm.errors.user_id}` |
| `resources/js/pages/Projects/Show.svelte` | `memberForm.post('/projects/{id}/members')` | `FormField error={addMemberClientError ?? memberForm.errors.user_id}` |

いずれも **Inertia フォームの inline field error として `errors.user_id` を描画**している。
候補 (`transferCandidates` / `assignableUsers`) はサーバが返した組織メンバーのみなので、
**正常系は 404 にも 403 にも到達しない**。逆に言えば、この 2 経路を 404 に倒すと
UI にはエラー表示先が無く、Inertia のエラーモーダル / エラーページに落ちる。

MCP consent (`/oauth/authorize` POST) は Blade の dropdown で、body 改ざん時のみ到達する経路。
UI 上の分岐表示は無い。

### 2-6. 台帳 (c2c) / 起票記述との食い違い

- 起票 (`follow-up-todo.md`) と brief は 3 件とも
  「`$organization->users()->whereKey($userId)->firstOrFail()` へ寄せる」= **404 化**を
  是正方針として書いているが、**2-5 の実査でこれは UI を壊す**ことが分かった
  (フォーム POST の唯一のエラー表示口が field error であるため)。本設計は
  「応答の**統一**」を目的に据え、統一先を **422 (field error) / 403 (MCP)** とする。
  詳細は §4・§5。
- brief の表は `McpConsentOrganizationBinder` も
  「`$user->organizations()->whereKey($orgId)->firstOrFail()` へ寄せる」としているが、
  実コードでは **fetch 結果が使われていない** (2-3)。寄せるのではなく **fetch を消す**のが正しい。
- 起票時「403 の代わりに 404 になる挙動変更を受け入れるか判断する」と書かれた
  `ProjectMemberController::store` は、判断の結果 **404 ではなく 422 に倒す** (§5-1)。

いずれも「台帳/起票の記述 vs 実コード」の食い違いであり、報告の `ledger_discrepancies` に記載する。

---

## 3. 課題 (何が問題なのか)

1. **global existence oracle**: 認証済みの組織 owner/admin が、任意の `users.id` /
   `organizations.id` について「実在するか」を判別できる。cross-org のデータ read/write は
   起きないが、AGENTS.md セキュリティ不変条件「cross-org 不可 / 層 2 は層 3 より前」の
   趣旨 (存在を漏らさない) に反する。
2. **fetch 時点でスコープが閉じていない**: 補償チェック (Service のロック下 / `organizationRole()`)
   に依存しており、「fetch → 使う」の間に検証を忘れた瞬間に cross-org write になる構造。
   `ModelDirectFetchInvariantTest` はこれを債務として固定しているが、
   `debtCap = 3` が残っている限り「準拠形でない形」がコードベースの見本として残り続ける。
3. **同じ情報を 2 箇所が漏らす**: fetch を relation 起点にしても
   `exists:users,id` が残れば 422 のメッセージ差で同じ 1 bit が漏れる。
   だから fetch と validation rule はセットでしか直せない。

---

## 4. 方針

### 4-0. 判断の軸

**「404 に倒す」ではなく「分岐を消す」を目的に置く。**
AGENTS.md の不変条件が要求しているのは「存在を漏らさないこと」であり、404 はその
**URL 子リソース (nested route) における実現手段**である
(`docs/app-integration-guide.md` §7-2「nested route の子リソースは…認可より前に 404」)。
payload 由来 id は URL 上のリソース指定ではなくフォーム入力であり、
**「入力が選択可能な集合に入っていない」= 422 field error** が意味論的にも UX 的にも正しい。
重要なのは **不在と非メンバーが同一応答になる**ことで、これは 422 でも満たせる。

### 4-1. 施策 A: `OrganizationOwnershipController::store`

- `exists:users,id` を落とし、`['required','integer']` (形式検証のみ) にする。
- 対象ユーザーを **組織 relation から解決**する:
  `$organization->users()->whereKey($userId)->first()`。
- 解決できなければ **Service と同一文言の** `ValidationException(['user_id' => [...]])` を投げる。
  → 不在 id も非メンバー id も **同一の 422 + 同一メッセージ**。
- Service 側のロック下再検証は**残す** (TOCTOU 防御。ここは存在確認の重複ではなく
  「ロック下での再確認」という別の役割)。

### 4-2. 施策 B: `ProjectMemberController::store`

- `exists:users,id` を落とし、`$organization->users()->whereKey($userId)->first()` で解決。
- **`organizationRole($organization) === null` の判定は残す** (2-4 の通り pivot 在籍と
  ロール付与は同値でないため、落とすと「ロール未付与の異常行」を
  プロジェクトに追加できてしまう = 現行より緩む)。
- 解決失敗と role 未付与を **同一の `ValidationException(['user_id' => [...]])`** に落とす
  (403 → 422 の挙動変更)。不在 id も他組織ユーザーも同じ応答。
- `Gate::authorize('update', $project)` は validation より前のまま = 権限の無い actor は
  引き続き 403 で、user_id の中身に触れずに終わる (オラクルにならない)。
  **この順序 (層 2 → 層 3 → payload 検証) は「現状そうなっている」では足りない**ので、
  「権限の無い actor は user_id の実在/不在/非メンバーによらず同一 403」を
  Feature テストで固定する (§7-1)。施策 A も同様。
- payload の型 narrowing は既存 controller と同じ `Assert::integerish()` + `(int)` cast の
  形を踏襲する (PHPStan level 10。具体は詳細設計)。

### 4-3. 施策 C: `McpConsentOrganizationBinder::handle`

- `Organization::query()->find($orgId)` を **削除**する (結果を使っていない)。
- 既存の `$user->organizations()->whereKey($orgId)->exists()` 1 本に集約し、
  false なら **403 一択** (不在 id も非メンバー組織も同じ 403・同じ文言)。
- `filter_var` による形式検証 422 は据え置き (形式不正は存在情報を含まないため
  統一の必要がない)。
- **入力分類の境界を明文化する** (将来ここを触ったときに新しい判定差を生まないため):

  | 入力 | 判定 | 応答 |
  |---|---|---|
  | 欠落 / 空文字 | 非 MCP フロー | 素通し (attribute を set しない) |
  | bool | 形式不正 | 422 `Invalid organization_id.` |
  | `filter_var(FILTER_VALIDATE_INT, min_range=1)` が false | 形式不正 | 422 `Invalid organization_id.` |
  | 整数として受理された値 | **すべて membership 判定へ流す** | member なら通過 / それ以外は **403 一択** |

  `filter_var` の実挙動を **PHP 8.4 で実測**した (推測で書かない):

  | 入力 | 結果 |
  |---|---|
  | `'1'` / `' 1'` / `'1 '` / `'+1'` | **int(1) = 受理** (前後空白と符号は許容される) |
  | `'001'` / `'07'` | **false = 422** (先頭ゼロは拒否される) |
  | `'0'` / `'-1'` | false = 422 (`min_range => 1`) |
  | `'1.5'` / `'1e5'` / `'abc'` / 配列 | false = 422 |

  先頭ゼロを受理する要件は無い (UI は Blade の dropdown で素の id を送る) ので、
  **`'001'` は 422 のまま**とし、正規化は入れない (今必要ないものを作らない)。

  **規約**: 「整数として受理されたものは 1 つ残らず membership 判定に流す」。
  422 側に落ちるのは id として成立しない形式だけであり、**実在情報を一切含まない**
  (存在する id かどうかで分岐しない) ため、403 と統一する必要がない。
- `use App\Models\Organization;` が不要になるので削除する。

### 4-4. 施策 D: gate の債務解消 (完了条件)

- `DirectFetchInventory::inventory()` から債務 3 エントリ (と「★債務」節見出し) を削除。
- `modelDirectFetchDebtCap()` を **3 → 0**、doc コメントを「実測 0 件」に更新。
- `DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt` と
  `DirectFetchJustificationEntry::globalExistenceRuleDebt()` は**残す**。
  cap 0 のまま case を残すことで「新しい債務は inventory 登録 + cap 引き上げの
  2 段のレビューを通さないと緑にならない」= deny-by-default が維持される
  (削除すると分類語彙ごと消え、次に同じ形が生えたときの裁定履歴が失われる)。
- 候補総数は 34 → 31 で floor (20) を下回らないことを確認する。

### 4-5. 施策 E: ドキュメント・コメントの同期 (陳腐化させない)

- `ProjectMemberController` のクラス docblock (「同一組織メンバーでなければ 403」) を更新。
- `resources/js/pages/Organizations/Settings.svelte` L124-126 のコメント
  (「最終ゲートはサーバ (Policy + exists:users,id + Service のメンバーシップ検証)」) を更新。
- `tests/Feature/Projects/ProjectMemberTest.php` の冒頭コメント
  (「追加対象 (payload user_id) の cross-org は 403」) を更新。
- **`McpConsentOrganizationBinder` の `filter_var` コメントの誤りを訂正する**:
  現行コメントは「`"1 "` を reject」と書いているが、実測では `'1 '` / `' 1'` / `'+1'` は
  **受理される** (拒否されるのは先頭ゼロ `'001'` の方)。挙動は変えず、
  コメントを実挙動に合わせる (誤った記述が次の実装者の判断材料になるのを防ぐ)。

---

## 5. 代替案と却下理由

### 5-1. 3 件すべて 404 に統一する (起票時の方針)

- **却下**。2-5 の通り 2 つのフォーム経路は `errors.user_id` を唯一のエラー表示口としており、
  404 にすると Inertia フォームの `onError` に乗らずエラーページ/モーダルへ落ちる。
  これは AGENTS.md「行き先のない詰みを作らない」(課金ゲートの着地設計と同じ思想) に反する。
- 404 が要求されるのは **URL が子リソースを名指しする nested route** の場合で
  (`projects.members.destroy` の `{user}` はまさにこれで、既に 404 化済み)、
  payload 由来 id には当てはまらない。**同じ機能でも URL 由来は 404 / payload 由来は 422**
  という現在の非対称は、意図的で正しい非対称である。
- なお 404 でもオラクルは閉じる。却下理由は安全性ではなく **UX の後退**である。

### 5-2. 組織スコープ付き `Rule::exists` を足して fetch はそのまま

`Rule::exists('organization_user', 'user_id')->where('organization_id', $organization->id)` を
足す案 (`StoreVideoManualRequest` の category と同じ形)。

- 単独では **不十分**。rule で 422 に落ちても、その後の
  `User::query()->findOrFail()` が組織スコープ外である事実は変わらず、
  gate の債務も解消しない (fetch 側が準拠形でない)。
- rule + relation fetch の**両方**を入れる案は、存在確認が二重になり
  「rule を通ったのに firstOrFail で 404」というレース時の着地が
  フォーム経路として不自然になる。§4 の relation 単独解決なら、レースでも
  同じ 422 field error に落ちる。
- ただし **却下ではなく不採用** (VideoManual の category は FormRequest を持つので
  あちらの形が正しい)。本件は FormRequest を持たない薄い controller 2 本であり、
  rule 追加のためだけに FormRequest を新設するのはオーバーエンジニアリング。

### 5-3. 専用 rule クラス (`OrganizationMemberRule` 等) を新設

- **却下**。使用箇所が 2 つ、いずれも「解決したモデルを直後に使う」形なので、
  rule にすると「検証で 1 回・fetch で 1 回」引くことになる。
  AGENTS.md 思考原則 2 (今必要なものだけ作る)。

### 5-4. MCP binder も 422 に統一する

- **不採用**。403 は「あなたの組織ではない」という actor 相対の意味を持ち、
  既存テスト・既存コメント (改ざん検知の最終防御) と整合する。
  不在 id を 403 側へ寄せれば分岐は消えるので、変更量が小さい方 (403 統一) を採る。
- 形式不正 (422) との統合も不要 — 形式不正は id の実在情報を含まない。

### 5-5. gate の分類 case ごと削除する

- **却下**。§4-4 の通り、cap 0 + case 存置が deny-by-default の維持に必要。
  「後方互換の並走を残さない」(思考原則 3) が禁じているのは**実装の二重化**であって、
  分類語彙の保持ではない。

---

## 6. スコープ境界

### 6-1. このタスクでやること

- 施策 A / B / C (アプリコード 3 ファイル)
- 施策 D (inventory 3 エントリ削除 + cap 3 → 0)
- 施策 E (コメント同期 3 箇所)
- 応答一致 (存在オラクル不成立) の Feature テスト新規追加、既存テスト 3 本の期待値更新

### 6-2. スコープに入れないもの (と理由)

| 対象 | 理由 |
|---|---|
| `ModelDirectFetchInvariantTest` / `PrimaryKeyStaticQueryScanner` 本体の改修 | brief の範囲外指定。gate の仕組みには手を入れない (inventory エントリ削除と cap 変更のみ) |
| 債務以外の直 fetch 31 件の見直し | 分類済み・準拠形。今の問題 (存在オラクル) を持たない。触ると 34 件全部の再裁定になる |
| `{organization:slug}` binding 自体の見直し | `MembershipScopedOrganizationBinder` が membership スコープで解決済み (実査で確認)。本件の 3 経路とは別機構 |
| タイミング差 (レスポンス時間) によるオラクル | 本 gate も `ResponseSignature` も観測対象にしていない。閉じるには一定時間応答が要り、今必要な範囲を超える |
| Admin console (`/manage/*`) のメンバー操作経路 | payload 由来 id を受けていない (URL param + `organizations.members.*` の scopeBindings で T108 S3 済み) |
| `exists:` rule 一般の棚卸し (他ドメイン) | 本件の 2 箇所以外に `exists:users,id` は無いことを実査で確認済み。予防的な横展開はしない |
| 404 統一への将来的な再検討 | §5-1 で不採用。UI 側に 404 の着地を作る話は別施策 (今は必要がない) |

---

## 7. 検証方法

### 7-1. 機械検証 (テスト)

**完了の定義**: 新規テスト・既存テストの期待値更新・Architecture テストが
**すべて緑になって初めて完了**とする (AGENTS.md 禁止事項 1)。

1. **新規**: `tests/Feature/Security/PayloadIdExistenceOracleTest.php`
   「実在の非メンバー id」と「不在 id」の応答一致を 3 経路で表明する
   (`MemberRouteExistenceOracleTest` の `mreoAssertNoOracle` と同じ主張形式)。
   表明は 2 段:
   (a) `ResponseSignature::of()` の一致 (status + ヘッダ + body)、
   (b) **`session('errors')->get('user_id')` の文言一致** (302 では body が空なので
   signature だけでは文言差を検出できないため)。
   - transfer-ownership (422 相当の redirect + 同一 `errors.user_id`)
   - projects.members.store (同上)
   - McpConsentOrganizationBinder は HTTP route ではなく middleware 直呼びで検証する形式なので、
     **既存の `tests/Feature/Mcp/ConsentOrganizationBinderTest.php` 側に**
     応答一致 (403 + 同一メッセージ) を足す (テスト様式を混ぜない)
1-b. **新規 (同ファイル)**: **層 3 の前置固定**。
   - 権限の無い actor (非 Owner) が transfer-ownership に
     実在メンバー / 実在非メンバー / 不在 id を送っても**すべて同一 403**
   - 権限の無い actor (project 更新権限なし) が projects.members.store に
     同 3 パターンを送っても**すべて同一 403**
   これで「Gate が payload 検証より前」が回帰で壊れたら落ちる。
1-c. **新規 (ConsentOrganizationBinderTest)**: MCP binder の
   入力分類境界 (§4-3 の表) を固定する。
   `'0'` / `'-1'` / `'1.5'` / `'001'` (先頭ゼロ) / 配列 / bool → **422**、
   member 組織 id の**前後に空白を付けた文字列** (`' '.$id`) → 通過して attribute に int が入る
   (`filter_var` が空白を許容する実挙動の固定)、
   実在非 member / 不在 → **同一 403**。
2. **更新**: `tests/Feature/Projects/ProjectMemberTest.php` の
   「他組織のユーザーは追加できない (cross-org は 403)」→ 422 + `errors.user_id` へ期待値変更
   (テストの削除ではない)。冒頭コメントも更新。
3. **更新**: `tests/Feature/Mcp/ConsentOrganizationBinderTest.php` の
   「存在しない organization_id は 422」→ 403 へ期待値変更。
4. **不変**: `tests/Feature/Organization/OwnershipTransferTest.php` は
   `assertSessionHasErrors('user_id')` のままで緑 (メッセージ統一のみのため)。
   ただし「非メンバーへは移譲できない」に**メッセージ一致**の表明を足す。
5. `ModelDirectFetchInvariantTest` が cap 0 で緑 (stale 検出 / floor / 双方向整合を含む)。

### 7-2. コマンド

開発中の絞り込み実行:

- `php artisan test tests/Feature/Security/PayloadIdExistenceOracleTest.php`
- `php artisan test tests/Architecture/ModelDirectFetchInvariantTest.php`
- `php artisan test tests/Feature/Projects/ProjectMemberTest.php tests/Feature/Organization/OwnershipTransferTest.php tests/Feature/Mcp/ConsentOrganizationBinderTest.php`

**完了条件は AGENTS.md の `VERIFICATION_COMMANDS` マーカー間に列挙された全コマンドが green**
(ここに写経して二重管理しない。`composer test` はグローバルロック配下で待つのが正常)。
本件はバックエンドのみの変更だが、フロントの検証コマンドも省略しない
(`resources/js` のコメント 1 箇所を触るため)。

### 7-3. 手動確認 (UI 非後退)

- 組織設定 → オーナー移譲: 候補選択 → 成功、ダイアログが閉じ flash が出る。
- プロジェクト詳細 → メンバー追加 / ロール変更: 成功。
- (DevTools で) 他組織ユーザーの id を送る → **画面遷移せず** select の下に
  「組織のメンバーではありません」相当の field error が出る (エラーページに飛ばない)。

---

## 8. 期待効果

- **使命への貢献**: 直接の機能価値はない。現場作業者が使う撮影 PWA / シナリオ生成に
  影響しない。効果は「AI-CUE が組織の SOP という機微情報を預かる前提を崩さない」
  = 信頼の維持であり、セキュリティ不変条件の債務返済である。
- 債務 cap が 0 になることで、**同じ形が二度と黙って増えない** (増やすには
  inventory 登録 + cap 引き上げの明示レビューが要る)。
- 「payload 由来 id は relation 起点で解決し、失敗は field error に統一する」という
  見本が 2 経路揃い、新規ドメイン追加時の参照実装になる。
