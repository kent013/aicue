## Round 2: Round 1 指摘への対応

Round 1 の全 [Critical]/[Warning] に対応しました。主な変更点:

### 施策1 [Critical] TOCTOU / ロック外照合 + [Warning] 三重実装 + [Suggestion] Assert 冗長
→ 施策 0 を新設: `OrganizationInvitation::isAddressedToEmail(string): bool` /
  `isAddressedTo(User): bool` を email 同一性規則の**単一出典**にした (narrow も predicate 内 1 箇所)。
  規則は既存 L150 の case-sensitive 厳密一致 (fail-secure) を踏襲。
  施策 1 を**二段照合**に変更:
  - 早期照合 (acceptInvitation 内): UX メッセージ + 高速拒否 (権威ではない)
  - **ロック下再照合 (joinOrganization 内)**: `$locked` 取得後・INSERT 前に `$user->fresh()` の
    committed email を predicate で再照合し不一致は false。既存の membership 書き込みロック
    (users→organizations + 招待行 lockForUpdate) の内側 = 照合と参加成立が同一排他区間。
    3 経路共通コアのため全経路がロック下 email 境界を得る (register/in-app は元から一致で挙動不変)。
  acceptInvitationIfValid L179 / MatchesInvitationEmail L46 も predicate 呼び出しへ置換。

### 施策2 [Warning] canAccept が強すぎ + Controller 独自比較
→ prop 名を `recipientEmailMatches` に改名。Controller も施策 0 predicate を使用。

### 施策3 [Warning] Svelte テスト任意 + 文言未確定
→ 新規 `tests/js/pages/InvitationsAccept.test.ts` を**必須化**。PageHeader の
  一致/不一致 title・description の確定文言を表で明記 (不一致時は組織名を含めない)。

### 施策5 [Critical] TOCTOU 検出不可 + [Warning] T5 同一招待順次 / T1・T6 未確定 / T4 role 偽陽性
→ T4b (ロック下再照合の TOCTOU 証明) 追加。T5 は独立 fixture + 同一入力表 (一致/完全不一致/
  大小差のみ→fail-secure 不一致)。T1= InvitationTest L278/L369/L446、
  T6= PendingInvitationScopeTest / AcceptInvitationInAppTest を名指し。
  T4 は laratrust_team_id 明示 + cache reset + DB assertion で pivot/role 不在を直接確認。

### 施策6 [Critical] T8 current-org 未設定で role fail-closed を検証できない + [Warning] T7 403 由来 / cache 不安定
→ T8 は membership あり + current_organization_id=対象組織 + role のみ無しで 403。
  T7 は自然な null 化を検証しつつ T7b で current_organization_id を除名済み組織へ戻しても
  membership 境界で拒否を分離固定。DB pivot 不在を直接 assert + cache reset。strict_types 明記。

### 施策7 [Suggestion] / 施策8 [Warning]
→ roleOptions へ改名。施策 8 に T9b (操作可能・非 disabled) / T9c (サーバ role エラー表示) /
  T10 (Feature: applyConsoleRole が 422 拒否・role/project pivot 不変) を追加。

対応マトリクスは design-review-decisions-round-1.md。改訂後の詳細設計書を再掲します。全体判定をお願いします。

---

## 詳細設計書 (改訂版)

# 詳細設計: bughunt-org-membership-authz

> **Codex 合議について**: 本設計は概念設計フェーズで `gpt-5.6-terra` の合議を Round 3 で APPROVED、
> 詳細設計フェーズで `gpt-5.6-sol` の合議を実施した (結果は本ディレクトリの
> `detailed-review-round-*.md`)。Codex は正常に稼働した。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本グループの位置づけ: 組織はこの SOP・撮影データ・課金の管理単位。組織のメンバー境界
(誰が入れるか / 誰を外せるか) が意図どおり閉じることは、機密の作業手順・映像資産を
守る使命の前提である。

### 禁止事項（設計に直結する核）

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて実装済み)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び (本グループは LLM 経路に触れない)
6. prompt 文字列のコード直書き (本グループ非該当)
7. 操作系 POST の応答での `redirect()->intended()` (ログイン直後フロー専用)
8. **必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示する。DESIGN.md)**
   — F-2-01 の設計判断の根拠
9. Artifact の使用禁止 (成果物はリポジトリ内ファイル)

セキュリティ不変条件 (関連): (2) 子は親に属する=認可より前に 404、(9) 変更系は認可を通る、
(10) 層 2 (テナント境界 404) は層 3 (認可 403) より前、(6) PII は CipherSweet / `whereBlind`。

### コーディングルール

- PHPStan level 10 (`composer phpstan`) / Pest (`composer test`) / RefreshDatabase + `--parallel`
  (`tests/Pest.php` グローバル適用、個別 `DatabaseTransactions` 禁止)
- テストデータは Factory で生成。DTO + JsonResource パターン。アーリーリターン推奨。
- `composer fix` (Pint) / `pnpm lint:fix`。PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TS。

## 概念設計リファレンス

