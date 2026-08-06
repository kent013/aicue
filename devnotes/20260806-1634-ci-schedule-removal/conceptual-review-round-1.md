全体判定: **APPROVED**

設計の方向は妥当です。裁定を蒸し返さず、実装対象を `ci.yml` / inventory gate / 関連ドキュメントに絞れており、「schedule を消す」だけでなく「schedule を強制していたテストを反転する」点を本丸として扱えているのが正しいです。

## 1. 除去の完全性

[Warning] `nightly` 検索の対象に `tests/` を含めるなら、W12/W15 のテスト名・失敗メッセージ・負のコントロール fixture でも `nightly` を使わない設計にしてください。  
修正提案: W12/W15 はすべて `schedule` / `定期実行` 表現に寄せ、検証条件 `rg -n -i "nightly" AGENTS.md docs/ tests/ scripts/ --glob '!TODO-closed.md'` と矛盾しないようにする。

[Suggestion] `ci.yml` の再導入防止コメントはやや長いですが、過去に再導入された経緯を考えると許容範囲です。可能なら「裁定」「受容済み損失」「CI に戻さない」「W12 が止める」に絞ると保守しやすいです。

## 2. 禁止事項違反

[Suggestion] 禁止事項 1 との関係では、`pnpm test` だけでなく W12/W15 の負のコントロールを実測する方針が入っているため十分です。実装完了報告では、負のコントロールでどの gate が fail したかを明記してください。

## 3. 機械ゲートの設計

[Warning] W15 の「どの job も `if` を持たない」は再導入防止として強い一方、将来の正当な job-level 条件も止めます。  
修正提案: テスト本文またはコメントに「job-level `if` は deny-by-default。必要な場合は理由付き allowlist に登録する」と明記してください。設計文にはありますが、実装側のテストにも残すべきです。

[Suggestion] W12 の trigger 完全一致は妥当です。`workflow_dispatch` は裁定上 optional なので、現時点で追加しない判断もスコープ管理として正しいです。

## 4. リスク

[Suggestion] `supply-chain-audit` job 自体を維持する境界が明確なので、重大な副作用は見当たりません。失われる先行検知・expiry 自動検出についても、docs に「受容済み」と明記する方針で十分です。

## 5. スコープの適切さ

[Suggestion] 小さく保てています。`audit-gate` 本体、accepted advisory 台帳、外部 cron、`workflow_dispatch` を触らない判断は妥当です。

## 6. 検証方法の妥当性

[Warning] 静的検証で `ci.yml` は `schedule` コメントを許容するため `^\s*schedule:|github\.event_name` を見る、という補正は正しいです。ただし `github.event_name` 以外の schedule 代替条件、例えば `if: contains(...)` などは rg だけでは拾えません。  
修正提案: 最終判定は rg ではなく W12/W15 の YAML 構造テストを正とし、rg は補助検査として扱う、と実装報告でも明記してください。

総評として、変更要求に相当する設計欠陥はありません。上記 Warning は実装時の取りこぼし防止であり、概念設計としては承認できます。