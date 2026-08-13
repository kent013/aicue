# Round 3: Round 2 指摘への対応と再レビュー依頼

Round 2 の指摘は全件受け入れました（反論なし）。対応マトリクスと修正後の概念設計を提示します。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] 真理値表の `pagehide`/`pageshow` の説明が不正確 (§3)
- 判断: **対応する**
- 根拠: 指摘が正しい。`pagehide` は bfcache 格納時だけでなく通常の離脱でも発火し、
  `pageshow` は初回表示でも発火する。「発火あり = 凍結・復帰した証拠」は誤りで、
  正しくは「**full-document lifecycle を通った証拠**」でしかない。
  当初の表はこの 2 つを混同していた。
- 対応内容:
  - 観測 1 の意味を「full-document navigation の lifecycle を通ったか」に修正
  - **`pagehide.persisted` も記録**する（離脱時のブラウザ申告）
  - **初回 `pageshow` は判定対象外**として明示
  - `pagehide.persisted` と `pageshow.persisted` の不一致を「観測矛盾」に加えた
  - 判定は「同一試行 ID に属する離脱と復帰の組」に対して行うと定義し直した

## [Critical] 「有効試行」と「guard の受入結果」を別軸にせよ (§3)
- 判断: **対応する（本レビューで最も重要な指摘）**
- 根拠: 当初の最終判定は「bfcache が成立したか」しか見ておらず、
  T085 の目的である「**真の復元時に guard が正しく振る舞ったか**」を判定していなかった。
  真の復元が起きても guard が `pending` で停止する・秘匿解除が早すぎる・
  MutationObserver の記録が空、といった受入失敗を PASS と読んでしまう。
  設計として明確な欠陥だったので全面的に受け入れる。
- 対応内容: 判定を三段構えに再設計した。
  1. **試行成立判定**: `valid-bfcache` / `invalid-not-bfcache` / `invalid-wrong-route` / `inconsistent`
  2. **guard 結果判定**: `authenticated-unhidden` / `unauthenticated-redirected` /
     `retry-hidden` / `failed-transition` / `not-observed`
  3. **総合判定**: 試行成立 **かつ** そのシナリオで期待した guard 結果に一致した場合のみ `PASS`
  - 「有効試行」を `PASS` と同義にしないことを明記した
  - 期待される guard 結果はシナリオ依存（ログアウト後の復元なら
    `unauthenticated-redirected`、ログイン維持のままの復元なら `authenticated-unhidden`）
    のため、**試行開始時にシナリオを宣言する**選択を画面に追加した。
    ページ側は利用者の意図を推測できないので、宣言させるのが正しい。

## [Warning] `/login` リダイレクト後の証跡回収が閉じていない (§3) / スクリーンショット 1 枚の保証 (§4)
- 判断: **対応する**
- 根拠: 指摘のとおり。再ログイン後に A を開き直すと新しい context token・初回 `pageshow`・
  新しい試行 ID が発生し、保存済み試行と混ざる。
  当初案の「debug login で入り直して読む」は回収経路として閉じていなかった。
- 対応内容:
  - sessionStorage の記録を**試行 ID ごとの immutable record** にする
  - **ページ読み込み時に自動で新規試行を開始しない**（自動開始が上書きの原因）。
    既定は「保存済み試行の表示」、新規試行は明示操作で開始する
  - 画面に **live observation / stored report のどちらかを表示**する
  - stored report では元試行 ID・元の復帰時刻・保存完了時刻を区別して出す
  - 証跡回収のための hard reload は新しい試行として数えない

## [Warning] B から A へ戻る履歴操作を固定せよ (§2)
- 判断: **対応する**
- 根拠: B で `router.post` logout を実行すると Inertia が履歴を積むため、
  1 回の「戻る」では A ではなく B に戻る。B 自身が復元されて guard に
  リダイレクトされる可能性もある。「戻る」を素朴に書くと手順が壊れる。
- 対応内容:
  - 「戻るで A」ではなく「**履歴上で A を選択して復帰**」と記述を改めた
  - iOS Safari と standalone それぞれで必要な操作を実機手順として固定する（詳細設計で確定）
  - **A と B を同一試行 ID で関連付け**、A 以外へ復帰した場合は無効試行とする
  - **B も local/debug + `auth` + `no-store` の範囲**に置く

