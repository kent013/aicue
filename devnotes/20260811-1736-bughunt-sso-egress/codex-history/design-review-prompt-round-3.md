# Round 3: 詳細設計の修正 (検証コマンド一覧)

## 対応マトリクス

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

---

## 修正後の該当節 (detailed-design.md 末尾「検証コマンド」)

## 検証コマンド（全 green でコミット）

`AGENTS.md` の `VERIFICATION_COMMANDS` マーカー内が正本（`verification-commands-doc-sync.test.ts` が
`package.json` との同期を deny-by-default で強制する）。**「フロント変更なし」を理由に省略しない**。

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
scripts/bug-hunt-shard.sh self-test
```

`pnpm` 系・`packages` 系は本 PR で変更が無いが、レーン既定として全て実行する。
`scripts/bug-hunt-shard.sh self-test` は施策 8 の周辺（guard / 資源導出 / env 隔離）を
壊していないことの確認。
`composer test:browser` は UI 無変更のため必須ではない（回すなら任意）。

---

他の節は Round 2 提示分から変更していません。
再レビューをお願いします。施策ごとの判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
