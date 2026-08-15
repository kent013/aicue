# Round 4: 詳細設計の修正版 (Round 3 の指摘反映)

## 対応マトリクス

| 指摘 | 判断 | 対応 |
|------|------|------|
| [Warning] 実装後の確認コマンドが AGENTS.md の必須検証集合を満たしていない | 対応する | 10 本すべて (composer test / composer phpstan / vendor/bin/pint --test / pnpm lint / pnpm typecheck / pnpm test / pnpm build / pnpm typecheck:packages / pnpm build:packages / pnpm test:packages) に拡張した。併せてグローバルテストロック (T099) の待ち時間の扱いを注記した |

A〜F は Round 3 ですべて APPROVE をもらったため、機能設計そのものは変更していない。

## 確認してほしい点

- 詳細設計として承認できるか。残る Critical / Warning があれば挙げてほしい

---

## 修正後の詳細設計書 (末尾の該当箇所のみ)

### 実装後の確認コマンド

AGENTS.md の検証コマンドは**全 green でコミット**が要件なので、フロント変更の有無に
関わらず全レーンを実行する (「変わっていないはず」は確認ではない)。

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
```

`composer test` / `pnpm test` / `pnpm test:packages` はホスト全体で 1 本ずつしか走らない
(T099 のグローバルテストロック)。待ちが出るのは正常で、30 秒ごとの heartbeat が
出ている間はハングではない。kill もロックファイルの手動削除もしない。
