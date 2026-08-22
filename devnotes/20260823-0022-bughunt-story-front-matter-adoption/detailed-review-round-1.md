# レビュー結果

検証仮説は「前付けを唯一の正本へ移せているなら、採用した全不変条件に担い手があり、生成器・照合器の間で欄と多重度が失われず、契約違反は正常終了しない」です。

この基準では、方向性は妥当ですが、実装前に直すべき論理矛盾と PHP エラーがあります。

| 施策 | 判定 |
|---|---|
| 1. 書式の正本 | REQUEST_CHANGES |
| 2. 7 枚のカード移行 | APPROVE |
| 3. 前付け読み取り器 | APPROVE |
| 4. 書式契約の自己テスト | REQUEST_CHANGES |
| 5. `composer test` 配線 | REQUEST_CHANGES |
| 6. 注釈から `story` 撤去 | APPROVE |
| 7. 生成器の入力切替 | REQUEST_CHANGES |
| 8. 照合器の複数値対応 | REQUEST_CHANGES |
| 9. 目録再生成 | APPROVE |
| 10. 乖離台帳更新 | REQUEST_CHANGES |
| 11. 移行検算 | REQUEST_CHANGES |

## 施策 1: REQUEST_CHANGES

[Warning] 「正典との差は3点だけ」とありますが、D6の「`not_applicable` を実走対象から外す契約を採らない」も明確な差です。

修正案: 差分表に4行目としてD6を追加するか、ステップ表との差に包含するなら、その包含関係を明記してください。

[Warning] 全数対応表は「10群47項目」ではなく、記載された行を数えると58項目です。

修正案: 件数を58へ訂正し、A1〜J3の識別子一覧をテスト側の定数でも点呼してください。単なる表記ミスでも「全数」の信頼性に直結します。

## 施策 2: APPROVE

前付けへの移行、旧メタ節の撤去、S7だけがS3へ依存する設計は整合しています。

[Suggestion] 「`## 手順` 以降を1文字も変えない」は、人の差分確認だけでなく、移行スクリプトで手順節の移行前後ハッシュを記録すると確実です。

## 施策 3: APPROVE

全Markdownを先に候補として集め、命名違反を走査対象から消さない設計はfail-closedです。

[Suggestion] 正規表現の判定は `re.match()` ではなく `fullmatch()` に統一してください。Pythonの`$`は末尾改行の直前にも一致するため、「厳密一致」と完全には同義ではありません。

## 施策 4: REQUEST_CHANGES

[Critical] AC-06の骨子はS8以降を追加できません。

現在のコードは全カードを `FAMILY_SURFACE_PIN` の7件と完全一致させるため、正規のS8を追加しても失敗します。これはD7と矛盾します。

修正案:

```python
pinned_ids = {card_id for card_id, _ in FAMILY_SURFACE_PIN}
actual = tuple(
    sorted(
        (
            str(card.front_matter["id"]),
            str(card.front_matter["surface"]),
        )
        for card in self.cards
        if card.front_matter["id"] in pinned_ids
    )
)
self.assertEqual(FAMILY_SURFACE_PIN, actual)
```

S8以降の一意性・連番・表Bとの一致はAC-03/05へ任せてください。

[Warning] J2とJ3を「施策4が検査」と分類していますが、AC-01〜14に次の検査がありません。

- `## 目的` が存在する
- `## 逸脱アイデア (--deviate 時)` が存在する

修正案: 本文構造専用の主題を追加し、欠落・重複・表記揺れの負例を置いてください。

[Warning] G6を「採用」としていますが、H番号の意味をカード内で再定義していないことを検査する担い手がありません。

修正案: 機械検査できる禁止形を正典から定義して検査するか、機械保証しない文書規約として対応表を修正してください。

[Warning] AC-14は「宣言済み主題にテストがあること」しか確認できず、J2/J3のように主題一覧から漏れた不変条件は検出できません。

修正案: 採用した不変条件IDの全リストと、各IDからテスト主題への対応表を定数化し、未割当IDがゼロであることを確認してください。

