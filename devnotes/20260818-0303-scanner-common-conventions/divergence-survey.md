# 規約と既存 gate の食い違い (棚卸し)

観測点: `main` @ 1194d34 (2026-08-18 実測)。
判定に使った 5 条は `conceptual-design.md` のブロック 1 (家系の正典 v1 の (a)〜(e))。

**この棚卸しは是正を求めるものではない**。AG-154 (1) は既存分への遡及を求めていない。
ここに書くのは「規約を敷いたときに、既存のどこが規約どおりでないか」を後から探し直さずに
済むようにするためであり、是正が要るものは別 TODO として起票する。

## 母集団と数え方 — **全数性を主張しない**

母集団は下記のコマンドで列挙される**候補**に限る。ここに現れない場所
(`scripts/` 配下の走査、`tests/Feature` / `tests/Unit` に直接書かれた走査、設定ファイル中の
判定など) は見ていない。全数を主張する棚卸しが必要になったら、それ自体が
走査器索引 (aigenba 形) の導入を再検討する条件になる (概念設計スコープ外節)。

- 検出器: `tests/Support/` 配下でソースを読んで site を列挙するクラス。
  ```bash
  git ls-files 'tests/Support/**/*.php' | xargs -I{} sh -c 'rg -lq "token_get_all|PhpTokenScan|Finder::create|RecursiveDirectoryIterator" {} && echo {}'
  ```
- gate: `tests/Architecture/*.php` (130 本) と `tests/js/architecture/*.test.ts` (22 本)。

## D1: 部分修飾名を解決しないまま通す (`PhpReferenceScanner`) — (a) と (b) の両方に抵触

`tests/Support/PhpReferenceScanner.php` は `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) を
`ltrim($text, '\\')` するだけで、**現在の名前空間への相対解決も先頭要素の別名解決も行わない**。
docblock (48-54 行) が自らこう書いている:

> `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できない。
> これは既存 gate と同じ非対称であり、抽出は**振る舞い保存**が目的なのでここを直さない。

規約の観点では 2 つ問題がある。

1. (a) に反する。解決できていない名前を、あたかも解決済みの名前として emit している。
2. (b) に反する。解決できない形なのに**落とさず通す**。利用側の完全修飾名の一覧に一致しないため、
   参照 site は emit されていても**違反候補として認識されず、無言で見逃される**
   (走査器の母集団から消えるのではなく、利用側の照合の段で落ちる)。

**「docblock へ限界を書けば済む」ではない**。本走査器の上に立つ gate
(外部到達点の目録 / プロンプト防御の窓口) は、**部分修飾名を除外する形で検出力の主張を狭めていない**。
保護対象の操作は部分修飾名でも書けるので、走査器側の但し書きだけでは不変条件の穴は塞がらない。
したがって D1 は引き続き (a)・(b) 違反である。

**波及**: 本走査器を直接使う gate 6 本
(`PastDueSinceWriteInvariantTest` / `NoMessageCarrying404Test` / `LlmDefenseConfigGateTest` /
`PromptDefenseWindowGateTest` / `PromptGuardrailTest` / `AccountDeletionPathGateTest`) と、
上に乗る検出器 2 本 (`ExternalSeam\ExternalSeamScanner` / `ExternalClientBoundaryScanner`)、
さらにその先の目録 gate。**セキュリティ不変条件に直結する経路を含む**
(外部到達点の目録 / プロンプト防御の窓口)。

**扱い**: 判定の是正は本 TODO では行わない (波及が広く、規約の成文化とは別の作業量になる)。
ただし**現 docblock を放置すると、規約導入後に「規約に照らして是認済みの限界」と誤読される**。
そこで本 TODO では **docblock の文面だけ**を
「規約 **(a)・(b)** を満たしていない既知の穴であり、是正は別 TODO」と読める形へ直す (概念設計 施策 2)。

- **追跡先 TODO ID**: T226 (部分修飾名を完全修飾名まで解決し、受け手の解決状態を判別可能にした。
  そのうえで**外部到達点の目録 2 系統と prompt 窓口**では未解決を拾う側へ倒した。
  残る限界は `PhpReferenceScanner` の docblock「保証しないもの」が正本)
- **是正するときの設計条件** (Codex Round 1 観点 7): 未解決を通常の完全修飾名文字列へ**混ぜない**。
  判別できる値 (専用の種別を持つ site / 専用の戻り値) か明示的な例外で表す。
  `string|null` へ潰すと PHPStan level 10 と fail-closed の意図の両方を損ねる。

