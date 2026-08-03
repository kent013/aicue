# 概念設計: bugfix-ux-feedback-nav-responsive

bug-hunt (devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md) の Medium finding
F-03 / F-06 / F-08 / F-14 (H13) に対する UX 整備。スコープは UX/表示のみ (ドメインロジック変更なし)。

## 背景・課題

bug-hunt 実走で以下の UX 破綻が確認された:

1. **F-03 (Medium, H7)**: `/email/verify` の「認証メールを再送信」押下後、`POST /email/verification-notification` は
   302 で成功しているのに画面に成功フィードバックが一切出ない。ユーザーは再送信されたか判断できず連打を誘発する。
2. **F-06 (Medium, H7)**: `/forgot-password` の「リセットリンクを送信」押下後も同一パターンで無反応に見える。
   F-03 と根本原因を共有する。
3. **F-08 (Medium, H12)**: ヘッダーナビが画面によって不統一。`/dashboard` には「設定」「ログアウト」があるが、
   `/billing`・`/purchase-tickets`・`/manage/users` 等では「通知」しかなく、ロゴから dashboard に戻らないと
   設定/ログアウトに到達できない。
4. **F-14 (Medium, H13)**: `/manage/users` のメンバー一覧行 (バッジ2種 + 「2FA 解除」+ ロール select + 「削除」) が
   モバイル幅 375px で 93px の横スクロール (`scrollWidth 468 > clientWidth 375`) を起こす。

### 根本原因 (コード調査済み)

- **F-03**: Fortify 既定の `EmailVerificationNotificationController` は
  `back()->with('status', 'verification-link-sent')` を返すが、`HandleInertiaRequests::share()` の flash 共有は
  `success/error/info/warning` の 4 キーのみで `status` を共有しない (flash-to-toast も同 4 キーのみ消費)。
  → toast が出ない。
- **F-06**: 自前の `EnumerationSafePasswordResetLinkResponse` (Fortify contract bind 済み) が
  `back()->with('status', ...)` を返している。フロント (`ForgotPassword.svelte`) のコメントは
  「flash success は AuthLayout の ToastContainer 経由で表示される」と `success` キーを期待しており、
  バックエンドとキーが食い違っている。
  → 既に同種の修正前例あり: `TwoFactorDisabledResponse` は「flash-to-toast は status を意図的に gating
  するため web は success キーへ寄せる」と明記して `back()->with('success', ...)` に差し替え済み。
- **F-08**: `AppLayout.svelte` (認証済み画面共通テンプレート) のヘッダーは
  「通知ベル + `headerActions` snippet」構成で、「設定」「ログアウト」は snippet として
  **Dashboard.svelte だけが**注入している。他の AppLayout 利用ページ (24 ページ) は snippet を渡していないため
  ナビが欠落する。
- **F-14**: `Admin/Users.svelte` のメンバー行が
  `<li class="flex items-center justify-between gap-4">` + 操作ブロック `<div class="flex shrink-0 ...">` で、
  `flex-wrap` も縦積みフォールバックもなく `shrink-0` が幅を強制するため 375px で溢れる。
  招待中一覧の行も同型 (`shrink-0`) で同リスクがある。

## 改善アイデア

### 施策 A: 成功 flash の補完 (F-03 / F-06)

フレームワークのレンジ内 (Fortify Response contract bind) で成功 flash を `success` キーに揃える。
禁止事項 7 準拠 (`redirect()->intended()` は使わず `back()->with(...)` で完結)。

- **F-03**: `App\Http\Responses\Fortify\VerificationNotificationSentResponse` を新設し、
  `Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse` に bind する。
  web は `back()->with('success', '認証メールを再送信しました。')`、
  `expectsJson` は Fortify 既定互換の JSON 202 を維持する (既存 `TwoFactorDisabledResponse` と同型)。
- **F-06**: 既存 `EnumerationSafePasswordResetLinkResponse` の
  `back()->with('status', ...)` を `back()->with('success', ...)` に変更する。
  enumeration 抑止の不変条件 (user 在/不在で同一応答) は維持 (メッセージ・キーとも同一のまま)。

**禁止事項 4 (raw JSON) との関係の明記**: 上記の `new JsonResponse(...)` 分岐は
「Fortify 固定契約 (パッケージが応答形式を規定する仕様固定 endpoint) の互換維持」という
禁止事項 4 の例外に該当する。既存の `TwoFactorDisabledResponse` /
`EnumerationSafePasswordResetLinkResponse` と同じ位置づけであり、
`app/Http/Responses/Fortify/` 配下に閉じる。通常のアプリ endpoint へこのパターンを波及させない
(この位置づけを新 Response class の docblock に明記する)。

