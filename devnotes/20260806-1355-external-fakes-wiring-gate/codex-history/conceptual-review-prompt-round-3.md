# Round 3: 対応マトリクスと修正後の概念設計

Round 2 の Critical 1 件・Warning 6 件・Suggestion 1 件をすべて反映しました。

# 対応マトリクス: conceptual-review Round 2

## [Critical] 網羅性走査が deny-by-default になっていない (既知 API の列挙にすぎない)
- 判断: **対応する** (指摘は正しい。`rebinding` / `app()` / `Container::getInstance()` /
  未来の Container API はすべて抜けられる)
- 対応内容: §4 施策 1 の「網羅性走査」を 2 条件 → **3 条件**に作り替え、
  「API 名を列挙して禁止」から「**許可を列挙して残りを禁止**」へ反転した。
  1. 登録形の固定: `$this->app-><allowed>(` の `<allowed>` を `bind` / `make` / `environment` の
     3 つに限定 (現行コードが実際に使う form のみ)。それ以外の method 名で fail。
  2. 間接経路の禁止: `app(` / `resolve(` / `App::` / `Container::getInstance()` /
     `$this->app->getContainer()` を同ファイル内で禁止。
  3. fake 参照の集合一致: provider が参照する fake クラス集合 = inventory の fake 集合 +
     明示例外 2 件 (`CannedPromptFakeRegistrar` / `FakeStorageGate`)。
     → 未知の API で配線しても、**fake クラスを参照した時点で**条件 3 が捕まえる。

## [Warning] `refreshApplication()` を「復元の不変条件」として持つのは過剰
- 判断: **対応する**
- 根拠: 指摘どおり、それは provider ではなく Laravel `TestCase` を検査するテストになる。
- 対応内容: 検査表 (§4) から「復元」行を削除。`refreshApplication()` は
  「1 test case 内で env/config を切り替える必要が出た場合の手段」へ格下げ。
  明示的な往復検査は **static (`Prompt::$fake`) だけ**に絞った。

## [Warning] `afterEach` を経た状態はその test case では assert できない
- 判断: **対応する** (事実誤り)
- 対応内容: LLM 検査を「test 本体の `try/finally` 内で `stopFaking()` →
  `expect(Prompt::isFaking())->toBeFalse()` を assert」に修正。`afterEach` は
  **フェイルセーフ**として残すが検査表現にはしない、と §4 / §9 の両方に明記。

## [Warning] Architecture suite の 2 回連続実行は同一プロセス内リーク検出にならない
- 判断: **対応する**
- 対応内容: §8 を「2 回連続実行 = **再実行安定性**の確認 (別プロセスなのでリーク検出ではない)」
  「同一プロセス内の order 依存は `--order-by=random` で見る。**seed をログに残して再現可能にする**」
  に書き換え。

## [Warning] fake クラス母集団のディレクトリ規約が未固定
- 判断: **対応する**
- 対応内容: 施策 2 に「クラス名が `Fake` で始まる / 終わる PHP クラスは `app/**/Fakes/` か
  `app/**/Testing/` 配下にしか置けない」という**配置規約の固定**を追加。
  `app/Services/Billing/FakeFoo.php` 直置きで母集団から逃げる経路を塞ぐ。

## [Warning] §5 に禁止事項 3 の誤参照が残っている
- 判断: **対応する**
- 対応内容: §5 の代替案表から番号参照を削除し、§6.4 と同じ理由付けに統一。

## [Warning] inventory の `risk` / `mutation` が自由記述では形骸化する
- 判断: **対応する**
- 対応内容: `mutation` を **安定 mutation ID の list (`mutationIds`: M1…M7)** に変更。
  §1 の mutation 表を正本とし、gate 側の `MUTATION_COVERAGE` map と inventory の ID 集合が
  **完全一致すること**を 1 test で機械照合する。`risk` はレビュー用説明として維持。

