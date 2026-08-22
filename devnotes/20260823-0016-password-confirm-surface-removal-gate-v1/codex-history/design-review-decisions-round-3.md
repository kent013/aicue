# 対応マトリクス: design-review Round 3

Critical 1 件・Warning 10 件。**すべて対応する**（反論・見送りは 0 件）。

## [Critical] trait 内の `self` / `static` / `parent` を「trait 名 + namespace」へ解決するのは PHP の意味論と一致しない（S3）
- 判断: **対応する**
- 根拠: 完全に正しい。trait のメンバーは利用クラスへ組み込まれるので `self` 等の意味は**利用クラス**に依存する。trait 自身の FQCN へ確定すると誤った解決済み結果になり、`self::imagesEnabled()` を trait に置いて対象クラスが `use` する形で**静かに通ってしまう**（fail-open）。
- 対応内容:
  - **class / enum 内**: `self` → 現在の宣言クラスへ解決。`static` → 現在クラスを保守的候補として扱う。`parent` → `extends` を解ければそれへ、解けなければ未解決
  - **trait 内**: `self` / `static` / `parent` は **trait 利用関係（trait-use graph）を解析しない限り未解決**とする。v1 では trait-use graph を実装しないので、**対象メソッド参照（`::imagesEnabled` / middleware 位置のクラス参照）に限り fail-closed で落とす**
  - この限界を `PhpNameResolver` と両 gate の docblock に明記する
  - 見本を追加する: trait 内の `self::imagesEnabled()`（**未解決**になること）、対象クラスがその trait を `use` する形（同じく未解決）

## [Warning] 「見本が検索語を連続して含むこと」の一律 assert が一部の正例で成立しない（S1）
- 判断: **対応する**
- 根拠: 指摘のとおり。大小違いの正例は canonical 表記を含まず、`self::imagesEnabled()` は対象 FQCN を含まず、alias / group use も参照位置に FQCN が無い。
- 対応内容: 一律の `str_contains()` をやめ、**検出経路ごとの見本前提検査**にする:
  - alias 文字列の正例 → 「alias 値の綴りをその見本が含む」
  - メソッド参照の正例 → 「メソッド名の綴り（大小を無視）をその見本が含む」
  - クラス宣言の正例 → 「クラス短名と namespace 宣言をその見本が含む」
  - `FqcnMethodReference` の正例 → 「`::` とメソッド名をその見本が含む」
  - Tier 2 の正例 → 「撤去語をそのまま含む」

## [Warning] 実バイナリ見本は編集・レビューが難しい（S1）
- 判断: **対応する**
- 対応内容: 実バイトのファイルを置くのをやめ、**hex のテキスト見本を復号して `classifyContents()` へ渡す**方式にする（`binary-with-nul.hex.txt` / `invalid-utf8.hex.txt` / `text-plain.txt`）。見本の生成・レビュー方法（hex を `hex2bin()` で復号する）を設計に明記する。

## [Warning] symlink 判定に自己検証の seam が無い（S2）
- 判断: **対応する**
- 対応内容: 純関数 **`RemovedSurfaceScanTargets::isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool`** を切り出し、`population()` と自己検証の**双方がこの関数を使う**形にする。実際の symlink 解決失敗（`realpath()` の `false`）は統合側で `unresolved` になることを併せて固定する。

## [Warning] 公開 enum の配置ファイルが変更ファイル一覧に無い（S2 / S3）
- 判断: **対応する**
- 対応内容: `ContentClassification.php` / `MiddlewareReferenceKind.php` / `MethodReferenceKind.php` を施策一覧の変更ファイルへ追加する。公開型は PSR-4 に沿った**専用ファイル**へ置く。

## [Warning] S3 末尾に「実行時層がその穴を塞ぐ」の断定が残っており S5 と矛盾（S3）
- 判断: **対応する**
- 対応内容: S3 の本文とリスクも次へ統一する。
  > 実行時層はテスト起動時に実体化した route のみを補完し、環境依存で実体化しない経路は保証しない。

## [Warning] S4 の docblock「列挙外からの復活は本テストが捕まえる」も保証過剰（S4）
- 判断: **対応する**
- 対応内容: S5 と同じ限定表現へ揃える。

## [Warning] `collectConfirmPasswordKeys…()` の再帰入力型が不明（下位配列に整数キーがあり得る）（S4）
- 判断: **対応する**
- 対応内容: 再帰引数を `@param array<array-key, mixed> $tree` / `@param array<string, mixed> $found` と注釈する。**数値キーを診断パスへ変換する規則**も明記する（数値キーは `[0]` のように角括弧で連結し、文字列キーは `.` で連結する）。

## [Warning] S5 / S6 のテスト件数表記が表と食い違う（13 本 / 12 本 vs 表 14 本）
- 判断: **対応する**
- 対応内容: **件数表記をやめ、テスト構成表を正本にする**（表を編集するたびに件数を直す運用にしない）。

## [Warning] trait 経由で対象クラスへ `imagesEnabled` が混入する場合の扱いが未定義（S6）
- 判断: **対応する**
- 対応内容: v1 では trait-use graph を扱わないことを docblock に明記し、
  - **対象クラスが trait を `use` している場合、その trait 内の `::imagesEnabled` 参照と `self/static/parent` 参照は未解決として落とす**
  - trait 宣言そのものの `imagesEnabled` は対象クラスの宣言として認識しないが、**実行時層の `method_exists()` が混入を検出する**
  という役割分担を正確に書く。

## [Warning] 正典対応表の施策番号が旧設計のまま（横断）
- 判断: **対応する**
- 対応内容: I1 を `S4（A）/ S6（B）`、I2 を `S5（A）/ S6（B）`、I4 を `S5 / S6`、I5 を `S4 / S6` へ修正する。

## [APPROVE] S7
- 変更なし。
