# 多角監査 サイクル 2 — 技術的負債 (Tech Debt)

- 監査日: 2026-08-05
- 対象レンジ: `4cbdff8..HEAD` (= `225550b`, T100/T101/T102/T103/T104/T105/T106)
- 監査担当観点: 技術的負債

## 技術的負債: DEBT_FOUND

**すべての品質ゲートは green**。新規に作り込まれた「壊れている負債」は無い。
検出したのは (a) 既知の未修正事項が **TODO 化されずに devnotes に埋もれている**こと、
(b) ゲート実装の**重複した自前静的解析**、(c) **環境側の残留物 (孤児 DB / git index 重複)** の 3 系統。
いずれも「今すぐアプリが壊れる」ものではないが、放置すると偽赤・調査コスト・運用事故として顕在化する。

---

## 1. 実測結果 (全 green)

| 検証 | コマンド | 結果 | 備考 |
|---|---|---|---|
| PHPStan (level 10) | `composer phpstan` | **PASS** | 779 files / `[OK] No errors` |
| PHP テスト | `bash scripts/run-test.sh` | **PASS** | 2867 tests / 2865 passed / 2 skipped / 11392 assertions / 216.4s |
| Pint | `vendor/bin/pint --test` | **PASS** | `{"tool":"pint","result":"passed"}` |
| ESLint | `pnpm lint` | **PASS** | 出力なし |
| TypeScript | `pnpm typecheck` | **PASS** | `tsc --noEmit` 出力なし |
| TypeScript (packages) | `pnpm typecheck:packages` | **PASS** | 出力なし |
| Vite build | `pnpm build` | **PASS** | 4.73s / app-*.js 166.87 kB (gzip 56.24 kB) |
| packages build | `pnpm build:packages` | **PASS** | `tsc -p tsconfig.json` |
| Vitest | `pnpm test` | **PASS** | 119 files / 1130 tests / 62.5s |
| Vitest (packages) | `pnpm test:packages` | **PASS** | 10 files / 106 tests / 0.83s |
| supply-chain gate | `pnpm run audit:gate` | **PASS** | 総 advisory 4 (moderate 4 / high 0 / critical 0) |

補足:
- グローバルテストロック (T099) 経由の直列化は正常動作。`ensure-test-db: base DB already exists: app_test_8af22c44`
  を出して既存 DB を再利用しており、レーン間干渉なし。
- `docs/supply-chain/accepted-advisories.yaml` は **空 (`[]`)**。accept-risk による
  「期限付き負債」はゼロ = supply-chain の受容負債は無い。**これは良い状態**。
- 非ブロッキングの警告が 2 系統ある (下記 §6-D)。

---

## 2. TODO / FIXME / HACK / XXX の件数と増減

`rg -e 'TODO|FIXME|HACK|XXX' app/ resources/js/ scripts/ tests/` の実測:

| 基準 | マーカー行数 |
|---|---|
| `4cbdff8` (サイクル開始) | **10** |
| `HEAD` (`225550b`) | **10** |
| 差分 | **±0 (増加なし)** |

内訳 (全 10 行、実質的な「未完了の印」は **0 件**):

| 種別 | 件数 | 内容 |
|---|---|---|
| テストフィクスチャの `T-XXX` / `CVE-2026-XXX` プレースホルダ | 4 | `scripts/audit-gate.test.ts` |
| `app/` の実コード | **0** | — |
| `resources/js/` の実コード | **0** | — |
| 「TODO」という語の文書的用法 (worktree 運用の "TODO 用 worktree") | 2 | `scripts/setup-worktree.sh` / `scripts/README.md` |

**判定: CLEAN**。今サイクルは 382 ファイル / +139k 行を動かしながら TODO コメントを 1 件も増やしていない。
「後で直す」をコメントで先送りしていない点は評価できる。

ただし裏返しの問題として、**`docs/TODO.md` の Open は T085 (iOS 実機受入確認) の 1 件のみ**で、
後述 §5 の既知未修正事項 4 件はいずれも TODO 化されていない。
「コード内 TODO ゼロ」が「追跡ゼロ」を意味してしまうと、devnotes に埋もれた既知事項が
サイクルを跨いで忘却される。**これが今サイクル最大のプロセス負債**。

---

## 3. 依存パッケージの outdated 状況

### 3.1 Composer (`composer outdated --direct`)

19 パッケージが更新可能。うち major (`~`) は 6 件。

