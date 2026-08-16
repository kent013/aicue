# Round 5: Round 4 指摘への対応と再レビュー依頼

Round 4 の [Warning] 1 件に対応しました。

- **§6-4**: 「保護キーへ登録する」という確定を撤回し、**入力契約の決定点**に置き換えました
  (利用者入力なら FormRequest / DTO の allowlist + enum 検証を通し `MassAssignmentProtectedKeys`
  へは登録しない / システム決定値なら payload から受けず保護キー目録へ登録する /
  **本書はこの決定をしない** = 入力主体が決まるのは要求が来てから)。
- **§6-6**: テスト項目を入力契約に応じた 2 系統へ変更しました。

再レビューをお願いします。

## 対応マトリクス (Round 4)

# 対応マトリクス: design-review Round 4

Codex 全体判定: CHANGES_REQUESTED (§6 のみ。[Warning] 1 件。他 8 節は APPROVE)

## [Warning] §6-4 / §6-6 で公開範囲カラムを `MassAssignmentProtectedKeys` へ登録すると確定しているのは不適切
- 判断: **対応する (指摘が正しい。規約の意味を取り違えていた)**
- 根拠: 保護キー不変条件が対象にするのは **payload を信頼してはならない ownership / actor /
  tenant キー** (`project_id` / `created_by` 等) である。公開範囲が**利用者の設定する業務入力**なら
  通常の入力であり、同目録へ入れると **UI からの正規の変更まで 422 で拒否する**設計になる。
  逆にシステム決定値なら保護対象にできるが、本書は入力主体を決めていない
  (決まるのは要求が来てからである)。Conditional 段階で片方だけ確定させるのは早すぎる。
- 対応内容:
  - §6-4 の「保護キー」項目を **入力契約の決定点**へ書き換えた —
    利用者入力なら FormRequest / DTO の allowlist + enum 検証を通し `MassAssignmentProtectedKeys`
    へは登録しない / システム決定値なら payload から受けず保護キー目録と対応テストへ登録する /
    **本書はこの決定をしない**。
  - §6-6 のテスト項目を入力契約に応じた 2 系統へ変更した
    (利用者入力: 許可 enum 以外が 422 かつ正規値は DTO 経由で保存 /
    システム決定値: payload 直送が 422)。


---

## 改訂後の詳細設計書 (全文)

# 詳細設計: video-manual-visibility-scope (動画マニュアルの公開範囲)

> **本設計の結論は「今は実装しない」である** (概念設計 APPROVED / conceptual-review Round 4)。
> したがって本書は**実装手順書ではなく、判断を実装可能な粒度まで固めた決定文書**である。
> 書くべきは 2 つ: **(1) 何が満たされているから不要なのか** (§4)、
> **(2) どうなったら必要になるのか** (§5)。加えて、将来 T-1 が満たされたときに
> ゼロから調べ直さずに済むよう、**実装しない前提の参照設計** (§6) を残す。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / **単一 Default Project**。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

**本タスクで特に効く禁止事項**: なし (コード変更を行わないため)。
代わりに**思考原則 2 (今必要なものだけ作る) と 4 (別物の概念を似ているからで統合しない)** が
判断基準の中心である。

