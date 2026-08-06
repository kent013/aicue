# Round 2: 対応マトリクスと修正後の概念設計

Round 1 の指摘に対する Claude 側の判断と、それを反映した概念設計の全文を送ります。
Critical 2 件はいずれも「対応する」で、設計を書き換えました。

# 対応マトリクス: conceptual-review Round 1

## [Critical] 同一 container 上での「flag off → provider 再実行 → real に戻る」は成立しない
- 判断: **対応する** (指摘は正しい)
- 根拠: `FakeExternalsServiceProvider` は flag off なら early return するだけで、既に上書きされた
  binding を巻き戻さない。`bind()` の巻き戻し API も無い。往復を同一 app の provider 再実行で
  見る設計は偽レッド/偽グリーンの温床。
- 対応内容: §4 施策 1 の「往復」行を全面書き換え。
  1. container binding の復元は **fresh Application 単位**で見る
     (`$this->refreshApplication()` = 再 bootstrap で config も binding も素の状態に戻る。
     Architecture lane は `RefreshDatabase` を使わないため mid-test の refresh が安全)。
  2. 「fake を触ったテストの後で real に戻る」は **Pest がテスト毎に app を作り直す**という
     フレームワーク保証に乗せ、gate 側は「fake を install する test case の**後続**に
     対照 test case を置く」のではなく、**test case ごとに独立した app で対照を取る**形にする
     (テスト順序に依存させない)。
  3. 真に往復が必要なのは **static (`Prompt::$fake`)** と **route collection** の 2 つだけ。
     ここだけ明示的な復元検査を持つ。

## [Critical] static / config / env / route / container の同一プロセス内リーク
- 判断: **対応する**
- 根拠: Architecture lane には `StrayLlmCallGuard` も `RefreshDatabase` も無い (`tests/Pest.php`)。
  LLM fake の install がリークすると他 Architecture テストを壊す。
- 対応内容: §9 制約に「gate 専用の `beforeEach` / `afterEach` を**このテストファイル内に限定して**
  定義し、`afterEach` で `Prompt::stopFaking()` を必ず実行する」「env/config を書き換える
  test case は try/finally で原値復元し、加えて `refreshApplication()` で締める」を追加。
  storage signed route は provider が `Route::has()` で冪等化済みだが、gate 側でも
  「route 二重登録が起きないこと」を 1 assertion で見る。

## [Warning] 禁止事項 3 の参照ずれ (dev DB 破壊操作であって既存テスト改変禁止ではない)
- 判断: **対応する** (事実誤認)
- 根拠: AGENTS.md 禁止事項 3 は dev DB 破壊操作。app-design スキル側の禁止事項表 #3 が
  「既存テストの削除・上書き」であり、番号を混同した。
- 対応内容: §6.4 の理由を「既存 Feature テストは振る舞い回帰として残し、Architecture 側を
  不変条件の正本にする (AGENTS.md 禁止事項 1 = 不変条件は Architecture/Feature テストへの
  登録まで含めて実装済み)」へ書き換え。番号参照をやめる。

## [Warning] テストファーストの成功判定が曖昧 (新 gate は実装前に素で赤にならない)
- 判断: **対応する**
- 根拠: 指摘どおり。新規 gate は「穴を塞ぐ」類なので素の main では緑になる。
  fail を先に見るには mutation が要る。
- 対応内容: §1 成功判定と §8 を書き換え、**mutation を受入条件へ格上げ**。
  「実装前に mutation を当てて現行検査が緑のままであること (= 穴の実在確認)」→
  「gate 追加後に同じ mutation で赤になること」の 2 段を必須手順にする。

## [Warning] `production` env 一時差し替えの意味が揺れる
- 判断: **対応する**
- 根拠: `$app['env']` の差し替えは既に走り終わった `AppServiceProvider::boot()` の
  `ProductionEnvGuard` を再実行しない。app 全体の production 相当 boot ではない。
- 対応内容: allowlist 外の検査を「**provider 単体の条件分岐検査**」と明示し、テスト名にも
  その限界を出す (`provider 単体: flag=true でも allowlist 外 env では bind しない`)。
  本番混入防止そのものは `ProductionEnvGuard` + `ProductionEnvGuardTest` の担当と
  責務境界を書く。

