Round 2 の指摘 2 件へ対応した。対応マトリクスと修正後の概念設計 (全文) を示す。

# 対応マトリクス: conceptual-review Round 2

## [Warning] `config/testing.php` の日本語列挙をどう処理するかが未記載
- 判断: 対応する
- 根拠: 指摘のとおり。注釈が集合から独立して残る限り「宣言の単一正本」は完成しない。
  家系の他 2 リポジトリでは実際に注釈と定数がずれた事故が観測されている。
- 対応内容: 施策 1 に「`config/testing.php` から差し替え対象の列挙を削除し、残すのは
  フラグの意味・既定値・本番での扱いだけ。対象と許可環境の正本は宣言クラスだと 1 行で指す」を追記。

## [Warning] seeder のガード要件が通常 seeder まで含むように読める
- 判断: 対応する
- 根拠: 指摘のとおり文言が広すぎた。`migrate:fresh --seed` は `DatabaseSeeder` 配下も走らせるため、
  「bug-hunt レーンで走る seeder」と書くと通常 seeder まで対象に読める。
- 対応内容: 不変条件 4 の対象を「目録で bug-hunt 専用区分 / 共用区分に分類され、
  `cmd_provision` / `cmd_reseed` から明示実行される seeder」に限定し、区分ごとの要求を表にした。
  あわせて `ManualTestSeeder` (開発者が手で流す fixture) はガードを要求しない区分として
  明示し、その判断を目録に理由付きで残すことにした (判断を隠さない)。


---

## 修正後の概念設計 (全文)

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

あわせて次の 3 つを宣言に持たせる。

- **差し替えてはいけない到達点** (署名検証・SSRF 検査)。宣言集合と交わったら落とす
- **設定フラグと環境変数名の対**。本番混入防止 (施策 3) と bug-hunt の環境ひな型検査が同じ対を読む
- **bug-hunt レーンで偽物を外せないフラグ** (`bughuntRequiredFlags()`)。
  正典 v1 の「安全下限集合」に当たる**名前付きの正本**をここに置き、
  既にこの不変条件を固定している `BughuntEnvExampleContractTest` は
  自前の定数をやめて宣言を読む形へ寄せる (二重管理にはしない)

`config/testing.php` からは**差し替え対象の列挙を削除する**。設定ファイルに残すのは
フラグの意味・既定値・本番での扱いだけで、「どの到達点をどの偽物へ差し替えるか」と
「どの環境で許可するか」の正本は宣言クラスであると 1 行で指す
(注釈が集合からずれる形を構造的に無くす)。

### 施策 1c: レーン側から個別の偽物を直接差し替えることの静的禁止

正典 v1 (3) が求める「レーン側から個別の fake を直接呼ぶことの静的禁止」に当たる。
**現時点の違反は 0 件**なので、増えないことを今固定するのが最も安い。

- 禁止するのは「テスト側のファイルが `app/` の偽物の実装クラスを container へ直接結ぶこと」
  (差し替えの入口は宣言 + provider の 1 本だけ)
- **per-test の代役 (`tests/Support/Fake*`) は対象外**。あれは Laravel 公式作法の
  テストダブルであり、bug-hunt レーンの差し替えとは別の概念である
  (思考原則 4: 別物の概念を似ているからで統合しない)
- **保証範囲を誇張しない**: 走査器が読めるのは字句として現れる結び付けの形だけで、
  変数経由の動的な結び付けには沈黙する

### 施策 2: 投入データ (seeder) の配線検査を、偽物の配線検査と同じ作法で入れる

検査の主目的は **bug-hunt 専用 seeder の配線一致と、通常経路への混入防止**である。
母集団を `database/seeders/` の全 seeder に取るのは、「登録しなければ検査対象から外れる」
抜け道を作らないためであって、全 seeder に新しい制約を課すためではない
(bug-hunt に関係しない seeder の区分は「bug-hunt レーンでは投入しない」1 行で終わる)。

deny-by-default の目録を作り、区分と 30 文字以上の理由付きで登録を必須にする。
目録が固定する不変条件は 4 つ。

1. bug-hunt レーンで明示投入する seeder の集合と順序が、`cmd_provision` と `cmd_reseed` で**一致する**
2. その集合が目録の宣言と**過不足なく一致する** (足し忘れ / 消し忘れがその場で落ちる)
3. bug-hunt 専用区分の seeder は `DatabaseSeeder` の呼び出し列に**現れない**
4. **bug-hunt 専用区分と共用区分の seeder だけ**が、暴発を止めるガードを `run()` の
   最初の実効文として持つ

不変条件 4 の対象を誇張しない。`migrate:fresh --seed` は `DatabaseSeeder` とその配下も走らせるが、
**それらにガードを要求しない**。要求するのは目録で次の 2 区分に分類され、
`cmd_provision` / `cmd_reseed` から明示実行される seeder だけである。

