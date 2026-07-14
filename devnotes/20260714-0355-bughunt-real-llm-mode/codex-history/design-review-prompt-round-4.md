# 詳細設計レビュー Round 4 — Round 3 指摘への対応報告

## [Critical] assert_llm_key_present が 4 つ目の未保護な秘密取扱箇所 → 対応 (Codex 提案を採用)

キー読取を build_mode_env の 1 箇所に集約し、assert は構築済み LLM_KEY_ENV を検査（再読しない）:

```bash
MODE_ENV=(); LLM_KEY_ENV=()   # グローバル空初期化（set -u 安全）

assert_llm_key_present() {
    [[ "${LLM_MODE}" == "real" ]] || return 0
    secret_xtrace_off
    if [[ "${#LLM_KEY_ENV[@]}" -ne 1 || "${LLM_KEY_ENV[0]}" == "ANTHROPIC_API_KEY=" ]]; then
        secret_xtrace_restore
        die 1 "real-llm（既定）だが ... ANTHROPIC_API_KEY が無い/空です。... --fake-llm ...（キー値はログに出しません）"
    fi
    secret_xtrace_restore
}

prepare_mode_and_preflight() { build_mode_env; assert_llm_key_present; }  # build_mode_env が唯一のキー読取
```

## [Warning] 秘密取扱箇所「3 箇所」断定の是正 → 対応

「キー読取は build_mode_env の 1 箇所のみ。xtrace ガード区間は 4 箇所（build_mode_env / assert_llm_key_present /
serve 起動 / worker 起動）」に再定義。

## [Warning] [z3] が assert の成功経路を xtrace 付きで検証していない → 対応

[z3]: ダミーキーありで **prepare_mode_and_preflight（build_mode_env + assert）の成功経路**を通常 + `set -x` で実行し、
stdout/stderr 双方にダミーキー値が現れないことを確認。MODE_ENV にキー値が含まれないことも確認。

---

これで秘密取扱の全区間（唯一のキー読取 = build_mode_env / assert / serve / worker）が xtrace ガードで閉じ、
[z3] が共通 preflight 単位で成功経路を検証します。全体判定をお願いします。