[Warning] 実施順序が読み取り器3→テスト4となっており、テストファースト規約と逆です。

修正案: ACの負例を先に置いて失敗を確認し、その後に読み取り器を実装する順序へ書き換えてください。

## 施策 5: REQUEST_CHANGES

[Critical] 提示コードはPHP/PHPStanの双方で通りません。

`STORY_FRONT_MATTER_MIN_TESTS` と `STORY_FRONT_MATTER_CORE_NEGATIVES` はクラス定数として置く設計なのに、コードではグローバル定数として参照しています。実行時には `Undefined constant` になります。

修正案:

```php
use Tests\Support\Bughunt\StoryFrontMatterPins;

StoryFrontMatterPins::MIN_TESTS;
StoryFrontMatterPins::CORE_NEGATIVES;
```

定数クラス側も以下を明示してください。

- 名前空間
- `declare(strict_types=1);`
- `final class`
- `public const int`
- 文字列リスト定数の型またはPHPDoc

[Warning] 4本目のテストはPythonプロセスの終了コードを確認していません。別テストが終了コードを確認していても、実行は別プロセスなので同一結果とは限りません。

修正案:

```php
[$code, $out] = bstRunUnittest(['test_story_front_matter']);
expect($code)->toBe(0, $out);
```

その後に名前と `... ok` を検査してください。

## 施策 6: APPROVE

`story` を未知項目へ戻し、旧正本の並走を許さない設計は妥当です。同一コミットで施策2・7・9まで完了させる条件も適切です。

## 施策 7: REQUEST_CHANGES

[Critical] `kubun`の対象判定が全数対応表と一致していません。

I1/I4では対象外を `kubun = 外` のみとしていますが、擬似コードは `KUBUN_NEEDS_REASON = ("外", "終")` の両方を対象外扱いしています。そのため、`終` routeは未割当でも通り、カードに載せると「対象外」と誤判定されます。

修正案はどちらかです。

- I1/I4を採用するなら、除外判定を `kubun == KUBUN_OUT_OF_SCOPE` に限定する。
- `終`も割当対象外にする現行意味を維持するなら、I1/I4を「差」に変更し、D20とREADMEへ保証境界を記載する。

[Critical] `fact in facts.screens` は、線形探索であること自体よりも欄判定に使っていることが問題です。

複合method routeが両tupleに存在すると、operations側を走査中でもscreens側が選ばれ、operationsの未割当を検出できません。また、欄違い判定を先にしているため、両集合に存在するrouteを欄違いと誤表示します。

修正案: 欄ごとに明示的にループしてください。

```python
for label, route_facts, pool in (
    ("covers_screens", facts.screens, assignment.screens),
    ("covers_operations", facts.operations, assignment.operations),
):
    for fact in route_facts:
        ...
```

実在判定の順序も、`expected` → `other` → 不明の順にします。

[Warning] 注釈不足があると `annotations.routes[name]` で`KeyError`になります。既存の「未注釈route」違反を集める前後で、処理全体が例外終了する可能性があります。

修正案: `annotations.routes.get(name)` を使い、存在しない場合は既存違反へ任せて当該複合検査をスキップしてください。

[Warning] `load_assignment()`が文法・型・語彙を検査せず、別プロセスの自己テストだけへ依存する設計は、生成器単体のfail-closedになっていません。

修正案:

- `parse_front_matter()`の違反を必ず`load_assignment()`から伝播する。
- `id`、`applicability`、各`covers_*`が期待型でなければ割当を構築しない。
- 不正カードを飛ばして目録を生成しない。
- 生成/checkコマンド自身がexit 3または入力成立不能のexit 2になることを統合テストする。

[Warning] 「capabilityの一意」が何の一意性か曖昧です。`frozenset`化するとカード内重複は検出不能になります。

修正案: カタログIDの一意性を指すなら対応表にそう明記してください。カード配列内の重複も禁止するなら、`frozenset`化する前に検査と負例を追加してください。複数カードが同じcapabilityを扱うことまで禁止してはいけません。

## 施策 8: REQUEST_CHANGES

