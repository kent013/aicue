## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【このレビューの特殊事情 — 最重要】
本詳細設計の結論は **「今は実装しない (Conditional として保留)」** である
(概念設計は conceptual-review Round 4 で APPROVED 済み)。
したがって本書は実装手順書ではなく、**判断を実装可能な粒度まで固めた決定文書**である。
レビューでは次を問うこと:

1. **見送り判断は妥当か** (作るべきという結論の方が正しくないか)
2. **決定文書として十分か** — この文書だけを読んだ別の設計者が、
   (a) なぜ今作らないのかを再現でき、(b) 来た要求がこの Conditional の対象かを同じ結論で判定でき、
   (c) 昇格したときにゼロから調査し直さずに着手できるか
3. **事実誤認が無いか** — 添付した現行コードと突き合わせて、設計書の主張
   (認可の委譲関係・可視性境界・既存テストが固定している前提) に誤りや誇張が無いか
4. **§6 の参照設計 (実装しない前提の地図) が過剰でないか** —
   思考原則 2 (今必要なものだけ作る) に照らして、書きすぎ (= 実質的な先回り設計) に
   なっていないか。逆に、昇格時に致命的に不足する情報が無いか

【通常のレビュー観点 (将来実装時に効くもの)】
1. コードの正確性・エッジケース・null 安全性 (§6 の参照スケッチについて)
2. 既存コードとの整合性 (命名規約・パターン)
3. PHPStan level 10 適合性
4. テスト計画の網羅性
5. DTO/JsonResource パターンの遵守 / Inertia Props vs API Response の使い分け
6. 副作用・後退リスク
7. 波及変更の網羅性 (TypeScript型定義、DTO、テスト)
8. セキュリティ (AGENTS.md のセキュリティ不変条件。特に「テナント境界 404 は認可 403 より前」
   「cross-org 不可」「変更系 route は Gate::authorize」)

【出力形式】
- 各節ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---
## 詳細設計書

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
| 元要件の公開範囲 3 値は現行で満たされているか | **2 値は満たされている / 1 値 (`作成者のみ`) は表現できない** (§4) |
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

### 4-1. 3 値の充足マトリクス

| 元要件の値 | 現行の状態 | 裏付け (実読) |
|---|---|---|
| **全ユーザー** | 実装してはならない方向で決着済み。組織内全員が最大公開範囲 | AGENTS.md セキュリティ不変条件 3 (cross-org 不可)。到達は `ResolvesCurrentOrganization` + テナント境界 404 で閉じている |
| **同じ所属** | **満たされている** (既定にしてただ 1 つの挙動) | `ProjectPolicy::view` = 組織メンバーなら可 |
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
| `tests/Feature/Projects/ManualRowAbilityPremiseTest.php` | 「download / delete の可否は manual 個別の属性 (status / **作成者** / カテゴリ) に依存しない」 | **赤くなる**。`ManualRowAbilities::forPage` (ページ 1 回評価) を行ループへ作り直す必要がある |
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
| E | A〜D の判定を**設計責任者が確認した**と記録されている | 要求元の主張だけでは昇格しない |

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

### 6-1. 適用点の一覧 (2026-08-16 時点で manual に到達する読み取り経路)

| 区分 | 場所 | 備考 |
|---|---|---|
| PC 一覧 | `ProjectController::manualRows` | `ManualListQuery` が唯一の解析点。ここに条件を足すと「絞り込み」と「認可」が同居する |
| PC 詳細以降 | `VideoManualController::show/edit/update/destroy/duplicate` | `Gate::authorize` は既に通る (ability の中身が変わる) |
| PC 付随 | `ManualScenarioController` / `ManualAnalysisController` / `ManualRenderController` / `ManualDownloadController` / `SourceDocumentController` / `CutTakeController` | 子リソース経由の到達も塞ぐ必要がある |
| 撮影 PWA | `CaptureManualController::index/show` / `CaptureTakeController` / `TakeUploadUrlController` | **撮影者が撮れなくなる詰みが出るのはここ** |
| ダッシュボード | `DashboardService::inProgress` / `recentManuals` / `shootingTargets` (3 クエリ) | 一覧に出ないのにダッシュボードに出る、を作らない |
| 通知 | `NotificationCenterService` | manual を参照する |

**数え方の注意**: 上は「本タスクで読んだ範囲」であり網羅の主張ではない。
昇格時は `routes/web.php` の `{manual}` を含む route を母集団として数え直すこと。

### 6-2. 決定点の 2 択 (先に決める)

