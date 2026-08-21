## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

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

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC (Organization→Team→Project階層)

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性
4. テスト計画の網羅性 (各施策に Pest、RefreshDatabase グローバル適用に従う)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク (特に F-2-02 が T055 register 誘導 / AG-113 アプリ内受諾を壊さないか)
8. 波及変更の網羅性 (TypeScript 型定義、目録テスト、既存テストの更新が変更対象に含まれているか)
9. セキュリティ (認可チェック、入力バリデーション、OWASP、AGENTS.md セキュリティ不変条件。特に「Service = 権威、画面は補助」が守れているか)
10. DESIGN.md 準拠 (UI 変更: token 経由参照、hex 直書きを増やさないか)
11. Atomic Design 準拠 (components の atoms/molecules/organisms/templates 責務分離)
特記: 禁止事項 8 (必須条件未充足でボタンを disabled にしない) を F-2-01 の設計根拠として採用している。この判断の妥当性も評価せよ。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書
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
| 1 | F-2-02 招待受諾の宛先 email 照合 (Service = 権威) | `app/Services/Organization/OrganizationMembershipService.php` | Critical |
| 2 | F-2-02 受諾確認画面の不一致分岐 (補助 UX) | `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` | Critical |
| 3 | F-2-02 Accept 画面 (canAccept prop) | `resources/js/pages/Invitations/Accept.svelte` | Critical |
| 4 | F-2-02 解決経路目録の説明更新 | `tests/Architecture/InvitationResolutionInventoryTest.php` | Critical |
| 5 | F-2-02 Feature テスト (照合 + 回帰 5 本) | `tests/Feature/Organization/InvitationTest.php` (追記) | Critical |
| 6 | F-2-03 除名/未割当 fail-closed リグレッションテスト | `tests/Feature/Organization/MemberRemovalAccessTest.php` (新規) | Critical |
| 7 | F-2-01 option ラベル注記 (非 disabled) | `resources/js/pages/Admin/Users.svelte` | Medium |
| 8 | F-2-01 Svelte テスト | `tests/js/pages/AdminUsers.test.ts` (追記) | Medium |

---

## 施策 1: F-2-02 招待受諾の宛先 email 照合 (Service = 唯一の権威的 gate)

### 変更箇所
- ファイル: `app/Services/Organization/OrganizationMembershipService.php`
  - `acceptInvitation()` (L116-149): join 前に宛先 email 照合を追加。
  - クラス docblock / メソッド docblock (L23 は Controller 側、L111-113 は本メソッド) の
    「一致を要求しない仕様」記述を削除・書き換え。

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

    // 宛先 email 照合 (F-2-02)。register 経路 (acceptInvitationIfValid) と同一規則:
    // CipherSweet 復号後平文の厳密比較。invitation->email / user->email はともに
    // モデル定義上 string (復号後) だが、PHPStan L10 のため明示 narrow する。
    $invitedEmail = $invitation->email;
    $actorEmail = $user->email;
    Assert::string($invitedEmail);
    Assert::string($actorEmail);
    if ($invitedEmail !== $actorEmail) {
        throw ValidationException::withMessages([
            'token' => ['この招待は別のメールアドレス宛に送信されています。招待先のメールアドレスでログインし直してください。'],
        ]);
    }

    $organization = $invitation->organization;
    Assert::isInstanceOf($organization, Organization::class);

    if ($organization->users()->whereKey($user->getKey())->exists()) {
        throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
    }
    // ... 以降変更なし ...
}
```

> **email 照合の位置**: 「受諾済/取消済/失効」の後・「既メンバー」の前に置く。理由: token の
> 生存判定は宛先に依らず既存の順序を保ち、宛先不一致は「受諾不能な状態」より後で判定する。
> どの分岐も join より前 = 状態を一切変えずに拒否する (Codex 概念 R1: 権威は Service)。

### PHPStan適合チェック
- [x] 戻り値型 `Organization` 明示済 (既存)
- [x] null 安全: `Assert::string` で email を narrow (復号後 string を型で確定)
- [x] DTO 返却 (Organization モデル。配列返却なし)
- [x] Generics 該当なし

### テスト計画 → 施策 5 に集約

### リスク
- 正規受諾者の誤拒否: register 経路と**同一比較**なので、register で受理される email は
  受諾でも受理される (規則分岐なし)。施策 5 のテスト 4 (規則一致) で固定。
- 既存 `InvitationAcceptRaceTest` / `InvitationTest` が「別 email でも受諾成功」を前提にした
  ケースを持つと落ちる → 施策 5 でそれらを email 一致前提へ更新 (仕様変更に追随。禁止事項 3 は
  「削除・上書き」だが本件は仕様変更に伴う正当な更新で、旧仕様の検証を残さない = 後方互換並走禁止)。

---

## 施策 2: F-2-02 受諾確認画面の不一致分岐 (補助 UX)

### 変更箇所
- ファイル: `app/Http/Controllers/Organizations/InvitationAcceptanceController.php`
  - `show()` (L43-77): ログイン済 + 有効招待の分岐 (L70-76) に email 照合を追加し、
    不一致なら `canAccept => false` で Accept を描画。
  - クラス docblock L23「一致はログイン後経路では要求しない仕様」を削除・書き換え。

### 波及変更
- TypeScript 型定義: 施策 3 の Accept Props に `canAccept: boolean` 追加
- API Resource/DTO: なし
- テストファイル: 施策 5

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

// 宛先 email 照合 (F-2-02, 補助 UX)。権威は Service の acceptInvitation。
// ここでは受諾ボタンの出し分けのみを行い、不一致でも招待 email は画面に出さない。
$user = $request->user();
Assert::isInstanceOf($user, User::class);
$invitedEmail = $invitation->email;
$actorEmail = $user->email;
Assert::string($invitedEmail);
Assert::string($actorEmail);
$canAccept = $invitedEmail === $actorEmail;

return Inertia::render('Invitations/Accept', [
    'organizationName' => $organization->name,
    'token' => $token,
    'canAccept' => $canAccept,
]);
```