| 種別 | パッケージ |
|---|---|
| **major (`~`) = 計画的作業が必要** | `pestphp/pest` 4.7.4→5.0.3、`pestphp/pest-plugin-browser` 4.3.1→5.0.0、`pestphp/pest-plugin-laravel` 4.1.0→5.0.1、`phpunit/phpunit` 12.5.30→13.2.6、`laravel/mcp` 0.8.0→0.9.1、`kent013/laravel-prism-prompt` 0.17.0→0.20.1 |
| **minor/patch (`!`) = 低リスク** | `laravel/framework` 13.18.0→13.24.0、`filament/filament` 5.6.7→5.7.5、`inertiajs/inertia-laravel` 3.1.0→3.3.1、`aws/aws-sdk-php` 3.387.1→3.390.4、`laravel/socialite` 5.27.0→5.29.0、`laravel/cashier` 16.5.3→16.6.0、`laravel/fortify` 1.37.2→1.37.3、`laravel/pint` 1.29.1→1.30.3、`spatie/laravel-ciphersweet` 1.7.4→1.8.0、`laravel-lang/lang`、`laravel/pao`、`nunomaduro/collision` |

**Pest 4→5 / PHPUnit 12→13 は連動する 1 バッチ**。2867 tests + browser 2 レーン
(Chromium/WebKit 契約) + グローバルロック機構 (`scripts/run-test.sh` / `run-browser-test.sh`) を
巻き込むため、独立 TODO として設計してから着手すべき。今サイクルでは触れていないのが正しい判断。

### 3.2 pnpm (`pnpm outdated`)

19 パッケージ。major は 3 件 (`@testing-library/jest-dom` 6→7、`@types/node` 25→26、他)。
残りは minor/patch。**フロントは概ね追従できている** (`svelte` 5.56.3→5.56.8、`vite` 8.0.16→8.2.0 等)。

### 3.3 未受容 advisory 4 件 — **いずれも上げれば消える**

| eco | パッケージ | 現在 | 修正版 | 経路 | 難易度 |
|---|---|---|---|---|---|
| npm | `undici` (GHSA-8xcm/-m8rv/-v3r7 の 3 件) | 6.27.0 | **>=6.28.0** | `packages/cli` の**直接依存** `"undici": "^6.27.0"` | **極小** — caret 範囲内。lockfile が古いだけで `pnpm update undici` で解消 |
| npm | `valibot` (GHSA-5qjj-4xww-7phc) | 1.4.1 | >=1.4.2 | `eslint-plugin-better-tailwindcss@4.4.1` (root で**厳密 pin**) の推移依存 | 小 — pin を 4.7.0 へ上げる必要あり (devDependency なので実行時影響なし) |

**評価**: advisory 負債は「上げ忘れ」であって「受容した負債」ではない。
AGENTS.md §依存脆弱性の「upgrade で解消が原則」に素直に従える状態。
`accepted-advisories.yaml` が空のまま解消できるので、**次サイクルで潰すべき最も費用対効果の高い項目**。

### 3.4 見落とされやすい構造的負債: **zod のメジャー分裂**

- root `package.json`: `"zod": "^4.4.3"`
- `packages/cli/package.json`: `"zod": "^3.23.0"`

同一リポジトリ内で zod v3 と v4 が並存している。AGENTS.md 思考原則 3
「後方互換の並走を残さない」に照らすと逸脱に近い。CLI 側のスキーマ定義が v4 へ移れば解消するが、
現状はどのゲートも検出していない (= 誰も気づかない負債)。

---

## 4. ゲート群のメンテ負債評価

### 4.1 規模の実測

| 指標 | 値 |
|---|---|
| `tests/Architecture/*.php` | **54 ファイル / 9,636 行** |
| `tests/js/architecture/*.ts` | **17 ファイル / 2,503 行** |
| ゲート合計 | **71 ファイル / 12,139 行** |
| **今サイクルの増分** | **新規 18 ファイル / +4,701 行** (ゲート総量の約 39% を 1 サイクルで追加) |
| app LOC / tests LOC (PHP) | 49,452 / 54,127 (**テストの方が多い**) |
| resources/js LOC / tests/js LOC | 19,088 / 19,756 (**ほぼ同量**) |

今サイクル新設のゲート (18 本):

