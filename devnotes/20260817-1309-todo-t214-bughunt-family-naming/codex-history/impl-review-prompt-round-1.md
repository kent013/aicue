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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

## あなたの役割

Laravel + Svelte アプリの実装レビュアーである。TODO T214「bug-hunt ランタイム配線の名前を家系へ揃える」の実装差分をレビューせよ。

### レビュー観点

1. **設計との一致性**: 詳細設計の施策 1〜4・受け入れ条件 A-1〜A-10 を満たしているか。設計に無い変更が紛れていないか
2. **正確性**: 改名の追従漏れ・逆に過剰な置換が無いか。振る舞いが 1 つも変わっていないか
3. **PHPStan level 10 適合性**
4. **テスト網羅性**: 新設した Architecture テスト (BughuntNamingResidualTest) が主張どおりの不変条件を固定しているか。保証範囲の記述が誇張になっていないか。負のコントロールが同じ述語を通しているか
5. **セキュリティ**: bug-hunt の偽外部サービス配線が壊れていないか (壊れると本物の外部サービスへ届く)
6. **設計からの逸脱の妥当性**: 下記「実装セッションの逸脱」に挙げた 3 点

本差分は `resources/js` / `resources/css` に 1 行も触れないため DESIGN.md / Atomic Design 観点は対象外である。

### 出力形式

- ファイルごとに判定
- 指摘は Critical / Warning / Suggestion に分類
- 最後に全体判定 **APPROVED** または **CHANGES_REQUESTED**

---

## 詳細設計書

# 詳細設計: bug-hunt ランタイム配線の名前を家系へ揃える (aicue:T214)

> 本ディレクトリ名に含まれる `t214` は TODO 番号 aicue:T214 を指す。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **本施策の使命への関係は間接である**。利用者に見える振る舞いは 1 つも変わらない。効くのは、
> bug-hunt の走行が本物の外部サービスへ届かないことを守る安全網の**維持コスト**である。

### 禁止事項 (AGENTS.md の転記。本施策に直結するものへ注記を付す)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
   → **本施策の不変条件「旧名は戻らない」は施策 1 の `BughuntNamingResidualTest` で登録する**
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

あわせて**思考原則 3「後方互換の並走を残さない。書き換えると決めたら同じ PR で旧実装を消す」**が
本施策の核である (旧名のクラスを別名として残さない)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。解析対象は `app` / `config` / `database` / `routes`
  であり、**`tests` は対象外**である (改名する provider と seeder は解析対象、新設する検査は対象外)。
- **Pest**。`RefreshDatabase` は `tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 禁止。
- `declare(strict_types=1)` + 日本語コメント。
- コードフォーマット: `vendor/bin/pint`。
- PHP 8.4 + Laravel 12。`App\` / `Database\Seeders\` は PSR-4 なので、ファイル名とクラス名を
  同時に変えれば autoload は追従する (classmap ではない)。

## 目的と台帳の根拠

| 項目 | 内容 |
|---|---|
| 機能 (lctl) | `bughunt-runtime` (bug-hunt ランタイム配線)。feature_revision 22-8c25a7989875 を読んだ |
| aicue の状態 | **update_pending** / assessment: divergence_candidate (観測点 aicue@bac558f) |
| 裁定 | **AG-085** (2026-08-06) — 「同じ関心事に名前が 2 つある状態を続けると、追従判断のたびに『これは欠落か別名か』の確認が発生する」を理由に名前の統一を求めた。**2026-08-10 の裁定でファイル数の統合 (AG-042 / AG-085) は撤回**され、**残る要件は「同一の関心事には家系で 1 つの名前を割り当てる」だけ**になっている |
| 直近の前進 | aicue:T177 (aicue@4d3007a) が投入配線の検査を新設し、欠落側は解消済み。同報告が「提供元クラスの名前は独自のままである。家系への改名は本件の範囲外とした」と申し送っている |
| 家系からの申し送り | aigenba:T1154 の報告 —「改名の残留検査は文書も見るべきで、**その走査範囲を設計時に決めること**」。実際に aigenba では残留検査がマージ後に旧名を検出して仕事をした |

### 家系名の確定 (推測していないことの根拠)

| 関心事 | 家系名 | 台帳での実測 |
|---|---|---|
| 決済側の投入 seeder | `BughuntStripeSyncSeeder` | gates に `tests/Feature/Bughunt/BughuntStripeSyncSeederTest.php` が 5 リポジトリで実在。「持たないのは aicue のみで、同じ位置に別名の aicue:tests/Feature/Database/BughuntBillingSeederTest.php が在る」。aigenba セルは `database/seeders/BughuntStripeSyncSeeder.php` を、metamovics セルは同名を実測記録している |
| 偽の外部サービスの配線 provider | `BughuntFakesServiceProvider` | aigenba セル「旧名 `app/Providers/DuskFakesServiceProvider.php` から `app/Providers/BughuntFakesServiceProvider.php` へ改名され (旧名は HEAD に不在)」。metamovics セルにも `app/Providers/BughuntFakesServiceProvider.php` が実在 |

## レビューの状態

| 段階 | 結果 |
|---|---|
| 概念設計 (Codex `gpt-5.5` / medium) | Round 1 で **APPROVED** (Critical 0 / Warning 2 → 詳細設計で対応) |
| 詳細設計 (Codex `gpt-5.5` / high) | Round 1〜5。**Round 5 時点で Critical 0 件**、施策 1 / 2 / 3 は APPROVE、施策 4 に残った Warning 1 件と Suggestion 1 件も同ラウンドで反映済み。`app-design` の上限 5 ラウンドに達したのでここを最終版とする (未対応の指摘は無い) |

各ラウンドのプロンプト・返答・対応マトリクスは `codex-history/` と
`detailed-review-round-{1..5}.md` に全部残してある。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t214-bughunt-family-naming/conceptual-design.md` (Codex 概念レビュー
Round 1 で APPROVED。Warning 2 件の対応は同ディレクトリの
`codex-history/conceptual-review-decisions-round-1.md`)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 旧名の残留検査を先に置いて赤にする | `tests/Architecture/BughuntNamingResidualTest.php` (新規) | 最初にやる |
| 2 | 決済側 seeder を家系名へ改名する | `database/seeders/BughuntBillingSeeder.php` → `BughuntStripeSyncSeeder.php`、`tests/Feature/Database/BughuntBillingSeederTest.php` → `BughuntStripeSyncSeederTest.php`、参照 8 ファイル (うち書き換えるのは 7。`docs/TODO-closed.md` は触らない) | 高 |
| 3 | 配線 provider を家系名へ改名する | `app/Providers/FakeExternalsServiceProvider.php` → `BughuntFakesServiceProvider.php`、`tests/Feature/Providers/FakeExternalsServiceProviderTest.php` → `BughuntFakesServiceProviderTest.php`、参照 23 ファイル (うち書き換えるのは 22。`docs/TODO-closed.md` は触らない。`.env.bughunt.local.example` は seeder 側と共通) | 高 |
| 4 | 命名規則から外れることの帰結を埋める (参照走査の候補集合) | `tests/Support/ExternalFakes/FakeClassCatalog.php`、`tests/Architecture/FakeClassReferenceInvariantTest.php`、`tests/Architecture/ExternalFakeWiringInvariantTest.php` | 高 (施策 3 と不可分) |

## 変更ファイル一覧

### 新規 (3)

| ファイル | 内容 |
|---|---|
| `tests/Architecture/BughuntNamingResidualTest.php` | 旧名 2 つが追跡下から消えたことと、戻らないことを固定する |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php` | A-6 の判定を再実行できる形にする**一時スクリプト** (恒久化しない。`scripts/` へは昇格させない) |
| `devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md` | 上の実行結果 (出力そのまま) |

### 改名 (4。`git mv` で履歴を繋ぐ)

| 変更前 | 変更後 |
|---|---|
| `database/seeders/BughuntBillingSeeder.php` | `database/seeders/BughuntStripeSyncSeeder.php` |
| `tests/Feature/Database/BughuntBillingSeederTest.php` | `tests/Feature/Database/BughuntStripeSyncSeederTest.php` |
| `app/Providers/FakeExternalsServiceProvider.php` | `app/Providers/BughuntFakesServiceProvider.php` |
| `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` | `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` |

### 変更 (28)

うち **25 ファイルは名前の置き換えだけ** (受け入れ条件 A-6a)。
残る 3 ファイル (`tests/Support/ExternalFakes/FakeClassCatalog.php` /
`tests/Architecture/FakeClassReferenceInvariantTest.php` /
`tests/Architecture/ExternalFakeWiringInvariantTest.php`) は、
**改名で縮む検査の網を元の広さに戻すための更新**を伴う (施策 4。受け入れ条件 A-6b)。

| 層 | ファイル | 出現数 |
|---|---|---|
| 起動 | `bootstrap/providers.php` | 2 |
| 実行スクリプト | `scripts/bug-hunt-shard.sh` (`cmd_provision` / `cmd_reseed` の投入列) | 2 |
| 本番コード (コメントのみ) | `app/Providers/AppServiceProvider.php` (2) / `app/Support/ExternalFakes/ExternalFakeDeclaration.php` (2) / `app/Support/FakeStorageGate.php` (1) / `app/Services/Billing/TicketLedgerService.php` (2) / `app/Services/Billing/Fakes/FakeStripeGateway.php` (3) / `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` (1) / `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` (1) / `app/Services/AI/Testing/CannedPromptFake.php` (1) / `CannedPromptFakeRegistrar.php` (1) / `CannedPromptResponses.php` (1) | 15 |
| 目録 (aicue:T177 の資産) | `tests/Support/Bughunt/BughuntSeedWiringInventory.php` | 3 |
| 検査 | `tests/Architecture/ExternalFakeWiringInvariantTest.php` (16) / `FakeClassReferenceInvariantTest.php` (6) / `LaneExternalFakeBindingTest.php` (1) | 23 |
| 検査の支援 | `tests/Support/ExternalFakes/FakeClassCatalog.php` (3) / `FakeWiringSourceScanner.php` (1) | 4 |
| レーン配線 | `tests/Pest.php` | 3 |
| 他のテスト | `tests/Feature/Auth/FakeSocialiteWiringTest.php` (3) / `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` (2) / `tests/Feature/Billing/TicketCheckoutTest.php` (1) / `TicketBalanceAccountingTest.php` (1) / `AutoRechargeStripeCallBudgetTest.php` (1) / `tests/Feature/Llm/CannedAnalysisPipelineTest.php` (1) | 9 |
| 文書 | `docs/architecture.md` (2) / `docs/testing-browser.md` (1) | 3 |
| 環境ひな型 | `.env.bughunt.local.example` (説明コメント) | 2 |

改名する 4 ファイル自身の中の出現 (seeder 本体 5 / provider 本体 1 / seeder テスト 7 /
provider テスト 9 = 22) を足すと、**追跡下・devnotes 以外の全出現は 33 ファイル / 91 箇所**である。
このうち `docs/TODO-closed.md` の 3 箇所 (`BughuntBillingSeeder` 1 / `FakeExternalsServiceProvider` 2) は
**触らない** (件数 pin で許容する) ので、実際に書き換えるのは **32 ファイル / 88 箇所**である。

### 削除 (0)

旧名のクラスは**別名として残さない** (思考原則 3)。移行期間・後方互換の別名は作らない。

## 施策 1: 旧名の残留検査を先に置いて赤にする

### 変更箇所

- 新規ファイル: `tests/Architecture/BughuntNamingResidualTest.php`

### 設計

家の既存作法 (`tests/Architecture/RouteCacheExemptionPremiseTest.php` の追跡下全ファイル走査 +
自己除外 + 件数 pin、`ForbiddenStatementTokenInvariantTest` の「走査する / 例外 / 除外 (理由必須)」の
3 分類) をそのまま踏襲する。**置き場所は 3 分類で排他的に扱う** (Codex 概念レビュー Warning と
詳細レビュー Round 1 Warning への対応)。

| 分類 | 対象 | 扱い |
|---|---|---|
| (a) 走査する | 追跡下の残り全ファイル | 旧名の出現は 1 件でも赤 |
| (b) 件数を完全一致で pin する | `docs/TODO-closed.md` (**旧名ごとに** `BughuntBillingSeeder` 1 件 / `FakeExternalsServiceProvider` 2 件。実測値) | 増えても減っても赤。合計ではなく**旧名ごと**に固定するので、片方を減らして他方を増やす書き換えも赤になる。丸ごと除外にすると将来の再流入に沈黙するため、粒度を落とさない |
| (c) 丸ごと除外する (理由必須) | `devnotes/` (接頭辞) と本テスト自身 | 前者は過去の設計・レビュー・走行記録が 190 ファイル規模で旧名を含み、件数 pin が実務にならない。後者は検出したい語を負のコントロールの入力として持つため |

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * 家系 (lctl の機能 bughunt-runtime) で 1 つに決まっている名前が、旧名へ戻らないことの固定。
 *
 * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
 * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
 * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
 *
 * ★保証範囲を誇張しない:
 *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
 *     動的に組み立てた文字列には**沈黙する**。
 *   - **丸ごと除外した分類 (c) の中では沈黙する**。分類 (b) は登録済みの件数だけを許容し、
 *     増減も旧名ごとの内訳の入れ替えも検出する (沈黙しない)。
 *   - 家系名が「正しい名前であること」は検査できない。正本は台帳であり、
 *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 退役した名前 → 家系の名前。
 *
 * 出典は lctl の機能 bughunt-runtime (aigenba / metamovics / laravel-claude-template の実測)。
 *
 * @var array<string, string>
 */
const BUGHUNT_RETIRED_NAMES = [
    'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
    'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
];

/**
 * (b) 旧名を持つことが確認済みの**過去の記録**と、**旧名ごとの**件数。
 *
 * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` /
 * `ROUTE_CACHE_PREMISE_KNOWN_MENTIONS` と同じ作法)。丸ごと除外にしないのは、
 * 除外したファイルの中で将来 旧名が再流入しても沈黙してしまうためである。
 *
 * ★合計ではなく**旧名ごと**に固定する。合計だけを見ると「片方を 1 件減らして
 *   もう片方を 1 件増やす」書き換えが緑のまま通る (Codex 詳細レビュー Round 2 の指摘)。
 *
 * - `docs/TODO-closed.md`: 完了した TODO の記録。aicue:T015 / aicue:T119 が当時作った
 *   クラスの名前は当時の事実であり、書き換えると記録が嘘になる。
 *   ★aicue:T214 のクローズ記録が旧名に触れると本 pin は赤くなる。そのときは
 *     「記録を書き換える」のではなく **pin の数を同じ変更の中で更新する** (意図的な摩擦)。
 *
 * @var array<string, array<string, int>>
 */
const BUGHUNT_NAMING_KNOWN_MENTIONS = [
    'docs/TODO-closed.md' => [
        'BughuntBillingSeeder' => 1,
        'FakeExternalsServiceProvider' => 2,
    ],
];

/**
 * (c) 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
 *
 * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、件数 pin が
 * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
 * 除外するのと同じ扱い)。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];

/**
 * (c) 丸ごと走査から外す唯一のファイル = 本テスト自身。
 *
 * 検出したい語を負のコントロールの入力として持つため、自分を走査すると必ず自分で赤くなる。
 * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
 */
const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';

/** 走査の母集団が空振りでないことを確かめる代表パス (改名後に実在するもの) */
const BUGHUNT_NAMING_SENTINEL_PATHS = [
    'bootstrap/providers.php',
    'scripts/bug-hunt-shard.sh',
    'database/seeders/BughuntStripeSyncSeeder.php',
    'app/Providers/BughuntFakesServiceProvider.php',
];

/** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;

/**
 * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
 *
 * @return list<string>
 */