### コーディングルール (将来実装する場合に適用されるもの)

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)、**RefreshDatabase** はグローバル適用 (個別 `DatabaseTransactions` 禁止)
- テストデータは Factory 生成、DTO + JsonResource パターン
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260816-1754-video-manual-visibility-scope/conceptual-design.md` (APPROVED: conceptual-review Round 4)

## 1. 判断

| 問い | 答え |
|---|---|
| 元要件の公開範囲 3 値は現行で満たされているか | **1 値 (`同じ所属`) は現行の既定として充足 / 1 値 (`全ユーザー`) は SaaS 文脈で「組織内全員」へ読み替え (literal には cross-org 不可で禁止) / 1 値 (`作成者のみ`) は表現できない** (§4) |
| 表現できない 1 値を今作るべきか | **作らない**。現時点で `created_by` でしか解けない業務要求が把握されていない |
| 今回のコード変更 | **なし** (`app/` `resources/` `routes/` `database/` `config/` `tests/` を 1 行も変更しない) |
| TODO の登録区分 | **Conditional** (Open ではない)。トリガーは §5 |

## 2. 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| — | **実装施策は 0 件** | なし | — |

### 非実装の成果物 (本タスクが残すもの)

| # | 成果物 | 置き場所 | 誰が使うか |
|---|---|---|---|
| A | 見送り判断と根拠 (充足マトリクス) | 本書 §4 | 同じ議論が再燃したときの一次資料 |
| B | Conditional の昇格条件 (T-1 記録条件 4 + 適格条件 A〜E / T-2 / T-3) | 本書 §5 | 要求受付者・設計責任者 |
| C | 将来実装する場合の参照設計 (適用点の一覧・決定点の選択肢・波及・テスト計画) | 本書 §6 | 昇格後の実装者 |
| D | `doc/02` と `doc/10` の差分記録の所在 | 本書 §7 | ドキュメント整備担当 |

> **注意**: `docs/TODO.md` への Conditional 登録は本タスクの責務ではない
> (`app-todo-add` / 後続エージェントが行う)。本書は登録に必要な材料を確定させるだけである。

## 3. 変更箇所 / 波及変更

### 変更箇所
- **なし**。

### 波及変更
- TypeScript 型定義: **なし** (`ManualFilters` / `ManualListItem` の shape は不変)
- API Resource / DTO: **なし** (`ManualListItemData` / `CaptureManualSummaryData` は不変)
- テストファイル: **なし** (既存テストは 1 件も変更しない)
- マイグレーション: **なし** (`video_manuals` にカラムを足さない =
  `RetentionTableClassificationTest` / `MassAssignmentProtectedKeys` にも影響しない)

## 4. 何が満たされているから不要なのか (現行コードによる裏付け)

### 4-1. 3 値の充足マトリクス (「満たされている」と「読み替えた」を区別する)

> **写像の宣言 (これを書かないと再燃する)**: 本設計は `doc/10 §10.5` の確定仕様に従い、
> 元要件の **「所属」を Organization 所属へ写像する**。
> **Team / Project membership は現行の読み取り境界ではない** —
> `ProjectPolicy::view` は `project_members` を見ず、Organization role の有無だけで判定する
> (Team は可視性の文脈に一度も登場しない)。
> Team あるいは Project が将来読み取り境界になる場合は §5 の T-2 で再評価する。

| 元要件の値 | 現行の状態 | 裏付け (実読) |
|---|---|---|
| **全ユーザー** | **満たされているのではなく読み替えている**。literal な全ユーザー公開は cross-org 不可により**禁止**されており、最大公開範囲を「組織内全員」へ読み替える | AGENTS.md セキュリティ不変条件 3。到達は `ResolvesCurrentOrganization` + テナント境界 404 で閉じている |
| **同じ所属** | **現行の既定として充足** (既定にしてただ 1 つの挙動)。「所属」= Organization の写像を前提とする | `ProjectPolicy::view` = 組織メンバーなら可 |
| **作成者のみ** | **表現できない** | `VideoManualPolicy` の全 ability が `ProjectPolicy` へ委譲し、`created_by` を 1 度も読まない |

### 4-2. 現行コード (認可の全体像)

```php
// app/Policies/ProjectPolicy.php — 読み取り境界は「組織」
public function view(User $user, Project $project): bool
{
    $organization = $project->organization;

    return $organization !== null && $user->organizationRole($organization) !== null;
}
```

```php
// app/Policies/VideoManualPolicy.php — manual 行の属性は認可に一切効かない
public function view(User $user, VideoManual $manual): bool
{
    $project = $manual->project;

    return $project !== null && $this->projectPolicy->view($user, $project);
}
// create / update / delete / duplicate / analyze / render / download は
// すべて $this->projectPolicy->update($user, $project) へ委譲 (同ファイル L32-L84)
```

```php
// database/migrations/2026_07_10_000100_create_video_manuals_table.php
$table->foreignId('created_by')->constrained('users'); // 列は在るが認可では使われていない
// visibility 相当のカラムは存在しない
```

### 4-3. 「作成者のみ」の代用に見える既存機能の**正確な**射程

| 機能 | 実装 | 満たすもの | **満たさないもの** |
|---|---|---|---|
| `mine` フィルタ (PC) | `ProjectController::manualRows` の `where('created_by', $user->id)` (`ManualListQuery::$mine` 経由) | 「自分の分だけ見たい」= **見る側**の意図 | 他者からの秘匿 (URL 直打ちは通る) |
| `mine` フィルタ (撮影 PWA) | `CaptureManualController::index` の同型実装 | 同上 | 同上 |
| `draft` 状態 | 撮影 PWA 一覧が `whereIn('status', [Ready, Published])`、`ManualDownloadController::show` が `status !== Published` で 404 | 撮影 PWA 一覧への露出抑止・完成動画 DL の遮断 | **PC 側の閲覧/編集は素通し** (実読した PC 側 6 系統のうち状態で拒否するのはダウンロードのみ) |

**結論**: これらは「作成者のみ」の代用ではない。
**しかし代用が要らない** — `created_by` でしか解けない業務要求が現時点で把握されていないためである
(概念設計 §4-3 の代表 5 シナリオでは 4 件が別の軸へ落ち、残る 1 件は使命の外)。

### 4-4. 入れた場合に壊れる既存の前提 (機械で固定されているもの)

| 固定物 | 何を固定しているか | 公開範囲を入れるとどうなるか |
|---|---|---|
| `tests/Feature/Projects/ManualRowAbilityPremiseTest.php` | 「download / delete の可否は manual 個別の属性 (status / **作成者** / カテゴリ) に依存しない」 | **§6-2 で確定した適用範囲 (manual-bound ability 全部) を採る場合、赤くなる**。`ManualRowAbilities::forPage` (ページ 1 回評価) を行ループへ作り直す必要がある。`view` だけに適用する形なら赤くならないが、その形は URL 直打ちでの更新・削除を残すため採らない |
| `tests/Feature/Projects/ManualListQueryCountTest.php` | 一覧のクエリ数が行数に依存しない | 上と同じ経路で影響 |
| `ControllerAuthorizationGateTest` / `TenantBoundaryOrderingTest` / `NestedRouteIdorDefenseTest` / `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` | 「テナント境界 404 は認可 403 より前」「404 は構造的事実に基づく」 | **存在秘匿 (主体依存 404) を選ぶ場合のみ**、層分けと母集団設計の再設計が要る。取得不能 (403) で足りるなら層 3 の中に収まる |

## 5. どうなったら必要になるのか (Conditional の昇格条件)

**記録条件 (4 つ。すべて書かれていること)**

1. 対象 (どの組織 / どの Project / どの manual 種別か) と要求元 (顧客名・運用責任者)
2. 見せない相手 (ロール名または個人単位)
3. どこまで隠す必要があるか — 「一覧から消えれば足りる / 内容を取得できなければ足りる (403 相当) /
   存在自体を知られてはならない (主体依存 404 相当)」の 3 択
4. 受け渡し (撮影依頼・承認・公開) の時点で可視性がどう遷移するか、あるいは遷移しないか

**適格条件 A〜E (5 つ。1 つでも「いいえ」なら不昇格)**

| # | 適格条件 | 満たさないときの行き先 |
|---|---|---|
| A | 許可する主体が **作成者本人だけ**である | 「担当者数名」→ 閲覧者リスト / 「特定ロール」→ ロール認可。`created_by` では解けない |
| B | 記録条件 3 が **「内容を取得できない」または「存在を知られない」** である | 「一覧から消えれば足りる」→ 一覧の絞り込み (`mine` の延長) |
| C | 完成後を含む**終端状態まで**作成者限定が維持される | 受け渡しで解除されるなら → 状態 / 承認 workflow |
| D | **Project 境界・workflow/状態・ロール認可・閲覧者リストのいずれでも代替できない** | 代替できるならその軸へ (概念設計 §5-5 の分岐表) |
| E | A〜D の判定を**設計責任者が確認した**と記録されている。とくに **D をどう判断したか (どの代替軸をなぜ退けたか) を 1 段落**で残す | 要求元の主張だけでは昇格しない |

**T-2 (再評価の開始条件。Open への自動昇格ではない)**:
`ProjectPolicy::view` を「project メンバーのみ」へ狭める変更が入り、Project が読み取り境界として
機能するようになったとき。「同じ所属」の意味が変わるため本設計を読み直す
(**不要になる可能性の方が高い**)。

**T-3**: `doc/02 §2.4` のデータモデルが受け入れ検査の対象として顧客と合意され、
カラムの存在自体が契約になったとき。業務要件ではなく契約要件として扱い、
「同じ所属」固定値で足りるかを最初に問う。

**昇格条件ではないもの**: 組織外への共有 (公開リンク・取引先閲覧)。
これは公開範囲ではなく**別概念**であり、別タスクとして起票する。

### 5-1. 昇格したときに最初にやる 3 手順 (設計のやり直しを防ぐ)

1. **決定点を先に固定する**: 記録条件 3 の答えが「取得不能 (403)」か「存在秘匿 (404)」か。
   ここが決まらないと適用点も波及もテスト計画も決まらない。
2. **適用点を数える** (§6-1 の一覧を出発点に、その時点の route 一覧で作り直す)。
   一覧だけに効かせる案は**採らない** (URL 直打ちで漏れる = 要求を満たさない)。
3. **`ManualRowAbilityPremiseTest` の扱いを先に決める** (行ループ化 + N+1 再設計)。
   ここを後回しにすると実装終盤でテストが赤くなり設計に差し戻る。

## 6. 将来実装する場合の参照設計 (**実装しない**)

> **この節はコードではなく地図である。** 現時点で書くのは、昇格時に同じ調査を
> やり直さないためであり、**今このとおりに実装してはならない** (思考原則 2)。
> 昇格時点のコードは変わっているため、必ず現行を読み直すこと。

### 6-1. 適用点の一覧 (2026-08-16 時点で manual に到達する参照・操作経路)

| 区分 | 場所 | 備考 |
|---|---|---|
| PC 一覧 | `ProjectController::manualRows` | `ManualListQuery` が唯一の解析点。ここに条件を足すと「絞り込み」と「認可」が同居する |
| PC 詳細以降 | `VideoManualController::show/edit/update/destroy/duplicate` | `Gate::authorize` は既に通る (ability の中身が変わる) |
| PC 付随 | `ManualScenarioController` / `ManualAnalysisController` / `ManualRenderController` / `ManualDownloadController` / `SourceDocumentController` / `CutTakeController` | 子リソース経由の到達も塞ぐ必要がある |
| 撮影 PWA | `CaptureManualController::index/show` / `CaptureTakeController` / `TakeUploadUrlController` | **撮影者が撮れなくなる詰みが出るのはここ** |
| ダッシュボード | `DashboardService::inProgress` / `recentManuals` / `shootingTargets` (3 クエリ) | 一覧に出ないのにダッシュボードに出る、を作らない |
| 通知 | `NotificationCenterService` | manual を参照する |

**数え方の注意**: 上は「本タスクで読んだ範囲」であり網羅の主張ではない。昇格時は
**`routes/web.php` の `{manual}` を含む route を起点に数え直し、加えて `VideoManual` または
cuts / takes から manual へ到達する Controller・Policy・一覧/集約/通知クエリを検索する**こと
(ダッシュボードと通知は `{manual}` を route parameter に持たないため、route 起点だけでは脱落する)。

### 6-2. 適用範囲の確定と、決定点の 2 択

**適用範囲 (曖昧にしない)**: 昇格して実装する場合、可視性の述語は
**Project 境界の確認を通した後に、manual-bound ability の全部
(`view` / `update` / `delete` / `duplicate` / `analyze` / `render` / `download`) と、
子リソース (cuts / takes) 経由の到達の共通前提**として効かせる。
**`view` だけに入れる形は採らない** — 一覧と詳細だけが隠れて、
URL を直接叩けば更新・削除できる実装になるためである
(この選択の帰結として `ManualRowAbilityPremiseTest` の前提が崩れる。§4-4)。

**決定点の 2 択 (先に決める)**

| 案 | 実装の形 | 現行との整合 | コスト |
|---|---|---|---|
| **(あ) 取得不能 = 403** | Project 境界確認の後、`VideoManualPolicy` の**全 manual-bound ability** に共通の可視性述語を適用する。子リソース (cuts/takes) の Policy も**親 manual の同じ述語**を前提にする。一覧・集約・通知クエリには**同義の query scope** を適用する (層 3 の中で完結) | テナント境界 404 の順序は不変。既存の gate 群と衝突しない | 適用点は Policy + 全クエリ。**`view` だけに足す形は採らない** (§6-2 冒頭) |
| **(い) 存在秘匿 = 404** | route の解決段階で主体依存の絞り込みを行う | **層 2 の意味 (構造的事実に基づく 404) を再定義**することになり、`NestedRouteIdorDefenseTest` 等の母集団設計から見直す | 高い。要求が本当に存在秘匿を求めるときだけ選ぶ |

### 6-3. 参照スケッチ (**実装しない**。案 (あ) を採った場合の形だけ示す)

現行:

```php
// app/Policies/VideoManualPolicy.php
public function view(User $user, VideoManual $manual): bool
{
    $project = $manual->project;

    return $project !== null && $this->projectPolicy->view($user, $project);
}
```

案 (あ) を採った場合の形 (**採用していない**):

```php
public function view(User $user, VideoManual $manual): bool
{
    $project = $manual->project;
    if ($project === null || ! $this->projectPolicy->view($user, $project)) {
        return false; // 組織境界が先 (この順序は変えない)
    }

    // 行単位の可視性は**述語を 1 か所に置く** (一覧クエリと同じ式を 2 度書かない)。
    // 目録 (deny-by-default) で「この式を書いてよいファイル」を固定する形が既存規約
    // (AdoptedReadyTakeCoverage / CurrentRenderArtifact と同型)。
    return ManualVisibility::isVisibleTo($manual, $user, $project);
}

