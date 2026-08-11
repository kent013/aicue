# 実装レビュー Round 2 対応マトリクス (T151)

Codex 判定: **APPROVED** ([Critical] 0 件 / [Warning] 1 件 (受容済みの環境要因) / [Suggestion] 1 件)

## Round 1 指摘の決着

| # | Round 1 の指摘 | Round 2 の判定 |
|---|----------------|----------------|
| 1 | AGENTS.md (i) に RenderJobService 3 本が抜け | **十分に対応** (Codex: 「(i) の準拠実装リストは docs/architecture.md の経路表と粒度が揃った」) |
| 2 | architecture.md がファイル粒度の限界と矛盾 | **十分に対応** (Codex: 「メソッド粒度の inventory と機械検証がファイル粒度に留まることが分離して書かれ、観点 7 の誇張は解消」) |
| 3 | inventory docblock にも同旨を明示 | **十分に対応** (Codex: 「保証範囲は正直」) |
| 4 | pnpm 系 5 本が未実行 | **対応**。4 本 green / `pnpm test` は環境要因の 1 ファイル 6 件が未検証のまま |

## Round 2 の新規指摘

| # | 分類 | 指摘 | 判断 | 根拠 |
|---|------|------|------|------|
| 1 | [Suggestion] | `docs/architecture.md` の `VideoManualService::duplicate()` 行は「生成経路」で始まりつつ `cuts` も同じ行に含むため、厳密には「生成経路 + 後続更新経路」と書く余地がある | **見送る** | (a) この行の文言は **Codex 合議済みの詳細設計 施策 4 が指定した文面そのもの**であり、必要のない逸脱を持ち込まない。(b) Codex 自身が「直前の本文で cuts materialize は再取得 lockForUpdate 後と明示されているのでブロッカーではない」と述べており、**同じ節の本文 (生成経路の免除範囲を初期値 INSERT のみに封じる段落) が既に正確に書いている**。(c) 行内に二重の分類語を入れると (i)/(ii) の 2 分類という本改訂の骨子がぼやける |
| 2 | [Warning] | `pnpm test` の 1 ファイル 6 件が未検証のまま残る | **受容 (扱いは Codex が妥当と判定)** | Codex: 「本差分に起因しない環境要因として扱うのは妥当。**green と書かず未検証として明記する現在の扱いは正しい**。merge 前または CI では 8010 を解放した環境で `pnpm test` を再実行するのが残タスク」。**この残タスクは Phase C (main マージ) の担当者へ申し送る** — 本タスクは Phase C を実施しない指示のため、ここで閉じられない |

## 残タスク (申し送り)

- `127.0.0.1:8010` を占有している pipeline-smoke の検証環境
  (`.claude/worktrees/tasks/smoke-20260811`) を停止した環境で **`pnpm test` を再実行**し、
  `scripts/run-browser-test.contract.test.ts` の 6 件が green になることを確認する。
  本タスクは当該 worktree に触ることを禁止されているため実行していない。
