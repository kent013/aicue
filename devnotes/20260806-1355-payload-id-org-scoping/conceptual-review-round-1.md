全体判定: **CHANGES_REQUESTED**

追加の実ファイル読み込みは行っていません。ユーザー指定の「コマンド実行なし」に従い、提供された設計本文の整合性レビューとして判断します。

## 1. 使命との整合性

[Suggestion]  
直接の機能価値ではなく、SOP・組織データを扱う前提の信頼性を守る債務返済として位置づけており、North Star との距離感は妥当です。過度に「ユーザー価値」として盛っていない点もよいです。

## 2. 禁止事項違反

[Warning]  
検証計画に `composer test` 等はありますが、設計段階では「実装完了報告ではない」ため禁止事項 1 には直ちに抵触しません。ただし実装時は、新規 `PayloadIdExistenceOracleTest` だけでなく既存 Feature/Architecture 更新まで通した状態で完了扱いにする必要があります。

修正提案: 完了条件に「少なくとも新規 Feature、既存期待値更新、`ModelDirectFetchInvariantTest`、関連既存テストが green」を明記してください。

## 3. 実現可能性

[Warning]  
422 統一方針自体は Laravel/Inertia のフォーム UX と整合します。ただし `ValidationException` / redirect back の応答一致テストで、**old input や session cookie 差分**が `ResponseSignature` に混ざる可能性があります。送信した `user_id` が「実在非メンバー」と「不在」で違うため、Laravel の flashed input や再描画 props に値が出ると body が一致しません。

修正提案: `ResponseSignature` が session cookie / CSRF / old input をどう正規化しているかを設計に明記してください。正規化しない方針なら、該当フォームで `user_id` を old input として再露出しないこと、または比較対象を「攻撃者が観測できる安定部分」に限定する必要があります。

## 4. 期待効果の妥当性

[Suggestion]  
「relation 起点 fetch + `exists:users,id` 撤去」をセットで扱っている点は妥当です。`exists` だけ残るとメッセージ差で漏れる、という問題認識も正しいです。

## 5. リスク

[Critical]  
`ProjectMemberController::store` を 403 から 422 に変える判断は UX 上は妥当ですが、**権限不足 actor の 403 と payload 不正の 422 の境界**をテストで固定する必要があります。設計では `Gate::authorize('update', $project)` が validation より前と書かれていますが、ここが将来入れ替わると、権限のない actor が `user_id` 応答差を観測できる経路になります。

修正提案: 新規テストに「project 更新権限のない actor は、実在/不在/非メンバー user_id に関係なく同一 403」を追加してください。少なくとも既存テストでこの順序が固定されているか確認し、なければ追加が必要です。

[Warning]  
`OrganizationOwnershipController::store` でも同様に、`Gate::authorize('transferOwnership', $organization)` が validation より前であることが重要です。設計上は書かれていますが、不変条件としてはやや弱いです。

修正提案: owner でない actor に対して `user_id` の値に依存せず同一 403 になるテストを追加するか、既存テスト名を明記してください。

## 6. スコープの適切さ

[Suggestion]  
3 件の債務解消に限定し、scanner 本体や他 31 件に広げない判断は適切です。FormRequest 新設や専用 Rule を避ける判断も、今回の規模では妥当です。

## 7. 型安全性

[Warning]  
`$organization->users()->whereKey($userId)->first()` の戻り値は `User|null` ですが、`transferOwnership()` や project member 追加処理に渡す前に null 分岐で型が確定する必要があります。設計としては書かれていますが、PHPStan level 10 前提なら `mixed` な `$request->validated()` / `$request->input()` 由来の扱いも詰めるべきです。

修正提案: validated payload は配列 shape を PHPDoc で補助するか、既存 controller の型付けパターンに合わせて `is_int` / cast 後の変数を明確にしてください。

## 8. セキュリティ

[Critical]  
MCP binder を「不在 id も非メンバーも 403」に倒す方針は、存在オラクルを閉じる目的には妥当です。ただし `organization_id` の形式不正だけ 422 のままにする場合、**整数として valid だが境界値・表記ゆれの入力**がどこまで 422/403 に分類されるかを固定しないと、別の判定差が残る可能性があります。

修正提案: `0`, `-1`, `1.0`, `001`, 空文字、配列入力などの扱いをテストで固定してください。存在情報に関係ない 422 は許容できますが、「整数形式として受けるもの」はすべて membership exists 判定へ流して 403 統一、という境界を明文化した方がよいです。

[Warning]  
「status / ヘッダ / body まで一致」を成功条件にしているのは強いですが、Laravel/Inertia の redirect + session を含む経路では、完全一致が実装詳細に引っ張られます。セキュリティ主張としてはよい一方、テストが脆くなるリスクがあります。

修正提案: `ResponseSignature` の比較範囲を設計内で具体化してください。完全一致を維持するなら、セッション ID・Set-Cookie・CSRF・flash old input の扱いまで含めてレビュー対象にするべきです。

---

結論として、**404 ではなく 422/403 に統一する判断自体は概ね妥当**です。特に payload 由来 id を nested route の子リソース 404 と同一視しない整理は正しいです。

ただし、現設計は「権限不足 actor では payload を見ない順序」と「ResponseSignature が本当に観測差を潰せること」の固定が不足しています。ここをテスト設計に追加すれば、APPROVED に近い内容です。