- PHP (12): `CarbonOverflowArithmeticGateTest` / `ControllerAuthorizationGateTest` /
  `DocumentTitleCoverageTest` / `GlobalTestLockInventoryTest` / `LoginMethodRemovalRouteTest` /
  `NoNonCompoundGlobalUseTest` / `PasskeyPackageContractTest` / `PasskeyRouteProtectionTest` /
  `PasswordConfirmMiddlewareAbsenceTest` / `PhpunitBrowserConfigParityTest` /
  `ScriptsReadmeInventoryTest` / `SocialProviderTrustPolicyTest`
- JS (6): `ci-workflow-inventory` / `codex-model-consistency` / `contrast-invariant` /
  `passkeys-import-isolation` / `svelte-head-no-title` / `svelte-no-undef-gate`

### 4.2 形骸化の兆候: **ほぼ無い (積極的に評価できる)**

今サイクルのゲートは、形骸化対策が**設計として作り込まれている**。実測で確認した対策:

1. **逆方向整合 (stale 検出)** — allowlist/inventory の key が現存 named route であることを検査。
   削除・rename 済みの死んだエントリが残らない。
   (`DocumentTitleCoverageTest:540` / `ControllerAuthorizationGateTest:303` /
   `NestedRouteIdorDefenseTest:162` / `LoginMethodRemovalRouteTest:112,121`)
2. **理由の実体強制** — 空文字禁止に加え、`ControllerAuthorizationGateTest:48` は
   **最低文字数**を課して「同上」「N/A」を機械的に弾く。
   免除理由は自由文字列ではなく **`ControllerAuthorizationExemption` enum + 具体的根拠**の型付きペア。
3. **空振りガード (下限件数)** — `ControllerAuthorizationGateTest:223`「変更系 route の候補は
   下限を下回らない」、`DocumentTitleCoverageTest:535`「走査が空振りしていない」。
   走査対象が 0 件になって green になる典型的な形骸化を封じている。
4. **正/負のコントロール** — `DocumentTitleCoverageTest` は 11 test 中 **6 本が
   正/負コントロール** (first-class callable の誤検出、引数アンパック、1 hop 追跡の境界)。
   `GlobalTestLockInventoryTest:324` も同様。「ゲートが本当に落ちること」を fixture で検証している。
5. **debt の可視化と出口条件** — `tests/js/styles/inventory.ts:97` の `PENDING_CONTRAST_PAIRS` は
   「検査していない範囲」を明示宣言し、**「全部消えたら本 export と検査テストを同時に削除すること」**
   という出口条件をコメントに書いている。空の宣言が残って形骸化するのを設計で防いでいる。

### 4.3 肥大化の兆候: **現時点では健全**

inventory / allowlist のエントリ数実測 (肥大化していない):

| ゲート | エントリ数 |
|---|---|
| `NestedRouteIdorDefenseTest` | 37 |
| `DocumentTitleCoverageTest` (unresolvable + exempt) | 16 |
| `ControllerAuthorizationGateTest` (exemption) | 12 |
| `ScenarioWritePathInventoryTest` | 8 |
| `GlobalTestLockInventoryTest` | 6 |
| `LoginMethodRemovalRouteTest` | 3 |

いずれも「読んで理解できる」規模。ただし `DocumentTitleCoverageTest` が **774 行**で
`tests/Architecture/` 最大になった点は注視が必要 (次点 `ScenarioWritePathInventoryTest` 727 行)。
中身は 260 行の解析ロジック + 500 行のコントロールテストで、水増しではない。

### 4.4 責務重複: **2 箇所で実質的な重複あり (最大の技術的負債)**

#### (a) 自前 PHP トークン解析器が **8 本**、共有されていない

| 実装 | API |
|---|---|
| `tests/Support/AuthorizationMarkerScanner.php` (436 行 + 専用テスト 296 行) | `token_get_all` |
| `tests/Architecture/ScenarioWritePathInventoryTest.php` | `token_get_all` (8 箇所) |
| `tests/Architecture/PromptGuardrailTest.php` | `token_get_all` (3 箇所) |
| `tests/Architecture/ProjectMemberPivotWritePathTest.php` | `token_get_all` (2 箇所) |
| `tests/Architecture/RecentAuthRouteTest.php` | `token_get_all` |
| `tests/Architecture/DocumentTitleCoverageTest.php` (**新規**) | `PhpToken` |
| `tests/Architecture/CarbonOverflowArithmeticGateTest.php` (**新規**) | `PhpToken` |
| `tests/Architecture/NoNonCompoundGlobalUseTest.php` (**新規**) | `PhpToken` |

