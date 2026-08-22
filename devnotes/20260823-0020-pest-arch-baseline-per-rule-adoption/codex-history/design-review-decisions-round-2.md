# 対応マトリクス: design-review Round 2

## Round 2 の判定

- 全体判定: **CHANGES_REQUESTED**
- [Critical] 4 件 / [Warning] 8 件 / [Suggestion] 1 件
- 施策別: 2 (`GlobalFunctionCallScanner`) / 4 (`VendorArchPresetReader`) / 7 (D40) / 8 (概念設計訂正) が **APPROVE**、
  1 / 3 / 5 / 6 が REQUEST_CHANGES
- **Round 1 の [Critical] C2 への対応方針 (名前解決を捨てて末尾セグメント一致にする) は承認された**:
  「末尾セグメント方式なら、Round 1 で挙げた以下は名前解決なしで扱えます … 
  『対象関数を import しながら、元の対象名が構文上まったく現れない』通常の `use function` 構文はありません。
  したがって基本方針は承認できます」
- **V5 の非対称 (S2 は大小区別 / S4 は無視) も承認された**:
  「S2 と S4 は目的が異なるため、大小の扱いを揃える方がかえって契約を曖昧にします」
- **S1 第 3 条 (実効対象集合が非空) も承認された**:
  「現在の Pest 版ではコア構文が存在するため、xdebug や polyfill の有無で真偽が揺れません」

**すべて対応した。反論・見送りは 0 件である。**

---

## [Critical] R2-1. `ignoring` / `toBeUsed` を `functionNameSites()` で 1 件に固定すると gate が初日から赤くなる

- 判断: **対応する**
- 根拠: **完全に正しい。自分が Round 1 の対応で入れた退行である。**
  Round 1 の [Critical] C1 (`arch` が完全修飾名・alias で迂回できる) を潰すとき、
  `arch` / `ignoring` / `toBeUsed` の 3 つを**まとめて** `functionNameSites()` へ移した。
  しかし実際のチェーンは `->toBeUsed()` / `->ignoring(...)` であり、
  `functionNameSites()` は「直前が `->` / `?->` / `::` ならメンバ名なので拾わない」という
  契約を自分で持っている。つまり**この 2 つは必ず 0 件になる**。
  「1 件であること」を要求している S4 は**新設した瞬間に赤**である。
  設計書の中だけで閉じた矛盾で、実装前に見つかったのは幸運だった。
- 対応内容: 走査を目的別に分けた。
  - `ArchBaseline::SINGLE_FUNCTION_NAMES = ['arch']` → `functionNameSites()` で
    `call` 1 件 / `import` 0 件
  - `ArchBaseline::SINGLE_MEMBER_NAMES = ['ignoring', 'toBeUsed']` → `identifierSites()` で各 1 件
  - どちらも `CHAIN_HOST_FILE` にあること
  - **メンバ名を動的にして綴りを回避する形は第 4 条 (動的メンバの exact-fit) が塞ぐ**ので、
    分けても穴は開かない (この理由を条の本文へ書いた)
- 併せて Codex の [Suggestion] (定数を 2 つに割る) も同時に満たしている。

## [Critical] R2-2. `functionNameSites()` の戻り値に `index` が無く、チェーン照合が実装できない

- 判断: **対応する**
- 根拠: 正しい。S4 第 3 条は「`arch` の唯一の呼び出し位置から `statementTokens()` を呼ぶ」
  設計なのに、戻り値は `line` しか持っていなかった。
  同じ行に複数の呼び出しがあれば行番号では一意にならない。
- 対応内容: `call` の shape を
  `array{status: 'call', name: string, line: int, index: int}` にした。
  S4 第 3 条を「第 2 条で得た `call` の `index` から切り出す」と書き換え、
  施策 6 に **同じ行に複数の名前呼び出しがあるソースで一意に切り出せる**負例 (No.26d) を足した。

## [Critical] R2-3. S3 第 7 条の母集団を PSR-4 パスから推測するのは不健全

- 判断: **対応する**
- 根拠: 正しい。パスからの推測は
  「1 ファイルに複数クラス / ファイル名とクラス名の不一致 / namespace 宣言が期待パスと違う /
  条件付き宣言」を取りこぼす。
- 対応内容: Codex の第一案どおり **Pest 自身が構築するオブジェクト名集合をそのまま使う**形にした。

  ```php
  foreach (\Pest\Arch\Support\Composer::userNamespaces() as $namespace) {
      foreach (\Pest\Arch\Repositories\ObjectsRepository::getInstance()->allByNamespace($namespace) as $object) {
          $names[] = $object->name;
      }
  }
  ```

  **母集団と判定対象が同一になるので定義上ずれない**。`ObjectsRepository` は
  prefix 単位でキャッシュするため、arch 表明が既に読んだ結果を再利用する (パース費用は増えない)。
  自前の走査器を新設する案は採らない — 専用走査器・未解決分岐・正負例・母集団 pin が
  丸ごと 1 セット増え、**Pest の集合を借りれば定義上ずれない**ものを再実装することになる
  (思考原則 2)。
- **vendor 再読で分かった追加事実 (V6 として設計へ追記)**:
  `ObjectDescriptionBase::make()` は `findFirst()` で **1 ファイルにつき最初の 1 オブジェクト**しか取らない。
  したがって Codex が挙げた懸念のうち
  「既存例外クラスのファイル内に `FakeObjectStoreDouble` が追加された場合、
  Pest は前方一致で除外し得る」は**成立しない** — 2 つ目のクラスは
  Pest のオブジェクト集合に**そもそも入らない**ので、除外の対象にもならない。
  これは同時に「**1 ファイルの 2 つ目以降のクラスの禁止語彙使用は Pest arch に見えない**」
  という保証範囲の限界でもあるので、gate の docblock の「保証しないもの」へ第 8 項として追加した。

