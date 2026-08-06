## アプリの使命 (North Star)

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

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## あなたの役割

あなたは Web アプリケーション (Laravel 12 + PHP 8.4 + Svelte 5 + Inertia) の改善に関する概念設計レビュアーです。

対象は「外部サービス fake の配線を実証で検査する Architecture テスト層を新設する」という **テスト基盤** の概念設計です。アプリの機能追加ではありません。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命 (North Star) に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か (Laravel 12 の service container / provider ライフサイクル / Pest の lane 構成を踏まえて)
4. 検査の有効性: 提案された検査は「登録漏れが無音になる」という問題を**実際に**捕まえられるか。偽グリーン (検査が常に通ってしまう) になる箇所は無いか
5. リスク: テスト間の状態リーク (container / static / config / route) や `--parallel` 実行での副作用
6. スコープの適切さ: 過大または過小になっていないか。特に「スコープに入れない」と判断したものの理由が妥当か
7. 検査の設計としての妥当性: inventory + 実証 + 網羅性走査という三層構成が、将来の変更に対して形骸化しないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: external-fakes-wiring-gate (fake 配線の実証検査)

- feature_id: `external-fakes-wiring-gate` (c2c)
- brief: `brief2-external-fakes-wiring-gate.md` (feature_revision `2-2ed887e4f99e` 時点)
- 対象リポジトリ: aicue
- 想定 theme: test / 想定 priority: High

---

## 1. 仮説

**仮説**: aicue の外部 fake 差し替えは「登録を忘れても例外が出ず、本物が静かに動く」形をしている。
差し替え対象を **inventory として持ち、実際に container から解決して中身が fake / 本物かを
厳密クラス一致で確かめる層**を 1 枚入れれば、登録漏れ・allowlist 崩れ・状態リークが
無音ではなくテスト赤として現れる。

**成功判定**:

1. 差し替え対象のいずれか 1 本の `bind()` を provider から削除したら Architecture テストが赤くなる。
2. `bootstrap/providers.php` から `FakeExternalsServiceProvider` を外したら赤くなる。
3. `FakeExternalsServiceProvider` に新しい fake 差し替えを足したら、inventory 未登録で赤くなる。
4. 本番コード (app/ 配下の業務コード) が fake クラス名を参照したら赤くなる。
5. 上記 1〜4 のいずれも、現状 (2026-08-06 の main) では**赤くならない** = 穴が実在することを
   実装前に fail で確認できる (AGENTS.md 思考原則 5 テストファースト)。

---

## 2. 現状の実査 (2026-08-06 / main = 2cb9068)

### 2.1 差し替えの実体は 1 provider に単一化されている

`app/Providers/FakeExternalsServiceProvider.php` が唯一の配線点。系統は 3 つで、
**capability flag も env allowlist も系統ごとに違う**:

| 系統 | capability flag | env allowlist | フェーズ | 差し替え対象 |
|---|---|---|---|---|
| 課金 (Stripe) | `config('testing.fake_externals')` | `local` / `testing` / `bughunt.local` | `register()` | `TicketCheckoutGateway` (interface) / `StripeGatewayInterface` / `AutoRechargeGatewayInterface` |
| LLM (Prism) | `config('testing.fake_llm')` | `bughunt.local` のみ | `boot()` | `Prompt::$fake` (static, container ではない) |
| storage | `config('testing.fake_storage')` | `FakeStorageGate`: `bughunt.local` ∨ (`testing` ∧ `runningUnitTests`) | `register()` (+ `boot()` で signed route) | `TakeObjectStorage` (**具象クラス**) / `RenderObjectStorage` (**具象クラス**) |

- 本物側の bind は `AppServiceProvider::register()` (課金 3 本)。storage 2 本は
  **bind が存在しない** = Laravel が具象クラスを自動組み立てする。
- `bootstrap/providers.php` は `FakeExternalsServiceProvider` を**末尾**に置いて後勝ちを成立させている
  (コメントで明記済み)。

### 2.2 既に存在する検査 (台帳の記述と食い違う点)

- `tests/Architecture/` に fake / external 系のテストは **0 本** (台帳と一致)。
- しかし **実証ベースの配線検査は Feature に既に 1 本ある**:
  `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` (6 test)。
  flag off/on・allowlist 外 warning・LLM の env 別挙動・系統分離の回帰を実際に解決して見ている。
  → 台帳の「実証も別プロセス観測も無い」は **Architecture 限定では真、リポジトリ全体では偽**。

その既存 Feature テストが**見ていない**もの (= 本タスクが埋める穴):

