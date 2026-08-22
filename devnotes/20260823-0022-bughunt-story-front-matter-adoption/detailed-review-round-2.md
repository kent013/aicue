# Round 2 レビュー結果

Round 1の主要な問題は適切に解消されています。ただし、AC-14は現状ではまだ「全数点呼」になっておらず、施策7にも`終`の波及漏れと`load_assignment()`のAPI矛盾が残っています。

| 施策 | 判定 |
|---|---|
| 1. 書式の正本 | REQUEST_CHANGES |
| 2. 7枚のカード移行 | APPROVE |
| 3. 前付け読み取り器 | APPROVE |
| 4. 書式契約の自己テスト | REQUEST_CHANGES |
| 5. `composer test` 配線 | REQUEST_CHANGES |
| 6. 注釈から`story`撤去 | APPROVE |
| 7. 生成器の入力切替 | REQUEST_CHANGES |
| 8. 照合器の複数値対応 | APPROVE |
| 9. 目録再生成 | APPROVE |
| 10. 乖離台帳更新 | APPROVE |
| 11. 移行検算 | REQUEST_CHANGES |

## 施策1: REQUEST_CHANGES

[Warning] 本文がまだ「正典との差は次の3点だけ」となっていますが、直後の表は4行です。

修正案: 「次の4点だけ」へ訂正してください。

## 施策2: APPROVE

AC-06の絞り込みと手順節のハッシュ検算を含め、Round 1の問題は解消されています。

## 施策3: APPROVE

`fullmatch()`への統一、候補Markdownの全件走査、構文違反の集約方針は妥当です。

## 施策4: REQUEST_CHANGES

[Critical] AC-14はまだ全数対応表の漏れを検出できません。

現在は`ADOPTED_INVARIANTS`自体が手書きなので、I1〜I6のようにその定数から項目を落とせば、検査も気づきません。実際、提示された`ADOPTED_INVARIANTS`にはI群が入っておらず、`INVENTORY_SIDE`にI1〜I5があっても、点呼のループは`ADOPTED_INVARIANTS`だけを走るため意味を持ちません。I6はどの集合にもありません。

また、`NON_MECHANICAL`もassertに使われていません。

修正案: 分類と担い手を分離し、58項目すべてを先に固定してください。

```python
ALL_INVARIANTS = (
    # A1〜A6、B1〜B16、…、J1〜J3の58件
)

ADOPTED = (...)
DIFFERENCES = (...)
NOT_ADOPTED = (...)

STORY_SIDE = (...)
INVENTORY_SIDE = (...)
NON_MECHANICAL = (...)
```

最低限、次を検査します。

1. `ADOPTED`、`DIFFERENCES`、`NOT_ADOPTED`が互いに排他的
2. 3集合の和が`ALL_INVARIANTS`と完全一致
3. `len(ALL_INVARIANTS) == 58`
4. `ADOPTED`の全件が`STORY_SIDE`、`INVENTORY_SIDE`、`NON_MECHANICAL`のいずれかに担われる
5. 各担い手集合に未知IDがない

B16のような複数担い手は、担い手集合同士の重複を許可すれば表現できます。

[Critical] 検査メソッドの存在確認が実際の命名と一致しません。

`AC-01`から生成される名前は`test_ac_01`ですが、実際の例は`test_ac_01_rejects_quoted_scalar`です。そのため、提示コードの`hasattr(self, method)`は失敗します。

修正案: 主題からテスト名を推測せず、明示的に対応させてください。

```python
SUBJECT_TO_TESTS = {
    "AC-01": (
        "test_ac_01_accepts_canonical_front_matter",
        "test_ac_01_rejects_quoted_scalar",
        # ...
    ),
}
```

各名前がcallableであり、各主題に正例・負例が1本以上あることを確認します。

[Warning] AC-15は見出し数しか検査しておらず、J2の「散文を持つ」まで保証しません。空の`## 目的`でも通ります。

修正案: 各H2節について、次のH2までの本文を抽出し、空白を除いて非空であることを検査してください。`## 逸脱アイデア`についても同様の空節負例を追加します。

