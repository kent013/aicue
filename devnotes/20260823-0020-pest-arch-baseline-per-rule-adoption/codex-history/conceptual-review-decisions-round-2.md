# 対応マトリクス: conceptual-review Round 2

## [Critical] 3. 許可された `ignoring` の**引数の出所**が固定されていない (件数 pin では偽装できる)

- 判断: **対応する** (指摘のとおりの穴があった)
- 根拠: `ignoring` の出現ファイルと件数だけを pin しても、`->ignoring([SomeUnregisteredClass::class])` と
  書けば件数は変わらないまま台帳を迂回できる。I3 (例外一覧は `ArchBaseline` にだけ在る) が
  機械では守られていなかった。
- 対応内容: Codex の提案 2 (`ignoring` を呼ぶ箇所を 1 箇所へ閉じる) と提案 3 (引数トークン列の期待形照合) を
  **両方**採る。7 本を手書きせず、`ArchBaseline::ruleIds()` の **`foreach` 1 本**から表明を生成する:

  ```php
  foreach (ArchBaseline::ruleIds() as $ruleId) {
      arch($ruleId.': '.ArchBaseline::titleOf($ruleId))
          ->expect(ArchBaseline::symbolsOf($ruleId))
          ->not->toBeUsed()
          ->ignoring(ArchBaseline::exceptionsOf($ruleId));
  }
  ```

  これで `ignoring` の呼び出し箇所は**リポジトリ全体で 1 つ**になり、S4 は
  「`tests/` 配下の追跡 PHP 全数で `ignoring` は**ちょうど 1 件**、その 1 件の直後のトークン列が
  `( ArchBaseline :: exceptionsOf ( $ruleId ) )` と**完全一致**する」を固定できる。
  対称性のため `expect` の引数も `ArchBaseline::symbolsOf($ruleId)` であることを同じ方式で照合する
  (語彙の直書きも同時に塞げる)。
  - `arch()` は `TestCall` を返す通常のテスト宣言関数なので (vendor 実装で確認:
    `pest-plugin-arch/src/Autoload.php`)、`foreach` の中から呼んでよい。テスト名は規則 ID を含むので一意になる
    (規則 ID の一意性は S3 が固定する)。
  - 「呼んで戻り値を捨てる偽装」は成立しない — `ignoring` は 1 箇所しか無く、その 1 箇所が
    表明の連鎖の末端だからである。
- 波及: この照合は Codex の助言どおり **`ArchSurfaceScanner` の責務拡張**として収める
  (7 本目の支援クラスは作らない)。走査器は「識別子の出現位置」だけでなく
  「出現位置から始まる**トークン列の切り出し**」を返す純関数にする。

## [Warning] 2. 「禁止事項 3」の引用が誤り (AGENTS.md の禁止事項 3 は dev DB への破壊操作)

- 判断: **対応する**
- 根拠: 事実誤り。AGENTS.md §禁止事項 3 は「dev DB への破壊操作」である。
  既存テストを消さない根拠は**裁定 AG-167** と **`app-design` スキルの禁止事項 3 (既存テストの削除・上書き)** の 2 つ。
- 対応内容: 引用を「裁定 AG-167 / `app-design` スキルの禁止事項 3 (既存テストの削除・上書き)」へ訂正した。

## [Warning] 4. 「2026-08-23 時点 HEAD」は未来日

- 判断: **対応する**
- 根拠: レビュー時点は 2026-08-22。JST では 2026-08-23 00:20 だが、タイムゾーンを書かずに未来日に見える表記は観測記録として不適切。
- 対応内容: 「**2026-08-22 (JST 2026-08-23 00:20) の HEAD `2dc4e2ec`**」へ訂正し、タイムゾーンとコミットを併記した。

## [Warning] 5. S2 の名前空間解決 — 素の `sha1()` を使用証明に数えてよいか

- 判断: **対応する** (ただし指摘の 3 案のうち 3 案目を、vendor 実装の実読を根拠に採る)
- 根拠 (vendor 実読):
  - Pest arch の使用判定は `PHPUnit\Architecture\Asserts\Dependencies\Elements\ObjectUses::getByName()` が
    担い、その実装は **`substr($use, -strlen($name)) === $name` の接尾辞一致**である
    (`vendor/ta-tikoma/phpunit-architecture-test/src/Asserts/Dependencies/Elements/ObjectUses.php`)。
  - つまり Pest 側は、素の `sha1()` が `sha1` と記録されようと `App\Foo\sha1` と記録されようと
    **どちらも `sha1` の使用として検出する**。名前空間に同名関数があっても Pest の検出集合からは外れない。
  - したがって「素の `sha1(` を数える」ことは **Pest の検出集合の部分集合**であり、
    保証外の形が使用証明に混ざるわけではない。
  - 逆に Pest の接尾辞一致は `mysha1()` のような**別関数まで拾う**。S2 がこれを真似ると
    数えすぎ = 腐った登録の温存になるので、S2 は**綴りのトークン完全一致だけ**を数える
    (= Pest の検出集合の真部分集合。数え漏らしは赤 = 安全)。
- 対応内容:
  - S2 の契約を「**Pest が対象シンボルとして検出する使用のうち、綴りがトークン完全一致する
    素のグローバル関数呼び出しだけを数える** (Pest の検出集合の部分集合)」と定義し直し、
    上の vendor 根拠を走査器の docblock に書く。
  - 残る不確実性 (vendor が接尾辞一致をやめた場合、登録が保守的に余る) は
    **I2 が blast radius を 1 シンボルに抑えているので穴にならない**ことを明記した
    (余った例外が隠せるのは、その 1 シンボルの、その 1 クラスでの使用だけである)。
  - 負例に `mysha1` (接頭辞つき) を追加し、「Pest は拾うが S2 は数えない」形として固定する。

## [Warning] 7. `Assert` だけでは静的な配列 shape は保たれない

- 判断: **対応する**
- 根拠: 正しい。`token_get_all()` の戻り値と vendor ソースからの抽出値は PHPStan 上で型が広がりやすい。
- 対応内容: 受入条件へ次を追加した。
  - 3 走査器の公開メソッドは**戻り値を正規化してから返す** —
    `GlobalFunctionCallScanner` は `list<string>` (検出した綴り) を、
    `ArchSurfaceScanner` は小さな値オブジェクト `ArchSurfaceSite` の `list<>` を、
    `VendorArchPresetReader` は `list<string>` を返す。
  - `token_get_all()` の生の戻り値は**走査器の外へ出さない** (既存の `PhpTokenScan::normalize()` の
    正規化形で閉じる)。

## [Suggestion] 6. ファイル数の最小性

- 判断: **助言を受け入れる (支援クラスを増やさない)**
- 対応内容: Critical の引数由来検査は `ArchSurfaceScanner` の責務拡張として収め、
  成果物は 6 ファイル (+ 乖離台帳 2 ファイル) のまま据え置いた。