| # | 穴 | なぜ無音になるか |
|---|---|---|
| A | `AutoRechargeGatewayInterface` の解決結果 | 3 本 bind のうち 1 本だけ検査が無い。削除しても緑 |
| B | storage 2 本 (`TakeObjectStorage` / `RenderObjectStorage`) の解決結果 | `FakeStorageRouteTest` は fake 側の往復を見るが、**flag off で本物に戻るか**を見ていない |
| C | `bootstrap/providers.php` への provider 登録 | 既存テストは `new FakeExternalsServiceProvider($this->app)` を**手で実行**する。登録を消しても緑 |
| D | 差し替え対象の網羅性 | 新しい fake を足しても、どのテストも「増えたこと」を知らない |
| E | 本番コードからの fake クラス参照 | 全走査が無い |

### 2.3 偽グリーンの罠 (設計の前提にする)

`FakeTakeObjectStorage extends TakeObjectStorage` / `FakeRenderObjectStorage extends RenderObjectStorage`
= **fake が本物のサブクラス**。したがって既存 Feature テストの流儀
(`expect(app(X::class))->toBeInstanceOf(Real::class)`) を storage へそのまま広げると
**fake でも通る = 対照実行が無意味になる**。
本設計の実証検査は `$resolved::class === Real::class` の**厳密一致**で書く。

### 2.4 本番混入防止の現状

`app/Support/ProductionEnvGuard.php` が fake フラグ 3 本 (`fake_externals` / `fake_llm` /
`fake_storage`) を検査項目に持ち、`tests/Feature/Support/ProductionEnvGuardTest.php` が
3 本とも回帰で固定している。呼び出し元は **2 つ**:

- `AppServiceProvider::boot()` の `if ($this->app->environment('production'))` → **起動時**
- `production:preflight` コマンド → **配備前**

→ 台帳の「起動時ではなく**配備前**に落とす層」は片面のみの記述。aicue は配備前と起動時の
両方を同一 SSOT で持っている。柱 3 の (a)(b) の差分として残るのは
「**設定キャッシュを信用せず、プロセスの実環境変数と二重判定する**」ことだけ。

### 2.5 本番コードからの fake 参照 (柱 3c の母集団)

`app/` 配下で fake クラス名を参照しているのは以下だけ (実査済み):

- `app/Providers/FakeExternalsServiceProvider.php` — 唯一の配線点 (正当)
- `app/Http/Controllers/Testing/{Put,Get}FakeStorageObjectController.php` — fake storage の
  signed route 受け口 (gate 成立時のみ登録される。正当だが本番バイナリには載る)
- `app/Services/Billing/Fakes/*` 同士の相互参照 (`FakeExternalUrl`)

= 現時点で違反 0。**今 gate を入れれば「増えないこと」を固定できる**状態。

### 2.6 bug-hunt レーン (柱 2 の判断材料)

- `scripts/bug-hunt-shard.sh` は実在し、`TESTING_FAKE_LLM` / `TESTING_FAKE_STORAGE` を
  **明示注入** (残留 env による反転防止) している。
- その注入内容は同スクリプトの `self-test` (`[z1]` ケース群) が既に検証している。
- aicue は **外部ログイン driver を fake 化していない** (`Socialite::driver()` を
  `SocialAuthController` が直接使うだけで、driver 差し替えの登録は無い)。
  = 家系の標準形が想定する「起動順序で勝敗が決まる差し替え」の主対象が aicue には**無い**。

---

## 3. 課題

1. **登録漏れが必ず無音**: storage 2 本は具象クラスなので、bind を消しても Laravel が
   本物を自動組み立てして静かに実 S3 を叩く。課金 3 本も interface だが、本物側 bind が
   `AppServiceProvider` にあるため fake 側 bind を消すと本物に落ちる = 例外にならない。
2. **provider 登録そのものが検査されていない**: 既存テストは provider を手で実行するため、
   `bootstrap/providers.php` からの脱落・順序反転 (AppServiceProvider より前に置く =
   後勝ちが崩れる) を検出できない。
3. **差し替え対象の増加が検査に反映されない**: 網羅性の deny-by-default が無い。
4. **本番コードへの fake 混入が静的に禁止されていない**: 現状違反 0 なので、今固定するのが最安。
5. **agenda 未裁定**: 家系正典 (実証主軸か / 3 段そろえるか) が確定していない。
   凝った独自機構を作ると、裁定後に捨てる羽目になる。

---

## 4. 方針 (最小形)

**「どちらの正典に転んでも無駄にならない部分」だけを 2 本の Architecture テストで実装する。**

### 施策 1: 実証ベースの fake 配線 gate (柱 1) — 必須

新規 `tests/Architecture/ExternalFakeWiringInvariantTest.php` +
inventory `tests/Support/ExternalFakeWiringInventory.php`。