function bughuntNamingViolationsIn(string $relative, string $content): array
{
    if ($relative === BUGHUNT_NAMING_SELF_PATH) {
        return [];
    }

    foreach (BUGHUNT_NAMING_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return [];
        }
    }

    // 過去の記録は「0 件」ではなく「pin した件数ちょうど」を旧名ごとに要求する。
    $pinned = BUGHUNT_NAMING_KNOWN_MENTIONS[$relative] ?? [];

    $violations = [];
    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
        $count = substr_count($content, $retired);
        $allowed = $pinned[$retired] ?? 0;

        if ($count === $allowed) {
            continue;
        }

        $violations[] = $allowed === 0
            ? "{$relative}: {$retired} が {$count} 箇所残っている (家系名は {$canonical})"
            : "{$relative}: {$retired} の出現が {$count} 箇所 (pin は {$allowed} 箇所)";
    }

    return $violations;
}
```

テスト本体 (要旨):

| # | 名前 | 内容 |
|---|---|---|
| N-1 | 追跡下の現役資産に旧名が 1 つも残っておらず、過去の記録は pin した件数ちょうどである | 追跡下の全ファイルを `bughuntNamingViolationsIn()` に通して `[]` |
| N-2 | fail-closed | 母集団が下限以上、かつ代表パスを全部含む (`git ls-files` が失敗したら**空を返さず例外**) |
| N-3 | 走査の外し方が意図どおり | 丸ごと除外の**定義**が 2 つちょうど (接頭辞 `devnotes/` 1 件 + 自分自身 1 件)、件数 pin の**定義**が 1 ファイル分ちょうど (`docs/TODO-closed.md` の旧名 2 種)。ファイル数ではなく定義の数を pin する意味であることをテスト名に書く |
| N-4 | 負のコントロール | 同じ述語が (a) `app/Foo.php` に旧名を書いた入力を検出し、(b) `devnotes/…` の入力は検出せず、(c) 自分自身のパスの入力も検出せず、(d) pin したファイルで**件数がずれた**入力 (1 件でも 3 件でも) を検出し、(e) pin したファイルで**合計は同じだが内訳が違う**入力 (片方 0 件 / 他方 3 件) も検出する |
| N-5 | 旧名のクラスが**存在しない** / 家系名のクラスが存在する | `class_exists()` で両方向を確かめる (後方互換の別名を残していないことの機械化) |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイルが新規テストである

### PHPStan 適合チェック

- [x] 解析対象外 (`tests` は `phpstan.neon` の paths に無い)。ただし型注記は既存 Architecture
      テストと同じ水準で書く (`@return list<string>` 等)
- [x] `git` が使えない環境では空を返さず例外にする (fail-open 防止)

### テスト計画

- [x] **これ自体が最初に赤になるテストである** (施策 2/3 の前に置く)
- [x] 負のコントロール (N-4) を同じ述語で通す

### リスク

- 走査は字面のみ。旧名を分割・連結する書き方には沈黙する (docblock に明記する)。
- `devnotes/` を丸ごと除外するため、**設計記録の中では旧名の再流入に沈黙する**。
  ここは過去の記録の置き場所であり、現役の資産ではないので受け入れる (穴として docblock に明記する)。
- `docs/TODO-closed.md` は件数 pin なので、**aicue:T214 のクローズ記録が旧名に触れると赤くなる**。
  そのときの正しい直し方は「記録を書き換える」ではなく「pin の数を同じ変更で更新する」である
  (実装セッションへの申し送りとして docblock に書く)。

## 施策 2: 決済側 seeder を家系名へ改名する

### 変更箇所

- `database/seeders/BughuntBillingSeeder.php` (L42 のクラス宣言、L56 / L65 / L83 / L106 の
  出力文言) → `database/seeders/BughuntStripeSyncSeeder.php`
- `tests/Feature/Database/BughuntBillingSeederTest.php` (L9 の import、L13 の docblock、
  L47 / L58 / L71 / L73 / L96 の `$this->seed(...)`) → `tests/Feature/Database/BughuntStripeSyncSeederTest.php`
- `tests/Support/Bughunt/BughuntSeedWiringInventory.php` (L8 import / L50 目録キー /
  L53 `guardPremiseTest` のパス)
- `scripts/bug-hunt-shard.sh` (L1121 = `cmd_provision`、L1347 = `cmd_reseed` の
  `db:seed --class=` の値)
- `app/Services/Billing/TicketLedgerService.php` (L405 / L822 のコメント)
- `app/Services/Billing/Fakes/FakeStripeGateway.php` (L18 / L48 / L55 のコメント)
- `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php` (L19 のコメント)
- `tests/Feature/Billing/TicketBalanceAccountingTest.php` (L164 のコメント)
- `.env.bughunt.local.example` (L69 の説明コメント)

### 現行コード (抜粋)

```php
// database/seeders/BughuntBillingSeeder.php:42
class BughuntBillingSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;
```

```php
// tests/Support/Bughunt/BughuntSeedWiringInventory.php:50
BughuntBillingSeeder::class => [
    'role' => BughuntSeedRole::BughuntOnly,
    'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
    'guardPremiseTest' => 'tests/Feature/Database/BughuntBillingSeederTest.php',
],
```

```bash
# scripts/bug-hunt-shard.sh:1121 / 1347
artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
```

### 変更後コード (抜粋)

```php
// database/seeders/BughuntStripeSyncSeeder.php:42
class BughuntStripeSyncSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;
```

```php
// tests/Support/Bughunt/BughuntSeedWiringInventory.php:50
BughuntStripeSyncSeeder::class => [
    'role' => BughuntSeedRole::BughuntOnly,
    'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
    'guardPremiseTest' => 'tests/Feature/Database/BughuntStripeSyncSeederTest.php',
],
```

```bash
artisan_for_shard "${db}" "${url}" db:seed --class=BughuntStripeSyncSeeder --force
```

**変えないもの**: 区分 (`BughuntSeedRole::BughuntOnly`)・理由文・投入列の**順序**・
三重ガードの条件と論理・冪等キー (`bughunt:initial-grant:{id}`)・
固定の購読 ID (`sub_bughunt_{id}`)・付与枚数 (100)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 上記のとおり (改名 1 + 参照 2)
- **DB マイグレーション: なし** (seeder の名前はデータに現れない)

### PHPStan 適合チェック

- [x] 戻り値の型は既存のまま (`run(TicketLedgerService $tickets): void` 等)
- [x] `Plan` / `Organization` の generics は既存のまま
- [x] PSR-4 のためファイル名とクラス名を同時に変える (不一致は autoload エラーになる)

### テスト計画

- [x] 既存 `tests/Feature/Database/BughuntStripeSyncSeederTest.php` (改名) がそのまま緑
- [x] `BughuntSeedWiringInvariantTest` の **S-1** (目録のキー集合 = `database/seeders` の
      Seeder クラス集合) が、改名とクラス名の追従を**機械的に強制する**
- [x] 同 **S-3 / S-4** が `cmd_provision` / `cmd_reseed` の投入列との一致を強制する
      (スクリプト側の書き換え漏れは必ず赤になる)
- [x] 同 **S-9** が `guardPremiseTest` の実在と「対象 seeder の名前を含むこと」を強制する
      (テストファイル名の追従漏れも赤になる)
- [x] 個別の `DatabaseTransactions` は使っていない (既存どおり)

### リスク

- **投入列の書き換え漏れ**が最大のリスク (漏れると bug-hunt 環境で購読が入らず、
  業務経路が探索できなくなる)。S-3 / S-4 が機械で止める。
- seeder が出す `warn` / `info` の文言は先頭にクラス名を含むため、**CLI 出力の文字列は変わる**
  (「振る舞い」ではないが観測可能な差分である)。これは意図した追従であり、受け入れ条件 A-6a の
  逆置換でバイト一致することによって「名前以外は変えていない」ことが機械で確かめられる。
- 走行中の bug-hunt 環境が旧名で `db:seed` を呼ぶ手順書を持っていないかは実測済み
  (`.claude/skills/` に旧名の出現は 0 件)。

## 施策 3: 配線 provider を家系名へ改名する

### 変更箇所

- `app/Providers/FakeExternalsServiceProvider.php` (L37 のクラス宣言) →
  `app/Providers/BughuntFakesServiceProvider.php`
- `bootstrap/providers.php` (L7 の `use`、L31 の登録)
- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` →
  `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` (import と 7 箇所の実体化)
- `tests/Pest.php` (L14 の `use`、`enableFakeStorage()` / `enableFakeExternals()` の実体化)
- `tests/Architecture/ExternalFakeWiringInvariantTest.php` (16 箇所: `use`、
  M3 / M4 の被覆表の説明文、`externalFakeWiringProviderSource()` が渡すパス、
  3-5 / 3-6 / 3-7 / 3-11 等の実体化とテスト名)
- `tests/Architecture/FakeClassReferenceInvariantTest.php` (施策 4 と合わせて対応)
- `tests/Architecture/LaneExternalFakeBindingTest.php` (docblock 1 箇所)
- `tests/Support/ExternalFakes/FakeClassCatalog.php` (施策 4 と合わせて対応)
- `tests/Support/ExternalFakes/FakeWiringSourceScanner.php` (docblock 1 箇所)
- `tests/Feature/Auth/FakeSocialiteWiringTest.php` / `tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php` /
  `tests/Feature/Billing/TicketCheckoutTest.php` / `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` /
  `tests/Feature/Llm/CannedAnalysisPipelineTest.php`
- `app/Providers/AppServiceProvider.php` (L130 / L134 のコメント) /
  `app/Support/ExternalFakes/ExternalFakeDeclaration.php` (L31 / L35 のコメント) /
  `app/Support/FakeStorageGate.php` (L13) / `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php` (L14) /
  `app/Services/AI/Testing/CannedPromptFake.php` (L25) / `CannedPromptFakeRegistrar.php` (L15) /
  `CannedPromptResponses.php` (L22)
- `docs/architecture.md` (L1686 / L1717) / `docs/testing-browser.md` (L160) /
  `.env.bughunt.local.example` (L68)

### 現行コード / 変更後コード (抜粋)

```php
// bootstrap/providers.php (現行)
use App\Providers\FakeExternalsServiceProvider;
…
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    FakeExternalsServiceProvider::class,
```

```php
// bootstrap/providers.php (変更後)
use App\Providers\BughuntFakesServiceProvider;
…
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    BughuntFakesServiceProvider::class,
```

**変えないもの**: `bootstrap/providers.php` 内の**登録順** (`AppServiceProvider` より後 =
後勝ち rebind)、`register()` / `boot()` の中身、capability flag 名
(`ExternalFakeDeclaration::EXTERNALS_FLAG` = `testing.fake_externals`)、環境 allowlist、
`FakeStorageGate` の判定、署名付き経路の名前 (`bughunt.storage.put` / `bughunt.storage.get`)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- **Inertia Props: なし** (provider は container 配線のみで画面へ出ない)
- テストファイル: 上記のとおり

### PHPStan 適合チェック

- [x] `app/` 配下なので解析対象。クラス名の変更のみで型は不変
- [x] `bootstrap/providers.php` は解析対象外だが、`use` と登録の両方を直さないと起動時に落ちる
      (Feature テスト全体が赤になるため見逃せない)

### テスト計画

- [x] `ExternalFakeWiringInvariantTest`
      **3-5** (`bootstrap/providers.php` に登録されている) /
      **3-6** (`AppServiceProvider` より後) /
      **3-7** (起動済み container にロード済み) /
      **3-9** (container 呼び出しの形) / **3-10** (provider が参照する fake 系クラスは
      配線基盤 4 件ちょうど) がすべて緑であること
- [x] `FakeClassReferenceInvariantTest` 4-1〜4-4 (施策 4 参照)
- [x] `LaneExternalFakeBindingTest` (レーンからの直結禁止) が緑
- [x] 改名した `BughuntFakesServiceProviderTest` が緑 (register / boot の 8 test)
- [x] `tests/Pest.php` の `enableFakeExternals()` / `enableFakeStorage()` を使う既存テスト群
      (課金・captcha・SSO・保存先) が緑

### リスク

- **`bootstrap/providers.php` の追従漏れ** = 偽物が立たず bug-hunt が本物の外部サービスへ届く。
  これは本 feature が台帳で共有されている理由そのものであり、3-5 / 3-6 / 3-7 が機械で止める。
- `bootstrap/cache/` 配下の生成物 (`services.php` / `packages.php` / `config.php` /
  `routes-*.php` 等) はすべて**追跡外の生成物**であり、`.gitignore` 済み・
  `FakeClassCatalog::EXCLUDED_PREFIXES` でも走査から外れている。provider 一覧が変わると
  Laravel 自身が manifest を作り直すため手当ては要らない。それでも詰まった場合の復旧は
  `php artisan optimize:clear` である。

## 施策 4: 命名規則から外れることの帰結を埋める (参照走査の候補集合)

### 背景 (この施策が必要な理由)

`FakeClassCatalog` は fake 系クラスの母集団を**ハードコードせずに導出**している。

- 定義 1「fake 実装クラス」= `app/**/Fakes/` か `app/**/Testing/` 配下の全クラス
- 定義 2「fake 命名クラス」= クラス名が `Fake` で始まる or `Fake` で終わるクラス

現在の `FakeExternalsServiceProvider` は**定義 2 に当たる**ため、`placementExceptions()`
(定義 2 のうち定義 1 に属さなくてよい例外) に登録されている。
`FakeClassReferenceInvariantTest` の **4-3 (本番コードは fake クラスを参照しない)** は、
候補集合を `implementationClasses() ∪ placementExceptions() のキー` としており、
その docblock は「配置例外を業務コードが参照しても検出できず**偽グリーン**になるため」と
理由を書いている。

**改名すると `BughuntFakesServiceProvider` は定義 2 に当たらなくなる** (先頭も末尾も `Fake` でない)。
帰結は 2 つある。

1. `placementExceptions()` から entry を落とすと、**配線 provider が 4-3 の候補集合から消え、
   業務コードが配線点を直接参照しても検出できなくなる** = docblock が名指しで警告している
   偽グリーンを自分で作ることになる。したがって **entry は残し、docblock の意味を書き直す**。
2. `ExternalFakeWiringInvariantTest` の **3-10** (provider が参照する fake 系クラスは配線基盤
   4 件ちょうど) は候補集合を `implementationClasses() ∪ namedClasses()` で作っているため、
   **改名すると provider 自身が候補から静かに落ちる**。いまの結果は変わらない
   (走査器は `isDeclarationName` のトークンを飛ばすので、クラス宣言名は参照として数えない
   — `FakeWiringSourceScanner` の docblock と実装で確認済み) が、
   **候補集合から配線 provider というクラスが 1 つ脱落する**こと自体が、
   3-10 が見ている集合を黙って狭める (Codex 詳細レビュー Round 4 の Suggestion に従い、
   「別の配線基盤クラスの検出まで広がる」という説明はしない。他クラスの検出可否は
   そのクラス自身が候補に入るかで決まる)。
   これは検査の網が縮む変化なので、候補集合を
   `implementationClasses() ∪ namedClasses() ∪ placementExceptions() のキー` へ揃える
   (Codex 詳細レビュー Round 1 の Critical への対応)。
   **これはアプリの振る舞いの変更ではなく、改名で縮む網を元の広さに戻す変更である。**

### 変更箇所

- `tests/Support/ExternalFakes/FakeClassCatalog.php` L52-62 (`placementExceptions()` の
  docblock と entry のクラス名・理由文)
- `tests/Architecture/FakeClassReferenceInvariantTest.php` L30-47 (allowlist のパス) /
  L60-66 (4-2 の期待値) / L68-103 (4-3 に候補集合の明示 assertion を追加) /
  L105-116 (4-4 の期待値)
- `tests/Architecture/ExternalFakeWiringInvariantTest.php` L239-243 (3-10 の候補集合に
  `placementExceptions()` のキーを足す)

### 現行コード

```php
/**
 * 定義 2 のうち定義 1 に属さなくてよい例外 (fake の実体ではなく配線基盤)。
 *
 * @return array<class-string, string> class => 理由
 */
public static function placementExceptions(): array
{
    return [
        FakeExternalsServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
        FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
    ];
}
```

### 変更後コード

