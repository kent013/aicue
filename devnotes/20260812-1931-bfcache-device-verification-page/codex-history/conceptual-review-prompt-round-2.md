# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の指摘に対する対応マトリクスと、修正後の概念設計を提示します。
1 件（専用 env フラグ）のみ根拠を添えて反論しています。再レビューをお願いします。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 「production コードを一切変更しない」の表現が不正確 (§2)
- 判断: **対応する**
- 根拠: 指摘のとおり。`/debug` の route / controller / Inertia ページ追加はアプリコード変更である。
  意図していたのは「検証対象の挙動を変えない」であり、書き方が不正確だった。
- 対応内容: 「**検証対象** (`bfcache-guard.ts` / 秘匿 CSS / `/session/status` / 秘匿の発火経路) の
  挙動は一切変更しない。追加するのは local/debug 限定の観測ページのみ」へ書き換えた。

## [Critical] sessionStorage ログの保存項目を allowlist 化せよ (§3)
- 判断: **対応する**
- 根拠: 指摘が正しい。ログが `/login` 遷移をまたいで残る設計である以上、
  そこにダミー PII やセッション状態が入ると検証ページ自身が新しい漏えい源になる。
  「証跡を devnotes に貼る」運用なので、貼った先にも波及する。
- 対応内容: 保存可能項目を allowlist として概念設計に明記した。
  timestamp / event 種別 / `persisted` / guard 属性値 / context token の短縮ハッシュ /
  `display-mode` / 試行 ID に限定し、氏名・email・URL query・cookie・レスポンス本文・
  ダミー PII 文字列そのものを**保存禁止**と明記。

## [Critical] debug ページ専用の env フラグを追加せよ / 露出リスク (§5)
- 判断: **一部対応する（専用フラグは反論、リスク明記は対応）**
- 根拠（専用フラグへの反論）:
  1. **要求された統制は既に存在する。** `LocalOnly` は `DEBUG_LOGIN_USER` /
     `DEBUG_LOGIN_PASSWORD` が未設定なら 404 に倒れる (fail-secure)。
     つまり「明示的な env による opt-in」は既に必須条件になっている。
  2. **さらに production 側に起動時 fail-fast がある。** `config/debug.php` の注記どおり
     防御は三層で、第三層は `ProductionEnvGuard` が production での `DEBUG_LOGIN_*` 残置を
     起動時に落とす。誤公開時に「設定が残っていた」状態は production では起動しない。
  3. **本ページは同一ゲート上の `/debug/login` より権限が低い。** `/debug/login` は
     パスワード無しで任意ユーザーになれる。本ページは `auth` の背後で偽のダミーを表示し
     観測値を出すだけで、新たな権限も新たなデータ露出も足さない。
     **より弱い経路にだけ 4 つ目の独自フラグを足す**のは統制として一貫せず、
     ゲート機構を二系統に分岐させる (思考原則 2「今必要なものだけ作る」に反する)。
- 対応内容（リスク明記は受け入れ）: 「本ページ追加によりトンネル運用時の露出面が増える」ことと、
  トンネルの運用規律（検証中のみ起動する / Basic 認証の資格情報を他と使い回さない /
  検証後に停止する）を制約セクションに明記した。

## [Warning] 使命への位置づけを明確にせよ (§1)
- 判断: **対応する**
- 対応内容: 「撮影 PWA を使った後、ログアウト後の履歴復元で PII が露出しないことを
  確認するための検証支援」と限定して記述し直した。

## [Warning] JSON endpoint 追加は `response()->json()` 直書き禁止に抵触しうる (§2)
- 判断: **対応する**
- 対応内容: 「新規 JSON endpoint を作らない。サーバ→クライアントは Inertia props のみ」を
  実装方針に明記した。観測値はすべてクライアント側で生成されるため、そもそもサーバ取得が不要。

## [Warning] 有効な試行条件を画面で明示せよ (§3)
- 判断: **対応する（指摘を超えて拡張）**
- 根拠: 指摘の検討中に、当初案の証拠 #2 (JS 実行コンテキスト生存トークン) 単独では
  **真の bfcache 復元と Inertia の同一 Document 復元 (経路 C) を区別できない**ことに気づいた。
  Inertia の client-side navigation では Document が破棄されないため token も不変になる。
