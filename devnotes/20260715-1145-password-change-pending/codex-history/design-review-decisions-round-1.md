# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED（Critical 1 / Warning 4 / Suggestion 複数）。全 Critical・Warning に対応した。

## [Critical] 施策4: clearErrors/put 順序テストが invocationCallOrder[0]=undefined で偽陽性化し得る
- 判断: 対応する
- 根拠: 妥当。呼び出しが 0 回だと `invocationCallOrder[0]` が undefined になり `toBeLessThan` が誤通過/難読失敗する。
- 対応内容: 順序比較の前に `await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1))` + `expect(clearMock).toHaveBeenCalledTimes(1)` を置き、両者が確実に 1 回呼ばれた前提を作ってから `invocationCallOrder[0]` を比較する形へ修正。

## [Warning] 施策1&2: コメントが実装詳細寄りで長い
- 判断: 対応する
- 根拠: 保守性の指摘は妥当。
- 対応内容: コメントを「送信中の誤認防止のため、前回エラーを送信開始時に明示クリア（Inertia useForm は送信ではクリアせず応答後にのみ errors を更新するため）」の 2 行に圧縮。

## [Warning] 施策3: transform 戻り値が { post } 固定
- 判断: 対応する
- 根拠: 将来 `transform().put(...)` 連鎖テストで不整合が出る恐れ。additive 拡張で解消可能。
- 対応内容: `transform()` の戻り値を `{ post, put, patch }` に拡張。戻り型注釈も更新。既存 consumer は `.post` のみ参照で後方互換。

## [Warning] 施策4: closest("form") as HTMLFormElement が null で落ちる
- 判断: 対応する
- 根拠: DOM 構造変更耐性の指摘は妥当。
- 対応内容: `submitPasswordForm()` ヘルパを追加し `const formEl = submit.closest("form"); expect(formEl).not.toBeNull();` で null ガードしてから submit する。全ケースで共用。

## [Warning] 施策4: pending 文言テストの tick() 1 回依存はフレークし得る
- 判断: 対応する
- 根拠: 妥当。反応的更新のタイミング依存を避けるべき。
- 対応内容: `tick()` をやめ `await waitFor(() => expect(screen.getByRole("button", { name: "変更中…" })).toBeInTheDocument())` に変更（`svelte` からの `tick` import も不要化）。

## [Suggestion] 既存 4 ケース名を変更しない方針を明記
- 判断: 対応する
- 根拠: レビュー/運用上の明確化に有益。
- 対応内容: 施策4 設計方針に「既存 4 ケースの describe/it 名は変更しない（追加のみ）」を明記。