問題は本数そのものではなく、**同じ問題を独立に 2 回解いていること**:

- `AuthorizationMarkerScanner` … route action からハンドラ本体を取り出し、
  マーカー呼び出しの有無を判定 (認可マーカー)
- `DocumentTitleCoverageTest` … route action からハンドラ本体を取り出し、
  マーカー呼び出しの有無を判定 (`setPrivateTitle`)。
  `documentTitleMethodRanges` / `documentTitleIsFirstClassCallable` /
  `documentTitleBodyCallsMethod` / `documentTitleBodyRendersInertia` を独自実装し、
  **`$this->` / `self::` 経由の private helper 1 hop 追跡まで独自に持っている**

「メソッド範囲の切り出し」「first-class callable (`foo(...)`) を呼び出しと誤認しない」
「1 hop の private helper を追う」は**両者で同一の難所**であり、片方でバグを直しても
もう片方に伝播しない。加えて `token_get_all` 系 5 本と `PhpToken` 系 3 本で
**API が世代分裂**している。

**深刻度: Medium**。今は両方 green なので実害ゼロ。だが 3 本目・4 本目の
「ハンドラ本体を静的に読むゲート」を足すたびにコストが線形に増え、
かつ誤検出パターンの修正が横展開されない。

#### (b) route × middleware ゲートの層が 8 本に分散

`ControllerAuthorizationGateTest` / `ManageRouteAuthGuardTest` / `NestedRouteIdorDefenseTest` /
`ProjectRouteCurrentOrgGuardTest` / `RecentAuthRouteTest` / `PasskeyRouteProtectionTest` /
`LoginMethodRemovalRouteTest` / `TwoFactorEnforcementAllowlistTest`。

責務は**概ね分離できている** (ハンドラ内の認可 vs middleware の付与 vs middleware の順序 vs
ドメイン別の必須 middleware)。ただし `passkey.destroy` については:

- `PasskeyRouteProtectionTest:74` 「`passkey.destroy` は recent-auth が ensure-login-method より先に走る」
- `LoginMethodRemovalRouteTest:139` 「`ensure-login-method` middleware を持つ route は guard 必須リストのみ」

が同一 route の同一 middleware を別角度から拘束しており、**片方を変更すると
もう片方が落ちる結合**がある。意図的な二重防御とも読めるが、
どちらが正本かがコード上明示されていない。

**深刻度: Low**。件数の分散自体は問題ではないが、
「route の認可・middleware に関するゲートの全体像」を示すインデックスが
`docs/` に無いため、新規 route 追加時にどのゲートに登録すべきかを人が探す必要がある。

#### (c) JS 側: `.svelte` 再帰列挙が **8 本で個別実装**

`shape-ramp-purity` / `atomic-import-graph` / `svg-inline-allowlist` / `deprecated-imports` /
`svelte-head-no-title` / `typography-invariant` / `ds-purity` / (他) が
`fs.readdir(dir, { recursive: true })` をそれぞれ書いている。
除外規則 (node_modules / 生成物 / テスト用 fixture) がゲートごとにずれるリスクがある。
`shape-ramp-purity.test.ts:40` には「glob を top-level dep に追加せず Node 標準で列挙」という
方針コメントがあるので、**方針は正しく、共有ヘルパが無いだけ**。

**深刻度: Low**。`tests/js/architecture/_walk.ts` のような共有ヘルパ 1 本で解消する。

### 4.5 総合評価

> **形骸化: 兆候なし (むしろ模範的) / 肥大化: 現時点は健全だが増加速度が速い /
> 責務重複: 自前静的解析器の重複が実質的な負債**

1 サイクルで +4,701 行のゲートを追加したペースは持続可能ではない。
**次に必要なのは「ゲートを増やすこと」ではなく「ゲートの共通基盤を作ること」**。

---

## 5. 既知未修正事項の深刻度評価と対応優先度

### 5-1. `scripts/global-test-lock.sh` の pgid 取得 race — **深刻度: Low / 優先度: Medium**

- **状態**: **未修正を実測確認**。`scripts/global-test-lock.sh:350` は依然として
  `pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')"` のまま。
- **機序**: `set -euo pipefail` 下で死んだ pid に対する `ps` が exit 1 → コマンド置換の
  終了ステータスが代入に伝播 → `set -e` で即死。直後の `[ -n "${pgid}" ]` による
  race 許容 (行 349 のコメントが明示する意図) に**到達しない**。
