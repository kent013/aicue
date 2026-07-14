# 対応マトリクス: design-review Round 2

## [Critical] 施策4 xtrace 秘密漏洩が関数内 set +x だけでは閉じない (呼び出し側代入・プロセス起動で漏れる)
- 判断: 対応する
- 根拠: 本スクリプトは `set -euo pipefail`（-x 無し）だが、防御的に -x 有効時も漏らさない設計にする。
  `key="$(...)"` の代入・`LLM_KEY_ENV` 展開のプロセス起動は呼び出し側の xtrace で値が出る。
- 対応内容: xtrace 退避/復元ヘルパ `secret_xtrace_off` / `secret_xtrace_restore`（`case $- in *x*)` で保存）を
  導入し、**(1) build_mode_env の秘密取扱区間、(2) serve 起動、(3) worker 起動**の 3 箇所を挟む。main_env_get の
  内部 `set +x`（subshell 内）は belt-and-suspenders として残す。self-test は xtrace 有効実行 + stdout/stderr 別捕捉で
  ダミーキー非出力を検証。

## [Warning] 施策4 モードフラグ適用範囲判定が不完全 (teardown --real-llm が拒否されない)
- 判断: 対応する
- 対応内容: `_llm_flag_real` / `_llm_flag_fake` / `_storage_flag_real` を立て、**いずれかが指定されたら**
  subcommand が provision/provision-all 以外なら die 2。[z4] に `teardown --real-llm` / `self-test --real-storage`
  の exit 2 を追加。

## [Warning] 施策4 冒頭「変更箇所（機能単位）」の項目 3/5 が旧設計のまま
- 判断: 対応する
- 対応内容: 項目 3 を「MODE_ENV（フラグ）/ LLM_KEY_ENV（実キー）分離」、項目 5 を「serve/worker へ両配列、
  専用 verify へ MODE_ENV のみ、artisan_for_shard へは注入なし」に更新。

## [Warning] 施策4 [z3] が stdout だけでは不十分 (秘密は主に stderr の xtrace へ漏れる)
- 判断: 対応する
- 対応内容: [z3] を stdout/stderr 別捕捉 + 通常実行と xtrace 有効実行の双方でキー値非包含を検証、に強化。

## 施策8 REQUEST_CHANGES (施策4 の追加テスト不足のみ)
- 判断: 対応する
- 対応内容: 上記 [z3]/[z4] 強化で解消（施策 8-4 は施策 4 のテスト計画を参照）。
