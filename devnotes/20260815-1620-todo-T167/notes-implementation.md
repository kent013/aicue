# T167 実装メモ: declare(strict_types=1) の全数強制 gate

- 設計: `devnotes/20260815-1534-strict-types-baseline-gate/detailed-design.md`
- ブランチ: `todo/T167`

## 実装順序 (設計どおり 2 → 1 → 3 → 4 → 5)

1. 施策 2: `tests/Support/StrictTypesDeclarationScanner.php` /
   `tests/Support/StrictTypesRuntimeProbe.php` /
   `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php`
2. 施策 1: `tests/Support/TrackedPhpSourceFiles.php` /
   `tests/Unit/Architecture/TrackedPhpSourceFilesTest.php` /
   `tests/Architecture/NoNonCompoundGlobalUseTest.php` (内部ヘルパの付け替え)
3. 施策 3: `tests/Architecture/StrictTypesDeclarationGateTest.php`
4. 施策 4: 未宣言 32 本への宣言追加
5. 施策 5: `AGENTS.md` / `docs/template-divergence.md` D15

## 判定器と実測器の突き合わせ (実装前に PHP 8.4.24 で実測)

設計の表を実装した検体表で再実測し、**すべて設計どおり**だった。

| 検体 | 判定器 | 実測 |
|------|--------|------|
| 正準形 / 先頭コメント / 大文字 / 空白揺れ / 後続 `declare(ticks=1)` | true | true |
| コメント内の記述のみ / 文字列リテラル内のみ / 値 0 / ブロック形 / 後置 / 別指令のみ / 宣言なし / `<?php` の前に文字 / 配列リテラルのキー | false | false |
| 後続の再宣言 (値 0 / 値 1) / `01` / `0x1` / `0b1` / 複合指令 / 冗長な括弧 / 2 文目の declare / 入れ子括弧つき複合指令 | false | **true** (安全側の乖離) |

逆向きの乖離 (判定器 true / 実測 false) は **0 件**。実測器の健全性も確認した
(読み込めない源 = false、自前出力 + `exit` = 例外、閉じタグで閉じた源 = 例外)。

## テストファーストの 4 段 (実測記録)

| 段 | 操作 | 結果 |
|----|------|------|
| 1 | 施策 4 の前に gate を実行 | **赤**。未宣言 32 件を列挙し、設計の一覧と完全一致 |
| 2 | 施策 4 で 32 本に宣言を追加して再実行 | **緑** |
| 3 | `config/mail.php` の宣言を一時的に外して再実行 | **赤** (`config/mail.php` 1 件を列挙)。実施後にファイルを復元済み |
| 4 | 走査域を `app/` へ一時的に狭めて再実行 | **赤** (`Failed asserting that 760 is equal to 1400 or is greater than 1400`)。実施後に gate を復元済み |

段 1 の 32 件は `app/` 4 / `config/` 18 / `database/migrations/` 7 / `bootstrap/` 2 /
`public/` 1 で、設計の前提実測値と一致した。

## 実装中に見つけた PHP の落とし穴 (記録)

`?>` は**1 行コメント (`//`) を終端して PHP モードを抜ける**。検体の説明を
`// ?> で閉じた検体は…` と 1 行コメントで書いたところ、以降がインライン HTML として
扱われ parse error になった (`vendor/bin/pint --test` が検出)。同種の説明は
ブロックコメントで書く (ブロックコメント内の `?>` は終端しない)。該当箇所には
再発防止の注意書きを残した。

## 走査域の性質を実測で確認したこと

`TrackedPhpSourceFiles` は **git 追跡下**しか列挙しない。新規テストファイルを
`git add` する前は gate の母集団に入らず、`TrackedPhpSourceFilesTest` の
「実リポジトリに対して疎通する」が赤くなることで気付いた (設計どおりの挙動)。
gate が守る境界が commit / CI であることの実地確認になっている。

## 検証コマンドの結果

| コマンド | 結果 |
|----------|------|
| `composer test` | 4801 tests / 4799 passed / 0 failed / 2 skipped |
| `composer phpstan` | No errors (level 10、916 ファイル) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | passed |
| `pnpm test` | 136 files / 1501 tests passed |
| `pnpm build` | built |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | passed (10 files / 106 tests) |
| `composer test:browser` | chromium 25 passed / 3 skipped、webkit 25 passed / 3 skipped |

### 起動確認 (config cache の影響を外した順序)

1. `php artisan config:clear` → OK
2. `php artisan route:list` → `Showing [211] routes`
3. `php artisan config:cache` → OK / `php artisan config:clear` → OK
4. `php -l public/index.php` / `php -l bootstrap/app.php` / `php -l bootstrap/providers.php` → syntax OK
5. `public/index.php` を**実サーバで起動**して疎通: `php -S 127.0.0.1:8099 -t public public/index.php`
   に対する `GET /up` が **HTTP 200**

> 補足: 設計の施策 4 テスト計画は「`public/index.php` は `composer test:browser` でのみ通る経路」と
> 書いているが、Browser lane は in-process サーバ (テストプロセス自身の HttpKernel) で走るため
> **`public/index.php` を読み込まない**。そこで上の 5 を追加し、`public/index.php` を実際に
> front controller として起動する経路で直接確認した (`composer test:browser` も設計どおり実行して緑)。
