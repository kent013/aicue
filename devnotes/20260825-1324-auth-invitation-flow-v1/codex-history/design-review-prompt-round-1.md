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

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー対象の背景】
複数リポジトリ共有の機能台帳 (lctl) の feature auth-invitation-flow の正典 v1 (不変条件 i1〜i17、台帳で確定済み・再議論しない) への追従。aicue で未充足の 4 点 (i7 の一部 / i11 / i14 の一部 / i16) を 3 施策で埋める。概念設計は Codex レビュー Round 2 で APPROVED 済み。

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
  (`$locked->organization()->exists()`) で書き、クラス起点の主キー同一性クエリを増やさない
  (`ModelDirectFetchInvariantTest` の母集団に入らない)

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
// 無効招待は理由非開示の専用ページへ (guest / auth 共通)。
// 招待元組織の論理削除 (SoftDeletes の default scope で relation が null) も同一畳み込み (i7)。
// ★guest 分岐より前で畳む: 後ろに置くと guest では token が session に入り、
//   register の prefill に宛先が出た上で登録 POST が失敗する二段障害になる。
if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted()
    || $invitation->isExpired() || $invitation->organization === null) {
    $seo->setPrivateTitle('招待リンクは使用できません');

    return Inertia::render('Invitations/Invalid');
}
// (guest 分岐は不変。その後の $organization = $invitation->organization; + Assert は
//  上の畳み込みで非 null が確定しているため到達時 narrow 用としてそのまま残す)

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
// 1c. 招待元組織の生存のロック下再検証 (正典 v1 i2/i7)。organizations 行は冒頭の
//     lockForMembershipWrite が canonical 順序で lockForUpdate 済みのため、並行の論理削除
//     (同一行の UPDATE) とはここまでで直列化されている。relation 起点の exists で
//     default scope (SoftDeletes) を効かせて読み直す (クラス起点の主キー同一性クエリを
//     増やさない = DirectFetchInventory の母集団外)。
if (! $locked->organization()->exists()) {
    return false; // 受諾不能へ畳む (全呼び出し元が false を消費する既存契約)
}
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
- [ ] TOCTOU 契約: 有効な招待と宛先一致 user を用意 → 組織を論理削除 → stale なメモリ上の
  `$organization` インスタンスで `joinOrganization` を ReflectionMethod 直接呼び (既存
  「既 attach 状態での受諾」テストと同じ手法) → false が返り membership 行も accepted_at も
  変化しない。docblock に「実 DB の並行トランザクションの再現ではなく、ロック下の最終再検証の
  契約を固定するテスト」と明記 (概念レビュー R2 Suggestion 採用。AGENTS.md
  「検出力の主張の書き方」準拠)
- [ ] 負のコントロール: 生存組織では同条件で受諾が成立する (畳み込みの誤爆がない)
- [ ] 既存テスト全緑 (`InvitationTest` / `InvitationAcceptRaceTest` / prefill 系 —
  i4/i8 の固定テストを 1 本も削除・上書きしない)

### リスク
- `findActiveByPlainToken` の `whereHas` 追加で register 経路の SQL に EXISTS 副問合せが 1 つ
  増える (login 前の低頻度経路であり実害なし)
- `show()` の畳み込みに relation の lazy load が加わるが、有効招待では後段で同じ relation を
  読むため実質増分なし
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
    private const string KEY = 'invitation_token';

    /** 招待リンクに到達した guest の token を覚えさせる。 */
    public static function remember(Session $session, string $token): void
    {
        $session->put(self::KEY, $token);
    }

    /** 型衛生つきの読み出し。不正値は忘れさせて null を返す。 */
    public static function resolve(Session $session): ?string
    {
        $raw = $session->get(self::KEY);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if ($raw !== null) {
            $session->forget(self::KEY);
        }

        return null;
    }

    /**
     * terminal 処理 (登録の確定 / stale・invalid 判明時の破棄) で token を落とす (i14)。
     * email 不一致での再試行を許す経路 (validation 422) では呼ばないこと。
     */
    public static function forget(Session $session): void
    {
        $session->forget(self::KEY);
    }
}

// --- InvitationAcceptanceController::show ---
InvitationContinuation::remember($request->session(), $token);