`devnotes/20260821-1522-bughunt-org-membership-authz/conceptual-design.md`
(概念合議 APPROVED: `conceptual-review-round-3.md`)。事前検証で **実在する脆弱性は F-2-02 のみ**、
F-2-03 の pivot 解除は実装済・「未割当」は fail-closed、F-2-01 の事前表示も実装済であることを確定した。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 0 | F-2-02 招待宛先判定の単一 domain predicate | `app/Models/OrganizationInvitation.php` | Critical |
| 1 | F-2-02 招待受諾の宛先 email 照合 (Service = 権威。ロック下再照合) | `app/Services/Organization/OrganizationMembershipService.php` | Critical |
| 2 | F-2-02 受諾確認画面の不一致分岐 (補助 UX) | `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` | Critical |
| 3 | F-2-02 Accept 画面 (recipientEmailMatches prop) + Svelte テスト | `resources/js/pages/Invitations/Accept.svelte` / `tests/js/pages/InvitationsAccept.test.ts` (新規) | Critical |
| 4 | F-2-02 解決経路目録の説明更新 | `tests/Architecture/InvitationResolutionInventoryTest.php` | Critical |
| 5 | F-2-02 Feature テスト (照合 + TOCTOU + 回帰) | `tests/Feature/Organization/InvitationTest.php` (追記) | Critical |
| 6 | F-2-03 除名/未割当 fail-closed リグレッションテスト | `tests/Feature/Organization/MemberRemovalAccessTest.php` (新規) | Critical |
| 7 | F-2-01 option ラベル注記 (非 disabled) | `resources/js/pages/Admin/Users.svelte` | Medium |
| 8 | F-2-01 Svelte + Feature テスト | `tests/js/pages/AdminUsers.test.ts` (追記) / 施策 5 と同ファイル | Medium |

### email 同一性規則の正本 (全施策共通の前提)

既存の受信者スコープ (`OrganizationInvitation::scopeActivePendingForEmail`, L150 docblock) と
register 経路 (`acceptInvitationIfValid` L179 / `MatchesInvitationEmail` L46) は、いずれも
**CipherSweet 復号後平文の「大文字小文字を区別する厳密一致」** を email 同一性規則としている
(L150: 「email は大文字小文字を区別する完全一致… Lowercase transformer を付けていない…
大小差のある宛先は fail-secure に 0 件へ倒れる」)。**本設計はこの既存規則をそのまま踏襲し、
新しい正規化 (lowercase / trim) を導入しない** (別物を作らない / 後方互換並走を残さない)。
規則を 1 箇所に集約するため、施策 0 で domain predicate を新設し、token POST 経路もそれを使う
(Codex 詳細 R1 施策1 [Warning] 三重実装の解消)。

---

## 施策 0: F-2-02 招待宛先判定の単一 domain predicate

### 変更箇所
- ファイル: `app/Models/OrganizationInvitation.php` — 新規メソッドを追加。

### 設計意図
email 同一性規則を **モデル 1 箇所** に集約する (Codex 詳細 R1 施策1 [Warning])。既存の
`acceptInvitationIfValid` (L179) / `MatchesInvitationEmail` (L46) の素の `!==` 比較を、この
predicate 呼び出しへ置換して出典を一本化する (将来の規則変更が 1 箇所で効く)。

### 変更後コード
```php
/**
 * この招待が指定 email 宛かを判定する (招待宛先判定の単一出典)。
 * email 同一性規則は scopeActivePendingForEmail (L150) と同じ
 * 「CipherSweet 復号後平文の大文字小文字を区別する厳密一致」。正規化 (lowercase/trim) は
 * 意図的に行わない (大小差は fail-secure に不一致へ倒す)。
 */
public function isAddressedToEmail(string $email): bool
{
    $invited = $this->email; // CipherSweet 復号後。PHPStan L10 のため string へ narrow
    Assert::string($invited);

    return $invited === $email;
}

/** User 宛判定の薄いラッパ (呼び出し側の可読性。規則は isAddressedToEmail に集約)。 */
public function isAddressedTo(User $user): bool
{
    $email = $user->email;
    Assert::string($email);

    return $this->isAddressedToEmail($email);
}
```

> `Assert::string` は model に email の `@property string` 注釈が無く PHPStan が `mixed` と見るため
> **predicate 内に 1 度だけ**置く (呼び出し側に散らさない = Codex 詳細 R1 施策1 [Suggestion] 対応。
> 冗長 narrow を各所に書かず出典へ寄せる)。`use App\Models\User;` を import する。

### 波及変更
- 置換対象: `acceptInvitationIfValid` L179 (`$invitation->email !== $user->email` →
  `! $invitation->isAddressedTo($user)`)、`MatchesInvitationEmail::validate` L46
  (`$invitation->email !== $value` → `! $invitation->isAddressedToEmail($value)`)。
- テスト: 施策 5 T5 (規則の単一出典を経路横断で固定)。

### PHPStan適合チェック
- [x] 戻り値 `bool` 明示 / `Assert::string` で narrow / 配列返却なし

### リスク
- register 経路の挙動は不変 (同じ厳密比較を predicate 経由に置換するだけ)。既存
  `InvitationTest` L446「異なる email で register すると email エラー」で回帰固定される。

---

## 施策 1: F-2-02 招待受諾の宛先 email 照合 (Service = 唯一の権威的 gate。ロック下再照合)

### 変更箇所
- ファイル: `app/Services/Organization/OrganizationMembershipService.php`
  - `acceptInvitation()` (L116-149): 早期の宛先 email 照合を追加 (UX 用の明示メッセージ + 高速拒否)。
  - `joinOrganization()` (L406-437): **ロック下・参加成立直前**に宛先 email を再照合する
    (TOCTOU 封じ。Service を真の権威にする = Codex 詳細 R1 施策1 [Critical] 対応)。
  - メソッド docblock (L111-113) の「一致を要求しない仕様」記述を削除・書き換え。

