# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)`)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性
2. 禁止事項違反
3. 実現可能性（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性
5. リスク（重大な副作用・後退）
6. スコープの適切さ
7. 型安全性（DTO/JsonResource、PHPStan level 10）

【重要な文脈】
本設計は bug-hunt finding の brief（「サーバが成功系で黙ってロールを破棄している。バックエンドで 422 を返すよう修正せよ」）を入力としつつ、設計者が現行コードを実測し、**brief の仮説を訂正**している。設計者の主張は「サーバは既に ValidationException(role) を送出しており(既存テスト `ConsoleRoleTransitionTest` が error bag を固定済み)、真因はフロントの controlled-input 欠陥（拒否された選択値が combobox に残り成功に見える）である。よって修正はフロントに閉じ、バックエンドは変更しない」。この**「brief の要求(バックエンド 422 化)を退けてフロント修正に絞る」判断が妥当か**を特に厳しく検証してほしい。Svelte の一方向 `value` バインドで「権威値が不変(admin→admin)だと DOM が再同期されない」という主張の技術的正しさ、および `{#key}` remount による復帰策の妥当性も評価すること。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、conceptual-design.md 全文）

# 概念設計: member-role-update-feedback

## 背景・課題

bug-hunt(回帰run) F-02(High, `claimed_success_no_change` / H7+H10)。

**症状**: `manage/users`(組織にプロジェクトが1つも無い状態)でメンバーのロールを「管理者」→「編集者/撮影者」に変更すると、`organizations.members.update` (PATCH) が 303 See Other を返し、エラーも成功トーストも無く combobox は変更後の値のまま残る。リロードすると「管理者」に戻っており、変更は保存されていない。owner/admin 両方で再現。

### コード実測による根本原因の特定(briefの仮説の訂正)

brief は「サーバが成功系(303)で黙って破棄している」と仮説を立てているが、現行コードを実測した結果、この仮説は成立しない。

1. サーバは既に検証エラーを返している。`OrganizationMembershipService::applyConsoleRole()`(L247-279)は、editor/shooter コマンドで Default Project が不在(`resolveForUpdate() === null`)の場合、`ValidationException`(`role` キー、メッセージ「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」)を送出し、トランザクションごと rollback する。org ロールも pivot も一切変更されない。
2. エンドポイントは error bag を返している。`tests/Feature/Organization/ConsoleRoleTransitionTest.php` の「endpoint 経由: Default Project 不在の editor コマンドは error bag」テストが `assertSessionHasErrors('role')` で固定済み。org ロールが `Member` のまま(=未変更)も検証済み。
3. Inertia mutation は `Accept: text/html` のため `expectsJson()` は false。よって `ValidationException` は 422 JSON ではなく redirect-back(302→Inertia が 303 化)+ セッション error bag となり、Inertia が `page.props.errors` に共有する。finding が報告する「303 See Other」はまさにこの redirect-back。

つまり「サーバが黙って破棄」ではなく、サーバは正しく拒否しているのにフロントがそれを反映できていないのが真因。

### フロント側の 2 つの欠陥

`resources/js/pages/Admin/Users.svelte` の `changeRole()`(L62-81)とロール `Select`(L272-288):

- (A) combobox が拒否された選択値を保持する(核心): ロール `Select` は `value={member.roleState}` の一方向バインド。ユーザーが「管理者」の行で「編集者」を選ぶと DOM は「編集者」を表示。サーバが拒否して redirect-back すると props 再取得されるが、権威値 `member.roleState` は admin のまま変化しない(rollback 済み)。Svelte は「値が変化したとき」だけ DOM を再同期するため、`value` が admin→admin で不変だとネイティブ `<select>` はユーザー選択(編集者)を保持したまま。
- (B) エラーが combobox から離れた場所に出る: `FormError` は行の左側(email 直下)にあり、Select(右側)から離れている。Select 自体の `error`(aria-invalid)も立てていない。

## 改善アイデア

サーバは既に正しいため変更しない。修正はフロント `Admin/Users.svelte` に閉じる:

1. combobox を権威値へ確実に戻す: `onError` で該当行の `Select` を remount キー(`{#key}`)で作り直し、権威値 `value={member.roleState}` を読み直させる。成功時は props 更新で `member.roleState` 自体が変わるため自然に反映。
2. エラーを combobox 直下に出し Select を invalid 化: `FormError` を Select 直下へ移し、`Select` に `error`(aria-invalid)を渡す。メッセージは `page.props.errors.role` をそのまま表示。

## 期待効果

- 使命への貢献: 現場管理者が「ロール変更が保存されない理由」を即座に理解でき、詰まりを解消。次アクション(プロジェクト作成)へ導く。
- 具体的改善: `claimed_success_no_change` の UX 破綻を解消。拒否時に combobox が元値へ戻り、原因メッセージが combobox 直下に表示。owner/admin・editor/shooter で一貫。

## 実装方針(概要)

- バックエンド: 変更なし。`applyConsoleRole` の `ValidationException`(role)と `ConsoleRoleTransitionTest` の error bag 検証が既に要件を満たす。回帰固定のため「拒否時に success flash を持たない/org ロール不変」を明示する assertion を既存テストに追加するのみ。
- フロント: `Admin/Users.svelte` のみ。`changeRole()` の `onError` で remount トークン更新→権威値へ復帰。`Select` を `error` 化し `FormError` を Select 直下へ。
- 型/Props/DTO: 変更なし。

## 制約・前提

- サーバの検証・トランザクション境界は既存実装が正。バックエンドの挙動は変えない。
- フロントは Svelte 5 runes + DS token のみ。`Select` / `FormError` 既存 atom を再利用。
- 禁止事項 8 を維持: Select は disabled にせず押下時にサーバ error を表示。

## スコープ外

- バックエンドのロール適用ロジック変更。
- Default Project 自動作成やプロジェクト作成導線ボタン新設。
- destroy / 2FA / 招待フロー。
- Settings.svelte 側の類似 UI。