## [Warning] context token のエントロピー / 「短縮ハッシュ」が不正確 (§5)
- 判断: **対応する**
- 根拠: 指摘のとおり。用途は秘匿ではなく前後の同一性確認であり、
  短くすると偶然一致を「Document 生存」と誤認する。「短縮ハッシュ」は用語として誤り。
- 対応内容:
  - token は `crypto.randomUUID()` で生成し、**比較には全値を使う**
  - **表示用の短縮とは明確に分ける**（短縮値で比較しない）
  - 付随して: `crypto.randomUUID()` は secure context 必須なので、
    利用できない環境では**沈黙で劣化させず、検証不能として明示的に失敗させる**。
    HTTPS 必須という既存の制約と整合し、平文 http で気づかず確認してしまう事故も同時に防ぐ

## [Warning] discriminated union に `trial-start` と `pagehide.persisted` が不足 (§7)
- 判断: **対応する**
- 対応内容:
  - union を `TrialStarted` / `PageHide` / `PageShow` / `GuardStateChanged` /
    `TrialVerdict` / `GuardVerdict` に拡張
  - 共通フィールド `schemaVersion` / `trialId` / `sequence` / `timestamp` を持たせる
  - **sessionStorage からの復元時は型 assertion で済ませず、allowlist と schemaVersion を
    検証し、不正なら破棄する**。これは `bfcache-guard.ts` の
    `readAuthenticatedFlag()` が採っている「shape を厳密判定し、崩れていたら
    判定不能に倒す」idiom と同じで、リポジトリ内に前例がある
  - 試行 ID はクライアント生成にできるため **Inertia props を持たない**構成にする
    （DTO を増やさない。指摘 §7 の後段を採用）

## [Suggestion] 専用 env フラグ不要の判断は受け入れ可（ただし条件付き） (§5)
- 判断: **条件をすべて受け入れる**
- 対応内容:
  - 本ページと B の全 route が既存 debug route block と `LocalOnly` の
    **両方に構造的に包含されることを architecture テストで固定**する（施策に追加）
  - **debug ページから実ユーザー情報を props に渡さない**（props 自体を持たない構成にしたので自動的に満たす）
  - トンネル運用規律を `docs/supported-browsers.md`（実機確認手順の正本）に残す

## [Suggestion] plain anchor = full navigation と仮定するな (§6)
- 判断: **対応する**
- 対応内容: 受入条件に以下を追加した。
  - **A で `pagehide` が観測されたこと**を必須条件にする（仮定ではなく観測で確かめる）
  - `performance.getEntriesByType('navigation')` は補助情報に留め、**主証拠にしない**
  - `target="_blank"` / download / 外部ブラウザ切替を使わない
  - **standalone から Safari UI へ脱出していないことを `display-mode` で検出**する

## [Suggestion] 使命への貢献の限定は適切 (§1) / スコープは適切 (§6) / 観測矛盾を要調査とするのは妥当 (§4)
- 判断: 追加対応なし（現状維持）

---

## 特に見てほしい点

1. **二軸 + 総合の判定設計** — Round 2 の最重要指摘を受けて作り直しました。
   軸 1 (試行成立) / 軸 2 (guard 結果) / 軸 3 (総合) の定義に、
   取りこぼしや誤分類はないか。とくに軸 1 の 4 分類が網羅的か。
2. **シナリオ宣言** — 期待される guard 結果はシナリオ依存なので、試行開始時に
   利用者に宣言させる設計にしました。推測させない判断は妥当か。
   宣言と実際の操作がずれた場合（宣言はログアウト後復元なのに実際はログアウトしなかった等）を
   どう扱うべきか、示唆があれば。
3. **stored report / live observation のモード分離** — 自動で新規試行を開始しないことで
   上書きを防ぐ設計にしました。証跡回収経路はこれで閉じているか。
4. **概念設計として十分か** — 詳細設計フェーズに進んでよい水準か。
   残る曖昧さがあれば、それが概念レベルの穴か詳細設計で埋めるべきものかを示してください。

---

## 修正後の概念設計

# 概念設計: bfcache 実機受入確認の検証ページ (debug 限定)

