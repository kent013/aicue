## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

【補足コンテキスト】
- リポジトリルートは /workspace。ファイル読み込みは許可されているので必要なら実ファイルを参照してよい
- 既存の走査基盤の見本: tests/Architecture/InertiaRenderPageExistsInvariantTest.php,
  tests/Architecture/NestedRouteIdorDefenseTest.php, tests/Architecture/ManageRouteAuthGuardTest.php,
  tests/js/architecture/pages-path-case-invariant.test.ts
- タイトル解決の実体: app/Support/Seo/SeoManager.php, app/View/Composers/SeoComposer.php, config/seo.php
- この devcontainer には PostgreSQL が無く Feature テストは実行できない

---

## 概念設計

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

---

## 改善アイデア

**4 本の deny-by-default 静的走査 gate を新設し、検出された既存違反をその場で潰す。**

| # | gate | 実体 | 既存違反 | 性格 |
|---|---|---|---|---|
| 1 | Carbon overflow | `tests/Architecture/CarbonOverflowArithmeticGateTest.php` | 8 件 | 予防 (本体 0 件) |
| 2 | 非複合 global use | `tests/Architecture/NoNonCompoundGlobalUseTest.php` | 2 件 | 是正 + 予防 |
| 3 | ページタイトル網羅 | `tests/Architecture/DocumentTitleCoverageTest.php` | 4 件 + allowlist 1 | 是正 + 予防 |
| 4 | svelte:head 二重 SoT 禁止 | `tests/js/architecture/svelte-head-no-title.test.ts` | 0 件 | 予防 |

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
- **`AGENTS.md` 実装規約に 1 行追記**: 月/年/四半期の加減算は `*NoOverflow` 必須

### 2. 非複合 global use gate

- **走査対象**: `git ls-files '*.php'` から `.blade.php` を除いたもの。
  git 管理下に限定することで `vendor/` `node_modules/` `.claude/worktrees/`
  `storage/` を**自動的に**除外できる (明示 exclude リストの保守が要らない)。
  git 不在は silent skip せず fail させる
  (`tests/js/architecture/pages-path-case-invariant.test.ts` の先例に揃える)
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
  4. 当該 controller メソッド本体に `setPrivateTitle` 呼び出しがある
- **action 解決不能 (vendor controller / Closure)** は「静的にページ名を決められない」
  のと同じ扱いで **理由付き allowlist 必須** (deny-by-default)。
  Fortify の `login` / `register` 等は既に `app_titles` にあるため実質は
  `capture.csrf-cookie` (204 を返す Closure) 等の少数
- **`config/seo.php` へ 4 件追加**: `billing.plans` / `onboarding.checkout` /
  `onboarding.billing-required` / `capture.manuals.index`
- **`debug.login` は allowlist**: `routes/web.php` が `isLocal() || runningUnitTests()`
  で囲む local/test 専用 route。本番に存在しないため固有名を持たせる価値がない
- **`Invitations/Invalid` の扱い**: route 名でないため config には登録できない。
  同一 route (`invitations.accept`) が有効/無効で 2 ページを出し分けているので、
  **controller の無効分岐で `setPrivateTitle` を呼ぶ**ことで対応する
  (config の役割は route 既定値、controller の役割は分岐ごとの上書き、という
  既存の責務分担どおり)。gate はこれを強制しない (route 粒度の gate なので範囲外)

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
  (走査ヘルパは純関数 + 明示戻り値型 + PHPDoc の `list<...>` / `array<string, string>`)
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

