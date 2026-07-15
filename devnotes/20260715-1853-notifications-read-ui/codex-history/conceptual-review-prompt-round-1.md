【アプリの使命 (North Star) — AGENTS.md より】
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件はテスト登録まで含めて実装済み)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()(back()->with(...) で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【思考原則】
まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵(Laravel/Svelte エコシステム)を探せ。機能の名前に立ち返れ。オーバーエンジニアリング禁止。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション(Laravel + Svelte)の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性
2. 禁止事項違反の有無
3. 実現可能性(Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性
5. リスク(重大な副作用・後退)
6. スコープの適切さ
7. 型安全性(DTO/JsonResource パターン、PHPStan level 10)

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: notifications-read-ui

## 背景・課題

`notifications.read`(POST `/notifications/{notification}/read`)は backend 実装済み
(`NotificationController::read()` が `back()` で完結し 1 件だけ既読化する)だが、
`resources/js` からの呼び出しが存在しない dead surface になっている。

現状の通知 UI の既読導線は 2 つだけ:
1. 行クリック = `open`(POST `/notifications/{id}/open`)— 既読化 + 遷移(行全体が `<button>`)
2. 「すべて既読にする」= `read-all` — 一括既読

「開かずに 1 件だけ既読にする」導線が無い。通知を開くと必ず遷移するため、
「確認済みだが今は開きたくない」通知をその場で片付けられず、未読バッジを減らすには
全部開くか一括既読しかない。粒度の中間(個別既読)が欠けている。

## 改善アイデア

未読通知の各行に個別「既読」アイコンボタンを追加。押下で POST `notifications.read` を呼び、
遷移せずにその 1 件だけを既読化し一覧 state に反映する(未読ドット・ハイライトが消える)。
既存 `open`(行クリック=既読+遷移)/ `read-all`(一括既読)は維持。個別既読ボタンは未読行にのみ表示。

## 期待効果

- 通知確認だが未開封の処理を最小操作で可能にし、未読ノイズを溜めず本来作業に集中できる。
- backend 実装済み route の dead surface を解消。

## 実装方針（概要）

- `NotificationListItem.svelte`: 「行全体が 1 button(open)」を「open ボタン + 個別既読ボタン」
  の 2 ボタン構成へ。ネストした button(不正 HTML)回避のため外側を `<div>` ラッパにし兄弟に。
  open ボタンは既存 testid `notification-item` / `data-unread` / open ハンドラを保持。
  未読時のみ既読アイコンボタン(Lucide `Check`, aria-label="既読にする",
  data-testid="notification-read-button")を表示。
  既読ハンドラ: `router.post('/notifications/{id}/read', {}, { preserveScroll: true })`。遷移しない。
  連打ガードは in-flight 送信ガード(disabled 属性は使わない)。
  一覧 state 反映は楽観的ローカル state で即時既読表示に切替、onError で未読復帰、
  サーバ `back()` 再読込が prop を確定。
- `Notifications/Index.svelte`: 変更不要。純フロント(backend/route/DTO/型変更なし)。

## 制約・前提

- 禁止事項#8: 既読ボタンは未読行にのみ表示(disabled 不使用)。
- DESIGN.md token 経由 / アイコンは Lucide のみ。Atomic Design は features/notifications に閉じる。
- 既存 vitest の open/未読表示アサート(testid notification-item / unread-dot / data-unread)を壊さない。

## スコープ外

backend/route/DTO/NotificationType 変更、open/read-all 挙動変更、unread 化導線、
NotificationBell への個別既読導線追加。
