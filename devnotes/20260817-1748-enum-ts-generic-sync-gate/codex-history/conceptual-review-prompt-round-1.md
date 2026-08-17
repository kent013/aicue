# 使命 (North Star)
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js + TypeScript 6 + vitest）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: PHPStan level 10 / TS strict を通せるか

【この設計に固有の論点 — 必ず判断を示すこと】
- (i) 検査を PHP レーン (Pest) から JS レーン (vitest) へ移し、既存の PHP テスト 4 本を **削除** する判断は妥当か。
  「既存テストの削除」は一般には禁止だが、同じ不変条件をより強い抽出器で再構築し、母集団を 14 組から 27 組へ増やす移設である。
  AGENTS.md 思考原則 3 は「後方互換の並走を残さない。書き換えると決めたら同じ PR で旧実装を消す」と定める。
  PHP レーンに薄い委譲テストを残す案 (家系の別リポジトリ aigenba が採った形) と比べてどちらが妥当か。
- (ii) 裁定 AG-099 の後半 (PHP 列挙の全数走査による発見の段 + 逆走査 2 規則) を今回のスコープから外す判断は妥当か。
  実測では、今回の登録を終えると逆走査が拾う未登録候補は 1 件だけになる。
- (iii) 未登録だが既に値が一致している 13 組を今回まとめて登録することは、スコープの膨張ではないか。
- (iv) 「完全一致のみを扱い、部分集合の関係は登録しない」という割り切りに穴はないか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (裁定 AG-099 追従)

- 機能台帳 (lctl) の機能: `enum-ts-sync-gate`
- 本リポジトリの現状: `pending` / `improvement_candidate`
- 起点: 裁定 AG-099 (「共通抽出基盤 + 型情報を使う抽出 + 宣言表への集約」= 前半 /
  「発見の段 (全数走査と既定拒否の分類) + 逆走査 2 規則」= 後半)

## 背景・課題

サーバー側 (PHP) の列挙型が持つ値の集合と、TypeScript 側に書かれた同じ選択肢の集合が
食い違うと、画面は「どの分岐にも当たらない値」を受け取って無言で描画を落とす。
撮影 PWA とマニュアル編集画面は状態値で分岐する面が多いため、この事故は使命
(思考ゼロ・編集ゼロで現場が動画を作れる) に直接効く。

本リポジトリには検査自体は存在するが、次の 3 つの問題がある。

### 問題 1: 抽出器が「二重引用符の型別名」専用の正規表現である

`tests/Support/TsUnionValues.php` は

```php
'/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s'   // 宣言の本体を取る
'/"([^"]+)"/'                                                      // 本体から二重引用符の文字列を拾う
```

の 2 段の正規表現である。これは**静かに間違える経路を実際に持つ**。

- (a) **注釈の中の引用符を値として拾う**。`| "a" // "b" の例` と書くと `b` が値集合に混ざる。
  PHP 側に `b` が無ければ赤くなる (誤検出) が、逆に **PHP 側に `b` があり TS 側の union に
  無い**とき、注釈のおかげで**緑になる** = 取り残しを見逃す。
- (b) **開いた union を閉じていると誤認する**。`export type X = "a" | "b" | (string & {});`
  は TS の型としては任意の文字列を許すが、正規表現は `a` `b` だけを見て「一致」と判定する。
- (c) **別名参照を読めない**。`export type MemberRoleState = ConsoleRole | "owner" | "unassigned";`
  は正規表現では `owner` `unassigned` しか取れないため、現状**登録できない** (登録すれば
  誤って赤くなる)。実際に本リポジトリの `resources/js/types/admin.ts` がこの形で、
  PHP の `MemberRoleState` (5 値) と**値集合が完全一致しているのに検査対象外**である。
- (d) 宣言の本体を「最初の `;` まで」で切るため、`;` を含む型 (オブジェクト型リテラル等) が
  union に混ざると本体を取り違える。

つまり現状は「狭い」だけでなく「(a)(b) の形で静かに間違えうる」。

### 問題 2: 列挙を 1 つ足すたびに検査を 1 本手で足す形になっている

`tests/Architecture/ManualEnumTsSyncInvariantTest.php` の test 宣言数は
2026-07 の 6 件から現在 **12 件** (列挙ごとの検査 11 件 + 取りこぼし防止の自己検査 1 件) へ
増えている。同じヘルパを使う PHP テストは 4 本 (他に参照だけの 1 本)。
検査の本体は 1 行しか違わないのに、ファイルと test 宣言だけが単調増加している。

