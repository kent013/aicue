## 施策別判定

- 施策1〜3: **APPROVE**
- 施策4: **REQUEST_CHANGES**
- 施策5〜7: **APPROVE**
- 施策8: **REQUEST_CHANGES**（施策4のテスト追加のみ）

## 残存指摘

- [Critical] `assert_llm_key_present()` が4つ目の秘密取扱箇所として未保護です。`set -x` 時、`local key; key="$(main_env_get ...)"` の代入トレースに実キーが出る可能性があります。`main_env_get()` 内の `set +x` は呼び出し側の代入トレースを防げません。  
  **修正案**: `assert_llm_key_present()` 全体も `secret_xtrace_off` / `secret_xtrace_restore` で囲む。ただし重複読込を避け、`build_mode_env()` が構築した `LLM_KEY_ENV` の存在をガード内で検査する方が単一正本になります。例:
  ```bash
  assert_llm_key_present() {
      [[ "${LLM_MODE}" == "real" ]] || return 0
      secret_xtrace_off
      if [[ "${#LLM_KEY_ENV[@]}" -ne 1 || "${LLM_KEY_ENV[0]}" == "ANTHROPIC_API_KEY=" ]]; then
          secret_xtrace_restore
          die 1 "..."
      fi
      secret_xtrace_restore
  }
  ```
  `set -u` を考慮し、配列要素参照前に長さを検査してください。

- [Warning] 設計文では秘密取扱箇所を「3箇所」と断定しているため、上記漏れを誘発しています。  
  **修正案**: 「キー取得・検査」「serve起動」「worker起動」の3区間に再定義し、`build_mode_env()`と`assert_llm_key_present()`を同じガード区間で連続実行する、または秘密取扱箇所を4箇所へ更新してください。

- [Warning] `[z3]` が `assert_llm_key_present()` の成功経路をxtrace付きで検証していません。キー欠如時は値がないため漏洩検査になりません。  
  **修正案**: ダミーキーありで `prepare_mode_and_preflight()` 全体を `set -x` 下で実行し、stdout/stderr双方への非露出を確認してください。

## 全体判定

**CHANGES_REQUESTED**

Round 2の指摘はほぼ解消しています。残件は `assert_llm_key_present()` のxtrace境界だけで、これを閉じて `[z3]` を共通preflight単位に拡張すれば承認可能です。