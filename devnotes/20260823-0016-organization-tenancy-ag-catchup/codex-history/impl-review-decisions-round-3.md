# 対応マトリクス: impl-review Round 3 (最終)

Round 3 で `APPROVED` に到達しなかったため、**指摘のうち Critical はすべて本 PR で対応した**上で、
残った限界を「保証しないもの」として docblock へ明記した。以下がその内訳である。

## [Critical] `importedOrganizationUrlBuilders()` が取り込み元の名前を見ていない
- 判断: 対応する
- 根拠: 指摘のとおり。`currentOrganizationSlug as passthrough` のような別名つき取り込みで、
  入口ではない関数まで免除の対象になっていた。
- 対応内容: 入口として認める**取り込み元の名前**を `ORGANIZATION_URL_BUILDERS`
  (`orgUrl` / `currentOrgUrl`) に限定し、specifier の「元の名前」と「別名」を分けて読むようにした。
  負例 (別名つきの別 export は免除されない / 正しい別名は免除される) を gate に足した。

## [Critical] import 検出が文字列リテラル内の偽の宣言を除外していない
- 判断: 対応する
- 根拠: 指摘のコード片で実際に免除できた。
- 対応内容: `SourceLiterals::stringSpans()` を新設し、import 宣言の**一致位置が文字列の内側なら
  無視する**ようにした (宣言自体が module 名の文字列を含むため、文字列ごと潰す方法は使えない)。
  負例を gate に足した。

## [Critical] 許可目録が「構文文脈まで識別する exact-fit」に達していない
- 判断: 対応する
- 根拠: 指摘のとおり、同じ path を同じファイルの別の構文位置へ移すと通った。
  設計は「構文文脈まで識別する安定 ID」を要求している。
- 対応内容: 検出結果に**構文文脈** (`LegacyUrlOccurrence::$context`) を持たせ、
  **許可目録のキーへ入れた** (パス + 規則 ID + 語 + 構文文脈)。語彙は限定列挙である。
  - 全文走査: `key:<名前>` (JSON / manifest の鍵) / `markdown-link` / `text`
  - ソース走査: `call:<名前>` (その名前の呼び出しの引数) / `expr`
  指摘の 2 例 (manifest の `start_url` を別の鍵へ移す / 呼び出しの引数を変数代入へ移す) が
  キーの変化として現れることを負例で固定した。
  この変更で目録は 32 件 → **36 件**になった (同じファイルでも文脈が違えば別登録になるため)。
- 残る限界 (docblock に明記): 文脈の判定は発見的規則であり、判定できない形は
  `expr` / `text` へ倒れる。**その位置どうしの移動は区別できない**。

## [Critical] class docblock の「別の旧 URL へ置き換えても通らない」が誇張
- 判断: 対応する
- 対応内容: 主張を「どの語を、**どの構文位置で**、何件許すか」まで固定する形へ更新し、
  併せて「判定できない文脈どうしの移動は区別できない」という限界を同じ docblock へ書いた。

## [Critical] `LegacyUrlOccurrence` が構文文脈を保持していない
- 判断: 対応する (上と同じ変更)

## [Warning] 5 区分のテストが「同じ path を別構文へ移す」抜け道を検査していない
- 判断: 対応する
- 対応内容: 構文文脈の負例 (`key:start_url` → `key:unrelated` / `call:get` → `expr`) と、
  キーが変わることの確認を足した。

## [Suggestion] `StorageObjectKey` の docblock が存在しない定数を参照している
- 判断: 対応する
- 対応内容: `STORAGE_KEY_PREFIX` → `STORAGE_KEY_MARKERS` へ直した (目録側の docblock も同様)。

## 別 TODO へ送る限界 (Codex が明示的に許容したもの)

いずれも利用側 gate が**保証から明示的に除いている**ため、施策 10 の主張を偽っていない。

1. script の抽出を正式な TypeScript / Svelte parser へ置き換えること
2. 実行時連結・絶対 URL・query/hash の中に書かれた旧 URL の検出
3. 自己検査用の数え方を本体の根位置判定から完全に独立させること
4. `OrganizationRelativePath` の汎用的なデータフロー解析

## 本 PR で閉じた「許可根拠そのもの」

Codex が「別 TODO へ送れない」と指摘した
`OrganizationRelativePath` の導線については、登録に**利用側のファイルと値を受ける記号**の
名指しを必須にし、両方の実在を機械検査するところまでを本 PR で入れた。
値が実際に組織 URL 組み立ての入口を通ることは、既存の
`tests/js/pages/Dashboard.test.ts` (課金 callout の CTA が組織 URL を指すこと) と
`tests/Feature/Organizations/TwoFactorEnforcementTest.php` (suffix を組織 URL に継いで要求すること)
が behavioral に固定しており、目録の理由欄からその 2 本を指している。
