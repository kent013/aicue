# 全体判定: CHANGES_REQUESTED

Round 1 の指摘には概ね正面から対応できています。`getRouteKeyName()` の判断も妥当です。一方、大小無視一意性と AG-047 の全数性には、まだ裁定を満たさない穴があります。

## 1. 使命との整合性

[Suggestion] URL 単一方式、状態を保存しない `/app` 分岐、旧 resolver の撤去は、共用端末での誤組織撮影を防ぐ方向として使命に整合しています。

[Suggestion] URL 共有の効果を「同じ組織へのアクセス権を持つ利用者」に限定した修正で、Round 1 の過大主張は解消されています。

## 2. 禁止事項違反

[Critical] 成功条件 8 の検証コマンドが、AGENTS.md の必須一覧を満たしていません。次の3本が欠けています。

- `pnpm typecheck:packages`
- `pnpm build:packages`
- `pnpm test:packages`

このままでは、リポジトリ規約上必要な検証を実行せずに成功条件を満たした扱いにできます。

修正提案: 成功条件 8 を AGENTS.md の `VERIFICATION_COMMANDS` と完全に一致させてください。可能なら手書きで複製せず、「同マーカー内に列挙された全コマンド」と参照し、詳細設計時点の正本との同期を確認してください。

## 3. 実現可能性

[Critical] 通常の unique index だけでは、PostgreSQL における「大小無視の一意性」を DB が保証しません。値オブジェクトと Architecture 検査は production DB の制約ではないため、直接 SQL、未検出の書き込み経路、将来の処理から `Foo` と `foo` を保存できます。

したがって「保存層の小文字強制と DB unique index の二重担保」という記述は成立していません。通常の unique index が保証するのは、保存済み文字列の大小を区別した一意性だけです。

修正提案: 次のいずれかを DB 制約として採用してください。

- `CHECK (slug = lower(slug))` と通常の unique indexを併用する
- `UNIQUE (lower(slug))` を使う
- PostgreSQL の適切な case-insensitive 型・collation を採用する

現在の「値は常に小文字」という設計を維持するなら、`CHECK (slug = lower(slug))`＋通常 unique が最も設計意図を直接表します。既存データの小文字化と、正規化後に衝突する行がないことの migration 前検査も必要です。

[Warning] 主な変更コンポーネントの migration 欄には、まだ「識別名の小文字式 unique index」と書かれており、A 節の「通常の unique index」と矛盾しています。

修正提案: 採用する DB 制約を決めたうえで、A 節、migration 表、成功条件 4 の表現を統一してください。

## 4. 期待効果の妥当性

[Suggestion] 自己修復撤去によって暗黙の組織切替が消えること、URL binding によって権限保有者間の表示対象が一意になることは合理的に期待できます。

[Warning] 「現場端末の誤組織事故が構造的に消える」は、`/app` から複数組織の選択画面へ進んだ後の誤選択まで消すようにも読めます。本設計が消すのは、少なくとも「サーバに保存された前回値による暗黙の誤組織」です。

修正提案: 「保持列と自己修復に起因する誤組織表示が構造的に消える」と、除去できる原因を限定してください。

## 5. リスク

[Critical] AG-047 の許可分類自体は妥当ですが、`relation_scoped` に信頼の起点がありません。親資源が slug、表示名、任意文字列で解決され、その relation から組織を辿った場合も、現行記述では `relation_scoped` として許可できてしまいます。

修正提案: `relation_scoped` を再帰的な provenance 契約にしてください。例えば次のように定義します。

> 親資源が `primary_key_binding` または `actor_derived` により確定され、かつ、その親から tenant-scoped relation のみを辿って組織を確定する。

親資源の確定方法が解決できなければ fail-closed にする必要があります。

