# アプリの使命（AGENTS.md より）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


# 禁止事項（AGENTS.md より）

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

【補足: レビューにあたり参照できる実ファイル】
- devnotes/20260803-0053-aigenba-alignment/detailed-design.md （施策6・施策8 = 本件の親設計）
- devnotes/20260803-0053-aigenba-alignment/bfcache-playwright-probe-result.md
- docs/supported-browsers.md （ブラウザ方針の正本）
- resources/js/lib/bfcache-guard.ts （検証対象の guard 実装）
- routes/web.php （L155 = /session/status、L667-681 = /debug/login と LocalOnly ゲート）
- app/Http/Middleware/LocalOnly.php
- app/Http/Controllers/DebugLoginController.php / resources/js/pages/Debug/Login.svelte
- public/manifest.webmanifest
- docs/TODO.md （T085）

---

## 概念設計

# 概念設計: bfcache 実機受入確認の検証ページ (debug 限定)

## 背景・課題

### T085 が現状かかえている欠陥

`docs/TODO.md` の T085「bfcache 実復元の iOS 実機受入確認」は、
Playwright では原理的に再現できない **ブラウザ自身の bfcache 復元経路** を
iOS Safari 実機の手動確認で埋める、という位置づけである
(`devnotes/20260803-0053-aigenba-alignment/detailed-design.md` 施策 8)。

しかしその手順は **素の目視確認** であり、次の 2 つを区別できない。

| # | 実際に起きたこと | 見た目 |
|---|---|---|
| 1 | guard が働いた (秘匿 → プローブ → login へ) | PII が出ない |
| 2 | **そもそも bfcache 復元が起きなかった** (Safari が普通に取り直した) | PII が出ない |

2 は空振りである。にもかかわらず「確認済み」と記録されうる。

これは同じ設計文書が Playwright レーンについて徹底的に潰した欠陥と**同型**である。
設計文書は「**空振りを green と偽らない**」「負のコントロールを必ず置き、
『復元が起きていない』ことを検出できるようにする」と繰り返し要求し、
`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` では
経路 C の正のコントロール 2 種 (`history.state.page instanceof ArrayBuffer` /
JS 実行コンテキストの生存) まで作り込んでいる
(`docs/supported-browsers.md` L66)。

**その規律が実機レーンにだけ適用されていない。**

### 記録の質

T085 は「日時・端末・OS バージョン・結果を devnotes に記録する」を求めるが、
現状これは**人手の書き写し**であり自己申告になる。
また `docs/supported-browsers.md` L146 のとおり
**このリポジトリに iOS 実機受入確認の記録はまだ 1 件も無い**。
同 L148 は実機確認を「補完ではなく現状唯一の実環境検証手段」と位置づけているので、
唯一の手段が自己申告のまま未実施で放置されている状態である。

### 検証の前提そのものが動く

`no-store` が bfcache 格納を止めるのは **HTML 仕様の要求ではなく慣習**であり、
Chrome は 2025 年に方針を反転させた (CCNS ページを格納し cookie 変更で evict する方式)。
つまり塞いだ穴は自分のコード変更だけでなく**ブラウザ側の方針転換でも開き直る**。
T085 の「再確認条件」(guard / 秘匿スタイル / プローブの変更時) が
一度きりでないのはそのためで、確認は**繰り返し実施される恒久作業**である。
毎回スクリーンショットを撮り直す作業を、安く・誤りなく回せる必要がある。

## 改善アイデア

`/debug` 配下に **bfcache 検証専用ページ**を置き、
「復元が実際に起きたか」を画面上の観測値として可視化する。

`/debug/login` が既に同じ性質の経路として存在し、
route 登録ゲート (`app()->isLocal() || app()->runningUnitTests()`) と
`LocalOnly` middleware (local 以外 404 / 資格情報未設定で 404 / Basic 認証) の
二重防御を持っている。**その作法にそのまま乗る**。

### 中核: 3 つの独立した証拠

単一の指標に依存しない。互いに独立な 3 つを同時に表示し、食い違いも見えるようにする。

| # | 証拠 | 何を示すか |
|---|---|---|
| 1 | `pageshow` の `PageTransitionEvent.persisted` | ブラウザ自身の申告 |
| 2 | **JS 実行コンテキスト生存トークン** | script 評価時に一度だけ生成する値。戻った後も同じ値なら **Document が再実行されていない = 真の復元**。`persisted` に依存しない |
| 3 | **ログの連続性** | 離脱前に記録したイベントが、復元後のページ内状態に残っているか |

2 は `docs/supported-browsers.md` L66 が経路 C の正のコントロールで使っている手法と同じで、
リポジトリ内に前例がある。

3 つが揃って初めて「有効な試行」であり、
`persisted === false` なら**その試行は無効**として画面上でやり直しを促す。

### guard の状態遷移をそのまま見せる

guard は状態を `documentElement` の `data-bfcache-hidden` 属性
(`pending` / `verifying` / `retry`) として持つ。
これを MutationObserver で監視し、時刻付きで遷移列を記録する。
`pending → verifying → (属性削除 | retry)` のどれを辿ったかが証跡になる。

### スクリーンショット 1 枚で足りる状態にする

同一画面に、環境情報 (UA / `display-mode` / `navigator.standalone` / 日時) と
上記の観測値・遷移列をまとめて表示する。
T085 が手で書き写せと言っている項目が全部画面に出るので、
**撮影 1 枚が証跡**になり、書き写し誤りが原理的に消える。
テキストコピーも用意し、devnotes へ貼れる形にする。

