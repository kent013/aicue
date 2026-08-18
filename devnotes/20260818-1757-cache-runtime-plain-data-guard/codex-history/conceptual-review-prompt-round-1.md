【アプリの使命 (North Star) — AGENTS.md より】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項 — AGENTS.md より】
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

（アプリの使命・禁止事項は上に挿入済み）

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

【補足文脈 (このリポジトリ固有)】
- 本件はテストレーンの検査機構 (Architecture / Support テスト) の話であり、UI・DTO・JsonResource の変更を含まない。
- 「家系」= 同一テンプレート (laravel-claude-template) から派生した 6 リポジトリ群。共有の機能台帳 (lctl) が正典を定め、裁定 (AG-番号) で実装形を確定する。
- 裁定 AG-151 が本件の正典を v2 (静的層 + 実行時層の 2 層) へ確定済みであり、「2 層にするか否か」自体は再検討の対象ではない。レビューは v2 を aicue へどう移植するかの妥当性に集中してほしい。

---

## 概念設計

# 概念設計: キャッシュ素データ規約の実行時層 (正典 v2 追従)

## 背景・課題

家系の機能台帳 lctl の feature `cache-payload-plain-data` は、裁定 AG-151 (2026-08-10) で
**正典 v2** へ上がった。v2 の必須要素は 4 つである。

1. **静的層** = 書き込みサイトの全数申告目録を持ち、未申告の経路を deny-by-default で落とす
2. **実行時層** = テスト実行中のキャッシュ書き込みを**受け皿の側で捕まえ、保管先へ渡す前の値を
   再帰的に検査する**
3. **設定の二段 pin** = `serializable_classes` を false で固定し、宣言と実効値の両方を pin する
4. **境界迂回の hard fail** = 保管先の直接取得・受け皿の直接生成・拡張登録を落とす

aicue の現在地 (台帳の判定は `update_pending` / version「v1 (静的層のみ)」/ target v2)。

| 要素 | aicue の現状 |
|---|---|
| (1) 静的層 | **実装済み**。`tests/Architecture/CachePayloadPlainDataGateTest.php` (1455 行、検査 1〜9 + 正負コントロール) |
| (2) 実行時層 | **完全に不在** |
| (3) 設定の二段 pin | **実装済み**。宣言 pin = `ConfigHardeningTest` (config ファイル直接評価)、実効値 = 上記 gate の検査 6 |
| (4) 境界迂回の hard fail | **部分的**。`getStore()` は CHAIN として辿るが落とさない。`Cache::extend()` は NON_WRITE に分類されて素通し。`new Repository(...)` / `new CacheManager(...)` は誤検出回避のため**意図的に走査から外している** |

さらに、gate 冒頭 10-16 行には次の主張がコメントとして残っている。

> ★なぜ静的検査か (実行時検出では捕まらない):
> テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
> 'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
> (中略) 実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。

この主張は AG-151 が名指しで**棄却した**。棄却の理由は「実行時層は直列化の検査ではなく**値の検査**
だから」である — 受け皿 (`Illuminate\Cache\Repository`) を包んで `put()` に渡された値そのものを
再帰的に見る形なら、保管方式が直列化しない array store でも同じように発火する。
したがって本リポジトリのコメントは**事実として誤り**であり、書き直しが要る。

### 2 層が相補である根拠 (家系の実測。片方では原理的に穴が残る)

aigenba の乖離台帳 D4 が解消時に残した両方向の実測が根拠である。

- **静的層だけが見えるもの**: 呼び出し元が 0 件のキャッシュ利用。テストが踏まないので
  実行時層には永久に見えない
- **実行時層だけが見えるもの**: 同梱パッケージ (vendor) 配下の書き込み。リポジトリに実体が無いので
  静的走査の母集団に入らない

本リポジトリでも**両方向の実例が現に存在する**ことを実読で確認した。

- 静的層だけが見える例: `vendor/kent013/laravel-prism-prompt/src/PromptTemplate.php` の
  `fromYaml()` が `Cache::store(...)->put($cacheKey, $instance, $ttl)` で
  **PromptTemplate オブジェクトそのもの**を既定ストアへ入れる。`config/prism-prompt.php` の
  `cache.enabled` は `env('PRISM_PROMPT_CACHE', true)` = **既定で有効**である。
  ただし `fromYaml()` の呼び出し元は本リポジトリにも同梱パッケージ内にも 0 件で
  (窓口 `PromptDefense` が使う `Prompt::load()` は `loadMetadata()` 経由で
  `PromptTemplate::fromYaml()` を通らない)、**現時点でテストは 1 度も踏まない**
