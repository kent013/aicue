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

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

【レビュー役割】
あなたは Laravel 12 + Svelte 5 (Inertia) アプリのコードレビュアーである。TODO aicue:T263「招待フローを lctl 正典 v1 へ追従 (i7/i11/i14/i16)」の実装差分をレビューせよ。

レビュー観点:
1. 詳細設計との一致性 (設計が Round 3 APPROVED の正本。逸脱があれば指摘)
2. 正確性 (ロジックバグ、TOCTOU、null 安全、存在オラクル/情報漏えい面の後退)
3. PHPStan level 10 適合性 (widen・ignore の混入がないか)
4. DTO / JsonResource / Inertia パターン準拠 (response()->json() 直書き禁止)
5. テスト網羅性 (テストファースト、負例・fail-closed、既存テストの削除・弱体化がないか)
6. セキュリティ不変条件 (テナント境界、存在オラクル排除、ロック下再検証、後方互換の並走を残さない)
7. 走査器・gate の共通規約 (fail-closed、負例で裏取り、保証範囲の docblock)
8. DESIGN.md 準拠 / Atomic Design 準拠 (本差分はフロントエンド変更なし — resources/ への変更が混入していないかの確認のみ)

出力形式:
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明記する

---

## 詳細設計書 (Round 3 APPROVED — 正本)

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


## 実装差分 (git diff HEAD -- app/ tests/)

