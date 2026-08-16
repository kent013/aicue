# 対応マトリクス: design-review Round 2

## [Warning] 施策 1: `CutTakeController` の DocBlock に採用テイク外部キーの識別子が残っている

- 判断: **対応する**
- 根拠: 自分で決めた方針（`app/` の新規コメントでは言い換える）と本文が食い違っていた。
  実装者はコード例をそのまま写すので、方針だけ書いても意味がない。
- 対応内容: 「採用テイク外部キーを書き込むのは」に置き換えた。

## [Suggestion] 施策 1: PHPStan 適合チェックの `label` 記述が旧のまま

- 判断: **対応する**
- 対応内容: 「`'カット'` で初期化（未初期化アクセスなし）」に更新した。

## [Suggestion] 施策 2: 変更後コード欄に無条件の `<video src>` が残っている

- 判断: **対応する**
- 根拠: 承認は妨げないとされたが、「実装仕様の正本が曖昧になる」は正しい。
  コード例が仕様として写されるため、分岐を書いておく方が安全。
- 対応内容: `{#if playbackUrl !== null}` / `{:else}` の分岐へコード例を更新した。

## [Warning] 施策 4: `queue.enqueue()` の例外経路でエラーが表示されない

- 判断: **対応する**
- 根拠: Round 1 のコードにあった `catch` を書き直しの際に落としていた。
  ネットワーク断・presigned PUT の例外で**無反応**になるのは実害のある欠落である。
- 対応内容: `try` / `catch` / `finally` の 3 段に戻し、catch で
  「アップロードできませんでした。接続を確認して再度お試しください。」を表示する。
  併せて `catch` 経路でも Blob が store に残らないことを明示（`enqueue` が throw した場合、
  即時アップロード経路では `store.put()` を通らないため残らない。念のためテストで固定する）。
  frontend テストに「例外時: エラー表示 / input リセット / store が空」を追加した。

## [Warning] 横断: 完了条件の検証コマンドが AGENTS.md と同期していない

- 判断: **対応する**
- 根拠: `AGENTS.md` の検証コマンド節（`verification-commands-doc-sync` テストが
  package.json と同期を強制している正本）には package レーンの 3 本も含まれる。
- 対応内容: 実装モードの説明と完了条件チェックの両方に
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加した。
