**レビュー結果: APPROVED**

コマンド実行は禁止条件のため未実行です。提示 diff と詳細設計書の照合ベースでレビューしました。

**AGENTS.md**
- 判定: OK
- cap=4 の割り当て記述へ更新済み。守り側 regex の写経をやめ、`DetectsBughuntDatabase` 参照に寄せている点も設計通りです。

**scripts/bug-hunt-shard.sh**
- 判定: OK
- `BUGHUNT_SHARD_CAP=4` の SSOT 化、`SHARD_RE` / `SHARD_DB_RE` の cap 導出、`BUGHUNT_DB_PREFIX` の「検証 → SHARD_DB_RE 代入」順序、`valid_parallel_n` の `set -euo pipefail` 対応、`stories_for_shard` の 6/8 削除はいずれも妥当です。
- self-test も旧 cap の 5/8 拒否、`bug_hunt_5/8` 拒否、`--parallel=6/8` 拒否を明示しており、境界固定として十分です。

**database/seeders/Concerns/DetectsBughuntDatabase.php**
- 判定: OK
- `BUGHUNT_DB_REGEX` を `[1-8]` のまま維持しており、守りの面を cap=4 に狭めていません。コメントも allowlist 側との方向差を説明できています。

**tests/Support/Ci/TestDatabaseEnv.php / tests/Unit/Ci/TestDatabaseEnvTest.php**
- 判定: OK
- `DEV_DB_DENYLIST` の `bug_hunt_1..8` 維持は設計通り。既存アサーションを緩めず、テスト名・コメントだけを意図に合わせています。

**scripts/run-browser-test.sh / scripts/run-browser-test.contract.test.ts / scripts/verify-global-test-lock.sh / docs/testing-browser.md / scripts/README.md**
- 判定: OK
- pre-flight guard / fixture の `8010..8018` を維持しており、守りの面を狭めていません。コメントも「残留検出のため広く見る」方向で一致しています。

**.claude/skills/app-bug-hunt/* / .env.bughunt.local.example / docs/worktree-isolation-strategy.md**
- 判定: OK
- 割り当て散文は `1..4` / `:8011..8014` / `bug_hunt_1..4` / `2/4` に統一されています。
- `findings.schema.json` は description のみ変更し、値制約を追加していないため過去 findings を壊していません。

**tests/Architecture/BughuntShardCapInvariantTest.php**
- 判定: OK with Suggestion
- [Suggestion] `bughuntCapAllocationValues()` は設計通り Tier A / Tier B を分離できており、`cap-defense-ok` が Tier A を免除しない実装になっています。
- [Suggestion] 将来の検出力を上げるなら、自然な日本語表記として `cap は 8` / `N は 8` / `--parallel 8` も Tier A に含める余地があります。現行設計の列挙パターンには入っていないため、今回の実装不備ではありません。
- [Suggestion] `SHARD_RE` / `SHARD_DB_RE` の構造検査は最初の代入行を見る作りです。後続の再代入を静的に拾う用途までは担っていませんが、現行差分では self-test 側が実行境界を補っています。

**全体判定: APPROVED**

設計の中心原則「触れる対象は 4 に狭める / 守る対象は 8 のまま維持する」は守られています。Critical / Warning 相当の修正要求はありません。