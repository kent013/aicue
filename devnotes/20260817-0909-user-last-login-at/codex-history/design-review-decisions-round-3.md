# 対応マトリクス: design-review Round 3

**全体判定: APPROVED**。施策 A〜G すべて APPROVE。Critical 0 件 / Warning 0 件 / Suggestion 1 件。
Round 1 で出した反論 2 件（Filament admin guard を構造で保証する / 索引の明示名を採らない）は
Round 2 に続き**維持できると判定**された。

---

## [Suggestion] 施策 D: 実行計画を断定しない表現へさらに弱める

- 判断: **対応する（修正必須ではないが、正確なので取り込む）**
- 根拠: 指摘が正しい。「一致した索引エントリごとに heap を参照して値を取りに行く」は
  **index scan を選んだ場合の説明**であり、PostgreSQL は統計情報しだいで
  seq scan / bitmap heap scan も選ぶ。実行計画を断定する書き方は、
  本リポジトリの「保証範囲を誇張しない」規約と、同じ Round 2 で自分が受け入れた
  「性能を断定しない」という修正方針の両方に照らして一貫しない。
  Codex は「修正必須の Warning ではない」としているが、
  **同じ節の中で断定を撤回したばかりなので、断定の残骸を残さない**。
- 対応内容: migration の doc comment を
  「既存索引には集約対象の `occurred_at` が含まれないため、選択された実行計画では
  heap から値を取得する必要がある（どの走査を選ぶかは統計情報しだいなので、
  実行計画を断定しない）」に差し替えた。

---

## 設計フローの完了

- 概念設計: Round 1 で APPROVED（Critical 0 / Warning 3 → 全件対応）
- 詳細設計: Round 3 で APPROVED（Round 1: Critical 2 + Warning 7 / Round 2: Warning 4 / Round 3: 0）
- 反論して通した判断: 2 件（`metadata.guard` で絞らない / 索引の明示名を直書きしない）
- 撤回した記述: 1 件（索引の性能効果を「行数の増加に耐える」「最大値の取得に効く」と
  書いていたのは誤りで、得られるのは index-only scan の候補化までである）
