Round 1 の指摘に対する対応マトリクスと、修正後の詳細設計 (全文) を提示します。再レビューし、施策ごとの判定と全体判定を出してください。

## 実測ログ (PHP 8.4)

filter_var($v, FILTER_VALIDATE_INT, ["options"=>["min_range"=>1]]):
  true => 1 / false => false / [1] => false / 1.0 (float) => 1 / 5 => 5
  "001" => false / " 1" => 1 / "1 " => 1 / "+1" => 1 / "0" => false / "-1" => false / "1.5" => false / "1e5" => false

現行の McpConsentOrganizationBinder には既に is_bool($raw) の早期 reject が L37-39 に存在します
(設計はこれを維持する方針で、Round 1 の指摘を受けてコード抜粋に明示しました)。

# 対応マトリクス: design-review Round 1

## [Warning] 施策 C: bool が `filter_var` で 1 として受理され、入力分類契約が崩れる
- 判断: **対応する (指摘は半分正しい)**
- 根拠: `filter_var(true, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])` は **1** を返すことを
  PHP 8.4 で実測した。ただし現行コードには**すでに `is_bool($raw)` の早期 reject が存在**しており
  (`McpConsentOrganizationBinder` L37-39)、設計はそこを変更対象にしていない。
  問題は「変更後コード」の抜粋がその guard の下から始まっていて、
  実装者が guard ごと書き換える事故を招きうる点。
- 対応内容: 変更後コードに **bool guard を明示的に含め**、
  「filter_var(true) は 1 を返すのでこの guard は必須」というコメントを付けた。
  併せて「本施策で変えるのは fetch の撤去と不在 id の応答だけ」という境界を明記し、
  float `1.0` が受理される件は HTTP 経由では到達しないため追加 guard を入れない
  (使わない防御を足さない) と明記。
- 提案された `! is_string($raw) && ! is_int($raw)` への置換は**採らない**:
  現行の bool guard で契約は満たされ、置換は挙動を変えない純粋な書き換え
  (今必要ないリファクタ = 思考原則 2)。

## [Warning] 施策 E: `pieoObserve()` が PHPStan level 10 で落ちる
- 判断: **対応する**
- 根拠: `session('errors')` は mixed。`array` 戻り値も value type 未指定で level 10 に引っかかる。
- 対応内容: `ViewErrorBag` への `instanceof` narrowing、`array_values()` による `list<string>` 化、
  `ResponseSignature::of()` の実 shape (`array{status: int, headers: array<string, list<string>>, body: string}`)
  を含む `@return` shape を明記。`use Illuminate\Support\ViewErrorBag;` を import に追加。

## [Warning][Suggestion] 「422」表記が `assertStatus(422)` を誘導する
- 判断: **対応する**
- 根拠: web/Inertia 経路の `ValidationException` は 302 redirect + session errors であり、
  422 が返るのは `expectsJson()` のときだけ。設計書の表記が実装者を誤らせる。
- 対応内容: フォーム 2 経路の記述を **「validation failure (redirect back + `errors.user_id`)」**へ統一。
  MCP binder の 422/403 は **実 HTTP status** なので表記を変えない (HttpException のため)。
  テストは `assertSessionHasErrors()` を主とする方針を明記済み。

## [Suggestion] `PIEO_MISSING_USER_ID` のコメント「18 桁 pattern 内」が値 (9 桁) と不一致
- 判断: 対応する
- 対応内容: 「実在しない user id (9 桁。テストで生成される id と衝突しない値)」に訂正。
  (18 桁は route parameter 版 `MemberRouteExistenceOracleTest` の pattern 上限の話で、
  payload 経路には route pattern が無いため持ち込まない)

## [追加確認] MCP テストに `true` / `[]` / `'001'` / 前後空白を必ず入れる
- 判断: 対応済み (施策 C のテスト計画に列挙済み)。bool guard を残すので `true` は 422 で緑になる。

## [追加確認] A/B の応答一致テストで `from()` を両リクエストで固定する
- 判断: 対応する
- 対応内容: ケース 1 だけでなく**ケース 2 も** `->from()` を固定する旨をテスト計画に明記
  (Location が `ResponseSignature` の比較対象に残るため)。

## [Suggestion] 施策 A/B/D/F の承認コメント
- 判断: 見送る (肯定的評価。設計変更なし)

## 修正後の詳細設計 (全文)

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
| B | プロジェクトメンバー追加の payload user_id を org 相対化 (403 → validation failure) | `app/Http/Controllers/Projects/ProjectMemberController.php` | 高 |
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
    // 組織の非メンバーにも validation error の文言差で答えてしまうため使わない (aicue:T118)
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
- テストファイル: `tests/Feature/Projects/ProjectMemberTest.php` (403 → validation failure の期待値更新 + 冒頭コメント + 新規 1 件)

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
 *   404 秘匿ではなく **field error (validation failure)** に倒す。不在 id・他組織ユーザーを
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
  - 冒頭コメント: 「追加対象 (payload user_id) の cross-org は 403」→ 「…は validation failure (field error)」
  - 「他組織のユーザーは追加できない (cross-org は 403)」→ 名称と期待値を
    `assertSessionHasErrors(['user_id' => ...])` へ変更 (削除ではなく期待値更新)
  - 「管理権限のない project member はメンバーを追加できない (403)」— 変更なしで緑
  - **新規**: 「pivot 在籍だがロール未付与のユーザーは追加できない」
    (`$organization->users()->attach($user)` のみ行い `addRole` しない状態で validation failure になること。
    現行の `organizationRole()` 判定が失われていないことの固定)