> Round 1 / Round 2 レビュー反映済み。判断の根拠は
> `codex-history/conceptual-review-decisions-round-{1,2}.md`。

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
経路 C の正のコントロール 2 種まで作り込んでいる
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
T085 の「再確認条件」が一度きりでないのはそのためで、
確認は**繰り返し実施される恒久作業**である。
毎回スクリーンショットを撮り直す作業を、安く・誤りなく回せる必要がある。

## 改善アイデア

`/debug` 配下に **bfcache 検証専用ページ**を置き、
「復元が実際に起きたか」と「guard が正しく振る舞ったか」を
**別々の観測値として**画面に可視化する。

`/debug/login` が既に同じ性質の経路として存在し、
route 登録ゲート (`app()->isLocal() || app()->runningUnitTests()`) と
`LocalOnly` middleware (local 以外 404 / 資格情報未設定で 404 / Basic 認証) の
防御に乗っている。**その作法にそのまま乗る**。

### 観測する生値

| # | 観測 | 何を示すか |
|---|---|---|
| 1 | `pagehide` / `pageshow` の発火と、それぞれの `persisted` | **full-document navigation の lifecycle を通ったか**。発火は「凍結・復帰した」証拠ではなく、同一 Document 内の遷移でなかったことの証拠にすぎない |
| 2 | **JS 実行コンテキスト生存トークン** | script 評価時に一度だけ生成する値。復帰後も同じなら Document が再実行されていない |
| 3 | guard の `data-bfcache-hidden` 属性の遷移列 | `pending` / `verifying` / `retry` / 属性削除 を時刻付きで |

観測 3 は MutationObserver で `documentElement` を監視して得る。

**初回 `pageshow` は判定対象外**とする（初回表示でも `pageshow` は発火するため）。
判定は「同一試行 ID に属する**離脱と復帰の組**」に対して行う。

### 判定は二軸 + 総合

**「bfcache が成立したか」と「guard が合格したか」は別の問いである。**
真の復元が起きても guard が `pending` で止まる・秘匿解除が早すぎる、といった
受入失敗はありうる。一つの判定に混ぜると受入失敗を PASS と読んでしまう。

#### 軸 1: 試行成立判定

| 判定 | 条件 |
|---|---|
| `valid-bfcache` | 離脱時 `pagehide` あり (原則 `persisted=true`) / 復帰時 `pageshow.persisted=true` / token が離脱前と同一 |
| `invalid-not-bfcache` | `pageshow.persisted=false` かつ token が離脱前と異なる (= 通常の再取得。空振り) |
| `invalid-wrong-route` | `pagehide`/`pageshow` を伴わない復帰 (= 同一 Document の popstate。経路 C であって対象外)、または A 以外のページへ復帰した |
| `inconsistent` | 上記のいずれにも当てはまらない組合せ。**`pagehide.persisted` と `pageshow.persisted` の不一致を含む** |

`inconsistent` は合格にも単なる無効にも倒さず **要調査**として扱う。
ブラウザ申告と実測の食い違いを黙って捨てると、
まさに T085 が避けたい「実態を見ないまま記録する」に戻る。

#### 軸 2: guard 結果判定

| 判定 | 意味 |
|---|---|
| `authenticated-unhidden` | 秘匿 → 検証 → 秘匿解除。DOM は温存 |
| `unauthenticated-redirected` | 秘匿を維持したまま `/login` へ |
| `retry-hidden` | プローブ失敗。秘匿維持 + 再試行ボタン |
| `failed-transition` | 遷移列が期待形を外れた (例: `pending` のまま停止、秘匿解除が早すぎる) |
| `not-observed` | guard の遷移が一度も観測されなかった |

#### 軸 3: 総合判定

**試行成立が `valid-bfcache` であり、かつそのシナリオで期待した guard 結果に
一致した場合のみ `PASS`。**「有効試行」は `PASS` と同義ではない。

期待される guard 結果はシナリオによって変わる
（ログアウト後の復元なら `unauthenticated-redirected`、
ログイン維持のままの復元なら `authenticated-unhidden`）。
ページ側は利用者の意図を推測できないので、
**試行開始時に検証シナリオを宣言させる**。