- 対応内容: 判定を真理値表として明文化した (概念設計「有効試行の判定」節)。
  `pagehide`/`pageshow` の観測有無が経路 C との区別に、`persisted` と token の組が
  真の復元と再取得の区別に効く。3 つが揃って初めて有効試行とする。

## [Warning] logout 導線は既存の Inertia history clear 契約を壊さない方式に限定せよ (§6)
- 判断: **対応する（指摘どおり。当初案を撤回）**
- 根拠: 指摘を受けて確認したところ、`tests/js/architecture/logout-call-site-inventory.test.ts` が
  **logout は Inertia visit (`router.post`) 一本**であることを deny-by-default で固定しており、
  同一ファイル内の `fetch`/`axios` 併用を違反として検出する。
  検討していた fetch ベースの logout は既存テストに弾かれる。
- 対応内容: **新しい logout 導線を一切作らない**方針にした。相方ページは既存 `AppLayout` を
  使い、そこに元からあるユーザーメニューの logout（inventory 登録済みの既存 call site）で
  ログアウトする。inventory への追記も発生しない。
  あわせて「A から離脱する遷移は **full document navigation** でなければ bfcache に入らない」
  ことを設計の中核制約として明記した（Inertia visit では同一 Document のままで経路 C になる）。

## [Warning] 検証ページに `unload`/`beforeunload` を登録しないことをテストで固定せよ (§5)
- 判断: **対応する**
- 根拠: 妥当。将来の改修で 1 行入るだけで検証が恒久的に空振りになり、
  しかも**空振りは緑に見える**ため誰も気づかない。既存の
  `tests/js/architecture/` に同種の deny-by-default テストが多数ある（前例あり）。
- 対応内容: 施策として JS architecture テストの追加を実装方針に含めた。

## [Warning] 型境界が未定義 (§7)
- 判断: **対応する**
- 対応内容: Inertia props は最小化（試行 ID の初期値程度）、クライアント内ログは
  TypeScript の discriminated union (`pagehide` / `pageshow` / `guard-state` / `verdict`) で
  定義する方針を明記した。

## [Suggestion] persisted と context token の食い違いの扱いを定義せよ (§4)
- 判断: **対応する**
- 対応内容: 上記の真理値表に「観測矛盾」を第三の判定として組み込んだ。
  合格でも単なる無効でもなく**要調査**として扱う。

## [Suggestion] 試行 ID・各時刻・最終判定・無効理由を表示せよ (§4)
- 判断: **対応する**
- 対応内容: 画面表示項目とコピー用テキストの必須項目に加えた。

## [Suggestion] passkey / 経路 C を混ぜないスコープ判断は適切 (§6)
- 判断: 追加対応なし（現状維持）

---

## 反論の根拠となる実ファイル（参照して判定してください）

- `config/debug.php` — 防御三層の記述。第三層 = ProductionEnvGuard が production での
  DEBUG_LOGIN_* 残置を起動時 fail-fast させる
- `app/Http/Middleware/LocalOnly.php` — DEBUG_LOGIN_* 未設定時に 404 (fail-secure)
- `tests/js/architecture/logout-call-site-inventory.test.ts` — logout は Inertia visit 一本を
  deny-by-default で固定（Round 1 の §6 指摘が正しいことの裏付け。当初案を撤回しました）

## 特に見てほしい点

1. **有効試行の判定（真理値表）** — Round 1 の §3/§4 指摘への対応中に、当初案の
   context token 単独では真の bfcache 復元と経路 C を区別できないことに気づき、
   3 観測の組で判定する形に作り直しました。この真理値表に漏れや誤りはないか。
2. **専用 env フラグへの反論** — 既存三層 + より低い権限、という理由で不要と判断しました。
   この判断が妥当か。妥当でないなら、既存 /debug/login との統制の一貫性をどう保つか。
3. **full document navigation の制約** — Inertia visit では bfcache に入らないため
   plain anchor で離脱する設計にしました。他に見落としている前提はないか。

---

## 修正後の概念設計

# 概念設計: bfcache 実機受入確認の検証ページ (debug 限定)

> Round 1 レビュー反映済み。判断の根拠は
> `codex-history/conceptual-review-decisions-round-1.md`。

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

### 中核: 3 つの独立した観測

単一の指標に依存しない。互いに独立な 3 つを同時に表示し、食い違いも見えるようにする。

