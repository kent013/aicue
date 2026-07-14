## アプリの使命（North Star / AGENTS.md 正本）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

## セキュリティ不変条件（AGENTS.md 正本・抜粋）

- tenant/ownership/actor キーを payload から受け取らない
- 子は親に属する（nested route の不整合は認可より前に 404）
- cross-org read/write 不可
- 権限判定は `laratrust_team_id` 明示

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。
データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10 を通せるか

【本設計の特有論点（重点的に評価してほしい）】
- 「自動ダウンロード」の技術解釈: 現行 v1 に端末永続化機構が無い中で、`fetch(playback_url)` で実バイトを取得して即破棄し ACK する方針は妥当か。それとも (a) fetch を省いて ACK のみにすべき（バイト取得は無駄な帯域）か、(b) 端末永続キャッシュ（IDB/Cache Storage）まで v1 でやるべきか。二重登録が既に冪等キーで防止済みである点を踏まえて評価してほしい。
- 自動 DL のトリガを onMount（+online 復帰）に限定し、セッション中 adopt 直後を対象外にしたスコープ判断は適切か。
- 手動 `downloadAndAck`（window.open 方式）と自動 DL（fetch 方式）で DL の実体が異なる点の整合性。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260715-0021-take-auto-download-on-entry/conceptual-design.md の全文）

# 概念設計: take-auto-download-on-entry（撮影詳細入室時の採用済みテイク自動ダウンロード）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #6（Medium）。

doc/05 §5.3・doc/02 §2.3 は撮影 PWA の核となる同期ルールとして次を求める:
「シナリオ詳細を開いたとき、既にサーバーで採用済みのテイクがあれば端末へ自動ダウンロードされる（DL 済み枠は色/枠線で区別）。→ PC ↔ アプリ間でテイクが二重登録されない設計。」

現状 resources/js/pages/Capture/Show.svelte は入室時の自動 DL を持たず、TakeStrip.svelte の手動 DL ボタン（downloadAndAck）のみが存在する。テイクの二重登録自体は同期冪等キー（client_take_id）で既に防止済みで詰みは無いが、仕様どおりの UX ではない。つまり本件は correctness gap ではなく v1 仕様への UX 忠実性ギャップである。

## 現状の整理（重要な前提）

- 詳細 GET（CaptureManualDetailData → CaptureCutData::fromCut）は採用テイクのみ playback_url と download_ack_token を非 null で返す。非採用テイクは常に両方 null。
- 「DL 済み」はサーバ側 takes.downloaded_at が唯一の真実。downloaded: boolean として詳細 GET に載る。
- 手動 DL（TakeStrip.downloadAndAck）は 2 段: (1) window.open(playback_url) (2) POST .../takes/{take}/downloaded（{ ack_token }。CaptureTakeService::markDownloaded が token 検証 → 未打刻なら downloaded_at=now()。再送は冪等 no-op）。
- 現行 v1 アーキテクチャに「DL した動画バイトを端末 IDB へ永続保存する」仕組みは存在しない。lib/capture/idb.ts はアップロード pending バッファ専用。「DL 済み」の効果は (a) バッジ表示、(b) サーバ側で当該テイク削除を 422 拒否、(c) 再アップロード対象からの除外（元々ローカル pending 由来のみ送信のため対象外）に限られる。

## 改善アイデア

Capture/Show.svelte のマウント時（入室時）に、未 DL の採用済みテイクを順次自動ダウンロード + ACK する仕組みを追加。

- 対象: 各カットの採用テイク（cut.adopted_take_id === take.id）のうち take.status === "ready" かつ take.downloaded === false かつ playback_url !== null かつ download_ack_token !== null。
- 手順（1 件ずつ = 順次）: (1) fetch(playback_url, { credentials: "omit" }) で実バイト取得（署名 S3 URL に cookie を送らない／カスタムヘッダ無しで CORS preflight 回避）、body は破棄。(2) 成功したら POST .../takes/{take}/downloaded（{ ack_token }）で ACK（手動 DL と同一経路）。(3) ACK 成功後 router.reload({ only: ["manual"] }) を 1 回だけ行い downloaded=true を反映。
- 規律: オフライン時スキップ（onLine===false なら何もしない。次回入室 or online 復帰で再試行）。失敗時は有界リトライ（指数 backoff）→ 最終失敗はスキップ（詰ませない。手動ボタン残す）。多重起動防止フラグ。手動で既に打った分は downloaded===true で除外・ACK はサーバ冪等。

### 実装構造（概要）

- 新規 resources/js/lib/capture/auto-download.ts（テスト可能）を追加し「manual を受け取り → 未 DL 採用テイク列挙 → 順次 fetch+ACK → 各成功で onDownloaded コールバック」を担わせる。upload-queue.ts と同じく fetcher/delay/isOnline を注入可能にしテストする。
- Capture/Show.svelte は onMount（+online 復帰）で呼ぶだけの薄い結線。ACK 経路は既存 lib/capture/http.ts を再利用。

## 期待効果

- 使命への貢献: 「思考ゼロ・編集ゼロ」。操作者が手で DL する手間をゼロにし doc/05 §5.3 の同期モデルを仕様どおり成立させる。DL 済みバッジが自動で正しく付き「どのカットが確定済みか」が一目で分かる。
- UX 忠実性: 監査ギャップ #6 を解消。
- 副作用最小: 二重登録は元々冪等キーで防止済み。手動 DL/バッジ/upload-queue と整合。

## 制約・前提

- v1 スコープ: 撮影は PWA・同一オリジン・セッション認証。
- セキュリティ: ACK は既存署名 download_ack_token 経由のみ（payload から downloaded_at 等を受け取らない。MarkTakeDownloadedRequest が missing で拒否）。新規エンドポイント・サーバ変更は不要。playback_url は take スコープ署名 S3 URL、cross-org 越境なし。
- 既存整合: Svelte 5 runes、lib/capture/http.ts、単方向 import。新規 UI コンポーネント追加なし。

## スコープ外（過剰実装しない）

- DL した動画バイトの端末 IDB 永続保存: 現行 v1 に永続化機構無し、二重登録は冪等キーで既に防止済み、iOS eviction/容量圧迫を考えると恒久保存機構の新設はオーバーエンジニアリング。本件は fetch でバイト取得 → ACK 記録に留め、永続キャッシュは v1 スコープ外。
- セッション中に採用したテイクの即時自動 DL: 仕様の「遷移時（入室時）」に忠実に onMount(+online 復帰)へ限定。滞在中 adopt 直後は対象外（手動ボタンで対応可・詰みなし）。
- サーバ側 API 変更: 既存 POST takes.downloaded と詳細 GET payload を一切変更しない。
- 手動 DL ボタンの削除: 残す。
