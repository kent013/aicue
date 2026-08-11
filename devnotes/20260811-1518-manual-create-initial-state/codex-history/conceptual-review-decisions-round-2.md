# 対応マトリクス: conceptual-review Round 2

Codex 全体判定: CHANGES_REQUESTED (Critical 0 / Warning 1 / Suggestion 6)。
Round 1 の Warning 3 件は「解消済み」と確認された。

## [Warning] AGENTS.md ドメイン規約 1 の正本文面との矛盾が残っている

- 判断: **対応する**
- 根拠: 指摘は正しい。規約 1 の文面は「書き込む**全経路**は、対象 VideoManual 行を
  `lockForUpdate()` で取得した同一トランザクション内で反映する」と例外なく書いており、
  `duplicate()` は**既にこの文面を literal には満たしていない** (対象行が未存在)。
  つまり**矛盾は本設計が持ち込むものではなく、T066 の時点で既に発生していた**。
  下位ドキュメント (docs/architecture.md / inventory docblock) だけで例外を説明すると、
  「正本を読んだ人が下位ドキュメントを読むまで矛盾に気づかない」状態が固定化する。
  スコープ拡大ではなく**今回顕在化した既存ドリフトの是正**であり、思考原則 2 に反しない
  (「あったら便利」ではなく、規約の正確さそのものが壊れている)。
- 対応内容: 施策 5 として **`AGENTS.md` ドメイン規約 1 の最小改訂**を追加する。
  規約を 2 分類にする —
  (i) **既存行の更新**: 対象 VideoManual 行を `lockForUpdate()` した同一 tx 内で反映
  (ii) **新規生成**: 対象行は未存在のため、所有元 Project 行を `lockForUpdate()` した
       同一 tx 内で INSERT 時に初期状態 (`status` / `scenario_version`) を明示代入する
       (DB default に依存しない)
  同一の 2 分類語を `ScenarioWritePathInventoryTest` の経路表 docblock と
  `docs/architecture.md` §シナリオ整合の共有不変条件 にも使い、**正本・設計・inventory の
  三者を同じ語彙で一致させる**。規約の**追加**ではなく**既存規約の適用範囲の明確化**であり、
  既存の準拠実装 (`ScenarioService` 等の更新経路) の要求は 1 ミリも緩めない。

## [Suggestion] mutation ② は status / scenario_version を個別に除去する

- 判断: **対応する** (Suggestion だが実質的に正しい)
- 根拠: 両方を同時に消すと、先に評価される assertion で停止して**もう片方の保証を
  実証できない**。mutation の目的は「どの assertion がどの実装行を守っているか」を
  1:1 で見ることなので、個別除去でなければ意味が薄い。
- 対応内容: mutation ② を ②-a (`status` のみ除去) / ②-b (`scenario_version` のみ除去) に分割し、
  それぞれ**対応する assertion が赤くなること**を確認する手順にする (詳細設計に記載)。

## [Suggestion] 使命との整合性 / 実現可能性 / 期待効果 / スコープ / 型安全性

- 判断: **見送る** (いずれも設計の現状を追認する肯定的評価)
- 根拠: 設計変更を要さない。
