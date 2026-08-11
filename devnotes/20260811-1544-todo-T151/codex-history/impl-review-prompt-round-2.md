# 使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

## あなたの役割

Laravel 12 + Svelte 5 + Inertia + PHP 8.4 のアプリ **AI-CUE** のコードレビュアーとして、
以下の実装差分をレビューする。

## レビュー観点

1. **設計との一致性**: 添付の詳細設計書 (Codex 合議済み) の施策 1〜5 のとおりに実装されているか。
   意図的な逸脱があれば、その妥当性
2. **正確性**: `create()` の初期状態明示代入が正しいか。`duplicate()` の既存の振る舞いを
   変えていないか。migration の default を消していないか。DB に入る値が変わっていないか
3. **PHPStan level 10 適合性** (`@phpstan-ignore` / baseline / 型 widen は禁止)
4. **テスト網羅性**: 再現テストが真に fail-first か。属性ごとの分割が mutation の非対称を
   観測できる形になっているか。既存テストを削除・弱体化していないか
5. **セキュリティ**: 保護キー (created_by / project_id / category_id) の扱い、
   forceFill の使用が規約 (サーバ導出値のみ) に収まっているか
6. **正本ドキュメントの整合**: `AGENTS.md` ドメイン規約 1 の 2 分類化が
   **既存の (i) 更新経路への要求を 1 ミリも緩めていないか**。特に
   「生成経路だから Project 行ロックだけでよい」と読めて `duplicate()` の cuts materialize の
   要求 (新 manual を lockForUpdate で再取得してから copyCuts) が弱まっていないか。
   `docs/architecture.md` / inventory テスト docblock と**語彙が一致**しているか
7. **保証範囲を誇張していないか**: 「allowlist はファイル粒度でメソッド単位の fail-first を
   担えない」という限界が、テスト名・コメント・ドキュメントで正直に書かれているか

※ 本差分に frontend (resources/js, resources/css) の変更は**含まれない**ため、
   DESIGN.md / Atomic Design 観点は対象外。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する


---

## Round 2: 前回指摘への対応確認

あなたは Round 1 で以下のレビューを返した (one-shot のため文脈は保持されていない。全文を再掲する)。

```
提供 diff のみでレビューしました。指示どおりコマンド実行・ファイル書き込みはしていません。

**Critical**
なし。

**AGENTS.md**
[Warning] `更新経路` の準拠実装リストから `RenderJobService::trigger()` / `failJob()` / `completeRenderIntoLockedManual()` が抜けています。直後に「後続の RenderJob 状態遷移も同規約に従う」とはありますが、詳細設計の施策 5 は準拠実装リスト内に含める前提です。`docs/architecture.md` の経路表とも粒度がずれるため、正本としては追記した方がよいです。

判定としては、生成経路の免除範囲は十分に狭く書けています。特に `duplicate()` の cuts materialize が「新 manual を lockForUpdate で再取得してから」と読めるので、観点 6 の重大な緩和は見当たりません。

**app/Services/Manual/VideoManualService.php**
問題なし。`create()` の `forceFill` は `created_by` がサーバ導出、`status` / `scenario_version` が生成経路の初期状態明示代入で、規約内に収まっています。DB に入る値も既存 default と同じ `draft` / `0` です。

**docs/architecture.md**
[Warning] 「準拠実装 (メソッド粒度の経路 inventory。`ScenarioWritePathInventoryTest` が deny-by-default の token 走査で機械検証する...)」という文が、後段の「allowlist はファイル粒度」とやや矛盾します。観点 7 的には保証範囲の誇張に見えます。

修正案は、例えば「経路表はメソッド粒度で記録するが、`ScenarioWritePathInventoryTest` の機械検証はファイル粒度の token gate に留まる」のように分けることです。

**tests/Architecture/ScenarioWritePathInventoryTest.php**
[Suggestion] docblock の「経路 (メソッド粒度...)」自体は表の説明として読めますが、architecture 側と同じく「機械検証はファイル粒度」と明示するとより安全です。T066 のテスト名・コメント是正は妥当で、メソッド単位 fail-first を担えない限界も正直に書けています。

**tests/Feature/Projects/ManualServiceBoundaryTest.php**
問題なし。status / scenario_version を分割したテストは mutation の非対称を観測できる形で、fail-first の説明とも整合しています。category + SOP ありの経路で 2 回目 save 後も戻り値属性が残ることを固定している点も妥当です。

**検証結果**
[Warning] 詳細設計では `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` も「省略しない」と明記されていますが、実測では未実行です。frontend 差分ゼロでも、このタスクの完了条件としては未充足です。

**全体判定: CHANGES_REQUESTED**

理由は Critical ではなく、正本ドキュメントの語彙ずれと必須検証未実行です。実装コードと fail-first テストの方向性は妥当です。
```

