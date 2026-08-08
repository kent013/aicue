# impl-review Round 2

Round 1 の指摘 3 件 (Warning 1 / Suggestion 2) をすべて受け入れて修正した。
以下に対応マトリクスと修正後のコードを示す。**再レビューして全体判定を出してほしい。**

---

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Warning] `EXTERNAL_SEAM_RULE_KINDS` の値集合が pin されていない (実質 Critical / 全体判定の根拠)

- 判断: **対応する**
- 根拠: 指摘は正しい。当初のテスト 4 は「キー集合の exact-fit + 各値が非空」までしか見ておらず、
  `http_facade_reference` へ `ExternalSeamKind::Mail` を**足す**改変では赤くならなかった。
  そのうえで `FxRateService` に `kind: Mail` の entry を足せばテスト 1(b) の残骸検出もすり抜ける。
  これは gate の主目的である「種別を登録者の言い値にしない」に直接開いた穴であり、
  「規則を減らす方向 (M7) は 別テストが受け止めるが、増やす方向は誰も受け止めていなかった」という
  非対称だった。
- 対応内容:
  - テスト名を `外部到達: 規則→種別表は規則 enum を exact-fit で覆い、値集合も pin される` へ変更し、
    Codex 提案どおり **enum value 化した期待表**を別宣言として置き、`EXTERNAL_SEAM_RULE_KINDS` と
    完全一致させる assert を追加した (規則の意味を広げるには 2 箇所を触らせる意図的な摩擦)。
  - mutation **M19**「`http_facade_reference` へ `Mail` を追加」を新設し、
    `EXTERNAL_SEAM_MUTATION_COVERAGE` / `EXTERNAL_SEAM_MUTATION_IDS` へ登録 (テスト 15 が同期を強制)。
  - M19 を実行し **テスト 4 のみが赤**になることを実測。副作用として M7 もテスト 4 で赤くなるため、
    mutation-evidence.md の M7 行と注 3 を実測どおり書き換えた
    (設計の予測「テスト 4 は赤にならない」は修正前の話であると明記した)。

## [Suggestion] group use 修正の回帰を抽出元 API (`ExternalClientBoundaryScanner`) 側にも置くべき

- 判断: **対応する**
- 根拠: 指摘のとおり、現状の group use 回帰は `ExternalSeamScannerTest` にしかなく、
  T126 が使う API (`ExternalClientBoundaryScanner::scan()`) 側の回帰としては間接的だった。
  抽出前の実装は docblock で「グループ use にも対応する」と書きながら
  `use Aws\{S3\S3Client, ...}` を `AwsS3\S3Client` と解決していた (= 検出漏れ) ため、
  この API 上で固定しておく価値がある。
- 対応内容: `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php` の**末尾へ 1 本追加**
  (`グループ use を接頭辞ごと解決する`)。
  **既存 268 行は 1 行も変更していない** (`git diff` は 19 insertions / 0 deletions の純粋な追記。
  差分中の `-` 1 件は diff ヘッダ `--- a/...` である)。
  「既存テストを編集して通す」という禁じ手には当たらない (追加した test は新しい振る舞いの回帰であり、
  既存 assert を 1 つも緩めていない)。

## [Suggestion] Stripe 例外だけを import するファイルの無関係な `->stripe()` が adopted になる

- 判断: **対応する (テストで方針を明示)**
- 根拠: 指摘の事実認識は正しい。ただし挙動を変えるべきではない —
  抑制は**偽陰性の口**であり、迷ったら「採用して目録登録を要求する」側へ倒すのが正しい
  (抑制側へ倒すと gate が静かに効かなくなる)。現状 `suppressed` は 0 件であり、
  抑制が働いた瞬間にテスト 6 が赤くなる設計と整合している。
- 対応内容: `ExternalSeamScannerTest` へ
  `走査器: Stripe 例外だけを import するファイルの ->stripe() は fail-closed で採用する` を追加し、
  「これは意図した fail-closed であり、偽陽性が出たら**規則側で分離する** (entry 登録で黙らせない)」
  をコメントで明記した。挙動は変更していない。

## 反論・見送りはなし

Round 1 の指摘 3 件はすべて受け入れた。


---

## 修正 1: テスト 4 に値集合の pin を追加 (`tests/Architecture/ExternalSeamInventoryTest.php`)

