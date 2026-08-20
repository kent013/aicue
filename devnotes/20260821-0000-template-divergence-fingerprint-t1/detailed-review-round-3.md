全体判定: **CHANGES_REQUESTED**

Round 2の主要Criticalはほぼ解消されています。債務モデル、母集合保持、部分更新検出、C3の債務移管はいずれも妥当な方向です。

残る実質的な問題は、`PathObservation`が検査不能状態を型として表現できないことと、初回以降の母集合定義・C3の波及変更が一部の文書や変更一覧へ反映されていないことです。

## S1: 識別子の反転

判定: **APPROVE**

期待値完全一致とfail-closedなF2で十分です。

## S2: 指紋台帳DTOとパス検証

判定: **APPROVE**

11形への統一、正準形バイト一致、生成器入力への同じ検査、F9への参照修正はいずれも適切です。

## S3: 母集合の列挙と生成ロジック

判定: **REQUEST_CHANGES**

[Warning] `AppFingerprintBuilder`のdocblockには、母集合が今も単純な「正典キー∩現在の追跡ファイル」と書かれています。実際の規則は、2回目以降に旧台帳キーを加えたものへ変わっています。

修正案: docblockを次の定義へ合わせてください。

- 初回: 新正典キー ∩ 現在の追跡パス
- 2回目以降: 新正典キー ∩（現在の追跡パス ∪ 旧アプリ台帳キー）

[Warning] `FingerprintGenerationService.php`が新規ファイル一覧にはありますが、施策一覧とS3/S4の変更ファイルに含まれていません。

修正案: S4の変更ファイルと施策一覧へ同ファイルを追加してください。

## S4: 生成器と生成物

判定: **REQUEST_CHANGES**

[Warning] exit 1の部分更新について、終了コード表には引き続き「件数pinが合わなくなるので必ず赤」と書かれています。今回追加したF14が必要になった理由と矛盾します。

修正案: 次のように訂正してください。

> 部分更新はF5・F9/F10・F14のいずれかで不合格になる。特に件数が変わらない部分更新はF14が検出する。検出力は3種類の失敗注入テストで固定する。

[Warning] `FingerprintGenerationService`へroot・writer・git等は注入しますが、入力sha256の期待値やsource commitなどのpinをどう注入するかが未定義です。`LedgerPins`を直接読むと、合成した正典台帳による正常系テストが難しくなります。

修正案: serviceには少なくとも以下を引数またはreadonly context DTOで渡してください。

- 期待する正典台帳sha256
- 期待するsource commit
- adoptフラグ
- 前世代台帳
- 出力先

CLIだけが`LedgerPins`から値を取得し、serviceの単体テストは合成値を渡せる構造にします。

## S5: 突合と債務判定

判定: **REQUEST_CHANGES**

[Critical] 現在説明されている`PathObservation`では、検査不能状態を矛盾なく表現できません。

保持する状態は`ComparisonState`とされていますが、このenumには次の3つしかありません。

- `Matched`
- `ContentMismatch`
- `MissingCurrent`

一方で不変条件は「検査不能なら理由が非null、hashがnull」としています。検査不能時にどの`ComparisonState`を入れるかが定義されていません。`MissingCurrent`を使うと「検査不能をMissingCurrentへ畳まない」という不変条件に反します。

修正案: 例えば次の排他的な型にしてください。

```php
final readonly class PathObservation
{
    public function __construct(
        public ?ComparisonState $state,
        public ?string $currentHash,
        public ?string $inspectionFailure,
    ) {
        // 次の4形だけを許容する
        // Matched         + valid hash + null
        // ContentMismatch + valid hash + null
        // MissingCurrent  + null       + null
        // null            + null       + non-empty reason
    }
}
```

または`ComparisonState`とは別に観測結果enumを作っても構いません。次の不正組合せをすべてコンストラクタで落とす負例も必要です。

- `MissingCurrent`とhash
- `MissingCurrent`と検査不能理由
- `Matched`または`ContentMismatch`でhashなし
- 正常状態と検査不能理由の併存
- state/hash/reasonがすべてnull
- 検査不能理由が空文字
- hashの書式不正

## S6: 突合gate

判定: **REQUEST_CHANGES**

