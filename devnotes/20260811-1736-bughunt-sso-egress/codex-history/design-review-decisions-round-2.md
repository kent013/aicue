# 対応マトリクス: design-review Round 2

## [Warning] 検証コマンドが AGENTS.md の必須一覧を満たしていない
- 判断: **対応する**
- 根拠: 指摘のとおり。`AGENTS.md` の `VERIFICATION_COMMANDS:BEGIN/END` マーカー内が正本で、
  `tests/js/architecture/verification-commands-doc-sync.test.ts` が `package.json` の
  検証系 script との同期を deny-by-default で強制している。
  「フロント変更なし」は省略の理由にならない（レーン既定である）。
- 対応内容: 検証コマンド節を AGENTS.md の全 10 コマンド
  （`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
  `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages` / `pnpm test:packages`）へ更新し、
  施策 8 の周辺確認として `scripts/bug-hunt-shard.sh self-test` を併記した。
  正本が AGENTS.md であることも本文に明記した。

## その他
- Round 1 の 4 件（regex 化・closure 化・google 前提の明示・provider 文字種検査の見送り）は
  Round 2 で問題なしと確認された。追加対応なし。