**flash キー統一ポリシーの明文化 (再発防止)**: 本修正の設計判断を
「**web 向け操作成功の flash は `success` キーに統一する (`status` は flash-to-toast が意図的に
gating しており toast にならない)**」というポリシーとして固定する。
- `tests/Feature/Auth/FortifyResponseTest.php` を「Fortify Response contract bind の応答契約」の
  正本テストとして拡張し、自前 bind 済み Response 群 (password reset link / verification resend /
  two-factor disabled / recovery codes) が web 応答で `success` flash を持つこと
  (= `status` キーに依存しないこと) を回帰テストとして登録する。
- 同ポリシーは `FortifyResponseTest` の冒頭コメントと各 Response class の docblock に記録し、
  今後 Fortify 応答を bind する際の参照点とする。

toast 表示側は既存機構 (`HandleInertiaRequests` の flash 共有 → `consumeFlash` → `ToastContainer`) を
そのまま使う。`/email/verify` は `AuthLayout`、`/forgot-password` も `AuthLayout` で
どちらも `ToastContainer` + `consumeFlash` 配線済みのためフロント変更は不要。

### 施策 B: ヘッダーナビの統一 (F-08)

「設定」「ログアウト」を Dashboard の page-local snippet から `AppLayout.svelte` 本体へ昇格し、
ログイン中 (`auth.user != null`、通知ベルと同じゲート) は全 AppLayout ページで常設する。

- `AppLayout.svelte`: 通知ベルの隣に「設定」`TextLink` (/settings) と「ログアウト」ghost Button
  (POST /logout、二重送信ガード付き) を追加。`headerActions` snippet はページ固有の追加アクション用として存置。
- `Dashboard.svelte`: 重複する snippet (設定/ログアウト) と logout ロジックを削除。
- ゲスト到達がある `invitations.accept` (AppLayout 利用・auth 任意) では auth.user が無いので出ない
  (通知ベルと同じ挙動で一貫)。

**モバイル幅 (375px) のヘッダー収まり方針 (先に固定する)**:
- ヘッダー行は「ロゴ = `shrink-0`」+「右側アクション群 = `flex flex-wrap items-center justify-end
  gap-x-3 gap-y-1`」とし、収まらない場合は右側アクション群が**行内折り返し** (2 段化) する。
  メニュー化 (ハンバーガー) は Phase 2 のサイドバー拡張と競合するため今回は採らない。
- 常設要素は「ベル (アイコンのみ) + 設定 (テキストリンク) + ログアウト (ghost/sm ボタン)」の 3 点で、
  375px でも 1 行に収まる想定だが、`headerActions` を併用するページが増えても折り返しで破綻しない
  ことを上記方針で構造的に保証する。
- 現在 `headerActions` を渡しているのは Dashboard のみ (本施策で削除) のため、適用後の併用ページは
  0 件。snippet 契約は optional のまま維持する。

**ログアウト処理の共通化**: logout POST は Dashboard からの移植ではなく、`AppLayout` 内の
単一ハンドラ (Inertia `router.post('/logout')` + 実行中フラグの二重送信ガード) に一本化する。
ページ側に logout 実装を残さない (再重複の防止)。

### 施策 C: manage.users のレスポンシブ対応 (F-14)

`Admin/Users.svelte` のメンバー行・招待行を、モバイル幅では縦積み・広い幅では現行の横並びに切り替える
(DS token の範囲 = Tailwind レイアウトユーティリティのみ。色/角丸/タイポは変更しない)。

- メンバー行 `<li>`: `flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4`
- メンバー操作ブロック: `shrink-0` を外し `flex flex-wrap items-center gap-2` (要素単位で折り返し可能に)
- 招待行も同型の縦積みフォールバックを適用
- 長い可変テキスト (メールアドレス・名前) は既存の `min-w-0` + `truncate` を維持し、将来の多言語ラベル・
  長文でも折り返し/省略で吸収できる構造にする (固定幅を新設しない)

## 期待効果

- **使命への貢献**: 「専門知識ゼロの現場作業者」が対象ユーザーであるため、操作結果が分からない・
  ログアウト導線が消える・スマホで画面が溢れる、という基礎 UX の破綻は North Star (思考ゼロで使える) を
  直接毀損する。3 施策はいずれもその足元を直す。