- **深刻度が低い理由**: 失敗モードが **偽赤** (レーンが走らずに落ちる) であり偽グリーンではない。
  実運用でロック配下に置かれるのは `artisan config:clear` / `ensure-test-db.php` / `pest` で、
  いずれもミリ秒では終わらない。今回の `run-test.sh` 実走 (216s) でも顕在化していない。
- **優先度を Medium に置く理由**: 既に **回避コードが混入している**
  (`scripts/run-browser-test.contract.test.ts` のスタブに `sleep 0.1` を入れて回避)。
  回避策付きのバグは、次に同種の契約テストを書く人が同じ罠を踏むまで再発見されない。
  修正は 1 行 (`|| pgid=""`) で、devnotes に修正案まで書かれている。
- **修正コスト**: 小。ただし `scripts/verify-global-test-lock.sh` (層 1・C01〜C24) と
  `GlobalTestLockInventoryTest` (層 2) の再検証を伴う。
  T104 が「契約ファイルなのでスコープ外」と判断したのは妥当。
- **記録**: `devnotes/20260805-1329-todo-T104/known-issue-global-test-lock-race.md`
  (「推奨: TODO 化して追跡すること」と明記されているが **TODO.md には未登録**)。

### 5-2. `preg_split('/\R/')` の `/u` 欠落 — **深刻度: Medium / 優先度: High**

**これは「軽微なスタイル問題」ではない。実測で明確な破壊を確認した。**

PCRE の 8bit 非 UTF-8 モードでは `\R` が **バイト `0x85`** にもマッチする。
UTF-8 の日本語には `0x85` を含む文字が多数ある (「全」`E5 85 A8`、「先」`E5 85 88`、
「共」`E5 85 B1`、「内」`E5 86 85`、「入」`E5 85 A5`、「公」`E5 85 AC`、「六」`E5 85 AD` など)。

実測 (PHP 実行で確認):

```
preg_split("/\R/",  "全先共内入\nsecond line\n")  → 8 要素 (文字が途中で分断され文字化け)
preg_split("/\R/u", "全先共内入\nsecond line\n")  → 3 要素 (正常)
```

**実ファイルへの影響を定量化** (`tests/Architecture/GlobalTestLockInventoryTest.php:146`
`globalTestLockCodeLines()` — シェルソースからコメント行を落とす純関数):

| 対象 | `/u` なし | `/u` あり | 漏出 |
|---|---|---|---|
| `scripts/global-test-lock.sh` | **454 行** | **380 行** | **+74 行の偽の行分割 / +3,226 バイトのコメント文字列が「コード」として解析入力に混入** |
| `scripts/run-browser-test.sh` | — | — | +740 バイト |
| `scripts/run-test.sh` | — | — | +524 バイト |
| `scripts/run-vitest.sh` | — | — | +290 バイト |
| `scripts/with-global-test-lock.sh` | — | — | +69 バイト |

**なぜこれが問題か**: `globalTestLockCodeLines` の docblock (行 134-143) は
その存在理由を明示している —

> 「変更後スクリプトは『旧 worktree-local な test.lock を廃止した』『flock -n をやめた』といった
> 説明を**コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる」

つまり**このゲートの成立条件そのものがコメント除去の完全性**である。それが約 92% しか
効いておらず、4.8 KB のコメント文字が検査対象に漏れている。
`globalTestLockLaneScriptViolations` は漏出テキストに対して
`str_contains($code, 'storage/framework/testing/test.lock')` /
`preg_match('/\bflock\s+-n\b/', $code)` / `preg_match('/GLOBAL_TEST_LOCK_DIR=/', $code)` を
掛けているため、**該当語を含む日本語コメントを 1 行書き足しただけでゲートが偽赤になる**。

- **現状**: 実測で偽陽性は **0 件** (漏出フラグメント中に "CI" を含むものが 2 件あるが、
  CI 検査パターンは `$CI` / `${CI}` 形式を要求するため今は当たらない)。
  **偶然で green を保っている**。
- **同種の欠落**: `tests/Architecture/BughuntOrchestratorGateInvariantTest.php:95`
  (`bughuntGateFirstEffectiveStatement` — 関数窓の「最初の実効文」を返す)。
  日本語コメントが分断されると**コメント断片が「最初の実効文」として返る**ため、
  こちらの方が誤判定の射程が広い。