```php
/**
 * 「fake の実体ではない配線基盤」の目録。用途は 2 つある。
 *
 * 1. 定義 2 (Fake 命名) に当たるが定義 1 (Fakes/ • Testing/ 配下) に属さなくてよい**配置の例外**
 * 2. **参照走査 (FakeClassReferenceInvariantTest 4-3) の候補**に必ず含める集合
 *
 * ★BughuntFakesServiceProvider は家系の名前へ改名した結果、名前の規則 (定義 2) では
 *   拾えなくなった。それでも本目録に残すのは用途 2 のためである — ここから落とすと、
 *   業務コードが唯一の配線点を直接参照しても検出できず**偽グリーン**になる
 *   (4-3 の docblock が名指しで警告している事故)。この包含は 4-3 の明示 assertion が固定する。
 *
 * @return array<class-string, string> class => 理由
 */
public static function placementExceptions(): array
{
    return [
        BughuntFakesServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
        FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
    ];
}
```

★**理由の文字列 (実行トークンとして残る値) は 1 文字も変えない**。説明は docblock (コメント) に
だけ書く。文字列を伸ばすと逆置換後に旧内容と一致しなくなり、受け入れ条件 A-6b が許す
「実行トークンの差分は 2 か所だけ」が崩れる (Codex 詳細レビュー Round 2 の Critical)。

`FakeClassReferenceInvariantTest` 側は allowlist のパスと 4-2 / 4-4 の期待値を新名へ差し替える
(**件数は 2 件 / 6 件のまま変えない**)。あわせて **4-3 の中に候補集合の明示 assertion を足す**
(Codex 詳細レビュー Round 1 の Critical / Warning への対応)。

```php
// tests/Architecture/FakeClassReferenceInvariantTest.php 4-3 の fail-closed 部に追記
    // ★名前の規則 (定義 2) では拾えなくなった配線 provider が、候補集合に必ず残っていること。
    //   ここが落ちると「本番コードが唯一の配線点を参照しても検出できない」偽グリーンになる。
    expect($candidates)->toContain(BughuntFakesServiceProvider::class)
        ->and($candidates)->toContain(FakeStorageGate::class);
```

```php
// tests/Architecture/ExternalFakeWiringInvariantTest.php 3-10 (候補集合の統一)
    $candidates = array_values(array_unique(array_merge(
        FakeClassCatalog::implementationClasses(),
        FakeClassCatalog::namedClasses(),
        array_keys(FakeClassCatalog::placementExceptions()),
    )));
```

**`placementExceptions()` の名前は変えない**。関数名まで変えると本施策が「名前を揃える作業」から
逸脱し、差分が名前の置換だけであるという受け入れ条件 (A-6) が成立しなくなる。
別メソッド (`referenceScanInfrastructureClasses()` 等) を新設する案も検討したが、
**目録が 2 件しかない段階で入口を 2 つに割ると、どちらに足すかの判断がそのつど要る**ため採らない
(思考原則 2「今必要なものだけ作る」)。代わりに用途 2 を docblock に明記し、
上の**明示 assertion で機械的に固定する** (削除事故は 4-3 の中で赤くなる)。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 上記 2 ファイル

### PHPStan 適合チェック

- [x] `tests/` は解析対象外。`@return array<class-string, string>` は既存のまま

### テスト計画

- [x] **4-1** (Fake 命名クラスは `Fakes/` か `Testing/` 配下にのみ存在する) が緑のままであること
      — 改名後は provider が `namedClasses()` から外れるため `$misplaced` は空のまま
- [x] **4-2** (配置例外は 2 件から増えていない) が新名で緑
- [x] **4-3** (本番コードは fake クラスを参照しない) が緑、かつ**候補集合に
      `BughuntFakesServiceProvider` と `FakeStorageGate` が含まれること**を明示 assertion で固定
- [x] **4-4** (参照 allowlist は 6 件から増えていない) が新名で緑
- [x] `ExternalFakeWiringInvariantTest` **3-10** の候補集合へ `placementExceptions()` のキーを
      足しても**期待値 (配線基盤 4 件) は変わらない**こと — 走査器はクラス宣言名を参照として
      数えないため、provider 自身が候補に入っても結果に現れない。
      **実装セッションはこれを実行して確かめる** (説明だけで済ませない)
- [x] **負のコントロール**: 3-10 の候補集合から `placementExceptions()` を外した状態でも
      緑のままであることを 1 度確認し、「いま結果は変わらないが将来の網の広さが違う」ことを
      実装記録 (impl-review 用のメモ) に残す (この 1 点は**恒久テストにはしない** —
      恒久化すると「候補集合を狭めてよい」という誤った要求になるため)

### リスク

- `placementExceptions()` の意味が 1 用途から 2 用途へ広がる。**名前は変えない**
  (関数名まで変えると本施策が「名前を揃える作業」から逸脱する)。docblock に用途 2 を明記し、
  4-3 の明示 assertion で「落とすと赤くなる」状態にする。
- 3-10 の候補集合を広げるのは**検査側の変更**であり、A-6 の「逆置換でバイト一致」の対象から
  外れる 3 ファイルのうちの 1 つになる (受け入れ条件 A-6b で別建てに検証する)。

## テストファースト計画 (どのテストを先に赤にするか)

| 順 | 操作 | 期待される色 |
|---|---|---|
| 1 | 施策 1 の `BughuntNamingResidualTest` を**改名前に**追加して実行 | **赤** (N-1 が **32 ファイル / 88 箇所**を列挙する = 追跡下・devnotes 以外の全出現 91 箇所から、件数 pin で許容する `docs/TODO-closed.md` の 3 箇所を引いた数。この出力が作業一覧になる)。N-2 の代表パスも改名後のパスを指すため赤 |
| 2 | 施策 2 の `git mv` を行い、クラス宣言だけ直して実行 | **赤** (`BughuntSeedWiringInvariantTest` S-1 = 目録のキー集合の不一致 / S-4 = 投入列の不一致 / S-9 = 前提テストの不在) |
| 3 | 施策 2 の参照を全部追従させて実行 | 施策 2 側は緑。N-1 はまだ赤 (provider が残る) |
| 4 | 施策 3 の `git mv` を行い、クラス宣言だけ直して実行 | **赤** (起動時に provider が解決できず Feature 全体 / `ExternalFakeWiringInvariantTest` 3-5・3-6・3-7 が落ちる) |
| 5 | 施策 3 + 施策 4 の参照を全部追従させて実行 | **緑** (N-1 も緑になる) |
| 6 | `php devnotes/…/verify-rename-only.php` (A-6 の検証手順) を実行 | 終了コード 0。落ちたら**逆置換で一致しない箇所が作業一覧になる** |
| 7 | 全検証コマンドを実行 | 全部緑 |

「先に赤を見る」対象は **N-1 (新設)** と、**既存の deny-by-default 検査群 (S-1 / S-4 / S-9 /
3-5 / 3-6 / 3-7 / 4-2 / 4-4)** の 2 系統である。後者は「参照の数え落とし」を機械で暴く役目を持つので、
**手順 2 と 4 で意図的に中間状態を作って赤を確認する** (赤を見ずに一括置換しない)。

## 受け入れ条件 (機械検証可能な形)

| # | 条件 | 検証方法 |
|---|---|---|
| A-1 | 追跡下の現役資産に旧名が 1 件も残っていない | `git ls-files -z \| xargs -0 grep -l -E 'BughuntBillingSeeder\|FakeExternalsServiceProvider'` の出力が **`devnotes/…` と `docs/TODO-closed.md` と `tests/Architecture/BughuntNamingResidualTest.php` だけ**である |
| A-2 | 同上を検査が固定している | `vendor/bin/pest tests/Architecture/BughuntNamingResidualTest.php` が緑 (N-1〜N-5) |
| A-3 | 旧名のクラスが存在しない | `php -r "require 'vendor/autoload.php'; var_dump(class_exists('Database\\Seeders\\BughuntBillingSeeder'), class_exists('App\\Providers\\FakeExternalsServiceProvider'));"` が `false` / `false` (N-5 が同じことをテストで固定する) |
| A-4 | 家系名のファイルが実在する | `test -f database/seeders/BughuntStripeSyncSeeder.php && test -f app/Providers/BughuntFakesServiceProvider.php` |
| A-5 | 改名前の履歴が追える | `git log --follow --oneline -- database/seeders/BughuntStripeSyncSeeder.php` / `-- app/Providers/BughuntFakesServiceProvider.php` が改名前の履歴を含む (**git は改名操作を記録せず内容の類似度から推定する**ので、これは「`git mv` を使ったことの証明」ではなく「rename 検出が働くこと」の確認である。`git mv` を使うことは実装手順として要求し、受け入れ条件とは分ける) |
| A-6a | **意図しない実行コード差分が無い (名前だけを置換したファイル群)** | 対象は変更ファイルのうち**下記 A-6b の 3 ファイルと新規テスト 1 件を除く全部** (改名した 4 ファイルを含む)。各ファイルの新内容へ `sed 's/BughuntStripeSyncSeeder/BughuntBillingSeeder/g; s/BughuntFakesServiceProvider/FakeExternalsServiceProvider/g'` を掛けたものが `git show main:<改名前のパス>` と**バイト一致**すること |
| A-6b | **意味も更新した 3 ファイルの差分が想定どおり** | 対象は `tests/Support/ExternalFakes/FakeClassCatalog.php` / `tests/Architecture/FakeClassReferenceInvariantTest.php` / `tests/Architecture/ExternalFakeWiringInvariantTest.php`。逆置換した新内容と旧内容を **PHP のトークン列 (`token_get_all` から `T_COMMENT` / `T_DOC_COMMENT` / `T_WHITESPACE` を除いたもの)** で比較し、差分が次の 2 か所**だけ**であること — (i) 3-10 の候補集合へ `array_keys(FakeClassCatalog::placementExceptions())` を足した式、(ii) 4-3 に足した候補集合の明示 assertion。**それ以外のコード差分が 1 トークンでもあれば不合格**である (理由文字列を含む) |
| A-6c | 新規の恒久資産は逆置換の対象外 | `tests/Architecture/BughuntNamingResidualTest.php` は新規のため比較しない |
| A-6d | A-6a〜A-6c・A-6e の判定が**再実行できる形**である | 下記「A-6 の検証手順」の一時スクリプトが終了コード 0 で通ること。母集団は `git diff --name-status -M main...HEAD` から導出し、**未分類・重複分類・分類はあるが差分に現れないファイルはすべて不合格**にする (fail-closed) |
| A-6e | **設計・レビュー記録・検証の道具は明示一覧で分類する** | `devnotes/20260817-1309-todo-t214-bughunt-family-naming/` 配下の成果物 (概念設計 / 詳細設計 / Codex のプロンプトと返答と対応マトリクス / `verify-rename-only.php` / `rename-verification.md`) と `docs/TODO.md`・`docs/TODO-closed.md` の TODO 行の増減を、**パスの明示一覧**として分類表に持つ。逆置換の比較対象にはしないが、**`devnotes/` を暗黙に丸ごと除外しない** (実際に差分へ現れたものを一覧に載せる = fail-closed)。**A-6e に載せてよいのは本ディレクトリ配下と TODO 台帳だけ**で、`app/` `tests/` `database/` `scripts/` `config/` `bootstrap/` `docs/` の他ファイルを A-6e へ逃がすことは禁止する |
| A-7 | 投入列が provision と reseed で一致 | `BughuntSeedWiringInvariantTest` の S-3 / S-4 が緑 |
| A-8 | 起動時の登録点が保たれている | `ExternalFakeWiringInvariantTest` の 3-5 / 3-6 / 3-7 が緑 |
| A-9 | 新設の検査が実在して緑 / 既存の指定 invariant が緑 / 全体が緑 | `vendor/bin/pest tests/Architecture/BughuntNamingResidualTest.php` に N-1〜N-5 の 5 test が現れて緑、`BughuntSeedWiringInvariantTest` (S-1〜S-11) / `ExternalFakeWiringInvariantTest` / `FakeClassReferenceInvariantTest` / `LaneExternalFakeBindingTest` / 改名した 2 本のテストが緑、`composer test` が **failed 0** かつ **skipped の件数が実装前と同じ** (テストの総数そのものは dataset 展開で揺れるため受け入れ条件にしない) |
| A-10 | 全検証コマンドが green | 下記の全コマンド |

### A-6 の検証手順 (再実行できる形にする)

判定を文章の読み合わせにしない。**一時スクリプトを `devnotes/20260817-1309-todo-t214-bughunt-family-naming/`
配下に置いて実行する** (AGENTS.md「一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ」。
恒久化はしない — 1 回きりの改名の検証であり、`scripts/README.md` の台帳に載せる性質ではない)。

ファイル名: `verify-rename-only.php` (実行は `php devnotes/…/verify-rename-only.php`)

処理の流れ:

0. **作業ツリーが clean であることを確かめる** (`git status --porcelain` が空)。
   母集団は `main...HEAD` から取るので、**未コミットの変更は見えない**。
   汚れたまま実行すると「最終成果物を検証していないのに緑」になる (Codex 詳細レビュー
   Round 4 の Critical)。汚れていたら即不合格にする。
1. `git diff --name-status -M main...HEAD` を実行し、母集団を作る。
   - `R<類似度> <旧パス> <新パス>` の行から**改名の対応表**を取る。
   - `M <パス>` は旧パス = 新パス。`A <パス>` は新規。
2. 状態の記号ごとに分類の規則を分ける (Codex 詳細レビュー Round 4 の Warning への対応)。

   | 記号 | 規則 |
   |---|---|
   | `R` / `M` | A-6b / A-6e の明示一覧に載っていればそれ、載っていなければ **A-6a** |
   | `A` (新規) | **A-6c か A-6e への明示登録を必須**とする。どちらにも無ければ**未分類として不合格** (新規を A-6a へ落として `git show main:<パス>` の失敗に頼らない) |
   | `D` (削除) | **無条件で不合格** (本施策に削除は無い) |

   - **明示一覧どうしにパスの重複があれば不合格**。
   - **一覧にあるのに差分へ現れないパスがあっても不合格** (設計と実装のずれ)。
   - A-6e に入れてよいのは
     `devnotes/20260817-1309-todo-t214-bughunt-family-naming/` 配下と `docs/TODO.md` /
     `docs/TODO-closed.md` **だけ**で、それ以外のパスが A-6e に載っていたら不合格にする
     (メタ成果物という名目で本体の差分を逃がせないようにする)。
3. A-6a: 新内容へ逆置換 (`BughuntStripeSyncSeeder` → `BughuntBillingSeeder`、
   `BughuntFakesServiceProvider` → `FakeExternalsServiceProvider`) を掛け、
   `git show main:<旧パス>` と**バイト比較**する。1 バイトでも違えば不合格。
4. A-6b: 逆置換した新内容と `git show main:<旧パス>` を `token_get_all()` に掛け、
   `T_COMMENT` / `T_DOC_COMMENT` / `T_WHITESPACE` を落としたトークン列 (種別と字句の対) を作る。
   2 つの列の差分を取り、**許可する追加トークン列 2 つ**
   ((i) `array_keys ( FakeClassCatalog :: placementExceptions ( ) )` を含む 3-10 の候補集合の追加、
   (ii) 4-3 に足した `expect ( $candidates ) -> toContain ( … )` の 1 文) と一致しなければ不合格。
   **削除側のトークンは 0 個であること**も要求する (既存の検査を弱めていないことの担保)。
5. すべて通ったら終了コード 0。失敗したファイルと理由を全件出力する
   (最初の 1 件で止めない = 直す作業一覧になる)。**出力は必ずパスの昇順に並べる**
   (`git diff` の出力順に依存しない = 再現性を契約として読めるようにする)。

**出力は決定的にする** — 時刻・実行環境・コミットハッシュのような毎回変わる値を出力に含めない
(含めると次の段の「2 回目でも結果ファイルが変わらない」が成立しない)。

**スクリプトは結果ファイルを自分では書かない**。標準出力へ出すだけにし、
**呼び出し側がリポジトリの外へ一度受けてから**結果ファイルへ反映する
(Codex 詳細レビュー Round 5 の Warning)。結果ファイルへ直接リダイレクトすると、
シェルが PHP を起動する**前に**ファイルを空にするため、段 0 の clean 検査が自分の副作用で
落ちる。

```bash
# 例 (リポジトリ外の一時ファイルへ受ける。scratchpad か mktemp を使う)
out="$(mktemp)"
php devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php > "$out"
cp "$out" devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md
rm -f "$out"
```

### 実行の順序 (母集団は必ずコミット済みの差分から取る)

