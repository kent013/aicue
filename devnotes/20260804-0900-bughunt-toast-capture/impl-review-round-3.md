### `.claude/skills/app-bug-hunt/SKILL.md` — 要修正

- [Critical] 複数 probe 応答を統合する際の `errors` が未定義です。1回目が `errors:1, pending:1`、再 probe が `errors:0, pending:0` の場合、規約は `seen` / `present_new` の和集合しか要求しておらず、2回目の `errors:0` で H7 陰性へ倒す余地があります（`.claude/skills/app-bug-hunt/SKILL.md:286`）。
- 「いずれかの応答で `errors>0` なら操作全体を未検証」と明記するか、再 probe 時は `errors` も合算する必要があります。これが閉じれば Round 2 Critical の意図を完全に満たします。

### `.claude/skills/app-bug-hunt/probes/feedback-probe.js` — APPROVED

- `errors` は drain 対象 batch と一致し、例外 entry の診断情報、`pending` の対称性、次回への状態持ち越しが適切です（`.claude/skills/app-bug-hunt/probes/feedback-probe.js:145`）。
- 同期評価側の例外は probe 自体を失敗させるため、陰性 JSON に偽装される経路もありません。

### `tests/js/bughunt/feedback-probe.test.ts` — APPROVED

- ケース N は rAF 内だけを確実に例外化し、読み出し時の同期評価を正常に戻しているため、狙った契約を固定しています（`tests/js/bughunt/feedback-probe.test.ts:235`）。
- `pending===0`、entry の `error`、`visible:false`、`errors===1` の組合せにより false green の穴は見当たりません。
- [Suggestion] 上記の複数応答問題を明文化した後、`errors` が次回 drain で `0` に戻るテストを追加すると、batch 単位であることがより明確になります。

### `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` — APPROVED

- 繰り返し化は位置サフィックス部分だけに限定され、拡張子を持たない非パストークンには過剰マッチしません（`.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py:61`）。
- `foo.js:bar:baz` のような緩い位置表現も許容しますが、パス部の実在確認が目的なので妥当です。

CHANGES_REQUESTED