## Claude 側の対応マトリクス

# 実装レビュー Round 1 対応マトリクス (T151)

Codex 判定: **CHANGES_REQUESTED** ([Critical] 0 件 / [Warning] 3 件 / [Suggestion] 1 件)

| # | 分類 | 指摘 | 判断 | 根拠・対応内容 |
|---|------|------|------|----------------|
| 1 | [Warning] | `AGENTS.md` (i) 更新経路の準拠実装リストから `RenderJobService::trigger()` / `failJob()` / `completeRenderIntoLockedManual()` が抜けている | **対応する** | 詳細設計 施策 5 の骨子は (i) にこの 3 本を含めていた。実装時の写し漏れ。3 本を追記し、代替として置かれていた「後続の RenderJob 状態遷移も同規約に従う」の 1 文は準拠実装リストに吸収されたので削除した (二重管理を残さない)。`docs/architecture.md` の経路表とも粒度が揃った |
| 2 | [Warning] | `docs/architecture.md` の「準拠実装 (メソッド粒度の経路 inventory。ScenarioWritePathInventoryTest が deny-by-default の token 走査で機械検証する)」が、後段の「allowlist はファイル粒度」と矛盾し保証範囲の誇張に見える | **対応する** | 観点 7 (保証範囲を誇張しない) に直結する。「下表は**メソッド粒度で記録する**経路 inventory。ただし機械検証は deny-by-default の token 走査 = **ファイル粒度**に留まり、表の粒度と一致しない (同一ファイル内のメソッド追加は検出しない)」へ書き分けた。**この一文は本 TODO の主題そのもの** (メソッド単位の fail-first を gate が担えないこと) なので、指摘どおり分離するのが正しい |
| 3 | [Suggestion] | `ScenarioWritePathInventoryTest` の docblock にも「機械検証はファイル粒度」を明示するとより安全 | **対応する** | #2 と同じ理由。3 箇所 (AGENTS.md / architecture.md / inventory docblock) の語彙を揃えることが施策 4・5 の要件でもある。docblock 冒頭を「下表はメソッド粒度で記録する経路 inventory。ただし本テストの機械検証は allowlist によるファイル粒度」に書き換えた |
| 4 | [Warning] | `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が未実行 | **対応する (1 本を除き全 green。1 本は環境要因で実行不能)** | 実行結果は下記。`pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` は **green**。`pnpm test` は **1 ファイル 6 件が失敗**したが、失敗理由は全件同一の環境要因であり本差分とは無関係 (下記) |

## `pnpm test` の失敗 6 件について (誇張せず事実のみ)

```
FAIL scripts/run-browser-test.contract.test.ts (6 tests)
Error: bug-hunt 環境が 127.0.0.1:8010 で listen 中のため、run-browser-test.sh の
       pre-flight guard が発火して契約テストを実行できません
       (scripts/bug-hunt-shard.sh teardown で停止してから再実行してください)
Test Files  1 failed | 129 passed (130)
     Tests  6 failed | 1310 passed (1316)
