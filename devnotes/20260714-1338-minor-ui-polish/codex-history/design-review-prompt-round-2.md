# design-review Round 2: 対応マトリクスと修正内容

Round 1 の指摘への対応を報告します。詳細設計 (detailed-design.md) を以下のとおり更新しました。

## [Critical/S1] `sm:min-w-40` の有効性 → 反論 (証拠あり)
本プロジェクトは **Tailwind v4.3.0** (`package.json` 実測 `"tailwindcss": "^4.3.0"`)。
v4 では min-width が spacing スケール (`--spacing` 連動) を共有し `min-w-<n>` = `n×0.25rem`
が有効です。さらにリポジトリ内で **`min-w-40` / `max-w-40` を既に現用**しており
(`grep -oE '(min|max)-w-[0-9]+' resources/js` で `min-w-40`/`max-w-40` を確認、ビルド green 実績)、
`sm:min-w-40` (=10rem) は確実に効きます。v3 前提の Critical は本環境に不適合と判断し、
設計は `sm:min-w-40` を維持。根拠を detailed-design.md の「設計判断の根拠」に明記しました。

## [Warning/S1] `sm:flex-wrap` + `sm:justify-between` の 2 行目整列不安定 → 対応
ご提案どおり `<li>` から `sm:justify-between` を除去し、操作ブロックに `sm:ml-auto` を付与
(メンバー行・招待行の両方)。1 行時は名前左 / 操作右 (従来と同一の見た目)、wrap 時は 2 行目の
操作ブロックが `ml-auto` で右寄せに安定します。既存テストは justify-between を assert して
いないため破壊なし。

## [Warning/S1] jsdom クラス検証のみでは 768/834 を保証できない → 対応
PR 成果物要件に「768px・834px の viewport スクリーンショット 2 点添付」を必須化しました。
vitest はクラス不変条件のプロキシに限定 (既存方針踏襲) と明記。

## [Suggestion/S1] 床付与の要素対称性 → 明記で解消
メンバー行は名前+バッジの wrapper `<div>`、招待行は email 単独の `<p>` が **それぞれ `<li>`
直下の flex 子**であり、床はいずれも「flex 直下の名前/メール列」に付与する点で対称。招待行は
wrapper が無いため `<p>` 直指定が正。設計にこの説明を追記。

## [Warning/S2] useForm fake の password form 捕捉 → 対応
テスト計画を具体化: fake を初期キーで二分岐 (`"email" in initial`→profile /
`"current_password" in initial`→password)、`formHolder.password` に独立捕捉し `put` を独立 spy。

## [Suggestion/S2] autocomplete / aria-describedby 透過の回帰ケース → 追加
現在=`current-password` / 新しい=`new-password` の autocomplete と aria-describedby 保持を
検証するケースを追加。

## [Suggestion/S2] errorBag:'updatePassword' まで assert → 追加
put 検証で第2引数 options に `errorBag:'updatePassword'` を含むことまで固定。

---

更新後の detailed-design.md の該当セクション (S1 変更後コード / 設計判断の根拠 / S1・S2 テスト計画)
を以下に添付します。全体判定を再度お願いします。

---

（以下、更新後 detailed-design.md の S1「変更後コード」〜「設計判断の根拠」および S1/S2「テスト計画」抜粋）

### 変更後コード

メンバー行: `<li>` に `sm:flex-wrap` を追加、`sm:justify-between` を除去。名前列 wrapper に
`sm:min-w-40` の床、操作ブロックに `sm:ml-auto` を付与する
(design-review R1 Warning 反映: wrap 時の整列安定化)。
```svelte
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
>
    <div class="min-w-0 sm:min-w-40">
        <div class="flex items-center gap-2">
            <p class="truncate text-body">{member.name}</p>
            ...badges...
        </div>
        <p class="truncate text-caption text-text-secondary">{member.email}</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 sm:ml-auto sm:shrink-0 sm:justify-end">
        ...actions...
    </div>
</li>
```