## D2: 走査の空振り検査が全体には無い — (b) の「母集団が 0 件」

**「違反が 0 件」ではなく「判定に使う母集団が 0 件」の話である**。違反ゼロは正常な状態であり、
落とす対象ではない。問題は母集団が空になっても緑のまま通る形で、heuristic な数え方は次のとおり
(語の揺れを拾い切れないため、**下限ではなく候補**として読むこと)。

```bash
# 空振り検査らしき表現を持たない Architecture gate
for f in tests/Architecture/*.php; do
  rg -q "not->toBe\(\[\]\)|not->toBeEmpty|toBeGreaterThan|空振り|degenerate|母集団が空" "$f" || basename "$f"
done
```

130 本中 32 本が該当し、そのうち**自分でソースを走査しているもの 12 本**が実際の候補である
(`AppNameHardcodeTest` / `BillingSyncDispatchInvariantTest` / `BugHuntInventoryCheckInvariantTest` /
`ClaudeHooksWiringTest` / `FormRequestProhibitedKeyTest` / `FreePlanCodeWriteInvariantTest` /
`MassAssignmentSafetyTest` / `NoMessageCarrying404Test` / `ProjectMemberPivotWritePathTest` /
`QueuedJobLeaseInventoryTest` / `RateLimiterKeyConventionTest` / `ValidationAttributeCoverageTest`)。

実読で確認した典型例:

- `MassAssignmentSafetyTest` — `RecursiveDirectoryIterator` で `app/Models` を走査してモデル一覧を
  作り、違反が空であることだけを assert する。**一覧が 0 件になっても緑**である。
- `FormRequestProhibitedKeyTest` — 同型 (`app/Http/Requests` の列挙)。

準拠している側の見本も実在するので、規約はそれを名指しすればよい:

- `FfmpegProcessLaunchInventoryTest` — 「母集団が空でない (degenerate PASS 防止)」を独立した
  test 1 本として持つ
- `PromptGuardrailTest` / `PromptDefenseWindowGateTest` — 「5 走査根が解決でき、いずれも空でない」

**扱い**: 12 本の分類と是正は別 TODO。規約側では「新設・変更するときに揃えるもの」の 3 番として
要求し、既存分へは遡及しない (AG-154 (1))。

## D3: 走査根の単一出典化が途中 — 本リポジトリ固有の条

`Tests\Support\TrackedPhpSourceFiles` (git 追跡下の PHP 全数) を**実際に呼ぶ** gate は 3 本
(`StrictTypesDeclarationGateTest` / `NoNonCompoundGlobalUseTest` / `LaneExternalFakeBindingTest`)。
さらに 3 本 (`ForbiddenStatementTokenInvariantTest` / `BughuntNamingResidualTest` /
`RouteCacheExemptionPremiseTest`) は**「使えない理由」を docblock に書いて自前で `git ls-files` を叩く**
(前者は母集団に Blade を含む、後 2 本は `*.php` に限らず追跡下の全ファイルを見るため)。

> **数え方の訂正**: 単純な文字列検索では上の 6 本すべてが「利用者」に見える。
> 実際の呼び出しは 3 本で、残りは説明のための言及である。
> **これは利用関係の棚卸しを素の文字列一致だけで行えない実例である** —
> クラス名の言及と実際の呼び出しは、構文か呼び出し関係で区別する必要がある
> (完全一致で検索しても docblock 中の同名参照は一致し続けるので、
> (e) のトークン完全一致では解けない別種の問題である)。

一方、母集団がそれより狭い走査は自分の根を持っている
(`PrismDirectDispatchScanner::ROOT_DIRECTORIES` の 5 本、`NoMessageCarrying404Test` の 3 本、
`FfmpegProcessLaunchInventoryTest` の `app` 1 本 等)。

**これは食い違いではない**と判定する。全数走査と部分走査は母集団の定義が違い、
片方に寄せると走査域が黙って変わる。ただし部分走査の側には
「**存在しない根は fail-fast**」が要る (準拠実装 `PrismDirectDispatchScanner::roots()`)。
この条件を規約のブロック 3 に明記する。

## D4: 負例の置き場が 3 通りある — (c) には抵触しない