```diff
diff --git a/app/Actions/Fortify/CreateNewUser.php b/app/Actions/Fortify/CreateNewUser.php
index 2390a802..5fd5f0bc 100644
--- a/app/Actions/Fortify/CreateNewUser.php
+++ b/app/Actions/Fortify/CreateNewUser.php
@@ -10,6 +10,7 @@
 use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Services\Organization\OrganizationProvisioningService;
+use App\Support\Auth\InvitationContinuation;
 use App\Support\Legal\LegalConsent;
 use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Support\Facades\DB;
@@ -29,10 +30,13 @@
  *   押下時にこのエラーを表示する (DESIGN.md §Do's and Don'ts)。
  * - 同意の証跡 (terms_accepted_at / consent_version) は $fillable 外のため forceFill で
  *   初回 INSERT 時点で記録する。
- * - 招待 (organization invitation) 経由の登録は、session の invitation_token を fail-secure に
- *   解決し、招待 email との一致を MatchesInvitationEmail rule で検証する。受諾可能なら本
- *   transaction 内で招待組織へ参加し、個人組織の自動生成はスキップする (招待組織を主所属に
- *   する)。受諾不能 (失効/取消/受諾済/不一致/既メンバー) なら個人組織生成へ fallback する。
+ * - 招待 (organization invitation) 経由の登録は、招待の継続 (InvitationContinuation) を
+ *   fail-secure に解決し、招待 email との一致を MatchesInvitationEmail rule で検証する。
+ *   受諾可能なら本 transaction 内で招待組織へ参加し、個人組織の自動生成はスキップする
+ *   (招待組織を主所属にする)。join 成立時は同一 tx 内で email_verified_at を付与する
+ *   (正典 v1 i16 — 招待メール URL の所持 = 受信箱の所有の証明)。
+ *   受諾不能 (失効/取消/受諾済/組織論理削除/不一致/既メンバー) なら個人組織生成へ
+ *   fallback し、verified は付与しない。
  * - 料金表由来のプラン意図 (`intended_plan`) は validation rules に足さない (無効値でも登録は
  *   通す = 422 で止めない)。値は IntendedPlanResolver が PlanCode allowlist に照合し、
  *   不在 / 無効 / 改ざんはすべて pending forget に倒す (stale pending の誤 promote 防止)。
@@ -51,8 +55,12 @@ public function __construct(
      */
     public function create(array $input): User
     {
-        // session の招待 token を fail-secure に解決 (未ログインの招待リンク経由で保存される)
-        $invitationToken = $this->resolveInvitationToken();
+        // 処理中の HTTP リクエストに紐づく session を 1 回だけ取得し、resolve と forget に
+        // 同じインスタンスを渡す (CreateNewUser は Fortify の RegisteredUserController からのみ
+        // HTTP 文脈で呼ばれる。session 未起動なら framework が例外を投げる = fail-fast)。
+        // session の招待 token の解決 (型衛生 + 汚染値破棄) は継続クラスに集約する (正典 v1 i11)
+        $session = request()->session();
+        $invitationToken = InvitationContinuation::resolve($session);
 
         $validated = Validator::make($input, [
             'name' => ['required', 'string', 'max:255'],
@@ -112,6 +120,17 @@ public function create(array $input): User
                     // その経路の同一 tx に閉じている。**marker 設定だけをここに残してはならない**
                     // (付与されない marker 済み org = 永久に付与を受けられない org になる)。
                     $this->provisioning->provisionInitialOrganization($user);
+                } else {
+                    // 招待経由の登録は email 確認済みとして作成する (正典 v1 i16 / 裁定 AG-214)。
+                    // join 成立 = 有効招待 + 宛先一致のロック下再照合を通過 = 招待メール URL の所持
+                    // = 受信箱の所有の証明。前提 (i13) は MatchesInvitationEmail rule +
+                    // acceptInvitationIfValid の事前照合 + joinOrganization のロック下再照合の三重。
+                    // 同一 tx 内で立てるため、Fortify の Registered event (create() return 後に発火) の
+                    // SendEmailVerificationNotification は hasVerifiedEmail() を見て確認メールを送らない。
+                    // Illuminate\Auth\Events\Verified は発火しない — あの event の意味論は
+                    // 「確認フローを完了した」であり登録時付与とは別 (framework の markEmailAsVerified()
+                    // 自体も event を発火しない)。aicue に Verified の listener は存在しない (2026-08-25 実測)。
+                    $user->forceFill(['email_verified_at' => now()])->save();
                 }
 
                 return $user;
@@ -126,37 +145,14 @@ public function create(array $input): User
             throw $e; // email 起因でない unique 違反は握り潰さず再送
         }
 
-        // 登録が確定したので招待 token を session から落とす (terminal)
+        // 登録が確定したので招待 token を継続から落とす (terminal — 正典 v1 i14)
         if ($invitationToken !== null) {
-            session()->forget('invitation_token');
+            InvitationContinuation::forget($session);
         }
 
         return $user;
     }
 
-    /**
-     * session の `invitation_token` を fail-secure に取得する。
-     *
-     * session には任意の型が入りうるため、`is_string && !== ''` を満たさないものは不正値として
-     * forget し null を返す (未ログインの招待リンク経路が put する。汚染された値で登録経路の
-     * 型契約を壊さない)。
-     */
-    private function resolveInvitationToken(): ?string
-    {
-        $session = session();
-        $raw = $session->get('invitation_token');
-
-        if (is_string($raw) && $raw !== '') {
-            return $raw;
-        }
-
-        if ($raw !== null) {
-            $session->forget('invitation_token');
-        }
-
-        return null;
-    }
-
     /**
      * UniqueEncryptedEmail rule と同一の blind index 照合 (検知パリティ)。
      * INSERT race 後の再確認専用 (事前チェックは validation の rule が担う)。
diff --git a/app/Http/Controllers/Organizations/InvitationAcceptanceController.php b/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
index d9caac3a..8d86ff6f 100644
--- a/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
+++ b/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
@@ -9,6 +9,7 @@
 use App\Models\OrganizationInvitation;
 use App\Models\User;
 use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Auth\InvitationContinuation;
 use App\Support\Seo\SeoManager;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
@@ -29,7 +30,7 @@ class InvitationAcceptanceController extends Controller
      * 受諾確認画面 (GET, guest 可)。
      *
      * - token 欠落 (URL に token param 自体が無い) は 404
-     * - 無効招待 (不在/取消済/受諾済/期限切れ) は理由を出し分けず組織名も出さない専用ページ
+     * - 無効招待 (不在/取消済/受諾済/期限切れ/招待元組織の論理削除) は理由を出し分けず組織名も出さない専用ページ
      *   (Invitations/Invalid) を返す。どの無効理由でも同一画面にすることで token オラクルを防ぐ
      *   (未認証の URL 探索で「組織が実在し招待が取り消された」等を識別させない)
      * - 未ログイン + 有効招待: token を session に fail-secure 保存し register へ誘導する
@@ -46,12 +47,14 @@ public function show(Request $request, SeoManager $seo): Response|RedirectRespon
         $token = $request->query('token');
         abort_unless(is_string($token) && $token !== '', 404);
 
-        $invitation = OrganizationInvitation::query()
-            ->where('token_hash', hash('sha256', $token))
-            ->first();
-
-        // 無効招待は理由非開示の専用ページへ (guest / auth 共通)
-        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
+        // 無効招待は理由非開示の専用ページへ (guest / auth 共通)。解決は findActiveByPlainToken
+        // (単一解決口) へ寄せる — 手書きの hash・状態条件の重複が消え、招待元組織の論理削除
+        // (whereHas('organization')) も同じ 1 本で畳まれる (正典 v1 i7)。
+        // ★guest 分岐より前で畳む: 後ろに置くと guest では token が session に入り、
+        //   register の prefill に宛先が出た上で登録 POST が失敗する二段障害になる。
+        // ★organization null の再判定は解決〜描画の間の削除 race の防御 (通常は到達しない)。
+        $invitation = OrganizationInvitation::findActiveByPlainToken($token);
+        if ($invitation === null || $invitation->organization === null) {
             // タブ title は h1「この招待リンクは使用できません」から指示語「この」を落とした形。
             // SeoTitle::compose が ` | {サイト名}` を付けるため、タブ幅を圧迫しない範囲で見出しと揃える
             // (config/seo.php の「h1 と一致させる」規約に対する意図的な短縮。
@@ -61,9 +64,10 @@ public function show(Request $request, SeoManager $seo): Response|RedirectRespon
             return Inertia::render('Invitations/Invalid');
         }
 
-        // 未ログイン: token を session に保存して register へ誘導 (受諾は登録完了後)
+        // 未ログイン: token を継続 (InvitationContinuation) に覚えさせて register へ誘導
+        // (受諾は登録完了後。session の鍵は継続クラスに閉じる — 正典 v1 i11)
         if (! $request->user() instanceof User) {
-            $request->session()->put('invitation_token', $token);
+            InvitationContinuation::remember($request->session(), $token);
 
             return redirect()->route('register');
         }
diff --git a/app/Http/Responses/Fortify/RegisterResponse.php b/app/Http/Responses/Fortify/RegisterResponse.php
index cb8a6c24..5fb30622 100644
--- a/app/Http/Responses/Fortify/RegisterResponse.php
+++ b/app/Http/Responses/Fortify/RegisterResponse.php
@@ -16,13 +16,14 @@
 use Webmozart\Assert\Assert;
 
 /**
- * 登録直後のレスポンス (Fortify contract bind)。
+ * 登録直後のレスポンス (Fortify contract bind)。着地は 3 通り:
  *
- * Fortify 標準は config('fortify.home') (= /dashboard) へ intended redirect するが、
- * dashboard は `verified` middleware で結局 verification.notice へ弾かれる。
- * 登録直後にメール認証を促す導線を明確にするため、未認証ユーザーが必ず到達できる
- * verification.notice (「認証してください」画面) へ直接誘導する。
- * XHR(201) は Fortify 標準と同じ後方互換を維持する。
+ *  - unverified 登録 (通常登録・招待 fallback) → verification.notice (「認証してください」画面)。
+ *    Fortify 標準は config('fortify.home') (= /dashboard) へ intended redirect するが、
+ *    dashboard は `verified` middleware で結局 verification.notice へ弾かれるため直接誘導する
+ *  - 招待成立で verified 済みの登録 (正典 v1 i16) → app.entry (認証促し画面を経由させず
+ *    組織解決の正規入口へ決定論的に送る。判定は hasVerifiedEmail() のみ)
+ *  - XHR (JSON) → 201 (Fortify 標準の後方互換。verified か否かで変えない)
  *
  * P7 の追加責務 (session 副作用のみ。初期組織の生成は CreateNewUser の tx 内で完結済み):
  *   - 通常登録: pending のプラン意図を**自分が Owner の初期組織**へ promote し、
@@ -65,7 +66,15 @@ public function toResponse($request): JsonResponse|RedirectResponse
         }
 
         if ($request->wantsJson()) {
-            return new JsonResponse('', 201);
+            return new JsonResponse('', 201); // XHR は Fortify 標準と同じ後方互換 (verified か否かで変えない)
+        }
+
+        // 招待経由 (i16) で verified 済みなら「認証してください」画面を経由させない。
+        // verification.notice へ送っても Fortify の prompt が fortify.home へ bounce するため
+        // 詰みはしないが、redirect()->intended() の stale URL に依存した着地になるのを避け、
+        // 組織解決の正規入口 (app.entry = /go) へ決定論的に送る。
+        if ($user->hasVerifiedEmail()) {
+            return redirect()->route('app.entry');
         }
 
         return redirect()->route('verification.notice');
diff --git a/app/Models/OrganizationInvitation.php b/app/Models/OrganizationInvitation.php
index 18ae5b9f..4c644894 100644
--- a/app/Models/OrganizationInvitation.php
+++ b/app/Models/OrganizationInvitation.php
@@ -62,11 +62,16 @@ public static function hashToken(string $plainToken): string
 
     /**
      * 平文 token から「受諾可能 (active: 未受諾・未失効・期限内)」な招待を解決する。
-     * token_hash 照合 + scopeActive のみ (平文 email 検索は行わない = 列挙面を広げない)。
-     * active でない (不在/失効/取消/受諾済) 場合は null。
+     * token_hash 照合 + scopeActive + 招待元組織の生存のみ (平文 email 検索は行わない =
+     * 列挙面を広げない)。active でない (不在/失効/取消/受諾済/組織論理削除) 場合は null。
      *
-     * MatchesInvitationEmail / acceptInvitationIfValid / register prefill resolver が共有し、
-     * active 判定条件のドリフトを防ぐ単一解決口。
+     * 招待元組織の生存 (SoftDeletes の default scope) も active の条件に含める
+     * (正典 v1 i7 — 論理削除済み組織宛は「active でない」へ畳む。scopeActivePendingForEmail の
+     * whereHas('organization') と同じ意味論)。scopeActive 自体は招待行の状態だけを表す scope の
+     * まま変えない (activePendingForEmail との条件重複を作らない)。
+     *
+     * MatchesInvitationEmail / acceptInvitationIfValid / register prefill resolver /
+     * InvitationAcceptanceController::show が共有し、active 判定条件のドリフトを防ぐ単一解決口。
      * (POST 受諾 acceptInvitation() は revoked/accepted/expired を個別メッセージに出し分けるため
      *  本メソッドを使わない)
      */
@@ -76,6 +81,7 @@ public static function findActiveByPlainToken(string $plainToken): ?self
         // isExpired()/isAccepted()/isRevoked() の個別判定と概念的に一致させ、ドリフトを防ぐ。
         return self::query()
             ->active()
+            ->whereHas('organization')
             ->where('token_hash', self::hashToken($plainToken))
             ->first();
     }
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index af5516b3..469bbdde 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -25,6 +25,7 @@
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
 use App\Support\Account\AccountDeletionGrace;
+use App\Support\Auth\InvitationContinuation;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Session\Session;
 use Illuminate\Database\Eloquent\Builder;
@@ -135,7 +136,15 @@ public function acceptInvitation(string $plainToken, User $user): Organization
             throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
         }
 
-        // 宛先 email の早期照合 (UX 用の明示メッセージ + 高速拒否)。生存判定 (取消/受諾済/失効) の後・
+        // 招待元組織の論理削除は「無効」へ畳む (正典 v1 i7)。不在・取消済みと同一の中立メッセージ
+        // (500 にしない / 理由の出し分けを増やさない)。宛先照合より前に置く —
+        // 消えた組織の招待で宛先一致の可否を教えない。最終権威は joinOrganization の 1c。
+        $organization = $invitation->organization;
+        if ($organization === null) {
+            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
+        }
+
+        // 宛先 email の早期照合 (UX 用の明示メッセージ + 高速拒否)。生存判定 (取消/受諾済/失効/組織削除) の後・
         // 既メンバー判定の前に置き、どの分岐も join より前 = 状態を一切変えずに拒否する。
         // 権威はロック下再照合 (joinOrganization) 側で、規則は OrganizationInvitation::isAddressedTo に集約。
         if (! $invitation->isAddressedTo($user)) {
@@ -144,9 +153,6 @@ public function acceptInvitation(string $plainToken, User $user): Organization
             ]);
         }
 
-        $organization = $invitation->organization;
-        Assert::isInstanceOf($organization, Organization::class);
-
         if ($organization->users()->whereKey($user->getKey())->exists()) {
             throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
         }
@@ -189,8 +195,13 @@ public function acceptInvitationIfValid(string $plainToken, User $user): ?Organi
             return null;
         }
 
+        // findActiveByPlainToken が組織生存 (whereHas) を含むため通常ここへは来ないが、
+        // 解決〜参照の間の論理削除 race を 500 にしない防御。null なら個人組織生成へ
+        // fallback する (登録そのものは成功させる)。
         $organization = $invitation->organization;
-        Assert::isInstanceOf($organization, Organization::class);
+        if ($organization === null) {
+            return null;
+        }
 
         // 既メンバー (race 等) は個人組織へ fallback
         if ($organization->users()->whereKey($user->getKey())->exists()) {
@@ -221,19 +232,15 @@ public function acceptInvitationIfValid(string $plainToken, User $user): ?Organi
      */
     public function resolveRegisterPrefillEmail(Session $session): ?string
     {
-        $raw = $session->get('invitation_token');
-
-        if (! is_string($raw) || $raw === '') {
-            if ($raw !== null) {
-                $session->forget('invitation_token'); // 汚染値を除去
-            }
-
+        // 型衛生 (非文字列/空の汚染値破棄) は継続クラスへ集約 (正典 v1 i11)
+        $token = InvitationContinuation::resolve($session);
+        if ($token === null) {
             return null;
         }
 
-        $invitation = OrganizationInvitation::findActiveByPlainToken($raw);
+        $invitation = OrganizationInvitation::findActiveByPlainToken($token);
         if ($invitation === null) {
-            $session->forget('invitation_token'); // stale/invalid を GET 時点で破棄
+            InvitationContinuation::forget($session); // stale/invalid を GET 時点で破棄 (terminal)
 
             return null;
         }
@@ -242,7 +249,7 @@ public function resolveRegisterPrefillEmail(Session $session): ?string
         // token を破棄して null 返却する (prefill しない)。
         $email = $invitation->email;
         if ($email === '') {
-            $session->forget('invitation_token');
+            InvitationContinuation::forget($session);
 
             return null;
         }
@@ -428,17 +435,33 @@ private function joinOrganization(OrganizationInvitation $invitation, Organizati
                 return false; // 宛先不一致は受諾不能へ畳む (既存の false 契約と同じ neutral 扱い)
             }
 
+            // 1c. 招待元組織の生存のロック下再検証 (正典 v1 i2/i7 の最終権威)。organizations 行は
+            //     冒頭の lockForMembershipWrite が canonical 順序で lockForUpdate 済みだが、
+            //     非ロックの SELECT は MVCC スナップショット版を返しうる (1b の $lockedUser と同じ理由)。
+            //     relation 起点の **lockForUpdate 読み**で最新版を取り直す — 取得済み行の再取得は
+            //     no-op re-acquire でロック順序も変わらない ($locked / $lockedUser と同じ流儀)。
+            //     SoftDeletes の default scope が論理削除済みを除外するため、削除済みなら null。
+            //     relation 起点なのでクラス起点の主キー同一性クエリを増やさない
+            //     (= DirectFetchInventory の母集団外)。
+            /** @var Organization|null $lockedOrganization */
+            $lockedOrganization = $locked->organization()->lockForUpdate()->first();
+            if ($lockedOrganization === null) {
+                return false; // 受諾不能へ畳む (全呼び出し元が false を消費する既存契約)
+            }
+
             // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role は変更しない。
-            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
+            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)。
+            //    書き込みは事前取得の $organization ではなくロック読みした $lockedOrganization を
+            //    権威として使う (同一行なので値は同じだが、権威の出所を 1 つにする)。
             $joined = DB::table('organization_user')->insertOrIgnore([
-                'organization_id' => $organization->id,
+                'organization_id' => $lockedOrganization->id,
                 'user_id' => $user->getKey(),
                 'created_at' => now(),
                 'updated_at' => now(),
             ]);
 
             if ($joined === 1) {
-                $user->addRole($role->value, $organization->laratrust_team_id);
+                $user->addRole($role->value, $lockedOrganization->laratrust_team_id);
             }
 
             $locked->forceFill(['accepted_at' => now()])->save();
diff --git a/app/Support/Auth/InvitationContinuation.php b/app/Support/Auth/InvitationContinuation.php
new file mode 100644
index 00000000..c130a4cd
--- /dev/null
+++ b/app/Support/Auth/InvitationContinuation.php
@@ -0,0 +1,66 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Auth;
+
+use Illuminate\Contracts\Session\Session;
+
+/**
+ * 招待を保持したまま認証を跨ぐときの**継続** (正典 v1 i11。参照実装は
+ * laravel-claude-template@5dd85a6 の同名クラス。形は隣接する
+ * EmailVerificationContinuation と同じ static + Session 引数)。
+ *
+ * 未ログインの招待リンク経路 (InvitationAcceptanceController::show) が覚えさせ、
+ * password 登録 (CreateNewUser) と register 画面の事前入力の解決
+ * (OrganizationMembershipService::resolveRegisterPrefillEmail) が拾う。
+ *
+ * ## 生の鍵をここ以外に書かない
+ * 鍵 literal はこのファイル 1 つに閉じ、InvitationContinuationKeySoTTest が機械で固定する。
+ * (従来は controller / 登録処理 / 会員サービスの 3 ファイルに生の鍵が散在していた)
+ *
+ * ## 型衛生
+ * session には任意の型が入りうるため、`is_string && !== ''` を満たさないものは
+ * 不正値として忘れさせて null を返す (汚染値で登録経路の型契約を壊さない)。
+ *
+ * ## 持たないもの
+ * 認証を抜けた後の着地 (テンプレートの landing()) は移植しない — aicue には継続を見て
+ * 着地を分岐する経路が現存しない (思考原則 2)。必要になったらテンプレートの形
+ * (token の有効性を見ずに受諾確認画面へ送る — 裁定 AG-113 (b)) で足すこと。
+ */
+final class InvitationContinuation
+{
+    /** session の鍵。生の文字列はこのファイルの外 (app/ 配下) に書かない (gate が固定する)。 */
+    private const string SESSION_KEY = 'invitation_token';
+
+    /** 招待リンクに到達した guest の token を覚えさせる。 */
+    public static function remember(Session $session, string $token): void
+    {
+        $session->put(self::SESSION_KEY, $token);
+    }
+
+    /** 型衛生つきの読み出し (非破壊)。不正値は忘れさせて null を返す。 */
+    public static function resolve(Session $session): ?string
+    {
+        $raw = $session->get(self::SESSION_KEY);
+
+        if (is_string($raw) && $raw !== '') {
+            return $raw;
+        }
+
+        if ($raw !== null) {
+            $session->forget(self::SESSION_KEY);
+        }
+
+        return null;
+    }
+
+    /**
+     * terminal 処理 (登録の確定 / stale・invalid 判明時の破棄) で token を落とす (i14)。
+     * email 不一致での再試行を許す経路 (validation 422) では呼ばないこと。
+     */
+    public static function forget(Session $session): void
+    {
+        $session->forget(self::SESSION_KEY);
+    }
+}
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
index 86b54347..e2326aeb 100644
--- a/tests/Architecture/AccountDeletionPathGateTest.php
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -197,6 +197,12 @@
     'App\Services\Project\DefaultProjectResolver',
     'App\Services\Security\SecurityEventRecorder',
     'App\Support\Account\AccountDeletionGrace',
+    // ↓ T263 (招待フローの正典 v1 追従) で閉包に入った 1 クラス。閉包はクラス粒度なので、
+    //   退会そのものが招待の継続を触らなくても、OrganizationMembershipService が
+    //   register prefill の解決 (resolveRegisterPrefillEmail) で参照した時点で入る。
+    //   session の鍵 1 つの読み書き (put/get/forget) しか行わず、
+    //   決済事業者 SDK への到達辺を持たない (検査 2 が機械的に固定する)。
+    'App\Support\Auth\InvitationContinuation',
 ];
 
 /**
diff --git a/tests/Architecture/InvitationContinuationKeySoTTest.php b/tests/Architecture/InvitationContinuationKeySoTTest.php
new file mode 100644
index 00000000..ec8b4a35
--- /dev/null
+++ b/tests/Architecture/InvitationContinuationKeySoTTest.php
@@ -0,0 +1,203 @@
+<?php
+
+declare(strict_types=1);
+
+/**
+ * 招待の継続が使う session の鍵 (`invitation_token`) の literal を 1 ファイルへ閉じる
+ * (正典 v1 i11 / aicue:T263 施策 B。テンプレート laravel-claude-template@5dd85a6 の
+ * 同名テストを移植し、判定と fail-closed を強化)。
+ *
+ * 従来は controller (`InvitationAcceptanceController`) / 登録処理 (`CreateNewUser`) /
+ * 会員サービス (`OrganizationMembershipService`) の 3 ファイルに**生の鍵文字列**が散在していた。
+ * 鍵の literal がどこに現れてよいかを機械で固定する。
+ *
+ * ## 走査対象と判定
+ *
+ *  - `app/` 配下の `*.php` 全数を `token_get_all($source, TOKEN_PARSE)` で走査し、
+ *    `T_CONSTANT_ENCAPSED_STRING` の**実行時値を復元**して `invitation_token` との
+ *    完全一致でファイルを列挙する (引用符の除去だけでは `"\x69nvitation_token"` のような
+ *    エスケープ表現をすり抜けられるため、二重引用符は stripcslashes()、単引用符は
+ *    `\\` と `\'` の 2 種を 1 パスで解いて復元する)
+ *  - コメント・DocComment 中の言及は数えない (説明文で書いた名前で赤くなると、
+ *    gate を黙らせるために説明を消す誘因が生まれる)
+ *
+ * ## fail-closed (AGENTS.md 走査器規約 (b))
+ *
+ *  - 走査根 `app/` が存在しなければ fail (RecursiveDirectoryIterator 任せにしない)
+ *  - `file_get_contents` が false のファイルは黙って continue せず fail
+ *  - TOKEN_PARSE により構文解析不能 (ParseError) は握らず fail
+ *  - 期待値は「ちょうど [SoT 1 ファイル]」の完全一致 (走査が空振りすれば [] ≠ [SoT] で赤 =
+ *    母集団の非空を判定が内包する) に加え、走査した PHP ファイル数が 0 でないことも独立に固定
+ *
+ * ## 保証しないこと
+ *
+ *  - **動的に組み立てた鍵** (連結 `'invitation'.'_token'` / 変数 / sprintf) は検出できない
+ *  - **`\u{}` unicode エスケープ表現** (stripcslashes は解かない) は検出できない
+ *  - **別名の鍵で同じ担体を作る形**は検出できない (鍵の名前を変えれば通る)。
+ *    これは「SoT の外に生の鍵 literal を書かない」という限定的な契約である
+ *  - **heredoc / nowdoc 本文** (T_ENCAPSED_AND_WHITESPACE 等) は検出できない
+ *  - **`tests/` 配下**は `withSession(['invitation_token' => ...])` で session を組む
+ *    正当な利用者なので対象外
+ */
+
+/** 鍵の literal を書いてよい唯一のファイル (`app/` からの相対パス)。 */
+const INVITATION_CONTINUATION_KEY_OWNER = 'Support/Auth/InvitationContinuation.php';
+
+/**
+ * PHP の文字列 literal トークン (T_CONSTANT_ENCAPSED_STRING の生テキスト) から実行時値を復元する。
+ *
+ * - 二重引用符: stripcslashes() (\x69 / \151 / \n 等を復元)
+ * - 単引用符: `\\` と `\'` の 2 種のみを **1 パス**で解く (PHP の単引用符の意味論どおり。
+ *   逐次 str_replace は `\\` と `\'` が隣接する入力で置換順により誤復元する)
+ * - binary 接頭辞 (b'…' / B"…") は値に影響しないため剥がす
+ *
+ * @throws RuntimeException 引用符を判別できない形 (解決できない形は落とす — fail-closed)
+ */
+function invitationContinuationRestoreLiteralValue(string $raw): string
+{
+    if ($raw !== '' && (str_starts_with($raw, 'b') || str_starts_with($raw, 'B'))) {
+        $raw = substr($raw, 1);
+    }
+    if (strlen($raw) < 2) {
+        throw new RuntimeException("文字列 literal として復元できない形です: {$raw}");
+    }
+
+    $quote = $raw[0];
+    $body = substr($raw, 1, -1);
+
+    if ($quote === "'") {
+        $restored = preg_replace_callback(
+            '/\\\\([\\\\\'])/',
+            static fn (array $matches): string => $matches[1],
+            $body,
+        );
+        if ($restored === null) {
+            throw new RuntimeException("単引用符 literal の復元に失敗しました: {$raw}");
+        }
+
+        return $restored;
+    }
+
+    if ($quote === '"') {
+        return stripcslashes($body);
+    }
+
+    throw new RuntimeException("引用符を判別できない literal です: {$raw}");
+}
+
+/**
+ * PHP ソース中で鍵 `invitation_token` を**文字列リテラルとして**書いている箇所を数える。
+ * 構文解析不能 (ParseError) は握らず呼び出し側へ伝播させる (fail-closed)。
+ */
+function invitationContinuationKeyLiteralHits(string $source): int
+{
+    $count = 0;
+    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
+        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if (invitationContinuationRestoreLiteralValue($token[1]) === 'invitation_token') {
+            $count++;
+        }
+    }
+
+    return $count;
+}
+
+/**
+ * `app/` 配下の *.php を走査し、鍵 literal を含むファイルの一覧と走査ファイル数を返す。
+ *
+ * @return array{files: list<string>, scanned: int} files は `app/` からの相対パス (昇順)
+ */
+function invitationContinuationKeyLiteralScan(): array
+{
+    $appRoot = dirname(__DIR__, 2).'/app';
+    if (! is_dir($appRoot)) {
+        throw new RuntimeException("走査根が存在しません: {$appRoot}");
+    }
+
+    $files = [];
+    $scanned = 0;
+
+    /** @var iterable<SplFileInfo> $iterator */
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
+    );
+
+    foreach ($iterator as $file) {
+        if ($file->getExtension() !== 'php') {
+            continue;
+        }
+        $source = file_get_contents($file->getPathname());
+        if ($source === false) {
+            // 黙って continue しない (見逃す方向へ倒さない)
+            throw new RuntimeException("読めないファイルがあります: {$file->getPathname()}");
+        }
+        $scanned++;
+
+        if (invitationContinuationKeyLiteralHits($source) > 0) {
+            $files[] = str_replace($appRoot.'/', '', $file->getPathname());
+        }
+    }
+
+    sort($files);
+
+    return ['files' => array_values(array_unique($files)), 'scanned' => $scanned];
+}
+
+test('IC-1: invitation_token の literal は継続クラス 1 ファイルにしか現れない', function (): void {
+    $scan = invitationContinuationKeyLiteralScan();
+
+    // 走査の空振り検査 (母集団の非空)。期待値の完全一致だけでも [] ≠ [SoT] で赤になるが、
+    // 「走査したファイル数が 0」という故障様態を独立に区別できるようにする
+    expect($scan['scanned'])->toBeGreaterThan(0);
+
+    expect($scan['files'])->toBe(
+        [INVITATION_CONTINUATION_KEY_OWNER],
+        '招待の継続の session の鍵が SoT の外に書かれています。'
+        .'App\\Support\\Auth\\InvitationContinuation の remember / resolve / forget を通してください。',
+    );
+});
+
+test('IC-2: 検出器の負例と正例 — コメント中の言及は数えず、literal は 3 形とも数える', function (): void {
+    // 負例: コメント / DocComment 中の言及は数えない
+    $commentOnly = "<?php\n// session の invitation_token を読む\n/** invitation_token */\n";
+    expect(invitationContinuationKeyLiteralHits($commentOnly))->toBe(0);
+
+    // 正例 1: 単引用符 literal
+    $singleQuoted = "<?php\n\$s->put('invitation_token', \$t);\n";
+    expect(invitationContinuationKeyLiteralHits($singleQuoted))->toBe(1);
+
+    // 正例 2: 二重引用符 literal
+    $doubleQuoted = "<?php\n\$s->put(\"invitation_token\", \$t);\n";
+    expect(invitationContinuationKeyLiteralHits($doubleQuoted))->toBe(1);
+
+    // 正例 3: \x エスケープ形 (引用符の除去だけの判定ではすり抜ける)
+    $escaped = "<?php\n\$s->put(\"\\x69nvitation_token\", \$t);\n";
+    expect(invitationContinuationKeyLiteralHits($escaped))->toBe(1);
+});
+
+test('IC-3: 単引用符復元器は `\\\\` と `\\\'` が隣接しても誤復元しない (置換順の罠の負例)', function (): void {
+    // 逐次 str_replace('\\\'', ...) → str_replace('\\\\', ...) のような実装は
+    // 隣接エスケープで壊れる。1 パス復元が PHP の意味論どおりであることを固定する
+    $raw = <<<'RAW'
+'\\\'invitation_token'
+RAW;
+    $expected = <<<'VALUE'
+\'invitation_token
+VALUE;
+    expect(invitationContinuationRestoreLiteralValue($raw))->toBe($expected);
+
+    // 復元値は鍵と一致しない (= 検出対象に数えない) ことも固定する
+    expect(invitationContinuationRestoreLiteralValue($raw))->not->toBe('invitation_token');
+});
+
+test('IC-4: fail-closed — 構文解析不能・復元不能な形は握らず fail する', function (): void {
+    // 構文解析不能 (TOKEN_PARSE): ParseError を握らない
+    expect(static fn (): int => invitationContinuationKeyLiteralHits('<?php class {'))
+        ->toThrow(ParseError::class);
+
+    // 引用符を判別できない生テキストは RuntimeException (黙って 0 扱いにしない)
+    expect(static fn (): string => invitationContinuationRestoreLiteralValue('`bad`'))
+        ->toThrow(RuntimeException::class);
+});
diff --git a/tests/Feature/Auth/InvitationRegistrationVerifiedTest.php b/tests/Feature/Auth/InvitationRegistrationVerifiedTest.php
new file mode 100644
index 00000000..cac01083
--- /dev/null
+++ b/tests/Feature/Auth/InvitationRegistrationVerifiedTest.php
@@ -0,0 +1,152 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use Illuminate\Auth\Notifications\VerifyEmail;
+use Illuminate\Support\Facades\Notification;
+use Illuminate\Testing\TestResponse;
+use Symfony\Component\HttpFoundation\Response;
+use Tests\TestCase;
+
+/*
+ * 招待経由登録への verified 付与と登録直後の着地 (正典 v1 i16 / 裁定 AG-214 / aicue:T263 施策 C)。
+ *
+ * join 成立 = 有効招待 + 宛先一致のロック下再照合を通過 = 招待メール URL の所持
+ * = 受信箱の所有の証明。よって招待成立の登録は email 確認済みで作成し、確認メールを送らず、
+ * 「認証してください」画面を経由させずに組織解決の正規入口 (app.entry) へ着地させる。
+ * 受諾不能 (取消・組織論理削除等) の fallback 登録には付与しない (i16 後段の fail-closed)。
+ */
+
+/**
+ * 指定 email 宛の active 招待を作り、平文 token とともに返す (factory ベース)。
+ *
+ * @return array{0: OrganizationInvitation, 1: string}
+ */
+function makeVerifiedFlowInvitation(Organization $organization, string $email): array
+{
+    return OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => $email]);
+}
+
+/**
+ * 招待 token を session に載せて register POST する。
+ *
+ * @return TestResponse<Response>
+ */
+function registerWithInvitationToken(TestCase $test, ?string $token, string $email, string $name = '招待 花子')
+{
+    $request = $token !== null ? $test->withSession(['invitation_token' => $token]) : $test;
+
+    return $request->post('/register', [
+        'name' => $name,
+        'email' => $email,
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ]);
+}
+
+test('招待経由登録の成立は verified で作成され確認メールを送らず app.entry へ着地する', function (): void {
+    Notification::fake();
+    [$organization] = createOrganizationWithOwner('招待組織');
+    [, $token] = makeVerifiedFlowInvitation($organization, 'verified@example.com');
+
+    $response = registerWithInvitationToken($this, $token, 'verified@example.com');
+
+    // 「認証してください」画面を経由させず、組織解決の正規入口へ決定論的に送る
+    $response->assertRedirect(route('app.entry'));
+
+    $user = User::whereBlind('email', 'email_index', 'verified@example.com')->firstOrFail();
+    expect($user->email_verified_at)->not->toBeNull();
+    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
+    // 同一 tx 内で verified を立てるため Registered event の
+    // SendEmailVerificationNotification は hasVerifiedEmail() を見て確認メールを送らない
+    Notification::assertNotSentTo($user, VerifyEmail::class);
+});
+
+test('着地チェーンを一段ずつ固定: 登録 POST → app.entry → 招待組織 dashboard → 200', function (): void {
+    // followRedirects では途中に verification.notice が挟まる経路も最終到達が同じなら
+    // 緑になり「経由しない」の根拠にならないため、redirect を自動追跡せず一段ずつ検査する
+    [$organization] = createOrganizationWithOwner('着地組織');
+    [, $token] = makeVerifiedFlowInvitation($organization, 'landing@example.com');
+
+    // 1. 登録 POST が app.entry へ redirect する
+    registerWithInvitationToken($this, $token, 'landing@example.com')
+        ->assertRedirect(route('app.entry'));
+
+    // 2. app.entry は招待組織 (唯一の所属) の dashboard へ直接 redirect する
+    $this->get(route('app.entry'))
+        ->assertRedirectToRoute('dashboard', ['organization' => $organization->slug]);
+
+    // 3. その dashboard は 200 (verified middleware を通過する)
+    $this->get(route('dashboard', ['organization' => $organization->slug]))
+        ->assertOk();
+});
+
+test('JSON (XHR) 後方互換: 招待成立の登録でも 201 のまま (membership と verified を併せて固定)', function (): void {
+    [$organization] = createOrganizationWithOwner('XHR 組織');
+    [, $token] = makeVerifiedFlowInvitation($organization, 'xhr@example.com');
+
+    $response = $this->withSession(['invitation_token' => $token])->postJson('/register', [
+        'name' => 'XHR 太郎',
+        'email' => 'xhr@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ]);
+
+    $response->assertCreated();
+
+    // 「未検証の通常登録が偶然 201」と区別する (偽グリーン防止)
+    $user = User::whereBlind('email', 'email_index', 'xhr@example.com')->firstOrFail();
+    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
+    expect($user->email_verified_at)->not->toBeNull();
+});
+
+test('fallback (取消済み token) は unverified のまま確認メールが送られ verification.notice へ', function (): void {
+    Notification::fake();
+    [$organization] = createOrganizationWithOwner('取消組織');
+    [$invitation, $token] = makeVerifiedFlowInvitation($organization, 'revoked@example.com');
+    $invitation->forceFill(['revoked_at' => now()])->save();
+
+    $response = registerWithInvitationToken($this, $token, 'revoked@example.com');
+
+    // 付与側 (app.entry) と対称に固定する: 受諾不能の fallback は従来どおり認証促し画面へ
+    $response->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'revoked@example.com')->firstOrFail();
+    expect($user->email_verified_at)->toBeNull();
+    expect($organization->users()->whereKey($user->getKey())->exists())->toBeFalse();
+    Notification::assertSentTo($user, VerifyEmail::class);
+});
+
+test('通常登録 (継続なし) は unverified のまま確認メールが送られる (対称の負例)', function (): void {
+    Notification::fake();
+
+    $response = registerWithInvitationToken($this, null, 'plain@example.com', '通常 太郎');
+
+    $response->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'plain@example.com')->firstOrFail();
+    expect($user->email_verified_at)->toBeNull();
+    Notification::assertSentTo($user, VerifyEmail::class);
+});
+
+test('論理削除組織の招待 token での登録は unverified の fallback (施策 A との結合 / i16 後段)', function (): void {
+    Notification::fake();
+    [$organization] = createOrganizationWithOwner('消えた組織');
+    [, $token] = makeVerifiedFlowInvitation($organization, 'gone@example.com');
+    $organization->delete();
+
+    $response = registerWithInvitationToken($this, $token, 'gone@example.com');
+
+    // 前提 (i13: join 成立) が成立しない登録に verified を与えない
+    $response->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'gone@example.com')->firstOrFail();
+    expect($user->email_verified_at)->toBeNull();
+    expect($user->organizations()->count())->toBe(1); // 個人組織 fallback
+    Notification::assertSentTo($user, VerifyEmail::class);
+});
diff --git a/tests/Feature/Auth/RegisterPlanHandoffTest.php b/tests/Feature/Auth/RegisterPlanHandoffTest.php
index ee7d1c95..248b4a1f 100644
--- a/tests/Feature/Auth/RegisterPlanHandoffTest.php
+++ b/tests/Feature/Auth/RegisterPlanHandoffTest.php
@@ -180,7 +180,8 @@
         'intended_plan' => 'starter',
     ]);
 