- 実行時層だけが見える例: 上記のとおり vendor 配下は静的走査の母集団 (`app` / `routes` /
  `database` / `tests`) に無いので、**呼び出し元が生まれた瞬間に静的層は沈黙する**。
  そのとき捕まえられるのは実行時層だけである

つまり aicue は「静的層のみ」でいる限り、**同梱パッケージ由来の書き込みに対して構造的に無防備**である。

### なぜ今やるか (使命との関係)

AI-CUE は SOP と生成シナリオ、撮影テイクという**顧客の業務知識**を扱う。キャッシュ経由の
逆シリアライズは、そこへ到達する経路を 1 本増やす。規約 (AGENTS.md セキュリティ不変条件 11) は
既にあるが、v1 の静的層が保証しているのは「**申告なしに書き込み経路を増やせない**」ことだけで、
「**申告された値が実際に素データである**」ことは gate 自身が「保証しないもの」として明記している
(目録の `payload` 欄は人間の申告である)。後者を機械で保証するのが実行時層である。

## 改善アイデア

**実行時層を新設し、テストの全レーンへ結線する。あわせて静的層の誤ったコメントを訂正し、
境界迂回の hard fail を v2 の水準まで引き上げる。**

### 方針 1: 受け皿 (Repository) を包む。イベント購読にはしない

`Illuminate\Cache\Events\KeyWritten` の購読は差し替え可能な境界で、テスト本体の `Event::fake()` や
store 設定の `'events' => false` で無効化できる。`Illuminate\Cache\Repository` の書き込みメソッドは
イベント層より**下**にあるため、どちらの影響も受けない。家系の 2 実装 (laravel-claude-template /
aigenba) はどちらも Repository 境界を採っており、本リポジトリもそれに揃える。

包む口は `CacheManager::repository()` ただ 1 つでよい。vendor の組み込み driver 生成
(`createArrayDriver()` / `createDatabaseStore()` 等) はいずれも `repository()` を通るため、
ここ 1 箇所の override で array / database / file いずれにも効く。

### 方針 2: 結線の形は既存の guard 慣行 (StrayHttpRequestGuard / StrayLlmCallGuard) に揃える

本リポジトリには既に同型の実行時 guard が 2 本あり、`tests/Pest.php` の全レーンで
`install()` (beforeEach) / `flushAndFailIfStray()` (afterEach) / `reset()` (finally) の
3 点セットで結線されている。キャッシュ guard も**同じ形**にする。

- **違反は「その場で例外」と「accumulator への記録」の両方**にする。片方だけでは足りない —
  アプリ側の `catch (Throwable)` (準拠実装 `FxRateService` 自身が読み戻し失敗時に握り潰す形を持つ)
  で例外が消えても、afterEach の flush で必ず赤くなる必要がある
- **意図的に違反を起こす自己検査**のために `drainForAssertion()` を持たせる
  (`StrayLlmCallGuard` と同じ)

### 方針 3: 露出した既存違反は「直す」を既定にし、免除の口を作らない

実行時層を全レーンへ結線すると、array store の性質に守られて緑だった書き込みが露出しうる。
本設計は**免除目録を持たない**。理由は 2 つ。

1. 家系の正典が「例外を作らない」を明示している (AG-107 由来。aigenba は許可一覧の撤去まで行った)
2. 事前調査の実測で、**露出する見込みが極めて小さい**

事前調査 (実読) の結果:

- `app/` のキャッシュ書き込みは `FxRateService::put` の 1 件だけで、渡すのは
  `FxSnapshotDto::toArray()` の連想配列である
- `tests/` の書き込みは静的層の目録 (L3 面) と exact-fit で、現在 `write` 役割は 0 件
  (`lock-only` 6 件 + `driver-handoff` 1 件 + `write` は `FxRateService` のみ)
- vendor 側でテストが実際に踏む書き込みは、いずれも素データであることを実読で確認した —
  Laratrust の役割 / 権限キャッシュ (`->get()->toArray()` の配列)、
  `Illuminate\Cache\RateLimiter` (整数と時刻の整数)、
  `Illuminate\Console\Scheduling\CacheEventMutex` / `CacheSchedulingMutex` (真偽値)、
  `Illuminate\Queue\Worker` の未処理例外カウンタ (整数)、
  同梱パッケージ `laravel-prism-prompt` の未知モデル警告の抑止 (整数)
