# 対応マトリクス: design-review Round 5 (全体判定 APPROVED)

Round 5 で **APPROVED**。施策 1〜11 のすべてが APPROVE。
Critical / Warning は 0 件。非ブロッキングの [Suggestion] 4 件はすべて反映した。

## [Suggestion] 「母集団と 4 分類」の表が 3 行しかない
- 判断: **対応する**
- 根拠: 後段で定義した「自己検査専用」が表に無く、本文と表が食い違っていた。
- 対応内容: 表へ第 4 行
  **「自己検査専用（名指し + 件数）」**（負例 fixture と抽出器の自己テスト。
  `LegacyUrlSelfCheckPopulationTest` がファイル名と検出語の一致件数を完全一致で pin）を追記した。

## [Suggestion] `capture-sw.js` の「該当が無ければ登録しない」と「件数 0 で明示登録」が併記
- 判断: **対応する**
- 根拠: 0 件登録は目録を膨らませるだけである。
- 対応内容: **「該当が無いので登録しない」**へ統一した。
  実測で `public/capture-sw.js` に navigation fallback としての `/app` は存在しない。
  「登録が無い = 検出対象」という既定のほうが明快である旨も添えた。

## [Suggestion] `route('capture.entry')` は route 名なので許可目録に載せる必要が無い
- 判断: **対応する**
- 根拠: 抽出器が見るのは **URL 文字列**であって route 名ではないので、検出結果を発行しない。
  検出されないものを目録に載せると「目録が何を守っているか」が曖昧になる。
- 対応内容: 許可目録から `route('capture.entry')` の行を外し、
  **「許可目録は実際に検出される正規入口の出現だけに exact-fit させる」**と明記した。
  入口への導線は **route helper 経由だけを許す**（URL 直書きは旧 URL として検出される）ことも残した。

## [Suggestion] 許可 rule ID は構文文脈まで識別する安定 ID にする
- 判断: **対応する**
- 根拠: `legacy-path` のような粗い ID だと、同じファイル内で別の裸の `/app` と
  置き換わっても件数だけで通ってしまう。
- 対応内容: rule ID を **`manifest-start-url` / `capture-entry-route-definition`** の形へ改め、
  「rule ID は構文文脈まで識別する安定 ID にする」理由と併せて本文に書いた。

---

**Phase 2 完了。詳細設計 APPROVED (design-review Round 5)。**

- 概念設計: APPROVED (conceptual-review Round 4)
- 詳細設計: APPROVED (design-review Round 5)
