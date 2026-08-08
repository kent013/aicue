# impl-review Round 3

Round 2 の [Warning] (mutation-evidence.md の本数・見出しの不整合) を修正した。
**コードは Round 1 の修正から 1 文字も変えていない** (本ラウンドの変更は devnotes 内の記録のみ)。

---

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Warning] mutation-evidence.md の本数・見出しが修正後の実態と一致していない

- 判断: **対応する**
- 根拠: 指摘のとおり。Round 1 の修正で `ExternalSeamScannerTest` へ 1 本追加したのに、
  evidence 冒頭のベースラインが「20 本」、P1 の記録が「15 + 20 = 35 本」のままで、
  見出しも `M1〜M18` のままだった。**実測 evidence を正本として提示する以上、
  数字が実態とずれているのは辻褄合わせと同じ**なので今回の PR で閉じる。
- 対応内容:
  1. 冒頭のベースラインを「`ExternalSeamInventoryTest` 15 本 / `ExternalSeamScannerTest` 21 本」へ更新し、
     **M1〜M18 の初回実測が 20 本時点のものである**ことと、追加した 1 本が M1〜M18 のいずれとも
     交差しない (赤化した test 集合は変わらない) ことを明記した。
  2. 見出しを `## M1〜M19 (すべて赤化を実測)` へ更新。
  3. **P1 / P2 / N1 / N2 を 21 本の状態で再実測**し、記録を実測値へ差し替えた:
     - P1: 15 + 21 = **36 本すべて緑**
     - P2: `ExternalSeamInventoryTest` 15 本すべて緑
     - N1: 21 本中 1 本赤 (`Stripe\HttpClient\CurlClient の new は検出しない` のみ)
     - N2: 21 本中 7 本赤 (「ちょうど N 件」を数えるテストすべて)
     いずれも結論は Round 1 修正前と同じで、規則強化の負のコントロールとしての意味は変わらない。

## コード側の追加変更要求はなし

Codex は「コード実装について追加の変更要求はない」と明記している。コードは Round 1 の修正から
1 文字も変えていない (本ラウンドの変更は devnotes 内の evidence 記録のみ)。


---

## 修正後の mutation-evidence.md (全文)

# T138 mutation evidence (実測)

各 mutation は **1 操作**で手編集し、`php artisan test --filter=…` を実行して赤を確認し、
直後に元へ戻した。最後に `git diff` / `git status --short` で残留が無いことを確認済み。

**現在のベースライン (impl-review Round 1 の修正反映後)**: `ExternalSeamInventoryTest` **15 本** /
`ExternalSeamScannerTest` **21 本** (Round 1 で fail-closed 方針の 1 本を追加)、いずれも無 mutation で **全緑**。
M1〜M18 の初回実測は `ExternalSeamScannerTest` が 20 本だった時点のものだが、
追加した 1 本は M1〜M18 のいずれとも交差しない (合成ソース上の `->stripe()` 採用のみを見る) ため
赤化した test 集合は変わらない。P1 / P2 / N1 / N2 は **21 本の状態で再実測済み**である。

## M1〜M19 (すべて赤化を実測)

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
| P1 | `RecaptchaVerifier` の `use Illuminate\Support\Facades\Http;` を消し `\Illuminate\Support\Facades\Http::asForm()` へ書き換え | 全緑 | (Round 1 修正後に再実測) `ExternalSeamInventoryTest` 15 + `ExternalSeamScannerTest` 21 = **36 本すべて緑** ✅ |
| P2 | `SocialAuthController` に `Socialite::driver()` を 3 箇所追加 | 全緑 | (再実測) `ExternalSeamInventoryTest` 15 本すべて緑 ✅ (クラス単位の目録なので site 数は問わない) |

## 規則強化の負のコントロール (規則を緩めると赤くなる)

| ID | 変形 | 期待する赤 | 実測 |
|----|------|-----------|------|
| N1 | `PAYMENT_CLIENT_CONSTRUCTION_EXACT` の完全一致を `str_starts_with($name, 'Stripe\\')` の接頭辞判定へ変更 | S6 #6 | (21 本で再実測) `走査器: Stripe\HttpClient\CurlClient の new は検出しない` **のみ**赤 ✅ = 完全一致が偽陽性分離に効いている |
| N2 | `classify()` の facade 判定に `StaticCall->receiver` 分岐を追加 (二重検出させる) | S6 #9 / #11 / #12 | (21 本で再実測) **7 本**赤 (#9 / #11 / #12 / #14 / #16 / #17 / #20 = 「ちょうど N 件」を数えるテストすべて) ✅ = canonical 契約 (facade は `NameReference` のみ) が守られている |

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
