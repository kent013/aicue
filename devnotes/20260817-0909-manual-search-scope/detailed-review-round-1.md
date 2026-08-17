**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、SQL 意図・PHPStan 型・LIKE エスケープ・性能見立てに修正必須の点があります。

**施策 1: REQUEST_CHANGES**

[Critical] `addcslashes()` だけでは PostgreSQL の `LIKE` エスケープ契約として不十分です。  
PostgreSQL では `\` が既定 escape 文字ですが、DBMS 依存の暗黙挙動に寄っています。`%` / `_` / `\` をリテラル扱いするなら、SQL 側にも明示して `ESCAPE '\'` を付けるべきです。Laravel の `where(..., 'like', ...)` だけでは `ESCAPE` を付けられないため、共通ヘルパ内で `whereRaw('"title" like ? escape \'\\\'', [$like])` 相当、または grammar に寄せた安全な実装が必要です。cuts 側も同じです。

[Warning] `Illuminate\Contracts\Database\Eloquent\Builder` を受け型にする判断は再確認が必要です。  
設計では「contract interface が `where` / `orWhereHas` を level 10 で通す」としていますが、Laravel の contract は実メソッド宣言が薄く、Larastan が `@mixin` をどこまで解決するかに依存します。修正案は次のどちらかです。

- `Illuminate\Database\Eloquent\Builder` を主型にし、呼び出し側で `$project->manuals()->getQuery()` ではなく relation のまま使いたいなら PHPDoc template 付きの local assertion/helper を置く
- contract を使うなら、実装前に最小の PHPStan 検証を設計完了条件へ明記する

[Warning] `BODY_COLUMNS` を `list<string>` にすると、列名 typo を PHPStan が検出できません。  
修正案: `private const array BODY_COLUMNS = [...]` に加え、テストで生成 SQL または各列 hit を固定する方針は良いですが、コメントで「この配列が正本」と言うなら列名変更時の検出責務も明記してください。

**施策 2: APPROVE**

大筋は妥当です。`ManualListQuery` が検索語の正規化ロジックを持たず、PC/PWA 共通の正本へ寄せる判断は良いです。

[Suggestion] DTO から Service 参照になる違和感は残るため、クラス名を `ManualKeywordSearch` ではなく `ManualSearchTerm` / `ManualKeywordNormalizer` と述語ビルダを分ける案もあります。ただし現スコープでは必須ではありません。

**施策 3: REQUEST_CHANGES**

[Critical] `orWhereHas` の grouping 方針は正しいですが、テストだけでなく生成 SQL の形を固定する観点が不足しています。  
特に意図は次です。

```sql
where project_id = ?
  and ...
  and (
    title like ?
    or exists (...)
  )
```

修正案: 境界テストに加えて、少なくとも unit/integration で `toSql()` を見て `or exists` が外側に漏れていないことを固定するか、より現実的には「別 project / 別 org / draft / mine 他人」がすべて混ざらない feature テストを必須にしてください。設計にはありますが、施策 3 の完了条件として明示した方がよいです。

[Warning] paginator の count クエリに対する検証が弱いです。  
EXISTS は重複行を作らないので `meta.total = 1` のテストは良いですが、範囲外ページ丸め時に count が 2 回走る構造は現行どおりです。検索条件が重くなるため、`?page=999&q=...` でも丸め後の `meta.current_page/last_page/total` が検索条件付きで整合するテストを追加してください。

**施策 4: REQUEST_CHANGES**

[Warning] PWA 側は `get()` で全件取得のままなので、検索範囲拡大による負荷評価が甘いです。  
`ready/published` が多い project では、`%LIKE% + EXISTS + withCount` を全件分返します。設計ではページングを Conditional としていますが、今回の変更は検索述語を重くするため、少なくとも「想定上限」と「p95 監視または後続 TODO」を明記してください。

[Warning] `Assert::string($search)` は実装上は残してよいですが、`when($search !== null, ...)` のクロージャ内 narrowing 問題を理由にするなら、`$searchValue = $search; if ($searchValue !== null) { ... }` のようにクロージャへ `string` として捕捉する方が読みやすいです。

**施策 5: REQUEST_CHANGES**

[Critical] index migration は事前確認を人手手順にしている点が弱いです。  
「既に同等索引があれば施策を落とす」だと実装者依存になります。修正案: migration 名は固定でよいですが、実装前チェックを設計の必須ゲートにするだけでなく、テストで「`video_manual_id` を先頭列に持つ index が 1 本以上」とする方針を維持してください。重複防止そのものは migration ではなくレビュー時確認で足りますが、確認結果を設計書に残すべきです。

[Warning] 性能説明に過信があります。  
`cuts.video_manual_id` index は `EXISTS` の相関で候補 cut を manual 単位に絞る助けにはなりますが、`%LIKE%` には効きません。project 内 manual が多く、各 manual の cuts が多い場合、結局多数の text scan になります。修正案: `EXPLAIN` で確認する観点を追加してください。最低限、`video_manual_id` index scan / bitmap scan が使われるか、seq scan になった場合の許容理由を記録するべきです。

**施策 6: APPROVE**

UI 変更は小さく、props shape も変えないため妥当です。

[Suggestion] `"タイトル・本文で検索"` は良いですが、「本文」が scene/narration/subtitle を指すことは利用者にはやや曖昧です。現時点では placeholder なので許容範囲です。

**施策 7: APPROVE**

台帳訂正は妥当です。実装完了後に入れる、という順序指定も正しいです。

**追加で必要な修正**

[Critical] テナント境界テストは PC/PWA 両面で「別 organization かつ同じ numeric id っぽい条件」まで見るべきです。  
route binding 後に `resolveOrganizationProject()` があるため基本は守られますが、検索 OR 漏れは relation constraint を破壊するため、本文一致の別 org manual が混ざらないことは必須です。設計にありますが、これは「新規テストファイル」ではなく施策 1/3/4 の完了条件に昇格してください。

[Warning] LIKE の大小文字区別をスコープ外にする判断はよいですが、PostgreSQL 固定なら `like` は case-sensitive です。既存 title 検索踏襲という説明を UI/仕様側にも残してください。ユーザーが英字検索で混乱しやすい箇所です。

[Warning] `source_documents` を対象外にする判断は妥当ですが、「SOP 起点」という North Star との距離があるため、将来の検索対象として別 TODO にしない理由をもう少し明確にした方がよいです。今回の一覧検索は「生成済み manual の検索」であり「原本検索」ではない、という境界を明記してください。