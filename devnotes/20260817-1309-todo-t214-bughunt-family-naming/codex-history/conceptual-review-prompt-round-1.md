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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提】
- 本件は「複数リポジトリで共有される機能台帳」が求める、同一の関心事に家系で 1 つの名前を割り当てる作業である。振る舞いの変更を含まない改名が要件そのものである。
- 家系の名前は台帳の実測記録から確定済みであり、推測で決めてよい対象ではない。
- 実装対象リポジトリは Laravel 12 + PHP 8.4 + Svelte 5 で、PHPStan level 10 / Pest を使う。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: bug-hunt ランタイム配線の名前を家系へ揃える (aicue:T214)

> 本ディレクトリ名に含まれる `t214` は TODO 番号 aicue:T214 を指す。

## 背景・課題

機能台帳 lctl の feature `bughunt-runtime` (bug-hunt ランタイム配線) について、
aicue セルの状態は **update_pending** である (観測点 aicue@bac558f、差分巡回 2026-08-16)。

- 裁定 **AG-085** (2026-08-06) は「偽の外部サービスの配線の検査を広さ+深さの和集合へ統合する」
  「専用データ投入の配線の検査を新世代へ統一する」「aigenba と aicue の欠落を埋める」を求めた。
  この裁定文は理由として **「同じ関心事に名前が 2 つある状態を続けると、追従判断のたびに
  『これは欠落か別名か』の確認が発生する」** ことを明記している。
- **2026-08-10 の裁定でファイル数の統合 (AG-042 / AG-085 が定めた 8 → 6 ファイル) は撤回された**。
  残る要件は **「同一の関心事には家系で 1 つの名前を割り当てる」だけ**である
  (経緯は feature `bug-hunt-exec-infra` の裁定記録)。
- 検査の欠落側は aicue:T177 (aicue@4d3007a) が
  `tests/Architecture/BughuntSeedWiringInvariantTest.php` を新設して解消済みである。
  aicue:T177 の報告自身が「提供元クラスの名前は独自のままである。家系への改名は本件の範囲外とした」と
  申し送っている。

したがって **aicue に残っている未達は 2 つの名前だけ**である。

| 関心事 | 家系の名前 | aicue の現在の名前 |
|---|---|---|
| 決済事業者との同期でしか生まれない購読状態を bug-hunt 環境へ投入する seeder | `BughuntStripeSyncSeeder` | `database/seeders/BughuntBillingSeeder.php` |
| 偽の外部サービスを container へ結ぶ唯一の配線 provider | `BughuntFakesServiceProvider` | `app/Providers/FakeExternalsServiceProvider.php` |

### 家系名の確定根拠 (推測ではなく台帳の実測記録)

`get_feature('bughunt-runtime')` の内容から確定した。

1. **`BughuntStripeSyncSeeder`**
   - gates: `tests/Feature/Bughunt/BughuntStripeSyncSeederTest.php` が
     laravel-claude-template / aigenba / spirux / motivation / metamovics の **5 本に実在**し、
     「**持たないのは aicue のみで、同じ位置に別名の
     aicue:tests/Feature/Database/BughuntBillingSeederTest.php (102 行) が在る**」と明記されている。
   - aigenba セル: `database/seeders/BughuntStripeSyncSeeder.php`
     (決済事業者との同期でしか生まれない購読状態を投入) が実在 (観測点 aigenba@8cb203a85)。
   - metamovics セル: `database/seeders/BughuntOAuthSeeder.php` + `BughuntStripeSyncSeeder.php` が実在。
   - aicue セル: 「決済側の投入 seeder は BughuntStripeSyncSeeder ではなく別名の
     `database/seeders/BughuntBillingSeeder.php` に当たるものであり、追従時に
     『欠落か別名か』の判定が再発しないよう記録しておく」。
2. **`BughuntFakesServiceProvider`**
   - aigenba セル: 「提供元クラスは旧名 `app/Providers/DuskFakesServiceProvider.php` から
     `app/Providers/BughuntFakesServiceProvider.php` へ**改名され** (旧名は HEAD に不在)」
     (aigenba:T1154、観測点 aigenba@8cb203a85)。
   - metamovics セル: `app/Providers/BughuntFakesServiceProvider.php`
     (bootstrap/providers.php に登録) が実在。
   - aicue セル: 「偽の外部サービスの提供元も `app/Providers/FakeExternalsServiceProvider.php` で
     名前が違う」。

