# fail-first の記録 (T223)

詳細設計「施策 0 のテスト計画」に従い、生成器を最小 stub
(`class RenderError(Exception)` と `def build(...): raise RenderError("not implemented")`) だけに
した状態で差し替え後のテストを走らせ、赤を確認してから実装に入った。

## 実行

```bash
cd .claude/worktrees/tasks/T223
python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_spec_ledger.py'
# → Ran 48 tests / FAILED (errors=90)  ※ subTest を含むため error 数がテスト数を上回る
```

代表 4 本 (設計で名指しされたもの) を個別に走らせた結果:

| テスト | 赤の理由 |
|---|---|
| `test_every_adjudication_id_is_listed_exactly_once` (完全性) | `render_spec_ledger` に `load_adjudications` が無い (`AttributeError`) |
| `test_schema_broken_context_does_not_affect_the_matcher` (fail-closed 境界) | 入力の写しを作る段階で `spec-ledger-migration.json` が未作成 (`FileNotFoundError`) |
| `test_required_fragment_missing_fails` (移行) | 同上 |
| `test_manual_edit_is_detected` (drift) | 同上 |

いずれも「まだ実装が無い」ことに起因する赤であり、assertion が空振りして緑になっていないことを
確認した (テストの前提確認として `REPO_ROOT / "AGENTS.md"` の実在を `setUpModule` で assert しており、
根拠パスの実在検査が別ディレクトリを見て全件緑になる事故も塞いである)。

## 実装後

```bash
python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
# → Ran 115 tests / OK   (内訳: 既存 test_validate_findings.py 67 本 + 本タスクの 48 本)
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check   # exit 0
```

契約は 43 本だが、1 契約を複数ケースへ割ったものがあるためテストメソッド数は 48 本になった
(`subTest` の内訳を数えると 90 ケース)。
