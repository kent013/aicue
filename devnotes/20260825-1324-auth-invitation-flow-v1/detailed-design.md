# 詳細設計: auth-invitation-flow-v1 — 家系の正典 v1 への追従 (t0 → v1)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項 (AGENTS.md より転記)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

(セキュリティ不変条件は AGENTS.md §セキュリティ不変条件が正本。本設計に関わるのは
「層 2 = テナント境界 404/中立が認可より前」「クラス起点の主キー同一性クエリは
deny-by-default で分類」「PII は CipherSweet」)

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント。走査器・gate 新設時は AGENTS.md
  「静的検査 (gate) と走査器の共通規約」5 条と「同じ PR で揃える 4 点」に従う

## 概念設計リファレンス

`devnotes/20260825-1324-auth-invitation-flow-v1/conceptual-design.md` (Codex 概念レビュー
Round 2 APPROVED)。台帳の根拠: lctl feature `auth-invitation-flow` 正典 v1
(feature_revision `28-be3d35ffee06`、2026-08-25 取得)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | i7: 招待元組織の論理削除を無効招待の同一畳み込みへ (500/存在オラクル排除) | `app/Models/OrganizationInvitation.php` / `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` / `app/Services/Organization/OrganizationMembershipService.php` / 新設 `tests/Feature/Organizations/InvitationDeletedOrganizationTest.php` | 高 |
| B | i11+i14: 招待継続クラス `InvitationContinuation` + 鍵一本化の機械検査 | 新設 `app/Support/Auth/InvitationContinuation.php` / 新設 `tests/Architecture/InvitationContinuationKeySoTTest.php` / 新設 `tests/Unit/Support/Auth/InvitationContinuationTest.php` / `InvitationAcceptanceController.php` / `app/Actions/Fortify/CreateNewUser.php` / `OrganizationMembershipService.php` | 高 |
| C | i16: 招待経由登録への verified 付与 + 登録直後の着地 | `app/Actions/Fortify/CreateNewUser.php` / `app/Http/Responses/Fortify/RegisterResponse.php` / 新設 `tests/Feature/Auth/InvitationRegistrationVerifiedTest.php` / 既存テスト 4 ファイルの期待値更新 | 中 |

実装順は A → B → C (A は実バグ + 存在オラクル、B は C が触る CreateNewUser の
土台整理を先に済ませる)。

---

## 施策 A — i7: 招待元組織の論理削除を無効招待の同一畳み込みへ

### 変更箇所
- `app/Models/OrganizationInvitation.php` `findActiveByPlainToken()` (L73-81)
- `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` `show()` (L44-90)
- `app/Services/Organization/OrganizationMembershipService.php`
  `acceptInvitation()` (L121-163) / `acceptInvitationIfValid()` (L178-205) /
  `joinOrganization()` (L404-448)

### 波及変更
- TypeScript 型定義: なし (`Invitations/Invalid` ページの props は元々無し。
  `Invitations/Accept` の props も不変)
- API Resource/DTO: なし
- テストファイル: 新設 `tests/Feature/Organizations/InvitationDeletedOrganizationTest.php`。
  既存 `tests/Feature/Auth/RegistrationInvitationPrefillTest.php` はケース追加のみ (既存ケース不変)
- DirectFetchInventory: **登録不要** — ロック下の生存再検証は relation 起点
  (`$locked->organization()->lockForUpdate()->first()`) で書き、クラス起点の主キー同一性
  クエリを増やさない (`ModelDirectFetchInvariantTest` の母集団に入らない)

### 現行コード

```php
// OrganizationInvitation::findActiveByPlainToken (L77-80)
return self::query()
    ->active()
    ->where('token_hash', self::hashToken($plainToken))
    ->first();

// InvitationAcceptanceController::show (L54, L64-72)
if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
    // ... Invitations/Invalid を直描き
}
// 未ログイン: token を session に保存して register へ誘導 (受諾は登録完了後)
if (! $request->user() instanceof User) {
    $request->session()->put('invitation_token', $token);
    return redirect()->route('register');
}
$organization = $invitation->organization;
Assert::isInstanceOf($organization, Organization::class); // ★論理削除済みで 500

// OrganizationMembershipService::acceptInvitation (L147-148)
$organization = $invitation->organization;
Assert::isInstanceOf($organization, Organization::class); // ★論理削除済みで 500

// OrganizationMembershipService::acceptInvitationIfValid (L192-193)
$organization = $invitation->organization;
Assert::isInstanceOf($organization, Organization::class); // ★論理削除済みで登録 POST が 500

// OrganizationMembershipService::joinOrganization (L413-429、ロック下再検証)
$locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
    return false;
}
// 1b. 宛先 email のロック下再照合 ...
// ★組織の生存のロック下再検証が無い
```

