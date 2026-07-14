# 対応マトリクス: conceptual-review Round 1

全体判定: **APPROVED** (Critical なし)。以下 Warning を詳細設計へ反映する。

## [Warning] F-3-02 の成功条件が定性的 (「最低限読める」の基準が曖昧)
- 判断: 対応する
- 根拠: 実装者判断で min-w 値がぶれるのを防ぐため、受け入れ条件を数値で固定する。
- 対応内容: 詳細設計で受け入れ条件を明文化 —
  (a) 768px 相当で名前/メール列が最小幅の床 (min-w-40 = 10rem) を保つ、
  (b) 入りきらない幅では操作ブロックが次行へ回り込む、
  (c) bug-hunt 再現名「Unverified User」相当が数文字潰れにならない。

## [Warning] min-w 値次第で中間サイズで折り返しが早すぎる懸念
- 判断: 対応する
- 根拠: 床を大きく取りすぎると 834px (iPad portrait) でも不要な折り返しが起きる。
- 対応内容: 床は min-w-40 (10rem/160px) に抑える。詳細設計の検証計画に
  「768px 前後で折り返す・834px 前後では 1 行を保つ」の 2 点確認を追記。

## [Warning] F-4-03 は bind:value / イベント契約が既存 Input と同等か確認せよ
- 判断: 対応する
- 根拠: PasswordInput は Input を内包し value を $bindable で透過するが、
  差し替え後の送信配線 (passwordForm.put) が維持されることを回帰で担保する。
- 対応内容: SettingsIndex.test.ts に
  (1) 2 フィールドが正しいラベルで取得できる、(2) 各トグルで type=password↔text 切替、
  (3) submit で passwordForm.put('/user/password') が呼ばれる、を追加。

## [Warning] F-3-02 は招待行も同根。片側だけでは回帰防止が弱い
- 判断: 対応する
- 根拠: 招待行 (email 列) も同一構造の潜在バグ。両方に適用し両方をテストする。
- 対応内容: 詳細設計でメンバー行・招待行の両方に min-w 床 + sm:flex-wrap を適用。
  AdminUsers.test.ts に両行の最小幅クラス + sm:flex-wrap 検証を追加。

## [Suggestion] 「見た目修正だからテスト不要」に流れない
- 判断: 対応する (方針として明記)
- 対応内容: 詳細設計のテスト計画を各施策に必須として記載。