> `$request->user() instanceof User` は L64 の guest 分岐で早期 return 済みのため、ここでは
> 必ず User だが、PHPStan L10 のため `Assert::isInstanceOf` で narrow する (既存様式に合わせる)。
> `organizationName` は不一致時も渡す (既存の token 保持者への開示水準を変えない — 招待 email は
> 出さないが組織名は従来どおり。露出面を増やさない)。

### PHPStan適合チェック
- [x] `Assert::isInstanceOf` / `Assert::string` で narrow
- [x] Inertia props (配列。`response()->json()` 不使用)

### テスト計画 → 施策 5

### リスク
- guest フロー (L63-68) は本変更より前で return するため T055 に影響しない (施策 5 テスト 1 で固定)。

---

## 施策 3: F-2-02 Accept 画面 (canAccept prop)

### 変更箇所
- ファイル: `resources/js/pages/Invitations/Accept.svelte`

### 波及変更
- TypeScript 型定義: 同ファイルの `Props` interface に `canAccept: boolean` を追加。
  Accept 用の型は本ファイル内 `interface Props` のみ (別 d.ts への切り出しは無い)。
- テストファイル: 施策 5 (Feature で prop 到達を、必要なら既存 Svelte テスト様式で表示を確認)

### 現行コード
```svelte
interface Props {
    organizationName: string;
    token: string;
}
let { organizationName, token }: Props = $props();
// ...
<Card padding="lg">
    <form novalidate onsubmit={submit}>
        <Button type="submit" loading={form.processing} testId="accept-invitation-button">
            招待を受諾する
        </Button>
    </form>
</Card>
```

### 変更後コード
```svelte
interface Props {
    organizationName: string;
    token: string;
    canAccept: boolean;
}
let { organizationName, token, canAccept }: Props = $props();
// ...
<Card padding="lg">
    {#if canAccept}
        <form novalidate onsubmit={submit}>
            <Button type="submit" loading={form.processing} testId="accept-invitation-button">
                招待を受諾する
            </Button>
        </form>
    {:else}
        <p class="text-body" data-testid="accept-invitation-mismatch">
            この招待は別のメールアドレス宛に送信されています。招待メールを受け取った
            アドレスでログインし直してください。画面右上のメニューからログアウトし、
            招待メールのリンクをもう一度開いてください。
        </p>
    {/if}
</Card>
```

> **ログアウトボタンは Accept 画面に置かない** (設計判断)。`resources/js` の `/logout` 参照は
> `logout-call-site-inventory.test.ts` が deny-by-default で 4 箇所に pin しており、新規追加は
> inventory + `docs/supported-browsers.md` (経路 C) の更新を伴う。ログアウト自体は `AppLayout` の
> ヘッダメニューに常設されているため、不一致画面は**文言で誘導**するだけにする
> (`/logout` 参照を増やさない = inventory 非変更、波及最小。思考原則「今必要なものだけ作る」)。
> token が logout を跨いで生存することにも依存しない (ログアウト後にリンク再オープン →
> guest 経路が session に token を保存し直す)。
> PageHeader の description は現状のまま (組織名を含む) でよいが、不一致時は「招待されています」の
> 文言が不正確になるため、`canAccept` に応じて description も出し分ける (実装時に調整)。