// --- CreateNewUser ---
// create() 冒頭:
$invitationToken = InvitationContinuation::resolve(session()->driver());
// ↑ session() ヘルパは SessionManager を返すため、Session 契約 (Store) は driver() で得る。
//   ★実装時は request()->session() でも可 — どちらを採るかは PHPStan の通り方で確定し、
//     Session 契約型で渡すことだけを不変条件とする。
// 登録確定の terminal (L129-132):
if ($invitationToken !== null) {
    InvitationContinuation::forget(session()->driver());
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
// テンプレート (laravel-claude-template@5dd85a6) の同名テストを移植する。骨子:
//  - app/ 配下の *.php を token_get_all で走査し、T_CONSTANT_ENCAPSED_STRING の値が
//    invitation_token に一致するファイルを列挙 (判定は trim($token[1], '\'"') —
//    単・二重引用符の両方を復元して比較)
//  - 期待値は「ちょうど ['Support/Auth/InvitationContinuation.php']」の完全一致
//    (走査が空振りすれば [] ≠ [SoT] で赤 = 母集団の非空を判定が内包する fail-closed)
//  - IC-2 (検出器の負例・正例): コメント / DocComment 中の言及は数えない。
//    literal は数える — 単引用符に加えて**二重引用符の literal も数える**正例を追加
//    (概念レビュー R1 Warning の採用。テンプレートとの差分はこの正例 1 つ)
//  - docblock (保証しないこと): 動的に組み立てた鍵 / 別名の鍵で同じ担体を作る形 /
//    tests/ 配下 (withSession で session を組む正当な利用者) は検出できない —
//    「SoT の外に生の鍵を書かない」という限定的な契約であることを明記
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
- [ ] IC-2 検出器自己検査 (負例: コメント中の言及 0 件 / 正例: 単引用符 literal 1 件・
  二重引用符 literal 1 件)
- [ ] 既存 Feature テスト全緑 (`RegistrationInvitationPrefillTest` の 10 ケースが
  型衛生・forget 挙動の等価性をそのまま固定する — 1 本も変更しないことが等価性の証明)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- session の物理鍵 (`invitation_token`) は変えないため、デプロイ跨ぎの既存 session とも互換
- `session()->driver()` と `request()->session()` の選択は実装時に PHPStan で確定する
  (どちらも `Illuminate\Session\Store` = `Session` 契約を返す。挙動は同一)
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

## 検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` (frontend 変更は無いが検証は全数実行する)

## 実装完了後の台帳報告 (実装タスク側の責務 — 本設計はファイル生成のみ)

実装が main へ入ったら `mcp__lctl__append_event` で `status_reported` を追記する
(refs は push 済みの aicue@<commit> 必須、expected_revision は直前の get_feature の値)。
i4/i8 既充足 + 本 4 点の解消で正典 v1 の全数充足を主張できるかは、報告時に
不変条件 i1-i17 を全数再点検してから書く。

---
## 関連する現行コード

### app/Http/Controllers/Organizations/InvitationAcceptanceController.php (全文)
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Seo\SeoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 招待受諾。GET (確認画面) は guest 可 (未ログインは register へ誘導)。POST (受諾) は auth 必須。
 * verified は要求しない (招待された直後の未検証ユーザーも受諾できる)。
 * 受諾には受諾者 email と招待の宛先 email の一致を要求する (権威は
 * OrganizationMembershipService。GET は補助 UX として不一致を事前表示する)。
 */
class InvitationAcceptanceController extends Controller
{
    /**
     * 受諾確認画面 (GET, guest 可)。
     *
     * - token 欠落 (URL に token param 自体が無い) は 404
     * - 無効招待 (不在/取消済/受諾済/期限切れ) は理由を出し分けず組織名も出さない専用ページ
     *   (Invitations/Invalid) を返す。どの無効理由でも同一画面にすることで token オラクルを防ぐ
     *   (未認証の URL 探索で「組織が実在し招待が取り消された」等を識別させない)
     * - 未ログイン + 有効招待: token を session に fail-secure 保存し register へ誘導する
     *   (登録完了時に CreateNewUser が招待組織へ参加させる)
     * - ログイン済 + 有効招待: 受諾確認画面 (組織名 + 受諾ボタン) を表示する
     *
     * タイトル: route 既定は config('seo.app_titles')['invitations.accept'] =「組織への招待」。
     * 無効分岐だけは同じ route で別ページ (Invitations/Invalid) を返すため、
     * SeoManager::setPrivateTitle() で上書きする (config は route 名でしか引けない)。
     * **理由・組織名は開示しない**既存の秘匿契約を守り、固有名にも組織名を混ぜない。
     */
    public function show(Request $request, SeoManager $seo): Response|RedirectResponse
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && $token !== '', 404);

        $invitation = OrganizationInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // 無効招待は理由非開示の専用ページへ (guest / auth 共通)
        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
            // タブ title は h1「この招待リンクは使用できません」から指示語「この」を落とした形。
            // SeoTitle::compose が ` | {サイト名}` を付けるため、タブ幅を圧迫しない範囲で見出しと揃える
            // (config/seo.php の「h1 と一致させる」規約に対する意図的な短縮。
            //  文言を変えるときは Invitations/Invalid.svelte の h1 も追随させる)。
            $seo->setPrivateTitle('招待リンクは使用できません');

            return Inertia::render('Invitations/Invalid');
        }

        // 未ログイン: token を session に保存して register へ誘導 (受諾は登録完了後)
        if (! $request->user() instanceof User) {
            $request->session()->put('invitation_token', $token);

            return redirect()->route('register');
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 宛先 email 照合 (補助 UX)。権威は Service (acceptInvitation + joinOrganization)。
        // prop 名は「email が一致するか」だけを表す (受諾可否の全条件ではない)。
        // 規則は OrganizationInvitation::isAddressedTo に集約 (Controller は独自比較式を持たない)。
        // $request->user() は上の guest 分岐で早期 return 済みだが PHPStan L10 のため narrow する。
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);
        $recipientEmailMatches = $invitation->isAddressedTo($user);

        // 不一致時は organizationName を渡さない (null)。DOM で隠すだけでは初期 Inertia payload /
        // devtools から非受信者が組織名を読めてしまうため、payload そのものから組織名を落とす
        // (非受信者への組織の実在・名称の開示面を増やさない)。
        return Inertia::render('Invitations/Accept', [
            'organizationName' => $recipientEmailMatches ? $organization->name : null,
            'token' => $token,
            'recipientEmailMatches' => $recipientEmailMatches,
        ]);
    }

    /** 受諾 (POST)。成否いずれも dashboard へ flash 付きで遷移する */
    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $request->validate([
            'token' => ['required', 'string'],
        ]);
        $token = $request->input('token');
        Assert::string($token);

        try {
            $organization = $membership->acceptInvitation($token, $user);
        } catch (ValidationException $e) {
            // back 先が GET /invitations/accept (404 になり得る) のため dashboard へ逃がす
            return redirect()->route('app.entry')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard', ['organization' => $organization->slug])
            ->with('success', "「{$organization->name}」に参加しました");
    }
}
### app/Models/OrganizationInvitation.php (全文)
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use Webmozart\Assert\Assert;

