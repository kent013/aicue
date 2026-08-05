# 対応マトリクス: conceptual-review Round 2

Round 2 の指摘を受けて**設計の中心を作り直した**。要点は 1 つ:

> ノイズ (分類しても意味がない 100 件超) は **ディレクトリの形** をしていない。**id の出所 (provenance) の形**をしている。

Round 1-2 の設計は「Services を母集団から外す」ことでノイズを避けていたが、これは
Codex の [Critical] (Service 委譲で抜ける) をそのまま招く筋の悪い解だった。
フィルタを**識別子引数の出所**に掛け直したところ、母集団を `app/` 全体に広げても
**分類対象は 33 件**にしかならないことを実測で確認した。母集団を絞る理由が消えたので絞るのをやめる。

## [Critical] builder alias (`$q = User::query(); $q->where('id', …)`) で抜ける

- 判断: **対応する**
- 根拠: 指摘どおり。token 走査で実装可能な範囲の抜け道であり、放置は成功条件と矛盾する。
- 対応内容: 同一メソッド内に限定した保守的な alias 追跡を仕様に追加
  (`$var = <静的 root>` の単純代入を静的 root として伝播、再代入で invalidate、
  それ以外の代入元は追跡しない)。§4-3。

## [Critical] 検出 B は Service 委譲の抜け道を塞いでいない

- 判断: **対応する (指摘を全面的に受け入れ、検出 B を廃止)**
- 根拠: 指摘は正しい。B は「入口の可視化」であって「後段解決の保証」ではない。
  提案 1 (Service 側も限定的に sink 検出) を採るべく実測したところ、
  **provenance フィルタを掛ければ `app/` 全体でも候補は 33 件**だった (下表)。
  つまり「母集団を絞る」必要がそもそも無かった。
  母集団を `app/` 全体に戻せば Service 委譲の抜け道は**検出 A が直接塞ぐ**ため、
  B の存在理由が消滅する。存在理由が消えた機構を残すのは思考原則 3 (並走を残さない) に反する。
- 対応内容: **検出 B を削除**。母集団を `app/**` + `routes/*.php` に拡大。
  B が担っていた「`exists:users,id` の debt 可視化」は、該当 2 箇所の case を
  `PayloadIdWithGlobalExistenceRuleDebt` (専用 debt case) に分けることで維持する
  (Round 2 [Warning] `KnownExistenceOracleDebt` の提案を採用)。

### 実測 (provenance フィルタの効き)

| | 件数 |
|---|---|
| `app/` 全体の static-rooted 主キー同一性クエリ | **70** |
| うち識別子引数が `$model->getKey()` / `$model->id` / `$model->{fk}_id` = **解決済みモデル由来** | **37** (自動除外) |
| **分類が必要な候補** | **33** |

除外が正当な理由: 識別子が解決済みモデル由来なら、**その元モデルの解決自体が候補**として
別途検査される。provenance は候補へ遡及するので取りこぼしにならない。
(旧案の `LockedRefetchOfVerifiedModel` case はこのフィルタに吸収されて**不要になった** =
Round 2 [Warning]「`User::whereKey($requestId)->lockForUpdate()` でも通る」も同時に解消。)

## [Critical] `routes/*.php` が母集団に入っていない

- 判断: **対応する**
- 根拠: 指摘どおり。route closure に業務ロジックを書けない gate は本リポジトリに存在しない。
- 対応内容: `routes/*.php` を母集団に追加。実測すると **routes に model/PK アクセスは 0 件**
  (closure は 29 個あるが全て middleware/grouping)。**コスト 0 で穴が 1 つ閉じる**ので入れない理由がない。

## [Critical] case 副条件が濫用抑止として弱い