両方とも**家系の 5 リポジトリのうち複数で同一の名前が実測されている**ため、確定できる。

## 改善アイデア

**名前を家系へ揃えるだけの変更を行う。振る舞いは 1 つも変えない。**

1. `database/seeders/BughuntBillingSeeder.php` → `database/seeders/BughuntStripeSyncSeeder.php`
   (クラス名も同時に変更)
2. `app/Providers/FakeExternalsServiceProvider.php` → `app/Providers/BughuntFakesServiceProvider.php`
   (クラス名も同時に変更)
3. 上記 2 名を指している**追跡下の参照を全数追従させる** (下の一覧は実測値)
4. **旧名が戻ってこないことを Architecture テストで固定する**
   (AGENTS.md 禁止事項 1「不変条件は対応する Architecture/Feature テストへの登録まで含めて実装済み」)。
   aigenba は同じ改名で `BughuntFakesProviderNamingResidualTest` を新設し、
   **「改名の残留検査は文書も見るべきで、その走査範囲を設計時に決めること」を家系へ申し送っている**
   (aigenba の報告本文)。本設計はその申し送りに従って走査範囲を決める。

### 参照の実測 (追跡下・devnotes を除く 33 ファイル / 90 箇所、2026-08-17 時点)

| 区分 | ファイル | 件数 |
|---|---|---|
| 本体 | `database/seeders/BughuntBillingSeeder.php` | 5 |
| 本体 | `app/Providers/FakeExternalsServiceProvider.php` | 1 |
| 起動時の登録点 | `bootstrap/providers.php` | 2 |
| 実行スクリプト | `scripts/bug-hunt-shard.sh` (provision / reseed の投入列) | 2 |
| 目録 (aicue:T177 の新資産) | `tests/Support/Bughunt/BughuntSeedWiringInventory.php` | 3 |
| 検査 | `tests/Architecture/ExternalFakeWiringInvariantTest.php` | 16 |
| 検査 | `tests/Architecture/FakeClassReferenceInvariantTest.php` | 6 |
| 検査 | `tests/Architecture/LaneExternalFakeBindingTest.php` | 1 |
| 検査の支援 | `tests/Support/ExternalFakes/FakeClassCatalog.php` / `FakeWiringSourceScanner.php` | 3 / 1 |
| テスト本体 | `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` | 9 |
| テスト本体 | `tests/Feature/Database/BughuntBillingSeederTest.php` | 7 |
| レーン配線 | `tests/Pest.php` | 3 |
| 他テストの説明・利用 | `tests/Feature/Auth/FakeSocialiteWiringTest.php` (3) / `Captcha/RecaptchaVerifierFakeWiringTest.php` (2) / `Billing/TicketCheckoutTest.php` (1) / `Billing/TicketBalanceAccountingTest.php` (1) / `Billing/AutoRechargeStripeCallBudgetTest.php` (1) / `Llm/CannedAnalysisPipelineTest.php` (1) | 9 |
| 本番コードの説明 (コメント) | `app/Providers/AppServiceProvider.php` (2) / `app/Support/ExternalFakes/ExternalFakeDeclaration.php` (2) / `app/Support/FakeStorageGate.php` (1) / `app/Services/Billing/TicketLedgerService.php` (2) / `Fakes/FakeStripeGateway.php` (3) / `Fakes/FakeTicketCheckoutGateway.php` (1) / `Auth/Fakes/FakeSocialiteDriverResolver.php` (1) / `AI/Testing/CannedPromptFake.php`・`CannedPromptFakeRegistrar.php`・`CannedPromptResponses.php` (各 1) | 15 |
| 文書 | `docs/architecture.md` (2) / `docs/testing-browser.md` (1) | 3 |
| 環境ひな型 | `.env.bughunt.local.example` (説明コメント) | 2 |
| **過去の記録 (書き換えない)** | `docs/TODO-closed.md` (aicue:T015 / aicue:T119 の完了記録) | 2 |

