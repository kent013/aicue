# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」を掲げる。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 役割

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC。本件はフロントのみの軽微 UI 修正 2 件 (PHP 変更なし)。

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性 (本件は PHP 変更なし。TypeScript 型の妥当性を見る)
4. テスト計画の網羅性 (各施策に vitest テスト)
5. DTO/JsonResource パターンの遵守 (本件は非該当だが逸脱がないか)
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク (特にレスポンシブ折り返し挙動、DOM 構造変化)
8. 波及変更の網羅性 (TypeScript 型定義、テストが変更対象に含まれているか)
9. セキュリティ (本件はパスワード入力 UI。表示トグルの autocomplete/aria の妥当性)
10. DESIGN.md 準拠 (token 経由参照か、hex 直書きを増やさないか)
11. Atomic Design 準拠 (atoms/molecules/organisms/templates の責務分離、単方向 import、Lucide 前提)

【特に厳しく見てほしい点】
- S1 の Tailwind クラス変更 (`sm:min-w-40` + `sm:flex-wrap`) が本当に「768px で回り込み / 834px で 1 行維持」を満たすか、`sm:justify-between` + `flex-wrap` の相互作用に破綻がないか、`min-w-40` が Tailwind の既定 spacing scale に存在するか。
- S1 のテストが jsdom (レイアウト非計算) でクラス不変条件のみを見る妥当性、回帰防止として十分か。
- S2 の `PasswordInput` 差し替えで `bind:value` / `autocomplete` / `aria-describedby` / `error` / 送信配線 (`passwordForm.put`) が既存 `Input` と等価に保たれるか。SettingsIndex.test.ts の useForm fake が password form (current_password/password キー) をどう捕捉するか。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# user: 詳細設計書 + 関連する現行コード

（詳細設計書の全文と現行コードを以下に添付する。ファイル実体もリポジトリ内で参照可能:
resources/js/pages/Admin/Users.svelte / resources/js/pages/Settings/Index.svelte /
resources/js/components/molecules/PasswordInput.svelte / resources/js/pages/Auth/Login.svelte /
tests/js/pages/AdminUsers.test.ts / tests/js/pages/SettingsIndex.test.ts /
tests/js/components/molecules/PasswordInput.test.ts / DESIGN.md）

==== detailed-design.md 全文 ====

# 詳細設計: minor-ui-polish (F-3-02 + F-4-03)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本件は上記を支える **UI 一貫性・可読性** の軽微 (Low) 改善。同種入力 (パスワード) の
操作モデルを全画面で揃え、管理画面でメンバー識別を誤らせない。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

→ 本件はフロント表示のみ。1・8 が関係する (テスト必須 / disabled 不使用は維持)。
2〜7 はバックエンド無変更のため非該当。

### コーディングルール

- フロントは Svelte 5 runes + DS token/ramp のみ (`DESIGN.md` が canonical、ds-purity テスト)。
  フォームは FormField / atom 経由。
- component 階層は `atoms → molecules → organisms → features → templates → pages` の
  単方向 import。アイコンは `@lucide/svelte` のみ、SVG 直書き新設禁止。
- 検証コマンド: `pnpm test` / `pnpm typecheck` / `pnpm lint` / `pnpm build`（全 green でコミット）。
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript。本件は PHP 側変更なし。

## 概念設計リファレンス