```php
test('外部到達: 規則→種別表は規則 enum を exact-fit で覆い、値集合も pin される', function (): void {
    // ★キー集合の exact-fit だけでは「規則が名乗れる種別を**増やす**」改変を止められない
    //   (例: http_facade_reference へ Mail を足すと FxRateService に kind: Mail の entry を
    //    登録できてしまい、テスト 1 の残骸検出もすり抜ける)。値集合も別宣言で pin し、
    //   規則の意味を広げるには**2 箇所**を触らせる (意図的な摩擦)。
    $expected = [
        'payment_client_call' => ['payment'],
        'payment_client_construction' => ['payment'],
        'socialite_facade_reference' => ['social_login'],
        'http_facade_reference' => ['captcha', 'market_data'],
        'mail_facade_reference' => ['mail'],
    ];

    $ruleValues = array_map(static fn (ExternalSeamRule $rule): string => $rule->value, ExternalSeamRule::cases());
    $tableKeys = array_keys(EXTERNAL_SEAM_RULE_KINDS);
    sort($ruleValues);
    sort($tableKeys);

    expect($tableKeys)->toBe($ruleValues, '規則を追加したら EXTERNAL_SEAM_RULE_KINDS へも登録してください。');

    $actual = [];
    foreach (EXTERNAL_SEAM_RULE_KINDS as $rule => $kinds) {
        expect($kinds)->not->toBe([], "規則 {$rule} が名乗れる種別が空です。");
        $actual[$rule] = array_map(static fn (ExternalSeamKind $kind): string => $kind->value, $kinds);
    }
    ksort($actual);
    ksort($expected);

    expect($actual)->toBe($expected,
        '規則が名乗れる種別を変えるときは、この pin 表も同時に更新してください'
        .' (種別を登録者の言い値にしないための二重宣言です)。');
});
```

あわせて mutation 被覆表へ M19 を登録した (テスト 15 がキー集合の同期を強制する):

```php
    'M19' => '規則が名乗れる種別を増やす (http_facade_reference へ Mail を足す) と値集合の pin が赤くなる',
```

```php
const EXTERNAL_SEAM_MUTATION_IDS = [
    'M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10',
    'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17', 'M18', 'M19',
];
```

## 修正 2: 抽出元 API 側の group use 回帰 (`tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php`)

**既存 268 行は 1 行も変更していない**。末尾への純粋な追記 (19 insertions / 0 deletions):

```diff
diff --git a/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
index f3c5245..d1c8f94 100644
--- a/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
+++ b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
@@ -266,3 +266,22 @@ public function f(): void {
     expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source), 'name'))
         ->toBe(['setHttpClient', 'setMaxNetworkRetries', 'instance']);
 });
+
+test('グループ use を接頭辞ごと解決する', function (): void {
+    // ★T138 の走査基盤抽出 (PhpReferenceScanner) で group use の接頭辞連結を直した際の回帰。
+    //   抽出前は `use Aws\{S3\S3Client, ...}` の区切り `\` を落として
+    //   `AwsS3\S3Client` と解決していた (= 検出漏れ)。app/ に group use が無いため
+    //   T126 の母集団は変わらないが、docblock が謳う仕様との差を残さない。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\{S3\S3Client, Sns\SnsClient};
+    class Sample { public function f(SnsClient $s): S3Client { return new S3Client([]); } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
+        ['rule' => 'imported_name_reference', 'name' => 'Aws\Sns\SnsClient', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+        ['rule' => 'new_external_object', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+    ]);
+});

```

## 修正 3: `->stripe()` の fail-closed 方針をテストで明示 (`tests/Unit/Architecture/ExternalSeamScannerTest.php`)

挙動は変えず、方針をテストとコメントで固定した:

```php
test('走査器: Stripe 例外だけを import するファイルの ->stripe() は fail-closed で採用する', function (): void {
    // 抑制解除は「決済名前空間を知っているか」で判定するため、Stripe 例外の import だけでも
    // 抑制は外れて adopted になる。**これは意図した fail-closed** である
    // (抑制 = 偽陰性の口なので、迷ったら採用して目録登録を要求する側へ倒す)。
    // 偽陽性が実際に出たら規則側で分離する (entry 登録で黙らせない)。
    $source = <<<'PHP'
    <?php
    namespace App\Support\Billing;
    use Stripe\Exception\CardException;
    final class Probe
    {
        public function go(object $client): mixed
        {
            return $client->stripe();
        }

        public function classify(CardException $error): string
        {
            return 'card';
        }
    }
    PHP;

    $result = ExternalSeamScanner::scan('probe.php', $source);

    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
        ->and($result->suppressed)->toBe([]);
});
```

---

## 修正後の実測

```
php artisan test --filter="ExternalClientBoundaryScanner|ExternalSeamScanner|ExternalClientTimeout|ExternalSeamInventory"
  → 72 tests, 72 passed, 147 assertions
```

mutation の再実測 (Round 1 の修正後):

| ID | 壊し方 | 実測で赤くなった test |
|----|--------|----------------------|
| **M19** (新設) | `EXTERNAL_SEAM_RULE_KINDS['http_facade_reference']` に `Mail` を**追加** | テスト 4 (値集合 pin) **のみ** |
| M7 (再実測) | 同表から `MarketData` を**削除** | テスト 1 双方向照合 + テスト 4 値集合 pin (修正前はテスト 1 のみだった) |
| M15 (再実測) | `FxRateService` に `kind: Mail` の entry を追加 | テスト 1 双方向照合 **のみ** (変化なし) |

