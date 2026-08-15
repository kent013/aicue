全体として Round 1 の Critical 2 件は解消されています。ただし、施策 5 の「`$reason` 出現回数だけで意味を固定する検査」は空振りと誤検出の両方が残るため、そこだけ修正が必要です。

## 施策別判定

### 施策 1: APPROVE

型、固定語彙、内部 DTO という境界は妥当です。TypeScript / JsonResource / Inertia への波及なしという整理も正しいです。

### 施策 2: APPROVE

`recordOrFail()` を同一トランザクション内で使い、例外を伝播させて全体をロールバックする設計は PostgreSQL と整合します。

施策 5 の検査 F によって、失効窓口が best-effort 版へ戻らないことも固定されました。

### 施策 3: APPROVE

修正後のクエリに、提示されたスキーマ上の失効漏れ・過剰失効・cross-org の穴は見当たりません。

- `tokenIds` は `(organization_id, user_id)` に属する全 access token を取得する
- access token の件数は未失効行だけを更新して数える
- refresh token は親 access token の現在の失効状態に関係なく失効する
- access token 更新時にも `(organization_id, user_id)` を再条件化している
- refresh token はグローバルに一意な access token IDを経由するため、他組織へ越境しない

`session_id` で絞らない判断も引き続き正しいです。セッションを持たない旧トークンを含め、「その組織におけるその利用者の全資格情報」という機能名どおりの集合になります。

残る競合窓、つまり `pluck()` 後に新しい access token が発行される可能性は設計書ですでに保証対象外として明示されています。要求ごとの再評価を最終防衛線とする現状では、今回の承認を妨げません。

[Suggestion] `oauth_access_tokens (organization_id, user_id)` の複合インデックスがなければ、運用データ量を見て後続で検討できます。ただし今回先回りして追加する必要はありません。

### 施策 4: APPROVE

配線位置は妥当です。

- canonical lock の取得後
- ロック下の再検証後
- 役割の書き換え後
- 同一トランザクション内
- コミット前

`applyConsoleRole → changeRole` の入れ子も Laravel のトランザクション管理下で外側のロールバックに追随します。`applyConsoleRole` 自身から失効を呼ばないため、二重発火も避けられています。

同値ロールの early return で失効しない判断も、「役割を変える操作が実際に状態を変更したこと」を境界とするなら整合しています。プロジェクト pivot だけの変更を対象外にする仕様とも一致します。

### 施策 5: REQUEST_CHANGES

検査 F と制御パスに関する保証範囲の明記は妥当です。一方、修正後の検査 E にはまだ問題があります。

[Warning] `$reason` の出現回数が 1 回であることだけでは、「監査 metadata でのみ使う」を保証できません。

例えば次は 1 回なので緑になります。

```php
$this->applyRevocationPolicy($reason);
```

分岐を別メソッドへ逃がせるため、検査の目的を満たしません。

また、Reflection で取得した本文にコメントが含まれる実装なら、コメント中の `$reason` で正当なコードが落ちます。変数をデバッガ属性や型確認に使うだけでも落ちるため、将来の無害な変更に対する硬さもあります。

修正案は、次の両方を検査してください。

1. コメントと文字列リテラルを除いた PHP トークン上で、変数 `$reason` がちょうど 1 回参照される
2. その参照が `'reason' => $reason->value` という監査 metadata の値として現れる

少なくとも、単純な `substr_count()` ではなく `token_get_all()` または既存の AST/トークン検査パターンを使うべきです。負例には次を追加してください。

- `$this->applyRevocationPolicy($reason)` だけがある
- コメントに `$reason` があり、metadata にも正規の参照がある
- metadata には固定文字列を入れ、別用途で `$reason` を1回使う

「厳しく固定する」こと自体は正当です。問題は回数では意味的位置を固定できない点です。

### 施策 6: APPROVE

Critical 2 への対応は妥当です。

API キーを「発行者個人の資格情報」ではなく「組織が保有するサービス資格情報」と位置付けるなら、発行者の退会時に自動失効させない設計には合理性があります。ここで一律に所属再評価を追加すると、退職・異動によって組織の連携が予告なく停止するため、本件で塞ぐべきとは判断しません。

ただし、次の境界を仕様として固定することが承認条件です。

- 発行者退会後も、許可された read は通る
- write は現在の組織ロールを評価する `ProjectPolicy` により 403
- API キーの organization 以外へはアクセスできない
- API キー自体の revoke / rotation は別の管理操作として存在する

施策 8 に read 成功と write 403 の両方を追加した判断は正しいです。片方だけでは、単に API キー全体が無効になっていてもテストが緑になり得ます。

[Suggestion] 文書では「所属の再評価をしない」より、「read 権限は発行者の退会後も API キーに残る」と結果を直接書く方が、運用者に誤解されません。

### 施策 7: APPROVE

否定条件と直後の例外送出まで固定する変更で、戻り値を捨てる形の主要な空振りは解消されています。

字句検査には限界がありますが、`final handle()`、全 tool の基底継承、Feature テストとの組み合わせとして十分です。

### 施策 8: APPROVE

Round 1 で不足していた以下が追加され、主要な状態空間を覆っています。

- access token 済失効・refresh token 未失効という不整合
- API キーの read/write 非対称
- cross-org / cross-user 非巻き添え
- rollback
- 実際の MCP/API/refresh grant 拒否

[Suggestion] API キーの write 403 テストでは、scope 不足による 403 ではなく `ProjectPolicy` による拒否だと区別できるデータを作ってください。`write` ability を持つキーを使わないと、意図した再評価を通っていなくても緑になります。

### 施策 9: APPROVE

訂正後の API キー境界と残余リスクを正本へ明記する方針は妥当です。

DTO / JsonResource、Inertia Props、TypeScript 型への変更はありません。

DESIGN.md / Atomic Design は、UI/frontend 変更がないため該当なしです。

## 個別回答

1. 修正後の施策 3 は妥当です。親 access token がすでに失効している refresh token も対象となり、`session_id` 非依存と cross-org 境界も正しいです。
2. API キーの前提訂正と範囲外判断は妥当です。本件で所属再評価を追加する必要はありません。read 継続/write 拒否を意図的な契約としてテスト・文書化することが重要です。
3. `$reason` の出現回数完全一致だけでは不十分です。正当なコメント等を誤って落とし、別メソッドへの分岐委譲を見逃します。トークン単位で「唯一の参照が監査 metadata の値であること」まで固定してください。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の Critical は解消済みです。残る変更要求は施策 5・検査 E の検出方法だけです。これを意味的位置まで検査する形に直せば、全体を `APPROVED` にできます。