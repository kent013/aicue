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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上記に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の補足】
- 本件は「テスト・探索的QA 用の偽の外部サービス (fake) の宣言と配線」の設計であり、利用者向け機能ではない。
  過剰な機構を作らないこと (思考原則 2「今必要なものだけ作る」) を特に厳しく見てほしい。
- 家系 (複数リポジトリ) の共通台帳が定める標準形 v1 の要求は次の 6 点である:
  (1) 宣言の単一正本 (定数 1 か所、設定はそれを参照するだけ)
  (2) 宣言と差し替え実装のずれの即時検出
  (3) 差し替え処理を 1 本に集約し全レーンが共有、レーンからの個別 fake 直呼びの静的禁止
  (4) 安全下限集合と、種類ごとの独立した切り替え
  (5) fake の実体を本番 autoload 配下に置く
  (6) 「差し替えない対象」の明文化
  本設計が (4) の安全下限集合を「新設しない」と判断していることの妥当性を必ず評価すること。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: external-fakes-declaration-bundle (偽の外部サービスの宣言集合と、投入データの配線検査)

## 背景・課題

家系の機能台帳 (lctl) が別々に立てている 4 件は、**同じ 2 つの穴**を別のレイヤから指している。
本設計はこの 4 件を 1 件に束ねて決着させる。

| lctl feature | 台帳の観測 (2026-08-14 時点) | HEAD 実読の結果 |
|---|---|---|
| external-fakes-declaration | aicue は「宣言そのものを新設する側」。設定は独立フラグ 3 本にとどまり、集合の宣言が無い | **成立する**。集合は `tests/Support/ExternalFakes/ExternalFakeWiringInventory` にあるがテスト側で、本番側 (`FakeExternalsServiceProvider`) は同じ集合を手書きで持つ = 二重管理 |
| external-fakes-wiring-gate の残差 | 柱 2 (別プロセス観測) と柱 3(b) (起動時の実 env 二重判定) が未実装 | **成立する**。ただし 2026-08-06 に別プロセス観測を見送った固有理由の片方 (「aicue は Socialite driver を fake 化していない」) は**HEAD では崩れている** |
| bughunt-runtime | 偽の外部サービスの配線側は T119 で入ったが、**投入データ (seeder) の配線側は未着手** | **成立する**。provision と reseed の 2 か所に同じ 4 本の `db:seed` が手書きで並び、どちらか片方を直し忘れても誰も気づかない |
| skill-bug-hunt | pending の理由は「投入データ側の検査の欠落」1 点だけ。台帳自身が「これは本 feature の boundary の外」と指摘 | **本 feature に実作業は無い**。後述 |

### 穴 1: 「何をどの偽物へ差し替えるか」の集合が本番側に無い

現状、同じ集合が 3 か所に分かれている。

1. `app/Providers/FakeExternalsServiceProvider` — `bind()` 7 本 + 許可環境の定数 3 本 (手書き)
2. `tests/Support/ExternalFakes/ExternalFakeWiringInventory` — 同じ 7 組 + 許可環境 3 本 (手書きの写し)
3. `config/testing.php` — フラグ 3 本 (`fake_externals` / `fake_llm` / `fake_storage`) と、
   差し替え対象を日本語で説明した注釈

1 と 2 のずれは `ExternalFakeWiringInvariantTest` の 3-8 (provider のソースを走査して bind 組を
集合比較する) が検出する。しかし**これは「同じ内容を 2 か所に書いて、ずれたら落とす」形**であり、
片方から他方を導いていない。許可環境 (`EXTERNAL_FAKE_ENVIRONMENTS` / `SSO_FAKE_ENVIRONMENTS` /
storage の許可環境は `FakeStorageGate` に直書き) に至っては集合比較すら無く、
**provider が許可環境を 1 つ増やしても、それが `production` / `staging` 以外なら誰も落ちない**。

さらに `config/testing.php` の注釈は「どの到達点を差し替えるか」を日本語で列挙しているが、
コードから導いていないため増減に追随しない (家系の他 2 リポジトリで実際に説明文と定数が
ずれている事故が観測されている)。

### 穴 2: 投入データ (seeder) の配線が誰にも検査されていない

bug-hunt 環境の投入データは `scripts/bug-hunt-shard.sh` の 2 か所に手書きで並ぶ。

- `cmd_provision`: `migrate:fresh --seed` → `ManualTestSeeder` → `BughuntBillingSeeder` →
  `AdminUserSeeder` → `BughuntOAuthSeeder`
- `cmd_reseed`: 同じ 5 段 (現時点では一致している)

偽の外部サービスの配線 (T119) は「登録漏れは無音で本物が動く」ことを理由に deny-by-default の
実証 gate を持っているのに、**投入データ側は同じ理由が当てはまるのに検査が 1 つも無い**。
実際に起きうる無音の事故は 3 つある。

1. provision にだけ seeder を足して reseed に足し忘れる → 子セッションが `reseed` した瞬間、
   課金状態や CLI セッションが消えた環境で探索が続き、**アプリの不具合として誤報告される**
