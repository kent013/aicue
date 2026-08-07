# T130 mutation evidence — テストレーンの HTTP 出口既定拒否

詳細設計 `devnotes/20260807-1235-stray-http-egress-deny/detailed-design.md` §S4
「mutation で赤化を確認する手順」の実施記録。

**目的**: 本 TODO で足した gate (`StrayHttpEgressLaneGateTest`) と self-test
(`StrayHttpRequestGuardTest`) は「正しい状態では常に緑」であり、放置すると空振りに
気づけない。実ファイルを一時的に壊して**実際に赤くなる**ことを確認する。

**共通手順**: 対象ファイルを scratchpad へ退避 → mutation を 1 本適用 →
`composer test -- <対象テスト>` → 結果を記録 → 退避元から復元。
最後に全 5 ファイルが復元前後で `diff` 一致することを確認済み
(`tests/Pest.php` / `tests/Support/StrayHttpRequestGuard.php` /
`tests/Architecture/StrayHttpEgressLaneGateTest.php` /
`tests/Feature/Security/AuthThrottleCoverageTest.php` /
`tests/Feature/Security/ThrottleExemptionPremiseTest.php`)。
**入れた mutation は 1 つも残っていない**。

---

## 結果一覧

| # | mutation | 実行コマンド | 結果 | 赤化したテスト |
|---|---|---|---|---|
| M1 | `tests/Pest.php` の Feature/Unit lane から `StrayHttpRequestGuard::install($this->app);` を削除 | `composer test -- tests/Architecture/StrayHttpEgressLaneGateTest.php` | **RED** 18/19 | `tests/Pest.php の全レーンが StrayHttpRequestGuard を既定配線していること` (`[Feature,Unit] beforeEach の closure 本体で install() を呼んでいない` + 必須レーン Feature/Unit 未充足) |
| M2 | 同 install 行を `->afterEach(` の closure 本体へ移動 | 同上 | **RED** 18/19 | 同上 (beforeEach 本体に install が無い) |
| M3 | `ALLOWED_URL_PATTERNS` に `'https://api.frankfurter.dev/*'` を追加 | 同上 | **RED** 18/19 | `許可 URL パターンが loopback ホストだけに閉じていること` |
| M4 | `ALLOWED_URL_PATTERNS` の `'http://127.0.0.1:*'` を `'http://127.0.0.1*'` に変更 | 同上 | **RED** 18/19 | `許可 URL パターンが loopback ホストだけに閉じていること` |
| M4' | 同 mutation で self-test 側も確認 | `composer test -- tests/Feature/Support/StrayHttpRequestGuardTest.php` | **RED** 8/9 | `case H: 許可パターンは loopback ホストだけに一致する` |
| M5 | `tests/Feature/Security/AuthThrottleCoverageTest.php` に `Http::allowStrayRequests(['*']);` を追加 | `composer test -- tests/Architecture/StrayHttpEgressLaneGateTest.php` | **RED** 18/19 | `opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)` |
| M6 | guard の `__invoke` から `self::$strayRequests[] = …` を削除 | `composer test -- tests/Feature/Support/StrayHttpRequestGuardTest.php` | **RED** 3/9 (6 本赤) | `case A` / `case B` / `case E` / `case F` / `case G` / `case I` — **握り潰し貫通 (case E) が確かに accumulator に依存している**ことの証明 |
| M7a | inventory から `tests/Support/StrayHttpRequestGuard.php` を削除 (架空名へ改名) | `composer test -- tests/Architecture/StrayHttpEgressLaneGateTest.php` | **RED** 17/19 (2 本赤) | `opt-out … inventory に登録済みであること` + `exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)` |
| M7b | inventory へ架空パス `tests/Support/NotExisting.php` を追加 | 同上 | **RED** 17/19 (2 本赤) | `形骸化ガード` + `exemption 件数が上限 (exact fit) を超えていないこと` |
| M8 | install 行を beforeEach closure の**外** (同一チャンク内の別 hook = `->beforeAll(...)`) へ移動 | 同上 | **RED** 18/19 | `全レーンが既定配線` (hook 本体の内包検査) |
| M9 | `ThrottleExemptionPremiseTest.php` の `Http::preventStrayRequests();` を `Http::preventStrayRequests($flag);` に変更 | 同上 | **RED** 18/19 | `opt-out … inventory に登録済みであること` (**非 literal** の opt-out 検出) |
| M10 | Feature/Unit lane の `->beforeEach(function (): void { … })` を `->beforeEach(strayHttpEgressMutationWrap(function (): void { … }))` (恒等ラッパ) に変更 | 同上 | **RED** 18/19 | `全レーンが既定配線` (**hook 引数が closure リテラルでない → fail-closed**) |

