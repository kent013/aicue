# 対応マトリクス: impl-review Round 1

## [Critical] usage() の固定行数依存 (`sed -n '2,54p'`) でモード説明が将来表示範囲外に落ちうる
- 判断: 対応する
- 根拠: 現状はモード行 (ヘッダ 32-38 行目) が範囲内で表示されているが、ヘッダ追記のたびに末尾の
  `set -euo pipefail` が範囲に近づき、固定行数はいずれ壊れる。要件「usage にモード表を明記」を
  構造的に保証すべき。
- 対応内容: `usage()` を行数固定 (`sed -n '2,54p'`) から動的切り出しへ変更。
  `awk 'NR==1{next} /^set -euo pipefail/{exit} {print}'` でヘッダコメント全体 (2 行目〜
  `set -euo pipefail` の直前) を出力。usage 出力に `set -euo pipefail` が漏れないこと・
  モード 3 フラグが全て出ることを確認済み。self-test も all passed。

## [Warning] cmd_provision と cmd_provision_all で preflight の dryrun 扱いに差がある
- 判断: 見送る (挙動は実質同一)
- 根拠: cmd_provision の `prepare_mode_and_preflight` は dryrun 早期 return の後段にあり、dryrun では
  到達しない = 実質スキップ。cmd_provision_all はループ前に早期 return が無いため `is_dryrun ||` を明示。
  両者とも「dryrun ではキー検証を実行しない」で挙動一致。cmd_provision 側に到達しない is_dryrun 判定を
  足すのは冗長。self-test [z4] で dryrun provision (--fake-llm/--real-storage) が 0 で通ることを固定済み。

## [Suggestion] main_env_get は `KEY = value` (= 前後空白) を拾わない
- 判断: 対応する (コメント明示のみ)
- 根拠: dotenv 標準は `KEY=value` (空白なし)。挙動変更は不要だが、誤解防止のためコメントで前提を明示。
- 対応内容: main_env_get の docコメントに「dotenv 標準どおり `KEY=value` で `=` 前後に空白を置かない前提」を追記。

## その他 [Suggestion] (docblock 1 行要約 / config 2 回呼び等)
- 判断: 見送る
- 根拠: 品質上問題なしと Codex も明記。過剰な整形はスコープ外 (オーバーエンジニアリング回避)。
  既存 docblock/テストの読みやすさは十分。
