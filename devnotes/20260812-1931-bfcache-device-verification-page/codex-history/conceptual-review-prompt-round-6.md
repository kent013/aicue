# Round 6 (確認のみ): Round 5 の承認条件 2 点の修正確認

Round 5 で示された承認条件 2 点のみを修正しました。他は変更していません。
本ラウンドは確認目的です。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 5

Round 5 の指摘は 2 件の Critical とも**自設計内の不整合**であり、全件受け入れた（反論なし）。

## [Critical] 手入力する端末情報が保存 allowlist に無い (§3)
- 判断: **対応する**
- 根拠: 指摘が正しい。端末モデルと確認済み OS バージョンを
  「試行開始時に手入力し stored report の必須表示項目にする」と定義しておきながら、
  allowlist には入れていなかった。失効セッション経路では `/login` を経由して
  A を再読込するため、保存しなければ stored report で復元できない。
  保存すれば allowlist 違反になる。**設計が自己矛盾していた。**
- 対応内容:
  - allowlist を「自動観測」と「利用者申告」に分け、
    後者に **`deviceModel` / `verifiedOsVersion`** を追加した
  - 自由記述の抜け道にしないため、**型付きフィールドとして最大長と使用可能文字を制限**し、
    「氏名等を入力しない」旨を入力欄に明示する
  - stored report 上で**「利用者申告」と明示**し、自動観測値と区別して表示する

## [Critical] append-only 節に撤回済みの記述が残存 (§7)
- 判断: **対応する**
- 根拠: 指摘が正しい。型境界の節では union から `TrialVerdict` / `GuardVerdict` を削除し
  「表示時に導出」へ統一したのに、append-only 節の
  「最終 verdict もイベントとして追記する」を消し忘れていた。直接矛盾していた。
- 対応内容: 当該行を削除し、append-only 節を
  「event log には観測事実のみを追記する。軸 1 / 軸 2 / 総合判定は保存せず、
  検証済みイベント列から表示時に純粋関数で導出する」に統一した。

## [Warning] `/login` 画像単独では試行 ID との対応を証明できない (§5)
- 判断: **対応する**
- 根拠: そのとおり。手動確認方式を採る以上この限界は受容するが、
  「画像から自動的に裏付けられた事実」と誤読されないようにする必要がある。
- 対応内容:
  - `RedirectObserved` に **`observationMethod: 'manual'` を固定値**で持たせる
  - stored report に **「manual confirmation」と明示**する
  - devnotes 上で `/login` 画像と試行 ID を同一証跡セットとして関連付ける
    （命名・貼付形式は詳細設計事項として送る）

## [Suggestion] 使命 (§1) / 禁止事項 (§2) / 期待効果 (§4) / スコープ (§6) / `RedirectObserved` 追加 (§7)
- 判断: 追加対応なし（現状維持）
- 備考: §4 の「2 枚の画像を devnotes 上で同一試行に関連付ける手順」と
  §7 の「runtime validator が余分なキーを拒否し手入力フィールドの長さも検証する」は
  詳細設計フェーズで詰める（Codex も承認阻害事項ではないと明記）。

---

## 確認してほしいこと

Round 5 の承認条件 2 点が閉じているか。閉じていれば APPROVED としてください。
残る事項が詳細設計フェーズのものだけであれば、その一覧も添えてください。

---

## 修正後の概念設計（全文）

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

`valid-bfcache` は次の **5 つすべて**を満たす場合に限る（機械判定に「原則」を残さない）。

1. `pagehide.persisted === true`
2. `pageshow.persisted === true`
3. 離脱前後の **full token** が同一（表示用の短縮値では比較しない）
4. 同一 `trialId` かつ同一の A route
5. 離脱イベントと復帰イベントの順序が正しい

| 判定 | 意味 |
|---|---|
| `valid-bfcache` | 上記 5 条件をすべて満たす |
| `invalid-not-bfcache` | `pageshow.persisted=false` かつ token が離脱前と異なる (= 通常の再取得。空振り) |
| `invalid-wrong-route` | **A が実際に再表示された**うえで、対応する lifecycle が無い (= 同一 Document の popstate。経路 C であって対象外) |
| `inconsistent` | 上記のいずれにも当てはまらない組合せ。**`pagehide.persisted` と `pageshow.persisted` の不一致を含む** |
| `incomplete` | 試行開始済みだが、判定可能な離脱・復帰の組がまだ揃っていない |