| 案 | 実装の形 | 現行との整合 | コスト |
|---|---|---|---|
| **(あ) 取得不能 = 403** | `VideoManualPolicy::view` に `created_by` 条件を足す (層 3 の中) | テナント境界 404 の順序は不変。既存の gate 群と衝突しない | 適用点は Policy 中心。一覧クエリは「見えない行を出さない」ために別途要る |
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
```

**設計上の要点** (スケッチの意図):
- 述語は `Services/Manual/ManualVisibility` の**ただ 1 ファイル**に置き、
  Policy と一覧クエリ (scope) が同じ定義を使う。2 か所に書くと必ず食い違う
  (既存の `AdoptedReadyTakeCoverage` / `CurrentRenderArtifact` の流儀)。
- 一覧側は `Builder` を受け取る scope として同ファイルから供給する
  (Policy 側と式が分岐しないこと自体をテストで固定する)。
- 撮影者の詰みを防ぐ規則 (例: 「撮影対象になった時点で Project 内へ開く」) を
  **述語の中**に置く。呼び出し側の分岐にしない。

### 6-4. 波及変更 (昇格時に必ず一緒に直すもの)

- **TypeScript 型**: `resources/js/pages/Projects/Show.svelte` が受ける `manuals.data[]` と
  `manualFilters` の型 (公開範囲を UI に出すなら新フィールドが増える)
- **DTO**: `ManualListItemData` / `CaptureManualSummaryData` / `ManualListQuery`
- **保護キー**: `MassAssignmentProtectedKeys` へ新カラムを追加 (payload 直送を 422 にする)
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
- [ ] **撮影者が撮るべき manual を見られること** (詰みが無いことの回帰。最重要)
- [ ] 一覧・撮影 PWA 一覧・ダッシュボード 3 クエリ・通知で同じ述語が効くこと
- [ ] 一覧のクエリ数が行数に依存しないこと (`ManualListQueryCountTest` の維持)
- [ ] `ManualRowAbilityPremiseTest` の前提書き換え (行ループ評価への移行)
- [ ] 保護キー直送が 422 になること

### 6-7. リスク (将来実装時)

- **撮影者の詰み** (最大のリスク)。述語の中に「撮影対象になったら開く」規則を持たないと、
  編集者が設定した瞬間に現場が撮れなくなる
- **判断点の二重化**。一覧の絞り込みと認可が別々に育つと、片方だけ直した日に漏れる
- **移行の既定値**。全行 `同じ所属` で埋めるなら情報量ゼロ、`作成者のみ` で埋めると全社が不可視

## 7. `doc/02` と `doc/10` の差分記録の所在

- `doc/02 §2.4 / §2.5` の公開範囲は、確定仕様 `doc/10 §10.5` では採用されていない
  (権限は「テナント階層 + 2 ロール + Policy」へ写像済み)。
- **差分記録の正本は本設計ディレクトリと `docs/TODO.md` の Conditional 項目**である。
- `doc/02` 側へポインタ 1 行を足すことは、次のドキュメント整備 (`app-update-docs`) の
  任意作業として扱い、**Open タスクにはしない** (実装タスクを生まない)。
- `docs/template-divergence.md` には**登録しない** — 同台帳はテンプレート構造からの逸脱の台帳であり、
  「アプリ要件ドキュメント間の解釈差」は対象外である (別物の概念を統合しない)。

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
- [x] 検証コマンド: コード・設定を 1 行も変更していないため実行不要
      (`devnotes/` はどの検証コマンドの対象でもない)
</content>


---
## 参考: 概念設計 (APPROVED)

# 概念設計: video-manual-visibility-scope (動画マニュアルの公開範囲)

> **このタスクは「作るかどうかの判断」から始まる。** 結論は末尾の §7。
> 現時点の暫定結論は **「今は作らない (Conditional として登録)」** であり、本書はその根拠を
> 現行コードの実読に基づいて示す。Codex 合議では **この判断そのもの**を論点として問う。

## 1. 背景・課題

`doc/02 §2.4` のデータモデルは動画マニュアルに
**`公開範囲`(作成者のみ・同じ所属・全ユーザー)** を持ち、`doc/02 §2.5` の権限の節も
「動画には公開範囲（作成者のみ/同一所属/全ユーザー）を設定可能」と述べている。

一方、現行実装には **カラムも UI も無い**
(`grep -rn "visibility|公開範囲" app resources database routes` の結果は
DOM の `visibilitychange` ばかりで、ドメイン概念としての公開範囲は 0 件)。

`doc/02` は要件の出自 (PC サイト Excel の概要設計書) をそのまま写した章であり、
**実装の確定仕様は `doc/10` である**。`doc/10 §10.5「課金・権限の確定値」` は権限について
次の 1 行しか置いていない:

> **ロール**: project_admin=編集者、project_member=撮影者（rename のみ）。
> permission は Policy で（編集者=manual CRUD/render/download、撮影者=take capture/upload/adopt + manual read）。

つまり **確定仕様の側では、権限は「テナント階層 + 2 ロール」に写像済み**であり、
行ごとの公開範囲は確定値に含まれていない。本タスクは、この写像で
元要件の 3 値が実質的に満たされているのか、それとも本当に欠けているのかを判定する。

## 2. 現行の可視性境界 (実読した事実)

### 2-1. 階層とロール

- `Organization → (Team) → Project`。`Project` の所属組織は `projects.organization_id`。
- 組織ロール `App\Enums\OrganizationRole` = `organization_owner` / `organization_admin` /
  `organization_member` (`canManage()` は owner/admin のみ true)。
- プロジェクトロール `App\Enums\ProjectRole` = `project_admin` (**編集者**) /
  `project_member` (**撮影者**)、`project_members` pivot で保持。

### 2-2. 読み取りの境界は「組織」であって「プロジェクト」ではない

`app/Policies/ProjectPolicy.php`:

```php
public function view(User $user, Project $project): bool
{
    $organization = $project->organization;

    return $organization !== null && $user->organizationRole($organization) !== null;
}
```

**現行では、組織メンバーであれば当該組織の全プロジェクトを閲覧できる**。
`project_members` への所属は読み取りの条件ではない (書き込み `update`/`delete` と
撮影 `capture` の判定にのみ効く)。

`app/Policies/VideoManualPolicy.php` は `view` を `ProjectPolicy::view` に、
それ以外の全 ability (`create`/`update`/`delete`/`duplicate`/`analyze`/`render`/`download`) を
`ProjectPolicy::update` に委譲している。**manual 行の属性 (作成者・状態・カテゴリ) は
1 つも認可判断に使われていない。**

### 2-3. 3 値の現行への写像

| 元要件の値 | 現行での対応 | 判定 |
|---|---|---|
| **全ユーザー** | 該当なし。**cross-org の read/write はセキュリティ不変条件 3 で禁止**されており、実装できない (実装してはいけない) | 構造的に不可。組織内全員が現行の最大公開範囲 |
| **同じ所属** | `所属` は Organization へ写像済み。`ProjectPolicy::view` が「組織メンバーなら可」= **現行の既定にしてただ 1 つの挙動** | **満たされている** |
| **作成者のみ** | `video_manuals.created_by` は存在するが、認可では 1 度も参照されない。同一組織の他メンバーからは常に見える | **表現できない** (現行に相当する状態は無い) |

### 2-4. 「作成者のみ」の近傍にある既存機能 (誇張しないための正確な範囲)

- **一覧の自作フィルタ `mine`**: `ManualListQuery::$mine` (PC 一覧) と
  `CaptureManualController::index` の `$mine` (撮影 PWA) の双方に既にある。
  どちらも `where('created_by', $user->id)` で、**表示の絞り込みであって認可ではない**。
  満たすのは **「自分の作った分だけを見たい」という *見る側* の意図だけ**であり、
  **「他人に見せたくない」という *見せない側* の要求は 1 ミリも満たさない**。
- **状態 `VideoManualStatus`** (`draft` / `analyzing` / `ready` / `rendering` / `published`):
  撮影 PWA の一覧は `whereIn('status', [Ready, Published])` で絞る。
  完成動画の受け取り (`download` / `playback` の finished 側) は
  `status === Published` が必須条件 (`VideoManualController::show`)。
  - **ただし `draft` は PC 側の閲覧制御ではない** (Round 1/2 の指摘を受けて実読・訂正):
    `ProjectController::manualRows` は状態の既定絞り込みを**持たず**
    (`ManualListQuery::$status` は利用者が明示したときだけ `where` に載る)、
    `ProjectPolicy::view` が組織メンバー全員に true を返すため、
    **PC 一覧では draft も同一組織の全員 (撮影者を含む) に見えている**。
    `Projects/Show.svelte` の状態フィルタも利用者の任意選択であって既定の遮蔽ではない。
  - **PC 側の主要 6 系統を実読して確認した** (状態による遮断の有無。
    **この 6 系統以外は見ていない** = 「PC 側の全経路」を主張しない):

    | 経路 | 状態による遮断 |
    |---|---|
    | `VideoManualController::show` / `edit` / `update` / `destroy` / `duplicate` | 無し (`finishedJob` の算出にのみ `Published` を使う = 表示の出し分け) |
    | `ManualScenarioController` (シナリオ保存) | 無し |
    | `ManualAnalysisController` (解析実行/ポーリング) | 無し |
    | `SourceDocumentController` (SOP 投入) | 無し |
    | `CutTakeController` (テイク一覧・採用) | 無し |
    | `ManualDownloadController::show` | **有り** (`status !== Published` で 404) |

    **実読したこの 6 系統のうち、状態によってリクエストを拒否するのは
    `ManualDownloadController::show` だけ**である
    (`ManualRenderController` や撮影 PWA 側の詳細・アップロード・再生経路は未調査)。
  - したがって正確には **「`draft` が抑えているのは撮影 PWA 一覧への露出と完成動画の
    ダウンロードであって、PC 側の閲覧・編集ではない」** であり、
    「制作途中は誰にも見えない」とは書けない。

## 3. 仮説と検証

**仮説**: 元要件の公開範囲 3 値のうち、実装すべき差分は「作成者のみ」1 値だけであり、
**`doc/02` の値を「完成後も維持される恒久的な公開範囲」と解釈する限り**、その「作成者のみ」は
AI-CUE の使命 (編集者が設計 → 撮影者が撮る → 現場が観る) と正面から衝突するため、
**作るべきではない**。

> **前提の明示**: 「撮影依頼を出すまでは作成者だけ、依頼後は Project 内へ」のように
> **可視範囲が受け渡しに応じて遷移する**要求は、恒久的な公開範囲ではなく
> **状態遷移 (workflow)** である。本タスクはそれを否定しない (§4 の (B) として分離する)。

検証の観点は 3 つ:

1. **3 値が満たされているか** → §2-3 のとおり、2 値は満たされ (うち 1 値は
   実装が禁止されている方向で決着済み)、残る 1 値は表現できない。
2. **「作成者のみ」に業務上の必要があるか** → §4。
3. **入れた場合の構造的コストとリスク** → §5。

## 4. 「作成者のみ」に業務上の必要があるか

**先に 2 つの要求を分ける** (Round 1 の指摘。混ぜると議論が壊れる):

- **(A) 恒久的な行単位アクセス制御としての「作成者のみ」** —
  「この manual は作成者以外には (完成後も) 見せない」。`doc/02` の公開範囲はこちらである。
- **(B) 一時的な作業中の非共有** — 「書きかけを人に見られたくない」「撮影依頼を出すまで出さない」。
  これは**制作の進捗**の話であり、状態 (`draft`) と承認 workflow の論点である。

本タスクが判定するのは **(A)** である。(B) は同じカラムで実現しようとすると
「進捗」と「アクセス権」という別概念を 1 軸に統合することになり思考原則 4 に反する。
(B) に実需があるなら**状態側の設計 (承認 workflow / draft の露出範囲) として別に起票する**
(§5-5 の代替軸を参照)。

### 4-1. (A) は使命の中心にある受け渡しを壊す

AI-CUE の使命は「標準作業を起点に AI が教材設計し撮影を指示する」ことであり、
**制作は 1 人で完結しない**。少なくとも次の受け渡しがある:

1. 編集者 (project_admin / org 管理者) が manual を作り、SOP を投入し、AI 解析でシナリオを作る
   (`VideoManualController::store` が `created_by` にその編集者を書く)
2. **撮影者 (project_member) が撮影 PWA でその manual を開いて撮る**
   (`CaptureManualController::show` → `Gate::authorize('view', $manual)`)
3. 編集者がテイクを採用しレンダする
4. 現場がマニュアルとして観る

**「作成者のみ」を、通常の教材制作ライフサイクル (SOP 投入 → シナリオ → 撮影 → レンダ → 公開) へ
載せる manual に適用すると、2 の受け渡しが遮断される。**
作成者 = 編集者であるため、「作成者のみ」の manual は撮影者から見えない
= 撮るべき対象が一覧にも出ず、URL を知っていても弾かれる
(「撮影者が撮るべきマニュアルを見られなくなる詰み」)。
回避するには「作成者のみでも撮影者には見せる」等の例外を足すことになるが、
それは**もはや「作成者のみ」ではない** (名前が意味を失う = 思考原則「機能の名前に立ち返れ」に反する)。

> **主張の範囲を限定する** (Round 3 の指摘): 設定が任意である以上、
> 「作成者限定を選んだ manual が撮影工程へ進まない」こと自体は仕様どおりであり、
> アプリ全体の受け渡しが壊れるわけではない。見送りの根拠は
> **「通常の制作ライフサイクルへ載せる manual に適用すると受け渡しを遮断する」** と
> **「それでも恒久的に本人だけへ残したい実要求が現時点で無い」** の**組合せ**である。

### 4-2. 「誰に見せたくないのか」を切り分ける

(A) を求める声が出たとき、実際の対象は次のどれかである。**現行で何が起きているか**を並べる:

| 見せたくない相手 | 現行の実際 | 妥当な扱い |
|---|---|---|
| 撮影者に、まだ撮らせたくない | 撮影 PWA 一覧は `ready`/`published` のみ = **既に出ていない**。ただし PC 一覧では見える | 露出の実害があるなら PC 一覧の既定絞り込み (状態) の論点。公開範囲ではない |
| 同僚の編集者にも見せたくない | **見えている** (組織メンバー全員が閲覧可) | 本当に必要なら (A) が要る。ただし §4-3 のとおり、多くは Project 分割で足りる |
| 別部署・別ラインの人に見せたくない | **見えている** (組織メンバー全員が閲覧可) | **Project の分割**が正面の解。Project は既に存在する境界概念である |
| 組織外の人に見せたくない | **見えていない** (cross-org 不可) | 現行で満たされている |

「見せたくない相手」が *組織の中の一部* である場合、AI-CUE には
**Project という分割単位の概念**が既にある。ただし **今の Project は代替機能として使えない**
(Round 2 の指摘を受けて事実確認):

- **v1 スコープは「単一 Default Project」** (AGENTS.md / `DefaultProjectResolver` の docblock。
  撮影 PWA の入口 `capture.home` は org の先頭 project へ redirect する)。
  複数 Project の作成自体は PC UI から可能 (`ProjectController::create` / `store`) だが、
  撮影 PWA 側は単一 Project 運用を前提にしている。
- **分割しても可視性は分離されない**。`ProjectPolicy::view` が組織メンバー全員に true を返すため、
  Project を分けても他 Project の manual は見える。

したがって Project 分割は「**今すぐ使える代替**」ではなく、
**要求が出たときに `visibility_scope` より先に設計すべき将来案** である
(必要なのは「複数 Project 運用」と「読み取り境界の導入」の 2 点セット)。
それでもこちらが先なのは、**既に存在する境界概念を機能させる**方が
**行ごとの新しい認可軸を足す**より、思考原則 1 (既存機構のレンジ内でやる) と
2 (今必要なものだけ作る) に沿うからである。

### 4-3. 「作成者のみ」を要求しそうな業務シナリオと、その正しい扱い

| シナリオ | 公開範囲 (`作成者のみ`) で解けるか | 正しい扱い |
|---|---|---|
| 顧客別・工程別の機密動画を同一組織内でも分けたい | 解けない | **Project 境界** (複数 Project 運用 + `ProjectPolicy::view` の境界化)。分けたい単位は「manual 1 件」ではなく「工程/顧客の束」である |
| 未承認 SOP から生成した draft を承認者以外に見せたくない | 解けない | **承認 workflow / 状態** (§4 の (B))。可視性ではなく「まだ承認されていない」という進捗の表現 |
| 外部委託の撮影者には特定プロジェクトだけ見せたい | 解けない | **Project 境界 + project_members**。委託先は「特定 manual だけ」ではなく「特定 Project だけ」で招く |
| 事故・懲戒・不適合対応の動画を限定公開したい | 解けない | 見せたい相手は「作成者 1 人」ではなく「安全担当の数名」。**閲覧者リスト**という別概念であって、`created_by` では表現できない |
| 編集者個人の恒久的な下書き・検証用 manual を持ちたい | **解ける (この 5 件では唯一)** | **棄却する**。AI-CUE は「標準作業を組織の教材へ形式化する」装置であり、組織へ渡らない個人所有物を持たせることは使命から外れる (SECI の外部化が起きない)。**機密性を必要としない**個人的な試行なら、`draft` + `mine` の一覧運用で足りる (秘匿にはならない) |

**検討したこの代表 5 シナリオでは、4 件が「作成者のみ」では解けず**、
Project 境界 / workflow・状態 / 閲覧者リストのいずれかで扱うべきものだった。
当てはまる 5 件目は使命の外にあるため棄却する。
**これは網羅の主張ではない** — 代表的な反例と、現時点で把握している要求の範囲での結論である。
未知の要求は §7 の T-1 (適格条件つき) で捕捉する。
「限定公開したい」という言葉が出たときに `created_by` へ飛びつくと、
**別物の概念を似ているからで統合する** (思考原則 4) ことになる。

## 5. 入れた場合の構造的コストとリスク

### 5-1. 認可の判断点が二重になる

現行は **「テナント境界 (組織/プロジェクトの URL 整合) → 404」→「ロール認可 → 403」** の
2 層で、AGENTS.md セキュリティ不変条件 9/10 と
`ControllerAuthorizationGateTest` / `TenantBoundaryOrderingTest` /
`NestedRouteIdorDefenseTest` が順序と網羅を機械強制している。

行単位の公開範囲は、この 2 層のどちらにも素直に載らない:

**まず要求を 2 段に分ける** (Round 2 の指摘。ここを混ぜるとコスト見積りが狂う):

- **(あ) 取得不能まででよい** (内容が読めなければよく、存在は知られてよい) →
  **403 で足りる**。これは層 3 の中の条件追加であり、現行の層分けは壊れない。
- **(い) 存在秘匿まで要る** (「その manual がある」ことすら知られたくない) →
  **主体依存の 404** が要る。これは以下のとおり現行の固定物の再設計を伴う。

- **403 (層 3) では存在が漏れる**。(い) の要求に対して 403 を返せば、
  同僚が「その id の manual は存在する」と知れる。(い) の目的を果たさない。
- **404 として実装すると、現行の層分けを再設計することになる**。
  主体依存の秘匿 404 は設計として**あり得ない訳ではない** (一般には妥当な手法である)。
  問題は本リポジトリの現行不変条件との関係で、テナント境界 404 は
  「親子関係の不整合」という**構造的事実**に基づき「誰が主体か」に依存しない、という
  読み方の上に `ControllerAuthorizationGateTest` /
  `TenantBoundaryOrderingTest` / `NestedRouteIdorDefenseTest` /
  `tests/Feature/Security/TenantBoundaryPrecedenceTest.php` が乗っている。
  主体依存の 404 を足すなら、**この読み方と目録の母集団設計を作り直す**必要がある
  (不可能ではないが安くはない)。

どちらを選んでも、**認可の判断点が 2 種類になる** (「構造的な親子関係」と「行ごとの主体依存」)。
「AGENTS.md のセキュリティ不変条件に矛盾なく載るか」への答えは、
**(あ) なら層 3 に載る / (い) なら既存の固定物の再設計が要る** である。
どちらの要求なのかを確かめずに設計に入ってはならない (T-1 の条件 4 で問う)。

### 5-2. 効かせる場所が広い (一覧の絞り込みだけでは終わらない)

「一覧から消えるが URL を直接叩けば見える」は公開範囲ではなく飾りである。
本当に効かせるなら、少なくとも次の全経路に同じ述語が要る:

- PC 一覧 `ProjectController::manualRows`
- 撮影 PWA 一覧 `CaptureManualController::index`
- ダッシュボード 3 クエリ (`DashboardService` L110 / L163 / L189)
- 詳細・編集・シナリオ保存・解析・レンダ・プレビュー・再生・ダウンロード
  (`VideoManualController` / `ManualScenarioController` / `ManualAnalysisController` /
  `ManualRenderController` / `ManualDownloadController` / `SourceDocumentController`)
- 子リソース (cuts/takes) 経由の到達
  (`CutTakeController` / `CaptureTakeController` / `TakeUploadUrlController` は
  `{manual}` を経由するため、manual が見えないなら take も見えてはならない)
- 通知センター (`NotificationCenterService` が manual を参照する)

つまり **10 か所以上の読み取り経路と、Policy の全 ability** に判断が増える。
現行の「manual 行の属性は認可に一切効かない」という単純さが失われる。

### 5-3. 既存の deny-by-default の目録・前提テストと衝突する

- `Services/Manual/ManualRowAbilities` は **「download / delete の可否は manual 個別の属性
  (status / 作成者 / カテゴリ) に依存しない」** ことを前提に、ページで 1 回だけ ability を評価している。
  この前提は `tests/Feature/Projects/ManualRowAbilityPremiseTest.php` が
  **「属性の異なる 3 行 (状態・作成者・カテゴリが全部違う) で結果が一致すること」**として固定しており、
  公開範囲を入れると**この前提が壊れて赤くなる**。復旧には行ループでの ability 評価への
  作り直しと N+1 の再設計が要る (docblock に手順まで書かれている既知の分岐点)。
- `ManualListQueryCountTest` はクエリ数の行数非依存を固定しており、上と同じ影響を受ける。
- 一覧クエリの解析点は `ManualListQuery` **ただ 1 つ**という設計 (一覧の絞り込みと
  行内削除の着地先が同じ VO を通る) が、公開範囲という「絞り込みではない条件」の
  追加でぼやける。

### 5-4. 移行時の既定値の危険

既存行への backfill を「作成者のみ」に倒すと**全社の既存マニュアルが即座に不可視**になり、
「同じ所属」に倒すと新カラムは**全行同値で情報量ゼロ**になる。
後者は「今必要でないものを作った」ことの機械的な証拠になる。

### 5-5. 要求の単位で分岐する (固定の優先順位は置かない)

Round 2 の指摘のとおり「常に案 1 が先」ではない。**分けたい単位**で行き先が決まる:

| 要求の単位 | 行き先 | 現行との距離 | 備考 |
|---|---|---|---|
| 束・顧客・工程・委託先ごと | **Project 境界** (複数 Project 運用 + `ProjectPolicy::view` の境界化) | 既存の `project_members` / `ProjectRole` を使う。判断点は増えない (層 3 の中で条件が変わる) | v1 は単一 Default Project 前提なので、運用側の設計も同時に要る |
| 承認前だけ隠したい | **workflow / 状態** | `VideoManualStatus` に載る (状態機械の論点) | 可視性カラムでやらない |
| manual 単位で「特定の複数人」に見せたい | **閲覧者リスト (別概念)** | 新概念。`created_by` では表現できない | Project を乱造するより適切な場合がある |
| 本当に「作成者本人だけ」 | **`visibility_scope` を再評価** | 判断点が 2 種類になり、10 か所以上の経路と前提テストを作り直す | 該当する業務シナリオが §4-3 では 1 件も残らなかった |
| Project 内の**既存ロール**単位 (編集者には見せるが撮影者には見せない 等) | **`ProjectRole` / Policy ability** | 既存の Policy 階層の中。新しい軸は増えない | 「限定公開」に見えて実体はロール認可であることが多い |
| 業務ロール単位だが**既存ロールで表現できない** (安全管理担当だけ 等) | **ロールモデルの再評価、または閲覧者グループ** | ロール体系の設計論点 | manual 単位の個別 ACL に飛びつかない |
| 組織外へ見せたい | **共有リンク (別タスク)** | cross-org 不可とは別枠 (署名付き URL) | §6 |

> **1 要求を必ず 1 行へ押し込まない**: 「同一組織の全員に見せるが `published` になってから」の
> ような要求は、**状態 × 対象の複数軸の合成**である。表は「どの軸が要るか」を選ぶためのもので、
> 排他の分類ではない。

**現時点で把握している要求の範囲では「作成者本人だけ」の行に落ちるものが 1 件も無い**ことが、
見送りの中核である。

## 6. 「全ユーザー」をどう扱うか (誤って作らないための明文化)

元要件の「全ユーザー」は、**単一テナントの社内システム**を前提にした値である
(ユーザー表が 1 つ・`所属` はその中の 1 カラム)。AI-CUE は組織で分離された SaaS であり、
**組織を跨ぐ read はセキュリティ不変条件 3 で禁止**されている。
したがって「全ユーザー」は
**「組織内の全員」= 現行の既定**へ写像するのが唯一の妥当な読み替えであり、
文字どおりの全ユーザー公開を実装してはならない。

将来「組織外へ見せたい」要求が出た場合、それは公開範囲ではなく
**別概念 (期限付き共有リンク / 公開カタログ)** として設計する
(思考原則 4: 別物の概念を似ているからで統合しない)。

## 7. 結論 (暫定) と、必要になる条件

**結論: 今は作らない (Conditional として条件付き保留)。**
`doc/02` の公開範囲を「完成後も維持される恒久的な行単位アクセス制御」と解釈する限り、
根拠は以下 5 点:

1. 3 値のうち「同じ所属」は現行の既定として満たされ、「全ユーザー」は
   セキュリティ不変条件 (cross-org 不可) により**実装してはならない方向で決着済み**である。
2. 残る「作成者のみ」は、**通常の教材制作ライフサイクルへ載せる manual に適用すると**
   編集者 → 撮影者の受け渡しを遮断する (撮影者が撮るべき manual を見られない詰みを作る)。
   かつ、それを承知で恒久的に本人だけへ残したい実要求が現時点で無い (根拠 3)。
3. 「作成者のみ」を要求しそうな業務シナリオを 5 件検討したが、**4 件は `created_by` では
   解けず** (実体は Project 境界 / workflow・状態 / 閲覧者リスト)、
   唯一当てはまる 1 件 (個人の恒久下書き) は使命の外にあるため棄却できる (§4-3)。
   なお `mine` と `draft` は**アクセス制御要求を満たさない** (`mine` は見る側の意図だけ、
   `draft` は撮影 PWA 一覧の露出だけ) — が、そもそも要求の実体が「作成者のみ」ではない。
4. (補助的根拠) 入れると認可の判断点が 2 種類になる。**コストは要求の深さで変わる** —
   「取得不能」までなら 403 で層 3 に載るが、「存在秘匿」まで求めるなら主体依存 404 となり、
   現行の層分けと目録・テスト群の再設計が要る (§5-1)。これは単独では見送りの根拠にならず、
   根拠 3 (実要求が無い) と合わせて効く。
5. 10 か所以上の読み取り経路と、`ManualRowAbilities` の前提テスト
   (`ManualRowAbilityPremiseTest` が「可否は manual の属性に依存しない」ことを固定) を
   作り直すことになる。今それを支払う業務上の必要が無い。

### 差分記録の所在 (doc/02 を根拠に再発火させないため)

`doc/02 §2.4 / §2.5` の公開範囲は、**確定仕様 `doc/10 §10.5` では採用されていない**
(権限は「テナント階層 + 2 ロール + Policy」へ写像済み)。この差分の記録の正本は
**本設計ディレクトリと、`docs/TODO.md` の Conditional 項目**である。
`doc/02` 側へポインタ 1 行を足すことは、次のドキュメント整備 (`app-update-docs`) の
任意作業として扱い、本タスクでは Open タスクにしない (実装タスクを生まない)。

### Conditional 登録のトリガー条件 (Open へ昇格する条件)

- **T-1 (主条件)**: 下の **記録条件 4 つ**がすべて書かれ、かつ **適格条件 A〜E の 5 つ**が
  すべて「はい」である要求が 1 件でも来たとき。**記録があるだけでは昇格しない**
  (記録は判定の入力であって、判定の結果ではない)。

  **記録条件 (何が書かれているか)**
  1. 対象 (どの組織 / どの Project / どの manual 種別か) と、要求元 (顧客名・運用責任者)
  2. 見せない相手 (ロール名または個人単位。「なんとなく限定」は不可)
  3. どこまで隠す必要があるか — 「一覧から消えれば足りる / 内容を取得できなければ足りる (403 相当) /
     存在自体を知られてはならない (主体依存 404 相当)」の 3 択
  4. 受け渡し (撮影依頼・承認・公開) の時点で可視性がどう遷移するか、あるいは遷移しないか

  **適格条件 A〜E (この Conditional の対象要求か。1 つでも「いいえ」なら不昇格)**

  | # | 適格条件 | 満たさないときの行き先 |
  |---|---|---|
  | A | 許可する主体が **作成者本人だけ**である | 「担当者数名」なら **閲覧者リスト** / 「特定ロール」なら **ロール認可** の要求。`created_by` では解けないため昇格しても解決しない |
  | B | 記録条件 3 が **「内容を取得できない」または「存在を知られない」** である | 「一覧から消えれば足りる」なら**一覧の絞り込み**の要求 (`mine` の延長) |
  | C | 完成後を含む**終端状態まで作成者限定が維持される** | 受け渡しに伴って解除されるなら **状態 / 承認 workflow** の要求 |
  | D | **Project 境界・workflow/状態・ロール認可・閲覧者リストのいずれでも代替できない** | 代替できるならその軸へ。§5-5 の分岐表を使う |

  | E | A〜D の判定を、要求元の主張としてではなく **設計責任者が確認した**と記録されている | 未検証の主張だけでは昇格しない |

  この形なら「安全担当 3 名だけに見せたい」は A で、「撮影依頼までは自分だけ」は C で、
  「一覧が散らかるので他人の分を消したい」は B で、それぞれ**確実に外れる**。
- **T-2 (再評価の開始条件。Open への自動昇格ではない)**:
  `ProjectPolicy::view` を「project メンバーのみ」へ狭める変更が入り、
  Project が読み取り境界として機能するようになったとき。
  「同じ所属」の意味が変わるため本設計を読み直す。
  **不要になる可能性の方が高い** (組織内の分離要求は Project 境界に吸収されるため)。
- **T-3**: `doc/02 §2.4` のデータモデルが受け入れ検査の対象として顧客と合意され、
  カラムの存在自体が契約になったとき (業務要件ではなく契約要件として扱い、
  そのときは「同じ所属」固定値で足りるかを最初に問う)。

**昇格条件ではないもの**: 組織外への共有 (公開リンク・取引先閲覧) の要求。
これは公開範囲ではなく**別概念**であり、**別タスクとして起票する** (§6)。

## 8. スコープ外

- `ProjectPolicy::view` の見直し (組織メンバー全員が全プロジェクトを読める点)。
  これは公開範囲とは別の論点であり、本タスクでは変更しない (T-2 として条件化する)。
- 多言語 (`doc/02 §2.5` の前半) の要否判断。
- `mine` フィルタ・`draft` 状態の挙動変更。
</content>
</invoke>


---
## 関連する現行コード

### app/Policies/ProjectPolicy.php
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/**
 * プロジェクトの認可。組織所属の確認は親 (Organization) 経由で行う (直 fetch 禁止)。
 *
 * - 閲覧: 組織メンバーなら可 (組織管理者は配下プロジェクトに暗黙アクセス = 継承規則)
 * - 作成: 組織の owner / admin
 * - 更新・削除: 組織の owner / admin、または当該プロジェクトの project_admin
 *
 * viewAny / create は対象 Project が無いため Organization を追加引数に取る
 * (Gate::authorize('create', [Project::class, $organization]))。
 */
class ProjectPolicy
{
    /** 一覧閲覧: 組織メンバーなら可 */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization) !== null;
    }

    /** 閲覧: 所属組織のメンバーなら可 */
    public function view(User $user, Project $project): bool
    {
        $organization = $project->organization;

        return $organization !== null && $user->organizationRole($organization) !== null;
    }

    /** 作成: 組織の owner / admin */
    public function create(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization)?->canManage() ?? false;
    }

    /** 更新: 組織の owner / admin または project_admin */
    public function update(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /** 削除: 組織の owner / admin または project_admin */
    public function delete(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /**
     * 撮影 (take の capture/upload/adopt): 管理権限者または project メンバー
     * (doc/10 §10.5 撮影者)。TakePolicy が全 ability を本メソッドへ委譲する。
     */
    public function capture(User $user, Project $project): bool
    {
        if ($this->canManageProject($user, $project)) {
            return true;
        }

        $organization = $project->organization;
        if ($organization === null || $user->organizationRole($organization) === null) {
            return false; // cross-org 不変条件
        }

        return $project->memberRole($user) !== null; // Admin / Member どちらも撮影可
    }

    /**
     * プロジェクト管理権限の判定。
     * 組織ロールは laratrust_team_id 明示 (organizationRole)、
     * プロジェクトロールは project_members pivot (memberRole) で判定する。
     */
    private function canManageProject(User $user, Project $project): bool
    {
        $organization = $project->organization;
        if ($organization === null) {
            return false;
        }

        if ($user->organizationRole($organization)?->canManage() ?? false) {
            return true;
        }

        // 組織メンバーでなければ project ロールがあっても不可 (cross-org 不変条件)
        if ($user->organizationRole($organization) === null) {
            return false;
        }

        return $project->memberRole($user) === ProjectRole::Admin;
    }
}

```

