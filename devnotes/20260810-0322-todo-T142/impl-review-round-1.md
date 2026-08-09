全体判定: **CHANGES_REQUESTED**

**ファイル別判定**
- `scripts/bug-hunt-shard.sh`: **REQUEST_CHANGES**
- `scripts/setup-worktree.sh`: **REQUEST_CHANGES**
- `tests/Architecture/BughuntSelfTestExecutionTest.php`: **APPROVE**
- `tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php`: **APPROVE**
- `tests/Architecture/BughuntRawDbCommandInventoryTest.php`: **APPROVE**
- `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`: **REQUEST_CHANGES**

**指摘**
[Warning] `scripts/setup-worktree.sh`  
`provision_bughunt_env_file` の失敗が `if provision_bughunt_env_file ... && [[ -f ... ]]` の条件内で評価されるため、`install -m 600` が失敗しても `set -e` で止まらず、単なる「無いためスキップ」扱いで続行します。秘密ファイルコピーの失敗を隠すので、H-4 の契約として弱いです。

修正案:
```bash
if [[ -f "${REPO_ROOT}/.env.bughunt.local" ]]; then
    provision_bughunt_env_file "${REPO_ROOT}" "${WORKTREE_DIR}"
    PROVISIONED_PATHS+=(".env.bughunt.local")
else
    echo "    note: .env.bughunt.local が親に無いためコピーをスキップ (bug-hunt 未使用なら不要)" >&2
fi
```
あわせて、コピー失敗時に非ゼロで落ちる契約テストを `SetupWorktreeRuntimeFilesContractTest.php` に追加してください。

[Warning] `scripts/bug-hunt-shard.sh`  
`group_scan_once()` が `group_member_counts` の出力を「先頭 3 フィールドだけ」読んでおり、余分なトークンを不正として扱いません。設計は「3 値が非負整数」を fail-closed の安全弁にしているため、`0 0 0 garbage` のような壊れ方も失敗に倒すべきです。

修正案:
```bash
local -a parts
read -r -a parts <<< "${counts}"
[[ ${#parts[@]} -eq 3 ]] || {
    echo "error: ${label} の member 集計が不正 ('${counts}') — 確認不能として停止失敗に倒す" >&2
    return 1
}
live=${parts[0]}
zomb=${parts[1]}
unknown=${parts[2]}
```
self-test の y7m に「余分な 4 フィールド目」も追加してください。

[Warning] `scripts/bug-hunt-shard.sh` / self-test  
受入条件 1・9・10 の固定がやや静的または人工的です。特に y7c は `group_stopped` の stub 検証で、`stop_shard_workers` が zombie-only 成功時に pidfile を削除することまでは見ていません。y7h も `cmd_teardown` 本体ではなく、同等の if 断片を検証しています。

修正案:
- zombie-only 成功時に `stop_shard_workers` が pidfile を削除するケースを追加する。
- `cmd_teardown --drop-db` を stub 環境で呼び、worker 停止失敗時に `pg_admin_for_provision` が呼ばれないことを直接検証する。
- 停止失敗時の戻り値非ゼロと pidfile 保持を同じケースで見る。

[Suggestion] `scripts/bug-hunt-shard.sh`  
`cmd_self_test()` 冒頭コメントに「内部生成も現時点では削除しない」とありますが、末尾では `sandbox_owned == 1` の場合に削除しています。実装は設計どおりなので、コメントだけ直すのがよいです。

**補足**
設計との大枠の一致性は高いです。dev DB 防御も `pg_admin_for_provision` 集中、`--except=cache`、`env -i`、raw DB command inventory により後退していません。保証範囲も「証明ではなく検出」と明記されており、誇張は概ね抑えられています。上記 3 点を直せば approve 可能です。