## [Critical] R2-4. `ignoring` / `toBeUsed` が `functionNameSites()` で 0 件になることのテストが無い

- 判断: **対応する**
- 対応内容: 施策 6 に 2 本を対で追加した。
  - No.26b: `functionNameSites()` が `ignoring` / `toBeUsed` を **0 件**で返す (`->toBeUsed()` の形)
  - No.26c: 同じソースで `identifierSites()` が**各 1 件**取れる
  対で置くことで「走査の使い分け」そのものが固定され、R2-1 の退行が再発しても落ちる。

---

## [Warning] R2-5. 「`ReflectionFunction::getName()` は常に小文字を返す」は一般には成立しない

- 判断: **対応する**
- 根拠: 正しい。ユーザー定義関数では**宣言時の綴り**が返る。
- 対応内容: 主張を「vendor preset が対象とする現行の組み込み関数・ヘルパでは
  正規の小文字名が返り、preset 語彙も小文字である」へ狭めた (3 か所: V2 / V5 / `ArchBaseline` の docblock)。
  **S3 の小文字制約は維持する** — Codex の助言どおり「vendor 集合との一致を守る」観点で正当である。

## [Warning] R2-6. `use function` の「対象名の綴りが現れる」が部分文字列一致に読める

- 判断: **対応する**
- 根拠: 正しい。そのまま読むと共通規約 (e) に抵触する。
- 対応内容: 契約を
  「**`use` 文に現れる名前トークンを `\` で割った各セグメント**のいずれかが、
  対象名と**大小無視の完全一致**をするもの」へ書き直し、
  **部分文字列一致・正規表現の語境界に頼らない**ことを明記した。
  施策 6 に取り込み側の 3 形 (No.25b) を追加した —
  `use function A\mycall_user_func;` / `A\not_call_user_func` / `A\call_user_func_x` を
  **拾わない**こと。

## [Warning] R2-7. `unresolved` は到達不能で共通規約 (d) に反する

- 判断: **対応する** (戻り値から削除)
- 根拠: 正しい。挙げていた 2 形 (group use の入れ子 2 段 / `;` 無しの `use` 文) は
  どちらも `TOKEN_PARSE` が先に `ParseError` にする。
  **有効な PHP でありながら走査不能になる構文を示せない**以上、
  収集しても誰も到達しない結果型になる。
- 対応内容: 戻り値を `call` | `import` の 2 種にした。
  **fail-closed は 2 つで担保する** — (1) トークン化できない入力は `RuntimeException`、
  (2) 判定が**拾いすぎる方向にしか倒れない** (名前空間を解決しないので `A\B\call_user_func()` も拾う)。
- **概念設計 (APPROVED) との関係**: 概念設計は「未解決は判別できる形で返して失敗させる」と
  定めていたが、これは***名前解決を行う走査器*を前提にした条件**であった。
  Round 1 の C2 対応で名前解決の段そのものを撤去したため、
  「解決できなかった状態」が存在しなくなった = 条件は前提ごと消えて満たされている。
  この経緯を `functionNameSites()` の docblock に明記した (読者が概念設計と突き合わせたとき
  「要求が落ちている」と誤読しないため)。

## [Warning] R2-8. `realpath()` の配下判定を素の文字列接頭辞にしてはいけない

- 判断: **対応する**
- 根拠: 正しい。`/app/Foo` と `/app/Foobar` が誤一致する。
- 対応内容: **`realpath($root).DIRECTORY_SEPARATOR` を接頭辞にした境界付き比較**にした。
  併せて根の取得元を Composer の生の `getPrefixesPsr4()` ではなく
  Pest と同じ `Composer::allNamespacesWithDirectories()` に揃えた (vendor 配下の除外も同じになる)。

## [Warning] R2-9. S4 第 2 条を施策 3 の修正に合わせて分離せよ

- 判断: **対応する** (R2-1 と同じ対応で満たしている)
- 対応内容: 第 2 条 (`arch` = 関数検査) と第 2b 条 (`ignoring` / `toBeUsed` = 識別子検査) に割り、
  第 3 条を「第 2 条で得た `index` から照合」に書き換えた。

## [Warning] R2-10 / R2-11 / R2-12. 施策 6 のテスト追加

- 判断: **すべて対応する**
- 対応内容:
  - `call.index` から文を切り出せるテスト (同じ行に複数呼び出し) → No.26d
  - `use function` 側の 3 形 → No.25b
  - `unresolved` の負例 → **削除**し、構文不正は No.7 の `RuntimeException` に統一
  - S3 第 7 条の母集団側の空振り検査 → No.34 (Pest のオブジェクト名集合が 500 件以上 + 代表クラス 4 件)
  - S3 第 7 条の純粋述語の正負例 → No.31 / 32 / 33

---

## オーバーエンジニアリング評価への対応

Codex が「削れる」とした 2 点はどちらも**削った**:

| 指摘 | 対応 |
|---|---|
| 到達可能な未解決構文が示せない `unresolved` 戻り値 | **戻り値から撤去**。例外へ統一 (R2-7) |
| PSR-4 パスからクラス名を推測する独自母集団生成 | **Pest 自身の解析結果を再利用**する形へ差し替え (R2-3) |

結果として、Round 1 開始時点から**走査器の機構が 3 つ消えている** —
取り込み対応表 / 名前空間の相対解決 / 未解決の結果型。
新設ファイルは 6 本のままで、**中身は単純になった**。
