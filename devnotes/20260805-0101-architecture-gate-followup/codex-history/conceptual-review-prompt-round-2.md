Round 1 の指摘への対応が終わりました。対応マトリクスと、修正後の概念設計全文を送ります。

**Critical 1 件については反論しています。** 反論の根拠は実ファイル (config/seo.php,
app/Support/Seo/SeoMeta.php, app/Support/Seo/SeoRenderer.php,
app/Http/Controllers/Marketing/PricingController.php) で検証可能です。
必要なら読んで確認してください。反論が成立していないと判断する場合は、
その理由を具体的に示してください。

再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

---

# 対応マトリクス: conceptual-review Round 1

## [Critical] `<meta name="description">` 禁止の根拠不足 (description の backend SoT が示されていない)

- 判断: **反論する (+ 指摘の建設的部分は設計に取り込む)**
- 根拠: description には **既にサーバ単一 SoT が存在する**。実ファイルで確認済み:
  - 設定ソース: `config/seo.php` の `default_description`
    (`env('SEO_DEFAULT_DESCRIPTION', '')`、`.env.example:137` に枠あり)
  - DTO: `app/Support/Seo/SeoMeta.php` の `public string $description` +
    `withDescription()`
  - 公開ページ描画: `app/Support/Seo/SeoRenderer.php::render()` が
    `<meta name="description">` / `og:description` / `twitter:description` を描画
  - 認証配下: `renderPrivate()` は description を**意図的に出さない** (noindex ページに
    メタを残さない)
  そして `resources/js/pages/` には公開ページの実体が含まれる:
  - `Welcome.svelte` ← `HomeController` (`home`, full 分類)
  - `Guest/Pricing.svelte` ← `PricingController` (`pricing`, full 分類) が
    **`->withDescription('AI-CUE の料金プラン…')` を実際に供給している** (L62)
  よってここで `<svelte:head><meta name="description">` を書くと、同一 `<head>` に
  description が **2 個並ぶ** (クローラから見た明確な defect) 上、サーバ側にしかない
  `og:description` / `twitter:description` と食い違い、**SNS カードと検索結果の説明文が
  別物になる**。認証配下でも「noindex なのに description だけ生える」不整合が復活する。
  つまり description を含めるのは「守るべき契約を先に増やす」のではなく、
  **既にサーバ側にある契約を破らせないための同一の禁止**である。
- 対応内容: 指摘の建設的部分 (「backend の description 解決経路・設定ソース・
  共有経路を設計に書け」) は正当なので、概念設計 §課題 4 に
  「`<meta name="description">` も同じ gate で禁止する根拠 (サーバ SoT の所在)」
  節を新設し、上記の対比表と具体ファイル/行を明記した。
  併せてスコープが膨らまないよう **`og:description` / `twitter:description` は
  今回対象外**と明示した (「今必要なものだけ作る」)。

## [Warning] `AGENTS.md` 追記案「`*NoOverflow` 必須」が `*WithOverflow` 明示許可と衝突

- 判断: **対応する**
- 根拠: 指摘のとおり。規約文と gate の契約がずれると、規約を読んだ人が
  `*WithOverflow` を「規約違反だが gate は通る」と誤解する (最悪の状態)。
- 対応内容: 概念設計 §実装方針 1 の追記文面を
  「月/年/四半期の加減算は**暗黙 overflow メソッドを禁止**する。既定は `*NoOverflow`、
  overflow が要件なら `*WithOverflow` を明示して意図をコードに残す」へ変更し、
  「必須」と書くと衝突する理由も併記した。

## [Warning] `setPrivateTitle` をメソッド本体固定にすると helper 抽象化で偽陽性が増える

- 判断: **対応する**
- 根拠: responder 化 / private helper への抽出は Laravel で十分ありうる。
  ただし無制限追跡は gate を小さな静的解析器に肥大化させ、逆に精度も落ちる。
  指摘の「追跡しないなら allowlist 条件を仕様として先に固定せよ」が本質。