### 変更後コード

```php
// --- OrganizationInvitation::findActiveByPlainToken ---
// docblock 追記: 「招待元組織の生存 (SoftDeletes の default scope) も active の条件に含める
// (正典 v1 i7 — 論理削除済み組織宛は『active でない』へ畳む。scopeActivePendingForEmail の
// whereHas('organization') と同じ意味論)。scopeActive 自体は招待行の状態だけを表す scope の
// まま変えない (activePendingForEmail との条件重複を作らない)」
return self::query()
    ->active()
    ->whereHas('organization')
    ->where('token_hash', self::hashToken($plainToken))
    ->first();

// --- InvitationAcceptanceController::show ---
// 無効招待は理由非開示の専用ページへ (guest / auth 共通)。解決は findActiveByPlainToken
// (単一解決口) へ寄せる — 手書きの hash・状態条件の重複が消え、招待元組織の論理削除
// (whereHas('organization')) も同じ 1 本で畳まれる (i7)。詳細レビュー R1 Suggestion 採用。
// ★guest 分岐より前で畳む: 後ろに置くと guest では token が session に入り、
//   register の prefill に宛先が出た上で登録 POST が失敗する二段障害になる。
// ★$invitation->organization === null は解決〜描画の間の削除 race の防御 (通常は不达)。
$invitation = OrganizationInvitation::findActiveByPlainToken($token);
if ($invitation === null || $invitation->organization === null) {
    $seo->setPrivateTitle('招待リンクは使用できません');

    return Inertia::render('Invitations/Invalid');
}
// (guest 分岐は不変。その後の $organization = $invitation->organization; + Assert は
//  上の畳み込みで非 null が確定しているため到達時 narrow 用としてそのまま残す。
//  findActiveByPlainToken の docblock の利用者一覧に show() を追記する)

// --- OrganizationMembershipService::acceptInvitation ---
// (isExpired 判定の後・宛先照合の前に挿入。既存の Assert 2 行を置き換える)
// 招待元組織の論理削除は「無効」へ畳む (i7)。不在・取消済みと同一の中立メッセージ。
// 宛先照合より前に置く — 消えた組織の招待で宛先一致の可否を教えない。
$organization = $invitation->organization;
if ($organization === null) {
    throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
}

if (! $invitation->isAddressedTo($user)) { /* 既存のまま */ }

// --- OrganizationMembershipService::acceptInvitationIfValid ---
// findActiveByPlainToken が組織生存を含むため通常ここへは来ないが、
// 解決〜参照の間の論理削除 race を 500 にしない防御 (既存の Assert 2 行を置き換える)。
$organization = $invitation->organization;
if ($organization === null) {
    return null; // 個人組織生成へ fallback (登録は成功させる)
}

// --- OrganizationMembershipService::joinOrganization (1b の後に 1c を追加) ---
// 1c. 招待元組織の生存のロック下再検証 (正典 v1 i2/i7 の最終権威)。organizations 行は
//     冒頭の lockForMembershipWrite が canonical 順序で lockForUpdate 済みだが、
//     非ロックの SELECT は MVCC スナップショット版を返しうる (1b の $lockedUser と同じ理由)。
//     relation 起点の **lockForUpdate 読み**で最新版を取り直す — 取得済み行の再取得は
//     no-op re-acquire でロック順序も変わらない ($locked / $lockedUser と同じ流儀)。
//     SoftDeletes の default scope が論理削除済みを除外するため、削除済みなら null。
//     relation 起点なのでクラス起点の主キー同一性クエリを増やさない
//     (= DirectFetchInventory の母集団外)。
/** @var Organization|null $lockedOrganization */
$lockedOrganization = $locked->organization()->lockForUpdate()->first();
if ($lockedOrganization === null) {
    return false; // 受諾不能へ畳む (全呼び出し元が false を消費する既存契約)
}
// 以後の書き込み (organization_id / laratrust_team_id) は事前取得の $organization ではなく
// ロック読みした $lockedOrganization を権威として使う:
//   insertOrIgnore の 'organization_id' => $lockedOrganization->id
//   addRole の $lockedOrganization->laratrust_team_id
// (同一行なので値は同じだが、権威の出所を 1 つにする)
```

### PHPStan適合チェック
- [x] 戻り値の型: 変更メソッドはすべて既存シグネチャ不変 (`?Organization` / `?self` / `bool` / `Response|RedirectResponse`)
- [x] null 安全: `$invitation->organization` (larastan で `Organization|null`) を明示 null 判定で narrow。
  `show()` の後段 `Assert::isInstanceOf` は narrow 用に残す (畳み込み済みで実質不达)
