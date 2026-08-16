# 対応マトリクス: design-review Round 5 (APPROVED)

Round 5 で全体判定 **APPROVED**、全 6 施策が APPROVE。Critical / Warning は 0 件。

## [Suggestion] 施策 F: 「3 レーンが互いに交差しない」は primary × secondary も含意する

- 判断: **対応する**
- 根拠: 指摘のとおり、実際の機械保証は `guide × primary` と `guide × secondary` の 2 組で、
  `primary × secondary` は検査していない。「3 レーンが互いに交差しない」という言い方は
  検査していない組まで保証しているように読める = **保証範囲の誇張**であり、
  本リポジトリが繰り返し戒めている書き方に当たる。
- 対応内容: 詳細設計と `docs/supported-browsers.md` 追記文の両方を
  **「撮影ガイドが上下の字幕帯のいずれとも交差しないこと」**へ書き換えた。
  あわせて「`primary × secondary` は本設計が触っていない既存 component 内部の配置なので
  検査対象に含めない」と理由を明記した。

## 最終確認 (Phase 2-5)

| 観点 | 結果 |
|---|---|
| 全施策が使命に寄与するか | 寄与する。横持ちで「向ける・録る・次へ」を同一画面に閉じ、撮影以外の操作負荷を減らす |
| 禁止事項に違反していないか | 違反なし。PHP 変更 0 行のため PHPStan の widen / baseline も発生しない。端のボタンと録画中の移動はどちらも `disabled` にせず押下時に告知する (禁止事項 8) |
| コーディングルールが設計に反映されているか | 全施策にテストを対応させ、不変条件 1〜6 を機械固定。DTO / JsonResource は変更なし (サーバ側 0 変更)。Browser は Chromium + WebKit の 2 レーン |

## 実装着手の前提 (Codex の最終コメント)

記載した全検証コマンドと **Chromium / WebKit 両レーンの green** をもって最終承認とする。