- `devnotes/20260714-1338-minor-ui-polish/conceptual-design.md`
- 概念レビュー: `conceptual-review-round-1.md` (**APPROVED**, Round 1)
- 対応マトリクス: `codex-history/conceptual-review-decisions-round-1.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | manage/users 名前・メール列の tablet 過剰 truncate 解消 (F-3-02) | `resources/js/pages/Admin/Users.svelte`, `tests/js/pages/AdminUsers.test.ts` | Low |
| S2 | /settings パスワード変更フォームに表示トグル追加 (F-4-03) | `resources/js/pages/Settings/Index.svelte`, `tests/js/pages/SettingsIndex.test.ts` | Low |

---

## S1: manage/users 名前・メール列の tablet 過剰 truncate 解消 (F-3-02)

### 受け入れ条件 (概念レビュー Warning を数値化)

- (a) 768px 相当で、メンバー名列・招待メール列が **最小幅の床 `min-w-40` (10rem/160px)** を保ち、
  `min-w-0` による無制限の潰れ (「Unverified User」→「Un...」) が起きない。
- (b) 名前/メールと操作ブロックが 1 行に収まらない幅では、**操作ブロックが次行へ回り込む**
  (行レベル `sm:flex-wrap`)。横スクロール (F-14) を新たに生まない。
- (c) 床は最小限に留め、**834px 前後 (iPad portrait) では 1 行を保ち**、768px 前後で回り込む。
- (d) 横並び発火ブレークポイント `sm:flex-row` は変更しない (既存テスト不変条件を維持)。

### 変更箇所

- ファイル: `resources/js/pages/Admin/Users.svelte`
  - メンバー行 `<li>` (L270-272 付近)
  - メンバー名列 wrapper `<div class="min-w-0">` (L273)
  - 招待行 `<li>` (L421-423 付近)
  - 招待メール `<p class="min-w-0 truncate text-body">` (L424)

### 波及変更

- TypeScript 型定義: なし (Props 契約・`MemberRow`/`InvitationRow` 不変)。
- API Resource/DTO: なし (サーバ無変更)。
- テストファイル: `tests/js/pages/AdminUsers.test.ts` に最小幅クラス + `sm:flex-wrap`
  検証をメンバー行・招待行の両方へ追加 (下記テスト計画)。

### 現行コード

メンバー行 (抜粋):
```svelte
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
>
    <div class="min-w-0">
        <div class="flex items-center gap-2">
            <p class="truncate text-body">{member.name}</p>
            ...badges...
        </div>
        <p class="truncate text-caption text-text-secondary">{member.email}</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
        ...actions...
    </div>
</li>
```

招待行 (抜粋):
```svelte
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
>
    <p class="min-w-0 truncate text-body">{invitation.email}</p>
    <div class="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
        ...
    </div>
</li>
```

### 変更後コード

メンバー行: `<li>` に `sm:flex-wrap` を追加し、名前列 wrapper に `sm:min-w-40` の床を与える。
```svelte
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-4"
>
    <div class="min-w-0 sm:min-w-40">
        <div class="flex items-center gap-2">
            <p class="truncate text-body">{member.name}</p>
            ...badges...
        </div>
        <p class="truncate text-caption text-text-secondary">{member.email}</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
        ...actions...
    </div>
</li>
```

招待行: 同様に `sm:flex-wrap` を `<li>` に、`sm:min-w-40` を email `<p>` に追加。
```svelte
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-4"
>
    <p class="min-w-0 truncate text-body sm:min-w-40">{invitation.email}</p>
    <div class="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
        ...
    </div>
