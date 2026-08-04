### `.claude/skills/app-bug-hunt/probes/feedback-probe.js` — 要修正

- [Critical] `pending` の恒久残留は解消されていますが、例外時の判定が新たな偽陽性を作ります。`visible:false + error`、`pending:0`、`present_new:[]` になると、SKILL の判定表上は「本当に出なかった」ため H7 候補です。可視判定不能を陰性証拠として扱っています（`.claude/skills/app-bug-hunt/probes/feedback-probe.js:96`）。
- `error` を含む entry が一件でもあれば「未検証」とする規約追加、または専用 `errors`/`failed` カウンタが必要です。今回の Warning の機械的意図は満たしますが、観測契約全体としては未完です。
- [Suggestion] dedupe 見送りは妥当です。判定が件数非依存で、現行 UI に nested live region がない以上、追加状態を持つ価値は薄いです。

### `.claude/skills/app-bug-hunt/SKILL.md` — 要修正

- [Critical] `seen[].error` の扱いが判定表にありません。`visible:false` は証拠に数えないだけでなく、現在は陰性判定を妨げないため、上記例外が H7 誤起票へ直結します（`.claude/skills/app-bug-hunt/SKILL.md:284`）。
- arm 漏れの追記は明確で、Round 1 の意図を満たしています（`.claude/skills/app-bug-hunt/SKILL.md:273`）。
- `pending>0` 前の短待機見送りも妥当です。Bash 往復が通常の rAF より十分長く、継続時は「未検証」という安全側の出口があります。

### `tests/js/bughunt/feedback-probe.test.ts` — 要修正

- [Warning] 今回追加した例外経路の回帰テストがありません。`getClientRects()` を一度 throw させ、`pending===0`、`seen[].error` が残ること、および規約上「未検証」になる契約を固定すべきです（`tests/js/bughunt/feedback-probe.test.ts:113`）。
- [Suggestion] `ProbeEntry` に `error?: string` を追加すると、返却契約と型が一致します（`tests/js/bughunt/feedback-probe.test.ts:21`）。
- `describe.sequential` の副作用は見当たりません。意図的な単一セッション再現に適合しています（`tests/js/bughunt/feedback-probe.test.ts:91`）。

### `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` — 概ね良好

- [Suggestion] 拡張による誤 FAIL は見当たりません。ただし `file.ts:12:5` は依然として検査対象外です。許容するなら複数位置セグメントも吸収してください（`.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py:61`）。
- `#L10`、`#anchor`、行範囲からパス部だけを抽出する変更は適切です。

### `.claude/skills/app-bug-hunt/spec-ledger.md` — APPROVED

- registry 正本参照のテンプレート化は役割分担を明確にし、説明文の二重管理も増やしていません（`.claude/skills/app-bug-hunt/spec-ledger.md:70`）。

### `.claude/agents/bughunt-shard.md` — APPROVED

- SKILL 正本への参照のみで、プロトコルの二重管理はありません（`.claude/agents/bughunt-shard.md:73`）。

CHANGES_REQUESTED