# 対応マトリクス: conceptual-review Round 2

全体判定: CHANGES_REQUESTED（Round 2）→ 下記対応の上 Round 3 へ。

## [Critical] category_id 入力名衝突（ProhibitsProtectedKeys が payload の category_id を弾く）
- 判断: 対応する
- 根拠: 正当。protected キー `category_id` を FormRequest 入力名にも使うと `ProhibitsProtectedKeys` で必ず 422 になり、通常のカテゴリ選択が成立しない。Round 1 の私の修正は解決経路を書いたが入力名の衝突を見落としていた。
- 対応内容: 入力名を別名 `category`（値は category id）に変更。`category` を project 配下 exists で検証 → 保存時に project relation から再解決して `category()->associate()`。`category_id` 直送は 422 のまま。Feature テストも「category 別名で他 project id は弾く / 再解決固定」を追加。

## [Warning] cuts.adopted_take_id ↔ takes.cut_id の循環 FK
- 判断: 対応する
- 根拠: 正当。単一マイグレーションでは構築不能。
- 対応内容: 「cuts（adopted_take_id は FK なし nullable カラム）→ takes（cut_id FK）→ Schema::table('cuts') で adopted_take_id FK 追加」の順序を明記。

## [Warning] reorder の検証が各 id exists だけで欠落・重複・空を許す
- 判断: 対応する
- 対応内容: ReorderCategoriesRequest で「送信 id 集合 = 当該 project の Category 集合と完全一致（distinct・過不足なし）」を検証、不一致は 422、と明記。

## [Warning] Rule::exists は検証時点の保証、保存時再解決を必須に
- 判断: 対応する
- 対応内容: 「検証済み id をそのまま代入せず project relation から再解決して associate」を必須契約とし Feature テストで固定、と明記（category_id 節・テスト節）。

## [Suggestion] JobStatus はフェーズ1に対応テーブル・利用箇所がない
- 判断: 対応する（採用）
- 根拠: 「今必要なものだけ作る」。JobStatus は analysis_jobs/render_jobs 専用で両テーブルは後続。
- 対応内容: フェーズ1 enum 一覧から JobStatus を除外しジョブ導入フェーズへ移動。制約セクションにも追記。

## [Warning] nullable ?Type だけでは PHPStan 型が確定しない
- 判断: 対応する
- 対応内容: 制約に「Item 規約フル踏襲（@property PHPDoc・casts() 返却型・relation generics・Resource/Data shape）」を明記。詳細トレースは Phase 2。

## [Warning] adopted_take_id の same-cut 保証は通常 FK では不可（将来条件）
- 判断: 対応する（記載）
- 対応内容: 制約に「Tier B の将来必須条件」として、後続採用 API は cut->takes() 経由解決・cross-cut は 404・IDOR テスト必須、を引き継ぎ事項として明記。

## [Suggestion] Category を CRUD と呼ぶ場合 index/show 内包の用語注記
- 判断: 対応する（採用）
- 対応内容: Tier A 定義に「専用 index/show を持たず Projects/Show 内包、CRUD は store/show/update/destroy を指す」注記を追加。
