# impl-review Round 2 — Warning への回答（設計意図の明文化）

Round 1 の唯一の Warning（`Settings.svelte` の `onFinish` 無条件 `transferClientError = null`）について、コードは変更せず、制御フロー不変条件と設計意図を提示する。再判定を求める。

## 不変条件: post 発火時点で `transferClientError` は必ず null

`transferOwnership()`（`transferForm.post` を呼ぶ関数）に到達する経路は **ConfirmDialog の確定ボタン `onConfirm` のみ**。ConfirmDialog が開くのは `openTransferDialog` の precheck を全通過した場合だけ:

1. `transferCandidates.length === 0` なら return（ダイアログを開かない）
2. `!isValidTransferTarget` なら `transferClientError` を代入して return（ダイアログを開かない）
3. 両方パス（候補あり かつ `isValidTransferTarget === true`）した場合のみ `transferDialogOpen = true`

したがってダイアログが開く = precheck 通過 = **client error は代入されていない**。さらに `isValidTransferTarget === true` が成立している間は `$effect(() => { if (clientError!==null && isValidTransferTarget) clientError=null })` が `transferClientError` を null に保ち続ける。

結論: **post 発火時点で `transferClientError` は常に null**。よって `onFinish` の `transferClientError = null` は**冪等な no-op（防御的クリア）**であり、「失敗時に残したい client error」は原理的に発生しない。

## serverErrors 非退行（onFinish は別 bag を触らない）

サーバ validation エラーは `transferForm.errors`（別 bag）に載る。`onFinish` はこれを一切触らない。onError で server error がセットされた後も、表示は `transferClientError(=null) ?? transferForm.errors.user_id` の後段で正しく表示される（Round 1 で APPROVE 済のテストが実証: client error クリア後に背後の server error が再表示されることを明示アサート）。

## 設計意図（明文化）

`onFinish` の clear は Codex 指摘どおり「**終了時は常に stale を掃除する**」意図。具体的には、再 mount しないライフサイクル（例: 再認証モーダルをキャンセル→ダイアログ再オープン）で client error を残さないための defensive clear（詳細設計 L191 に明記）。onSuccess ではなく onFinish に置くのは、成功・失敗・キャンセルのいずれの終了経路でも transient state を初期化するため。

以上より、この Warning は過剰クリアではなく意図的な defensive no-op と確認できる。全体判定の再提示を求める。