### app/Policies/VideoManualPolicy.php
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/**
 * VideoManual (Project 配下の動画マニュアル) の認可。
 * 子リソースは親 Policy に委譲する (直 fetch 禁止)。
 *
 * 権限表 (doc/10 §10.5): 編集者 (project_admin / org 管理者) = write 全可、
 * 撮影者 (project_member) = show / 一覧のみ。write 判定は ProjectPolicy::update が担う。
 */
class VideoManualPolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 閲覧: プロジェクトを閲覧できる人 (撮影者も可) */
    public function view(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }

    /** 作成: プロジェクトを操作できる人 (対象 VideoManual が無いため Project を追加引数に取る) */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->update($user, $project);
    }

    /** 更新 (メタデータ): プロジェクトを操作できる人 */
    public function update(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 削除: プロジェクトを操作できる人 */
    public function delete(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 複製 (別名保存): 元を閲覧でき、かつ同一プロジェクトに作成できる人 = プロジェクト編集者のみ。撮影者は不可 */
    public function duplicate(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** AI 解析の実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 */
    public function analyze(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** レンダ/プレビューの実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 (§10.5) */
    public function render(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }

    /** 完成動画のダウンロード: 編集者のみ (§10.5。ポーリングは view = 撮影者も可) */
    public function download(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->update($user, $project);
    }
}

```

### app/Enums/ProjectRole.php / OrganizationRole.php
```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * プロジェクトロール (project_members pivot)。Q2 決定の正規名。
 * AI-CUE のドメイン表示名 (doc/10 §10.5): project_admin=編集者 / project_member=撮影者。
 * permission キー (value) はテンプレート既存のまま rename しない (表示名のみ差し替え)。
 */
enum ProjectRole: string
{
    case Admin = 'project_admin';
    case Member = 'project_member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => '編集者',
            self::Member => '撮影者',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 組織ロール (Laratrust team スコープ)。Q2/Q10 決定の正規名。
 * アプリ固有のロール体系が必要な場合もこの 3 値の構造 (owner/admin/member) は維持し、
 * label() とシーダーで表現を差し替える。
 */
enum OrganizationRole: string
{
    case Owner = 'organization_owner';
    case Admin = 'organization_admin';
    case Member = 'organization_member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'オーナー',
            self::Admin => '管理者',
            self::Member => 'メンバー',
        };
    }

    /** 管理権限 (メンバー管理・設定変更) を持つか */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }
}

```

### app/Http/Controllers/Projects/ProjectController.php (manualRows 抜粋 L146-227)
```php
    /**
     * 動画マニュアル一覧 rows (paginate + DTO で shape を固定)。
     * 未分類は category => null (フロントは「未分類」を表示する)。
     * creator は退会/削除で解決不可のとき null (実運用では FK RESTRICT で常に解決)。
     *
     * @return array{
     *   data: list<array{id: int, title: string, status: string,
     *     category: array{id: int, name: string}|null,
     *     creator: array{id: int, name: string}|null,
     *     created_at: string, updated_at: string,
     *     duration_ms: int|null, downloadable: bool, deletable: bool}>,
     *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
     * }
     */
    private function manualRows(Project $project, ManualListQuery $listQuery, User $user): array
    {
        // latestSucceededRender も eager load する (行ごとの現行世代判定で N+1 を作らない)
        $baseQuery = $project->manuals()->with(['category', 'creator', 'latestSucceededRender']);

        // 並べ替え (allowlist enum 由来のカラム名のみ。既定は現行踏襲 created_at desc, id desc)
        $orderings = $listQuery->sort?->orderings() ?? ManualSortOption::defaultOrderings();
        foreach ($orderings as $ordering) {
            /** @var ManualOrdering $ordering */
            $baseQuery->orderBy($ordering['column'], $ordering['direction']);
        }

        if ($listQuery->mine) {
            // 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            $baseQuery->where('created_by', $user->id);
        }
        if ($listQuery->category === 'uncategorized') {
            $baseQuery->whereNull('category_id');
        } elseif ($listQuery->category !== null) {
            $baseQuery->where('category_id', (int) $listQuery->category);
        }
        if ($listQuery->status !== null) {
            $baseQuery->where('status', $listQuery->status);
        }
        if ($listQuery->keyword !== null) {
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
        }

        $paginated = (clone $baseQuery)
            ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
            ->withQueryString();

        // 範囲外ページ (行内削除で件数が減った / 古いブックマーク) は最終ページへ丸める。
        // 「空の一覧」に着地させない (行き先のない詰みを作らない)。
        // **0 件のときも丸める**: 一覧が空でも lastPage() は 1 なので、丸めないと
        // current_page=99 / last_page=1 という食い違った meta を渡すことになる。
        // URL の ?page=99 と meta.current_page は食い違うが、ページ送り UI は
        // meta.current_page を見る (**props が正本**であり redirect はしない)。
        if ($paginated->currentPage() > $paginated->lastPage()) {
            $paginated = (clone $baseQuery)
                ->paginate(perPage: ManualListQuery::PER_PAGE, page: $paginated->lastPage())
                ->withQueryString();
        }

        /** @var list<VideoManual> $manuals */
        $manuals = [];
        foreach ($paginated->items() as $manual) {
            Assert::isInstanceOf($manual, VideoManual::class);
            $manuals[] = $manual;
        }

        // ability はページで 1 回だけ評価する (理由は ManualRowAbilities の docblock)
        $abilities = ManualRowAbilities::forPage($user, $project, $manuals);

        return [
            'data' => array_map(
                fn (VideoManual $manual): array => ManualListItemData::fromManual($manual, $abilities)->toArray(),
                $manuals,
            ),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

```

### app/Http/Controllers/Capture/CaptureManualController.php (index 抜粋)
```php
    /** 撮影対象 (ready/published) の manual 一覧。category / q で絞り込み */
    public function index(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $project);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class); // view 認可済み = 認証済み。早期に int を確定
        $userId = $user->id;

        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
        $search = $request->filled('q') ? $request->string('q')->value() : null;
        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化

        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (PC 一覧 manualRows と統一)
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
            })
            // 自作フィルタ: 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
            ->with(['category', 'creator'])
            ->withCount([
                'cuts',
                // 採用済み cut 数 (relation 経由 = 'adopted_take_id' リテラルを撮影経路に増やさない)
                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual)->toArray())
            ->all();

        return Inertia::render('Capture/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manuals' => array_values($manuals),
            'categories' => $project->categories()
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine],
        ]);
    }

