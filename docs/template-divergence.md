# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。

登録エントリ: 45 件

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
  不変条件が揃っていれば構文差は許容
- **登録は逸脱を作る変更そのものに含める**。後でまとめて書かない。まだ実在しない逸脱
  (これから作る予定) は登録しない — 予定の管理は `docs/TODO.md` の役目である
- **解消した逸脱は登録から消す**。全パスが戻ったならエントリごと、一部が戻ったなら
  そのパスを対象パス欄から削る。状態の語で「解消済み」を表さない。
  台帳の中に履歴の節を作らない (走査の対象外になる領域は回避口になるため)
- 番号 (`D<n>`) は**再利用しない**。削除しても後続を詰めない (欠番は正常)。
  他リポジトリから参照するときは `aicue:D<n>` と書く
- **登録するか迷ったら登録する**。テンプレートの実物は手元に無いので「テンプレートに無い領域への
  上積み」か「ひな形から外れた判断」かを本アプリだけで確定できないことがある。
  誤登録はエントリを削除すれば是正できるが、登録漏れには気付けない。台帳リポジトリの巡回から
  「記録されるべき乖離」として届いた指摘は、この理由で登録する側へ倒す

## 登録メタ表 (9 行ちょうど・この順序)

| 行 | 値域 |
|---|---|
| 対象パス | リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上。区切りは半角スペースとスラッシュと半角スペース。glob・絶対パス・上位への相対指定は不可。ファイルとして実在すること。**全登録の和集合で重複しないこと** |
| 業務要件起因の説明 | なぜドメイン要件のせいでテンプレートの形から外れたか (1〜2 文) |
| 揃え続ける不変条件と保証機構 | 何を揃え続け、どの機構が保証するか |
| 再判定の条件 | 何が変わったら見直すか (**恒久の登録にも必須**) |
| 決めた日 | `YYYY-MM-DD`。逸脱を最初に決めた日 (再判断で書き換えない)。未来日は不可 |
| 決めた人 | `オーナー` / `開発者` |
| 根拠 | `T<n>` (3 桁以上のゼロ埋め。`docs/TODO.md` / `docs/TODO-closed.md` の表に実在) または `devnotes/<dir>/` (ディレクトリが実在) |
| 状態 | `恒久` / `監視中` |
| 見直し期限 | `監視中` は `YYYY-MM-DD` (基準日から 400 日以内)。`恒久` は全角ダッシュ 1 文字 |

- **`恒久` も `監視中` も「今ある逸脱」を表す**。解消を意味する語は値域に無い
- `監視中` にするのは、期限付きで能動的に見直す根拠 (期限・予定時期・追跡中の事象) が
  あるときだけである。解消の条件が書けることは `監視中` の根拠にならない
  (`恒久` の登録も再判定の条件を必ず持つので、条件の有無は区別にならない)
- セルの中に縦棒を書かない (エスケープしても解釈しない)。表の区切りを使いたくなる内容は
  エントリ本文の節へ書く

## 見直し期限が切れたときの直し方 (4 通り)

1. 逸脱を解消して登録を消す
2. `恒久` へ変えて理由を足す
3. 期限を延ばして再判断の根拠を足す
4. 対象を分けて個別に判断する

**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。

## この登録簿が保証しないもの

- 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
  再利用しないことは人が守る規約である)
- **実体との突合は別の検査が持つ** —
  `tests/Architecture/TemplateDivergenceFingerprintTest.php` が指紋台帳
  (`docs/template-fingerprints.json`) と実ファイルを突き合わせ、食い違いに登録が無い場合と、
  内容が一致へ戻ったのに登録が残っている場合を落とす。
  **形式検査 (`TemplateDivergenceLedgerFormatTest`) 自身は突合を持たない**
- **突合が保証しない範囲の正本は突合検査の docblock である** (ここには写さない。
  2 か所に書くと必ず食い違う)。突合が見ない範囲 (母集合の外・ファイル内部の逸脱・
  追従遅れ・採用時債務の分類) は台帳リポジトリの巡回が引き続き担う (家系の裁定 AG-159)

## エントリ形式

```
## D1 <逸脱の要約>

| 行 | 内容 |
|---|---|
| 対象パス | `app/Example.php` |
| 業務要件起因の説明 | ... |
| 揃え続ける不変条件と保証機構 | ... |
| 再判定の条件 | ... |
| 決めた日 | 2026-01-01 |
| 決めた人 | 開発者 |
| 根拠 | T001 |
| 状態 | 恒久 |
| 見直し期限 | — |

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
```

---

## D1 Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Models/Cut.php` / `app/Models/Take.php` |
| 業務要件起因の説明 | 中核集約のスキーマをフェーズ 1 で確定させ、後続フェーズが列追加なしに振る舞いだけを足せるようにするため、route と UI を伴わないモデルを先に置いた |
| 揃え続ける不変条件と保証機構 | route を張った時点で `NestedRouteIdorDefenseTest` の登録と relation 経由解決を同時に行う。保護キーの不含は `MassAssignmentSafetyTest` が走査する |
| 再判定の条件 | Cut / Take に route と UI が付いたとき (SourceDocument と同じく本登録から外す) |
| 決めた日 | 2026-07-10 |
| 決めた人 | 開発者 |
| 根拠 | T001 |
| 状態 | 恒久 |
| 見直し期限 | — |

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

## D2 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)

| 行 | 内容 |
|---|---|
| 対象パス | `database/migrations/2026_07_10_000300_create_cuts_table.php` / `database/migrations/2026_07_10_000500_add_foreign_keys_to_cuts_table.php` |
| 業務要件起因の説明 | 循環 FK と自己参照 FK は単一の CREATE では構築できず DB 実装に依存して不安定になるため、cuts → takes → FK 後付けの 3 段に分けた |
| 揃え続ける不変条件と保証機構 | 親削除時の参照整合 (nullOnDelete) と down() の逆順 drop。`RefreshDatabase` が全 Feature テストで up を暗黙検証する |
| 再判定の条件 | cuts と takes の循環参照が解消されたとき / 採用する DB が単一 CREATE での循環 FK を扱えるようになったとき |
| 決めた日 | 2026-07-10 |
| 決めた人 | 開発者 |
| 根拠 | T001 |
| 状態 | 恒久 |
| 見直し期限 | — |

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

## D3 Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Services/Manual/CategoryService.php` / `app/Models/Category.php` |
| 業務要件起因の説明 | 並べ替えは「送信 id 集合 = project の Category 集合」という集合契約で成立するため、任意の並び順を payload から受けると契約を迂回して順序が破綻する |
| 揃え続ける不変条件と保証機構 | create / update / reorder / delete は Project 行ロック下で直列化され、sort_order は project 内で一意な並びを保つ。`CategoryReorderTest` が固定する |
| 再判定の条件 | 並べ替えを行単位の操作として外部へ開く要件が出たとき |
| 決めた日 | 2026-07-10 |
| 決めた人 | 開発者 |
| 根拠 | T001 |
| 状態 | 恒久 |
| 見直し期限 | — |

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

## D4 web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-route-org)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php` / `routes/web.php` |
| 業務要件起因の説明 | FormRequest の DB ルールは controller の inline guard より前に走り、他組織の project に対する 422 と 404 の差がカテゴリ名や所属関係を辞書探索できる存在オラクルになる |
| 揃え続ける不変条件と保証機構 | 他組織の project は FormRequest を含むあらゆるアプリコードより前に 404。`ProjectRouteCurrentOrgGuardTest` が deny-by-default で強制する |
| 再判定の条件 | 組織の解決が web と API v1 の両方で routing 層の 1 本に揃ったとき (web は URL binding へ移したが API v1 は API キー由来のままで 2 本立てのため、本登録は存続する) |
| 決めた日 | 2026-07-10 |
| 決めた人 | 開発者 |
| 根拠 | T001 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| {project} ∈ current org の guard | controller の inline guard (`resolveOrganizationProject`) のみ | `project.in-route-org` middleware (`EnsureProjectBelongsToRouteOrganization`) を web の {project} route group に一括付与 + inline guard を二重防御として維持 |

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
- 実装: `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php`, `routes/web.php`, `bootstrap/app.php`
- テンプレート側の根拠: `docs/app-integration-guide.md` §2 (URL 整合 guard 行を 2 層構成に更新済み)

## D5 Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Services/Manual/ScenarioService.php` / `app/Http/Requests/Projects/UpdateScenarioRequest.php` / `app/Http/Controllers/Projects/ManualScenarioController.php` |
| 業務要件起因の説明 | シナリオ編集は親子カスケードと並べ替えを伴うため、行単位の CRUD では原子性が壊れ、編集途中の中間状態がサーバへ漏れる |
| 揃え続ける不変条件と保証機構 | 保護キー不信 / 認可より前の 404 / relation 経由の作成を document 保存でも同じ機構で維持する。`ScenarioUpdateTest` と `NestedRouteIdorDefenseTest` が固定する |
| 再判定の条件 | シナリオを行単位で編集する要件が出たとき / 楽観ロックを別の同時編集制御へ置き換えるとき |
| 決めた日 | 2026-07-11 |
| 決めた人 | 開発者 |
| 根拠 | T002 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 子リソースの書き込み | Item 見本の per-row CRUD (store/update/destroy を行単位で張る) | シナリオ (Cut 群) は `PUT /organizations/{slug}/projects/{project}/manuals/{manual}/scenario` で document (steps→points ツリー) を一括保存し、サーバが 1 トランザクションで reconcile |

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

## D6 presigned PUT の署名対象は ChecksumSHA256 のみ (Content-Type/Length は HeadObject 照合が担う)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Services/Capture/TakeObjectStorage.php` |
| 業務要件起因の説明 | AWS SDK の presign は Content-Type と Content-Length を署名対象から外すため、置ける内容を 1 通りに固定する保証はハッシュの署名だけで成立させる必要がある |
| 揃え続ける不変条件と保証機構 | presigned URL で登録済みオブジェクトを別内容に差し替えられない。`TakeObjectStorageTest` が署名対象を、`TakeRegistrationTest` が登録時の三点照合を固定する |
| 再判定の条件 | SDK が署名対象ヘッダの扱いを変えたとき / 登録時の三点照合を外すとき |
| 決めた日 | 2026-07-11 |
| 決めた人 | 開発者 |
| 根拠 | T004 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | 設計書 (T004 detailed-design) | 実装 |
|---|---|---|
| presign 署名対象 | ContentType / ContentLength / ChecksumSHA256 を署名対象に含める | **ChecksumSHA256 のみ** (query パラメータ + SignedHeaders)。ContentType / ContentLength はコマンドに渡すが署名には含まれない |

### なぜ正当な差分か(logic-driven)
AWS PHP SDK の `SignatureV4::presign` は presigned URL 生成時に Content-Type / Content-Length を
署名対象ヘッダから除外する (SDK の仕様。ブラウザ・中継系がこれらを書き換えるため)。
セキュリティ上の要点 (概念設計 D2b「この URL で置ける内容は申告ハッシュの 1 通りに固定」) は
ChecksumSHA256 の署名だけで完全に成立する: 本文がハッシュと一致しない PUT は S3 が拒否し、
本文が固定されればサイズも一意に固定される。Content-Type は課金・検証に影響しない表示属性で、
登録時の HeadObject 三点照合 (size / content_type / checksum) が不一致を削除・拒否する。

### 揃えている不変条件(これは保証し続ける)
> 「presigned URL で登録済みオブジェクトを別内容に差し替えることはできない」
`TakeObjectStorageTest` が実 SDK で `X-Amz-SignedHeaders=host;x-amz-checksum-sha256` と
checksum query の存在を固定し、`TakeRegistrationTest` が三点照合の削除・拒否を固定する。

### 関連
- 実装: `app/Services/Capture/TakeObjectStorage.php`
- 設計: `devnotes/20260711-0345-capture-pwa/detailed-design.md` 施策3

## D7 org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留 (逐次境界テストで代替)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Services/Manual/RenderJobService.php` / `tests/Feature/Manual/RenderPreviewConcurrencyTest.php` |
| 業務要件起因の説明 | `RefreshDatabase` が検体を未コミットのトランザクション内に置くため、別プロセスからは検体が見えず、直列化の実証には非トランザクションの専用レーンが要る |
| 揃え続ける不変条件と保証機構 | 組織ごとの同時 preview 上限の検査とジョブ作成は Organization 行ロック下で行う。逐次境界は `RenderPreviewConcurrencyTest` が固定する |
| 再判定の条件 | 実プロセス並行テストの本数制約を見直すとき、または preview 上限の直列化に退行が疑われたとき |
| 決めた日 | 2026-07-11 |
| 決めた人 | 開発者 |
| 根拠 | T005 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | 設計 (T005 詳細設計 施策 4/15) | 本アプリ |
|---|---|---|
| 実証方法 | 親が Organization 行ロック保持 → 子プロセス (Symfony Process 起動の artisan) で triggerPreview → ロック待ち順序を同期ポイント付きで検証 | 逐次境界の Feature テスト (上限-1 通過 / 上限 409 / terminal 化で枠が空く / kind=render は数えない) + skip 済みプレースホルダテスト |

### なぜ正当な差分か (logic-driven)

テストスイートは `RefreshDatabase` をグローバル適用しており (AGENTS.md 実装規約)、テストの
フィクスチャは**未コミットのトランザクション内**にしか存在しない。別プロセス (subprocess) や
別 DB connection からは親テストの Organization / VideoManual 行が見えないため、子プロセスで
`triggerPreview` を実行する実証は**専用の非トランザクション pgsql 環境** (fixtures を実
コミットする別 lane) が前提になる。この lane 導入はテスト基盤の変更 (RefreshDatabase 規約の
例外化) を伴うため、本フィーチャの worktree では行わない。

### 揃えている不変条件 (これは保証し続ける)

> 「org 同時 preview 上限の検査 + job 作成は Organization 行ロック下で行い、異なる manual への
> 並行トリガーでも上限を超えない」

- 直列化の実装は `RenderJobService::triggerPreview` の `Organization ... lockForUpdate()`
  (`TicketLedgerService::reserve` の残高判定と同一手法 = 実証済みパターンの転用)
- 逐次境界は `tests/Feature/Manual/RenderPreviewConcurrencyTest.php` が固定
- ロック順 `video_manuals → organizations` はグローバル順の部分列 (docs/architecture.md
  §レンダジョブの運用契約が正本)
- subprocess 実証は同テストの skip プレースホルダとして残置 (専用 lane 導入時に実装する)

### 再判定の記録 (2026-08-23)

lctl feature `process-concurrency-test-harness` (rev `14-3117f6369f21` / 正典 v1) への追従作業
(T248) 時に再判定した。**本登録は据え置く (完了扱いにしない)**。

- 非トランザクションの検体置き場 (`tests/Support/Concurrency/OutOfTransactionFixtures.php`) は
  導入したので、本登録が挙げていた前提 (「別プロセスからは検体が見えない」) の一部は解消した
- ただし正典 v1 の要素 (6) が **実プロセス版は 1 本に絞る**ことを求めており、その 1 本は
  冪等 claim (`tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php`) へ
  割り当てた。preview 上限の実証は**逐次境界のまま据え置く**
- したがって「subprocess 実証が入った」と読まないこと。道具はできたので、
  次に実プロセス版の本数制約を見直すときに選択肢へ載る

### 関連

- 実装: `app/Services/Manual/RenderJobService.php` (triggerPreview)
- 設計: `devnotes/20260711-0549-render/detailed-design.md` 施策 4 テスト計画
- 再判定: `devnotes/20260823-0017-process-concurrency-harness-adoption/detailed-design.md` 施策 9

---

## D8 管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings からの UI 移設

| 行 | 内容 |
|---|---|
| 対象パス | `app/Enums/AdminConsoleRole.php` / `app/Enums/MemberRoleState.php` / `app/Services/Organization/OrganizationMembershipService.php` / `app/Http/Controllers/Admin/UserManagementController.php` |
| 業務要件起因の説明 | 管理メニューの役割は組織ロールと Default Project の割当の合成で表す必要があり、保存された役割にすると非正規状態を見つけて直せなくなる |
| 揃え続ける不変条件と保証機構 | 招待 token は hash-only 保存 / 権限判定は laratrust_team_id を明示 / Owner 昇格は transferOwnership のみ。`ConsoleRoleTransitionTest` と `ProjectMemberPivotWritePathTest` が固定する |
| 再判定の条件 | 役割を保存概念へ戻す要件が出たとき / 家系の裁定が役割の語彙を変えたとき |
| 決めた日 | 2026-07-11 |
| 決めた人 | オーナー |
| 根拠 | T006 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| メンバー管理 UI | `Organizations/Settings.svelte` に組織設定と同居 | 管理メニュー専用画面 `Admin/Users` (GET `/organizations/{slug}/manage/users`) へ移設。Settings は組織設定 (名称 / 2FA 方針 / API キー導線 / オーナー移譲) のみ |
| ロールの語彙 | org ロール直接指定 (`organization_admin` / `organization_member`) | **3 値遷移コマンド** (`AdminConsoleRole`: admin/editor/shooter)。org ロール + Default Project pivot への「正規状態への遷移」を 1 tx で適用 (`applyConsoleRole`)。表示は導出 5 値 (`MemberRoleState`: owner/admin/editor/shooter/unassigned) |
| 招待 | org ロールのみ | **org ロールのみ (テンプレートと同じ)**。一度 `organization_invitations.project_role` を追加して受諾時に Default Project へ pivot attach する差分を持っていたが、裁定 AG-079 (Default Project という概念自体が不要) で**列ごと撤去**し逸脱を戻した。編集者 / 撮影者は参加後にロール割当コマンドで付与する |
| settings() props | members に email / role / twoFactorStatus | members は `{id, name}` に縮小 (オーナー移譲 select 用途のみ = PII 最小化)。invitations prop は撤去 |
| ユーザー作成 | (doc/04 レガシーモック: 管理者がパスワード直接発行・平文一覧表示) | **招待一本化** (ユーザー ID → email へマッピング)。パスワードは本人設定のみ |

### なぜ正当な差分か (logic-driven)

doc/04 §4.2 (管理メニュー) + doc/02 §2.5 (管理者/一般の分離) + doc/10 §10.5
(project_admin=編集者 / project_member=撮影者) を、テンプレの org メンバー基盤の上に
「org ロール + Default Project pivot の合成」で実現するため。doc/04 レガシーモックの
直接発行・平文パスワード表示はセキュリティ不変条件 (PasswordPolicy / CipherSweet) と
衝突するため招待一本化に reconcile した。ロールを保存概念にしない (毎リクエスト導出) ことで
backfill 不要・非正規状態 (未割当 / stale pivot) の可視化と修復が可能になる。

### 揃えている不変条件 (これは保証し続ける)

> 「招待 token は hash-only 保存 / 重複は中立メッセージ / 権限判定は laratrust_team_id 明示 /
> Owner 昇格は transferOwnership のみ / PII 可視性は manageMembers 到達境界 (403)」

- Owner はメンバーのロール変更では `AdminConsoleRole` の enum 外 = 型で構造的に指定不可。
  招待では `Rule::enum(OrganizationRole)->except([Owner])` が構造的に拒否する
  (**ロール語彙の非対称**: 招待は org ロール 2 値 / メンバーのロール変更は 3 値コマンド。
  招待は「組織に入れる」だけを意味し、編集者 / 撮影者の割当は参加後の別操作である)
- pivot 書き込み経路は `OrganizationMembershipService` / `ProjectMemberController` に閉じる
  (**`ProjectMemberPivotWritePathTest`** が deny-by-default で強制)
- `/organizations/{slug}/manage/` 配下 route の auth+verified は **`ManageRouteAuthGuardTest`** が deny-by-default で強制
- drift 防止テスト: `ConsoleRoleTransitionTest` / `UserManagementPageTest` /
  `OrganizationsSettings.test.ts` (メンバー管理 UI 不在の回帰封じ)

### 関連

- 実装: `app/Enums/AdminConsoleRole.php` / `app/Enums/MemberRoleState.php` /
  `app/Services/Organization/OrganizationMembershipService.php` /
  `app/Services/Project/DefaultProjectResolver.php` /
  `app/Http/Controllers/Admin/UserManagementController.php`
- 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7) /
  `devnotes/20260807-2032-invitation-in-app-acceptance/` (役割付き招待の撤去 = 裁定 AG-079)

