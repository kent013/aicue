レビュー仮説は「実スキーマとの集合等価、構造矛盾、検査自体の空振りを、既存の保持期限の正本を複製せず検出できる」です。成功条件は、純関数を合成入力で赤くでき、実DB照会が型安全かつ現在スキーマに限定されることです。

## 質問への回答

1. Round 1 の2件の [Critical] は解消しています。
2. 区分ごとの件数 pin を落とす判断は妥当です。
3. `FrameworkManaged` をRC-7に含める方針自体は理解できますが、現在の「FKが一本でもあれば違反」という判定は強すぎます。`on_delete = set null` を考慮する必要があります。

## 施策1: 区分 enum と値オブジェクト

**判定: APPROVE**

[Suggestion] `hasHorizon()` は実際の削除保証ではなく、RC-7で保守的に扱うための分類述語です。名前だけでは強く読めるため、docblockに「実際の削除期限の存在を保証する述語ではない」と一文あると保証範囲がさらに明確になります。

## 施策2: 全表の分類台帳

**判定: APPROVE**

`entries()` を `list<RetentionTableEntry>` に統一したことで、重複宣言がキー化によって消える問題は解消しています。

`users` の行単位差異と、`oauth_*` のScheduleを含む責任未決も、台帳の表単位という限界と整合しました。

[Suggestion] 実装コードのPHPDocを必ず次に固定してください。

```php
/** @return list<RetentionTableEntry> */
public static function entries(): array
```

## 施策3: 実スキーマとの照合 gate

**判定: REQUEST_CHANGES**

Round 1の2件の [Critical] は解消しています。

- `DB::connection()->getSchemaBuilder()` によって具体的な `Builder` を取得
- `array_map()` で `list<string>` を構築
- `sort()` による順序固定
- RC-6/RC-7を外部キーmap入力の純関数へ分離

この構成なら、PHPStan level 10と負のコントロールの両方に対して筋が通っています。

[Warning] RC-7が外部キーの存在だけを見て違反にすると、`on delete set null` を誤検出します。

期限を持つ親を参照していても、親削除時にFKがNULLになり、子行自身は残り続けるなら、その子表は必ずしも`DeletedWithParent`ではありません。したがって「FKがあること」と「子表も期限の連鎖にあること」は同値ではありません。

修正案として、RC-7を削除動作別に定義してください。

- `cascade`: 子も消えるため、`ReferenceData` / `FrameworkManaged` と矛盾
- `restrict` / `no action`: 親の期限執行を妨げ得るため矛盾
- `set null`: 子自身は残れるため、原則として違反にしない
- `set default`: default値と制約次第で意味が決まるため、保守的に違反または明示的な扱いを定義
- `null`: 未知として違反に倒す

例えば純関数内で次の条件を明示できます。

```php
static fn (array $fk): bool => in_array(
    $fk['on_delete'],
    ['cascade', 'restrict', 'no action', 'set default', null],
    true,
);
```

`set default`を許可するなら、その根拠を設計書に明記すべきです。

[Suggestion] NC-4も単なるFK存在ではなく、`cascade`または`restrict`を持つ合成入力にしてください。加えて`set null`が違反にならない正のコントロールがあると、RC-7の境界が固定されます。

[Suggestion] `retentionForeignKeyMap()`は全63表を照会します。現規模では問題ありませんが、「必要な区分だけ照会する」という旧記述が詳細設計内に残っているなら削除してください。実装と保証記述を一致させるだけで十分です。

[Suggestion] RC-5は次の二つを別々に収集すると失敗理由が明確になります。

- `ownerClass !== null && ! class_exists($ownerClass)`
- `ownerCommand !== null && ! array_key_exists($ownerCommand, Artisan::all())`

## 施策4: 既存 gate との責務境界

**判定: APPROVE**

「年数・起算点・purgerを写さない」「表集合の重なりだけをRC-4で結線する」という改訂は正確です。二つのgateの母集団と責務も区別できています。

## 施策5: 運用文書

**判定: REQUEST_CHANGES**

[Warning] RC-7の外部キー削除動作に関する保証範囲を文書にも反映する必要があります。

修正案として、`ReferenceData` / `FrameworkManaged`からhorizon側へのFKを一律禁止すると書かず、どの`on delete`動作を矛盾として扱うかを列挙してください。特に`set null`をどう扱うかが必要です。

行単位の寿命、Schedule登録、保持者が実際に対象表を処理するかを保証しないという追記は適切です。

## 施策6: 規約への登録

**判定: APPROVE**

規約の粒度は妥当です。件数をAGENTS.mdへ複製せず、台帳とgateを正本にしている点も既存作法に沿っています。

RC-7を規約本文で説明する場合は、「期限が要る表へのFKをすべて禁止」とまでは書かず、architecture文書へ委譲するのが安全です。

## 全体判定

**CHANGES_REQUESTED**

Round 1の2件の [Critical] は解消しており、残る [Critical] はありません。修正必須なのはRC-7の`on_delete`意味論です。

区分ごとの件数pinを削除した判断は妥当です。総件数は母集団消失の空振りを、未確定表名のexact pinは未決事項の無音増加を検出します。非未確定区分間の変更は台帳差分とRC-4/RC-6/RC-7でレビューされるため、区分別件数を重ねる利益は小さいです。