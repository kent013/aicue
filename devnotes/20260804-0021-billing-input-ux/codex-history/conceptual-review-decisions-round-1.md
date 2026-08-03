# 対応マトリクス: conceptual-review Round 1

Codex (gpt-5.4 / medium) の全体判定は **APPROVED**。Critical なし / Warning 2 件 / Suggestion 多数。
APPROVED のため追加ラウンドは行わず、Warning 2 件を概念設計へ反映して Phase 2 (詳細設計) に進む。

## [Warning] `novalidate` 全面適用の前提「対象フォームがサーバエラーをフィールドに表示できること」

- 判断: **対応する** (前提の裏取り + 受入条件を概念設計に明記)
- 根拠: 指摘は正しい。native validation を外すと「submit は通るがエラーが見えない」形の後退が
  起こりうる。ただし裏取りの結果、**8 フォームすべてが既に `FormField error={form.errors.*}` に
  サーバ errors bag を配線済み**であることを確認した (grep 実測):
  - `Auth/Login.svelte:34` / `Auth/Register.svelte:102` / `Auth/ForgotPassword.svelte:32` /
    `Auth/ResetPassword.svelte:32` / `Settings/Index.svelte:179` / `Contact/Index.svelte:129` /
    `Admin/Users.svelte:381` / `BillingContactForm.svelte:81`
  よって前提は成立しており、後退リスクは「配線漏れ」ではなく「回帰の未固定」に絞られる。
- 対応内容: 概念設計に「受入条件」節を追加し、(a) 8 フォームの errors 配線を確認済み事実として
  記載、(b) 主要導線 (`BillingContactForm` / `Auth/Register` / `Auth/Login`) は
  「不正 email を submit → PATCH/POST が飛ぶ → 日本語 field error が出る」を vitest で固定、
  (c) 全 form の `novalidate` は architecture テストで機械固定、と明記した。

## [Warning] readonly を disabled と視覚的に同一化しすぎない

- 判断: **対応する** (視覚仕様を修正)
- 根拠: 指摘が正しい。readonly は「値は生きている (送信される・コピーできる・フォーカスできる)」、
  disabled は「値が無効化されている」。同じ muted で塗り潰すと別種の誤解を生む。
- 対応内容: readonly の視覚仕様を以下に変更した。
  - readonly: `bg-neutral` (面で編集不可を示す) + **文字色は通常の `text-text` のまま** +
    `cursor-default` + **focus ring は base のまま維持** (フォーカス・選択・コピーができるため)
  - disabled (現行維持): `disabled:bg-neutral` + `disabled:text-text-secondary` +
    `disabled:cursor-not-allowed`
  - 差分は「文字色」「カーソル」「フォーカス可否」の 3 点。DESIGN.md に意味差として明記する。

## [Suggestion] 位置付けを「課金 UI 改善」ではなく「運用信頼を毀損する入力 UX の是正」に

- 判断: **対応する** (背景節の表現を調整)
- 根拠: 対象が認証系フォームまで及ぶため、タイトルの「課金 UI」だけでは範囲を誤認させる。

## [Suggestion] 将来 native validation を使いたくなった時の逃げ道をメモに残す (allowlist は今は不要)

- 判断: **対応する** (テスト設計のメモとして残す。allowlist は作らない)
- 根拠: 「今必要なものだけ」(思考原則 2)。ただし architecture テストの docblock に
  「例外が必要になったら allowlist を足すのではなく、まず日本語エラー経路を疑うこと」を残す。

## [Suggestion] `Select` に readonly を追加しない境界を型で表現する

- 判断: **対応する** (詳細設計で型定義として明記)
- 根拠: HTML 仕様上 `<select>` に `readonly` は無い。`Select.svelte` の Props は
  `HTMLSelectAttributes` 由来で `readonly` を持たないため、現状で既に型境界になっている。
  詳細設計にその事実をコメントとして残す。

## [Suggestion] `type` union を widen しない / PHP 側無変更

- 判断: **そのまま維持** (設計方針と一致)
