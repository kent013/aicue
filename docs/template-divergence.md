# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
  不変条件が揃っていれば構文差は許容
- 各エントリには (a) なぜ logic-driven か (b) テンプレートの不変条件をどの機構で保証し続けるか
  を必ず書く

## エントリ形式

```
## D1 ✅ <逸脱の要約>

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| ... | ... | ... |

### なぜ正当な差分か(logic-driven)
...

### 揃えている不変条件(これは保証し続ける)
> 「...」
どの機構でカバーするか。drift を防ぐテスト。

### 関連
- 実装: ...
- テンプレート側の根拠: ...
```

---

## D1 ✅ Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| リソース追加 | Item 見本の 15 点フルセット (route/Controller/UI まで) を同時に張る | Cut / Take は migration + Model + Factory + 保護キーのみ (route/Controller/UI なし)。SourceDocument は T003 (AI 解析) で route/Controller/UI を追加済みで本差分の対象外 |

### なぜ正当な差分か(logic-driven)
AI-CUE の中核集約 (VideoManual ─< SourceDocument / Cut ─< Take) はフェーズ1で
スキーマを確定させ、後続フェーズ (AI 解析・シナリオ編集・撮影 PWA) がカラム追加なしに
振る舞いだけを足せるようにする (doc/10 §10.6 フェーズ1定義)。実 route 未確定のまま
IDOR inventory だけ先行させないため、route/UI は張らない。

なお SourceDocument は T003 (AI 解析) で振る舞いが確定し、
`SourceDocumentController` + `projects.manuals.source-documents.store` route +
`SourceDocumentUpload.svelte` を追加して本差分から**卒業**した。残る先取りは Cut / Take のみ
(Cut は書き込み経路のみ ScenarioService 経由で存在し、per-row route は張っていない。D5 参照)。

### 揃えている不変条件(これは保証し続ける)
> 「route/UI を張るまで外部到達不可。張るときに NestedRouteIdorDefenseTest inventory 登録と
> relation 経由解決 + 404 テストを同時に行う」
NestedRouteIdorDefenseTest の deny-by-default (2+param route の未分類 fail) が、後続フェーズで
route を張った瞬間に分類登録を強制する。保護キー (video_manual_id/cut_id/parent_cut_id/
adopted_take_id) は MassAssignmentSafetyTest が $fillable 不含を自動走査する。
SourceDocument の卒業時にはこの不変条件どおり、`projects.manuals.source-documents.store` の
NestedRouteIdorDefenseTest inventory 登録と relation 経由解決 + 404 テスト
(`tests/Feature/Projects/SourceDocumentUploadTest.php`) を route 追加と同時に行った。

### 関連
- 実装: `database/migrations/2026_07_10_*`, `app/Models/{SourceDocument,Cut,Take}.php`,
  卒業分: `app/Http/Controllers/Projects/SourceDocumentController.php`,
  `resources/js/components/features/manual/SourceDocumentUpload.svelte`
- 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策2/4/5

## D2 ✅ 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| migration | 1 テーブル = 1 migration (CREATE 内で FK 完結) | cuts は CREATE 時に FK なし nullable カラムのみ置き、takes 作成後に FK を後付け |

### なぜ正当な差分か(logic-driven)
`cuts.adopted_take_id` ↔ `takes.cut_id` の循環 FK と `cuts.parent_cut_id` の自己参照 FK は
単一 CREATE では構築不能/DB 依存で不安定なため、cuts → takes →
`2026_07_10_000500_add_foreign_keys_to_cuts_table` の 3 段に分離する。

### 揃えている不変条件(これは保証し続ける)
> 「親削除時の参照整合 (nullOnDelete) と、down() の逆順 drop (dropForeign → 各テーブル drop)」
RefreshDatabase が全 Feature テストで migration の up を暗黙検証する。

### 関連
- 実装: `database/migrations/2026_07_10_000300_create_cuts_table.php` / `..._000500_add_foreign_keys_to_cuts_table.php`

## D3 ✅ Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| カラム入力 | Item は全業務カラムを FormRequest 経由で受ける | Category の sort_order は payload から一切受けず、CategoryService (作成時末尾採番 + reorder) のみが更新 |

### なぜ正当な差分か(logic-driven)
並べ替えは「送信 id 集合 = project の Category 集合 (distinct・過不足なし)」という
集合契約で成立する。Store/Update から任意 sort_order を設定できると reorder 契約を
迂回して順序が破綻するため、専用 reorder 操作に閉じる。