inventory は container 差し替え 5 本 (課金 3 + storage 2) を
`abstract / real / fake / flag / allowed envs / phase` で持つ。テストは inventory を回して:

| 検査 | 内容 |
|---|---|
| 対照 (flag off) | 既定 container で解決 → **real と厳密クラス一致** |
| 実証 (flag on + allowlist 内) | provider を実走 → **fake と厳密クラス一致** |
| allowlist 外 | flag on + `production` → real のまま (課金は warning ログも) |
| 往復 (復元) | flag を戻して provider 再実走 → real に戻る / `Prompt::isFaking()` が false |
| 登録点 | `bootstrap/providers.php` の配列に provider が居て `AppServiceProvider` より**後**。かつ起動済み container の `getLoadedProviders()` に載っている |
| 網羅性 | provider ソースを token 走査して `$this->app->bind(A::class, B::class)` の組を抽出し、inventory と**集合一致**を要求 (deny-by-default) |

LLM (Prism) は container ではなく static のため inventory とは別枠で
「`bughunt.local` ∧ `fake_llm=true` でのみ `Prompt::isFaking()` が true」「必ず復元される」の 2 点のみ。

> 網羅性検査だけはソース走査 (字面) になる。brief は「差し替え処理の**内部構造**をソースの
> 文字列一致で固定する形は推奨どまり」としているが、ここで固定するのは内部構造ではなく
> **inventory の網羅性 (登録漏れの検出)** であり、aicue の既存 Architecture テスト
> (`ScenarioWritePathInventoryTest` / `PromptGuardrailTest`) と同じ token 走査の流儀に乗る。
> 振る舞いの検証は上 5 行の実証側が持つ。

### 施策 2: 本番コードの fake クラス参照 全走査 gate (柱 3c) — 必須

新規 `tests/Architecture/FakeClassReferenceInvariantTest.php`。

- fake クラス名は **ディレクトリから動的導出** (`app/**/Fakes/*.php` と `app/**/Testing/*.php`)。
  ハードコード一覧を持たない (fake が増えたら自動的に母集団に入る)。
- `app/` 配下を token 走査し、fake クラス名の参照が allowlist 外にあれば fail。
- allowlist は 3 件だけ (2.5 の実査どおり)。理由をコメントに残す。

### 施策 3: ProductionEnvGuard の fake フラグ 3 本を「壊さない」ことの明示 — 追記のみ

既に `ProductionEnvGuardTest` が固定済みなので**新規テストは作らない**。
施策 1 の inventory の docblock から「本番混入防止は `ProductionEnvGuard` が担当 (配備前 +
起動時)。本 gate は非本番側の配線だけを見る」と責務境界を書き、二重実装を防ぐ。

---

## 5. 代替案と却下理由

| 案 | 却下理由 |
|---|---|
| テンプレートの `ExternalFakes.php` を移植して宣言 SSOT を作る | 宣言の一致は別 feature (`external-fakes-declaration`) の範囲。aicue は provider 単一化という別の形を選んでおり乖離台帳にも登録済み。brief も「移植する話ではない」と明示 |
| spirux の `BrowserFakesContractTest` をそのまま移植 | aicue は Browser lane の fake 構成が違う (Prompt fake は `tests/Pest.php` の browser lane で install)。丸写しは形骸化する。**要点 (実証 + 往復) だけ**を借りる |
| 別プロセス probe (柱 2) を今回入れる | §6 に理由を詳述。今回スコープ外 |
| 起動時の実 env 二重判定 (柱 3b) を今回入れる | §6 に理由を詳述。今回スコープ外 |
| provider ソースの構造 (early return の順序等) を文字列で固定 | brief が「推奨どまり」に落とした形。実証で振る舞いを直接見られるため不要 |
| Feature テスト側 (`FakeExternalsServiceProviderTest`) を拡張して済ませる | 不変条件は Architecture テストへの登録まで含めて「実装済み」(AGENTS.md 禁止事項 1)。また既存 Feature テストは provider を手で実行する形なので、登録点検査を足すと責務が混ざる。**既存テストは削除も改変もしない** (禁止事項 3) |

---

## 6. スコープに入れないものと理由

### 6.1 柱 2 (別プロセスでの実測) — 入れない

1. **agenda 未裁定**: 観測点の定義 (driver 解決クラス / 転送先ホスト / 利用者情報取得メソッドの
   宣言クラス / 遅延 provider の読み込み) は家系正典が確定してから寄せるべき箇所そのもの。
   先に独自実装すると裁定後に捨てる。
2. **aicue に主対象が無い**: 標準形が挙げる観測点の中心は「外部ログイン driver の差し替え」だが、
   aicue は Socialite を fake 化していない (実査 §2.6)。決済 fake は container bind なので
   同一プロセスで実証でき、別プロセスを起こす必然が無い。