### Codex impl-review Round 1 の Critical 対応で追加した mutation (M11〜M13)

Round 1 で「`ALLOWED_URL_PATTERNS` の `:*` は `Str::is()` の glob なので **userinfo 詐称**
(`http://127.0.0.1:80@api.frankfurter.dev/` = userinfo が loopback・**実ホストは外部**) を
許可してしまう」という Critical を受け、パース済みホストを見る**第 2 層**
(`StrayHttpRequestGuard::isSmuggledLoopbackUrl()`) を middleware に追加した。
その第 2 層が load-bearing であることを mutation で確認した。

| # | mutation | 実行コマンド | 結果 | 赤化したテスト |
|---|---|---|---|---|
| M11 | `__invoke` から第 2 層 (`isSmuggledLoopbackUrl` による事前拒否) を削除 | `composer test -- tests/Feature/Support/StrayHttpRequestGuardTest.php` | **RED** 9/10 | `case J: userinfo で loopback を騙る URL は stray として記録され送信されない` |
| M12 | `isSmuggledLoopbackUrl()` を常に `false` を返すようにする | `composer test -- tests/Architecture/StrayHttpEgressLaneGateTest.php` | **RED** 20/21 | `許可判定が userinfo 詐称で loopback を騙る URL を拒否すること (第 2 層)` |
| M13 | `LOOPBACK_HOSTS` に `'aicue.test'` を追加 | 同上 | **RED** 20/21 | `LOOPBACK_HOSTS が ALLOWED_URL_PATTERNS のホスト部と 1:1 対応していること` |

#### M11 が暴いた「空振りテスト」— 最初の case J は通ってしまった

**最初に書いた case J は M11 を当てても緑のままだった**。原因を実測で追ったところ、
第 2 層を外した状態では以下が起きていた:

1. `http://127.0.0.1:80@api.frankfurter.dev/` は許可パターン `http://127.0.0.1:*` に glob 一致し、
   framework は stray と判定しない → **1 本目の要求が実際に外部へ送信される**。
2. `api.frankfurter.dev` が `https://api.frankfurter.dev/` へ 301 リダイレクトする。
3. Guzzle の redirect が再送した 2 本目の URL は許可パターンに一致しないので、
   そこで初めて `StrayRequestException` が出る。

つまり「例外が出たこと」も「例外メッセージにホスト名が含まれること」も**第 2 層の有無で変わらない**。
第 1 版では assertion を `toContain('api.frankfurter.dev')` から**元 URL との完全一致**へ変更して
区別できるようにした (baseline 緑 / M11 赤を確認)。

**ただしこの形にはまだ欠陥があった** (Codex impl-review Round 2 の Critical):
第 2 層が壊れているとき、**回帰テスト自身が実際に外部へ 1 本送信してしまう**。
既定拒否を守るためのテストが既定拒否を破る構造である。

そこで case J の先頭に **全許可 fake** (`Http::fake(['*' => Http::response('', 200)])`) を置いた。
これは S6 の「`'*'` fake でごまかさない」規律の**明示された例外**である
(このテストの主題が「どの URL であれ 1 本も出てはならない」ことの検証そのものだから)。

| 状態 | 起きること | 判定 |
|---|---|---|
| 第 2 層あり | 最外側 middleware が stub より**先に** throw → accumulator に元 URL が 1 件 | 緑 |
| 第 2 層なし | stub が `'*'` に一致して 200 を返す → 例外なし・記録なし・**送信もなし** | 赤 |