### 問題 3: 母集団が手書きの決め打ちで、既に大きな取りこぼしがある

本設計の実測 (`devnotes/20260817-1748-enum-ts-generic-sync-gate/survey.md`) では、
PHP の文字列付き列挙 112 本に対し、TS 側に値集合が完全一致する宣言が 27 組ある。
**うち検査されているのは 14 組だけ**で、13 組が未検査のまま放置されている
(`PlanCode` / `AdminConsoleRole` / `DashboardState` / `DashboardRole` /
`OrganizationRole` / `BillingFeedbackKind` / `PurchaseFormState` / `TakeStatus` /
`AnalysisStep` / `AnalysisConflictType` / `ScenarioConflictType` / `ManualSortOption` /
`MemberRoleState`)。プラン符号や役割のような**課金と権限の語彙**が入っているので、
取りこぼしの実害は小さくない。

## 改善アイデア

**検査を「TypeScript の型情報で読む 1 本の汎用 gate」へ作り直し、対象は目録の 1 行にする。**

1. 抽出は **TypeScript コンパイラの型情報**で行う。型別名の宣言を型検査器で解決し、
   その型が**文字列リテラル型だけの union (または単一の文字列リテラル型)** であることを
   要求する。1 つでもそれ以外の構成要素があれば**受理せず例外で落とす**(fail-closed)。
   これで問題 1 の (a)(b)(c)(d) がすべて閉じる。
2. PHP 側の値は、**PHP 列挙ファイルを最小の字句解析で読む**。列挙本体の直下に現れる
   `case 名 = '値';` の形だけを受理し、それ以外の書き方 (定数式など) は例外で落とす。
   - PHP を実行しない理由: CI の `frontend` job には PHP が入っていない
     (`.github/workflows/ci.yml` の `frontend` は `setup-php` も `composer install` も
     持たない)。検査を PHP 実行に依存させると CI の構成を変えることになる。
3. 対象は **1 本の目録 (写しの一覧)** に集約する。1 組 = 1 行 (PHP 列挙ファイル /
   TS ファイル / TS 側の宣言名 / 一言の説明)。列挙を足したときの作業は**行を 1 つ足すこと**になる。
4. 旧実装 (`tests/Support/TsUnionValues.php` と、それを使う PHP テスト 4 本) は
   **同じ変更で消す** (思考原則 3: 後方互換の並走を残さない)。個別の 12 件は
   目録の行として汎用 gate の母集団に載り替える。
5. ついでに、**既に値集合が完全一致している未登録の 13 組を目録へ登録する**
   (問題 3 の解消)。いずれも現状で一致しているので、登録しても赤くはならない。

### 置き場所を PHP レーンから JS レーンへ移す判断

型情報を使う抽出には TypeScript コンパイラが要るので、検査は vitest レーン
(`tests/js/architecture/`) に置く。本リポジトリの TypeScript 側の目録型 gate
(`logout-call-site-inventory.test.ts` / `svg-inline-allowlist.test.ts` /
`atomic-import-graph.test.ts` 等) はすべてこの形で、**目録を test ファイル内の定数として持つ**
様式が既にある。家系のテンプレートと motivation の同種 gate も
`tests/js/architecture/enum-ts-sync.test.ts` である。したがって置き場所は既存の様式に一致する。

PHP レーンに委譲用の薄いテストを残す案 (aigenba がとった形) は**採らない**。
本リポジトリの 4 本は列挙 ⇔ TS の突き合わせ**だけ**を行っており、他の検査と同居していない。
残すと「同じ不変条件を 2 レーンで宣言する」並走になる。検証コマンドは
`composer test` と `pnpm test` の両方が緑であることを commit の条件にしているので
(AGENTS.md 実装規約)、レーンが移っても commit 前に必ず走る。

## 期待効果

- **使命への貢献**: 撮影 PWA / マニュアル画面が状態値で分岐する面は多く、
  値の取り残しは「押しても何も起きない」「空白の画面」という詰みに直結する。
  母集団が 14 組 → 27 組に増えることで、課金・権限・解析・撮影の語彙が検査に載る。
- **静かに間違える経路が閉じる**: 注釈内の引用符と開いた union の 2 つは、
  現在**取り残しを緑にしうる**。型情報で読めば構造上起きない。
