# 対応マトリクス: conceptual-review Round 2

## [Critical] 新規 manual を lockForUpdate していない（共有ロック規約の文言非準拠）（観点3）
- 判断: 対応する
- 根拠: 規約は目的規定だけでなく「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内」という**実装規定**。cuts の書き込み先（新 manual）を literal にロックすべき。Architecture テスト解釈を増やさない。
- 対応内容: Service を「新 manual を save() → 同一 tx 内で `$locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail()` で再取得 → その locked インスタンスの relation 経由で cuts を作成」に変更。既存 ScenarioService の準拠形と一致。

## [Warning] 新経路の inventory 登録は architecture.md 追記だけでは不足（観点3）
- 判断: 対応する
- 根拠: AGENTS.md は scanner 検出有無に関わらず新書き込み経路の inventory 登録を要求。
- 対応内容: `ScenarioWritePathInventoryTest.php` の docblock 経路表に `VideoManualService::duplicate()`（書いてよいもの = cuts。lockForUpdate 済み新 manual 経由）を明示追記。`docs/architecture.md` の書き込み経路表にも追記。scanner の deny-by-default（検出 1/2/4）は duplicate が scenario_version/status/adopted_take_id リテラルを書かないため追加 allowlist 変更は不要（この点も docblock に記す）。

## [Warning] FormRequest 検証が resolveOrganizationProject より先に走り category 存在オラクル（観点2 / 観点5）
- 判断: 一部対応（既存機構で担保 + 明文化）+ 対応（契約固定）
- 根拠: route は `project.in-current-org` middleware 配下。middleware は FormRequest 検証より前に走り、cross-org `{project}` を 404 に落とす（`{manual}∈{project}` も route model binding = scopeBindings で検証前に 404）。よって `{project}` は検証時点で in-org 保証済み。StoreVideoManualRequest と同型に category exists を **route project の id にスコープ**すれば cross-project category は 422。
- 対応内容: `DuplicateVideoManualRequest` は StoreVideoManualRequest を厳密に踏襲（`Rule::exists('categories','id')->where('project_id', $routeProjectId)`、`ProhibitsProtectedKeys`）。設計に「middleware ordering が存在オラクルを閉じる」ことを明記。category 選択肢取得（Show props）も解決済み project relation 経由（既存 `categoryOptions()`）。

## [Warning] category エラーの 422/404 契約が曖昧（観点5）
- 判断: 対応する
- 根拠: 同一入力が FormRequest と Service のどちらで拒否されるかで契約が変わると混乱。
- 対応内容: 契約を固定 — **不正値・他 project の category = 422（FormRequest）**、**検証後にカテゴリが削除/移動された競合時のみ Service 再解決の firstOrFail で 404**。両方をテスト。

## [Warning] `validated('category')` が mixed で PHPStan L10 リスク（観点7）
- 判断: 対応する
- 根拠: mixed をそのまま `?int` 引数へ渡すと L10 で問題。
- 対応内容: `DuplicateVideoManualRequest` に型付きアクセサ `title(): string` / `categoryId(): ?int`（内部で validated + Assert で narrow）を追加。Controller はアクセサ経由で Service に渡す。保護キー非 mass-assign は維持。

## [Suggestion] 後続フロー接続の Feature テスト（観点7末尾）
- 判断: 対応する（比例的に）
- 対応内容: テスト計画に「複製直後の `CutSequencer::orderedWithLabels` が全 cuts を手順N/急所N-M ラベル付きで返す」を追加。「take 採用後 RenderJobService が長さ再計算できる」は既存レンダ経路のテストが担保する領域のため、複製側では cut_length_ms=null 初期化の確認までに留める（過剰 scope 化しない）。

## [Suggestion] 使命整合・効果表現・スコープ（観点1/4/6）
- 判断: 見送り（既に妥当と評価）
- 対応内容: 変更なし。
