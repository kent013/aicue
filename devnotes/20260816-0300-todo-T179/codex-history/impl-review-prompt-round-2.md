Round 1 の指摘への対応を報告する。対応マトリクスと、Round 1 以降の差分だけを載せる。

# 対応マトリクス: impl-review Round 1

## [Critical] D22 / D23 が施策 5 の判定規則と矛盾している (テンプレートに無い = 追加機能では)

- 判断: **対応する** (指摘の核は正しい。ただし登録そのものは維持する)
- 根拠: 指摘のとおり、本文が「相当する仕組みを持たない」としか書いていないと、
  読み手には追加機能との区別が付かない。一方で判定の出所は本アプリの自己判断ではなく
  **台帳リポジトリの巡回 (2026-08-12) が「記録されるべき乖離」として受信箱へ届けた判定**であり、
  概念設計が定める「(a) 台帳リポジトリから届いた判定」に当たる。
  統一形式の不変条件 i14 も「ひな形が家系の先例から外れた判断」を守備範囲に含めている。
  D22 / D23 はどちらもオーナー決定で、家系の別リポジトリの先例に揃えた形である。
- 対応内容:
  - D22 / D23 の本文へ「登録する理由 (テンプレートに相当物が無いのに登録する根拠)」の段落を足し、
    判定の出所・決定者・i14 との対応・過剰検出寄りに倒した理由を明記した
  - 比較表のテンプレート列を「相当する仕組みを持たない (退会の入口も猶予も凍結も同梱していない)」
    のように、何を持たないかまで書く形へ直した
  - 登録簿の「記録の原則」へ**迷ったら登録する**の 1 行を足し、同じ迷いが再発したときの
    倒し方を規約側に置いた
  - なお「テンプレートは退会 = 行の破棄を持つ」という書き方は**採らなかった**。
    実物を読めないので確認できず、確認していないことを書くのは規約違反になる

## [Warning] D23 の対象パスが実体を十分に表していない

- 判断: **対応する**
- 根拠: 対象パス欄は重複検査と実在検査の入力であり、掃除器群が消えたときに赤くならないのは
  掃除漏れの検出が効かないのと同じである
- 対応内容: 対象パスへ列挙型 2 本 + 登録簿 (registry) + 掃除器の共通形 + 個別の掃除器 7 本を
  実ファイル名で展開した (計 11 パス)

## [Warning] 囲みの受理が仕様より広い (```php も開閉として扱う)

- 判断: **対応する**
- 根拠: 指摘のとおり。設計は「行頭のバッククォート 3 個ちょうど」だけを許す方針で、
  言語名つきを黙って受けると fail-closed の方針とずれる
- 対応内容: 開閉に使えるのを `^```\s*$` だけに絞り、言語名などを添えた囲みは
  **P3 の違反として明示的に拒否**するようにした (閉じる側の判定も同じ形に揃えた)

## [Warning] 登録メタ表 9 行ちょうどの検査に抜けがある (3 列以上の 10 行目が通る)

- 判断: **対応する**
- 根拠: 指摘のとおり。列を増やした 10 行目が比較表に紛れて通るのは、
  「9 行ちょうど」という不変条件が守れていない状態である
- 対応内容: 9 行の直後の行が縦棒で始まっていたら列数によらず違反にした
  (メタ表の直後には空行を置く、という形へ規約側も揃えた)

## [Warning] 上記 2 点の負例が不足している

- 判断: **対応する**
- 対応内容: 単体テストへ次を追加した
  - 囲み: `` ```php `` と `` ``` markdown `` が P3 で拒否されること
  - メタ表: 2 列の 10 行目と**3 列の 10 行目 (比較表と同じ列数)** の両方が落ちること

## [Suggestion] 判定器 / 検査層 / DTO 群 / AGENTS.md / guide は問題なし

- 判断: 対応不要 (現状維持)


## Round 1 以降の差分 (git diff。Round 1 で送った状態からの差分)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 99f47a0..2dde794 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -4,17 +4,83 @@ # テンプレート差分レジストリ
 逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
 逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。
 
+**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
+`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
+`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
+
+登録エントリ: 23 件
+
 ## 記録の原則
 
 - 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
   不変条件が揃っていれば構文差は許容
-- 各エントリには (a) なぜ logic-driven か (b) テンプレートの不変条件をどの機構で保証し続けるか
-  を必ず書く
+- **登録は逸脱を作る変更そのものに含める**。後でまとめて書かない。まだ実在しない逸脱
+  (これから作る予定) は登録しない — 予定の管理は `docs/TODO.md` の役目である
+- **解消した逸脱は登録から消す**。全パスが戻ったならエントリごと、一部が戻ったなら
+  そのパスを対象パス欄から削る。状態の語で「解消済み」を表さない。
+  台帳の中に履歴の節を作らない (走査の対象外になる領域は回避口になるため)
+- 番号 (`D<n>`) は**再利用しない**。削除しても後続を詰めない (欠番は正常)。
+  他リポジトリから参照するときは `aicue:D<n>` と書く
+- **登録するか迷ったら登録する**。テンプレートの実物は手元に無いので「テンプレートに無い領域への
+  上積み」か「ひな形から外れた判断」かを本アプリだけで確定できないことがある。
+  誤登録は 1 行消せば済むが、登録漏れには気付けない。台帳リポジトリの巡回から
+  「記録されるべき乖離」として届いた指摘は、この理由で登録する側へ倒す
+
+## 登録メタ表 (9 行ちょうど・この順序)
+
+| 行 | 値域 |
+|---|---|
+| 対象パス | リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上。区切りは半角スペースとスラッシュと半角スペース。glob・絶対パス・上位への相対指定は不可。ファイルとして実在すること。**全登録の和集合で重複しないこと** |
+| 業務要件起因の説明 | なぜドメイン要件のせいでテンプレートの形から外れたか (1〜2 文) |
+| 揃え続ける不変条件と保証機構 | 何を揃え続け、どの機構が保証するか |
+| 再判定の条件 | 何が変わったら見直すか (**恒久の登録にも必須**) |
+| 決めた日 | `YYYY-MM-DD`。逸脱を最初に決めた日 (再判断で書き換えない)。未来日は不可 |
+| 決めた人 | `オーナー` / `開発者` |
+| 根拠 | `T<n>` (3 桁以上のゼロ埋め。`docs/TODO.md` / `docs/TODO-closed.md` の表に実在) または `devnotes/<dir>/` (ディレクトリが実在) |
+| 状態 | `恒久` / `監視中` |
+| 見直し期限 | `監視中` は `YYYY-MM-DD` (基準日から 400 日以内)。`恒久` は全角ダッシュ 1 文字 |
+
+- **`恒久` も `監視中` も「今ある逸脱」を表す**。解消を意味する語は値域に無い
+- `監視中` にするのは、期限付きで能動的に見直す根拠 (期限・予定時期・追跡中の事象) が
+  あるときだけである。解消の条件が書けることは `監視中` の根拠にならない
+  (`恒久` の登録も再判定の条件を必ず持つので、条件の有無は区別にならない)
+- セルの中に縦棒を書かない (エスケープしても解釈しない)。表の区切りを使いたくなる内容は
+  エントリ本文の節へ書く
+
+## 見直し期限が切れたときの直し方 (4 通り)
+
+1. 逸脱を解消して登録を消す
+2. `恒久` へ変えて理由を足す
+3. 期限を延ばして再判断の根拠を足す
+4. 対象を分けて個別に判断する
+
+**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。
+
+## この登録簿が保証しないもの
+
+- 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの) は検出できない。
+  実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
+- 内容としてテンプレート準拠へ戻したのにファイルが残っている登録も検出できない
+- 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
+- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
+  再利用しないことは人が守る規約である)
 
 ## エントリ形式
 
 ```
-## D1 ✅ <逸脱の要約>
+## D1 <逸脱の要約>
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Example.php` |
+| 業務要件起因の説明 | ... |
+| 揃え続ける不変条件と保証機構 | ... |
+| 再判定の条件 | ... |
+| 決めた日 | 2026-01-01 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -29,12 +95,23 @@ ### 揃えている不変条件(これは保証し続ける)
 
 ### 関連
 - 実装: ...
-- テンプレート側の根拠: ...
 ```
 
 ---
 
-## D1 ✅ Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)
+## D1 Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Models/Cut.php` / `app/Models/Take.php` |
+| 業務要件起因の説明 | 中核集約のスキーマをフェーズ 1 で確定させ、後続フェーズが列追加なしに振る舞いだけを足せるようにするため、route と UI を伴わないモデルを先に置いた |
+| 揃え続ける不変条件と保証機構 | route を張った時点で `NestedRouteIdorDefenseTest` の登録と relation 経由解決を同時に行う。保護キーの不含は `MassAssignmentSafetyTest` が走査する |
+| 再判定の条件 | Cut / Take に route と UI が付いたとき (SourceDocument と同じく本登録から外す) |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -67,7 +144,19 @@ ### 関連
   `resources/js/components/features/manual/SourceDocumentUpload.svelte`
 - 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策2/4/5
 