gateの判定構造自体は妥当です。

[Warning] F14は`debtPathsOutsidePopulation`が存在する場合に、`$templateHashes[$path]`を直接参照すると未定義キーになります。

修正案: F14では、母集合内であることを確認できた債務だけをhash比較へ進めるか、母集合外を独立違反として記録してから安全に評価してください。全違反を一度に出す方針なら、未定義キー例外で途中終了させない構造が適切です。

[Warning] docblockやF11説明にはC2時点の「債務176件」が固定記述されていますが、C3完了後は174件です。

修正案: gateの正本docblockでは具体件数を持たず、「`LedgerPins::ADOPTION_DEBT_COUNT`でpinされた債務」と記述してください。フェーズ別数値は設計書の表だけを正本にするとC3でgateを再編集せずに済みます。

## S7: 負例・正例

判定: **REQUEST_CHANGES**

[Warning] `PathObservation`の不変条件テストが計画にありません。

修正案: S5で挙げた有効4形と不正組合せをdatasetへ追加してください。検査不能を独立状態として扱うための型が崩れると、F8の前提も崩れます。

そのほかの11形・8件・部分更新3状態の整理は妥当です。

## S8: 件数pinの一本化

判定: **APPROVE**

既存の両方向負例を再利用する判断も含め、問題ありません。

## S9: D33/D34と保証範囲

判定: **APPROVE**

`AtomicLedgerWriter.php`と`.tsv`への訂正が反映され、C2の整合式と一致しています。

[Suggestion] 「上記9パス」はD33の7パスとD34の2パスを合わせた数だと読めますが、D33単独の件数と誤解されやすいため、「D33/D34で追加する計9パス」と書くと明確です。

## S10: AG-159の責務縮小

判定: **REQUEST_CHANGES**

[Warning] AGENTS.md案は母集合を再び「正典キーと現在の追跡ファイルの積集合」とだけ説明しています。2回目以降に旧台帳キーを保持する規則と一致しません。ローカル削除を母集合へ残すという今回の重要な修正が、規約文では消えています。

修正案: 詳細式をAGENTS.mdへ複製したくない場合は、次の程度に留めてください。

> 母集合は正典指紋台帳のキーを起点に生成する。初回採用後のローカル削除では既存キーを母集合から外さず、正典側から消えた場合だけ除外する。詳細な生成規則の正本は`AppFingerprintBuilder`のdocblockとする。

## S11: 登録の契機

判定: **REQUEST_CHANGES**

D35へ移して債務を174件へ減らす判断は正しいです。

[Warning] 施策一覧のS11の変更ファイルはスキル2本だけですが、本文上は同じC3で次も変更します。

- `docs/template-divergence.md`
- `LedgerPins.php`
- `adoption-debt.tsv`
- S7の現物期待値
- D34本文

修正案: S11の変更ファイル欄とC3の波及一覧へすべて追加してください。

[Warning] テスト計画の「スキル文書の変更のみ。テストへ影響しない」は誤りです。C3ではpin、債務一覧、登録簿、テスト期待値も変わります。

修正案: 次をC3の受入条件として明記してください。

- D35が2パスを登録している
- 債務TSVから2行消えている
- 債務件数が174
- 登録件数が33
- `doubleDeclaredPaths`が空
- `mutatedDebtPaths`が空
- 10検証コマンドがすべて緑

[Warning] C3後もS6 docblock、S4の説明、その他の「176件」という固定記述が残る可能性があります。

修正案: C3で変更する数値をD34とS7だけに限定せず、追跡下の`176`の該当箇所を棚卸しし、フェーズ説明を除く現在値の記述を174へ更新してください。最終整合式も次の形で残すと安全です。

```text
281 = 78（一致）+ 29（登録済み相違）+ 174（債務）
```

## 実装モード

判定: **APPROVE**

新規PHP18本と生成物2本、フェーズ別pin、10コマンドの受入条件は整合しています。

---

債務モデルと部分更新の設計は承認可能な水準まで改善されています。残る唯一の構造的なCriticalは、`PathObservation`が検査不能を型として表現できない点です。ここを直し、母集合定義とC3の波及一覧を文書全体へ反映すれば、次ラウンドではAPPROVEDにできます。