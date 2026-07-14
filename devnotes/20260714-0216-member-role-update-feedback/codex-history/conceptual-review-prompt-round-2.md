# Round 2: 概念設計の修正反映

Round 1 の指摘への対応は以下。全体判定を再評価してください(APPROVED / CHANGES_REQUESTED)。

## 対応サマリー
- [Critical] フロント回帰テスト不足 → 概念設計に「テスト計画 > フロント回帰(核心/新規)」節を新設。AdminUsers.test.ts に 4 ケース(権威値復帰 / 対象行のみ invalid + combobox直下エラー / 成功時反映 / 他行にエラー無し)を明記。router.patch は vi.mock で onError/onSuccess を制御(既存 NotificationListItem.test.ts の手法踏襲)。
- [Warning] 失敗行の特定 → 「失敗行の特定」節を追加。errors.role は文言ソース、表示対象は roleErrorMemberId(number|null)で限定。
- [Warning] {#key} remount のフォーカス喪失 → 「アクセシビリティ・フォーカス」節。tick() 後に当該 Select へフォーカス復帰 + aria-invalid/aria-describedby 接続。
- [Warning] stale 応答レース → 「送信の直列化」節。changingRole が in-flight を全行で 1 件に直列化、onFinish まで再入不可。
- [Suggestion] 用語 → 「一方向 value 伝播と DOM 選択状態の乖離」に統一。
- [Suggestion] 行単位状態の型 → roleResetTokens: Record<number,number> / roleErrorMemberId: number|null / changingRole: boolean を明記。
- [Suggestion] バックエンド回帰 → ConsoleRoleTransitionTest に success flash なし/ロール不変の assertion 追加を継続。

---

## 修正後の概念設計(全文)

# 概念設計: member-role-update-feedback

## 背景・課題

bug-hunt(回帰run) F-02(High, `claimed_success_no_change` / H7+H10)。

**症状**: `manage/users`(組織にプロジェクトが1つも無い状態)でメンバーのロールを「管理者」→「編集者/撮影者」に変更すると、`organizations.members.update` (PATCH) が 303 See Other を返し、**エラーも成功トーストも無く combobox は変更後の値のまま**残る。リロードすると「管理者」に戻っており、変更は保存されていない。owner/admin 両方で再現。

### コード実測による根本原因の特定(briefの仮説の訂正)

brief は「サーバが成功系(303)で黙って破棄している(サービスがプロジェクト不在時に無言でロールを捨てている)」と仮説を立てているが、**現行コードを実測した結果、この仮説は成立しない**。

1. **サーバは既に検証エラーを返している**。
   `OrganizationMembershipService::applyConsoleRole()`(L247-279)は、editor/shooter コマンドで Default Project が不在(`resolveForUpdate() === null`)の場合、`ValidationException`(`role` キー、メッセージ「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」)を送出し、トランザクションごと rollback する。org ロールも pivot も一切変更されない。
2. **エンドポイントは error bag を返している**。
   `tests/Feature/Organization/ConsoleRoleTransitionTest.php` の「endpoint 経由: Default Project 不在の editor コマンドは error bag」テストが `assertSessionHasErrors('role')` で固定済み。org ロールが `Member` のまま(=未変更)であることも検証済み。
3. **Inertia mutation は `Accept: text/html`** のため `expectsJson()` は false(`app/Http/Middleware/RequireRecentAuth.php` L80 のコメントが明示)。よって `ValidationException` は 422 JSON ではなく **redirect-back(302→Inertia が 303 化)+ セッション error bag** となり、Inertia が `page.props.errors` に共有する。finding が報告する「303 See Other」はまさにこの redirect-back であり、**サーバは既にエラーを返している**。

つまり「サーバが黙って破棄」ではなく、**サーバは正しく拒否しているのにフロントがそれを反映できていない**のが真因である。bug-hunt が `claimed_success_no_change`(成功したように見えるのに変更が残らない)で検出したのは、この「UI が拒否を無視して選択値を保持し、成功したように見せている」挙動そのものである。

### フロント側の 2 つの欠陥

`resources/js/pages/Admin/Users.svelte` の `changeRole()`(L62-81)とロール `Select`(L272-288):

- **(A) 一方向 `value` 伝播と DOM 選択状態の乖離(核心)**:
  ロール `Select` は `value={member.roleState}` の**一方向バインド**で描画される。ユーザーが「管理者(admin)」の行で「編集者」を選ぶと DOM の `<select>` は「編集者」を表示する。サーバが拒否して redirect-back すると props が再取得されるが、権威値 `member.roleState` は **admin のまま変化しない**(rollback 済み)。Svelte のリアクティブ更新は「依存値が変化したとき」だけ DOM 更新コードを走らせるため、`value` が admin→admin で不変だと**ネイティブ `<select>` はユーザー選択(編集者)を保持したまま**になる(React 的な常時制御 input ではない)。これが「combobox は変更後の値のまま」の正体であり、保存されていない変更を成功したかのように見せる(= bug-hunt の `claimed_success_no_change`)。
- **(B) エラーが combobox から離れた場所に出る**:
  `FormError` は行の左側(email 直下, L254-259)に置かれており、ロール `Select`(行の右側)から視覚的に離れている。finding の期待「combobox 直下にエラー表示し値を元に戻す/invalid にする」を満たしていない。Select 自体の `error`(aria-invalid)状態も立てておらず、`aria-describedby` でエラーと接続もしていない(支援技術に読み上げられない)。

## 改善アイデア

**サーバは既に正しい(422 相当の検証エラーを返す)ため変更しない**。修正はフロント `Admin/Users.svelte` に閉じる:

1. **combobox を権威値へ確実に戻す**: ロール変更が拒否(`onError`)されたら、その行の `Select` を `member.roleState`(サーバ権威値)へ強制的に再同期し、ユーザーが選んだ拒否値を UI から消す。実装は該当行の `Select` を **remount キー(`{#key}`)** で作り直し、権威値 `value={member.roleState}` を読み直させる(一方向 `value` 伝播では同値への再同期が走らない問題を remount で断つ)。成功時は props 更新で `member.roleState` 自体が変わるため自然に反映される。
2. **エラーを combobox 直下に出し、Select を invalid 化**: `FormError` を Select の直下へ移し、`Select` に `error`(aria-invalid)と `aria-describedby`(FormError の id)を渡す。エラーメッセージはサーバの `page.props.errors.role`(「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」)をそのまま表示する(画面上部の `no-project-note` 案内文と同趣旨)。

### 失敗行の特定(どの行の失敗か)

`page.props.errors.role` は組織全体で 1 つの error bag(行を区別しない)。表示行の特定はローカル状態 `roleErrorMemberId: number | null` で行う(既存)。`onError` で `roleErrorMemberId = member.id` を立て、`roleErrorMemberId === member.id && pageErrors.role` の行にのみ FormError と Select の invalid を出す。**`errors.role` は文言ソース、表示対象はローカル状態で限定**する(誤った行・複数行への表示を構造的に防ぐ)。次の変更が成功したら `onSuccess` で `roleErrorMemberId = null` に戻す。

### 送信の直列化(stale 応答レースの排除)

`changingRole: boolean`(既存)が in-flight 中の再操作を全行で抑止し、常に in-flight は 1 件に保たれる。`onFinish` まで再入不可のため、遅れて返る古いエラー応答で別の行が誤って remount される競合は起きない。

### アクセシビリティ・フォーカス

`{#key}` remount は当該 `<select>` を作り直すためフォーカスが外れうる。緩和として **(1)** remount 後に `await tick()` で当該 Select へフォーカスを戻す、**(2)** Select に `error`(aria-invalid)と `aria-describedby` を接続し FormError を読み上げ対象にする。これによりキーボード/支援技術でも「値が戻った理由」が伝わる。

### 行単位状態の型

- `roleResetTokens: Record<number, number>`(member.id → remount トークン。`onError` で該当 id をインクリメント)
- `roleErrorMemberId: number | null`(FormError/invalid を出す対象行)
- `changingRole: boolean`(送信直列化ガード)

## 期待効果

- **使命への貢献**: 現場管理者が「ロール変更が保存されない理由」を即座に理解でき、詰まり(操作の空振り)を解消する。標準作業の担い手(編集者/撮影者)を正しく割り当てられるよう、次アクション(プロジェクト作成)へ導く。
- **具体的改善**: `claimed_success_no_change` の UX 破綻(成功に見えて保存されない)を解消。拒否時に combobox が元値へ戻り、原因メッセージが combobox 直下に表示される。owner/admin 両者・editor/shooter 両ロールで一貫。

## 実装方針(概要)

- **バックエンド**: 変更なし。`applyConsoleRole` の `ValidationException`(role)と `ConsoleRoleTransitionTest` の error bag 検証が既に要件を満たす。回帰固定のため「拒否時に success flash を持たない/org ロール不変」を明示する assertion を **既存テストに追加**するのみ(新規挙動は導入しない)。
- **フロント**: `resources/js/pages/Admin/Users.svelte` のみ。
  - `changeRole()` の `onError` で該当行の remount トークンを更新し、`Select` を権威値へ戻す。
  - `Select` を `error`(aria-invalid)化し、`FormError` を Select 直下へ配置。
- **型/Props/DTO**: 変更なし(`MemberRow.roleState` / `hasDefaultProject` など既存 props で完結。TS 型・JsonResource・DTO の波及なし)。

## 制約・前提

- サーバの検証・トランザクション境界(TOCTOU 封じ、Default Project 行ロック)は既存実装が正。**バックエンドの挙動は変えない**(後方互換の並走を作らない/今必要なものだけ作る)。
- フロントは Svelte 5 runes + DS token のみ。`Select` / `FormError` は既存 atom を再利用(新規コンポーネント不要)。アイコン追加なし。
- 禁止事項 8(必須未充足で disabled にしない)を維持: Select は disabled にせず、押下(選択)時にサーバ error を表示する既存方針を踏襲する。

## テスト計画

### フロント回帰(核心 / 新規)

`tests/js/pages/AdminUsers.test.ts` に追加。`vi.mock("@inertiajs/svelte")` で `router.patch` を制御し、`options.onError`/`onSuccess` を任意に発火させる(既存の `NotificationListItem.test.ts` / `OrganizationSwitcher.test.ts` の router モック手法を踏襲)。`page` は `props.errors.role` を返すよう構成:

1. **拒否時に対象行 Select が権威値へ戻る**: admin 行で「編集者」を選択 → `router.patch` が `onError` 発火 → 当該 `member-role-{id}` の value が `member.roleState`(admin)へ戻る。
2. **拒否時に対象行のみ invalid + combobox 直下にエラー**: 当該行 Select が `aria-invalid=true`、直下に `role-error-{id}` が `errors.role` 文言で表示。
3. **成功時に新ロールが反映**: `onSuccess` 経路では invalid/エラーが出ず、`roleErrorMemberId` がクリアされる。
4. **他行にエラーが出ない**: 失敗行以外の Select は invalid にならず、`role-error-{他id}` が存在しない。

### バックエンド回帰(既存の強化)

`tests/Feature/Organization/ConsoleRoleTransitionTest.php`「endpoint 経由: Default Project 不在の editor コマンドは error bag」に assertion を追加し、「拒否時に success flash を持たない」「org ロール/pivot が不変(部分適用なし)」を明示固定する(brief の誤仮説=サイレント成功が将来再発しないよう回帰ネットを張る)。新規の挙動は導入しない。

## スコープ外

- バックエンドのロール適用ロジック・トランザクション設計の変更。
- Default Project 自動作成や「プロジェクト作成導線」ボタンの新設(画面上部に既存の案内文があり、今回の finding は「拒否の明示と combobox 復帰」が焦点)。
- `organizations.members.destroy` / 2FA リセット / 招待フローの UX(本 finding の対象外)。
- Settings.svelte 側の類似 UI(本 finding は `manage/users`=`Admin/Users.svelte` が対象。必要なら別 finding で扱う)。
