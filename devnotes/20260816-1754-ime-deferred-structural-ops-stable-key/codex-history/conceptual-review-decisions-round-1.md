# 対応マトリクス: conceptual-review Round 1

全体判定は **APPROVED**。Critical は 0 件。Warning 4 件・Suggestion 3 件。
Warning はすべて概念設計へ反映し、詳細設計へ持ち越す (再レビュー要求は不要と判断)。

## [Warning] 観点 3: `confirmingStepKey` 化に伴うダイアログ表示側の見直し

- 判断: **一部対応する / 一部は現行コードの事実を根拠に反論する**
- 根拠: 現行の `ConfirmDialog` 呼び出しは
  `open={confirmingStepIndex !== null}` / 固定文言の `title` / `message` / `onConfirm` /
  `onCancel` の 5 つだけで、**`steps[confirmingStepIndex]` を参照していない**
  (`ScenarioEditor.svelte` L1323-1332 を実読して確認)。文言に手順番号も scene も出さないため、
  「表示側に index 依存が残る」という前提はこのコードには当てはまらない。
  ただし「確定・キャンセル時の状態リセットを同時に見直す」という要求そのものは妥当なので、
  そちらは受ける。
- 対応内容: 概念設計へ「ダイアログは対象の内容を描画しない (固定文言) ので表示側の解決は不要」
  という事実と、後述する解決不能時の閉じ方を明記した。詳細設計では現行コードと変更後コードを
  並べて、markup 側の差分が `open` / `onclick` / `onConfirm` の 3 箇所に閉じることを示す。

## [Warning] 観点 4: 「唯一の throw 経路が消える」という主張が強すぎる

- 判断: **対応する**
- 根拠: 妥当な指摘である。消せるのは「本設計が棚卸しした 3 経路の index ずれ由来の throw」で
  あって、`pendingActions` に将来積まれる closure が例外を投げないことまでは保証できない。
  保証範囲を誇張しないことは AGENTS.md の各所で繰り返し要求されている書き方でもある。
- 対応内容: 概念設計の制約節と期待効果節の表現を
  「**今回棚卸しした** index ずれ由来の throw 経路は消える」へ弱めた。
  try/catch を足さない判断自体は Codex も妥当としているので維持する。

## [Warning] 観点 5: ダイアログが開いたまま対象が消えた場合の UI 状態

- 判断: **対応する (Codex の提示した後者 = 確定時 no-op して閉じる を採用)**
- 根拠: 前者 (`compositionend` 後に解決不能なら閉じる) は、drain ループから
  ダイアログ状態を触る新しい結合を作る = 遅延実行の仕組みへ手を入れることになり、
  「遅延実行の仕組みそのものを作り替えない」という本タスクの制約に反する。
  後者は現行コードの形 (`removeStep` が `confirmingStepIndex = null` を即時実行する) の
  ままで成立し、追加の配線が要らない。
- 対応内容: 概念設計に「解決できなくても**ダイアログは必ず閉じる**
  (`confirmingStepKey = null` は `runSettled` の外で即時実行する現行の形を維持)」と明記した。
  観測される結果は「消したかった行は既に無く、ダイアログも閉じている」で、利用者にとって
  不整合が無い。この振る舞いを回帰テストの 1 ケースにする。

## [Warning] 観点 7: 関数シグネチャで key 前提を明示し、`-1` 分岐を確実に return する

- 判断: **対応する**
- 根拠: 妥当。引数名が `stepIndex` のまま中身だけ key になると、次に読む人が誤読する。
- 対応内容: `addPoint(stepKey: string)` / `removeStep(stepKey: string)` /
  `removePoint(stepKey: string, pointKey: string)` を採用し、概念設計の実装方針へ書いた。
  `findIndex` の `-1` は必ず早期 return し、その後でのみ添字アクセスする形を詳細設計の
  変更後コードで固定する。markup 側の呼び出し漏れは `pnpm typecheck` が検出する
  (`number` を `string` 引数へ渡すとコンパイルエラーになる) — この点も Codex の提案どおり。

## [Suggestion] 観点 1 / 2 / 6 (使命整合・禁止事項・スコープ)

- 判断: **対応不要 (肯定的評価のみ)**
- 根拠: いずれも現行方針を追認する内容で、変更要求を含まない。
