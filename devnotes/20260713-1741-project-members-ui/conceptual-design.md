# 概念設計: project-members-ui

## 背景・課題

bug-hunt finding **F-M3 (Medium, broken_flow)**。

`app/Http/Controllers/Projects/ProjectMemberController.php` は `store` / `destroy` を
実装済み (成功 flash 文言まで用意) だが、**これを呼ぶ UI がアプリのどこにも存在しない**。
`routes/web.php` にも `projects.members.store` (POST `/projects/{project}/members`) /
`projects.members.destroy` (DELETE `/projects/{project}/members/{user}`) が登録済みで、
Feature テスト (`tests/Feature/Projects/ProjectMemberTest.php`) も緑だが、
エンドユーザーはブラウザから編集者 (project_admin) / 撮影者 (project_member) の
**個別アサインを一切実行できない**。

さらに `ProjectController::show()` は既に `members` (明示 + 暗黙メンバー rows) と
`canViewMemberEmails` を Inertia props として配っているが、`Projects/Show.svelte` は
**この props を Props interface にすら宣言しておらず、完全に無視している** (デッドデータ)。
つまり「サーバは配線済み・画面が受け取っていない」片肺状態。

### ユーザー影響

- Default Project 以外のプロジェクトで、誰が編集者/撮影者かを確認できない。
- プロジェクト単位のメンバー割当 (SOP → シナリオ生成 → ナビ撮影の担当分担) ができず、
  現場の撮影者アサインという中核ワークフローに人的運用の穴が空く。

## 改善アイデア

`Projects/Show.svelte` に **プロジェクトメンバー管理 UI** を追加し、既存の
`projects.members.store` / `projects.members.destroy` を呼べるようにする:

1. **メンバー一覧** — 既に配られている `members` prop (明示 + 暗黙) を表示。
   名前・email (可視権限があるとき)・ロール (編集者/撮影者/暗黙は「管理者(組織)」表示) を出す。
2. **ロール変更** — 明示メンバー各行の role select。`store` の再実行
   (`syncWithoutDetaching` = ロール更新) を呼ぶ。既存 `Admin/Users.svelte` の
   「3 値遷移コマンド 1 セレクト」流儀に倣う。
3. **メンバー追加フォーム** — 組織メンバーから未アサインの候補を select し、ロールを選んで
   `store` に POST。
4. **メンバー削除** — 明示メンバー行の削除ボタン → `ConfirmDialog` → `destroy` に DELETE。
   暗黙メンバー (org owner/admin) は project pivot を持たないため削除対象外 (ボタン非表示)。

UI 配置は既存の「管理メニュー」「アイテム」Card と同列 (`Projects/Show.svelte` の
`max-w-2xl` カラム内)。表示は `canManage` (= `can('update', $project)`) gate。

### バックエンドの最小追加

追加フォームの候補セレクトのために `ProjectController::show()` に
**`assignableUsers` prop (id/name のみ)** を 1 つ追加する。

- **候補の定義**: current org のメンバーのうち、**現在の `members` prop に存在しない者**
  (= 明示メンバーも暗黙メンバー = org owner/admin も除外)。暗黙メンバーを候補に含めると
  「追加しても見え方が変わらない・削除しても暗黙で残る」混乱を招くため除外する。
- **PII 最小化ゲート (Codex Round 1 Critical)**: `name` も PII。`assignableUsers` は
  `can('update', $project)` (= `$canManage`) が true のときのみ実データを返し、
  それ以外は `[]` を返す。UI 非表示だけに頼らず **payload 生成時点で絞る**
  (`canViewMemberEmails` と同じ流儀)。`email` は候補に含めない。
- **shape 固定 (PHPStan L10)**: `list<array{id:int,name:string}>` を PHPDoc で明示し、
  Feature テストで id/name 以外のキーを含まないことを検証する。

それ以外の props (`members` / `canViewMemberEmails`) は既存を再利用し、
`store` / `destroy` / route / Policy / pivot 書き込み経路は**一切変更しない** (既に完成している)。

### 暗黙メンバー・競合のサーバ側セマンティクス (Codex Round 2)

- **暗黙メンバー (org owner/admin) への明示アサインは許容 (無害) — バックエンド変更なし**:
  UI は暗黙メンバーを候補に出さない (既に管理アクセスがあり追加が無意味なため)。ただし万一
  リクエスト改変で暗黙メンバーへ明示 pivot が付いても**無害**。彼らの管理アクセスは org ロールから
  継承され (ProjectPolicy)、明示 pivot の有無に依存しない。detach しても暗黙メンバーとして残る。
  cross-org は store が 403 で既に防御済み。これは「守るべきドメイン不変条件」ではなく既存 store の
  意図した upsert 挙動なので、store をフォークして禁止するのは AGENTS.md 原則2 (今必要なものだけ) に反する。
- **競合セマンティクス = last-writer-wins (バックエンド変更なし)**: `store` の upsert
  (`syncWithoutDetaching`) は既存テスト済みの意図した契約 (add と role 更新を兼ねる)。競合時も
  **「選択されたロールへの upsert を正しい結果と定義する」(last-writer-wins をドメイン契約とする)**
  — これが根拠であり、「stale 窓が小さい」は補足に過ぎない。add と update を別セマンティクスに
  分けるには endpoint フォークが必要だが、本質は「メンバーシップの upsert」という単一概念であり
  過剰 (AGENTS.md 原則2/3)。add は `assignableUsers` (= 非メンバー) からのみ選択させ、各操作後に
  Inertia redirect back で `members` / `assignableUsers` が再取得される (補足: stale 窓の縮小)。