2. bug-hunt 専用 seeder を `DatabaseSeeder` に足す → `migrate:fresh --seed` が走る全環境
   (開発機・テストレーン) で実行され、三重ガードが無い seeder なら dev DB を汚す
3. 新しい bug-hunt 専用 seeder を三重ガード無しで足す → 開発機で誤って
   `db:seed --class=...` した瞬間に dev DB へ既知資格情報が入る

### 穴 3 (隣接): 本番混入防止の 2 段目が設定値しか見ていない

`ProductionEnvGuard` はフラグ 3 本を検査項目に持つが、読むのは `config()` だけである。
`php artisan config:cache` を**非 bug-hunt 環境で作り直し忘れた**まま出荷すると、
設定キャッシュは false のままなので guard は通る。ところがその後に設定キャッシュが失われた起動
(`config:clear` / キャッシュ生成に失敗したデプロイ) では `env()` が読み直され、
**プロセスの実環境変数に `TESTING_FAKE_EXTERNALS=true` が残っていれば本番で偽の決済が立つ**。
これは本リポジトリが `route:cache` について既に運用要件として警戒している事故 (AGENTS.md
§運用要件 (route:cache)) と同じ形で、**設定キャッシュを信用しない二重判定**が対策になる。

### 穴 4 (隣接): テストプロセスの中でしか差し替えを確かめていない

T119 の実証 gate は「provider を手で実走させてから解決する」形で、
**実際の bug-hunt 起動 (別プロセス・`APP_ENV=bughunt.local`・遅延読み込み provider・
設定キャッシュ) を 1 度も踏んでいない**。

2026-08-06 に別プロセス観測を見送った理由は 2 つ記録されている。HEAD で再確認した結果:

- 「aicue は Socialite driver を fake 化しておらず、起動順で勝敗が決まる差し替えを持たない」
  → **崩れている**。HEAD には `FakeSocialiteDriverResolver` が実在し、provider のコメント自身が
  「Socialite の Factory へ直接 bind しない (`SocialiteServiceProvider` は遅延読み込みで、
  最初の解決時に singleton が後勝ちして fake を消すため)」と書いている。
  **起動順で勝敗が決まる差し替えを、回避策込みで持っている**のが現状であり、
  その回避策が効いていることは今どこでも実測されていない
- 「bug-hunt レーンへのフラグ注入は `scripts/bug-hunt-shard.sh` の self-test が検証済み」
  → **半分成立する**。self-test が確かめるのは「スクリプトが環境変数を組み立てること」までで、
  **その環境変数で起動したアプリが実際に偽物を解決すること**は誰も見ていない

## 改善アイデア

### 施策 1: 差し替えの宣言集合を本番側 1 か所へ移し、経路登録・実行時判定・許可環境をそこから導く

`app/Support/ExternalFakes/` に**宣言の正本**を新設し、次の 3 つを 1 か所へ集める。

- どの到達点 (abstract) を、どの本物 / 偽物のクラスへ結ぶか
- どの設定フラグで有効になるか
- どの環境で許可するか

`FakeExternalsServiceProvider` は宣言を読んで差し替えるだけの実行者になり、
自前の bind 列と許可環境の定数を**消す**。`FakeStorageGate` の許可環境も宣言から読む。
テスト側の写し (`tests/Support/ExternalFakes/ExternalFakeWiringInventory`) は**削除**し、
gate は宣言を直接読む (思考原則 3: 書き換えると決めたら旧実装を同じ変更で消す)。

あわせて「**差し替えてはいけない到達点**」を宣言に持たせ、宣言集合と交わったら落とす
(署名検証や SSRF 検査を偽物に落とす変更を、追加した本人の手元で止める)。

### 施策 2: 投入データ (seeder) の配線検査を、偽物の配線検査と同じ作法で入れる

`database/seeders/` の全 seeder を母集団とする deny-by-default の目録を作り、
区分と 30 文字以上の理由付きで登録を必須にする。目録が固定する不変条件は 4 つ。

1. bug-hunt レーンで明示投入する seeder の集合と順序が、`cmd_provision` と `cmd_reseed` で**一致する**
2. その集合が目録の宣言と**過不足なく一致する** (足し忘れ / 消し忘れがその場で落ちる)
3. bug-hunt 専用区分の seeder は `DatabaseSeeder` の呼び出し列に**現れない**
4. bug-hunt レーンで走る seeder は、暴発を止めるガードを `run()` の**最初の実効文**として持つ

### 施策 3: 本番混入防止に、設定値とプロセスの実環境変数の二重判定を足す

`ProductionEnvGuard` のフラグ 3 本の判定を、`config()` と**実環境変数 3 経路**
(`$_SERVER` / `$_ENV` / `getenv()`) の独立判定にする。解釈できない値は安全側 (違反) へ倒す。

### 施策 4: 別プロセスで起動して、差し替えが実際に効いていることを観測する