```

- 失敗は **1 ファイル `scripts/run-browser-test.contract.test.ts` に閉じており、6 件すべて同一の
  pre-flight guard メッセージ**である。テスト本体の assertion 失敗ではなく、
  **契約テストを実行する前段で環境を検出して止まっている**
- 原因は別 worktree (`.claude/worktrees/tasks/smoke-20260811`) に **pipeline-smoke の検証環境が
  provision 済みで `127.0.0.1:8010` が listen 中**であること。解消手段は
  `scripts/bug-hunt-shard.sh teardown` だが、**本タスクは当該 worktree に触ることを明示的に
  禁止されている**ため実行していない
- 本差分は **`resources/js` / `resources/css` / `scripts/` / `packages/` を 1 バイトも変更していない**
  (変更は `app/` 1 ファイル・`tests/` 2 ファイル・`docs/architecture.md`・`AGENTS.md`・`devnotes/` のみ)。
  したがってこの失敗が本差分に起因しないことは差分の範囲から言える
- **ただし「pnpm test が green である」とは書かない**。実測は上記のとおりであり、
  この 1 ファイルは**未検証のまま残っている**

## 対応後の検証 (再実測)

| コマンド | 結果 |
|---|---|
| `composer phpstan` | **OK** (No errors / 891 files / level 10) |
| `composer test` | **passed** tests=4455 passed=4453 skipped=2 assertions=19177 |
| `composer fix` → `vendor/bin/pint --test` | **passed** |
| `pnpm lint` | **passed** |
| `pnpm typecheck` | **passed** |
| `pnpm test` | **1 failed / 129 passed** (上記の環境要因 1 ファイル 6 件のみ) |
| `pnpm build` | **passed** (built in 4.01s) |
| `pnpm typecheck:packages` | **passed** |
| `pnpm build:packages` | **passed** |
| `pnpm test:packages` | **passed** (10 files / 106 tests) |


## 対応後の差分 (AGENTS.md / docs/architecture.md / inventory テスト docblock の最新状態。HEAD からの累積 diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 2c5ffda..7b1b77c 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -315,15 +315,31 @@ ## ドメイン固有規約
      テンプレート更新の取り込みを容易にするため、できるだけ書き換えない。 -->
 
 1. **シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` /