### テスト計画 → 施策 8 と同様の Svelte テスト or 施策 5 の Feature で prop 到達を確認

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
    .'出し分ける。解決後に宛先 email 照合を行い、不一致は join せず拒否する (F-2-02。'
    .'register 経路と同一の email 境界)。'],
```

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

### テスト計画 (Pest。Factory 生成・RefreshDatabase グローバル・個別 DatabaseTransactions 不使用)
- [ ] **T1 (T055 不変)**: guest が `GET /invitations/accept?token=...` → register へ redirect、
      session に `invitation_token` 保存、register 画面に招待 email が prefill される。
      (`resolveRegisterPrefillEmail` 経路。既存の T055 テストがあれば重複を避け参照する)
- [ ] **T2 (正常系回帰)**: 招待先 email でログイン中のユーザーが `POST /invitations/accept` →
      302 dashboard + success flash「…に参加しました」、`organization_user` に参加成立、org role 付与。
- [ ] **T3 (照合・show)**: 別 email のログイン者が `GET /invitations/accept?token=...` →
      Inertia `Invitations/Accept` に `canAccept=false` (受諾ボタン非表示の駆動値)。
- [ ] **T4 (照合・直 POST。権威)**: 別 email のログイン者が `POST /invitations/accept` →
      302 dashboard + error flash、`organization_user` に行が増えない・role が付かない・
      招待の `accepted_at` は null のまま (状態不変)。
- [ ] **T5 (規則一致)**: register 経路 (`acceptInvitationIfValid`) と token POST 経路
      (`acceptInvitation`) が **同一 email 入力で同一判定** を返す (一致 → 双方成功、
      不一致 → 双方 join しない)。Service を直接呼ぶ単体寄り Feature で規則の同値を固定。
- [ ] **T6 (AG-113 不変)**: 宛先本人のみ pending 一覧に出て受諾できることを確認
      (既存 `PendingInvitation*Test` / `AcceptInvitationInAppTest` の不変が維持される。
      重複する場合は新規追加せず既存テストが緑であることを実装時に確認)。
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

### テスト計画 (Pest)
- [ ] **T7 (HTTP 除名の完全性)**: owner が `DELETE organizations.members.destroy` で編集者を除名 →
      (1) `$organization->users()->whereKey($member)->exists()` が false、
      (2) `/manage/users` (Inertia `Admin/Users`) の `members` prop に当該 user が含まれない、
      (3) org role 無し (`organizationRole` が null)、
      (4) project_members pivot から消滅、
      (5) `current_organization_id` が (当該 org だった場合) null 化、
      (6) 被除名者で `GET /projects` `GET /billing` が 403 (当該 org へアクセス不可)。
- [ ] **T8 (未割当 fail-closed)**: attach のみ・role 無しの user が
      `/dashboard` `/projects` `/billing` `/manage/users` で 403。
      docblock に「検証した主要 route (全 route 保証ではない)」を明記。
- [ ] Factory 生成・RefreshDatabase グローバル・個別 `DatabaseTransactions` 不使用

### リスク
- アクセス制御の実装が role ではなく別条件に将来変わると 403 前提が崩れる → その時こそ本テストが
  落ちて検知する (それが本テストの目的)。

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
const ROLE_OPTIONS = $derived<{ value: ConsoleRole; label: string }[]>([
    { value: "admin", label: "管理者" },
    {
        value: "editor",
        label: hasDefaultProject ? "編集者" : "編集者（要プロジェクト）",
    },
    {
        value: "shooter",
        label: hasDefaultProject ? "撮影者" : "撮影者（要プロジェクト）",
    },
]);
```

> `hasDefaultProject` は既存の Props。`ROLE_OPTIONS` を `const` から `$derived` にするだけで、
> template の `{#each ROLE_OPTIONS ...}` はそのまま動く。disabled 属性は追加しない。

### テスト計画 → 施策 8

### リスク
- ラベル文言が長くなりモバイル幅で折り返す可能性 → 既存 Select atom の native `<option>` は
  ブラウザ標準の省略に委ねる (追加 CSS 不要)。DESIGN.md token に触れない (色/radius 不変)。

---

## 施策 8: F-2-01 Svelte テスト

### 変更箇所
- ファイル: `tests/js/pages/AdminUsers.test.ts` (既存に追記)

