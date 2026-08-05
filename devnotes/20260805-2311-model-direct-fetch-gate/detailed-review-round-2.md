**全体判定: CHANGES_REQUESTED**

Round 1 の主要リスクはかなり潰れています。特に alias を「再代入でも取り消さない」方向へ倒した自己修正は妥当です。fail-open を避ける判断として正しいです。

ただし、まだ gate の主張に直接関わる未解決点が 3 つあります。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1. 分類 enum | REQUEST_CHANGES |
| 2. inventory エントリ型 | APPROVE |
| 3. 走査器 | REQUEST_CHANGES |
| 4. inventory 本体 | REQUEST_CHANGES |
| 5. gate 本体 | REQUEST_CHANGES |
| 6. 走査器 Unit テスト | REQUEST_CHANGES |
| 7. 規約ドキュメント登録 | APPROVE |

## 指摘

[Critical] **`routes/*.php` の closure がまだ走査対象として設計上閉じていない**

母集団に `routes/*.php` を入れる目的は正しいですが、走査器設計は「メソッド境界」「methodName」を前提にしています。route closure / arrow function / top-level route 定義内の `User::find(...)` をどう key 化するかが未定義です。ここが未定義だと、概念設計で明示した「route closure に書かれた直 fetch を塞ぐ」が実現しません。

修正案: `routes/*.php` では疑似 scope を導入してください。

- top-level: `__file`
- closure: `__closure1`, `__closure2`
- arrow function: `__fn1`
- key 例: `routes/web.php#__closure1#User.find:$userId#1`

加えて Unit test に `Route::post(..., function () { User::findOrFail(request('user_id')); });` を positive fixture として追加してください。

[Critical] **provenance の「本 gate で分類済みの式の結果」は現在の `candidates()` 署名では循環している**

`candidates()` が provenance 証明を適用して候補を返す設計なのに、「本 gate で分類済みの式の結果」を除外条件に入れると、scanner が inventory を知らないまま inventory 済みかを判断する必要があります。これは token_get_all の問題ではなく処理順の問題です。

修正案: v1 ではこの provenance を外してください。つまり `$var = <候補式>` 由来の `$var->id` 再利用も候補に残す。これは fail-closed で安全です。

どうしても入れるなら、`rawCandidates()` と `classifiedCandidates()` の 2 段に分け、gate 本体が inventory と照合した後に dependent candidate を除外する構成にしてください。ただし実装コストは上がるため、今回は外す方がよいです。

[Critical] **route binding provenance が広すぎる**

「Controller / Middleware のハンドラで型付き引数なら implicit binding」とするのは強すぎます。Laravel の安全性は「型付き引数であること」ではなく、「route parameter と同名で binding され、既存の NestedRoute / TenantBoundary gate の母集団に入っていること」に依存します。

修正案: v1 の安全側後退として、route binding 由来の型付き引数除外をやめてください。候補が増えるだけなので安全です。

もし除外したいなら、route list から `Controller::method` → route parameter 名を引き、`$var` 名と route parameter 名が一致し、かつ `NestedRouteDefenseInventory` 側で分類済みであることまで確認してください。

[Warning] **alias 方針は正しいが、対応マトリクスに古い記述が残っている**

本文では「再代入があっても取り消さない」となっていますが、対応マトリクスには「再代入が 1 回でもあれば alias 無効」と残っています。

修正案: 全文を「再代入では取り消さない」に統一してください。判断自体は approve です。

[Warning] **構造 fingerprint は改善だが、同一 fingerprint 重複時の横滑りはまだ残る**

`User.findOrFail:$userId#1` / `#2` のように同一 fingerprint が複数ある場合、新しい同一候補を前に足すと、既存理由が新しい候補へ一時的に対応してしまいます。未知候補も出るので完全な沈黙ではありませんが、レビュー時の見落とし余地は残ります。

修正案: 同一 `{path, method, rootKind, predicate, identity}` が複数ある場合は、失敗メッセージに duplicate group と `chainSource` の短い preview を必ず出してください。可能なら「同一 fingerprint 重複は明示確認が必要」とする assertion を追加してください。

[Warning] **enum docblock と v1 の許可 signature が不一致**

`DirectFetchJustification::OwnerScopedQueryConstraint` の docblock には `whereHas(...)` が残っていますが、v1 の gate は `whereHas` を受理しない設計です。

修正案: enum docblock から `whereHas` を消すか、「v1 gate では未対応。必要時に fixture と同時追加」と明記してください。

[Warning] **初期 inventory 例が旧 key 形式のまま**

改訂後 key は fingerprint 入りですが、inventory 例は `Jobs/...#handle#1` の旧形式です。実装者がこのまま写すと設計と実装が割れます。

修正案: 例をすべて新 key 形式に更新してください。

[Warning] **`QueuePayloadRehydration` と `predicateKind` の対応が未整理**

`MultiIdentity` を残すなら、queue payload の副条件 `$this->{...Id}` だけでは足りません。`findMany($this->userIds)` は `$this->{...Ids}` になるはずです。一方 `DestructiveIdentity` に queue payload を許すかは別判断です。

修正案: case × predicateKind の許可表を明示してください。最低限:

- `QueuePayloadRehydration + SingleIdentity`: `$this->{...Id}`
- `QueuePayloadRehydration + MultiIdentity`: `$this->{...Ids}` を許すか、v1 では禁止
- `QueuePayloadRehydration + DestructiveIdentity`: v1 では禁止推奨

[Warning] **`verifiedBy` の instance 呼び出し照合は “exact class” を証明できない**

`$this->membership->transferOwnership(...)` を受理する場合、token だけでは `$this->membership` が `OrganizationMembershipService` であることを証明できません。constructor promoted property の型まで追えば可能ですが、設計にはありません。

修正案: どちらかに寄せてください。

- 実装コストを抑える: 「exact class」ではなく「method 名 + marker + 根拠文」と弱めて明記する
- 証明を強める: constructor property / property PHPDoc の型を追って `$this->membership` の class を確認する

v1 なら前者で十分です。ただし “exact method” とは書かない方がよいです。

[Suggestion] **`modelTables()` は Reflection 前提を明記した方がよい**

動的導出は妥当です。ただし abstract model / trait / custom constructor で壊れないようにしてください。

提案: `ReflectionClass` で `isInstantiable()` と `is_subclass_of(Model::class)` を確認し、`newInstanceWithoutConstructor()` か通常 `new` のどちらを使うか設計に書いてください。非 model だが security-sensitive な table があるなら、明示追加リストも持てる形にしておくと現実的です。

[Suggestion] **request accessor の定義を fixture に落とすべき**

`AuthenticatedActorScope` の negative check は濫用防止の要です。

提案: `$request->input()`, `$request->query()`, `$request->validated()`, `request()`, `request('x')` は最低限 fixture 化してください。

## 質問への回答

1. fingerprint は大幅改善です。ただし同一 fingerprint 重複時だけ横滑り余地が残るため、duplicate group の明示検出を足すと十分です。

2. provenance 2 段構成は token_get_all でも一部可能ですが、route binding と「本 gate 分類済み式」はコストが跳ねます。v1 は relation 起点だけ確実に除外し、他は候補に残すのが安全側です。

3. `predicateKind` は方向性 approve です。queue payload との対応だけ明示してください。

4. alias は「再代入でも取り消さない」で正しいです。過剰検出ですが、この gate ではその失敗方向が正しい。

5. 現状のまま provenance を全部やると過大です。route binding 除外と classified-expression 除外を v1 から外せば、実装コストは妥当な範囲に戻ります。