-## D2 ✅ 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)
+## D2 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `database/migrations/2026_07_10_000300_create_cuts_table.php` / `database/migrations/2026_07_10_000500_add_foreign_keys_to_cuts_table.php` |
+| 業務要件起因の説明 | 循環 FK と自己参照 FK は単一の CREATE では構築できず DB 実装に依存して不安定になるため、cuts → takes → FK 後付けの 3 段に分けた |
+| 揃え続ける不変条件と保証機構 | 親削除時の参照整合 (nullOnDelete) と down() の逆順 drop。`RefreshDatabase` が全 Feature テストで up を暗黙検証する |
+| 再判定の条件 | cuts と takes の循環参照が解消されたとき / 採用する DB が単一 CREATE での循環 FK を扱えるようになったとき |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -85,7 +174,19 @@ ### 揃えている不変条件(これは保証し続ける)
 ### 関連
 - 実装: `database/migrations/2026_07_10_000300_create_cuts_table.php` / `..._000500_add_foreign_keys_to_cuts_table.php`
 
-## D3 ✅ Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)
+## D3 Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Manual/CategoryService.php` / `app/Models/Category.php` |
+| 業務要件起因の説明 | 並べ替えは「送信 id 集合 = project の Category 集合」という集合契約で成立するため、任意の並び順を payload から受けると契約を迂回して順序が破綻する |
+| 揃え続ける不変条件と保証機構 | create / update / reorder / delete は Project 行ロック下で直列化され、sort_order は project 内で一意な並びを保つ。`CategoryReorderTest` が固定する |
+| 再判定の条件 | 並べ替えを行単位の操作として外部へ開く要件が出たとき |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -105,7 +206,19 @@ ### 関連
 - 実装: `app/Services/Manual/CategoryService.php`, `app/Models/Category.php`
 - 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策7
 
-## D4 ✅ web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-route-org)
+## D4 web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-route-org)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php` / `routes/web.php` |
+| 業務要件起因の説明 | FormRequest の DB ルールは controller の inline guard より前に走り、他組織の project に対する 422 と 404 の差がカテゴリ名や所属関係を辞書探索できる存在オラクルになる |
+| 揃え続ける不変条件と保証機構 | 他組織の project は FormRequest を含むあらゆるアプリコードより前に 404。`ProjectRouteCurrentOrgGuardTest` が deny-by-default で強制する |
+| 再判定の条件 | web と API v1 で project の解決モデルが 1 つに揃ったとき (binder 化を再検討できる) |
+| 決めた日 | 2026-07-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T001 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -134,7 +247,19 @@ ### 関連
 - 実装: `app/Http/Middleware/EnsureProjectBelongsToRouteOrganization.php`, `routes/web.php`, `bootstrap/app.php`
 - テンプレート側の根拠: `docs/app-integration-guide.md` §2 (URL 整合 guard 行を 2 層構成に更新済み)
 
-## D5 ✅ Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)
+## D5 Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Manual/ScenarioService.php` / `app/Http/Requests/Projects/UpdateScenarioRequest.php` / `app/Http/Controllers/Projects/ManualScenarioController.php` |
+| 業務要件起因の説明 | シナリオ編集は親子カスケードと並べ替えを伴うため、行単位の CRUD では原子性が壊れ、編集途中の中間状態がサーバへ漏れる |
+| 揃え続ける不変条件と保証機構 | 保護キー不信 / 認可より前の 404 / relation 経由の作成を document 保存でも同じ機構で維持する。`ScenarioUpdateTest` と `NestedRouteIdorDefenseTest` が固定する |
+| 再判定の条件 | シナリオを行単位で編集する要件が出たとき / 楽観ロックを別の同時編集制御へ置き換えるとき |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T002 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -161,7 +286,19 @@ ### 関連
 - 実装: `app/Services/Manual/ScenarioService.php`, `app/Http/Requests/Projects/UpdateScenarioRequest.php`, `app/Http/Controllers/Projects/ManualScenarioController.php`
 - 設計: `devnotes/20260711-0007-scenario-editing/detailed-design.md`
 
-## D6 ✅ presigned PUT の署名対象は ChecksumSHA256 のみ (Content-Type/Length は HeadObject 照合が担う)
+## D6 presigned PUT の署名対象は ChecksumSHA256 のみ (Content-Type/Length は HeadObject 照合が担う)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Capture/TakeObjectStorage.php` |
+| 業務要件起因の説明 | AWS SDK の presign は Content-Type と Content-Length を署名対象から外すため、置ける内容を 1 通りに固定する保証はハッシュの署名だけで成立させる必要がある |
+| 揃え続ける不変条件と保証機構 | presigned URL で登録済みオブジェクトを別内容に差し替えられない。`TakeObjectStorageTest` が署名対象を、`TakeRegistrationTest` が登録時の三点照合を固定する |
+| 再判定の条件 | SDK が署名対象ヘッダの扱いを変えたとき / 登録時の三点照合を外すとき |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T004 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | 設計書 (T004 detailed-design) | 実装 |
 |---|---|---|
@@ -184,7 +321,19 @@ ### 関連
 - 実装: `app/Services/Capture/TakeObjectStorage.php`
 - 設計: `devnotes/20260711-0345-capture-pwa/detailed-design.md` 施策3
 
-## D7 ✅ org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留 (逐次境界テストで代替)
+## D7 org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留 (逐次境界テストで代替)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Manual/RenderJobService.php` / `tests/Feature/Manual/RenderPreviewConcurrencyTest.php` |
+| 業務要件起因の説明 | `RefreshDatabase` が検体を未コミットのトランザクション内に置くため、別プロセスからは検体が見えず、直列化の実証には非トランザクションの専用レーンが要る |
+| 揃え続ける不変条件と保証機構 | 組織ごとの同時 preview 上限の検査とジョブ作成は Organization 行ロック下で行う。逐次境界は `RenderPreviewConcurrencyTest` が固定する |
+| 再判定の条件 | 非トランザクションのテストレーンを導入したとき (別プロセスでの実証へ移す) |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T005 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | 設計 (T005 詳細設計 施策 4/15) | 本アプリ |
 |---|---|---|
@@ -218,7 +367,19 @@ ### 関連
 
 ---
 