[Warning] 「保証しないもの」と`NON_MECHANICAL`が「1対1」と書かれていますが、表はE5/G6以外にI5やIDなしの保証外項目を含みます。

修正案: 「全数対応表で採用と分類した非機械保証2件だけが`NON_MECHANICAL`と1対1」と範囲を限定するか、保証外項目すべてに安定したIDを付けてください。

## 施策5: REQUEST_CHANGES

PHPStan level 10については、提示された構文に確定的な問題はありません。

- PHP 8.4では`public const array`を使用可能
- `@var list<string>`はPHPStanが理解可能
- 名前空間と`use`は整合
- `array{0: int|null, 1: string}`も適切
- `preg_match() === 1`後のキャプチャ参照も妥当

[Warning] `MIN_TESTS = 0`の置き忘れを検出する仕組みがありません。忘れても件数pinが常に成功します。

修正案: 実装時に実測値へ置換するだけでなく、PHP側でも次を固定してください。

```php
expect(StoryFrontMatterPins::MIN_TESTS)->toBeGreaterThan(0);
```

可能なら定数のPHPDocも`positive-int`にします。ただしPHPDocだけでは実行時の0を防げないため、上記assertが必要です。

[Suggestion] `CORE_NEGATIVES`に`test_ac_06_family_surface_pin`が含まれていますが、名前上は負例ではありません。負例として実行される専用テスト名へ置き換えると、定数名と実態が一致します。

## 施策6: APPROVE

`story`を未知項目として拒否し、旧正本を残さない設計は妥当です。

## 施策7: REQUEST_CHANGES

### `終`の扱い

[Critical] `validate_assignment()`だけを`外`限定へ変えても、`終`の対象内化は完了しません。

提示済みの現行コードでは、少なくとも次が`KUBUN_NEEDS_REASON`を対象外判定にも使っています。

- `render_screens()`の「うち対象外」件数
- `_out_of_scope_section()`

このままだと、`終`は割当必須でありながら、生成物では対象外件数・対象外節へ入るという矛盾が発生します。現在0件でも、追加時に静かに顕在化します。

修正案:

- `KUBUN_NEEDS_REASON`はreason要否だけに使用する
- スコープ判定はすべて`kubun == KUBUN_OUT_OF_SCOPE`へ統一する
- `終`が通常の一覧・対象内件数へ入り、対象外節へ入らない統合テストを追加する
- `KUBUN_NEEDS_REASON`の全利用箇所を棚卸しし、「reason判定」か「scope判定」か分類する

[Warning] 「`終`にstoryを書けなかったのはスカラー模型の制約」という説明には論理的根拠がありません。単一値でも`終`へ1枚を割り当てることは可能です。

正典へ寄せる判断自体は妥当ですが、これはデータ構造上自然に消える制約ではなく、`終`の意味を変更する意図的な仕様変更です。

修正案: 因果説明を次のように改めてください。

> 現行は`終`を割当対象外としていたが、正典の「外以外は対象内」へ意図的に意味を変更する。`終`はreason必須かつカード割当必須となる。

そのうえで全consumerの波及確認を明記します。

### `load_assignment()`

[Critical] 「違反時はAssignmentを構築しない」という契約と、戻り値型が矛盾しています。

```python
def load_assignment(...) -> tuple[Assignment, list[str]]:
```

では、違反時にも必ず`Assignment`を返す必要があります。空のAssignmentを返すと、呼び出し側の確認漏れで生成できてしまいます。

修正案:

```python
def load_assignment(
    stories_dir: Path,
) -> tuple[Assignment | None, list[str]]:
```

違反が1件でもあれば`None`を返し、`_prepare()`は`None`または違反ありの場合にレンダリングへ進まないようにします。Result型のdataclassでも構いません。

[Warning] 型だけの検査では生成器単体のfail-closedとして不足します。

例えば次は構文上scalar/stringでも、生成器の処理に直接影響します。

- 不正な`id: SX`
- 未知の`applicability`
- `covers_*`内の非文字列
- route/capabilityの不正形式
- 配列内重複

特に不正IDは`int(s[1:])`で例外になり得ます。

