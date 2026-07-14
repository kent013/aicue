# Round 4: 概念設計の再修正反映

Round 3 の Critical/Warning への対応。全体判定を再評価してください。

## 対応サマリー
- [Critical] フォーカス復帰タイミング → onError では remount トークン +1 と roleRefocusMemberId=member.id の保存のみ。実復帰は onFinish で changingRole=false(disabled 解除)後に await tick()→focus()→クリア。成功時は roleRefocusMemberId 未設定。roleRefocusMemberId: number|null を状態に追加。
- [Warning] 「制約・前提」の disabled 矛盾 → 「必須未充足では disabled にせず、通信中のみ二重送信防止として disabled」に修正(2箇所)。
- [Warning] フォーカス復帰の回帰テスト → テスト計画にケース6追加(onFinish 後に document.activeElement が失敗行 Select)。

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

### 送信の直列化(stale 応答レース・別行乖離の排除)

`changingRole: boolean`(既存)を強化し、in-flight 中(`changingRole === true`)は**全行のロール Select を `disabled`** にする。これにより (1) 二重送信を防ぎ、(2) in-flight 中に別行の Select を操作して「リクエストは飛ばないが DOM 選択値だけ変わる」新たな乖離が生じるのを構造的に排除する。`onFinish` で解除。

> **禁止事項8 との切り分け**: 禁止事項8 は「必須条件未充足を理由に操作を無効化する」ことの禁止。ここでの disabled は**二重送信防止(in-flight)**であり、必須条件の未充足を理由にしていないため該当しない(招待フォーム等で `loading` 中にボタンを無効化する既存パターンと同型)。

### アクセシビリティ・フォーカス

`{#key}` remount は当該 `<select>` を作り直すためフォーカスが外れうる。緩和:
- **(1) フォーカス復帰(タイミングに注意)**: `onError` 時点では `changingRole === true` で remount 後の Select も **disabled のため focus できない**。よって `onError` では remount トークン `+1` と復帰対象 `roleRefocusMemberId = member.id` の**保存のみ**を行う。実際の復帰は `onFinish` で `changingRole = false`(=disabled 解除)にした**後**に、`await tick()` してから既存の `id`/`data-testid`(Select atom が `{...restProps}` と `data-testid` で native `<select>` にそのまま渡す)で `document` から当該要素を取得して `focus()` し、`roleRefocusMemberId` をクリアする。成功時は `roleRefocusMemberId` を設定しない(復帰対象を残さない)。**Select atom への ref 追加は不要**(over-engineering 回避)。
- **(2) 読み上げ接続**: Select に `error`(aria-invalid)と `aria-describedby`(FormError の id)を渡し、フォーカス復帰後にエラー文言が読み上げられるようにする。Select/FormError atom は無改造で対応可能。

### 行単位状態の型とリアクティビティ

- `roleResetTokens: $state<Record<number, number>>({})`(member.id → remount トークン。`onError` で該当 id を `+1`)。Svelte 5 の `$state` は deep proxy のため per-key 書き込み(未存在キーの read 追跡含む)がリアクティブに追跡され、`{#key roleResetTokens[member.id] ?? 0}` が再評価される(full 再代入不要)。remount は**失敗行のみ**に限定(全 Select 一括 remount はしない)。
- `roleErrorMemberId: number | null`(FormError/invalid を出す対象行)。`changeRole` 冒頭(送信開始時)に `null` へリセットし、前回エラーが次通信中まで残らないようにする。`onError` で `member.id`、`onSuccess` で `null`。
- `roleRefocusMemberId: number | null`(フォーカス復帰対象。`onError` で `member.id`、`onFinish` で復帰後クリア。成功時は未設定)。
- `changingRole: boolean`(送信直列化ガード。in-flight 中は全 Select disabled)。

## 期待効果

- **使命への貢献**: 現場管理者が「ロール変更が保存されない理由」を即座に理解でき、詰まり(操作の空振り)を解消する。標準作業の担い手(編集者/撮影者)を正しく割り当てられるよう、次アクション(プロジェクト作成)へ導く。
- **具体的改善**: `claimed_success_no_change` の UX 破綻(成功に見えて保存されない)を解消。拒否時に combobox が元値へ戻り、原因メッセージが combobox 直下に表示される。owner/admin 両者・editor/shooter 両ロールで一貫。

## 実装方針(概要)

