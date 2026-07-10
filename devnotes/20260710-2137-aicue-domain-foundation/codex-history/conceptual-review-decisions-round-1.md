# 対応マトリクス: conceptual-review Round 1

全体判定: CHANGES_REQUESTED（Round 1）→ 下記対応の上 Round 2 へ。

## [Critical] category_id が protected なのに VideoManual のカテゴリ選択が成立しない矛盾
- 判断: 対応する（一部反論を含む）
- 根拠: protected キーは「payload から fill しない」であって「値を設定できない」ではない。ただし設計に解決経路が明記されておらず曖昧だった点は正当な指摘。
- 対応内容: スコープ6に明記 —「`category_id` は protected のまま。FormRequest で `Rule::exists('categories','id')->where('project_id', $project->id)`（null 許容）で project 配下限定検証、保存は project スコープで解決した Category を `->associate()` して代入（payload 値を直接 fill しない）」。tenant キー不信を維持したままカテゴリ選択を成立させる。

## [Critical] VideoManual が update/edit 欠落で CRUD ではなく CRD
- 判断: 対応する
- 根拠: 正当。ユーザーはマニュアルのリネーム・再カテゴリ分類を必要とする。doc §10.3 の manual 系 endpoint は解析/シナリオ/レンダ中心でメタデータ更新が抜けていた（シナリオ編集は別 endpoint）。メタデータ更新は逸脱なく追加できる。
- 対応内容: `edit`/`update`（title・category_id のメタデータ更新）を Tier A に追加。成功判定も「CRUD（一覧/作成/表示/メタデータ更新/削除）」に修正。

## [Critical/Warning] Cut/Take/SourceDocument まで route/IDOR/test を広げるのは過大
- 判断: 対応する（Codex の二段階分割提案を採用）
- 根拠: 「フェーズ1 = データ基盤 + 最初の CRUD」に対し、振る舞いの無い子リソースへ route/IDOR を先行させると実装と inventory の整合が崩れる。使命への直接寄与より将来準備が前に出る。
- 対応内容: スコープを Tier A（Category/VideoManual = 15 点フルセット）/ Tier B（SourceDocument/Cut/Take = schema+model+factory のみ、route/IDOR/UI なし）に明確化。IDOR inventory は「張ったルートだけ登録」に変更。

## [Warning] DTO/JsonResource/Inertia props 契約が未明示
- 判断: 対応する
- 対応内容: スコープ10「レスポンス型契約」を追加。一覧 props shape（`manuals:{data,meta}` + `categories` + `filters`）、詳細/フォーム初期値を Resource/Data 経由で型付け、TS interface 追加を波及変更として明示。

## [Warning] NestedRouteIdorDefenseTest に未確定ルートを先行登録する危うさ
- 判断: 対応する
- 対応内容: Tier B は IDOR 登録しない（後続でルートを張る時に同時登録）。「張ったルートだけ登録」と明記。

## [Warning] Category 並べ替えの endpoint/Request/Service 契約が不足
- 判断: 対応する
- 対応内容: 実装方針に `PATCH .../categories/reorder` + `ReorderCategoriesRequest`（project 配下 exists 検証）+ Service 1 transaction 一括再採番を明記。競合は後勝ち・全件再採番で単純化（sort_order は表示順のみ）。

## [Warning] 先取りカラム（scenario_version/client_take_id/size_bytes）の意味づけが曖昧
- 判断: 対応する
- 対応内容: スコープ2に「先取りカラムの意味づけ」表を追加（誰が更新/何と整合/フェーズ1での状態）。adopted_take_id も併記。

## [Warning] 権限がアクション別許可表になっていない
- 判断: 対応する
- 対応内容: スコープ8にアクション×role 許可表を追加（show 系は両者可、write 系は編集者のみ）。

## [Warning] null category（未分類）の UI/DB 整合が未定
- 判断: 対応する
- 対応内容: スコープ9に未分類の表示名/フィルタ選択肢/フォーム初期値/並び順（末尾）を明記。

## [Warning] PHPStan lv10 に向けた enum cast/nullable/props shape の粒度不足
- 判断: 対応する
- 対応内容: スコープ3（casts() 登録・nullable は `?Type` 明示）とスコープ10（props shape）で具体化。

## [Suggestion] manual 一覧は GET クエリ + paginate で固定
- 判断: 対応する（採用）
- 対応内容: スコープ9で「GET クエリ絞り込み + paginate」に明記。

## [Suggestion] Resource/Data/ViewModel のどれを使うか揃える
- 判断: 対応する（採用）
- 対応内容: レスポンス型契約で Resource/Data 経由に統一（詳細な選択は詳細設計 Phase 2 で Item 見本に合わせて確定）。
