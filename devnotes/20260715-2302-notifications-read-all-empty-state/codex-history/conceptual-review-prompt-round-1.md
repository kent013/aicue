## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告。
2. PHPStan エラーの widen・baseline 化。
3. dev DB への破壊操作をエージェント判断で実行。
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)。
5. LLM 呼び出しの Prism 直呼び。
6. prompt 文字列のコード直書き。
7. 操作系 POST の応答での `redirect()->intended()`。
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか（特に #8: disabled 化の是非）
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: notifications-read-all-empty-state

bug-hunt run 20260715-213842 / finding F-4-01 (Low, H12)

## 背景・課題

`/notifications` (`notifications.index`) は未読が 0 件のときも「すべて既読にする」
(read-all) ボタンが常時活性のまま表示され、無意味な `notifications.read-all`
POST を発火できる。無害だが、既読化する対象が無い状態で「すべて既読にする」を
押せるのは操作として意味が無く、UX ノイズになっている。

## 改善アイデア

未読が 0 件のとき read-all ボタンを**非表示**にする。未読が 1 件以上あるときは
従来どおり表示する。

- AGENTS.md 禁止事項 #8「必須条件未充足を理由にボタンを disabled にする UI」に留意し、
  disabled で無反応にするのではなく、意味の無い操作は非表示にするのが自然
  (押しても意味が無い = そもそも押させない = 非表示。禁止事項 #8 は「押下時にエラー表示する」
  文脈であり、押す意味自体が無い操作は非表示が適切)。

## 期待効果

- 使命への貢献: 現場作業者が迷わず使える UI (思考ゼロ) の一貫性。意味の無い操作要素を排す。
- 未読 0 件時の空振り read-all リクエストを構造的に排除。UX ノイズ解消。

## 実装方針（概要）

1. サーバ (`NotificationController::index`): Inertia props に明示的な `unreadCount` を追加。
   値は既存の `NotificationCenterService::unreadCountFor(User): int` (全 org 横断・自分宛のみ)。
   - 理由: `HandleInertiaRequests` の shared prop `notifications.unreadCount` は Index ページ固有
     prop `notifications` (`NotificationItem[]`) と同一キー衝突し配列で上書きされ、Index からは
     読めない。ページ固有 prop として別キー `unreadCount` を渡すのが正。
   - グローバル未読数を使う理由: ページャ現在ページのリストだけで判定すると 2 ページ目以降に
     未読があるのに 1 ページ目全既読でボタンが消える。全体未読数が read-all の対象そのもの。

2. フロント (`resources/js/pages/Notifications/Index.svelte`): Props に `unreadCount: number` を追加し
   read-all ボタンを `{#if unreadCount > 0}` で条件描画。可視時の in-flight 連打ガード (markingAll) は
   維持。JSDoc を「未読 0 では非表示 (禁止事項 #8 準拠で hide であって disable ではない)」に更新。

## 制約・前提

- DTO/JsonResource 契約は変更しない (Inertia props への scalar 追加のみ。notifications/meta shape 不変)。
- read-all の POST 挙動・in-flight ガードは変更しない。
- unreadCountFor は既存メソッドで追加クエリ 1 本 (count)。index 負荷影響は軽微。

## スコープ外

- shared prop `notifications` と Index ページ prop `notifications` のキー衝突そのものの是正は本 finding 対象外。
- read-all ボタンの文言・スタイル変更、1 件既読/open 遷移の挙動変更はしない。