- [x] DTO 返却: 変更なし (Inertia render / RedirectResponse のまま)
- [x] Generics: `whereHas('organization')` は `Builder<OrganizationInvitation>` を保つ

### テスト計画 (テストファースト — 実装前に赤を確認する)
新設 `tests/Feature/Organizations/InvitationDeletedOrganizationTest.php`
(名称はテンプレートの同目的テストに揃える。組織の論理削除は `$organization->delete()`):
- [ ] guest + 論理削除組織の招待リンク GET → 200 で `Invitations/Invalid` を返し、
  **session に `invitation_token` が保存されない** (`assertSessionMissing`。
  概念レビュー R2 Suggestion の採用 — 二段障害の再発防止)
- [ ] ログイン済み (宛先一致) + 論理削除組織の GET → 500 ではなく `Invitations/Invalid`
- [ ] ログイン済み (宛先一致) + 論理削除組織の POST 受諾 → 500 ではなく `app.entry` へ
  error flash `'この招待は無効です。'` (不在 token と同一文言) で差し戻し、membership 行なし
- [ ] 論理削除組織の招待 token を session に持つ register POST → 登録は成功し個人組織へ
  fallback、招待は未受諾のまま
- [ ] 論理削除組織の招待 token を session に持つ GET /register → `invitationEmail` null +
  token forget (既存 `RegistrationInvitationPrefillTest` へケース追加)
- [ ] 最終再検証の消費契約 (状態注入): 既存 `InvitationAcceptRaceTest` の家風
  (`DB::beforeExecuting` の one-shot 注入 — 「SQL の形で当てる」) をそのまま使い、
  `organization_invitations ... for update` の SELECT (bindings に対象招待 id) を検出した
  直前に `DB::table('organizations')->...->update(['deleted_at' => now()])` で招待元組織を
  論理削除する。事前検証 (`findActiveByPlainToken` / 早期照合) は生存組織で通過し、
  ロック下再検証 1c が削除を受諾不能へ畳むこと — `acceptInvitation` は中立メッセージ
  `'この招待は無効です。'`、`acceptInvitationIfValid` は null (登録は fallback 成立 +
  unverified)、いずれも membership 行なし・accepted_at 不変 — を固定する
- [ ] 実装注意 (詳細レビュー R3 Suggestion 採用): one-shot 注入の callback は
  「`injected` (削除注入済み)」と「1c の対象 SQL を観測済み」の**二段階の状態**で管理する
  (注入で callback 全体を inert にすると 1c の SQL を記録できない)。注入用 beforeExecuting と
  記録用 listener を分ける実装でもよい
- [ ] 1c が**ロック読み**であることの SQL 形状の固定 (詳細レビュー R2 Warning 採用):
  状態注入だけでは 1c を非ロック読みへ退行させても自トランザクションの更新が見えて緑に
  なってしまうため、注入後に実行される `organizations` への問い合わせを
  (`InvitationAcceptRaceTest` と同じ SQL 小文字化 + bindings 照合の家風で) 記録し、
  対象 organization id の問い合わせが以下を満たすことを assert する:
  `organizations` を対象 / SoftDeletes 条件 (`deleted_at is null` 相当) を含む /
  `for update` を含む / bindings に対象 organization id がある
- [ ] 保証範囲の docblock (AGENTS.md「検出力の主張の書き方」準拠) は 3 分割で書く:
  状態注入テスト = 最終再検証が削除を受諾不能へ畳むこと /
  SQL 形状 assert = 最終再検証が非ロック読みでなくロック読みであること /
  保証外 = 別接続を使った DB エンジン固有の MVCC スケジュールの完全再現
  (RefreshDatabase 下では別接続からテストデータが見えず構造的に不可能。また one-shot の
  注入時点では組織行ロックが取得済みのため実際の競合順序の再現でもない —
  「消費契約の決定的検証」であって「競合の再現」と表現しない)
- [ ] 負のコントロール: 生存組織では同条件で受諾が成立する (畳み込みの誤爆がない)
- [ ] 既存テスト全緑 (`InvitationTest` / `InvitationAcceptRaceTest` / prefill 系 —
  i4/i8 の固定テストを 1 本も削除・上書きしない)

### リスク
- `findActiveByPlainToken` の `whereHas` 追加で register 経路の SQL に EXISTS 副問合せが 1 つ
  増える (login 前の低頻度経路であり実害なし)