## [Warning] `bind(A,B)` だけの走査では singleton / instance / extend / contextual に漏れる
- 判断: **対応する** (これが網羅性 gate の要)
- 根拠: 将来 `singleton` へ変えたら inventory 突合をすり抜ける。
- 対応内容: 網羅性走査を 2 条件に強化する。
  1. `FakeExternalsServiceProvider` 内の `$this->app-><api>(` 呼び出しのうち、
     container 差し替え系 API (`bind` / `bindIf` / `singleton` / `singletonIf` / `scoped` /
     `scopedIf` / `instance` / `extend` / `when` / `alias` / `resolving` / `afterResolving`) を
     **すべて検出**し、`bind` 以外が使われていたら fail (= 差し替え API を `bind` に固定する規約)。
  2. `bind(A::class, B::class)` の (A, B) 組が inventory と**集合一致**すること。
  これで API を変えた瞬間に赤くなるので、網羅性の抜け道が塞がる。

## [Warning] interface 系も厳密クラス一致に統一せよ
- 判断: **対応する** (元々そのつもりだが明記が弱かった)
- 対応内容: §2.3 / §4 に「inventory 全 entry で `$resolved::class === $expected` を唯一の判定にする
  (`toBeInstanceOf` は使わない)」と明記。

## [Warning] `--parallel` より test order 依存が主リスク
- 判断: **対応する**
- 対応内容: §8 の検証に「Architecture suite を 2 回連続実行」「`--order-by=random` で実行」を追加。

## [Warning] 柱 3b を外す理由が弱い / 後続 TODO の発火条件を明示せよ
- 判断: **対応する**
- 対応内容: §6.2 に発火条件 3 つ (production で fake flag の incident / near-miss が出た /
  config cache 前提の deploy 手順を変更した / 外部 fake flag が増えた) を明記。

## [Warning] inventory が「一覧を満たすだけの台帳」に形骸化するリスク
- 判断: **対応する**
- 対応内容: inventory entry に `risk` (なぜ外部副作用として危険か) と
  `mutation` (この entry の bind を消すとどの検査が赤になるか) の 2 フィールドを追加し、
  詳細設計の mutation 手順と 1:1 対応させる。

## [Suggestion] 柱 2 を外す判断は妥当 / 使命への貢献は十分
- 判断: **見送る** (指摘なし。現状維持)

---

## 修正後の概念設計 (全文)

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

**成功判定 (= 受入条件。§8 の mutation 手順と 1:1 対応する)**:

新 gate は「穴を塞ぐ」類なので、実装前の main では**素で赤にならない**。
テストファースト (AGENTS.md 思考原則 5) は **mutation の 2 段確認**で満たす:

| # | mutation (一時的にローカルで当ててすぐ戻す) | 実装前 | 実装後 |
|---|---|---|---|
| 1 | `FakeExternalsServiceProvider` の `AutoRechargeGatewayInterface` の bind を削除 | 緑 (= 穴の実在) | **赤** |
| 2 | 同 `TakeObjectStorage` の bind を削除 | 緑 | **赤** |
| 3 | `bootstrap/providers.php` から `FakeExternalsServiceProvider` を削除 | 緑 | **赤** |
| 4 | 同ファイルで provider を `AppServiceProvider` より前に移動 (後勝ち崩し) | 緑 | **赤** |
| 5 | provider に架空の `bind(Foo::class, FakeFoo::class)` を追加 | 緑 | **赤** (inventory 未登録) |
| 6 | provider の `bind` を `singleton` に変更 | 緑 | **赤** (差し替え API 固定) |
| 7 | 任意の Service に `use App\Services\Billing\Fakes\FakeStripeGateway;` を追加 | 緑 | **赤** |

「実装前に全部緑」であること自体が**穴の実在の実証**であり、これを先に記録してから実装に入る。

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
**interface 系 (課金 3 本) も同じ厳密一致に統一**する (`toBeInstanceOf` は inventory 由来の
検査では一切使わない)。将来 fake が real の装飾実装になっても検査意図がぼやけないため。

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
`abstract / real / fake / flag / allowed envs / phase / risk / mutation` で持つ。
`risk` は「なぜ外部副作用として危険か」、`mutation` は「この entry の bind を消すと
どの検査が赤になるか」で、§1 の mutation 表と 1:1 対応させる (inventory を
「一覧を満たすだけの台帳」に形骸化させない)。