母集団を `main...HEAD` から取る以上、**検証の前に必ずコミットする**
(Codex 詳細レビュー Round 3 / Round 4 の Critical への対応)。

1. 実装を終え、**空の `rename-verification.md` を置いたうえで全部コミットする**
   (A-6e の一覧に載っているパスが差分に現れる状態にする = 「一覧にあるのに差分に現れない」で
   落ちないようにする)。
2. `git status --porcelain` が空であることを確かめ、**出力をリポジトリ外の一時ファイルへ受けて**
   スクリプトを実行する (結果ファイルへ直接リダイレクトしない)。
3. 一時ファイルの内容を `rename-verification.md` へ写し、**`--amend` で同じコミットへ畳む**。
4. **もう 1 度スクリプトを実行**し (これも一時ファイルへ受ける)、(i) 終了コード 0、
   (ii) 一時ファイルと `rename-verification.md` が `cmp` で一致 (出力が決定的なので変化しない)、
   (iii) 実行後も `git status --porcelain` が空、の 3 つを確認する。

この 4 段まで通って初めて A-6d を満たしたとする。作業ツリーを汚したまま緑にできる経路は
段 0 の clean 検査と段 4 の (iii) で塞ぐ。

> **保証範囲を誇張しない**: A-6 が示すのは「意図しない実行コード差分が無い」ことまでである。
> **振る舞いの同値性そのものを証明するものではない** (autoload・キャッシュ・
> リポジトリ外の実行手順・動的に組み立てるクラス名は対象外)。振る舞い側の担保は、
> 既存の Feature / Architecture テストと `composer test` 全体が受け持つ。

## 全検証コマンド (すべて green であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

- 前線は `composer test` と `composer phpstan` と `vendor/bin/pint --test` である
  (変更は PHP / シェル / 文書のみで、フロントには 1 行も触れない)。
- `pnpm` 系は**回帰していないことの確認**として全数実行する (AGENTS.md の検証コマンド節が
  `tests/js/architecture/verification-commands-doc-sync.test.ts` で同期を強制している)。
- `composer test:browser` は本施策の対象外だが、`tests/Pest.php` を触るため
  **実装セッションの判断で 1 度は流す** (Browser lane も同じ `enableFakeExternals()` を使う)。
- テストレーンはホスト全体で 1 本ずつしか走らない (T099)。待ちが出るのは正常であり、
  ロックファイルを消さない・kill しない。

## 保証しないもの / やらないと決めたこと

| 項目 | 判断 | 理由 |
|---|---|---|
| ファイル数の統合 (8 → 6) | やらない | **2026-08-10 の裁定で撤回済み**。やると裁定に反する |
| `config('testing.fake_externals')` / `TESTING_FAKE_EXTERNALS` の改名 | やらない | 台帳が不一致として挙げていない。設定キーと環境変数は `.env.bughunt.local` との外部契約で、改名は bug-hunt 環境の再設定を要求する破壊的変更になる |
| 検査ファイル名の家系への統一 (`ExternalFakeWiringInvariantTest` → `BughuntFakeWiringTest` 等) | やらない | 2026-08-16 の巡回が残要件として名指ししたのは本設計の 2 件だけである。広げるなら台帳の議題として起こす |
| `tests/Feature/Database/BughuntOAuthSeederGuardTest.php` の改名 | やらない | 台帳の gates は aicue のこの名前を**実在する検査**として登録しており、別名として問題視していない |
| テストの置き場所の移動 (`tests/Feature/Database/` → `tests/Feature/Bughunt/`) | やらない | 台帳は aicue の決済側テストを「**同じ位置に**別名で在る」と記録している = 位置は要件ではない |
| `docs/TODO-closed.md` の旧名 | 書き換えない (件数は pin する) | 完了した TODO の記録であり、当時の名前は当時の事実である。**旧名ごとの件数を完全一致で pin するので、増減も内訳の入れ替えも検出する** (沈黙しない) |
| `devnotes/` の旧名 | 書き換えない (走査から丸ごと外す) | 過去の設計・レビュー・走行記録であり、190 ファイル規模で旧名を含む。件数 pin が実務にならないため丸ごと外す。**帰結として、ここでは旧名の再流入に沈黙する** |
| 本検査ファイル自身の中の旧名 | 走査しない | 検出したい語を負のコントロールの入力として持つため。**帰結として、このファイルの中では沈黙する** (`RouteCacheExemptionPremiseTest` と同じ穴の持ち方) |
| 旧名からの別名 (`class_alias` / 継承した薄いクラス) の提供 | やらない | 思考原則 3 (後方互換の並走を残さない)。名前を 1 つにするのが目的なので、2 つ残したら要件を満たさない |
| 検査が「家系名として正しいこと」を保証すること | しない | 正本は台帳である。N-1 が固定するのは「旧名が現役資産に無いこと」まで |
| 字面以外の検出 (分割連結・動的生成) | しない | 走査は `substr_count` の字面照合である。誇張しない |
| 「振る舞いが同値である」ことの証明 | しない | A-6 が示すのは**意図しない実行コード差分が無いこと**までである。autoload・キャッシュ・リポジトリ外の実行手順・動的に組み立てるクラス名は対象外で、振る舞い側の担保は既存テストと `composer test` 全体が受け持つ |
| 台帳への書き込み (`append_event`) | 設計では行わない | 実装完了後に実装セッションが `status_reported` を出す (refs は push 済みの `<repo>@<commit>`) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 追跡下 32 ファイル・88 箇所に触れる横断的な改名であり、他タスクと同時に走らせると衝突が避けられない。逆に**変更の中身は名前の置換だけ**なので、単独で走らせれば短時間で終わる |
| 競合リスク | `tests/Pest.php` / `bootstrap/providers.php` / `docs/architecture.md` は他タスクも触りやすい。worktree 作成から main マージまでを 1 セッションで閉じ、途中で他タスクを挟まない |

---

## 実装セッションの逸脱 (設計との差分。ここを重点的に見てほしい)

1. **`docs/TODO.md` を件数 pin の対象に追加した (設計に無い)**
   設計の実測 (91 箇所 / 33 ファイル) は T214 の TODO 登録**前**の値であり、現在の HEAD では
   `docs/TODO.md` の T214 登録行そのものが旧名を 2 箇所 (旧 seeder 1 / 旧 provider 1) 含む。
   実測は 93 箇所 / 34 ファイルで、差は正確にこの 2 箇所である。
   丸ごと除外にせず `BUGHUNT_NAMING_KNOWN_MENTIONS` へ旧名ごとの件数 pin として足した。
   書き換えるのは 32 ファイル / 88 箇所で設計どおりである。
   なお T214 をクローズすると登録行が `docs/TODO.md` から `docs/TODO-closed.md` へ移り
   両方の pin が同時に動くため、その旨を定数の docblock に申し送りとして書いた。

2. **`vendor/bin/pint` の `ordered_imports` により 2 ファイルで `use` 行の順序が動いた**
   (`bootstrap/providers.php` / `tests/Support/Bughunt/BughuntSeedWiringInventory.php`)。
   受け入れ条件 A-6a は「逆置換してバイト一致」を要求するが、並べ替えのぶん一致しない。
   そこで検証スクリプトに分類 **A-6a-imports** を足し、「逆置換した新内容と旧内容の
   `use` 行を同じ規則で並べ替えたうえでバイト比較する」「並べ替えが起きていないファイルを
   この分類に置いたら不合格」という形で fail-closed に扱った。

3. **`composer dump-autoload` が必要だった**
   設計は「PSR-4 なので autoload は自動追従する」としていたが、本リポジトリの composer.json は
   `optimize-autoloader: true` であり classmap が生成されるため、改名後に
   `composer dump-autoload` を実行するまで旧名の classmap 項目が残った (追跡外の生成物であり
   リポジトリの差分には現れない)。

## A-6 検証スクリプトの実行結果

**この時点ではまだ未実行**である (母集団を `main...HEAD` から取る設計のため、コミット後に実行する)。
スクリプト本体 (`devnotes/.../verify-rename-only.php`) は差分に含まれているのでレビュー対象である。

## テスト結果

- `composer test`: 5636 tests / 5634 passed / **0 failed** / 2 skipped
- `composer phpstan`: 987 files / No errors
- `vendor/bin/pint --test`: pass
- 新設 `tests/Architecture/BughuntNamingResidualTest.php`: N-1〜N-5 の 5 test すべて緑
- 改名前の中間状態で赤を実測済み:
  - 施策 1 追加時点: N-1 が 32 ファイルを列挙して赤 / N-2 / N-5 も赤
  - 施策 2 の `git mv` 直後: `BughuntSeedWiringInvariantTest` の S-1 / S-9 が赤 (S-6 は include エラー)
  - 施策 3 の `git mv` 直後: `ExternalFakeWiringInvariantTest` 51 test が全部 error (provider が解決できない)

## 実装差分 (git diff -M)

