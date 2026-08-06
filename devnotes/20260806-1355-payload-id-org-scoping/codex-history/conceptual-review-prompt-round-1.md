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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか
8. セキュリティ: 存在オラクル (existence oracle) の閉じ方として妥当か。応答統一の選択 (422 / 403) に穴はないか

【重要な前提 — レビュー時に踏まえること】
- 本件は「機能追加」ではなくセキュリティ不変条件の債務返済である。
- 対象リポジトリのファイルは読んでよい (リポジトリルートは /workspace)。設計書の主張が実コードと合っているかを確認してほしい。
- 起票時の方針 (3 件とも 404 に統一) を設計者が UX 理由で覆している。この判断の妥当性を重点的に見てほしい。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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

### 4-3. 施策 C: `McpConsentOrganizationBinder::handle`

- `Organization::query()->find($orgId)` を **削除**する (結果を使っていない)。
- 既存の `$user->organizations()->whereKey($orgId)->exists()` 1 本に集約し、
  false なら **403 一択** (不在 id も非メンバー組織も同じ 403・同じ文言)。
- `filter_var` による形式検証 422 は据え置き (形式不正は存在情報を含まないため
  統一の必要がない)。
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

1. **新規**: `tests/Feature/Security/PayloadIdExistenceOracleTest.php`
   `ResponseSignature` で「実在の非メンバー id」と「不在 id」の応答一致を 3 経路で表明する
   (`MemberRouteExistenceOracleTest` の `mreoAssertNoOracle` と同じ主張形式)。
   - transfer-ownership (422 + 同一 `errors.user_id`)
   - projects.members.store (422 + 同一 `errors.user_id`)
   - McpConsentOrganizationBinder (403 + 同一メッセージ。middleware 直呼びの単体形)
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

- `php artisan test tests/Feature/Security/PayloadIdExistenceOracleTest.php`
- `php artisan test tests/Architecture/ModelDirectFetchInvariantTest.php`
- `composer test` (グローバルロック配下。待ちは正常)
- `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint`

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