- **問題ない実装**: `DefensiveInstructionsPresenceTest:47` (`/\r?\n/`) と
  `ScriptsReadmeInventoryTest:71` (`/\r\n|\r|\n/`) は `\R` を使っていないので安全。
- **修正**: `preg_split('/\R/u', ...)` へ変更 (2 箇所)。
  正しくは `\R` を使う全箇所に `/u` を義務づけるゲート化まで行うのが望ましい。
- **優先度 High の理由**: 修正コストが 2 文字 × 2 箇所と極小である一方、
  放置すると「日本語コメントを足したらテストが落ちた」という**原因が極めて追いにくい偽赤**を
  将来の実装者に投げつける。しかもこのリポジトリはコメントを日本語で書く規約
  (AGENTS.md §実装規約)。**踏むのは時間の問題**。

### 5-3. `doc/reference/` の NFC/NFD 重複 — **深刻度: Medium / 優先度: Medium**

実測で根本原因を特定:

- `git ls-files doc/reference` = **197 entry**、ファイルシステム上の実体 = **139 ファイル**。
- 差分 **58 件が NFD/NFC の重複 index entry**。全 58 件が「NFC 正規化すると衝突するグループ」。
- **同一 blob hash が 2 つの path 名で index に載っている**ことを確認:
  ```
  e6b874481b... 0  "doc/reference/mockups/UL/09_\343\202\253\343\203\206\343\202\263\343\202\231\343\203\252...png"  ← NFD (カテコ + ゛)
  e6b874481b... 0  "doc/reference/mockups/UL/09_\343\202\253\343\203\206\343\202\264\343\203\252...png"              ← NFC (カテゴ)
  ```
- **重複 blob 55 件**。実体 139 ファイルに対して 58 件が二重登録 = index の約 30% が幽霊エントリ。
- `.git/config` に **`precomposeunicode = false`** が設定されている
  (macOS 由来の NFD 名を git がそのまま扱う設定)。
- 現在の `/workspace` では `git status --porcelain doc/reference` は clean
  (基盤 FS の正規化非依存 lookup により両 path が同一 inode に解決されるため)。

**影響**: `scripts/teardown-worktree.sh:71` は
`git status --porcelain --untracked-files=all` が非空なら **teardown を fail させる**。
NFD/NFC の解決挙動は FS・git 設定・checkout 順序に依存するため、worktree では
「削除済み扱いの NFD entry + untracked 扱いの NFC ファイル」が現れて dirty 判定になりうる。
これは §5-4 の孤児 DB を生む直接の経路でもある (teardown が fail → DB 回収が走らない)。

**深刻度が Medium 止まりの理由**: 対象がアプリ実装ではなく参照用モックアップ画像であり、
アプリの動作には影響しない。

**修正方針**: NFD 側 58 entry を `git rm --cached` で index から落とし、
`precomposeunicode = true` へ。加えて「index に NFC 正規化衝突が無いこと」を
ゲート化すれば再発を防げる (`ScriptsReadmeInventoryTest` と同系統の軽量ゲートで足りる)。

### 5-4. 孤児テスト DB — **深刻度: Low / 優先度: Medium (悪化を確認)**

`pg_database` 実測 (`/workspace` の正規 DB は `app_test_8af22c44`。
`substr(sha1(realpath('/workspace')),0,8)` で確認済み。`git worktree list` は `/workspace` のみ):

| DB 群 | 個数 | サイズ |
|---|---|---|
| `app_test_3a7d6b4e` + `_test_1..4` | 5 | ~68 MB |
| `app_test_823cbbd2` + `_test_1..4` | 5 | ~69 MB |
| **`app_test_b4f0102e` + `_test_1..4`** (課題文に記載なし = **今サイクルで新規発生**) | 5 | ~73 MB |
| `app_test_018d63c6` | 1 | ~7.6 MB |
| `app_test_91c7197b` | 1 | ~7.6 MB |
| **孤児 合計** | **17 DB** | **221.9 MB** |

**課題文が挙げた 2 群 (3a7d6b4e / 823cbbd2) に加え、b4f0102e / 018d63c6 / 91c7197b の
3 群が存在する**。すなわち **孤児は前サイクルから減っておらず、むしろ増えている**。
`teardown-worktree.sh` の「best-effort 回収」が実際には機能していない (もしくは
teardown を経ずに worktree が破棄されている)。

- **深刻度 Low の理由**: dev 環境の pgsql ディスクを食うだけで、アプリにも CI にも影響しない。
  DB 名 regex により本番/dev DB との混同はない。