修正案: `load_assignment()`は少なくとも自身が消費する項目について、ID形式・一意性、applicability語彙、配列要素型・形式・重複を検査してください。表A/Bやlaneなど、目録生成と無関係な契約はstories側だけで構いません。

[Warning] 統合テストの期待終了コードが「exit 3またはexit 2」では広すぎます。どちらになっても合格するため、終了コード契約の後退を検出できません。

修正案:

- カード内容のドリフト・形式違反: exit 3
- ディレクトリ不存在、読み取り不能など検査成立不能: exit 2

のように原因別に固定してください。双方で生成物が未変更であることも確認します。

## 施策8: APPROVE

`FatalError`による停止、`fullmatch()`、数値順・重複検査、route名を含むメッセージまで整合しています。契約違反時に走行を止める設計は過剰ではありません。

## 施策9: APPROVE

再生成とbyte一致検査の組み合わせは妥当です。`終`の対象内化に関する施策7の修正後に再生成してください。

## 施策10: APPROVE

Round 1で指摘した対象パスとD14の例は修正されています。件数pinは実装時の実測を優先する条件付きで妥当です。

## 施策11: REQUEST_CHANGES

[Warning] `EXPECTED_S7_ADDED_SCREENS`がまだプレースホルダーです。

今回の検算強化の目的は、手作業で起こすS7画面の誤割当を明示リストで防ぐことです。空のまま「実装時に確定」では、詳細設計時点でS7画面の意味が確定していません。

修正案: 実装開始前にroute名を列挙してください。正しい期待値が本当に空集合なら、コメントではなく「S7が新規消化を宣言するscreenは0件」と明記し、本文の「S7が踏み直す画面」「N件」との矛盾を解消してください。

[Suggestion] 手順節ハッシュは、`## 手順`見出しの直後から次のH2見出しの直前まで、と抽出境界を明文化してください。旧メタ節の撤去をハッシュ対象へ誤って含めるのを防げます。

## 指定された4点への回答

### (a) `終`を対象内へ寄せる判断

正典追従としては成立しますが、「スカラー模型だから割り当てられなかった」という説明は成立しません。意図的な意味変更として扱う必要があります。

また、現行コードには`KUBUN_NEEDS_REASON`を対象外判定にも使う箇所が残っているため、`validate_assignment()`だけの修正では不十分です。reason要否とscope判定を全利用箇所で分離してください。

### (b) AC-14は漏れを捕まえるか

現状では捕まえません。

- 全数の基準となる独立した58件リストがない
- I群が`ADOPTED_INVARIANTS`から漏れている
- `NON_MECHANICAL`がassertに使われていない
- 主題名から生成するメソッド名が実際のテスト名と一致しない

58件の分類partitionと、明示的な主題→テスト名対応が必要です。

### (c) 施策5のPHPStan level 10

`public const array`と`@var list<string>`を含め、提示コードにPHPStan上の明確な問題はありません。PHP 8.4にも適合します。

残る問題は型ではなく、`MIN_TESTS = 0`の置き忘れで検査が無効化されることです。

### (d) 残っているCritical / Warning

- [Critical] AC-14が全58項目を点呼できていない
- [Critical] AC-14のテストメソッド存在判定が実名と一致しない
- [Critical] `終`の対象内化がレンダリング・対象外節まで波及していない
- [Critical] `load_assignment()`の非optional戻り値と「構築しない」契約が矛盾
- [Warning] AC-15が空節を許す
- [Warning] `終`をスカラー制約とする説明が不正確
- [Warning] 生成器が消費する語彙・形式の単体fail-closedが不足
- [Warning] exit 2/3の期待が曖昧
- [Warning] `MIN_TESTS = 0`を防ぐ検査がない
- [Warning] S7 screen期待リストが未確定

# 全体判定

**CHANGES_REQUESTED**

Round 1から大きく改善しており、施策8を含む複数値対応とPHP側の型設計は承認できます。残る中心課題は、AC-14を本当の58項目partitionへすること、`終`のscope判定を全consumerで統一すること、`load_assignment()`の失敗を型で表現することです。