```diff
diff --git a/.env.bughunt.local.example b/.env.bughunt.local.example
index 5c48a44..ef76d37 100644
--- a/.env.bughunt.local.example
+++ b/.env.bughunt.local.example
@@ -65,8 +65,8 @@ QUEUE_CONNECTION=sync
 # 外部サービス fake (Stripe 課金 gateway + captcha 検証器 + SSO driver 解決点) の capability flag
 # (LLM は別フラグ fake_llm に分離)。
 # config('testing.fake_externals') を通して fake セットを有効化する
-# (Stripe: FakeExternalsServiceProvider が checkout/portal gateway を fake に bind。
-#  fake は決済せず中立帰還する。課金状態の正本は BughuntBillingSeeder。
+# (Stripe: BughuntFakesServiceProvider が checkout/portal gateway を fake に bind。
+#  fake は決済せず中立帰還する。課金状態の正本は BughuntStripeSyncSeeder。
 #  captcha: RecaptchaVerifier を RecaptchaVerifierTestFake へ bind し Google siteverify へ出さない。
 #  SSO: SocialiteDriverResolver を FakeSocialiteDriverResolver へ bind し、SSO ボタンは
 #  自アプリの social.callback へ戻る (実 IdP へ出ない)。SSO の env allowlist は
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index ba0a432..c504bf9 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -127,11 +127,11 @@ public function register(): void
         $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);
 
         // サブスク Checkout / Customer Portal の Stripe 抽象。fake_externals 時は
-        // FakeExternalsServiceProvider が fake に rebind する (providers.php で後勝ち)
+        // BughuntFakesServiceProvider が fake に rebind する (providers.php で後勝ち)
         $this->app->bind(StripeGatewayInterface::class, CashierStripeGateway::class);
 
         // オートリチャージ (P8a) の Stripe 抽象 (setup Checkout / off-session invoice)。
-        // fake_externals 時は FakeExternalsServiceProvider が fake に rebind する
+        // fake_externals 時は BughuntFakesServiceProvider が fake に rebind する
         $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);
 
         // アプリ内通知 (T008): database channel を薄い拡張へ差し替え、AppNotification の
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/BughuntFakesServiceProvider.php
similarity index 99%
rename from app/Providers/FakeExternalsServiceProvider.php
rename to app/Providers/BughuntFakesServiceProvider.php
index 9de0530..a16e507 100644
--- a/app/Providers/FakeExternalsServiceProvider.php
+++ b/app/Providers/BughuntFakesServiceProvider.php
@@ -34,7 +34,7 @@
  * - LLM (Prism): LLM_FLAG。Prompt::$fake はプロセス大域の static のため container 差し替えではなく
  *   boot() で install する (宣言の swaps() には現れない)。
  */
-class FakeExternalsServiceProvider extends ServiceProvider
+class BughuntFakesServiceProvider extends ServiceProvider
 {
     public function register(): void
     {
diff --git a/app/Services/AI/Testing/CannedPromptFake.php b/app/Services/AI/Testing/CannedPromptFake.php
index c6bbf33..51d3044 100644
--- a/app/Services/AI/Testing/CannedPromptFake.php
+++ b/app/Services/AI/Testing/CannedPromptFake.php
@@ -22,7 +22,7 @@
  * record() は executePrism()/executePrismStructured() の fake 分岐で nextResponse() の
  * 直前に必ず呼ばれるため、$this->recorded の最新 entry が「今実行中の Prompt」を指す。
  *
- * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider::boot) の
+ * Browser lane (tests/Pest.php) と bughunt 実行時 (BughuntFakesServiceProvider::boot) の
  * 両方で共有される (Browser 専用ではない)。
  */
 final class CannedPromptFake extends PromptFake
diff --git a/app/Services/AI/Testing/CannedPromptFakeRegistrar.php b/app/Services/AI/Testing/CannedPromptFakeRegistrar.php
index 58fea60..8708f88 100644
--- a/app/Services/AI/Testing/CannedPromptFakeRegistrar.php
+++ b/app/Services/AI/Testing/CannedPromptFakeRegistrar.php
@@ -12,7 +12,7 @@
  * `laravel-prism-prompt` が提供する `Prompt::installFake(PromptFake)` 公開 API を使う。
  * 将来この API が変わった場合も影響範囲はここだけ。
  *
- * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider::boot) の
+ * Browser lane (tests/Pest.php) と bughunt 実行時 (BughuntFakesServiceProvider::boot) の
  * 両方から共有される (Browser 専用ではない)。
  */
 final class CannedPromptFakeRegistrar
diff --git a/app/Services/AI/Testing/CannedPromptResponses.php b/app/Services/AI/Testing/CannedPromptResponses.php
index ddff586..80a6c83 100644
--- a/app/Services/AI/Testing/CannedPromptResponses.php
+++ b/app/Services/AI/Testing/CannedPromptResponses.php
@@ -19,7 +19,7 @@
  * signature は各 YAML 固有の一意句 (DefensiveInstructions preamble は全 YAML 共通なので使わない)。
  *
  * 未一致 (0 件) / 曖昧 (2 件以上) はいずれも fail-fast で例外を投げ、silent false-positive を防ぐ。
- * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider) の双方で共有される。
+ * Browser lane (tests/Pest.php) と bughunt 実行時 (BughuntFakesServiceProvider) の双方で共有される。
  */
 final class CannedPromptResponses
 {
diff --git a/app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php b/app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php
index 7508088..5b6bdd6 100644
--- a/app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php
+++ b/app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php
@@ -11,7 +11,7 @@
  * SSO (Socialite) driver 解決点の fake。
  *
  * bug-hunt / 自動テストレーンのブラウザが SSO ボタンから**実 IdP へ出ないようにする**ための
- * 差し替え先。配線条件は `FakeExternalsServiceProvider::registerSocialAuthFake()`
+ * 差し替え先。配線条件は `BughuntFakesServiceProvider::registerSocialAuthFake()`
  * (`config('testing.fake_externals') === true` ∧ env ∈ {testing, bughunt.local})。
  */
 final class FakeSocialiteDriverResolver extends SocialiteDriverResolver
diff --git a/app/Services/Billing/Fakes/FakeStripeGateway.php b/app/Services/Billing/Fakes/FakeStripeGateway.php
index e1a7054..79bbeb9 100644
--- a/app/Services/Billing/Fakes/FakeStripeGateway.php
+++ b/app/Services/Billing/Fakes/FakeStripeGateway.php
@@ -15,7 +15,7 @@
 /**
  * StripeGatewayInterface の runtime fake (fake_externals 環境専用)。
  * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
- * (active subscription の正本は BughuntBillingSeeder)。
+ * (active subscription の正本は BughuntStripeSyncSeeder)。
  *
  * session id は **idempotency key から決定的に導出**する (Stripe の idempotency replay と
  * 同じ収束特性 = 同一 key の再呼び出しで同一 sessionId)。
@@ -45,14 +45,14 @@ public function swapSubscriptionPrices(
         string $idempotencyKey,
     ): SubscriptionSwapOutcome {
         // 中立帰還: 実 Stripe を叩かず、subscription 状態も変えない
-        // (active subscription の正本は BughuntBillingSeeder。反映は webhook が担うが
+        // (active subscription の正本は BughuntStripeSyncSeeder。反映は webhook が担うが
         //  fake 環境では webhook が発火しないため、画面は「反映待ち」までを観測する)。
         return SubscriptionSwapOutcome::Applied;
     }
 
     public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
     {
-        // 中立帰還: 契約状態の正本は BughuntBillingSeeder。突き合わせは何も収束させない。
+        // 中立帰還: 契約状態の正本は BughuntStripeSyncSeeder。突き合わせは何も収束させない。
         return null;
     }
 
diff --git a/app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php b/app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php
index 5a75583..6b8ac63 100644
--- a/app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php
+++ b/app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php
@@ -16,7 +16,7 @@
  * - session id は idempotency key から決定的に導出 (Stripe の idempotency replay と同じ収束特性)
  * - 遷移先はアプリ内帰還画面 ($cancelUrl) + 観測用 marker query `fake_external=stripe`。
  *   アプリはこの query を一切解釈しない (purchased 偽装なし / cancel の意味付けもなし)
- * - 決済・チケット付与・状態変更は一切行わない (課金状態の正本は BughuntBillingSeeder)
+ * - 決済・チケット付与・状態変更は一切行わない (課金状態の正本は BughuntStripeSyncSeeder)
  *
  * テスト専用 spy (Tests\Support\FakeTicketCheckoutGateway) とは責務が異なる:
  * spy は呼び出し記録と失敗注入を持つが、本クラスは無状態 stub (serve プロセスで動く前提)。
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index 59e67c1..d1142dd 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -402,7 +402,7 @@ public function reserve(Organization $organization, int $amount): TicketReservat
 
             $consumeSource = $availableMonthly >= $amount ? TicketSource::Monthly : TicketSource::Purchased;
             // monthly は最短の生きた月次期限を境界にする。AI-CUE には無期限 monthly grant
-            // (BughuntBillingSeeder / monthly_ticket_grant を戻した場合の invoice.paid) が実在するため
+            // (BughuntStripeSyncSeeder / monthly_ticket_grant を戻した場合の invoice.paid) が実在するため
             // null を許容する (null = 無期限 monthly からの消費 = 失効しない hold)
             $consumeExpiresAt = $consumeSource === TicketSource::Monthly
                 ? $this->nearestMonthlyExpiry($organization, $now)
@@ -819,7 +819,7 @@ private function expiredMonthlyHoldCondition(Builder $query, CarbonImmutable $no
      *
      * **現行は構造的に到達不能**: D28 で全 tier の monthly_ticket_grant = 0
      * (PlanSeederPriceInvariantTest が pin) のため、有限期限の monthly は org 生涯 1 回の
-     * signup grant のみ。BughuntBillingSeeder の 100 枚は無期限で本メソッドの対象外。
+     * signup grant のみ。BughuntStripeSyncSeeder の 100 枚は無期限で本メソッドの対象外。
      * **Filament PlanResource で monthly_ticket_grant を 1 以上へ戻すと窓が開く** ので、
      * その際は本メソッドの契約から見直すこと。挙動は TicketBalanceAccountingTest の
      * 「[既知窓]」2 本が機械的に固定している。
diff --git a/app/Support/ExternalFakes/ExternalFakeDeclaration.php b/app/Support/ExternalFakes/ExternalFakeDeclaration.php
index f7e284f..5d7b5db 100644
--- a/app/Support/ExternalFakes/ExternalFakeDeclaration.php
+++ b/app/Support/ExternalFakes/ExternalFakeDeclaration.php
@@ -28,11 +28,11 @@
 /**
  * 「どの外部到達点を、どのフラグと許可環境で、どの偽の実装へ差し替えるか」の唯一の正本。
  *
- * ★本番の読み込み対象 (app/) に置く。差し替えの配線 (FakeExternalsServiceProvider)・
+ * ★本番の読み込み対象 (app/) に置く。差し替えの配線 (BughuntFakesServiceProvider)・
  *   storage の有効化条件 (FakeStorageGate)・bug-hunt の投入データ (seeder)・
  *   本番混入防止 (ProductionEnvGuard) が**すべてここだけを読む** (同じ集合を 2 か所に書かない)。
  * ★本クラスは値を返すだけで判定を持たない。有効・無効の判定は
- *   FakeExternalsServiceProvider (container 差し替え) と FakeStorageGate (storage) が行う。
+ *   BughuntFakesServiceProvider (container 差し替え) と FakeStorageGate (storage) が行う。
  *
  * 関連する目録との責務境界:
  * - 本番コードが偽の実装のクラス名を参照しないことの全走査は FakeClassReferenceInvariantTest
diff --git a/app/Support/FakeStorageGate.php b/app/Support/FakeStorageGate.php
index 377d48d..397b07e 100644
--- a/app/Support/FakeStorageGate.php
+++ b/app/Support/FakeStorageGate.php
@@ -10,7 +10,7 @@
 /**
  * 偽の保存先の有効化条件の単一正本 (fail-secure 二軸)。
  *
- * 経路登録 (FakeExternalsServiceProvider) と署名付き経路の action guard の双方が
+ * 経路登録 (BughuntFakesServiceProvider) と署名付き経路の action guard の双方が
  * 本メソッドを参照する (登録条件より実行時条件が弱いと経路キャッシュ残存で素通りするため
  * 完全一致させる)。
  *
diff --git a/bootstrap/providers.php b/bootstrap/providers.php
index f2f574e..299ccb6 100644
--- a/bootstrap/providers.php
+++ b/bootstrap/providers.php
@@ -3,8 +3,8 @@
 declare(strict_types=1);
 
 use App\Providers\AppServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Providers\ExternalClientTimeoutServiceProvider;
-use App\Providers\FakeExternalsServiceProvider;
 use App\Providers\Filament\AdminPanelProvider;
 use App\Providers\FortifyServiceProvider;
 use App\Providers\McpPassportServiceProvider;
@@ -28,5 +28,5 @@
     SeoServiceProvider::class,
     // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
     // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
-    FakeExternalsServiceProvider::class,
+    BughuntFakesServiceProvider::class,
 ];
diff --git a/database/seeders/BughuntBillingSeeder.php b/database/seeders/BughuntStripeSyncSeeder.php
similarity index 90%
rename from database/seeders/BughuntBillingSeeder.php
rename to database/seeders/BughuntStripeSyncSeeder.php
index 359cf6b..d20d826 100644
--- a/database/seeders/BughuntBillingSeeder.php
+++ b/database/seeders/BughuntStripeSyncSeeder.php
@@ -39,7 +39,7 @@
  *
  * 依存は Seeder の method injection (run() 引数) で受ける (Laravel 公式作法。型安全)。
  */
-class BughuntBillingSeeder extends Seeder
+class BughuntStripeSyncSeeder extends Seeder
 {
     use Concerns\DetectsBughuntDatabase;
 
@@ -53,7 +53,7 @@ public function run(TicketLedgerService $tickets): void
             || ! app()->environment('bughunt.local')
             || ! $this->isBughuntDatabase()
         ) {
-            $this->command->warn('BughuntBillingSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');
+            $this->command->warn('BughuntStripeSyncSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');
 
             return;
         }
@@ -62,7 +62,7 @@ public function run(TicketLedgerService $tickets): void
 
         $paidPlanCodes = $this->paidPlanCodes();
         if ($paidPlanCodes === []) {
-            $this->command->warn('BughuntBillingSeeder: 有料プランが無いため skip。先に PlanSeeder を流すこと。');
+            $this->command->warn('BughuntStripeSyncSeeder: 有料プランが無いため skip。先に PlanSeeder を流すこと。');
 
             return;
         }
@@ -80,7 +80,7 @@ public function run(TicketLedgerService $tickets): void
             );
         }
 
-        $this->command->info("BughuntBillingSeeder: {$organizations->count()} 組織に active subscription + チケット".self::INITIAL_TICKET_GRANT.' 枚を付与。');
+        $this->command->info("BughuntStripeSyncSeeder: {$organizations->count()} 組織に active subscription + チケット".self::INITIAL_TICKET_GRANT.' 枚を付与。');
     }
 
     /**
@@ -103,7 +103,7 @@ private function grandfatherUncontractedOrganizations(): void
                 'updated_at' => $now,
             ]);
 
-        $this->command->info("BughuntBillingSeeder: 未契約 {$count} 組織に declarer-less な free entitlement を付与。");
+        $this->command->info("BughuntStripeSyncSeeder: 未契約 {$count} 組織に declarer-less な free entitlement を付与。");
     }
 
     /**
diff --git a/devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php b/devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php
new file mode 100644
index 0000000..747a1ce
--- /dev/null
+++ b/devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php
@@ -0,0 +1,387 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * T214 の受け入れ条件 A-6 (意図しない実行コード差分が無いこと) を再実行できる形にする一時スクリプト。
+ *
+ * 恒久化しない (1 回きりの改名の検証であり scripts/README.md の台帳に載せる性質ではない)。
+ *
+ * 使い方 (リポジトリルートで実行):
+ *   out="$(mktemp)"
+ *   php devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php > "$out"
+ *   cp "$out" devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md
+ *   rm -f "$out"
+ *
+ * ★出力は決定的である (時刻・実行環境・コミットハッシュを含めない)。
+ * ★結果ファイルを自分では書かない (直接リダイレクトすると段 0 の clean 検査が自分の副作用で落ちる)。
+ *
+ * ★保証範囲を誇張しない: 示せるのは「意図しない実行コード差分が無い」ことまでである。
+ *   振る舞いの同値性そのものは証明しない (autoload・キャッシュ・リポジトリ外の実行手順・
+ *   動的に組み立てるクラス名は対象外)。振る舞い側は既存テストと composer test 全体が受け持つ。
+ */
+
+const RENAMES = [
+    'BughuntStripeSyncSeeder' => 'BughuntBillingSeeder',
+    'BughuntFakesServiceProvider' => 'FakeExternalsServiceProvider',
+];
+
+/**
+ * A-6b: 名前の置換に加えて**意味も更新した**ファイルと、許可する追加。
+ *
+ * 逆置換した新内容のトークン列が、旧内容のトークン列へ下記の挿入を施したものと
+ * **完全一致**することを要求する (それ以外のコード差分が 1 トークンでもあれば不合格)。
+ * 削除側は挿入だけを許す構成上ゼロである。
+ *
+ * トークン列はコメント・docblock・空白を落として 1 個の空白で連結したものである
+ * (= コメントの書き換えは自由、実行トークンは固定)。
+ *
+ * @var array<string, list<array{after: string, insert: string}>>
+ */
+const TOKEN_LEVEL_FILES = [
+    // docblock (用途 2 の明記) だけを更新した = 実行トークンの差分はゼロ
+    'tests/Support/ExternalFakes/FakeClassCatalog.php' => [],
+
+    // 3-10 の候補集合へ配置例外のキーを足す
+    'tests/Architecture/ExternalFakeWiringInvariantTest.php' => [
+        [
+            'after' => 'FakeClassCatalog :: namedClasses ( ) ,',
+            'insert' => 'array_keys ( FakeClassCatalog :: placementExceptions ( ) ) ,',
+        ],
+    ],
+
+    // 4-3 へ候補集合の明示 assertion を足す
+    'tests/Architecture/FakeClassReferenceInvariantTest.php' => [
+        [
+            'after' => 'expect ( $candidates ) -> not -> toBeEmpty ( ) -> and ( $files ) -> not -> toBeEmpty ( ) ;',
+            'insert' => 'expect ( $candidates ) -> toContain ( FakeExternalsServiceProvider :: class ) '
+                .'-> and ( $candidates ) -> toContain ( FakeStorageGate :: class ) ;',
+        ],
+    ],
+];
+
+/**
+ * A-6a のうち **import の並べ替えを伴う**ファイル。
+ *
+ * `vendor/bin/pint` の `ordered_imports` が強制するため、名前を変えると `use` 行の順序が動く。
+ * 逆置換した新内容と旧内容の**`use` 行を同じ規則で並べ替えたうえで**バイト比較する
+ * (= 並べ替え以外の差分は 1 バイトも許さない)。並べ替えが実際に起きていない
+ * (= 素のバイト比較で一致する) ファイルをここに置くと不合格にする (分類の誤用を通さない)。
+ *
+ * @var list<string>
+ */
+const IMPORT_REORDERED_FILES = [
+    'bootstrap/providers.php',
+    'tests/Support/Bughunt/BughuntSeedWiringInventory.php',
+];
+
+/**
+ * A-6c: 新規の恒久資産 (旧内容が無いので逆置換の比較対象にしない)。
+ *
+ * @var list<string>
+ */
+const NEW_PERMANENT_FILES = [
+    'tests/Architecture/BughuntNamingResidualTest.php',
+];
+
+/**
+ * A-6e: 設計・レビュー記録・検証の道具 (**パスの明示一覧**)。
+ *
+ * ここに載せてよいのは本 devnotes ディレクトリ配下と TODO 台帳だけである
+ * (`app/` `tests/` `database/` `scripts/` `config/` `bootstrap/` `docs/` の他ファイルを
+ * 逃がすことは禁止する)。
+ *
+ * @var list<string>
+ */
+const META_FILES = [
+    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/rename-verification.md',
+    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php',
+];
+
+/** A-6e に載せてよいパスの接頭辞 (これ以外が META_FILES にあれば不合格) */
+const META_ALLOWED_PREFIXES = [
+    'devnotes/20260817-1309-todo-t214-bughunt-family-naming/',
+    'docs/TODO.md',
+    'docs/TODO-closed.md',
+];
+
+/**
+ * コマンドを実行して標準出力を返す (失敗したら例外)。
+ *
+ * @param  list<string>  $command
+ */
+function run(array $command): string
+{
+    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+    $process = proc_open($command, $descriptors, $pipes);
+
+    if (! is_resource($process)) {
+        throw new RuntimeException('コマンドを起動できない: '.implode(' ', $command));
+    }
+
+    $stdout = (string) stream_get_contents($pipes[1]);
+    $stderr = (string) stream_get_contents($pipes[2]);
+    fclose($pipes[1]);
+    fclose($pipes[2]);
+    $status = proc_close($process);
+
+    if ($status !== 0) {
+        throw new RuntimeException(
+            'コマンドが失敗した ('.implode(' ', $command)."): {$stderr}"
+        );
+    }
+
+    return $stdout;
+}
+
+/** 新内容へ逆置換 (家系名 → 旧名) を掛ける */
+function reverseSubstitute(string $content): string
+{
+    return str_replace(array_keys(RENAMES), array_values(RENAMES), $content);
+}
+
+/** PHP ソースのトークン列 (コメント・docblock・空白を落として 1 個の空白で連結) */
+function normalizedTokens(string $source): string
+{
+    $parts = [];
+
+    foreach (token_get_all($source) as $token) {
+        if (! is_array($token)) {
+            $parts[] = $token;
+
+            continue;
+        }
+
+        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
+            continue;
+        }
+
+        $parts[] = $token[1];
+    }
+
+    return implode(' ', $parts);
+}
+
+/** `use ...;` 行を同じ規則で並べ替える (順序以外の差分だけを残す) */
+function sortUseLines(string $content): string
+{
+    $lines = explode("\n", $content);
+    $indices = [];
+    $useLines = [];
+
+    foreach ($lines as $index => $line) {
+        if (preg_match('/^use\s[^;]*;$/', $line) === 1) {
+            $indices[] = $index;
+            $useLines[] = $line;
+        }
+    }
+
+    sort($useLines, SORT_STRING);
+
+    foreach ($indices as $position => $index) {
+        $lines[$index] = $useLines[$position];
+    }
+
+    return implode("\n", $lines);
+}
+
+/** main 上の内容を取る (存在しなければ例外) */
+function contentOnMain(string $path): string
+{
+    return run(['git', 'show', 'main:'.$path]);
+}
+
+$failures = [];
+$rows = [];
+
+// 段 0: 作業ツリーが clean であること (母集団は main...HEAD から取るので未コミットは見えない)
+$status = run(['git', 'status', '--porcelain']);
+if (trim($status) !== '') {
+    $failures[] = '段 0: 作業ツリーが clean ではない (未コミットの変更は母集団に現れないため検証にならない)';
+}
+
+// A-6e の置き場所の制約 (メタ成果物という名目で本体の差分を逃がせないようにする)
+foreach (META_FILES as $path) {
+    $allowed = false;
+    foreach (META_ALLOWED_PREFIXES as $prefix) {
+        if (str_starts_with($path, $prefix)) {
+            $allowed = true;
+        }
+    }
+    if (! $allowed) {
+        $failures[] = "A-6e: 許可されていない置き場所が明示一覧に載っている: {$path}";
+    }
+}
+
+// 明示一覧どうしの重複
+$explicit = [
+    'A-6b' => array_keys(TOKEN_LEVEL_FILES),
+    'A-6a-imports' => IMPORT_REORDERED_FILES,
+    'A-6c' => NEW_PERMANENT_FILES,
+    'A-6e' => META_FILES,
+];
+$seen = [];
+foreach ($explicit as $category => $paths) {
+    foreach ($paths as $path) {
+        if (isset($seen[$path])) {
+            $failures[] = "分類の重複: {$path} ({$seen[$path]} と {$category})";
+        }
+        $seen[$path] = $category;
+    }
+}
+
+// 段 1: 母集団
+$diff = run(['git', 'diff', '--name-status', '-M', 'main...HEAD']);
+$entries = [];
+foreach (explode("\n", trim($diff)) as $line) {
+    if ($line === '') {
+        continue;
+    }
+
+    $fields = explode("\t", $line);
+    $symbol = substr($fields[0], 0, 1);
+
+    if ($symbol === 'R') {
+        $entries[$fields[2]] = ['symbol' => 'R', 'old' => $fields[1]];
+
+        continue;
+    }
+
+    $entries[$fields[1]] = ['symbol' => $symbol, 'old' => $fields[1]];
+}
+ksort($entries);
+
+// 一覧にあるのに差分へ現れないパス (設計と実装のずれ)
+foreach ($explicit as $category => $paths) {
+    foreach ($paths as $path) {
+        if (! isset($entries[$path])) {
+            $failures[] = "{$category} の一覧にあるのに差分へ現れない: {$path}";
+        }
+    }
+}
+
+// 段 2〜4: 分類と判定
+foreach ($entries as $path => $entry) {
+    $category = $seen[$path] ?? null;
+
+    if ($entry['symbol'] === 'D') {
+        $failures[] = "削除は本施策に無い: {$path}";
+        $rows[] = [$path, 'D', '不合格 (削除)'];
+
+        continue;
+    }
+
+    if ($entry['symbol'] === 'A' && $category === null) {
+        $failures[] = "新規なのに A-6c / A-6e への明示登録が無い: {$path}";
+        $rows[] = [$path, 'A', '不合格 (未分類)'];
+
+        continue;
+    }
+
+    if ($category === 'A-6c' || $category === 'A-6e') {
+        $rows[] = [$path, $entry['symbol'], "{$category} (比較対象外)"];
+
+        continue;
+    }
+
+    // ここから先は旧内容との比較が要る (R / M)
+    if ($entry['symbol'] === 'A') {
+        $failures[] = "新規を比較対象の分類へ入れている: {$path}";
+        $rows[] = [$path, 'A', '不合格 (分類の誤り)'];
+
+        continue;
+    }
+
+    $old = contentOnMain($entry['old']);
+    $new = (string) file_get_contents($path);
+    $reversed = reverseSubstitute($new);
+
+    if ($category === 'A-6b') {
+        $expected = normalizedTokens($old);
+
+        foreach (TOKEN_LEVEL_FILES[$path] as $insertion) {
+            $occurrences = substr_count($expected, $insertion['after']);
+            if ($occurrences !== 1) {
+                $failures[] = "A-6b: 挿入位置の目印が {$occurrences} 箇所 (1 箇所であること): {$path}";
+
+                continue 2;
+            }
+            $expected = str_replace(
+                $insertion['after'],
+                $insertion['after'].' '.$insertion['insert'],
+                $expected
+            );
+        }
+
+        if (normalizedTokens($reversed) !== $expected) {
+            $failures[] = "A-6b: 許可した追加以外の実行トークン差分がある: {$path}";
+            $rows[] = [$path, $entry['symbol'], '不合格 (A-6b)'];
+
+            continue;
+        }
+
+        $rows[] = [$path, $entry['symbol'], 'A-6b 合格 (許可した追加のみ)'];
+
+        continue;
+    }
+
+    if ($category === 'A-6a-imports') {
+        if ($reversed === $old) {
+            $failures[] = "A-6a-imports: 並べ替えが起きていない (A-6a へ移すこと): {$path}";
+            $rows[] = [$path, $entry['symbol'], '不合格 (分類の誤用)'];
+
+            continue;
+        }
+
+        if (sortUseLines($reversed) !== sortUseLines($old)) {
+            $failures[] = "A-6a-imports: import の並べ替え以外の差分がある: {$path}";
+            $rows[] = [$path, $entry['symbol'], '不合格 (A-6a-imports)'];
+
+            continue;
+        }
+
+        $rows[] = [$path, $entry['symbol'], 'A-6a-imports 合格 (import 順のみ)'];
+
+        continue;
+    }
+
+    // 分類なし = A-6a (名前の置換だけ)
+    if ($reversed !== $old) {
+        $failures[] = "A-6a: 逆置換しても旧内容とバイト一致しない: {$path}";
+        $rows[] = [$path, $entry['symbol'], '不合格 (A-6a)'];
+
+        continue;
+    }
+
+    $rows[] = [$path, $entry['symbol'], 'A-6a 合格 (名前の置換のみ)'];
+}
+
+usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
+sort($failures, SORT_STRING);
+
+echo "# T214 改名の差分検証 (A-6)\n\n";
+echo "`php devnotes/20260817-1309-todo-t214-bughunt-family-naming/verify-rename-only.php` の出力そのままである。\n\n";
+echo "| ファイル | 状態 | 判定 |\n|---|---|---|\n";
+foreach ($rows as [$path, $symbol, $verdict]) {
+    echo "| `{$path}` | {$symbol} | {$verdict} |\n";
+}
+
+echo "\n";
+echo '- 対象ファイル数: '.count($rows)."\n";
+echo '- 不合格: '.count($failures)."\n";
+
+if ($failures !== []) {
+    echo "\n## 不合格の内訳\n\n";
+    foreach ($failures as $failure) {
+        echo "- {$failure}\n";
+    }
+
+    exit(1);
+}
+
+echo "\n判定: 合格 (意図しない実行コード差分は無い)。\n";
+echo "\n";
+echo "> 保証範囲: 示せるのはここまでである。振る舞いの同値性そのものは証明しない\n";
+echo "> (autoload・キャッシュ・リポジトリ外の実行手順・動的に組み立てるクラス名は対象外)。\n";
+
+exit(0);
diff --git a/docs/architecture.md b/docs/architecture.md
index 03aa6ed..e80aad1 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1683,7 +1683,7 @@ ## 偽の外部サービスの宣言と配線の不変条件 (T119 / T177)
   capability flag / 許可環境 / 差し替えない対象もここが持つ。
   provider・`FakeStorageGate`・bug-hunt の seeder・`ProductionEnvGuard`・
   bug-hunt の環境ひな型検査は**すべてこの宣言を読む** (同じ集合を 2 か所に書かない)。
-- **差し替えの唯一の配線点は `App\Providers\FakeExternalsServiceProvider`**。container 差し替えは
+- **差し替えの唯一の配線点は `App\Providers\BughuntFakesServiceProvider`**。container 差し替えは
   `$this->app->bind($swap->abstract, $swap->fake)` の形**だけ**で行う。
   **`::class` を直に書く bind は許可形から外れる** = 差し替え先の決定は宣言側にしか無い
   (`singleton()` / 第 3 引数 (= singleton 相当) / 変数 abstract / closure concrete /
@@ -1714,7 +1714,7 @@ ## 偽の外部サービスの宣言と配線の不変条件 (T119 / T177)
   false でも、キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうるため。
   解釈できない値 (`maybe` / 非文字列) は安全側で違反にする。fake 配線 gate はこれを二重実装しない。
 - **fake 実装クラスは `app/**/Fakes/` か `app/**/Testing/` に置く**。配置例外は
-  `FakeExternalsServiceProvider` (唯一の配線点) と `FakeStorageGate` (有効化条件) の 2 件のみ。
+  `BughuntFakesServiceProvider` (唯一の配線点) と `FakeStorageGate` (有効化条件) の 2 件のみ。
 - **本番コード (`app/` • `routes/` • `config/` • `bootstrap/`) は fake クラスを参照しない**。
   参照してよいのは宣言・配線点・偽の保存先の署名付き経路の受け口を含む 6 ファイルだけで、
   allowlist の件数はテストが固定している (増やすには理由コメントと併せて 2 箇所を触る摩擦がかかる)。
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index 4a052f1..d923ecf 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -157,7 +157,7 @@ ## LLM fake (in-process)
 2. **CannedPromptFake** (`app/Services/AI/Testing/`): `Prompt` 実行を SystemMessage の役割文
    (signature) 単位の決定論 canned response に差し替える (sequence 枯渇しない無限供給)。
    `CannedPromptFakeRegistrar` が `Prompt::installFake()` で beforeEach ごとにインストールする。
-   この canned 機構は bughunt 実行時 (`FakeExternalsServiceProvider::boot`) とも共有される。
+   この canned 機構は bughunt 実行時 (`BughuntFakesServiceProvider::boot`) とも共有される。
 
 さらに `phpunit.browser.xml` が LLM provider API キーをダミー値で `<server force>` する
 (guard が万一無効化された場合の最終防壁。phpunit.xml と同じ 3 プロバイダ)。
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index 26d0dfd..1827885 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -1118,7 +1118,7 @@ cmd_provision() {
     artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
     # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
     # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
-    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
+    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntStripeSyncSeeder --force
     # 管理画面 (Filament admin) 探索用 admin user。AdminUserSeeder は local 限定 (DatabaseSeeder が
     # local でしか呼ばない) のため bughunt では明示 seed する。admin MFA は .env.bughunt.local の
     # ADMIN_MFA_REQUIRED=false で無効化済 (email+password ログイン可)。
@@ -1344,7 +1344,7 @@ cmd_reseed() {
     artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
     # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
     # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
-    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
+    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntStripeSyncSeeder --force
     artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
     artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
     echo "reseeded: ${db}"
diff --git a/tests/Architecture/BughuntNamingResidualTest.php b/tests/Architecture/BughuntNamingResidualTest.php
new file mode 100644
index 0000000..48cffe9
--- /dev/null
+++ b/tests/Architecture/BughuntNamingResidualTest.php
@@ -0,0 +1,270 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+
+/*
+ * 家系 (機能台帳 lctl の機能 bughunt-runtime) で 1 つに決まっている名前が、
+ * 旧名へ戻らないことの固定。
+ *
+ * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
+ * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
+ * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
+ *
+ * ★保証範囲を誇張しない:
+ *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
+ *     動的に組み立てた文字列には**沈黙する**。
+ *   - **丸ごと除外した分類 (c) の中では沈黙する**。分類 (b) は登録済みの件数だけを許容し、
+ *     増減も旧名ごとの内訳の入れ替えも検出する (沈黙しない)。
+ *   - 家系名が「正しい名前であること」は検査できない。正本は機能台帳であり、
+ *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * 退役した名前 → 家系の名前。
+ *
+ * 出典は機能台帳の機能 bughunt-runtime
+ * (aigenba / metamovics / laravel-claude-template の実測記録)。
+ *
+ * @var array<string, string>
+ */
+const BUGHUNT_RETIRED_NAMES = [
+    'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
+    'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
+];
+
+/**
+ * (b) 旧名を持つことが確認済みの**過去と現在の記録**と、**旧名ごとの**件数。
+ *
+ * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` /
+ * `ROUTE_CACHE_PREMISE_KNOWN_MENTIONS` と同じ作法)。丸ごと除外にしないのは、
+ * 除外したファイルの中で将来 旧名が再流入しても沈黙してしまうためである。
+ *
+ * ★合計ではなく**旧名ごと**に固定する。合計だけを見ると「片方を 1 件減らして
+ *   もう片方を 1 件増やす」書き換えが緑のまま通る。
+ *
+ * - `docs/TODO-closed.md`: 完了した TODO の記録。T015 / T119 が当時作ったクラスの
+ *   名前は当時の事実であり、書き換えると記録が嘘になる。
+ * - `docs/TODO.md`: 本件 (T214) の登録行そのものが「どの名前をどの名前へ改名するか」を
+ *   書いているため旧名を含む。これも記録であって現役の資産ではない。
+ *
+ * ★**TODO 台帳を動かすときは本 pin も同じ変更の中で更新する** (意図的な摩擦)。
+ *   T214 をクローズすると登録行が `docs/TODO.md` から `docs/TODO-closed.md` へ移り、
+ *   両ファイルの件数が同時に動く。そのときは「記録を書き換える」のではなく
+ *   **pin の数を直す**のが正しい直し方である。
+ *
+ * @var array<string, array<string, int>>
+ */
+const BUGHUNT_NAMING_KNOWN_MENTIONS = [
+    'docs/TODO-closed.md' => [
+        'BughuntBillingSeeder' => 1,
+        'FakeExternalsServiceProvider' => 2,
+    ],
+    'docs/TODO.md' => [
+        'BughuntBillingSeeder' => 1,
+        'FakeExternalsServiceProvider' => 1,
+    ],
+];
+
+/**
+ * (c) 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
+ *
+ * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、件数 pin が
+ * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
+ * 除外するのと同じ扱い)。
+ *
+ * ★**保証の穴として明記する**: ここでは旧名の再流入に沈黙する。
+ *
+ * @var list<string>
+ */
+const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];
+
+/**
+ * (c) 丸ごと走査から外す唯一のファイル = 本テスト自身。
+ *
+ * 検出したい語を負のコントロールの入力として持つため、自分を走査すると必ず自分で赤くなる。
+ * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
+ */
+const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';
+
+/**
+ * 走査の母集団が空振りでないことを確かめる代表パス (改名後に実在するもの)。
+ *
+ * @var list<string>
+ */
+const BUGHUNT_NAMING_SENTINEL_PATHS = [
+    'bootstrap/providers.php',
+    'scripts/bug-hunt-shard.sh',
+    'database/seeders/BughuntStripeSyncSeeder.php',
+    'app/Providers/BughuntFakesServiceProvider.php',
+];
+
+/** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
+const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;
+
+/**
+ * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
+ *
+ * @return list<string>
+ */
+function bughuntNamingViolationsIn(string $relative, string $content): array
+{
+    if ($relative === BUGHUNT_NAMING_SELF_PATH) {
+        return [];
+    }
+
+    foreach (BUGHUNT_NAMING_EXCLUDED_PREFIXES as $prefix) {
+        if (str_starts_with($relative, $prefix)) {
+            return [];
+        }
+    }
+
+    // 記録は「0 件」ではなく「pin した件数ちょうど」を旧名ごとに要求する。
+    $pinned = BUGHUNT_NAMING_KNOWN_MENTIONS[$relative] ?? [];
+
+    $violations = [];
+    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
+        $count = substr_count($content, $retired);
+        $allowed = $pinned[$retired] ?? 0;
+
+        if ($count === $allowed) {
+            continue;
+        }
+
+        $violations[] = $allowed === 0
+            ? "{$relative}: {$retired} が {$count} 箇所残っている (家系名は {$canonical})"
+            : "{$relative}: {$retired} の出現が {$count} 箇所 (pin は {$allowed} 箇所)";
+    }
+
+    return $violations;
+}
+
+/**
+ * git 追跡下の全ファイル (repo 相対パス、昇順)。
+ *
+ * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る) ため
+ *   `Tests\Support\TrackedPhpSourceFiles` は使えない。共用クラスを新設せず本テスト内に閉じる。
+ * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
+ *
+ * @return list<string>
+ */
+function bughuntNamingTrackedFiles(): array
+{
+    $process = new Process(['git', 'ls-files', '-z'], base_path());
+    $process->run();
+
+    if (! $process->isSuccessful()) {
+        throw new RuntimeException(
+            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
+            .$process->getErrorOutput()
+        );
+    }
+
+    $files = [];
+    foreach (explode("\0", $process->getOutput()) as $relative) {
+        if ($relative === '') {
+            continue;
+        }
+
+        $files[] = $relative;
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * 追跡下ファイルの中身を読む (読み取り失敗を空文字で握り潰さない)。
+ *
+ * 走査結果が空であることを「違反なし」と解釈する gate なので、読めなかったファイルは
+ * 必ず名指しで落とす。
+ */
+function bughuntNamingSourceOf(string $relative): string
+{
+    $absolute = base_path($relative);
+    $content = @file_get_contents($absolute);
+
+    if (! is_string($content)) {
+        throw new RuntimeException("追跡下ファイルを読み取れない (旧名の残留検査の走査対象): {$relative}");
+    }
+
+    return $content;
+}
+
+test('N-1 追跡下の現役資産に旧名が 1 つも残っておらず、記録は pin した件数ちょうどである', function (): void {
+    $violations = [];
+
+    foreach (bughuntNamingTrackedFiles() as $relative) {
+        foreach (bughuntNamingViolationsIn($relative, bughuntNamingSourceOf($relative)) as $violation) {
+            $violations[] = $violation;
+        }
+    }
+
+    expect($violations)->toBe([]);
+});
+
+test('N-2 fail-closed: 走査の母集団が空振りしていない', function (): void {
+    $files = bughuntNamingTrackedFiles();
+
+    expect(count($files))->toBeGreaterThanOrEqual(
+        BUGHUNT_NAMING_MINIMUM_TRACKED_FILES,
+        '追跡下ファイルの列挙が少なすぎます (git ls-files が期待どおり動いていない可能性)',
+    );
+
+    foreach (BUGHUNT_NAMING_SENTINEL_PATHS as $sentinel) {
+        expect($files)->toContain($sentinel);
+    }
+});
+
+test('N-3 走査の外し方が意図どおり (ファイル数ではなく**定義の数**を固定する)', function (): void {
+    // 丸ごと除外の定義は 2 つちょうど (接頭辞 devnotes/ が 1 件 + 本テスト自身が 1 件)。
+    expect(BUGHUNT_NAMING_EXCLUDED_PREFIXES)->toBe(['devnotes/'])
+        ->and(BUGHUNT_NAMING_SELF_PATH)->toBe('tests/Architecture/BughuntNamingResidualTest.php');
+
+    // 件数 pin の定義は 2 ファイル分ちょうど (TODO 台帳の 2 冊)。旧名は 2 種とも書く。
+    expect(array_keys(BUGHUNT_NAMING_KNOWN_MENTIONS))->toBe(['docs/TODO-closed.md', 'docs/TODO.md']);
+
+    foreach (BUGHUNT_NAMING_KNOWN_MENTIONS as $pinned) {
+        expect(array_keys($pinned))->toBe(array_keys(BUGHUNT_RETIRED_NAMES));
+    }
+
+    // 退役した名前は 2 つで、家系名と 1:1 に対応する。
+    expect(BUGHUNT_RETIRED_NAMES)->toBe([
+        'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
+        'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
+    ]);
+});
+
+test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
+    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
+    $seeder = $retired[0];
+    $provider = $retired[1];
+
+    // (a) 現役資産に旧名があれば検出する
+    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}"))->toHaveCount(1);
+
+    // (b) devnotes/ は丸ごと外れている (沈黙する)
+    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}"))->toBe([]);
+
+    // (c) 本テスト自身も丸ごと外れている (沈黙する)
+    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}"))->toBe([]);
+
+    // (d) pin したファイルで件数がずれたら検出する (少なくても多くても)
+    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$provider} {$provider}"))->toBe([]);
+    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$provider} {$provider}"))->toHaveCount(1);
+    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$seeder} {$provider} {$provider}"))->toHaveCount(1);
+
+    // (e) 合計は同じだが内訳が違う入力も検出する (旧名ごとに固定しているため)
+    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$provider} {$provider} {$provider}"))->toHaveCount(2);
+});
+
+test('N-5 旧名のクラスは存在せず、家系名のクラスが存在する', function (): void {
+    expect(class_exists('Database\Seeders\BughuntBillingSeeder'))->toBeFalse()
+        ->and(class_exists('App\Providers\FakeExternalsServiceProvider'))->toBeFalse()
+        ->and(class_exists('Database\Seeders\BughuntStripeSyncSeeder'))->toBeTrue()
+        ->and(class_exists('App\Providers\BughuntFakesServiceProvider'))->toBeTrue();
+});
diff --git a/tests/Architecture/ExternalFakeWiringInvariantTest.php b/tests/Architecture/ExternalFakeWiringInvariantTest.php
index c9c0568..1d28c16 100644
--- a/tests/Architecture/ExternalFakeWiringInvariantTest.php
+++ b/tests/Architecture/ExternalFakeWiringInvariantTest.php
@@ -5,7 +5,7 @@
 use App\Http\Controllers\Testing\GetFakeStorageObjectController;
 use App\Http\Controllers\Testing\PutFakeStorageObjectController;
 use App\Providers\AppServiceProvider;
