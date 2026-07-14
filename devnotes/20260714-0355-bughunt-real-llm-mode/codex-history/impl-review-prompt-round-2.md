# 実装レビュー Round 2 (T036)

Round 1 の指摘への対応です。全体判定の再評価をお願いします。

## [Critical] usage() 固定行数依存 → 動的切り出しへ修正 (対応済み)
`usage()` を `sed -n '2,54p'` (行数固定) から下記へ変更しました:
```bash
usage() {
    # ヘッダコメント (2 行目〜 `set -euo pipefail` の直前) を動的に切り出す。行数固定依存を避け、
    # ヘッダにモード表などを追記しても usage が確実に全文を出す (Codex 実装レビュー R1 Critical 反映)。
    awk 'NR==1{next} /^set -euo pipefail/{exit} {print}' "${SCRIPT_PATH}" | sed 's/^# \{0,1\}//'
    exit 2
}
```
検証: usage 出力にモード 3 フラグ (--real-llm/--fake-llm/--real-storage) が全て出る / `set -euo pipefail` が漏れない (grep -c = 0) / self-test all passed。

## [Warning] cmd_provision vs provision_all の preflight dryrun 差
挙動は実質同一のため見送り。cmd_provision の prepare_mode_and_preflight は dryrun 早期 return の後段にあり dryrun では到達しない (= 実質スキップ)。provision_all はループ前に早期 return が無いため `is_dryrun ||` を明示。両者とも「dryrun ではキー検証しない」で一致。self-test [z4] で dryrun provision (--fake-llm/--real-storage) が 0 で通ることを固定済み。

## [Suggestion] main_env_get の `KEY = value` 空白
コメントで前提を明示しました (「dotenv 標準どおり `KEY=value` で `=` 前後に空白を置かない前提」)。挙動変更なし。

## その他 Suggestion (docblock 要約 / config 2 回呼び)
オーバーエンジニアリング回避のため見送り (Codex も品質上問題なしと明記)。

---

### scripts/bug-hunt-shard.sh の該当差分 (Round 1 → Round 2)
```diff
 usage() {
-    sed -n '2,55p' "${SCRIPT_PATH}" | sed 's/^# \{0,1\}//'
+    # ヘッダコメント (2 行目〜 `set -euo pipefail` の直前) を動的に切り出す。行数固定依存を避け、
+    # ヘッダにモード表などを追記しても usage が確実に全文を出す (Codex 実装レビュー R1 Critical 反映)。
+    awk 'NR==1{next} /^set -euo pipefail/{exit} {print}' "${SCRIPT_PATH}" | sed 's/^# \{0,1\}//'
     exit 2
 }
 
@@ -1649,6 +1903,10 @@ main() {
```

全品質ゲート (composer test 1655 / phpstan / pint / pnpm lint,typecheck,test,build / self-test / inventory-check) は再確認して全て green です。
残 Critical/Warning があれば指摘を、なければ APPROVED をお願いします。