## 期待効果

- **使命への貢献**: 撮影 PWA (`/app/*`) の主要実行系は iOS Safari であり
  (`docs/supported-browsers.md` L54-57)、その唯一の実環境検証手段の信頼性を上げる。
  現場作業者の PII が漏れないことを、空振りでない形で確認できるようになる
- **T085 の空振りを構造的に排除**する。Playwright レーンに課した規律を実機レーンにも揃える
- **再確認コストを下げる**。T085 は一度きりでなく変更のたびに回る。
  手順が「ページを開いて操作して 1 枚撮る」になれば実施され続ける
- **未実施の解消**: 記録 0 件という現状を、実施しやすさの側から崩す

## 実装方針（概要）

### production コードを一切変更しない

**これは要件である。** 検証対象 (`bfcache-guard.ts` / 秘匿 CSS / `/session/status`) を
検証の都合で変えたら、確認しているものが production と別物になる。

- guard は `resources/js/app.ts` で既に全ページに自動インストールされる
- プローブ先 `/session/status` (`routes/web.php:155`) は `auth` グループ外・`LocalOnly` 外
- したがって**どちらも本物がそのまま動く**。再実装もフックの追加も不要

検証ページは guard を**外から観測するだけ**にする。

### オーバーレイが自分のログを覆う問題

guard の秘匿は画面全体に掛かるので、素朴に作るとログパネルも覆われて読めない。
とくに `retry` 終端では秘匿が維持されるため永久に読めない。

対策: ログは発生のつど `sessionStorage` に追記し、ページ描画時にそこから復元して表示する。

- `authenticated` に倒れた場合 → 秘匿が解除され、そのままログが読める
- `retry` に倒れた場合 → 再試行ボタンで hard reload → 読み込み直後にログが出る
- `unauthenticated` に倒れた場合 → `/login` へ飛ぶ。ログは残っているので
  debug login で入り直して読む

guard 自身が復元マーカーに `sessionStorage` を使わない (タブ共有で誤検知するため) のは
guard の設計判断であり、**検証ページ側のログ用途とは目的が違う**ので競合しない。

### 経路の配置

| 項目 | 方針 |
|---|---|
| route | 既存の `isLocal() \|\| runningUnitTests()` ブロック内、`LocalOnly` グループに追加 |
| **`auth` 必須** | `NoStoreCacheHeadersForAuthenticatedPages` (`bootstrap/app.php:132`) を通す。**`no-store` が実際に付いた状態**でなければ「Safari は no-store でも格納する」の検証にならない |
| 画面 | Inertia ページ。既存 `resources/js/pages/Debug/Login.svelte` の idiom に合わせる |
| ダミー PII | 誰が見ても偽物と分かる固定文字列。証跡を devnotes に貼る以上、本物めいた個人情報を写り込ませない |

### 検証シナリオの操作性

ログアウト後の復元を試すには、**検証ページを bfcache に置いたままセッションを破棄する**必要がある。
検証ページ自身から遷移してログアウトすると戻る操作が増えて手順が濁るため、
遷移先の相方ページを 1 枚用意する。

## 制約・前提

- **本番非到達**: route 登録ゲートと `LocalOnly` の二重防御に乗る。
  `LocalOnly` の判定は `config('app.env')` であって接続元 IP ではないため、
  **`APP_ENV=local` のまま HTTPS トンネル経由で実機から到達させられる**。
  露出は Basic 認証が受ける
- **HTTPS がほぼ必須**: CCNS × bfcache の議論は HTTPS ページを前提にしており、
  PWA の standalone インストールも secure context を要求する。
  平文 http の LAN IP で試すと**本番と違う条件を見て「確認済み」と記録する**危険がある
- **PWA scope への暗黙依存**: `public/manifest.webmanifest` は `scope` を明示しておらず
  `start_url: "/app"` である。既定 scope は `/` に解決されるため
  `/debug/*` も standalone に含まれるが、これは**暗黙の依存**である。
  将来 `"scope": "/app/"` が明示されると standalone 確認が無言で壊れる。
  検証ページが `display-mode` を表示することで、この破綻はページ自身が検出する
- **`unload` ハンドラを置かない**: 1 つで bfcache 対象外になり、
  検証ページ自身が空振りの原因になる。`pagehide` / `pageshow` は問題ない
- Basic 認証は Chrome の言う "other authorization methods" に当たるため
  Chrome での evict 挙動に影響しうる。iOS Safari の確認には無関係だが、
  Chrome を併用するときの注意点として文書に残す

## スコープ外

- **自動化しない**。Playwright で bfcache 復元を再現するのは原理的に不可能と実測済みで
  (`devnotes/20260803-0053-aigenba-alignment/bfcache-playwright-probe-result.md`)、
  本ページはその不可能性を前提とした手動確認の補助である
- **passkey の実機確認シナリオ** (`docs/supported-browsers.md` L126-127) は別物。混ぜない
- **経路 C (Inertia のクライアント履歴復元)** は既に両レーンで恒久自動回帰があるので対象外
- **T085 の実施そのもの**。本設計は実施を可能にする設備であり、実機確認の実行は T085 の責務
- production の guard・秘匿 CSS・プローブへの変更