`FatalError`を投げる判断自体は既存のfail-closed方針と整合します。契約外セルのまま続行すると、findingの紐付けを欠落させた不完全なレポートを正常生成するため、停止が正しいです。

[Critical] 擬似コードでは `STORY_CELL_SEPARATOR` が定義されていません。施策7の定数は別モジュールなので、そのままでは`NameError`です。

修正案:

```python
STORY_CELL_SEPARATOR = " "
```

をcorrelate側にも置くか、標準ライブラリだけに依存する共有契約モジュールから両者がimportしてください。規則の散文上の正本がREADMEであることは維持できます。

[Warning] `STORY_CELL_RE.match()`と`$`では末尾改行を厳密拒否できません。

修正案:

```python
if STORY_CELL_RE.fullmatch(cell) is None:
    raise FatalError(...)
```

[Suggestion] `FatalError`にはセル値だけでなくroute名または入力行番号も含めると、停止後の修正箇所を特定しやすくなります。

## 施策 9: APPROVE

生成物を手編集せず、既存のbyte一致検査で固定する設計は妥当です。前書きの更新も必要な波及変更として拾えています。

## 施策 10: REQUEST_CHANGES

[Warning] 対象判定表では `.claude/skills/app-bug-hunt/stories/**` を新規Dの対象としていますが、D40案の対象パスはREADMEだけです。

修正案: 台帳規約に従い、少なくとも以下のどちらかへ統一してください。

- READMEだけが差の正本であると対象判定表を修正する。
- 差が現れるカード群・契約テストもD40の対象パスへ列挙する。

特にステップ表を検査しない実装上の差は、READMEだけでなく`test_story_front_matter.py`にも現れます。

[Warning] D14例の「`S4 S7` は `S3` のfindingと一致しない」は比較対象が食い違っています。

修正案: `S3 S7`と`S3`、または`S4 S7`と`S4`の比較へ直してください。

[Warning] E5は対応表で「採用」なのに担い手が`—`です。機械検査しないこと自体が正典どおりでも、「派生キャッシュである」という位置づけの担い手は必要です。

修正案: `施策1（文書のみ。前付けとの一致は非機械保証）`と明記し、保証済み不変条件と混同しない形にしてください。

## 施策 11: REQUEST_CHANGES

[Warning] 実施順序が文書内で矛盾しています。

修正案: 次の1本に統一してください。

`1 → 11（生成）→ 2 → 3/4 → 5 → 6 → 7 → 11（検算）→ 8 → 9 → 10`

なお3/4、7、8はそれぞれ負例を先に作る順序へ分解する必要があります。

[Warning] 「変換後のみがS7ならよい」だけでは、誤ったrouteへS7を付けても合格します。特にS7のscreensは手作業で起こすため、この穴が残ります。

修正案:

- 期待するS7追加分を画面・操作別の明示リストとして固定する。
- 件数だけでなく、`field / route / before / after` の全差分を検算資料へ出す。
- 期待リストとの完全一致で終了コードを失敗させる。
- operationsの「9件」も件数だけでなくroute名で固定する。

## 特に指定された4点への回答

- (a) `fact in facts.screens`：通常の排他的な集合では結果は合いますが、複合method routeで誤ります。欄違い判定も`other`先行ではなく、`expected`先行が適切です。
- (b) `FatalError`：設計として妥当です。契約外の生成物から不完全な照合結果を出すより、入力不成立として停止すべきです。
- (c) 全数対応表：J2/J3、G6、E5に担い手の不足または表現上の過大主張があります。また47項目という件数も実表の58項目と一致しません。
- (d) PHPStan level 10：提示された施策5のPHPは通りません。クラス定数をグローバル定数として参照している点が確定的なエラーです。

## 全体判定

**CHANGES_REQUESTED**

正本を前付けへ一本化する方針、複数カード割当への拡張、契約外セルをfail-closedにする方向は承認できます。主な修正点は、S8を阻害するAC-06、`終`の分類矛盾、複合method routeの欄判定、施策5の定数参照、未定義の区切り定数、採用済み不変条件の検査漏れです。