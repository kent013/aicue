## 全体判定: CHANGES_REQUESTED

Round 2 の指摘は適切に解消されています。残る主な問題は、種別×次元の被覆検査が「少なくとも一方」であり、二重宣言や余剰委譲を拒否していない点です。

### S1: APPROVE

public API の維持対象と `phpFiles()` の委譲ラッパーが明確になりました。振る舞い保存、名前解決限界の固定、既存テストを変更しない条件も整合しています。

### S2: APPROVE

`ReferenceScanResult::$imports`、facade の canonical 規則、抑制コレクションの扱いは一貫しています。`http_facade_reference` の意味が曖昧になるケースも、gate が拒否する設計として明記されています。

### S3: APPROVE

`(class, kind)` を識別単位に引き上げたことで、同一クラスの複数到達を表現できます。語彙、目録、委譲の責務分担にも問題ありません。

### S4: APPROVE

移設後のAPIと回帰確認は妥当です。

### S5: REQUEST_CHANGES

[Critical] テスト10が「目録か委譲のどちらか」を排他的に保証していません。

現在は必須 `(kind, dimension)` ごとに、目録または委譲が1件以上あれば通ります。そのため、以下が緑になり得ます。

- 目録と委譲の両方で同じ対を宣言する
- 同じ `(kind, dimension)` の委譲を重複登録する
- `requiredDimensions()` に存在しない余剰委譲を追加する
- `DestinationSet` を複数の委譲先へ曖昧に委譲する

これは「同じ到達事実を二重宣言しない」という設計目的と一致しません。

修正案:

- 必須対ごとに coverage source を列挙し、該当元がちょうど1つであることを検査する
- 目録は `CodeReachPoint` の coverage source 1件として数える
- 委譲は同じ `(kind, dimension)` がちょうど1件まで
- 全 delegation が `requiredDimensions()` の必須対に含まれることを逆方向に検査する
- `(kind, dimension)` の委譲重複を拒否する mutation を追加する
- 目録で覆われた `Payment × CodeReachPoint` に委譲を追加し、二重被覆で赤になる mutation を追加する

[Warning] M7の期待する赤が誤っています。

`EXTERNAL_SEAM_RULE_KINDS['http_facade_reference']` から `MarketData` を削除しても、テスト4はキー集合がexact-fitで各値も非空なので緑です。赤になるのはテスト1です。

修正案: M7の期待結果を次へ変更してください。

- テスト1(a): `FxRateService` のHttp siteに一致するentryが0件
- テスト1(b): `MarketData` entryに対応するsiteが0件

[Warning] `PestTestNameScanner` はメソッド呼び出しもtest宣言として拾えます。

`$object->test('名前')` や `SomeClass::test('名前')` も、局所的には `T_STRING(test) + ( + string` になります。

修正案:

- 直前tokenが `T_OBJECT_OPERATOR`、`T_NULLSAFE_OBJECT_OPERATOR`、`T_DOUBLE_COLON` の場合は除外する
- この3形を負のコントロールへ追加する
- Pestのグローバル `test()` / `it()` だけを対象とする契約をdocblockへ明記する

### S6: APPROVE

scannerの正負両方向、canonical規則、scope追跡、既知の名前解決限界、複数kindの前提が十分に検査されています。

S5のM7期待結果だけ修正が必要ですが、S6自体の計画には問題ありません。

### S7: APPROVE

fake配線、実HTTP経路の負のコントロール、secret未設定時、環境復元が揃っています。セキュリティ不変条件との矛盾もありません。

### S8: APPROVE

詳細の正本を `docs/architecture.md` に一本化し、gateとAGENTS.mdを要約にした方針は妥当です。

[Suggestion] S8には「保証しないもの9項目」とありますが、現在は10項目です。表記だけ「完全一覧」に変更すると今後の増減にも耐えます。

DTO/JsonResource、Inertia、TypeScript、DESIGN.md、Atomic Designは今回の変更対象外であり、追加対応は不要です。