| 区分 | 例 | 要求するガード |
|---|---|---|
| bug-hunt 専用 | `BughuntBillingSeeder` / `BughuntOAuthSeeder` | 三重 (フラグ / 環境 / DB 名) |
| 共用 (通常経路にも載る) | `AdminUserSeeder` | 環境 (local は無条件、bughunt.local は DB 名も) |
| 開発者が手で流す fixture | `ManualTestSeeder` | **要求しない** (区分と理由を目録に残して判断を可視化する。ガードを課すかは本件の対象外) |
| bug-hunt レーンでは投入しない | `RoleSeeder` ほか | **要求しない** (混入禁止の検査対象にもしない) |

### 施策 3: 本番混入防止に、設定値とプロセスの実環境変数の二重判定を足す

`ProductionEnvGuard` のフラグ 3 本の判定を、`config()` と**実環境変数 3 経路**
(`$_SERVER` / `$_ENV` / `getenv()`) の独立判定にする。解釈できない値は安全側 (違反) へ倒す。

### 施策 4: 別プロセスで起動して、差し替えが実際に効いていることを観測する

子プロセスで `APP_ENV=bughunt.local` + フラグを与えてアプリを起動し、
container から解決した実クラスと、SSO の転送先ホストを観測する小さな観測用スクリプトを置く。

**観測用スクリプトの責務は 4 つに限る** (壊れやすさを持ち込まないため):
DB へ接続しない / container から解決する / 転送先の URL を組み立てて読む /
終了コードを返す。**HTTP サーバもブラウザも起動しない**。

観測点は 4 つ (対照を含む)。

1. フラグ有効 + `bughunt.local`: 宣言集合の全件が**偽物のクラスで厳密一致**する
2. 同上で SSO の転送先が**外部の身元確認サービスではなく自ホスト**である
   (解決クラス名だけを見る検査は、転送先を戻す退行を緑で通す)
3. 対照: フラグ無効 → 全件が本物のクラスで厳密一致する
4. 対照: `APP_ENV=production` + フラグ有効 → **`ProductionEnvGuard` が実値を検出して
   非ゼロ終了する** (この 1 点に絞る。本番相当の起動全体を模すことはしない)

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
| 1 | 宣言集合の一元化 | `app/Support/ExternalFakes/` に宣言 + 値オブジェクト (`final readonly` + `class-string` 型) を新設、provider / `FakeStorageGate` / seeder のフラグ参照を宣言へ寄せる、テスト側の写しと不要になった検査を削除 |
| 1c | レーンの直接差し替え禁止 | 既存の走査器を使い、テスト側から `app/` の偽物クラスへ直接結ぶ形が 0 件であることを固定 |
| 2 | 投入配線の検査 | `tests/Support/Bughunt/` に目録 + シェル関数窓の読み取り口、`tests/Architecture/BughuntSeedWiringInvariantTest` を新設 |
| 3 | 実 env 二重判定 | `App\Support\ProductionEnvGuard` にフラグ 3 本の二重判定を追加 |
| 4 | 別プロセス観測 | `tests/Support/ExternalFakes/fake-wiring-probe.php` (観測用スクリプト) + `tests/Architecture/ExternalFakeBootProbeTest` |
| 5 | skill-bug-hunt | 作業なし。理由を `docs/` と台帳報告に残す |

## 制約・前提

- **DB 操作は 1 つも増やさない**。本件が触るのは検査 (静的走査 + container 解決) だけで、
  `migrate:fresh` / `db:seed` を新たに実行する経路は作らない。既存の投入経路
  (`scripts/bug-hunt-shard.sh` の用途別 wrapper) は**読むだけ**で、生の artisan / psql は
  扱わない (AGENTS.md §bug-hunt「dev DB 防御 (非交渉)」と禁止事項 3)。
  別プロセス観測も **DB へ接続しない**ことを不変条件に置く
- **フラグの本数と env 契約は変えない**。`TESTING_FAKE_EXTERNALS` / `TESTING_FAKE_LLM` /
  `TESTING_FAKE_STORAGE` の 3 本と既定値はそのまま (env ひな型・shard スクリプト・
  `BughuntEnvExampleContractTest` を巻き込む破壊的変更にしない)
- **正典 v1 の「安全下限集合」は、減算の仕組みごと作るのではなく名前付きの正本 1 つで表す**。
  aicue には集合を絞る減算の仕組み自体が無く (フラグは既定 false = 本物、bug-hunt レーンで
  個別に true にする形)、「bug-hunt で偽物を外せないフラグ」という不変条件は既に
  `BughuntEnvExampleContractTest` と `.env.bughunt.local.example` が機械化している。
  よって**新しい検査は増やさず**、宣言に `bughuntRequiredFlags()` を置いて
  既存 gate がそれを読む形へ寄せる (同じ不変条件を 2 か所に書かない。思考原則 2)
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


---

再レビューを依頼する。全体判定 (APPROVED / CHANGES_REQUESTED) を明示すること。