-use App\Providers\FakeExternalsServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
 use App\Services\Auth\SocialiteDriverResolver;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
@@ -66,8 +66,8 @@
  * (Pest のファイル直下 const / function はグローバル空間に出る)。
  */
 const EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE = [
-    'M3' => 'bootstrap/providers.php に FakeExternalsServiceProvider が登録されている',
-    'M4' => 'FakeExternalsServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
+    'M3' => 'bootstrap/providers.php に BughuntFakesServiceProvider が登録されている',
+    'M4' => 'BughuntFakesServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
     'M5' => 'provider は差し替え先のクラス名を 1 つも参照しない (決定は宣言側だけにある)',
     'M6' => 'provider の container 呼び出しは許可された形だけ',
     'M7' => '本番コードは fake クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
@@ -96,7 +96,7 @@
 /** 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
 function externalFakeWiringProviderSource(): string
 {
-    return FakeClassCatalog::sourceOf('app/Providers/FakeExternalsServiceProvider.php');
+    return FakeClassCatalog::sourceOf('app/Providers/BughuntFakesServiceProvider.php');
 }
 
 /**
@@ -161,7 +161,7 @@ function (ExternalFakeBinding $binding, string $environment): void {
             $this->app['env'] = $environment;
             config([$binding->flag => true]);
 
-            (new FakeExternalsServiceProvider($this->app))->register();
+            (new BughuntFakesServiceProvider($this->app))->register();
 
             // ★厳密一致 (instanceof は使わない。storage fake は real のサブクラス)
             expect(app($binding->abstract)::class)->toBe($binding->fake);
@@ -181,7 +181,7 @@ function (ExternalFakeBinding $binding, string $environment): void {
             $this->app['env'] = $environment;
             config([$binding->flag => true]);
 
-            (new FakeExternalsServiceProvider($this->app))->register();
+            (new BughuntFakesServiceProvider($this->app))->register();
 
             expect(app($binding->abstract)::class)->toBe($binding->real);
         } finally {
@@ -201,7 +201,7 @@ function (ExternalFakeBinding $binding, string $environment): void {
         $this->app['env'] = 'staging';
         config([ExternalFakeDeclaration::EXTERNALS_FLAG => true]);
 
-        (new FakeExternalsServiceProvider($this->app))->register();
+        (new BughuntFakesServiceProvider($this->app))->register();
 
         Log::shouldHaveReceived('warning')->once();
     } finally {
@@ -210,14 +210,14 @@ function (ExternalFakeBinding $binding, string $environment): void {
     }
 });
 
-test('3-5 登録点: bootstrap/providers.php に FakeExternalsServiceProvider が登録されている', function (): void {
-    expect(externalFakeWiringRegisteredProviders())->toContain(FakeExternalsServiceProvider::class);
+test('3-5 登録点: bootstrap/providers.php に BughuntFakesServiceProvider が登録されている', function (): void {
+    expect(externalFakeWiringRegisteredProviders())->toContain(BughuntFakesServiceProvider::class);
 });
 
-test('3-6 登録点: FakeExternalsServiceProvider は AppServiceProvider より後 (後勝ち)', function (): void {
+test('3-6 登録点: BughuntFakesServiceProvider は AppServiceProvider より後 (後勝ち)', function (): void {
     $providers = externalFakeWiringRegisteredProviders();
 
-    $fakeIndex = array_search(FakeExternalsServiceProvider::class, $providers, true);
+    $fakeIndex = array_search(BughuntFakesServiceProvider::class, $providers, true);
     $appIndex = array_search(AppServiceProvider::class, $providers, true);
 
     expect($fakeIndex)->toBeInt()
@@ -226,7 +226,7 @@ function (ExternalFakeBinding $binding, string $environment): void {
 });
 
 test('3-7 登録点: 起動済み container に provider がロードされている', function (): void {
-    expect(array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
+    expect(array_key_exists(BughuntFakesServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
 });
 
 test('3-9 網羅性: provider の container 呼び出しは許可された形だけ', function (): void {
@@ -237,9 +237,13 @@ function (ExternalFakeBinding $binding, string $environment): void {
 });
 
 test('3-10 網羅性: provider が参照する fake 系クラスは配線基盤 4 件ちょうど (差し替え先を含まない)', function (): void {
+    // ★配置例外のキーも候補に足す。配線 provider は家系名への改名で名前の規則 (定義 2) から
+    //   外れたため、namedClasses() だけでは候補から静かに脱落する。いまの結果は変わらない
+    //   (走査器はクラス宣言名を参照として数えない) が、候補集合が黙って狭まること自体を防ぐ。
     $candidates = array_values(array_unique(array_merge(
         FakeClassCatalog::implementationClasses(),
         FakeClassCatalog::namedClasses(),
+        array_keys(FakeClassCatalog::placementExceptions()),
     )));
 
     // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
@@ -271,25 +275,25 @@ function (ExternalFakeBinding $binding, string $environment): void {
         // (1) bughunt.local ∧ on → 立つ
         $this->app['env'] = 'bughunt.local';
         config([ExternalFakeDeclaration::LLM_FLAG => true]);
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
         expect(Prompt::isFaking())->toBeTrue();
 
         Prompt::stopFaking();
 
         // (2) testing ∧ on → 立たない (static をテストプロセスで占有させない)
         $this->app['env'] = 'testing';
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
         expect(Prompt::isFaking())->toBeFalse();
 
         // (3) local ∧ on → 立たない (実 API 検証を潰さない)
         $this->app['env'] = 'local';
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
         expect(Prompt::isFaking())->toBeFalse();
 
         // (4) bughunt.local ∧ off → 立たない (既定 real LLM)
         $this->app['env'] = 'bughunt.local';
         config([ExternalFakeDeclaration::LLM_FLAG => false]);
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
         // static の往復を**同一 test case 内で** assert する (afterEach はフェイルセーフ)
diff --git a/tests/Architecture/FakeClassReferenceInvariantTest.php b/tests/Architecture/FakeClassReferenceInvariantTest.php
index ce4116a..a644be6 100644
--- a/tests/Architecture/FakeClassReferenceInvariantTest.php
+++ b/tests/Architecture/FakeClassReferenceInvariantTest.php
@@ -2,7 +2,7 @@
 
 declare(strict_types=1);
 
-use App\Providers\FakeExternalsServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Support\FakeStorageGate;
 use Tests\Support\ExternalFakes\FakeClassCatalog;
 use Tests\Support\ExternalFakes\FakeWiringSourceScanner;
@@ -15,7 +15,7 @@
  * 現時点の違反は 0 件 = 「増えないこと」を今固定するのが最安。
  *
  * ★走査候補: 「fake 実装クラス」だけでは足りない。配置例外
- *   (FakeExternalsServiceProvider / FakeStorageGate) を業務コードが参照しても検出できず
+ *   (BughuntFakesServiceProvider / FakeStorageGate) を業務コードが参照しても検出できず
  *   偽グリーンになるため、候補は implementationClasses() ∪ placementExceptions() のキーとする。
  *
  * ★走査根: app/ だけだと routes/ に Testing controller を直書きする、config/ にクラス名を書く、
@@ -32,7 +32,7 @@
     'app/Support/ExternalFakes/ExternalFakeDeclaration.php',
     // 唯一の配線点。差し替え先は宣言から読むので、ここに現れる偽物系クラスは
     // 配線基盤の 4 件だけである (ExternalFakeWiringInvariantTest の 3-10 が集合で固定する)
-    'app/Providers/FakeExternalsServiceProvider.php',
+    'app/Providers/BughuntFakesServiceProvider.php',
     // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
     'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
     'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
@@ -42,7 +42,7 @@
     // ★実装条件: constructor 引数を持たず、fake は handle() の fail-secure 4 条件を
     //   通過した**後**にのみ app() で遅延解決する。
     'app/Console/Commands/Development/PipelineSmokeCommand.php',
-    // provider 登録点。FakeExternalsServiceProvider (配置例外クラス) を必ず参照する
+    // provider 登録点。BughuntFakesServiceProvider (配置例外クラス) を必ず参照する
     'bootstrap/providers.php',
 ];
 
@@ -60,7 +60,7 @@
 test('4-2 配置例外は 2 件から増えていない', function (): void {
     // 増やすときは placementExceptions() に理由を書いたうえで**ここも触る** (意図的な摩擦)。
     expect(array_keys(FakeClassCatalog::placementExceptions()))->toBe([
-        FakeExternalsServiceProvider::class,
+        BughuntFakesServiceProvider::class,
         FakeStorageGate::class,
     ]);
 });
@@ -77,6 +77,11 @@
     expect($candidates)->not->toBeEmpty()
         ->and($files)->not->toBeEmpty();
 
+    // ★名前の規則 (定義 2) では拾えなくなった配線 provider が、候補集合に必ず残っていること。
+    //   ここが落ちると「本番コードが唯一の配線点を参照しても検出できない」偽グリーンになる。
+    expect($candidates)->toContain(BughuntFakesServiceProvider::class)
+        ->and($candidates)->toContain(FakeStorageGate::class);
+
     $violations = [];
     foreach ($files as $file) {
         if (in_array($file, FAKE_REFERENCE_ALLOWED, true)) {
@@ -107,7 +112,7 @@
     expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(6)
         ->and(FAKE_REFERENCE_ALLOWED)->toBe([
             'app/Support/ExternalFakes/ExternalFakeDeclaration.php',
-            'app/Providers/FakeExternalsServiceProvider.php',
+            'app/Providers/BughuntFakesServiceProvider.php',
             'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
             'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
             'app/Console/Commands/Development/PipelineSmokeCommand.php',
diff --git a/tests/Architecture/LaneExternalFakeBindingTest.php b/tests/Architecture/LaneExternalFakeBindingTest.php
index b576164..399a621 100644
--- a/tests/Architecture/LaneExternalFakeBindingTest.php
+++ b/tests/Architecture/LaneExternalFakeBindingTest.php
@@ -11,7 +11,7 @@
  * (正典 v1 の「差し替え処理を 1 本に集約し、レーン側からの直呼びを静的に禁じる」)。
  *
  * 差し替えの入口は「宣言 (App\Support\ExternalFakes\ExternalFakeDeclaration) +
- * 配線 provider (FakeExternalsServiceProvider)」の 1 本だけである。レーン側で同じことを
+ * 配線 provider (BughuntFakesServiceProvider)」の 1 本だけである。レーン側で同じことを
  * 書けると、宣言に載っていない差し替えがテストの中だけで成立し、
  * 「宣言と実際の差し替えが一致している」という保証が意味を失う。
  *
diff --git a/tests/Feature/Auth/FakeSocialiteWiringTest.php b/tests/Feature/Auth/FakeSocialiteWiringTest.php
index 6ae23fa..11f9f6c 100644
--- a/tests/Feature/Auth/FakeSocialiteWiringTest.php
+++ b/tests/Feature/Auth/FakeSocialiteWiringTest.php
@@ -4,7 +4,7 @@
 
 use App\Models\SocialAccount;
 use App\Models\User;
-use App\Providers\FakeExternalsServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Services\Auth\Fakes\FakeSocialiteProvider;
 use App\Services\Auth\SocialiteDriverResolver;
 use Illuminate\Testing\TestResponse;
@@ -30,7 +30,7 @@
  */
 $enableSsoFake = function (): void {
     config(['testing.fake_externals' => true]);
-    (new FakeExternalsServiceProvider(app()))->register();
+    (new BughuntFakesServiceProvider(app()))->register();
 };
 
 /** リダイレクト先 URL の host 部を取り出す (Location ヘッダ不在は null) */
@@ -176,7 +176,7 @@ function () use ($enableSsoFake): void {
         $this->app['env'] = 'local';
         config(['testing.fake_externals' => true]);
 
-        (new FakeExternalsServiceProvider($this->app))->register();
+        (new BughuntFakesServiceProvider($this->app))->register();
 
         // ★厳密一致 (fake は real のサブクラスなので instanceof では対照が無意味になる)
         expect(app(SocialiteDriverResolver::class)::class)->toBe(SocialiteDriverResolver::class);
diff --git a/tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php b/tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php
index 4858d10..a4a391f 100644
--- a/tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php
+++ b/tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php
@@ -186,7 +186,7 @@ function (
             ApiRequestor::setHttpClient($counting);
             // 実 Cashier クライアントを構築するため API キーが要る (送信は fake client が受ける)。
             config(['cashier.secret' => 'sk_test_external_client_timeout_gate']);
-            // テストレーンの fake 配線 (FakeExternalsServiceProvider) が rebind しうるため、
+            // テストレーンの fake 配線 (BughuntFakesServiceProvider) が rebind しうるため、
             // **実装へ明示的に戻す** (前提が変わっても本テストが無意味にならないようにする)。
             $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);
 
diff --git a/tests/Feature/Billing/TicketBalanceAccountingTest.php b/tests/Feature/Billing/TicketBalanceAccountingTest.php
index 1d4d547..d00d107 100644
--- a/tests/Feature/Billing/TicketBalanceAccountingTest.php
+++ b/tests/Feature/Billing/TicketBalanceAccountingTest.php
@@ -161,7 +161,7 @@ function accountingService(): TicketLedgerService
  *
  * **現行の AI-CUE では構造的に到達不能**: D28 により全 tier の monthly_ticket_grant は 0
  * (PlanSeederPriceInvariantTest が pin) で、有限期限の monthly は org 生涯 1 回の signup grant のみ。
- * BughuntBillingSeeder の 100 枚は無期限 (expires_at IS NULL) で nearestMonthlyExpiry の対象外。
+ * BughuntStripeSyncSeeder の 100 枚は無期限 (expires_at IS NULL) で nearestMonthlyExpiry の対象外。
  * よって「生きた有限期限 monthly が 2 本」は Filament PlanResource で monthly_ticket_grant を
  * 戻したときにだけ成立する。
  *
diff --git a/tests/Feature/Billing/TicketCheckoutTest.php b/tests/Feature/Billing/TicketCheckoutTest.php
index c17eb02..9f3351f 100644
--- a/tests/Feature/Billing/TicketCheckoutTest.php
+++ b/tests/Feature/Billing/TicketCheckoutTest.php
@@ -62,7 +62,7 @@ function checkoutPayload(int $count = 30, ?string $token = null): array
 test('fake_external marker query は purchased 表示に転用されない (アプリ非解釈)', function (): void {
     [, $owner] = createOrganizationWithOwner();
 
-    // runtime fake (FakeExternalsServiceProvider) の中立帰還 URL に付く観測用 marker。
+    // runtime fake (BughuntFakesServiceProvider) の中立帰還 URL に付く観測用 marker。
     // アプリはこの query を一切解釈しない = purchased 偽装にならないことを固定する
     $this->actingAs($owner)->get('/purchase-tickets?fake_external=stripe')
         ->assertOk()
diff --git a/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php b/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php
index 939df25..8c9265b 100644
--- a/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php
+++ b/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php
@@ -2,7 +2,7 @@
 
 declare(strict_types=1);
 
-use App\Providers\FakeExternalsServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Services\Captcha\RecaptchaVerifier;
 use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
@@ -41,7 +41,7 @@ function recaptchaFakeSiteverify(): void
         $this->app['env'] = 'bughunt.local';
         config([$flag => true, 'services.recaptcha.secret_key' => 'dummy-secret']);
 
-        (new FakeExternalsServiceProvider($this->app))->register();
+        (new BughuntFakesServiceProvider($this->app))->register();
 
         $verifier = app(RecaptchaVerifier::class);
 
diff --git a/tests/Feature/Database/BughuntBillingSeederTest.php b/tests/Feature/Database/BughuntStripeSyncSeederTest.php
similarity index 90%
rename from tests/Feature/Database/BughuntBillingSeederTest.php
rename to tests/Feature/Database/BughuntStripeSyncSeederTest.php
index 67e3c66..b144c6f 100644
--- a/tests/Feature/Database/BughuntBillingSeederTest.php
+++ b/tests/Feature/Database/BughuntStripeSyncSeederTest.php
@@ -6,11 +6,11 @@
 use App\Models\Organization;
 use App\Services\Billing\BillingAccess;
 use App\Services\Billing\TicketLedgerService;
-use Database\Seeders\BughuntBillingSeeder;
+use Database\Seeders\BughuntStripeSyncSeeder;
 use Illuminate\Support\Facades\DB;
 
 /*
- * BughuntBillingSeeder: bug-hunt env 専用の課金 fixture (有料プラン組織のみ
+ * BughuntStripeSyncSeeder: bug-hunt env 専用の課金 fixture (有料プラン組織のみ
  * active subscription + 初期チケット 100。free 組織は未契約のまま温存)。
  * 三重 fail-secure (fake_externals / bughunt.local / bug_hunt DB 名) を固定する。
  *
@@ -44,7 +44,7 @@ function runWithBughuntGuardSatisfied(Closure $callback): void
     [$organization] = createOrganizationWithOwner('標準組織');
     $organization->forceFill(['plan_code' => 'standard'])->save();
 
-    $this->seed(BughuntBillingSeeder::class);
+    $this->seed(BughuntStripeSyncSeeder::class);
 
     expect(Subscription::query()->count())->toBe(0);
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
@@ -55,7 +55,7 @@ function runWithBughuntGuardSatisfied(Closure $callback): void
     $organization->forceFill(['plan_code' => 'standard'])->save();
 
     config(['testing.fake_externals' => true]);
-    $this->seed(BughuntBillingSeeder::class);
+    $this->seed(BughuntStripeSyncSeeder::class);
 
     expect(Subscription::query()->count())->toBe(0);
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
@@ -68,9 +68,9 @@ function runWithBughuntGuardSatisfied(Closure $callback): void
     $freeOrg->forceFill(['plan_code' => 'free'])->save();
 
     runWithBughuntGuardSatisfied(function (): void {
-        $this->seed(BughuntBillingSeeder::class);
+        $this->seed(BughuntStripeSyncSeeder::class);
         // 冪等: 再実行しても subscription 1 行・残高 100 のまま増えない
-        $this->seed(BughuntBillingSeeder::class);
+        $this->seed(BughuntStripeSyncSeeder::class);
     });
 
     $standardOrg = Organization::query()->findOrFail($standardOrg->id);
@@ -93,7 +93,7 @@ function runWithBughuntGuardSatisfied(Closure $callback): void
     createFakeSubscription($organization, 'past_due');
 
     runWithBughuntGuardSatisfied(function (): void {
-        $this->seed(BughuntBillingSeeder::class);
+        $this->seed(BughuntStripeSyncSeeder::class);
     });
 
     $organization = Organization::query()->findOrFail($organization->id);
diff --git a/tests/Feature/Llm/CannedAnalysisPipelineTest.php b/tests/Feature/Llm/CannedAnalysisPipelineTest.php
index 80a9ddd..8d3a04a 100644
--- a/tests/Feature/Llm/CannedAnalysisPipelineTest.php
+++ b/tests/Feature/Llm/CannedAnalysisPipelineTest.php
@@ -47,7 +47,7 @@
     $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
     app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
 
-    // bughunt 実行時 (FakeExternalsServiceProvider::boot) と同一の install 経路。
+    // bughunt 実行時 (BughuntFakesServiceProvider::boot) と同一の install 経路。
     app(CannedPromptFakeRegistrar::class)->install();
     app(AnalysisPipeline::class)->run($job->id);
 
diff --git a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php b/tests/Feature/Providers/BughuntFakesServiceProviderTest.php
similarity index 90%
rename from tests/Feature/Providers/FakeExternalsServiceProviderTest.php
rename to tests/Feature/Providers/BughuntFakesServiceProviderTest.php
index ba1bcb4..a49303d 100644
--- a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
+++ b/tests/Feature/Providers/BughuntFakesServiceProviderTest.php
@@ -3,7 +3,7 @@
 declare(strict_types=1);
 
 use App\Prompts\ExampleSummaryPrompt;
-use App\Providers\FakeExternalsServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Services\Billing\CashierStripeGateway;
 use App\Services\Billing\CashierTicketCheckoutGateway;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
@@ -15,7 +15,7 @@
 use Kent013\PrismPrompt\Prompt;
 
 /*
- * FakeExternalsServiceProvider: config('testing.fake_externals') が capability flag。
+ * BughuntFakesServiceProvider: config('testing.fake_externals') が capability flag。
  * fail-secure 二軸 (flag 既定 false = 完全 no-op / 環境 allowlist) を固定する。
  * Pest はテスト毎に app を再構築するため register() 再実行の container 汚染は漏れない。
  *
@@ -37,7 +37,7 @@
 
 test('flag=true かつ allowlist 環境 (testing) では両 gateway が fake に解決される', function (): void {
     config(['testing.fake_externals' => true]);
-    (new FakeExternalsServiceProvider($this->app))->register();
+    (new BughuntFakesServiceProvider($this->app))->register();
 
     expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(FakeTicketCheckoutGateway::class);
     expect(app(StripeGatewayInterface::class))->toBeInstanceOf(FakeStripeGateway::class);
@@ -50,7 +50,7 @@
     $originalEnv = $this->app['env'];
     try {
         $this->app['env'] = 'production';
-        (new FakeExternalsServiceProvider($this->app))->register();
+        (new BughuntFakesServiceProvider($this->app))->register();
     } finally {
         $this->app['env'] = $originalEnv;
     }
@@ -75,7 +75,7 @@
     try {
         config(['testing.fake_llm' => true]);
         $this->app['env'] = 'bughunt.local';
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeTrue();
 
@@ -95,7 +95,7 @@
     try {
         // env は既定の testing のまま。
         config(['testing.fake_llm' => true]);
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
@@ -109,7 +109,7 @@
     try {
         config(['testing.fake_llm' => true]);
         $this->app['env'] = 'local';
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
@@ -124,7 +124,7 @@
     try {
         config(['testing.fake_llm' => false]);
         $this->app['env'] = 'bughunt.local';
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
@@ -142,7 +142,7 @@
         config(['testing.fake_externals' => true]);
         config(['testing.fake_llm' => false]);
         $this->app['env'] = 'bughunt.local';
-        (new FakeExternalsServiceProvider($this->app))->boot();
+        (new BughuntFakesServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
diff --git a/tests/Pest.php b/tests/Pest.php
index 30584db..144da3a 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -11,7 +11,7 @@
 use App\Models\Organization;
 use App\Models\Project;
 use App\Models\User;
-use App\Providers\FakeExternalsServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Organization\OrganizationProvisioningService;
@@ -338,7 +338,7 @@ function enableFakeStorage(): void
 {
     config()->set(ExternalFakeDeclaration::STORAGE_FLAG, true);
 
-    $provider = new FakeExternalsServiceProvider(app());
+    $provider = new BughuntFakesServiceProvider(app());
     $provider->register();
     $provider->boot();
     // provider の register()/boot() は本来 bootstrap 時に走り、フレームワークが route 読込後に
@@ -366,5 +366,5 @@ function enableFakeExternals(): void
 {
     config()->set(ExternalFakeDeclaration::EXTERNALS_FLAG, true);
 
-    (new FakeExternalsServiceProvider(app()))->register();
+    (new BughuntFakesServiceProvider(app()))->register();
 }
diff --git a/tests/Support/Bughunt/BughuntSeedWiringInventory.php b/tests/Support/Bughunt/BughuntSeedWiringInventory.php
index 31a8cf3..1bfb9cd 100644
--- a/tests/Support/Bughunt/BughuntSeedWiringInventory.php
+++ b/tests/Support/Bughunt/BughuntSeedWiringInventory.php
@@ -5,8 +5,8 @@
 namespace Tests\Support\Bughunt;
 
 use Database\Seeders\AdminUserSeeder;
-use Database\Seeders\BughuntBillingSeeder;
 use Database\Seeders\BughuntOAuthSeeder;
+use Database\Seeders\BughuntStripeSyncSeeder;
 use Database\Seeders\DatabaseSeeder;
 use Database\Seeders\ManualTestSeeder;
 use Database\Seeders\PermissionSeeder;
@@ -47,10 +47,10 @@ final class BughuntSeedWiringInventory
     public static function entries(): array
     {
         return [
-            BughuntBillingSeeder::class => [
+            BughuntStripeSyncSeeder::class => [
                 'role' => BughuntSeedRole::BughuntOnly,
                 'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
-                'guardPremiseTest' => 'tests/Feature/Database/BughuntBillingSeederTest.php',
+                'guardPremiseTest' => 'tests/Feature/Database/BughuntStripeSyncSeederTest.php',
             ],
             BughuntOAuthSeeder::class => [
                 'role' => BughuntSeedRole::BughuntOnly,
diff --git a/tests/Support/ExternalFakes/FakeClassCatalog.php b/tests/Support/ExternalFakes/FakeClassCatalog.php
index 27d61be..ceb1b12 100644
--- a/tests/Support/ExternalFakes/FakeClassCatalog.php
+++ b/tests/Support/ExternalFakes/FakeClassCatalog.php
@@ -4,7 +4,7 @@
 
 namespace Tests\Support\ExternalFakes;
 
-use App\Providers\FakeExternalsServiceProvider;
+use App\Providers\BughuntFakesServiceProvider;
 use App\Support\FakeStorageGate;
 use FilesystemIterator;
 use InvalidArgumentException;
@@ -49,14 +49,22 @@ public static function repoRoot(): string
     }
 
     /**
-     * 定義 2 のうち定義 1 に属さなくてよい例外 (fake の実体ではなく配線基盤)。
+     * 「fake の実体ではない配線基盤」の目録。用途は 2 つある。
+     *
+     * 1. 定義 2 (Fake 命名) に当たるが定義 1 (Fakes/ • Testing/ 配下) に属さなくてよい**配置の例外**
+     * 2. **参照走査 (FakeClassReferenceInvariantTest 4-3) の候補**に必ず含める集合
+     *
+     * ★BughuntFakesServiceProvider は家系の名前へ改名した結果、名前の規則 (定義 2) では
+     *   拾えなくなった。それでも本目録に残すのは用途 2 のためである — ここから落とすと、
+     *   業務コードが唯一の配線点を直接参照しても検出できず**偽グリーン**になる
+     *   (4-3 の docblock が名指しで警告している事故)。この包含は 4-3 の明示 assertion が固定する。
      *
      * @return array<class-string, string> class => 理由
      */
     public static function placementExceptions(): array
     {
         return [
-            FakeExternalsServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
+            BughuntFakesServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
             FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
         ];
     }
@@ -108,7 +116,7 @@ public static function namedClasses(): array
      * 走査根は 4 つ: app/ • routes/ • config/ • bootstrap/。
      * 対象は `.php` 拡張子のファイルのみ。
      *
-     * @return list<string> 例: 'app/Providers/FakeExternalsServiceProvider.php', 'routes/web.php'
+     * @return list<string> 例: 'app/Providers/BughuntFakesServiceProvider.php', 'routes/web.php'
      */
     public static function scanFiles(): array
     {
diff --git a/tests/Support/ExternalFakes/FakeWiringSourceScanner.php b/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
index 3fad0fd..1673447 100644
--- a/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
+++ b/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
@@ -8,7 +8,7 @@
 use App\Support\FakeStorageGate;
 
 /**
- * FakeExternalsServiceProvider の container 呼び出し形と、本番コードのクラス参照を
+ * BughuntFakesServiceProvider の container 呼び出し形と、本番コードのクラス参照を
  * token ベースで抽出する純粋 helper (I/O を持たない。引数は PHP ソース文字列)。
  *
  * ★設計判断
```