子プロセスで `APP_ENV=bughunt.local` + フラグを与えてアプリを起動し、
container から解決した実クラスと、SSO の転送先ホストを観測する小さな観測用スクリプトを置く。
観測点は 4 つ (対照を含む)。

1. フラグ有効 + `bughunt.local`: 宣言集合の全件が**偽物のクラスで厳密一致**する
2. 同上で SSO の転送先が**外部の身元確認サービスではなく自ホスト**である
   (解決クラス名だけを見る検査は、転送先を戻す退行を緑で通す)
3. 対照: フラグ無効 → 全件が本物のクラスで厳密一致する
4. 対照: `APP_ENV=production` → 起動が**非ゼロ終了**する (本番混入防止が別プロセスでも効く)

### 施策 5: skill-bug-hunt に実作業は無い (作らないことの明記)

台帳の skill-bug-hunt が pending の理由に挙げるのは「投入データ側の検査の欠落」1 点だけで、
台帳自身が「これは本 feature の boundary が明示的に『含まない』と書いた実行時配線の関心事」
と指摘している。HEAD 実読でも、本 feature の boundary が求める 5 要素
(置き場所の名前 / 骨格 11 節 / 兆候表 / 子への共通指示 / 手順書の不変条件の検査 185 行) は
**すべて実在する**。よって **skill-bug-hunt に対する実装作業は無い**。
施策 2 が入れば pending の理由そのものが消える (理由は bughunt-runtime 側にあった)。

## 期待効果

- **使命への貢献 (間接だが直接的な費用の話)**: bug-hunt は実ブラウザで撮影 PWA を含む全画面を
  走る。差し替えが 1 つ無音で外れると、探索そのものが実 Stripe に課金を作り、実 IdP へ出て、
  実 S3 を汚す。宣言を本番側 1 か所に集めることは、この事故の入口を 3 か所から 1 か所へ減らす
- 投入データの配線検査が入ると、「reseed したら課金状態が消えていた」類の**環境起因の偽の所見**が
  構造的に消える (探索の結果が信用できるようになる)
- 別プロセス観測は、遅延読み込み provider の後勝ち・設定キャッシュ・起動順という
  **テストプロセスの中では原理的に見えない層**の退行を初めて可視化する

## 実装方針 (概要)

| # | 施策 | 主な変更 |
|---|---|---|
| 1 | 宣言集合の一元化 | `app/Support/ExternalFakes/` に宣言 + 値オブジェクトを新設、provider / `FakeStorageGate` / seeder のフラグ参照を宣言へ寄せる、テスト側の写しと不要になった走査を削除 |
| 2 | 投入配線の検査 | `tests/Support/Bughunt/` に目録 + シェル関数窓の読み取り口、`tests/Architecture/BughuntSeedWiringInvariantTest` を新設 |
| 3 | 実 env 二重判定 | `App\Support\ProductionEnvGuard` にフラグ 3 本の二重判定を追加 |
| 4 | 別プロセス観測 | `tests/Support/ExternalFakes/fake-wiring-probe.php` (観測用スクリプト) + `tests/Architecture/ExternalFakeBootProbeTest` |
| 5 | skill-bug-hunt | 作業なし。理由を `docs/` と台帳報告に残す |

## 制約・前提

- **フラグの本数と env 契約は変えない**。`TESTING_FAKE_EXTERNALS` / `TESTING_FAKE_LLM` /
  `TESTING_FAKE_STORAGE` の 3 本と既定値はそのまま (env ひな型・shard スクリプト・
  `BughuntEnvExampleContractTest` を巻き込む破壊的変更にしない)
- **正典 v1 の「安全下限集合」は新設しない**。aicue には集合を絞る減算の仕組み自体が無く、
  「bug-hunt で偽物を外せない capability」は既に 2 つの gate が機械化している
  (`BughuntEnvExampleContractTest` が `TESTING_FAKE_EXTERNALS=true` を固定し、
  `.env.bughunt.local.example` が「このフラグだけはスクリプトが注入しない」理由を記録する)。
  同じ不変条件を 2 か所で持たない (思考原則 2)
- `FakeClassReferenceInvariantTest` の参照 allowlist は**必ず更新する** (宣言クラスが偽物の
  クラス名を参照するため)。件数固定の 4-4 も同じ変更で直す
- Architecture レーンは `RefreshDatabase` を使わない。宣言に entry を足すときは
  abstract / real / fake のコンストラクタが DB に触れないことを確認する (既存の注意書きを引き継ぐ)
- 別プロセス観測は**DB に触れない**。解決とルーティングだけを見る (D7 が subprocess 方式を
  保留した理由 = 未コミットのフィクスチャが子プロセスから見えない、は本件には当たらない)

## スコープ外

- フラグの粒度を 5 本 (決済 / 人間性確認 / 外部ログイン / LLM / 保存) へ割ること
- 実 S3 接続の実配線 (`--real-storage` は現在も inert なまま)
- bug-hunt の探索手順書 (skill-bug-hunt) の変更
- 家系の他リポジトリへの還流作業 (台帳への報告は監督セッションの責務)

