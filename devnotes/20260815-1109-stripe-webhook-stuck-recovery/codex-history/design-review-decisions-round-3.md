# 対応マトリクス: design-review Round 3

## [Warning] 実装後の確認コマンドが AGENTS.md の必須検証集合を満たしていない
- 判断: 対応する
- 根拠: 指摘のとおり。AGENTS.md の検証コマンドは「全 green でコミット」が要件で、
  「フロント変更が無いから既存のまま緑のはず」は確認ではない。
- 対応内容: 実装後の確認コマンドを 10 本 (composer test / composer phpstan /
  vendor/bin/pint --test / pnpm lint / pnpm typecheck / pnpm test / pnpm build /
  pnpm typecheck:packages / pnpm build:packages / pnpm test:packages) に拡張した。
  併せてグローバルテストロック (T099) の待ち時間の扱い
  (待ちは正常 / kill しない / ロックファイルを消さない) を注記した。

## A〜F の判定
- すべて APPROVE。追加の設計変更なし。
