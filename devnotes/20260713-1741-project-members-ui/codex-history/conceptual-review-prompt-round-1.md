# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)` で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# セキュリティ不変条件

1. tenant キー不信 (payload から ownership/actor/tenant キーを受けない)
2. 子は親に属する (nested route の不整合は**認可より前に 404**、NestedRouteIdorDefenseTest 登録必須)
3. cross-org 不可 (relation / org-scoped 解決経由のみ)
5. 権限判定は常に `laratrust_team_id` を明示
6. PII(email/name)は CipherSweet。検索は `whereBlind()`

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ (Laravel / Svelte エコシステム)。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから調整せよ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

---

あなたは Web アプリケーション（Laravel 12 + Svelte 5 + Inertia.js）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト（既存コードの事実）】
- `ProjectMemberController::store` は payload の `user_id` + `role` を validate し、cross-org は 403、
  `syncWithoutDetaching([$id => ['role' => ...]])` でロール明示代入 (既存メンバーはロール更新)。
- `ProjectMemberController::destroy` は URL の {user} を org member か URL 整合 guard で認可より前に 404 判定後 detach。
- route `projects.members.store` (POST) / `projects.members.destroy` (DELETE) は登録済み。
  Feature テスト `tests/Feature/Projects/ProjectMemberTest.php` (store/destroy/cross-org/404) は既に緑。
- `ProjectController::show()` は既に `members` (明示 + 暗黙メンバー rows。email はキー常在・値は canViewMemberEmails のみ実値)、
  `canViewMemberEmails` prop を配っているが、`Projects/Show.svelte` は Props に宣言すらせず無視 (デッドデータ)。
- `ProjectShowEmailVisibilityTest` が members[].email の PII 最小化契約を固定済み。
- Inertia (GET は props / 書き込みは `back()->with('success', ...)`) 経路。`response()->json()` は使わない。

## 概念設計

（以下 conceptual-design.md 全文）

# 概念設計: project-members-ui

## 背景・課題

bug-hunt finding **F-M3 (Medium, broken_flow)**。

`app/Http/Controllers/Projects/ProjectMemberController.php` は `store` / `destroy` を実装済み (成功 flash 文言まで用意) だが、**これを呼ぶ UI がアプリのどこにも存在しない**。route も登録済み、Feature テストも緑だが、エンドユーザーはブラウザから編集者 (project_admin) / 撮影者 (project_member) の**個別アサインを一切実行できない**。

さらに `ProjectController::show()` は既に `members` と `canViewMemberEmails` を Inertia props として配っているが、`Projects/Show.svelte` は**この props を Props interface にすら宣言しておらず完全に無視している** (デッドデータ)。

### ユーザー影響
- Default Project 以外のプロジェクトで、誰が編集者/撮影者かを確認できない。
- プロジェクト単位のメンバー割当 (撮影者アサイン) ができず、中核ワークフローに人的運用の穴。

## 改善アイデア

`Projects/Show.svelte` に **プロジェクトメンバー管理 UI** を追加し、既存の `projects.members.store` / `projects.members.destroy` を呼べるようにする:

1. **メンバー一覧** — `members` prop (明示 + 暗黙) を表示 (名前・email(可視権限時)・ロール)。
2. **ロール変更** — 明示メンバー各行の role select。`store` 再実行 (syncWithoutDetaching = ロール更新)。`Admin/Users.svelte` の 1 セレクト流儀に倣う。
3. **メンバー追加フォーム** — 組織メンバーから未アサイン候補を select + ロール選択 → `store` に POST。
4. **メンバー削除** — 明示メンバー行の削除 → `ConfirmDialog` → `destroy` に DELETE。暗黙メンバー (org owner/admin) は pivot を持たないため削除対象外。

UI 配置は既存「管理メニュー」「アイテム」Card と同列。表示は `canManage` (= can('update', $project)) gate。

### バックエンドの最小追加
追加フォーム候補のために `ProjectController::show()` に **`assignableUsers` prop (組織メンバーのうち未アサイン明示メンバー候補、id/name のみ)** を追加。それ以外の props / store / destroy / route / Policy / pivot 書き込み経路は**一切変更しない**。

## 期待効果
- 使命への貢献: 担当分担 (撮影者 / 編集者) をプロジェクト単位で運用可能に。撮影者アサインはナビ撮影ワークフローの前提。
- 死蔵 endpoint / 死蔵 props の活性化。退行リスク低 (サーバ側はテスト済み・不変)。

## 実装方針（概要）
| 層 | 変更 |
|----|------|
| Controller | `show()` に `assignableUsers` prop (list<{id,name}>) 追加。候補 = current org メンバーで当該 project の明示メンバーでない者。email 不含。 |
| Frontend | `Show.svelte` に Props (`members`/`canViewMemberEmails`/`assignableUsers`) 宣言 + メンバー管理 Card。canManage gate。 |
| 型 | Show.svelte 内 inline interface (既存 Item と同流儀)。ProjectRole ラベル (編集者/撮影者) は option 定数。 |
| テスト | Feature: `assignableUsers` prop の shape/絞り込み契約テスト追加 (既存 ProjectMemberTest は不変)。frontend: typecheck / build / ds-purity / atomic-import green。 |

- 禁止事項 8: 候補 0 人でも追加ボタン disabled にしない (押下時エラー or 案内文)。
- `response()->json()` 直書きなし。DESIGN.md / Atomic Design 準拠 (DS atom/molecule/organism のみ、hex 直書き・新規 SVG なし)。

## 制約・前提
- サーバ側認可・IDOR 防御は既存のまま (store cross-org 403 / destroy URL {user} 404)。UI 追加で契約を変えない。
- members[].email の PII 最小化契約を尊重。email 表示は canViewMemberEmails 単一根拠。
- assignableUsers は email を含めない。

## スコープ外
- Controller / route / Policy / pivot 書き込み経路の変更 (完成済み)。
- 招待フロー (組織未所属ユーザー招待 = /manage/users の責務)。本 UI は「組織メンバーをプロジェクトへアサイン」に限定。
- Projects/Edit.svelte への配置 (Show を正とする)。
- ロール 5 値正規化等の共通化。
