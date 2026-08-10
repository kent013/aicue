【アプリの使命 (North Star) — AGENTS.md より転記】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則 — AGENTS.md より転記】

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【禁止事項 — AGENTS.md より転記】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、`->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【この設計の背景 (実ブラウザで再現済みの finding)】
bug-hunt run で **実際に再現された** 問題への対応である (推測ではない)。
67 カットのうち 1 カットだけテイクを採用した状態で「プレビュー生成」を押すと、約 201 秒の
全編黒画面の動画が警告なしで生成完了する。姉妹機能の完成動画生成 (render) は同じ状態を
422 で明示ブロックし未採用カットを列挙する。証跡はサーバ生成 mp4 の ffprobe + フレーム抽出。

【現行コードの要点 (レビュー時に前提としてよい事実)】
- `RenderJobService::trigger()` (render) は `assertAllCutsHaveAdoptedReadyTakes()` で
  `adoptedTake === null || status !== Ready` のカットを集め、あれば
  `ValidationException` (422, キー `takes`) を投げる。
- `RenderJobService::triggerPreview()` (preview) には同等の判定が**無い**。cuts が 1 件でもあれば 201。
  preview はチケット非消費・manual status を触らない (編集と並走する)。
- `RenderPipeline::clipSpecFor()` は採用テイク欠落時、render なら防御 LogicException、
  preview なら `RenderClipSource::Placeholder` に落とす。
- `FfmpegVideoComposer::planPlaceholder()` は `color=black` を
  `config('manual.preview_placeholder_seconds')` (=3 秒) 分生成し字幕を焼き込む
  (= 黒背景プレースホルダは意図的な仕様であり、消してはならない)。
- ロック順序の正本は `docs/architecture.md`:
  `render_jobs → video_manuals → ticket_reservations → organizations`。
  `RenderPipeline::buildManifest` は video_manuals を、`finalize` は render_jobs → video_manuals を取る。
- 進捗更新 (`updateProgress`) は `where status=running` の条件付き UPDATE
  (terminal 化後の書き戻し防止。AGENTS.md ドメイン規約 6)。
- `RenderJobData` (DTO) は render/preview の 201 応答・ポーリング応答・Inertia props で共用され、
  TS 側 `RenderJobProps` と対で保守されている。

---

## 概念設計

（以下、devnotes/20260811-0146-preview-render-parity/conceptual-design.md の全文）

# 概念設計: preview-render-parity (プレビューと完成生成の判断基準を揃える)

> 対応 finding: bug-hunt run `20260811-003230` の **F-1-01 (High)**。
> 一次入力: `devnotes/20260811-0146-preview-render-parity/recon-brief.md`
> 実査で確認済みの再現: 67 カット中 1 カットだけ採用 → プレビュー生成が
> **約 201 秒の全編黒画面 mp4 を無警告で完了**。完成動画生成 (`projects.manuals.render`) は
> 同じ状態を **422** で明示ブロックし未採用カットを列挙する。

## 背景・課題

### 事実 (コードを読んで確認したもの)

| 経路 | 未採用カットの扱い | 実装位置 |
|---|---|---|
| `render` トリガー | **422** (`採用テイクが未設定のカットがあります: {label 列挙}`) | `RenderJobService::assertAllCutsHaveAdoptedReadyTakes()` (L363-377) |
| `preview` トリガー | **判定なし** (cuts が 1 件でもあれば 201) | `RenderJobService::triggerPreview()` (L126-168) |
| 合成 (manifest) | render=防御例外 / preview=`RenderClipSource::Placeholder` | `RenderPipeline::clipSpecFor()` (L240-273) |
| 合成 (ffmpeg) | Placeholder = `color=black` × `manual.preview_placeholder_seconds` (=3秒) + 字幕焼き込み | `FfmpegVideoComposer::planPlaceholder()` (L148-163) |

66 カット × 3 秒 = 198 秒 + 採用済み 1 カット ≒ **実査で観測された 201 秒**と一致する。
つまり黒画面は故障ではなく**設計どおりの出力**であり、壊れているのは
「その出力が何であるかをユーザーに一言も伝えていない」点である。

判定 (「採用テイクが ready で揃っているか」) は現在 **3 箇所に散っている**:
`RenderJobService::assertAllCutsHaveAdoptedReadyTakes()` / `RenderPipeline::clipSpecFor()` /
(preview には存在しない)。**乖離したのは基準が 1 箇所に無いから**である。

### 仮説

**プレビューの不具合は「止めなかったこと」ではなく「未撮影が結果に何をもたらすかを、
事前にも事後にも言わなかったこと」である。**

- 検証したいこと: 判定を 1 箇所に集約し、preview は**止めずに知らせる**形にすれば、
  「途中経過を見る」というプレビュー本来の用途を壊さずに F-1-01 を閉じられるか。
- 成功の判断: 同一の前提状態 (未採用カットあり) に対し
  (a) render は今までどおり 422、(b) preview は 201 で生成されるが**押す前**に未撮影件数が
  画面に出ており、(c) 出来上がった動画の隣に**その動画が実際に含んだプレースホルダ件数**が
  出ている。かつ (a)(b) の件数が**同じ判定関数から出ている**ことが機械で固定されている。

## 改善アイデア

### 判断 1: プレビューはブロックしない (第三の道を採る)

recon-brief の設問 1 への結論。**preview を 422 にしない**。理由:

1. プレビューは「途中経過を見る」機能であり、**未撮影があること自体が正常な状態**である。
   422 にすると、撮影を 1 カットずつ進めながら仕上がりを確かめる中核ジョブが消える。
2. プレビューはチケット非消費 (無料) かつ manual status を触らない (編集と並走する) 設計で、
   render (課金・成果物確定・編集ロック) とは**リスクが違う**。同じ扱いにするのは
   「別物の概念を似ているから統合する」(思考原則 4) にあたる。
3. AGENTS.md 禁止事項 8 の思想 (必須条件未充足を理由に行き先を塞がない) と整合する。

**揃えるのは「判定」であって「制裁」ではない**。render は 422、preview は告知 — 入力が同じ
1 つの判定関数から出ていることを機械で固定する。

### 判断 2: 判定を 1 箇所に置く (recon-brief 設問 2)

`App\Services\Manual\AdoptedTakeCoverage` (新規・純関数) を**唯一の基準**とする。

- 入力: `VideoManual` (ロック済みでもそうでなくてもよい読み取り専用)
- 出力: `TakeCoverageData` DTO — `totalCuts` / `missingLabels` (表示順ラベル列) と
  そこから導く `missingCount`
- 基準: `cut->adoptedTake === null || adoptedTake->status !== TakeStatus::Ready`
  (= 現行 `assertAllCutsHaveAdoptedReadyTakes` と**同一**。基準そのものは変えない)
- 消費者は 3 つ: render トリガーの 422 / 詳細画面 props の事前告知 / manifest の Placeholder 分岐

### 判断 3: 黒背景プレースホルダは残す (recon-brief 設問 3)

意図的な仕様なので消さない。代わりに**その存在を 2 段で告知**する:

- **事前** (押す前): `Manuals/Show` の props に coverage を載せ、`RenderPanel` の
  プレビューボタン近傍に「未撮影が n 件あります。プレビューは該当区間が黒背景になります」を出す。
  押下時の確認ダイアログは**足さない** (無料・可逆・途中確認が正常用途 = 摩擦を足す理由がない)。
- **事後** (出来たものの説明): `render_jobs.placeholder_cut_count` を新設し、
  **finalize (terminal tx) でその動画が実際に含んだプレースホルダ数**を記録する。
  プレビュー `<video>` の直上にこの値で注記する。

事後を props から再計算しないのは、**再計算はその動画の説明として誤りうる**からである
(プレビュー生成後に撮影が進めば「未撮影 2 件」と書きながら 66 件黒い動画を指すことになり、
今回直しているのと同じ種類の嘘を新規に作る)。

### 判断 4: 再発防止 (recon-brief 設問 4)

- **Architecture gate (deny-by-default)**: 採用テイク充足判定の実体
  (`adoptedTake` プロパティ参照 / `whereDoesntHave('adoptedTake')` 等) を持てるファイルを
  目録に固定する。`ScenarioWritePathInventoryTest` の検出 4 (`adopted_take_id` の
  ファイル allowlist) と同じ流儀。新しいレンダ経路が独自判定を書いたら fail する。
- **behavioral な核**: 同一 fixture (n-1/n 未採用) に対し render 422 の列挙件数と
  preview 側 coverage の件数が**一致する**ことを Feature テストで固定する
  (「乖離しない」を型ではなく値で押さえる)。

## 期待効果

- **使命への貢献**: 「SOP → シナリオ → ナビ撮影 → 確認」の確認ステップが機能を取り戻す。
  黒画面を「アプリが壊れた」と誤解して離脱する経路を消す (中核体験の信頼)。
- プレビューを止めないため、**1 カットだけ撮って確かめる**という正常な使い方は温存される。
- 判定が 1 箇所になるので、今後 render / preview / 別経路が増えても基準が分岐しない。

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|---|---|
| 1 | 判定の単一化 | `AdoptedTakeCoverage` + `TakeCoverageData` 新設。`RenderJobService` / `RenderPipeline` を委譲に置換 |
| 2 | 事前告知 | `VideoManualController::show` の `render` props に coverage 追加 → `RenderPanel` に注記 |
| 3 | 事後説明 | `render_jobs.placeholder_cut_count` 追加 (migration + model + factory)、`RenderManifest` から finalize で記録、`RenderJobData` 経由で UI へ |
| 4 | 再発防止 | Architecture gate + Feature/Browser テスト |

## 制約・前提

- **ロック順序**を変えない (`render_jobs → video_manuals → ticket_reservations → organizations`)。
  施策 3 の書き込みは buildManifest (manual を先にロックする) ではなく **finalize** (job → manual の
  正順) で行う。
- 422 の**既存メッセージ本文と契約は変えない** (`RenderTriggerTest` 等の既存テストを壊さない)。
- preview 201 のレスポンス shape (`RenderJobResource`) は**壊さない**。追加は
  `placeholder_cut_count` の 1 フィールドのみ (nullable。TS `RenderJobProps` と対で更新)。
- フロントは DESIGN.md token 経由。注記は既存 `Alert` atom (`type="warning"`) と
  `text-caption text-text-secondary` を使い hex 直書きを増やさない。層は
  `features/manual/RenderPanel.svelte` のみ (atom/molecule の新設なし)。

## スコープ外 / 保証しないもの

- **黒背景プレースホルダの仕組みは消さない**。プレースホルダに「未撮影」テロップを
  焼き込む案 (bug-hunt の候補 b) も**採らない** — 字幕合成の仕様変更であり、
  finding を閉じるのに必要な最小形を超える (思考原則 2)。
- 生成された動画の品質評価は扱わない。
- ダッシュボード / 撮影ナビの「撮影待ち」カウント (`whereDoesntHave('adoptedTake')` =
  **ready 状態を見ない別基準**) は本設計では統合しない。gate の母集団に含めるが
  「別基準として登録済み」の扱いにし、統合の是非は別 TODO とする。
- 事前告知の coverage は**ページ描画時点のスナップショット**であり、別タブ・別ユーザーの
  撮影で古くなる。押下を止めないので詰みは作らないが、「常に最新」とは言わない。
- 自然言語メッセージの**文意**は機械照合しない (testId と件数の一致までを固定する)。

