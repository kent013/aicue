# 対応マトリクス: impl-review Round 1

## [Warning] Settings.svelte `onFinish` の無条件 `transferClientError = null` が「失敗時にも client error を消す」過剰クリアになり得る
- 判断: **反論する（設計意図を明文化して再判定を求める）**
- 根拠: 制御フロー上、`transferOwnership()` に到達する時点で `transferClientError` は必ず `null`。
  - `transferOwnership` は ConfirmDialog の確定 (`onConfirm`) からのみ呼ばれる。
  - ConfirmDialog は `openTransferDialog` の precheck を**すべて通過**（候補あり かつ `isValidTransferTarget === true`）した場合のみ開く。precheck 通過時は client error を代入していない。
  - さらに `isValidTransferTarget === true` が成立している間は `$effect` が `transferClientError` を null に保つ。
  - よって post 発火時点で client error は存在せず、`onFinish` の null 代入は**冪等な no-op**（防御的クリア）。「失敗時に残したい client error」は原理的に存在しない。
  - サーバ validation エラーは別 bag (`transferForm.errors`) に載り、`onFinish` はそれを触らない = 非退行。onError→server error は `transferClientError ?? transferForm.errors.user_id` の後段で表示される。
  - 目的は Codex 指摘どおり「終了時は常に stale を掃除する」= 再 mount しないライフサイクル（再認証キャンセル→ダイアログ再オープン等）で client error を残さないための defensive clear。設計書 L191 に明記済み。
- 対応内容: コード変更なし。Round 2 で上記 invariant と設計意図を提示し APPROVED を求める（Codex は「意図的仕様と明文化できれば即 APPROVED」と述べている）。

## その他
- Projects/Show.svelte / 両テストファイル: 指摘なし（APPROVE 相当）。
