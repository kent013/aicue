## 施策別判定

- 施策1: **APPROVE**
- 施策2: **APPROVE**
- 施策3: **APPROVE**
- 施策4: **REQUEST_CHANGES**
- 施策5: **APPROVE**
- 施策6: **APPROVE**
- 施策7: **APPROVE**
- 施策8: **REQUEST_CHANGES**（施策4の追加テスト不足のみ）

## 残存指摘

- [Critical] `main_env_get()` 内の `set +x` だけでは秘密漏洩を防げません。`key="$(main_env_get ...)"` の代入完了時や、`env -i ... "${LLM_KEY_ENV[@]}"` の実行時に、呼び出し側の xtrace がキー値を出力し得ます。また「局所退避」とありますが、xtrace 状態を復元していません。  
  **修正案**: キー取得・配列格納・serve/worker 起動までを共通の秘密取扱関数で囲み、呼び出し前の xtrace 状態を `[[ $- == *x* ]]` で保存して `set +x`、処理後に `set -x` を復元する。少なくとも `build_mode_env` 内だけでなく、`LLM_KEY_ENV` を展開するプロセス起動箇所も対象にしてください。self-test は `bash -x` 相当で実行し、stdoutとstderrの双方にダミーキーがないことを検証します。

- [Warning] モードフラグの適用範囲判定が不完全です。現在の条件は非既定モードだけを見るため、`teardown --real-llm` は拒否されません。「全モードフラグが provision系専用」という仕様と不一致です。  
  **修正案**: `_llm_flag_real`、`_llm_flag_fake`、`_storage_flag_real` のいずれかが指定されたら subcommand を検査してください。`teardown --real-llm` と `self-test --real-storage` の exit 2 も `[z4]` に追加します。

- [Warning] 施策4冒頭の変更一覧が旧設計のままです。項目3は「real時のみ実キーを載せる」、項目5は「`artisan_for_shard` に `MODE_ENV` を展開」と記載され、改訂後の最小権限設計と矛盾します。  
  **修正案**: 項目3を `MODE_ENV` と `LLM_KEY_ENV` の分離、項目5を「serve/workerへ両配列、専用verifyへ`MODE_ENV`のみ、artisan_for_shardへは注入なし」に更新してください。

- [Warning] `[z3]` は「self-test標準出力」だけでは不十分です。秘密は主にstderrのxtraceへ漏れます。  
  **修正案**: stdout/stderrを別々に捕捉し、通常実行とxtrace有効実行の双方でキー値が含まれないことを検証してください。

## 全体判定

**CHANGES_REQUESTED**

Round 1のdotenv解析と最小権限の問題は適切に解消されています。残る本質的な問題は、xtrace有効時の秘密漏洩防止が関数内部だけでは完結しない点です。ここをプロセス起動まで含めて閉じれば承認可能です。