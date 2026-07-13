# アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ (Laravel/Svelte の公式作法)。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

## 概念設計

（以下は devnotes/20260713-1646-manuals-stale-alert/conceptual-design.md の内容）

（レビュアーへ: リポジトリ内の以下ファイルを読んで文脈確認して構いません。
- resources/js/components/features/manual/AnalysisPanel.svelte
- resources/js/components/features/manual/SourceDocumentUpload.svelte
- resources/js/pages/Manuals/Show.svelte
- app/Http/Controllers/Projects/SourceDocumentController.php ）

---

# 概念設計: manuals-stale-alert

## 背景・課題

bug-hunt finding F-H2 (High, H10)。

manuals show 画面で、直前の AI 解析起動失敗に由来する赤字 alert「手順書をアップロードしてください。」が、その後に SOP アップロード成功 / シナリオ保存成功 / 解析成功 といった別操作を行っても消えずに残留する。手動リロードで消えるため、クライアント側の stale local state が原因。

### 根本原因 (コードレベル)

エラー文言の実体は Show ページ内の AnalysisPanel.svelte が持つローカル $state:
- errorMessage … 解析起動 (POST .../analyze) の 402/409/422 応答メッセージを表示。「手順書をアップロードしてください。」は 422 (手順書なし) の応答文言。
- 付随する一過性 state: showPurchaseLink (402 併記の購入導線) / sessionExpiredMessage (401/419)。
- currentJob / status … job / manualStatus props から一度だけ seed し (// svelte-ignore state_referenced_locally)、以後は XHR 応答でのみ更新する設計。

SOP アップロードは兄弟コンポーネント SourceDocumentUpload.svelte が Inertia の form.post(...) で送信し、サーバは back()->with('success', ...) を返す。これにより Show ページは同一ページコンポーネントのまま新しい props で再描画される (analysis.hasDocument が false → true)。しかし Inertia は同一ページコンポーネントを再マウントしないため、AnalysisPanel のローカル errorMessage は初期 seed のまま残り、startAnalyze() を再度呼ぶまでクリアされない。

補足: 「シナリオ保存成功」は別ページ (Manuals/Edit.svelte) の操作であり、Show への遷移で AnalysisPanel は再マウントされるため元来 stale は起きない。本 finding の残留は Show ページ内の兄弟操作 (SOP アップロード) 後に props だけ更新され、ローカルエラーが再同期されないケースが本質。

## 改善アイデア

AnalysisPanel に「サーバ props が更新されたら = Inertia の新しいスナップショットが届いたら、一過性のクライアントエラー overlay を破棄し、job/status をサーバ値へ再同期する」責務を追加する。
- ポーリングは props を変更しない (XHR でローカル state のみ更新) ため、props の変化は「Inertia の再訪/reload = 権威あるサーバ真実」とみなせる。よって props 変化を契機に再同期しても、進行中ポーリングの間隔・状態を壊さない。
- 再同期対象: errorMessage / showPurchaseLink / sessionExpiredMessage を null/false に、currentJob を最新の job prop に、status を最新の manualStatus prop に揃える。

実装は $effect による reconciliation とし、job.id / hasDocument / manualStatus の prop 変化を検知したときだけ (前回値と比較して no-op を避ける) 上記クリア/再同期を行う。

## 期待効果
- 使命への貢献: SOP → シナリオ → 撮影の中核導線で、成功操作後も古いエラーが残ると誤認と操作の詰まりを生む。残留エラー解消で信頼性を回復。
- SOP アップロード成功後、手順書なしエラーが即座に消え次アクション (AI 解析) へスムーズに進める。手動リロード不要。
- サーバ props を単一の権威とする再同期により、他の Inertia reload 起因の stale も一括で解消。

## 実装方針（概要）
- 変更対象は原則 AnalysisPanel.svelte の 1 ファイル。reconciliation $effect を追加し、変化時のみ一過性エラーをクリアし currentJob/status を props に再同期。前回 prop 値を非リアクティブなローカル変数で保持し変化なしは早期 return。
- SourceDocumentUpload.svelte は既に Inertia back() で props を更新させているため変更不要。
- サーバは既に正しい fresh props を返すため変更不要 (backend 変更なし)。

## 制約・前提
- frontend のみの修正。PHP/DTO/JsonResource の変更なし。
- 既存の意図的設計 (state_referenced_locally seed-once + XHR 駆動ポーリング) を壊さない。ポーリングは props を変えないので干渉しない。
- failedJob alert は currentJob = job prop 由来のサーバ真実であり stale client state ではない。本 finding 対象外。
- DESIGN.md / Atomic Design: 既存 atom (Alert) の利用のみ。新規追加なし。

## スコープ外
- failedJob alert 表示ロジック自体の変更。
- Edit ページ (シナリオ保存) 側の挙動。
- flash/toast の挙動変更。
- backend の変更。