## D10 テストレーンのグローバルロック (worktree-local flock を残さず削除)

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/global-test-lock.sh` / `scripts/with-global-test-lock.sh` / `scripts/verify-global-test-lock.sh` / `scripts/run-test.sh` / `scripts/run-browser-test.sh` / `scripts/run-vitest.sh` |
| 業務要件起因の説明 | 実装を必ず worktree で行うため同一マシンで複数のテストレーンが同時に走るのが常態で、奪い合う資源 (PostgreSQL / CPU / ブラウザ掃除) の作用域がマシン全体である |
| 揃え続ける不変条件と保証機構 | ブロッキング取得 / 待機中の heartbeat / 再入ガード / ロック fd の非継承の 4 要件。`scripts/verify-global-test-lock.sh` と `GlobalTestLockInventoryTest` が固定する |
| 再判定の条件 | テストレーンの並走が起きなくなったとき / 家系がロックの標準形を別の形で確定したとき |
| 決めた日 | 2026-08-04 |
| 決めた人 | オーナー |
| 根拠 | T099 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート(正典 = spirux 形) | 本アプリ |
|---|---|---|
| worktree-local flock | 残す (グローバルロックとの二重ロック) | **削除する** (グローバルロックが厳密に包含するため。思考原則 3) |
| lock file 名 | `/tmp/spirux-global-test.lock` (repo 名固定) | `/tmp/global-test-lane-<uid>.d/lock` (slug 非依存 + UID 分離 + 0700 / 所有者 / symlink 検証) |
| heartbeat | 常時 30 秒 | **待機中のみ** (保持中はテストランナー自身が喋る。CI は無競合なので 1 行も出ない) |
| 再入ガード | owner-pid 一致 | **nonce 一致** (sidecar の 1 行目と env の突き合わせ。PID 再利用の穴を持たない) |
| 検証スイートの置き場 | devnotes 常駐 | **`scripts/verify-global-test-lock.sh` へ昇格 + Architecture テスト + CI ゲート** (禁止事項 1) |
| bug-hunt | (正典に記述なし) | 対象外。非干渉は保証せず best-effort の pre-flight guard のみ (残余リスクとして受容) |

### なぜ正当な差分か (logic-driven)

aicue の実装は必ず worktree で行う (AGENTS.md §worktree 運用ルール) ため、
**同一マシン上で複数のテストレーンが同時に走るのが常態**である。実在するハザードは
(H1) Browser lane の playwright 掃除が `pgrep -f` で**マシン全体**を走査して他レーンの
run-server を kill する、(H2) PostgreSQL サーバという単一共有資源の奪い合い、
(H3) devcontainer の CPU/メモリ枯渇によるタイムアウト由来の偽赤、
(H4) `flock -n` が待たずに死ぬためエージェントがリトライループを回す、の 4 つで、
**いずれも作用域はマシン (コンテナ) 全体**である。

ロックの作用域は守るべき資源の作用域と一致していなければならない。したがって
worktree-local ロックは 1 つも新しい事象を防がない (グローバルロックのスコープが
厳密に包含する)。残せば有害な `flock -n` (H4) をそのまま温存することになるため、
**同じ変更で削除する**。lock 名に slug を入れないのは `AppNameHardcodeTest` が
`scripts/` へのアプリ slug 直書きを禁じていること、および**このロックは repo を
またいで共有されて正しい** (同一マシンの PostgreSQL と CPU は repo をまたいで 1 つ) ため。

### 揃えている不変条件 (これは保証し続ける)

> 「ブロッキング取得 / 待機中の heartbeat / 再入ガード / ロック fd の非継承」の 4 要件は
> 正典と同一。差分はいずれも**同じ不変条件をより強い機構で保証する**方向である。
> 加えて aicue は「ロック保持期間 = 取得 〜 **専用プロセスグループが空になった後**」
> (親の生存期間でも直接の子の終了時点でもない) を非交渉の契約として追加している。

- 並行挙動 (層 1): `scripts/verify-global-test-lock.sh` (C01〜C24)。CI の `php` job で毎回走る
- 構造的不変条件 (層 2): `tests/Architecture/GlobalTestLockInventoryTest.php`。
  composer.json / package.json の `test*` script は明示 exemption (`test:ui` / `test:watch`) 以外
  **deny-by-default でロック経由必須**。旧 `test.lock` / `app-vitest-*` / `flock -n` / `exec` /
  lane 自前の `trap ... EXIT` / `GLOBAL_TEST_LOCK_DIR` 設定の残存を検出する
- **層 2 から層 1 を実行してはならない** (非交渉): 層 2 は `composer test` の内側 =
  ロック保持中に走るため、自己競合する

### 保証しないこと (明示)

- SIGKILL / 親のクラッシュ / コンテナ強制停止 (trap が走らない)。この場合も flock は OS が
  解放し、残留 sidecar は次の取得者がアトミックに上書きするため「ロックリーク」と
  「stale nonce による誤再入」は防ぐが、**残存子孫と次レーンの併走は防げない**
- 自ら `setsid()` / `setpgid()` で専用プロセスグループを離脱した子孫
- 規約に参加しないプロセス (bug-hunt / 未移植リポジトリ / 手打ちの `vendor/bin/pest` / 他ツール)
- 別 UID のプロセスとの H2 / H3 競合

### スコープ外の観測 (次に触る人への申し送り)

bug-hunt 自身の `.claude/bug-hunt.lock` は **worktree-local** なので、別 worktree からの
bug-hunt 同時起動は `playwright-cli kill-all` で相互破壊しうる。本設計では触っていない
(bug-hunt 側 = orchestrator と N 体の subagent worker にまたがる security-sensitive な
スクリプトの改造になり、スコープに対して過大)。同種の課題として記録に残す。

### 関連

- 実装: `scripts/global-test-lock.sh` / `scripts/with-global-test-lock.sh` /
  `scripts/verify-global-test-lock.sh` / `scripts/run-test.sh` /
  `scripts/run-browser-test.sh` / `scripts/run-vitest.sh` / `package.json`
- 設計: `devnotes/20260804-2319-global-test-lock/` (概念設計 Round 6 / 詳細設計 Round 5)
- c2c 台帳: `global-test-lock` (origin: spirux:T1109/T1110、テンプレ昇格承認済み)

---

## D11 svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/architecture/svelte-no-undef-gate.test.ts` / `eslint.config.js` |
| 業務要件起因の説明 | 同じ不変条件を守る実装がテンプレート側にあるが手元で読めないため、実装を待たずに設定の静的検査で先に固定した |
| 揃え続ける不変条件と保証機構 | resources/js 配下の全 svelte で no-undef が error / globals が実行時グローバルと完全一致 / lint 対象の全ファイルで inline の抑制が効かない |
| 再判定の条件 | laravel-claude-template の実装を読める状態になったとき (突き合わせて寄せられるなら本登録を消す) |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | T102 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| gate 実装 | `tests/js/architecture/svelte-no-undef-gate.test.ts` (実装未確認 = mirror 未取得) | 同名ファイルを **ESLint `calculateConfigForFile()` による実効設定の静的検査**として独自実装 |

### なぜ正当な差分か(logic-driven)

c2c 台帳 `atomic-design-gates` の AG-023 裁定 (2026-08-05) で
「aicue に svelte-no-undef-gate を補完する」ことは確定しているが、
laravel-claude-template の mirror が本環境に無く、テンプレ実装を読めない。
実装を待って不変条件を無防備のまま放置するより、
**同じ不変条件を別実装で先に固定する**方が実害を早く閉じられる。

### 揃えている不変条件(これは保証し続ける)

> A. 「`resources/js` 配下の**全** `.svelte` で ESLint `no-undef` が error である」
> B. 「その `languageOptions.globals` は**実行時グローバル**
>    (`globals.browser` + `eslint.config.js` の `APP_RUNTIME_GLOBALS` 明示登録) と
>    **完全一致**する — 型専用名を混ぜて no-undef を骨抜きにしない」
> C. 「**lint 対象の全ファイル**
>    (= `pnpm lint` = `eslint resources/js` の範囲 × `eslint.config.js` が `files` で
>    対象にしている全拡張子: `.svelte` / `.js` / `.mjs` / `.cjs` / `.ts` / `.jsx` / `.tsx`) で
>    `linterOptions.noInlineConfig` が true であり、inline の eslint-disable が効かない」

`tests/js/architecture/svelte-no-undef-gate.test.ts` が
ESLint 公開 API `calculateConfigForFile()` で実効設定を解決し、
A/B を全 `.svelte` に、C を lint 対象全ファイルに適用して検査する。
走査 0 件でも fail する (空振り防止)。
検査ロジックは純関数 (`assertSvelteNoUndefConfig` / `assertNoInlineConfig`) に切り出し、
正負のコントロールで検出器の実効性を固定している
(ESLint の flat config マージ規則そのものは試験対象にしない)。

**運用契約 1 (noInlineConfig 体制)**: ルールを黙らせる唯一の手段は
`eslint.config.js` の file-scoped override。override を認めるのは
(a) 抑制対象が具体的な 1 ファイル (または明示列挙) に閉じている
(b) なぜ安全かがコード側コメントで説明されている
(c) config 側に理由と再検討条件が書かれている — の 3 条件をすべて満たすときだけ。

**運用契約 2 (宣言と検査範囲の一致)**: `pnpm lint` の対象を広げる
(引数ディレクトリを増やす / 新しい拡張子を扱う) ときは、本 gate の
`LINT_TARGET_EXTENSIONS` と走査ルートも**同一 PR で**広げること。
宣言 (config コメント) と検査範囲が乖離すると「守っているつもりの穴」ができる。

### 収束条件

laravel-claude-template の mirror が取得できた時点でテンプレ実装と突き合わせ、
実装を寄せられるなら本エントリを解消する。

### 関連

- 実装: `tests/js/architecture/svelte-no-undef-gate.test.ts`, `eslint.config.js`
- 設計: `devnotes/20260805-0101-frontend-baseline-gates/detailed-design.md` 施策 4
- 台帳: c2c `atomic-design-gates` AG-023 (2026-08-05 裁定), `eslint-svelte-ts-baseline`

---

## D12 ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Support/Seo/SeoManager.php` / `app/Support/Seo/SeoRenderer.php` / `resources/js/lib/document-title.ts` / `config/seo.php` |
| 業務要件起因の説明 | ページ題名の一次情報は controller と config が持ち、ページ側に helper を挟む層が無い。同じ契約を移植すると一次情報が 2 か所に割れ、フルロードと SPA 遷移で題名が食い違う |
| 揃え続ける不変条件と保証機構 | 題名の正本はサーバの `SeoManager::resolveDocumentTitle` ただ 1 つで、フルロードと SPA 遷移で一致する。`DocumentTitleCoverageTest` と `svelte-head-no-title.test.ts` が固定する |
| 再判定の条件 | ページ側が題名の一次情報を持つ要件が出たとき / description に SPA 遷移後の追従が要るようになったとき |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260805-0101-architecture-gate-followup/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| `<title>` の供給 | ページ側が JS helper 経由で宣言する契約 (helper 経由必須を frontend gate で強制) | サーバが単一 SoT。`SeoManager::resolveDocumentTitle()` が Blade `<title>` と Inertia 共有 prop `title` の両方へ同じ文字列を流す |
| `<meta name="description">` | ページ側 helper の守備範囲 | サーバのみ (`config('seo.default_description')` → `SeoMeta::$description` → `SeoRenderer::render()`)。認証配下 (`renderPrivate`) は**意図的に出さない** |
| frontend gate の役割 | 「helper を経由していること」を強制 | 「クライアントに第二 SoT を作らせない」を強制 (`<svelte:head>` の `<title>` / `<meta name="description">` を禁止) |

### なぜ正当な差分か(logic-driven)

本アプリは **SEO ヘッドをサーバ描画に一本化している** (`app/Support/Seo/`)。
クローラが読む正本はサーバが描画した `<head>` であり、title / canonical / og / JSON-LD は
すべて `config/seo.php` を起点に組み立てる。この構造では、ページ固有名の供給点は
`config('seo.app_titles')` (route 既定) と `SeoManager::setPrivateTitle()` (controller の
動的上書き) の 2 つで完結し、**JS helper を挟む層が存在しない**。

テンプレートの「helper 経由必須」契約は「ページ側が title の一次情報を持つ」前提の設計だが、
本アプリでは title の一次情報は **controller / config** が持つ。同じ契約を移植すると
一次情報が 2 箇所に分かれ、**フルロードと SPA 遷移でタイトルが食い違う**という
テンプレートが防ごうとしたまさにその破綻を招く。よって helper 契約は不採用とし、
同じ不変条件を「第二 SoT の禁止」という別の機構で保証する。

### 揃えている不変条件(これは保証し続ける)

> `<title>` の SoT はサーバ (`SeoManager::resolveDocumentTitle`) ただ 1 つであり、
> **フルロードと SPA 遷移で一致する** (共有 prop `title` + `resources/js/lib/document-title.ts`)。
> `<meta name="description">` は **サーバが生成する初回 HTML のみを SoT とし**、
> クライアントから第二 SoT や重複タグを作らない。

**title と description で射程が違う点に注意**: `HandleInertiaRequests::share()` が
共有するのは `title` のみで、description に SPA 同期経路は無い。description の読み手は
クローラであり、クローラが読むのは初回 HTML なので、SPA 遷移後の追従は保証しない
(必要になったら共有 prop + クライアント反映機構 + テストを別途設計する)。

どの機構でカバーするか:

- **`DocumentTitleCoverageTest`** (Architecture): Inertia を render する GET named route が
  必ずページ固有名を持つことを deny-by-default で強制する (未網羅は fail。
  action を静的解決できない route は理由付き allowlist が必須)
- **`tests/js/architecture/svelte-head-no-title.test.ts`**: `resources/js/pages/**/*.svelte` の
  `<svelte:head>` に `<title>` / `<meta name="description">` を書かせない
  (`<svelte:head>` 自体は preload hint 等のため許可)
- **`tests/Feature/Seo/SeoManagerTest.php`**: 解決優先順位と各 route の固有 title を仕様固定

### 関連

- 実装: `app/Support/Seo/SeoManager.php` / `app/Support/Seo/SeoRenderer.php` /
  `app/View/Composers/SeoComposer.php` / `app/Http/Middleware/HandleInertiaRequests.php` /
  `resources/js/lib/document-title.ts` / `config/seo.php`
- 設計: `devnotes/20260805-0101-architecture-gate-followup/`
- c2c 台帳: `gate-document-title-coverage` / `page-title-frontend-contract`

---

## D13 SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Services/Auth/SocialAccountService.php` |
| 業務要件起因の説明 | SSO とパスキーを第一級のログイン手段として扱うため、「ログイン手段が 0 になる操作を止める」不変条件が `hasPassword()` の真実性に依存する |
| 揃え続ける不変条件と保証機構 | `User::hasPassword()` は password 経路の可否を fail-closed で判定する。`SocialAuthTest` / `RecentAuthTest` / `LoginMethodInventoryTest` が固定する |
| 再判定の条件 | 既存ユーザーの遡及是正を判別できる材料 (password 変更の監査証跡) が全ユーザーぶん揃ったとき |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260805-1244-auth-method-and-passkey/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| SSO 登録時の `users.password` | `Str::password(32)` をハッシュ化して保存 | **保存しない (null のまま)** |
| `User::hasPassword()` の意味 | SSO-only ユーザーでも常に true (docblock 自身が「テンプレート標準では常に true」と明記) | 「password 経路が実際に使えるか」を返す |
| 既存データの扱い | — | **遡及是正しない (前方修正のみ)** |

### なぜ正当な差分か(logic-driven)

本アプリは password 以外のログイン手段 (SSO / パスキー) を第一級に扱い、
「**ログイン手段が 0 になる操作を止める**」ことを不変条件にしている
(`EnsureLoginMethodRemains` / `LoginMethodInventory`。docs/auth-security-mechanisms.md §6)。
この不変条件は `hasPassword()` が真実を返すことに依存する。

phantom password (ランダム値) が入っていると:

1. `LoginMethodInventory` が SSO-only ユーザーにも `password` を数え、
   **唯一のパスキーを削除できてしまう** (guard が形骸化する)。
2. recent-auth の `passwordSet` が true になり、SSO-only ユーザーの再認証モーダルが
   **入力しても必ず失敗するパスワード欄**を出す (詰み画面)。

`users.password` は migration が nullable で作られており
(`0001_01_01_000000_create_users_table.php`)、`UserFactory::ssoOnly()` も
`password => null` を前提にしている。つまりスキーマとテスト補助は既に「null を許す」側で、
`Str::password(32)` だけが取り残されていた。

### 射程 (既知の制約として残すもの)

**前方修正のみ**。本変更**以前**に SSO 登録されたユーザーの phantom password はそのまま残り、
そのユーザーに限り上記 1 / 2 の誤差が続く。遡及移行 (既存 SSO ユーザーの password を null 化) は
**「password を先に登録してから SSO を連携したユーザーの実パスワードを消す」**危険があり、
`password_changed` の監査証跡が無い時代のユーザーを機械的に判別できないため行わない。

判別材料を今後蓄積するため、`UpdateUserPassword` から
`SecurityEventType::PasswordChanged` を記録するようにした
(enum に存在しながら記録経路が無かった。`/reset-password` 経路は
`Illuminate\Auth\Events\PasswordReset` 経由で既に記録済み)。

### 揃えている不変条件(これは保証し続ける)

> 「`User::hasPassword()` は password 経路の可否を **fail-closed** で判定する」

- `tests/Feature/Auth/SocialAuthTest.php`: SSO register 後の `password` が null /
  `hasPassword()` が false / `email_verified_at` は従来どおり非 null (T105 との相互作用の回帰)
- `tests/Feature/Auth/RecentAuthTest.php`: SSO 登録直後の `/recent-auth/status` が
  `passwordSet: false` / `canSatisfy: true` (再SSO が satisfier)
- `tests/Feature/Auth/LoginMethodInventoryTest.php`: `ssoOnly()` ユーザーの手段集合に
  `password` が含まれない

### 関連

- 実装: `app/Services/Auth/SocialAccountService.php` / `app/Actions/Fortify/UpdateUserPassword.php` /
  `app/Services/Auth/LoginMethodInventory.php`
- 設計: `devnotes/20260805-1244-auth-method-and-passkey/` (施策 2)

---

## D14 実行した route の記録をアプリ側の観測器で採る (退避と正規化と route 名解決の 3 段を置かない)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` / `.claude/skills/app-bug-hunt/coverage/test_correlate.py` |
| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する。併せて、割当列が複数値になった目録を照合器が取り違えずに読む |
| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う。割当セルの分解は `test_correlate.py` が値域の両方向で固定する |
| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき / 家系の正典が割当列の分解を実装したとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | T164 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 「どの操作を叩けたか」の採取 | ブラウザの通信履歴を退避 → 正規化器 → artisan コマンドで route 名解決、の 3 段 | **serve のプロセス内で middleware が 1 要求 1 行を追記**する (`BughuntExecutedRouteMiddleware`) |
| 採取の起動 | 走行中の LLM (探索エージェント) が退避コマンドを呼ぶ | 起動時に `provision` が env で仕込み、以後は無条件 |
| 遮断された要求の扱い | 通信履歴なので 302/403 も「叩いた」側に残り、後段で除外しきれない | 遮断 middleware より**内側**に置いてあるため、そもそも記録に現れない |
| 主入力が欠けたとき | 照合器が「全 in_scope を未実行 candidate」として出力し 0 で終わる | **終了コード 3 で落ちる** (worklist を出さない) |
| 目録の割当列の読み方 (理由 2) | セルをそのままキーにするので `S3 S7` の行は `S3` の finding と一致しない | **セルを検証してから分解**し、各 story へ索引する (単一値の挙動は不変。正典に無い上乗せ = 家系への還流候補) |

### なぜ正当な差分か(logic-driven)

操作到達カバレッジの出力は「次に何を叩くべきか」という作業指示であり、
**記録が採れていないこと**と**本当に叩けていないこと**を取り違えると、
一覧そのものが嘘になる (全機構が未実行に見える)。3 段方式はこの取り違えを
2 か所で作っていた:

1. **採取の起動が LLM に依存する**。退避コマンドを呼び忘れた走行は、
   記録が空のまま「全部未実行」として成功終了する。
2. **通信履歴は遮断された要求も含む**。認証・課金ゲート・step-up 再認証で
   跳ねた 302 は「叩いた」ように見えるが、controller には到達していない。
   route 名の再解決は URL からの逆引きなので、この差を後段では復元できない。

アプリ側の観測器は、web グループの**末尾** (priority list の鎖の最後) に置くことで
「ここに到達した = 遮断 middleware をすべて通過した」という機械的事実を得る。
route 名は `$request->route()->getName()` でその場で確定するので逆引きも要らない。
起動は `scripts/bug-hunt-shard.sh provision` が env で仕込むため LLM の手順に依存しない。

### 揃えている不変条件(これは保証し続ける)

> 「**主入力が揃わない走行は成功にしない**」

- `scripts/bug-hunt-shard.sh provision` は疎通確認の要求が実際に記録されたことを
  同期点として確認し、記録されなければ**走行前に**落ちる (`assert_executed_capture_wired`)
- `coverage/build_executed.py` は失敗マーカー / ファイル欠落 / 壊れた行 / 別 run の混入 /
  **名前付き route の観測行が 0** のいずれでも**終了コード 3** で落ち、`executed.json` を
  書き出さない (`route_name: null` の行しか無い shard もここで落ちる)
- `coverage/correlate.py` は `--executed` 未指定 / 形が契約外 / run_id 不一致 /
  shard 宣言と実測の食い違い / 観測行 0 のいずれでも**終了コード 3** で落ち、
  未実行 worklist を出力しない
- 記録器が遮断 middleware より内側に居ることは
  `tests/Architecture/BughuntExecutedRouteOrderingTest.php` が deny-by-default で固定する
  (短絡しうる middleware の分類は `tests/Support/Routing/MiddlewareShortCircuitInventory.php`)
- 記録器が既定 no-op であること (env 既定 false + production 除外) と ok/blocked の写像は
  `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` が実 HTTP 要求で固定する

理由 2 (割当列の分解) が揃え続けるのは次である。

> 「**目録の割当列に載ったカードは、すべてその finding の索引先になる**」

- 割当セルの値域 (`S{n}` を番号の昇順で半角空白 1 つ区切り、または `-`) は
  書き出し側 (`scripts/bug-hunt-inventory.py`) が自分の出力を突き合わせ、
  読み手 (`correlate.py`) が `fullmatch` で強制する。**寛容に正規化しない**
- 契約外のセル (前後空白 / 連続空白 / 降順 / 重複 / 未知の綴り) は照合器が
  **終了コード 3** で落ちる (目録の手編集と生成器の故障を黙って進めない)
- 両側の定数が一致することと、生成側が書くセルを読み手が同じ値へ分解することは
  `scripts/tests/test_bug_hunt_inventory.py` が同一ケースの列挙で固定する

### 保証しないもの (誇張しない)

- **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
  未実行側へ倒れる (過小申告の方向)
- **部分欠測は検出しない**。分かるのは「名前付き route の行が 1 件も無い」「別 run が混ざった」
  「失敗マーカーが残せた」まで
- **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない
- 割当セルに書かれたカードが**実在するか**は照合器では見ない (目録は生成物であり、
  割当列は実在するカードの前付けからしか作られない。手編集で紛れ込んだ id は
  目録の byte 一致検査が落とす)

### 関連

- 実装: `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `config/bughunt.php` /
  `bootstrap/app.php` / `scripts/bug-hunt-shard.sh` /
  `.claude/skills/app-bug-hunt/coverage/build_executed.py` /
  `.claude/skills/app-bug-hunt/coverage/correlate.py`
- 設計: `devnotes/20260815-1113-bughunt-route-capture-failclosed/`

---

## D15 strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/StrictTypesDeclarationGateTest.php` / `tests/Support/StrictTypesDeclarationScanner.php` / `tests/Support/StrictTypesRuntimeProbe.php` |
| 業務要件起因の説明 | 容量 (bytes) やチケット枚数のように数値と文字列の取り違えがそのまま業務の誤りになる領域を持つため、走査域のどこか 1 枚だけが緩い状態を残さない |
| 揃え続ける不変条件と保証機構 | 宣言を欠く PHP ファイルが新しく増えない。走査域はテンプレートが保証する app/ を包含し、判定器は実測照合器と突き合わせて fail-open を 0 件に固定する |
| 再判定の条件 | どうしても宣言できないファイルが現れたとき (なし崩しに許可一覧を足さず設計レビューを通す) |
| 決めた日 | 2026-08-15 |
| 決めた人 | オーナー |
| 根拠 | T167 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| テスト名 | `StrictTypesBaselineInvariantTest` | `StrictTypesDeclarationGateTest` |
| 走査域 | `app/` のファイル走査 | **git 追跡下の `*.php` − `*.blade.php`** (実測 1543 本) |
| 未宣言一覧 (baseline) | 常に空を契約として保持 | **持たない** (免除機構そのものが無い) |
| 判定器 | 構造判定 (値は数値リテラル 1 の完全一致) | 構造判定 + **実測照合器との突き合わせ** |

### なぜ正当な差分か(logic-driven)