| # | 観測 | 何を示すか |
|---|---|---|
| 1 | `pagehide` / `pageshow` が発火したか | Document が凍結・復帰したか。**発火しなければ同一 Document 内の遷移** (= 経路 C であって bfcache ではない) |
| 2 | `pageshow` の `PageTransitionEvent.persisted` | ブラウザ自身の申告 |
| 3 | **JS 実行コンテキスト生存トークン** | script 評価時に一度だけ生成する値。復帰後も同じなら Document が再実行されていない |

3 は `docs/supported-browsers.md` L66 が経路 C の正のコントロールで使っている手法と同じで、
リポジトリ内に前例がある。

### 有効試行の判定

**3 つの観測は単独では足りない。**とくに 3 単独では、真の bfcache 復元と
Inertia の同一 Document 復元 (経路 C) を区別できない
— Inertia の client-side navigation では Document が破棄されないため
token も不変のままだからである。組で見て初めて判定できる。

| 実際に起きたこと | `pagehide`/`pageshow` | `persisted` | context token | 判定 |
|---|---|---|---|---|
| **真の bfcache 復元** | あり | `true` | 不変 | **有効試行** |
| 通常の再取得 (full reload) | あり | `false` | **変わる** | 無効 (空振り) |
| Inertia の同一 Document 復元 (経路 C) | **無し** | — | 不変 | 無効 (対象外の経路) |
| 上記のいずれにも当てはまらない組合せ | — | — | — | **観測矛盾 = 要調査** |

「観測矛盾」を合格にも単なる無効にも倒さないのが要点である
(例: `persisted=true` なのに token が変わっている、
`persisted=false` なのに token が不変、など)。
ブラウザ申告と実測が食い違っている状態を黙って捨てると、
まさに T085 が避けたい「実態を見ないまま記録する」に戻る。

判定結果と、無効の場合はその理由を画面に出す。

### guard の状態遷移をそのまま見せる

guard は状態を `documentElement` の `data-bfcache-hidden` 属性
(`pending` / `verifying` / `retry`) として持つ。
これを MutationObserver で監視し、時刻付きで遷移列を記録する。
`pending → verifying → (属性削除 | retry)` のどれを辿ったかが証跡になる。

### スクリーンショット 1 枚で足りる状態にする

同一画面に、環境情報と観測値・遷移列・判定をまとめて表示する。
T085 が手で書き写せと言っている項目が全部画面に出るので、
**撮影 1 枚が証跡**になり、書き写し誤りが原理的に消える。
テキストコピーも用意し、devnotes へ貼れる形にする。

必須表示項目:

- 試行 ID / 開始時刻 / 離脱時刻 / 復帰時刻
- UA / `display-mode` / `navigator.standalone` / OS バージョン (UA から読める範囲)
- 上表 3 観測の生値
- guard の状態遷移列 (時刻付き)
- **最終判定** (有効試行 / 無効 / 観測矛盾) と、無効・矛盾の場合はその理由

## 期待効果

- **使命への貢献**: 撮影 PWA (`/app/*`) の主要実行系は iOS Safari であり
  (`docs/supported-browsers.md` L54-57)、
  **現場作業者が撮影 PWA を使った後、ログアウト後の履歴復元で PII が露出しないこと**を
  確認するための検証支援である。使命への接続は撮影導線の安全性・信頼性に限定される
  (新機能ではない)
- **T085 の空振りを構造的に排除**する。Playwright レーンに課した規律を実機レーンにも揃える
- **再確認コストを下げる**。T085 は一度きりでなく変更のたびに回る。
  手順が「ページを開いて操作して 1 枚撮る」になれば実施され続ける
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

検証ページは guard を**外から観測するだけ**にする。

### サーバ通信を増やさない

観測値はすべてクライアント側で生成されるため、サーバから取る必要がない。

- **新規 JSON endpoint を作らない** (`response()->json()` 直書き禁止に触れない)
- サーバ → クライアントは **Inertia props のみ**。渡すのは試行 ID の初期値程度に最小化

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

#### 保存項目の allowlist（保存してよいものだけを列挙する）

ログは `/login` 遷移をまたいで残り、かつ devnotes に貼られる。
したがって保存可能な項目を allowlist で固定する。

**保存可**: 試行 ID / timestamp / event 種別 / `persisted` の真偽値 /
guard 属性の状態値 / context token の短縮ハッシュ / `display-mode` /
`navigator.standalone` の真偽値 / UA 文字列

**保存禁止**: 氏名・email などの実データ、**ダミー PII 文字列そのもの**、
URL の query string、cookie、プローブのレスポンス本文、
その他 allowlist に無い一切の値