- 見本ファイル: `tests/Architecture/fixtures/global-use/` の 12 本のみ
- 検出器の自己検査: `tests/Unit/Architecture/` の 11 本 (219 test)
- gate 内の合成入力: `PromptGuardrailTest` (Prism 直呼びの正例・負例 8 本) /
  `JobDeferralTerminationGateTest` (E12 / E13 / E14) /
  `PromptDefenseWindowGateTest` (「合成負例で判定が発火し、正例では発火しない」)

**実測の結論**: `tests/Support/` の検出器で「負例による裏取りをまったく持たないもの」は
**見つからなかった**。当初 4 本 (`PhpReferenceScanner` / `PromptWindowScanner` /
`PrismDirectDispatchScanner` / `JobDeferralScanner`) を候補として挙げたが、実読すると
すべて上記 3 通りのどれかで裏取りされている
(`PhpReferenceScanner` は `ExternalClientBoundaryScannerTest` の 14 test と
`ExternalSeamScannerTest` の 21 test が別名解決・group use・scope 追跡・
コメント / 文字列リテラル除外を通して押さえている)。

したがって規約は**置き場を 1 つに強制しない**。強制するのは
「gate または検出器の docblock から負例へ辿れること」だけにする。

## D5: 保証範囲の書き方 — 慣習は定着しているが正本が無い

「保証しない / 誇張しない」を明記しているファイルは `tests/` 配下で 60 本以上ある。
`AGENTS.md` にも同じ作法が各節に散っている (テストレーンの外部 HTTP 出口 / 禁止する文 /
ドメイン固有規約 9・11・14・15・18 ほか)。

**食い違いではないが、要求として書かれた場所が無い**。新節のブロック 2 の 4 番として
「docblock に走査対象と保証しないものを書く」を置き、**中身は docblock 側を正本とする**
(件数や条件を `AGENTS.md` へ写さない)。

## 別 TODO として起票を申し送るもの

| # | 内容 | 根拠 | 追跡先 TODO ID |
|---|---|---|---|
| 1 | `PhpReferenceScanner` の部分修飾名を落とす形へ寄せる (波及 6 gate + 2 検出器)。未解決は判別できる値か例外で表し、完全修飾名の文字列へ混ぜない | D1 | T226 (実施済み) |
| 2 | 空振り検査を持たない走査 gate 12 本の分類と付与 | D2 | _(未採番)_ |

いずれも本 TODO のスコープ外である (`conceptual-design.md` スコープ外節)。
**TODO の起票・採番は監督者、採番済み ID の本表への追記は実装者の作業**である
(本設計セッションも実装者も `docs/TODO.md` を変更しない)。
**2 行とも ID が埋まることが本 TODO の完了条件**である
(片方だけを条件にすると、残った側が一覧に埋もれて追跡先を失う)。
とくに 1 行目はセキュリティ不変条件に波及するため、ID 未採番のまま完了扱いにしない。

---

# 付録: `AGENTS.md` を対象に含む機械検査の分類 (施策 1 の影響調査)

Codex 詳細設計レビュー Round 1・Round 2 の指摘 (影響調査の再現可能性 / 共通列挙器経由の見落とし) に応じて記録する。
観測コミット: `main` @ 1194d34。

**主張の範囲**: 下の**母集団 A と B の 2 つに限って**全数を主張する。
この 2 本で拾えない形 (パスを定数や変数から組み立てる / 複数行に割った呼び出し /
リポジトリ外から `AGENTS.md` を読むツール) は**見ていない**。
「動的に組み立てる形は存在しない」とは言えない。

## 母集団 A: `AGENTS.md` をパスのリテラルとして持つ追跡下ファイル

```bash
git grep -lE "['\"\`]AGENTS\.md['\"\`]" -- ':!devnotes'
```

**22 本**が当たる。うち 13 本は散文 (`README.md` / `doc/*.md` / `docs/*.md` /
`.claude/skills/*/SKILL.md`) で、**実行されるコードは 9 本**である。

## 母集団 B: 追跡下のファイルを丸ごと列挙する走査 (共通列挙器とその利用側)

```bash
git grep -lE "ls-files" -- ':!devnotes'
```

**10 本**が当たり、うち `AGENTS.md` を**母集団に含む**のは **3 本**
(`BughuntNamingResidualTest` / `RouteCacheExemptionPremiseTest` / `GitIndexNormalizationTest`)。
残る **7 本**の除外理由は次のとおり。