-## D8 ✅ 管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings からの UI 移設
+## D8 管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings からの UI 移設
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Enums/AdminConsoleRole.php` / `app/Enums/MemberRoleState.php` / `app/Services/Organization/OrganizationMembershipService.php` / `app/Http/Controllers/Admin/UserManagementController.php` |
+| 業務要件起因の説明 | 管理メニューの役割は組織ロールと Default Project の割当の合成で表す必要があり、保存された役割にすると非正規状態を見つけて直せなくなる |
+| 揃え続ける不変条件と保証機構 | 招待 token は hash-only 保存 / 権限判定は laratrust_team_id を明示 / Owner 昇格は transferOwnership のみ。`ConsoleRoleTransitionTest` と `ProjectMemberPivotWritePathTest` が固定する |
+| 再判定の条件 | 役割を保存概念へ戻す要件が出たとき / 家系の裁定が役割の語彙を変えたとき |
+| 決めた日 | 2026-07-11 |
+| 決めた人 | オーナー |
+| 根拠 | T006 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -261,52 +422,19 @@ ### 関連
 - 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7) /
   `devnotes/20260807-2032-invitation-in-app-acceptance/` (役割付き招待の撤去 = 裁定 AG-079)
 
-## D9 ✅→解消 BillingAccess の entitlement 判定への書き換え (free tier は課金ゲートを通す)
-
-> **【解消 / 2026-08-03 (T075 = 決済 parity P4)】** 本乖離は**ゲート反転で解消した**。
-> 「free tier (= `plan_code` null) は課金ゲートを通す」という扱いをやめ、無料枠は
-> `organizations.free_plan_code = 'personal'` の**明示申告** (`ActiveFreePlan`) で表現するようになった。
-> `plan_code` は entitlement 判定に一切使わない (quota の解決キーのみ)。
-> 既存組織は grandfathering backfill が `free_plan_code` を書くため締め出しは発生しない。
-> 設計: `devnotes/20260717-0035-aigenba-billing-parity/` §P4。**記録は削除せず経緯として残す**。
-
-| 観点 | テンプレート | 本アプリ |
-|---|---|---|
-| BillingAccess::hasActiveAccess | `subscription('default')` が active/trialing のときのみ許可 (未契約 = fail-closed) | plan_code null (未契約 = 支払い不要 free tier) は許可 / plan_code 非 null (有償プラン契約状態) のみ active/trialing を要求 |
-| 遮断時の UX | billing へ redirect (理由提示なし) / JSON 402 「有効なサブスクリプションがありません」 | billing へ redirect + 理由 flash / JSON 402 (両経路とも「サブスクリプションのお支払いが確認できないため…」で統一) |
-| ダッシュボード callout | `has_active_subscription` (subscription 有無) | `billing_state` (`OnboardingBillingState` の 5 値) による状態別 callout。未契約はプラン選択 CTA / 支払い不健全は支払い方法確認 CTA (真偽値に潰さない。T150) |
-
-### なぜ正当な差分か (logic-driven)
-
-AI-CUE は「Free プランで今すぐ試せます」を掲げる freemium 設計 (pricing / home)。テンプレート
-既定の「active subscription 必須」では、未契約の新規組織が business route (/projects, /app) に
-一切到達できず、North Star フロー (SOP→シナリオ→撮影→動画) が入口で詰む
-(bug-hunt F-07: devnotes/20260712-075854-bug-hunt)。有償価値は別レイヤで gate 済み
-(チケット残高 = analyze/render、Quota = max_projects / max_storage_bytes) のため、
-本ゲートの責務は「有償プラン契約中の支払い健全性の担保」のみで足りる。
-なお BillingAccess docblock 自身が「アプリは本クラスの書き換えで gate 方針を変更する」と
-宣言する公式拡張ポイントのため、これは構造逸脱ではなくサンクション済み拡張の記録。
-
-### 揃えている不変条件 (これは保証し続ける)
-
-> 「課金による利用可否の判定は BillingAccess 経由のみ / 有償契約の支払い不健全
-> (past_due / canceled / incomplete / 行不在) は fail-closed で遮断 / billing・checkout は
-> 構造的 allowlist で遮断中も到達可能 / plan_code は Stripe Price を持つ有償プラン契約時のみ
-> webhook が set する状態キー (null = 未契約 = free tier)」
-
-- 挙動固定: `RequireActiveSubscriptionMiddlewareTest` (F-07 再現 3 本 + 有償契約マトリクス +
-  free プランが Stripe Price を持たない前提の固定 + BillingAccess 単体マトリクス)
-- 遮断 UX: 同テストが flash / 402 message の文言を両経路で固定。
-  ダッシュボード callout は `DashboardTest` + `Dashboard.test.ts` が固定
+## D10 テストレーンのグローバルロック (worktree-local flock を残さず削除)
 
-### 関連
-
-- 実装: `app/Services/Billing/BillingAccess.php` /
-  `app/Http/Middleware/RequireActiveSubscription.php` /
-  `app/DataTransferObjects/Dashboard/BillingSummaryData.php`
-- 設計: `devnotes/20260712-0927-bugfix-billing-free-access/` (概念設計 + 詳細設計 施策 1〜5)
-
-## D10 ✅ テストレーンのグローバルロック (worktree-local flock を残さず削除)
+| 行 | 内容 |
+|---|---|
+| 対象パス | `scripts/global-test-lock.sh` / `scripts/with-global-test-lock.sh` / `scripts/verify-global-test-lock.sh` / `scripts/run-test.sh` / `scripts/run-browser-test.sh` / `scripts/run-vitest.sh` |
+| 業務要件起因の説明 | 実装を必ず worktree で行うため同一マシンで複数のテストレーンが同時に走るのが常態で、奪い合う資源 (PostgreSQL / CPU / ブラウザ掃除) の作用域がマシン全体である |
+| 揃え続ける不変条件と保証機構 | ブロッキング取得 / 待機中の heartbeat / 再入ガード / ロック fd の非継承の 4 要件。`scripts/verify-global-test-lock.sh` と `GlobalTestLockInventoryTest` が固定する |
+| 再判定の条件 | テストレーンの並走が起きなくなったとき / 家系がロックの標準形を別の形で確定したとき |
+| 決めた日 | 2026-08-04 |
+| 決めた人 | オーナー |
+| 根拠 | T099 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート(正典 = spirux 形) | 本アプリ |
 |---|---|---|
@@ -375,7 +503,19 @@ ### 関連
 
 ---
 
-## D11 ✅ svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)
+## D11 svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/js/architecture/svelte-no-undef-gate.test.ts` / `eslint.config.js` |
+| 業務要件起因の説明 | 同じ不変条件を守る実装がテンプレート側にあるが手元で読めないため、実装を待たずに設定の静的検査で先に固定した |
+| 揃え続ける不変条件と保証機構 | resources/js 配下の全 svelte で no-undef が error / globals が実行時グローバルと完全一致 / lint 対象の全ファイルで inline の抑制が効かない |
+| 再判定の条件 | laravel-claude-template の実装を読める状態になったとき (突き合わせて寄せられるなら本登録を消す) |
+| 決めた日 | 2026-08-05 |
+| 決めた人 | 開発者 |
+| 根拠 | T102 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -432,7 +572,19 @@ ### 関連
 
 ---
 
-## D12 ✅ ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)
+## D12 ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Support/Seo/SeoManager.php` / `app/Support/Seo/SeoRenderer.php` / `resources/js/lib/document-title.ts` / `config/seo.php` |
+| 業務要件起因の説明 | ページ題名の一次情報は controller と config が持ち、ページ側に helper を挟む層が無い。同じ契約を移植すると一次情報が 2 か所に割れ、フルロードと SPA 遷移で題名が食い違う |
+| 揃え続ける不変条件と保証機構 | 題名の正本はサーバの `SeoManager::resolveDocumentTitle` ただ 1 つで、フルロードと SPA 遷移で一致する。`DocumentTitleCoverageTest` と `svelte-head-no-title.test.ts` が固定する |
+| 再判定の条件 | ページ側が題名の一次情報を持つ要件が出たとき / description に SPA 遷移後の追従が要るようになったとき |
+| 決めた日 | 2026-08-05 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260805-0101-architecture-gate-followup/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -486,7 +638,19 @@ ### 関連
 
 ---
 
-## D13 ✅ SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)
+## D13 SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Auth/SocialAccountService.php` |
+| 業務要件起因の説明 | SSO とパスキーを第一級のログイン手段として扱うため、「ログイン手段が 0 になる操作を止める」不変条件が `hasPassword()` の真実性に依存する |
+| 揃え続ける不変条件と保証機構 | `User::hasPassword()` は password 経路の可否を fail-closed で判定する。`SocialAuthTest` / `RecentAuthTest` / `LoginMethodInventoryTest` が固定する |
+| 再判定の条件 | 既存ユーザーの遡及是正を判別できる材料 (password 変更の監査証跡) が全ユーザーぶん揃ったとき |
+| 決めた日 | 2026-08-05 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260805-1244-auth-method-and-passkey/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -544,7 +708,19 @@ ### 関連
 
 ---
 
-## D14 ✅ 実行済み route の記録をアプリ側の観測器で採る (退避 → 正規化 → route 名解決の 3 段を置かない)
+## D14 実行した route の記録をアプリ側の観測器で採る (退避と正規化と route 名解決の 3 段を置かない)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` |
+| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する |
+| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う |
+| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T164 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -607,7 +783,19 @@ ### 関連
 
 ---
 
-## D15 ✅ strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない
+## D15 strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/StrictTypesDeclarationGateTest.php` / `tests/Support/StrictTypesDeclarationScanner.php` / `tests/Support/StrictTypesRuntimeProbe.php` |
+| 業務要件起因の説明 | 容量 (bytes) やチケット枚数のように数値と文字列の取り違えがそのまま業務の誤りになる領域を持つため、走査域のどこか 1 枚だけが緩い状態を残さない |
+| 揃え続ける不変条件と保証機構 | 宣言を欠く PHP ファイルが新しく増えない。走査域はテンプレートが保証する app/ を包含し、判定器は実測照合器と突き合わせて fail-open を 0 件に固定する |
+| 再判定の条件 | どうしても宣言できないファイルが現れたとき (なし崩しに許可一覧を足さず設計レビューを通す) |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | オーナー |
+| 根拠 | T167 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -669,7 +857,19 @@ ### 関連
 
 ---
 
-## D16 ✅ prompt の trusted 変数の入口を作らない (窓口の引数は untrusted だけ)
+## D16 prompt の trusted 変数の入口を作らない (窓口の引数は untrusted だけ)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Support/Llm/PromptDefense.php` / `app/Support/Llm/GuardedPrompt.php` / `config/llm-defense.php` |
+| 業務要件起因の説明 | prompt の変数はすべて作業手順書 (SOP) 由来の untrusted で、固定値や列挙型の値を prompt へ渡す面が 1 つも無いため、trusted の入口を作る対象が存在しない |
+| 揃え続ける不変条件と保証機構 | prompt へ入る実行時の文字列はすべて窓口で無害化とタグ境界化を受ける。`PromptDefenseWindowGateTest` の変数集合の突き合わせが双方向で固定する |
+| 再判定の条件 | trusted 変数を足すとき (窓口の入口・値をリテラルに限る字句検査・目録の 3 つを同じ変更で足す) |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T169 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 | 観点 | テンプレート | 本アプリ |
 |---|---|---|
@@ -714,7 +914,19 @@ ### 関連
 
 ---
 
-## D17 ✅ 滞留回収の共通基盤を、閾値の置き場所と `recover()` の引数で正典から外す
+## D17 滞留回収の共通基盤を、閾値の置き場所と `recover()` の引数で正典から外す
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Contracts/Recovery/StuckWorkStream.php` / `app/Services/Recovery/StuckWorkRecoverySweeper.php` / `app/Services/Recovery/StuckWorkStreamRegistry.php` / `app/Console/Commands/Operations/RecoverStuckWorkCommand.php` |
+| 業務要件起因の説明 | 「ジョブの制限時間 < 再試行間隔 < 予約の有効期限 ≤ 滞留の閾値」の序列を既存の検査 2 本が固定しており、閾値を回収側の設定へ移すと序列の情報源が 2 つに割れる |
+| 揃え続ける不変条件と保証機構 | 回収は必ず行を取り直し、候補列挙と同じ述語を行ロック下で再評価してから作用する。`StuckWorkRecoveryInventoryTest` が系列の集合一致を deny-by-default で強制する |
+| 再判定の条件 | 家系の正典が閾値の置き場所を変えたとき / 遡及の下限や自走をやめる上限が要る事象が実際に起きたとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | オーナー |
+| 根拠 | T171 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 家系の裁定 AG-083 標準形 v1 (追従元 laravel-claude-template:T076) の共通基盤へ寄せ替えるにあたり、
 **3 点だけ**正典と形を変えた。骨格 (系列の契約 / 走査と作用の分離 / 既定は実行しない入口 /
@@ -764,7 +976,19 @@ ### 関連
 
 ---
 
-## D18 ✅ hook の起動子を「起動先の検証 + 終了コードの写像器」にする
+## D18 hook の起動子を「起動先の検証 + 終了コードの写像器」にする
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` / `scripts/code-review-graph-update-hook.sh` |
+| 業務要件起因の説明 | hook の故障がセッションの操作を止めてはならず、起動先の検証は起動された後では手遅れなので起動子にしか置けない |
+| 揃え続ける不変条件と保証機構 | 配線は常設で起動子は絶対パス、排他はスクリプト内にあり、配線は台帳テストで完全一致 pin される。`ClaudeHooksWiringTest` が固定する |
+| 再判定の条件 | Claude Code が hook の終了コードの扱いを変えたとき / 家系が起動子の形を確定したとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T172 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 常設 hook 配線 (家系の feature `claude-hooks-wiring`) を取り込むにあたり、**起動子の形だけ**
 テンプレートと変えた。配線されている hook の本数・対象・スクリプトの置き場所は正典どおりである。