### 経路の配置

| 項目 | 方針 |
|---|---|
| route | 既存の `isLocal() \|\| runningUnitTests()` ブロック内、`LocalOnly` グループに追加 |
| **`auth` 必須** | `NoStoreCacheHeadersForAuthenticatedPages` (`bootstrap/app.php:132`) を通す。**`no-store` が実際に付いた状態**でなければ「Safari は no-store でも格納する」の検証にならない |
| 画面 | Inertia ページ。既存 `resources/js/pages/Debug/Login.svelte` の idiom に合わせる |
| ダミー PII | 誰が見ても偽物と分かる固定文字列。証跡を devnotes に貼る以上、本物めいた個人情報を写り込ませない |

### 離脱は full document navigation でなければならない（中核制約）

**Inertia visit では bfcache に入らない。** client-side navigation は同一 Document のままなので
`pagehide` が起きず、戻る操作は popstate = 経路 C になる。これは検証したい経路ではない。

したがって検証ページからの離脱は **plain な `<a href>` による full document navigation** にする。

### 新しい logout 導線を作らない

`tests/js/architecture/logout-call-site-inventory.test.ts` が
**logout は Inertia visit (`router.post`) 一本**であることを deny-by-default で固定しており、
同一ファイル内の `fetch`/`axios` 併用を違反として検出する。
これは経路 C の保証 (`clearHistory: true` を含む Inertia page をクライアントが適用すること) が
その一本に乗っているためである。

よって **logout 導線は一切新設しない**。相方ページは既存 `AppLayout` を使い、
そこに元からあるユーザーメニューの logout（inventory 登録済みの既存 call site）で
ログアウトする。inventory への追記も発生しない。

### 検証シナリオの操作

1. `/debug/login` で任意ユーザーとしてログイン
2. 検証ページ A を開く（full document load。試行 ID と context token が確定する）
3. A の plain anchor で相方ページ B へ **full document navigation**（ここで A が bfcache に入る）
4. B の `AppLayout` ユーザーメニューから**通常のログアウト**（A は凍結されたまま）
5. **戻る**で A に復帰 → guard が発火するはず
6. A の判定表示を撮影する（`retry` / `/login` 送りに倒れた場合はログから復元して読む）

### `unload` / `beforeunload` を置かないことをテストで固定する

1 行入るだけで検証が恒久的に空振りになり、しかも**空振りは緑に見える**ため誰も気づかない。
`tests/js/architecture/` の deny-by-default テスト群（`logout-call-site-inventory` 等）に倣い、
検証ページ配下に `unload` / `beforeunload` リスナが登録されないことを固定するテストを追加する。

### 型境界

- Inertia props: 最小化。必要なら DTO を 1 つ置く
- クライアント内ログ: TypeScript の discriminated union
  (`pagehide` / `pageshow` / `guard-state` / `verdict`) で定義する

## 制約・前提

- **本番非到達**: route 登録ゲートと `LocalOnly` の二重防御に乗る。
  `config/debug.php` の注記どおり防御は三層で、第三層は `ProductionEnvGuard` が
  production での `DEBUG_LOGIN_*` 残置を起動時に fail-fast させる。
  また `LocalOnly` は `DEBUG_LOGIN_USER` / `DEBUG_LOGIN_PASSWORD` 未設定時に 404 に倒れるため、
  **明示的な env による opt-in が既に必須条件**になっている
- **露出面の増加を認識する**: `LocalOnly` の判定は `config('app.env')` であって接続元 IP ではないため、
  `APP_ENV=local` のまま HTTPS トンネル経由で実機から到達させられる。露出は Basic 認証が受ける。
  ただし**本ページの追加でトンネル運用時の露出面は増える**。運用規律として
  (1) トンネルは検証中のみ起動する (2) Basic 認証の資格情報を他と使い回さない
  (3) 検証後に停止する — を文書に残す
- **HTTPS がほぼ必須**: CCNS × bfcache の議論は HTTPS ページを前提にしており、
  PWA の standalone インストールも secure context を要求する。
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
  ただし「経路 C と取り違えていないこと」の判定は本ページの責務に含む（上記真理値表）
- **T085 の実施そのもの**。本設計は実施を可能にする設備であり、実機確認の実行は T085 の責務
- 検証対象 (guard / 秘匿 CSS / プローブ) への変更
- 新しい logout 導線