### スクリーンショット 1 枚で足りる状態にする

必須表示項目:

- **live observation / stored report のどちらであるか**
- 試行 ID / 宣言したシナリオ / 開始時刻 / 離脱時刻 / 復帰時刻
- UA / `display-mode` / `navigator.standalone` / OS バージョン (UA から読める範囲)
- 観測 1〜3 の生値
- **軸 1 / 軸 2 / 総合**の 3 判定と、無効・矛盾・不合格の場合はその理由
- stored report では元試行 ID・元の復帰時刻・保存完了時刻を区別して出す

T085 が手で書き写せと言っている項目が全部画面に出るので、
**撮影 1 枚が証跡**になり、書き写し誤りが原理的に消える。
テキストコピーも用意し、devnotes へ貼れる形にする。

## 期待効果

- **使命への貢献**: 撮影 PWA (`/app/*`) の主要実行系は iOS Safari であり
  (`docs/supported-browsers.md` L54-57)、
  **現場作業者が撮影 PWA を使った後、ログアウト後の履歴復元で PII が露出しないこと**を
  確認するための検証支援である。使命への接続は撮影導線の安全性・信頼性に限定される
  (新機能ではない)
- **T085 の空振りを構造的に排除**する。Playwright レーンに課した規律を実機レーンにも揃える
- **受入失敗を PASS と読む事故を防ぐ**（二軸判定）
- **再確認コストを下げる**。T085 は変更のたびに回る恒久作業である
- **未実施の解消**: 記録 0 件という現状を、実施しやすさの側から崩す

## 実装方針（概要）

### 検証対象の挙動を一切変更しない

**これは要件である。** 検証対象を検証の都合で変えたら、
確認しているものが production と別物になる。

変更しない対象: `resources/js/lib/bfcache-guard.ts` / 秘匿 CSS /
`/session/status` / guard の発火経路 (`resources/js/app.ts` の登録)。

追加するのは **local/debug 限定の観測ページのみ**である
(route / controller / Inertia ページ / architecture テスト)。
これらはアプリコードの追加ではあるが、production の挙動は変えない。

- guard は `resources/js/app.ts` で既に全ページに自動インストールされる
- プローブ先 `/session/status` (`routes/web.php:155`) は `auth` グループ外・`LocalOnly` 外
- したがって**どちらも本物がそのまま動く**。再実装もフックの追加も不要

### サーバ通信を増やさない / props を持たない

観測値はすべてクライアント側で生成できる。試行 ID もクライアント生成にする。

- **新規 JSON endpoint を作らない**（`response()->json()` 直書き禁止に触れない）
- **Inertia props を持たない**構成にする。DTO を増やさず、
  「debug ページから実ユーザー情報を props に渡さない」も自動的に満たす

### 2 つのモード（証跡の上書きを防ぐ）

**ページ読み込み時に自動で新規試行を開始しない。** 自動開始は保存済み試行の上書き原因になる。

| モード | 挙動 |
|---|---|
| **stored report**（既定） | 保存済み試行を一覧・表示する。読み込んだだけでは何も記録しない |
| **live observation** | 明示操作で新規試行を開始した状態。シナリオを宣言してから離脱する |

証跡回収のための hard reload は**新しい試行として数えない**。

### ログの保存

guard の秘匿は画面全体に掛かるので、素朴に作るとログパネルも覆われて読めない。
とくに `retry` 終端では秘匿が維持されるため永久に読めない。
そこでログは発生のつど `sessionStorage` に追記し、描画時に復元して表示する。

- `authenticated` に倒れた → 秘匿が解除され、そのまま読める
- `retry` に倒れた → 再試行ボタンで hard reload → stored report として読める
- `unauthenticated` に倒れた → `/login` へ。debug login で入り直し、
  **stored report** として元試行を読む（新規試行は始まらない）

記録は**試行 ID ごとの immutable record** とし、既存試行を上書きしない。

guard 自身が復元マーカーに `sessionStorage` を使わない (タブ共有で誤検知するため) のは
guard の設計判断であり、**検証ページ側のログ用途とは目的が違う**ので競合しない。

#### 保存項目の allowlist（保存してよいものだけを列挙する）

ログは `/login` 遷移をまたいで残り、かつ devnotes に貼られる。

