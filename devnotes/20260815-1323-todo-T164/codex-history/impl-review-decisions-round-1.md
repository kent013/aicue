# 対応マトリクス: impl-review Round 1

## [Critical] build_executed.py `_check_row()` が unhashable な `status` で traceback になる

- 判断: **対応する**
- 根拠: 指摘のとおり `{} in {"ok","blocked"}` は `TypeError: unhashable type` を投げる。
  `main()` は `CaptureError` と `OSError` しか捕まえないため、終了コード規約 (1 / 3) から
  外れて traceback で落ちる。「構文上は読めるが形が契約外なら 3」という契約の穴であり、
  correlate.py 側は同じ理由で既に `isinstance(status, str)` を先に見ている (非対称だった)。
- 対応内容: `_check_row()` の判定を
  `if not isinstance(status, str) or status not in VALID_STATUSES:` に変更した。

## [Warning] BughuntExecutedRouteOrderingTest のコメント / 失敗メッセージが誤った直し方を案内している

- 判断: **対応する**
- 根拠: `appendToPriorityList` は `[$append => $after]` の連想配列なので、同じ記録器を
  複数の anchor で append すると後勝ちで 1 本しか残らない。赤を見た人がその案内どおりに
  直すと、直したつもりで順序が閉じない (静かに fail-open へ戻る) 経路になる。
- 対応内容: docblock と失敗メッセージの案内を
  `prependToPriorityList(BughuntExecutedRouteMiddleware::class, $短絡middleware)` に直し、
  「なぜ append 側ではないか」も 1 行添えた。

## [Warning] test_build_executed.py に unhashable status の負の対照が無い

- 判断: **対応する**
- 根拠: 上の Critical を直しても、回帰を止めるテストが無ければ同じ穴が戻る
  (禁止事項 1: 不変条件はテストへの登録まで含めて実装済み)。
- 対応内容: `test_unhashable_status_returns_3` を追加し、`{}` / `[]` / `0` を通しても
  traceback ではなく終了コード 3 になることを固定した。

## [Suggestion] test_naming_no_stale.py の `--executed 省略` パターンが backtick 付き表記を拾えない

- 判断: **対応する**
- 根拠: 文言 gate の目的は「旧 fail-open の説明が文書へ戻ること」の検知であり、
  Markdown では `` `--executed` 省略時 `` と書くのが自然な表記である。
  現状は `未実行 candidate` 側で捕まるが、旧文言だけが単独で戻ると素通しになる。
- 対応内容: パターンを `` `?--executed`?\s*(を)?省略 `` に広げ、gate 自身のテストへ
  backtick 付き表記のケースを追加した。

## [注記] 施策 5 の文書差分がレビューに含まれていなかった

- 判断: **Round 2 で提示する**
- 根拠: 差分の分量を抑えるため `docs/` `AGENTS.md` `.claude/agents/` `.claude/skills/**/SKILL.md`
  を Round 1 の diff から外していたが、施策 5 は文言 gate と対になる変更なので未確認のままにしない。
- 対応内容: Round 2 のプロンプトへ当該 4 ファイルの diff を全文添付する。