- `show()` の解決を `findActiveByPlainToken` へ寄せることで応答仕様は不変のまま条件の重複が
  消える (SQL は token_hash + active + whereHas の 1 クエリ。組織 null の race 防御の
  lazy load は有効招待では後段で同じ relation を読むため実質増分なし)。同メソッドの docblock の
  利用者一覧へ show() を追記する
- joinOrganization 1c のロック読みは、冒頭 lockForMembershipWrite で取得済みの行の
  no-op re-acquire なのでロック順序・デッドロック性質を変えない
- 文言・応答形の出し分けは増やしていない (畳み込み先はすべて既存応答) ため
  オラクル面の後退はない

---

## 施策 B — i11+i14: 招待継続クラス `InvitationContinuation` + 鍵一本化の機械検査

### 変更箇所
- 新設 `app/Support/Auth/InvitationContinuation.php`
- 新設 `tests/Architecture/InvitationContinuationKeySoTTest.php`
- 新設 `tests/Unit/Support/Auth/InvitationContinuationTest.php`
- `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` `show()` L66
- `app/Actions/Fortify/CreateNewUser.php` (L129-158: terminal forget と
  `resolveInvitationToken()` の削除)
- `app/Services/Organization/OrganizationMembershipService.php`
  `resolveRegisterPrefillEmail()` (L222-251)

### 波及変更
- TypeScript 型定義: なし (session 内部の再編。props 契約不変)
- API Resource/DTO: なし
- テストファイル: 既存テストの `withSession(['invitation_token' => ...])` は tests/ 配下で
  機械検査の走査対象外のため**変更不要** (session の物理鍵は変えない)
- 乖離台帳: 不要 — `docs/template-fingerprints.json` の entries (281 件) に
  `app/Support/Auth/` 配下・招待関連のパスは無い (2026-08-25 実測)。採用時債務一覧にも該当なし

### 設計判断: aicue の家風 (static) を採る

テンプレート (laravel-claude-template@5dd85a6) の `InvitationContinuation` は
`final readonly` + DI 注入だが、aicue には同じ置き場に **static メソッドの継続クラス**
`EmailVerificationContinuation` が既にある。正典 i11 が要求するのは「鍵と読み書きを 1 つの
専用クラスに閉じ、機械検査で固定する」ことであってクラスの形ではないため、
**隣接クラスと同じ static 形**を採り、リポジトリ内の一貫性を優先する
(`Session` はメソッド引数で受ける — 両者共通の形)。機械検査の判定は literal の所在なので
形の差に影響されない。

### 現行コード

```php
// InvitationAcceptanceController::show (L66)
$request->session()->put('invitation_token', $token);

// CreateNewUser (L129-132, L144-158)
if ($invitationToken !== null) {
    session()->forget('invitation_token');
}
private function resolveInvitationToken(): ?string
{
    $session = session();
    $raw = $session->get('invitation_token');
    if (is_string($raw) && $raw !== '') { return $raw; }
    if ($raw !== null) { $session->forget('invitation_token'); }
    return null;
}

// OrganizationMembershipService::resolveRegisterPrefillEmail (L222-251)
$raw = $session->get('invitation_token');
if (! is_string($raw) || $raw === '') {
    if ($raw !== null) { $session->forget('invitation_token'); }
    return null;
}
$invitation = OrganizationInvitation::findActiveByPlainToken($raw);
if ($invitation === null) {
    $session->forget('invitation_token');
    return null;
}
$email = $invitation->email;
if ($email === '') {
    $session->forget('invitation_token');
    return null;
}
return $email;
```

### 変更後コード