- **増殖が止まる**: 列挙を足したときの作業が「テストを 1 本書く」から「目録に 1 行足す」になる。
- **家系への追従**: 裁定 AG-099 の前半 (共通抽出基盤 + 型情報 + 目録への集約) を満たす。
  aigenba が 2026-08-17 に同じ形を着地させており、設計の当たりは既に取れている。

## 実装方針(概要)

| # | 施策 | 主な変更 |
|---|------|---------|
| A | 汎用 gate の新設 | `tests/js/architecture/enum-ts-sync.test.ts` (目録 + 突き合わせ) / `tests/js/support/enum-ts-sync/` (型情報の入口・TS 値集合の抽出・PHP 列挙の読み取り) |
| B | 抽出器の自己検査 | `tests/js/architecture/enum-ts-sync-extractor.test.ts` + 見本ファイル群。**旧形が静かに合格する変異**を実測して記録する |
| C | 旧実装の撤去 | `tests/Support/TsUnionValues.php` と PHP テスト 4 本を削除。`TicketLedgerReaderInventoryTest` の案内文を新しい目録へ向ける |
| D | 母集団の拡張 | 未登録で値集合が完全一致する 13 組を目録へ登録 (14 組 → 27 組) |
| E | 規約・文書 | AGENTS.md ドメイン規約に 1 項追加 / `docs/architecture.md` に「保証しないもの」を含む節を追加 |

## 制約・前提

- CI の `frontend` job に PHP は無い → PHP 実行に依存しない (字句解析で読む)。
- `tsconfig.json` は `tests/js/**/*.ts` を型検査対象に含む → 施策 B の**壊れた見本**は
  `exclude` へ足して `pnpm typecheck` から外す (aigenba の申し送りと同じ手当)。
- 型情報の入口 (program) の構築は最初のテストの中で走ると高負荷時に既定の制限時間を
  超えうる (aigenba の実測)。**目録に出てくる TS ファイルだけを起点**にし、
  `beforeAll` で明示的な制限時間を与えて 1 度だけ作る。
- 値集合の突き合わせは**完全一致のみ**を扱う。部分集合の関係
  (`SelectableTakeStatus` / `DashboardJobStatus`) は登録しない。
- `.svelte` の中の宣言は本設計では扱わない (現状 1 件も無い。登録されたら fail-closed で落とす)。

## スコープ外

- **裁定 AG-099 の後半**: 発見の段 (PHP 列挙を全数走査して未分類を既定拒否で落とす) と
  逆走査 2 規則。aigenba も未着手で、本作業の後に別 TODO として起票する。
  本設計の実測により、施策 D 適用後に逆走査の規則 1 (値集合の完全一致による未登録候補) が
  拾う残りは **`SelectableTakeStatus` 1 件だけ**であることが分かっている
  → 後半の見積りは小さい。
- **スキーマ正本から TS を生成する方式** (`schema-codegen-ts-pattern`) — 別 feature。
- 部分集合・上位集合の関係の検査、`.svelte` / 定数配列 / switch の case ラベルの抽出。
- 値以外の同期 (ラベル文言・表示順)。

## 参考: 実測

# 実測: 本リポジトリの PHP 列挙 ⇔ TS 値集合の対応関係 (2026-08-17)

数え方と再現手順は `survey.py` (設計時の使い捨て。`scripts/` へは昇格しない)、
生の出力は `survey-raw.txt`。

- PHP の文字列付き列挙: **112 本** (`app/**/*.php` の `enum X: string`)
- TS 側で「値だけの集合」として読める宣言: **48 件**
  (`export type X = "a" | "b";` と `const X = [...] as const` を正規表現で拾ったもの。
  別名参照を含む宣言は拾えていないので、これは**下限**である)

## 現在検査されている 14 組

