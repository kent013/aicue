## 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

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

---
## 概念設計

# 概念設計: OCR 機能フラグ (`manual.ocr_analysis_enabled`) の完全撤去 (常時有効化)

## 背景・課題

`manual.ocr_analysis_enabled` (env: `MANUAL_OCR_ANALYSIS_ENABLED`, 既定 `false`) は、
画像・スキャン SOP の OCR 対応 (T234) をロールアウト用の機能フラグ付きで実装したときに
新設された。フラグの目的は「コードのデプロイ」と「法務確認・画像内 prompt injection の
手動評価という承認プロセスの完了」を切り離すことだった (承認プロセス完了前にコードだけ
デプロイしても、フラグが `false` の間は無害であることを保証する rollout gate)。

`docs/rollout-checklists.md` の記録によれば、この切り離しの役目は既に終えている:

- 項目 1 (法務確認): オーナー自身が「現行の利用規約・顧客契約と矛盾しない」ことを確認し、
  これをもって項目 1 を完了として扱うと決定した (2026-08-21)。
- 項目 2 (画像内 prompt injection の手動評価): 評価は実施していないが、オーナーが責任者
  権限で本項目を実施しないことを明示的に決定した (2026-08-21、例外決定であり合格ではない)。
- 項目 3 (再評価対象の棚卸し) は将来の義務として存続する。

この 2 つの決定を踏まえ、オーナーとの対話 (2026-08-21〜22) で「フラグを既定 `true` に変えて
緊急停止手段として残す」案 (B とは別の案) も提示されたが、オーナーは **B 案 (フラグの完全撤去
= 常時有効化)** を「いらないので。」という理由で選択した。

現状のフラグは以下の全箇所で読まれている (`grep ocr_analysis_enabled` による全数列挙):

| ファイル | 用途 |
|---|---|
| `config/manual.php:61` | フラグ定義本体 (`env('MANUAL_OCR_ANALYSIS_ENABLED', false)`) |
| `app/Support/Manual/AcceptedSourceDocumentTypes.php:57` | `imagesEnabled()` の判定源。受理形式の単一情報源 |
| `app/Services/Manual/AnalysisPipeline.php:213` | extract 段の route 決定 (`text`/`ocr`) の入力 |
| `app/Services/Manual/SopTextExtractor.php` (docblock のみ) | フラグの説明コメント (コード分岐なし) |
| `app/Rules/SourceDocumentSizeLimit.php` (docblock のみ) | 「容量分類はフラグに依存しない」という設計判断の説明コメント (コード分岐なし) |
| `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` | 両状態 (false/true) の分岐を固定するテスト |
| `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` | 両状態の分岐を固定する Feature テスト |
| `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` | 両状態の分岐を固定するパイプラインテスト |
| `docs/architecture.md` | rollout gate の設計記述 |
| `docs/rollout-checklists.md` | チェックリスト本体 (フラグの有効化条件) |

`AcceptedSourceDocumentTypes::imagesEnabled()` は Inertia props (`imageSourceDocumentsEnabled`)
経由でフロント (`SourceDocumentUpload.svelte` / `SourceDocumentUploadNotice.svelte` /
`Manuals/Create.svelte` / `Manuals/Show.svelte`) にも伝播しており、画像専用の外部送信注意文言の
出し分けに使われている。

## 改善アイデア

フラグを撤去し、OCR 機能 (画像受理 + PDF の OCR フォールバック) を無条件で有効にする。
思考原則 3 (後方互換の並走を残さない) に従い、フラグの痕跡そのもの
(config キー・env 変数・`config()->boolean()` 呼び出し・フラグ分岐を作るためだけに存在する
コード・両状態を検査するテスト分岐) を残さない。OCR 機能自体 (媒体検証・OCR プロンプト・
容量上限等) は撤去対象ではない — 撤去するのは「有効/無効を切り替える仕組み」だけである。

## 期待効果

- 使命への貢献: OCR 対応 (T234) は現場の SOP が画像・スキャン PDF で保管されている場合に
  対応するための機能であり、これが常時有効になることで「思考ゼロ・編集ゼロ」の対象範囲が
  フラグの状態に依存せず一貫する。
- コードの単純化: `AcceptedSourceDocumentTypes` / `AnalysisPipeline::resolveExtractInput()` の
  分岐が 1 本に減り、両状態を検査していたテストの半分 (無効状態側) が不要になる。
- 運用上の帰結の明示: 次のデプロイで OCR は無条件に有効になる。**これは意図された帰結であり、
  リスクではない** — 有効化の前提条件だったチェックリスト項目は既に (完了または例外決定として)
  クローズ済みである。

## 実装方針 (概要)

1. `config/manual.php` から `ocr_analysis_enabled` キーを削除する。
2. `AcceptedSourceDocumentTypes` の `imagesEnabled()` を撤去し、`extensions()` / `mimes()` /
   `formatsLabel()` を画像込みの固定値へ一本化する。
3. `AnalysisPipeline` の route 決定 (`resolveExtractInput()` / `runExtractStage()`) から
   `$ocrEnabled` 変数と分岐を除去し、「画像なら OCR」「PDF 品質ゲート失敗なら OCR
   フォールバック」を無条件の経路にする。
4. Inertia props `imageSourceDocumentsEnabled` を撤去する (常に `true` になる値をわざわざ
   props として配らない)。`SourceDocumentUploadNotice.svelte` の OCR 固有警告は常時表示に
   フォールディングする。
5. `SopTextExtractor` / `SourceDocumentSizeLimit` のフラグに触れる docblock を、フラグが
   存在しない前提の記述へ書き換える (コード分岐は元々無いため変更なし)。
6. 両状態 (false/true) を検査していたテストを畳み込む: 無効状態を固定するテスト
   (「フラグ false のとき画像を含まない」等) は削除し、有効状態の挙動を固定するテスト
   だけを残す (常時有効という前提に立ったテスト名・コメントへ書き換える)。
7. `docs/rollout-checklists.md` の OCR 節へ、オーナー決定によるフラグ撤去の記録を追記する
   (既存の承認記録・将来の再評価義務の記録は消さない)。運用手順節はフラグ撤去に伴い
   「フラグを立てる」という前提の記述が成立しなくなるため、事実に合わせて書き換える。
8. `docs/architecture.md` の rollout gate に関する記述をフラグ撤去後の実態に合わせて更新する。

## 制約・前提

- OCR 機能自体 (媒体検証・プロンプト・容量上限・観測ログ) は変更しない。変更対象は
  「有効/無効を切り替える仕組み」だけである。
- `AnalysisFailedException` の文言・`AnalysisFailureReason` の列挙値・
  `AnalysisAcceptanceGate` 等の OCR 経路そのものは変更しない。
- `docs/template-fingerprints.json` に該当ファイル (`config/manual.php` /
  `AcceptedSourceDocumentTypes.php` / `AnalysisPipeline.php` / フロント該当ファイル) が
  含まれていないことを確認済み (テンプレート共有ファイルではない。乖離台帳の登録は不要)。
- 本設計は「設計」のみであり、実装・TODO 登録は行わない (別スキルの責務)。

## スコープ外

- OCR 機能自体の仕様変更 (対応形式の追加、容量上限の変更等)。
- `docs/rollout-checklists.md` の項目 3 (再評価義務) の運用自体の変更 — 記録として残す。
- 他機能のフラグ・rollout gate パターンへの一般化。