```

### app/Services/Manual/ManualRowAbilities.php (docblock 抜粋)
```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/**
 * 一覧の行に出す操作 (完成動画のダウンロード / 削除) の可否。
 *
 * **前提 (名前が示す約束)**: download / delete の可否は「その manual が属する project」で決まり、
 * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
 * VideoManualPolicy::download / ::delete が対象から `project` しか読まず
 * ProjectPolicy::update へ委譲しているためである。よって**ページで 1 回だけ**評価して全行へ配る。
 *
 * **なぜ畳むか**: ProjectPolicy は毎回 DB を見る (Project::memberRole() は memo 無しのクエリ、
 * Laratrust のキャッシュは config/laratrust.php の既定で production 以外は無効)。
 * 行ごとに can() を呼ぶと権限解決クエリが行数に比例する (per_page=10 × 2 ability)。
 *
 * **なぜ ProjectPolicy::update を直接問わないか**: それは委譲関係を呼び出し側へ
 * ハードコードすることであり、policy が分岐した日に**赤くならずに間違う**。
 * 問う ability 名は download / delete のまま保ち、評価の**回数**だけを畳む。
 *
 * 前提は ManualRowAbilityPremiseTest が固定し (manual 依存になったら赤くなる)、
 * 行数に比例しないことは ManualListQueryCountTest が固定する。読み取り専用。
 *
 * **前提が崩れたときの手順**: ManualRowAbilityPremiseTest が赤くなったら、
 * 評価を行ループへ移す (そのとき N+1 の解消も同時に設計し直す)。
 */
