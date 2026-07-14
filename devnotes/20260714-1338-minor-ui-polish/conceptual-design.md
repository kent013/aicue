# 概念設計: minor-ui-polish (F-3-02 + F-4-03)

## 背景・課題

bug-hunt (real-llm run) が検出した軽微 (Low) な UI 一貫性の綻び 2 件。いずれも
「見た目のみ・操作阻害なし」だが、標準化された体験を掲げるプロダクトとして
一貫性の欠けは信頼感を損なう。

### F-3-02 (Low, H11+H13): manage/users の名前が tablet 幅で過剰 truncate
- 画面: `manage/users` (`resources/js/pages/Admin/Users.svelte`, Inertia page `Admin/Users`)
- 症状: tablet 幅 (768px 相当) でメンバー名が過剰に truncate される
  (例「Unverified User」→「Un...」)。読めない。
- 根本原因: メンバー行 (`<li>`) は `sm:flex-row sm:justify-between` で 640px 以上を
  横並びにする。一方、この画面は管理メニュー用の左サイドバー `<aside class="md:w-56">`
  を **md (768px) 以上で** 表示する。結果、ちょうど 768px 付近で
  「サイドバー (w-56 ≒ 224px) が本文幅を削る」×「行はすでに横並び」が重なり、
  名前列 (`min-w-0` で無制限に縮む) が操作ブロック (`sm:shrink-0` = 縮まない) に
  押されて数文字まで潰れる。招待中一覧の email 列も同一構造で同じ潜在バグを持つ。

### F-4-03 (Low, H12): /settings のパスワード変更欄に表示トグルが無い
- 画面: `/settings` プロフィール (`resources/js/pages/Settings/Index.svelte`) の
  「パスワード変更」カード。
- 症状: 「現在のパスワード」「新しいパスワード」の 2 入力が素の
  `Input type="password"` で、ログイン画面 (`Auth/Login.svelte`) にはある
  「パスワードを表示」トグルが無い。同じパスワード入力なのに UI が非一貫。
- 既存資産: 表示トグル付きパスワード入力は
  `resources/js/components/molecules/PasswordInput.svelte` として molecule 化済み。
  Login/Register/ResetPassword/ConfirmRecentAuth が既に再利用している。
  Settings のパスワード変更フォームだけが取り残されている。

## 改善アイデア

いずれも **既存資産の再利用** と **最小の Tailwind クラス調整** で閉じる。新規
コンポーネント・新規トークン・API/DTO 変更は不要。

### F-3-02: 名前列に可読な最小幅の床を与え、操作ブロックを回り込ませる
- メンバー行 / 招待行の `<li>` に **行レベルの折り返し (`sm:flex-wrap`)** を許可する。
- 名前 (email) 列に **sm 以上で最小幅の床 (`sm:min-w-*`)** を与え、`min-w-0` による
  無制限の潰れを止める。名前が入りきらない幅では操作ブロックが次行へ回り込み、
  名前は最低限読める文字数を確保する (`truncate` は極端に長い名前の保険として残す)。
- 横並びの発火ブレークポイント (`sm:flex-row`) は **変更しない** →
  既存 AdminUsers テストのブレークポイント不変条件を保ちつつ、潰れだけを解消する。

### F-4-03: Settings のパスワード変更フォームで PasswordInput molecule を再利用
- `Settings/Index.svelte` の「現在のパスワード」「新しいパスワード」の
  `Input type="password"` を、Login 画面と同じ `PasswordInput` molecule に差し替える。
- `id` / `autocomplete` (`current-password` / `new-password`) / `error` /
  `aria-describedby` は現行 FormField 配線をそのまま透過する (PasswordInput が透過対応済み)。

## 期待効果

- **一貫性 (使命への貢献)**: 「思考ゼロ」で迷わず使えるプロダクトとして、同種入力の
  UI を全画面で揃える。パスワード入力の表示トグルが全経路で一貫する。
- **可読性**: tablet でメンバー名 / 招待メールが読める。管理者が誰に何をするかを
  取り違えない (誤操作リスクの芽を摘む)。
- **副作用最小**: 既存 molecule / Tailwind utility の範囲内。トークン・API 不変。

## 実装方針（概要）

| 施策 | 変更 | 種別 |
|------|------|------|
| F-3-02 | `Admin/Users.svelte`: メンバー行・招待行の名前/email 列に `sm:min-w-*` の床、`<li>` に `sm:flex-wrap` | Tailwind クラス調整 |
| F-4-03 | `Settings/Index.svelte`: パスワード 2 入力を `PasswordInput` molecule に差し替え | 既存 molecule 再利用 |

- Atomic Design: `PasswordInput` は既存 molecule、`Admin/Users` / `Settings/Index` は
  pages。単方向 import (pages → molecules) を維持。新規 SVG 内包なし (Lucide の
  eye/eye-off は PasswordInput 内で既に完結)。
- DESIGN.md: color/radius/typography トークンの新規追加・hex 直書きは無し。
  spacing は Tailwind の min-w utility (既存スケール) のみ。

## 制約・前提

- PHP/バックエンド変更なし (フロント表示のみ)。PHPStan/Pest(PHP) への影響なし。
- 既存 vitest テスト:
  - `AdminUsers.test.ts` は行が `flex-col sm:flex-row`、操作ブロックが `flex-wrap` を
    持つことを assert 済み → ブレークポイント (`sm:flex-row`) を変えない方針なので
    これらは維持。新たに「名前列が最小幅の床クラスを持つ」検証を追加する。
  - `SettingsIndex.test.ts` は useForm を fake 差し替え。PasswordInput 差し替え後も
    `screen.getByLabelText`/type 切替の検証を追加できる。
- 検証: `pnpm test` (vitest) / `pnpm typecheck` / `pnpm lint` / `pnpm build` 全 green。

## スコープ外

- パスワード強度メーター等の新 UI (今回の一貫性修正に不要)。
- Users 画面のロール変更・2FA リセット等の挙動 (今回は列レイアウトのみ)。
- サイドバー (`AdminMenuNav`) 自体のレスポンシブ再設計。
- 他画面の Input→PasswordInput 移行 (該当は Settings のみ、他は移行済み)。