3. **既存の代替がある**: bug-hunt レーンの env 注入 (`TESTING_FAKE_*`) は
   `scripts/bug-hunt-shard.sh self-test` が既に検証している。二重実装になる。
4. コストが高い (子プロセス起動 + probe スクリプト + グローバルテストロック下での実行時間)。

→ **後続 TODO 候補**: 「家系の正典が確定し、かつ aicue が外部ログイン driver か
起動順依存の差し替えを持った時点で、別プロセス probe を追加する」。

### 6.2 柱 3 (b) 起動時の実 env 二重判定 — 入れない

`ProductionEnvGuard` が起動時 (`AppServiceProvider::boot`) と配備前 (`production:preflight`) の
両方から呼ばれており (実査 §2.4)、fake フラグ 3 本は回帰テスト済み。
残差は「`config:cache` された値ではなく `$_SERVER`/`getenv()` を直接読む二重判定」だけで、
これは agenda の裁定対象そのもの。今の aicue の運用 (config キャッシュを production で使う) では
**キャッシュが古い**というシナリオを具体的に踏んでいない = 「あったら便利」に該当する
(AGENTS.md 思考原則 2)。

→ **後続 TODO 候補**として残す。

### 6.3 別 feature の範囲 (brief が明示)

- 宣言そのものと宣言⇔実装の一致 → `external-fakes-declaration`
- 未登録の外部通信の実行時遮断 / 資格情報無効化 / 未消費検出 → `external-egress-default-deny`
- 外部到達点の集約と直呼びの静的禁止 → `external-seam-funnel`
- 決済 fake の実体 → `stripe-fake-lane`
- fake と無関係の本番起動時検査 (暗号鍵 / Cookie / 信頼プロキシ)

### 6.4 既存テストの改変

`tests/Feature/Providers/FakeExternalsServiceProviderTest.php` と
`tests/Feature/Storage/FakeStorageRouteTest.php` は**触らない** (禁止事項 3)。
新 gate は重複を恐れず独立に成立させる (Architecture 側が不変条件の正本)。

---

## 7. 期待効果 (使命への貢献)

- 使命の中核 (SOP → シナリオ → **ナビ撮影** → 動画生成) のうち、撮影データの保管
  (`TakeObjectStorage`) と課金 (チケット / サブスク) は**外部サービスに直結する経路**。
  fake 配線が静かに崩れると、テストは緑のまま実 S3 / 実 Stripe を叩く。
  現場の撮影データと課金は取り返しがつかない副作用を持つ = 無音の失敗が最も高くつく箇所。
- bug-hunt レーン (探索的 UX バグハント) は fake 前提で走る。配線が崩れると
  bug-hunt が実サービスへ漏れる。

---

## 8. 検証方法

| 手順 | 期待結果 |
|---|---|
| 実装前に gate を書いて実行 | §1 の成功判定 1〜4 の**すべての mutation 無しで緑**、mutation 入りで赤 |
| `FakeExternalsServiceProvider` の `AutoRechargeGatewayInterface` bind を一時削除 | 施策 1 が赤 |
| `bootstrap/providers.php` から provider を一時削除 | 施策 1 の登録点検査が赤 |
| provider に架空の `bind(Foo::class, FakeFoo::class)` を一時追加 | 施策 1 の網羅性検査が赤 (inventory 未登録) |
| 任意の Service に `use App\Services\Billing\Fakes\FakeStripeGateway;` を一時追加 | 施策 2 が赤 |
| `composer test -- --testsuite=Architecture` | 全緑 |
| `composer phpstan` / `vendor/bin/pint --test` | 全緑 |

mutation は**一時的にローカルで当ててすぐ戻す** (コミットしない)。手順は詳細設計に書く。

---

## 9. 制約・前提

- Architecture lane は `RefreshDatabase` なし・`StrayLlmCallGuard` なし (`tests/Pest.php`)。
  → gate は **DB に触れない**こと、LLM fake を触ったら `finally` で必ず `Prompt::stopFaking()` すること。
  実査で container 解決対象 5 本の constructor はすべて DB 非依存であることを確認済み。
- テストは `--parallel` で走る。`config()` / `$app['env']` の書き換えは try/finally で原値復元する
  (プロセス跨ぎの汚染は無いが、同一プロセス内の後続テストへは漏れる)。
- `FakeStorageGate` の allowlist は `testing ∧ runningUnitTests` を含むため、Architecture lane で
  storage 系の実証が可能 (env の差し替え不要)。LLM だけ `bughunt.local` への一時差し替えが要る。
- `pnpm` 側の変更なし (フロントエンド非関与)。DESIGN.md / Atomic Design の観点は非該当。