- F-03/F-06: メール送信系操作の成否が toast で即座に分かり、成功が見えないことによる不要な再試行を低減する
  (送信制御そのものは既存の loading ガード/throttle の責務で、本施策の保証範囲ではない)。
- F-08: 全業務画面から設定/ログアウトへ 1 クリックで到達可能になり、ナビの一貫性が回復。
- F-14: スマホからのメンバー管理 (現場管理者の主要環境) で横スクロールが消える。

## 実装方針（概要）

| finding | 変更ファイル | 変更内容 |
|---------|------------|---------|
| F-03 | `app/Http/Responses/Fortify/VerificationNotificationSentResponse.php` (新規) / `app/Providers/FortifyServiceProvider.php` | contract bind 追加。web=success flash、JSON=既定互換 |
| F-06 | `app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php` | flash キー `status`→`success` (メッセージ不変) |
| F-08 | `resources/js/components/templates/AppLayout.svelte` / `resources/js/pages/Dashboard.svelte` | 設定/ログアウトをレイアウト常設化、Dashboard の重複 snippet 削除 |
| F-14 | `resources/js/pages/Admin/Users.svelte` | メンバー行・招待行の縦積みフォールバック + flex-wrap |

**型安全性の方針**:

- `AppLayout.svelte` の `auth` 参照は既存の `SharedProps` / `AuthUser` 型 (`resources/js/lib/shared-props.ts`、
  backend の `HandleInertiaRequests` が真実) を使い、現行の場当たり的なインラインキャストを
  `page.props as unknown as SharedProps` (既存ページと同じ流儀) に置き換える。`any` は使わない。
- `headerActions` は既存の `Snippet` 型 optional prop を維持 (契約変更なし)。
- PHP 側の新 Response class は既存 Fortify Response パターンに閉じる
  (`toResponse(Request): JsonResponse|RedirectResponse`、`declare(strict_types=1)`、final class)。

テスト:

- **Feature (Pest)**: 認証メール再送で `success` flash が載ること / forgot-password が user 在/不在とも
  同一の `success` flash であること (既存 `FortifyResponseTest` の `status` アサーションを更新) /
  bind 済み Fortify Response 群の `success` flash 契約 (flash キー統一ポリシーの回帰防止)
- **Vitest**: AppLayout がログイン中に設定リンク (/settings) とログアウトボタンを描画すること
  (auth 無しでは出ないこと) / Dashboard から重複ナビが消えたことの回帰 /
  Admin/Users のメンバー行がモバイル縦積みクラス (`flex-col` + `sm:flex-row`) と操作ブロック `flex-wrap` を
  持つこと (jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして検証)
- **出口条件 (実装 Phase)**: クラス不変条件はプロキシのため、実装時に実ブラウザ観察
  (375px で `document.body.scrollWidth <= clientWidth`) を verify 手順に含め、
  bug-hunt 再走行での F-14 消込を最終確認とする

## 制約・前提

- 禁止事項 7: 操作系 POST は `back()->with(...)` で完結 (`redirect()->intended()` 不使用) — 施策 A は準拠
- 禁止事項 8: disabled ボタン UI 禁止 — 変更なし (既存の loading ガードのみ)
- DS token/ramp のみ (DESIGN.md canonical、ds-purity テスト)。アイコン追加時は `@lucide/svelte` のみ
- atomic 階層 (templates は pages から利用) を逆流しない — AppLayout への昇格は templates 層内で完結
- Fortify contract bind は既存パターン (`FortifyServiceProvider::register()` + `app/Http/Responses/Fortify/`) を踏襲
- enumeration 抑止 (F-06 応答の user 在/不在同一性) は維持する
- PHPStan level 10 / Pint / eslint / typecheck 全 green

## スコープ外

- F-02 (バリデーション未翻訳)・F-05/F-07 (課金/プロジェクト作成詰み)・F-11 (confirm-password 500) 等の
  他 finding (別設計 20260712-0925/0926/0927 で対応)
- TODO 登録・実装 (本 Phase は設計のみ)
- 通知センター/サイドバー等ヘッダーの機能拡張 (Phase 2 拡張点はコメントどおり温存)
- manage.users 以外の画面の網羅的レスポンシブ監査 (bug-hunt で問題が確認されたのは manage.users のみ)
