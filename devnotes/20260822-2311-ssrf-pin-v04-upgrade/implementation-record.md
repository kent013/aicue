# 実装記録: T243 kent013/laravel-ssrf-pin ^0.4 追従

詳細設計 `detailed-design.md` の「実装完了の条件（受け入れ基準）」8 項が要求する実測値の記録。

## 環境 (lock のメタデータ差分の原因追跡用)

| 項目 | 実測値 | 設計時点の値 | 一致 |
|---|---|---|---|
| `composer --version` | `Composer version 2.10.2 2026-07-01 11:24:45` | 同左 | ✓ |
| `php -v` | `PHP 8.4.24 (cli)` | 同左 | ✓ |
| `composer.lock` の `plugin-api-version` | `2.9.0` | 同左 | ✓ |

## 施策 B: lock の構造比較スクリプトの出力

```
OK: 252 -> 252 packages / 動いたのは kent013/laravel-ssrf-pin だけ (v0.2.0 -> v0.4.1)
```

除外したのは設計が定めた 3 つだけ (ルートの `content-hash` / 対象エントリ /
事前承認済み新規依存 = **0 件**)。残り (`packages` / `packages-dev` の全エントリ +
ルートの他キー全部) はセクション所属を保ったまま**完全一致**であった。
`assert` は 1 つも落ちていない。

対象エントリの中身 (4 点確認):

| 確認項目 | 期待値 | 実測 |
|---|---|---|
| `version` | `v0.4.1` | ✓ |
| `source.type` / `source.url` | `git` / `https://github.com/kent013/laravel-ssrf-pin.git` | ✓ |
| `source.reference` | `93ba837c661bf2c31b6801c4c9ad866bdff4445e` (注釈を剥がした commit) | ✓ |
| `require` | 上流 v0.4.1 の `composer.json` と一致 (`guzzlehttp/psr7 ^2.4` / `psr/http-message ^1.1 \|\| ^2.0` を含む) | ✓ |

補助確認:

- `composer validate --no-check-publish` → `./composer.json is valid`
- `composer install --dry-run` → `Nothing to install, update or remove` (lock と json が整合)
- `vendor/kent013/laravel-ssrf-pin/resources/ip-classification.json` の
  `registry_version` = **`2025-10-09`** (施策 C の R1 の pin 値と一致)

## 施策 C: 版上げ前の fail の実測 (テストファースト)

設計の予測は **14 件**。実測も **14 件**で一致した (`composer test -- --filter=SsrfPinSpecialPurposeRange`、
vendor は v0.2.0 のまま):

```
{"tool":"pest","result":"failed","tests":23,"passed":9,"assertions":29,"failed":13,"errors":1}
```

| 区分 | 予測 | 実測 | 内訳 |
|---|---|---|---|
| S1 (IANA 特殊用途 8 区間) | 8 fail | **8 fail** | 全ケース `allowed=true` = 素通り (`Failed asserting that true is false`) |
| S4 (A/AAAA 跨ぎの全件検査) | 4 fail | **4 fail** | family 交差 2 件と A 内・AAAA 内の複数 2 件がすべて `allowed=true` |
| R1 (登録簿の版) | 1 error | **1 error** | `Call to undefined method Kent013\SsrfPin\UrlSafetyInspector::classificationRegistryVersion()` |
| R2 (負のコントロール) | 1 fail | **1 fail** | 2 回目 (TEST-NET-1) が `allowed=true` |
| S2 (従来の拒否) | 緑 | **緑 (6)** | 判定の反転で挙動が変わらない = 「緩んでいない」ことの実測 |
| S3 (正のコントロール) | 緑 | **緑 (3)** | 公開到達可能アドレスは v0.2.0 でも allow |

版上げ後は全 23 ケース緑 (`composer test` 全数の中で確認)。

## 施策 0: 触らないことの実測

`git diff --name-only` と `git status --porcelain` の**両方で出力が空**であることを確認した
(4 パスをすべて明示指定):

- `config/ssrf-pin.php`
- `tests/Architecture/SsrfPinBoundaryTest.php`
- `tests/Support/SnsTestData.php`
- `app/Services/Mail/Sns/SnsCertificateFetcher.php`

`composer update` は `--with-all-dependencies` を付けず、
`vendor:publish --tag=ssrf-pin-config` も実行していない
(`post-update-cmd` の `--tag=laravel-assets` は `No publishable resources` で無害に終了)。

## 施策 D が「検査を緩めていない」ことの根拠

fixture `203.0.113.10` (TEST-NET-3) → `93.184.216.34` の差し替えは、
**「SSRF 検査を通る正常系」を表す値**の訂正である。^0.2 では TEST-NET-3 が
誤って allow されていたので**偶然成立していた**にすぎない。
拒否側の検査 (`SnsCertificateFetcherTest` L124 の private IP / L134 の DNS 解決失敗 /
`SesSignatureMiddlewareTest` L98 の private IP) は **1 つも触っていない**。
さらに施策 C の S1 が TEST-NET-3 を**拒否側**として明示的に固定したので、
以後「TEST-NET-3 を allow として使う」ことはできない。

## 乖離台帳の確認段

変更した全ファイル (`AGENTS.md` / `composer.json` / `composer.lock` / `tests/Pest.php` /
SNS 系テスト 3 本 / 新規 gate 1 本) を `docs/template-fingerprints.json` の
`entries` (**281 キー**) と `tests/Support/TemplateDivergence/adoption-debt.tsv` に
突き合わせた結果、**該当は 1 件も無い**。

→ `docs/template-divergence.md` への登録の追加も削除も無し。
`tests/Support/TemplateDivergence/LedgerPins.php` の 3 定数
(`DIVERGENCE_ENTRY_COUNT` / `FINGERPRINT_POPULATION_COUNT` / `ADOPTION_DEBT_COUNT`) も**据え置き**。
設計の「乖離台帳の確認段」の判定どおりである。

## VERIFICATION_COMMANDS 10 コマンドの実測 (全数 green)

| # | コマンド | 結果 |
|---|---|---|
| 1 | `composer test` | 6445 tests / 6443 passed / 2 skipped / 5 risky / exit 0 |
| 2 | `composer phpstan` (level 10) | No errors |
| 3 | `vendor/bin/pint --test` | passed |
| 4 | `pnpm lint` | clean |
| 5 | `pnpm typecheck` | clean |
| 6 | `pnpm test` | Test Files 173 passed (173) / Tests 2366 passed (2366) |
| 7 | `pnpm build` | ✓ built in 6.37s |
| 8 | `pnpm typecheck:packages` | clean |
| 9 | `pnpm build:packages` | clean |
| 10 | `pnpm test:packages` | Test Files 10 passed (10) / Tests 106 passed (106) |

## Codex 実装レビュー

- Round 1: `CHANGES_REQUESTED` — 指摘は **[Warning] 1 件のみ**で、内容は
  「`pnpm test` / `pnpm test:packages` / `composer validate` / `composer install` の
  実測値が記録に無い」という**証跡の不足**。コード修正要求は 0 件
  (「この不足が解消されれば、提示差分について追加のコード修正要求はありません」)。
  当該 2 レーンはグローバルテストロック待ちで Round 1 の時点では未完走だった。
- Round 2: **APPROVED** — 実測値を添えて再提出。コード差分は Round 1 から 1 行も変えていない。
