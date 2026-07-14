# 対応マトリクス: conceptual-review Round 2

## [Warning] in-flight 中の Select 再操作(早期 return だけでは別行に同じ乖離が再発)
- 判断: 対応する
- 根拠: `changingRole` 早期 return は「リクエストは送らないが DOM 選択値は変わる」ため、別行で同じ乖離を生む。二重送信防止のための disabled は禁止事項8(必須未充足での無効化)に**該当しない**。
- 対応内容: in-flight 中(`changingRole === true`)はロール Select を `disabled` にする。禁止事項8 との切り分けを設計に明記。

## [Warning] tick() 後フォーカス復帰に Select atom の ref 公開が必要か
- 判断: 対応する(atom 変更不要と確認)
- 根拠: Select atom は `{...restProps}`(`id` 含む)と `data-testid` を native `<select>` にそのまま渡す。remount 後に `document.getElementById(id)` / `[data-testid]` で参照でき、`focus()` 可能。atom への ref 追加は不要(over-engineering 回避)。
- 対応内容: フォーカス復帰は既存の `id`/`data-testid` 経由の DOM 参照で行う旨を明記。Select/FormError atom は無改造。加えて Select に `aria-describedby`(FormError の id)を渡し、フォーカス復帰後にエラーが読み上げられるようにする。

## [Warning] 成功テストが「onSuccess 発火だけで反映」は成立しない
- 判断: 対応する
- 根拠: 実 Inertia では成功レスポンスの再取得 props が値を更新する。モックは props を更新しないと反映されない。
- 対応内容: 成功テストは「成功相当の members(roleState=editor)で再描画」して Select が editor を表示することを検証する props 駆動に修正。併せて `onSuccess` で `roleErrorMemberId` がクリアされることを別途検証。

## [Suggestion] 新規送信開始時にも roleErrorMemberId をクリア
- 判断: 対応する
- 対応内容: `changeRole` 冒頭(送信開始時)に `roleErrorMemberId = null` を設定し、前回エラーが次通信中まで残らないようにする。

## [Suggestion] Record<number, number> のリアクティビティ
- 判断: 対応する(方針固定)
- 根拠: Svelte 5 の `$state` は deep proxy で、`roleResetTokens[id] = n` の per-key 書き込みも(未存在キーの read 追跡含め)リアクティブに追跡される。
- 対応内容: `roleResetTokens` は `$state<Record<number, number>>({})` とし per-key 更新で `{#key}` を再評価する旨を明記(full 再代入不要)。remount は失敗行のみに限定(全 Select 一括 remount はしない)。

## [Suggestion] 使命寄与・スコープ・型安全は概ね支持
- 判断: 見送る(追加対応不要)