## [Suggestion] route 二重 boot 検査は過大
- 判断: **対応する** (削る)
- 対応内容: §9 から冪等性検査を削除し、「signed route の振る舞いは既存 Feature テストの責務」
  「provider は `Route::has()` で冪等化済み、崩れても後勝ちで無害」と理由を明記。

## [Suggestion] 使命との整合 / 柱 2・柱 3b を外す判断は妥当
- 判断: **見送る** (現状維持)

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

| ID | mutation (一時的にローカルで当ててすぐ戻す) | 実装前 | 実装後 |
|---|---|---|---|
| M1 | `FakeExternalsServiceProvider` の `AutoRechargeGatewayInterface` の bind を削除 | 緑 (= 穴の実在) | **赤** |
| M2 | 同 `TakeObjectStorage` の bind を削除 | 緑 | **赤** |
| M3 | `bootstrap/providers.php` から `FakeExternalsServiceProvider` を削除 | 緑 | **赤** |
| M4 | 同ファイルで provider を `AppServiceProvider` より前に移動 (後勝ち崩し) | 緑 | **赤** |
| M5 | provider に架空の `bind(Foo::class, FakeFoo::class)` を追加 | 緑 | **赤** (inventory 未登録) |
| M6 | provider の `bind` を `singleton` に変更 | 緑 | **赤** (登録形 allowlist 違反) |
| M7 | 任意の Service に `use App\Services\Billing\Fakes\FakeStripeGateway;` を追加 | 緑 | **赤** |

この ID は inventory の `mutationIds` / gate の `MUTATION_COVERAGE` と機械照合される (§4)。

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
`abstract / real / fake / flag / allowed envs / phase / risk / mutationIds` で持つ。

- `risk`: なぜ外部副作用として危険か (レビュー用の自由記述)。
- `mutationIds`: **安定 mutation ID の list** (`M1`…`M7`。§1 の mutation 表が正本)。
  gate ファイル側は「どの mutation ID をどの test case が捕まえるか」の
  `MUTATION_COVERAGE` map を持ち、**inventory 側の ID 集合と gate 側の被覆 ID 集合が
  完全一致すること**を 1 test で機械照合する (Codex Round 2 Warning: 自由記述では
  「空でない文字列」で満たせてしまい形骸化するため、機械照合できる ID にする)。
  → entry を足して mutation ID を書かない / 書いた ID を捕まえる test が無い、のどちらも赤になる。

テストは inventory を回して以下を見る:

| 検査 | 内容 | app の扱い |
|---|---|---|
| 対照 (flag off) | 既定 container で解決 → **real と厳密クラス一致** | 素の test app (何も書き換えない) |
| 実証 (flag on + allowlist 内) | flag を立てて provider を実走 → **fake と厳密クラス一致** | 独立 test case (Pest がテスト毎に app を作り直す) |
| allowlist 外 | flag on + env=`production` → real のまま (課金は warning ログも) | **provider 単体の条件分岐検査**。app 全体の production boot ではない (§ 限界を明記) |
| 登録点 | `bootstrap/providers.php` の配列に provider が居て `AppServiceProvider` より**後**。かつ起動済み container の `getLoadedProviders()` に載っている | 素の test app |
| 網羅性 | provider ソースの token 走査 (下記 2 条件) | 走査のみ |

> **重要 (Codex Round 1 Critical / Round 2)**: 「flag を戻して provider を再実走すれば real に戻る」は
> **成立しない**。provider は flag off なら early return するだけで、既に上書きされた binding を
> 巻き戻さないため。
> 一方、**「fake 実証の後に `refreshApplication()` して real に戻る」を不変条件として持つのも過剰**
> (それは provider ではなく Laravel の `TestCase` を検査するテストになる。事故検出力は上がらない)。
> したがって:
> - container の復元は **Pest が test case ごとに app を作り直す**フレームワーク保証に任せ、
>   **対照 (real) と実証 (fake) を独立 test case に分ける** (順序依存を作らない)。
> - `refreshApplication()` は「1 つの test case 内で env/config を切り替える必要が出た場合の
>   手段」に格下げする (不変条件としては持たない)。
> - **明示的な往復検査が要るのは static (`Prompt::$fake`) だけ**。これは test 本体の
>   `try/finally` の中で `stopFaking()` → `expect(Prompt::isFaking())->toBeFalse()` まで
>   **同一 test case 内で assert** する (`afterEach` はフェイルセーフとして併置するが、
>   `afterEach` 完了後の状態はその test case では assert できないため検査表現にしない)。

