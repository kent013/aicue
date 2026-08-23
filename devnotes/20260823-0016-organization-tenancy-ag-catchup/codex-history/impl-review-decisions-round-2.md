# 対応マトリクス: impl-review Round 2

## [Critical] `withoutRouteDefinitionUris()` が `->name()` / `->as()` も外し、撤去 route 名を見逃す
- 判断: 対応する
- 根拠: 指摘のとおり。`->name('organizations.switch')` のリテラルが抽出結果から消え、
  撤去 route 名の台帳が `routes/` の中で丸ごと効かなくなっていた。docblock とも矛盾していた。
- 対応内容: 除外集合から `name` / `as` / `domain` を外し、**URI を受ける引数だけ**にした。
  負例 (`->name(removedRouteName)` が検出されること) を gate に足した。

## [Critical] canonical builder の import 判定が `str_contains()` だけで、コメントでも前提を満たす
- 判断: 対応する
- 根拠: 指摘のコード片で実際に免除できた。
- 対応内容: `importedOrganizationUrlBuilders()` を新設し、**コメントを潰した写しの上で
  `import { … } from "@/lib/org-url"` を構文で読み、取り込んだローカル名 (別名つきは別名側) を
  解決**するようにした。呼び出しの照合はその名前だけを使う。
  指摘のコード片 (コメントに module 名 + 同名関数の自前定義) が検出されることを実測で確認した。

## [Warning] `notCurrentOrgUrl()` は大文字 C のため接頭辞の負例になっていない
- 判断: 対応する
- 対応内容: fixture を `notcurrentOrgUrl()` と `myorgUrl()` の 2 形へ直した
  (lookbehind が無ければ実際に一致する形)。

## [Critical] 区分の前提が「許可対象となった個々の出現」と結び付いていない
- 判断: 対応する
- 根拠: 指摘のとおり、同じ根・同じ件数で別 path へ置換できた。
- 対応内容: 検出結果に**根から終端までの path 全体** (`LegacyUrlOccurrence::$path`) を持たせ、
  区分の前提を**出現ごとの path** で判定するようにした。
  - `CanonicalCaptureEntry`: すべての出現の path が route 表の入口の URI と**完全一致**すること
    (`/app` → `/app/projects/1` の置換は path が変わって落ちる)
  - `FilesystemPath`: すべての出現の path が**実在するディレクトリ**であること
    (この検査で `correlate.py` の docstring が実在しない例示パスだったことが判明したので、
     例示を相対の形へ書き直して登録を 2 件 → 1 件へ直した)
  - `StorageObjectKey` / `AbsenceAssertion`: ファイル単位の印のまま (path で表せる性質ではない)
  - `OrganizationRelativePath`: 利用側に加えて**値を受ける記号 (`symbol`)** の名指しを必須にした
- 残る限界: 記号の一致は**データフローの証明ではない** (同じファイルにあることまで)。
  値が本当に builder へ渡ることは利用側の component テスト / Feature テストが担う、と
  目録の docblock に明記した。

## [Warning] 5 区分の前提に不適合な合成 entry の負例が無い
- 判断: 対応する
- 対応内容: 5 区分すべてについて**成立・不成立の両方向**を合成 entry で固定した
  (出現 0 件の登録が拒否されることも含む)。

## [Critical] handler gate が中間 parameter の欠落を検出できない
- 判断: 対応する
- 根拠: 指摘のとおり。部分列一致では `['organization', 'manual']` が通ってしまう。
- 対応内容: 判定を **route parameter の並びの先頭からの連続一致 (prefix)** に変えた。
  負例に「中間を飛ばした形」を足し、正例・欠落・飛ばし・順序違いの 4 形を固定した。

## [Warning] 裸の `/app` が検出される負例が無い
- 判断: 対応する
- 対応内容: `legacy-paths.md` に裸の形を足した (件数 pin も更新)。

## [Warning] `routes/` の負例が `redirect()` だけ
- 判断: 対応する
- 対応内容: `->name(removedRouteName)` の負例を足した (上の Critical と対)。

## [Warning] 自己検査の数え方が本体の `matchesIn()` から独立していない
- 判断: 対応する (説明を狭める)
- 根拠: 指摘のとおり。独立しているのは抽出方式だけである。
- 対応内容: docblock を「**独立しているのは抽出方式だけ**であり、根の位置と境界の判定は
  本体を共有しているのでその欠陥からは独立していない」と書き直し、
  位置判定の検出力は種別ごとの正例・負例が担うことを明記した。

## [Warning] symlink / NUL / 不正 UTF-8 の負例見送りへの異論
- 判断: 対応する (見送りを撤回)
- 根拠: 指摘のとおり、追跡下 fixture は不要だった (純関数と一時ディレクトリで足りる)。
- 対応内容: 内容の判定を `contentsUnresolvedReason()` へ純関数として切り出し、
  合成文字列で両方向を固定した。symlink は一時ディレクトリに壊れた symlink /
  リポジトリ外へ向く symlink / リポジトリ内へ向く symlink / 通常ファイルの 4 形を作って固定した。