- Livewire の `#[Computed(persist: true)]` / `#[Computed(cache: true)]` は
  任意の戻り値をキャッシュへ入れる形だが、**本リポジトリにも Filament にも使用箇所が 0 件**である

したがって「露出したら直す」を既定にできる。**万一この見込みが外れて大量に露出した場合の扱いも
設計で先に決めておく** (下の「露出時の扱い」)。

### 方針 4: 境界迂回の hard fail は静的層の責務として既存 gate に足す

v2 要素 (4) は静的な性質 (「そういう書き方をしていない」) なので、実行時層ではなく
既存の静的 gate に足す。既存 gate の責務分担 (L1 語彙 / L2 書き込み経路 / L3 面) は壊さず、
**L4 = 境界迂回**を新しい検査として追加する。

- `Cache::extend()` を NON_WRITE から外して迂回語彙へ移す
  (独自 creator は `repository()` を通る保証が無く、実行時層の被覆から抜ける口になる)
- `getStore()` を CHAIN から迂回語彙へ移す (保管先を直接触る = 受け皿を跨ぐ)
- 受け手型の**直接生成** (`new Repository` / `new CacheManager` / `new TaggedCache`) を検出する
- 迂回は **0 件で pin** する。ただし**実行時層の実装ファイル自身**は構造上これらを避けられない
  (`extends Repository` / `Store` 型の引数) ため、**名指しの 1 群**として扱い、
  「`$store` は guard 付き受け皿の第 1 引数以外に現れない」という構造条件を機械検査する
  (laravel-claude-template と同じ形)

### 方針 5: 誤った説明の訂正と、2 層の責務分担の明文化

- 静的 gate 冒頭の「実行時 detector は原理的にこの穴を塞げない」を削除し、
  **2 層構成 (静的層 = 申告の全数性 / 実行時層 = 値の実体) の責務分担**を書く
- AGENTS.md セキュリティ不変条件 11 と `docs/app-integration-guide.md` §7 不変条件 6 の
  「静的検査で塞ぐ」という記述を「静的層 + 実行時層の 2 層で塞ぐ」に直す
- **保証しないものの正本は実行時層の docblock に置き**、AGENTS.md / guide には写さない
  (2 か所に書くと必ず食い違う。ドメイン規約 17 と同じ扱い)

## 期待効果

- **使命への貢献**: 顧客の業務知識 (SOP / シナリオ / テイク) を扱うアプリのキャッシュ経路から、
  逆シリアライズによる任意コード実行の余地を機械で塞ぐ。とくに**同梱パッケージ由来**の
  書き込みに対して現在まったく効いていない防御を立てる
- **家系との整合**: 台帳の判定を `update_pending` (v1) から v2 相当へ進める。
  家系 6 リポジトリのうち v2 実装は 2 本 (laravel-claude-template / aigenba) で、
  aicue が 3 本目になる
- **誤情報の除去**: AG-151 が棄却した主張がコードコメントとして残っている状態を解消する。
  この記述は「実行時層は要らない」という誤った判断を将来のセッションへ再生産する

## 実装方針 (概要)

| # | 施策 | 主な変更ファイル |
|---|---|---|
| A | 実行時層の新設 (値検査器 / guard 付き受け皿 / guard 付き manager / 例外 / guard 本体) | `tests/Support/Cache/` 配下 5 本 (新規) |
| B | 全レーンへの結線 | `tests/Pest.php` |
| C | 実行時層の振る舞い検査 (正負コントロール・funnel の実証・上限・自己参照) | `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` (新規) |
| D | 結線の pin (レーンごとに install / flush が居ることを deny-by-default で固定) | `tests/Architecture/CacheGuardLaneWiringGateTest.php` (新規) |
| E | 静的層の冒頭コメント訂正 + L4 (境界迂回) の追加 + 目録の役割追加 | `tests/Architecture/CachePayloadPlainDataGateTest.php` |
| F | 規約の明文化の更新 | `AGENTS.md` / `docs/app-integration-guide.md` / `docs/architecture.md` |
| G | 同梱パッケージのオブジェクトキャッシュ経路を閉じる | `config/prism-prompt.php` + `tests/Feature/Config/ConfigHardeningTest.php` |
| H | テンプレートとの差の登録 (差が出る場合) | `docs/template-divergence.md` |

