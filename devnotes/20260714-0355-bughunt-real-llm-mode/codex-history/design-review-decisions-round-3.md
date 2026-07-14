# 対応マトリクス: design-review Round 3

## [Critical] assert_llm_key_present が 4 つ目の未保護な秘密取扱箇所 (代入トレースでキー露出)
- 判断: 対応する（Codex 提案の形をそのまま採用）
- 対応内容: assert_llm_key_present はキーを再読せず、**build_mode_env が構築した LLM_KEY_ENV を検査**する
  （キー読取は build_mode_env の 1 箇所のみ = 単一正本）。全体を secret_xtrace_off/restore で囲み、set -u 安全の
  ため配列長を先に検査（`${#LLM_KEY_ENV[@]} -ne 1 || LLM_KEY_ENV[0]=="ANTHROPIC_API_KEY="`）。
  MODE_ENV/LLM_KEY_ENV をグローバルで空初期化（assert 単独呼びでも set -u で壊れない）。

## [Warning] 秘密取扱箇所を「3 箇所」と断定して漏れを誘発
- 判断: 対応する
- 対応内容: 「キーの読取は build_mode_env の 1 箇所のみ。xtrace ガード区間は 4 箇所（build_mode_env / assert /
  serve / worker）」に再定義。

## [Warning] [z3] が assert_llm_key_present の成功経路を xtrace 付きで検証していない
- 判断: 対応する
- 対応内容: [z3] を「ダミーキーありで prepare_mode_and_preflight 全体を通常 + set -x で実行し stdout/stderr
  双方に非露出」に拡張。