| # | PHP 列挙 | TS ファイル | TS 宣言 | 現在の検査 |
|---|---|---|---|---|
| 1 | `Manual\VideoManualStatus` | `types/manual.ts` | `VideoManualStatus` | ManualEnumTsSyncInvariantTest |
| 2 | `Manual\ManualProgress` | `types/manual.ts` | `ManualProgress` | 同 |
| 3 | `Manual\RenderKind` | `types/manual.ts` | `RenderKind` | 同 |
| 4 | `Manual\RenderStep` | `types/manual.ts` | `RenderStep` | 同 |
| 5 | `Manual\RenderErrorCode` | `types/manual.ts` | `RenderErrorCode` | 同 |
| 6 | `Manual\RenderConflictType` | `types/manual.ts` | `RenderConflictType` | 同 |
| 7 | `Manual\ScenarioVerdict` | `types/manual.ts` | `ScenarioVerdict` | 同 |
| 8 | `Manual\ScenarioRuleCode` | `types/manual.ts` | `ScenarioRuleCode` | 同 |
| 9 | `Manual\JobStatus` | `types/manual.ts` | `AnalysisJobStatus` | 同 |
| 10 | `Manual\MaterialType` | `types/manual.ts` | `CutMaterialType` | 同 |
| 11 | `Manual\MaterialType` | `types/capture.ts` | `MaterialType` | 同 (写しが 2 ファイルにある) |
| 12 | `Notification\NotificationType` | `types/notification.ts` | `NotificationType` | NotificationTypeTsSyncInvariantTest |
| 13 | `Billing\OnboardingBillingState` | `types/billing.ts` | `BillingStateValue` | OnboardingBillingStateTsSyncInvariantTest |
| 14 | `AccountDeletionBlockerAction` | `types/account.ts` | `AccountDeletionBlockerAction` | AccountDeletionBlockerActionTsSyncInvariantTest |

## 未検査だが値集合が完全一致している 13 組 (施策 D で登録する)

| # | PHP 列挙 | TS ファイル | TS 宣言 | 備考 |
|---|---|---|---|---|
| 15 | `PlanCode` | `types/Auth.ts` | `PlanCode` | 課金プランの符号 |
| 16 | `AdminConsoleRole` | `types/admin.ts` | `ConsoleRole` | 管理画面の役割 |
| 17 | `MemberRoleState` | `types/admin.ts` | `MemberRoleState` | **正規表現では読めない** (`ConsoleRole \| "owner" \| "unassigned"` の別名参照)。型情報でのみ一致が取れる |
| 18 | `OrganizationRole` | `lib/shared-props.ts` | `OrganizationRoleValue` | 共有 props の役割 |
| 19 | `Billing\BillingFeedbackKind` | `types/billing.ts` | `BillingFeedbackKind` | 課金画面の通知種別 |
| 20 | `Billing\PurchaseFormState` | `types/billing.ts` | `PurchaseFormStateValue` | 購入フォームの状態 |
| 21 | `Manual\TakeStatus` | `types/capture.ts` | `TakeStatus` | 撮影テイクの状態 |
| 22 | `Dashboard\DashboardState` | `types/dashboard.ts` | `DashboardState` | ダッシュボードの状態 |
| 23 | `Dashboard\DashboardRole` | `types/dashboard.ts` | `DashboardRole` | ダッシュボードの役割 |
| 24 | `Manual\AnalysisStep` | `types/manual.ts` | `AnalysisStep` | 解析の段階 |
| 25 | `Manual\AnalysisConflictType` | `types/manual.ts` | `AnalysisConflictType` | 解析の衝突種別 |
| 26 | `Manual\ScenarioConflictType` | `types/manual.ts` | `ScenarioConflictType` | 台本の衝突種別 |
| 27 | `Manual\ManualSortOption` | `types/manual.ts` | `ManualSortOption` | 一覧の並び順 |

## 登録しないもの (理由つき)

| TS 宣言 | 理由 |
|---|---|
| `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という**部分集合の意図**を持つ宣言。今は `TakeStatus` と全一致だが、完全一致で縛ると意図と食い違う。逆走査 (AG-099 後半) の候補として残す |
| `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合 (進行中のみ)。注釈でそう書かれている |
| `types/capture.ts::CaptureProgress` | 対応する PHP 列挙が無い (画面側だけの語彙) |
| `components/atoms/*.types.ts` の見た目の語彙 (`ButtonVariant` / `BadgeTone` / `ModalSize` 等) | デザインシステムの語彙でサーバー側に対応が無い |
| `lib/stores/toast.ts::ToastType` / `lib/stores/flash-to-toast.ts::FLASH_KEYS` | 値集合は `AlertType` と同じだが、対応する PHP 列挙が無い |
| `lib/capture/*` / `lib/debug/*` の状態語彙 | 画面側の内部状態。サーバーへ出ない |

## 逆走査 (AG-099 後半) の見積り

施策 D の 13 組を登録した後、「値集合が完全一致するのに未登録」で残るのは
**`SelectableTakeStatus` の 1 件だけ**である。つまり後半の規則 1 を入れるときの
免除登録は 1 件で足りる見込みで、後続 TODO の規模は小さい。