-   `video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で
-   取得した同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
-   `Manual/ScenarioService::materializeIntoLockedManual()` / `Manual/AnalysisJobService::trigger()` /
-   `Manual/AnalysisJobService::failJob()` / `Capture/CaptureTakeService::adopt()`・`delete()`
-   (cuts.adopted_take_id)。経路 inventory は **`ScenarioWritePathInventoryTest`
-   (Architecture テスト) へ昇格済み** = 新しい書き込み経路は inventory 登録が必須。
+   `video_manuals.status` を書き込む経路は、次の 2 分類のいずれかに属する。
+   - **(i) 更新経路** (既存行の書き換え): 対象 VideoManual 行を `lockForUpdate()` で取得した
+     同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
+     `Manual/ScenarioService::materializeIntoLockedManual()` /
+     `Manual/AnalysisJobService::trigger()` / `Manual/AnalysisJobService::failJob()` /
+     `Manual/RenderJobService::trigger()` / `Manual/RenderJobService::failJob()` /
+     `Manual/RenderJobService::completeRenderIntoLockedManual()` /
+     `Capture/CaptureTakeService::adopt()`・`delete()` (cuts.adopted_take_id))
+   - **(ii) 生成経路** (新規 INSERT): 対象行は未存在のため、**所有元 Project 行を
+     `lockForUpdate()` した同一トランザクション内で INSERT** し、初期状態
+     (`status` / `scenario_version`) を **INSERT 時に明示代入する**
+     (DB カラム default に依存しない = migration default 変更による silent break と、
+     戻り値インスタンスの属性欠落の両方を防ぐ)。
+     準拠実装: `Manual/VideoManualService::create()` / `::duplicate()`
+     - **免除の範囲を広げない**: (ii) が `lockForUpdate()` を免除されるのは
+       **その tx が生成した新規行の初期値 (`status` / `scenario_version`) の INSERT のみ**である。
+       **生成後の行に対する後続の書き込み (`cuts` 等) は (i) 更新経路として扱い**、
+       保存済みの新 manual を `lockForUpdate()` で**再取得した**同一 tx 内で行う
+       (準拠実装: `duplicate()` は新 manual を save 後に `lockForUpdate()` で再取得してから
+       `copyCuts()` を呼ぶ)
+   経路 inventory は **`ScenarioWritePathInventoryTest` (Architecture テスト) へ昇格済み** =
+   新しい書き込み経路は inventory 登録が必須。**ただし allowlist はファイル粒度**であり、
+   同一ファイル内のメソッド追加は検出しない (メソッド単位の fail-first は behavioral テストが担う)。
    テイク採用 API は検出 4 (`adopted_take_id` の deny-by-default 走査) で inventory 準拠済み。
-   後続の RenderJob 状態遷移も同規約に従う。
-   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件)
+   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件
 2. **容量 Quota (max_storage_bytes) の予約規約**: presigned アップロードの容量判定は
    `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
    (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
diff --git a/docs/architecture.md b/docs/architecture.md
index 401851e..6bb1d22 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -215,14 +215,24 @@ ## 主要 Service (テンプレート同梱)
 ## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
 
 > **cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
-> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。**
+> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する
+> (= 更新経路)。対象行がまだ存在しない生成経路 (新規 INSERT) は、所有元 Project 行を
+> `lockForUpdate()` した同一トランザクション内で INSERT し、初期状態
+> (`status` / `scenario_version`) を明示代入する (DB カラム default に依存しない)。**
 
-- 直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との整合は
-  シナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
+- **更新経路**の直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との
+  整合はシナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
   親 relation 経由の再解決 (`$project->manuals()->whereKey(...)->lockForUpdate()`) で
   「子は親に属する」も同時に担保する
-- 準拠実装 (メソッド粒度の経路 inventory。`ScenarioWritePathInventoryTest` が
-  deny-by-default の token 走査で機械検証する = **Architecture テストへ昇格済み**):
+- **生成経路**は対象 VideoManual 行が未存在のため、所有元 Project 行を `lockForUpdate()` した
+  同一 tx 内で INSERT する。**免除されるのはその tx が生成した新規行の初期値
+  (`status` / `scenario_version`) の INSERT のみ**であり、生成後の行に対する後続の書き込みは
+  更新経路として扱う — `duplicate()` の cuts materialize は、保存した新 manual を
+  `lockForUpdate()` で**再取得してから**行う (`copyCuts` の呼び出し前提)
+- 準拠実装 (下表は**メソッド粒度で記録する経路 inventory**。ただし
+  `ScenarioWritePathInventoryTest` (Architecture テストへ昇格済み) の**機械検証は
+  deny-by-default の token 走査 = ファイル粒度**に留まり、表の粒度と一致しない。
+  同一ファイル内のメソッド追加は検出しない):
 
   | 経路 | 書いてよいもの |
   |---|---|
@@ -234,7 +244,14 @@ ## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
   | `RenderJobService::trigger()` | status (ready→rendering のみ。scenario_version はスナップショット読み) |
   | `RenderJobService::failJob()` | status (rendering→ready のみ。kind=render に限る。preview は触らない) |
   | `RenderJobService::completeRenderIntoLockedManual()` | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ。呼び出しは RenderPipeline::finalize の terminal tx に限定 = 検出 5) |