走査域を `app/` に限ると、`config/` `database/` `bootstrap/` `public/` の未宣言 28 本が
規約の外に残り続ける。本アプリは容量予約 (bytes) やチケット枚数のように、数値と文字列の
取り違えがそのまま容量・金額の誤りになる領域を持つため、「どこか 1 枚だけ緩い」状態を
残さないことに意味がある。実測ではこの 28 本は 1 行の追加だけで解消でき、うち 25 本は
PHPStan level 10 の解析対象でもあるので、**追加した宣言の副作用は静的解析で機械的に確認できる**。

未宣言一覧 (baseline) を持たないのは、導入時点の未宣言 32 本を同一変更で是正して 0 件から
始めるためである。空の登録簿とそれを双方向比較する仕組みは 1 件も違反を守らないまま
複雑さだけを足すことになる (「今必要なものだけ作る」)。既存の
`QueueDispatchAtomicityInventoryTest` が採っているのと同じ形である。

判定器が実測照合器 (`StrictTypesRuntimeProbe`) と突き合わせるのは、「判定器は宣言済みと
言うのに実際は厳密化されない」という**逆向きの乖離 = fail-open** を機械的に 0 件へ固定する
ためである。判定器は実効性の下界であり、安全側の乖離 (実効だが受理しない形) だけを許す。

### 揃えている不変条件(これは保証し続ける)

> 「宣言を欠く PHP ファイルが新しく増えない」

- 走査域が広いので、テンプレートが保証する `app/` の範囲は**包含している**
- 空の baseline と本アプリの「登録簿なし」は、守っている集合が同じ (未宣言 0 件)
- テンプレート取り込みで `StrictTypesBaselineInvariantTest` が入ってきた場合は、
  **2 本立てにせず本 gate へ統合する** (同じ事実を 2 箇所で宣言しない)
- どうしても宣言できないファイルが将来出た場合も、なし崩しに allow-list を足さない。
  設計レビューを通してから機構を新設する

### 保証しないもの (誇張しない)

- `artisan` など拡張子が `.php` でない PHP ファイル / 未追跡 (git add 前) のファイルは見ない
  (gate が守る境界は commit / CI である)
- `*.blade.php` は見ない。テンプレートであり PHP ソースファイルではないため、免除ではなく対象外
- 宣言の有無だけを見る。型の緩さそのもの (level 10 で検出される型不一致) は PHPStan の担当
- 実効ではあるが正準形でない書き方 (`01` / `0x1` / `declare(ticks=1, strict_types=1)` 等) は
  **受理しない** (安全側の乖離)。**冒頭の正準形より後ろに `strict_types` を含む declare が
  ある形も受理しない** (現行 PHP の実効は strict のままだが、表記を揃える規約であり、
  「後に書いた方が勝つ」へ仕様が変わったときの fail-open も同時に塞ぐ)
- `php artisan vendor:publish` 直後に宣言が失われることは防げない (検出はする)

### 関連

- 実装: `tests/Architecture/StrictTypesDeclarationGateTest.php` /
  `tests/Support/StrictTypesDeclarationScanner.php` /
  `tests/Support/StrictTypesRuntimeProbe.php` / `tests/Support/TrackedPhpSourceFiles.php`
- 自己検査: `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` /
  `tests/Unit/Architecture/TrackedPhpSourceFilesTest.php`
- 設計: `devnotes/20260815-1534-strict-types-baseline-gate/`
- テンプレート側の根拠: `tests/Architecture/StrictTypesBaselineInvariantTest.php`
  (家系の裁定 AG-010 (2026-08-05)「テンプレートへ還流し家系の標準装備とする」)

---

## D16 prompt の trusted 変数の入口を作らない (窓口の引数は untrusted だけ)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Support/Llm/PromptDefense.php` / `app/Support/Llm/GuardedPrompt.php` / `config/llm-defense.php` |
| 業務要件起因の説明 | prompt の変数はすべて作業手順書 (SOP) 由来の untrusted で、固定値や列挙型の値を prompt へ渡す面が 1 つも無いため、trusted の入口を作る対象が存在しない |
| 揃え続ける不変条件と保証機構 | prompt へ入る実行時の文字列はすべて窓口で無害化とタグ境界化を受ける。`PromptDefenseWindowGateTest` の変数集合の突き合わせが双方向で固定する |
| 再判定の条件 | trusted 変数を足すとき (窓口の入口・値をリテラルに限る字句検査・目録の 3 つを同じ変更で足す) |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | T169 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 窓口の引数 | `untrusted` (生 string の配列) と `trusted` (リテラル / クラス定数 / enum case の値に限る配列) の 2 系統 | **`untrusted` だけ**。`trusted` の引数そのものを持たない |
| 窓口 gate の変数突き合わせ | untrusted ∪ trusted ∪ 合言葉 == YAML 変数集合 の**三点一致** | untrusted ∪ 合言葉 == YAML 変数集合 の**二点一致** |
| trusted の値をリテラルへ限る字句 gate | あり | **無い** (限る対象が存在しないため) |

### なぜ正当な差分か (logic-driven)

本アプリの prompt YAML 4 本の変数は `text` / `extracted` / `decomposition` の 3 つで、
いずれも SOP 由来の untrusted である。固定値・enum・locale を prompt へ渡す面は 1 つも無い。

入口が無ければ「trusted に混ぜて素通しする」という迂回は**構造的に存在しない**。
実体の無い入口と、それを守るための字句 gate と目録を先に作るのは、
今必要でないものを作ることになる (思考原則 2)。テンプレート側の 3 系統は
**提供元として正しく**、本アプリが縮めているのは母集団であって保証ではない。

### 揃えている不変条件 (これは保証し続ける)

> 「prompt へ入る実行時の文字列は、すべて窓口で無害化・タグ境界化される」

1. prompt YAML の変数は**すべて untrusted か合言葉のいずれか**である
   (`PromptDefenseWindowGateTest` の変数集合突き合わせが双方向で固定する)
2. trusted の入口は存在しない (窓口の public メソッドの引数に無い)
3. 窓口は合言葉の変数名 `llm_canary` の**上書きを拒否**し、untrusted の変数名を
   `/\A[a-z][a-z0-9_]*\z/` に限る。**予約 namespace は作らない** — 現時点で予約したい名前が
   `llm_canary` 以外に無く、実装より強い保証を文書に書かないため
4. **trusted 変数を足す PR は、次の 3 つを同じ PR で足す**:
   (a) 窓口の入口 (`trusted` 引数)、(b) 値をリテラル / クラス定数 / enum case に限る字句 gate、
   (c) 目録。1 つでも欠けたら「実行時に決まる値が trusted 側へ紛れ込む」経路が開く。
   窓口 gate の変数突き合わせの失敗メッセージにもこの義務を書いてある

### 関連

- 実装: `app/Support/Llm/PromptDefense.php` / `app/Support/Llm/GuardedPrompt.php` /
  `config/llm-defense.php`
- gate: `tests/Architecture/PromptDefenseWindowGateTest.php` /
  `tests/Architecture/LlmDefenseConfigGateTest.php` /
  `tests/Architecture/PromptYamlContractTest.php`
- 設計: `devnotes/20260815-1537-prompt-injection-defense/`
- 契約の正本: `docs/architecture.md` §LLM プロンプト防御の窓口方式

---

## D17 滞留回収の共通基盤を、閾値の置き場所と `recover()` の引数で正典から外す

| 行 | 内容 |
|---|---|
| 対象パス | `app/Contracts/Recovery/StuckWorkStream.php` / `app/Services/Recovery/StuckWorkRecoverySweeper.php` / `app/Services/Recovery/StuckWorkStreamRegistry.php` / `app/Console/Commands/Operations/RecoverStuckWorkCommand.php` |
| 業務要件起因の説明 | 「ジョブの制限時間 < 再試行間隔 < 予約の有効期限 ≤ 滞留の閾値」の序列を既存の検査 2 本が固定しており、閾値を回収側の設定へ移すと序列の情報源が 2 つに割れる |
| 揃え続ける不変条件と保証機構 | 回収は必ず行を取り直し、候補列挙と同じ述語を行ロック下で再評価してから作用する。`StuckWorkRecoveryInventoryTest` が系列の集合一致を deny-by-default で強制する |
| 再判定の条件 | 家系の正典が閾値の置き場所を変えたとき / 遡及の下限や自走をやめる上限が要る事象が実際に起きたとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | オーナー |
| 根拠 | T171 |
| 状態 | 恒久 |
| 見直し期限 | — |

家系の裁定 AG-083 標準形 v1 (追従元 laravel-claude-template:T076) の共通基盤へ寄せ替えるにあたり、
**3 点だけ**正典と形を変えた。骨格 (系列の契約 / 走査と作用の分離 / 既定は実行しない入口 /
deny-by-default の目録 / 撤去済み参照の gate) はそのまま採っている。

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 滞留の閾値の置き場所 | 回収側の設定 (`config/recovery.php` + `RecoveryThresholds`) に集約 | 各ドメインの設定 (`config/manual.php` / `config/billing.php` / `config/capture.php`) に据え置き |
| `recover()` の引数 | 主キーだけ | 主キー + **掃引開始時刻** |
| 遡及の下限 (look-back) と自走をやめる上限 (give-up) | 系列ごとに設定で持つ | **持たない** |

### なぜ正当な差分か (logic-driven)

1. **閾値**: 本アプリは「ジョブの制限時間 < 再試行間隔 < 予約の有効期限 ≤ 滞留の閾値」という
   序列を既存の Architecture テスト 2 本 (`AnalysisTimeBudgetInvariantTest` /
   `QueuedJobLeaseInventoryTest`) が固定している。回収側の設定へ移すと**序列の情報源が 2 つに
   割れ**、片方だけ変えても検査が通る窓が開く。閾値はドメインの時間予算の一部である
2. **掃引開始時刻**: 候補列挙と行ロック下の再評価で現在時刻がずれると、境界ちょうどの行を
   取りこぼす (列挙では候補、再評価では未超過、の食い違い)。渡すのは**行の内容ではない**ので、
   正典が狙う「行を取り直して述語を再評価させる」強制は壊れない
3. **遡及の下限と自走の上限**: 遡及の下限は「古すぎる滞留を永久に回収しない」無音の穴を作る。
   自走をやめる上限に当たる機構は Stripe の通知側が既に持っている
   (`attempts >= MAX_PROCESSING_ATTEMPTS` で `recovery_pending` へ移す)。
   今必要でないものを先回りして作らない (思考原則 2)

### 揃えている不変条件 (これは保証し続ける)

> 「回収は必ず行を取り直し、候補列挙と同じ述語を行ロック下で再評価してから作用する」

1. `recover()` の引数は**主キーと時刻だけ**で、行・モデル・述語の判定結果は渡さない
2. 候補列挙と再評価は**同じ 1 つの述語**を共有する (各ドメインの Service の private に集約)
3. 共通側が持つ上限は「1 掃引で扱う件数」だけで、それは**対象を失敗として確定する条件ではない**
   (上限に達しても未処理分は終わらせず、次の掃引と人手の判断に委ねる)
4. 閾値はドメインの設定に置き、序列を固定する既存テストを緑に保つ

### 関連

- 実装: `app/Contracts/Recovery/StuckWorkStream.php` /
  `app/Services/Recovery/StuckWorkRecoverySweeper.php` /
  `app/Services/Recovery/StuckWorkStreamRegistry.php` /
  `app/Console/Commands/Operations/RecoverStuckWorkCommand.php` /
  `app/Services/Recovery/Streams/`
- gate: `tests/Architecture/StuckWorkRecoveryInventoryTest.php` /
  `tests/Architecture/RetiredRecoveryReferenceGateTest.php`
- 設計: `devnotes/20260815-1538-stuck-job-recovery/`
- 契約の正本: `docs/architecture.md` §滞留回収の共通基盤

---