### 二段照合の設計 (早期 + ロック下再照合)
- **早期照合** (`acceptInvitation` 内、解決直後): predicate 不一致なら固有メッセージの
  `ValidationException`。UX (どのメッセージを出すか) と高速拒否のため。**権威ではない**。
- **ロック下再照合** (`joinOrganization` 内、`$locked` 取得後・INSERT 前): 既存の
  membership 書き込みロック規律 (users→organizations 行ロック + 招待行 lockForUpdate) の内側で、
  **ロック下の招待 (`$locked`) と fresh 再取得したユーザーの email** を predicate で再照合し、
  不一致なら `false` を返す (= 受諾不能。既存の「受諾済/取消/期限」と同じ neutral 畳み込み)。
  これにより「照合と参加成立が同一排他区間」になり、受諾中の email 変更 TOCTOU / stale user を
  封じる。**この再照合は 3 経路 (token / register / in-app) 共通コアに入るため全経路が
  ロック下 email 境界を得る** (register/in-app は元から pre-lock で email 一致を保証済みのため
  挙動は不変。token 経路だけが新たに保護される)。

### 波及変更
- TypeScript 型定義: なし (Service 内部)
- API Resource/DTO: なし (`ValidationException` は標準 error bag に乗る)
- テストファイル: 施策 5 (`InvitationTest.php`) / 施策 4 (目録)

### 現行コード
```php
// L111-149 (抜粋)
/**
 * 招待受諾。ログイン中ユーザーが受諾する (招待 email と user の email の一致は要求しない)。
 */
public function acceptInvitation(string $plainToken, User $user): Organization
{
    $invitation = OrganizationInvitation::query()
        ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
        ->first();

    if ($invitation === null || $invitation->isRevoked()) {
        throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
    }
    if ($invitation->isAccepted()) {
        throw ValidationException::withMessages(['token' => ['この招待は既に使用されています。']]);
    }
    if ($invitation->isExpired()) {
        throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
    }

    $organization = $invitation->organization;
    Assert::isInstanceOf($organization, Organization::class);

    if ($organization->users()->whereKey($user->getKey())->exists()) {
        throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
    }

    $role = OrganizationRole::from($invitation->role);
    // ... joinOrganization ...
}
```

### 変更後コード
```php
/**
 * 招待受諾。ログイン中ユーザーが受諾する。
 * **受諾者の email は招待の宛先 email と一致しなければならない** (F-2-02)。
 * register 経路 (acceptInvitationIfValid) / アプリ内受諾 (acceptPendingInvitation) と
 * 同じ email 境界を token POST 経路にも適用する。email 同一性規則は
 * acceptInvitationIfValid と同一 (CipherSweet 復号後平文の厳密比較)。
 *
 * @throws ValidationException token 不正 / 取消済 / 失効 / 受諾済 / 宛先 email 不一致 / 既メンバー
 */
public function acceptInvitation(string $plainToken, User $user): Organization
{
    $invitation = OrganizationInvitation::query()
        ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
        ->first();

    if ($invitation === null || $invitation->isRevoked()) {
        throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
    }
    if ($invitation->isAccepted()) {
        throw ValidationException::withMessages(['token' => ['この招待は既に使用されています。']]);
    }
    if ($invitation->isExpired()) {
        throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
    }

    // 早期の宛先 email 照合 (F-2-02, UX + 高速拒否)。規則は施策 0 の predicate に集約。
    // 権威はロック下再照合 (joinOrganization) 側。
    if (! $invitation->isAddressedTo($user)) {
        throw ValidationException::withMessages([
            'token' => ['この招待は別のメールアドレス宛に送信されています。招待先のメールアドレスでログインし直してください。'],
        ]);
    }

    $organization = $invitation->organization;
    Assert::isInstanceOf($organization, Organization::class);

    if ($organization->users()->whereKey($user->getKey())->exists()) {
        throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
    }
    // ... 以降変更なし (joinOrganization がロック下で最終権威の再照合を行う) ...
}
```

`joinOrganization()` のロック下再照合 (追加分):
```php
// 1. 招待行ロック + 受諾可能状態のロック下再検証 (既存)
/** @var OrganizationInvitation $locked */
$locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
    return false;
}

// 1b. 宛先 email のロック下再照合 (F-2-02 権威。TOCTOU 封じ)。
//     lockForMembershipWrite が既に user 行をロック済みのため fresh 取得は committed 権威値。
$freshUser = $user->fresh();
Assert::isInstanceOf($freshUser, User::class);
if (! $locked->isAddressedToEmail($freshUserEmailNarrowed($freshUser))) {
    return false; // 宛先不一致は受諾不能へ畳む (neutral。既存の false 契約と同じ扱い)
}
// ... 以降 insertOrIgnore など既存処理 ...
```

> `$freshUser->email` は predicate 内で narrow されるため `isAddressedTo($freshUser)` を使えばよい
> (上記の `$freshUserEmailNarrowed` は説明用の擬似表現。実装は `$locked->isAddressedTo($freshUser)`)。