```php
// --- 新設 app/Support/Auth/InvitationContinuation.php ---
<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Session\Session;

/**
 * 招待を保持したまま認証を跨ぐときの**継続** (正典 v1 i11。参照実装は
 * laravel-claude-template@5dd85a6 の同名クラス。形は隣接する
 * EmailVerificationContinuation と同じ static + Session 引数)。
 *
 * 未ログインの招待リンク経路 (InvitationAcceptanceController::show) が覚えさせ、
 * password 登録 (CreateNewUser) と register 画面の事前入力の解決
 * (OrganizationMembershipService::resolveRegisterPrefillEmail) が拾う。
 *
 * ## 生の鍵をここ以外に書かない
 * 鍵 literal はこのファイル 1 つに閉じ、InvitationContinuationKeySoTTest が機械で固定する。
 * (従来は controller / 登録処理 / 会員サービスの 3 ファイルに生の鍵が散在していた)
 *
 * ## 型衛生
 * session には任意の型が入りうるため、`is_string && !== ''` を満たさないものは
 * 不正値として忘れさせて null を返す (汚染値で登録経路の型契約を壊さない)。
 *
 * ## 持たないもの
 * 認証を抜けた後の着地 (テンプレートの landing()) は移植しない — aicue には継続を見て
 * 着地を分岐する経路が現存しない (思考原則 2)。必要になったらテンプレートの形
 * (token の有効性を見ずに受諾確認画面へ送る — 裁定 AG-113 (b)) で足すこと。
 */
final class InvitationContinuation
{
    /** session の鍵。生の文字列はこのファイルの外 (app/ 配下) に書かない (gate が固定する)。 */
    private const string SESSION_KEY = 'invitation_token';
    // ↑ 定数名は隣接する EmailVerificationContinuation::SESSION_KEY に揃える
    //   (詳細レビュー R1 Suggestion 採用)

    /** 招待リンクに到達した guest の token を覚えさせる。 */
    public static function remember(Session $session, string $token): void
    {
        $session->put(self::SESSION_KEY, $token);
    }

    /** 型衛生つきの読み出し。不正値は忘れさせて null を返す。 */
    public static function resolve(Session $session): ?string
    {
        $raw = $session->get(self::SESSION_KEY);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if ($raw !== null) {
            $session->forget(self::SESSION_KEY);
        }

        return null;
    }

    /**
     * terminal 処理 (登録の確定 / stale・invalid 判明時の破棄) で token を落とす (i14)。
     * email 不一致での再試行を許す経路 (validation 422) では呼ばないこと。
     */
    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}

// --- InvitationAcceptanceController::show ---
InvitationContinuation::remember($request->session(), $token);

// --- CreateNewUser ---
// create() 冒頭で「処理中の HTTP リクエストに紐づく session」を 1 回だけ取得し、
// resolve と forget に**同じインスタンス**を渡す (意味論を確定 — 詳細レビュー R1 対応。
// Request::session() の戻り型は Illuminate\Contracts\Session\Session。CreateNewUser は
// Fortify の RegisteredUserController からのみ HTTP 文脈で呼ばれる):
$session = request()->session();
$invitationToken = InvitationContinuation::resolve($session);
// 登録確定の terminal (L129-132):
if ($invitationToken !== null) {
    InvitationContinuation::forget($session);
}
// private resolveInvitationToken() は**削除** (後方互換の並走を残さない)

// --- OrganizationMembershipService::resolveRegisterPrefillEmail ---
public function resolveRegisterPrefillEmail(Session $session): ?string
{
    $token = InvitationContinuation::resolve($session); // 型衛生 + 汚染値破棄は継続クラスへ集約
    if ($token === null) {
        return null;
    }

    $invitation = OrganizationInvitation::findActiveByPlainToken($token);
    if ($invitation === null) {
        InvitationContinuation::forget($session); // stale/invalid を GET 時点で破棄 (terminal)

        return null;
    }

    $email = $invitation->email;
    if ($email === '') {
        InvitationContinuation::forget($session);

        return null;
    }

    return $email;
}
```

```php
// --- 新設 tests/Architecture/InvitationContinuationKeySoTTest.php ---
// テンプレート (laravel-claude-template@5dd85a6) の同名テストを移植し、判定と fail-closed を
// 強化する (詳細レビュー R1 Warning 採用)。骨子:
//  - app/ 配下の *.php を token_get_all($source, TOKEN_PARSE) で走査し、
//    T_CONSTANT_ENCAPSED_STRING の**実行時値を復元**して 'invitation_token' との完全一致で
//    ファイルを列挙する。復元は引用符の除去に加えてエスケープ表現を解く:
//      * 二重引用符: stripcslashes() (\x69 / \151 / \n 等を復元。実測:
//        stripcslashes("\\x69nvitation_token") === 'invitation_token')
//      * 単引用符: \\ と \' の 2 種のみ手動で解く (PHP の単引用符の意味論どおり)
//  - 期待値は「ちょうど ['Support/Auth/InvitationContinuation.php']」の完全一致
//    (走査が空振りすれば [] ≠ [SoT] で赤 = 母集団の非空を判定が内包する fail-closed)
//  - fail-closed の追加 (AGENTS.md 走査器規約 (b)):
//      * 走査根 app/ が存在しなければ fail (RecursiveDirectoryIterator 任せにしない)
//      * file_get_contents が false のファイルは**黙って continue せず fail**
//      * TOKEN_PARSE により構文解析不能 (ParseError) は fail (握らない)
//      * 走査した PHP ファイル数が 0 でないことを独立の expect で固定
//  - IC-2 (検出器の負例・正例): コメント / DocComment 中の言及は数えない。
//    literal は数える — 単引用符 / **二重引用符** / **\x エスケープ形** の 3 正例
//    (概念レビュー R1 + 詳細レビュー R1 の採用)
//  - docblock (保証しないこと): 動的に組み立てた鍵 (連結・変数・sprintf) /
//    \u{} unicode エスケープ表現 (stripcslashes は解かない) / 別名の鍵で同じ担体を作る形 /
//    heredoc・nowdoc 本文 / tests/ 配下 (withSession で session を組む正当な利用者) は
//    検出できない — 「SoT の外に生の鍵 literal を書かない」という限定的な契約であることを明記
```