## 期待効果

- **使命への貢献**: 「専門知識ゼロの現場作業者が標準化された動画マニュアルを作る」ための
  担当分担 (誰が撮る=撮影者 / 誰が設計・確認する=編集者) をプロジェクト単位で運用可能にする。
  撮影者アサインは AI-CUE のナビ撮影ワークフローの前提であり、その運用穴を塞ぐ。
- **具体的改善**: Default Project 以外でも**ブラウザからの割当操作 (追加/ロール変更/削除) が
  可能になる**。既存の死蔵 endpoint / 死蔵 props が実際にユーザーへ到達する
  (実装済み資産の活性化)。
- **退行リスク評価 (Codex Round 1 で表現を弱めた)**: バックエンド契約 (store/destroy/Policy)
  は既存テストで安定しているが、UI から初めて到達可能になる経路のため、**UI 到達性と
  権限に応じた表示 (email 可視性・assignableUsers ゲート) には追加検証が必要**。
  この検証は詳細設計のテスト計画でカバーする。

## 実装方針（概要）

| 層 | 変更 |
|----|------|
| Controller | `ProjectController::show()` に `assignableUsers` prop (`list<array{id:int,name:string}>`) を追加。候補 = current org メンバーで**現在の `members` に存在しない**者 (明示・暗黙とも除外)。`$canManage` が false のときは `[]`。PII 最小 (email 不含)。 |
| Frontend | `Projects/Show.svelte` に Props (`members` / `canViewMemberEmails` / `assignableUsers`) を宣言し、メンバー管理 Card (一覧 + ロール変更 + 追加フォーム + 削除 ConfirmDialog) を追加。`canManage` gate。 |
| 型 | Show.svelte 内 inline interface (既存 Show.svelte の Item と同じ流儀)。ProjectRole の表示ラベル (編集者/撮影者) は select の option に定数で持つ。 |
| テスト | Feature (Inertia assertion): `assignableUsers` の shape (id/name のみ・余剰キーなし)・絞り込み 4 ケース (明示メンバー除外 / 暗黙メンバー除外 / 他組織ユーザー除外 / `canManage=false` で `[]`)・`canViewMemberEmails=false` で email 実値なし を検証 (既存 `ProjectMemberTest` は store/destroy を既に網羅、不変)。frontend: `pnpm typecheck` / `pnpm build` / ds-purity・atomic-import テスト green。 |

- **禁止事項 8 遵守 (UI 挙動を明文化)**: 追加ボタンは**常に活性**。送信時に候補未選択なら
  form error を表示し **POST しない**。候補 0 人 (`assignableUsers` 空) のときはフォーム上部に
  **単一の案内文**「アサインできる組織メンバーがいません。」を出す (Codex Round 3: 状態分岐用の
  新規 prop は追加せず案内文を統一)。`canManageMembers` (既存 prop) が true のときのみ
  `/manage/users` への導線を併記する (未割当メンバーの招待・割当見直しのどちらにも有用。
  `Organizations/Settings` のオーナー移譲流儀)。
- **一覧の視覚分離**: `implicit` フラグで「管理者(組織)」バッジと明示ロールバッジを分け、
  暗黙メンバー行は削除ボタン・ロール変更 select を出さない (project pivot を持たないため)。
- **`response()->json()` 直書きなし** (Inertia props と `back()->with()` の既存経路のみ)。
- **DESIGN.md / Atomic Design 準拠**: DS atom/molecule/organism (Card / Button / Select /
  FormField / Badge / ConfirmDialog) のみ。hex 直書き・新規 SVG なし。

## 制約・前提

- サーバ側の認可・IDOR 防御は既存のまま (store の cross-org 403 / destroy の
  URL {user} 404 / `NestedRouteIdorDefenseTest` 登録済み)。UI 追加でこの契約は変えない。
- `members[].email` の PII 最小化契約 (`ProjectShowEmailVisibilityTest` が固定) を尊重し、
  フロントの email 表示は `canViewMemberEmails` を単一根拠にする (キー常在・値のみ null)。
- `assignableUsers` は email を含めない (追加 select に PII は不要)。
- Laratrust の権限判定 (`laratrust_team_id` 明示) はサーバ側で完結済み。フロントは判定しない。

## スコープ外

- `ProjectMemberController` / route / Policy / pivot 書き込み経路の変更 (既に完成)。
- 招待フロー (組織未所属ユーザーの招待) — これは組織メンバー管理 (`/manage/users`) の責務。
  本 UI は「既に組織にいるメンバーをプロジェクトへアサイン」に限定する。
- Default Project の自動アサイン挙動の変更。
- `Projects/Edit.svelte` への配置 (詳細情報と一覧が揃う Show を正とする)。
- ロール表示の 5 値正規化等の大掛かりな共通化 (Admin/Users 側の作り込みは持ち込まない)。
