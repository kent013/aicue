# 対応マトリクス: design-review Round 1

## [Critical] 施策4: joinOrganization の attach 固定は再受諾・並行処理で重複/例外化の余地
- 判断: 対応する
- 根拠: 経路が register / ログイン後 / project_role 付きの 3 系統に増え、顕在化しやすくなるのは指摘どおり。
- 対応内容: org 参加を `$organization->users()->syncWithoutDetaching([$user->getKey()])` へ変更（冪等）。pivot attach は元設計から syncWithoutDetaching。再受諾 race の冪等性テストを追加。

## [Critical] 施策6: orgRole null の異常行を continue で非表示にすると「消えた」ように見える
- 判断: 対応する（可視化 + 復旧導線の案を採用。監査ログ案は不採用）
- 根拠: 管理画面の役割は非正規状態の可視化と正規化（概念設計 D2）。監査ログ新設より一貫する。
- 対応内容: `MemberRoleState::derive()` の第 1 引数を `?OrganizationRole` にし、null → `Unassigned`（org attach 済みだが Laratrust ロール未付与の異常行も「未割当」として表示・修復可能に）。あわせて `applyConsoleRole` に修復経路を追加: 対象が org attach 済みかつ organizationRole null の場合、changeRole（現ロール必須）ではなく `addRole` で直接付与する。修復経路のテストを追加。

## [Critical] 横断: Architecture テストの明示が弱い（禁止事項 1）
- 判断: 対応する
- 対応内容: 以下を設計へ明示追加。
  1. `tests/Architecture/ProjectMemberPivotWritePathTest.php`（新規）: `project_members` pivot への書き込み（attach/detach/sync* / DB::table('project_members')）が許可 inventory（`OrganizationMembershipService` / `ProjectMemberController`）の外に現れたら fail（ScenarioWritePathInventoryTest と同型の deny-by-default 走査）。Codex 概念レビュー Round 4 Suggestion の昇格でもある。
  2. `tests/Architecture/ManageRouteAuthGuardTest.php`（新規）: `/manage/*` の全 route が `auth` + `verified` middleware を持つことを deny-by-default で固定（将来の manage 配下追加の guard 漏れ防止）。
  3. 旧 Settings UI 非並走の Feature 面固定: `settings()` props に `invitations` キーが無い・`members` 行に `email` キーが無いことを Feature テストで assert（Vitest の UI 不在テストと両面）。

## [Warning] 施策3: project_role の DB 制約がアプリ側前提のみ
- 判断: 対応する
- 対応内容: migration に check 制約を追加（`project_role IS NULL OR project_role IN ('project_admin','project_member')`）。

## [Warning] 施策4: detachProjectMemberships の DB::table 直叩きはモデルイベントに弱い
- 判断: 対応する（コメント + テストで契約固定）
- 根拠: belongsToMany の detach も pivot モデルイベントは発火しない（pivot は Eloquent モデルを持たない）ため実質差はないが、意図の明文化は有益。
- 対応内容: 「pivot に対応する Eloquent モデル・イベントは存在せず、意図的に素の delete を使う」コメントを設計へ明記。挙動は ConsoleRoleTransitionTest で固定。

## [Warning] 施策5: FormRequest authorize(): true の認可責務の明記
- 判断: 対応する
- 対応内容: class doc に「認可は Controller の Gate::authorize が唯一の責務（FormRequest では判定しない）」を明記。

## [Suggestion] 施策5: 旧値送信時のエラー文言
- 判断: 対応する
- 対応内容: `role.Illuminate\Validation\Rules\Enum` のカスタムメッセージ「ロールの指定が不正です。画面を再読み込みしてやり直してください。」を FormRequest messages() に追加。

## [Warning] 施策6: categoriesUrl の文字列直組み立て
- 判断: 対応する
- 対応内容: `route('projects.categories.index', $project)` で生成（施策 8 の usersUrl も `route('manage.users.index')`）。

## [Warning] 施策7: loading と二重送信抑止の境界
- 判断: 対応する
- 対応内容: 各 submit ハンドラ冒頭に `if (form.processing) return;` の冪等ガードを明示（disabled は使わない）。

## [Warning] 施策9: Show 撤去の回帰リスク
- 判断: 対応する
- 対応内容: カテゴリフィルタの「存続テスト」（`manual-filter-category` が描画され選択肢に categories が出る）を ProjectsShow.test.ts 更新項目へ追加。

## [Warning] 横断: URL 文字列直書き
- 判断: 部分的に対応する
- 根拠: サーバ側は route() helper へ統一（上記）。フロント側の literal path は本リポジトリの既存規約（Settings.svelte / Show.svelte とも literal。ziggy 未導入）であり、本フィーチャだけ別方式を持ち込む方が drift。
- 対応内容: サーバ側のみ route() 化。フロントは既存規約踏襲を明記。

## [Warning] 横断: Admin/Users 閲覧の監査ログ
- 判断: 見送る（根拠を明記）
- 根拠: SecurityEventRecorder は状態変更イベント（2FA リセット・オーナー移譲等）用で、画面閲覧の監査基盤はテンプレに存在しない（既存の org settings のメンバー email 表示も記録していない）。閲覧できるのは manageMembers 権限者のみで、既存 PII 露出面（settings）より縮小している。閲覧監査は org 全体の要件として別途設計すべきで、本フィーチャで単発導入しない（AGENTS.md 思考原則 2: 今必要なものだけ作る）。

## [Suggestion] テスト名（admin_ignores_project_pivot / authorize前404）・architecture.md への A+B 不可分理由・AdminMenuNav props strict null
- 判断: 対応する（テスト計画・ドキュメント節へ反映）

## [Suggestion] resolveForUpdate の transactionLevel ランタイムガード
- 判断: 見送る（必須ではないと明示されており、呼び出し経路が Service 2 箇所 + Architecture テストで固定されるため過剰）