### PHPStan適合チェック
- [x] 戻り値の型: `remember/forget: void`、`resolve: ?string` を明示。session の `mixed` は
  `resolve()` 内部の `is_string` narrow に閉じ、呼び出し側へ漏らさない
- [x] null 安全: 呼び出し側は `?string` の null 判定のみ (Assert 不要)
- [x] DTO: 該当なし (session の読み書きのみ)
- [x] Generics: 該当なし
- [x] `resolveRegisterPrefillEmail` の戻り契約 (非 null = 非空 email) は不変 —
  FortifyServiceProvider の no-store 判定と frontend `isInvited` の依存を壊さない

### テスト計画 (テストファースト)
- [ ] 機械検査を**先に赤で確認**: `InvitationContinuationKeySoTTest` を先に置くと現行 3 ファイル
  (controller / CreateNewUser / MembershipService) が検出され赤 → 置き換え完了で緑
  (AGENTS.md「同じ PR で揃える 4 点」の 1: 負例=現行コードそのもの)
- [ ] 新設 `tests/Unit/Support/Auth/InvitationContinuationTest.php`:
  remember→resolve の round-trip / 非文字列 (配列・数値) は forget して null /
  空文字は forget して null / null は forget を呼ばず null / forget の冪等
- [ ] IC-2 検出器自己検査 (負例: コメント中の言及 0 件 / 正例: 単引用符 literal・
  二重引用符 literal・`"\x69nvitation_token"` エスケープ形が各 1 件・
  単引用符復元器に `\\` と `\'` が隣接する入力 1 件 — 置換順による誤復元の防止。
  詳細レビュー R2 Suggestion 採用) +
  fail-closed 検査 (走査ファイル数 > 0 / 読めないファイル・構文解析不能で fail する分岐)
- [ ] 既存 Feature テスト全緑 (`RegistrationInvitationPrefillTest` の 10 ケースが
  型衛生・forget 挙動の等価性をそのまま固定する — 1 本も変更しないことが等価性の証明)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- session の物理鍵 (`invitation_token`) は変えないため、デプロイ跨ぎの既存 session とも互換
- Session の取得は `request()->session()` に確定 (処理中リクエストの session という意味論。
  session 未起動なら framework が例外を投げる = fail-fast。CreateNewUser を HTTP 外から呼ぶ
  経路は現存しない)
- CreateNewUser の resolve は validation より前 (既存と同順)。forget は tx 成功後のみ
  (validation 422 では継続が残り再試行できる — 既存挙動を保存)

---

## 施策 C — i16: 招待経由登録への verified 付与 + 登録直後の着地

### 変更箇所
- `app/Actions/Fortify/CreateNewUser.php` `create()` (L99-115 の join 分岐)
- `app/Http/Responses/Fortify/RegisterResponse.php` `toResponse()` (L69-73)

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル (redirect 期待値 `verification.notice` → `app.entry` の更新 5 か所 +
  検証追加):
  - `tests/Feature/Organization/InvitationTest.php` L381 (招待 email で register) /
    L406-411 (共有プロップ — 検証用 GET 先を `verification.notice` から組織外の認証済み
    Inertia ページ `/settings` へ差し替え。検証意図「組織 route の外では
    currentOrganization が null」は不変) / L430 (signup grant)
  - `tests/Feature/Auth/RegistrationInvitationPrefillTest.php` L203 (P7)
  - `tests/Feature/Auth/RegisterPlanHandoffTest.php` L183 (招待経由 + plan 意図)
  - fallback 系 (`InvitationTest` L479 / `RegistrationInvitationPrefillTest` L169) は
    unverified のまま `verification.notice` — **変更しない**

### i13 (付与の前提) の成立根拠 — 設計として記録する

i16 は「作成 email = 招待の宛先が server 保証されている (i13) こと」を付与の前提とする。
aicue で i13 は三重で成立している (2026-08-25 実読):
1. validation 層: `MatchesInvitationEmail` rule (判定は `isAddressedToEmail` に集約 — i5)
2. 受諾の事前照合: `acceptInvitationIfValid()` の `isAddressedTo($user)` (不一致 = join しない)
3. 最終権威: `joinOrganization()` 1b の行ロック下再照合 (ロック読みした User 行で照合)