### 揃えている不変条件(これは保証し続ける)
> 「create/update/reorder/delete は Project 行ロック (lockForUpdate) 下で直列化され、
> sort_order は project 内で一意な並びを保つ」
`tests/Feature/Projects/CategoryReorderTest.php` が末尾採番・集合不一致 422・並び反映を固定する。

### 関連
- 実装: `app/Services/Manual/CategoryService.php`, `app/Models/Category.php`
- 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策7

## D4 ✅ web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-current-org)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| {project} ∈ current org の guard | controller の inline guard (`resolveOrganizationProject`) のみ | `project.in-current-org` middleware (`EnsureProjectBelongsToCurrentOrganization`) を web の {project} route group に一括付与 + inline guard を二重防御として維持 |

### なぜ正当な差分か(logic-driven)
FormRequest のバリデーションは controller メソッド解決時 = inline guard より**前**に走る。
テンプレの Item 見本は DB ルールを持たないため無害だが、T001 で追加した project スコープの
DB ルール (categories.name の unique / category の exists) は、cross-org プロジェクトに対して
422 (検証エラー) と 404 の応答差分を作り、他組織のカテゴリ名・所属関係を辞書探索できる
存在オラクルになる (T001 セキュリティレビュー指摘)。middleware は FormRequest 解決より前
(SubstituteBindings の後) に走るため、順序ハザードを route group 単位で構造的に閉じる。
`Route::bind('project', ...)` の binder 化は不採用: `{project}` param は API v1
(`routes/api.php`) でも使われ、API は org を API キーから確定する (`ResolvesApiOrganization`)
ため、web セッション前提の binder はコンテキスト分岐を持ち込む。middleware なら web group に
閉じて付与でき、API 側の解決モデルに触れない。

### 揃えている不変条件(これは保証し続ける)
> 「cross-org の {project} は、FormRequest の DB ルールを含むあらゆるアプリコードより前に 404
> (403 や 422 で存在を漏らさない)」
`tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` が deny-by-default で
「web の {project} route は必ず本 middleware を持つ / API は持たない」を機械検証する。
実挙動は `CategoryCrudTest` (unique 探索 404) / `VideoManualCrudTest` (exists 探索 404) が固定する。

### 関連
- 実装: `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`, `routes/web.php`, `bootstrap/app.php`
- テンプレート側の根拠: `docs/app-integration-guide.md` §2 (URL 整合 guard 行を 2 層構成に更新済み)

## D5 ✅ Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 子リソースの書き込み | Item 見本の per-row CRUD (store/update/destroy を行単位で張る) | シナリオ (Cut 群) は `PUT /projects/{project}/manuals/{manual}/scenario` で document (steps→points ツリー) を一括保存し、サーバが 1 トランザクションで reconcile |

### なぜ正当な差分か(logic-driven)
シナリオ編集は「行追加/削除/並べ替え/手順削除で配下急所も削除」を伴う。per-row CRUD では
(a) 親子カスケード + 並べ替えの原子性が壊れる、(b) 編集途中の中間状態がサーバに漏れる。
document 保存 + 楽観ロック (`expected_version` / 409) が原子性と後勝ち破壊防止を両立する
(doc/09 §9.4 / doc/10 §10.8-2)。

### 揃えている不変条件(これは保証し続ける)
> 「保護キー不信 / 認可前 404 / relation 経由 create を document 保存でも同じ機構で維持する」
- 保護キー + サーバ導出キー (`parent_cut_id` / `adopted_take_id` / `sort_order` / `type`) は
  ネスト行にも `missing` ルールを張り送出で 422 (`UpdateScenarioRequest::nestedProtectedKeyRules` は
  `MassAssignmentProtectedKeys::all()` 由来で drift しない)
- payload の cut id は照合専用。他 manual の id 混入は 404 (存在を漏らさない)、
  階層/型変更 (step↔point) と id 重複は 422
- `{manual}` ∈ `{project}` は scopeBindings、`{project}` ∈ current org は middleware + inline guard
drift 防止テスト: `ScenarioUpdateTest` (保護キー 422 / 異物 id 404 / 409 系) と
`NestedRouteIdorDefenseTest` (`projects.manuals.scenario.update` 登録)。

### 関連
- 実装: `app/Services/Manual/ScenarioService.php`, `app/Http/Requests/Projects/UpdateScenarioRequest.php`, `app/Http/Controllers/Projects/ManualScenarioController.php`
- 設計: `devnotes/20260711-0007-scenario-editing/detailed-design.md`