- `.claude/skills/` 配下 (追跡下 50 ファイル) には旧名の出現が **0 件**である (実測)。
- `AGENTS.md` にも出現は **0 件**である (実測)。
- `bootstrap/cache/services.php` は追跡外の生成物である
  (provider 一覧が変わると Laravel 自身が作り直すため手当ては要らない)。

## 期待効果

- **使命への貢献は間接である。誇張しない。** 本変更は利用者に見える振る舞いを 1 つも変えない。
  効くのは「AI が撮るべきカットを設計する」本体ではなく、それを守る開発時の安全網の**維持コスト**である。
- 台帳の裁定文が挙げた実害がそのまま消える —
  「同じ関心事に名前が 2 つある状態」を続けると、**家系の追従判断のたびに
  『これは欠落か別名か』の実読が発生する**。実際に台帳のキュレーターは 2026-08-14 の深掘りで
  この判定に時間を要し、再発防止のために aicue セルへ注記を書き残している。
- aicue セルが update_pending から implemented へ進み、`bughunt-runtime` の
  追従状態が家系で読めるようになる。

## 実装方針 (概要)

1. **テストファースト**: 旧名の残留検査 (新規 Architecture テスト) を**先に書いて赤にする**。
   この検査の失敗メッセージが「まだ旧名が残っているファイルと件数」を出すので、
   そのまま改名の作業一覧になる。
2. `git mv` で 4 ファイル (本体 2 + それぞれのテスト 2) を改名し、クラス名と参照を追従させる。
3. 既存の deny-by-default の検査群が**参照の数え落としを機械的に暴く**ことを利用する
   (目録とクラス集合の一致 / 登録点の集合 / 件数を固定した一覧)。
4. 全検証コマンドが green であることを確認する。

## 制約・前提

- **振る舞い完全不変**。名前以外の差分を持ち込まない
  (条件・順序・件数・環境ガードの論理は 1 文字も変えない)。
- 改名は `git mv` を使い、履歴の追跡が切れないようにする。
- `Database\Seeders\` / `App\` は PSR-4 のため、`composer dump-autoload` は改名で自動追従する
  (classmap ではない)。
- 家系名の確定は台帳の実測記録のみを根拠とし、推測で名前を作らない。

## スコープ外

| 項目 | 理由 |
|---|---|
| ファイル数の統合 (8 → 6) | **2026-08-10 の裁定で撤回済み**。やると裁定に反する |
| `config('testing.fake_externals')` / `TESTING_FAKE_EXTERNALS` の改名 | 台帳が名前の不一致として挙げていない。設定キーと環境変数は外部契約 (`.env.bughunt.local`) であり、改名は bug-hunt 環境の再設定を要求する破壊的変更になる |
| 検査ファイル名 (`ExternalFakeWiringInvariantTest` / `BughuntSeedWiringInvariantTest` 等) の家系への統一 | 家系では `BughuntFakeWiringTest` / `BughuntSeedWiringTest` に当たるが、**2026-08-16 の巡回が残要件として名指ししたのは本設計の 2 件だけ**である。検査名まで広げるかは台帳の議題として起こす (勝手に広げない) |
| `tests/Feature/Database/BughuntOAuthSeederGuardTest.php` の改名 | 家系では `BughuntOAuthSeederTest` に当たるが、台帳の gates は aicue のこの名前を**別名ではなく実在する検査として登録**しており、残要件にも挙げていない |
| テストファイルの置き場所の移動 (`tests/Feature/Database/` → `tests/Feature/Bughunt/`) | 台帳は aicue の決済側テストを「**同じ位置に**別名で在る」と記録している = 位置は問題ではない。移動はファイル構成の変更であり、撤回された統合要件に近づく |
| `docs/TODO-closed.md` / `devnotes/` の旧名の書き換え | どちらも**過去に何をしたかの記録**である。当時作ったクラスの名前は当時の事実であり、書き換えると記録が嘘になる |
| 台帳への書き込み (append_event) | 設計段階では行わない。実装完了後に実装セッションが行う |