</li>
```

#### 設計判断の根拠 (レイアウト挙動)

- 現状: 名前列 `min-w-0` は flex item の min-width:auto を 0 に緩め、`truncate` を成立させる。
  しかし床が 0 のため、操作ブロック (`sm:shrink-0` = 縮まない) に押されて数文字まで潰れる。
- 変更: `sm:min-w-40` で **可読な床 (10rem)** を与える。`min-w-0` はモバイル (縦積み) 用に残し、
  sm 以上で `sm:min-w-40` が後勝ちする (Tailwind の同プロパティ最終宣言)。`truncate` は
  床超過の長い名前の保険として維持 (床 = 下限、truncate = 上限の省略)。
- `sm:flex-wrap` を `<li>` に付与: 名前列 (床 10rem) と操作ブロック (shrink-0) の合計が
  行幅を超えると、操作ブロックが **次行へ回り込む** (潰れでも横スクロールでもなく折り返し)。
  `sm:justify-between` は wrap 時も各行内で成立し破綻しない。
- 値選定 (`min-w-40` = 160px): 「Unverified User」相当 (text-body) が識別可能な下限。
  834px (content ≒ 560px 見込み) では名前床 160 + gap + 操作ブロック実測が 1 行に収まり、
  768px (content ≒ 480px 見込み) で回り込む — 概念レビュー Warning の「早すぎる折り返し」を回避。
  実装時に 768/834 の 2 点で目視確認する (下記テスト計画の手動確認項目)。

### PHPStan 適合チェック

- 本施策は Svelte/TSX のみ。PHP 変更なし → PHPStan 影響なし。
- TypeScript: Props 型・イベント契約は不変。`pnpm typecheck` green を維持。

### テスト計画

- [ ] 既存 `tests/js/pages/AdminUsers.test.ts` の既存 2 ケース
  (「メンバー行はモバイル縦積みクラス…」「招待行もモバイル縦積みクラス…」) は
  `flex-col sm:flex-row` / 操作ブロック `flex-wrap` を assert 済み → **維持** (破壊しない)。
- [ ] 上記 2 ケースに **`<li>` が `sm:flex-wrap` を持つ** assertion を追記
  (行レベル折り返しの回帰防止)。
- [ ] 新規: 「メンバー名列が sm 以上の最小幅の床を持つ」— 名前列 wrapper が
  `min-w-0 sm:min-w-40` を持つ (`toHaveClass("sm:min-w-40")`)。対象は
  `member.name` を含む wrapper を data 起点で特定 (owner 行 `owner@example.com` の親)。
- [ ] 新規: 「招待メール列が sm 以上の最小幅の床を持つ」— email `<p>` が
  `sm:min-w-40` を持つ。
- [ ] 手動確認 (実装 PR 時): 768px で操作ブロックが回り込み名前が読める / 834px で 1 行維持。
- [ ] 個別 `DatabaseTransactions` 不使用 — 本件 JS テストのみ、非該当。

### リスク

- `sm:min-w-40` が大きすぎると 834px でも回り込む懸念 → 値を 10rem に抑え 2 点目視で担保。
  必要なら `min-w-36`(9rem) へ微調整可 (テストのクラス名も合わせて更新)。
- jsdom はレイアウト計算しないため、テストはクラス不変条件を回り込みのプロキシとする
  (既存テストと同じ方針。実挙動は手動確認で補完)。

---

## S2: /settings パスワード変更フォームに表示トグル追加 (F-4-03)

### 変更箇所

- ファイル: `resources/js/pages/Settings/Index.svelte`
  - import 追加: `PasswordInput`
  - 「現在のパスワード」FormField 内 `Input type="password"` (L199-207)
  - 「新しいパスワード」FormField 内 `Input type="password"` (L215-223)

### 波及変更

- TypeScript 型定義: なし。`PasswordInput` の Props は `id`/`value`(bindable)/`error`/
  `disabled`/`class`/`testId` + 残余 HTMLInputAttributes。既存 `Input` と同等契約で、
  `Auth/Login.svelte` が同パターンで使用済み。
- API Resource/DTO: なし (`passwordForm.put('/user/password')` の送信配線は不変)。
- テストファイル: `tests/js/pages/SettingsIndex.test.ts` にトグル + 送信配線検証を追加。

### 現行コード

```svelte
import Input from "@/components/atoms/Input.svelte";
...
<FormField label="現在のパスワード" id="current-password" error={passwordForm.errors.current_password}>
    {#snippet children({ id, describedBy, invalid })}
        <Input
            {id}
            type="password"
            bind:value={passwordForm.current_password}
            error={invalid}
            aria-describedby={describedBy}
            autocomplete="current-password"
        />
    {/snippet}
</FormField>
<FormField label="新しいパスワード" id="new-password" error={passwordForm.errors.password}>
    {#snippet children({ id, describedBy, invalid })}
        <Input
            {id}
            type="password"
            bind:value={passwordForm.password}
            error={invalid}
            aria-describedby={describedBy}
            autocomplete="new-password"
        />
    {/snippet}
</FormField>
```

### 変更後コード

`Input` を `PasswordInput` に差し替える (`type` は PasswordInput が内部管理するため渡さない)。
```svelte
import PasswordInput from "@/components/molecules/PasswordInput.svelte";
// 注: Input の import は他用途 (プロフィールの名前/メール) で引き続き使用するため残す。
...
<FormField label="現在のパスワード" id="current-password" error={passwordForm.errors.current_password}>
    {#snippet children({ id, describedBy, invalid })}
        <PasswordInput
            {id}
            bind:value={passwordForm.current_password}
            error={invalid}
            aria-describedby={describedBy}
            autocomplete="current-password"
        />
    {/snippet}
</FormField>
<FormField label="新しいパスワード" id="new-password" error={passwordForm.errors.password}>
    {#snippet children({ id, describedBy, invalid })}
        <PasswordInput
            {id}
            bind:value={passwordForm.password}
            error={invalid}
            aria-describedby={describedBy}
            autocomplete="new-password"
        />
    {/snippet}
</FormField>
```

#### 設計判断の根拠

- `PasswordInput` は Login/Register/ResetPassword/ConfirmRecentAuth で実績のある molecule。
  Settings のパスワード変更フォームだけが素の `Input` で非一貫 → molecule 再利用で解消。
- Atomic Design: pages → molecules の単方向 import。Lucide eye/eye-off は PasswordInput
  内で完結し、pages に SVG を新設しない。
- FormField の配線 (label for / error / aria-describedby) は PasswordInput が Input へ
  透過するため、既存の `{id}`/`describedBy`/`invalid` snippet 契約をそのまま使える。
- `type` prop は PasswordInput が `visible` 状態から内部導出するため呼び出し側から渡さない
  (PasswordInput の Props は `type` を Omit している = 型でも渡せない)。

### PHPStan 適合チェック

- PHP 変更なし → PHPStan 影響なし。
- TypeScript: `PasswordInput` の Props は `type`/`value`/`class`/`id`/`disabled` を Omit した
  `HTMLInputAttributes` + 明示 Props。`autocomplete`/`aria-describedby` は残余属性として型上許容。
  `pnpm typecheck` green を維持。

### テスト計画

- [ ] 新規 (SettingsIndex.test.ts): 「パスワード変更フォームの 2 入力が表示トグル付き
  (PasswordInput) で描画される」— `getByLabelText("現在のパスワード")` /
  `getByLabelText("新しいパスワード")` が `type=password` で、各々に
  `aria-label="パスワードを表示"` のトグルボタンが存在する。
- [ ] 新規: 「トグルで type が password↔text に切り替わる」— 現在/新しい 各フィールドの
  トグルを click → 対応 input の `type` が `text` になり、再 click で `password` に戻る。
  (2 フィールドのトグルが独立=相互干渉しないことも確認)
- [ ] 新規: 「送信配線が維持される」— useForm fake の `put` を spy し、パスワード変更フォーム
  submit で `passwordForm.put('/user/password', { errorBag: 'updatePassword', ... })` が
  1 回呼ばれる (差し替えで送信契約が壊れていないことの回帰)。
  ※ 既存 SettingsIndex.test.ts の useForm fake は `email` キーで profileForm を holder に
    捕捉する。パスワード form は `current_password`/`password` キー → holder に
    `password` 用スロットを足すか、submit 経由で put spy を参照する形にする。
- [ ] 既存の PasswordInput 単体テスト (`PasswordInput.test.ts`) は molecule の
  トグル挙動を担保済み → S2 では「Settings が molecule を使っている」統合面を検証。
- [ ] 個別 `DatabaseTransactions` 不使用 — JS テストのみ、非該当。

### リスク

- DOM 構造が `Input` 直下 → `div.relative > Input + button` に変わり、`getByLabelText` は
  label の `for`=input id で解決するため影響なし。トグルボタンの絶対配置は既存 molecule で
  検証済み → レイアウトずれは軽微。
- `PasswordInput` は `class` を wrapper に付与する Props を持つが本件では未使用 (既定 relative)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 2 施策とも独立した既存 page の局所的クラス調整 / molecule 差し替えで、共有基盤・型・API に触れない。ロールバックも局所。段階適用でリスクなし。 |
| 競合リスク | S1 (Admin/Users.svelte) と S2 (Settings/Index.svelte) は別ファイルで干渉なし。各々のテストファイルも別。並行実装可だが規模が小さいため 1 worktree での連続実装を推奨。 |

## 使命・禁止事項 最終チェック

- 使命: 「思考ゼロ」で迷わない UI 一貫性・可読性に寄与 (パスワード操作モデル統一 / メンバー識別)。
- 禁止事項 1 (テスト必須): 両施策に vitest テストを計画済み。
- 禁止事項 8 (disabled 不使用): 変更はレイアウト/入力コンポーネントのみ、ボタン disabled 化なし。
- 2〜7: バックエンド無変更のため非該当。
- DESIGN.md/Atomic Design: token 新設・hex 直書きなし、単方向 import 維持、Lucide のみ。