> **allowlist 外検査の限界を明示する**: `$app['env']` を `production` に差し替えても、
> 既に走り終わった `AppServiceProvider::boot()` の `ProductionEnvGuard::enforce()` は再実行されない。
> この検査は **provider 単体の条件分岐**を見るものであり、production 相当の app 全体 boot の
> 再現ではない。テスト名にもその限界を出す (`provider 単体: …`)。
> **本番混入防止そのものの正本は `ProductionEnvGuard` + `ProductionEnvGuardTest`** であり、
> 本 gate は非本番側の配線だけを見る (責務境界)。

**網羅性走査の 3 条件** (deny-by-default。Codex Round 2 Critical への対応で
「既知 API の列挙」から「**許可された唯一の登録形 + fake 参照の集合一致**」へ変更):

1. **登録形の固定 (allowlist)**: `FakeExternalsServiceProvider` 内で container に触れてよい形は
   `$this->app-><allowed>(...)` のみとし、`<allowed>` を **`bind` / `make` / `environment`**
   の 3 つに限定する (`bind` = 差し替え、`make` = gate/registrar 解決、`environment` = allowlist 判定。
   いずれも現行コードが実際に使っている form)。これ以外の method 名が `$this->app->` に
   現れたら fail。**API 名を列挙して禁止するのではなく、許可を列挙して残りを禁止する**。
2. **間接経路の禁止**: 同ファイル内で `app(` / `resolve(` / `App::` facade /
   `Container::getInstance()` / `$this->app->getContainer()` 等、`$this->app` 以外から
   container へ到達する形が現れたら fail (未知 API を経由した抜け道を構文で塞ぐ)。
3. **fake 参照の集合一致**: 同ファイルが参照する fake クラス (施策 2 と同じ**動的導出**の
   母集団に属するクラス) の集合が、inventory の `fake` 集合 + 明示例外集合と**完全一致**すること。
   明示例外は現状 2 件のみ (`CannedPromptFakeRegistrar` = LLM static fake の install 窓口、
   `FakeStorageGate` = storage の gate predicate)。
   → 未知の container API を使って fake を配線しても、**fake クラスを参照した時点で**
   条件 3 が inventory 未登録として捕まえる。

LLM (Prism) は container ではなく static のため inventory とは別枠で以下 2 点のみ:

- `bughunt.local` ∧ `fake_llm=true` でのみ `Prompt::isFaking()` が true になる
  (`testing` / `local` / flag off では false)。
- 同一 test case の `finally` 内で `stopFaking()` → `Prompt::isFaking()` が false に戻ることを
  assert する (static リークの明示検査)。

> 網羅性検査だけはソース走査 (字面) になる。brief は「差し替え処理の**内部構造**をソースの
> 文字列一致で固定する形は推奨どまり」としているが、ここで固定するのは内部構造ではなく
> **inventory の網羅性 (登録漏れの検出)** であり、aicue の既存 Architecture テスト
> (`ScenarioWritePathInventoryTest` / `PromptGuardrailTest`) と同じ token 走査の流儀に乗る。
> 振る舞いの検証は上 5 行の実証側が持つ。

### 施策 2: 本番コードの fake クラス参照 全走査 gate (柱 3c) — 必須

新規 `tests/Architecture/FakeClassReferenceInvariantTest.php`。

- fake クラス名は **ディレクトリから動的導出** (`app/**/Fakes/*.php` と `app/**/Testing/*.php`)。
  ハードコード一覧を持たない (fake が増えたら自動的に母集団に入る)。