再実測: baseline **10/10 緑** / M11 適用時 **9/10 (case J が `userinfo 詐称が既定拒否を潜り抜けている`
で赤)**。redirect 挙動への依存も消え、外部送信も起きない形になった。

> **この 2 段階の修正が本 evidence を書く目的そのもの**である。mutation を回さなければ
> 「Critical を直したうえで、その修正を守るテストが空振りしている」状態で緑になっていた。
> さらに 1 段目の修正だけでも「テスト自身が外部へ出る」欠陥が残っていた。

---

## M8 / M10 で設計から変えた点 (正直に記録する)

詳細設計の M8 は `->use(StrayHttpRequestGuard::install(app()))`、M10 は
`->beforeEach(wrap(function () { … }))` という形を指定していた。**この 2 つはそのまま
実行すると PHP の実行時 fatal になる** (前者は
`install(): Argument #1 must be of type Illuminate\Contracts\Foundation\Application,
Illuminate\Container\Container given`、後者は `Call to undefined function wrap()`)。

fatal で落ちると「テストが 1 本も収集されずスイート全体が落ちた」だけであり、
**gate が検出したことの証明にならない** (実際に一度そうなったので作り直した)。
そこで**実行時に有効な形**へ置き換えた:

- **M8**: `->beforeAll(function (): void { if (false) { StrayHttpRequestGuard::install(app()); } })`
  — 構文上は同一チャンク内・hook closure の外にある install 呼び出しだが実行はされない。
- **M10**: `strayHttpEgressMutationWrap(Closure $c): Closure { return $c; }` という恒等ラッパを
  一時的に定義して `->beforeEach(strayHttpEgressMutationWrap(function (): void { … }))` にする。
  **runtime としては正しく配線されている**のに gate は fail-closed で赤くする =
  「引数が closure リテラルであること」を要求する契約が効いていることのより強い証明になった。

mutation の意図 (hook 本体の内包検査 / closure リテラル要求) は変えていない。

---

## 追加で行った「レーン既定が実際に効くこと」の実証 (設計の mutation 表には無い)

gate はソース走査であり、S2 self-test は**自前で install** する。したがって
「`tests/Pest.php` のレーン既定として実際に guard が効いているか」はどちらも直接は
証明しない。そこで一時的な probe テストを 2 本置いて実挙動を確認した (確認後に削除済み)。

```php
// tests/Feature/Support/TmpLaneDefaultProbeTest.php (一時) / tests/Architecture/TmpLaneDefaultProbeTest.php (一時)
test('probe: …', function (): void {
    try { Http::get('https://api.frankfurter.dev/v1/latest'); } catch (Throwable) { /* 握り潰す */ }
    expect(true)->toBeTrue();
});
```

| レーン | 結果 |
|---|---|
| Feature | **RED** — `Stray outbound HTTP request detected during test execution. … [1] GET https://api.frankfurter.dev/v1/latest` (afterEach の `flushAndFailIfStray()` 経由) |
| Architecture | **RED** — 同上 |

**test 本体では例外を握り潰しているのに赤くなる** = 本 guard の存在意義 (accumulator 経由の
afterEach 判定) がレーン既定として実働している。probe 2 本はいずれも削除済み
(`git status` に残っていないことを確認)。

---

## S6 (既存テストの是正) の実測

詳細設計 S6 は「レーン既定 ON で赤化する既存テスト」を候補集合として挙げていたが、
**実際に `composer test` を全件実行したところ 1 本も赤化しなかった** (3463 tests /
3461 passed / 2 skipped / 0 failed)。したがって `Http::fake` の追加は**ゼロ件**である。

理由の考察 (推測を事実として書かない): RegistrationTest 系が挙げられていたのは
`api.frankfurter.dev` (FxRateService) への到達を想定してのものだが、testing env では
到達経路が発火していない。**S6 の是正が不要だったこと自体が「既存テストは既に
外部へ出ていなかった」という測定結果**であり、赤化ゼロを `'*'` fake でごまかした結果ではない
(`Http::fake` は 1 行も足していない)。