// **同じ述語を update / delete / duplicate / analyze / render / download にも通す**
// (§6-2 の適用範囲。view だけに入れると URL 直打ちで更新・削除が通る)。
// 子リソース (cuts / takes) の Policy も親 manual の可視性を前提にする。
```

**設計上の要点** (スケッチの意図):
- 述語は 1 クラスの**ただ 1 ファイル**に置き、Policy と一覧クエリ (scope) が同じ定義を使う。
  2 か所に書くと必ず食い違う (既存の `AdoptedReadyTakeCoverage` / `CurrentRenderArtifact` の流儀)。
  **`ManualVisibility` はあくまで候補名**であり、昇格時に既存の命名と揃えて決め直す。
- 一覧側は `Builder` を受け取る scope として同ファイルから供給する
  (Policy 側と式が分岐しないこと自体をテストで固定する)。
- **撮影者への例外公開を述語に入れてはならない** (Round 2 の指摘)。
  T-1 の適格条件 A (許可主体は作成者本人だけ) と C (終端状態まで維持) を満たして昇格した
  要求に、「撮影対象になったら Project 内へ開く」規則を足すと **A / C を満たさなくなる** =
  昇格させた要求とは別物を実装することになる。
  「撮影依頼までは作成者だけ、依頼後は Project 内へ」という要求は、
  適格条件 C により**この Conditional の対象外** (状態 / 承認 workflow 側) である。
  したがって本設計で扱う「作成者のみ」は、**撮影者を含め終端まで作成者以外に開かない**もの
  ただ 1 つを指す。

### 6-4. 波及変更 (昇格時に必ず一緒に直すもの)

- **TypeScript 型**: `resources/js/pages/Projects/Show.svelte` が受ける `manuals.data[]` と
  `manualFilters` の型 (公開範囲を UI に出すなら新フィールドが増える)
- **DTO**: `ManualListItemData` / `CaptureManualSummaryData` / `ManualListQuery`
- **入力契約 (昇格時に確定する決定点)**: 公開範囲を **利用者が選択する業務入力**とするか、
  **システムが決定する値**とするかを先に決める。
  - **利用者入力なら**: FormRequest / DTO の allowlist と enum 検証を通す。
    **`MassAssignmentProtectedKeys` へは登録しない** — 同目録は payload を信頼してはならない
    ownership / actor / tenant キー (`project_id` / `created_by` 等) の目録であり、
    利用者が設定する業務値をここへ入れると**正規の変更操作まで 422 で拒否する**設計になる。
  - **システム決定値なら**: payload から受けず、保護キー目録と対応テストへ登録する。
  - 本書は**この決定をしていない** (入力主体が決まるのは要求が来てからである)。
- **保持期限**: 新カラムは表を増やさないので `RetentionTableRegistry` は不変
- **テスト**: `ManualRowAbilityPremiseTest` (前提の書き換え) /
  `ManualListQueryCountTest` (クエリ数) / `ProjectShowManualsTest` / `VideoManualCrudTest` /
  撮影側 (`Capture*`) / ダッシュボード / 通知

### 6-5. PHPStan 適合チェック (将来実装時の観点)

- [ ] 述語の戻り値型が明示されている (`bool`)
- [ ] `Builder<VideoManual>` の generics を明示する (scope を閉包で渡す形は lv10 で型が落ちやすい。
      既存の `DashboardService::shootingTargets` が「relation subquery で親 id を絞る」形を
      採っている理由と同じ)
- [ ] null 安全 (`$manual->project` は nullable。`Assert` で潰さず早期 return する既存流儀)
- [ ] enum を新設する場合は `string` backed + `label()` (`ProjectRole` / `OrganizationRole` の流儀)

### 6-6. テスト計画 (将来実装時。**今回は 1 件も書かない**)

- [ ] 作成者以外が「作成者のみ」の manual に到達したときの応答 (403 か 404 か = 決定点で決まる)
- [ ] **主体ごとの期待値**で書く (「工程へ進めない」という workflow 制約に読み替えない):
  - [ ] **作成者**は、capture 権限を持つ場合、作成者限定の manual を撮影 PWA の一覧・詳細で
        閲覧し撮影できる (現行 `ProjectPolicy::capture` は編集者である作成者を通すため、
        作成者が自分で撮るライフサイクルは成立する。これを塞ぐ根拠は T-1 に無い)
  - [ ] **非作成者**は、capture 権限や project role を持っていても、その manual を
        一覧・詳細・cuts/takes 経由のいずれからも閲覧・操作できない
  - [ ] **作成者限定でない manual** について、既存の撮影者アクセスが後退しない (最重要)
- [ ] 一覧・撮影 PWA 一覧・ダッシュボード 3 クエリ・通知で同じ述語が効くこと
- [ ] 一覧のクエリ数が行数に依存しないこと (`ManualListQueryCountTest` の維持)
- [ ] `ManualRowAbilityPremiseTest` の前提書き換え (行ループ評価への移行)
- [ ] **入力契約に応じて 2 系統**のどちらかを書く (§6-4 の決定点):
  - [ ] 利用者入力の場合: 許可 enum 以外が 422 になり、正規値が DTO 経由で保存されること
  - [ ] システム決定値の場合: payload 直送が 422 になること

### 6-7. リスク (将来実装時)

- **担当割当との不整合** (最大のリスク)。**非作成者を撮影担当として割り当てられる機構が
  存在する場合**、作成者限定の manual との不整合を検出して**その操作を拒否する**
  (可視性を例外的に開いて解決しない。§6-3)。
  **担当割当の機構が存在しないなら、その workflow 制約を先回りして追加しない** (思考原則 2)。
  なお**作成者自身が撮る**ことは作成者限定と矛盾しないので禁止しない
- **判断点の二重化**。一覧の絞り込みと認可が別々に育つと、片方だけ直した日に漏れる
- **移行の既定値**。全行 `同じ所属` で埋めるなら情報量ゼロ、`作成者のみ` で埋めると全社が不可視

## 7. `doc/02` と `doc/10` の差分記録の所在

- `doc/02 §2.4 / §2.5` の公開範囲は、確定仕様 `doc/10 §10.5` では採用されていない
  (権限は「テナント階層 + 2 ロール + Policy」へ写像済み)。
- **差分記録の正本は本設計ディレクトリ (`devnotes/20260816-1754-video-manual-visibility-scope/`) である**。
  `docs/TODO.md` へ Conditional が登録された後は、その項目が本ディレクトリを指す
  (登録前に「TODO が正本」と書くと空参照になるため、正本は常にこちら側に置く)。
- `doc/02` 側へポインタ 1 行を足すことは、次のドキュメント整備 (`app-update-docs`) の
  任意作業として扱い、**Open タスクにはしない** (実装タスクを生まない)。
- `docs/template-divergence.md` には**登録しない** — 同台帳はテンプレート構造からの逸脱の台帳であり、
  「アプリ要件ドキュメント間の解釈差」は対象外である (別物の概念を統合しない)。

### 7-1. Conditional 登録用の本文 (後続の登録エージェントはこれをそのまま使う)

> **タスク**: 動画マニュアルの公開範囲 (`作成者のみ` の導入可否)
> **区分**: Conditional (Open ではない)
> **設計**: `devnotes/20260816-1754-video-manual-visibility-scope/`
> **判断 (2026-08-16)**: 実装しない。`doc/02 §2.4/§2.5` の公開範囲 3 値のうち、
> 「同じ所属」は現行の既定 (組織メンバーなら閲覧可) として充足、「全ユーザー」は
> cross-org 不可により「組織内全員」へ読み替え済み、「作成者のみ」は表現できないが、
> `created_by` でしか解けない業務要求が現時点で把握されていない。
> 入れると通常の制作ライフサイクルで編集者 → 撮影者の受け渡しが遮断され、
> 認可の判断点が 2 種類になり、`ManualRowAbilityPremiseTest` の前提が崩れる。
> **昇格条件**: 詳細設計 §5 の記録条件 4 つ + 適格条件 A〜E をすべて満たす要求が来たとき
> (A: 許可主体が作成者本人だけ / B: 取得不能または存在秘匿が要る / C: 終端状態まで維持される /
> D: Project 境界・workflow/状態・ロール認可・閲覧者リストで代替できない /
> E: A〜D を設計責任者が確認し、D の判断理由を 1 段落で記録した)。
> **再評価条件 (自動昇格ではない)**: `ProjectPolicy::view` が project メンバー境界へ変わったとき (T-2)、
> `doc/02` のデータモデルが契約になったとき (T-3)。

## 8. 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** (ただし**今回は実装しない**ため実行されない) |
| 判断根拠 | 将来 T-1 が満たされて着手する場合、Policy・一覧クエリ・撮影 PWA・ダッシュボード・通知・前提テストの書き換えが 1 つの意味単位で動くため、他タスクと混ぜられない。今回の成果物は設計文書のみで、コードの競合は発生しない |
| 競合リスク | **なし** (`devnotes/` 以外を変更しない) |

## 9. 最終確認 (使命・禁止事項チェック)

- [x] 使命への寄与: 「作らない」ことで、編集者 → 撮影者の受け渡し (使命の中心) を壊す変更を入れない
- [x] 禁止事項: コード変更が無いため抵触なし。思考原則 2 / 4 に沿う判断である
- [x] テスト: 変更が無いため新規テストは不要 (テストなしの実装完了報告には当たらない)
- [x] 検証コマンド: **コード・設定・テストを 1 行も変更しないため、通常の実装検証コマンドは不要**
      (「`devnotes/` はどの検証にも掛からない」とは主張しない — 走査対象の設計は
      各 Architecture テスト側の事情であり、本書が保証できる範囲ではない)
</content>