テストは inventory を回して以下を見る:

| 検査 | 内容 | app の扱い |
|---|---|---|
| 対照 (flag off) | 既定 container で解決 → **real と厳密クラス一致** | 素の test app (何も書き換えない) |
| 実証 (flag on + allowlist 内) | flag を立てて provider を実走 → **fake と厳密クラス一致** | 独立 test case (Pest がテスト毎に app を作り直す) |
| allowlist 外 | flag on + env=`production` → real のまま (課金は warning ログも) | **provider 単体の条件分岐検査**。app 全体の production boot ではない (§ 限界を明記) |
| 復元 | 実証と同じ test case 内で `refreshApplication()` → **real に戻る**。`Prompt::isFaking()` が false | 再 bootstrap で config も binding も素に戻ることを実証する |
| 登録点 | `bootstrap/providers.php` の配列に provider が居て `AppServiceProvider` より**後**。かつ起動済み container の `getLoadedProviders()` に載っている | 素の test app |
| 網羅性 | provider ソースの token 走査 (下記 2 条件) | 走査のみ |

> **重要 (Codex Round 1 Critical)**: 「flag を戻して provider を再実走すれば real に戻る」は
> **成立しない**。provider は flag off なら early return するだけで、既に上書きされた binding を
> 巻き戻さないため。復元は **fresh Application (`refreshApplication()` による再 bootstrap)** で
> 見る。テスト間の復元は Pest が test case ごとに app を作り直すフレームワーク保証に乗せ、
> gate は **test case 順序に依存しない**形にする (対照は fake を触らない独立 test case で取る)。
> 真に明示的な往復検査が要るのは **static (`Prompt::$fake`)** と **route collection** の 2 つだけ。

> **allowlist 外検査の限界を明示する**: `$app['env']` を `production` に差し替えても、
> 既に走り終わった `AppServiceProvider::boot()` の `ProductionEnvGuard::enforce()` は再実行されない。
> この検査は **provider 単体の条件分岐**を見るものであり、production 相当の app 全体 boot の
> 再現ではない。テスト名にもその限界を出す (`provider 単体: …`)。
> **本番混入防止そのものの正本は `ProductionEnvGuard` + `ProductionEnvGuardTest`** であり、
> 本 gate は非本番側の配線だけを見る (責務境界)。

**網羅性走査の 2 条件** (deny-by-default):

1. `FakeExternalsServiceProvider` 内の `$this->app-><api>(` 呼び出しのうち、container 差し替え系
   API (`bind` / `bindIf` / `singleton` / `singletonIf` / `scoped` / `scopedIf` / `instance` /
   `extend` / `alias` / `when`) を検出し、**`bind` 以外が使われていたら fail**
   (= 差し替え API を `bind` に固定する規約。`singleton` へ変えた瞬間に赤くなるので
   条件 2 の抜け道が塞がる)。
2. `bind(A::class, B::class)` の (A, B) 組が inventory と**集合一致**すること。

LLM (Prism) は container ではなく static のため inventory とは別枠で
「`bughunt.local` ∧ `fake_llm=true` でのみ `Prompt::isFaking()` が true」
「`afterEach` を経て必ず false に戻る (static リーク検出)」の 2 点のみ。

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

→ **後続 TODO 候補**。以下のいずれかを踏んだら再検討する (発火条件):

- production で fake flag に起因する incident / near-miss が出た
- config キャッシュ前提の deploy 手順を変更した (`config:cache` の実行位置・タイミングを動かした)
- 外部 fake の capability flag が 3 本から**増えた** (= 混入面が広がった)
- 家系の agenda が「3 段そろえる」で裁定された

### 6.3 別 feature の範囲 (brief が明示)

