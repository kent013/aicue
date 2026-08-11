# 実装レビュー Round 1 対応マトリクス (T151)

Codex 判定: **CHANGES_REQUESTED** ([Critical] 0 件 / [Warning] 3 件 / [Suggestion] 1 件)

| # | 分類 | 指摘 | 判断 | 根拠・対応内容 |
|---|------|------|------|----------------|
| 1 | [Warning] | `AGENTS.md` (i) 更新経路の準拠実装リストから `RenderJobService::trigger()` / `failJob()` / `completeRenderIntoLockedManual()` が抜けている | **対応する** | 詳細設計 施策 5 の骨子は (i) にこの 3 本を含めていた。実装時の写し漏れ。3 本を追記し、代替として置かれていた「後続の RenderJob 状態遷移も同規約に従う」の 1 文は準拠実装リストに吸収されたので削除した (二重管理を残さない)。`docs/architecture.md` の経路表とも粒度が揃った |
| 2 | [Warning] | `docs/architecture.md` の「準拠実装 (メソッド粒度の経路 inventory。ScenarioWritePathInventoryTest が deny-by-default の token 走査で機械検証する)」が、後段の「allowlist はファイル粒度」と矛盾し保証範囲の誇張に見える | **対応する** | 観点 7 (保証範囲を誇張しない) に直結する。「下表は**メソッド粒度で記録する**経路 inventory。ただし機械検証は deny-by-default の token 走査 = **ファイル粒度**に留まり、表の粒度と一致しない (同一ファイル内のメソッド追加は検出しない)」へ書き分けた。**この一文は本 TODO の主題そのもの** (メソッド単位の fail-first を gate が担えないこと) なので、指摘どおり分離するのが正しい |
| 3 | [Suggestion] | `ScenarioWritePathInventoryTest` の docblock にも「機械検証はファイル粒度」を明示するとより安全 | **対応する** | #2 と同じ理由。3 箇所 (AGENTS.md / architecture.md / inventory docblock) の語彙を揃えることが施策 4・5 の要件でもある。docblock 冒頭を「下表はメソッド粒度で記録する経路 inventory。ただし本テストの機械検証は allowlist によるファイル粒度」に書き換えた |
| 4 | [Warning] | `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が未実行 | **対応する (1 本を除き全 green。1 本は環境要因で実行不能)** | 実行結果は下記。`pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` は **green**。`pnpm test` は **1 ファイル 6 件が失敗**したが、失敗理由は全件同一の環境要因であり本差分とは無関係 (下記) |

## `pnpm test` の失敗 6 件について (誇張せず事実のみ)

```
FAIL scripts/run-browser-test.contract.test.ts (6 tests)
Error: bug-hunt 環境が 127.0.0.1:8010 で listen 中のため、run-browser-test.sh の
       pre-flight guard が発火して契約テストを実行できません
       (scripts/bug-hunt-shard.sh teardown で停止してから再実行してください)
Test Files  1 failed | 129 passed (130)
     Tests  6 failed | 1310 passed (1316)
```

- 失敗は **1 ファイル `scripts/run-browser-test.contract.test.ts` に閉じており、6 件すべて同一の
  pre-flight guard メッセージ**である。テスト本体の assertion 失敗ではなく、
  **契約テストを実行する前段で環境を検出して止まっている**
- 原因は別 worktree (`.claude/worktrees/tasks/smoke-20260811`) に **pipeline-smoke の検証環境が
  provision 済みで `127.0.0.1:8010` が listen 中**であること。解消手段は
  `scripts/bug-hunt-shard.sh teardown` だが、**本タスクは当該 worktree に触ることを明示的に
  禁止されている**ため実行していない
- 本差分は **`resources/js` / `resources/css` / `scripts/` / `packages/` を 1 バイトも変更していない**
  (変更は `app/` 1 ファイル・`tests/` 2 ファイル・`docs/architecture.md`・`AGENTS.md`・`devnotes/` のみ)。
  したがってこの失敗が本差分に起因しないことは差分の範囲から言える
- **ただし「pnpm test が green である」とは書かない**。実測は上記のとおりであり、
  この 1 ファイルは**未検証のまま残っている**

## 対応後の検証 (再実測)

| コマンド | 結果 |
|---|---|
| `composer phpstan` | **OK** (No errors / 891 files / level 10) |
| `composer test` | **passed** tests=4455 passed=4453 skipped=2 assertions=19177 |
| `composer fix` → `vendor/bin/pint --test` | **passed** |
| `pnpm lint` | **passed** |
| `pnpm typecheck` | **passed** |
| `pnpm test` | **1 failed / 129 passed** (上記の環境要因 1 ファイル 6 件のみ) |
| `pnpm build` | **passed** (built in 4.01s) |
| `pnpm typecheck:packages` | **passed** |
| `pnpm build:packages` | **passed** |
| `pnpm test:packages` | **passed** (10 files / 106 tests) |