-  | `VideoManualService::duplicate()` | cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成)。scenario_version/status/adopted_take_id のリテラル書き込みはしない (新規行は DB default 依存) ため検出 1/2/4 は非対象 |
+  | `VideoManualService::create()` | **生成経路**。status=Draft / scenario_version=0 を新規 manual の INSERT 時に明示代入 (DB default 非依存 = 戻り値インスタンスが hydrate 済みになる)。Project 行 lockForUpdate 済み tx 内の新規 INSERT で、既存行への並行書き込みではない |
+  | `VideoManualService::duplicate()` | **生成経路**。cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成) + status=Draft / scenario_version=0 の明示代入。adopted_take_id は複製しない (検出 4 は非対象) |
+
+  生成経路 (`create()` / `duplicate()`) の allowlist は**ファイル粒度**であり、
+  `ScenarioWritePathInventoryTest` は「VideoManualService.php が status/scenario_version を書く」
+  ことまでしか固定しない。**個々のメソッドが初期状態を明示代入していることの fail-first は
+  `tests/Feature/Projects/ManualServiceBoundaryTest.php` /
+  `tests/Feature/Projects/ManualDuplicateTest.php` の behavioral テストが担う。**
 
   テイク採用 API は inventory 準拠へ昇格済み (検出 4 = `adopted_take_id` の token 走査 +
   書き込み形検出)。RenderJob の状態遷移も inventory 準拠済み (検出 5 =
diff --git a/tests/Architecture/ScenarioWritePathInventoryTest.php b/tests/Architecture/ScenarioWritePathInventoryTest.php
index 9d82392..009f32a 100644
--- a/tests/Architecture/ScenarioWritePathInventoryTest.php
+++ b/tests/Architecture/ScenarioWritePathInventoryTest.php
@@ -5,10 +5,14 @@
 /*
  * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン固有規約 1) の書き込み経路 inventory。
  *
- * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
- *   対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
+ * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む経路は次の 2 分類:
+ *   (i) 更新経路 = 対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する。
+ *   (ii) 生成経路 = 対象行が未存在のため所有元 Project 行を lockForUpdate() した同一 tx 内で INSERT し、
+ *        初期状態 (status / scenario_version) を INSERT 時に明示代入する (DB default に依存しない)」
  *
- * 経路 (メソッド粒度。docs/architecture.md と対):
+ * 下表は**メソッド粒度で記録する**経路 inventory (docs/architecture.md と対)。ただし
+ * **本テストの機械検証は下記 allowlist によるファイル粒度**であり表の粒度とは一致しない
+ * (同一ファイル内のメソッド追加は検出しない。メソッド単位の fail-first は behavioral テストが担う):
  * | 経路 | 書いてよいもの |
  * |---|---|
  * | ScenarioService::save() | cuts / scenario_version / status (rendering·analyzing guard 付き) |
@@ -16,7 +20,13 @@
  * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
  * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
  * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
- * | VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
+ * | VideoManualService::create() | status / scenario_version (**(ii) 生成経路**。新規 manual の INSERT 時に
+ *   status=Draft / scenario_version=0 を明示代入する。対象 VideoManual 行は未存在のため所有元 Project 行を
+ *   lockForUpdate した同一 tx 内で INSERT = 既存行への並行書き込みではない。検出 1 は
+ *   SCENARIO_VERSION_ALLOWED、検出 2 は STATUS_WRITE_ALLOWED に登録済み (duplicate() と同一ファイル)。
+ *   **allowlist はファイル粒度のため create() 単体の検出保証はなく、fail-first を担うのは
+ *   tests/Feature/Projects/ManualServiceBoundaryTest.php の behavioral 契約テストである** (T151) |
+ * | VideoManualService::duplicate() | **(ii) 生成経路**。cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
  *   lockForUpdate して一貫読み取り。複製 manual の INSERT 時に status=Draft / scenario_version=0 を
  *   明示代入する (新規行生成 = lockForUpdate 前だが、その tx が生成した排他的新規行・同一 tx 内反映で
  *   既存行への並行書き込みではない)。検出 1 (scenario_version) は SCENARIO_VERSION_ALLOWED、
@@ -61,9 +71,11 @@ final class ScenarioWritePathScanner
         // T032: failJob が失敗確定時の scenario_version を job にスナップショット読みする
         // (書き込むのは scenario_version_at_terminal であり scenario_version ではない)
         'Services/Manual/AnalysisJobService.php',
-        // VideoManualService は 2 理由で許可: (1) T032 stale alert 判定 (displayXxxJob) が
+        // VideoManualService は 3 理由で許可: (1) T032 stale alert 判定 (displayXxxJob) が
         // manual.scenario_version を read (read-only)。(2) T066 duplicate() が複製 manual の
-        // INSERT 時に scenario_version=0 を明示 write (新規行生成 + 同一 tx。既存行への並行 write ではない)
+        // INSERT 時に scenario_version=0 を明示 write。(3) T151 create() が新規 manual の
+        // INSERT 時に scenario_version=0 を明示 write
+        // ((2)(3) はいずれも生成経路 = 新規行生成 + 同一 tx。既存行への並行 write ではない)
         'Services/Manual/VideoManualService.php',
     ];
 
@@ -74,8 +86,10 @@ final class ScenarioWritePathScanner
         // trigger: ready→rendering / failJob: rendering→ready / complete...: rendering→published。
         // RenderPipeline は VideoManualStatus を直接書かない (全て Service メソッド経由)
         'Services/Manual/RenderJobService.php',
-        // T066: duplicate() が複製 manual の INSERT 時に status=Draft を明示代入
-        // (新規行生成 + 同一 tx。既存行への並行書き込みではないためロック規約の趣旨に整合)
+        // T066: duplicate() が複製 manual の INSERT 時に status=Draft を明示代入。
+        // T151: create() も新規 manual の INSERT 時に status=Draft を明示代入
+        // (どちらも生成経路 = 新規行生成 + 同一 tx。既存行への並行書き込みではないため
+        //  ロック規約の趣旨に整合)
         'Services/Manual/VideoManualService.php',
     ];
 
@@ -676,11 +690,14 @@ class N {}
     expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($captureTakeService))->toBeTrue();
 });
 
-test('T066: VideoManualService に status/scenario_version の明示 write が実在する (allowlist の degenerate PASS 防止 + 明示代入の fail-first 契約)', function (): void {
-    // duplicate() は複製 manual の初期状態を DB default に委ねず status=Draft / scenario_version=0 を
-    // 明示 write する。その **write 形** が VideoManualService 内に実在することを token ベースで担保する
-    // (明示代入を消すと write が消え、この契約テストが fail = fail-first。STATUS_WRITE_ALLOWED /
-    //  SCENARIO_VERSION_ALLOWED の degenerate = 未使用 allowlist 化も防ぐ)。
+test('T066: VideoManualService ファイルに status/scenario_version の明示 write が少なくとも 1 つ存在する (allowlist の degenerate PASS 防止。ファイル粒度でありメソッド単位の fail-first ではない)', function (): void {
+    // create() / duplicate() は新規 manual の初期状態を DB default に委ねず status=Draft /
+    // scenario_version=0 を明示 write する。その **write 形** が VideoManualService 内に実在することを
+    // token ベースで担保する (STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED の
+    // degenerate = 未使用 allowlist 化を防ぐ)。
+    // **メソッド単位の fail-first は本テストでは担えない** (create() の明示代入を消しても
+    // duplicate() が残れば通る)。create()/duplicate() それぞれの初期状態の保証は
+    // ManualServiceBoundaryTest / ManualDuplicateTest の behavioral テストが担う (T151)。
     // scenario_version は displayXxxJob の read があるため token 出現では区別できず、write 形で判定する。
     $appDir = ScenarioWritePathScanner::appDir();
     $videoManualService = (string) file_get_contents($appDir.'/Services/Manual/VideoManualService.php');

```

## 質問

1. Round 1 の [Warning] 3 件 / [Suggestion] 1 件それぞれについて、対応が十分か判定してほしい
2. とくに観点 6 (AGENTS.md の (i) 更新経路への要求が 1 ミリも緩んでいないか) と
   観点 7 (保証範囲の誇張がないか) を再確認してほしい
3. `pnpm test` の 1 ファイル 6 件が環境要因で未検証のまま残っていることの扱いについて、
   「green と書かず未検証と明記する」という現在の扱いで妥当か
4. 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記してほしい