招待行: 同様に `sm:flex-wrap` を `<li>` に (`sm:justify-between` を除去)、`sm:min-w-40` を
email `<p>` に、`sm:ml-auto` を操作ブロックに追加。
```svelte
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
>
    <p class="min-w-0 truncate text-body sm:min-w-40">{invitation.email}</p>
    <div class="flex flex-wrap items-center gap-3 sm:ml-auto sm:shrink-0 sm:justify-end">
        ...
    </div>
</li>
```

#### 設計判断の根拠 (レイアウト挙動)

- **`min-w-40` の有効性 (design-review R1 Critical への回答)**: 本プロジェクトは
  **Tailwind v4.3.0** (`package.json`)。v4 は min-width が spacing スケール
  (`--spacing` 連動) を共有し `min-w-<n>` = `n × 0.25rem` が有効。加えて **既存コードで
  `min-w-40` / `max-w-40` を現用中** (`grep -oE '(min|max)-w-[0-9]+'` で確認済み・ビルド実績あり)。
  よって `sm:min-w-40` (= 10rem) は確実に効く。v3 前提の「未定義の可能性」は本環境に不適合。
- 現状: 名前列 `min-w-0` は flex item の min-width:auto を 0 に緩め、`truncate` を成立させる。
  しかし床が 0 のため、操作ブロック (`sm:shrink-0` = 縮まない) に押されて数文字まで潰れる。
- 変更: `sm:min-w-40` で **可読な床 (10rem)** を与える。`min-w-0` はモバイル (縦積み) 用に残し、
  sm 以上で `sm:min-w-40` が後勝ちする (Tailwind の同プロパティ最終宣言)。`truncate` は
  床超過の長い名前の保険として維持 (床 = 下限、truncate = 上限の省略)。
- **`sm:flex-wrap` + `sm:ml-auto` (justify-between の置換)**: 名前列 (床 10rem) と
  操作ブロック (shrink-0) の合計が行幅を超えると操作ブロックが **次行へ回り込む**
  (潰れでも横スクロールでもなく折り返し)。整列は `sm:justify-between` ではなく操作ブロックの
  `sm:ml-auto` で行う → 1 行時は名前左 / 操作右 (見た目は従来と同一)、wrap 時は 2 行目の
  操作ブロックが `ml-auto` で右寄せに安定する (justify-between を wrap 併用したときの
  各行での整列の曖昧さを回避)。
- **要素対称性 (design-review R1 Suggestion)**: メンバー行は名前+バッジをまとめる wrapper
  `<div>`、招待行は email 単独の `<p>` が **それぞれ `<li>` 直下の flex 子** であり、
  床 (`min-w-0 sm:min-w-40`) はいずれも「flex 直下の名前/メール列」に付与する点で構造的に対称。
  招待行は wrapper を持たないため `<p>` へ直指定するのが正しい配置。
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
- [ ] 操作ブロックが `sm:ml-auto` を持つ assertion を追記 (両行、右寄せ整列の回帰防止)。
- [ ] **PR 成果物要件 (design-review R1 Warning 反映)**: jsdom はレイアウト非計算のため、
  受け入れ条件 (c)「768px で回り込み / 834px で 1 行維持」は **768px・834px の viewport
  スクリーンショット 2 点** を PR に添付して担保する (vitest はクラス不変条件のプロキシに限定)。
- [ ] 個別 `DatabaseTransactions` 不使用 — 本件 JS テストのみ、非該当。

### リスク

- `sm:min-w-40` が大きすぎると 834px でも回り込む懸念 → 値を 10rem に抑え 2 点目視で担保。
  必要なら `min-w-36`(9rem) へ微調整可 (テストのクラス名も合わせて更新)。
- jsdom はレイアウト計算しないため、テストはクラス不変条件を回り込みのプロキシとする
  (既存テストと同じ方針。実挙動は手動確認で補完)。

---

## S2: /settings パスワード変更フォームに表示トグル追加 (F-4-03)
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

- [ ] **useForm fake の二分岐化 (design-review R1 Warning 反映)**: 既存 fake は `email` キーで
  profileForm のみ holder 捕捉していた。初期データキーで明確に二分岐する —
  `"email" in initial` → profile / `"current_password" in initial` → password として、
  `formHolder.password` に独立捕捉し `put` を独立 spy とする (profile 検証と相互汚染しない)。