よって「`acceptInvitationIfValid` が Organization を返した ⇔ 作成 email = 招待宛先が
ロック下で確認済み」であり、**join の成立を付与条件にする**ことで前提が構造的に満たされる。
受諾不能 (失効・取消・組織論理削除・並行敗北・宛先不一致) の fallback 登録には付与しない
(i16 後段の fail-closed)。

なお**テンプレートは i16 実装済み** (laravel-claude-template@5dd85a6 の CreateNewUser を
実読 — join 成立時に同一 tx 内で `forceFill(['email_verified_at' => now()])->save()`)。
本施策はその形をそのまま写す。

### 現行コード

```php
// CreateNewUser::create (L99-115)
$joined = $invitationToken !== null
    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
    : null;

if ($joined === null) {
    $this->provisioning->provisionInitialOrganization($user);
}

return $user;

// RegisterResponse::toResponse (L69-73)
if ($request->wantsJson()) {
    return new JsonResponse('', 201);
}

return redirect()->route('verification.notice');
```

### 変更後コード

```php
// --- CreateNewUser::create (tx 内の join 分岐) ---
$joined = $invitationToken !== null
    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
    : null;

if ($joined === null) {
    // (既存コメントのまま) 個人用組織を同一 transaction 内で原子的に生成する
    $this->provisioning->provisionInitialOrganization($user);
} else {
    // 招待経由の登録は email 確認済みとして作成する (正典 v1 i16 / 裁定 AG-214)。
    // join 成立 = 有効招待 + 宛先一致のロック下再照合を通過 = 招待メール URL の所持
    // = 受信箱の所有の証明。前提 (i13) は MatchesInvitationEmail rule +
    // acceptInvitationIfValid の事前照合 + joinOrganization のロック下再照合の三重。
    // 同一 tx 内で立てるため、Fortify の Registered event (create() return 後に発火) の
    // SendEmailVerificationNotification は hasVerifiedEmail() を見て確認メールを送らない。
    // Illuminate\Auth\Events\Verified は発火しない — あの event の意味論は
    // 「確認フローを完了した」であり登録時付与とは別 (framework の markEmailAsVerified()
    // 自体も event を発火しない)。aicue に Verified の listener は存在しない (2026-08-25 実測)。
    $user->forceFill(['email_verified_at' => now()])->save();
}

// --- RegisterResponse::toResponse ---
// クラス docblock を変更後の責務に合わせて書き換える (詳細レビュー R1 Warning 採用):
//   - unverified 登録 (通常登録・招待 fallback) → verification.notice (従来どおり)
//   - 招待成立で verified 済みの登録 (i16) → app.entry (認証促し画面を経由させない)
//   - XHR (JSON) → 201 (Fortify 標準の後方互換。verified か否かで変えない)
// あわせて CreateNewUser のクラス docblock にも「join 成立時は同一 tx 内で
// email_verified_at を付与する (i16)」を追記する。
if ($request->wantsJson()) {
    return new JsonResponse('', 201); // XHR は Fortify 標準と同じ後方互換 (不変)
}

// 招待経由 (i16) で verified 済みなら「認証してください」画面を経由させない。
// verification.notice へ送っても Fortify の prompt が fortify.home へ bounce するため
// 詰みはしないが、redirect()->intended() の stale URL に依存した着地になるのを避け、
// 組織解決の正規入口 (app.entry = /go) へ決定論的に送る。
if ($user->hasVerifiedEmail()) {
    return redirect()->route('app.entry');
}

return redirect()->route('verification.notice');
```

(`$user` は toResponse 冒頭で `Assert::isInstanceOf($user, User::class)` 済みの既存変数。
pending プラン意図の分岐 (L57-67) は不変 — 招待経由は既存の else 分岐 = forgetPending 側)

### PHPStan適合チェック
- [x] 戻り値の型: 両メソッドともシグネチャ不変
- [x] null 安全: `$joined`/`$user` の narrow は既存のまま。`hasVerifiedEmail(): bool`
- [x] DTO: 該当なし (RedirectResponse / JsonResponse のまま)
- [x] forceFill は保護キー規約どおり (email_verified_at は $fillable 外の明示代入)

### テスト計画 (テストファースト)
新設 `tests/Feature/Auth/InvitationRegistrationVerifiedTest.php`
(名称は家系 aigenba の同目的テストに揃える):
- [ ] 招待経由登録の成立 → `email_verified_at` 非 null / `Notification::fake()` で
  `VerifyEmail` 通知が**送られない** / redirect が `app.entry` / 招待組織へ参加済み