- 判断: **対応する** (3 つの指摘すべて)
- 対応内容:
  - `OwnerScopedQueryConstraint`: 「追加の where があればよい」を廃し、
    **許可する tenant/owner 制約 signature を列挙**する
    (`organization_id` / `user_id` / `team_id` / `project_id` 列、`whereHas('users'|'organizations')`、
    `whereBelongsTo($user|$organization)`)。`where('active', true)` では通らない。
  - `LockedRefetchOfVerifiedModel`: **case ごと廃止** (provenance フィルタに吸収)。
  - `PayloadIdWithCompensatingCheck`: 「同一メソッドに marker がある」だけでは不十分という指摘を受け、
    **同一の識別子変数が検証呼び出しに渡ること**を条件に追加。さらに「fetch **後**の補償チェック」で
    あることを case 名と説明に明記し、準拠形ではなく **debt** として扱う。

## [Warning] 述語アンカーの文法が狭い / `where('id','>',…)` の誤検出

- 判断: **対応する**
- 対応内容:
  - **等価・IN に限定**する (`where('id', $x)` の 2 引数形、3 引数形は演算子が `=` / `in` のときのみ)。
    `where('id', '>', $cursor)` (`ManualRenderController:122` に実在) は候補にしない。
  - 対応構文を明文化: qualified id (`users.id` / `getQualifiedKeyName()`)、`whereId(`、
    array where (`where(['id' => …])`)、`Model::destroy(`、`DB::connection()->table(`。
  - **非対応**構文 (`whereIntegerInRaw`、`whereRaw`、動的列名) は限界として明記し fixture に negative で残す。

## [Warning] 名称と検出内容のずれ

- 判断: **一部対応する**
- 根拠: テストクラス名 `ModelDirectFetchInvariantTest` は c2c 台帳上の gate 識別子であり変えない
  (他リポジトリとの対応が切れる)。
- 対応内容: **内部概念名**を `PrimaryKeyConstrainedStaticQuery` に統一し、
  走査器を `PrimaryKeyStaticQueryScanner` と命名する。テスト名との関係を docblock に書く。

## [Warning] `PayloadIdVerifiedInLockedServiceTransaction` は lockForUpdate だけでは tenant 検証を証明しない

- 判断: **対応する**
- 根拠: 完全に正しい。ロックは競合制御であって所属検証ではない。
- 対応内容: 根拠文の `Class::method` から**当該メソッド本文を切り出し**、その本文内に
  `lockForUpdate` **と** membership/tenant marker の**両方**があることを条件にする
  (クラスファイル全体ではなくメソッド本文)。加えて呼び出し側が exact method を呼んでいることも確認する。

## [Warning] `AuthenticatedActorScope` に機械条件なしは広すぎる

- 判断: **対応する**
- 対応内容: 部分条件を置く。(a) **同一メソッド内に request accessor が存在しないこと** (negative check)、
  (b) inventory に構造化 field `actorSource` (`authenticated_user` / `validated_token_claim` /
  `passport_token_record` / `queue_payload`) を必須にする。散文だけに依存しない。

## [Warning] 完了条件を「代表的な抜け道 fixture が fail する」まで含める

- 判断: **対応する**
- 対応内容: §8 の検証表を、inventory green だけでなく**抜け道 fixture 7 種が fail すること**を
  必須項目として書き直す (builder alias / where('id') / Service 委譲 / qualified id /
  array where / destroy / DB::table)。

## [Warning] 「不変条件 3 は機械強制済み」と言うには早い

- 判断: **対応する (主張を弱める)**
- 対応内容: 「不変条件 3 を全面的に機械強制する」とは書かない。
  本 gate が保証するのは「**主キー同一性による静的起点の取得**」という具体的経路に限る、と明記する。
  relation/org-scoped 解決の一般的強制は本 gate の主張範囲外であると §4-5 (限界) に書く。

## [Warning] `末尾が id/_id` の key パターンが曖昧 (`valid` に当たる 等)

- 判断: **対応不要となった**
- 根拠: 検出 B の廃止に伴い request accessor / id key grammar の定義自体が不要になった。
  (指摘自体は正しいので、B を将来復活させる場合はこの指摘を参照する旨を残す。)