- **優先度 Medium の理由**: **単調増加する負債**であり、放置すると開発機の
  ディスクを静かに埋める。かつ「孤児が増える = teardown が失敗している」という
  **上流の運用不全のシグナル**として価値がある。掃除だけして原因 (§5-3) を
  放置すると、シグナルを消して問題を隠すことになる。
- **対応**: (1) §5-3 を先に直す → (2) `scripts/` に孤児 DB の検出・回収コマンドを追加し
  `scripts/README.md` 台帳に登録 (`ScriptsReadmeInventoryTest` が整合を強制する)。
  **回収は「アプリ判断で dev DB を破壊するな」の禁止事項に触れるため、
  必ず名前 regex + 現存 worktree hash 突合の二重ガード付き wrapper 経由にすること**。

---

## 6. その他の観察

### 6-A. 検証コマンド規約とスクリプトの乖離 (小)

`AGENTS.md` §実装規約の検証コマンド一覧に **`pnpm build:packages` が含まれていない**
(`composer test` / `composer phpstan` / `pint --test` / `pnpm lint` / `pnpm typecheck` /
`pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm test:packages` の 9 本)。
一方 CI (`.github/workflows/ci.yml` frontend job) は `Build (workspace packages)` を実行している。
**規約と CI が不一致**。`ci-workflow-inventory.test.ts` は CI 側を固定しているが、
AGENTS.md 側との突合はしていない。深刻度 Low。

### 6-B. Svelte コンパイラ警告 7 件 (ゲート対象外)

`pnpm test` 実行時に `state_referenced_locally` 警告が 7 件出る:

| ファイル | 件数 |
|---|---|
| `resources/js/pages/Capture/Index.svelte` (34:24 / 35:28 / 35:68 / 36:22) | 4 |
| `resources/js/pages/Auth/ResetPassword.svelte` (20:8 / 21:15) | 2 |
| `resources/js/components/features/billing/BillingContactForm.svelte` (29:26) | 1 |

**今サイクルの混入ではない** (該当 3 ファイルの最終変更は 2026-08-03 の T092/T094、
`4cbdff8..HEAD` の diff に含まれない)。`pnpm lint` (ESLint) では検出されないため
**どのゲートにも掛かっていない**。Svelte 5 runes のリアクティビティが期待どおり効いていない
可能性を示す警告なので、機能バグの予兆になりうる。深刻度 Low〜Medium。

### 6-C. Vite 設定の将来非互換警告 (小)

`vitest.config.ts:2:29` の `import "../../scripts/test-inventory-config"` が拡張子なしで、
Vite の `configLoader: 'native'` (将来のデフォルト) で非対応。
Vite major 更新時に確実に踏む。修正は拡張子追加のみ。深刻度 Low。

### 6-D. Vite build のプラグイン時間偏り (情報)

`vite-plugin-svelte:load-custom` が 45%、`laravel` が 38%。
build は 4.73s なので現時点で問題なし。記録のみ。

---

## 7. 次サイクルの TODO 候補 (優先度つき)

