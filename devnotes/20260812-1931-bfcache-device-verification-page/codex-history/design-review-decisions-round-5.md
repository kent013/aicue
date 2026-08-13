# 対応マトリクス: design-review Round 5

全件受け入れ（反論なし）。3 件とも私の消し漏れ・定義漏れ・規約違反。

## [Critical] `in-progress` が軸3で `fail` に落ちる（施策1）
- 判断: **対応する**
- 根拠: 指摘のとおり。`trial === valid-bfcache` かつ `guard === in-progress` は
  期待値とも一致せず正常終端でもないため、最後の「それ以外」に入って `fail` になる。
  **復元直後の正常な観測途中が一時的に FAIL 表示される**。
  Round 4 で `in-progress` を導入した際、軸3への波及を見落としていた。
- 対応内容: 軸3の判定順の先頭側に途中状態を明示した。
  - `guard === "in-progress"` → `undetermined`
  - `guard === "not-observed"` → `undetermined`
  - `guard === "failed-transition"` → `fail`
- `not-observed` を `undetermined` とした意図（Codex の要求により明記）:
  これは「`trial-aborted` 時点で guard イベントが 1 件も無い」状態で、
  **guard が発火しなかったのか利用者が早すぎる時点で中止したのかを
  イベント列からは区別できない**。区別できないものを `fail` と断定しない。

## [Warning] `AwayNavigationStartedEvent` のコメントに旧推論が残存（施策1）
- 判断: **対応する**
- 対応内容: 「離脱リンクが押された**操作事実**を同期記録する。
  `page-hide` の不在だけから離脱失敗を推論しない」に書き換えた。

## [Warning] ボタンの disabled が禁止事項 8 に抵触（施策3）
- 判断: **対応する**
- 根拠: 指摘が正しい。「操作ボタンの活性を `deriveTrialPhase()` の許可表に従わせる」は
  **AGENTS.md 禁止事項 8「必須条件未充足を理由にボタンを disabled にする UI」**に
  そのまま違反する。規約違反を自分で書き込んでいた。
- 対応内容:
  - **ボタンは disabled にしない**
  - 押下時に phase を検査し、許可されない場合はイベントを追記せず**理由を画面に表示**する
  - 二重送信防止など**処理実行中の一時的 disabled とは区別**する
    （`Debug/Login.svelte` の `submitting` と同じ扱いは可）
  - 施策 5 に「許可されない phase でも disabled にならないこと」のテストを追加

## [Critical] 軸2真理値表に旧期待値が残り新規則と矛盾（施策5）
- 判断: **対応する**
- 根拠: 同じ表に #5「遷移イベント無し → `not-observed`」と
  #10「guard イベント無し → `in-progress`」、
  #6「pending のまま停止 → `failed-transition`」と #11「pending のみ → `in-progress`」が
  併存していた。実装者がどちらを正本とするか判断できない。私の消し漏れ。
- 対応内容:
  - #5 / #6 を**削除**した（`not-observed` は #15 の中止済みケースだけに限定）
  - 軸3 に `in-progress` / `not-observed` / `failed-transition` のテストを追加

## APPROVE された施策
施策 2 / 施策 4 / 施策 6 / 施策 7
