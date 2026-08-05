# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high) の全体判定は **APPROVED**。Critical 0 / Warning 0 / Suggestion 3。

## [Suggestion] `bughuntCapAllocationValues()` の Tier A / Tier B 分離と `cap-defense-ok` の bypass 不能性

- 判断: 対応不要 (確認事項として提示した論点への肯定回答)
- 根拠: 詳細設計 §Codex レビューの最終状態で「Round 3 の反映を Codex に再確認させていない 2 点」として
  明示的に確認を依頼した箇所。実装差分ベースで「Tier A / Tier B を分離できており、
  `cap-defense-ok` が Tier A を免除しない実装になっている」と確認された。
- 対応内容: なし (負のコントロール 3 本 = Tier A 検出 / Tier A はマーカー付きでも違反 /
  マーカー allowlist・守りの語の 2 条件が既にテストで固定されている)。

## [Suggestion] 日本語表記 `cap は 8` / `N は 8` / `--parallel 8` も Tier A に含める余地がある

- 判断: **対応する**
- 根拠: 「今回の実装不備ではない」と明記されているが、対象文書は日本語散文であり
  `cap=8` より `cap は 8` の方が自然に書かれうる。検出漏れは gate の存在意義を削るのに対し、
  修正コストは区切り表現の正規表現 1 箇所のみ。オーバーエンジニアリングには当たらない。
- 対応内容: `bughuntCapAllocationValues()` の区切りを
  `(?:\s*(?:=|は|:)\s*|\s+)` に共通化し、`--parallel` / `parallel` / `N` / `cap` の 4 パターンへ適用。
  数値が続く場合のみ一致するため `parallel 実行は 2025 年に導入した` には当たらない
  (偽陽性防止テストで固定済み)。負のコントロールへ
  `--parallel 8` / `N は 8` / `cap は 8` の 3 ケースを追加。
  適用後、割り当て散文 10 ファイルに対する走査は 0 件のまま (偽陽性なし) であることを確認。

## [Suggestion] `SHARD_RE` / `SHARD_DB_RE` の構造検査は最初の代入行しか見ていない

- 判断: 見送る
- 根拠: 行頭アンカー (`/^SHARD_DB_RE=/m`) は**トップレベルの代入**のみを対象とする設計。
  唯一の再代入は `cmd_self_test` 内の sandbox 初期化 (インデント済み = 非一致) で、
  これは施策 2-0 で意図的に入れた「self-test の環境非依存化」であり、cap から同じ式で導出している。
  任意箇所の再代入まで静的に追うには bash のスコープ解析が必要でコストが釣り合わない。
  実行時の allowlist の実効値は self-test [c] (`bug_hunt_5` / `bug_hunt_8` が abort されること) が
  直接固定しており、二段防御として十分 (AGENTS.md 思考原則 2)。
- 対応内容: なし。