**保存可**: 試行 ID / シナリオ種別 / `schemaVersion` / `sequence` / timestamp /
event 種別 / `pagehide.persisted` / `pageshow.persisted` / guard 属性の状態値 /
context token / `display-mode` / `navigator.standalone` の真偽値 / UA 文字列

**保存禁止**: 氏名・email などの実データ、**ダミー PII 文字列そのもの**、
URL の query string、cookie、プローブのレスポンス本文、
その他 allowlist に無い一切の値

#### 復元時の検証

`sessionStorage` からの復元は**型 assertion で済ませない**。
allowlist と `schemaVersion` を検証し、不正なら破棄する。
これは `bfcache-guard.ts` の `readAuthenticatedFlag()` が採っている
「shape を厳密判定し、崩れていたら判定不能に倒す」idiom と同じで、
リポジトリ内に前例がある。

### context token

- `crypto.randomUUID()` で生成し、**比較には全値を使う**
- 表示用の短縮とは明確に分ける（**短縮値で比較しない**）
- `crypto.randomUUID()` は secure context 必須。利用できない環境では
  **沈黙で劣化させず、検証不能として明示的に失敗させる**。
  HTTPS 必須という制約と整合し、平文 http で気づかず確認してしまう事故も同時に防ぐ

### 経路の配置

| 項目 | 方針 |
|---|---|
| route | 既存の `isLocal() \|\| runningUnitTests()` ブロック内、`LocalOnly` グループに追加 |
| **`auth` 必須** | `NoStoreCacheHeadersForAuthenticatedPages` (`bootstrap/app.php:132`) を通す。**`no-store` が実際に付いた状態**でなければ「Safari は no-store でも格納する」の検証にならない |
| **A も B も同条件** | 相方ページ B も local/debug + `auth` + `no-store` の範囲に置く |
| 画面 | Inertia ページ。既存 `resources/js/pages/Debug/Login.svelte` の idiom に合わせる |
| ダミー PII | 誰が見ても偽物と分かる固定文字列。証跡を devnotes に貼る以上、本物めいた個人情報を写り込ませない |

### 離脱は full document navigation でなければならない（中核制約）

**Inertia visit では bfcache に入らない。** client-side navigation は同一 Document のままなので
`pagehide` が起きず、戻る操作は popstate = 経路 C になる。これは検証したい経路ではない。

したがって離脱は **plain な `<a href>`** にする。ただし
**「plain anchor だから必ず full navigation」と仮定しない**。受入条件:

- **A で `pagehide` が観測されたこと**を必須にする（仮定ではなく観測で確かめる）
- `performance.getEntriesByType('navigation')` は補助情報に留め、**主証拠にしない**
- `target="_blank"` / download / 外部ブラウザ切替を使わない
- **standalone から Safari UI へ脱出していないことを `display-mode` で検出**する

### 新しい logout 導線を作らない

`tests/js/architecture/logout-call-site-inventory.test.ts` が
**logout は Inertia visit (`router.post`) 一本**であることを deny-by-default で固定しており、
同一ファイル内の `fetch`/`axios` 併用を違反として検出する。
これは経路 C の保証（`clearHistory: true` を含む Inertia page をクライアントが適用すること）が
その一本に乗っているためである。

よって **logout 導線は一切新設しない**。相方ページ B は既存 `AppLayout` を使い、
そこに元からあるユーザーメニューの logout（inventory 登録済みの既存 call site）で
ログアウトする。inventory への追記も発生しない。

### 検証シナリオの操作

1. `/debug/login` で任意ユーザーとしてログイン
2. 検証ページ A を開く（stored report モードで開く。まだ記録しない）
3. **シナリオを宣言して新規試行を開始**（試行 ID と context token が確定する）
4. A の plain anchor で相方ページ B へ **full document navigation**（ここで A が bfcache に入る）
5. B の `AppLayout` ユーザーメニューから**通常のログアウト**（A は凍結されたまま）
6. **履歴上で A を選択して復帰**する。B での logout は Inertia が履歴を積むため
   「戻る 1 回」では A に戻らない。iOS Safari と standalone それぞれで
   必要な操作は詳細設計で実機手順として固定する
