# 対応マトリクス: conceptual-review Round 3 (概念設計の最終ラウンド)

**本ラウンドで概念設計の Codex レビューは上限 3 ラウンドに到達**した (タスク規定)。
Round 3 の [Critical] 2 件・[Warning] 全件を設計へ反映し、概念設計を確定する。
反映後の再レビューは行っていないため、**残存リスクは §末尾に明記**し、詳細設計レビューで再確認する。

## [Critical] provenance フィルタが「モデル由来に見える形」を信じすぎている

- 判断: **対応する (設計の中核を修正)**
- 根拠: 完全に正しい。`$dto->user_id` / `$payload->project_id` はトークン上 `$model->id` と同形であり、
  形だけで除外すると **payload object 由来 id の global fetch が静かに消える**。
  これは gate が「静かに弱くなる」最悪の失敗モードである。
- 対応内容: §4-2(c) を全面改訂。除外は「**Eloquent モデルであると証明できる場合**」に限定し、
  証明手段を 3 つ (型付き引数が `App\Models\*` / PHPDoc 明示 / 同一メソッド内で relation 起点・候補式から代入)
  に限定した。証明できなければ**候補に残す (fail-closed)**。

## [Critical] 「元モデルの解決も候補として捕まる」遡及が常には成立しない

- 判断: **対応する**
- 根拠: 正しい。元モデルが `where('uuid', $requestUuid)` / slug / 外部 DTO / `new Model([...])` /
  implicit binding で解決されていれば、主キー同一性の候補には現れない。
  Round 2 で書いた遡及の議論は無条件では誤りだった。
- 対応内容: **除外が成立する条件を表として明記**した。除外してよいのは元モデルが
  「route binding (`NestedRouteIdorDefenseTest` / `TenantBoundaryOrderingTest` が保証) /
  `{project}` の org 帰属 (`ProjectRouteCurrentOrgGuardTest`) / relation 起点クエリ /
  本 gate で分類済みの式」という**別の保証済み provenance に属する**場合に限る。
  この条件を走査器 docblock と失敗メッセージに書かせる。

## [Critical] `PayloadIdWithGlobalExistenceRuleDebt` の副条件が対応マトリクスより弱い

- 判断: **対応する**
- 根拠: 指摘どおり本文が Round 2 マトリクスより後退していた (クラス全体の `lockForUpdate` 確認止まり)。
- 対応内容: 構造化 field を 4 つ必須にした:
  `verifiedBy` (`Class::method`) / `validationRule` / `todoRef` に加え、機械副条件として
  (a) **呼び出し側がその exact method を呼ぶ**こと、(b) **当該メソッド本文** (クラス全体でなく) に
  membership/tenant marker があること を確認する。

## [Warning] `OwnerScopedQueryConstraint` が右辺 provenance を見ていない

- 判断: **対応する**
- 根拠: `where('organization_id', $requestOrgId)` が signature 一致だけで通るのは穴。
- 対応内容: 副条件 (b) として**右辺が §4-2(c) の provenance 証明を満たすこと**を追加。
  provenance 証明器を候補判定と case 副条件の**両方で再利用**する形になり、機構が 1 つで済む。

## [Warning] `AuthenticatedActorScope` に queue_payload が混ざっている

- 判断: **対応する**
- 根拠: 正しい。actor/token (リクエストごとに検証される) と queue payload
  (過去のリクエストがシリアライズした値) は信頼境界が違う。
- 対応内容: **`QueuePayloadRehydration` を独立 case に分離**。副条件は
  `app/Jobs/**` 配下 + 識別子が `$this->{…Id}` + 構造化 field `enqueuedBy` (dispatch 元 `Class::method`)。

## [Warning] `LocalOnlyDiagnostics` / `OperatorInvokedConsoleCommand` の副条件が弱い

- 判断: **対応する**
- 対応内容:
  - `LocalOnlyDiagnostics`: 構造化 field `routeName` を必須にし、**route 走査で当該 route に
    `LocalOnly` middleware が付いていることを照合**する (ファイル内文字列一致をやめる)。
  - `OperatorInvokedConsoleCommand`: 構造化 field `commandSignature` を必須にし、
    根拠文に呼び出し主体を書かせる (scheduler / queue から呼ばれる command と区別)。

## [Warning] FQCN / 同一 namespace / `new` 起点が抜ける

- 判断: **対応する**
- 対応内容: (a) の解決経路を `use` import / FQCN 直書き / 同一 namespace の 3 つに拡張。
  `new App\Models\*` 起点も候補に含め、内部概念名を `ClassRootedPrimaryKeyQuery` に改めた。

## [Warning] 非対応構文 (`whereRaw` 等) の negative fixture が「抜け道の追認」になる

- 判断: **対応する**
- 対応内容: fixture 名を `outOfScope_*` に改め「保証」ではなく「既知の範囲外」と読める形にした。
  さらに **`whereRaw('id` / `whereIntegerInRaw('id'` が app 全体で 0 件であることを別 assertion で固定**
  (現状 0 件)。範囲外の経路が実際に生えたら fail する。

## [Warning] §9 の主張が §4-6 の限界宣言と矛盾

- 判断: **対応する**
- 対応内容: §9 を「静的起点 + 主キー同一性による直 fetch が、分類なしに入り込まない」まで弱め、
  「cross-org read/write が起きないことの全面的証明ではない」と明記した。

## [Warning] builder alias / 追加 fixture

- 判断: **対応する**
- 対応内容: §8-2 の fixture を 7 種 → **12 種**に拡張 (FQCN / alias 付き qualified / `whereId` /
  3 引数等価 / `whereIn` qualified / `new` 起点 / **`$dto->user_id` の非除外**)。
  negative 側にも alias invalidation と型付き引数の除外を追加。

---

## 残存リスク (再レビュー未実施のまま確定した点)

上限 3 ラウンドに達したため、上記の反映内容そのものは Codex 再レビューを受けていない。
特に次の 2 点は詳細設計レビュー (Phase 2) で重点的に確認する:

1. **provenance 証明器の実装可能性**。型付き引数・PHPDoc の解決を `token_get_all` で行う際、
   メソッドシグネチャの走査が必要になる。実装が過度に複雑化するなら
   「証明手段を型付き引数のみに絞り、PHPDoc は候補に残す」方向へ後退させる余地を残す
   (fail-closed 側への後退なので安全側)。
2. **初期 inventory の実件数**。§2-3 の 33 件は旧 (syntactic) フィルタでの実測値であり、
   型証明を要求する新フィルタでは増える (見積り 33〜40)。実装者が走査器を流して確定する。
   **50 件を大きく超えるようなら分類粒度の再検討が要る**ことを詳細設計に申し送る。