> **早期照合の位置**: 「受諾済/取消済/失効」の後・「既メンバー」の前。token の生存判定は宛先に
> 依らず既存順序を保ち、宛先不一致はその後で判定する。**どの分岐も join より前 = 状態を一切
> 変えずに拒否する**。最終権威はロック下再照合。

### PHPStan適合チェック
- [x] 戻り値型 `Organization` / `bool` 明示済
- [x] null 安全: email narrow は predicate (施策 0) に集約 / `$user->fresh()` は `Assert::isInstanceOf`
- [x] DTO 返却 (Organization モデル。配列返却なし)
- [x] Generics 該当なし

### テスト計画 → 施策 5 に集約 (TOCTOU 再照合の証明を含む)

### リスク
- 正規受諾者の誤拒否: register / in-app 経路と**同一 predicate**なので、それらで受理される
  email は token 受諾でも受理される (規則分岐なし)。施策 5 T5 (規則一致) で固定。
- ロック下再照合の追加が register/in-app の挙動を変えないこと (元から email 一致) を
  既存 `AcceptInvitationInAppTest` / register 系テストが緑のまま維持することで確認。
- 既存 `InvitationTest` / `InvitationAcceptRaceTest` が「別 email でも受諾成功」を前提にした
  ケースを持つと落ちる → 施策 5 波及節で列挙し email 一致前提へ更新 (仕様変更に追随。禁止事項 3 は
  「削除・上書き」だが本件は仕様変更に伴う正当な更新で、旧仕様の検証を残さない = 後方互換並走禁止)。

---

## 施策 2: F-2-02 受諾確認画面の不一致分岐 (補助 UX)

### 変更箇所
- ファイル: `app/Http/Controllers/Organizations/InvitationAcceptanceController.php`
  - `show()` (L43-77): ログイン済 + 有効招待の分岐 (L70-76) に email 照合を追加し、
    不一致なら `canAccept => false` で Accept を描画。
  - クラス docblock L23「一致はログイン後経路では要求しない仕様」を削除・書き換え。

### 波及変更
- TypeScript 型定義: 施策 3 の Accept Props に `recipientEmailMatches: boolean` 追加
- API Resource/DTO: なし
- テストファイル: 施策 5 / 施策 3 (Svelte)

### 現行コード
```php
// L70-76
$organization = $invitation->organization;
Assert::isInstanceOf($organization, Organization::class);

return Inertia::render('Invitations/Accept', [
    'organizationName' => $organization->name,
    'token' => $token,
]);
```

### 変更後コード
```php
$organization = $invitation->organization;
Assert::isInstanceOf($organization, Organization::class);

// 宛先 email 照合 (F-2-02, 補助 UX)。権威は Service (acceptInvitation + joinOrganization)。
// prop 名は「email が一致するか」だけを表す (受諾可否の全条件ではない = Codex 詳細 R1 施策2 [Warning])。
// 規則は施策 0 の predicate に集約 (Controller が独自比較式を持たない)。
$user = $request->user();
Assert::isInstanceOf($user, User::class);
$recipientEmailMatches = $invitation->isAddressedTo($user);

return Inertia::render('Invitations/Accept', [
    'organizationName' => $organization->name,
    'token' => $token,
    'recipientEmailMatches' => $recipientEmailMatches,
]);
```

> **prop 名 `recipientEmailMatches`** (旧 `canAccept`): 既に組織メンバー等の場合 email 一致でも
> Service は拒否するため、「受諾可能」を意味する強い名前を避け、実際に判定している「宛先 email が
> 一致するか」に限定した名前にする (Codex 詳細 R1 施策2 [Warning])。
> `$request->user() instanceof User` は L64 の guest 分岐で早期 return 済みだが PHPStan L10 のため
> `Assert::isInstanceOf` で narrow (既存様式)。email narrow は predicate 内。
> `organizationName` は不一致時**も渡すが、画面 (施策 3) では一致時のみ表示に使い、不一致時は
> 組織名を含む description を出さない** (非受信者への開示面を増やさない)。

### PHPStan適合チェック
- [x] `Assert::isInstanceOf` で narrow / email narrow は predicate
- [x] Inertia props (配列。`response()->json()` 不使用)

### テスト計画 → 施策 5 (Feature: prop 値) + 施策 3 (Svelte: 表示)

### リスク
- guest フロー (L63-68) は本変更より前で return するため T055 に影響しない (施策 5 T1 で固定)。

---

## 施策 3: F-2-02 Accept 画面 (recipientEmailMatches prop) + 必須 Svelte テスト

### 変更箇所
- ファイル: `resources/js/pages/Invitations/Accept.svelte`
- 新規: `tests/js/pages/InvitationsAccept.test.ts` (現在 Accept の Svelte テストは無いため新設)

### 波及変更
- TypeScript 型定義: 同ファイル内 `Props` interface に `recipientEmailMatches: boolean` を追加
  (別 d.ts への切り出しは無い)。
- テストファイル: 本施策の新規 Svelte テスト + 施策 5 の Feature (prop 値到達)。

### 確定文言 (両分岐。Codex 詳細 R1 施策3 [Warning] 対応)
| 分岐 | PageHeader title | PageHeader description |
|---|---|---|
| 一致 (`recipientEmailMatches=true`) | `組織への招待` | `「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。` (現行維持) |
| 不一致 (false) | `組織への招待` | `この招待は別のメールアドレス宛に送信されています。` (**組織名を含めない**) |

