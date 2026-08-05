**レビュー仮説**
A1 は `/u` 欠落を静的に捕まえ、コメントや通常文字列で偽赤にならないこと。A2 は dead pid race だけを許容し、取得時の strict probe を弱めないこと。この条件で差分を確認しました。

**scripts/global-test-lock.sh**
指摘なし。  
`pgid=""` 初期化 + `pgid="$(...)" || pgid=""` は `set -euo pipefail` 下の代入失敗を局所的に吸収しており、直後の「空なら race として許容」という既存契約に到達できます。`_gtl_probe_process_group()` 側は空なら成功せずリトライ後に die する構造なので、strict 判定も維持されています。

**scripts/verify-global-test-lock.sh**
指摘なし。  
C25 は実レーンと同じ「裸呼び出し + `set -euo pipefail`」を再現しており、設計補足の逸脱は妥当です。C26 も単なる非ゼロ終了ではなく、probe 固有メッセージと acquire 到達証跡を見ており、偽グリーン対策として十分です。

**scripts/run-browser-test.contract.test.ts**
指摘なし。  
`sleep 0.1` 撤去が実際に `global_test_lock_run` の best-effort probe race を固定する契約テストになっています。コメントも回避策撤去の理由を明示できています。

**tests/Architecture/PcreUnicodeModifierGateTest.php**
[Suggestion] 現状の `PCRE_DELIMITERS` は設計どおり「このリポジトリで実際に使われているもの」に限定されていますが、PHP の PCRE delimiter としては `{}` / `[]` / `()` / `<>` なども合法です。将来それらで `\R` が書かれると gate は見逃します。今回の詳細設計には一致しているため blocking ではありませんが、deny-by-default をより強くするなら bracket delimiter の正/負コントロールを追加する余地があります。

それ以外は指摘なし。`PhpToken` 走査、コメント除外、通常文字列除外、`\\R` と `\R` の区別、P15 の明示的な射程外固定は妥当です。DB・実ロック・dev 環境には触れていません。

**tests/Architecture/GlobalTestLockInventoryTest.php**
指摘なし。  
対象 2 箇所の `/\R/u` 化は設計どおりです。日本語コメント断片の漏出を防ぐ目的に合っています。

**tests/Architecture/BughuntOrchestratorGateInvariantTest.php**
指摘なし。  
`preg_split('/\R/u', ...)` への変更は A1 の不変条件に一致しています。

**tests/Feature/Mail/MailThemeDesignParityTest.php**
指摘なし。  
設計の探索漏れで見つかった 4 件目として `/su` 化する判断は妥当です。DESIGN.md が日本語を含む前提では実バグ修正として扱えます。

**全体判定**
APPROVED