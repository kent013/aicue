### `.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py`

- [Critical] `adjudication_id` の CR/LF 防御が漏れています。`_ADJ_ID_RE.match()` と末尾の `$` の組み合わせは、Python では末尾 LF の直前にも一致するため、`"A-001\n"` を受理します。この値は機械マーカーと見出しへ直接出力され、`ENTRY_MARKER_RE` に認識されない壊れた項目を生成できます。生成器単体の fail-closed と掲載完全性を破る経路です。

  `_ADJ_ID_RE.fullmatch(value)` を使用するか、ID にも `_check_inline_text()` 相当の CR/LF 検査を適用してください。同じ正規表現を使う `supersedes` と移行 hash の鍵も `fullmatch()` に揃えるべきです。

`load_migration()` の検査順序は妥当です。各要素の型・語彙・重複を先に検査し、その後に entries/headings の件数一致、最後に固定値との一致を検査しており、提示された契約間に新たな到達不能はありません。

byte 比較への変更も適切です。

### `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py`

- [Critical] CR/LF の表駆動テストで漏れている出力欄は `adjudication_id` です。`review_after_days` は正整数、`narrative` は複数行本文なので除外して問題ありません。移行 key は有効な ID への完全一致解決により間接的に制約されますが、その正本となる ID 自体が上記の `$` 問題を持っています。少なくとも `"A-001\n"` と `"A-001\r"` を拒否するケースを追加してください。

- [Warning] `SPEC_BASIS_EXTENSIONS` と実際の正規表現が別々に手書きされているため、「許可側の完全一致 pin」にはなっていません。たとえば正規表現だけへ `xml` を追加しても、現在の拒否例に `xml` がないので全テストが緑になります。正規表現を `SPEC_BASIS_EXTENSIONS` から組み立て、定数を唯一の正本にするのが確実です。

重複鍵、見出し重複、件数不一致、block count pin の各テストは、今回 `assertRaisesRegex` で失敗理由まで固定されており、Round 1 の空振りは解消されています。byte 不変性テストも適切です。

### `.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json`

問題ありません。`source_lines: "81-112"` は提示された移行元の行数と整合します。

### `.claude/skills/app-bug-hunt/ledger/README.md`

問題ありません。追加差分による記述との食い違いもありません。

### `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl`

内容上の新たな問題はありません。ただし renderer の ID 検証問題は将来追加される登録に影響します。

### `.claude/skills/app-bug-hunt/spec-ledger.md`

現在の3登録について問題はありません。byte drift の検出も今回の修正で固定されています。

### `AGENTS.md`

問題ありません。

### `docs/TODO.md`

問題ありません。T224 の登録も維持されています。

### 検証状況

- [Warning] 「すべて green」とありますが、`pnpm test:packages` は実行中です。AGENTS.md が要求する一式の完了条件は、同コマンドの成功結果が確認されるまで未充足です。

CHANGES_REQUESTED