### 現行コード
```svelte
interface Props { organizationName: string; token: string; }
let { organizationName, token }: Props = $props();
// PageHeader description は固定文字列 (組織名入り)
<Card padding="lg">
    <form novalidate onsubmit={submit}>
        <Button type="submit" loading={form.processing} testId="accept-invitation-button">招待を受諾する</Button>
    </form>
</Card>
```

### 変更後コード
```svelte
interface Props { organizationName: string; token: string; recipientEmailMatches: boolean; }
let { organizationName, token, recipientEmailMatches }: Props = $props();

const description = $derived(
    recipientEmailMatches
        ? `「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。`
        : "この招待は別のメールアドレス宛に送信されています。",
);
// ...
<PageHeader title="組織への招待" {description} icon={UserPlus} testId="accept-invitation-heading" />
// ...
<Card padding="lg">
    {#if recipientEmailMatches}
        <form novalidate onsubmit={submit}>
            <Button type="submit" loading={form.processing} testId="accept-invitation-button">
                招待を受諾する
            </Button>
        </form>
    {:else}
        <p class="text-body" data-testid="accept-invitation-mismatch">
            招待メールを受け取ったアドレスでログインし直してください。画面右上のメニューから
            ログアウトし、招待メールのリンクをもう一度開いてください。
        </p>
    {/if}
</Card>
```

> **ログアウトボタンは Accept 画面に置かない** (設計判断)。`resources/js` の `/logout` 参照は
> `logout-call-site-inventory.test.ts` が deny-by-default で 4 箇所に pin しており、新規追加は
> inventory + `docs/supported-browsers.md` (経路 C) の更新を伴う。ログアウトは `AppLayout` ヘッダに
> 常設のため、不一致画面は**文言で誘導**するだけにする (`/logout` 参照を増やさない = inventory
> 非変更、波及最小)。token が logout を跨いで生存することにも依存しない。

### テスト計画 (必須。Codex 詳細 R1 施策3 [Warning] 「任意扱い」是正。Vitest + Testing Library)
- [ ] **一致時**: `recipientEmailMatches=true` → 受諾ボタン (`accept-invitation-button`) が表示され、
      `accept-invitation-mismatch` が無い。description に組織名が含まれる。
- [ ] **不一致時**: false → 受諾ボタン/フォームが無く、`accept-invitation-mismatch` の案内文が表示。
      description は「別のメールアドレス宛」文言で**組織名を含まない**。

### リスク
- なし特筆 (`/logout` 参照を増やさないため logout inventory に触れない)。

---

## 施策 4: F-2-02 解決経路目録の説明更新

### 変更箇所
- ファイル: `tests/Architecture/InvitationResolutionInventoryTest.php`
  - `Services/Organization/OrganizationMembershipService.php#acceptInvitation` の登録
    (scope = `TokenHashLookup`) の**説明文**を、email 照合を含む形に更新。

### 波及変更
- scope 分類は **変更しない** (`TokenHashLookup` のまま)。email 照合は token_hash による解決の
  **後段**に足す検証であって解決の起点 (どのクエリで招待を引くか) を変えないため、目録の分類契約に
  影響しない。テストは「解決起点の分類」を検証しており、本文の email 比較追加では落ちない。

### 現行コード (登録の説明文)
```php
'Services/Organization/OrganizationMembershipService.php#acceptInvitation' => [$token,
    'POST token 受諾。token_hash 照合で解決し、失効/期限/受諾済みを個別メッセージに'
    .'出し分ける (token 保持者向けの既存契約)。'],
```

### 変更後コード
```php
'Services/Organization/OrganizationMembershipService.php#acceptInvitation' => [$token,
    'POST token 受諾。token_hash 照合で解決し、失効/期限/受諾済みを個別メッセージに'
    .'出し分ける。宛先 email の早期照合に加え、参加成立と同じ排他区間 (joinOrganization の'
    .'ロック下) で再照合し、不一致は join しない (F-2-02。register 経路と同一の email 境界)。'],
```

> なお `joinOrganization` の目録登録 (`LockedRowReload`) は本文に `lockForUpdate()` と
> `whereKey($model->id)` を要求する機械検査を持つ。email 再照合の追加はこれらの字句を消さない
> (既存の `whereKey($invitation->id)->lockForUpdate()` は維持) ため目録は落ちない。

### リスク
- 目録テストが説明文に対して字句アサートを持つ場合の破綻。→ 目録テストは scope 分類と
  RecipientScopedPendingSet の `activePendingForEmail(` 本文検査 / LockedRowReload の
  `lockForUpdate()` 本文検査のみを機械検証する (説明文は自由記述)。`acceptInvitation` は
  `TokenHashLookup` で本文字句要件が無いため、email 照合の追加でも目録は落ちない (実装時に
  `composer test -- --filter=InvitationResolutionInventory` で確認する)。

---

## 施策 5: F-2-02 Feature テスト

### 変更箇所
- ファイル: `tests/Feature/Organization/InvitationTest.php` (既存に追記。既存の POST 受諾ケースで
  「別 email でも成功」を前提にするものがあれば email 一致前提へ更新)

### 波及変更 (既存テストの更新 — 仕様変更への追随。必須)

email 照合の追加により、「valid な招待 token を、宛先と異なる email のユーザーが受諾して**成功する**」
ことを前提にしていた既存ケースが落ちる。これらは**旧仕様の検証を残さず**新仕様へ更新する
(禁止事項 3 は「テストの削除・上書き」の禁止だが、本件は仕様変更に伴う setup の正当な更新であり、
旧挙動の検証を温存する = 後方互換並走を残す方がむしろ禁止。受諾者の email を招待宛先に揃える):