- [ ] 着地チェーンの固定 (詳細レビュー R1/R2 Warning 採用): redirect を自動追跡せず
  **一段ずつ**検査する — `followRedirects` では途中に verification.notice が挟まる経路も
  最終到達が同じなら緑になり「経由しない」の根拠にならないため:
  1. 登録 POST が `app.entry` へ redirect する
  2. `route('app.entry')` を GET → 応答が**招待組織の dashboard へ直接** redirect する
     (`assertRedirectToRoute('dashboard', ['organization' => $organization->slug])`)
  3. その dashboard を GET → 200 (verified middleware を通過する)
- [ ] JSON (XHR) 後方互換: 招待成立の登録でも `wantsJson` 要求は 201 のまま。
  偽グリーン防止のため同一ケースで membership 行と `email_verified_at` 非 null も assert する
  (「未検証の通常登録が偶然 201」と区別する — 詳細レビュー R2 採用)
- [ ] fallback (取消済み token) → unverified / `VerifyEmail` 通知が**送られる** /
  redirect は `verification.notice` (付与側と対称に固定 — 概念レビュー R2 Suggestion 採用)
- [ ] 通常登録 (継続なし) → unverified / `VerifyEmail` 通知が送られる (対称の負例)
- [ ] 論理削除組織の招待 token での登録 (施策 A との結合) → unverified で fallback
  (前提が成立しない登録に verified を与えない — i16 後段)
- [ ] 既存 5 か所の redirect 期待値更新 (上記波及変更) — 検証意図は変えず期待値のみ追随
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 招待経由登録者が即 verified になることで `pendingInvitationsQuery` (verified 必須) の
  対象になる — アプリ内受諾一覧に自分宛の他招待が見えるようになるのは正当な機能有効化で後退ではない
- `verified` middleware 配下の route へ登録直後から入れるようになる — i16 の意図そのもの
  (招待メール URL の所持 = 受信箱の所有の証明)
- RegisterResponse の分岐は `hasVerifiedEmail()` のみで判定するため、将来 verified で作成される
  別経路 (例: 企業 SSO の JIT provisioning が registration response を通る形になった場合) も
  同じ分岐に乗る — 現状その経路は RegisterResponse を通らない (2026-08-25 実測:
  EnterpriseSsoLoginController は独自 redirect)

---

## 乖離台帳の確認段 (app-design Phase 3-0)

- 変更・新設する全パスを `docs/template-fingerprints.json` の entries (281 件) と突合:
  **1 件も該当しない** (2026-08-25 実測。招待関連・`app/Support/Auth/`・Fortify 関連の
  キーは存在しない) → `docs/template-divergence.md` への登録義務も
  `tests/Support/TemplateDivergence/LedgerPins.php` の件数更新も**発火しない**
- 採用時債務一覧 (`tests/Support/TemplateDivergence/adoption-debt.tsv`) にも該当パスなし
- 「テンプレートに無い領域への上積み」にも当たらない (テンプレートに実在する機能の追従)。
  RegisterResponse の verified 分岐はテンプレート現行版 (無条件 verification.notice) との
  意図的な差だが、同ファイルは fingerprints 非登録 (aicue が既に P7 で大きく分岐済み) のため
  登録簿の対象外。判断根拠は本設計と実装コメントに残す

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 3 施策が同一ファイル群 (CreateNewUser / OrganizationMembershipService / InvitationAcceptanceController) を触り、C は A (論理削除 fallback) と B (継続クラス経由の resolve) の完成形の上で結合テストを書くため、単一 worktree で A → B → C の順に積む |
| 競合リスク | 招待・登録まわりを触る他 TODO は現在 Open に無い (2026-08-25)。`tests/Feature/Organization/InvitationTest.php` は大きい共有テストのため、並行施策が生えた場合は先にマージした側へ rebase する |

## 検証コマンド (AGENTS.md の必須集合 — 全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages`
(frontend・packages 変更は無いが AGENTS.md が全数 green を要求するため省略しない)

## 実装完了後の台帳報告 (実装タスク側の責務 — 本設計はファイル生成のみ)

実装が main へ入ったら `mcp__lctl__append_event` で `status_reported` を追記する
(refs は push 済みの aicue@<commit> 必須、expected_revision は直前の get_feature の値)。
i4/i8 既充足 + 本 4 点の解消で正典 v1 の全数充足を主張できるかは、報告時に
不変条件 i1-i17 を全数再点検してから書く。