@@ -812,7 +1036,19 @@ ### 関連
 
 ---
 
-## D19 ✅ 経路キャッシュ起動での middleware 後付けは「走らせない」側の契約を維持する (専用の実行点クラスへは移行しない)
+## D19 経路キャッシュ起動での middleware 後付けは「走らせない」側の契約を維持する (専用の実行点クラスへは移行しない)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Support/Http/RouteMiddlewareBinder.php` / `app/Support/Http/RouteThrottleBinder.php` |
+| 業務要件起因の説明 | 本リポジトリにデプロイ定義の実体が無く、存在しない基盤のための preflight を先回りして作らない規約があるため、正典の専用実行点へ移行する利益が今は無い |
+| 揃え続ける不変条件と保証機構 | 後付けした保護は実効の経路に必ず載る / 後付けの入口は 2 つの binder に限られる / 経路名が消えたら起動を止める。`PostBootRouteMutationInventoryTest` と `RouteCacheBakedProtectionTest` が固定する |
+| 再判定の条件 | デプロイ定義が入ったとき / route:cache を実行する記述が入ったとき / 家系の機能台帳の裁定が変わったとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260815-2100-route-cache-middleware-attach/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 家系の正典 (機能台帳 `route-cache-safe-middleware-attach` の v1) は、経路の一覧が組み上がった後に
 走らせたい処理を**専用の実行点クラス 1 つ**へ集約し、経路キャッシュ起動でも後付けを効かせる形である。
@@ -889,7 +1125,19 @@ ### 関連
 
 ---
 
-## D20 ✅ bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する
+## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` |
+| 業務要件起因の説明 | 機能カタログの id 列が所見記録の語彙の正本であり、Python ツールを標準ライブラリだけで書く規約から注釈は TOML になる |
+| 揃え続ける不変条件と保証機構 | 目録は実装と注釈から再生成でき、ずれていたら CI が落ちる。`BugHuntInventoryCheckInvariantTest` と生成器の自己テストが 4 段の判定を固定する |
+| 再判定の条件 | 家系の正典が id 列を持つ形へ変わったとき / Python に依存を足す裁定が出たとき / 中間 JSON を読む道具が家系に現れたとき |
+| 決めた日 | 2026-08-15 |
+| 決めた人 | 開発者 |
+| 根拠 | T176 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
 
 家系の正典 (機能台帳 `bughunt-inventory-generation` の t1) は、bug-hunt の分母 (画面一覧 /
 操作一覧 / 機能カタログ) を実装から生成し、人が書く注釈ファイルと段階的なドリフト検査で守る形である。
@@ -957,3 +1205,184 @@ ### 関連
   `tests/Architecture/BughuntInventoryToolSelfTest.php` /
   `tests/Feature/Bughunt/InventoryScanCommandTest.php`
 - 設計: `devnotes/20260815-2100-bughunt-inventory-generator/`