mutation-evidence.md は実測どおり更新済み (設計の予測とズレた点は注 1〜3 に明記し、辻褄合わせをしていない)。

---

## 更新後の mutation evidence (全文)

# T138 mutation evidence (実測)

各 mutation は **1 操作**で手編集し、`php artisan test --filter=…` を実行して赤を確認し、
直後に元へ戻した。最後に `git diff` / `git status --short` で残留が無いことを確認済み。

実行時のベースライン: `ExternalSeamInventoryTest` 15 本 / `ExternalSeamScannerTest` 20 本、
いずれも無 mutation で **全緑**。

## M1〜M18 (すべて赤化を実測)

| ID | 壊し方 | 設計の予測 | **実測で赤くなった test** | 一致 |
|----|--------|-----------|--------------------------|------|
| M1 | `entries()` から `FxRateService` を削除 | テスト 1(a) + テスト 10 | 1 双方向照合 / 10 排他的被覆 | ✅ 予測どおり |
| M2 | `entries()` に走査へ出ない `App\Models\User` を追加 | テスト 1(b) | 1 双方向照合 | ✅ |
| M3 | `ExternalSeamScanner::FACADE_RULES` を `[]` | テスト 1(b) + テスト 7。テスト 2 / 10 は赤にならない | 1 双方向照合 / 7 SocialLogin 固定 / **13 委譲済み種別**。テスト 2 と 10 は**緑のまま** | ⚠️ **予測外の追加赤 1 本** (下記 注1) |
| M4 | M3 に加えて `classify()` を `return null` (全規則の無効化) | テスト 2 (空振り防止) | 2 空振り防止 / 1 / 7 / 13 | ✅ (予測の本命 = テスト 2 が赤) |
| M5 | `OrganizationMembershipService` に `\Laravel\Socialite\Facades\Socialite::driver('google')` を追加 | テスト 7 + テスト 1(a) | 1 双方向照合 / 7 SocialLogin 固定 | ✅ |
| M6 | 同クラスに `$client->stripe()` を追加 (Cashier / Stripe を import も参照もしない) | テスト 6 | **6 抑制 0 件のみ** | ✅ |
| M7 | `EXTERNAL_SEAM_RULE_KINDS['http_facade_reference']` から `MarketData` を削除 | テスト 1(a)+(b)。テスト 4 は赤にならない | **impl-review Round 1 の修正前**: 1 双方向照合のみ (テスト 4 は緑のまま = 設計の予測どおり)。**修正後 (現状)**: 1 双方向照合 + **4 値集合 pin** | ⚠️ 予測から意図的に変更 (下記 注3) |
| M8 | `requiredDimensions()` から `Llm` を削除 | テスト 9 | 9 exact-fit / 10 排他的被覆 | ✅ (10 も連動。想定内) |
| M9 | 委譲の `gateTestName` の末尾を 1 文字変更 | テスト 12 | **12 のみ** | ✅ |
| M10 | `config/template.php` の `social_providers` を `[]` | テスト 11 + 既存 `SocialProviderTrustPolicyTest` | 11 生存確認 / 既存 gate 3 本 | ✅ |
| M11 | `FACADE_RULES` に `Aws\S3\S3Client` を追加 | テスト 13 | 13 委譲済み種別 / 1 双方向照合 | ✅ (注2) |
| M12 | 任意 entry の `classification` を `Exempt` | テスト 8 | **8 のみ** | ✅ |
| M13 | `entries()` に既存と同じ `(class, kind)` を追加 | テスト 1(c) | 1 双方向照合 | ✅ |
| M14 | 委譲先 test を改名し、旧名をコメントとして残す | テスト 12 | **12 のみ** | ✅ (単純な `str_contains` なら緑になる箇所) |
| M15 | `FxRateService` に `kind: Mail` の entry を追加 | テスト 1(b) | 1 双方向照合 | ✅ |
| M16 | `delegations()` に `Payment × CodeReachPoint` を追加 | テスト 10(c) 二重被覆 | **10 のみ** | ✅ |
| M17 | `ObjectStorage × CodeReachPoint` の委譲を 2 件へ重複 | テスト 10(b) | **10 のみ** | ✅ |
| M18 | `delegations()` に `Payment × DestinationSet` を追加 | テスト 10(d) 余剰委譲 | **10 のみ** | ✅ |
| **M19** (impl-review Round 1 で追加) | `EXTERNAL_SEAM_RULE_KINDS['http_facade_reference']` に `Mail` を**追加** (規則が名乗れる種別を増やす) | テスト 4 (値集合 pin) | **4 のみ** | ✅ (下記 注3) |