### テスト計画 (Vitest + Testing Library 様式。既存 `AdminUsers.test.ts` に倣う)
- [ ] **T9a (制約可視化)**: `hasDefaultProject=false` で描画 → ロール select の option に
      「編集者（要プロジェクト）」「撮影者（要プロジェクト）」が存在し、「管理者」は素のまま。
      3 option とも `disabled` 属性を持たない (禁止事項 8 の遵守を固定)。
- [ ] **T9b (対の正例)**: `hasDefaultProject=true` で描画 → option ラベルが
      「編集者」「撮影者」(注記なし) に戻る。
- [ ] 既存の no-project-note 表示テストがあれば維持を確認。

### 波及変更
- なし (frontend テストのみ)

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

## 関連する現行コード (抜粋)

### OrganizationMembershipService::acceptInvitation / acceptInvitationIfValid (現行)
```php
    /**
     * 招待受諾。ログイン中ユーザーが受諾する (招待 email と user の email の一致は要求しない)。
     *
     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 既メンバー
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
     * **register 経路専用 (再利用禁止)**: join 成立時、参加した招待組織を
     * current_organization_id へ **無条件で確定する副作用** を持つ (登録直後の user は
     * current 未設定のため「招待成立 ⇒ current = 招待先」を強制できる)。この副作用は
     * 「呼び出し元の user が登録直後で current 未確定」であることに依存するため、
     * **ログイン中経路 (既存 current を持つ user) から再利用してはならない**
     * (既存 current を無条件上書きしてしまう)。POST 受諾は current を切り替えない
     * acceptInvitation を使い、共通コア joinOrganization は current を触らない
     * (InvitationTest が POST 受諾の current 非変更を固定する)。
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
        if ($invitation->email !== $user->email) {
            return null;
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 既メンバー (race 等) は個人組織へ fallback
        if ($organization->users()->whereKey($user->getKey())->exists()) {
            return null;
        }

        if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
            // 受諾不能なら現在組織も確定しない (join 失敗でも current_organization_id を
            // 招待組織へ書くと、非所属 org が current という非正規状態を作る)
            return null;
        }

        // [register 経路限定] 参加した招待組織をこの新規ユーザーの「現在組織」として確定する。
        // - 本メソッドは register 経路専用 (呼び出し元は CreateNewUser のみ。POST 受諾は acceptInvitation)。
        //   よって現在組織の確定は POST 受諾経路 (現在組織を切り替えない契約) に波及しない。
        // - 個人組織パスが provision() 内で現在組織を据えるのと対称に、招待参加も本サービス内で
        //   「join + 現在組織確定」を 1 ユースケースとして閉じる (呼び出し元の登録 tx 内で連続実行され、
        //   「join 済だが現在組織未設定」の中間状態を残さない)。
        // - この user は登録直後で現在組織が未確定のため、招待先組織を無条件に現在組織にする
        //   (register 責務として「招待成立 ⇒ 現在組織 = 招待先」を強制)。current_organization_id は
        //   mass-assignment 保護キーのためサーバ導出値を forceFill で明示代入する (tenant キー不信)。
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }
```
### InvitationAcceptanceController::show / store (現行)
```php
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

        return Inertia::render('Invitations/Accept', [
            'organizationName' => $organization->name,
            'token' => $token,
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
            return redirect()->route('dashboard')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard')->with('success', "「{$organization->name}」に参加しました");
    }
}
```
### Admin/Users.svelte ROLE_OPTIONS + no-project-note (現行)
```svelte
    /** ロール select の選択肢 (遷移コマンド 3 値。owner は enum 外 = 構造的に指定不可) */
    const ROLE_OPTIONS: { value: ConsoleRole; label: string }[] = [
        { value: "admin", label: "管理者" },
        { value: "editor", label: "編集者" },
        { value: "shooter", label: "撮影者" },
    ];

    /** ロール select を出す行か (owner 行・自分の行はテキスト表示 = 現行 Settings と同じ流儀) */
    function canChangeRole(member: MemberRow): boolean {
        return member.roleState !== "owner" && !member.isSelf;
    }
                    {#if !hasDefaultProject}
                        <p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">
                            プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。
                        </p>
                        <!-- 詰まりの文脈から 1 ホップで作成画面へ (既存 CTA 流儀 = Button href+inertia) -->
                        <Button
                            href="/projects/create"
                            inertia
                            variant="ghost"
                            size="sm"
                            class="mt-3"
                            testId="create-project-link"
                        >
                            プロジェクトを作成
                        </Button>
                    {/if}
```
