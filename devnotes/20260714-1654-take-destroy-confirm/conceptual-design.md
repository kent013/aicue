# 概念設計: take-destroy-confirm

## 背景・課題

bug-hunt (real-llm 2nd run 20260714-154640) の F-1-2 (Medium, H7) として報告。

テイク動画の削除 (`capture.takes.destroy`, `DELETE /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}`) が、アプリの他の破壊的操作 (動画マニュアル削除・プロジェクト削除・メンバー削除・API キー revoke 等) と異なり、**確認ダイアログ無しで即削除**される。

- 該当 UI: `resources/js/components/features/capture/TakeStrip.svelte` L187-196 の削除ボタン (`take-delete-{id}`)。
  `onclick={() => remove(take)}` が押下と同時に `captureJson(takeUrl(take), "DELETE")` を発火する。
- 撮影は PWA (スマホ) で行われるため、**モバイル誤タップによるテイク喪失リスク**が高い。テイクは現場で撮り直しが困難な素材であり、消失は使命 (「思考ゼロ・編集ゼロ」で標準化マニュアル動画を作る) の中核データを失うことを意味する。
- 他の破壊的操作は既に `ConfirmDialog` organism で確認を挟んでいる (例: `pages/Manuals/Show.svelte` L138-147 の動画マニュアル削除)。テイク削除だけがこの一貫性から逸脱している。

## 改善アイデア

テイク削除ボタン押下時に、他の destructive 操作と**同じ確認ダイアログ** (既存の `ConfirmDialog` organism) を挟み、「削除する」確定後に初めて `DELETE` を送る。UI/文言は既存の削除確認 (動画マニュアル削除等) のパターンに合わせる。

- 押下 → 削除対象のテイクを保持して `ConfirmDialog` を開く。
- 確認モーダルで「削除する」(danger variant) を押した時のみ、既存の `remove(take)` (XHR DELETE) を実行する。
- 「キャンセル」/ESC/オーバーレイ/X で閉じた場合は DELETE を発火しない。
- 送信中 (`busyTakeId === target.id`) は `processing` を立て、二重送信・誤操作での close を抑止する。

## 期待効果

- **使命への貢献**: 現場で撮り直し困難なテイク素材の誤削除喪失を防ぎ、標準化マニュアル動画の生成基盤 (撮影データ) を保全する。
- 破壊的操作 UX の**アプリ全体での一貫性**を回復する (テイク削除が他と同じ確認フローになる)。
- モバイル誤タップによる不可逆なデータ喪失インシデントの削減。

## 実装方針（概要）

`TakeStrip.svelte` に閉じた変更で完結させる。新規コンポーネントは作らず、既存 organism を再利用する。

1. `TakeStrip.svelte` に `ConfirmDialog` organism を import し、削除確認用の state を追加する。
   - `deleteTarget = $state<CaptureTake | null>(null)`
   - `deleteDialogOpen = $state(false)`
2. 削除ボタンの `onclick` を「即 remove」から「確認ダイアログを開く」へ変更する。
   - `onclick={() => requestDelete(take)}` (target 設定 + open=true)
3. `ConfirmDialog` の `onConfirm` で、保持した target に対して既存の `remove(target)` を実行し、完了後にダイアログを閉じる。
   - 文言は動画マニュアル削除に合わせる: title「テイク削除」, message「テイク {n} を削除しますか？ この操作は取り消せません。」, confirmLabel「削除する」, confirmVariant「danger」。
   - `processing` は `busyTakeId === deleteTarget?.id` を束ねる。
4. 既存の `remove` / `run` (XHR + `onChanged` + エラー表示) のロジックはそのまま流用する。DL 済みテイクの 422 エラー表示 (押下時にサーバメッセージ) の挙動も維持する (確認後に DELETE を送り、422 なら従来どおり `take-strip-error` に表示)。

Inertia ではなく XHR (`captureJson`) 経由のままにする点に注意 (テイク操作は SPA 内 XHR で `onChanged` により再取得する既存設計)。`ConfirmDialog` は XHR/Inertia いずれの確定処理にも中立なため再利用に問題はない。

## 制約・前提

- **Atomic Design**: `TakeStrip` (features/capture) から `ConfirmDialog` (organisms) への import は単方向 import (下層→上層) に適合 (`atomic-import-graph.test.ts`)。新規 SVG 内包はしない (アイコンは既存 Lucide `Trash2` のまま)。
- **DESIGN.md 準拠**: `ConfirmDialog` は DS token 経由。hex 直書きを増やさない。confirmVariant は既存の `danger` を使う。
- **禁止事項 (AGENTS.md #8)**: 必須条件未充足でボタンを disabled にしない。削除ボタンは従来どおり常時押下可能で、DL 済み等の制約は確認後の DELETE 応答 (422) で表示する挙動を維持する。
- バックエンド (`CaptureTakeController::destroy` / ルート / 認可) は**変更しない**。UI のみの変更。
- 型安全性: TypeScript のみの変更で PHP 側変更なし。PHPStan への影響なし。

## スコープ外

- テイク以外の破壊的操作 (既に確認ダイアログ有り) の変更。
- 削除の undo / ゴミ箱 / ソフトデリート化 (別施策)。
- コメント削除・並べ替え等、テイクの他操作への確認追加 (誤タップリスクと不可逆性の観点で削除のみ対象)。
- バックエンド・API・ルートの変更。
