# 詳細設計レビュー Round 2 (対応反映後の再レビュー依頼)

Round 1 の指摘 (Critical 1 / Warning 4 / Suggestion 3) をすべて設計へ反映しました。対応マトリクスと修正後の該当セクションを提示します。全体判定の再評価をお願いします。

## 対応マトリクス

| # | 指摘 | 判断 | 対応 |
|---|------|------|------|
| 1 | [Critical] A1: Feature テストの auth/throttle 前提の固定不足 | 対応 | テスト計画に前提を固定: `verification.send` は `auth:web` + `throttle:6,1`（fortify.limiters.verification 既定、routes.php L98-100）。`actingAs(User::factory()->unverified()->create())` で **1 リクエストのみ**発行し throttle 上限に構造的に触れない設計（`withoutMiddleware` 等の抑制は使わない）。`assertRedirect('/email/verify')` + `assertSessionHas('success', '認証メールを再送信しました。')` + `assertSessionMissing('status')` + `Notification::assertSentTo($user, VerifyEmail::class)` |
| 2 | [Warning] A1: wantsJson 採用理由の記録 | 対応 | テストコメントに「JSON 分岐は Fortify 元実装互換のため wantsJson/202 を維持（既存 3 クラスの expectsJson とあえて揃えない）」と明記する旨を設計に追加 |
| 3 | [Warning] A2: 両ケースで success 一致 + status 不在を対で検証 | 対応 | 「user 在/不在の両ケースで対に `assertSessionHas('success', 同一文言)` + `assertSessionMissing('status')`」に更新（同一メッセージ + 同一キーの両方を固定。片側だけ status が残る誤実装も検出） |
| 4 | [Suggestion] A2: STATUS_MESSAGE の意味を docblock に追記 | 対応 | 「`STATUS_MESSAGE` は Fortify の status 言語キーに対応するメッセージ内容の意味であり、flash キー名 (success) とは無関係」と docblock 追記を設計に反映 |
| 5 | [Warning] B: notifications 未定義ケースのテスト固定 | 対応 | AppLayout.test.ts に「`notifications` が undefined でもクラッシュせず unreadCount 0 相当で描画する」ケースを追加（closure 共有が partial reload で省略されるケースをカバー） |
| 6 | [Warning] B: headerActions 併用時の重複表示回帰 | 対応 | AppLayout.test.ts に「`headerActions` snippet を渡しても `nav-settings` / `nav-logout` は `getAllByTestId(...).length === 1` でちょうど 1 つずつ」を追加 |
| 7 | [Suggestion] B: Dashboard.test.ts のテスト意図明記 | 対応 | 「logout POST は AppLayout の単一ハンドラの責務であり、Dashboard 内イベントから `router.post('/logout')` を直接呼ばない」ことをコメント明記する旨を追加 |
| 8 | [Warning] C: 対象要素特定の DOM 順序依存 | 対応 | 既存 `data-testid` 起点の traversal に変更: メンバー行は `screen.getByTestId("member-role-3").closest("li")`、操作ブロックは同 select の `parentElement`、招待行は `screen.getByTestId("revoke-invitation-10").closest("li")`。bug-hunt 再現条件（2FA バッジ + 2FA 解除ボタンあり）の行を fixture で用意 |
| 9 | [Suggestion] C: min-w-0 維持の明記 | 現状維持 | 既に設計に明記済み |

## 修正後の該当セクション全文

### 施策 A1 テスト計画（修正後）