+
+---
+
+## D21 bug-hunt の自己検証を CI の専用ステップでなく composer test の配線に載せる
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/BughuntSelfTestExecutionTest.php` |
+| 業務要件起因の説明 | bug-hunt の自己検証はどこからも自動実行されておらず二段防御の片側が眠っていた。CI の責務を同期検査に限る裁定があるため、専用ステップではなくテストの配線へ載せた |
+| 揃え続ける不変条件と保証機構 | 自己検証が毎回のテスト実行で実走し、実資源に触れない。隔離境界はテスト側が握り、専用マーカーのある空き地しか受け付けない |
+| 再判定の条件 | CI に bug-hunt 専用のステップを設ける判断が出たとき / 自己検証の実行時間がテスト実行の妨げになったとき |
+| 決めた日 | 2026-08-10 |
+| 決めた人 | 開発者 |
+| 根拠 | T142 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | 家系の他リポジトリ / テンプレート | 本アプリ |
+|---|---|---|
+| 自己検証の実行点 | CI の専用ステップ | `composer test` が走らせる Architecture テスト (`BughuntSelfTestExecutionTest`) |
+| 隔離境界の持ち主 | 自己検証スクリプト自身 | テスト側が「捨ててよい空き地」を作って渡す (専用マーカー必須・借り物は消さない) |
+
+### なぜ正当な差分か (logic-driven)
+
+bug-hunt の自己検証は guard と資源導出と環境変数の隔離という**実行時の挙動**を見る側で、
+静的構造を見る Architecture テストと二段防御をなす。ところが導入時はどこからも自動実行されておらず、
+片側が眠ったまま緑になっていた。本アプリの CI は同期検査に責務を限る裁定を採っており
+(依存脆弱性の gate と同じ考え方)、専用ステップを増やすと「CI でしか走らない検査」が生まれて
+手元の `composer test` と CI で守られる範囲が食い違う。実行点を 1 つにするほうが、
+実行され続けることを保証しやすい。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「自己検証は毎回のテスト実行で実走し、実資源には触れない」
+
+- 隔離境界はテスト側が握る。自己検証は外から渡された空き地を使い、未指定のときだけ自分で作る
+- 外から渡せるのは専用マーカーを置いた空き地だけで、リポジトリのルートを渡す事故を構造的に防ぐ
+- 借り物の空き地は削除しない (作った側が片付ける)
+
+### 関連
+
+- 実装: `tests/Architecture/BughuntSelfTestExecutionTest.php` / `scripts/bug-hunt-shard.sh`
+- 設計: `devnotes/20260810-0251-bughunt-harness-hardening/`
+
+---
+
+## D22 退会は利用者の行を消さず凍結で表す (猶予 30 日)
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` / `app/Http/Controllers/Settings/AccountDeletionRequestController.php` |
+| 業務要件起因の説明 | 退会後も課金記録の保持義務が残るため利用者の行を消せない。猶予中の取消をそのまま元の状態へ戻せる形にする必要もある |
+| 揃え続ける不変条件と保証機構 | 凍結は deny-by-default で auth と verified の group 全体に掛かり、開けるのは救済経路だけである。退会の予約と取消は監査記録に残る |
+| 再判定の条件 | 猶予期間や保持義務の前提が変わったとき / 家系が退会の標準形を確定したとき |
+| 決めた日 | 2026-08-09 |
+| 決めた人 | オーナー |
+| 根拠 | devnotes/20260809-0908-account-deletion-grace/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 退会の表現 | 相当する仕組みを持たない (退会の入口も猶予も凍結も同梱していない) | 利用者の行の生死を変えない**凍結** (論理削除も使わない) + 30 日の猶予 |
+| 猶予中の到達性 | — | `auth` + `verified` の group 全体に凍結 middleware を付け、取消などの救済経路だけを許可一覧で開ける |
+
+### なぜ正当な差分か (logic-driven)
+
+退会しても課金記録には保持義務が残る (D23) ため、利用者の行を消す形にすると
+「消えた利用者に紐づく課金記録」という参照の切れた状態を作ってしまう。
+凍結なら猶予中の取消がそのまま元の状態に戻り、保持義務のある記録も参照を保ったまま残る。
+
+**登録する理由 (テンプレートに相当物が無いのに登録する根拠)**: 判定の出所は本アプリではなく
+**台帳リポジトリの巡回 (2026-08-12) が「記録されるべき乖離」として受信箱へ届けた判定**である。
+決定はオーナーで、**ひな形ではなく家系の別リポジトリの先例に揃えた**形であり、
+統一形式の不変条件 i14 (登録簿の守備範囲には「ひな形が家系の先例から外れた判断」も含む) に当たる。
+テンプレートに無い領域への上積みか、ひな形から外れた判断かの線引きは、テンプレートの実物が
+手元に無いので本アプリだけでは確定できない。過剰検出寄りに倒す原則 (誤登録は 1 行消せば済むが
+登録漏れは気付けない) に従い、登録する側へ倒している。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「凍結は deny-by-default で、開けるのは救済経路だけである」
+
+- 凍結 middleware は route ごとの付け忘れが起きないよう group 全体に付ける
+- 取消 (救済) には再認証を課さない。詰みを作らないための例外であり、目録に理由付きで載る
+- 退会の予約と取消は監査記録に残る
+
+### 関連
+
+- 実装: `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` /
+  `app/Http/Controllers/Settings/AccountDeletionRequestController.php`
+- 設計: `devnotes/20260809-0908-account-deletion-grace/` (PR-B)
+
+---
+
+## D23 課金記録は退会後も 7 年保持し、対象と年数を 1 か所で持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Enums/Billing/BillingRetentionTarget.php` / `app/Enums/Billing/BillingRetentionExclusion.php` / `app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php` / `app/Services/Billing/Retention/AbstractBillingRetentionPurger.php` / `app/Services/Billing/Retention/BillingCheckoutSessionPurger.php` / `app/Services/Billing/Retention/StripeWebhookEventPurger.php` / `app/Services/Billing/Retention/SubscriptionItemPurger.php` / `app/Services/Billing/Retention/SubscriptionPurger.php` / `app/Services/Billing/Retention/TicketAutoRechargeAttemptPurger.php` / `app/Services/Billing/Retention/TicketCheckoutSessionPurger.php` / `app/Services/Billing/Retention/TicketLedgerEntryPurger.php` |
+| 業務要件起因の説明 | 課金記録の保持義務は退会より寿命が長く、利用者データと同じ掃除の対象にできない。年数を各所に書くと必ず食い違う |
+| 揃え続ける不変条件と保証機構 | 保持年数の正本は 1 か所で、掃除の対象は宣言された表だけである。`BillingRetentionConfigSingleSourceTest` と `BillingRetentionTargetInventoryTest` が固定する |
+| 再判定の条件 | 保持義務の年数が変わったとき / 家系が保持期間の標準形を確定したとき |
+| 決めた日 | 2026-08-09 |
+| 決めた人 | オーナー |
+| 根拠 | devnotes/20260809-0908-account-deletion-grace/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 課金記録の寿命 | 相当する仕組みを持たない (保持年数の宣言も掃除器も同梱していない) | 保持年数の正本を列挙型 1 か所に置き、表ごとの掃除を登録した掃除器だけが行う |
+| 利用者データとの関係 | — | 退会で消えるものと 7 年残るものを分け、残す側は掃除の対象から除外する理由を宣言する |
+
+### なぜ正当な差分か (logic-driven)
+
+課金記録の保持義務は退会よりも寿命が長く、利用者データと同じ掃除の対象にできない。
+年数を各所に書くと必ず食い違うため、年数と対象表の対応を 1 か所に集約し、
+掃除の実処理はその宣言からしか作れないようにした。
+
+**登録する理由 (テンプレートに相当物が無いのに登録する根拠)**: D22 と同じで、判定の出所は
+**台帳リポジトリの巡回 (2026-08-12) が受信箱へ届けた判定**であり、決定はオーナー
+(保持 7 年) で家系の先例に揃えた形である (統一形式の不変条件 i14)。
+D22 と別の登録にしているのは、対象パスが交わらず解消の条件も別だからである
+(退会の表現と、課金記録の寿命は別の判断)。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「保持年数の正本は 1 か所で、掃除の対象は宣言された表だけである」
+
+- `BillingRetentionConfigSingleSourceTest` が単一出典を固定する
+- `BillingRetentionTargetInventoryTest` が対象表と掃除器の対応を deny-by-default で強制する
+- 表を足したときの分類は `RetentionTableClassificationTest` が実スキーマと突き合わせる
+
+### 関連
+
+- 実装: `app/Enums/Billing/BillingRetentionTarget.php` /
+  `app/Services/Billing/Retention/` 配下の掃除器
+- 設計: `devnotes/20260809-0908-account-deletion-grace/` (PR-C)
+
+---
+
+## D24 SSO の driver 解決点を自前クラス 1 つへ切り出す
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `app/Services/Auth/SocialiteDriverResolver.php` |
+| 業務要件起因の説明 | bug-hunt の走行が実際の外部 ID 基盤へ出ていくのを塞ぐ必要があるが、Socialite の Factory を丸ごと差し替えると本番経路の解決まで置き換わる |
+| 揃え続ける不変条件と保証機構 | SSO の driver 解決は 1 クラスに集約され、他クラスからの直呼びは登録も免除もできない。`ExternalSeamInventoryTest` と `ExternalFakeWiringInvariantTest` が固定する |
+| 再判定の条件 | 家系の外部到達点の標準形が解決点の形を定めたとき / Socialite が差し替え点を公式に提供したとき |
+| 決めた日 | 2026-08-11 |
+| 決めた人 | 開発者 |
+| 根拠 | T153 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| SSO の driver 取得 | 呼び出し側が Socialite の facade から直接取る | `SocialiteDriverResolver` 1 クラスに集約し、他クラスからの直呼びを目録が拒否する |
+| 非本番での差し替え | — | 解決点クラスを container で差し替える (Socialite の Factory そのものは差し替えない) |
+
+### なぜ正当な差分か (logic-driven)
+
+bug-hunt の走行が実際の外部 ID 基盤へ遷移すると、探索が本アプリの外へ出て戻れなくなる。
+これを塞ぐには driver の解決点が要るが、Socialite の Factory を丸ごと差し替えると
+本番経路の解決まで置き換わり、差し替えの影響範囲が読めなくなる。
+薄い解決点を 1 つ置くほうが、差し替えの範囲を非本番だけに閉じられる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「SSO の driver 解決は 1 クラスに集約され、他クラスからの直呼びは登録も免除もできない」
+
+- `ExternalSeamInventoryTest` が解決点を名指しで固定する (他クラスの直呼びは目録に載せられない)
+- 非本番の差し替えは `ExternalFakeWiringInvariantTest` が配線ごと固定する
+- 差し替えを許す環境は testing と bug-hunt だけで、local は含めない
+
+### 関連
+
+- 実装: `app/Services/Auth/SocialiteDriverResolver.php` /
+  `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php`
+- 設計: `devnotes/20260811-1736-bughunt-sso-egress/`
diff --git a/tests/Support/TemplateDivergence/DivergenceLedgerParser.php b/tests/Support/TemplateDivergence/DivergenceLedgerParser.php
new file mode 100644
index 0000000..2be9867
--- /dev/null
+++ b/tests/Support/TemplateDivergence/DivergenceLedgerParser.php
@@ -0,0 +1,373 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 逸脱の登録簿 (`docs/template-divergence.md`) の Markdown を解析する (純関数)。
+ *
+ * 解析器は**取り出すだけ**で、値の妥当性は `DivergenceLedgerRules` が見る。
+ * 読み解けなかったこと (囲みが閉じない / 登録エントリ領域が無い) は
+ * 違反として返し、空集合へ落として緑にする経路は持たない (fail-closed)。
+ *
+ * Markdown の囲み文法を全部は実装しない。台帳で使ってよい囲みは**行頭のバッククォート
+ * 3 個ちょうど**だけで、バッククォート 4 個以上と `~~~` は**明示的に違反**にする
+ * (黙って読み飛ばすと、その囲みで登録を隠せる回避口になる)。
+ */
+final class DivergenceLedgerParser
+{
+    /**
+     * 登録メタ表のラベル (規定の順序)。過不足・順序違いは「台帳を解釈できない」= 不合格。
+     *
+     * @var list<string>
+     */
+    public const META_LABELS = [
+        '対象パス',
+        '業務要件起因の説明',
+        '揃え続ける不変条件と保証機構',
+        '再判定の条件',
+        '決めた日',
+        '決めた人',
+        '根拠',
+        '状態',
+        '見直し期限',
+    ];
+
+    /** 登録の見出しの正準形 (行全体一致)。 */
+    private const ENTRY_HEADING = '/^## D([1-9]\d*) (\S.*)$/u';
+
+    /** 件数の明示行。 */
+    private const DECLARED_COUNT = '/^登録エントリ: (\d+) 件$/u';
+
+    public static function parse(string $markdown): ParsedLedger
+    {
+        $scan = self::outsideFenceLines($markdown);
+        $violations = $scan['violations'];
+
+        if ($scan['unclosed']) {
+            $violations[] = 'P1: 囲みコード区画が閉じていない (解析不能)。囲みは行頭のバッククォート 3 個ちょうどで開閉する';
+
+            return new ParsedLedger([], null, $violations, true);
+        }
+
+        $lines = $scan['lines'];
+
+        $declared = null;
+        $declaredHits = 0;
+        foreach ($lines as $line) {
+            if (preg_match(self::DECLARED_COUNT, $line[1], $matches) === 1) {
+                $declaredHits++;
+                $declared = (int) $matches[1];
+            }
+        }
+        if ($declaredHits !== 1) {
+            $violations[] = sprintf(
+                'TD12: 件数の明示行「登録エントリ: N 件」は囲みの外にちょうど 1 本必要 (実測 %d 本)',
+                $declaredHits,
+            );
+            $declared = null;
+        }
+
+        $regionStart = null;
+        foreach ($lines as $index => $line) {
+            if (str_starts_with($line[1], '## D')) {
+                $regionStart = $index;
+                break;
+            }
+        }
+
+        if ($regionStart === null) {
+            $violations[] = 'P2: 登録エントリ領域 (最初の `## D<n>` 見出し) が見つからない (解析不能)';
+
+            return new ParsedLedger([], $declared, $violations, true);
+        }
+
+        /** @var list<int> $headingIndexes */
+        $headingIndexes = [];
+        $total = count($lines);
+        for ($index = $regionStart; $index < $total; $index++) {
+            if (str_starts_with($lines[$index][1], '## ')) {
+                $headingIndexes[] = $index;
+            }
+        }
+
+        /** @var list<ParsedEntry> $entries */
+        $entries = [];
+        /** @var array<int, int> $seenIds id => 初出の行番号 */
+        $seenIds = [];
+
+        foreach ($headingIndexes as $position => $headingIndex) {
+            [$lineNumber, $headingText] = $lines[$headingIndex];
+
+            if (preg_match(self::ENTRY_HEADING, $headingText, $matches) !== 1) {
+                $violations[] = sprintf(
+                    'TD1: %d 行目の見出しが正準形 `## D<n> <要約>` ではない: %s',
+                    $lineNumber,
+                    $headingText,
+                );
+
+                continue;
+            }
+
+            $id = (int) $matches[1];
+            $summary = $matches[2];
+
+            foreach (self::forbiddenSummaryReasons($summary) as $reason) {
+                $violations[] = sprintf('TD1: D%d (%d 行目) の要約は%s', $id, $lineNumber, $reason);
+            }
+
+            if (isset($seenIds[$id])) {
+                $violations[] = sprintf(
+                    'TD1: D%d が重複している (%d 行目と %d 行目)。番号はリポジトリ内で一意',
+                    $id,
+                    $seenIds[$id],
+                    $lineNumber,
+                );
+            } else {
+                $seenIds[$id] = $lineNumber;
+            }
+
+            $bodyEnd = $headingIndexes[$position + 1] ?? $total;
+            $body = array_slice($lines, $headingIndex + 1, $bodyEnd - $headingIndex - 1);
+
+            $metadata = self::parseMetadata($body, sprintf('D%d (%d 行目)', $id, $lineNumber), $violations);
+
+            $entries[] = new ParsedEntry($id, $summary, $lineNumber, $metadata);
+        }
+
+        return new ParsedLedger($entries, $declared, $violations, false);
+    }
+
+    /**
+     * 囲みコード区画の外にある行だけを行番号付きで返す。
+     *
+     * @return array{lines: list<array{0: int, 1: string}>, violations: list<string>, unclosed: bool}
+     */
+    private static function outsideFenceLines(string $markdown): array
+    {
+        $split = preg_split("/\r\n|\n|\r/", $markdown);
+        Assert::isArray($split, '登録簿を行へ分割できない');
+
+        /** @var list<array{0: int, 1: string}> $lines */
+        $lines = [];
+        /** @var list<string> $violations */
+        $violations = [];
+        $inFence = false;
+
+        foreach ($split as $index => $text) {
+            Assert::string($text);
+            $number = $index + 1;
+
+            if ($inFence) {
+                if (preg_match('/^`{3}\s*$/', $text) === 1) {
+                    $inFence = false;
+                }
+
+                continue;
+            }
+
+            if (preg_match('/^`{4,}/', $text) === 1) {
+                $violations[] = sprintf('P3: %d 行目: バッククォート 4 個以上の囲みは台帳では使えない', $number);
+
+                continue;
+            }
+
+            if (str_starts_with($text, '~~~')) {
+                $violations[] = sprintf('P3: %d 行目: `~~~` の囲みは台帳では使えない', $number);
+
+                continue;
+            }
+
+            if (preg_match('/^`{3}\s*$/', $text) === 1) {
+                $inFence = true;
+
+                continue;
+            }
+
+            if (str_starts_with($text, '```')) {
+                // 言語名などを添えた囲みは扱わない。黙って読み飛ばすと、その書き方で登録を
+                // 隠せる回避口になるため、書式そのものを 1 種類に絞って明示的に拒否する。
+                $violations[] = sprintf('P3: %d 行目: 囲みは行頭のバッククォート 3 個だけで書く (言語名などを添えない)', $number);
+
+                continue;
+            }
+
+            $lines[] = [$number, $text];
+        }
+
+        return ['lines' => $lines, 'violations' => $violations, 'unclosed' => $inFence];
+    }
+
+    /**
+     * 要約に印・解消を表す語が含まれていないかを見る。
+     *
+     * `\p{S}` 全体は禁じない (見出しに `+` を使っている登録が実在し、正当な書き方であるため)。
+     *
+     * @return list<string> 違反理由 (空 = 合格)
+     */
+    private static function forbiddenSummaryReasons(string $summary): array
+    {
+        /** @var list<string> $reasons */
+        $reasons = [];
+
+        if (preg_match('/\p{So}/u', $summary) === 1) {
+            $reasons[] = '印 (その他の記号) を含む。見出しは印を持たない正準形で書く';
+        }
+        if (str_contains($summary, '→')) {
+            $reasons[] = '矢印 `→` を含む。状態の遷移を見出しで表さない';
+        }
+        foreach (['解消', '済み'] as $word) {
+            if (str_contains($summary, $word)) {
+                $reasons[] = sprintf('解消を表す語「%s」を含む。解消した逸脱は登録ごと消す', $word);
+            }
+        }
+
+        return $reasons;
+    }
+
+    /**
+     * 見出しの直後にある登録メタ表を解析する。
+     *
+     * @param  list<array{0: int, 1: string}>  $body  見出しの次の行から次の見出しの手前まで
+     * @param  list<string>  $violations
+     */
+    private static function parseMetadata(array $body, string $label, array &$violations): ?EntryMetadata
+    {
+        $position = 0;
+        $count = count($body);
+        while ($position < $count && trim($body[$position][1]) === '') {
+            $position++;
+        }
+
+        if ($position >= $count) {
+            $violations[] = sprintf('TD2: %s に登録メタ表が無い', $label);
+
+            return null;
+        }
+
+        $header = self::splitRow($body[$position][1]);
+        if ($header === null || $header[0] !== '行' || $header[1] !== '内容') {
+            $violations[] = sprintf('TD2: %s の登録メタ表は `| 行 | 内容 |` のヘッダで始める', $label);
+
+            return null;
+        }
+        $position++;
+
+        $separator = $position < $count ? self::splitRow($body[$position][1]) : null;
+        if ($separator === null
+            || preg_match('/^:?-{3,}:?$/', $separator[0]) !== 1
+            || preg_match('/^:?-{3,}:?$/', $separator[1]) !== 1) {
+            $violations[] = sprintf('TD2: %s の登録メタ表にヘッダ区切り行 `|---|---|` が無い', $label);
+
+            return null;
+        }
+        $position++;
+
+        /** @var list<string> $values */
+        $values = [];
+        foreach (self::META_LABELS as $expected) {
+            $row = $position < $count ? self::splitRow($body[$position][1]) : null;
+            if ($row === null) {
+                $violations[] = sprintf(
+                    'TD2: %s の登録メタ表が 9 行に足りない (「%s」の行が読めない。セルに `|` を書いていないか)',
+                    $label,
+                    $expected,
+                );
+
+                return null;
+            }
+            if ($row[0] !== $expected) {
+                $violations[] = sprintf(
+                    'TD2: %s の登録メタ表 %d 行目のラベルが「%s」ではなく「%s」。9 行の順序は規定である',
+                    $label,
+                    count($values) + 1,
+                    $expected,
+                    $row[0],
+                );
+
+                return null;
+            }
+            $values[] = $row[1];
+            $position++;
+        }
+
+        // 9 行の直後は空行にする。表の行が続いていたら 10 行目とみなす
+        // (列数で見分けようとすると、列を増やした 10 行目が比較表に紛れて通ってしまう)。
+        if ($position < $count && str_starts_with(trim($body[$position][1]), '|')) {
+            $violations[] = sprintf('TD2: %s の登録メタ表が 9 行を超えている (メタ表の直後には空行を置く)', $label);
+
+            return null;
+        }
+
+        return new EntryMetadata(
+            targetPaths: self::extractTargetPaths($values[0]),
+            rawTargetPathCell: $values[0],
+            domainReason: $values[1],
+            invariantAndGuard: $values[2],
+            reevaluationCondition: $values[3],
+            decidedOn: $values[4],
+            decidedBy: $values[5],
+            rationale: $values[6],
+            state: $values[7],
+            reviewDeadline: $values[8],
+        );
+    }
+
+    /**
+     * 表の 1 行を 2 セルへ分割する。
+     *
+     * セルの中に `|` を書くこと (エスケープした `\|` を含む) は許さないので、
+     * 分割後の要素数がちょうど 4 個 (先頭の空・ラベル・値・末尾の空) でなければ null を返す。
+     *
+     * @return array{0: string, 1: string}|null
+     */
+    private static function splitRow(string $line): ?array
+    {
+        $trimmed = trim($line);
+        if (! str_starts_with($trimmed, '|')) {
+            return null;
+        }
+
+        $parts = explode('|', $trimmed);
+        if (count($parts) !== 4) {
+            return null;
+        }
+        if (trim($parts[0]) !== '' || trim($parts[3]) !== '') {
+            return null;
+        }
+
+        return [trim($parts[1]), trim($parts[2])];
+    }
+
+    /**
+     * 対象パス欄からパスを取り出す。
+     *
+     * 許すのはバッククォート囲みのパスを ` / ` でつないだ形だけで、
+     * バッククォートの外に空白以外の文字があれば 1 件も取り出さない
+     * (書式違反は `DivergenceLedgerRules` が生セルを見て報告する)。
+     *
+     * @return list<string>
+     */
+    private static function extractTargetPaths(string $cell): array
+    {
+        if (preg_match('/^`[^`]+`(?: \/ `[^`]+`)*$/u', $cell) !== 1) {
+            return [];
+        }
+
+        $found = preg_match_all('/`([^`]+)`/u', $cell, $matches);
+        if ($found === false || $found === 0) {
+            return [];
+        }
+
+        /** @var list<string> $paths */
+        $paths = [];
+        foreach ($matches[1] as $path) {
+            $paths[] = $path;
+        }
+
+        return $paths;
+    }
+}
diff --git a/tests/Unit/Architecture/DivergenceLedgerRulesTest.php b/tests/Unit/Architecture/DivergenceLedgerRulesTest.php
new file mode 100644
index 0000000..d1e9d49
--- /dev/null
+++ b/tests/Unit/Architecture/DivergenceLedgerRulesTest.php
@@ -0,0 +1,504 @@
+<?php
+
+declare(strict_types=1);
+
+use Carbon\CarbonImmutable;
+use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
+use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
+use Tests\Support\TemplateDivergence\LedgerContext;
+use Tests\Support\TemplateDivergence\TodoLedgerReference;
+
+/*
+ * 逸脱の登録簿の形式検査 (`DivergenceLedgerParser` + `DivergenceLedgerRules`) の
+ * 正例と負例を固定する。
+ *
+ * ★負例が本テストの存在理由である。検査が「何も検出できないまま緑」になっていても
+ *   実物の台帳が合格していれば Architecture レーンは緑になるので、
+ *   検出器そのものの実効性はここでしか固定できない。
+ *
+ * ★期限の判定は**固定した基準日**を渡して検証する (実行日でテストが揺れない)。
+ *
+ * ★検体は文字列で組み立てる。実ファイルの実在判定は文脈 (`LedgerContext`) の
+ *   クロージャに閉じてあるので、DB もファイルシステムも触らない。
+ */
+
+/** 検体の基準日。期限の境界はすべてこの日を起点に書く。 */
+function divergenceBaseDate(): CarbonImmutable
+{
+    return CarbonImmutable::parse('2026-08-16')->startOfDay();
+}
+
+/**
+ * 検体で実在扱いにするファイル。
+ *
+ * @return list<string>
+ */
+function divergenceExistingFiles(): array
+{
+    return ['docs/template-divergence.md', 'AGENTS.md', 'README.md'];
+}
+
+/** 検体用の文脈 (実在判定は固定の一覧で答える)。 */
+function divergenceContext(int $pinnedEntryCount = 1): LedgerContext
+{
+    return new LedgerContext(
+        baseDate: divergenceBaseDate(),
+        pinnedEntryCount: $pinnedEntryCount,
+        pathExists: fn (string $path): bool => in_array($path, divergenceExistingFiles(), true),
+        directoryExists: fn (string $path): bool => $path === 'devnotes/20260816-0300-todo-T179',
+        rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn(
+            $reference,
+            "| ID | タイトル |\n|---|---|\n| T010 | 何かの作業 |\n| T179 | 逸脱の登録簿の形式検査 |\n",
+        ),
+    );
+}
+
+/**
+ * 登録メタ表の既定値 (すべて合格する値)。
+ *
+ * @return array<string, string>
+ */
+function divergenceDefaultMeta(): array
+{
+    return [
+        '対象パス' => '`docs/template-divergence.md`',
+        '業務要件起因の説明' => '業務の都合でテンプレートの形から外した理由',
+        '揃え続ける不変条件と保証機構' => '不変条件 X を gate Y が守り続ける',
+        '再判定の条件' => '前提 Z が変わったら見直す',
+        '決めた日' => '2026-08-01',
+        '決めた人' => '開発者',
+        '根拠' => 'T010',
+        '状態' => '恒久',
+        '見直し期限' => '—',
+    ];
+}
+
+/**
+ * 登録メタ表の行を規定の順序で組み立てる。
+ *
+ * @param  array<string, string>  $overrides
+ * @return list<array{0: string, 1: string}>
+ */
+function divergenceMetaRows(array $overrides = []): array
+{
+    $defaults = divergenceDefaultMeta();
+
+    /** @var list<array{0: string, 1: string}> $rows */
+    $rows = [];
+    foreach (DivergenceLedgerParser::META_LABELS as $label) {
+        $rows[] = [$label, $overrides[$label] ?? $defaults[$label]];
+    }
+
+    return $rows;
+}
+
+/**
+ * 登録 1 件の Markdown を組み立てる。
+ *
+ * @param  list<array{0: string, 1: string}>  $rows
+ */
+function divergenceEntry(string $heading, array $rows): string
+{
+    $markdown = $heading."\n\n| 行 | 内容 |\n|---|---|\n";
+    foreach ($rows as $row) {
+        $markdown .= sprintf("| %s | %s |\n", $row[0], $row[1]);
+    }
+
+    return $markdown."\n| 観点 | テンプレート | 本アプリ |\n|---|---|---|\n| 例 | 例 | 例 |\n\n### なぜ正当な差分か\n\n説明。\n";
+}
+
+/**
+ * 登録簿 1 冊の Markdown を組み立てる (規約節つき)。
+ *
+ * @param  list<string>  $entries
+ */
+function divergenceLedgerMarkdown(array $entries, ?int $declaredCount = null): string
+{
+    $declared = $declaredCount ?? count($entries);
+
+    $markdown = "# テンプレート差分レジストリ\n\n登録エントリ: ".$declared." 件\n\n";
+    $markdown .= "## 記録の原則\n\n- 解消した逸脱は登録から消す (この節は登録エントリ領域の外にある)\n\n";
+    $markdown .= "## エントリ形式\n\n```\n## D1 <逸脱の要約>\n\n| 行 | 内容 |\n|---|---|\n```\n\n";
+
+    return $markdown.implode("\n", $entries);
+}
+
+/**
+ * 検体を解析して違反一覧を返す。
+ *
+ * @return list<string>
+ */
+function divergenceViolations(string $markdown, int $pinnedEntryCount = 1): array
+{
+    return DivergenceLedgerRules::violations(
+        DivergenceLedgerParser::parse($markdown),
+        divergenceContext($pinnedEntryCount),
+    );
+}
+
+/** 違反一覧に指定の記号で始まる違反が含まれるか。 */
+function divergenceHasViolation(string $marker, string $markdown, int $pinnedEntryCount = 1): bool
+{
+    foreach (divergenceViolations($markdown, $pinnedEntryCount) as $violation) {
+        if (str_starts_with($violation, $marker)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+test('正例: 統一形式を満たす検体は違反 0 件になる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('負のコントロール: 囲みコード区画の中の記入例は登録として数えない', function (): void {
+    // 規約節の記入例 (`## D1 <逸脱の要約>`) は囲みの中にある。数えていれば件数が 2 になり赤くなる。
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    expect(DivergenceLedgerParser::parse($markdown)->entries)->toHaveCount(1);
+});
+
+test('負のコントロール: 登録エントリ領域より前の節は違反にならない', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    // `## 記録の原則` / `## エントリ形式` は領域の外なので正準形でなくてよい
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD1a: 見出しに印が付いていると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 ✅ 逸脱の要約', divergenceMetaRows()),
+    ]);
+
+    expect(divergenceHasViolation('TD1', $markdown))->toBeTrue();
+});
+
+test('TD1a: 見出しに解消を表す語や矢印があると落ちる', function (string $heading): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry($heading, divergenceMetaRows()),
+    ]);
+
+    expect(divergenceHasViolation('TD1', $markdown))->toBeTrue();
+})->with([
+    '矢印' => ['## D1 課金ゲートの反転 → 解消'],
+    '解消' => ['## D1 課金ゲートの反転 (解消)'],
+    '済み' => ['## D1 課金ゲートの反転 (対応済み)'],
+]);
+
+test('TD1: 要約に `+` を使う正当な見出しは落ちない', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 招待一本化 + 遷移コマンドロール', divergenceMetaRows()),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD1b: 見出しの階層を 1 段下げると登録として数えられず件数が合わない', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+        divergenceEntry('### D2 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
+    ], declaredCount: 2);
+
+    expect(divergenceHasViolation('TD12', $markdown, 2))->toBeTrue();
+});
+
+test('TD1c: id が重複すると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+        divergenceEntry('## D1 別の逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
+    ]);
+
+    expect(divergenceHasViolation('TD1', $markdown, 2))->toBeTrue();
+});
+
+test('TD2a: 登録メタ表が 9 行に足りないと落ちる', function (int $drop): void {
+    $rows = divergenceMetaRows();
+    array_splice($rows, $drop, 1);
+
+    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);
+
+    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
+})->with([
+    '8 行 (末尾を落とす)' => [8],
+    '8 行 (途中を落とす)' => [3],
+]);
+
+test('TD2a: 登録メタ表が 9 行を超えると落ちる (列を増やして比較表に紛れさせても落ちる)', function (string $extraRow): void {
+    $rows = divergenceMetaRows();
+    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);
+    // 9 行目 (見直し期限) の直後へ余分な行を差し込む
+    $markdown = str_replace("| 見直し期限 | — |\n", "| 見直し期限 | — |\n".$extraRow."\n", $markdown);
+
+    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
+})->with([
+    '2 列の 10 行目' => ['| 備考 | 10 行目 |'],
+    '3 列の 10 行目 (比較表と同じ列数)' => ['| 備考 | 10 行目 | 隠したい値 |'],
+]);
+
+test('TD2b: ラベルの順序を入れ替えると落ちる', function (): void {
+    $rows = divergenceMetaRows();
+    [$rows[7], $rows[8]] = [$rows[8], $rows[7]];
+
+    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);
+
+    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
+});
+
+test('TD3a: 対象パスが 0 件だと落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => ''])),
+    ]);
+
+    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
+});
+
+test('TD3b/TD3c/TD3d: 対象パスの値域と実在を見る', function (string $cell): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => $cell])),
+    ]);
+
+    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
+})->with([
+    'glob' => ['`app/Models/*.php`'],
+    '波括弧展開' => ['`app/Models/{Cut,Take}.php`'],
+    '絶対パス' => ['`/workspace/AGENTS.md`'],
+    '上位への相対指定' => ['`../AGENTS.md`'],
+    '実在しない' => ['`app/Nope.php`'],
+    'ディレクトリ' => ['`devnotes/20260816-0300-todo-T179`'],
+]);
+
+test('TD3e: 対象パスのセルにバッククォート外の説明文を添えると落ちる', function (string $cell): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => $cell])),
+    ]);
+
+    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
+})->with([
+    '説明を添える' => ['`AGENTS.md` (規約の正本)'],
+    '読点でつなぐ' => ['`AGENTS.md`、`README.md`'],
+    'バッククォート無し' => ['AGENTS.md'],
+]);
+
+test('TD3: 複数の対象パスを ` / ` でつなぐ形は合格する', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md` / `README.md`'])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD4: 2 つの登録が同じ対象パスを挙げると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
+        divergenceEntry('## D2 別の逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md` / `README.md`'])),
+    ]);
+
+    expect(divergenceHasViolation('TD4', $markdown, 2))->toBeTrue();
+});
+
+test('TD5: 状態が値域の外だと落ちる', function (string $state): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['状態' => $state])),
+    ]);
+
+    expect(divergenceHasViolation('TD5', $markdown))->toBeTrue();
+})->with([
+    '解消済み' => ['解消済み'],
+    '解消' => ['解消'],
+    '未実装' => ['未実装'],
+    '空' => [''],
+]);
+
+test('TD6: 監視中の見直し期限が不正だと落ちる', function (string $deadline): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
+            '状態' => '監視中',
+            '見直し期限' => $deadline,
+        ])),
+    ]);
+
+    expect(divergenceHasViolation('TD6', $markdown))->toBeTrue();
+})->with([
+    '期限が無い' => ['—'],
+    '空' => [''],
+    '日付でない' => ['not-a-date'],
+    '実在しない日付' => ['2026-02-30'],
+    '基準日の前日 (期限切れ)' => ['2026-08-15'],
+    '基準日から 401 日後' => ['2027-09-21'],
+]);
+
+test('TD6e: 監視中の見直し期限の境界は合格する', function (string $deadline): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
+            '状態' => '監視中',
+            '見直し期限' => $deadline,
+        ])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+})->with([
+    '基準日当日' => ['2026-08-16'],
+    '基準日の翌日' => ['2026-08-17'],
+    '基準日から 400 日後' => ['2027-09-20'],
+]);
+
+test('TD7: 恒久に日付の見直し期限が書いてあると落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
+            '状態' => '恒久',
+            '見直し期限' => '2026-12-31',
+        ])),
+    ]);
+
+    expect(divergenceHasViolation('TD7', $markdown))->toBeTrue();
+});
+
+test('TD8: 決めた日が未来日・不正な日付だと落ちる', function (string $decidedOn): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた日' => $decidedOn])),
+    ]);
+
+    expect(divergenceHasViolation('TD8', $markdown))->toBeTrue();
+})->with([
+    '基準日の翌日 (未来日)' => ['2026-08-17'],
+    '実在しない日付' => ['2026-02-30'],
+    '空' => [''],
+    '日付でない' => ['not-a-date'],
+    '桁が足りない' => ['2026-8-1'],
+]);
+
+test('TD8b: 決めた日が基準日当日は合格する', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた日' => '2026-08-16'])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD9: 決めた人が値域の外だと落ちる', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた人' => 'チーム'])),
+    ]);
+
+    expect(divergenceHasViolation('TD9', $markdown))->toBeTrue();
+});
+
+test('TD10: 根拠が実在しない・書式外・プレースホルダだと落ちる', function (string $rationale): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['根拠' => $rationale])),
+    ]);
+
+    expect(divergenceHasViolation('TD10', $markdown))->toBeTrue();
+})->with([
+    '実在しない T 番号' => ['T999'],
+    'プレースホルダ' => ['TBD'],
+    '空' => [''],
+    '実在しない devnotes' => ['devnotes/9999-nope/'],
+    '書式外 (末尾のスラッシュ無し)' => ['devnotes/20260816-0300-todo-T179'],
+    '書式外 (自由記述)' => ['前任者の口頭指示'],
+]);
+
+test('TD10: 実在する devnotes ディレクトリは根拠として合格する', function (): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['根拠' => 'devnotes/20260816-0300-todo-T179/'])),
+    ]);
+
+    expect(divergenceViolations($markdown))->toBe([]);
+});
+
+test('TD10c: T 番号の照合は表のセル境界で行う (T1 が T10 に一致しない)', function (): void {
+    $todo = "| ID | タイトル |\n|---|---|\n| T010 | 何かの作業 |\n";
+
+    expect(TodoLedgerReference::existsIn('T010', $todo))->toBeTrue()
+        ->and(TodoLedgerReference::existsIn('T01', $todo))->toBeFalse()
+        ->and(TodoLedgerReference::existsIn('T1', $todo))->toBeFalse();
+});
+
+test('TD11: 自由記述 3 欄が空かプレースホルダだと落ちる', function (string $label, string $value): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([$label => $value])),
+    ]);
+
+    expect(divergenceHasViolation('TD11', $markdown))->toBeTrue();
+})->with([
+    '説明が空' => ['業務要件起因の説明', ''],
+    '不変条件が伏せ字' => ['揃え続ける不変条件と保証機構', '...'],
+    '再判定の条件が未定' => ['再判定の条件', '未定'],
+    '再判定の条件が不在の記号' => ['再判定の条件', '—'],
+]);
+
+test('TD12: 明示件数・解析件数・固定件数の 3 点一致を要求する', function (int $declared, int $pinned): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ], declaredCount: $declared);
+
+    expect(divergenceHasViolation('TD12', $markdown, $pinned))->toBeTrue();
+})->with([
+    '明示件数が多い' => [2, 1],
+    '明示件数が少ない' => [0, 1],
+    '固定件数が多い' => [1, 2],
+    '固定件数が少ない' => [1, 0],
+]);
+
+test('TD12: 件数の明示行が無い・2 本ある場合も落ちる', function (string $markdown): void {
+    expect(divergenceHasViolation('TD12', $markdown))->toBeTrue();
+})->with([
+    '明示行が無い' => [
+        "# 台帳\n\n".divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ],
+    '明示行が 2 本' => [
+        "# 台帳\n\n登録エントリ: 1 件\n\n登録エントリ: 1 件\n\n".divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ],
+]);
+
+test('P1: 囲みコード区画が閉じていないと解析不能で落ち、以降の規則を評価しない', function (): void {
+    $markdown = "# 台帳\n\n登録エントリ: 1 件\n\n```\n## D1 <逸脱の要約>\n";
+
+    $violations = divergenceViolations($markdown);
+
+    // 件数 (TD12) も対象パス (TD3) も評価されないことまで固定する (fail-closed)
+    expect($violations)->toHaveCount(1)
+        ->and($violations[0])->toStartWith('P1');
+});
+
+test('P2: 登録エントリ領域が見つからないと解析不能で落ちる', function (): void {
+    $markdown = "# 台帳\n\n登録エントリ: 0 件\n\n## 記録の原則\n\n- 何か\n";
+
+    $violations = divergenceViolations($markdown, 0);
+
+    expect($violations)->toHaveCount(1)
+        ->and($violations[0])->toStartWith('P2');
+});
+
+test('P3: 台帳が扱わない囲みの書き方は明示的に拒否する', function (string $fence): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
+    ])."\n".$fence."\n本文\n".$fence."\n";
+
+    expect(divergenceHasViolation('P3', $markdown))->toBeTrue();
+})->with([
+    'バッククォート 4 個' => ['````'],
+    'チルダ 3 個' => ['~~~'],
+    '言語名を添えた囲み' => ['```php'],
+    '語を添えた囲み' => ['``` markdown'],
+]);
+
+test('P4: 登録メタ表のセルに `|` を書くと落ちる', function (string $value): void {
+    $markdown = divergenceLedgerMarkdown([
+        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['再判定の条件' => $value])),
+    ]);
+
+    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
+})->with([
+    '素の縦棒' => ['A | B が変わったら見直す'],
+    'エスケープした縦棒' => ['A \\| B が変わったら見直す'],
+]);

```

## 再検証

- `composer test -- --filter="DivergenceLedgerRules|TemplateDivergenceLedgerFormat"`: 76 tests / 76 passed
- `vendor/bin/pint --test`: passed (再実行済み)
- 全体の `composer test` / `composer phpstan` / `pnpm` 系は Round 1 時点で全緑、
  本ラウンドの変更後に再実行する

## 依頼

上の対応で Round 1 の [Critical] と [Warning] が閉じているかを判定し、
残る指摘があれば分類して示したうえで、最後に全体判定を APPROVED か CHANGES_REQUESTED の
1 語で書いてほしい。
