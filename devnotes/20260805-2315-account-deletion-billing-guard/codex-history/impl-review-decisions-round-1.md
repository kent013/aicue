# 対応マトリクス: impl-review Round 1

## [Warning] AccountDeletionBillingGuard::hasLiveBillingObligation の `instanceof` fail-open

- 判断: **対応する**
- 根拠: 指摘のとおり。課金ガードで想定外の型を黙って読み飛ばすと「課金あり」を「なし」と誤答し、
  宙づり課金を通す (fail-open)。ガードは fail-closed であるべき。
- 対応内容: `if ($subscription instanceof Subscription && ...)` を
  `Assert::isInstanceOf($subscription, Subscription::class);` + 判定に変更し、
  想定外型は例外で落とす形にした (コメントで理由も明記)。PHPStan level 10 は narrowing 済みで green。

## [Warning] docs/architecture.md の redaction 注記に出典 URL が無い

- 判断: **一部対応する (URL は書かない)**
- 根拠: 一次情報の URL は**リポジトリにも c2c 台帳にも存在しない**。台帳
  (feature `account-deletion-billing-guard` の handover / 裁定 AG-033、2026-08-05) は
  「決済事業者は…と規定」という要約のみで URL を pin していない。ここで URL を推測して書くと
  「確認していない一次情報を確認したかのように固定する」ことになり、外部仕様を鵜呑みにしないという
  設計意図に反する。
- 対応内容: 出典を「c2c 台帳 feature `account-deletion-billing-guard` の handover / 裁定 AG-033
  (確認日 2026-08-05)」と明記し、**一次情報の URL が pin されていない事実**と
  「数値を運用に効かせる前に一次情報を引き直し、URL と確認日をここへ追記すること」を書いた。

## [Warning] `composer test` 全体の green が未確認

- 判断: **対応する**
- 根拠: そのとおり。レビュー送信時点では実行中だった。
- 対応内容: 完走を確認した (3160 tests / 3158 passed / 0 failed / 2 skipped、assertions 12243)。
  併せて `composer phpstan` (No errors) / `vendor/bin/pint --test` (passed) /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` (124 files・1216 tests passed) / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (10 files・106 tests) も green。

## [Suggestion] AccountDeletionBlockerDto が空 reasons を許す

- 判断: **見送る**
- 根拠: 唯一の呼び出し元 (`organizationsBlockingDeletion`) が `$reasons === []` を先に弾いており、
  空 reasons の DTO は生成されない。ここに追加の防御を足すのは「今必要なものだけ作る」に反する
  (AGENTS.md 思考原則 2)。契約は phpdoc とテストで固定済み。