- 対応内容: 射程を **1 hop (同一クラスの private/protected メソッド)** に仕様固定し、
  それ以上の間接化 (trait / 基底クラス / 別クラス responder) は
  「静的に決定できない」として**理由付き allowlist を要求する**と概念設計に明記した
  (`INERTIA_DYNAMIC_ALLOWLIST` と同じ deny-by-default の考え方)。

## [Warning] route 名単位 gate の説明に `Invitations/Invalid` の分岐タイトル話が混ざり責務境界が曖昧

- 判断: **対応する**
- 根拠: 指摘のとおり。gate の射程を誤解させる書き方だった。
- 対応内容: 概念設計 §実装方針 3 に「責務境界: gate が守る範囲と、手動是正の範囲」
  節を新設し、表で「route 既定タイトル = gate が強制」「分岐ごとのタイトル =
  gate 対象外」と分離。`Invitations/Invalid` は **施策 3b (gate なし・手動是正)** として
  施策一覧の表にも独立行で切り出した。分岐タイトルの機械強制は follow-up 議題と明記。

## [Suggestion] `git ls-files` 走査は未追跡ファイルを拾わない点を注記すべき

- 判断: **対応する**
- 根拠: 開発時の体感 (「add したら急に赤くなる」) を説明しておく価値がある。
- 対応内容: §実装方針 2 に「git 追跡ベースの既知の限界」を追記。
  gate が守るべき境界は commit / CI であり実効性は損なわれないこと、
  テスト冒頭コメントに明記することを設計に含めた。
  併せて gate 3 は `Route::getRoutes()` 走査なのでこの限界を持たない旨も明記。

## [Suggestion] `Route` action 解決結果とトークン走査結果の型を小さな DTO 風に固めるとよい

- 判断: **対応する (ただし DTO クラスは作らない形で)**
- 根拠: 型を先に固める意図には全面的に賛成。ただし `InertiaRenderPageExistsInvariantTest`
  の既存作法は「テストファイル内の純関数 + PHPDoc array shape」であり、
  テスト専用 DTO クラスを `app/` に増やすのは本アプリの規約 (本番コードでない型を
  `app/` に置かない) と逆行する。
- 対応内容: §制約 に「走査結果の型」を追記。array shape
  (`array{page: string, location: string}` 等) を**先に決めてから実装する**ことを
  詳細設計で固定すると明記した。

## [Suggestion] その他 (使命整合・実現可能性・スコープ・型安全性)

- 判断: 見送る (肯定的評価のため対応不要)


---

## 修正後の概念設計 (全文)

# 概念設計: architecture-gate-followup (c2c 台帳 4 件の統合バッチ)

## 背景・課題

c2c 機能台帳の追従タスク 4 件 —
`gate-carbon-overflow` / `gate-no-non-compound-global-use` / `gate-document-title-coverage` /
`page-title-frontend-contract` — はいずれも **テンプレート (laravel-claude-template) 由来の
deny-by-default 静的走査 gate** の追従である。4 件とも

- 実行時 (DB/HTTP) に依存しない **純粋な静的走査**
- 既存 `tests/Architecture/` (43 本) と `tests/js/architecture/` (11 本) の走査基盤・
  allowlist 規約の**再利用**
- 「今すぐ壊れている」ではなく「**壊れたときに気づけない**」種類の欠陥の予防

という同じ形をしており、**1 バッチに統合したほうが走査基盤・規約・レビュー観点を共有できる**。

### 4 件それぞれの課題

#### 1. Carbon の月末オーバーフロー (`gate-carbon-overflow`)

Carbon 3.13.0 の `addMonth()` / `subMonth()` / `addYear()` / `addQuarter()` は
**既定でオーバーフローする** (実測):