- 宣言そのものと宣言⇔実装の一致 → `external-fakes-declaration`
- 未登録の外部通信の実行時遮断 / 資格情報無効化 / 未消費検出 → `external-egress-default-deny`
- 外部到達点の集約と直呼びの静的禁止 → `external-seam-funnel`
- 決済 fake の実体 → `stripe-fake-lane`
- fake と無関係の本番起動時検査 (暗号鍵 / Cookie / 信頼プロキシ)

### 6.4 既存テストの改変

`tests/Feature/Providers/FakeExternalsServiceProviderTest.php` と
`tests/Feature/Storage/FakeStorageRouteTest.php` は**触らない**。
理由は「既存 Feature テストは**振る舞いの回帰**として残し、Architecture 側を**不変条件の正本**に
する」から (AGENTS.md 禁止事項 1: 不変条件は対応する Architecture/Feature テストへの登録まで
含めて「実装済み」)。新 gate は重複を恐れず独立に成立させる。

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

**受入条件は §1 の mutation 表 (7 件) の 2 段確認**。実装前に「全部緑 = 穴の実在」を記録し、
実装後に「全部赤」を確認する。mutation は**一時的にローカルで当ててすぐ戻す** (コミットしない)。
具体的な diff と実行コマンドは詳細設計に書く。

加えて以下:

| 手順 | 期待結果 |
|---|---|
| Architecture suite を 2 回連続実行 | 2 回とも全緑 (同一 worker プロセス内の状態リーク検出) |
| Architecture suite を `--order-by=random` で実行 | 全緑 (test order 依存が無いこと) |
| `composer test` 全体 | 全緑 (既存 Feature テストへの巻き添えが無いこと) |
| `composer phpstan` / `vendor/bin/pint --test` | 全緑 |

---

## 9. 制約・前提

- Architecture lane は `RefreshDatabase` なし・`StrayLlmCallGuard` なし (`tests/Pest.php`)。
  → gate は **DB に触れない**こと、LLM fake を触ったら `finally` で必ず `Prompt::stopFaking()` すること。
  実査で container 解決対象 5 本の constructor はすべて DB 非依存であることを確認済み
  (`TakeObjectStorage` / `RenderObjectStorage` は ctor 無し、`Cashier*Gateway` も ctor 無し、
  `FakeTakeObjectStorage` は `FakeObjectStore` のみ注入)。
- **状態リーク対策 (Codex Round 1 Critical)**: gate ファイル内に限定した `afterEach` を定義し、
  `Prompt::stopFaking()` を必ず通す。env/config を書き換える test case は try/finally で
  原値復元したうえで `refreshApplication()` で締める。`refreshApplication()` は
  Architecture lane では DB transaction を壊さないため安全 (RefreshDatabase 不使用)。
- storage signed route は provider が `Route::has()` で冪等化済み。gate 側でも
  「provider を 2 回 boot しても route が二重登録されない」を 1 assertion で確認する。
- テストは `--parallel` で走る (プロセス分離)。主リスクは並列ではなく**同一 worker プロセス内の
  test order 依存**であり、上記の fresh app 前提と復元でこれを断つ。検証で
  `--order-by=random` を回して確認する。
- `FakeStorageGate` の allowlist は `testing ∧ runningUnitTests` を含むため、Architecture lane で
  storage 系の実証が可能 (env の差し替え不要)。LLM だけ `bughunt.local` への一時差し替えが要る。
- `pnpm` 側の変更なし (フロントエンド非関与)。DESIGN.md / Atomic Design の観点は非該当。

---

## 再レビュー依頼

上記の修正で Round 1 の Critical 2 件・Warning 8 件が解消されているか判定してください。
特に以下を厳しく見てください:

1. `refreshApplication()` による復元検査が Laravel 12 + Pest の Architecture lane
   (RefreshDatabase 不使用) で技術的に成立するか。成立しないなら代案。
2. 網羅性走査の 2 条件 (差し替え API を `bind` に固定 + (A,B) 組の集合一致) に、
   まだ抜け道が残っていないか。
3. スコープが過大になっていないか (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
   逆に、この最小形では捕まらない現実的な事故シナリオが残っているなら指摘してください。

出力形式は Round 1 と同じ (全体判定 + [Critical]/[Warning]/[Suggestion])。