/**
 * 組織招待。token は平文を保存せず sha256 ハッシュ (token_hash) のみ。
 * email は CipherSweet 暗号化 + blind index。
 * token_hash / organization_id / invited_by_user_id は $fillable 外。
 * 取り消しは行削除ではなく revoked_at による論理失効 (spirux 方式)。
 * 招待が持つロールは**組織ロールのみ** (役割付き招待は裁定 AG-079 で撤去。
 * 編集者 / 撮影者は参加後に管理画面のロール割当コマンドで付与する)。
 */
class OrganizationInvitation extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    use UsesCipherSweet;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'role',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * 招待 token (平文) を生成する。URL 埋め込み用途のみで DB には保存しない。
     * DB には hashToken() の sha256 を token_hash 列に保存する。
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * 平文 token を at-rest 保存用の sha256 hash に変換する。
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * 平文 token から「受諾可能 (active: 未受諾・未失効・期限内)」な招待を解決する。
     * token_hash 照合 + scopeActive のみ (平文 email 検索は行わない = 列挙面を広げない)。
     * active でない (不在/失効/取消/受諾済) 場合は null。
     *
     * MatchesInvitationEmail / acceptInvitationIfValid / register prefill resolver が共有し、
     * active 判定条件のドリフトを防ぐ単一解決口。
     * (POST 受諾 acceptInvitation() は revoked/accepted/expired を個別メッセージに出し分けるため
     *  本メソッドを使わない)
     */
    public static function findActiveByPlainToken(string $plainToken): ?self
    {
        // active の定義は scopeActive が単一の正 (未受諾・未失効・期限内: expires_at > now)。
        // isExpired()/isAccepted()/isRevoked() の個別判定と概念的に一致させ、ドリフトを防ぐ。
        return self::query()
            ->active()
            ->where('token_hash', self::hashToken($plainToken))
            ->first();
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        /** @var Carbon $expiresAt */
        $expiresAt = $this->getAttribute('expires_at');

        return $expiresAt->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * この招待が指定 email 宛かを判定する (**復号後インメモリ宛先比較の単一出典**)。
     *
     * email 同一性規則は scopeActivePendingForEmail (上の docblock) と同じ
     * 「CipherSweet 復号後平文の大文字小文字を区別する厳密一致」である。正規化 (lowercase / trim) は
     * 意図的に行わない (大小差は fail-secure に不一致へ倒す)。
     *
     * 保証範囲は「復号後インメモリ宛先比較の単一出典」であって「email 同一性規則すべての単一実装」では
     * ない。受信者スコープ (scopeActivePendingForEmail) は blind index による DB 検索であり別レイヤ。
     * 両者は同じ意図 (大小区別の厳密一致) で書かれているが、本 predicate を直接は使わない。
     */
    public function isAddressedToEmail(string $email): bool
    {
        $invited = $this->email; // CipherSweet 復号後。model に @property 注釈が無く PHPStan L10 は mixed と見る
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

    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * **受信者視点の単一解決口** — 「この email 宛の、いま受諾できる招待」の集合。
     *
     * アプリ内受諾 (invitations.accept-in-app) の解決・一覧・件数はすべてこの scope を
     * 再利用する (裁定 AG-113 の必須要素 (b)。2 つがずれると「件数は出るのに受諾できない」が起きる)。
     * 再利用の強制は InvitationResolutionInventoryTest が deny-by-default で行う。
     *
     * 3 条件は**すべて存在秘匿のためにある**:
     *  - active(): 期限切れ・取消済・受諾済を落とす
     *  - whereBlind: 宛先不一致を落とす (CipherSweet の blind index。平文 where は hit しない)
     *  - whereHas('organization'): 削除済み組織宛を落とす
     *    (Organization は SoftDeletes。default scope が効くため deleted_at 判定を手書きしない)
     * これらが**すべて同じ「0 件」に collapse する**ことが、呼び出し側で理由を出し分けずに
     * 一律 404 へ畳める根拠である (403 を返さない = 招待の存在を教えない)。
     *
     * ★email は**大文字小文字を区別する完全一致**である (email の blind index に
     *   Lowercase transformer を付けていない)。大小差のある宛先は 0 件 = 404 に倒れる
     *   (fail-secure)。従来のメール token 経路は token_hash 照合なので影響を受けず、
     *   そちらで受諾できる。
     * ★空文字 email での呼び出しは**呼び出し側が事前に弾く**契約
     *   (OrganizationMembershipService::pendingInvitationsQuery)。ここでは防御しない
     *   (guard を 2 箇所に置くと「どちらが正か」が曖昧になる)。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActivePendingForEmail(Builder $query, string $email): void
    {
        $query->active()
            ->whereBlind('email', 'email_index', $email)
            ->whereHas('organization');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
### app/Actions/Fortify/CreateNewUser.php (全文)
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\MatchesInvitationEmail;
use App\Rules\UniqueEncryptedEmail;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Services\Organization\OrganizationMembershipService;
use App\Services\Organization\OrganizationProvisioningService;
use App\Support\Legal\LegalConsent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Webmozart\Assert\Assert;

/**
 * メール + パスワード登録。
 *
 * - email は CipherSweet 暗号化カラムのため unique rule では検証できない。
 *   UniqueEncryptedEmail rule (blind index 照合) で検証し、衝突時は手段を開示しない
 *   中立メッセージの 422 を返す (アカウント列挙対策)。
 * - 利用規約同意はサーバ側でも必須 (`accepted`)。UI 側はボタンを disabled にせず
 *   押下時にこのエラーを表示する (DESIGN.md §Do's and Don'ts)。
 * - 同意の証跡 (terms_accepted_at / consent_version) は $fillable 外のため forceFill で
 *   初回 INSERT 時点で記録する。
 * - 招待 (organization invitation) 経由の登録は、session の invitation_token を fail-secure に
 *   解決し、招待 email との一致を MatchesInvitationEmail rule で検証する。受諾可能なら本
 *   transaction 内で招待組織へ参加し、個人組織の自動生成はスキップする (招待組織を主所属に
 *   する)。受諾不能 (失効/取消/受諾済/不一致/既メンバー) なら個人組織生成へ fallback する。
 * - 料金表由来のプラン意図 (`intended_plan`) は validation rules に足さない (無効値でも登録は
 *   通す = 422 で止めない)。値は IntendedPlanResolver が PlanCode allowlist に照合し、
 *   不在 / 無効 / 改ざんはすべて pending forget に倒す (stale pending の誤 promote 防止)。
 *   pending → org-scoped への移送は RegisterResponse が行う。
 */
class CreateNewUser implements CreatesNewUsers
{
    public function __construct(
        private readonly OrganizationProvisioningService $provisioning,
        private readonly OrganizationMembershipService $membership,
        private readonly IntendedPlanResolver $intendedPlanResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        // session の招待 token を fail-secure に解決 (未ログインの招待リンク経由で保存される)
        $invitationToken = $this->resolveInvitationToken();

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueEncryptedEmail(message: 'このメールアドレスではアカウントを作成できません。'),
                // 招待 token がある場合のみ、招待 email との一致を検証する (通常登録では素通り)
                new MatchesInvitationEmail($invitationToken),
            ],
            // 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
            // 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)
            'password' => ['required', 'string', Password::default()],
            'terms_accepted' => ['accepted'],
        ], [
            'terms_accepted.accepted' => '利用規約への同意が必要です。',
        ])->validate();

        // 料金表 → /register?plan= のプラン意図を pending に書き込む (常に書き換える規約)。
        // validate 通過後・tx 前に 1 回だけ呼ぶ (422 で止めた入力の意図は保持しない)。
        $this->intendedPlanResolver->rememberPendingFromForm($input);

        $name = $validated['name'];
        $email = $validated['email'];
        $password = $validated['password'];
        Assert::string($name);
        Assert::string($email);
        Assert::string($password);

        try {
            $user = DB::transaction(function () use ($name, $email, $password, $invitationToken): User {
                $user = (new User([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]))->forceFill([
                    'terms_accepted_at' => now(),
                    'consent_version' => LegalConsent::version(),
                ]);
                $user->save();

                // 招待 token 経由なら招待組織へ参加し、個人組織生成をスキップする。
                // 受諾不能 (失効/取消/不一致/既メンバー) なら null が返るので個人組織へ fallback。
                $joined = $invitationToken !== null
                    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
                    : null;

                if ($joined === null) {
                    // 個人用組織を同一 transaction 内で原子的に生成する
                    // (user だけ存在し組織なしの中間状態を作らない)。
                    //
                    // 初回 signup grant はここでは付与しない (P6/F2)。付与契機はプラン有効化時
                    // (free = PersonalPlanService::activate / paid = customer.subscription.created)
                    // であり、marker (organizations.signup_tickets_granted_at) の先取と付与は
                    // その経路の同一 tx に閉じている。**marker 設定だけをここに残してはならない**
                    // (付与されない marker 済み org = 永久に付与を受けられない org になる)。
                    $this->provisioning->provisionInitialOrganization($user);
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // validation を通り抜けた INSERT race (blind_indexes の partial unique が止める)。
            // transaction は rollback 済なので、外側で email 起因か再確認する。
            if ($this->emailAlreadyRegistered($email)) {
                $this->rejectExistingEmail();
            }

            throw $e; // email 起因でない unique 違反は握り潰さず再送
        }

        // 登録が確定したので招待 token を session から落とす (terminal)
        if ($invitationToken !== null) {
            session()->forget('invitation_token');
        }

        return $user;
    }

    /**
     * session の `invitation_token` を fail-secure に取得する。
     *
     * session には任意の型が入りうるため、`is_string && !== ''` を満たさないものは不正値として
     * forget し null を返す (未ログインの招待リンク経路が put する。汚染された値で登録経路の
     * 型契約を壊さない)。
     */
    private function resolveInvitationToken(): ?string
    {
        $session = session();
        $raw = $session->get('invitation_token');

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if ($raw !== null) {
            $session->forget('invitation_token');
        }

        return null;
    }

    /**
     * UniqueEncryptedEmail rule と同一の blind index 照合 (検知パリティ)。
     * INSERT race 後の再確認専用 (事前チェックは validation の rule が担う)。
     *
     * @phpstan-impure
     */
    private function emailAlreadyRegistered(string $email): bool
    {
        return User::whereBlind('email', 'email_index', $email)->exists();
    }

    /**
     * 既存 email 衝突: 手段を開示しない中立メッセージで 422 を返す。
     *
     * @throws ValidationException
     */
    private function rejectExistingEmail(): never
    {
        throw ValidationException::withMessages([
            'email' => ['このメールアドレスではアカウントを作成できません。'],
        ]);
    }
}
### app/Http/Responses/Fortify/RegisterResponse.php (全文)
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Support\Auth\EmailVerificationContinuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Webmozart\Assert\Assert;

/**
 * 登録直後のレスポンス (Fortify contract bind)。
 *
 * Fortify 標準は config('fortify.home') (= /dashboard) へ intended redirect するが、
 * dashboard は `verified` middleware で結局 verification.notice へ弾かれる。
 * 登録直後にメール認証を促す導線を明確にするため、未認証ユーザーが必ず到達できる
 * verification.notice (「認証してください」画面) へ直接誘導する。
 * XHR(201) は Fortify 標準と同じ後方互換を維持する。
 *
 * P7 の追加責務 (session 副作用のみ。初期組織の生成は CreateNewUser の tx 内で完結済み):
 *   - 通常登録: pending のプラン意図を**自分が Owner の初期組織**へ promote し、
 *     verify ソフトゲートの継続導線 (EmailVerificationContinuation) にその組織 id を保持する。
 *   - 招待受諾成立 (= Owner の組織を持たない): 料金表由来の pending を forget し、
 *     継続導線も張らない (招待組織へ参加するだけのユーザーに契約導線を出さない)。
 * session 副作用は XHR (201) 経路でも同じく先に実行してから応答を返す。
 */
final class RegisterResponse implements RegisterResponseContract
{
    public function __construct(
        private readonly IntendedPlanResolver $intendedPlanResolver,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 招待受諾は CreateNewUser の tx 内で完了しており、成立時は初期組織を作らない。
        // ★種別フラグ (旧 `is_personal`) は撤去済み (家系裁定 AG-038) なので、
        //   「所属組織の有無」では判定できない — 招待経由の利用者も所属組織を 1 件持つ。
        //   判定軸は **その利用者が Owner の組織かどうか**である。初期組織は必ず本人が Owner で、
        //   招待は Owner を与えないため、料金表由来のプラン意図を他人の組織へ移送してしまう
        //   経路が構造的に消える。
        $initialOrganization = $user->organizations()->orderBy('organizations.id')->get()
            ->first(static fn (Organization $organization): bool => $user->organizationRole($organization) === OrganizationRole::Owner);

        if ($initialOrganization instanceof Organization) {
            // pending → org-scoped へ移送 (pending は必ず forget で消費される)。
            $this->intendedPlanResolver->promotePendingToOrganization($initialOrganization);
            // 生 URL ではなく組織 id のみ保持する (参照時に membership 確認 + route 再構築)。
            EmailVerificationContinuation::remember($request->session(), $initialOrganization->id);
        } else {
            // 招待経由: 料金表由来の pending が残っていても消費しない (stale 防止)。
            $this->intendedPlanResolver->forgetPending();
        }

        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        return redirect()->route('verification.notice');
    }
}
### app/Rules/MatchesInvitationEmail.php (全文)
<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\OrganizationInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 登録フォームの email が session に保管された招待 token の招待先 email と一致するかを検証する
 * (spirux の MatchesInvitationEmail 相当)。
 *
 * - session に invitation_token が無い / 不正型 / 空文字 → pass (通常登録)
 * - token が DB に存在しない / 失効 / 受諾済み / 取り消し済み → pass
 *   (validation 経路では弾かず、CreateNewUser の受諾処理が中立メッセージで処理する責務分離)
 * - email 不一致のみ validation failure を返す
 *
 * constructor は session 値を `mixed` で受け取り内部で fail-secure に narrow する。
 */
class MatchesInvitationEmail implements ValidationRule
{
    public function __construct(private readonly mixed $invitationToken) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($this->invitationToken) || $this->invitationToken === '') {
            return;
        }
        if (! is_string($value)) {
            return;
        }

        // 平文 token は DB 非保存。active 判定は findActiveByPlainToken に集約 (単一解決口)。
        // 不在/失効/受諾済/取り消しはここでは弾かず、後段の受諾処理が中立メッセージで扱う。
        $invitation = OrganizationInvitation::findActiveByPlainToken($this->invitationToken);
        if ($invitation === null) {
            return;
        }

        if (! $invitation->isAddressedToEmail($value)) {
            $fail('招待されたメールアドレスと一致しません。招待メール記載のアドレスをご確認ください。');
        }
    }
}
### app/Support/Auth/EmailVerificationContinuation.php (全文 — 家風の参照)
<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * 登録 → verify notice → **認証完了後**に checkout へ着地させる継続導線。
 *
 * 生 URL を session に持たず organization_id のみ保持し、参照時に route を再構築 +
 * membership 確認する (URL 直保持はルート変更・値汚染に脆い)。所属確認 (relation 経由
 * fetch、IDOR 防御規約) を通らない値は null = 継続なし。
 * 寿命: remember (登録時) → forget (verify 完了時)。
 *
 * URL を実際に使うのは `VerifyEmailResponse` (認証完了後の着地) だけである。
 * verify notice 画面へは URL を渡さない — `onboarding.checkout` は ['auth','verified']
 * 配下にあり、未認証で遷移させると必ず差し戻されるため (bug-hunt F-2-01)。
 * 画面へは `hasContinuation()` の bool だけを渡す。
 *
 * onboarding route は**組織 URL 配下**にある (家系裁定 AG-037) ので、session に保持した
 * organization_id は「その組織のメンバーであること」の確認と、**URL の再構築**の
 * 両方に使う (所属確認を通った組織の識別名だけが URL に載る)。
 */
final class EmailVerificationContinuation
{
    private const string SESSION_KEY = 'verify_continue_organization_id';

    public static function remember(Session $session, int $organizationId): void
    {
        $session->put(self::SESSION_KEY, $organizationId);
    }

    /**
     * session の organization_id から checkout URL を再構築する。
     * 所属確認を通らない値・非 int・null user は null (= 導線を出さない)。
     */
    public static function resolveUrl(?User $user, Session $session): ?string
    {
        if ($user === null) {
            return null;
        }

        $organizationId = $session->get(self::SESSION_KEY);
        if (! is_int($organizationId)) {
            return null;
        }

        $organization = $user->organizations()->whereKey($organizationId)->first();
        if ($organization === null) {
            return null;
        }

        return route('onboarding.checkout', ['organization' => $organization->slug]);
    }

    /**
     * 継続導線が実在するか (URL を露出せず有無だけを返す)。
     * membership 確認の単一出典を保つため resolveUrl() へ委譲する。
     * 「どの画面へ進むか」という UI 語彙はここに持ち込まない (呼び出し側が写像する)。
     */
    public static function hasContinuation(?User $user, Session $session): bool
    {
        return self::resolveUrl($user, $session) !== null;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
### app/Services/Organization/OrganizationMembershipService.php (抜粋 L38-260: 招待系)
/**
 * 組織メンバーシップ操作の唯一の窓口 (招待 / 受諾 / ロール変更 / 削除 / オーナー移譲)。
 *
 * - ロール操作は必ず laratrust_team_id を明示する (strict_check=true)
 * - 招待 token は平文を保存せず sha256 (token_hash) のみ。平文はメールにだけ載る
 * - 既存メンバー / 既存招待への再招待はアカウント列挙対策の中立メッセージで拒否する
 */
class OrganizationMembershipService
{
    /** 招待の有効期限 (日) */
    private const EXPIRES_DAYS = 7;

    /**
     * 移譲先が組織メンバーでないときの文言。Controller の org 相対解決と
     * ロック下の再検証が**同一文言**であることが存在オラクル不成立の条件なので、
     * 文字列リテラルを 2 箇所に置かない (aicue:T118)。
     */
    public const MEMBER_REQUIRED_MESSAGE = '移譲先は組織のメンバーである必要があります。';

    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly DefaultProjectResolver $defaultProjects,
        private readonly NotificationCenterService $notifications,
        private readonly AccountDeletionBillingGuard $billingGuard,
        private readonly OrganizationAccessRevoker $accessRevoker,
    ) {}

    /**
     * メンバー招待。招待レコード生成 + 受諾 URL 付きメール送信。
     * ロールは**組織ロール 2 値 (管理者 / メンバー)**。Owner は招待で付与できない
     * (Owner 昇格は transferOwnership のみという不変条件の型表現)。
     * 編集者 / 撮影者 (Default Project の pivot ロール) は参加後に applyConsoleRole で割り当てる
     * (裁定 AG-079 で役割付き招待を撤去したため)。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ)
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
    {
        // Owner は FormRequest の Rule::enum(...)->except() で構造的に弾かれるが、
        // Service を直接呼ぶ経路 (テスト・将来のバッチ) でも不変条件を守る
        Assert::notSame($role, OrganizationRole::Owner, 'Owner は招待で付与できない');

        if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
            // 既存メンバーか既存招待かを開示しない中立メッセージ (アカウント列挙対策)
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには招待を送信できません。'],
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = new OrganizationInvitation(['email' => $email]);
        $invitation->organization()->associate($organization);
        $invitation->invitedBy()->associate($invitedBy);
        // role / token_hash / expires_at は明示代入 (mass-assignment させない)
        $invitation->forceFill([
            'role' => $role->value,
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
        ]);
        $invitation->save();

        // 受諾はログイン必須 (auth ミドルウェア) のため署名なし URL でよい。平文 token は保存しない
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(
            organizationName: $organization->name,
            acceptUrl: url('/invitations/accept?token='.$plainToken),
        ));

        // 既存ユーザーが宛先ならアプリ内でも気づけるようにする (メールの補完。平文 token は含めない)
        $this->notifications->notifyInvitationReceived($invitation);

        return $invitation;
    }

    /**
     * 招待受諾。ログイン中ユーザーが受諾する。
     * **受諾者の email は招待の宛先 email と一致しなければならない**。register 経路
     * (acceptInvitationIfValid) / アプリ内受諾 (acceptPendingInvitation) と同じ email 境界を
     * token POST 経路にも適用する。email 同一性規則は acceptInvitationIfValid と同一
     * (CipherSweet 復号後平文の厳密比較)。最終権威は joinOrganization のロック下再照合。
     *
     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 宛先 email 不一致 / 既メンバー
     */
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        // 取り消し済みは「無効」と区別しない (取り消された事実を token 保持者に開示しない)
        if ($invitation === null || $invitation->isRevoked()) {
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages(['token' => ['この招待は既に使用されています。']]);
        }
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
        }

        // 宛先 email の早期照合 (UX 用の明示メッセージ + 高速拒否)。生存判定 (取消/受諾済/失効) の後・
        // 既メンバー判定の前に置き、どの分岐も join より前 = 状態を一切変えずに拒否する。
        // 権威はロック下再照合 (joinOrganization) 側で、規則は OrganizationInvitation::isAddressedTo に集約。
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

        $role = OrganizationRole::from($invitation->role);

        if (! $this->joinOrganization($invitation, $organization, $user, $role)) {
            // ロック下再検証で受諾不能になった (並行受諾 / 取り消し / 期限到来)。
            // 事前検証と同じ中立メッセージへ畳む (取り消された事実を token 保持者に開示しない)
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }

        return $organization;
    }

    /**
     * 登録 (register) 経路の招待受諾。CreateNewUser から呼ぶ。
     *
     * acceptInvitation (ログイン後経路) と異なり、失敗しても例外を投げず null を返す
     * (登録そのものは成功させ、呼び出し側が個人組織へ fallback するため)。register 経路は
     * 招待 email と登録 email の一致を要求する (MatchesInvitationEmail rule と対で二重防御)。
     *
     * ★組織文脈は URL だけで決まる (家系裁定 AG-037) ので、受諾は**どこにも状態を保存しない**。
     *   受諾後にどの組織の URL へ着地するかは呼び出し側が返り値から決める。
     *
     * @return Organization|null 参加した組織 / 招待が受諾不能 (不在・失効・受諾済・取消・
     *                           email 不一致・既メンバー) なら null
     */
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        // active (未受諾・未失効・期限内) 解決は findActiveByPlainToken に集約 (単一解決口)。
        $invitation = OrganizationInvitation::findActiveByPlainToken($plainToken);
        if ($invitation === null) {
            return null;
        }

        // 招待 email と登録 email が一致しない場合は join しない
        // (email 同一性規則は OrganizationInvitation::isAddressedTo に集約)
        if (! $invitation->isAddressedTo($user)) {
            return null;
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 既メンバー (race 等) は個人組織へ fallback
        if ($organization->users()->whereKey($user->getKey())->exists()) {
            return null;
        }

        if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
            return null;
        }

        return $organization;
    }

    /**
     * register 画面のメール prefill 用に、session の invitation_token から
     * 「active な招待の招待先 email」を解決する。fail-secure:
     *  - session 値が非文字列/空 → forget して null
     *  - findActiveByPlainToken が null (不在/失効/取消/受諾済) → session から forget して null
     *    (GET 時点で stale/invalid な token を破棄し「UI は通常登録・サーバは招待フロー」の
     *    不整合を除去する)
     *  - active → 招待先 email (CipherSweet 自動復号後は string) を返す
     *
     * 平文 email 検索は行わない (token_hash 照合のみ)。列挙面を広げない。
     * 正常系 (active) では forget しない: 後続 POST の CreateNewUser が受諾に token を使う。
     *
     * **戻り契約**: 非 null を返す場合は必ず非空の email 文字列である (空文字は null に潰す)。
     * 呼び出し側 (Fortify registerView の no-store 判定 / frontend の isInvited) はこの契約に依存する。
     */
    public function resolveRegisterPrefillEmail(Session $session): ?string
    {
        $raw = $session->get('invitation_token');

        if (! is_string($raw) || $raw === '') {
            if ($raw !== null) {
                $session->forget('invitation_token'); // 汚染値を除去
            }

            return null;
        }

        $invitation = OrganizationInvitation::findActiveByPlainToken($raw);
        if ($invitation === null) {
            $session->forget('invitation_token'); // stale/invalid を GET 時点で破棄

            return null;
        }

        // CipherSweet 復号後の email。空文字 (想定外の欠損) は fail-secure に握り、
        // token を破棄して null 返却する (prefill しない)。
        $email = $invitation->email;
        if ($email === '') {
            $session->forget('invitation_token');

            return null;
        }

        return $email;
    }

    /**
     * 招待の取り消し (論理失効)。行削除ではなく revoked_at を立てる (監査痕跡を残す)。
     * 既に失効/受諾済みなら冪等 no-op (二重取り消しを例外にしない)。
     */
    public function revokeInvitation(OrganizationInvitation $invitation): void
    {
        if ($invitation->isRevoked() || $invitation->isAccepted()) {
            return;
### 同 (抜粋 L383-448: joinOrganization)
    /**
     * 招待受諾の確定処理 (attach + ロール付与 + accepted_at)。全受諾経路の共通コア。
     * accepted_at は $fillable 外のため forceFill で明示代入する。
     *
     * 並行受諾への防御は 2 層:
     * 1. **招待行の lockForUpdate**: 同一招待 (同一トークン二重送信) の並行受諾を直列化し、
     *    accepted_at / revoked_at / expires_at の判定をロック下で再実行する (TOCTOU 封じ。
     *    呼び出し元の事前検証は第 1 層として維持)
     * 2. **organization_user の原子的 INSERT (insertOrIgnore)**: 別招待経由の並行 join
     *    (同一 user × 同一 org) でも unique 違反にならず、勝った側だけが role/pivot を付与する
     *    (affected rows = 0 なら join 済みと判断してスキップ)。値はすべてサーバ側モデル由来
     *    (organization/user は relation 解決済み) で、payload 不信の保護キー規約に反しない。
     *    organization_user は (organization_id, user_id) UNIQUE + timestamps のみの pivot。
     *
     * 招待は「組織に入れる」ことだけを意味する (役割付き招待は裁定 AG-079 で撤去)。
     * 編集者 / 撮影者の割当は参加後に applyConsoleRole で行う。
     *
     * @return bool true = ロック下再検証を通り変換が完了した (既 join の冪等 no-op を含む) /
     *              false = ロック下で受諾不能 (受諾済 / 取消済 / 期限切れ) だった。
     *              **false は全呼び出し元が必ず消費する** (成功扱いで返さない)。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): bool
    {
        return DB::transaction(function () use ($organization, $user, $role, $invitation): bool {
            // canonical 共通ロック境界 (users 昇順 → organizations)。並行メンバー追加を
            // deleteAccount 等と直列化する (招待行ロックの手前で org/user 行ロックを取る)。
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return false; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は受諾不能)
            }

            // 1b. 宛先 email のロック下再照合 (最終権威。受諾中の email 変更 TOCTOU / stale user を封じる)。
            //     ロック**読み**した User インスタンスで照合する ($user->fresh() は非ロック SELECT で
            //     MVCC スナップショット版を返しうるため使わない)。users 行は lockForMembershipWrite が
            //     canonical 順序で既にロック済みのため、同一行の lockForUpdate 再取得は no-op re-acquire
            //     (新しいロック順序を作らない = デッドロックを導入しない。上の $locked 招待行 reload と同じ流儀)。
            //     3 経路 (token / register / in-app) 共通コアに入るため全経路がロック下 email 境界を得る
            //     (register / in-app は元から pre-lock で email 一致を保証済みのため挙動は不変)。
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isAddressedTo($lockedUser)) {
                return false; // 宛先不一致は受諾不能へ畳む (既存の false 契約と同じ neutral 扱い)
            }

            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role は変更しない。
            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);
            }

            $locked->forceFill(['accepted_at' => now()])->save();

            return true;
        });
    }
### 同 (抜粋 L940-971: lockForMembershipWrite / keyOf)
    /**
     * メンバーシップ書き込みの共通ロック境界。canonical 順序で行ロックを取り、
     * デッドロックを構造的に排除する: **users(id 昇順) → organizations(id 昇順)**。
     * ロック取得後は呼び出し側が最新状態を DB から再取得して判定すること (事前取得値を信用しない)。
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $organizationIds
     */
    private function lockForMembershipWrite(array $userIds, array $organizationIds): void
    {
        $sortedUserIds = collect($userIds)->unique()->sort()->values()->all();
        if ($sortedUserIds !== []) {
            DB::table('users')->whereIn('id', $sortedUserIds)->orderBy('id')->lockForUpdate()->get();
        }
        $sortedOrgIds = collect($organizationIds)->unique()->sort()->values()->all();
        if ($sortedOrgIds !== []) {
            DB::table('organizations')->whereIn('id', $sortedOrgIds)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * モデルの主キーを int として取得する (getKey() の mixed を PHPStan L10 で narrowing)。
     * 本アプリのメンバーシップ関連モデル (User / Organization) は bigint auto-increment 主キー。
     */
    private function keyOf(Model $model): int
    {
        $key = $model->getKey();
        Assert::integer($key);

        return $key;
    }

### app/Providers/FortifyServiceProvider.php (抜粋 L462-492: registerView)

        Fortify::registerView(static function (Request $request): SymfonyResponse {
            // 招待リンク経由 (session に active token) の場合のみ招待先 email を prefill 用に解決する。
            // resolver 内で stale/invalid token は session から破棄される (fail-secure)。
            $invitationEmail = app(OrganizationMembershipService::class)
                ->resolveRegisterPrefillEmail($request->session());

            $response = Inertia::render('Auth/Register', [
                'socialProviders' => array_keys(config()->array('template.social_providers')),
                'invitationEmail' => $invitationEmail,
                // 料金表 → /register?plan={code} のプラン意図。ユーザー入力のため
                // resolver の allowlist 照合に一本化する (Provider 側で分岐を書かない)。
                // 未知値 / 配列 / Enterprise はすべて null (= 意図なし) に倒れる。
                'intendedPlan' => IntendedPlanResolver::normalizeRaw($request->query('plan'))?->value,
            ])->toResponse($request);

            // PII (招待先 email) を含む応答を HTTP キャッシュ (共有/中間プロキシ/ブラウザの
            // HTTP キャッシュ) に保存させない (bearer token 由来 PII の運用 fail-safe)。
            // email を含まない通常登録応答には付けない (不要なキャッシュ抑止を避ける)。
            // 「PII 実在 = 非空 email 文字列」で判定する (resolver 契約と frontend の isInvited
            //  = invitationEmail != null && !== "" に揃え、null 判定だけの暗黙契約に依存しない)。
            if ($invitationEmail !== null && $invitationEmail !== '') {
                $response->headers->set('Cache-Control', 'no-store');
            }

            return $response;
        });

        Fortify::requestPasswordResetLinkView(
            static fn (): InertiaResponse => Inertia::render('Auth/ForgotPassword'),
        );
