# 実装メモ: PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (T218 / AG-099 前半)

詳細設計 `detailed-design.md` の施策 A〜F を 1 つの変更として着地させた記録。
**故障注入の実測**・**旧検査からの引き継ぎの対応表**・**設計から変えた点**を残す。

## 1. 故障注入の実測 (感度の裏取り)

再現手順は `fault-injection.sh` (一時スクリプト。恒久 `scripts/` へは昇格させない)。
注入ごとに対象ファイルを退避 → 壊す → **置換が実際に起きたことを確認** →
対象の vitest を走らせて終了コードを見る → 退避から戻す、を機械的に回している。
**22 件すべてで赤**になり、緑のまま素通りする注入は 1 件も無かった
(後半 6 件は Codex 実装レビュー Round 1 で足した分岐の分)。

| 注入 | 走らせたテスト | 結果 |
|---|---|---|
| TS 側: VideoManualStatus から `"published"` を落とす | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| PHP 側: VideoManualStatus へ case を 1 つ足す | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| TS 側: MemberRoleState から `"unassigned"` を落とす | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| PHP 側: MemberRoleState へ case を 1 つ足す | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| TS 側: PlanCode から `"enterprise"` を落とす | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| PHP 側: PlanCode へ case を 1 つ足す | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| 目録: 件数 pin を 1 ずらす | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| 目録: 行を 1 つ消す | enum-ts-sync.test.ts | 赤 (失敗 1 件。件数 pin が拾う) |
| 目録: `app/` の外のパスを登録する | enum-ts-sync.test.ts | 赤 (beforeAll で停止 = 体裁検査が program 構築より先に効いている) |
| 抽出器: TypeScript の `enum` を弾く分岐を外す | enum-ts-sync-extractor.test.ts | 赤 (失敗 2 件 = T19 / T20) |
| 抽出器: 同名の型別名が 2 件ある検査を外す | enum-ts-sync-extractor.test.ts | 赤 (失敗 1 件 = T8) |
| 抽出器: 起点を縮めた program でも全体 program を使う | enum-ts-sync-extractor.test.ts | 赤 (失敗 1 件 = T25b) |
| 抽出器: 逆斜線の偶奇を 1 文字送りにする | enum-ts-sync-extractor.test.ts | 赤 (失敗 3 件 = P31 / P32 / P33) |
| 抽出器: 行注釈の中の閉じタグを見逃す | enum-ts-sync-extractor.test.ts | 赤 (失敗 2 件 = P24 / P25) |
| 抽出器: case の深さの条件を外す | enum-ts-sync-extractor.test.ts | 赤 (失敗 1 件 = P3 の switch を拾う) |
| 抽出器: backing の値の重複の検査を外す | enum-ts-sync-extractor.test.ts | 赤 (失敗 1 件 = P36) |
| 抽出器: ファイル名の語幹の照合を外す | enum-ts-sync-extractor.test.ts | 赤 (失敗 1 件 = P34) |
| 抽出器: case の値に改行を許す (レビュー Round 1 の Critical の回帰) | enum-ts-sync-extractor.test.ts | 赤 (失敗 2 件 = P39 / P40) |
| 行列: 起点を縮めた program の行を消す | enum-ts-sync-extractor.test.ts | 赤 (失敗 1 件 = 件数 pin) |
| 目録の体裁: 配下の判定から区切り文字を落とす | enum-ts-sync.test.ts | 赤 (失敗 1 件 = 兄弟ディレクトリ `app-legacy/`) |
| 目録の体裁: symlink の解決先の検査を外す | enum-ts-sync.test.ts | 赤 (失敗 1 件) |
| 目録の体裁: symlink 別名の二重登録の検査を外す | enum-ts-sync.test.ts | 赤 (失敗 1 件) |

**この表が主張しないこと**: 注入したのは既知の分岐だけである。
「どんな改変でも赤くなる」ことは実測していない。

## 2. 速さの実測 (起点を縮めない判断の裏取り)

`tsconfig.json` が含む TS 全体で program を作る所要時間 (同一プロセス内で 3 回):

| 回 | 所要 |
|---|---|
| 1 回目 | 1480 ms |
| 2 回目 | 1108 ms |
| 3 回目 | 1059 ms |

gate 2 ファイル (負例の追加後は 120 件) 全体で 3.4 秒 (`pnpm test tests/js/architecture/enum-ts-sync`)。
**起点を縮める必要は無かった** (縮めれば偽陰性になることは T25a/T25b が固定している)。

## 3. 旧検査からの引き継ぎの対応表

削除したのは `tests/Support/TsUnionValues.php` と PHP 側の同期検査 4 本
(test 宣言 **18 件** = 値集合の比較 14 件 + 抽出不能を確かめる自己検査 4 件)。

| 旧 (PHP レーン) | 新 (frontend レーン) |
|---|---|
| `ManualEnumTsSyncInvariantTest` の値集合比較 11 件 | 目録の `manual.ts` 10 行 + `capture.ts::MaterialType` 1 行 |
| `NotificationTypeTsSyncInvariantTest` の値集合比較 1 件 | 目録の `notification.ts` 行 |
| `OnboardingBillingStateTsSyncInvariantTest` の値集合比較 1 件 | 目録の `billing.ts::BillingStateValue` 行 |
| `AccountDeletionBlockerActionTsSyncInvariantTest` の値集合比較 1 件 | 目録の `account.ts` 行 |
| 各テストの「抽出できないと落ちる」自己検査 4 件 | 負例行列の T7 (宣言が無い) ほか TS 27 件 / PHP 40 件 |
| 配列比較なので backing の値の重複を検出できた | `readPhpEnumValuesFromText` が**明示的に**例外にする (P36) |