> - [ ] 新規 (Pest, `tests/Feature/Auth/FortifyResponseTest.php`):
>   「認証メール再送は success flash を返す (web)」
>   - **前提の固定**: `verification.send` ルートの middleware は `auth:web` + `throttle:6,1`（`config('fortify.limiters.verification')` 既定。`vendor/laravel/fortify/routes/routes.php` L98-100）。テストは **`actingAs(User::factory()->unverified()->create())` の 1 リクエストのみ**発行し、throttle 上限 (6/min) に構造的に触れない設計とする（`withoutMiddleware` 等の抑制は使わない。`RefreshDatabase` はグローバル適用・`--parallel` 実行でユーザー毎にレートキーも独立）
>   - `Notification::fake()` → `$this->from('/email/verify')->post('/email/verification-notification')`
>   - `assertRedirect('/email/verify')`（`back()` 契約）
>   - `assertSessionHas('success', '認証メールを再送信しました。')`
>   - `assertSessionMissing('status')`（flash キー統一ポリシー: status 併用の誤実装を検出）
>   - `Notification::assertSentTo($user, VerifyEmail::class)`（再送自体が起きたことの確認）
>   - テストコメントに「JSON 分岐は Fortify 元実装互換のため wantsJson/202 を維持（既存 3 クラスの expectsJson とあえて揃えない）」を明記し、将来の統一リファクタでの誤変換を防ぐ
> - [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### 施策 A2 変更後コード補足 + テスト計画（修正後）

> あわせて class docblock の「同一の『送信しました』flash」の記述に「flash キーは success（flash-to-toast 消費対象）」であることを追記する。enumeration 抑止の不変条件（user 在/不在で同一メッセージ・同一キー・同一 redirect）は変更しない。定数名 `STATUS_MESSAGE` は enumeration 抑止文言の意味で使われているため改名しない（diff を flash キー変更に限定する）。docblock に「`STATUS_MESSAGE` は Fortify の status 言語キーに対応する**メッセージ内容**の意味であり、flash キー名 (`success`) とは無関係」と 1 行追記して将来の混同を防ぐ。
>
> - [ ] 既存テスト更新 (`tests/Feature/Auth/FortifyResponseTest.php` L27-28): `assertSessionHas('status', ...)` → `assertSessionHas('success', 'パスワードリセット用のリンクをメールで送信しました。')`。**user 在/不在の両ケースで対に** `assertSessionHas('success', 同一文言)` + `assertSessionMissing('status')` を検証する（enumeration 抑止 = 同一メッセージだけでなく**同一キー**であることを固定。片側だけ status が残る誤実装も検出できる）。既存のアサーション構造（existing/missing の対比較）は維持

### 施策 B テスト計画（修正後）

> - [ ] 新規 (Vitest, `tests/js/components/templates/AppLayout.test.ts`):
>   - `page` store を `vi.mock("@inertiajs/svelte", ...)` で差し替え（`readable({ props: { auth: { user: {...} }, appName, notifications: { unreadCount: 0 } } })`。router も mock）。`children` は `createRawSnippet` で渡す
>   - 「ログイン中は 設定 リンク (/settings 宛) と ログアウト ボタンを描画する」（`getByTestId("nav-settings")` の pathname = `/settings`、`getByTestId("nav-logout")` 存在）
>   - 「ログアウトボタン押下で POST /logout が呼ばれる」（router mock で検証）
>   - 「auth.user が null なら 設定/ログアウト/ベル を描画しない」（ゲスト到達ページの回帰）
>   - 「ログアウトボタンは disabled でない」（禁止事項 8 の系）
>   - 「`notifications` が undefined でもクラッシュせず unreadCount 0 相当で描画する」（shared props の閉包 (closure) 共有が partial reload で省略されるケース・テスト環境での未定義ケースの両方をカバー。`shared.notifications?.unreadCount ?? 0` の回帰固定）
>   - 「`headerActions` snippet を渡しても `nav-settings` / `nav-logout` は**ちょうど 1 つずつ**」（`getAllByTestId(...).length === 1`。将来ページ側が設定/ログアウトを再注入した際の重複表示回帰を防ぐ）
> - [ ] 既存更新 (Vitest, `tests/js/pages/Dashboard.test.ts`): Dashboard が page-local の設定/ログアウトを持たないこと（AppLayout 常設化後の重複排除の回帰。page store 未設定 = auth なしの現行テスト環境では `queryByTestId("nav-logout")` が null であることを確認。テスト意図として「logout POST は AppLayout の単一ハンドラの責務であり、Dashboard 内のイベントから `router.post('/logout')` を直接呼ばない」ことをコメントに明記する）
> - [ ] 主要レイアウトへのナビ常設は AppLayout.test.ts が単一の真実（全ページの個別テストは追加しない。24 ページはすべて AppLayout 経由のため template テストで代表する）

### 施策 C テスト計画（修正後）

> - [ ] 既存更新 (Vitest, `tests/js/pages/AdminUsers.test.ts`) に追記:
>   - 「メンバー行はモバイル縦積みクラス (`flex-col` + `sm:flex-row`) を持ち、操作ブロックは `flex-wrap` を持つ」（jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして固定する）
>   - **対象要素の特定は既存 `data-testid` 起点で行い、DOM 順序に依存しない**: メンバー行は `screen.getByTestId("member-role-3").closest("li")`（ロール select 起点）、操作ブロックは同 select の親 div (`element.parentElement`) を辿る。招待行は `screen.getByTestId("revoke-invitation-10").closest("li")` 起点。fixture は既存 `membersFixture` の id=3（未割当）/ id=2 + `twoFactorStatus: "enabled"`（2FA バッジ + 2FA 解除ボタンあり = bug-hunt 再現条件の行）を利用する
>   - 「招待行も同様の縦積みクラスを持つ」（`invitation-list` 側）
> - [ ] 出口条件（実装 Phase の verify 手順）: 実ブラウザ 375px で `/manage/users` を開き、`document.body` / member-list コンテナ / header の `scrollWidth <= clientWidth` を確認。bug-hunt 再走行での F-14 消込を最終確認とする

---

以上で Round 1 の Critical 1 件・Warning 4 件・Suggestion 3 件すべてに対応しました。全体判定をお願いします。