> **注1 (設計の予測とのズレ。辻褄を合わせない)**: M3 で `FACADE_RULES` を空にすると、
> 設計が挙げた テスト 1 / 7 に加えて **テスト 13 (`委譲した種別は本目録の母集団に現れない`) も赤くなる**。
> テスト 13 は `ruleSymbols()` のキー集合が `ExternalSeamRule::cases()` と exact-fit であることも
> 検査しており、`FACADE_RULES` を空にすると facade 系 3 規則のキーが消えるためである。
> 設計はこの連動を予測していなかった。gate の意図 (規則を足したら表へ載せる) には合致しており、
> 弱める理由が無いのでそのままにした。
>
> **注3 (impl-review Round 1 の指摘反映)**: 当初のテスト 4 は「キー集合の exact-fit + 各値が非空」
> までしか見ておらず、**規則が名乗れる種別を増やす**改変 (`http_facade_reference` へ `Mail` を足す)
> を止められなかった。増やしたうえで `FxRateService` に `kind: Mail` の entry を足すと
> M15 相当の残骸検出もすり抜ける = gate の主目的「種別を登録者の言い値にしない」に穴があった。
> テスト 4 に**値集合の pin 表**を足して 2 箇所同時変更を要求する形へ修正し、**M19** で赤化を実測した。
> 副作用として M7 (種別を減らす方向) もテスト 4 で赤くなるようになった (設計の予測「テスト 4 は
> 赤にならない」は修正前の実測であり、修正後は当てはまらない)。
>
> **注2**: M11 は `Aws\S3\S3Client` を `FACADE_RULES` へ足すため、`AppServiceProvider` の
> `Aws\S3\S3Client` 参照が `http_facade_reference` site として新たに出る。
> その結果 テスト 13 だけでなく テスト 1 も赤くなる (設計は 13 のみを予測)。想定内の連動。

## 等価変形 (緑のままであることを実測)

| ID | 変形 | 期待 | 実測 |
|----|------|------|------|
| P1 | `RecaptchaVerifier` の `use Illuminate\Support\Facades\Http;` を消し `\Illuminate\Support\Facades\Http::asForm()` へ書き換え | 全緑 | `ExternalSeamInventoryTest` 15 + `ExternalSeamScannerTest` 20 = **35 本すべて緑** ✅ |
| P2 | `SocialAuthController` に `Socialite::driver()` を 3 箇所追加 | 全緑 | 15 本すべて緑 ✅ (クラス単位の目録なので site 数は問わない) |

## 規則強化の負のコントロール (規則を緩めると赤くなる)

| ID | 変形 | 期待する赤 | 実測 |
|----|------|-----------|------|
| N1 | `PAYMENT_CLIENT_CONSTRUCTION_EXACT` の完全一致を `str_starts_with($name, 'Stripe\\')` の接頭辞判定へ変更 | S6 #6 | `走査器: Stripe\HttpClient\CurlClient の new は検出しない` **のみ**赤 ✅ = 完全一致が偽陽性分離に効いている |
| N2 | `classify()` の facade 判定に `StaticCall->receiver` 分岐を追加 (二重検出させる) | S6 #9 / #11 / #12 | **7 本**赤 (#9 / #11 / #12 / #14 / #16 / #17 / #20 = 「ちょうど N 件」を数えるテストすべて) ✅ = canonical 契約 (facade は `NameReference` のみ) が守られている |

## S7 (captcha fake 配線) の負のコントロール

| 壊し方 | 実測で赤くなった test |
|--------|----------------------|
| `FakeExternalsServiceProvider` の `bind(RecaptchaVerifier::class, RecaptchaVerifierTestFake::class)` を削除 | 新規 `fake 配線時は secret があっても Google siteverify を叩かずに true を返す` + 既存 `3-2 実証: flag on + allowlist 環境で fake が厳密一致で解決される` の captcha dataset 3 環境分 + 1 = **計 5 本** |

これにより「新規 Feature テスト 1 が、そもそも外へ出ない状況を検査しているだけ」ではないことが
2 方向 (テスト 2 の負のコントロール = flag off で実際に 1 回出ることの実測 / 本 mutation) で示せている。

## 後片付けの確認

```
$ git status --short   # mutation 由来の差分は 0 (残っているのは本 PR の正規変更のみ)
```
すべての mutation は python ドライバが「編集前の内容をメモリに退避 → 実行 → finally で書き戻し」を
行っており、途中で失敗しても残らない形にした。


---

再レビューのうえ **全体判定: APPROVED / CHANGES_REQUESTED** を明記してほしい。
残る指摘があれば、それが「今回の PR で閉じるべきもの」か「後続 TODO へ分けるべきもの」かも述べてほしい。
