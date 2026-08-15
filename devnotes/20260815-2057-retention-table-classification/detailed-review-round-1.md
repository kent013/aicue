**前提仮説**

この設計の成功条件は「実スキーマの全テーブルが、保持期限の責務区分に必ず分類されること」と「既存の課金保持 gate と保持期限値の正本を二重管理しないこと」です。方向性は妥当ですが、現状の詳細設計には実装時に赤くなる可能性が高い点と、gate の責務が少し過剰に広がっている点があります。

**施策 1: 区分 enum と値オブジェクト**  
判定: **APPROVE**

[Suggestion] `RetentionClass::Undecided::hasHorizon()` を `true` にする判断は合理的です。ただし「未確定 = 期限が要る」とまでは断定していないため、コメントは「期限の連鎖に入り得るため保守的に horizon 側へ置く」程度にすると保証範囲がより正確です。

**施策 2: 全表の分類台帳**  
判定: **REQUEST_CHANGES**

[Warning] `RetentionTableRegistry::entries()` の戻り値が設計内で矛盾しています。骨格では `array<string, RetentionTableEntry>`、直後の注記では `list<RetentionTableEntry>` を採る、とされています。  
修正案: `entries(): array` は必ず `list<RetentionTableEntry>` に統一し、phpdoc も `@return list<RetentionTableEntry>` にする。キー化は gate 側の純関数で行い、二重宣言検出前に associative array 化しない。

[Warning] 初期分類で `users` を `ScheduledDeletion` に置くのは意味が広すぎます。実際は「退会予約済みの users だけ削除対象」で、通常 user は期限を持たない主体です。表単位分類なのでこの表は混合寿命になります。  
修正案: `ScheduledDeletion` の rationale に「表全体ではなく deletion_requested 系の状態を持つ行だけが対象」と明記し、docs 側にも「表単位 gate は行状態ごとの寿命差までは表現しない」と保証しないものへ追加してください。

[Warning] `oauth_*` を未確定にする理由として「Passport の掃除コマンドは存在するが Schedule に無い」とありますが、RC-5 は「登録済み artisan コマンド」の実在だけを見る設計です。Schedule 登録有無を見ないなら、根拠と検査がずれます。  
修正案: 未確定理由は「本リポジトリの保持期限責務として Schedule 配線まで含めた正本が未決」と書く。Schedule 検査をしないことも保証しないものへ明記する。

**施策 3: 実スキーマとの照合 gate**  
判定: **REQUEST_CHANGES**

[Critical] `retentionSchemaTableNames()` の実装骨格は PHPStan level 10 で不安定です。`Schema::getFacadeRoot()` は型が緩く、`array_column()` も `list<string>` として推論されにくいです。  
修正案: `Schema::connection()` など `Illuminate\Database\Schema\Builder` として解決できる呼び方に統一し、`array_map(static fn (array $table): string => $table['name'], ...)` で明示的に `list<string>` を返してください。結果は sort して比較順も固定する。

[Critical] RC-6 / RC-7 の負のコントロールを「純関数へ合成入力」と書いていますが、設計された純関数 `retentionClassify()` は RC-1〜RC-3 しか返していません。RC-6 / RC-7 は現状 DB 照会に直結しており、合成入力で点灯できません。  
修正案: FK 構造も引数に取る純関数を分離してください。例: `retentionDeletedWithParentViolations(list<RetentionTableEntry>, array<string, list<RetentionForeignKey>>): list<string>`、`retentionHorizonParentViolations(...)`。Feature テストは実 DB から FK map を作って渡し、負のコントロールは合成 FK map を渡す形にする。

[Warning] `Schema::getForeignKeys($table)` は現在 schema を絞っていません。`getTables()` は current schema に絞る一方、FK 取得が search_path 依存になると、同名テーブルや pgsql schema の扱いで不整合が出ます。  
修正案: Laravel 13 の `getForeignKeys()` が schema-qualified table を受けられるか確認し、受けられるなら current schema qualified 名を使う。受けられない場合は「current schema の search_path を前提にする」と docblock に保証範囲として書く。

[Warning] RC-7「ReferenceData / FrameworkManaged が horizon table を親に持たない」は有用ですが、`FrameworkManaged` には Laravel 管理表同士の FK や vendor 実装都合が入り得ます。将来 framework 表がアプリ表へ FK を持つケースは少ないものの、責務境界としてやや強いです。  
修正案: RC-7 の対象をまず `ReferenceData` に限定するか、`FrameworkManaged` を含めるなら rationale に「framework 表がアプリ寿命を持つ親へ依存したら framework-managed ではない」と明記してください。

[Warning] RC-5 の「登録済み artisan コマンド」は `Artisan::all()` を呼ぶならアプリ boot と command discovery の副作用を伴います。Feature lane なので許容できますが、実行時間と外部初期化のリスクがあります。  
修正案: `array_key_exists($entry->ownerCommand, Artisan::all())` に限定し、コマンド実行はしないことをコメントで固定する。可能なら ownerCommand 必須対象を `ScheduledDeletion` のみに絞る。

[Suggestion] RC-8 は件数 pin と未確定表名 pin はよいですが、区分ごとの件数 pin まで持つと分類変更のたびに機械的更新が増えます。分類変更をレビューに出す目的なら有効ですが、過剰摩擦になり得ます。採用するなら「分類変更そのものもレビュー対象にするため」と明記してください。

**施策 4: 既存 gate との責務境界**  
判定: **APPROVE**

[Suggestion] 追記内容は妥当です。ただし「同じ事実を 2 か所に書かない」と言いつつ RC-4 で課金表集合を照合するため、表名自体は両方に存在します。文言は「年数・起算点・purger は写さない。表集合の重なりは RC-4 の結線だけで管理する」がより正確です。

**施策 5: 運用文書**  
判定: **REQUEST_CHANGES**

[Warning] gate docblock と同じ保証範囲を書く方針はよいですが、今回の設計では「行状態ごとの寿命差」「Schedule 登録有無」「ownerClass が実際に対象表を削除すること」を保証しない点が重要です。  
修正案: docs にこの 3 点を明記してください。特に `users` と `oauth_*` の分類理由と検査内容がずれやすいです。

**施策 6: 規約への登録**  
判定: **APPROVE**

[Suggestion] AGENTS.md に足す内容は妥当です。ただし `AGENTS.md` の既存構成上、「ドメイン固有規約」という見出しが実際に存在しない場合は、近い既存節へ入れる必要があります。実装時に章名を先に確認してください。

**全体判定: CHANGES_REQUESTED**

設計の方向性はよいです。deny-by-default の母集団を実スキーマに置く判断、課金保持 gate と値の正本を分ける判断、未確定を隠さず pin する判断はいずれも妥当です。

ただし、実装前に少なくとも次を直すべきです。

1. `entries()` の戻り値を `list<RetentionTableEntry>` に統一する  
2. RC-6 / RC-7 を合成入力で負のコントロール可能な純関数へ分離する  
3. Schema API 周りを PHPStan level 10 で通る具体形に落とす  
4. `users` や `oauth_*` のような「表単位では表現しきれない保持」を保証しないものへ明記する