7. A の判定表示を撮影する（`retry` / `/login` 送りに倒れた場合は stored report として読む）

A と B は**同一試行 ID で関連付ける**。A 以外へ復帰した場合は `invalid-wrong-route`。

### `unload` / `beforeunload` を置かないことをテストで固定する

1 行入るだけで検証が恒久的に空振りになり、しかも**空振りは緑に見える**ため誰も気づかない。
`tests/js/architecture/` の deny-by-default テスト群に倣い、
検証ページ配下に `unload` / `beforeunload` リスナが登録されないことを固定するテストを追加する。

### route の包含を architecture テストで固定する

専用 env フラグを追加しない判断（下記）の前提条件として、
**A と B の全 route が既存 debug route block と `LocalOnly` の両方に
構造的に包含されること**をテストで固定する。

### 型境界

- Inertia props: **持たない**
- クライアント内ログ: discriminated union
  `TrialStarted` / `PageHide` / `PageShow` / `GuardStateChanged` /
  `TrialVerdict` / `GuardVerdict`
- 共通フィールド: `schemaVersion` / `trialId` / `sequence` / `timestamp`
- イベント種別ごとに許可フィールドを固定する

## 制約・前提

- **本番非到達**: route 登録ゲートと `LocalOnly` の防御に乗る。
  `config/debug.php` の注記どおり防御は三層で、第三層は `ProductionEnvGuard` が
  production での `DEBUG_LOGIN_*` 残置を起動時に fail-fast させる。
  また `LocalOnly` は `DEBUG_LOGIN_USER` / `DEBUG_LOGIN_PASSWORD` 未設定時に 404 に倒れるため、
  **明示的な env による opt-in が既に必須条件**になっている。
  よって本ページ専用の env フラグは追加しない
  （より権限の低い経路にだけ 4 つ目のフラグを足すと debug 経路の有効条件が二系統化する）
- **露出面の増加を認識する**: `LocalOnly` の判定は `config('app.env')` であって接続元 IP ではないため、
  `APP_ENV=local` のまま HTTPS トンネル経由で実機から到達させられる。露出は Basic 認証が受ける。
  ただし**本ページの追加でトンネル運用時の露出面は増える**。運用規律
  (1) トンネルは検証中のみ起動する (2) Basic 認証の資格情報を他と使い回さない
  (3) 検証後に停止する — を `docs/supported-browsers.md`（実機確認手順の正本）に残す
- **HTTPS がほぼ必須**: CCNS × bfcache の議論は HTTPS ページを前提にしており、
  PWA の standalone インストールも secure context を要求する。
  `crypto.randomUUID()` も secure context 必須である。
  平文 http の LAN IP で試すと**本番と違う条件を見て「確認済み」と記録する**危険がある
- **PWA scope への暗黙依存**: `public/manifest.webmanifest` は `scope` を明示しておらず
  `start_url: "/app"` である。既定 scope は `/` に解決されるため
  `/debug/*` も standalone に含まれるが、これは**暗黙の依存**である。
  将来 `"scope": "/app/"` が明示されると standalone 確認が無言で壊れる。
  検証ページが `display-mode` を表示することで、この破綻はページ自身が検出する
- Basic 認証は Chrome の言う "other authorization methods" に当たるため
  Chrome での evict 挙動に影響しうる。iOS Safari の確認には無関係だが、
  Chrome を併用するときの注意点として文書に残す

## スコープ外

- **自動化しない**。Playwright で bfcache 復元を再現するのは原理的に不可能と実測済みで
  (`devnotes/20260803-0053-aigenba-alignment/bfcache-playwright-probe-result.md`)、
  本ページはその不可能性を前提とした手動確認の補助である
- **passkey の実機確認シナリオ** (`docs/supported-browsers.md` L126-127) は別物。混ぜない
- **経路 C (Inertia のクライアント履歴復元)** は既に両レーンで恒久自動回帰があるので対象外。
  ただし「経路 C と取り違えていないこと」の判定は本ページの責務に含む（`invalid-wrong-route`）
- **T085 の実施そのもの**。本設計は実施を可能にする設備であり、実機確認の実行は T085 の責務
- 検証対象 (guard / 秘匿 CSS / プローブ) への変更
- 新しい logout 導線
