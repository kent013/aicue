# 対応マトリクス: conceptual-review Round 2

## [Critical] `'001'` が FILTER_VALIDATE_INT を通過する前提が誤り (観点 3)
- 判断: **対応する (指摘が正しい)**
- 根拠: PHP 8.4 で実測した結果、`filter_var('001', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])`
  は **false**。逆に `'1 '` / `' 1'` / `'+1'` は **int(1) として受理**される。
  設計書の記述が実挙動と逆だった。`sprintf('%03d', $id)` が id >= 100 でゼロ埋めにならない
  という指摘も正しい。
- 対応内容:
  - §4-3 に **実測表**を追加し、`'001'` / `'07'` を 422 側へ移動。
  - 先頭ゼロ受理の要件は無いので正規化は入れない (今必要ないものを作らない) と明記。
  - §7-1 の境界値テストを「`'001'` → 422」「member 組織 id の前後空白付き文字列 → 通過」に差し替え
    (ゼロ埋めではなく空白で「受理される表記ゆれ」を固定する)。
- 副産物: **実コードのコメントが誤っている**ことが判明した
  (`McpConsentOrganizationBinder` の「`"1 "` を reject」)。挙動は変えずコメントを訂正する
  施策を §4-5 に追加した。

## [Warning] MCP の形式境界が不正確なので 422/403 契約を承認できない (観点 8)
- 判断: 対応する (上記 Critical と同一。実測表で同期済み)

## [Warning] 検証コマンドが AGENTS.md の必須セットを満たしていない (観点 2)
- 判断: **対応する**
- 根拠: AGENTS.md は `VERIFICATION_COMMANDS` マーカーで正本を持ち、
  `verification-commands-doc-sync.test.ts` が package.json と同期を強制している。
  設計書に部分列挙を書くと二重管理になる。
- 対応内容: §7-2 を「開発中の絞り込み実行」と「完了条件 = AGENTS.md の
  VERIFICATION_COMMANDS 全部が green」に分け、写経をやめた。

## [Suggestion] 使命 / 効果 / スコープ / 型安全性 (Round 1 の対応を評価)
- 判断: 見送る (肯定的評価。PHPStan level 10 を完了条件から外さない点は §7-2 で担保)