final readonly class ManualRowAbilities
{
    private function __construct(
        public bool $canDownload,
        public bool $canDelete,
    ) {}

    /**

```

### tests/Feature/Projects/ManualRowAbilityPremiseTest.php (抜粋)
```php
<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\ManualRowAbilities;

/*
 * T182: ManualRowAbilities の**前提**を固定する。
 *
 * 前提: download / delete の可否は「その manual が属する project」で決まり、
 * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
 * よってページで 1 回だけ評価して全行へ配ってよい。
 *
 * **この前提が崩れる policy 変更をしたらこのテストが赤くなる**。そのときは
 * 可否の評価を行ループへ移し (同時に N+1 の解消も設計し直す)、
 * ManualRowAbilities の docblock と本テストを書き換えること。
 */

/**
 * 同一 project 配下に属性の異なる 3 行を作る (status / 作成者 / カテゴリが全部違う)。
 *
 * @return list<VideoManual>
 */
function manualRowsWithDifferingAttributes(Project $project, User $creator): array
{
    $category = Category::factory()->forProject($project)->create();
    $other = User::factory()->create();

    return [
        VideoManual::factory()->forProject($project)->createdBy($creator)->published(60_000)
            ->forCategory($category)->create(),
        VideoManual::factory()->forProject($project)->createdBy($other)->create([
            'status' => VideoManualStatus::Draft->value,
        ]),
        VideoManual::factory()->forProject($project)->createdBy($creator)->create([
            'status' => VideoManualStatus::Ready->value,
        ]),
    ];
}


```

### database/migrations/2026_07_10_000100_create_video_manuals_table.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VideoManual: 動画マニュアル本体 (doc/10 §10.1 + §10.8)。Project 配下。
     *
     * - project_id / created_by は保護キー (payload から受けない)
     * - category_id は nullable + nullOnDelete (カテゴリ削除で未分類化 = §10.8-8)
     * - scenario_version はシナリオ一括保存の楽観ロック用 (§10.8-2。フェーズ1は default 0 固定)
     */
    public function up(): void
    {
        Schema::create('video_manuals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('status')->default('draft');
            $table->integer('scenario_version')->default(0);
            $table->integer('total_length_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_manuals');
    }
};

```