`incomplete` に落ちるのは、離脱したがまだ復帰していない / B やログイン画面で操作を中断した /
Safari が A の履歴項目を破棄した / `pagehide` の記録だけあって対応する `pageshow` が無い、
といった状態である。これらを `inconsistent` に混ぜると
**観測値の矛盾と単なる未完了が区別できなくなる**。

また「A 以外へ復帰した」ことは、**A の JS が動かなければ A 自身には判定できない**。
観測できない場合は `incomplete` のままにする。
**タイムアウトで自動的に失敗へ変換しない。** 利用者が「試行を中止」と確定する
（`TrialAborted`）。

`inconsistent` は合格にも単なる無効にも倒さず **要調査**として扱う。
ブラウザ申告と実測の食い違いを黙って捨てると、
まさに T085 が避けたい「実態を見ないまま記録する」に戻る。

#### 軸 2: guard 結果判定

| 判定 | 意味 | 確定に必要なもの |
|---|---|---|
| `authenticated-unhidden` | 秘匿 → 検証 → 秘匿解除。DOM は温存 | 自動観測のみ |
| `unauthenticated-redirected` | 秘匿を維持したまま `/login` へ | 自動観測 **+ 手動確認** |
| `retry-hidden` | プローブ失敗。秘匿維持 + 再試行ボタン | 自動観測のみ |
| `failed-transition` | 遷移列が期待形を外れた (例: `pending` のまま停止、秘匿解除が早すぎる) | 自動観測のみ |
| `not-observed` | guard の遷移が一度も観測されなかった | 自動観測のみ |

##### `unauthenticated-redirected` だけは自動判定できない

guard は `unauthenticated` のとき属性を `verifying` のまま
`location.replace(LOGIN_PATH)` を呼ぶ。したがって A から自動観測できるのは

```text
pageshow(persisted=true) → pending → verifying → 秘匿を維持したまま A から離脱
```

までであり、**離脱先が `/login` だったことは A には分からない**。
次の事象は保存ログ上で同じ形になる。

- guard が `/login` へリダイレクトした
- 利用者が検証中に別ページへ移動した
- 別のコードが navigation を発生させた
- タブが閉じられた / 試行が中断された

再ログイン後に stored report を開いても、
「その前に `/login` へ着地した」事実は**遡って観測できない**。

したがって:

- 自動観測だけで言えるのは **`hidden-then-left`**（秘匿を維持したまま A から離脱した）まで
- `unauthenticated-redirected` の確定には、利用者が `/login` 到達を確認して記録する
  **手入力イベント (`RedirectObserved`)** を必須とする
- **軸 2 を完全自動判定とは主張しない**

`/login` のスクリーンショット単独では試行 ID との対応を証明できない。
手動確認方式を採る以上この限界は受容するが、隠さない。

- `RedirectObserved` は `observationMethod: 'manual'` を固定値で持つ
- stored report 上に **「manual confirmation」と明示**する
- devnotes 上で `/login` 画像と試行 ID を同一証跡セットとして関連付ける
  （命名・貼付形式は詳細設計事項）

`/login` 側に debug 試行の観測を足せば証跡は強くなるが、
**auth 経路の production ページに debug 専用ロジックを持ち込む**ことになるため採らない
（思考原則 2）。guard 側に検証フックを設ける案は
「検証対象を変更しない」要件と正面から衝突するので採らない。

#### 軸 3: 総合判定

**試行成立が `valid-bfcache` であり、かつそのシナリオで期待した guard 結果に
一致した場合のみ `PASS`。**「有効試行」は `PASS` と同義ではない。

総合判定は**型として保持せず、軸 1 と軸 2 から純粋関数で導出する**
（二重保持による不整合を避ける）。

期待される guard 結果はシナリオによって変わるため、
ページ側は利用者の意図を推測できない。**試行開始時に検証シナリオを宣言させる**。

| シナリオ | 役割 | 期待する guard 結果 |
|---|---|---|
| **失効セッション経路**（ログアウト後の復元） | 本試行 | `unauthenticated-redirected` |
| **有効セッション経路**（ログイン維持のままの復元） | **正のコントロール** | `authenticated-unhidden` |

**2 つとも T085 完了の必須条件とする。** ログアウト後シナリオだけでは
「常に `/login` へ飛ばす壊れた guard」が PASS してしまう。
さらに親設計 (`bfcache-guard.ts` docblock) は
「hard reload は常用しない。撮影中の media stream・未送信フォーム・Inertia 履歴を
破棄してしまい、**撮影 PWA という使命に直撃する**ため。有効なら秘匿を外すだけにする」
と明記しており、`authenticated-unhidden` の確認は
**撮影導線を壊していないことの確認**そのものである。T085 の射程内であって、追加機能ではない。