- **母集団が漏れないように配置規約も同時に固定する** (Codex Round 2 Warning):
  クラス名が `Fake` で始まる / `Fake` で終わる PHP クラスは `app/**/Fakes/` または
  `app/**/Testing/` 配下にしか置けない (それ以外の場所にあれば fail)。
  これで「`app/Services/Billing/FakeFoo.php` を直置きして母集団から逃げる」経路が塞がる。
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
| Feature テスト側 (`FakeExternalsServiceProviderTest`) を拡張して済ませる | 不変条件は Architecture テストへの登録まで含めて「実装済み」(AGENTS.md 禁止事項 1)。また既存 Feature テストは provider を手で実行する形なので、登録点検査を足すと責務が混ざる。**既存 Feature テストは振る舞い回帰として残す** (§6.4) |

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
| Architecture suite を 2 回連続実行 | 2 回とも全緑 (**再実行安定性**の確認。別プロセスなので同一プロセス内リークの検出にはならない) |
| Architecture suite を `--order-by=random` で実行 (**seed をログに残す**) | 全緑 (同一プロセス内の test order 依存が無いこと。失敗したら記録した seed で再現する) |
| `composer test` 全体 | 全緑 (既存 Feature テストへの巻き添えが無いこと) |
| `composer phpstan` / `vendor/bin/pint --test` | 全緑 |

---

## 9. 制約・前提

- Architecture lane は `RefreshDatabase` なし・`StrayLlmCallGuard` なし (`tests/Pest.php`)。
  → gate は **DB に触れない**こと、LLM fake を触ったら `finally` で必ず `Prompt::stopFaking()` すること。
  実査で container 解決対象 5 本の constructor はすべて DB 非依存であることを確認済み
  (`TakeObjectStorage` / `RenderObjectStorage` は ctor 無し、`Cashier*Gateway` も ctor 無し、
  `FakeTakeObjectStorage` は `FakeObjectStore` のみ注入)。
- **状態リーク対策 (Codex Round 1 Critical / Round 2)**: gate ファイル内に限定した `afterEach` を
  定義し、`Prompt::stopFaking()` を必ず通す (**フェイルセーフ**。検査表現ではない)。
  検査としての復元 assert は test 本体の `try/finally` の中で行う。
  env/config を書き換える test case は try/finally で原値復元する。
  `refreshApplication()` は「1 test case 内で env/config を切り替える必要が出た場合」だけ使う
  (Architecture lane は RefreshDatabase 不使用なので mid-test の refresh は安全)。
- storage signed route の**冪等性検査は入れない** (Codex Round 2 Suggestion)。provider が
  `Route::has()` で冪等化済みで、崩れても route は後勝ちで無害。signed route の振る舞いは
  `tests/Feature/Storage/FakeStorageRouteTest.php` の責務。本 gate の目的
  (登録漏れ / provider 脱落 / 順序反転 / inventory 漏れ) に直接寄与しない。
- テストは `--parallel` で走る (プロセス分離)。主リスクは並列ではなく**同一 worker プロセス内の
  test order 依存**であり、上記の fresh app 前提と復元でこれを断つ。検証で
  `--order-by=random` を回して確認する。
- `FakeStorageGate` の allowlist は `testing ∧ runningUnitTests` を含むため、Architecture lane で
  storage 系の実証が可能 (env の差し替え不要)。LLM だけ `bughunt.local` への一時差し替えが要る。
- `pnpm` 側の変更なし (フロントエンド非関与)。DESIGN.md / Atomic Design の観点は非該当。

---

## 再レビュー依頼

Round 2 の指摘が解消されたか判定してください。特に:

1. 網羅性走査の 3 条件 (登録形 allowlist / 間接経路禁止 / fake 参照の集合一致) で
   deny-by-default が成立しているか。まだ残る抜け道があれば具体的に。
2. これ以上のスコープ拡大は AGENTS.md 思考原則 2 (今必要なものだけ作る) に反すると考えています。
   概念設計としてこれで着手可能か。

全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