**引き継がないもの**: 旧テストは PHP のクラスを実際に読み込んで `::cases()` を呼んでいた。
新 gate は本文を読むので、PHP の構文の妥当性・名前空間・オートロード・完全修飾名の正しさは
見ていない (それらは `composer test` と PHPStan の担当)。
TS 側のソース上の重複 (`"a" | "a"`) は値集合の意味では区別できないので保証から外した。

## 4. 母集団の拡張 (14 組 → 27 組)

`survey.md` の #15〜#27 を登録した。**`MemberRoleState` (#17) は旧抽出器では読めなかった組**である
(`ConsoleRole | "owner" | "unassigned"` の別名参照を正規表現が解決できなかった)。
型情報にしたから登録できた実例として記録する。
今後この 13 組の PHP 列挙を変えるときは TS 側の追随が**必須**になる (意図した効果)。

## 4b. Codex 実装レビュー

`impl-review-round-1.md` (CHANGES_REQUESTED) → `impl-review-round-2.md` (**APPROVED**)。
判断の記録は `codex-history/impl-review-decisions-round-1.md`。Round 1 の指摘は 6 件で、
**すべて「対応する」**で処理した (反論・見送りは 0 件)。主なものは次の 3 つ:

1. **[Critical] case の値が改行を含んでも受理していた**。`[^'\\]*` は CR/LF に一致するため、
   `case A = 'a<改行>b';` を 1 件の宣言として受理し、TS 側を `"a\nb"` にすれば gate 全体が
   緑になる経路が開いていた (文書と `AGENTS.md` の「1 行に一致」の主張が実装より強かった)。
   宣言の範囲に CR/LF があれば照合の前に落とす分岐と、値の文字集合からの除外の二重で塞ぎ、
   負例 P39 / P40 を足した (PHP 行列 38 → 40 件)。
2. **[Warning] TS 行列の件数 pin が実行対象を固定していなかった**。T25b を独立した `it` で
   持っていたため、その `it` を消しても `TS_CASES.length + 1` は 27 のままだった。
   program の別を**行のデータ**にして 27 行を 1 つの `it.each` へ載せ、
   「起点を縮めた program で判定する行がちょうど 1 件ある」ことも併せて固定した。
3. **[Warning] 目録の体裁検査の負のコントロールが境界の分岐を押さえていなかった**。
   走査根を引数化し (**負のコントロール専用**であることを docblock に明記)、
   一時ディレクトリに見本の木を作って兄弟ディレクトリ (`app-legacy/`)・symlink による脱出・
   symlink 別名の二重登録・ディレクトリの登録を負例にした。

## 5. 設計から変えた点

- **D 番号を D27 → D29 へ、登録エントリ数を 26 → 28 へ**改めた。詳細設計を書いた時点では
  D26 までが使用済みだったが、main 側で T220 が D27 を、T221 が D28 を取ったため
  (`docs/template-divergence.md` の番号は再利用しない)。`AGENTS.md` / `docs/architecture.md` /
  gate の docblock からの参照も同じ変更で D29 へ直した。
- 目録の `PlanCode` は詳細設計のテスト計画で `Billing\PlanCode` と書いていたが、
  実在するのは `app/Enums/PlanCode.php` である (代表 3 組の実測はこちらで行った)。
- **PHP の負例行列は 38 件 → 40 件**になった (レビューで見つかった値の中の改行の負例 2 件)。
- 詳細設計の「後続 TODO」を **T225** として `docs/TODO.md` へ起票した
  (D29 の再判定の条件がこの TODO に結び付いている)。

## 6. 残した残骸と、その理由

`docs/TODO-closed.md` の T197 の記録に `ManualEnumTsSyncInvariantTest` の名前が残っている。
これは**その時点で何をしたかの歴史の記録**であり、`devnotes/` と同じ扱いで直さない
(直すと当時の作業内容の記述が事実と食い違う)。現在の案内としての参照は 0 件である。

## 7. 検証コマンドの結果 (main を取り込んだ後の worktree で実行)

| コマンド | 結果 |
|---|---|
| `composer test` | 5777 tests / 5775 passed / 2 skipped / 0 failed / 25409 assertions |
| `composer phpstan` (level 10) | No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | passed |
| `pnpm typecheck` | passed |
| `pnpm test` | 165 files / 2224 tests passed |
| `pnpm build` | 成功 |
| `pnpm typecheck:packages` / `pnpm build:packages` | 成功 |
| `pnpm test:packages` | 10 files / 106 tests passed |

main の取り込みで直したのは 3 箇所 —
`docs/TODO.md` (T224 と T225 の両方を残す) /
`docs/template-divergence.md` (登録を **D29** へ、件数を **28 件** へ) /
`TemplateDivergenceLedgerFormatTest` の件数の定数 (28)。
D 番号の参照 (`AGENTS.md` / `docs/architecture.md` / gate の docblock) も同じ変更で D29 へ直した。