#### `expectation-mismatch`

宣言したシナリオと観測結果が一致しない場合（例: 失効セッション経路を宣言したのに
`authenticated-unhidden` になった）、そこから分かるのは
**宣言した期待結果と観測結果が一致しない**ことだけである。
利用者のログアウトし忘れとは限らず、セッション失効判定やプローブの異常もありうる。

したがって `expectation-mismatch` を独立した分類として持ち、
**「操作不一致またはシステム異常のいずれかで、原因未確定」**と定義する。
総合判定は FAIL とするが、**原因を guard 故障と断定しない**。

#### `unrecordable`

証跡の保存に失敗した試行は、guard を live で観測できていても
`/login` 送りのあとに回収できない。
**保存成功を試行成立とは別の必須前提**として持ち、
保存に失敗した試行は `unrecordable` として明示する（下記「ログの保存」）。

### 1 試行の証跡セット

失効セッション経路では `/login` 到達を A の側から証明できないため、
**「スクリーンショット 1 枚」は主張しない**。証跡は次の組とする。

| 経路 | 証跡 |
|---|---|
| 失効セッション経路 | **`/login` 到達画面** + **stored report** の 2 枚 |
| 有効セッション経路 | live observation の 1 枚 |

必須表示項目:

- **live observation / stored report のどちらであるか**
- 試行 ID / 宣言したシナリオ / 開始時刻 / 離脱時刻 / 復帰時刻
- 自動観測: UA / **UA reported OS** / `display-mode` / `navigator.standalone`
- **手入力: 端末モデル / 確認済み OS バージョン**（試行開始時に入力する）
- 観測 1〜3 の生値
- **軸 1 / 軸 2 / 総合**の 3 判定と、無効・矛盾・不合格の場合はその理由
- stored report では元試行 ID・元の復帰時刻・保存完了時刻を区別して出す

#### 手入力値と自動観測値を区別する

UA から得られる OS 情報は、受入記録に必要な端末名・正確な OS バージョンと**一致しない**
（UA reduction / iPadOS の desktop-class UA / standalone と Safari の差）。
したがって:

- 自動取得値は **「UA reported OS」** と明記し、確定した OS バージョンとして扱わない
- **端末モデルと確認済み OS バージョンは手入力**とする
- **自動取得できない値を推測しない**
- 証跡上で手入力値と自動観測値を視覚的に区別する

テキストコピーも用意し、devnotes へ貼れる形にする。

## 期待効果

- **使命への貢献**: 撮影 PWA (`/app/*`) の主要実行系は iOS Safari であり
  (`docs/supported-browsers.md` L54-57)、
  **現場作業者が撮影 PWA を使った後、ログアウト後の履歴復元で PII が露出しないこと**を
  確認するための検証支援である。使命への接続は撮影導線の安全性・信頼性に限定される
  (新機能ではない)
- **T085 の空振りを構造的に排除**する。Playwright レーンに課した規律を実機レーンにも揃える
- **受入失敗を PASS と読む事故を防ぐ**（二軸判定）
- **イベント観測値の書き写しをなくし、環境情報の転記を減らす**。
  ただし端末モデルと確定 OS バージョンは手入力が残り、
  失効セッション経路では `/login` 到達の手動確認も残るため、
  「書き写しが原理的に消える」「1 枚で証明できる」とまでは言わない
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

guard 自身が復元マーカーに `sessionStorage` を使わない (タブ共有で誤検知するため) のは
guard の設計判断であり、**検証ページ側のログ用途とは目的が違う**ので競合しない。

#### 論理モデルは append-only event log

- **各イベントは immutable**。追記のみで、既存イベントを書き換えない
- **event log には観測事実のみを追記する。**
  軸 1 / 軸 2 / 総合判定は**保存せず**、検証済みイベント列から
  表示時に純粋関数で導出する
- sessionStorage への物理的な配列再保存は実装詳細として許容するが、
  論理モデルは append-only である

（「試行 ID ごとの immutable record に追記する」は用語矛盾なので採らない。）

#### 保存失敗を検証不能として扱う

Safari の容量制限・private browsing・壊れた既存値・`setItem()` の例外により
保存が失敗しうる。そのとき画面内では guard を観測できても、
`/login` 送りのあとに証跡を回収できない。

- **保存成功を試行成立とは別の必須前提**として持つ
- **試行開始時に保存テストを行い、失敗したら離脱させず live 画面で `unrecordable` を表示する**
  （そもそも試行を始めさせない）