## D18 hook の起動子を「起動先の検証 + 終了コードの写像器」にする

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` / `scripts/code-review-graph-update-hook.sh` |
| 業務要件起因の説明 | hook の故障がセッションの操作を止めてはならず、起動先の検証は起動された後では手遅れなので起動子にしか置けない |
| 揃え続ける不変条件と保証機構 | 配線は常設で起動子は絶対パス、排他はスクリプト内にあり、配線は台帳テストで完全一致 pin される。`ClaudeHooksWiringTest` が固定する |
| 再判定の条件 | Claude Code が hook の終了コードの扱いを変えたとき / 家系が起動子の形を確定したとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | T172 |
| 状態 | 恒久 |
| 見直し期限 | — |

常設 hook 配線 (家系の feature `claude-hooks-wiring`) を取り込むにあたり、**起動子の形だけ**
テンプレートと変えた。配線されている hook の本数・対象・スクリプトの置き場所は正典どおりである。

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 起動子 | `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` (スクリプトを直に起動) | `/bin/bash -p -c '…'` で起動先を検証してから起動し、終了コードを写像する |
| hook の終了コードの扱い | スクリプトの終了コードがそのまま harness へ届く | PreToolUse は **97 だけ**を 2 (ブロック) へ写し、それ以外はすべて 0 に畳む |
| 環境からのシェル関数 | 内側へ継承される | `-p` (privileged mode) で遮断する |

### なぜ正当な差分か (logic-driven)

1. **hook の故障がセッションを止めてはならない**。bash は構文エラーでも 2 を返し、
   PreToolUse の 2 は Bash ツールをブロックする。テンプレートの形では hook スクリプトの
   1 文字のタイプミスが、そのセッションの Bash 操作を全滅させうる。
   写像器を**設定ファイル側**に置くと、スクリプトの退行から独立して「拒否できるのは
   意図した 97 だけ」を保てる。
2. **起動先の検証は起動子にしか置けない**。`CLAUDE_PROJECT_DIR` が相対値・`..` 入り・
   `scripts/` が symlink・起動先が symlink のいずれかなら、内側を起動しないのが正しい。
   これはスクリプトが起動された後では手遅れである。
3. **シェル関数の注入**は、判定を組み込みだけで書いても環境から乗っ取れる。
   遮断は起動の瞬間 (`-p`) にしかできない。

検査はすべて bash の組み込み (`[` / パラメータ展開) で行い、外部コマンドを 1 つも使わない。

### 揃えている不変条件 (これは保証し続ける)

> 「配線は常設で、起動子は絶対パスで、排他はスクリプト内にあり、配線は台帳テストで
> 完全一致 pin される」

1. `.claude/settings.json` は git 追跡下の配線の正本で、見本ファイル方式は復活させない
   (`ClaudeHooksWiringTest` の S02 / S08)
2. 起動子は `/bin/bash` の絶対パスで始まる (S06b)
3. 索引更新の排他は hook スクリプト内の `flock` が持つ (B16 / B17)
4. hook 種別 / matcher / 起動コマンド文字列 / timeout / トップレベルキーを完全一致で pin する
   (S03〜S06)。97 → 2 の写像そのものも実起動で固定する (B41〜B50)

### 関連

- 実装: `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` /
  `scripts/code-review-graph-update-hook.sh`
- gate: `tests/Architecture/ClaudeHooksWiringTest.php`
- 設計: `devnotes/20260815-1539-claude-hooks-settings-wiring/`
- 規約の正本: `AGENTS.md` §常設 hook 配線

---

## D19 経路キャッシュ起動での middleware 後付けは「走らせない」側の契約を維持する (専用の実行点クラスへは移行しない)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Support/Http/RouteMiddlewareBinder.php` / `app/Support/Http/RouteThrottleBinder.php` |
| 業務要件起因の説明 | 本リポジトリにデプロイ定義の実体が無く、存在しない基盤のための preflight を先回りして作らない規約があるため、正典の専用実行点へ移行する利益が今は無い |
| 揃え続ける不変条件と保証機構 | 後付けした保護は実効の経路に必ず載る / 後付けの入口は 2 つの binder に限られる / 経路名が消えたら起動を止める。`PostBootRouteMutationInventoryTest` と `RouteCacheBakedProtectionTest` が固定する |
| 再判定の条件 | デプロイ定義が入ったとき / route:cache を実行する記述が入ったとき / 家系の機能台帳の裁定が変わったとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260815-2100-route-cache-middleware-attach/ |
| 状態 | 恒久 |
| 見直し期限 | — |

家系の正典 (機能台帳 `route-cache-safe-middleware-attach` の v1) は、経路の一覧が組み上がった後に
走らせたい処理を**専用の実行点クラス 1 つ**へ集約し、経路キャッシュ起動でも後付けを効かせる形である。
本アプリはそこへ**移行しない**。この判断を逸脱として登録する。

| 観点 | 家系の正典 / テンプレート | 本アプリ |
|---|---|---|
| 実行点 | 専用クラス 1 つ (`AfterRoutesLoaded` 相当) へ集約 | 2 つの binder が各々 `Application::booted()` を使う |
| 経路キャッシュ起動での契約 | 容器の `routes` 束縛の張り替えを捕まえ、読み込まれた直後に後付けを走らせる | **走らせない**。実効は `route:cache` 生成時の焼き込み |
| 入口の絞り込み | 素の起動完了フックの直呼びを走査で禁止 | `PostBootRouteMutationInventoryTest` が入口を 2 binder に絞る (deny-by-default) |
| 経路キャッシュ起動での実証 | 別プロセスで起動して後付けの残存を確認 | 同一プロセスで「焼き込みの入力」と「欠落時の剥落」を確認 (別プロセス起動は導入しない) |

### なぜ正当な差分か (logic-driven)

1. **前提が今は成立している**。本リポジトリにデプロイ定義の実体は無く、`route:cache` を実行する
   記述も追跡下に 1 件も無い。ただし言えるのは「**いま定めた走査条件で検出される発生経路が無い**」
   までである (「発生確率がゼロ」とも「管理下に発生経路が無い」とも書かない。走査条件が拾わない
   書き方は `tests/Architecture/RouteCacheExemptionPremiseTest.php` の説明が列挙する)。
2. **毎デプロイ再生成の機械強制は今は採れない**。`AGENTS.md` の運用要件 (route:cache) が
   「存在しない基盤のための preflight 機構を先回りして作らないこと (思考原則 2)」と明記しており、
   デプロイ基盤そのものが無い段階で preflight を作るのは、その規約に正面から反する。
3. **正典の形は Laravel 13 では 4 つの問題を同時に解く必要がある** — 容器の `routes` 束縛の
   張り替えの捕捉 / その束縛がまだ無いときに張り替えが発火しない穴の手当て / 経路一覧の実体ごとの
   冪等 / cached 起動で起動を止めると `route:list` も `route:clear` も落ちて復旧手段を失う問題への
   例外設計。実証には別プロセスで起動する検査基盤も要る。
   **正典を採る利益は「運用要件を 1 つ消せること」であり、その運用要件が効く相手 (デプロイ基盤) が
   まだ無い**。基盤を作る PR で実物の手順と突き合わせて設計する方が確実である。
4. **これは保留ではなく明示の判断である**。期限で自然解消せず、**前提が崩れたときに解消する**。

### 揃えている不変条件 (これは保証し続ける)

> 「後付けした保護は、実効の経路に必ず載る」
> 「後付けの入口は 2 つの binder に限られる」
> 「経路名が消えたら起動を止める (無防備なまま公開しない)」

担い手は次のとおりで、新設したのは下 3 行だけである (既存目録と二重管理にしない)。

| 不変条件 | 担い手 |
|---|---|
| どの route に何を付けるべきか (対象と件数) | `ThrottleCoverageInventoryTest` / `RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` / `PasskeyRouteProtectionTest` |
| 後付けの入口が 2 binder に限られること | `PostBootRouteMutationInventoryTest` |
| 後付けの契約 (cached では resolver すら呼ばない / 経路が引けなければ起動を止める / 冪等 / 列の順) | `RouteMiddlewareBinderTest` / `RouteThrottleBinderTest` |
| 実際に付いた middleware 列が、直列化の準備を通しても焼き込みの入力へ欠落なく移ること | `tests/Feature/Security/RouteCacheBakedProtectionTest.php` (検査 1) |
| 焼き込みが欠けた経路一覧では保護が実際に外れること | `tests/Feature/Security/RouteCacheBakedProtectionTest.php` (検査 2) |
| この逸脱を許す前提 (直接書かれた `route:cache` / 空白だけを挟む `artisan optimize` が無いこと。デプロイ定義の不在は早期の気づき) | `tests/Architecture/RouteCacheExemptionPremiseTest.php` |

**保証範囲を誇張しない**: `RouteCacheBakedProtectionTest` が固定するのは同一プロセス内の
「直列化の準備 → compile」までで、**cached 起動そのものの再現ではない**。
`RouteCacheExemptionPremiseTest` が見るのは追跡下の文字列までで、動的に組み立てた実行・
オプションを挟む書き方・リポジトリの外にある手順には沈黙する。
説明として `route:cache` の語を持つ既存ファイルは**件数を完全一致で pin** して扱い
(増減のどちらでも赤になる)、走査から丸ごと外れているのは**同テスト自身の 1 件だけ**である
(自分が検出したい語を負のコントロールの入力として持つため。その 1 ファイルの中は見えない)。
また**デプロイ定義の検出は網羅を主張しない** (新しい CI 基盤やファイル名は拾えない)。
主前提を固定するのは `route:cache` 側の検査である。

### 再検討の条件 (解消条件)

- リポジトリに**デプロイ定義**の実体が入ったとき
- `route:cache` (または `artisan optimize`) を実行する記述が入ったとき
- 家系の機能台帳の裁定が変わったとき

前の 2 つは `RouteCacheExemptionPremiseTest` の検査条件と**同じ言葉**で書いてある。
どちらかで赤くなったら、正典の形への移行か毎デプロイ再生成の機械強制かを**同じ PR で**決めること。

### 関連

- 実装: `app/Support/Http/RouteMiddlewareBinder.php` / `app/Support/Http/RouteThrottleBinder.php`
- gate: `tests/Architecture/RouteCacheExemptionPremiseTest.php` /
  `tests/Feature/Security/RouteCacheBakedProtectionTest.php` /
  `tests/Architecture/PostBootRouteMutationInventoryTest.php`
- 設計: `devnotes/20260815-2100-route-cache-middleware-attach/`
- 契約の正本: `docs/app-integration-guide.md` §7c

---

## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` / `scripts/tests/test_bug_hunt_inventory.py` / `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` / `.claude/skills/app-bug-hunt/SKILL.md` / `scripts/README.md` |
| 業務要件起因の説明 | 機能カタログの id 列が所見記録の語彙の正本であり、Python ツールを標準ライブラリだけで書く規約から注釈は TOML になる |
| 揃え続ける不変条件と保証機構 | 目録は実装と注釈から再生成でき、ずれていたら CI が落ちる。`BugHuntInventoryCheckInvariantTest` と生成器の自己テストが 4 段の判定を固定する |
| 再判定の条件 | 家系の正典が id 列を持つ形へ変わったとき / Python に依存を足す裁定が出たとき / 中間 JSON を読む道具が家系に現れたとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | T176 |
| 状態 | 恒久 |
| 見直し期限 | — |

家系の正典 (機能台帳 `bughunt-inventory-generation` の t1) は、bug-hunt の分母 (画面一覧 /
操作一覧 / 機能カタログ) を実装から生成し、人が書く注釈ファイルと段階的なドリフト検査で守る形である。
本アプリはこの**方式そのものは採る**が、次の 3 点で正典と形が違うので登録する。

| 観点 | 家系の正典 / テンプレート | 本アプリ |
|---|---|---|
| 機能カタログ (`capability-catalog.md`) | 生成物。3 列は 機能 / 対応する画面 / 対応する操作 | **生成しない**。3 列は `id` / `機能 (actor→outcome)` / `代表機構 (route name)` を維持し、参照整合だけを検査する |
| 注釈ファイル | `inventory/annotations.yaml` | **`inventory/annotations.toml`** |
| 中間成果物 | `inventory/inventory.json` をコミットする | **持たない** (生成・検査の実行中にだけ存在する) |
| 割当の正本 | カードの前付け (`covers_screens` / `covers_operations`) | **同じ** (2026-08-23 に注釈の `story` を撤去して一本化した。以前は注釈側が正本だった) |
| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method (GET / HEAD / OPTIONS) の web route** (`kind` の語彙が `画面` / `JSON` で違うため `kind` に依存させない) |
| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで** (機能カタログが継承宣言の欄 `no_route` / `coverage_surface` / `covered_via` を持たないため、分母・被覆は見ない) |

### なぜ正当な差分か (logic-driven)

1. **id 列は所見記録の語彙正本である**。`.claude/skills/app-bug-hunt/ledger/findings.schema.json` の
   必須項目 `capability_tag` は機能カタログの id を値に取る。正典の 3 列には id 列が無く、
   寄せると語彙の供給元が消えて `unknown` / `unmapped` の判定基準ごと壊れる。
   また「機能 ↔ 画面 / 操作」の対応は注釈側が route ごとに持つので、カタログにも書くと
   同じ対応関係が 2 か所に載る (家系が AG-044 でやめた形)。カタログ本体は
   「機構を利用者価値で束ねた overlay であり MECE ではない」と自ら宣言しており、
   実装から導けない = 生成対象にしても保守量は減らない。
2. **注釈が TOML なのは Python の依存規約の帰結である**。`AGENTS.md` §bug-hunt が
   Python ツールを標準ライブラリのみと定めており、本環境に PyYAML は無い (実測)。
   `tomllib` は標準ライブラリにあるので、YAML を採ると依存追加か自前パーサのどちらかが要る
   (どちらも「自前機構の前に公式作法を確認する」に反する)。
3. **中間 JSON に読み手がいない**。下流の照合器 (`coverage/correlate.py`) が読むのは
   `operations.md` の name 列であって中間 JSON ではない。コミットするとドリフト面が 1 つ増えるだけで、
   守るものが無い。

### 揃えている不変条件 (これは保証し続ける)

> 「目録は実装と注釈から再生成でき、ずれていたら CI が落ちる」

| 不変条件 | 担い手 |
|---|---|
| 抽出が成功し、宣言した抽出条件で走り、母集合が 0 件でないこと (段 1) | `scripts/bug-hunt-inventory.py` (exit 2) / `scripts/tests/test_bug_hunt_inventory.py` |
| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない。割当を注釈へ書き戻す道は未知の項目として塞ぐ |
| 対象内 (区分が `外` でない) の route が 1 枚以上のカードの `covers_*` に載っていること (段 2) | 同上 (exit 3)。載せた route の実在・欄の意味・対象外でないことも見る |
| 生成物が再生成の結果と byte 一致すること (段 3) | 同上 (exit 3)。手編集と再生成の忘れをまとめて捕まえる |
| 機能カタログの代表機構が実在し、id が重複しないこと (段 4) | 同上 (exit 3) |
| カードが挙げる capability が実在すること (段 4) | 同上 (exit 3)。**被覆漏れは見ない** |
| 検査シェルが判定を持たず、終了コード 0 / 2 / 3 を実際に返すこと | `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (sandbox 実走) |
| 生成器の自己テストが `composer test` の下で実走すること | `tests/Architecture/BughuntInventoryToolSelfTest.php` |
| 抽出コマンドが事実だけを書き出すこと (面の判定を持たない) | `tests/Feature/Bughunt/InventoryScanCommandTest.php` |

**保証範囲を誇張しない**: 見るのは `web` group を宣言した面だけである。
沈黙するのは「`web` group を**宣言していない**面」であり、実測では機械向け API (`api/`) /
Filament の管理画面 (`/admin`) / MCP / Stripe の webhook がこれに当たる。
「webhook 一般に沈黙する」わけではない — `web` を宣言している `webhooks.ses` は
操作表に載り、区分 `外` として理由付きで見える。面として除くのは先頭セグメントの
`oauth` と `livewire-{hash}` の 2 つだけで、それ以外で `web` を宣言した route は
必ず目録に入り注釈を要求される。
注釈の**内容**の妥当性 (割当が適切か) は見ない。画面題名の欠落も検出しない。
機能カタログの網羅性も見ない (代表機構の実在と id の一意性まで)。
**割当が痩せたこと**も検出できない — 見るのは「1 枚以上のカードに載っていること」だけなので、
ある route が `S3 S7` から `S3` へ減っても緑のままである (PR レビューの義務)。
目録の母集合は T164 の記録器が観測しうる route の**部分集合**であり、両者は一致しない。

**対象パスに運用文書 2 本を含める理由 (範囲を誇張しない)**: `.claude/skills/app-bug-hunt/SKILL.md` と
`scripts/README.md` は本エントリで**目録の生成方式に関わる記述だけ**を説明する
(どこを直して再生成するか / 割当の正本はどこか)。両ファイルには本エントリと無関係な
テンプレート差分も含まれうるが、それらは本エントリが説明したことにはならない。
2026-08-23 に採用時債務一覧から本エントリへ移した (割当の正本を一本化したのに、
運用手順が廃止済みの入力先へ誘導したままになるのを避けるため)。

### 再検討の条件 (解消条件)

- 家系の正典が id 列を持つ形へ変わったとき (機能カタログの生成を採り直す)
- 本リポジトリの Python に依存を足す裁定が出たとき (注釈を YAML へ寄せる)
- 中間 JSON を読む道具が家系に現れたとき
- 機能カタログが継承宣言の欄を持つ形になったとき (`covers_capabilities` の被覆判定を採り直す)

### 関連

- 実装: `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` /
  `.claude/skills/app-bug-hunt/inventory/`
- gate: `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` /
  `tests/Architecture/BughuntInventoryToolSelfTest.php` /
  `tests/Feature/Bughunt/InventoryScanCommandTest.php`
- 設計: `devnotes/20260815-2100-bughunt-inventory-generator/`

---

## D21 bug-hunt の自己検証を CI の専用ステップでなく composer test の配線に載せる

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/BughuntSelfTestExecutionTest.php` |
| 業務要件起因の説明 | bug-hunt の自己検証はどこからも自動実行されておらず二段防御の片側が眠っていた。CI の責務を同期検査に限る裁定があるため、専用ステップではなくテストの配線へ載せた |
| 揃え続ける不変条件と保証機構 | 自己検証が毎回のテスト実行で実走し、実資源に触れない。隔離境界はテスト側が握り、専用マーカーのある空き地しか受け付けない |
| 再判定の条件 | CI に bug-hunt 専用のステップを設ける判断が出たとき / 自己検証の実行時間がテスト実行の妨げになったとき |
| 決めた日 | 2026-08-10 |
| 決めた人 | 開発者 |
| 根拠 | T142 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | 家系の他リポジトリ / テンプレート | 本アプリ |
|---|---|---|
| 自己検証の実行点 | CI の専用ステップ | `composer test` が走らせる Architecture テスト (`BughuntSelfTestExecutionTest`) |
| 隔離境界の持ち主 | 自己検証スクリプト自身 | テスト側が「捨ててよい空き地」を作って渡す (専用マーカー必須・借り物は消さない) |

### なぜ正当な差分か (logic-driven)

bug-hunt の自己検証は guard と資源導出と環境変数の隔離という**実行時の挙動**を見る側で、
静的構造を見る Architecture テストと二段防御をなす。ところが導入時はどこからも自動実行されておらず、
片側が眠ったまま緑になっていた。本アプリの CI は同期検査に責務を限る裁定を採っており
(依存脆弱性の gate と同じ考え方)、専用ステップを増やすと「CI でしか走らない検査」が生まれて
手元の `composer test` と CI で守られる範囲が食い違う。実行点を 1 つにするほうが、
実行され続けることを保証しやすい。

### 揃えている不変条件 (これは保証し続ける)

> 「自己検証は毎回のテスト実行で実走し、実資源には触れない」

- 隔離境界はテスト側が握る。自己検証は外から渡された空き地を使い、未指定のときだけ自分で作る
- 外から渡せるのは専用マーカーを置いた空き地だけで、リポジトリのルートを渡す事故を構造的に防ぐ
- 借り物の空き地は削除しない (作った側が片付ける)

### 関連

- 実装: `tests/Architecture/BughuntSelfTestExecutionTest.php` / `scripts/bug-hunt-shard.sh`
- 設計: `devnotes/20260810-0251-bughunt-harness-hardening/`

---

## D22 退会は利用者の行を消さず凍結で表す (猶予 30 日)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` / `app/Http/Controllers/Settings/AccountDeletionRequestController.php` |
| 業務要件起因の説明 | 退会後も課金記録の保持義務が残るため利用者の行を消せない。猶予中の取消をそのまま元の状態へ戻せる形にする必要もある |
| 揃え続ける不変条件と保証機構 | 凍結は deny-by-default で auth と verified の group 全体に掛かり、開けるのは救済経路だけである。退会の予約と取消は監査記録に残る |
| 再判定の条件 | 猶予期間や保持義務の前提が変わったとき / 家系が退会の標準形を確定したとき |
| 決めた日 | 2026-08-09 |
| 決めた人 | オーナー |
| 根拠 | devnotes/20260809-0908-account-deletion-grace/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 退会の表現 | 相当する仕組みを持たない (退会の入口も猶予も凍結も同梱していない) | 利用者の行の生死を変えない**凍結** (論理削除も使わない) + 30 日の猶予 |
| 猶予中の到達性 | — | `auth` + `verified` の group 全体に凍結 middleware を付け、取消などの救済経路だけを許可一覧で開ける |

### なぜ正当な差分か (logic-driven)

退会しても課金記録には保持義務が残る (D23) ため、利用者の行を消す形にすると
「消えた利用者に紐づく課金記録」という参照の切れた状態を作ってしまう。
凍結なら猶予中の取消がそのまま元の状態に戻り、保持義務のある記録も参照を保ったまま残る。

**登録する理由 (テンプレートに相当物が無いのに登録する根拠)**: 判定の出所は本アプリではなく
**台帳リポジトリの巡回 (2026-08-12) が「記録されるべき乖離」として受信箱へ届けた判定**である。
決定はオーナーで、**ひな形ではなく家系の別リポジトリの先例に揃えた**形であり、
統一形式の不変条件 i14 (登録簿の守備範囲には「ひな形が家系の先例から外れた判断」も含む) に当たる。
テンプレートに無い領域への上積みか、ひな形から外れた判断かの線引きは、テンプレートの実物が
手元に無いので本アプリだけでは確定できない。過剰検出寄りに倒す原則 (誤登録はエントリを
削除すれば是正できるが、登録漏れは気付けない) に従い、登録する側へ倒している。

### 揃えている不変条件 (これは保証し続ける)

> 「凍結は deny-by-default で、開けるのは救済経路だけである」

- 凍結 middleware は route ごとの付け忘れが起きないよう group 全体に付ける
- 取消 (救済) には再認証を課さない。詰みを作らないための例外であり、目録に理由付きで載る
- 退会の予約と取消は監査記録に残る

### 関連

- 実装: `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` /
  `app/Http/Controllers/Settings/AccountDeletionRequestController.php`
- 設計: `devnotes/20260809-0908-account-deletion-grace/` (PR-B)

---

## D23 課金記録は退会後も 7 年保持し、対象と年数を 1 か所で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `app/Enums/Billing/BillingRetentionTarget.php` / `app/Enums/Billing/BillingRetentionExclusion.php` / `app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php` / `app/Services/Billing/Retention/AbstractBillingRetentionPurger.php` / `app/Services/Billing/Retention/BillingCheckoutSessionPurger.php` / `app/Services/Billing/Retention/StripeWebhookEventPurger.php` / `app/Services/Billing/Retention/SubscriptionItemPurger.php` / `app/Services/Billing/Retention/SubscriptionPurger.php` / `app/Services/Billing/Retention/TicketAutoRechargeAttemptPurger.php` / `app/Services/Billing/Retention/TicketCheckoutSessionPurger.php` / `app/Services/Billing/Retention/TicketLedgerEntryPurger.php` |
| 業務要件起因の説明 | 課金記録の保持義務は退会より寿命が長く、利用者データと同じ掃除の対象にできない。年数を各所に書くと必ず食い違う |
| 揃え続ける不変条件と保証機構 | 保持年数の正本は 1 か所で、掃除の対象は宣言された表だけである。`BillingRetentionConfigSingleSourceTest` と `BillingRetentionTargetInventoryTest` が固定する |
| 再判定の条件 | 保持義務の年数が変わったとき / 家系が保持期間の標準形を確定したとき |
| 決めた日 | 2026-08-09 |
| 決めた人 | オーナー |
| 根拠 | devnotes/20260809-0908-account-deletion-grace/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 課金記録の寿命 | 相当する仕組みを持たない (保持年数の宣言も掃除器も同梱していない) | 保持年数の正本を列挙型 1 か所に置き、表ごとの掃除を登録した掃除器だけが行う |
| 利用者データとの関係 | — | 退会で消えるものと 7 年残るものを分け、残す側は掃除の対象から除外する理由を宣言する |

### なぜ正当な差分か (logic-driven)

課金記録の保持義務は退会よりも寿命が長く、利用者データと同じ掃除の対象にできない。
年数を各所に書くと必ず食い違うため、年数と対象表の対応を 1 か所に集約し、
掃除の実処理はその宣言からしか作れないようにした。

**登録する理由 (テンプレートに相当物が無いのに登録する根拠)**: D22 と同じで、判定の出所は
**台帳リポジトリの巡回 (2026-08-12) が受信箱へ届けた判定**であり、決定はオーナー
(保持 7 年) で家系の先例に揃えた形である (統一形式の不変条件 i14)。
D22 と別の登録にしているのは、対象パスが交わらず解消の条件も別だからである
(退会の表現と、課金記録の寿命は別の判断)。

### 揃えている不変条件 (これは保証し続ける)

> 「保持年数の正本は 1 か所で、掃除の対象は宣言された表だけである」

- `BillingRetentionConfigSingleSourceTest` が単一出典を固定する
- `BillingRetentionTargetInventoryTest` が対象表と掃除器の対応を deny-by-default で強制する
- 表を足したときの分類は `RetentionTableClassificationTest` が実スキーマと突き合わせる

### 関連

- 実装: `app/Enums/Billing/BillingRetentionTarget.php` /
  `app/Services/Billing/Retention/` 配下の掃除器
- 設計: `devnotes/20260809-0908-account-deletion-grace/` (PR-C)

---

## D24 SSO の driver 解決点を自前クラス 1 つへ切り出す

| 行 | 内容 |
|---|---|
| 対象パス | `app/Services/Auth/SocialiteDriverResolver.php` |
| 業務要件起因の説明 | bug-hunt の走行が実際の外部 ID 基盤へ出ていくのを塞ぐ必要があるが、Socialite の Factory を丸ごと差し替えると本番経路の解決まで置き換わる |
| 揃え続ける不変条件と保証機構 | SSO の driver 解決は 1 クラスに集約され、他クラスからの直呼びは登録も免除もできない。`ExternalSeamInventoryTest` と `ExternalFakeWiringInvariantTest` が固定する |
| 再判定の条件 | 家系の外部到達点の標準形が解決点の形を定めたとき / Socialite が差し替え点を公式に提供したとき |
| 決めた日 | 2026-08-11 |
| 決めた人 | 開発者 |
| 根拠 | T153 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| SSO の driver 取得 | 呼び出し側が Socialite の facade から直接取る | `SocialiteDriverResolver` 1 クラスに集約し、他クラスからの直呼びを目録が拒否する |
| 非本番での差し替え | — | 解決点クラスを container で差し替える (Socialite の Factory そのものは差し替えない) |

### なぜ正当な差分か (logic-driven)

bug-hunt の走行が実際の外部 ID 基盤へ遷移すると、探索が本アプリの外へ出て戻れなくなる。
これを塞ぐには driver の解決点が要るが、Socialite の Factory を丸ごと差し替えると
本番経路の解決まで置き換わり、差し替えの影響範囲が読めなくなる。
薄い解決点を 1 つ置くほうが、差し替えの範囲を非本番だけに閉じられる。

### 揃えている不変条件 (これは保証し続ける)

> 「SSO の driver 解決は 1 クラスに集約され、他クラスからの直呼びは登録も免除もできない」

- `ExternalSeamInventoryTest` が解決点を名指しで固定する (他クラスの直呼びは目録に載せられない)
- 非本番の差し替えは `ExternalFakeWiringInvariantTest` が配線ごと固定する
- 差し替えを許す環境は testing と bug-hunt だけで、local は含めない

### 関連

- 実装: `app/Services/Auth/SocialiteDriverResolver.php` /
  `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php`
- 設計: `devnotes/20260811-1736-bughunt-sso-egress/`

---

## D25 退避終端 gate の母集団と目録を静的 gate のファイル内に置く

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/JobDeferralTerminationGateTest.php` |
| 業務要件起因の説明 | キューに載るクラスの母集団を決める実装を `tests/Support/QueuedJobPopulation.php` ただ 1 本へ集約する既存の判断があり、正典の**母集団を数える実装**まで持ち込むと母集団の実装が 2 本になって片方だけ更新される食い違いが復活する。正典が置き場所を `tests/Pest.php` にした理由 (並列実行で他ファイルの関数を参照すると未定義関数になる) は同一ファイル内定義には掛からないため、静的 gate のファイル内へ集約すれば置き場所の制約と母集団の一本化を両立できる |
| 揃え続ける不変条件と保証機構 | 母集団と全数申告の完全一致を既定拒否で取り、退避を持たないという申告を毎回の走査で裏取りする。同ファイルの E1 から E4 が固定する |
| 再判定の条件 | 母集団の正本が `QueuedJobPopulation` から移ったとき / 並列実行で同一ファイル内の関数定義が解決されなくなったとき / 移植元が目録の置き場所を変えたとき |
| 決めた日 | 2026-08-17 |
| 決めた人 | 開発者 |
| 根拠 | T215 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 母集団と目録の置き場所 | `tests/Pest.php` の 2 関数 | 静的 gate のファイル内の 2 関数 |
| 母集団の供給元 | `appShouldQueueClasses()` と `appDispatchableVendorJobs()` (後者は移植元でも空) | `Tests\Support\QueuedJobPopulation::shouldQueueClasses()` (既存の唯一の正本) |
| 守る不変条件 | 母集団と申告の完全一致 と 走査による裏取り | 同じ |
| 保証機構 | E1 から E4 | 同じ |

### なぜ正当な差分か (logic-driven)

本アプリは「キューに載るクラスの母集団」を決める実装を `Tests\Support\QueuedJobPopulation`
ただ 1 本へ集約済みである。同クラスの説明が書いているとおり、これは
`QueuedJobLeaseInventoryTest` と `JobExecutionDedupInventoryTest` が同じ母集団を見ることを
構造で保証するための集約であり、2 実装に分かれていると片方だけ更新される食い違いが起きる。
正典の**母集団を数える実装**まで `tests/Pest.php` 版のとおりそのまま持ち込むと、
母集団を数える実装が本アプリの中に 2 本できる。既に潰した食い違いをわざわざ復活させる
変更なので採らない。

正典が `tests/Pest.php` を選んだ理由は「並列実行では Architecture テストが別プロセスへ
振り分けられうるため、他のテストファイルで定義した関数を参照すると未定義関数で落ちる」ことである。
**この理由は同一ファイル内の定義には掛からない**。本アプリには先例があり、
`tests/Architecture/JobExecutionDedupInventoryTest.php` は目録関数を自ファイル内で定義して
並列実行の下で緑になっている。

ここでの「ジョブ」は**キューの payload に載るもの全般**を指す (メールと通知を含む)。
どれも同じキューに載り同じ試行回数の勘定を受けるので、退避の有無を問う対象としては同格である。
帰結として、メールや通知に退避する job middleware を付けると本 gate が赤くなる。
それは誤検出ではなく設計どおりの動作である。

### 揃えている不変条件 (これは保証し続ける)

> 「母集団と全数申告の完全一致を既定拒否で取り、退避を持たないという申告を毎回の走査で裏取りする」

- E1 が母集団と目録の集合一致を両方向で固定する (登録漏れも stale も落ちる)
- E2 が母集団 0 件 (検出器の故障) を落とす
- E3 が申告の値域と理由の非空を固定する
- E4 が申告を信じず、走査根 (クラス自身と祖先と trait の推移閉包) に退避マーカーが
  0 件であることを毎回裏取りする

### 保証しないもの

- **`app/` の外 (vendor が登録するキュークラス) は母集団に入らない**。移植元の拡張点
  `appDispatchableVendorJobs()` も空配列を返すので実効は同じだが、
  「vendor のキュークラスまで見ている」とは読めない
- 検出器そのものの限界 (委譲・動的呼び出し・自作 job middleware・factory 経由・
  投入サイトでの後付け) は移植元と同じで、限界ごと移植している。
  正本は `docs/architecture.md` §退避を正常系に持つジョブの終端方式

### 関連

- 実装: `tests/Architecture/JobDeferralTerminationGateTest.php` /
  `tests/Support/Queue/JobDeferralScanner.php` / `tests/Support/Queue/JobDeferralContract.php`
- 設計: `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/`

---

## D26 パスキー設定の検査を「設定の評価時」ではなく「本番起動時の関門」で行う

| 行 | 内容 |
|---|---|
| 対象パス | `app/Support/PasskeyConfigValidator.php` / `app/Support/PasskeyOriginCanonicalizer.php` |
| 業務要件起因の説明 | 撮影 PWA の主要ログイン導線がパスキーであり、設定の評価時に例外を投げる正典の形では開発環境とテストレーンまで起動不能にできる。本アプリは受け入れホストと接続元の信頼設定で「本番起動時に落とす」関門を先に確立しており、パスキーもそこへ相乗りする |
| 揃え続ける不変条件と保証機構 | 正規形の定義は 1 か所 (`PasskeyOriginCanonicalizer`) で、宣言側は正規形へ寄せ、検証側は正規形からの逸脱を落とす。本番で書式・相互整合・導出鍵の宣言が不正なら起動しない (`ProductionEnvGuardTest` / `PasskeyConfigValidatorTest` / `PasskeyOriginCanonicalizerTest` / `PasskeyOriginDeclarationTest`) |
| 再判定の条件 | 正典が検査の置き場所を変えたとき、または本番以外でも設定事故を早期に検出したい要求が出たとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260815-1111-passkey-config-hardening/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 設定が正規形でなかったときの落とし方 | 設定の評価時にその場で例外を投げる | 本番起動時の関門 (`ProductionEnvGuard`) で落とす |
| 正規形へ寄せる場所 | 設定の宣言時 | 設定の宣言時 (ここは正典と同じ) |

### なぜ正当な差分か (logic-driven)

設定ファイルは**すべての環境で評価される**。評価時に例外を投げる形にすると、
開発環境とテストレーンまで起動不能にできる。撮影 PWA の主要ログイン導線がパスキーである以上、
設定事故を本番前に止める必要はあるが、その代償として開発が止まる形は取れない。
本アプリは接続元の信頼設定 (TRUSTED_PROXIES) と受け入れホストで
「本番起動時に落とす」関門を先に確立しており、パスキーもそこへ相乗りするほうが
落とし方の置き場所が 1 つで済む。

### 揃えている不変条件 (これは保証し続ける)

> 「正規形の定義は 1 か所にあり、宣言側は正規形へ寄せ、検証側は正規形からの逸脱を落とす」

- 正規形の定義は `PasskeyOriginCanonicalizer` ただ 1 つで、宣言側と検証側の両方が参照する
- 本番で書式・相互整合・導出鍵の宣言が不正なら起動しない (`ProductionEnvGuardTest`)
- 宣言経路が正規形へ寄せることは宣言経路そのものの再評価で固定する
  (`PasskeyOriginDeclarationTest`)

### 保証しないもの

- 検査が走るのは `Features::passkeys()` が有効な**本番起動時だけ**である
  (キルスイッチを切った環境には設定を要求しない)
- 開発環境・テストレーンでは設定事故が起動時には表面化しない (これがこの逸脱の代償である)

### 関連

- 実装: `app/Support/PasskeyConfigValidator.php` / `app/Support/PasskeyOriginCanonicalizer.php`
- 設計: `devnotes/20260815-1111-passkey-config-hardening/` /
  `devnotes/20260817-1309-todo-t216-passkey-hardening-completion/`

---

## D27 コード到達の対象外の宣言を、route 名の接頭辞を持たないコード軸だけの形にする

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` / `.claude/skills/app-bug-hunt/coverage/out_of_scope.py` / `.claude/skills/app-bug-hunt/coverage-audit.md` |
| 業務要件起因の説明 | 探索の分母は route 単位の注釈が正本であり、コード到達の未到達は `app/` のパス単位でしか説明できないため、対象外の宣言を軸で 2 本に分ける |
| 揃え続ける不変条件と保証機構 | 対象外は理由と代替検証と実在する参照を伴う。増減は承認済み範囲のスナップショットとの完全一致で必ずレビューに出る。`BughuntCoverageToolSelfTest` から `test_out_of_scope` が実走する |
| 再判定の条件 | 家系の正典が route 名接頭辞を必須にしたとき / 注釈側へ代替検証の欄が入ったとき / 集計器が宣言を読む形になったとき |
| 決めた日 | 2026-08-17 |
| 決めた人 | 開発者 |
| 根拠 | T220 |
| 状態 | 恒久 |
| 見直し期限 | — |

家系の参照実装は対象外の宣言に **route 名の接頭辞**を持たせ、目録のドリフト検査をそこから
導出している。本アプリは route 単位の判断を `inventory/annotations.toml` (区分 外) が持つため、
宣言を**コード到達の軸だけ**に絞る。

| 観点 | 家系の参照実装 | 本アプリ |
|---|---|---|
| 宣言が持つ軸 | route 名の接頭辞と `app/` のパスの両方 | `app/` のパスだけ |
| 目録のドリフト検査の対象外 | 宣言から導出する | 注釈 TOML の区分 外 が正本 (D20) |
| 代替検証の実在 | 散文で書く | `verification_refs` として機械が実在を見る |

### なぜ正当な差分か (logic-driven)

1. **route 単位の対象外は既に別の正本を持っている**。本アプリの目録は注釈 TOML から生成する形
   (D20) で、区分 外 の行は 30 文字以上の理由付きで目録に見える。宣言にも route 名接頭辞を
   置くと、同じ判断が 2 か所に載って必ず食い違う。
2. **導出先が無い**。参照実装が接頭辞を持つのは、検査スクリプトが宣言から選択の正規表現を
   導出するためである。本アプリの検査は生成器側で判定するので、導出する相手がいない。
   使われない出力を作らない (思考原則 2)。
3. **代替検証は実在を機械で見るほうが腐りにくい**。散文で「別のテストが見ている」と書くと、
   その参照先が消えても気付けない。パスとして宣言すれば、少なくとも参照先が丸ごと消えたことは
   検出できる。

### 揃えている不変条件 (これは保証し続ける)

> 「対象外は理由と代替検証と実在する参照を伴い、増減は必ずレビューに出る」

| 不変条件 | 担い手 |
|---|---|
| 理由と代替検証が中身のある文であること (30 文字以上・無内容な値でない) | `test_out_of_scope` (必須キー / 型 / 文の中身) |
| 代替検証が実在し、追跡下にあり、自己言及でないこと | 同上 (実在・追跡・循環参照) |
| 対象パスが実在し、幹や包含や正規形の迂回で無制限に広がらないこと | 同上 (幹の禁止・antichain・層 1 の正規形) |
| 対象外の静かな増減が起きないこと | 同上 (承認済み範囲のスナップショットとの完全一致) |
| 宣言不正が fail-closed であること (標準出力を汚さない) | 同上 (CLI の終了コード契約を実プロセスで検査) |
| これらが `composer test` から実走すること | `tests/Architecture/BughuntCoverageToolSelfTest.php` |

### 保証しないもの

- **集計器との自動照合は持たない**。`merge_pcov.py` は宣言を読まないので、
  まだ通れていないものの一覧と宣言の突合は人が読んで行う。
- 機械が見るのは宣言の形式と参照先の実在までで、代替検証がその面を本当に守っているか
  (テストの意味的十分性) は人のレビューの担当である。
- 古い断定の再混入を見る走査 (`test_naming_no_stale`) の射程はスキル配下の `.md` / `.py` で、
  `app/` のコメントは見ない。

### 関連

- 実装: `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` /
  `.claude/skills/app-bug-hunt/coverage/out_of_scope.py` /
  `.claude/skills/app-bug-hunt/coverage-audit.md`
- gate: `tests/Architecture/BughuntCoverageToolSelfTest.php` /
  `.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py`
- 設計: `devnotes/20260818-0243-bughunt-coverage-audit-doc/`

---

## D28 デザイントークンの生成 CSS 検査を、値の写しを持たず実 app.css も通す形で実装する

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/styles/tokens.test.ts` / `tests/js/styles/design-system-docs.test.ts` |
| 業務要件起因の説明 | 撮影 PWA は現場作業者が屋外のスマホで使う面であり、状態色と本文が読めることが業務の前提になる。テンプレート家系の正典実装は期待値を検査側の表に literal で持つが、本アプリは DESIGN.md を唯一の正本と定めており、値の写しを 3 か所へ増やすと正本の一元化と衝突する |
| 揃え続ける不変条件と保証機構 | inventory に登録された DESIGN.md 対応の色・角丸・文字組が Tailwind の生成 CSS に期待する値で現れ、色と角丸の utility は対応する変数を参照し、typography の utility は期待する宣言を過不足なく持つこと。および運用契約の文書が検査ファイルの実体と同期していること。`tests/js/styles/tokens.test.ts` (密閉の層 = 母集団の全件 / 経路の層 = 実 app.css のアンカー) と `tests/js/styles/design-system-docs.test.ts` (双方向の集合一致) が保証する |
| 再判定の条件 | 正典が literal 期待値表の保持そのものを不変条件として明文化したとき。または Tailwind の生成 CSS の構造 (テーマ層の置き場所や hover の入れ子の形) が変わって構文木の走査で読めなくなったとき。selector の綴りそのものは検査の完全一致対象ではないので再判定の契機にしない |
| 決めた日 | 2026-08-17 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260818-0248-design-token-t1-tests/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 期待値の置き場所 | 検査側の inventory に literal の表 | DESIGN.md から共有パーサ経由で導出 |
| 入力 CSS | 静的な fixture ファイル | inventory から組み立てた文字列 |
| 自動ソース走査 | 止めていない (アプリ全体の class を拾う) | `source(none)` と `@source inline` で候補を明示供給 |
| 生成 CSS の読み方 | 文字列の正規表現 | postcss の構文木を `@layer theme` の `:root, :host` 直下に絞って走査 |
| 実 app.css の検査 | 先頭 2 行のテキスト検査のみ | 構文木でのテキスト検査 + 実コンパイル (経路の層) |
| 文書の検査 | 散文の完全一致フレーズ | 節・表のセル・パス・検査目録の構造検査 + 節ごとの規範の最小断片 (描画されない領域を先に除く) |

### なぜ正当な差分か (logic-driven)

家系の裁定 (機能 `design-token-system` の AG-022b) は「原本の逐語移植は求めず、
原本が確かめている内容を実測して有用な部分を取り込む」と定めている。
正典の literal 表が持つ「DESIGN.md とは独立に値を pin する」性質は、この裁定に照らせば
**追従が要る不変条件ではなく正典実装の副次的な性質**である。本アプリは DESIGN.md を
唯一の正本としており、トークンの値の変更は「気付くべき事故」ではなく正規の変更手順であるため、
独立 pin を採らない。

静的 fixture を持たない判断も同様に、fixture の目的
(アプリ全体の class 変動から検査を独立させる) を `source(none)` と `@source inline` が満たす。

### 揃えている不変条件 (これは保証し続ける)

> 「inventory に登録された DESIGN.md 対応の色・角丸・文字組が、Tailwind のコンパイルを通って
> 生成 CSS に期待する値で現れること。色と角丸の utility は対応する変数を参照し、
> typography の utility は期待する宣言を過不足なく持つこと」

> **色・角丸と typography で形が違う**: 色と角丸の utility は `var(--color-*)` と
> `var(--radius-*)` を参照するが、typography の ramp は `font-size` / `font-weight` /
> `line-height` を literal で出し、変数を参照するのは `font-family` だけである。
> 「utility 名が変数へ解決する」と一括りに書かない。

密閉の層が母集団の全件を、経路の層が実 app.css からの到達をアンカーで保証する。
正本との drift は `tests/js/styles/canonical-source-parity.test.ts` の集合一致と値一致が
別の段で保証する。

### 保証しないもの

- 派生トークン `--color-primary-soft` の値 (生成 CSS への出現までしか見ていない)
- font-family の先頭以外のフォールバック列
- 生成 CSS より先 (Vite のビルド・アセット配信・ブラウザでの適用)
- 文書側は構造と節ごとの規範の最小断片までを見る。最小断片が在っても
  周りの説明が骨抜きになっていることは検出できない。
  描画されない領域として除くのは HTML コメントと fenced code の 2 つだけで、
  4 空白字下げのコードブロックと HTML 要素による非表示は見ていない

### 関連

- 実装: `tests/js/styles/tokens.test.ts` / `tests/js/styles/design-system-docs.test.ts` /
  `tests/js/styles/inventory.ts` / `tests/js/styles/design-md.ts` /
  `tests/js/styles/canonical-source-parity.test.ts` / `docs/design-system.md`
- 設計: `devnotes/20260818-0248-design-token-t1-tests/`

---

## D30 テスト DB の作成と回収に出自の記録と孤児の分類を上積みする

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` / `tests/Support/Ci/TestDatabaseCandidate.php` / `tests/Support/Ci/TestDatabaseClassification.php` / `tests/Support/Ci/TestDatabaseDecision.php` |
| 業務要件起因の説明 | 実装を必ず worktree で行う進め方のため、テスト DB 名を worktree の realpath の hash から作っている。worktree が検証なしで強制撤去されると hash を再現できず、引数なしの回収では二度と落とせない孤児 DB が積み上がる (2026-08-05 の監査時点で 17 個 / 221.9 MB)。加えて、worktree ごとに基点 DB を新規作成するため、正典の到達確認 (「migrations 表があり行が 1 件以上ある」) では古い基点 DB に古い migrations 表が残っている状態を見逃す頻度が正典の想定より高い (2026-08-19 追記) |
| 揃え続ける不変条件と保証機構 | 孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流すること、dev DB の拒否と allowlist の再検査が `TestDatabaseEnv` の既存実装を共有すること、テスト DB 名が worktree の realpath から決まること。`tests/Unit/Ci/DropTestDbScriptTest.php` (`--orphans --apply` の削除も通常の回収と同じ guard ループ `dropTestDbDropAll()` を通り、そこへ dev DB と allowlist 外の名前が到達しない) と `tests/Unit/Ci/TestDatabaseClassificationTest.php` (分類の優先順位と確認用の値の照合) と `tests/Unit/Ci/TestDatabaseProvenanceTest.php` (出自の記録が冪等で best-effort) と `tests/Unit/Ci/TestDatabaseEnvTest.php` (名前が worktree ごとに変わり同じ worktree では変わらない) が固定する。加えて (2026-08-19 追記)、基点 DB のスキーマ更新 (家系の裁定 AG-135 への追従) は「`database/migrations` の全ファイル名が migrations 表に含まれる」という正典より強い包含判定 (`pgsqlTestSchemaUnappliedMigrations()`) で成功を判定すること、スキーマ更新の子プロセスへは ensure 専用の非既定設定キャッシュパス (`pgsqlTestConfigCachePath()`) を渡し各 artisan 起動の直前にその残存を確認すること、出自の記録 (StampProvenance) はスキーマ更新 (UpdateSchema) より先に実行することを `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` と `tests/Architecture/BaseTestDatabaseSchemaTest.php` (B-2) が固定する |
| 再判定の条件 | 正典が同じ回収経路を取り込んだとき。または実装を worktree で行う進め方をやめてテスト DB 名が worktree に依存しなくなったとき。または (2026-08-19 追記) 正典が同水準以上の到達確認 (ファイル→表の包含判定) を採用したとき、または専用非キャッシュパスと同等の TOCTOU 対策を採用したとき (この場合は該当する上積みだけを撤去し正典実装へ揃え直す) |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | T114 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 基点 DB の作成 | 不在なら CREATE する | 同じ |
| 出自の記録 | 持たない | `COMMENT ON DATABASE` へ worktree の realpath を作成時・既存時の両方で記録する (非破壊 DDL。付与失敗は無視する) |
| 回収の入口 | 引数なしの 1 経路だけ (現 worktree の基点と worker DB) | それに加えて `--orphans` の列挙と `--apply` |
| 孤児の扱い | 経路が無い (hash を再現できないので落とせない) | SELECT だけで `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順に分類し dry-run で列挙する |
| 削除の決め方 | 名前の一致で自動 | 分類だけでは決めない。`--include-hash` で人が 1 つずつ名指しし、`--confirm` の値を lock 取得後に再計算して照合する |
| DROP DDL の実行点 | `drop-test-db.php` の 1 本 | 同じ (`--orphans` は入口を足すだけ) |
| 基点 DB のスキーマ更新 | 正典 HEAD は `migrate` まで担う (家系の裁定 AG-135) | 追従済み (`devnotes/20260819-1056-ensure-test-db-schema-followup/`)。到達確認は正典より強い基準を採用し、専用の非キャッシュ設定パスを使う (下記「到達確認を正典より強めた基準」参照) |

### なぜ正当な差分か (logic-driven)

本アプリの実装は必ず worktree で行う (AGENTS.md §worktree 運用ルール)。テスト DB 名は
`TestDatabaseEnv::workrootHash()` = worktree root の realpath の sha1 先頭 8 桁から作るので、
**worktree が消えると名前を再現できない**。teardown が `doc/reference/` の NFC/NFD 問題で
常時失敗していた時期に `git worktree remove --force` での迂回が常態化し、
回収経路を通らない孤児 DB が単調増加した (2026-08-05 の監査時点で 17 個 / 221.9 MB)。

テンプレートの `drop-test-db.php` は「今いる worktree の基点と worker DB を落とす」だけなので、
この事象に手が届かない。届かせるには DB 自身に出自を持たせるしかなく、
非破壊の `COMMENT ON DATABASE` を選んだ。分類は SELECT だけで行い、DROP DDL の実行点は
1 本のまま据え置いた — **危険な操作の入口を増やさずに、判断材料だけを増やす**形である。

### 揃えている不変条件 (これは保証し続ける)

> 「孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流する。dev DB の拒否
> (`isDevDatabase()`) と allowlist の再検査 (`isAllowedTestDatabase()`) と DROP 文の組み立て
> (`pgsqlDropDatabaseSql()`) は既存実装をそのまま共有する」

- 分類の優先順位は `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順で、
  **`Live` が `Foreign` や `Orphan` より先**である。出自のコメントを細工しても生存 DB は落とせない
- 削除可否を分類だけで決めない。`Orphan` も `Unlabeled` も `--include-hash` で
  人が 1 つずつ名指ししない限り 1 件も落ちない (一括の指定は意図的に用意していない)
- `--apply` は確認用の値を `.claude/worktrees/.setup.lock` の取得後に再計算して照合する
  (指紋ではなく lock 下のスナップショット照合)
- 合流を固定しているのは `tests/Unit/Ci/DropTestDbScriptTest.php` の次のケースである。
  `--apply` の削除は `dropTestDbDropAll()` (通常の回収と同じ guard ループ) を必ず通り、
  その結果から終了コードが決まる (`wires the drop outcome into the --apply exit code end to end`)。
  承認済みの一覧に dev DB が紛れても実行境界へは 1 件も到達しない
  (`exits non-zero from --apply if a dev database somehow reached the approved target list`)。
  実行境界へ何が渡るかを見るケース群 (`never passes the dev database to the SQL executor` ほか 2 件) は
  この 1 本の guard ループを対象にしている

併せて、家系の裁定 AG-135 への追従で「出自の記録 (StampProvenance) はスキーマ更新
(UpdateSchema) より先に実行する」を不変条件へ加える (スキーマ更新の失敗時に
「ラベルの無い現役 DB」を残さないため)。`tests/Unit/Ci/TestDatabaseProvenanceTest.php` の
`always plans the schema update last, after the provenance stamp` が固定する。
到達確認の基準そのもの・専用非キャッシュ設定パスの採用理由は次の節を参照。

### 追従の記録

正典 HEAD の `ensure-test-db.php` が担う基点 DB のスキーマ更新 (家系の裁定 AG-135) に、
`devnotes/20260819-1056-ensure-test-db-schema-followup/` の設計で追従した
(オーナー決定 2026-08-19)。追従の実装は `tests/Architecture/BaseTestDatabaseSchemaTest.php` と
`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` が固定する。
`docs/worktree-isolation-strategy.md` の「既知のギャップ」から該当項を削除した。

### 到達確認を正典より強めた基準と専用の非キャッシュ設定パス (還流候補)

正典の到達確認 (「migrations 表があり行が 1 件以上ある」) は、古い基点 DB に古い
migrations 表が残っている状態を通してしまう。実装を必ず worktree で行う進め方
(AGENTS.md §worktree 運用ルール) は worktree ごとに基点 DB を新規作成するため、
この見逃しを踏む頻度が正典の想定より高い。本アプリはこの追従にあたり、次の 2 点を
正典より強くした。

1. 到達確認は `database/migrations` の全ファイル名が migrations 表に含まれることを要求する
   (`pgsqlTestSchemaUnappliedMigrations()`)。集合の一致は求めない (vendor パッケージ由来の
   migration が表に増えても許容する)。
2. スキーマ更新の子プロセスへ渡す設定キャッシュパスは Laravel の既定パスではなく ensure
   専用の非既定パス (`pgsqlTestConfigCachePath()`) を使い、各 artisan 起動の直前にこのパスの
   残存を確認する。

`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (到達確認の判定関数・専用パスの値・
各失敗経路) と `tests/Architecture/BaseTestDatabaseSchemaTest.php` (B-2。同じ判定関数を
共有する到達確認の実地観測) が固定する。**正典より強い基準であるため、家系の機能台帳への
還流候補として扱う**。正典が同水準以上の到達確認 (ファイル→表の包含判定) を採用したとき、
または正典が専用非キャッシュパスと同等の TOCTOU 対策を採用したときに、この上積みを
撤去して正典実装へ揃え直す (再判定の条件)。

### 保証しないもの

- 出自の記録は best-effort である。付与に失敗した DB は `Unlabeled` に落ち、
  `--include-hash` で人が名指ししない限り 1 件も回収されない
  (回収経路があることは「孤児が自動で片づく」ことを意味しない)
- 排他が閉じるのは**同一クローンの協調スクリプト間**の競合だけである。
  別クローンとの競合は `Foreign` の分類と `--protect-hash` と人の承認の 3 段で扱う
- 「`--apply` を LLM が実行しない」は運用契約であり、機械では強制していない
- **リポジトリ全体で DROP の実行点が 1 本であることを走査する検査は持たない**。
  上の不変条件が言っているのは「孤児の回収経路が既存の境界へ合流している」ことだけで、
  別のファイルに新しい DROP の実行点が増えたことは検出できない
- スキーマ更新の到達確認は「基点 DB の最終状態がスキーマ最新である」ことの確認であって、
  直前の migrate/migrate:status 子プロセスがその更新を行ったことの監査ではない
  (基点 DB が既に最新なら、子プロセスの環境変数解決が壊れていて別の DB を
  更新していても、この確認だけでは検出できない。dev DB 保護は名前の出所の一本化・
  起動直前の再検証・非継承の環境固定で成立させている)
- 専用非キャッシュパスの残存チェックは「多重起動が絶対に起きない」ことを前提にしない。
  `scripts/setup-worktree.sh` はグローバルテストロックの**外**で本スクリプトを呼ぶため
  (worktree 作成そのものを壊さないための意図的な設計)、多重起動は理論上ゼロではない。
  このチェックが担うのは「専用パスが原因を問わず既に存在していたら、通常の
  `config:cache` はこの専用パスを絶対に書かないという前提が崩れているとみなして
  fail-closed で停止する」ことだけである

### 関連

- 実装: `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` /
  `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` /
  `tests/Support/Ci/TestDatabaseCandidate.php` /
  `tests/Support/Ci/TestDatabaseClassification.php` /
  `tests/Support/Ci/TestDatabaseDecision.php`
- 検査: `tests/Unit/Ci/DropTestDbScriptTest.php` /
  `tests/Unit/Ci/TestDatabaseClassificationTest.php` /
  `tests/Unit/Ci/TestDatabaseProvenanceTest.php` /
  `tests/Unit/Ci/TestDatabaseEnvTest.php` /
  `tests/Architecture/BaseTestDatabaseSchemaTest.php` /
  `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`
- 背景: `docs/worktree-isolation-strategy.md` の「孤児テスト DB の回収」と「既知のギャップ」
- 設計: `devnotes/20260805-2017-todo-T114/` /
  `devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/` /
  `devnotes/20260819-1056-ensure-test-db-schema-followup/`

---

## D31 起動ラッパの拡張探索と代替経路を正典と別の形で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/claude` |
| 業務要件起因の説明 | 起動ラッパは開発で必ず通る経路で、拡張の置き場も接尾辞の綴りも環境で変わるため、完全一致だけを見る形では拡張が入っているのに起動できない環境が残る。この経路は正典に無い時点で別実装として先に固定したものであり、正典が同じ目的の経路を別の形で持った今も、家系の正典形が決まるまでは検証済みの挙動を裁定なしに変えないため期限付きで現状を保つ |
| 揃え続ける不変条件と保証機構 | 2 つの置き場から版が最も大きい拡張を採ること、完全一致が無ければ代替を探すこと、完全一致でない候補を使って起動する実行ではその事実が警告として観測できること、完全一致のときは警告を 1 文字も出さないこと、起動する実体が無ければ明示エラーで止めること、ラッパ専用の指定だけを剥がして残りの引数を順序も内容も変えずに渡すこと。`scripts/claude-wrapper.test.ts` が W1 から W8 の 8 要件を 9 つのケースで固定する (W6 だけ状態表示行の有る場合と無い場合の 2 ケースを持つ) |
| 再判定の条件 | 家系が起動ラッパの正典形を確定したとき。または正典の探索と警告の形を取り込むと決めたとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | T181 |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-18 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 探索の関数 | `find_latest_ext()` が版の文字列を返し、パスの解決は関数の外で行う | `find_claude_extension()` が採用するパスを返し、見つからなければ非ゼロで戻る |
| 拡張が 1 つも入っていない環境 | 拾い直しを試す前に警告を出すのでエラーの前に警告が 1 本出る | 警告は出さずエラーだけで終わる |
| 代替を採用したときの知らせ方 | 拾い直しを試す前に出す | 採用に成功したときに出す |
| 警告の内容 | 期待した platform | 期待した platform と採用したパスの両方 |
| 採用後の存在検査 | `[ ! -d ... ]` を残す | 関数が実在するディレクトリしか返さないので持たない |
| 回帰テスト | ある | ある (W1 から W8 の 8 要件を 9 ケースで固定する。拾い直しの警告と、完全一致では 1 文字も警告しない負のコントロールを含む) |

### なぜ正当な差分か (logic-driven)

aicue:T181 の時点で、本アプリの `scripts/claude` は拡張の探索を本文へ直書きしており、
platform が完全一致する拡張が無い環境では即座に終了して代替を案内しなかった。
T181 は探索を 1 か所の関数へまとめ (完全一致と拾い直しで同じ規則が使われる)、
拾い直しの経路と警告を足し、回帰テストを新設した。**意図が確認できる変更**であり、
気付かないうちにずれたものではない。

当時、正典側にこの経路は無く、正典の実装はこの機械から読めなかった (T181 は
「追従元との byte 一致は確認できないし主張しない」と明記している)。実装を待って
起動できない環境を放置するより、**同じ不変条件を別実装で先に固定する**方が実害を早く閉じられる。
これは aicue:D11 (svelte-no-undef-gate を別実装で先に固定した登録) と同じ形の判断である。

正典はその後に同じ目的の経路を別実装で持った。**揃えている不変条件と、揃っていない振る舞いを
分けて書く**。

- 揃っているもの: 2 つの置き場から版が最も大きい拡張を採ること、完全一致が無ければ代替を探すこと、
  **完全一致でない候補を使って起動する実行では、その事実が警告として観測できる**こと、
  完全一致のときは警告を 1 文字も出さないこと、起動する実体が無ければ明示エラーで止めること
- 揃っていないもの: **拡張が 1 つも入っていない環境での知らせ方**である。正典は拾い直しを
  試す前に警告を出すのでエラーの前に警告が 1 本出る。本アプリは代替の採用に成功したときだけ
  警告を出すので、エラーだけで終わる。関数が版の文字列を返すかパスを返すかも揃っていない

揃っていない側を「同じ不変条件の構文差」とは言わない (振る舞いとして観測できる差である)。
本アプリがこの形を保つ理由は、**拡張が 1 つも無いという分かりきった失敗に警告を足しても
読み手の判断は増えず、警告が出ない状態を負のコントロールとして固定してあるから**である
(`scripts/claude-wrapper.test.ts` の W3 と W8)。とはいえ、どちらの形を家系の正典とするかは
本アプリだけでは決められないし、正典の実装は今なら読める (家系の機能台帳から原本を取得できる)。
したがって状態は `監視中` にし、期限までに寄せるかどうかを判断する。

### 正典の内容へ戻す案との比較 (採らない理由)

| 案 | 内容 | 判断 |
|---|---|---|
| A: 登録する | 差を登録簿に載せ、期限を切って再判断する | 採る |
| B: 正典へ戻す | 正典の `49e03e31` を byte 一致で取り込み、差を消す | 採らない |

B を採らない理由は 3 つある。

1. 意図が確認できる変更である (T181 の目的・実装・回帰テストが揃っている)。
   登録簿の「登録するか迷ったら登録する」に素直に当てはまる
2. 振る舞いが後退する方向を含む。正典は拾い直しを試す前に無条件で警告するので、
   拡張が 1 つも入っていない環境では警告とエラーが 2 段で出る。本アプリの回帰テストは
   「拡張が 1 つも無ければ platform 名つきのエラーで終了する」と
   「完全一致では警告を 1 文字も出さない」を負のコントロールとして固定しており、
   戻すと期待の書き換えが要る
3. どちらの形を正典とするかは家系の判断であり、下流が独断で寄せる話ではない。
   登録して見える状態にし、期限までに判断する

### 保証しないもの

- **警告をいつ出すかは正典と揃えていない**。揃えているのは「完全一致でない候補を使って
  起動する実行では警告が観測できる」ところまでで、代替が見つからずに失敗する実行で
  警告を出すかどうかは実装の差として残る (正典は探す前に出すので出る。本アプリは出ない)
- 拾い直した実体がその機械で実際に動くこと (代替の経路は arch を検査しない。正典も同じである)
- 同じ版が 2 つの置き場にあるときの優先順 (正典が固定していないので下流だけで固定しない)
- 版の比較は `sort -V` に依存する。これは本変更より前からある前提であり、
  無い環境で動くことは保証の対象にしていない
- 正典との byte 一致 (T181 の時点でも主張していない)

### 関連

- 実装: `scripts/claude` / `scripts/claude-wrapper.test.ts` / `scripts/README.md`
- 設計: `devnotes/20260816-0457-todo-T181/` /
  `devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/`
- 家系の機能台帳: `vscode-cli-wrappers` (本アプリのセルは追従の判断待ちのまま)

---

## D32 キャッシュ素データ規約の実行時層を、アプリ起動の前に結線し境界迂回を正典より広く塞ぐ

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/Cache/PlainDataCacheGuard.php` / `tests/Support/Cache/GuardedBoundaryProbe.php` / `tests/Architecture/CacheGuardWiringGateTest.php` |
| 業務要件起因の説明 | 本アプリは起動時に名前付き流量制限を多数登録し、その時点で受け皿を握るため、Pest の beforeEach で結線すると起動中の書き込みが 2 層とも見えない穴になる。また同梱パッケージがオブジェクトをキャッシュへ入れる実装を持つため、受け皿を跨ぐ書き方を正典の 3 形より広く塞ぐ必要がある |
| 揃え続ける不変条件と保証機構 | 結線がアプリ起動の前にあり全レーンが後始末すること (`CacheGuardWiringGateTest`)。受け皿を跨ぐ書き方と静的に解決できない生成が目録と exact-fit であること (`CachePayloadPlainDataGateTest` の検査 L4a-L4h) |
| 再判定の条件 | 家系の正典が結線点と境界迂回の語彙を改めたとき / Laravel が `createApplication()` の本体を変えて写しが維持できなくなったとき |
| 決めた日 | 2026-08-18 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260818-1757-cache-runtime-plain-data-guard/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-14 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 結線点 | Pest の beforeEach 相当 | アプリ起動の前 (`Tests\TestCase::createApplication()` の bootstrap 直前) |
| 境界迂回の語彙 | 保管先の直接取得・受け皿の直接生成・拡張登録の 3 形 | 上記に加えて `setStore` / `tags` / macro 系 / 具体 store の生成 / 静的に解決できない生成 |
| 迂回の判定 | 0 件 | 通常経路 0 件 + 実行時層の自己テストだけを名指しの目録へ exact-fit |
| 継承・実装の宣言 | 対象外 | 受け手型・保管先型・実行時層の実装クラスの継承を**別の名指し目録**で扱い、実行時層の実装 2 本だけを許す |
| 静的に解決できない生成 (`new $class`) | 対象外 | 走査根の全体で deny-by-default にし、キャッシュの保管先ではない既知の用途を**理由付きの目録**へ exact-fit で登録する |
| 目録の構造 | 書き込みサイトの全数申告目録 | 既存の L1-L3 に L4 (迂回) を足す形 |
| ArrayAccess 書き込み | 検出しない | `$cache[$k] = $v` を静的にも検出する |

### なぜ正当な差分か (logic-driven)

`AppServiceProvider::boot()` が名前付き流量制限を多数登録するため、`Illuminate\Cache\RateLimiter` は
**起動中に** cache を解決して受け皿を握る。beforeEach で結線すると RateLimiter が握るのは
guard の付いていない受け皿になり、起動中の書き込みは実行時層に見えない。
vendor 由来の書き込みは静的層の走査根 (`app` / `routes` / `database` / `tests`) にも入らないので、
**2 層とも沈黙する**。`Illuminate\Foundation\Testing\TestCase::createApplication()` は
`bootstrap/app.php` を require したあと `bootstrap()` を呼ぶ間に**まだ起動していない `$app`** に
触れる唯一の点なので、そこを override して結線する。

境界迂回を広げたのは、`Repository::tags()` が `new TaggedCache($this->store, ...)` を素で生成して
継承を素通りすること、`Repository` が `Macroable` を use しており macro の closure から
`$this->store` へ直接到達できることを vendor 実読で確認したためである。
どちらも実行時層の被覆から抜ける口であり、正典の 3 形には含まれていない。

### 揃えている不変条件 (これは保証し続ける)

> 「テストが実行したキャッシュ書き込みの値は、保管先へ渡る前に素データであることを検査されている」

- 結線がアプリ起動の前にあることと、全レーンが後始末することは `CacheGuardWiringGateTest` が固定する
- vendor の `createApplication()` の写しは token 列の完全一致で pin するので、静かに古くならない
- 受け皿を跨ぐ書き方は自己テスト目録と exact-fit で、1 件増えたら必ず赤くなる

### 保証しないもの

- 保証しないものの正本は `tests/Support/Cache/PlainDataCacheGuard.php` の docblock である
  (本書と `docs/architecture.md` には写さない)

### 関連

- 実装: `tests/Support/Cache/` / `tests/TestCase.php` / `tests/Pest.php` /
  `tests/Architecture/CachePayloadPlainDataGateTest.php`
- 設計: `devnotes/20260818-1757-cache-runtime-plain-data-guard/`

---

## D33 テンプレート乖離の突合を、正典の分類規則ではなく公開された指紋台帳のキーで行う

| 行 | 内容 |
|---|---|
| 対象パス | `docs/template-fingerprints.json` / `tests/Architecture/TemplateDivergenceFingerprintTest.php` / `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` / `tests/Support/TemplateDivergence/DivergenceLedgerParser.php` / `tests/Support/TemplateDivergence/FingerprintLedger.php` / `tests/Support/TemplateDivergence/AtomicLedgerWriter.php` / `scripts/update-template-fingerprints.php` |
| 業務要件起因の説明 | テンプレートの現物を CI に持てないため、母集合を正典の分類規則ではなく正典が公開する指紋台帳のキーで決める。突合は本アプリの登録簿が許す複数の対象パスに合わせて実装する |
| 揃え続ける不変条件と保証機構 | 3a / 3b の集合等式と fail-closed の 4 規約を保つ (`TemplateDivergenceFingerprintTest` / `TemplateDivergenceFingerprintRulesTest` / `TemplateFingerprintGeneratorTest`) |
| 再判定の条件 | 正典が母集合の決め方・schema・パス検証の判定を変えたとき / テンプレートの現物を CI で引ける手段ができたとき |
| 決めた日 | 2026-08-20 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 母集合の出典 | 自リポジトリの `git ls-files` を `SharedPathRules` (22KB の規則表) で分類する | 正典が公開する指紋台帳のキー ∩ 自リポジトリの追跡ファイル (規則表は持ち込まない) |
| パスの書式判定 | `SharedPathRules::isValidRepoRelativePath()` | `RepoRelativePath::isValid()` (書式判定だけを切り出した 1 クラス) |
| 指紋台帳の解釈 | 連想配列で解釈する | object 形で解釈し、空配列と空 object を型で区別する |
| 鮮度比較 | `matchesIgnoringGeneratedCommit()` で指紋台帳が古くなっていないかを見る | 持たない (受け手側には呼び出し元が無いため。思考原則 2) |
| 指紋台帳自身の種別 | 検査しない | 母集合の正本なので regular file であることを要求する (symlink 差し替えを塞ぐ) |
| 初回生成 (採用) の条件 | 概念が無い (提供元は毎回生成する) | 出力先 2 本がどちらも追跡下に無く既存債務も空のときだけ許す (削除して再採用する経路を塞ぐ) |
| 正本の正準形 | 検査しない | 正本のバイト列が解釈して直列化し直した結果と完全一致することを要求する (重複キー・整形の崩れを落とす) |
| 突合の DTO | 対象パスを 1 件だけ持つ `DivergenceEntry` | 対象パスの複数指定を許す本アプリの解析結果をそのまま使う |
| 生成器の起動 | 提供元で走らせ、子アプリでは role ガードが拒否する | 受け手側で走らせ、入力の正典台帳を `--template-ledger` で渡す (既存台帳が `role: template` なら拒否) |
| 生成物 | 指紋台帳 1 本 | 指紋台帳 + 採用時債務一覧の 2 本 (平文は `AtomicTextWriter` が書く) |
| 生成器の先頭行 | `#!/usr/bin/env php` を持つ | 持たない (`StrictTypesDeclarationGateTest` が開始タグより前のトークンを未宣言として落とすため) |

### なぜ正当な差分か (logic-driven)

本リポジトリは**テンプレートの受け手**であり、テンプレートの現物 (working tree) を CI に持てない。
正典の突合はテンプレート側の分類規則を自分で走らせて母集合を決めるが、受け手側で同じ規則表を
持つと「使われない 22KB の資産」が増えるだけで不変条件は 1 つも増えない (思考原則 2)。
そこで**母集合の出典を正典が公開する指紋台帳のキーに置き換えた**。
正典自身が「検査の本数・クラス名・ファイル配置は不変条件に含めない」と定めているため、
同じ等式を本リポジトリのモデル (対象パスの複数指定を許す解析器) で実装している。

解釈を object 形にしたのと正準形バイト一致を要求したのは、どちらも**過剰検出寄りへの上積み**である。
連想配列で解釈すると `{"entries": []}` のような空配列が空 object と区別できず、
`json_decode` は重複キーを後勝ちで潰すため、どちらも「母集合が黙って空になる」経路になる。

### 揃えている不変条件 (これは保証し続ける)

> 「テンプレートと共有するファイルが食い違ったなら、登録簿の登録か採用時債務の記載が必ずある」

- 集合等式 1 本で両方向 (3a = 未登録の食い違い / 3b = 一致へ戻ったのに残る登録) を落とす
- 読み取り失敗・解釈不能・git の失敗・母集合 0 件・検査不能はすべて不合格にする (fail-closed)
- 本機構自身のファイルが母集合に残り regular file であることを必須メンバ pin が固定する

### 保証しないもの

- 保証しないものの正本は `tests/Architecture/TemplateDivergenceFingerprintTest.php` の
  docblock である (本書と `AGENTS.md` には写さない)

### 関連

- 実装: `tests/Support/TemplateDivergence/` / `tests/Architecture/TemplateDivergenceFingerprintTest.php` /
  `scripts/update-template-fingerprints.php`
- 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`

---

## D34 採用時点で説明の無い食い違いを、採用時ハッシュ付きで凍結する層を持つ

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` |
| 業務要件起因の説明 | テンプレートの現物が CI に無いため、採用時点で食い違っていたファイル (174 件) が意図的逸脱なのか追従遅れなのかを機械では区別できない。区別が付くまで採用時の姿を凍結して扱う層を持つ |
| 揃え続ける不変条件と保証機構 | 債務パスは採用時のアプリ側ハッシュのまま留まること。変えたら `mutatedDebtPaths`、テンプレート一致へ戻ったら `resolvedDebtPaths` が落とす (`TemplateDivergenceFingerprintTest` の F10 / F11) |
| 再判定の条件 | 一覧が 0 件になったとき (一覧ファイルを削除し、対象パスから一覧パスの 1 行を外す。**登録そのものは一覧クラスの説明として残す**。突合 gate の F12 が両方向で強制する) / テンプレート更新の一括取り込みを行うとき / 債務パスの分類が付いたとき |
| 決めた日 | 2026-08-20 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-28 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 未分類の食い違い | 存在しない (提供元なので食い違いの概念が無い) | 採用時債務一覧に採用時のアプリ側 sha256 付きで凍結する |
| 凍結の粒度 | — | パス 1 件 + そのときのアプリ側ハッシュ (パスだけを持つ形は恒久的な許可一覧になってしまう) |
| 一覧が縮む契機 | — | 内容をテンプレートへ戻す / 意図的逸脱として登録簿へ書く の 2 つだけ |
| 期限の管理 | — | 本登録の状態を `監視中` にし、見直し期限切れを CI の赤で強制する |

### なぜ正当な差分か (logic-driven)

**本登録は「未分類の債務をまとめて正当化する登録」ではない。**
本書の冒頭は「互換・UX・**作業量**を理由にした逸脱は記録せず是正する」と定めており、
「件数が多くて書くのが大変だから」は逸脱の理由になり得ない。
本登録が登録するのは**未分類の債務を期限付きで管理する安全機構を持つこと**そのものであり、
その業務要件起因は「テンプレートの現物が CI に無く、意図的逸脱と追従遅れを機械で区別できない」
ことである。**分類を先送りする言い訳ではなく、先送りを期限付きで可視化する装置**として登録する
(期限切れは CI の赤 = 是正の強制)。

一覧が**採用時のアプリ側ハッシュを持つ**ことが要点である。パスだけを持つと
「そのパスは食い違っていればいつでも合格」になり、凍結された観測ではなく
**そのパスに対する恒久的な許可一覧**になってしまう。ハッシュを持てば
「採用時の姿のまま」と「採用後に手を入れた」を区別でき、後者は違反として落とせる。

### 揃えている不変条件 (これは保証し続ける)

> 「採用時債務に載っているパスは、採用時の姿のまま留まっている」

- 採用時の姿から変わったら `mutatedDebtPaths` が落とす (登録を書くか、戻すか、同期する)
- テンプレート一致へ戻ったら `resolvedDebtPaths` が落とす (一覧から削れという指示になる)
- 件数は `LedgerPins::ADOPTION_DEBT_COUNT` と完全一致で pin する (増減のどちらでも赤になる)
- 2 生成物の世代が食い違ったら F14 が落とす (片方だけ更新された状態を緑にしない)

### 保証しないもの

- 保証しないものの正本は `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` と
  `tests/Architecture/TemplateDivergenceFingerprintTest.php` の docblock である
  (本書には写さない)
- **引退で消えるのは一覧ファイルと対象パスの 1 行だけで、本登録は残る**。
  一覧が 0 件になっても判定機構 (`AdoptionDebtInventory.php`) は残り続ける
  (突合 gate の F12 が `retirementViolations()` を呼び続けるため) ので、
  「機構は残すが説明だけ消す」は登録簿の意味と一致しない。
  なお同クラスは**正典の指紋台帳のキーではない** (母集合の外) ため、突合 gate は
  同クラスの内容には沈黙する。対象パスに挙げているのは
  「本アプリ固有の追加である」ことを記録するためである
- **引退は安定状態である**。生成器は債務が 0 件のとき一覧を書かず、既存の一覧ファイルを
  取り除く (「0 件の一覧」ではなく「一覧が無い」が正しい生成物の状態である)。
  逆に台帳の載せ替えで新しい債務が生じたら一覧は再作成されるので、そのときは
  件数 pin を戻し、対象パスへ一覧パスの 1 行を戻すことになる
- **機構ごと撤去する判断をするなら、本登録・判定機構・F12 を一緒に消すことになる**
  (そこは指紋台帳や gate 自身の手編集と同じ原理的限界であり、PR レビューの担当である)

### 関連

- 実装: `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` /
  `tests/Support/TemplateDivergence/adoption-debt.tsv`
- 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`

---

## D35 設計・実装スキルに乖離台帳の確認段とアプリ固有の手順を持たせる

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md` |
| 業務要件起因の説明 | 本アプリの設計・実装スキルは、乖離台帳の確認段と bug-hunt 等のアプリ固有の手順を持つためひな形と異なる。共有ファイルを変えた変更が登録を伴わずにコミットされると突合 gate が赤くなるので、登録の契機を人手の出口に置く必要がある |
| 揃え続ける不変条件と保証機構 | 共有ファイルを変えたら登録を同じコミットで足す手順を出口に持つこと (突合 gate `TemplateDivergenceFingerprintTest` が登録漏れを赤にすることで手順の実効を担保する) |
| 再判定の条件 | 登録の契機を機械強制へ移せたとき (指紋台帳のキーとの突合を pre-commit で行う手段ができたとき) / スキルの構成をひな形へ戻すとき |
| 決めた日 | 2026-08-20 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 設計スキルの完了段 | 完了報告と TODO 登録の案内だけ | その前に乖離台帳の確認段を持ち、共有ファイルの変更を施策として明記させる |
| 実装スキルのコミット段 | 変更をまとめてコミットする | コミット前に登録と件数 pin を同じコミットへ含めることを確認させる |
| 債務一覧に在るパスの扱い | 概念が無い | 「変更したまま残す」を選べないことと 3 択を明示する |

### なぜ正当な差分か (logic-driven)

突合 gate は**登録漏れを検出する**が、**登録を書かせることはできない**。
検出だけを足すと「CI が赤くなってから登録を書く」流れになり、赤を消す最短経路として
**指紋台帳や債務一覧を書き換える**誘惑が生まれる (これは検査を書き換えるのと等価である)。
そこで登録の契機を、設計の完了段と実装のコミット段という**人手の出口 2 か所**に置いた。
テンプレートのスキルはこの段を持たないので、本アプリのスキルはひな形から外れる。

### 揃えている不変条件 (これは保証し続ける)

> 「共有ファイルを変えた変更は、同じコミットに登録と件数 pin の更新を含む」

- 手順が守られなかった場合は突合 gate が 3a で赤くなる (手順の実効はここが担保する)
- 債務一覧に在るパスを触った場合は `mutatedDebtPaths` が赤くなる

### 保証しないもの

- **確認段は人手の層であり機械強制ではない**。家系の正典が持つ「role で分岐する二役の書き方」
  への完全な到達は主張しない
- スキル文書を読まずに実装した場合は何も止まらない (止まるのは突合 gate の側である)

### 関連

- 実装: `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md`
- 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`

## D37 ジョブ重複配送の免除目録は業務追加ごとに更新され続ける台帳である

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/JobExecutionDedupInventoryTest.php` |
| 業務要件起因の説明 | 本ファイルはキューに載る全クラス (`ShouldQueue` 実装) を「保証側」か「免除側」に分類する deny-by-default 目録である (AGENTS.md ドメイン規約 6)。新しい通知・ジョブを追加するたびに 1 エントリと件数 pin の更新が要る、**業務ドメインの拡張に追随して恒常的に更新され続ける**設計であり、テンプレートの汎用形 (空の目録) や「採用時点の姿」のどちらにも収束しない。採用時債務一覧が要求する「変更したら 3 択のいずれかを選ぶ」に従い、意図的逸脱として登録する |
| 揃え続ける不変条件と保証機構 | 母集団 (キューに載る全クラスの完全一致) と免除の理由付き分類は E1 系のテストが deny-by-default で強制し続ける。件数 pin (`jobDedupExemptionCap()` / `jobDedupExemptionCapByCase()`) は登録の追加ごとに機械的に更新させられる (更新漏れは gate 自体が赤くなる) |
| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
| 決めた日 | 2026-08-21 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 目録の内容 | 汎用の骨組み (業務エントリ無し) | 本アプリの `ShouldQueue` クラス全数に対する業務判断 (保証側/免除側) を蓄積した目録 |
| 更新頻度 | テンプレート更新時のみ | 新規ジョブ・通知の追加ごとに更新 (構造上恒常的) |

### なぜ正当な差分か (logic-driven)

本目録は「新しいジョブ・通知を追加したら分類を書かせる」という deny-by-default 機構そのもの
であり、業務ドメインが拡張し続ける限り内容が増え続けることが**設計の目的**である。
「採用時点の姿」や「テンプレートの汎用形」に固定・収束させることは目録の意図と矛盾するため、
債務一覧の 3 択のうち「意図的逸脱として登録する」を選ぶ。今回 (T110) は
`AuthMethodChangedNotification` の免除エントリ追加がこの恒常的な更新の 1 例である。

### 揃えている不変条件 (これは保証し続ける)

> 「キューに載る全クラスが保証側か免除側のどちらかに分類され、免除には 30 文字以上の
> 理由が付く。件数 pin は現在値ちょうどに保たれる」

- 分類漏れ・件数 pin の更新漏れは既存の E 系テストが deny-by-default で検出する
- 本登録は「今後も内容が変わり続けること」を許容するものであり、
  内容そのものの正しさ (各エントリの分類・理由の妥当性) は人のレビュー対象のままである

### 保証しないもの

- 目録の分類判断 (どのクラスを保証側/免除側にするか) の妥当性は本登録の対象外
  (既存の E 系テストと人のレビューが担う)
- 将来テンプレート側に同種の目録が持ち込まれた場合の統合可否は判断していない

### 関連

- 実装: `tests/Architecture/JobExecutionDedupInventoryTest.php`
- 設計: `devnotes/20260821-2015-auth-method-change-notification/`

## D38 キュー接続リース目録は業務追加ごとに更新され続ける台帳である

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/QueuedJobLeaseInventoryTest.php` |
| 業務要件起因の説明 | 本ファイルはキューに載る全クラス (`ShouldQueue` 実装) の接続 (`QUEUED_JOB_LEASE_INVENTORY`) を deny-by-default で目録化する (AGENTS.md §キューのリース期間とワーカー制限時間の規約)。D37 の `JobExecutionDedupInventoryTest.php` と同じ母集団 (`Tests\Support\QueuedJobPopulation`) を見る対の目録であり、新しい通知・ジョブを追加するたびに 1 エントリの追加が要る、**業務ドメインの拡張に追随して恒常的に更新され続ける**設計である。テンプレートの汎用形や「採用時点の姿」のどちらにも収束しないため、採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
| 揃え続ける不変条件と保証機構 | 母集団 (キューに載る全クラスの完全一致) は D37 と同じ `Tests\Support\QueuedJobPopulation` を経由するため、片方だけ更新される drift が起きない。接続の明示登録漏れは本ファイルの deny-by-default 検査が強制し続ける |
| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
| 決めた日 | 2026-08-21 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 目録の内容 | 汎用の骨組み (業務エントリ無し) | 本アプリの `ShouldQueue` クラス全数に対する接続割当を蓄積した目録 |
| 更新頻度 | テンプレート更新時のみ | 新規ジョブ・通知の追加ごとに更新 (構造上恒常的) |

### なぜ正当な差分か (logic-driven)

D37 と同じ理由である。本目録は「新しいジョブ・通知を追加したら接続を明示させる」という
deny-by-default 機構そのものであり、業務ドメインが拡張し続ける限り内容が増え続けることが
**設計の目的**である。今回 (T110) は `AuthMethodChangedNotification` の登録
(既定接続 = `null`) 追加がこの恒常的な更新の 1 例である。

### 揃えている不変条件 (これは保証し続ける)

> 「キューに載る全クラスが目録に登録され、目録と走査結果の対称差が空である」

- 登録漏れは既存の検査が deny-by-default で検出する
- 本登録は「今後も内容が変わり続けること」を許容するものであり、
  内容そのものの正しさ (各エントリの接続割当の妥当性) は人のレビュー対象のままである

### 保証しないもの

- 目録の接続割当判断 (どのクラスをどの接続にするか) の妥当性は本登録の対象外
  (既存の検査と人のレビューが担う)
- 将来テンプレート側に同種の目録が持ち込まれた場合の統合可否は判断していない

### 関連

- 実装: `tests/Architecture/QueuedJobLeaseInventoryTest.php`
- 設計: `devnotes/20260821-2015-auth-method-change-notification/`

## D39 パスキー削除の同期購読者 pin は listener 追加ごとに更新され続ける固定値である

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/PasskeyPackageContractTest.php` |
| 業務要件起因の説明 | 本ファイルは `PasskeyDeleted` の直接購読者を「同期で走る N 件だけ」という完全一致 pin で固定している (削除の巻き戻りの前提の検査)。新設の `App\Listeners\Auth\NotifyAuthMethodChange` (T110) が同じイベントを同期購読するため、この pin (顔ぶれ・件数・購読順) を更新する必要がある。この pin は「同期購読という前提が保たれているか」を業務追加ごとに人手で確認させる deny-by-default 機構であり、テンプレートの汎用形にも「採用時点の姿」にも収束しないため、採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
| 揃え続ける不変条件と保証機構 | 「`PasskeyDeleted` の直接購読者は `ShouldQueue` を実装しない (同期で走る)」ことは本ファイルの検査が deny-by-default で強制し続ける。顔ぶれ・購読順の完全一致 pin は、新しい購読者が増減したときに人手での確認 (同期性が保たれているか) を強制する仕組みとして機能する |
| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
| 決めた日 | 2026-08-21 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| pin の内容 | 汎用の骨組み (業務購読者無し) | 本アプリが `PasskeyDeleted` へ同期購読させた listener 全数の顔ぶれ・順序の固定値 |
| 更新頻度 | テンプレート更新時のみ | 同期購読 listener の追加・削除ごとに更新 (構造上恒常的) |

### なぜ正当な差分か (logic-driven)

本 pin は「新しい同期購読者を追加したら、それが本当に同期で走るかを人手で確認させる」という
deny-by-default 機構そのものであり、業務ドメイン (認証手段の変更に反応する処理) が
増える限り内容が変わり続けることが**設計の目的**である。今回 (T110) は
`NotifyAuthMethodChange` の追加 (2 件 → 3 件、購読順
`RecordSecurityEvent → NotifyAuthMethodChange → ClearRecentAuthOnPasskeyChange`) が
この恒常的な更新の 1 例である。

### 揃えている不変条件 (これは保証し続ける)

> 「`PasskeyDeleted` の直接購読者は全員 `ShouldQueue` を実装しない (同期で走る)。
> 顔ぶれと購読順は pin した値と完全一致する」

- `ShouldQueue` 実装の検出漏れは既存の検査が deny-by-default で検出する
- 本登録は「今後も内容が変わり続けること」を許容するものであり、
  内容そのものの正しさ (どの listener を同期購読させるべきかの妥当性) は
  人のレビュー対象のままである

### 保証しないもの

- 同期購読させる listener の選定判断の妥当性は本登録の対象外 (人のレビューが担う)
- 将来テンプレート側に同種の pin が持ち込まれた場合の統合可否は判断していない

### 関連

- 実装: `tests/Architecture/PasskeyPackageContractTest.php`
- 設計: `devnotes/20260821-2015-auth-method-change-notification/`

---

## D40 撤去表面の不在 gate を、走査根と走査器を共通基盤へ切り出した形で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/SurfaceRemoval/ContentClassification.php` / `tests/Support/SurfaceRemoval/MethodReference.php` / `tests/Support/SurfaceRemoval/MethodReferenceKind.php` / `tests/Support/SurfaceRemoval/MiddlewareReference.php` / `tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php` / `tests/Support/SurfaceRemoval/Occurrence.php` / `tests/Support/SurfaceRemoval/PhpNameResolver.php` / `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` / `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` / `tests/Support/SurfaceRemoval/RemovedTerm.php` / `tests/Support/SurfaceRemoval/ScanOutcome.php` / `tests/Support/SurfaceRemoval/ScanPopulation.php` / `tests/Support/SurfaceRemoval/ScannedFile.php` / `tests/Support/SurfaceRemoval/TermMatchMode.php` / `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` / `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php` |
| 業務要件起因の説明 | aicue が撤去した表面 (Fortify 標準のパスワード確認 step-up 機構 / OCR 機能フラグ) はテンプレートには存在しない。撤去物が 2 件あり、走査根 (`.github` と `scripts` を含む 8 本) の列挙と PHP の名前解決を 2 本持たないために共通基盤へ切り出す必要がある |
| 揃え続ける不変条件と保証機構 | 走査根に `.github/` と `scripts/` を含み `database/migrations/` を含まないこと、実走査母集団が根・種別ごとに非空で未解決もバイナリ除外も 0 件であること、静的層が許可形を 0 個で保つこと、検出器の自己検証を正例・負例・未解決の三軸で持つこと。`PasswordConfirmSurfaceAbsenceGateTest` と `OcrFeatureFlagAbsenceGateTest` が固定する |
| 再判定の条件 | 3 件目の撤去物が来て、撤去項目の台帳から層を機械駆動する形へ移すとき。またはテンプレートが同じ共通基盤を取り込んだとき (そのときは上積みを撤去して正典実装へ揃え直す) |
| 決めた日 | 2026-08-22 |
| 決めた人 | 開発者 |
| 根拠 | T250 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 走査根の持ち方 | 撤去 1 件ごとに gate 自身のファイル内へ走査を書く (`RetiredRecoveryReferenceGateTest`) | 走査根と走査器を `tests/Support/SurfaceRemoval/` へ切り出し、許可ポリシーは撤去物ごとの gate が指定する |
| 名前の突合 | 語彙一致中心 | クラス参照は完全修飾名へ解決してから突合する (`PhpNameResolver`)。解決できない形は未解決として gate を落とす |
| 母集団 | 拡張子で絞った列挙 | `git ls-files` から生成し拡張子で絞らない (`scripts/` の拡張子なし実行ファイルを落とさない) |

### なぜ正当な差分か (logic-driven)

同じ家系正典 (`surface-removal-absence-gate` v1) を満たす形は 1 つではない。テンプレートは
撤去物が 1 件のため gate のファイル内に走査を閉じているが、aicue は撤去物が **2 件**あり、
両者が同じ走査根 (8 本) と同じ PHP 名前解決を要る。ここで各 gate に走査を複写すると
「走査根の列挙を 2 本持つ」ことになり、AGENTS.md「静的検査 (gate) と走査器の共通規約」の
**走査根の単一出典**に反する。したがって共通基盤へ切り出す側を選んだ。

3 件目が来たら台帳駆動へ移す判断が要るが、2 件のために台帳機構を先回りして作るのは
思考原則 2 (今必要なものだけ作る) に反するため v1 では作らない。

### 揃えている不変条件 (これは保証し続ける)

> 「**各 gate が列挙した静的構文**への参照は、走査根 8 本の git 追跡下の全ファイルで 0 件である。
> 許可一覧は持たない (母集団の定義そのもので絞る)。解決できない形は未解決として gate を落とす」

保証するのは**列挙した構文**についてであり、「あらゆる書き方で 0 件」ではない
(変数・式・分割連結・定数経由・動的組み立ては母集団に入らない。下の「保証しないもの」を参照)。

- 母集団の空振り (走査根の改名・ディレクトリ移動) は代表パス pin と種別検査が検出する
- 検出力は見本 (`tests/Architecture/fixtures/surface-removal/`) の正例・負例・未解決で裏取りする
- NUL を 1 つ入れて静的層を迂回する経路は `binaryExcluded === []` の要求が塞ぐ

### 保証しないもの

- 静的層が見るのは列挙した構文だけである。middleware 位置の変数・式、分割連結、定数経由、
  動的組み立て、PHP のコメント内には沈黙する。網羅的な一覧の正本は
  `RemovedSurfaceScanner` と各 gate の docblock であり、ここには写さない
- 実行時層が補完するのは**テスト起動時に実体化した route** までで、環境依存で実体化しない
  経路 (production 限定の条件分岐・未実行コード) は両層とも見えない

### 関連

- 実装: `tests/Support/SurfaceRemoval/` / `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` / `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`
- 実行時層: `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`
- 設計: `devnotes/20260823-0016-password-confirm-surface-removal-gate-v1/`

---

## D41 シナリオカードの前付けは採るが、ステップ表の書式は採らない

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/skills/app-bug-hunt/stories/README.md` / `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py` |
| 業務要件起因の説明 | 所見台帳の finding は story までしか指さず step を指す欄を持たないため、ステップ識別子を入れても読む機械が 1 つも無い |
| 揃え続ける不変条件と保証機構 | 前付けの制限文法・番号規約・表 A / 表 B との突合は `stories/test_story_front_matter.py` が強制し、`BughuntStoryToolSelfTest` が composer test の配線に載せる |
| 再判定の条件 | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき / `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき |
| 決めた日 | 2026-08-22 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260823-0022-bughunt-story-front-matter-adoption/ |
| 状態 | 恒久 |
| 見直し期限 | — |

家系の正典 (機能台帳 `bughunt-story-front-matter` の t1) は、シナリオカードに制限文法の前付けを
置いて割当の正本にし、併せて**手順をステップ表の書式で書く**ことまでを 1 つの契約にしている。
本アプリは**前付けは全面的に採る**が、次の 2 点は採らないので登録する。

| 外している契約 | 本アプリの形 |
|---|---|
| ステップ表の書式 (正準 4 列ヘッダ `step / 操作 / 期待 / 注目` / 疎な step 識別子 `{id}-{3 桁}` / 副ブロック / 期待欄・注目欄の書き分け) | **散文の番号付きリストのまま**置く |
| `not_applicable` のカードを実走対象から外す契約 (`SKILL.md` 側が持つ) | **持たない** (該当カードが 0 枚) |

### なぜ正当な差分か (logic-driven)

1. **step 識別子を読む機械が 1 つも無い**。所見台帳の schema
   (`.claude/skills/app-bug-hunt/ledger/findings.schema.json`) は finding の位置を
   `story_id` / `route_name` / `capability_tag` で指し、**step を指す欄を持たない**。
   識別子を振っても照合器・抑制機構・目録のどれもそれで join しないので、
   増えるのは「振り直してはいけない番号」という保守債務だけである。
   正典が step を切ったのは finding が step を指す形を前提にしているためで、
   その前提が本アプリには無い。
2. **`not_applicable` の実走除外は該当カードが 0 枚である**。本アプリは家系必須 7 面の
   すべてに実カードがあり、`not_applicable` を取るカードは 1 枚も無い。
   **読む対象が 1 枚も無い契約を先回りして置かない** (思考原則 2「今必要なものだけ作る」)。
   置くべき時期は本エントリの再判定の条件が名指ししている — `applicability` に
   `not_applicable` を取るカードを 1 枚でも置くことになったときである。
   そのときの置き場は `SKILL.md` (実走の手順の正本) になる。

### 揃えている不変条件 (これは保証し続ける)

> 「**割当の正本はカードの前付けだけであり、前付けは制限文法と番号規約を機械で満たす**」

| 不変条件 | 担い手 |
|---|---|
| 前付けの制限文法 (区切り / 1 行 1 項目 / key の書式と重複 / 値の 3 形) | `.claude/skills/app-bug-hunt/stories/story_front_matter.py` |
| 必須 13 key の全数と正準順序・閉じた語彙・値の書式 | `stories/test_story_front_matter.py` (AC-01 / AC-02) |
| 番号規約 (命名 / 一意 / 欠番なし / 家系固定の `(id, surface)`) | 同上 (AC-03 / AC-06) |
| 表 A の構造と家系必須 11 語・表 B とカードの 1 対 1 | 同上 (AC-04 / AC-05) |
| 依存と実行方式の整合 (実在 / 自己参照 / 循環 / 初期化 / 直列待ち) | 同上 (AC-07 / AC-08 / AC-09) |
| 本文の確定形と旧メタ節の不在 (二重の正本を残さない) | 同上 (AC-10 / AC-11 / AC-12 / AC-15) |
| 採用した不変条件の全数点呼 (未割当 0 件・担い手の実在) | 同上 (AC-14) |
| 上記が `composer test` の下で実走し、検査を削って緑にできないこと | `tests/Architecture/BughuntStoryToolSelfTest.php` (件数の下限 + 中核負例の成功表示) |

### 保証しないもの (誇張しない)

- **ステップ表を採らない帰結**: step 識別子の再採番の禁止・副ブロックの個数・期待欄と注目欄の
  書き分けは 1 つも検査しない (概念ごと持たない)
- 兆候番号 (`H{n}`) の意味をカードに書かないことは**文書規約であり機械検査しない**
  (正典もこれ単独の検査は持たない)
- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
  (固定マップは前付けからの派生キャッシュ。**正典も未達**)
- `accounts` と `database/seeders/ManualTestSeeder.php` の一致は見ない (正典も同じ)
- `covers_*` の値の**実在**は前付け側では見ない (形だけ)。実在・欄の意味・分母の被覆は
  目録側 (D20) の責務である

### 関連

- 実装: `.claude/skills/app-bug-hunt/stories/` (README.md / story_front_matter.py /
  test_story_front_matter.py / S1〜S7 のカード)
- gate: `tests/Architecture/BughuntStoryToolSelfTest.php` /
  `tests/Support/Bughunt/StoryFrontMatterPins.php`
- 設計: `devnotes/20260823-0022-bughunt-story-front-matter-adoption/`

---

## D42 契約文書のゲート索引を、本アプリの実在ゲートへ写した判定規準として持つ

| 行 | 内容 |
|---|---|
| 対象パス | `docs/app-integration-guide.md` / `tests/Architecture/IntegrationGuideGateTableSyncTest.php` |
| 業務要件起因の説明 | 本文書の §2 は「新しいドメインリソースを足すときにどの検査へ登録するか」の索引であり、指す先が本アプリのゲート実体である以上、本アプリ固有のセキュリティ境界と実在ゲート名で構成するほかない。家系の裁定 AG-116 が定めた合成版の一部だが、テンプレート現物を参照できないため逐語復元ではなく判定規準としての写像である |
| 揃え続ける不変条件と保証機構 | 索引が指すゲート名の実在・件数 (必ず踏む 8 件 / 条件付き 13 件)・表をまたいだ一意性は `tests/Architecture/IntegrationGuideGateTableSyncTest.php` が固定し続ける。§7 の不変条件を参照するときは番号ではなく項目名で指す (本文書 §7 と AGENTS.md の採番は 1:1 対応しないため、どちらの側も renumber しない) |
| 再判定の条件 | テンプレート更新の一括取り込みを行うとき / 家系の巡回で裁定 AG-116 の合成版の現物が配られたとき / §2 のゲート表の行を増減させるとき。再照合の正本は家系の機能台帳 lctl の feature `app-integration-guide` とテンプレートの `docs/app-integration-guide.md` である。本登録を消せるのはファイル単位の不一致そのものが解消したときだけで、意味の一致だけでは消せない (下の「削除の判断基準」) |
| 決めた日 | 2026-08-22 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260822-2305-integration-guide-gate-table-restore/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-28 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| §2 のゲート索引 | 必ず踏む 8 本 / 条件付き 13 本 (台帳が記録する規模。行の中身は現物を参照できていない) | 同じ 8 件 / 13 件で、行は本アプリの実在ゲート名で構成する |
| 本アプリ由来の節 | 裁定 AG-116 に基づき還流済みの 3 節を持つ (エラー応答の優先順位 / テナント境界を経路解決の直後で閉じる / 新規ルート追加チェックリスト。実装は `T121` と `T132`) | 還流済みの 3 節に加えて「流量制限の付与規約」と「vendor route への後付け機構と経路キャッシュの契約」の 2 節を持つ (台帳が AG-116 の名指しした 3 節の外と明記) |
| §7 の採番 | 1〜11 | 1〜10 (renumber しない。相互参照は項目名で行う) |
| §9 (正本から生成し写しを同期検査する) | 持つ | 持たない (裁定 AG-116 が名指しした 3 節の外) |
| 索引と実装の同期検査 | 文書と実装ゲートの整合を見る gate を持つ | §2 の 2 表に限った実在・件数・一意性の検査を持つ |

### なぜ正当な差分か (logic-driven)

**本登録は「テンプレート現物が届くまでの監視中の登録」である。** 恒久の差分を主張するものではない。

逸脱が logic-driven なのは 2 点による。

1. **索引が指す先が本アプリのゲート実体である**。§2 の 2 表は「新しいドメインリソースを
   足すときにどの検査へ何を登録するか」を指すものなので、実在しないゲート名を指す索引は
   無価値になる。本アプリのゲート構成 (SOP・シナリオ・撮影テイクというテナントデータを
   守る境界の集合) はテンプレートの汎用形と同一ではないため、名前をそのまま写すことはできない。
2. **本アプリ由来の節は実測された監査所見への対処である**。「エラーを返す順番を間違えると
   他組織のデータの存在が 1 bit 漏れる」という所見と、その順番を機械で固定する規約であり、
   家系の裁定 AG-116 自身が「テンプレートに無いのは取りこぼしに近い」と評価して還流の対象にした。
   逸脱の理由は互換・UX・作業量ではない。

### 削除の判断基準 (この登録をいつ消すか)

**意味的な一致とファイル指紋の一致は別物である。** 突合 gate はファイル単位のハッシュを見るので、
同じ不変条件を同じ抽象度で要求していても、ゲート名や文章が違えば指紋は一致しない。
その状態で本登録だけを消すと、**未登録の不一致として再び赤くなる**。したがって本登録を消せるのは、
次のどれかによって**ファイル単位の不一致そのものが解消したとき**である:

1. 配布されたテンプレート現物を正規の取り込み手順で採用し、実ファイルが指紋台帳と一致した
2. 正規のテンプレート台帳更新 (`LedgerPins::TEMPLATE_LEDGER_SOURCE_*` の更新を伴う取り込み) により、
   本パスの新しい指紋が入って一致した
3. 別の承認済みの同期機構がファイル単位の不一致を解消した

**意味的な一致だけが確認できてファイル内容が異なる場合、登録簿の記録の原則の上では
「同じ不変条件」であっても、現行の指紋検査の上では D42 を削除できない。**
そのときに行うのは削除ではなく、本登録の説明を「意味は一致しており、残っているのは
表記の差である」旨へ更新して見直し期限を引き直すことである。
テンプレート現物を参照できない間は、**台帳で確認できる範囲を超えた現状断定をしない**。

### 揃えている不変条件 (これは保証し続ける)

> 「§2 のゲート索引が指すゲートは実在する。必ず踏む表は 8 件、条件付きの表は 13 件で、
> 同じゲートが 2 度現れない」

- 実在・件数・一意性は同期検査が deny-by-default ではなく**抽出した各行の未解決・不存在・
  件数不一致・重複を拒否する**形で固定する
- §7 を参照するときに番号を使わない規約は人のレビューが担う

### 保証しないもの

- **採用時ハッシュによる追跡を失う**。突合 gate は「登録済みのパスの追加の drift は検出しない
  (検出するのは一致から不一致へ移る瞬間である)。**債務パスは例外**で、採用時ハッシュとの
  一致まで見る」と定めており、債務から登録へ移した本パスは以後の内容変更を検出されない。
  再照合の契機は本登録の見直し期限とテンプレート更新の一括取り込みである
- 表に書かれた発火条件・登録先の**意味的な正確さ**は機械では見ない (同期検査の docblock が正本)
- 設計者が実際に §2 の判定を踏んだかは見ない (家系の正典が「それを確かめる機械は家系のどこにも
  無い」と記録しており、本登録はその状況を変えない)

### 関連

- 実装: `tests/Architecture/IntegrationGuideGateTableSyncTest.php`
- 設計: `devnotes/20260822-2305-integration-guide-gate-table-restore/`

---

## D43 組織文脈の共有プロパティを URL の binding だけから導出する

| 行 | 内容 |
|---|---|
| 対象パス | `app/Http/Middleware/HandleInertiaRequests.php` |
| 業務要件起因の説明 | 撮影は共用端末で行われ、直前の利用者が選んだ組織が画面に残ると別現場の手順書を撮ってしまう。組織文脈を保持列から消して URL だけで決めるため、共有プロパティの導出元をテンプレートの形から変える必要がある |
| 揃え続ける不変条件と保証機構 | 組織 route 以外では `currentOrganization` が必ず null になる。`OrganizationNavSharedPropsTest` と `CurrentOrganizationSharedPropShapeTest` が固定する |
| 再判定の条件 | テンプレート側が共有プロパティに組織文脈を持つ形を同梱したとき (現在は同梱しておらず、本アプリ固有の上積みである) |
| 決めた日 | 2026-08-23 |
| 決めた人 | 開発者 |
| 根拠 | T247 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 画面へ渡す組織文脈 | 持たない (共有プロパティは認証・flash・タイトル等の汎用のみ) | `currentOrganization` を **URL の binding から**導出して共有する。組織 route 以外では必ず null |

### なぜ正当な差分か(logic-driven)
家系の裁定 AG-037 は「いまどの組織かは URL だけで決まる。保持列と切替 endpoint は存在しては
ならない (2 方式の併存不可)」と定める。画面 (ナビ・リンク・権限フラグ) はその組織文脈を必要と
するので、**URL の binding から導出した値**を共有プロパティとして 1 か所で作る。
テンプレートは組織という概念を持たないためこの導出点を同梱しておらず、本アプリ固有の上積みになる。
保持列 (`users.current_organization_id`) と切替 endpoint は同じ変更で撤去した。

### 揃えている不変条件(これは保証し続ける)
> 「組織 route 以外では `currentOrganization` が必ず null になる
> (所属している組織のどれかを裏口から選ばない)」
`tests/Feature/Organizations/OrganizationNavSharedPropsTest.php` が組織 route 以外での null を、
`tests/Feature/Shared/CurrentOrganizationSharedPropShapeTest.php` が
キー集合と各値の型 (nullable も含む) を固定する。
撤去そのものの残骸は `tests/Architecture/CurrentOrganizationRemovalTest.php` が
3 つの形 (列名 / relation / 撤去した Service の FQCN) で 0 件を固定し、
撤去した route 名は `LegacyOrganizationlessUrlAbsenceTest` の撤去 route 名台帳が
追跡下ファイル全数で 0 件を固定する。

### 関連
- 実装: `app/Http/Middleware/HandleInertiaRequests.php`, `app/DataTransferObjects/Organizations/CurrentOrganizationData.php`
- 設計: `devnotes/20260823-0016-organization-tenancy-ag-catchup/`

---

## D44 テンプレート共有部の「組織文脈」前提を URL 単一方式へ書き換える

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/skills/app-bug-hunt/coverage/fixtures/operations.sample.md` / `.claude/skills/app-bug-hunt/ledger/test_validate_findings.py` / `app/Enums/Security/NestedRouteDefenseMode.php` / `app/Http/Middleware/RequireActiveSubscription.php` / `app/Http/Middleware/RequireRecentAuth.php` / `docs/default-team-pattern.md` / `docs/supported-browsers.md` / `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` / `tests/Architecture/AccountDeletionPathGateTest.php` / `tests/Architecture/ControllerAuthorizationGateTest.php` / `tests/Architecture/FlashNotificationRelayDriftTest.php` / `tests/Architecture/RateLimiterKeyConventionTest.php` / `tests/js/setup.ts` |
| 業務要件起因の説明 | 撮影は共用端末で行われ、直前の利用者が選んだ組織が残ると別現場の手順書を撮ってしまう。組織文脈を URL 単一方式へ揃えた結果、テンプレート共有部が持っていた「組織は保持列から取る」「業務 route は組織セグメントを持たない」という前提が成り立たなくなった |
| 揃え続ける不変条件と保証機構 | 業務 route は 1 本残らず組織 URL 配下にあり (`OrganizationScopedRouteCoverageTest`)、課金ゲートと render-trigger は組織 binding が無ければ fail-closed になる (`BillingGateRouteOrganizationParamTest` / `RenderTriggerRouteOrganizationParamTest`)。撤去した保持列の残骸は `CurrentOrganizationRemovalTest` が 3 つの形で、撤去した切替 route 名と旧 URL は `LegacyOrganizationlessUrlAbsenceTest` が追跡下ファイル全数で 0 件に固定する |
| 再判定の条件 | テンプレート側が組織テナンシーを同梱し、組織文脈の取得元を規定したとき (現在は組織という概念自体を同梱していない) |
| 決めた日 | 2026-08-23 |
| 決めた人 | 開発者 |
| 根拠 | T247 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 業務 route の URL | 組織セグメントを持たない (`projects/{project}` 等) | `/organizations/{organization:slug}/…` 配下に全数を置く。route 名は不変 |
| 課金ゲートの組織解決 | 保持列 (利用者に紐づく現在組織) から取り、無ければ素通し | URL の binding だけから取り、無ければ fail-closed |
| 認証直後・エラー画面の着地 | ダッシュボードへ固定 | 組織文脈を持たない**分岐入口** (`/go`) へ倒す (所属数で分岐する) |
| 画面テストの既定の現在地 | 指定しない | 組織 URL 配下を既定にする (URL から組織を読む helper があるため) |

### なぜ正当な差分か(logic-driven)
家系の裁定 AG-037 は「いまどの組織かは **URL だけ**で決まる。保持列と切替 endpoint は
存在してはならない (2 方式の併存不可)」と定める。テンプレートは組織という概念を同梱しないため、
共有部の記述は「組織を持たないアプリ」の形のままであり、そのままでは
**組織を持つ本アプリで矛盾する** (課金ゲートが組織を解決できず、認証直後の着地が
どの組織かを決められない)。**互換や作業量ではなく、裁定に従うために**書き換えている。

ここに挙げた 9 パスはいずれも「組織の取得元」または「業務 route の URL 形」に触れる箇所だけで、
テンプレートの構造 (層の並び・middleware の順序・gate の判定方式) は 1 つも変えていない。

### 揃えている不変条件(これは保証し続ける)
> 「業務 route は 1 本残らず組織 URL 配下にあり、組織文脈は URL の binding からのみ導出する。
> 組織 binding を持たない経路が課金ゲート・レート制限の下に入ったら fail-closed で落ちる」
`tests/Architecture/OrganizationScopedRouteCoverageTest.php` /
`tests/Architecture/BillingGateRouteOrganizationParamTest.php` /
`tests/Architecture/RenderTriggerRouteOrganizationParamTest.php` /
`tests/Architecture/CurrentOrganizationRemovalTest.php` が機械で固定する。

### 関連
- 実装: `routes/web.php`, `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php`
- 設計: `devnotes/20260823-0016-organization-tenancy-ag-catchup/`
- 同じ変更で採用時債務一覧 (D34) から上記のパスを外した (説明が付いたため)
- `docs/app-integration-guide.md` も同じ変更で組織文脈の記述を URL 単一方式へ書き換えたが、
  対象パスには挙げない。同ファイルは **D42** が既に逸脱として登録しており、
  台帳の書式が対象パスの重複を禁じているためである (片方を消しても赤にならなくなる)

## D45 route binding param の型台帳は業務追加ごとに更新され続ける台帳である

| 行 | 内容 |
|---|---|
| 対象パス | `app/Http/Routing/RouteBindingTypes.php` |
| 業務要件起因の説明 | 本ファイルは全 route binding param を 5 分類 (BIGINT / UUID / CUSTOM_BINDER / NON_MODEL / EXTERNAL) のいずれかへ登録させる deny-by-default の単一台帳である。新しい業務リソースの route を足すたびに param と対応モデルの登録が要る、**業務ドメインの拡張に追随して恒常的に更新され続ける**設計であり、テンプレートの汎用形 (アプリのモデルを 1 つも知らない骨組み) にも「採用時点の姿」にも収束しない。採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
| 揃え続ける不変条件と保証機構 | 未登録 param の出現は `RouteBindingTypeConstraintInventoryTest` (IV-1) が deny-by-default で落とす。分類の重複・pattern の未適用・binder の実在・doc との双方向同期は同テストと `RouteBindingCustomBinderDocSyncTest` が強制し続ける |
| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
| 決めた日 | 2026-08-23 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260823-0015-enterprise-oidc-sso-adoption/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 台帳の内容 | 汎用の骨組み (アプリのモデルを持たない) | 本アプリの全 binding param と対応モデルを蓄積した台帳 |
| 更新頻度 | テンプレート更新時のみ | 新規 route の追加ごとに更新 (構造上恒常的) |

### なぜ正当な差分か (logic-driven)

D37 / D38 と同じ理由である。本台帳は「新しい route の param を足したら型と解決方式を宣言させる」
という deny-by-default 機構そのものであり、業務ドメインが拡張し続ける限り内容が増え続けることが
**設計の目的**である。今回 (T253) は企業 OIDC SSO の `{oidcConnection}` (BIGINT) と
`{connection}` (CUSTOM_BINDER) の登録がこの恒常的な更新の 1 例である。

### 揃えている不変条件 (これは保証し続ける)

> 「全 binding param が 5 分類のいずれかに登録され、BIGINT / UUID には型 pattern が当たる」

- 分類漏れ・pattern の未適用は `RouteBindingTypeConstraintInventoryTest` が deny-by-default で検出する
- 本登録は「今後も内容が変わり続けること」を許容するものであり、
  個々の分類の妥当性は人のレビュー対象のままである

### 保証しないもの

- どの param をどの分類にするかの判断の妥当性は本登録の対象外
- 将来テンプレート側に同種の台帳が持ち込まれた場合の統合可否は判断していない

### 関連

- 実装: `app/Http/Routing/RouteBindingTypes.php`
- 設計: `devnotes/20260823-0015-enterprise-oidc-sso-adoption/`

## D46 キャッシュ素データ規約の書き込み経路の目録は業務追加ごとに更新され続ける台帳である

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/CachePayloadPlainDataGateTest.php` |
| 業務要件起因の説明 | 本ファイルはキャッシュへ書く全経路とキャッシュに触れる全ファイルを exact-fit の目録で分類する deny-by-default の静的層である (AGENTS.md セキュリティ不変条件 11)。新しくキャッシュを使う業務経路を足すたびに payload の形・往復の証明・役割の登録が要る、**業務ドメインの拡張に追随して恒常的に更新され続ける**設計であり、テンプレートの汎用形にも「採用時点の姿」にも収束しない。採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
| 揃え続ける不変条件と保証機構 | 未登録の書き込み経路・未登録のキャッシュ接触ファイルは同ファイルの検査 2 / 検査 4 が exact-fit で落とす。payload が素のデータだけであることの実挙動は実行時層 (`PlainDataCacheGuard`) が別途受ける |
| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
| 決めた日 | 2026-08-23 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260823-0015-enterprise-oidc-sso-adoption/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 目録の内容 | 汎用の骨組み (業務の書き込み経路を持たない) | 本アプリのキャッシュ書き込み経路と接触ファイルを蓄積した目録 |
| 更新頻度 | テンプレート更新時のみ | 新規キャッシュ経路の追加ごとに更新 (構造上恒常的) |

### なぜ正当な差分か (logic-driven)

D37 / D38 と同じ理由である。本目録は「キャッシュへ書く経路を足したら payload の形と
往復の証明を書かせる」という deny-by-default 機構そのものであり、業務ドメインが拡張し続ける限り
内容が増え続けることが**設計の目的**である。今回 (T253) は企業 IdP の接続先情報と公開鍵の
キャッシュ経路 (`OidcDiscoveryService`) の登録がこの恒常的な更新の 1 例である。

### 揃えている不変条件 (これは保証し続ける)

> 「キャッシュへ入れるのは素のデータだけであり、書き込み経路と接触ファイルは
> 目録と exact-fit で一致する」

- 未登録の経路・ファイルは同ファイルの検査が deny-by-default で検出する
- 実挙動 (実行時層が違反値を落とすこと) は `PlainDataCacheGuard` が担う

### 保証しないもの

- 各 entry の payload 記述と rationale の妥当性は本登録の対象外 (人のレビューが担う)
- vendor が `getStore()` 経由で書く値は静的層・実行時層のどちらからも見えない (元の gate の限界)

### 関連

- 実装: `tests/Architecture/CachePayloadPlainDataGateTest.php`
- 設計: `devnotes/20260823-0015-enterprise-oidc-sso-adoption/`

## D47 step-up 必須 route の allowlist は業務追加ごとに更新され続ける台帳である

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/RecentAuthRouteTest.php` |
| 業務要件起因の説明 | 本ファイルは「再認証 (step-up) を必須にする機微操作 route」の allowlist を持ち、宣言と実際の middleware 付与を双方向で突き合わせる。機微操作の route を足すたびに 1 行の追加が要る、**業務ドメインの拡張に追随して恒常的に更新され続ける**設計であり、テンプレートの汎用形 (アプリの route 名を 1 つも知らない骨組み) にも「採用時点の姿」にも収束しない。採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
| 揃え続ける不変条件と保証機構 | allowlist に載せた route に `recent-auth` が実際に付いていること、および allowlist の route が実在することを同ファイルが双方向で強制する。判定の実体は `Tests\Support\Security\RecentAuthMiddleware` に単一化され、`TwoFactorStepUpInventoryTest` と同じ述語を共有する |
| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
| 決めた日 | 2026-08-23 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260823-0015-enterprise-oidc-sso-adoption/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| allowlist の内容 | 汎用の骨組み (アプリの route 名を持たない) | 本アプリの機微操作 route 全数を蓄積した allowlist |
| 更新頻度 | テンプレート更新時のみ | 新規の機微操作 route の追加ごとに更新 (構造上恒常的) |

### なぜ正当な差分か (logic-driven)

D37 / D38 と同じ理由である。本 allowlist は「機微操作の route を足したら step-up を宣言させる」
という機構そのものであり、業務ドメインが拡張し続ける限り内容が増え続けることが**設計の目的**である。
今回 (T253) は企業 SSO 接続の管理 6 本とメール昇格の発行・再送 2 本の追加が
この恒常的な更新の 1 例である。**確認 (confirm) を足していない**のも同じ台帳の判断であり、
救済経路に関門を足すと確定できず詰むためである。

### 揃えている不変条件 (これは保証し続ける)

> 「allowlist に載せた機微操作 route には `recent-auth` 系 middleware がちょうど 1 種類付く」

- 付与漏れ・allowlist の陳腐化 (削除済み route の残置) は同ファイルが双方向で検出する
- 判定の述語は `TwoFactorStepUpInventoryTest` と共有され、片方だけが真になる drift は起きない

### 保証しないもの

- どの route を allowlist に載せるかの判断の妥当性は本登録の対象外 (人のレビューが担う)
- 名前ベースのセレクタなので、別名の route で第二要素へ触る経路には沈黙する (元の gate の限界)

### 関連

- 実装: `tests/Architecture/RecentAuthRouteTest.php`
- 設計: `devnotes/20260823-0015-enterprise-oidc-sso-adoption/`

## D48 企業 SSO のログイン試行を DB の表で保管し、一時トークンを用途別の指紋で扱う

| 行 | 内容 |
|---|---|
| 対象パス | `app/Models/EnterpriseSsoLoginAttempt.php` / `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` / `app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php` / `app/DataTransferObjects/EnterpriseSso/AttemptConsumeResult.php` / `app/Support/EnterpriseSso/AttemptFingerprint.php` / `app/Enums/EnterpriseSso/FingerprintPurpose.php` / `app/Console/Commands/EnterpriseSso/PruneLoginAttemptsCommand.php` / `database/migrations/2026_08_23_001300_create_enterprise_sso_login_attempts_table.php` / `database/factories/EnterpriseSsoLoginAttemptFactory.php` / `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php` / `tests/Feature/EnterpriseSso/PruneLoginAttemptsTest.php` / `tests/Architecture/EnterpriseSsoPruneScheduleTest.php` |
| 業務要件起因の説明 | 正典はログイン試行の保管先を表として持たない。aicue は `state` の使用権の唯一性を**セッションドライバの種別と `->block()` の書き忘れに依存させない**ため、DB の一意制約と行ロックへ寄せた。あわせて**一時トークンの指紋方式** (用途ラベルで domain separation する導出) を機構横断の部品として持つ — 企業 SSO のログイン試行とメールアドレスの昇格が同じ導出を使う |
| 揃え続ける不変条件と保証機構 | 「同じ試行の使用権をちょうど 1 つの要求だけが得る」「その試行を開始したブラウザだけが使える」を `EnterpriseLoginAttemptStoreTest` の並行検査と別ブラウザ検査が固定する。用途別の指紋が相互に使い回せないことは `AttemptFingerprintTest` が実挙動で固定する |
| 再判定の条件 | 本形が正典へ還流されて正典側の版が上がったら、独自差分ではなく新しい正典追従になるので登録を消す。また正典が同等の原子性とブラウザ結合を別方式で持ったときも見直す。★**メールアドレスの昇格の側が正典で指紋方式を採ったときも見直す** (本登録は機構横断の一時トークンの指紋方式を含むため、昇格側だけが正典化したら対象パスの線引きを引き直す) |
| 決めた日 | 2026-08-23 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260823-0015-enterprise-oidc-sso-adoption/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-08-23 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| `state` の保管先 | セッション | DB の表 (一意制約 + 行ロック) |
| 一時トークンの保存 | 原文 | 用途別ラベルつきの指紋のみ (PKCE の検証子だけは暗号化して原文) |
| 使用権の唯一性の根拠 | セッションの読み書き | `state_fingerprint` の一意制約と `SELECT … FOR UPDATE` |

### なぜ正当な差分か (logic-driven)

同一セッションへの並行要求は route 側で `->block()` を書かない限り直列化が保証されない。
つまりセッション方式は「普通の `get()` + `forget()` を書いても契約を満たしたと誤認できる」形であり、
**書き忘れが無音で不変条件を壊す**。DB の一意制約と行ロックへ寄せれば、
使用権の唯一性の根拠がドライバ設定と呼び出し側の作法から独立する。

`routes/console.php` を対象パスに**入れない**のは、既存の共有ファイルであり、掃除の登録 1 行の
ために全体を本登録の対象にすると、この 1 ファイルを触る将来の逸脱と必ず衝突するためである
(値域の要件「全登録の和集合で重複しない」)。**追跡は切れない** — 掃除の本体は
`PruneLoginAttemptsCommand` (本登録の対象) に在り、`routes/console.php` の 1 行はその
呼び出しの登録にすぎない。

`EnterpriseSsoLoginController` / `EnterpriseCallbackAuthenticator` を入れないのは、
**正典にも在る資産**だからである (保管先の実装が違うだけ)。逸脱は「保管先を表にしたこと」であって
controller の存在ではない。

### 揃えている不変条件 (これは保証し続ける)

> 「同じ試行の使用権を、ちょうど 1 つの要求だけが得る。
> かつ、その試行を開始したブラウザだけが使える」

- 使用権の唯一性は実プロセスの並行検査が、ブラウザ結合は別セッションからの検査が固定する
- 期限切れ行はオンアクセスと日次の掃除の二段で回収する (**即時削除ではない**)

### 保証しないもの

- セッション cookie ごと奪われた場合のブラウザ結合は破れる (結合はセッションの秘密に依存する)
- `APP_KEY` のローテートで進行中の試行 (10 分) と未確認の昇格 (60 分) は失効する。
  **永続する値 (`subject`) は指紋を使わない**ので、ローテートで失われるのはこれだけである

### 関連

- 実装: `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php`
- 設計: `devnotes/20260823-0015-enterprise-oidc-sso-adoption/`
