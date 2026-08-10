# T141 (PR-A) mutation evidence — 「壊すと赤くなること」の実測

> 詳細設計 `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` §共通: mutation で赤化を確認する手順。
> **実装完了の条件は「テストが緑」ではなく「壊すと赤くなることを実測した」**である。
> PR-A に該当する mutation は **M1 / M2 / M3 / M24** の 4 本。
> 各 mutation は 1 つずつ適用 → 実測 → 戻す、を行い、最後に `git status --short` で残留 0 を確認した。

実行コマンド: `composer test -- <対象テストファイル>` (グローバルテストロック配下)。

---

## M1 (設計どおりでは**赤くならなかった**。設計の予測と実測がずれた例)

| 項目 | 内容 |
|---|---|
| 変異 | `AccountDeletionPathGateTest` の `DELETION_PATH_ROOTS` から `OrganizationMembershipService::deleteAccount` を外す |
| 設計の予測 | 空振り検知 (閉包サイズ floor) が赤くなる |
| **実測** | **16 tests / 16 passed = 緑のまま** |

**なぜずれたか (辻褄を合わせずに記録する)**:
`AccountController::destroy` は `OrganizationMembershipService` を**引数の型宣言で受け取る**。
閉包はクラス粒度で辿るので、`deleteAccount` を起点から外しても
`AccountController` 経由で `OrganizationMembershipService` に到達し、**閉包が 1 件も変わらない**。
すなわち設計が想定した「起点を 1 つ外せば閉包が縮む」という前提が、この 2 起点の関係
(片方がもう片方を型で参照している) では成立しない。

これは gate の欠陥ではなく **mutation の設計ミス**である。閉包の到達判定が生きていることは
M1' が示す。なお PR-B で 3 つ目の起点 (`PurgeDeletionRequestsCommand::handle`) を足すときは、
その起点が他の 2 起点から到達不能なら M1 相当が成立する (足した側で再確認すること)。

## M1' (M1 の代替。実際に赤くなる形へ置き換えて実測)

| 項目 | 内容 |
|---|---|
| 変異 | `DELETION_PATH_ROOTS` から `AccountController::destroy` を外す (他方から到達不能な起点を外す) |
| 赤くなったテスト | **検査 1: 退会経路の依存閉包は目録と exact-fit で一致する** |
| 実測 | 16 tests / 15 passed / **1 failed** |

失敗メッセージ (要点):

```
残骸 => [
  'App\Http\Controllers\Controller',
  'App\Http\Controllers\Settings\AccountController',
]
```

---

## M2

| 項目 | 内容 |
|---|---|
| 変異 | `OrganizationMembershipService` に `private ?\Stripe\StripeClient $mutationProbe = null;` を追加 (**型宣言だけ。呼び出しは 1 つも書かない**) |
| 赤くなったテスト | **検査 2: 閉包内のどのクラスも決済事業者記号を参照しない** |
| 実測 | 16 tests / 15 passed / **1 failed** |

```
App\Services\Organization\OrganizationMembershipService :
  app/Services/Organization/OrganizationMembershipService.php:39 name Stripe\StripeClient
```

これが本 gate の存在理由そのものである。behavioral 2 本
(`AccountDeletionTest`) はこの変異では**緑のまま**である
(型注入しただけで呼ばれていないため、実行時には観測されない)。

## M3

| 項目 | 内容 |
|---|---|
| 変異 | `deleteAccount` の中に `$probe = app('cashier.stripe');` を追加 (container の literal 解決) |
| 赤くなったテスト | **検査 2: 閉包内のどのクラスも決済事業者記号を参照しない** |
| 実測 | 16 tests / 15 passed / **1 failed** |

```
App\Services\Organization\OrganizationMembershipService :
  app/Services/Organization/OrganizationMembershipService.php container literal cashier.stripe
```

## M24

| 項目 | 内容 |
|---|---|
| 変異 | redaction 記録 migration から CHECK 制約 (`organizations_stripe_customer_redaction_pair_check`) の `DB::statement` を削除 |
| 赤くなったテスト | **片列だけの UPDATE は DB の CHECK 制約が拒否する (アプリ層を迂回しても守られる)** |
| 実測 | 8 tests / 7 passed / **1 failed** — `Exception "Illuminate\Database\QueryException" not thrown.` |

---

# impl-review Round 1 の修正で**新設した検出点**の mutation (Round 2 [Warning] 対応)

Round 1 の指摘対応で入れた検出点 (検査 7 / 8 / 9 / literal 動的メソッド名 / implements 逆向き辺) は
設計の mutation 表に無い。「不変条件は壊すと赤くなることまで確認して初めて実装済み」に従い、
**5 本を追加で実測した**。

## M4