### 露出時の扱い (先に決めておく)

結線後に既存テストが赤くなったとき、次の順序で判断する。**免除目録は作らない**。

1. **アプリ側の書き込み**が露出した → **必ず直す**。素の配列にして入れ、読み戻しで
   組み立て直す (準拠実装 `FxRateService` + `FxSnapshotDto`)。あわせて静的層の
   L2 目録へ登録する
2. **テスト側の書き込み**が露出した → **必ず直す**。テストが本番で壊れる書き方を先取りしている
   状態なので、そのまま残す理由が無い
3. **同梱パッケージ (vendor) 由来**が露出した → まず**その機能をアプリ側で無効化できるか**を見る
   (準拠する先例: aigenba は `prism-prompt` のテンプレートキャッシュを設定で無効化した)。
   無効化できない場合に限り、**その場しのぎの免除ではなく**、
   (a) パッケージ側の修正、(b) 当該機能の不使用、(c) 台帳への議題化 の 3 択で判断する。
   **guard 側に許可一覧を足す選択肢は取らない** (許可一覧の禁止は正典の要素そのものである)
4. **想定を超える件数 (目安: 10 ファイル以上) が露出した**場合だけ、実装を止めて設計へ差し戻す。
   このときも既定の免除を作らず、**露出の一覧を devnotes に残して TODO を分割する**

### 意図的に違反を起こす自己検査の扱い

実行時層の負例テストは**意図的にオブジェクトをキャッシュへ入れる**。これは静的層から見ると
新しい書き込み経路なので、L2 目録への登録が要る。次の 2 点で扱う。

- 書き込みを**テストファイル内の 1 つのヘルパ関数へ集約**し、目録の key がテストの並べ替えで
  ずれないようにする (laravel-claude-template と同じ形)
- L2 目録の `proof` 欄は本来「配列往復を固定する単体テストのパス」だが、自己検査の entry は
  往復ではなく**違反が検出されること**を固定する。entry に**種別を明示**して、
  検査 3 が種別ごとに要求を切り替える形にする (種別を持たせずに意味の違う値を同じ欄へ
  入れると、目録の読み手が誤解する)

## 制約・前提

- **テストレーンだけの機構である**。本番のキャッシュ経路には一切触れない
  (`tests/Support/` 配下と `tests/Pest.php` だけを変える)。ただし施策 G だけは
  `config/` を触るので、本番の挙動 (テンプレートキャッシュの無効化) が変わる
- **既存 gate (1455 行) の責務分担を壊さない**。L1/L2/L3 の構造・語彙表・正負コントロールは
  そのまま残し、L4 を足す形にする
- `Illuminate\Cache\RateLimiter` は**既存 gate が明示的に母集団から外している**
  (「レート制限。ThrottleCoverageInventoryTest の担当」)。実行時層もこの区分に従い、
  既に解決済みの RateLimiter インスタンスへ**手を入れない**。
  帰結として流量制限の書き込みは guard を通らない場合がある。これは
  **保証しないもの**として docblock に書く (aigenba は反射でここへ手を入れたが、
  本リポジトリは既存の区分と整合させる方を採る)
- **並列実行**: accumulator はプロセス内 static である。`--parallel` の worker 間では共有しない
  (既存の 2 guard と同じ)
- **`install()` より前 (アプリ boot 中) の書き込みは観測できない**。そこは静的層が覆う
- PHPStan level 10 を通す。`tests/` も解析対象である
- 走査器・gate の新設・変更なので、AGENTS.md「走査器・gate を新設・変更するときに同じ PR で
  揃える 4 点」(負例と正例 / 解決できない形を落とす / 空振り検知 / docblock に保証範囲) が
  全面的に適用される

## スコープ外

- **本番のキャッシュ実行経路への guard 導入**。これはテストレーンの検査機構であり、
  本番へ入れると性能と挙動の両方を変える。正典も要求していない
- **キャッシュの保存先・キー設計・有効期限の設計** (feature の boundary が明示的に除いている)
- **キュー・セッション・経路表のキャッシュ** (Laravel 側で別の仕組みが扱う。
  必要になったら別 feature として起票する、と台帳が定めている)
- **静的層の L1/L2/L3 の作り直し**。L4 の追加と冒頭コメントの訂正に限る
- **`Illuminate\Cache\RateLimiter` の包み込み** (上の制約を参照)
- **台帳への書き戻し (append_event)**。実装完了後に別途行う