-    $response->assertRedirect(route('verification.notice'));
+    // 招待成立の登録は verified で作成されるため認証促し画面を経由せず app.entry へ (i16)
+    $response->assertRedirect(route('app.entry'));
 
     $user = User::query()->whereBlind('email', 'email_index', $email)->firstOrFail();
 
diff --git a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
index 57da5927..4340d5dc 100644
--- a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
+++ b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
@@ -116,6 +116,17 @@ function makeInvitationWithToken(string $email = 'invitee@example.com'): array
     $response->assertSessionMissing('invitation_token');
 });
 
+test('論理削除組織の token → invitationEmail null かつ forget (i7: 無効招待への同一畳み込み)', function (): void {
+    [, $token, , $organization] = makeInvitationWithToken('deleted-org@example.com');
+    $organization->delete();
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
 test('token 無し GET /register は invitationEmail null・socialProviders あり・no-store を付けない', function (): void {
     $response = $this->get('/register');
 
@@ -200,7 +211,8 @@ function makeInvitationWithToken(string $email = 'invitee@example.com'): array
         'intended_plan' => 'starter',
     ]);
 
-    $response->assertRedirect(route('verification.notice'));
+    // 招待成立の登録は verified で作成されるため認証促し画面を経由せず app.entry へ (i16)
+    $response->assertRedirect(route('app.entry'));
 
     $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();
     expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
diff --git a/tests/Feature/Organization/InvitationTest.php b/tests/Feature/Organization/InvitationTest.php
index 37122bb3..b84cd954 100644
--- a/tests/Feature/Organization/InvitationTest.php
+++ b/tests/Feature/Organization/InvitationTest.php
@@ -378,7 +378,8 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
         'terms_accepted' => '1',
     ]);
 
