# 概念設計レビュー Round 1 対応マトリクス (gpt-5.4)

全体判定: CHANGES_REQUESTED

| # | 分類 | 指摘 | 判断 | 対応 |
|---|------|------|------|------|
| 3 | Critical | `hasScenario`/`SCENARIO_PRESENT_BY_STATUS` の命名が「cuts 実在」を含意し、認めている draft+cuts と意味論矛盾。表示相判定と分かる名へ | 対応 | `SCENARIO_ESTABLISHED_BY_STATUS` / `isScenarioEstablished(status)` に改名。map/関数コメントで「シナリオ確定相 (ready 以降) の**表示判定**であり cuts 実在判定ではない」と明示 |
| 1 | Warning | published/rendering で解析 CTA が残り押すと 409。文言だけ直しても体験は崩れる。CTA も見直すか効果を文言整合に限定 | 対応 | 施策2追加: AI 解析ボタンの表示を解析可能 status (draft/ready) に限定 (`isAnalyzable` helper)。`AnalysisJobService` L63 が許可するのは Draft/Ready のみ = server truth と一致。prohibition#8 との区別を明記 |
| 4 | Warning | 「シナリオ有無を正しく判定できる」は過大。draft+cuts は拾えない | 対応 | 期待効果を「ready/rendering/published での誤案内解消」に限定。汎用 scenario presence 解決とは言わない |
| 5a | Warning | 将来 status 増加で「cuts 実在」と「表示相」が再混線 | 対応 | map コメント + テスト名で「表示相判定」を固定。改名 (#3) と併せて意味を固定 |
| 5b | Warning | published/rendering で再解析不可なのに別 UI が再解析可能に見えると新たな混乱 | 対応 | 施策2 (CTA 限定) で解消 |
| 6 | Warning | draft+cuts をスコープ外にするなら helper を汎用化しすぎるな。スコープを明記し別 finding 起票前提に | 対応 | スコープを「公開済み/書き出し中で未生成案内が出る不整合」に限定と明記。draft+cuts は別 finding として詳細設計リスク欄に記載 |
| 2,7 | Suggestion | 回帰テスト必須。5 status の表示分岐を固定するテストを追加 | 対応 | テスト計画に draft(±document)/analyzing/ready/rendering/published の分岐固定 + ボタン表示を含める |

論点回答:
- status ベース判定は最小変更目的に妥当。ただし命名を「状態相に基づく表示判定」に寄せる → 対応。
- draft+cuts スコープ外は別 issue 明示管理なら妥当 → 別 finding として記録。