```
2026-01-31 ->addMonth()            => 2026-03-03   ← 2 月が無い分だけ 3 月へ溢れる
2026-01-31 ->addMonthNoOverflow()  => 2026-02-28
2026-03-31 ->subMonth()            => 2026-03-03
2024-02-29 ->addYear()             => 2025-03-01
```

課金ドメイン (`current_period_end` / チケット有効期限 / signup grant マーカー) は
**月末起点の日付を扱う可能性が構造的にある**。1 日ずれた期日は「請求周期が 1 日ずれる」
「リマインダが 1 日早い/遅い」という形で顕在化し、しかも**月末月にしか再現しない**ため
テストでも本番でも極めて発見が遅れる。

現状 `app/` 本体には 0 件 (= 実害はまだ無い)。だが `database/seeders/` に 2 件、
`tests/Feature/Billing/` に 6 件の合計 **8 件**の非 NoOverflow 呼び出しが既に存在し、
このまま放置すると「テストがそう書いているから本体もそう書く」という**規約の逆流**が起きる。

#### 2. 非複合 global `use` (`gate-no-non-compound-global-use`)

namespace 宣言の無い PHP ファイルで `use RuntimeException;` のような
**非複合名 (バックスラッシュを含まない単一名) の use** を書くと、PHP は
`Warning: The use statement with non-compound name 'RuntimeException' has no effect`
を出す。しかも `use` 自体は**何の効果も持たない** = そのファイル内の `RuntimeException`
参照は global にフォールバックしていた (今回は偶然 global なので動いていた)。

実違反 2 件 (いずれも `database/migrations/`、実測で warning 発生を確認済み):

- `2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php:7`
- `2026_07_17_000610_create_ticket_auto_recharge_attempts_table.php:9`

**危険度の測定結果 (台帳の前提を実測で更新した)**:

台帳には「この警告はコンパイル時に出るため `set_error_handler` を経由しない」と
記録されていたが、本 devcontainer で実測したところ **経路は環境依存で両方起きる**:

| 実測 | 結果 |
|---|---|
| `php -r 'set_error_handler(...); require <migration の実パス>;'` | handler 不発火・raw warning が stderr へ |
| 同じ内容のファイルを別パスへコピーして require | **handler が発火** |

そして Laravel 12 の `HandleExceptions::handleError` は

```php
} elseif (error_reporting() & $level) {
    throw new ErrorException($message, 0, $level, $file, $line);
}
```

であり、本アプリの実行時 `error_reporting()` は **-1 (全部)** (実測)。
つまり **handler に届いた場合、この warning は `ErrorException` として throw される**。
migration は Migrator が実行時に `require` するため、そこで throw されれば
`RefreshDatabase` のマイグレーションが死に、**全テストが全滅する** (aigenba の実事故と同型)。

したがって位置づけは「今すぐ CI が赤くなる種類ではない」ではなく、
**「今日たまたま raw output 汚染で済んでいるが、PHP バージョン / opcache 状態 /
ハンドラ導入タイミングが変われば全テスト全滅へ化ける、非決定的な地雷」**である。
予防価値は台帳の記述より高い。

namespace 無しの PHP ファイルは repo 全体で **482 本** (うち `database/migrations/` が 60 本、
`tests/` が大半、`config/` 35 本) あり、人手レビューで抑え続けるのは非現実的。

#### 3. ページタイトル網羅 (`gate-document-title-coverage`)

本アプリのタイトル解決は `SeoManager::resolveDocumentTitle()` に一本化されており
(Blade `<title>` と Inertia 共有 prop `title` の両方がここを読む)、
route に固有名が無いページは **サイト名だけの `AI-CUE`** になる。
「複数タブを開いたとき全部同じタイトル」は静かな UX 劣化で、テストでは落ちない。

実測 (route:list × controller メソッド単位の Inertia 判定) で
**Inertia を render する GET named route のうち固有名が無いもの**:

| route | 現状 | 判定 |
|---|---|---|
| `billing.plans` | 固有名なし | 要追加 |
| `onboarding.checkout` | 固有名なし | 要追加 |
| `onboarding.billing-required` | 固有名なし | 要追加 |
| `capture.manuals.index` | 固有名なし (`show` だけ `setPrivateTitle`) | 要追加 |
| `debug.login` | 固有名なし | local/test 専用 → 理由付き allowlist |

いずれも**課金導線と撮影 PWA の入口**であり、放置してよい画面ではない
(`onboarding.checkout` / `onboarding.billing-required` は課金ゲートの着地先 =
「行き先のない詰みを作らない」規約の当事者)。

なお台帳の「不足 5 件」に挙がっていた `Invitations/Invalid` は **route 名ではなく
ページコンポーネント名**であり、`config/seo.php` は route 名でキーを引くため
そのままでは登録できない (§実装方針 3 で扱いを定義する)。

#### 4. `<svelte:head>` による二重 SoT (`page-title-frontend-contract`)

`resources/js/lib/document-title.ts` が明文で

> クライアント側に第二の title SoT (`<svelte:head>` 等) を作らない。

と宣言しているが、これを**強制する機構が無い**。現在 `resources/js/pages/` に
`<svelte:head>` は **0 件** (実測) なので、純粋な予防 gate である。

Svelte/Inertia の一般的な作法は `<svelte:head><title>` を書くことなので、
外部からのコード移植や新規実装で**極めて混入しやすい**。混入すると
サーバ描画 `<title>` とクライアント上書きが競合し、フルロード時と SPA 遷移時で
タイトルが食い違う (再現条件が遷移経路依存 = デバッグが難しい)。

##### `<meta name="description">` も同じ gate で禁止する根拠 (サーバ SoT の所在)

title と同様に **description もサーバ単一 SoT が既に存在する**。経路は title と別だが
同じ構造で完結しており、クライアント側の第二 SoT を許すと同じ破綻をする:

| 層 | title | description |
|---|---|---|
| 設定ソース | `config('seo.site_name')` / `title_separator` / `app_titles` / `minimal_titles` | `config('seo.default_description')` (`SEO_DEFAULT_DESCRIPTION`) |
| DTO | `SeoMeta::$title` (`withTitle` = `SeoTitle::compose`) | `SeoMeta::$description` (`withDescription`) |
| 公開ページ描画 | `SeoRenderer::render()` の `<title>` | `SeoRenderer::render()` の `<meta name="description">` + `og:description` + `twitter:description` |
| 認証配下描画 | `SeoRenderer::renderPrivate()` の `<title>` | **意図的に出さない** (noindex ページにメタを残さない) |

そして `resources/js/pages/` には**公開ページの実体が含まれる**:

- `Welcome.svelte` ← `HomeController` (`home`, full 分類。`SeoMeta::default` を供給)
- `Guest/Pricing.svelte` ← `PricingController` (`pricing`, full 分類。
  **`->withDescription('AI-CUE の料金プラン…')` を実際に供給している**)

つまりここで `<svelte:head><meta name="description">` を書くと、
サーバが既に出している `<meta name="description">` と**同一 `<head>` に 2 個並ぶ**。
description の重複タグはクローラにとって明確な defect であり、
しかも `og:description` / `twitter:description` (サーバ側のみ) と食い違うため
**SNS カードと検索結果の説明文が別物になる**。

認証配下ページ側でも害がある: `renderPrivate()` は noindex ページに description を
**出さないと決めている**のに、クライアントから注入すると
「noindex なのに description だけ生えている」不整合が静かに復活する。

したがって description を含めるのは「守るべき契約を先に増やす」のではなく、
**既にサーバ側にある契約を破らせないための同一の禁止**である。
なお `og:description` / `twitter:description` は現状クライアントから書かれる懸念が薄く、
検出集合を広げすぎない (「今必要なものだけ作る」) ため今回は対象外とする。

---

## 改善アイデア

**4 本の deny-by-default 静的走査 gate を新設し、検出された既存違反をその場で潰す。**