-    $response->assertRedirect(route('verification.notice'));
+    // 招待成立の登録は verified で作成されるため認証促し画面を経由せず app.entry へ (i16)
+    $response->assertRedirect(route('app.entry'));
 
     $user = User::whereBlind('email', 'email_index', 'newbie@example.com')->firstOrFail();
     // 招待組織へ参加し、招待ロールが付与される
@@ -403,12 +404,14 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
         'email' => 'header@example.com',
         'password' => 'SecurePass1234',
         'terms_accepted' => '1',
-    ])->assertRedirect(route('verification.notice'));
+    ])->assertRedirect(route('app.entry'));
 
-    // 組織文脈は **URL だけ**で決まる (家系裁定 AG-037)。verification.notice は組織 route では
+    // 組織文脈は **URL だけ**で決まる (家系裁定 AG-037)。settings は組織 route では
     // ないので、招待先に所属していても currentOrganization は null でなければならない
     // (所属している組織のどれかを裏口から選ぶと、それが保持列の再発明になる)。
-    $this->get(route('verification.notice'))
+    // 検証用 GET 先は i16 (招待成立 = verified) 以降も到達できる組織外の認証済み
+    // Inertia ページ (/settings) を使う。検証意図は従来 (verification.notice) と同じ。
+    $this->get(route('settings'))
         ->assertOk()
         ->assertInertia(fn (AssertableInertia $page) => $page
             ->where('currentOrganization', null)
@@ -427,7 +430,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
         'email' => 'nofree@example.com',
         'password' => 'SecurePass1234',
         'terms_accepted' => '1',
-    ])->assertRedirect(route('verification.notice'));
+    ])->assertRedirect(route('app.entry'));
 
     $user = User::whereBlind('email', 'email_index', 'nofree@example.com')->firstOrFail();
     // 初期組織は作らない (招待組織が唯一の所属になる)
