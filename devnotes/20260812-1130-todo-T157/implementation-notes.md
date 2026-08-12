# 実装メモ: T157 入力後もエラー表示が消えない 2 画面 (bug-hunt F-1-01 / F-3-02)

設計: `devnotes/20260812-1130-form-stale-invalid/` (概念 Round 4 APPROVED / 詳細 Round 3 APPROVED)。
実装レビュー: `impl-review-round-1.md` (Round 1 APPROVE / Warning 0)。

## 経緯 — 当初案は合議で棄却した

最初は「`FormField` が subtree の input/change で表示を抑制し、Inertia の visit 完了で戻す」で
**65 箇所を一度に直す**案だった。**棄却**した理由 (概念設計に詳細):

- `router.on('finish')` は「そのフィールドの再検証結果の到着」と対応せず、
  無関係な visit で**古いエラーを非決定的に復活させる**
- `error` prop からは「新しい応答が来た」ことを識別できない (同じ文言の再到着)
- 65 箇所の分類が閉じない (control 内訳の合計が 63 で 2 箇所未分類だった)
- `clearErrors` (値を消す) と表示抑制 (値を残す) は**同等置換ではない**

代替として提案された「page props のオブジェクト同一性で解除」も、
**同じ欠陥を形を変えて持ち込む**ため棄却した (Round 3)。

## 実装したもの (出所別の 2 機構)

| 対象 | 機構 |
|---|---|
| `Projects/Create` (`useForm`) | 既存 9 箇所と同じ `form.clearErrors(field)` を `oninput` で呼ぶ。**値を消す**ので同じ文言の再到着でも必ず再表示される |
| `BillingContactForm` (page props 由来) | フィールド単位の編集済みフラグ + **自分の `router.patch` の `onError`/`onSuccess`** で解除。**フィールドごとの編集世代**を持ち、送信中に編集されたフィールドは解除しない |

**サーバ 0 行 / `FormField` 無変更 / props 不変。**

## fail 先行 (予測と実測)

予測「契約 0..3 と 6..12 が赤、4 と 5 だけ緑」→ **実測一致**。
ただし最初の版で**契約 11 (2 件) が偽の緑**になっていた — callback を同期的に呼んだ後に
DOM 反映を待っていなかったため。`await tick()` を足して是正し、
12 赤 / 2 緑 (契約 4・5 のみ緑) にした。**偽緑を fail 先行の段階で捕まえた**。

## mutation の実測 (8 種すべて予測どおり)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | `clearErrors("name")` → 引数なし | 契約 1・3 | 一致 (2 件) |
| M2 | `Projects/Create` の `oninput` 削除 | 契約 1 | 一致 (契約 0・1・3) |
| M3 | `emailEdited` を抑制条件から外す | 契約 6 | 一致 (8 件) |
| M4 | email/name を共有フラグにする | 契約 7 | 一致 (契約 7・12) |
| M5 | 解除を `onError` → `onFinish` へ | 契約 10 | 一致 |
| M6 | `onSuccess` の解除を削除 | 契約 9 | 一致 |
| M7 | 編集世代の一致判定を外す | 契約 11 (両 callback) | 一致 (契約 11×2・12) |
| M8 | 「どちらかが動いていたら両方解除しない」 | 契約 12 | 一致 (契約 12 のみ) |

**M8 が契約 12 だけを赤くした**ことで、「フィールドごとの編集世代」を採った中核の理由が
機械的に守られていることを実測できた。

## 設計からの乖離 1 点

契約 0 のテストスタブを既存の `tests/js/support/reactiveUseForm.svelte.ts` に差し替えた。
自作した plain object の useForm stub は `errors` が非反応で、`clearErrors` の削除が
再描画に繋がらず契約 0 を観測できなかった (実測で赤のまま)。

## 検証コマンド (worktree 内)

`pnpm test` 1357 passed / `composer test` 4511 passed・2 skipped / `composer phpstan` No errors /
`pnpm lint` / `pnpm typecheck` / `vendor/bin/pint --test` / `pnpm build` / packages 106: 全緑。

## 保証しないもの (誇張しない)

- **同種の挙動が残る `FormField` 呼び出しは 54 箇所** (65 − 既存 9 − 今回 2)。
  本 TODO は直さないし、**機械的にも防がない** (gate を入れていない = 3 件目の忘れは起こりうる)。
- **クライアント検証はしない**。「編集された」ことしか見ておらず、正誤は次の送信が決める。
- **`BillingContactForm` の解除は自分の送信結果だけに反応する**。別画面での更新や
  partial reload では解除しない (無関係な visit で古いエラーを復活させないための代償)。
- **Vitest は DOM と呼び出しの契約のみ**。実ブラウザでの見え方は確認していない。