- 試行途中の保存失敗は可能な範囲で live 表示するが、**再表示後の回収は保証しない**
- storage 書き込みは例外を捕捉し、**黙って live 表示だけで続行しない**
- `StorageFailed` は「**保存できれば記録する診断イベント**」であり、
  **保存不能の永続証拠ではない**（storage が壊れていれば `StorageFailed` 自身も残らない）
- 書き込み後の read-back validation の要否は詳細設計で決める

#### 保存項目の allowlist（保存してよいものだけを列挙する）

ログは `/login` 遷移をまたいで残り、かつ devnotes に貼られる。

**保存可（自動観測）**: 試行 ID / シナリオ種別 / `schemaVersion` / `sequence` / timestamp /
event 種別 / `pagehide.persisted` / `pageshow.persisted` / guard 属性の状態値 /
context token / `display-mode` / `navigator.standalone` の真偽値 / UA 文字列 /
`RedirectObserved` の `observationMethod`（`'manual'` 固定値）

**保存可（利用者申告）**: `deviceModel` / `verifiedOsVersion`

失効セッション経路では `/login` を経由して A を再読込するため、
手入力した端末情報も保存しなければ stored report で復元できない。
ただし自由記述の抜け道にしないため、**型付きフィールドとして最大長と使用可能文字を制限**し、
「氏名等を入力しない」旨を入力欄に明示する。
保存された値は stored report 上で**「利用者申告」と明示**し、自動観測値と区別して表示する。

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

2 シナリオとも実施する（両方が T085 完了の必須条件）。

**シナリオ 1: ログアウト後の復元（本試行）**

1. `/debug/login` で任意ユーザーとしてログイン
2. 検証ページ A を開く（stored report モードで開く。まだ記録しない）
3. **端末モデルと OS バージョンを手入力し、シナリオを宣言して新規試行を開始**
   （試行 ID と context token が確定する。保存に失敗したら `unrecordable`）
4. A の plain anchor で相方ページ B へ **full document navigation**（ここで A が bfcache に入る）
5. B の `AppLayout` ユーザーメニューから**通常のログアウト**（A は凍結されたまま）
6. **履歴上で A を選択して復帰**する。B での logout は Inertia が履歴を積むため
   「戻る 1 回」では A に戻らない。iOS Safari と standalone それぞれで
   必要な操作は詳細設計で実機手順として固定する
7. **`/login` へ着地したことを確認し、その画面を撮影する**（証跡 1 枚目）
8. `/debug/login` で入り直し、A を **stored report** モードで開く
9. **`/login` 到達を確認した旨を記録する**（`RedirectObserved` の手入力）
10. stored report を撮影する（証跡 2 枚目）

期待: 軸 1 = `valid-bfcache` / 軸 2 = `unauthenticated-redirected` / 総合 = `PASS`

（手順 7・9 が必要なのは、A が離脱先を観測できないためである。詳細は軸 2 の節を参照。）

**シナリオ 2: ログイン維持のままの復元（正のコントロール）**

上記の 5 を飛ばす（ログアウトしない）。B から**履歴上で A を選択して復帰**する。

期待: 軸 1 = `valid-bfcache` / 軸 2 = `authenticated-unhidden` / 総合 = `PASS`

秘匿が解除されるだけで、DOM・フォーム状態が温存されていることを目視でも確認する
（撮影導線を壊していないことの確認）。

#### T085 の完了条件は「試行の組」

個々の試行ではなく、次の組が揃って初めて T085 完了とする。

```text
同一の端末・OS・実行モード（standalone / Safari）
├─ 失効セッション経路: PASS
└─ 有効セッション経路: PASS
```

**片方だけ PASS した場合は T085 全体を未完了**とする。
組の識別子を試行 ID と別に持つかは詳細設計事項。

A と B は**同一試行 ID で関連付ける**。A が再表示されたうえで lifecycle が伴わなければ
`invalid-wrong-route`、そもそも A に戻れていなければ `incomplete`。

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

```ts
type ProbeEvent =
    | TrialStarted
    | PageHide
    | PageShow
    | GuardStateChanged
    | RedirectObserved   // 利用者が /login 到達を確認して記録する手入力イベント
    | StorageFailed
    | TrialAborted;
```

- 共通フィールド: `schemaVersion` / `trialId` / `sequence` / `timestamp`
- イベント種別ごとに許可フィールドを固定する
- **event log には観測事実だけを保存する。** 派生結果は保存しない
- **軸 1 / 軸 2 / 総合の 3 判定はすべて表示時に純粋関数で導出する。**
  verdict をイベントとして保存すると、後から `RedirectObserved` が追記された時点で
  確実に stale になる（そして本設計では実際に追記される）

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
