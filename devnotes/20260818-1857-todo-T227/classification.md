# T227 空振り検査を持たない走査 gate 12 本の分類と付与

観測点: `main` @ edfb863 (T226 マージ直後)。

対象は `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の D2 が
列挙した 12 本である。分類の基準は `AGENTS.md` §静的検査 (gate) と走査器の共通規約 (b) の
「違反が 0 件」と「母集団が 0 件」の区別、および同条の適用対象の定義
(「母集団の非空が不変条件である gate」か「入力を受け取って候補を返す再利用可能な検出器」か)。

準拠実装は `FfmpegProcessLaunchInventoryTest` の「母集団が空でない (degenerate PASS 防止)」と
`PromptGuardrailTest` の「5 走査根が解決でき、いずれも空でない」、および
`StrictTypesDeclarationGateTest` の「空振り防止 1〜4」(非空 + 床値 + 代表パス + 判定器の自己検査)。

## 分類の結果

| # | gate | 判定 | 走査根 / 母集団 | 付与した検査 (または付与しない理由) |
|---|---|---|---|---|
| 1 | `AppNameHardcodeTest` | 付与 | app / routes / database / resources/js / scripts の 5 本 | 5 本すべてが実在しファイルを持つこと (+ 判定の自己検査) |
| 2 | `BillingSyncDispatchInvariantTest` | 付与 | app/ 配下で `SyncBillingCustomerDetails::dispatch` を持つファイル | 母集団が窓口 1 本と完全一致すること |
| 3 | `ClaudeHooksWiringTest` (S12b) | 付与 | 7 本の glob が当たる実行面のファイル | 走査域が非空 + **glob ごと**に代表ファイルを当てること (S12c) |
| 4 | `FormRequestProhibitedKeyTest` | 付与 | app/Http/Requests の FormRequest | 非空 + 床値 25 (実測 34) + 代表クラス 2 本 |
| 5 | `FreePlanCodeWriteInvariantTest` | 付与 | app/ 配下で `free_plan_code` へ書き込むファイル | 母集団が窓口 1 本と完全一致すること |
| 6 | `MassAssignmentSafetyTest` | 付与 | app/Models の Model | 非空 + 床値 30 (実測 40) + 代表クラス 3 本 |
| 7 | `NoMessageCarrying404Test` | 付与 | app / routes / bootstrap の 3 本 | 3 本すべてが実在し PHP ファイルを持つこと |
| 8 | `ProjectMemberPivotWritePathTest` | 付与 | app/ の PHP ファイル | 非空 + 床値 400 (実測 827) + allowlist の各ファイルが実際に検出されること |
| 9 | `ValidationAttributeCoverageTest` | 付与 | app/Http/Requests と app/ (Requests を除く) の 2 つ | 2 つの母集団の非空 + 床値 25 / 400 (実測 34 / 793) |
| 10 | `BugHuntInventoryCheckInvariantTest` | 付与しない | 名指しの 2 ファイル + テスト自身が組み立てる sandbox | ディレクトリを列挙して母集団を作らない。根の改名は `Assert::fileExists` の即時 fail になる |
| 11 | `QueuedJobLeaseInventoryTest` | 付与しない | `Tests\Support\QueuedJobPopulation` | 目録との対称差 0 + 「3 系統が母集団に入っている」で非空が既に固定されている |
| 12 | `RateLimiterKeyConventionTest` | 付与しない | app/ の `RateLimiter::for()` 登録 | 非空の inventory との完全一致が非空を構造的に担保し、各 limiter は実評価もされる |

付与しない 3 本は、その理由を各 gate の docblock 冒頭に書いた (中身の正本は docblock 側に置き、
`AGENTS.md` へは写さない)。

## 負例による裏取り

付与した 9 本には、それぞれ「走査根を差し替えると母集団が空になる」ことを示す
負のコントロールのケースを併置した (母集団の列挙を走査根の引数で呼べる形へ揃えた)。
加えて実装中に**走査根そのものを一時的に壊して赤を確認**した。

- 手順: 9 本の走査根の既定値を存在しないパスへ書き換え、当該 9 ファイルだけを実行
- 結果: 付与した 9 件の空振り検査がすべて赤 (10 件目は
  `ValidationAttributeCoverageTest` の既存の stale entry 検査で、走査停止に伴う想定内の連鎖)
- 書き換えを戻した後は Architecture レーン 1121 件が緑

**この裏取りが押さえる範囲を誇張しない**: 押さえたのは「母集団の列挙が止まったら赤くなる」
ことだけであり、各 gate の**判定そのもの**の検出力は元から各 gate が持つ負例 / 正例の担当である。
唯一の例外が `AppNameHardcodeTest` で、slug が既定値 'app' の間は判定が一度も実行されないため、
判定を `appSlugHardcodeViolations()` へ分離し、「必ず在る語を拾う」「どこにも無い語を拾わない」の
両方向を実在の走査根に対して固定した (Codex Round 1 の指摘)。

## 併せて行ったこと

`devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の
「別 TODO として起票を申し送るもの」表 2 行目の追跡先 TODO ID 欄へ T227 を記入した
(同表 2 行とも ID が埋まることが元 TODO の完了条件だったため)。