- **バックエンド**: 変更なし。`applyConsoleRole` の `ValidationException`(role)と `ConsoleRoleTransitionTest` の error bag 検証が既に要件を満たす。回帰固定のため「拒否時に success flash を持たない/org ロール不変」を明示する assertion を **既存テストに追加**するのみ(新規挙動は導入しない)。
- **フロント**: `resources/js/pages/Admin/Users.svelte` のみ(Select/FormError atom は無改造)。
  - `changeRole()` 冒頭で `roleErrorMemberId = null`。`onError` で該当行 remount トークン `+1` + `roleErrorMemberId = member.id` + `roleRefocusMemberId = member.id`(保存のみ)。`onFinish` で `changingRole = false`(disabled 解除)後に `roleRefocusMemberId` があれば `await tick()` → 当該 Select へ focus → クリア。
  - ロール `Select` を `{#key roleResetTokens[member.id]}` で包み、`error`(aria-invalid)/`aria-describedby` を付与、`FormError` を Select 直下へ配置。
  - in-flight 中(`changingRole`)は全ロール Select を `disabled`(二重送信・別行乖離の防止)。
- **型/Props/DTO**: 変更なし(`MemberRow.roleState` / `hasDefaultProject` など既存 props で完結。TS 型・JsonResource・DTO の波及なし)。

## 制約・前提

- サーバの検証・トランザクション境界(TOCTOU 封じ、Default Project 行ロック)は既存実装が正。**バックエンドの挙動は変えない**(後方互換の並走を作らない/今必要なものだけ作る)。
- フロントは Svelte 5 runes + DS token のみ。`Select` / `FormError` は既存 atom を再利用(新規コンポーネント不要)。アイコン追加なし。
- 禁止事項 8(必須未充足で disabled にしない)を維持: **必須条件未充足を理由には disabled にせず**、選択時にサーバ error を表示する既存方針を踏襲する。**通信中(in-flight)のみ二重送信防止として disabled** にする(loading 中のボタン無効化と同型で禁止事項8 非該当)。

## テスト計画

### フロント回帰(核心 / 新規)

`tests/js/pages/AdminUsers.test.ts` に追加。`vi.mock("@inertiajs/svelte")` で `router.patch` を制御し、`options.onError`/`onSuccess` を任意に発火させる(既存の `NotificationListItem.test.ts` / `OrganizationSwitcher.test.ts` の router モック手法を踏襲)。`page` は `props.errors.role` を返すよう構成:

1. **拒否時に対象行 Select が権威値へ戻る**: admin 行で「編集者」を選択(DOM は editor 表示)→ `router.patch` が `onError` 発火 → `{#key}` remount 後に当該 `member-role-{id}` の value が `member.roleState`(admin)へ戻る。
2. **拒否時に対象行のみ invalid + combobox 直下にエラー**: 当該行 Select が `aria-invalid=true`、直下に `role-error-{id}` が `errors.role` 文言で表示、`aria-describedby` が FormError の id を指す。
3. **成功時に新ロールが反映(props 駆動)**: `onSuccess` 発火に加え、**成功相当の members(roleState=editor)で再描画**して当該 Select が editor を表示することを検証(実 Inertia の「再取得 props が値を更新」を再現)。併せて `onSuccess` 経路で `roleErrorMemberId` がクリアされ invalid/エラーが消えることを検証。
4. **他行にエラーが出ない**: 失敗行以外の Select は invalid にならず、`role-error-{他id}` が存在しない。
5. **in-flight 中は全ロール Select が disabled**: `router.patch` を pending にした状態で他行 Select が `disabled`(二重送信・別行乖離の防止)、`onFinish` で解除されることを検証。
6. **拒否後にフォーカスが失敗行 Select へ復帰**: `onError`→`onFinish` 発火後、`await tick()` を挟んで `document.activeElement` が失敗行の `member-role-{id}` であることを検証(remount で生じるフォーカス喪失の回帰ネット。`onError` 直後の disabled 中は復帰していないことも同ケースで確認可)。

### バックエンド回帰(既存の強化)

`tests/Feature/Organization/ConsoleRoleTransitionTest.php`「endpoint 経由: Default Project 不在の editor コマンドは error bag」に assertion を追加し、「拒否時に success flash を持たない」「org ロール/pivot が不変(部分適用なし)」を明示固定する(brief の誤仮説=サイレント成功が将来再発しないよう回帰ネットを張る)。新規の挙動は導入しない。

## スコープ外

- バックエンドのロール適用ロジック・トランザクション設計の変更。
- Default Project 自動作成や「プロジェクト作成導線」ボタンの新設(画面上部に既存の案内文があり、今回の finding は「拒否の明示と combobox 復帰」が焦点)。
- `organizations.members.destroy` / 2FA リセット / 招待フローの UX(本 finding の対象外)。
- Settings.svelte 側の類似 UI(本 finding は `manage/users`=`Admin/Users.svelte` が対象。必要なら別 finding で扱う)。