| # | 優先度 | テーマ | タイトル | 概要 | モード | 規模 |
|---|---|---|---|---|---|---|
| 1 | **High** | test | `\R` 正規表現の `/u` 欠落是正 + ゲート化 | `GlobalTestLockInventoryTest:146` / `BughuntOrchestratorGateInvariantTest:95` の `preg_split('/\R/')` を `/u` 付きへ。実測で 74 行の偽分割・4.8 KB のコメント漏出を確認済み。日本語コメント規約下では偽赤が時間の問題。合わせて「`\R` を使うなら `/u` 必須」を Architecture ゲート化 | incremental | 小 |
| 2 | **High** | infrastructure | 未受容 advisory 4 件の解消 (undici / valibot) | `packages/cli` の `undici` を `^6.28.0` へ (caret 範囲内、lockfile 更新のみ)。`eslint-plugin-better-tailwindcss` の厳密 pin 4.4.1→4.7.0 で valibot 1.4.2+ を引き込む。`accepted-advisories.yaml` を空のまま保つ | incremental | 小 |
| 3 | **Medium** | infrastructure | `global-test-lock.sh` pgid race の修正 | `scripts/global-test-lock.sh:350` を `pgid=""` + `\|\| pgid=""` へ。`verify-global-test-lock.sh` (C01〜C24) と `GlobalTestLockInventoryTest` の再検証、および `run-browser-test.contract.test.ts` の `sleep 0.1` 回避策の撤去まで含めて 1 バッチ。修正案は `devnotes/20260805-1329-todo-T104/known-issue-global-test-lock-race.md` に記載済み | standalone | 小〜中 |
| 4 | **Medium** | infrastructure | `doc/reference/` NFC/NFD 重複の解消 + 再発防止ゲート | NFD 側 58 index entry を除去 (実体 139 に対し index 197、重複 blob 55)、`precomposeunicode=true` 化。「index に NFC 正規化衝突が無い」ゲートを新設。**#5 の前提条件** | standalone | 中 |
| 5 | **Medium** | infrastructure | 孤児テスト DB の検出・回収と teardown 失敗の是正 | 実測 17 DB / 221.9 MB (前サイクル既知の 2 群に加え b4f0102e 群が新規発生 = 増加中)。名前 regex + 現存 worktree hash 突合の二重ガード付き wrapper を `scripts/` に追加し `scripts/README.md` 台帳へ登録。**#4 を先に直さないとシグナルを消すだけになる** | standalone | 中 |
| 6 | **Medium** | test | Architecture ゲートの共通基盤化 (PHP トークン解析) | `AuthorizationMarkerScanner` と `DocumentTitleCoverageTest` が独立に実装している「メソッド範囲切り出し / first-class callable 除外 / `$this->`・`self::` 1 hop 追跡」を共有ヘルパへ集約。`token_get_all` 系 5 本と `PhpToken` 系 3 本の API 分裂も解消。**次のゲートを足す前にやる**べき | standalone | 中 |
| 7 | **Low** | test | JS ゲートの `.svelte` 列挙ヘルパ共有化 | 8 本のゲートが個別に `fs.readdir(recursive)` を実装。除外規則のドリフト防止に `tests/js/architecture/` の共有ヘルパ 1 本へ集約 (glob 依存は追加しない方針を維持) | incremental | 小 |
| 8 | **Low** | docs | route 認可・middleware ゲートのインデックス整備 | 8 本に分散した route ゲートについて「新規 route 追加時にどれに登録するか」の対応表を `docs/architecture.md` か `docs/app-integration-guide.md` に追加。`passkey.destroy` の二重拘束 (`PasskeyRouteProtectionTest:74` / `LoginMethodRemovalRouteTest:139`) の正本を明示 | incremental | 小 |
| 9 | **Low** | general | zod メジャー分裂の解消 | root `^4.4.3` / `packages/cli` `^3.23.0` の並存を v4 へ統一 (思考原則 3「後方互換の並走を残さない」) | incremental | 中 |
| 10 | **Low** | frontend | Svelte `state_referenced_locally` 警告 7 件の解消 | `Capture/Index.svelte` (4) / `Auth/ResetPassword.svelte` (2) / `BillingContactForm.svelte` (1)。ESLint では検出されないため、解消後に vite-plugin-svelte 警告を fail 扱いにするゲート化も検討 | incremental | 小 |
| 11 | **Low** | docs | AGENTS.md 検証コマンド一覧に `pnpm build:packages` を追加 | CI (frontend job) は実行しているが規約に無い。`ci-workflow-inventory.test.ts` に AGENTS.md 側との突合を足せばドリフトを機械検出できる | incremental | 極小 |
| 12 | **Conditional** | infrastructure | Pest 4→5 / PHPUnit 12→13 のメジャー更新 | 2867 tests + browser 2 レーン (Chromium/WebKit 契約) + グローバルロック機構 (`run-test.sh` / `run-browser-test.sh`) を巻き込む。`pest-plugin-browser` 4→5 / `pest-plugin-laravel` 4→5 と連動する 1 バッチ。**トリガー: Pest 5 系が安定し pest-plugin-browser の WebKit サポートが確認できたら** | standalone | 大 |

### 運用面の提言 (TODO 化とは別)

コード内 TODO は 0 件で理想的だが、**`docs/TODO.md` の Open が 1 件しかない一方で
devnotes には未追跡の既知事項が 4 件埋もれている**。
「実装しない」と決めた既知事項は devnotes に置いたまま終わらせず、
**必ず `docs/TODO.md` (Open または Conditional) へ昇格させる**ことを
`app-implement` / `app-todo-close` の運用に組み込むべき。
`known-issue-global-test-lock-race.md` 自身が「推奨: TODO 化して追跡すること」と
書いているのに TODO 化されていないことが、この抜けを端的に示している。
