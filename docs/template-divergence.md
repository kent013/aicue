# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。

登録エントリ: 23 件

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

- 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの) は検出できない。
  実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
- 内容としてテンプレート準拠へ戻したのにファイルが残っている登録も検出できない
- 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
  再利用しないことは人が守る規約である)

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

## D4 web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-current-org)

| 行 | 内容 |
|---|---|
| 対象パス | `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php` / `routes/web.php` |
| 業務要件起因の説明 | FormRequest の DB ルールは controller の inline guard より前に走り、他組織の project に対する 422 と 404 の差がカテゴリ名や所属関係を辞書探索できる存在オラクルになる |
| 揃え続ける不変条件と保証機構 | 他組織の project は FormRequest を含むあらゆるアプリコードより前に 404。`ProjectRouteCurrentOrgGuardTest` が deny-by-default で強制する |
| 再判定の条件 | web と API v1 で project の解決モデルが 1 つに揃ったとき (binder 化を再検討できる) |
| 決めた日 | 2026-07-10 |
| 決めた人 | 開発者 |
| 根拠 | T001 |
| 状態 | 恒久 |
| 見直し期限 | — |

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
| 再判定の条件 | 非トランザクションのテストレーンを導入したとき (別プロセスでの実証へ移す) |
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

### 関連

- 実装: `app/Services/Manual/RenderJobService.php` (triggerPreview)
- 設計: `devnotes/20260711-0549-render/detailed-design.md` 施策 4 テスト計画

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
| メンバー管理 UI | `Organizations/Settings.svelte` に組織設定と同居 | 管理メニュー専用画面 `Admin/Users` (GET `/manage/users`) へ移設。Settings は組織設定 (名称 / 2FA 方針 / API キー導線 / オーナー移譲) のみ |
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
- `/manage/` 配下 route の auth+verified は **`ManageRouteAuthGuardTest`** が deny-by-default で強制
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
| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` |
| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する |
| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う |
| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき |
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

### 保証しないもの (誇張しない)

- **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
  未実行側へ倒れる (過小申告の方向)
- **部分欠測は検出しない**。分かるのは「名前付き route の行が 1 件も無い」「別 run が混ざった」
  「失敗マーカーが残せた」まで
- **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない

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
| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` |
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
| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない |
| 生成物が再生成の結果と byte 一致すること (段 3) | 同上 (exit 3)。手編集と再生成の忘れをまとめて捕まえる |
| 機能カタログの代表機構が実在し、id が重複しないこと (段 4) | 同上 (exit 3) |
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
目録の母集合は T164 の記録器が観測しうる route の**部分集合**であり、両者は一致しない。

### 再検討の条件 (解消条件)

- 家系の正典が id 列を持つ形へ変わったとき (機能カタログの生成を採り直す)
- 本リポジトリの Python に依存を足す裁定が出たとき (注釈を YAML へ寄せる)
- 中間 JSON を読む道具が家系に現れたとき

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