- [x] 新規 `PayloadIdExistenceOracleTest` (施策 E)

### リスク

- **403 → validation failure の挙動変更**。UI は `memberForm.errors.user_id` を描画するため後退しない
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
// ★既存の bool guard は**必ず残す**。filter_var(true, FILTER_VALIDATE_INT) は
//   1 を返す (PHP 8.4 実測) ため、これが無いと `organization_id=true` が
//   組織 id 1 として membership 判定に流れ、入力分類契約が崩れる。
if (is_bool($raw)) {
    throw new HttpException(422, 'Invalid organization_id.');
}

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
- 変更しない: `use Webmozart\Assert\Assert;` / `use Symfony\Component\HttpKernel\Exception\HttpException;`

> **本施策で変えるのは「fetch の撤去」と「不在 id の応答 (422 → 403)」だけ**である。
> 前段の bool guard・`filter_var` の条件・素通し条件 (欠落 / 空文字) には手を入れない。
> なお PHP の `filter_var` は float `1.0` も int 1 として受理するが、HTTP 経由の入力は
> 文字列で届くため到達しない (テストで in-memory Request を作ったときだけ観測される)。
> ここに追加の guard は入れない (使わない防御を足さない)。

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
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Support\ResponseSignature;

/*
 * payload 由来 id (POST body の user_id) の存在オラクル不成立 (aicue:T118)。
 *
 * route parameter 版 (`MemberRouteExistenceOracleTest`) と同じ主張を payload 経路で行う:
 *   「実在するが非メンバーの id」と「不在の id」の応答が観測上まったく同じであること。
 * URL 子リソースではないので 404 ではなく **validation failure (redirect back + field error)** に統一している
 * (統一先が何かではなく、**分岐しないこと**が不変条件)。
 *
 * 併せて「層 3 (認可) は payload 検証より前」を固定する。ここが入れ替わると
 * 権限の無い actor が user_id の差を観測できるようになる。
 */

/** 実在しない user id (9 桁。テストで生成される id と衝突しない値)。 */
const PIEO_MISSING_USER_ID = 999999999;

/**
 * 応答 signature と field error の文言を 1 つにまとめた観測値。
 *
 * `session('errors')` は静的解析上 mixed なので ViewErrorBag への narrowing を明示する
 * (PHPStan level 10)。
 *
 * @return array{
 *     signature: array{status: int, headers: array<string, list<string>>, body: string},
 *     user_id_errors: list<string>
 * }
 */
function pieoObserve(TestResponse $response): array
{
    $errors = session('errors');

    return [
        'signature' => ResponseSignature::of($response),
        'user_id_errors' => $errors instanceof ViewErrorBag
            ? array_values($errors->getBag('default')->get('user_id'))
            : [],
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

- ケース 1 は `withSession(['recent_auth_at' => time()])` を付ける
  (recent-auth の 302 で短絡させない)。
- **全ケースで `->from(...)` を固定する** (ケース 2 も含む)。`location` ヘッダは
  `ResponseSignature` の比較対象に残るため、referer が揺れると比較が成立しない。
- **`assertStatus(422)` を書かない**。web 経路の `ValidationException` は
  **302 redirect + session errors** であり、422 が返るのは `expectsJson()` のときだけ。
  期待値は `assertRedirect()` + `assertSessionHasErrors([...])` で書く。
- ケース 3/4 は field error が出ないため `pieoObserve` の `user_id_errors` は空配列で一致する。
- **session の errors は次のリクエストで上書きされる**ため、観測は必ず「リクエスト直後」に取る
  (`pieoObserve` を呼ぶ順序に依存する。ヘルパ内で 1 リクエスト = 1 観測にしてある)。

### 既存テストの更新一覧

| ファイル | 変更内容 |
|---|---|
| `tests/Feature/Projects/ProjectMemberTest.php` | 冒頭コメントの「cross-org は 403」→ 「cross-org は validation failure (field error)」。テスト「他組織のユーザーは追加できない (cross-org は 403)」→ 「…(cross-org は validation failure)」に改名し `assertSessionHasErrors(['user_id' => '追加できるのは組織のメンバーだけです。'])` + `expect($project->members()->count())->toBe(0)` を維持。**新規**「pivot 在籍だがロール未付与のユーザーは追加できない」を追加 |
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
| `php artisan test tests/Feature/Security/PayloadIdExistenceOracleTest.php` | 実装前は 1・2 が fail (403 と validation failure の分岐)、実装後は全緑 |
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