`tests/Feature/Organization/InvitationTest.php`:
- **L74「token 受諾でメンバーシップ + 招待ロールが付与される」**: 受諾者 (`$invitee`) の email を
  招待宛先 `invitee@example.com` に揃える (例:
  `User::factory()->create(['email' => 'invitee@example.com'])` を使い、その後 `current_organization_id`
  を別組織に設定)。
- **L96「受諾画面 (GET) は組織名と token を表示する」**: `$invitee` の email を `invitee@example.com` に
  揃える (canAccept=true で受諾ボタン/組織名の表示アサートを維持)。
- **L347「有効な招待リンクの受諾確認画面は route 既定タイトル」**: `$invitee` の email を
  `valid-title@example.com` に揃える (canAccept に依らずタイトルは同じだが、意味的に揃える)。
- **L513「招待の受諾は org 参加のみで Default Project の pivot を作らない」**: `$invitee` の email を
  `member@example.com` に揃える (POST 成功前提の維持)。
- **L546「受諾済み招待で joinOrganization 相当に到達しても no-op」**: 1 人目 (`$first`) の email を
  `idempotent@example.com` に揃える (1 人目の受諾成立 → 2 人目が `isAccepted` で弾かれる、という
  本来の意図を保つ。2 人目は email 不一致でも `isAccepted` チェックが email 照合より前なので
  引き続き error になる)。

**落ちない既存ケース** (email 照合の**前**で弾かれるため無変更): 失効 (L111) / 受諾済み (L130) /
取り消し済み (L228) — いずれも token 解決 or `isRevoked/isAccepted/isExpired` で先に error になる。
`joinOrganization` を reflection で直接叩く L565 も acceptInvitation を経由しないため無変更。

`tests/Feature/Organization/InvitationAcceptRaceTest.php`:
- **L54「acceptInvitation はロック下再検証の敗北を…畳む」**: 受諾者は既に
  `email => 'invitee@example.com'` で作られている。招待側の宛先 email も `invitee@example.com` に
  揃っていることを確認する (揃っていれば email 照合を通過してロック競合分岐に到達し、テストの
  検証意図が保たれる。揃っていなければ email 照合で先に落ちるため setup を合わせる)。
- L75 (`acceptInvitationIfValid`) / L90 (`acceptPendingInvitation`) は元から email 一致
  (register / 受信者スコープ) 前提のため無変更。

### テスト計画 (Pest。`declare(strict_types=1);`・Factory 生成・RefreshDatabase グローバル・
個別 `DatabaseTransactions` 不使用)

- [ ] **T2 (正常系回帰)**: 招待先 email でログイン中のユーザーが `POST /invitations/accept` →
      302 dashboard + success flash「…に参加しました」、`organization_user` に参加成立、
      **対象組織の `laratrust_team_id` を明示**して org role が付与されたことを確認
      (relation/Laratrust キャッシュを fresh 取得後に検証)。
- [ ] **T3 (照合・show)**: 別 email のログイン者が `GET /invitations/accept?token=...` →
      Inertia `Invitations/Accept` に `recipientEmailMatches=false` (受諾ボタン非表示の駆動値)。
- [ ] **T4 (照合・直 POST = 権威。副作用境界を明示)**: 別 email のログイン者が
      `POST /invitations/accept` → 302 dashboard + error flash。以下がすべて不変:
      - `organization_user` の行数が増えない (**DB assertion で pivot 不在を直接確認**)
      - 対象組織 `laratrust_team_id` の role_user に行が増えない (キャッシュ/relation リセット後に確認。
        `organizationRole` の null だけに依存しない = Codex 詳細 R1 施策5 [Warning])
      - 招待の `accepted_at` が null のまま
      - project pivot / `current_organization_id` も不変
- [ ] **T4b (TOCTOU = ロック下再照合の証明。Codex 詳細 R1 施策5 [Critical])**:
      招待宛先 email で解決した後、**参加成立前に宛先が食い違う状態**を作って
      `joinOrganization` (共通コア) を通し、join / role / `accepted_at` が変化しないことを固定する。
      具体化: (a) Service を直接呼び、解決済みの招待に対しユーザー email を招待と別値へ変更してから
      `acceptInvitation` を呼ぶ → ロック下再照合で false に畳まれ join しない。または
      (b) reflection で `joinOrganization` を、招待宛先と異なる email の user で直接呼び false を確認
      (既存 L565 の reflection 様式を踏襲)。実装上 email が受諾中に不変であることを別途保証できる
      なら、その不変性テストへ置換してよいが、**ロック下再照合が宛先不一致を弾く**ことを機械的に固定する。
- [ ] **T5 (規則の単一出典・厳密比較)**: 独立 fixture (経路ごとに別の招待・ユーザー・組織) を作り、
      register 経路 (`acceptInvitationIfValid`) と token POST 経路 (`acceptInvitation`) に**同一の
      email 入力表**を適用する: (i) 完全一致 → 双方 join、(ii) 完全不一致 → 双方 join しない、
      (iii) **大文字小文字のみ相違 → 双方 join しない** (既存 L150 の case-sensitive fail-secure 規則を
      固定。Codex 詳細 R1 施策5 [Warning])。同一招待を順に使い回さない (先行受諾の `accepted_at` で
      後続が別理由で落ちるのを防ぐ)。