| # | gate | 実体 | 既存違反 | 性格 |
|---|---|---|---|---|
| 1 | Carbon overflow | `tests/Architecture/CarbonOverflowArithmeticGateTest.php` | 8 件 | 予防 (本体 0 件) |
| 2 | 非複合 global use | `tests/Architecture/NoNonCompoundGlobalUseTest.php` | 2 件 | 是正 + 予防 |
| 3 | ページタイトル網羅 | `tests/Architecture/DocumentTitleCoverageTest.php` | 4 件 + allowlist 1 | 是正 + 予防 |
| 3b | 招待無効分岐のタイトル | (gate なし・手動是正) | 1 件 | 是正のみ |
| 4 | svelte:head 二重 SoT 禁止 | `tests/js/architecture/svelte-head-no-title.test.ts` | 0 件 | 予防 |

3b だけは gate を持たない。理由は §実装方針 3「責務境界」に記す。

4 本すべてに以下の**共通規約**を課す (既存 Architecture テストの作法に揃える):

1. **deny-by-default**: 未知は fail。逃がすには「理由コメント付き allowlist 登録」が必須
2. **空振り検知**: 検査件数が 0 なら fail (走査基盤が壊れて全 green になる退行を落とす)
3. **負/正のコントロール内蔵**: 実ファイルを書き換えず fixture 文字列に対して
   検出器が点灯すること・誤検出しないことを同一テストで固定する
4. **DB 非依存**: `tests/Architecture` は `RefreshDatabase` を使わない
   (`tests/Pest.php` で `Feature`/`Unit` にのみ適用済み) 前提を維持する
5. **stale 検知**: allowlist の各エントリが今も実在の対象を指していることを逆方向に検査する

---

## 期待効果

### 使命への貢献

AI-CUE の使命は「専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れる」こと。
本バッチはその**実行基盤の静かな腐敗**を止める:

- **課金導線が詰まない** (#3): `onboarding.checkout` / `onboarding.billing-required` は
  「契約するために未契約組織が到達しなければならない導線」(AGENTS.md ドメイン規約 4)。
  タブ名が全部 `AI-CUE` では複数タブ運用の現場担当者が導線を見失う
- **撮影 PWA の識別性** (#3): `capture.manuals.index` は PWA の実質エントリ。
  スマホで複数タブ/ホーム画面から戻る現場ユースケースでタイトルは唯一の識別子
- **請求周期のずれを構造的に防ぐ** (#1): 月末契約組織の `current_period_end` が
  1 日ずれる事故は、ユーザーから見れば「勝手に請求日が変わった」= 信用毀損

### 開発基盤への貢献

- **全テスト全滅の芽を摘む** (#2): migration compile 時 warning → `ErrorException` の
  非決定的経路を構造的に閉じる
- **タイトル SoT の一本化を機械保証** (#3 + #4): サーバ単一 SoT
  (`SeoManager::resolveDocumentTitle`) という設計判断が、コメントではなく
  **テストで守られる**状態になる
- **規約の逆流を止める** (#1): テストコードの書き方が本体実装の手本になる現象を、
  `tests/` も走査対象に含めることで断つ

### 定量

- 新規 Architecture テスト 3 本 + JS architecture テスト 1 本
- 既存違反の是正: Carbon 8 箇所 / 非複合 use 2 箇所 / タイトル 4 route
- ドキュメント: `AGENTS.md` 実装規約 1 行 / `docs/template-divergence.md` 1 エントリ

---

## 実装方針（概要）

### 1. Carbon overflow gate

- **走査対象**: `app/` `database/` `tests/` の `.php`
- **検出方式**: `PhpToken::tokenize` で
  `T_OBJECT_OPERATOR|T_NULLSAFE_OBJECT_OPERATOR` の直後に来る `T_STRING` が
  deny 集合に**完全一致**するものを違反とする。
  deny 集合 = `addMonth` `addMonths` `subMonth` `subMonths` `addYear` `addYears`
  `subYear` `subYears` `addQuarter` `addQuarters` `subQuarter` `subQuarters` (12 個)
- **完全一致にする理由**: `addMonthNoOverflow` / `addMonthsWithOverflow` を
  前方一致で巻き込まないため。`*WithOverflow` は**意図の明示**なので許可する
  (呼び出し側でオーバーフローを選んだことがコードに書かれている状態が望ましい)
- **PhpToken を使う理由**: コメント・文字列リテラル中の `->addMonth()` という記述を
  誤検出しないこと (regex では不可能)。これは負のコントロールテストで固定する
- **既存 8 件を `*NoOverflow` へ置換**。8 件すべて「遠い未来の失効日」「遠い過去のマーカー」
  「同一式で計算した値同士の突き合わせ」であり、意味的に日付ずれの影響を受けない
  ことを個別に確認済み (詳細設計に根拠を書く)
- **`AGENTS.md` 実装規約に 1 行追記**: 文面は gate の契約と 1:1 に揃える —
  「月/年/四半期の加減算は**暗黙 overflow メソッドを禁止**する。既定は `*NoOverflow`、
  overflow が要件なら `*WithOverflow` を明示して意図をコードに残す」
  (「`*NoOverflow` 必須」と書くと `*WithOverflow` 許可と規約が衝突するため)

### 2. 非複合 global use gate

- **走査対象**: `git ls-files '*.php'` から `.blade.php` を除いたもの。
  git 管理下に限定することで `vendor/` `node_modules/` `.claude/worktrees/`
  `storage/` を**自動的に**除外できる (明示 exclude リストの保守が要らない)。
  git 不在は silent skip せず fail させる
  (`tests/js/architecture/pages-path-case-invariant.test.ts` の先例に揃える)
- **git 追跡ベースの既知の限界**: 未追跡 (untracked) の新規ファイルは走査されない。
  つまり「ファイルを書いた直後・`git add` 前」のローカル実行では検出されない。
  gate が守るべき境界は **commit / CI** であり、そこでは必ず追跡下にあるので実効性は
  損なわれないが、開発中の体感として「add したら急に赤くなる」ことがある点は
  テスト冒頭のコメントに明記する
- **検出方式**: `PhpToken` で
  (a) `T_NAMESPACE` が現れるファイルは対象外
  (b) brace depth を追跡し **depth 0 の `T_USE`** のみを見る
      (クラス本体 `{}` 内の trait use を誤検知しない)
  (c) `use` の直後が `T_STRING` 単独 (= `T_NAME_QUALIFIED` でも
      `T_NAME_FULLY_QUALIFIED` でもない) で、`function` / `const` 修飾でもないものを違反
- **`use A, B;` / `use A\B\{C, D};` の扱い**: グループ use は必ず `\` を含むので
  非複合になりえない。カンマ区切りは各要素を評価する
- **クロージャの `use ($x)`** は直後が `(` なので (c) の条件で自然に外れる
- **既存 2 件を是正**: `use RuntimeException;` を削除し参照側を `\RuntimeException` へ
- **allowlist は設けない** (非複合 use に正当な用途が存在しないため。
  必要になったら理由付きで足す = deny-by-default の意図)

### 3. ページタイトル網羅 gate

- **候補抽出**: `Route::getRoutes()` から
  `GET` を含み・named で・パッケージ内部 (`filament.*` / `livewire.*` / `passport.*` /
  `mcp.*` / vendor 定義) でないものを列挙
- **Inertia 判定**: route の action (`Class@method` / `__invoke`) を解決し、
  `InertiaRenderPageExistsInvariantTest` と同じ `PhpToken` 抽出基盤で
  **当該メソッド本体に限定して** `Inertia::render` / `inertia(` の有無を判定する。
  **メソッド粒度が必須**である根拠 (実測):
  - `ConfirmRecentAuthController` はファイル単位では Inertia を含むが
    `status` は `JsonResponse` を返す → ファイル粒度だと誤検出
  - `CaptureManualController` はファイル単位では `setPrivateTitle` を含むが
    `index` は呼んでいない → ファイル粒度だと**取りこぼす**(本命の 1 件)
- **カバレッジ判定**: 以下のいずれかを持てば OK
  1. `config('seo.route_classification.full')` に含まれる
  2. `config('seo.minimal_titles')` にキーがある
  3. `config('seo.app_titles')` にキーがある
  4. 当該 controller メソッド本体に `setPrivateTitle` 呼び出しがある、または
     そのメソッドが呼ぶ**同一クラスの private/protected メソッド (1 hop)** の本体にある
- **`setPrivateTitle` 検出の射程を 1 hop に固定する理由**: helper 抽象化 (responder /
  trait / 基底クラス) へ将来リファクタされたときに gate が偽陽性を出す、という指摘への回答。
  無制限に追跡すると gate が「小さな静的解析器」に肥大化し、逆に検出精度も落ちる。
  **1 hop = 同一クラス内の素直な抽出まで**を許容し、それ以上の間接化 (trait / 基底 /
  別クラス responder) は「静的に決定できない」として **理由付き allowlist を要求する**
  (`INERTIA_DYNAMIC_ALLOWLIST` と同じ deny-by-default の考え方)。
  この境界を**仕様として先に固定する**ことで、実装時の判断ブレを無くす
- **action 解決不能 (vendor controller / Closure)** は「静的にページ名を決められない」
  のと同じ扱いで **理由付き allowlist 必須** (deny-by-default)。
  Fortify の `login` / `register` 等は既に `app_titles` にあるため実質は
  `capture.csrf-cookie` (204 を返す Closure) 等の少数
- **`config/seo.php` へ 4 件追加**: `billing.plans` / `onboarding.checkout` /
  `onboarding.billing-required` / `capture.manuals.index`
- **`debug.login` は allowlist**: `routes/web.php` が `isLocal() || runningUnitTests()`
  で囲む local/test 専用 route。本番に存在しないため固有名を持たせる価値がない
- **走査対象は git 追跡ファイルではなく `Route::getRoutes()`** なので、
  未追跡ファイルの取りこぼしは起きない (gate 2 との違い)

##### 責務境界: gate が守る範囲と、手動是正の範囲

本 gate の責務は **「route 既定タイトルの網羅」に限定する**。混同を避けるため明記する:

| 対象 | gate が強制するか | 本バッチでの扱い |
|---|---|---|
| Inertia を render する GET named route が固有名を持つ | **する** (deny-by-default) | 施策 3 |
| 1 つの route が分岐で複数ページを出すとき、分岐ごとに固有名を持つ | **しない** (route 粒度の gate の射程外) | 施策 3b (手動是正) |

`Invitations/Invalid` は後者。route 名 (`invitations.accept`) は既に
`app_titles` に `'組織への招待'` を持つため **gate は通る**。だが実際には
同一 route が有効/無効で 2 ページを出し分けており、無効時に「組織への招待」と
表示されるのは誤誘導に近い。よって

- **gate 対象外の手動是正**として独立施策 (3b) に切り出す
- 対応は `config/seo.php` ではなく **controller の無効分岐で `setPrivateTitle` を呼ぶ**
  (config = route 既定値、controller = 分岐ごとの上書き、という既存の責務分担どおり。
  config は route 名でキーを引くのでページコンポーネント名は登録できない)
- 「分岐ごとのタイトル網羅」を機械強制するのは follow-up 議題とし、本バッチでは扱わない

### 4. `<svelte:head>` 二重 SoT 禁止 gate

- **走査対象**: `resources/js/pages/**/*.svelte`
- **検出方式**: `<svelte:head>` ブロックを抽出し、その中の
  `<title>` / `<meta name="description">` を違反とする。
  `pages-path-case-invariant.test.ts` の作法 (再帰 readdir + 純関数の検出器 +
  fixture による負/正コントロール) を踏襲する
- **`<svelte:head>` 自体は禁止しない**: preload hint 等の正当な用途があるため、
  禁止するのは title/description という **SoT が競合する 2 要素**に限定する
- **`docs/template-divergence.md` に逸脱エントリ (D11) を登録**:
  テンプレートは「helper 経由必須の JS 契約」を採るが、aicue は
  **サーバ単一 SoT (`SeoManager::resolveDocumentTitle`)** 形を採り JS helper 契約を不採用。
  揃えている不変条件は「タイトルの SoT はただ 1 つで、フルロードと SPA 遷移で一致する」

---

## 制約・前提

### 環境 (重要)

- この devcontainer に **PostgreSQL が無い** (`DB_HOST=db` は未起動の compose サービス)。
  `composer test` の Feature レーンは実行できない。
  `scripts/run-test.sh` は起動時に ensure-test-db で fail するため、
  **本バッチの検証は `bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/...`
  で行う** (実測で green 確認済み。グローバルロックは経由する = T099 の規約を破らない)
- `tests/Architecture` は `tests/Pest.php` で `RefreshDatabase` の適用対象外
  (`->in('Feature', 'Unit')` のみ)。新設 3 本もこの前提を維持し DB を掴まない
- 既存の `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が
  12 errors で落ちるのは DB 不在由来の**既存事象**で、本バッチとは無関係
- JS 側は `pnpm test` (= `scripts/run-vitest.sh`) がそのまま使える (DB 非依存)

### 既存アーキテクチャとの整合

- `SeoManager` / `SeoComposer` / `HandleInertiaRequests` の title 解決経路は**変更しない**。
  #3 は `config/seo.php` のデータ追加と controller の `setPrivateTitle` 追加のみ
- `NestedRouteIdorDefenseTest` の inventory / `ManageRouteAuthGuardTest` の
  空振り検知 (`expect($checked)->toBeGreaterThan(0)`) と同じ規約を踏襲する
- Carbon 置換は**テストとシーダのみ** (`app/` 本体は 0 件) なので本番挙動に影響しない

### 禁止事項との関係

- PHPStan の widen / baseline 化は行わない。新設テストは PHPStan level 10 を通す
- **走査結果の型**: 走査ヘルパは全て**純関数** + 明示戻り値型 + PHPDoc の array shape
  (`array{page: string, location: string}` / `list<...>` / `array<string, string>`) で表す。
  `InertiaRenderPageExistsInvariantTest` の既存作法どおり **テスト専用の DTO クラスは作らない**
  (`app/` に本番コードでない型を増やさない / テストファイル内で完結させる)。
  array shape を先に決めてから実装する点は詳細設計で固定する
- 既存テストの削除・上書きはしない (追加のみ)
- `response()->json()` 直書きなし (テストコードのみ)
- 個別 `DatabaseTransactions` なし

---

## スコープ外

- **`app/` 本体への Carbon 呼び出し追加**: 現状 0 件。gate を張るだけで書き換えない
- **`->add('1 month')` / `CarbonInterval` / `modify()` 経由の月加算**: 今回の deny 集合は
  メソッド名の完全一致に限定する。これらは表現が多様で静的検出のコスト対効果が悪く、
  「今必要なものだけ作る」原則に従い対象外とする (必要になったら gate を拡張する)
- **namespace 付きファイルの非複合 use**: そちらは PHP が warning を出さない
  (実際に import として機能する) ため対象外
- **`<svelte:head>` の全面禁止**: title/description 以外は許可する
- **公開ページの SEO メタ拡充** (`route_classification.full` の拡大): 別議題
- **タイトル文言の UX レビュー**: 今回は「固有名が存在すること」の網羅性のみを扱う
- **Browser (Playwright) レーンでの実挙動検証**: 本バッチはすべて静的走査で完結する