diff --git a/tests/Feature/Organizations/InvitationDeletedOrganizationTest.php b/tests/Feature/Organizations/InvitationDeletedOrganizationTest.php
new file mode 100644
index 00000000..5a084094
--- /dev/null
+++ b/tests/Feature/Organizations/InvitationDeletedOrganizationTest.php
@@ -0,0 +1,243 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\Organization;
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Validation\ValidationException;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * 招待元組織の論理削除は「無効招待」へ同一畳み込みされる (正典 v1 i7 / aicue:T263 施策 A)。
+ *
+ * - 論理削除済み組織宛の招待は GET/POST/register のどの経路でも 500 にならず、
+ *   不在・取消済みと**同一の応答** (Invitations/Invalid / 中立メッセージ / fallback) に畳む
+ *   (理由の出し分けを増やさない = 存在オラクルを作らない)。
+ * - guest GET では畳み込みを session 保存より**前**に行う (token が session に入ると
+ *   register prefill に宛先が出た上で登録 POST が失敗する二段障害になる)。
+ *
+ * ## ロック下最終再検証 (joinOrganization 1c) の検証は 3 分割 (保証範囲を混同しない)
+ * 1. **状態注入テスト**: 事前検証の通過後 (招待行 FOR UPDATE の直前) に組織を論理削除する
+ *    one-shot 注入で、最終再検証が削除を受諾不能へ畳むこと (= false の消費契約) を固定する。
+ * 2. **SQL 形状 assert**: 注入後に実行される organizations への問い合わせが
+ *    「SoftDeletes 条件 + FOR UPDATE + 対象 organization id」を満たすことを固定する。
+ *    状態注入だけでは 1c を非ロック読みへ退行させても自トランザクションの更新が見えて
+ *    緑になるため、ロック読みであることは形状で固定する。
+ * 3. **保証外**: 別接続を使った DB エンジン固有の MVCC スケジュールの完全再現は保証しない
+ *    (RefreshDatabase 下では別接続からテストデータが見えず構造的に不可能。また one-shot の
+ *    注入時点では組織行ロックが取得済みのため実際の競合順序の再現でもない —
+ *    これは「消費契約の決定的検証」であって「競合の再現」ではない)。
+ */
+
+/**
+ * 指定組織宛の active 招待を作り、平文 token とともに返す (factory ベース。
+ * メール送信経路は InvitationTest 側が固定済みのためここでは通さない)。
+ *
+ * @return array{0: OrganizationInvitation, 1: string}
+ */
+function makeDeletableOrgInvitation(Organization $organization, string $email, OrganizationRole $role = OrganizationRole::Member): array
+{
+    return OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => $email, 'role' => $role->value]);
+}
+
+/**
+ * joinOrganization がロック下再検証のために発行する招待行の SELECT ... FOR UPDATE を検出し、
+ * その直前に招待元組織を論理削除する (one-shot 注入)。
+ *
+ * 状態は**二段階**で管理する (InvitationAcceptRaceTest の家風の拡張):
+ *  - $injected: 注入は 1 回だけ。ただし注入後も callback を inert にせず、
+ *    続けて実行される organizations への SELECT を $organizationQueries へ記録する
+ *    (1c の SQL 形状検査用。注入で callback 全体を inert にすると 1c の SQL を記録できない)。
+ *  - id は必ず placeholder になるため bindings 側で対象 id を確認する。
+ *
+ * **DB::beforeExecuting() の callback は解除できない**ため、注入は $injected で恒久的に
+ * 一度きりになる設計にしてある (記録側は追記のみで副作用なし)。
+ *
+ * @param  list<array{sql: string, bindings: array<int, mixed>}>  $organizationQueries
+ */
+function deleteOrganizationOnLockedInvitationRead(int $invitationId, int $organizationId, array &$organizationQueries): void
+{
+    $injected = false;
+    DB::beforeExecuting(function (string $query, array $bindings) use ($invitationId, $organizationId, &$injected, &$organizationQueries): void {
+        $lower = strtolower($query);
+
+        if ($injected) {
+            // 注入後: organizations への SELECT を記録する (1c のロック読み形状の検査用)。
+            // 注入 UPDATE 自身や users のロック読みは対象外 (select + "organizations" で絞る)
+            if (str_starts_with($lower, 'select') && str_contains($lower, '"organizations"')) {
+                $organizationQueries[] = ['sql' => $lower, 'bindings' => $bindings];
+            }
+
+            return;
+        }
+
+        if (! str_contains($lower, 'organization_invitations') || ! str_contains($lower, 'for update')) {
+            return;
+        }
+        $stringBindings = array_map(static fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $bindings);
+        if (! in_array((string) $invitationId, $stringBindings, true)) {
+            return;
+        }
+
+        // 記録より先に立てる (自分の UPDATE による再入を注入分岐へ入れない)
+        $injected = true;
+        // 同一接続・同一トランザクション内なので自分のロックと競合しない
+        DB::table('organizations')->where('id', $organizationId)->update(['deleted_at' => now()]);
+    });
+}
+
+test('guest + 論理削除組織の招待リンク GET は Invalid を返し token を session に入れない', function (): void {
+    [$organization] = createOrganizationWithOwner('消える組織');
+    [, $token] = makeDeletableOrgInvitation($organization, 'ghost@example.com');
+
+    $organization->delete();
+
+    $response = $this->get('/invitations/accept?token='.$token);
+
+    // 不在・取消済みと同一の専用ページ (理由は出し分けない)。register への誘導もしない
+    $response->assertOk();
+    $response->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Invalid'));
+    // 畳み込みは guest 分岐より前 = token は session に入らない (二段障害の再発防止)
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('ログイン済み (宛先一致) + 論理削除組織の GET は 500 ではなく Invalid を返す', function (): void {
+    [$organization] = createOrganizationWithOwner('消える組織');
+    [, $token] = makeDeletableOrgInvitation($organization, 'invitee@example.com');
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+
+    $organization->delete();
+
+    $response = $this->actingAs($invitee)->get('/invitations/accept?token='.$token);
+
+    $response->assertOk();
+    $response->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Invalid'));
+});
+
+test('ログイン済み (宛先一致) + 論理削除組織の POST 受諾は不在 token と同一の中立メッセージで差し戻す', function (): void {
+    [$organization] = createOrganizationWithOwner('消える組織');
+    [, $token] = makeDeletableOrgInvitation($organization, 'invitee@example.com');
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+
+    $organization->delete();
+
+    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);
+
+    // 500 にせず、不在 token と同一の文言で app.entry へ差し戻す (理由を開示しない)
+    $response->assertRedirect(route('app.entry'));
+    $response->assertSessionHas('error', 'この招待は無効です。');
+    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $invitee->id)->exists())->toBeFalse();
+    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
+});
+
+test('論理削除組織の招待 token を session に持つ register POST は個人組織へ fallback し招待は未受諾のまま', function (): void {
+    [$organization] = createOrganizationWithOwner('消える組織');
+    [, $token] = makeDeletableOrgInvitation($organization, 'fallback@example.com');
+
+    $organization->delete();
+
+    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
+        'name' => 'フォールバック 花子',
+        'email' => 'fallback@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ]);
+
+    // 登録そのものは成功する (500 にしない)
+    $response->assertSessionHasNoErrors();
+    $this->assertAuthenticated();
+
+    $user = User::whereBlind('email', 'email_index', 'fallback@example.com')->firstOrFail();
+    // 削除済み組織へは参加せず、個人組織が 1 件だけ作られる
+    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->getKey())->exists())->toBeFalse();
+    expect($user->organizations()->count())->toBe(1);
+    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
+});
+
+test('acceptInvitation: 事前検証通過後の論理削除はロック下再検証 1c が受諾不能へ畳む (SQL 形状も固定)', function (): void {
+    [$organization] = createOrganizationWithOwner('消える組織');
+    [$invitation, $token] = makeDeletableOrgInvitation($organization, 'race@example.com');
+    $invitee = User::factory()->create(['email' => 'race@example.com']);
+
+    /** @var list<array{sql: string, bindings: array<int, mixed>}> $organizationQueries */
+    $organizationQueries = [];
+    deleteOrganizationOnLockedInvitationRead($invitation->id, $organization->id, $organizationQueries);
+
+    $thrown = null;
+    try {
+        app(OrganizationMembershipService::class)->acceptInvitation($token, $invitee);
+    } catch (ValidationException $exception) {
+        $thrown = $exception;
+    }
+
+    // 事前検証 (findActiveByPlainToken / 早期照合) は生存組織で通過し、1c が中立メッセージへ畳む
+    expect($thrown)->not->toBeNull();
+    expect($thrown?->errors()['token'][0] ?? null)->toBe('この招待は無効です。');
+    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $invitee->id)->exists())->toBeFalse();
+    expect($invitation->refresh()->isAccepted())->toBeFalse();
+
+    // 1c の SQL 形状: 非ロック読みへ退行させると自トランザクションの更新が見えて状態注入では
+    // 緑のままになるため、「SoftDeletes 条件 + FOR UPDATE + 対象 organization id」を形状で固定する
+    $lockedRead = array_filter(
+        $organizationQueries,
+        function (array $query) use ($organization): bool {
+            $bindings = array_map(static fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $query['bindings']);
+
+            return str_contains($query['sql'], '"deleted_at" is null')
+                && str_contains($query['sql'], 'for update')
+                && in_array((string) $organization->id, $bindings, true);
+        },
+    );
+    expect($lockedRead)->not->toBeEmpty();
+});
+
+test('register POST: 事前検証通過後の論理削除は fallback 登録になり verified を与えない', function (): void {
+    [$organization] = createOrganizationWithOwner('消える組織');
+    [$invitation, $token] = makeDeletableOrgInvitation($organization, 'race-register@example.com');
+
+    /** @var list<array{sql: string, bindings: array<int, mixed>}> $organizationQueries */
+    $organizationQueries = [];
+    deleteOrganizationOnLockedInvitationRead($invitation->id, $organization->id, $organizationQueries);
+
+    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
+        'name' => 'レース 太郎',
+        'email' => 'race-register@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ]);
+
+    // acceptInvitationIfValid は 1c の敗北で null → 登録は個人組織 fallback で成立する
+    $response->assertSessionHasNoErrors();
+    $this->assertAuthenticated();
+
+    $user = User::whereBlind('email', 'email_index', 'race-register@example.com')->firstOrFail();
+    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->getKey())->exists())->toBeFalse();
+    expect($user->organizations()->count())->toBe(1);
+    expect($invitation->refresh()->isAccepted())->toBeFalse();
+    // 受諾不能の fallback 登録は unverified のまま (i16 後段の fail-closed と対称)
+    expect($user->email_verified_at)->toBeNull();
+});
+
+test('負のコントロール: 生存組織では同条件で受諾が成立する (畳み込みの誤爆がない)', function (): void {
+    [$organization] = createOrganizationWithOwner('生きている組織');
+    [, $token] = makeDeletableOrgInvitation($organization, 'alive@example.com');
+    $invitee = User::factory()->create(['email' => 'alive@example.com']);
+
+    // GET は受諾確認画面 (Invalid に誤爆しない)
+    $this->actingAs($invitee)->get('/invitations/accept?token='.$token)
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Accept'));
+
+    // POST は参加が成立する
+    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);
+
+    $response->assertRedirect(route('dashboard', ['organization' => $organization->slug]));
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
+    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();
+});
diff --git a/tests/Unit/Support/Auth/InvitationContinuationTest.php b/tests/Unit/Support/Auth/InvitationContinuationTest.php
new file mode 100644
index 00000000..2e10d3f2
--- /dev/null
+++ b/tests/Unit/Support/Auth/InvitationContinuationTest.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Auth\InvitationContinuation;
+use Illuminate\Session\ArraySessionHandler;
+use Illuminate\Session\Store as SessionStore;
+
+/*
+ * 招待継続 (InvitationContinuation) の単体契約 (正典 v1 i11+i14 / aicue:T263 施策 B)。
+ * remember → resolve の round-trip / 型衛生 (不正値は忘れさせて null) / forget の冪等。
+ */
+
+function freshInvitationContinuationSession(): SessionStore
+{
+    return new SessionStore('test-session', new ArraySessionHandler(60));
+}
+
+test('remember → resolve の round-trip で token が返る', function (): void {
+    $session = freshInvitationContinuationSession();
+
+    InvitationContinuation::remember($session, 'plain-token-123');
+
+    expect(InvitationContinuation::resolve($session))->toBe('plain-token-123');
+    // resolve は非破壊 (後続 POST の受諾に token を残す)
+    expect(InvitationContinuation::resolve($session))->toBe('plain-token-123');
+});
+
+test('非文字列 (配列) は忘れさせて null を返す (型衛生)', function (): void {
+    $session = freshInvitationContinuationSession();
+    $session->put('invitation_token', ['tampered']);
+
+    expect(InvitationContinuation::resolve($session))->toBeNull();
+    expect($session->has('invitation_token'))->toBeFalse();
+});
+
+test('非文字列 (数値) は忘れさせて null を返す (型衛生)', function (): void {
+    $session = freshInvitationContinuationSession();
+    $session->put('invitation_token', 12345);
+
+    expect(InvitationContinuation::resolve($session))->toBeNull();
+    expect($session->has('invitation_token'))->toBeFalse();
+});
+
+test('空文字は忘れさせて null を返す', function (): void {
+    $session = freshInvitationContinuationSession();
+    $session->put('invitation_token', '');
+
+    expect(InvitationContinuation::resolve($session))->toBeNull();
+    expect($session->has('invitation_token'))->toBeFalse();
+});
+
+test('鍵が無い (null) 場合は forget を呼ばず null を返す', function (): void {
+    $session = freshInvitationContinuationSession();
+
+    expect(InvitationContinuation::resolve($session))->toBeNull();
+    expect($session->has('invitation_token'))->toBeFalse();
+});
+
+test('forget は冪等 (2 回呼んでも例外にならず鍵は消えたまま)', function (): void {
+    $session = freshInvitationContinuationSession();
+    InvitationContinuation::remember($session, 'plain-token-123');
+
+    InvitationContinuation::forget($session);
+    InvitationContinuation::forget($session);
+
+    expect($session->has('invitation_token'))->toBeFalse();
+    expect(InvitationContinuation::resolve($session))->toBeNull();
+});

```

## テスト結果サマリー

- composer test: 7859 tests, 7857 passed, 2 skipped (既存), 5 risky (既存), 0 failed
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test (2748 passed) / build: green
- pnpm typecheck:packages / build:packages / test:packages: green
- 新設テストはすべてテストファーストで赤を確認してから実装した
  (施策 A: 6 failed → 実装後 green / 施策 B: gate が現行 3 ファイルを検出して赤 + unit はクラス不在で赤 → 実装後 green / 施策 C: 3 failed → 実装後 green)
- AccountDeletionPathGateTest の依存閉包目録へ App\Support\Auth\InvitationContinuation を理由付きで登録
  (OrganizationMembershipService が resolveRegisterPrefillEmail で参照するため閉包に入る。決済到達辺なし)