- [ ] **T1 (T055 不変。回帰の所在を特定)**: 以下の既存テストが緑のまま維持されることを確認し、
      不足があれば assertion を追加する (新規重複は作らない):
      - `InvitationTest` L278「未ログインの有効招待リンクは token を session 保存し register へ誘導」
        (session `invitation_token` 保存)
      - `InvitationTest` L369「招待 email で register すると個人組織を作らず招待組織へ参加」
      - `InvitationTest` L446「招待 email と異なる email で register すると email エラー」(prefill/照合)
- [ ] **T6 (AG-113 不変。回帰の所在を特定)**: 以下が緑のまま維持されることを確認:
      - `tests/Feature/Invitations/PendingInvitationScopeTest.php` (受信者スコープ = 宛先本人限定)
      - `tests/Feature/Invitations/AcceptInvitationInAppTest.php` (アプリ内受諾の成功系)
- [ ] 個別 `DatabaseTransactions` 不使用を確認

---

## 施策 6: F-2-03 除名 / 未割当 fail-closed リグレッションテスト (production 変更なし)

### 変更箇所
- ファイル: `tests/Feature/Organization/MemberRemovalAccessTest.php` (新規)

### 設計判断 (明記事項 — 本グループの必須成果)
「attach 済みだが laratrust ロール未付与 (=『未割当』) の行を許容し続ける設計」は **維持する**:
- (a) 検証した主要 route (dashboard/projects/billing/manage-users) で 403 = fail-closed。
- (b) 管理画面の `applyConsoleRole` (`normalizeOrganizationRole` 修復枝) が正規の回復手段。
- (c) 並行受諾レースの自然な帰結であり、禁止するには受諾コアへ追加機構が必要 =
  思考原則「今必要なものだけ作る」に反する。実在の障害も観測されていない。
→ **production コードは変更しない**。既存の正しい挙動をテストで不変条件化するのみ。

### テスト計画 (Pest。`declare(strict_types=1);`・Factory 生成・RefreshDatabase グローバル・
個別 `DatabaseTransactions` 不使用。relation / Laratrust キャッシュはアサート前に fresh 化)

- [ ] **T7 (HTTP 除名の完全性)**: owner が `DELETE organizations.members.destroy` で編集者を除名 →
      (1) **`organization_user` の pivot 行が DB assertion で不在** (`assertDatabaseMissing` 等。
          `organizationRole` の null だけに依存しない = Codex 詳細 R1 施策6 [Warning])、
      (2) `/manage/users` (Inertia `Admin/Users`) の `members` prop に当該 user が含まれない、
      (3) 対象組織 `laratrust_team_id` の role_user 行が不在 (キャッシュ reset 後に確認)、
      (4) `project_members` pivot から消滅 (DB assertion)、
      (5) `current_organization_id` が (当該 org だった場合) null 化、
      (6) 被除名者で `GET /projects` `GET /billing` が **403 or 404** (当該 org へアクセス不可。
          自然な除名結果では current_organization_id=null になるため、まずこの自然状態を検証)。
- [ ] **T7b (stale current-org でも membership 境界で拒否)**: 除名後、被除名者の
      `current_organization_id` を**除名済み組織へ明示的に戻した**上で `GET /projects` `GET /billing`
      → membership/role が無いため依然アクセス不可 (403/404)。これにより「403 が current-org 不在
      ではなく membership 境界で成立している」ことを分離して固定する (Codex 詳細 R1 施策6 [Warning])。