[Critical] 「組織を確定する入口を全数目録化」とありますが、入口の母集団をどう機械的に抽出するかが定義されていません。目録に登録された入口だけを見る検査では、新しい controller、console command、Filament action、MCP tool が目録にも走査候補にも入らず、緑のままになる可能性があります。docblock に「目録に登録された入口だけ」と書くことは、この全数性の穴を解消しません。

修正提案: 各面の母集団を機械的に定義してください。少なくとも以下が必要です。

- api / ai: route collection から得られる全 action
- console: 登録された全 command または所定 namespace 配下の全 concrete command
- Filament:対象 panel の Resource / Page / Action 等、組織解決を持ち得る構成要素
- MCP: 登録された全 tool / handler

その全母集団を「許可3種別」または理由付き `not_organization_scoped` のいずれかへ完全一致で分類し、未登録・余剰登録・重複登録・空の走査根を落としてください。

[Warning] `primary_key_binding` の「route / 引数」は範囲が広すぎます。HTTP payload の `organization_id` を許すと、tenant キー不信に違反します。また、単に主キーを受け取るだけでは actor がその組織を操作できることを保証しません。

修正提案: 入力面ごとに許可元を限定してください。

- HTTP: route binding の内部主キーのみ。request body/query の tenant key は不可
- actor-derived: 認証済み credential の帰属のみ
- console/internal job: 型付き内部識別子と、呼び出し元・認可境界を目録化
- relation-scoped: 信頼済み親の tenant-scoped relation のみ

これは AG-047 の識別子安定性と、既存の tenant キー不信・cross-org 防止を別々に満たす必要があります。

## 6. スコープの適切さ

[Suggestion] 対象を AG-037 / AG-038 / AG-039 系 / AG-046 / AG-047 に限定し、AG-036・AG-040・他 feature 所有物を除外する切り分けは適切です。割当済み確定項目の明白な欠落もありません。

[Warning] 旧 URL の6系統は主要箇所を押さえていますが、「全数」と呼ぶには走査対象が狭いです。特に PHP 側の一般的な URL 生成、永続・遅延実行される URL、リポジトリ内文書が明示されていません。

修正提案: 6系統を概念上の閉じた一覧とせず、最低でも次を棚卸し母集団へ加えてください。

- Controller / Service / Job / Event / Listener / Resource / Blade / config の `route()`・URL直書き
- queue 済み通知など、URL文字列を生成時点で永続化する経路
- Browser の履歴・bfcache・開いたままの旧画面
- 利用者向け文書、README、運用手順、生成テンプレート
- リポジトリ外のブックマーク等は検査不能であることの明記

旧 URL からの転送を追加する必要はありません。保証範囲を正確にし、リポジトリ内の生成元を走査根ベースで閉じることが修正の中心です。

## 7. 型安全性

[Suggestion] 値オブジェクト、backed enum、設定読込直後の検証、型付き改名判定、Inertia/TypeScript 同時更新は、Round 1 の型境界に関する指摘へ十分対応しています。

[Warning] `currentOrganization` を「明示型」とするだけでは PHPStan level 10 の契約として少し抽象的です。

修正提案: 詳細設計では `OrganizationResource|null`、または専用の `CurrentOrganizationData|null` のように具体型を固定し、Laravel/Inertia の最終的な配列化地点だけで型付き shape に変換してください。

## 指定された3点への回答

1. AG-047 は、否定検査から deny-by-default 契約へ進んだ点は正しいですが、まだ十分ではありません。`relation_scoped` の信頼起点と、全入口を抽出する母集団の定義が必要です。

2. `getRouteKeyName()` の `id` 据え置きは AG-039 と矛盾しません。利用者向け web route がすべて明示的な `{organization:slug}` を使い、field 無指定 binding がゼロである限り、web は可読 slug、Filament 等の機械経路は内部 ID という境界が成立します。

3. 旧 URL の6系統は主要箇所を押さえていますが、全数性の根拠としては不足しています。PHP 全層の URL 生成、遅延・永続化された URL、文書類、検査不能なリポジトリ外状態を追加または保証外として明記する必要があります。