| 除外したもの | 理由 |
|---|---|
| `tests/Support/TrackedPhpSourceFiles.php` | `ls-files -- *.php` なので `AGENTS.md` は母集団外 |
| `tests/Architecture/ForbiddenStatementTokenInvariantTest.php` | 同上 (`*.php` 限定) |
| `tests/Architecture/ClaudeHooksWiringTest.php` | `ls-files` の使い方が `--error-unmatch .claude/settings.json` = **1 ファイルの追跡確認**であり列挙ではない (同テストの `AGENTS.md` 参照はマーカー区間の読み取りで、母集団 A 側の話) |
| `tests/js/architecture/codex-model-consistency.test.ts` | 走査根が `.claude` と `scripts` の 2 つ |
| `tests/js/architecture/pages-path-case-invariant.test.ts` | 走査根が `resources/js/` |
| `.claude/skills/app-bug-hunt/ledger/validate_findings.py` | bug-hunt の所見台帳の検証器で、`ls-files` の対象は所見が指す glob |
| `.claude/skills/app-update-docs/SKILL.md` | 手順書の散文 (実行されるコードではない) |

**再現コマンドの書き方**: 除外は `git grep` の pathspec (`':!devnotes'`) で行う。
`$(git ls-files | … )` でファイル名を引数へ展開する形は、空白を含むパスで割れる上に
引数長の上限にも触れるので使わない。

## 分類 (A ∪ B のうち `AGENTS.md` を実際に対象に含むもの)

| 利用箇所 | 母集団 | 読み方の型 | 新節の追加で赤くなるか |
|---|---|---|---|
| `tests/js/architecture/verification-commands-doc-sync.test.ts` | A | マーカー区間 (`VERIFICATION_COMMANDS`) | ならない (区間の外) |
| `tests/Architecture/ClaudeHooksWiringTest.php` | A | マーカー区間 (`CLAUDE_HOOKS_WIRING`) | ならない (区間の外) |
| `tests/Architecture/BughuntOrchestratorGateInvariantTest.php` | A | 全文の**必須語句** (`BUGHUNT_ORCHESTRATOR=1` / `default-deny` / `` `provision`/`teardown` ``) | ならない (在ることの検査なので追記で壊れない) |
| `tests/Architecture/RouteCacheExemptionPremiseTest.php` | A + B | 全文の**必須語句** (逸脱 ID) + **追跡下で `route:cache` を持つファイル数の pin** | ならない (`AGENTS.md` は既に母集団に居るのでファイル数は動かない。新節にこの語も書かない) |
| `tests/Architecture/BughuntShardCapInvariantTest.php` | A | 全文の**禁止語句** (散文中の並列枠数・ポート範囲の主張) | ならない (新節はポート番号も枠数も書かない) |
| `tests/Architecture/RetiredRecoveryReferenceGateTest.php` | A | 全文の**禁止語句** (撤去済みの回収コマンド名・クラス名) | ならない (新節に撤去語彙を書かない) |
| `tests/Architecture/BughuntNamingResidualTest.php` | **B のみ** | 全文の**禁止語句** (撤去済みの bug-hunt クラス名。既知の言及は `docs/TODO*.md` だけ件数で pin) | ならない (新節に撤去語彙を書かない) |
| `tests/Architecture/GitIndexNormalizationTest.php` | A + B | **パスのみ** (index のパス正規化。中身を読まない) | ならない |
| `tests/Architecture/SkillsLockIgnoreCoverageTest.php` | A | **存在のみ** (`.gitignore` に載っていないこと) | ならない |
| `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` | A | **パスのみ** (逸脱台帳の書式規則の見本) | ならない |

**読み方の型は 5 つ** — マーカー区間 / 全文の必須語句 / 全文の禁止語句 /
全文を対象にした構造・件数の判定 / パス・存在のみ。

**`AGENTS.md` の中の語句の出現数を数える検査は存在しない**
(`RouteCacheExemptionPremiseTest` が数えるのは**ファイル数**であってファイル内の出現数ではない)。
したがって節の追記で件数の pin が動く経路は無い。

**Round 2 で見つかった漏れ**: `BughuntNamingResidualTest` は母集団 A に現れない
(`AGENTS.md` をリテラルで持たず、追跡下を丸ごと列挙するため) 。
母集団 A だけを見ていた Round 1 の付録はこれを落としていた。
**共通列挙器経由の利用側は、パスのリテラル検索では原理的に拾えない**というのが教訓である。