- [ ] **T8 (未割当 fail-closed)**: `organization_user` に attach 済み **かつ
      `current_organization_id = 対象組織**」だが Laratrust role だけが無い user を作り
      (Codex 詳細 R1 施策6 [Critical])、`/dashboard` `/projects` `/billing` `/manage/users` が 403。
      これにより「current-org 不在ではなく role 不在で拒否される」ことを固定する。
      docblock に「検証した主要 route (dashboard/projects/billing/manage-users)。全 route 保証ではない」
      と明記する。

### リスク
- アクセス制御の実装が role ではなく別条件に将来変わると 403 前提が崩れる → その時こそ本テストが
  落ちて検知する (それが本テストの目的)。
- 拒否コードが 403 か 404 かは route により異なりうる (テナント境界は 404、認可は 403)。
  各 route の期待コードは実装時に現行の挙動へ合わせて確定する (未割当は「current org が対象組織で
  membership もある」ため manageMembers 系は 403 を想定。projects/billing 系は実装の gate に従う)。

---

## 施策 7: F-2-01 option ラベル注記 (非 disabled。禁止事項 8 遵守)

### 変更箇所
- ファイル: `resources/js/pages/Admin/Users.svelte`
  - `ROLE_OPTIONS` (L59-63) を、`hasDefaultProject` に応じてラベルを派生する `$derived` に変える。

### 設計判断 (明記事項)
bug-hunt 改善案の「option を disabled にする」は **AGENTS.md 禁止事項 8 に反するため採らない**。
既にカード冒頭に事前注記 + 作成 CTA (L275-290) があり、事前表示は実装済。本施策は選択地点
(option ラベル) に非 disabled の情報を足し、手戻りを **減らす** (完全に無くすものではない)。
enforcement は従来どおりサーバ側 validation (`applyConsoleRole`)。

### 波及変更
- TypeScript 型定義: なし (`ConsoleRole` 型は不変。ラベル文字列のみ派生)
- API Resource/DTO: なし
- テストファイル: 施策 8

### 現行コード
```svelte
const ROLE_OPTIONS: { value: ConsoleRole; label: string }[] = [
    { value: "admin", label: "管理者" },
    { value: "editor", label: "編集者" },
    { value: "shooter", label: "撮影者" },
];
```

### 変更後コード
```svelte
// hasDefaultProject が false のとき、編集者・撮影者は選べても割り当てはできない
// (サーバが validation で拒否)。禁止事項 8 に従い disabled にはせず、選択地点で
// 制約を可視化する注記サフィックスを付ける。管理者は無条件に選べるため付けない。
// props 依存の反応値になるため命名を SCREAMING_CASE 定数から roleOptions へ変える
// (Codex 詳細 R1 施策7 [Suggestion])。
const roleOptions = $derived<{ value: ConsoleRole; label: string }[]>([
    { value: "admin", label: "管理者" },
    { value: "editor", label: hasDefaultProject ? "編集者" : "編集者（要プロジェクト）" },
    { value: "shooter", label: hasDefaultProject ? "撮影者" : "撮影者（要プロジェクト）" },
]);
```

> `hasDefaultProject` は既存の Props。template の `{#each ROLE_OPTIONS ...}` を
> `{#each roleOptions ...}` に追随させる (rename 波及。1 箇所)。disabled 属性は追加しない。
> `INVITE_ROLE_OPTIONS` (招待フォーム側) は本施策の対象外 (別集合。統合しない)。

### テスト計画 → 施策 8

### リスク
- ラベル文言が長くなりモバイル幅で折り返す可能性 → 既存 Select atom の native `<option>` は
  ブラウザ標準の省略に委ねる (追加 CSS 不要)。DESIGN.md token に触れない (色/radius 不変)。

---

## 施策 8: F-2-01 Svelte テスト + Feature バックストップ

### 変更箇所
- ファイル: `tests/js/pages/AdminUsers.test.ts` (既存に追記)
- Feature: 施策 5 と同じ `InvitationTest.php` ではなく、既存の
  `tests/Feature/Organization/ConsoleRoleTransitionTest.php` に追記 (applyConsoleRole の拒否は
  既にここの領域。重複があれば既存ケースを参照)。

### テスト計画 (Svelte: Vitest + Testing Library。既存 `AdminUsers.test.ts` に倣う)
- [ ] **T9a (制約可視化)**: `hasDefaultProject=false` で描画 → ロール select の option に
      「編集者（要プロジェクト）」「撮影者（要プロジェクト）」が存在し、「管理者」は素のまま。
- [ ] **T9b (操作可能・非 disabled = 禁止事項 8 の実挙動固定。Codex 詳細 R1 施策8 [Warning])**:
      `hasDefaultProject=false` でも編集者/撮影者 option を選択して change を開始でき、
      ロール select も削除/変更ボタン等の最終操作も `disabled` 属性を持たない。
- [ ] **T9c (サーバ拒否の表示)**: role の error bag (`page.props.errors.role`) がある状態で
      該当行に `FormError` (`role-error-{id}`) が表示される (押下後にエラーが画面に出ることを固定)。
- [ ] **T9d (対の正例)**: `hasDefaultProject=true` → option ラベルが「編集者」「撮影者」(注記なし)。
- [ ] 既存の no-project-note 表示テストの維持を確認。

### テスト計画 (Feature バックストップ: enforcement の権威はサーバ)
- [ ] **T10**: プロジェクト 0 件組織で `PATCH organizations.members.update` に editor/shooter を送ると
      `applyConsoleRole` が 422 (「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」)
      で拒否し、**org role / project pivot を変更しない** (DB assertion)。
      (既存 `ConsoleRoleTransitionTest` に同等ケースがあれば参照・不足を補う)

### 波及変更
- なし (テストのみ)

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | F-2-02 は Service + Controller + Svelte + Architecture 目録 + Feature テストに跨り、docblock の仕様記述変更も伴う。認可境界の変更で既存招待テストの更新も必要。1 本の worktree でまとめて実装・テスト・レビューするのが安全。F-2-01/F-2-03 は独立だが小さく、同一 PR に同梱しても干渉しない (触るファイルが重ならない)。 |
| 競合リスク | 低。触るファイルは招待受諾系・Admin/Users.svelte・新規テスト。他の進行中 TODO と重なる可能性は低いが、`InvitationTest.php` / `InvitationAcceptRaceTest.php` の既存ケース更新は他作業と競合しうるので実装時に最新 main を確認する。 |

## 使命・禁止事項チェック (最終)
- [x] 全施策が使命 (組織のメンバー境界を守る) に寄与、または既存不変条件を保護する
- [x] 禁止事項 8 を F-2-01 の設計根拠として明示 (disabled 化を退けた)
- [x] 禁止事項 4 (`response()->json()` 直書き) を増やさない (ValidationException + Inertia props)
- [x] 禁止事項 1 (テストなし完了禁止): 各 finding に Feature/Svelte テストを対応付け
- [x] PHPStan L10: email narrow を `Assert::string` で明示
- [x] DESIGN.md: 色/radius/typography token に触れない (ラベル文字列と条件表示のみ)