| 項目 | 内容 |
|---|---|
| 変異 | `DELETION_PATH_CASHIER_LOCAL_METHODS` へ `'charge' => '…'` を追加 (検出面を狭める改変) |
| 赤くなったテスト | **検査 7 (allowlist の exact-fit pin)** + **負のコントロール 7 形目** (`->{'charge'}()` が検出されなくなる) |
| 実測 | 21 tests / 19 passed / **2 failed** — `Failed asserting that an array has the key 'charge'` |

2 本落ちたことに意味がある: exact-fit pin だけでなく、**検出面が実際に狭まったこと**も
fixture が独立に捕まえている。

## M5

| 項目 | 内容 |
|---|---|
| 変異 | `DELETION_PATH_ROOTS` から `OrganizationMembershipService::deleteAccount` を外す (= 設計の M1 と同じ変異) |
| 赤くなったテスト | **検査 8 (起点集合の exact-fit pin)** |
| 実測 | 21 tests / 20 passed / **1 failed** |

**設計の M1 が緑だった穴は、これで塞がった**。閉包が変わらない起点削除でも起点 pin が赤くなる。

## M6

| 項目 | 内容 |
|---|---|
| 変異 | `MarkStripeCustomerRedactedCommand::handle` に `\Laravel\Cashier\Cashier::stripe();` を追加 |
| 赤くなったテスト | **検査 9 (redaction 記録コマンドは決済事業者記号を参照しない)** |
| 実測 | 21 tests / 20 passed / **1 failed** (symbol `Laravel\Cashier\Cashier` と `Laravel\Cashier\Cashier::stripe()` を検出) |

Feature テストの `StripeGatewayInterface` mock は**この変異では緑のまま**である
(Cashier facade は mock を経由しない)。静的検査が要る理由そのもの。

## M7

| 項目 | 内容 |
|---|---|
| 変異 | `deletionPathLiteralDynamicCalls()` の収集直前に `continue;` を挿入し検出を殺す |
| 赤くなったテスト | **負のコントロール 7 形目 (literal の動的メソッド名)** |
| 実測 | 21 tests / 20 passed / **1 failed** (`['->stripe()', '->charge()']` → `[]`) |

## M8

| 項目 | 内容 |
|---|---|
| 変異 | `deletionPathTraverse()` から implementors の逆向き辺を外す |
| 赤くなったテスト | **負のコントロール 8 形目 (interface の実装クラスを保守的に引き込む)** |
| 実測 | 21 tests / 20 passed / **1 failed** |

## M9 (impl-review Round 3 で新設した前提 pin の赤化実測)

| 項目 | 内容 |
|---|---|
| 変異 | `deletionPathDeclaredTypes()` を `return []` にする (= Round 3 で指摘された「`ReferenceSite` 経由だと空の型で集合が空になる」退行の再現) |
| 赤くなったテスト | **検査 4 (PSR-4 前提 pin)** / **負のコントロール 9 形目** / **同 10 形目** / **正のコントロール (匿名クラス)** の計 4 本 |
| 実測 | 24 tests / 20 passed / **4 failed** |

検査 4 は app/ の**全ファイル**を「宣言型 [] ≠ パス由来 [FQCN]」として列挙して落ちた。
すなわち集合比較 (`$declared !== [$scan['class']]`) にしたことで、**宣言 0 件でも空振りしない**。

---

## 実装中に mutation とは別に発見した fail-open (修正済み)

`tests/Support/PhpReferenceScanner` の alias マップ (`ReferenceScanResult::$imports`) は、
**クラス本体の `use SomeTrait;` を先頭の `use App\...\SomeTrait;` と同じ短縮キーで上書きする**。
結果として alias マップの値が短縮名 (`'SomeTrait'`) に潰れ、**FQCN が失われる**。

閉包の到達辺を alias マップから取ると **trait 経由の到達が丸ごと消える (fail-open)**。
本 gate は辺を**正規化トークン列の修飾名トークンから直接**取ることでこれを回避しており、
`負のコントロール 5 形目 (b)` が「alias マップは潰れている / それでも辺は残る」を両方 pin している。

**走査器そのものは変更していない** (他 gate の振る舞い保存のため)。この非対称は
`ExternalSeamInventoryTest` / `ExternalClientTimeoutInventoryTest` にも同じ形で存在しうるが、
両目録は「決済 / facade / client 構築」の **site** を見ており trait 到達を辺に使っていないため、
本件で挙動が変わる箇所は無い (実測でも app/ の閉包メンバーは 1 件も増減しなかった)。

---

## 後始末

全 mutation を戻した後、`git diff` に mutation の残留が無いことを確認済み
(`git status --short` の差分は本 PR の実装ファイルのみ)。