- [ ] 新規: 「パスワード変更フォームの 2 入力が表示トグル付き (PasswordInput) で描画される」—
  `getByLabelText("現在のパスワード")` / `getByLabelText("新しいパスワード")` が `type=password`
  で、各々に `aria-label="パスワードを表示"` のトグルボタンが存在する。
- [ ] 新規: 「トグルで type が password↔text に切り替わる」— 現在/新しい 各フィールドの
  トグルを click → 対応 input の `type` が `text` になり、再 click で `password` に戻る。
  (2 フィールドのトグルが独立=相互干渉しないことも確認)
- [ ] 新規: 「autocomplete / aria-describedby が透過保持される」(design-review R1 Suggestion) —
  現在のパスワード入力が `autocomplete="current-password"`、新しいパスワード入力が
  `autocomplete="new-password"` を持ち、`aria-describedby` (FormField 由来) を保持する
  (PasswordInput の rest props 透過保証の回帰)。
- [ ] 新規: 「送信配線が維持される」— パスワード変更フォーム submit で
  `formHolder.password.put` が `('/user/password', options)` で 1 回呼ばれ、`options` に
  **`errorBag: 'updatePassword'`** を含むことまで assert する (design-review R1 Suggestion:
  ルートだけでなく Inertia options 契約まで固定)。
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

==== S2 テスト計画 ====
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
- [ ] 操作ブロックが `sm:ml-auto` を持つ assertion を追記 (両行、右寄せ整列の回帰防止)。
- [ ] **PR 成果物要件 (design-review R1 Warning 反映)**: jsdom はレイアウト非計算のため、
  受け入れ条件 (c)「768px で回り込み / 834px で 1 行維持」は **768px・834px の viewport
  スクリーンショット 2 点** を PR に添付して担保する (vitest はクラス不変条件のプロキシに限定)。
- [ ] 個別 `DatabaseTransactions` 不使用 — 本件 JS テストのみ、非該当。

### リスク
### テスト計画

- [ ] **useForm fake の二分岐化 (design-review R1 Warning 反映)**: 既存 fake は `email` キーで
  profileForm のみ holder 捕捉していた。初期データキーで明確に二分岐する —
  `"email" in initial` → profile / `"current_password" in initial` → password として、
  `formHolder.password` に独立捕捉し `put` を独立 spy とする (profile 検証と相互汚染しない)。
- [ ] 新規: 「パスワード変更フォームの 2 入力が表示トグル付き (PasswordInput) で描画される」—
  `getByLabelText("現在のパスワード")` / `getByLabelText("新しいパスワード")` が `type=password`
  で、各々に `aria-label="パスワードを表示"` のトグルボタンが存在する。
- [ ] 新規: 「トグルで type が password↔text に切り替わる」— 現在/新しい 各フィールドの
  トグルを click → 対応 input の `type` が `text` になり、再 click で `password` に戻る。
  (2 フィールドのトグルが独立=相互干渉しないことも確認)
- [ ] 新規: 「autocomplete / aria-describedby が透過保持される」(design-review R1 Suggestion) —
  現在のパスワード入力が `autocomplete="current-password"`、新しいパスワード入力が
  `autocomplete="new-password"` を持ち、`aria-describedby` (FormField 由来) を保持する
  (PasswordInput の rest props 透過保証の回帰)。
- [ ] 新規: 「送信配線が維持される」— パスワード変更フォーム submit で
  `formHolder.password.put` が `('/user/password', options)` で 1 回呼ばれ、`options` に
  **`errorBag: 'updatePassword'`** を含むことまで assert する (design-review R1 Suggestion:
  ルートだけでなく Inertia options 契約まで固定)。
- [ ] 既存の PasswordInput 単体テスト (`PasswordInput.test.ts`) は molecule の
  トグル挙動を担保済み → S2 では「Settings が molecule を使っている」統合面を検証。
- [ ] 個別 `DatabaseTransactions` 不使用 — JS テストのみ、非該当。

### リスク
