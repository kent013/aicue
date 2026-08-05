Round 1 の指摘 (Critical 1 / Warning 6 / Suggestion 2) をすべて捌きました。
2 件については**部分的に反論**しています (施策 1 の全 dynamic dispatch deny-by-default、
施策 9 の Svelte AST 切替)。いずれも実測データを添えています。

指摘された技術的事実はすべて実測で裏を取り、さらに **施策 1 / 施策 4 / 施策 9 の検出器を
プロトタイプで実走させて**、fixture が全 PASS し実リポジトリ走査が期待件数
(Carbon 8 件 / 非複合 use 2 件) と完全一致することを確認しています。
結果は詳細設計内に転記しました。

再レビューをお願いします。各施策の判定と全体判定 (APPROVED / CHANGES_REQUESTED) を
明示してください。

---

# 対応マトリクス: design-review Round 1

指摘された技術的事実はすべて **実測 / プロトタイプ実走で裏を取ってから**対応した。

## 施策 4 [Critical] `use \RuntimeException;` (先頭 `\` 付き単一名) を取りこぼす

- 判断: **対応する**（指摘は正しい。silent hole だった）
- 根拠: 実測で確認。PHP は先頭 `\` 付きでも**まったく同じ warning** を出す:

  ```
  use \RuntimeException;    → Warning ...non-compound name 'RuntimeException'...
  use function \strlen;     → Warning ...non-compound name 'strlen'...
  use const \PHP_VERSION;   → Warning ...non-compound name 'PHP_VERSION'...
  ```

  しかも tokenizer 上は **T_STRING ではなく T_NAME_FULLY_QUALIFIED** になる:

  ```
  use \RuntimeException;  → T_USE, T_NAME_FULLY_QUALIFIED('\RuntimeException'), ';'
  use RuntimeException;   → T_USE, T_STRING('RuntimeException'), ';'
  ```

  元設計の `is(T_STRING)` 判定では**先頭 `\` 付きを丸ごと取りこぼす**。
- 対応内容: 判定を token 種別から**名前の中身**へ変更した（Codex の提案どおり）:
  - import 要素を `T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` /
    `T_NS_SEPARATOR` を連結して **1 つの文字列に正規化**
  - `ltrim($name, '\\')` 後に区切り `\` を含まなければ非複合 = 違反
  - `,` / `as` / `;` / `{` で flush する制御に書き換え
  - 負のコントロール **「先頭バックスラッシュ付きの非複合 use も検出する」を追加**
  - 正のコントロールに `use \Illuminate\Support\Str;`（先頭 `\` 付き複合名）と
    カンマ区切りの複合名を追加
- 検証: プロトタイプ実走で全 fixture PASS + 実リポジトリ走査
  （total=1124 / namespaceless=459 / violations=**2** = 施策 5 の 2 件と完全一致）

## 施策 4 [Warning] `T_NAME_*` 非依存の実装は tokenizer 差分に弱い

- 判断: **対応する**（上の Critical 対応に統合）
- 根拠: 同じ問題の別表現。「名前を 1 要素に集約 → セグメント数判定」に統一せよ、が本質。
- 対応内容: まさにその形に書き換えた。`T_NS_SEPARATOR` 分割型の tokenizer 表現でも
  同じ結論になる（連結してから判定するため）。

## 施策 1 [Warning] `safeCalls > 0` は「違反ゼロかつ許可形ゼロ」の健全状態で誤落ちする

- 判断: **対応する（ただし提案より強い形で）**
- 根拠: 指摘は正しい。実コードの Carbon 使用有無に依存する指標は脆い。
  ただし「fixture だけで担保して実コード側の空振り検知を捨てる」のも惜しい —
  走査基盤が実ファイルに対して生きているかは見たい。
- 対応内容: 指標を `safeCalls`（Carbon 許可形の件数）から
  **`methodCalls`（`->name(` 形のメソッド呼び出しを何件見たか）**へ変更した。
  - 実コードの Carbon 使用有無に**依存しない**（Codex の懸念を解消）
  - かつ実ファイル走査が生きていることを証明する（元の意図も維持）
  - プロトタイプ実測で `methodCalls=24486`。ゼロになる現実的経路が無く頑健
  fixture の負/正コントロールによる担保はそのまま維持している。

## 施策 1 [Warning] `$date->{$method}()` の動的呼び出しが完全スルーで規約回避経路が残る

- 判断: **部分的に対応する（deny-by-default 全面適用は反論）**
- 根拠: 実測でスコープを測った。走査対象（app/ database/ tests/）の動的メソッド呼び出しは
  **全 5 件（コメント 1 件を除くと実質 4 件）**で、内訳は
  - `->{$method}($uri)` … HTTP verb を回す 2FA テスト
  - `->{$state}()` … factory state を回す課金テスト 3 件
  いずれも**日付と無関係**。ここで全 dynamic dispatch を deny-by-default にすると、
  Carbon gate が無関係な 4 件を人質に取り、今後 factory state テストを書くたびに
  Carbon gate の allowlist を触らせることになる。**gate の責務外への越境**であり、
  「やたらに複雑な案を提案する」（禁止事項）にも触れる。
- 対応内容: 回避経路のうち**静的に決定できるものだけ**を塞いだ:
  - `->{'addMonth'}()` / `->{"subYears"}()` （**literal 文字列**の動的呼び出し）を
    deny 対象に追加。実測 0 件なので allowlist コストはゼロ
  - 変数形 `->{$method}()` は**本 gate の明示的な限界**としてテスト冒頭コメントに
    理由付きで明記（「変数に 'addMonth' を入れて日付を進める」は実測 0 件かつ
    通常のコードレビューで自明に不自然 = 現実的な脅威ではない）
  - 負のコントロール「literal 文字列の動的メソッド呼び出しも検出する」を追加
- 検証: プロトタイプ実走で literal 動的形 2/3 検出（NoOverflow は正しく許可）PASS

## 施策 6 [Warning] `setPrivateTitle` 判定が「識別子の存在」だけで偽陰性リスク

- 判断: **対応する**
- 根拠: 指摘は正しい。しかもこの偽陰性は**取りこぼす方向**（タイトル未供給を
  「供給済み」と誤判定）に倒れるため、gate の失敗として最悪。
  callable 参照 `[$seo, 'setPrivateTitle']` や変数名でも通ってしまう。
- 対応内容: `documentTitleBodyHasIdentifier()` を
  **`documentTitleBodyCallsMethod()`** に置き換え、
  `->setPrivateTitle(` / `?->setPrivateTitle(` の**呼び出しトークン列**に限定した。
  既存 `ScenarioWritePathInventoryTest::containsMethodCall()` と同じ判定形であり、
  既存作法とも整合する。呼び出し側 3 箇所と fixture テストも追随させた。

## 施策 6 [Warning] `Inertia::render` 判定で `(` を確認していない

- 判断: **対応する**
- 根拠: 指摘のとおり。`[Inertia::class, 'render']` のような callable 参照や
  `Inertia::render` 単独参照を誤検出しうる（Inertia を描画しない route を
  候補に入れてしまう = 偽陽性）。
- 対応内容: `render` の直後に `(` があることを必須にした。
  コメントに「callable 参照を誤検出しない」理由も明記。

## 施策 6 [Suggestion] メソッド名解決を小文字正規化して PHP の case-insensitive 仕様に揃える

- 判断: **対応する**
- 根拠: route action 文字列（`Class@Index`）と宣言（`function index`）の case が
  揃っていなくても PHP は解決する。case 一致前提だと
  「メソッドを解決できない」→ 誤って unresolvable 扱いになる。
- 対応内容: `documentTitleMethodRanges()` の**キーを小文字化**し、
  参照側も `strtolower($method)`（`$methodKey`）で引くよう統一。
  1 hop 追跡の callee 解決も同様に小文字化。fixture テストのキーも追随させた。

## 施策 9 [Warning] `meta[name="description"]` の regex が限定的で抜け道が残る

- 判断: **対応する（AST 切替は反論）**
- 根拠: 無引用属性値（`name=description`）は HTML として有効なので抜け道になる —
  指摘は正しい。一方 **Svelte AST 解析への切替は過大**:
  この gate は「ページに第二 SoT を作らせない」ための deny-by-default で、
  対象は `resources/js/pages/**/*.svelte` の `<svelte:head>` ブロックのみ。
  AST パーサ（`svelte/compiler`）を architecture テストに持ち込むと
  Svelte のバージョン更新で gate が壊れる依存を増やす。
  「今必要なものだけ作る」原則に照らして regex 拡張で足りる。
- 対応内容:
  - regex を `/<meta\b[^>]*\bname\s*=\s*(?:"description"|'description'|description\b)/i` に拡張
    （ダブル・シングル・**無引用**の 3 形態をカバー。`\b` で `descriptionfoo` を弾く）
  - 式・スプレッド属性（`<meta name={x}>` / `<meta {...attrs}>`）は
    「description でないと静的に証明できない」ので **deny-by-default で fail**
    （`meta[dynamic-attr]`）。実測 0 件なのでコストはゼロで、
    「静的に判定できないものを黙って通さない」という他 gate と同じ規律に揃う
  - 負のコントロールに無引用 1 件・式/スプレッド 2 件を追加（計 8 ケース）
  - 正のコントロールに `name=descriptionfoo`（無引用の紛らわしい語）を追加（計 7 ケース）
- 検証: **全 15 ケースを Node で実走して ALL OK を確認済み**

## 施策 8 [Suggestion] 文言を h1 と完全一致にするか、差異理由をコメントで固定する

- 判断: **対応する（差異理由をコメントで固定する側を採用）**
- 根拠: h1 は「**この**招待リンクは使用できません」で、指示語「この」はタブ title には不要。
  一方 `config/seo.php` には「h1 見出しと一致させる」規約があるため、
  理由を残さないと後から「不一致だから直そう」と揺り戻される。
- 対応内容: controller のコードコメントに
  「h1 から指示語を落とした形」「意図的な短縮」「文言変更時は h1 も追随させる」を明記。
  詳細設計の該当節にもコメント例を掲載した。

## 施策 2 / 3 / 5 / 7 / 10 (APPROVE)

- 判断: 変更なし


---

## 修正後の詳細設計 (全文)

# 詳細設計: architecture-gate-followup (c2c 台帳 4 件の統合バッチ)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
  - ただし `tests/Architecture` は `->in('Feature', 'Unit')` の対象外 = **DB を使わない**
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### 本バッチ固有の検証手順（環境制約）

この devcontainer に **PostgreSQL は無い**（`DB_HOST=db` は未起動の compose サービス）。
`scripts/run-test.sh` は起動直後の ensure-test-db で fail するため `composer test` は使えない。

| レーン | この環境での検証コマンド | 備考 |
|---|---|---|
| Architecture (新設 3 本) | `bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/<Test>.php` | **実測 green 確認済み**。DB 非依存 |
| JS architecture (新設 1 本) | `pnpm test`（`scripts/run-vitest.sh`） | DB 非依存 |
| Feature (施策 9 の追加テスト) | **この環境では実行不能**。DB のある CI で走らせる | 実行不能は書かない理由にならない |
| PHPStan | `composer phpstan` | DB 非依存 |

`vendor/bin/pest` の直叩きは `docs/template-divergence.md` D10 で「ロック規約に参加しない」
と明記されているため、**必ず `scripts/with-global-test-lock.sh` で包む**こと。

## 概念設計リファレンス

- [devnotes/20260805-0101-architecture-gate-followup/conceptual-design.md](./conceptual-design.md)
  （Codex 概念設計レビュー **APPROVED (Round 4)**）

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | Carbon overflow gate 新設 | `tests/Architecture/CarbonOverflowArithmeticGateTest.php` (新規) | 高 |
| 2 | Carbon 既存違反 8 箇所の `*NoOverflow` 置換 | `database/seeders/BughuntOAuthSeeder.php` / `tests/Feature/Billing/{SubscriptionSnapshotSyncTest,PersonalPlanServiceTest,TicketVolumeTierTest,SendBillingRemindersTest}.php` | 高 |
| 3 | AGENTS.md 実装規約への 1 行追記 | `AGENTS.md` | 高 |
| 4 | 非複合 global use gate 新設 | `tests/Architecture/NoNonCompoundGlobalUseTest.php` (新規) | 高 |
| 5 | migration 2 件の `use RuntimeException;` 除去 | `database/migrations/2026_07_13_180622_*.php` / `2026_07_17_000610_*.php` | 高 |
| 6 | ページタイトル網羅 gate 新設 | `tests/Architecture/DocumentTitleCoverageTest.php` (新規) | 高 |
| 7 | `config/seo.php` へ不足 4 route の固有名を追加 | `config/seo.php` | 高 |
| 8 | 招待無効分岐の専用タイトル (3b) | `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` / `tests/Feature/Organization/InvitationTest.php` | 中 |
| 9 | `<svelte:head>` 二重 SoT 禁止 gate 新設 | `tests/js/architecture/svelte-head-no-title.test.ts` (新規) | 高 |
| 10 | テンプレート差分レジストリへ D11 登録 | `docs/template-divergence.md` | 中 |

---

## 施策 1: Carbon overflow gate 新設

### 変更箇所

- ファイル: `tests/Architecture/CarbonOverflowArithmeticGateTest.php`（**新規**）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイル自体。既存テストの変更なし（施策 2 で別途置換）

### 設計の要点

**deny 集合（全小文字・完全一致 12 個）**

```
addmonth  addmonths  submonth  submonths
addyear   addyears   subyear   subyears
addquarter addquarters subquarter subquarters
```

**なぜ小文字化して比較するか（実測根拠）**

`2026-01-31` 起点で計測:

| 呼び出し | 結果 |
|---|---|
| `->addMonth()` | `2026-03-03`（溢れる） |
| `->addmonth()` | **`2026-03-03`（溢れる = すり抜けると実害）** |
| `->addmonths()` / `->addyear()` / `->addquarter()` / `->submonth()` / `->subyears()` | いずれも受理され溢れる |
| `->AddMonth()` / `->ADDMONTH()` | `UnknownMethodException`（Carbon が拒否） |
| `->addmonthnooverflow()` | `UnknownMethodException`（Carbon が拒否） |

全小文字の overflow 形が**実際に動く**ため case 無視は必須。
`*NoOverflow` / `*WithOverflow` は小文字化しても deny 集合と一致しないため、
case 無視にしても許可側を巻き込まない（安全側）。
既存 `InertiaRenderPageExistsInvariantTest::inertiaIsIdentifier()` が `strcasecmp`
を使う先例と同一の理由。

**`*WithOverflow` を許可する理由**: overflow が要件のときに**意図がコードに残る**
書き方を用意しておかないと、規約が「回避不能な禁止」になり抜け道（`->add('1 month')` 等）
へ逃げられる。明示 opt-in を正規経路にする。

**プロトタイプ実走結果（設計の検証済み）**

本節の検出器を一時プロトタイプで実走させ、期待どおりに動くことを確認した:

```
fixture テスト:
  負: 暗黙 7 種       violations 7/7   methodCalls 7/7   PASS
  負: 全小文字        violations 3/3   methodCalls 3/3   PASS
  負: literal 動的    violations 2/2   methodCalls 3/3   PASS  (NoOverflow は許可)
  正: NoOverflow/With violations 0/0   methodCalls 6/6   PASS
  正: コメント/文字列 violations 0/0   methodCalls 1/1   PASS
  正: プロパティ/static violations 0/0 methodCalls 0/0   PASS

実リポジトリ走査 (app/ + database/ + tests/):
  files=1060  methodCalls=24486  violations=8  ← 施策 2 の 8 件と完全一致
```

`methodCalls=24486` は「`->name(` 形の呼び出しを実際に見ている」ことの証明であり、
実コードの Carbon 使用有無に依存しない空振り検知指標として十分に頑健。

### 変更後コード

```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: 月 / 年 / 四半期の加減算に **暗黙 overflow メソッドを使わない**。
 *
 * SoT = Carbon 3 の既定挙動。`addMonth()` 等は「月末を超えた分だけ翌月へ溢れる」:
 *   2026-01-31 ->addMonth()           => 2026-03-03   (溢れる)
 *   2026-01-31 ->addMonthNoOverflow() => 2026-02-28
 *   2024-02-29 ->addYear()            => 2025-03-01
 *   2026-03-31 ->subMonth()           => 2026-03-03
 * 課金ドメイン (current_period_end / チケット有効期限 / signup grant マーカー) は月末起点の
 * 日付を構造的に扱いうるため、1 日ずれた期日は「請求周期が 1 日ずれる」形で顕在化し、
 * しかも **月末月にしか再現しない** = 発見が極めて遅れる。
 *
 * 契約: 既定は `*NoOverflow`。overflow が要件なら `*WithOverflow` を明示して意図を残す
 * (`->addMonthWithOverflow()`)。暗黙形 (`->addMonth()`) だけを fail させる。
 * AGENTS.md 実装規約と 1:1 で対応する。
 *
 * 検出方式: PhpToken::tokenize で `->` / `?->` の直後の T_STRING を見る。
 * コメント・文字列リテラル中の `->addMonth()` という **記述** を誤検出しないために
 * regex ではなく token 走査を使う (負のコントロールで固定)。
 *
 * 識別子比較は **小文字化して完全一致**:
 *   - PHP のメソッド解決は case-insensitive で、実測上 `->addmonth()` は受理され溢れる
 *     (`->AddMonth()` は Carbon が拒否)。case 一致で判定すると全小文字形がすり抜ける
 *   - 完全一致にすることで `addMonthNoOverflow` / `addMonthsWithOverflow` を
 *     前方一致で巻き込まない (小文字化しても deny 集合と一致しない = 安全側)
 *
 * 動的メソッド呼び出しの扱い:
 *   - `->{'addMonth'}()` (literal 文字列) は **静的に決定できるので検出する**
 *     (gate の literal 回避を塞ぐ。実測で現状 0 件なので追加コストなし)
 *   - `->{$method}()` / `->$prop()` (変数形) は静的に決定できないため **対象外**。
 *     これは本 gate の明示的な限界。全 dynamic dispatch を deny-by-default にする案は
 *     採らない — 走査対象に日付と無関係な dynamic dispatch が実在し
 *     (HTTP verb テスト `->{$method}($uri)`、factory state `->{$state}()` の 4 件)、
 *     Carbon gate がそれらを人質に取るのは責務外だから。
 *     「変数に 'addMonth' を入れて日付を進める」書き方は現実的な脅威ではない
 *     (実測 0 件、かつ通常のコードレビューで自明に不自然)
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 暗黙 overflow するメソッド名 (全小文字)。
 *
 * @var list<string>
 */
const CARBON_OVERFLOW_DENIED_METHODS = [
    'addmonth', 'addmonths', 'submonth', 'submonths',
    'addyear', 'addyears', 'subyear', 'subyears',
    'addquarter', 'addquarters', 'subquarter', 'subquarters',
];

/**
 * 動的メソッド名のうち **literal 文字列** で書かれたもの (`->{'addMonth'}()`) は
 * 静的に決定できるため deny 対象に含める。変数による動的ディスパッチ
 * (`->{$method}()`) は静的に決定できず、本 gate の**明示的な限界**として扱う
 * (理由はテスト冒頭コメント参照)。
 */
const CARBON_OVERFLOW_DYNAMIC_LITERAL_ENABLED = true;

/**
 * 走査対象 (app/ + database/ + tests/) の PHP ファイル一覧。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function carbonOverflowScanTargets(): array
{
    $root = base_path();
    $files = [];
    foreach (['app', 'database', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $files[] = [
                'absolute' => $absolute,
                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
            ];
        }
    }

    return $files;
}

/** index 以降で最初の significant token の index。 @param list<PhpToken> $tokens */
function carbonOverflowNextSignificant(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * literal 文字列トークン (`'addMonth'` / `"addMonth"`) の中身を返す。literal でなければ null。
 */
function carbonOverflowLiteralValue(string $raw): ?string
{
    if (preg_match('/\A[bB]?([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\z/', $raw, $m) !== 1) {
        return null;
    }

    return $m[2];
}

/**
 * 1 ファイル分の PHP ソースから月/年/四半期系のメソッド呼び出しを収集する (純関数)。
 *
 * `methodCalls` は「`->name(` 形のメソッド呼び出しを何件見たか」。
 * 空振り検知に使う (実コードの Carbon 使用有無に依存しない指標)。
 *
 * @return array{violations: list<string>, methodCalls: int}
 */
function carbonOverflowCollectFromSource(string $source, string $relative): array
{
    $violations = [];
    $methodCalls = 0;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            continue;
        }
        $nameIndex = carbonOverflowNextSignificant($tokens, $i + 1);
        if ($nameIndex === null) {
            continue;
        }

        $name = null;

        if ($tokens[$nameIndex]->is(T_STRING)) {
            // 通常形 `->addMonth(`。メソッド呼び出し (プロパティアクセスではない) に限る
            $parenIndex = carbonOverflowNextSignificant($tokens, $nameIndex + 1);
            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
                continue;
            }
            $methodCalls++;
            $name = $tokens[$nameIndex]->text;
        } elseif ($tokens[$nameIndex]->text === '{') {
            // 動的形 `->{...}(`。**literal 文字列**なら静的に決定できるので対象に含める
            // (`->{'addMonth'}()` による gate 回避を塞ぐ)。変数形は決定できないので対象外。
            $inner = carbonOverflowNextSignificant($tokens, $nameIndex + 1);
            if ($inner === null || ! $tokens[$inner]->is(T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }
            $close = carbonOverflowNextSignificant($tokens, $inner + 1);
            if ($close === null || $tokens[$close]->text !== '}') {
                continue;
            }
            $parenIndex = carbonOverflowNextSignificant($tokens, $close + 1);
            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
                continue;
            }
            $methodCalls++;
            $name = carbonOverflowLiteralValue($tokens[$inner]->text);
        }

        if ($name === null) {
            continue;
        }

        if (in_array(strtolower($name), CARBON_OVERFLOW_DENIED_METHODS, true)) {
            $violations[] = "{$relative}:{$tokens[$nameIndex]->line} → ->{$name}()";
        }
    }

    return ['violations' => $violations, 'methodCalls' => $methodCalls];
}

/**
 * app/ + database/ + tests/ 全体の収集結果。
 *
 * @return array{violations: list<string>, methodCalls: int, files: int}
 */
function carbonOverflowCollectAll(): array
{
    $violations = [];
    $methodCalls = 0;
    $files = 0;

    foreach (carbonOverflowScanTargets() as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $files++;
        $collected = carbonOverflowCollectFromSource($source, $target['relative']);
        $violations = array_merge($violations, $collected['violations']);
        $methodCalls += $collected['methodCalls'];
    }

    return ['violations' => $violations, 'methodCalls' => $methodCalls, 'files' => $files];
}

test('月/年/四半期の加減算に暗黙 overflow メソッドを使っていない', function (): void {
    $result = carbonOverflowCollectAll();

    expect($result['violations'])->toBe([],
        '暗黙 overflow するメソッドを検出しました。既定は *NoOverflow を使い、'
        .'overflow が要件なら *WithOverflow を明示してください (AGENTS.md 実装規約)。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (対象ファイル > 0 かつメソッド呼び出しを実際に見ている)', function (): void {
    $result = carbonOverflowCollectAll();

    // ディレクトリ構成変更や走査基盤の破損で「0 件検査して green」になる退行を落とす。
    expect($result['files'])->toBeGreaterThan(0);
    // `->name(` 形のメソッド呼び出し件数。実コードの Carbon 使用有無に依存しない指標なので、
    // 将来 Carbon 呼び出しが 0 になっても誤落ちしない。0 なら token 走査が死んでいる。
    expect($result['methodCalls'])->toBeGreaterThan(0);
});

/*
 * 負のコントロール: 実ファイルを書き換えず fixture ソースに対して gate が点灯することを確認する。
 * app/ 本体の違反は 0 件 (= 予防 gate) のため、ここが空振りでないことの唯一の担保になる。
 */
test('負のコントロール: 暗黙 overflow メソッドを検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Carbon\CarbonImmutable;
    class Fixture {
        public function run(CarbonImmutable $at): void {
            $a = $at->addMonth();
            $b = $at->subMonths(2);
            $c = $at->addYear();
            $d = $at->subYears(1);
            $e = $at->addQuarter();
            $f = $at->subQuarters(3);
            $g = $at?->addMonths(1);
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toHaveCount(7);
    expect($result['methodCalls'])->toBe(7);
});

/*
 * 負のコントロール: literal 文字列の動的呼び出し `->{'addMonth'}()` による gate 回避を塞ぐ。
 * (変数形 `->{$method}()` は静的に決定できないため本 gate の明示的な限界。冒頭コメント参照)
 */
test('負のコントロール: literal 文字列の動的メソッド呼び出しも検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run($at): void {
            $a = $at->{'addMonth'}();
            $b = $at->{"subYears"}(2);
            $c = $at->{'addMonthNoOverflow'}();
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toHaveCount(2); // NoOverflow は許可
    expect($result['methodCalls'])->toBe(3);
});

/*
 * 負のコントロール: PHP のメソッド解決は case-insensitive で、実測上 `->addmonth()` は
 * Carbon に受理され実際に溢れる。case 一致で判定していると全小文字形がすり抜ける。
 */
test('負のコントロール: 全小文字の呼び出しも検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run($at): void {
            $a = $at->addmonth();
            $b = $at->submonths(2);
            $c = $at->addyear();
        }
    }
    PHP;

    expect(carbonOverflowCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

test('正のコントロール: NoOverflow / WithOverflow 形は検出せず、許可形として数える', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run($at): void {
            $a = $at->addMonthNoOverflow();
            $b = $at->subMonthsNoOverflow(2);
            $c = $at->addYearNoOverflow();
            $d = $at->addQuartersNoOverflow(1);
            $e = $at->addMonthWithOverflow();
            $f = $at->addMonthsWithOverflow(2);
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toBe([]);
    expect($result['methodCalls'])->toBe(6);
});

/*
 * 正のコントロール: コメント / 文字列リテラル中の記述は「コード」ではないので検出しない。
 * これが regex ではなく PhpToken を使う理由そのもの。
 */
test('正のコントロール: コメント・文字列中の記述を誤検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        /** 使用禁止: $at->addMonth() は溢れるので addMonthNoOverflow を使うこと */
        public function run($at): void {
            // $at->addYear() と書くと 2/29 起点で 3/1 になる
            $doc = '呼び出し例: $at->subQuarter()';
            $heredoc = <<<'INNER'
            $at->addMonths(3)
            INNER;
            $ok = $at->addMonthNoOverflow();
        }
    }
    PHP;

    $result = carbonOverflowCollectFromSource($fixture, 'fixture.php');
    expect($result['violations'])->toBe([]);
    expect($result['methodCalls'])->toBe(1);
});

/*
 * 正のコントロール: 同名でも「メソッド呼び出しでない」ものは対象外。
 * プロパティアクセス・静的呼び出し・関数定義を巻き込まないことを固定する。
 */
test('正のコントロール: プロパティアクセスや別文脈の同名識別子は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public $addMonth = 1;
        public function addMonth(): void {}
        public function run($at): void {
            $x = $this->addMonth;          // プロパティ (括弧なし)
            $y = ['addMonth' => 1];        // 配列キー
            $z = Helper::addMonth($at);    // static (:: は対象外)
        }
    }
    PHP;

    expect(carbonOverflowCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（全ヘルパに `: array` / `: ?int` + PHPDoc array shape）
- [x] null 安全（`file_get_contents` / `getRealPath` の `is_string` ガード、
      `carbonOverflowNextSignificant` の `?int` を毎回 null チェック）
- [x] DTO を返している（**該当なし** — テスト内純関数のため array shape で表す。
      `InertiaRenderPageExistsInvariantTest` の既存作法どおりテスト専用 DTO は作らない）
- [x] Generics の型パラメータが正しい（`list<PhpToken>` / `list<string>` /
      `list<array{absolute: string, relative: string}>`）

### テスト計画

- [x] バグ修正ではなく予防 gate。**負のコントロールを先に書いて fail を確認**してから
      実ファイル走査を有効化する（AGENTS.md 思考原則 5「テストファースト」）
- [x] 新規テスト: 「暗黙 overflow メソッドを使っていない」— 実ファイル走査（施策 2 適用後 green）
- [x] 新規テスト: 「走査が空振りしていない」— files > 0 かつ safeCalls > 0
- [x] 新規テスト: 負のコントロール 3 本（暗黙形 7 種 / 全小文字 3 種 /
      **literal 文字列の動的呼び出し** `->{'addMonth'}()`）
- [x] 新規テスト: 正のコントロール 3 本（NoOverflow・WithOverflow / コメント・文字列 /
      プロパティ・static）
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Architecture` は DB 不使用）
- 検証: `bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/CarbonOverflowArithmeticGateTest.php`

### リスク

- **自己検出のリスク**: 本テストファイル自身が `tests/` 配下にあり deny 名を含む。
  ただし fixture は nowdoc (`<<<'PHP'`) なので本体が単一の
  `T_ENCAPSED_AND_WHITESPACE` トークンになり、`->addMonth` の
  `T_OBJECT_OPERATOR` + `T_STRING` 列にはならない。deny 集合の定義も
  `T_CONSTANT_ENCAPSED_STRING` であり `->` の直後ではない。**自己検出しない**
  （これも「正のコントロール: コメント・文字列中の記述」テストが同時に保証する）
- **走査コスト**: `app/` + `database/` + `tests/` の PHP を毎テストで 3 回
  (`carbonOverflowCollectAll()` を 2 テストが呼ぶ) tokenize する。
  既存 `InertiaRenderPageExistsInvariantTest` が同様の走査を 4 テストで行って
  実用時間に収まっている実績があるため許容範囲と判断する

---

## 施策 2: Carbon 既存違反 8 箇所の `*NoOverflow` 置換

### 変更箇所

| ファイル | 行 | 現行 | 変更後 |
|---|---|---|---|
| `database/seeders/BughuntOAuthSeeder.php` | 200 | `now()->addYear()` | `now()->addYearNoOverflow()` |
| `database/seeders/BughuntOAuthSeeder.php` | 224 | `now()->addYear()` | `now()->addYearNoOverflow()` |
| `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` | 83 | `CarbonImmutable::now()->addMonth()` | `->addMonthNoOverflow()` |
| `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` | 171 | `CarbonImmutable::now()->addMonth()` | `->addMonthNoOverflow()` |
| `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` | 281 | `CarbonImmutable::now()->addMonth()` | `->addMonthNoOverflow()` |
| `tests/Feature/Billing/PersonalPlanServiceTest.php` | 149 | `now()->subYear()` | `now()->subYearNoOverflow()` |
| `tests/Feature/Billing/TicketVolumeTierTest.php` | 74 | `CarbonImmutable::now()->subYear()` | `->subYearNoOverflow()` |
| `tests/Feature/Billing/SendBillingRemindersTest.php` | 116 | `now()->addMonth()` | `now()->addMonthNoOverflow()` |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 上記 4 本が変更対象そのもの。**新規テストの追加は不要**
  （施策 1 の gate が回帰を防ぐ）

### 「月末起点で期待日付が 1 日ずれうる」点の個別評価

概念設計で挙げた注意点を、8 箇所すべてについて実コードを読んで確認した結果:

| 箇所 | 用途 | 日付ずれの影響 |
|---|---|---|
| `BughuntOAuthSeeder:200,224` | bughunt 用 OAuth トークンの `expires_at`（**遠い未来の失効日**） | **なし**。「1 年後のいずれか」であればよく、日付は検証されない |
| `SubscriptionSnapshotSyncTest:83` | `$periodEnd` を組み立て、同じ変数を snapshot に渡して同期結果を検証 | **なし**。生成側と期待側が**同一の値**（変数を使い回す） |
| `SubscriptionSnapshotSyncTest:171` | 既存 `current_period_end` を作り「period 欠落 snapshot でも維持される」ことを検証 | **なし**。同上（`$existingPeriodEnd` を使い回す） |
| `SubscriptionSnapshotSyncTest:281` | webhook payload の `current_period_end` タイムスタンプ。assert は `->not->toBeNull()` | **なし**。日付そのものを assert していない |
| `PersonalPlanServiceTest:149` | `signup_tickets_granted_at` に「マーカー済み」を表す**遠い過去**の値を入れる | **なし**。「過去であること」だけが意味を持つ |
| `TicketVolumeTierTest:74` | 履歴行の `active_from`（`active_to` は `subDay()`。**遠い過去**） | **なし**。`is_current=false` の履歴行を作るだけ |
| `SendBillingRemindersTest:116` | `$periodEnd` を組み立て、`$periodEnd->getTimestamp()` と突き合わせる | **なし**。生成側と期待側が**同一の値** |

**結論: 8 箇所すべて日付ずれの影響を受けない。**
危険なのは「片側だけを置換して生成側と期待側の計算式が食い違う」パターンだが、
本施策は**ファイル内の全該当箇所を一括置換**するため発生しない。

### 現行コード / 変更後コード（代表例）

```php
// database/seeders/BughuntOAuthSeeder.php:200 (現行)
'expires_at' => now()->addYear(),

// 変更後
'expires_at' => now()->addYearNoOverflow(),
```

```php
// tests/Feature/Billing/SendBillingRemindersTest.php:116 (現行)
$periodEnd = now()->addMonth()->startOfSecond();

// 変更後
$periodEnd = now()->addMonthNoOverflow()->startOfSecond();
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（呼び出し側の変更のみ。`*NoOverflow` は
      Carbon の `__call` 経由だが PHPStan の carbon 拡張が解決する。
      解決できない場合でも `CarbonImmutable` を返す点は同一で型は変わらない）
- [x] null 安全（変更なし）
- [x] DTO を返している（該当なし）
- [x] Generics（該当なし）

### テスト計画

- [x] 既存テスト `tests/Feature/Billing/*.php` の更新（本施策そのもの）
- [x] 置換後も既存テストが green であること（**この環境では実行不能** = DB 不在。
      CI の Feature レーンで確認する）
- [x] 施策 1 の gate が「違反 0 件」で green になることで置換漏れを機械検出する
      （`vendor/bin/pest tests/Architecture/CarbonOverflowArithmeticGateTest.php` は
      この環境でも実行できるため、**置換漏れは devcontainer 内で検証可能**）
- [x] 個別の `DatabaseTransactions` を使っていない（既存ファイルにも無い）

### リスク

- **`BughuntOAuthSeeder` は bughunt 環境専用**（AGENTS.md §bug-hunt の三重 fail-secure
  ガード下で fake_externals + bughunt.local + `^bug_hunt(_[1-8])?$` のときのみ動く）。
  dev/本番へは影響しない
- **PHPStan が `addYearNoOverflow` を解決できない可能性**: 万一 unknown method に
  なっても、**widen / baseline 化は禁止**（禁止事項 2）。その場合は
  `Carbon\CarbonImmutable` の型を明示するなど**型を狭める方向**で解決する

---

## 施策 3: AGENTS.md 実装規約への 1 行追記

### 変更箇所

- ファイル: `AGENTS.md` の「## 実装規約」セクション（L58〜74）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / テストファイル: なし
  （規約文書のみ。強制は施策 1 の gate が担う）

### 現行コード

```markdown
## 実装規約

- `declare(strict_types=1)` + 日本語コメント。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
```

### 変更後コード

```markdown
## 実装規約

- `declare(strict_types=1)` + 日本語コメント。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
- 月 / 年 / 四半期の加減算は**暗黙 overflow メソッドを禁止**する。既定は
  `addMonthNoOverflow` / `subYearNoOverflow` 等の `*NoOverflow`、overflow が要件なら
  `*WithOverflow` を明示して意図をコードに残す(`addMonth()` は 1/31 → 3/3 と溢れる。
  `CarbonOverflowArithmeticGateTest` が検出)
```

**「`*NoOverflow` 必須」と書かない理由**: gate は `*WithOverflow` を許可する。
規約文が「必須」だと「規約違反だが gate は通る」状態が生まれ、規約と機械保証が乖離する。
**規約と gate の契約は 1:1 に保つ**（概念設計レビュー Round 1 [Warning] 対応）。

### PHPStan 適合チェック

- 該当なし（Markdown）

### テスト計画

- [x] `AppNameHardcodeTest` 等の既存ドキュメント系 Architecture テストに抵触しないこと
- [x] 規約の実効性は施策 1 の gate が担保する（文書だけで終わらせない = 禁止事項 1）

### リスク

- なし（追記のみ。既存記述は変更しない）

---

## 施策 4: 非複合 global use gate 新設

### 変更箇所

- ファイル: `tests/Architecture/NoNonCompoundGlobalUseTest.php`（**新規**）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本ファイル自体。既存テストの変更なし

### 設計の要点

**なぜ危険か（実測で台帳の前提を更新した）**

台帳には「コンパイル時に出るため `set_error_handler` を経由しない」と記録されていたが、
本 devcontainer での実測では **経路が環境依存で両方起きる**:

| 実測 | 結果 |
|---|---|
| `php -r 'set_error_handler(...); require <migration の実パス>;'` | handler 不発火・raw warning が stderr へ |
| 同内容を別パスへコピーして require | **handler が発火** |

そして `vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/HandleExceptions.php`
の `handleError()` は

```php
} elseif (error_reporting() & $level) {
    throw new ErrorException($message, 0, $level, $file, $line);
}
```

であり、本アプリの実行時 `error_reporting()` は **-1（全部）**（実測）。
つまり **handler に届いた場合、この warning は `ErrorException` として throw される**。
migration は Migrator が実行時に `require` するため、そこで throw されれば
`RefreshDatabase` のマイグレーションが死に、**全テストが全滅する**（aigenba の実事故と同型）。

位置づけは「今日たまたま raw output 汚染で済んでいるが、PHP バージョン / opcache 状態 /
ハンドラ導入タイミングが変われば全テスト全滅へ化ける、**非決定的な地雷**」。

**なぜ `use function` / `use const` も対象か（実測根拠）**

namespace 無しファイルでの実測:

```
use function strlen;      → Warning: The use statement with non-compound name 'strlen' has no effect
use const PHP_VERSION;    → Warning: The use statement with non-compound name 'PHP_VERSION' has no effect
use RuntimeException;     → Warning: The use statement with non-compound name 'RuntimeException' has no effect
use function Foo\bar;     → (警告なし。複合名なので正常)
```

**まったく同じ warning** が出るため、除外すると同じ地雷が別の綴りで残る。3 形態すべて対象。

**なぜ `git ls-files` か**

git 管理下に限ることで `vendor/` `node_modules/` `.claude/worktrees/` `storage/`
`public/build` を**自動的に**除外できる（明示 exclude リストの保守が要らない）。
`tests/js/architecture/pages-path-case-invariant.test.ts` が同じ理由で
`git ls-files` を使い、git 不在を silent skip せず fail させる先例がある。

**既知の限界**: 未追跡ファイルは走査されない。gate が守るべき境界は commit / CI で、
そこでは必ず追跡下にあるため実効性は損なわれないが、テスト冒頭コメントに明記する。

**プロトタイプ実走結果（設計の検証済み）**

名前正規化を入れた検出器を一時プロトタイプで実走させ、期待どおりに動くことを確認した:

```
fixture テスト:
  負: 3 形態 (class/function/const)      3/3  PASS
  負: カンマ区切り / as 別名             3/3  PASS
  負: 先頭 \ 付き 3 形態                 3/3  PASS  ← レビュー指摘の Critical
  正: 複合名 / group use / 先頭 \ 複合名 0/0  PASS
  正: trait use / クロージャ use          0/0  PASS
  正: namespace 付きファイル              scanned=false  PASS

実リポジトリ走査 (git ls-files '*.php' から blade 除外):
  total=1124  namespaceless=459  violations=2  ← 施策 5 の 2 件と完全一致
    database/migrations/2026_07_13_180622_...:7 → use RuntimeException;
    database/migrations/2026_07_17_000610_...:9 → use RuntimeException;
```

### 変更後コード

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: **namespace 宣言の無い PHP ファイル**の global スコープに
 * 非複合名の `use` を書かない。
 *
 * SoT = PHP の言語仕様。namespace 無しファイルでの非複合 use は
 *   Warning: The use statement with non-compound name 'X' has no effect
 * を出し、**import として何の効果も持たない** (参照は global にフォールバックする)。
 * `use function` / `use const` でも **まったく同じ warning** が出る (実測):
 *   use RuntimeException;   → Warning ...'RuntimeException'...
 *   use function strlen;    → Warning ...'strlen'...
 *   use const PHP_VERSION;  → Warning ...'PHP_VERSION'...
 *   use function Foo\bar;   → (複合名なので正常)
 *
 * なぜ「出力が汚れるだけ」で済ませないか (実測):
 *   - この warning が set_error_handler に届くかは **環境依存** (opcache 状態 /
 *     ファイルの初回コンパイル時点)。同一 devcontainer で「届く」「届かない」両方を観測した
 *   - 届いた場合、Laravel の HandleExceptions::handleError は
 *     `error_reporting() & $level` (本アプリは -1) で **ErrorException を throw する**
 *   - migration は Migrator が実行時に require する = そこで throw されれば
 *     RefreshDatabase が死に **全テストが全滅する**
 * つまり「今日は raw output 汚染で済んでいるが、いつ全滅へ化けてもおかしくない非決定的な地雷」。
 *
 * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。
 * git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 * **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * **既知の限界**: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
 * commit / CI であり、そこでは必ず追跡下にあるため実効性は損なわれない。
 * git 不在は環境不備として silent skip せず fail させる
 * (tests/js/architecture/pages-path-case-invariant.test.ts と同じ作法)。
 *
 * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
 */

/**
 * git 追跡下の PHP ソースファイル一覧 (blade を除く)。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function nonCompoundUseScanTargets(): array
{
    $root = base_path();
    $process = new Process(['git', 'ls-files', '-z', '*.php'], $root);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '' || str_ends_with($relative, '.blade.php')) {
            continue;
        }
        $absolute = $root.'/'.$relative;
        if (! is_file($absolute)) {
            continue; // 削除済みだが index に残っている等
        }
        $files[] = ['absolute' => $absolute, 'relative' => $relative];
    }

    return $files;
}

/** index 以降で最初の significant token の index。 @param list<PhpToken> $tokens */
function nonCompoundUseNextSignificant(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * 1 ファイル分の PHP ソースから global スコープの非複合 use を収集する (純関数)。
 *
 * 判定手順:
 *   1. T_NAMESPACE が出現するファイルは対象外 (PHP が warning を出さない = 実際に import される)
 *   2. brace depth を追跡し **depth 0 の T_USE** のみを見る (クラス本体の trait use を除外)
 *   3. `use` 直後の `function` / `const` 修飾は読み飛ばす (同じ warning が出るため対象)
 *   4. `(` が続くならクロージャの `use ($x)` なので対象外
 *   5. カンマ区切りの各要素について、`as` の前の import 名を **1 つの文字列に正規化**し、
 *      先頭の `\` を除いた残りに区切り `\` を含まなければ非複合 = 違反
 *
 * **名前の正規化が必須である理由 (実測)**: PHP は `use \RuntimeException;` のような
 * 先頭 `\` 付きの単一名も受理し、**まったく同じ warning を出す**:
 *   use \RuntimeException;    → Warning ...non-compound name 'RuntimeException'...
 *   use function \strlen;     → Warning ...non-compound name 'strlen'...
 *   use const \PHP_VERSION;   → Warning ...non-compound name 'PHP_VERSION'...
 * しかも tokenizer 上は **T_STRING ではなく T_NAME_FULLY_QUALIFIED** になる:
 *   `use \RuntimeException;`  → T_USE, T_NAME_FULLY_QUALIFIED('\RuntimeException'), ';'
 *   `use RuntimeException;`   → T_USE, T_STRING('RuntimeException'), ';'
 * よって「T_STRING かどうか」で判定すると **先頭 `\` 付きを丸ごと取りこぼす** (silent hole)。
 * token 種別ではなく **名前の中身 (セグメント数)** で判定する。
 *
 * @return array{violations: list<string>, scanned: bool}
 */
function nonCompoundUseCollectFromSource(string $source, string $relative): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    // 1. namespace 付きファイルは対象外
    foreach ($tokens as $token) {
        if ($token->is(T_NAMESPACE)) {
            return ['violations' => [], 'scanned' => false];
        }
    }

    $violations = [];
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token->text === '{') {
            $depth++;

            continue;
        }
        if ($token->text === '}') {
            $depth--;

            continue;
        }

        // 2. global スコープの use だけを見る
        if (! $token->is(T_USE) || $depth !== 0) {
            continue;
        }

        $cursor = nonCompoundUseNextSignificant($tokens, $i + 1);
        if ($cursor === null) {
            continue;
        }

        // 4. クロージャの `use ($x)` は import ではない
        if ($tokens[$cursor]->text === '(') {
            continue;
        }

        // 3. `use function` / `use const` の修飾を読み飛ばす (対象に含める)
        if ($tokens[$cursor]->is([T_FUNCTION, T_CONST])) {
            $next = nonCompoundUseNextSignificant($tokens, $cursor + 1);
            if ($next === null) {
                continue;
            }
            $cursor = $next;
        }

        // 5. カンマ区切りの各 import 要素を評価する。
        //    名前は「1 要素 = 1 文字列」に正規化してからセグメント数で判定する
        //    (T_STRING / T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED / T_NS_SEPARATOR 分割の
        //     いずれの tokenizer 表現でも同じ結論になる)。
        $name = '';
        $nameLine = 0;
        $collecting = true;

        /** 収集済みの名前を判定して violations へ積む。 */
        $flush = function () use (&$name, &$nameLine, &$violations, $relative): void {
            $normalized = ltrim($name, '\\');
            if ($normalized !== '' && ! str_contains($normalized, '\\')) {
                $violations[] = "{$relative}:{$nameLine} → use {$name};";
            }
            $name = '';
        };

        for ($j = $cursor; $j < $count; $j++) {
            $current = $tokens[$j];

            if ($current->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($current->text === ';') {
                $flush();
                break;
            }
            if ($current->text === ',') {
                $flush();
                $collecting = true;

                continue;
            }
            if ($current->is(T_AS)) {
                $flush();
                $collecting = false; // `as` 以降の別名は判定対象ではない

                continue;
            }
            // グループ use (`use A\B\{C, D};`) は prefix に必ず `\` を含むため非複合になりえない。
            if ($current->text === '{') {
                $name = '';
                break;
            }
            if (! $collecting) {
                continue;
            }
            if ($current->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                if ($name === '') {
                    $nameLine = $current->line;
                }
                $name .= $current->text;
            }
        }
    }

    return ['violations' => $violations, 'scanned' => true];
}

/**
 * git 追跡下全体の収集結果。
 *
 * @return array{violations: list<string>, namespacelessFiles: int, totalFiles: int}
 */
function nonCompoundUseCollectAll(): array
{
    $violations = [];
    $namespaceless = 0;
    $total = 0;

    foreach (nonCompoundUseScanTargets() as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $total++;
        $collected = nonCompoundUseCollectFromSource($source, $target['relative']);
        if ($collected['scanned']) {
            $namespaceless++;
        }
        $violations = array_merge($violations, $collected['violations']);
    }

    return [
        'violations' => $violations,
        'namespacelessFiles' => $namespaceless,
        'totalFiles' => $total,
    ];
}

test('namespace 無しファイルに非複合 global use が存在しない', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['violations'])->toBe([],
        '非複合 global use を検出しました。PHP は「has no effect」warning を出し import は無効です。'
        .'use 文を削除して参照側を \\FQCN (例: \\RuntimeException) にしてください。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (git 追跡 PHP > 0 かつ namespace 無しファイル > 0)', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['totalFiles'])->toBeGreaterThan(0);
    // database/migrations (60 本) や tests/Architecture など namespace 無しファイルは
    // 構造的に必ず存在する。0 なら namespace 判定が壊れている。
    expect($result['namespacelessFiles'])->toBeGreaterThan(0);
});

/*
 * 負のコントロール: 3 形態すべて (class / function / const) が実際に同じ warning を出すため、
 * 3 形態すべてを検出できることを fixture で固定する。
 */
test('負のコントロール: class / function / const の非複合 use を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    declare(strict_types=1);
    use RuntimeException;
    use function strlen;
    use const PHP_VERSION;
    return new class {};
    PHP;

    $result = nonCompoundUseCollectFromSource($fixture, 'fixture.php');
    expect($result['scanned'])->toBeTrue();
    expect($result['violations'])->toHaveCount(3);
});

test('負のコントロール: カンマ区切り / as 別名の非複合 use も検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use RuntimeException, LogicException;
    use InvalidArgumentException as Bad;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

/*
 * 負のコントロール: **先頭 `\` 付きの単一名**も PHP は同じ warning を出す (実測)。
 * tokenizer 上は T_STRING ではなく T_NAME_FULLY_QUALIFIED になるため、
 * token 種別で判定していると丸ごと取りこぼす (silent hole)。
 */
test('負のコントロール: 先頭バックスラッシュ付きの非複合 use も検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use \RuntimeException;
    use function \strlen;
    use const \PHP_VERSION;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

test('正のコントロール: 複合名 / グループ use / 先頭 \\ 付き複合名は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\{DB, Schema};
    use function Illuminate\Support\enum_value;
    use const Illuminate\Foundation\SOME_CONST;
    use App\Models\User as Account;
    use \Illuminate\Support\Str;
    use Illuminate\Support\Arr, Illuminate\Support\Collection;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});

/*
 * 正のコントロール: namespace 付きファイルの非複合 use は PHP が warning を出さない
 * (実際に import として機能する) ため対象外。scanned=false で走査自体をスキップする。
 */
test('正のコントロール: namespace 付きファイルは対象外', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Services;
    use RuntimeException;
    class Foo {}
    PHP;

    $result = nonCompoundUseCollectFromSource($fixture, 'fixture.php');
    expect($result['scanned'])->toBeFalse();
    expect($result['violations'])->toBe([]);
});

/*
 * 正のコントロール: クラス本体の trait use と、クロージャの use ($x) を誤検知しない。
 * brace depth 追跡が効いていることの証明。
 */
test('正のコントロール: trait use / クロージャ use を誤検知しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    return new class extends Migration {
        use SomeTrait;
        public function up(): void {
            $x = 1;
            $fn = function () use ($x) { return $x; };
            $arrow = fn () => $x;
        }
    };
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（全ヘルパに `: array` / `: ?int` + PHPDoc array shape）
- [x] null 安全（`nonCompoundUseNextSignificant` の `?int` を毎回チェック、
      `file_get_contents` の `is_string` ガード）
- [x] DTO を返している（該当なし — テスト内純関数。array shape で表す）
- [x] Generics の型パラメータが正しい（`list<PhpToken>` /
      `list<array{absolute: string, relative: string}>`）
- [x] **本テスト自身が gate に違反しない**: `use Symfony\Component\Process\Process;` は
      複合名。`throw new RuntimeException(...)` は use せず global 解決される
      （本ファイルは namespace 無し = global namespace なのでそのまま `RuntimeException` で解決される）

### テスト計画

- [x] バグ修正: **再現テスト（負のコントロール）を先に書く**。fixture で
      3 形態すべてが検出されることを確認してから実ファイル走査を有効化する
- [x] 新規テスト: 「非複合 global use が存在しない」— 実ファイル走査（施策 5 適用後 green）
- [x] 新規テスト: 「走査が空振りしていない」— totalFiles > 0 かつ namespacelessFiles > 0
- [x] 新規テスト: 負のコントロール 3 本（3 形態 / カンマ・as /
      **先頭 `\` 付き 3 形態**）
- [x] 新規テスト: 正のコントロール 3 本（複合名・グループ・先頭 `\` 複合名 /
      namespace 付き / trait use・クロージャ use）
- [x] 個別の `DatabaseTransactions` を使っていない
- 検証: `bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/NoNonCompoundGlobalUseTest.php`

### リスク

- **`Process` の使用**: `symfony/process` は Laravel の必須依存なので追加インストール不要。
  代替として `exec()` も使えるが、`Process` のほうが失敗検知が明示的
  （`isSuccessful()` + `getErrorOutput()`）
- **worktree 内での `git ls-files`**: worktree でも正しく動く（`.git` file 経由で
  リポジトリを解決する）。`scripts/setup-worktree.sh` が作る
  `.claude/worktrees/tasks/<id>` でも問題ない
- **`return new class extends Migration` の brace 追跡**: 匿名クラス本体の `{` は
  depth を上げるため、内部の `use SomeTrait;` は depth ≥ 1 で除外される。
  正のコントロールで固定する

---

## 施策 5: migration 2 件の `use RuntimeException;` 除去

### 変更箇所

| ファイル | 変更 |
|---|---|
| `database/migrations/2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php` | L7 の `use RuntimeException;` を削除。L32 / L40 の `new RuntimeException(` を `new \RuntimeException(` へ |
| `database/migrations/2026_07_17_000610_create_ticket_auto_recharge_attempts_table.php` | L9 の `use RuntimeException;` を削除。L47 の `new RuntimeException(` を `new \RuntimeException(` へ |

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: **なし**（migration の挙動は不変。施策 4 の gate が回帰を防ぐ）

### 現行コード

```php
// database/migrations/2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;          // ← L7: 非複合 global use (無効 + warning)

// ...
            throw new RuntimeException(
// ...
            throw new RuntimeException("部分 UNIQUE index 未対応の driver: {$driver} (pgsql/sqlite のみ対応)");
```

### 変更後コード

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
// use RuntimeException; は削除 (namespace 無しファイルでは無効な import で warning を出す)

// ...
            throw new \RuntimeException(
// ...
            throw new \RuntimeException("部分 UNIQUE index 未対応の driver: {$driver} (pgsql/sqlite のみ対応)");
```

**なぜ `\` を明示するか**: このファイルは namespace 無し = global namespace なので
`new RuntimeException(...)` のままでも動く。だが `\` を明示することで
「global の組み込み例外を指している」ことがコードから読み取れ、将来この
ファイルに namespace が付いたときに壊れない（防御的な明示）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（変更なし）
- [x] null 安全（変更なし）
- [x] DTO を返している（該当なし）
- [x] Generics（該当なし）
- [x] **PHPStan が `\RuntimeException` を解決できる**（組み込みクラス）

### テスト計画

- [x] バグ修正: 施策 4 の gate が**先に fail することを確認**してから修正する
      （テストファースト。gate → 実ファイル走査が 2 件 fail → 修正 → green）
- [x] 既存テストの更新: **不要**（migration の実行結果は不変。
      `throw` される例外クラスも `RuntimeException` のまま同一）
- [x] 新規テスト: 不要（施策 4 の gate が恒久回帰テスト）
- [x] 個別の `DatabaseTransactions` を使っていない
- 検証: `bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/NoNonCompoundGlobalUseTest.php`
  が green になること。migration 自体の実行は CI の Feature レーン（`RefreshDatabase`）で確認

### リスク

- **migration ファイルの変更は「実行済み migration の書き換え」にあたる**が、
  変更内容は import 文と例外クラスの参照表記のみで **schema 差分は一切生じない**。
  実行済み環境への影響はない
- **`use RuntimeException;` を消し忘れて `new RuntimeException` だけ残す**逆パターンは
  施策 4 の gate が検出する（use が残っていれば fail）

---

## 施策 6: ページタイトル網羅 gate 新設

### 変更箇所

- ファイル: `tests/Architecture/DocumentTitleCoverageTest.php`（**新規**）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本ファイル自体。既存テストの変更なし

### 設計の要点（プロトタイプで検証済み）

本 gate のアルゴリズムは devnotes 外の一時プロトタイプで実走させ、
**期待どおりの結果が出ることを確認済み**:

```
検査対象 route: 49 本（パッケージ所有 route を除外後）
  タイトル未網羅 (MISSING): 5 本
    billing.plans                 App\Http\Controllers\Billing\BillingController@plans
    onboarding.checkout           App\Http\Controllers\Onboarding\OnboardingController@show
    onboarding.billing-required   App\Http\Controllers\Onboarding\BillingRequiredController@show
    capture.manuals.index         App\Http\Controllers\Capture\CaptureManualController@index
    debug.login                   App\Http\Controllers\DebugLoginController@index
  action を静的解決できない (要 allowlist): 9 本
```

**メソッド粒度が必須である根拠（実測）**

| controller | ファイル粒度だと | メソッド粒度なら |
|---|---|---|
| `ConfirmRecentAuthController` | Inertia を含む → `status` (JsonResponse) も候補入り = **誤検出** | `status` は除外 ✓ |
| `CaptureManualController` | `setPrivateTitle` を含む → `index` も網羅済み扱い = **取りこぼし** | `index` を検出 ✓（本命） |

**route の分類（deny-by-default の 3 分岐）**

1. **パッケージ所有 route** → 対象外（自前で head を持つ / アプリの Inertia ページではない）。
   `filament.` / `livewire.` / `passport.` / `mcp.` / `cashier.` / `storage.` /
   `horizon.` / `telescope.` / `sanctum.` / `ignition.` の名前 prefix。
   `NestedRouteIdorDefenseTest` が同じ prefix 除外を行っている先例に揃える
2. **action を静的解決できる** → メソッド本体が Inertia を render するなら
   タイトル網羅を要求する
3. **action を静的解決できない**（Closure / vendor controller / メソッド不在）→
   **config にタイトルがあれば OK、無ければ理由付き allowlist 必須**
   （先に config を見ることで allowlist が 9 件に収まる。Fortify の
   `login` / `register` 等は既に `app_titles` にあるため allowlist 不要）

**`setPrivateTitle` の 1 hop 追跡（仕様として固定）**

次を**すべて**満たす場合のみ 1 段だけ辿る:

1. 呼び出し形が `$this->name(` または `self::name(` の**直接呼び出し**
   （`$other->name(` / `static::` / `$this->$m(` は辿らない = 別オブジェクトの
   同名呼び出しを誤認しない）
2. `name` が**同一ファイル・同一クラスに宣言**されている（継承基底・trait は辿らない）
3. その宣言の可視性が `private` または `protected`（`public` は外部 API）
4. 辿るのは 1 段のみ

**本バッチ時点で 1 hop が必要な route は存在しない**（実測: `setPrivateTitle` は
すべて当該メソッド本体に直接ある）。将来の偽陽性への保険であり、
**正のコントロールを fixture で固定して機能を保証する**。

### 変更後コード

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Architecture invariant: **Inertia を render する GET named route** は必ず
 * ページ固有のタイトルを持つ (deny-by-default)。
 *
 * SoT = SeoManager::resolveDocumentTitle() の優先順位:
 *   1. controller 供給メタ (route_classification.full)
 *   2. config('seo.minimal_titles')[route]
 *   3. SeoManager::setPrivateTitle() の動的上書き / config('seo.app_titles')[route]
 * どれも無い route は **サイト名のみ (`AI-CUE`)** になる。複数タブを開いたとき全部
 * 同じタイトルになる静かな UX 劣化で、既存テストでは落ちない。
 * (`<title>` = SeoComposer/SeoRenderer、SPA 遷移時の document.title =
 *  HandleInertiaRequests の共有 prop `title` で、どちらも同じ経路を読む)
 *
 * 検出方式: Route ファサードで route を列挙し、action を
 * `Class@method` / invokable / Closure に分けて解決する。Inertia の render 判定と
 * setPrivateTitle 判定は **PhpToken でメソッド本体に限定して**行う
 * (InertiaRenderPageExistsInvariantTest と同じ token 走査基盤)。
 *
 * **メソッド粒度が必須である根拠 (実測)**:
 *   - ConfirmRecentAuthController: ファイル粒度だと JsonResponse を返す status() まで
 *     Inertia 扱いになる (誤検出)
 *   - CaptureManualController: ファイル粒度だと show() の setPrivateTitle が index() を
 *     覆い隠す (取りこぼし。本 gate の本命 1 件)
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * パッケージが所有する route 名の prefix (自前で head を持つ / アプリのページではない)。
 * NestedRouteIdorDefenseTest の除外規約に揃える。
 *
 * @var list<string>
 */
const DOCUMENT_TITLE_PACKAGE_ROUTE_PREFIXES = [
    'filament.', 'livewire.', 'passport.', 'mcp.', 'cashier.',
    'storage.', 'horizon.', 'telescope.', 'sanctum.', 'ignition.',
];

/**
 * action を静的解決できず、かつ config にタイトルも無い route の明示 allowlist (理由付き)。
 *
 * 新規追加は「なぜ静的に決定できないか」+「なぜタイトルが不要か」の理由とセットでのみ許可する。
 *
 * @return array<string, string>
 */
function documentTitleUnresolvableAllowlist(): array
{
    return [
        // --- Fortify の非ページ endpoint (JSON / redirect。HTML ヘッドを持たない) ---
        'verification.verify' => 'Fortify の署名付き検証リンク着地。検証後 redirect するのみでページを描画しない',
        'password.confirmation' => 'Fortify の確認済みパスワード状態プローブ (JSON)。ページを描画しない',
        'two-factor.qr-code' => 'Fortify の 2FA QR (SVG/JSON) endpoint。ページを描画しない',
        'two-factor.secret-key' => 'Fortify の 2FA secret (JSON) endpoint。ページを描画しない',
        'two-factor.recovery-codes' => 'Fortify のリカバリコード (JSON) endpoint。ページを描画しない',
        // --- Route::view の Blade スタブ (Inertia ではない。title は blade 側が持つ) ---
        'legal.terms' => 'Route::view の Blade スタブ (Inertia 非経由)。NoIndex middleware 付きの文面プレースホルダ',
        'legal.privacy' => 'Route::view の Blade スタブ (Inertia 非経由)。同上',
        'legal.commerce-disclosure' => 'Route::view の Blade スタブ (Inertia 非経由)。同上',
        // --- 仕様固定の空応答 endpoint ---
        'capture.csrf-cookie' => '419 リトライ用の CSRF cookie 再発行 (204 no content の Closure)。ページを描画しない',
    ];
}

/**
 * Inertia を render するが、タイトル網羅の対象外とする route の明示 allowlist (理由付き)。
 *
 * @return array<string, string>
 */
function documentTitleExemptAllowlist(): array
{
    return [
        // routes/web.php が isLocal() || runningUnitTests() で route 登録自体を囲む =
        // staging / production には存在しない。LocalOnly middleware で二重防御済み。
        'debug.login' => 'local / テスト専用のデバッグログイン。本番に存在しないため固有タイトルを持たせる価値がない',
    ];
}

/** index 以降で最初の significant token の index。 @param list<PhpToken> $tokens */
function documentTitleNextSignificant(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * ファイル内の各メソッドの token 範囲と可視性を抽出する (純関数)。
 *
 * **キーは小文字化したメソッド名**。PHP のメソッド名解決は case-insensitive なので、
 * route の action 文字列 (`Class@Index`) と宣言 (`function index`) の case が
 * 揃っていなくても解決できるようにする。
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, array{start: int, end: int, visibility: string}>
 */
function documentTitleMethodRanges(array $tokens): array
{
    $ranges = [];
    $count = count($tokens);
    $visibility = 'public';

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
            $visibility = strtolower($token->text);

            continue;
        }
        if ($token->is([T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY])) {
            continue;
        }
        if (! $token->is(T_FUNCTION)) {
            continue;
        }

        $nameIndex = documentTitleNextSignificant($tokens, $i + 1);
        if ($nameIndex !== null && $tokens[$nameIndex]->text === '&') {
            $nameIndex = documentTitleNextSignificant($tokens, $nameIndex + 1);
        }
        if ($nameIndex === null || ! $tokens[$nameIndex]->is(T_STRING)) {
            $visibility = 'public'; // 無名関数 / arrow fn

            continue;
        }

        // 引数括弧と return type を跨いで body の `{` を探す
        $parenDepth = 0;
        $bodyStart = null;
        for ($j = $nameIndex + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(') {
                $parenDepth++;
            } elseif ($text === ')') {
                $parenDepth--;
            } elseif ($parenDepth === 0 && $text === '{') {
                $bodyStart = $j;
                break;
            } elseif ($parenDepth === 0 && $text === ';') {
                break; // abstract / interface メソッド
            }
        }
        if ($bodyStart === null) {
            $visibility = 'public';

            continue;
        }

        $braceDepth = 0;
        for ($j = $bodyStart; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '{') {
                $braceDepth++;
            } elseif ($text === '}') {
                $braceDepth--;
                if ($braceDepth === 0) {
                    $ranges[strtolower($tokens[$nameIndex]->text)] = [
                        'start' => $bodyStart,
                        'end' => $j,
                        'visibility' => $visibility,
                    ];
                    break;
                }
            }
        }
        $visibility = 'public';
    }

    return $ranges;
}

/**
 * メソッド本体に `->name(` / `?->name(` 形の**メソッド呼び出し**が現れるか (case 無視)。
 *
 * 識別子の出現だけを見ると、変数名・配列キー・コメント外の同名文字列・callable 参照
 * (`[$seo, 'setPrivateTitle']`) でも通ってしまい **偽陰性** (タイトル未供給を
 * 「供給済み」と誤判定して gate が取りこぼす) になる。呼び出しトークン列に限定する。
 * 既存 `ScenarioWritePathInventoryTest::containsMethodCall()` と同じ判定形。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array{start: int, end: int, visibility: string}  $range
 */
function documentTitleBodyCallsMethod(array $tokens, array $range, string $method): bool
{
    for ($i = $range['start']; $i <= $range['end']; $i++) {
        if (! $tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            continue;
        }
        $nameIndex = documentTitleNextSignificant($tokens, $i + 1);
        if ($nameIndex === null || ! $tokens[$nameIndex]->is(T_STRING)
            || strcasecmp($tokens[$nameIndex]->text, $method) !== 0) {
            continue;
        }
        $paren = documentTitleNextSignificant($tokens, $nameIndex + 1);
        if ($paren !== null && $tokens[$paren]->text === '(') {
            return true;
        }
    }

    return false;
}

/**
 * メソッド本体が Inertia ページを render するか (`Inertia::render(` / `inertia(` の literal 呼び出し)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array{start: int, end: int, visibility: string}  $range
 */
function documentTitleBodyRendersInertia(array $tokens, array $range): bool
{
    for ($i = $range['start']; $i <= $range['end']; $i++) {
        $token = $tokens[$i];
        if (! $token->is(T_STRING)) {
            continue;
        }

        // Inertia::render( — `(` まで確認する (`Inertia::render(...)` の callable 参照
        // `[Inertia::class, 'render']` や `Inertia::render` 単独参照を誤検出しない)
        if (strcasecmp($token->text, 'Inertia') === 0) {
            $colon = documentTitleNextSignificant($tokens, $i + 1);
            if ($colon !== null && $tokens[$colon]->is(T_DOUBLE_COLON)) {
                $method = documentTitleNextSignificant($tokens, $colon + 1);
                if ($method !== null && $tokens[$method]->is(T_STRING)
                    && strcasecmp($tokens[$method]->text, 'render') === 0) {
                    $paren = documentTitleNextSignificant($tokens, $method + 1);
                    if ($paren !== null && $tokens[$paren]->text === '(') {
                        return true;
                    }
                }

                continue;
            }
        }

        // inertia( ヘルパ (引数なし `inertia()` = ResponseFactory 取得は除く)
        if (strcasecmp($token->text, 'inertia') === 0) {
            $paren = documentTitleNextSignificant($tokens, $i + 1);
            if ($paren !== null && $tokens[$paren]->text === '(') {
                $arg = documentTitleNextSignificant($tokens, $paren + 1);
                if ($arg !== null && $tokens[$arg]->text !== ')') {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * 1 hop (同一クラスの private/protected helper) 経由で setPrivateTitle に到達するか。
 *
 * 追跡条件 (すべて満たす場合のみ):
 *   1. `$this->name(` または `self::name(` の直接呼び出し
 *   2. name が同一ファイル・同一クラスに宣言されている
 *   3. その可視性が private / protected
 *   4. 1 段のみ
 *
 * @param  list<PhpToken>  $tokens
 * @param  array{start: int, end: int, visibility: string}  $range
 * @param  array<string, array{start: int, end: int, visibility: string}>  $ranges
 */
function documentTitleOneHopHasSetPrivateTitle(array $tokens, array $range, array $ranges): bool
{
    for ($i = $range['start']; $i <= $range['end']; $i++) {
        $token = $tokens[$i];
        $callee = null;

        if ($token->is(T_VARIABLE) && $token->text === '$this') {
            $arrow = documentTitleNextSignificant($tokens, $i + 1);
            if ($arrow === null || ! $tokens[$arrow]->is(T_OBJECT_OPERATOR)) {
                continue; // ?-> は helper 呼び出しとして扱わない (nullable な自己参照はしない)
            }
            $name = documentTitleNextSignificant($tokens, $arrow + 1);
            if ($name === null || ! $tokens[$name]->is(T_STRING)) {
                continue; // $this->$method() は静的に決まらない
            }
            $paren = documentTitleNextSignificant($tokens, $name + 1);
            if ($paren === null || $tokens[$paren]->text !== '(') {
                continue; // プロパティアクセス
            }
            $callee = $tokens[$name]->text;
        } elseif ($token->is(T_STRING) && strcasecmp($token->text, 'self') === 0) {
            $colon = documentTitleNextSignificant($tokens, $i + 1);
            if ($colon === null || ! $tokens[$colon]->is(T_DOUBLE_COLON)) {
                continue;
            }
            $name = documentTitleNextSignificant($tokens, $colon + 1);
            if ($name === null || ! $tokens[$name]->is(T_STRING)) {
                continue;
            }
            $paren = documentTitleNextSignificant($tokens, $name + 1);
            if ($paren === null || $tokens[$paren]->text !== '(') {
                continue;
            }
            $callee = $tokens[$name]->text;
        }

        $key = strtolower($callee); // PHP のメソッド名解決は case-insensitive
        if (! isset($ranges[$key])) {
            continue; // 同一ファイル・同一クラスに宣言が無い (継承 / trait は辿らない)
        }
        if (! in_array($ranges[$key]['visibility'], ['private', 'protected'], true)) {
            continue; // public は外部 API = 専用 helper と見なさない
        }
        if (documentTitleBodyCallsMethod($tokens, $ranges[$key], 'setPrivateTitle')) {
            return true;
        }
    }

    return false;
}

/** route 名がパッケージ所有か。 */
function documentTitleIsPackageRoute(string $name): bool
{
    foreach (DOCUMENT_TITLE_PACKAGE_ROUTE_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }

    return false;
}

/** config 由来のタイトルを持つか (full / minimal_titles / app_titles)。 */
function documentTitleHasConfiguredTitle(string $name): bool
{
    /** @var list<string> $full */
    $full = config('seo.route_classification.full', []);
    /** @var array<string, string> $minimal */
    $minimal = config('seo.minimal_titles', []);
    /** @var array<string, string> $app */
    $app = config('seo.app_titles', []);

    return in_array($name, $full, true)
        || array_key_exists($name, $minimal)
        || array_key_exists($name, $app);
}

/**
 * GET named route を走査し、タイトル網羅の判定結果を返す。
 *
 * @return array{
 *     missing: list<string>,
 *     unresolvable: list<string>,
 *     inertiaRoutes: int,
 * }
 */
function documentTitleCollectAll(): array
{
    $missing = [];
    $unresolvable = [];
    $inertiaRoutes = 0;
    $exempt = documentTitleExemptAllowlist();
    $allowUnresolvable = documentTitleUnresolvableAllowlist();

    /** @var array<string, array{tokens: list<PhpToken>, ranges: array<string, array{start: int, end: int, visibility: string}>}> $cache */
    $cache = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $name = $route->getName();
        if ($name === null || documentTitleIsPackageRoute($name)) {
            continue;
        }

        $controller = $route->getAction('controller');

        // ---- action の静的解決 ----
        $file = null;
        $method = null;
        if (is_string($controller)) {
            if (str_contains($controller, '@')) {
                [$class, $method] = explode('@', $controller, 2);
            } else {
                $class = $controller;
                $method = '__invoke';
            }
            if (class_exists($class)) {
                $reflected = (new ReflectionClass($class))->getFileName();
                if (is_string($reflected) && ! str_starts_with($reflected, base_path('vendor'))) {
                    $file = $reflected;
                }
            }
        }

        if ($file === null || $method === null) {
            // 静的解決できない: config にタイトルがあれば OK、無ければ allowlist 必須
            if (documentTitleHasConfiguredTitle($name) || array_key_exists($name, $allowUnresolvable)) {
                continue;
            }
            $unresolvable[] = "{$name} ({$route->uri()}) は action を静的解決できず、config にもタイトルが無い";

            continue;
        }

        if (! isset($cache[$file])) {
            $source = file_get_contents($file);
            if (! is_string($source)) {
                continue;
            }
            /** @var list<PhpToken> $tokens */
            $tokens = PhpToken::tokenize($source);
            $cache[$file] = ['tokens' => $tokens, 'ranges' => documentTitleMethodRanges($tokens)];
        }
        $tokens = $cache[$file]['tokens'];
        $ranges = $cache[$file]['ranges'];
        $methodKey = strtolower($method); // ranges のキーは小文字化されている

        if (! isset($ranges[$methodKey])) {
            if (documentTitleHasConfiguredTitle($name) || array_key_exists($name, $allowUnresolvable)) {
                continue;
            }
            $unresolvable[] = "{$name} ({$route->uri()}) のメソッド {$method} を解決できず、config にもタイトルが無い";

            continue;
        }

        if (! documentTitleBodyRendersInertia($tokens, $ranges[$methodKey])) {
            continue; // Inertia ページを描画しない route は本 gate の対象外
        }
        $inertiaRoutes++;

        if (array_key_exists($name, $exempt)) {
            continue;
        }
        if (documentTitleHasConfiguredTitle($name)) {
            continue;
        }
        if (documentTitleBodyCallsMethod($tokens, $ranges[$methodKey], 'setPrivateTitle')) {
            continue;
        }
        if (documentTitleOneHopHasSetPrivateTitle($tokens, $ranges[$methodKey], $ranges)) {
            continue;
        }

        $missing[] = "{$name} ({$route->uri()}) → {$controller}";
    }

    return ['missing' => $missing, 'unresolvable' => $unresolvable, 'inertiaRoutes' => $inertiaRoutes];
}

test('Inertia を render する GET named route は全てページ固有タイトルを持つ', function (): void {
    $result = documentTitleCollectAll();

    expect($result['missing'])->toBe([],
        'ページ固有タイトルが無い route があります。config/seo.php の app_titles / minimal_titles に'
        .'登録するか、controller で SeoManager::setPrivateTitle() を呼んでください'
        .'(タイトル不要なら documentTitleExemptAllowlist() に理由付きで登録)。'
        .PHP_EOL.implode(PHP_EOL, $result['missing']));
});

test('action を静的解決できない route は config タイトルか理由付き allowlist が必須', function (): void {
    $result = documentTitleCollectAll();

    expect($result['unresolvable'])->toBe([],
        'action を静的解決できず、タイトルも無い route があります。'
        .'documentTitleUnresolvableAllowlist() に「なぜ静的に決定できないか」+'
        .'「なぜタイトルが不要か」の理由付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $result['unresolvable']));
});

test('走査が空振りしていない (Inertia route を実際に検出できている)', function (): void {
    // route 定義の変更や token 走査の破損で「0 件検査して green」になる退行を落とす。
    expect(documentTitleCollectAll()['inertiaRoutes'])->toBeGreaterThan(0);
});

test('allowlist の key は現存 named route (逆方向整合・stale 検出)', function (): void {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        $routeName = $route->getName();
        if ($routeName !== null) {
            $named[$routeName] = true;
        }
    }

    $stale = [];
    foreach ([
        ...array_keys(documentTitleUnresolvableAllowlist()),
        ...array_keys(documentTitleExemptAllowlist()),
    ] as $key) {
        if (! isset($named[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'allowlist に現存しない route 名 (削除/rename 済): '.implode(', ', $stale));
});

test('allowlist の各エントリは理由コメント (非空文字列) を持つ', function (): void {
    foreach ([...documentTitleUnresolvableAllowlist(), ...documentTitleExemptAllowlist()] as $key => $reason) {
        expect(trim($reason))->not->toBe('', "allowlist エントリ {$key} に理由がありません");
    }
});

/*
 * 負のコントロール: 実ファイルを書き換えず fixture ソースに対して検出器が点灯することを確認する。
 * 「Inertia を render するがタイトルを供給しないメソッド」を検出できること。
 */
test('負のコントロール: Inertia を render するがタイトルを供給しないメソッドを識別する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function index() {
            return Inertia::render('Fixture/Index', []);
        }
        public function status() {
            return response()->json(['ok' => true]);
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    // index は Inertia を render し、setPrivateTitle を持たない = 網羅対象かつ未網羅
    expect(documentTitleBodyRendersInertia($tokens, $ranges['index']))->toBeTrue();
    expect(documentTitleBodyCallsMethod($tokens, $ranges['index'], 'setPrivateTitle'))->toBeFalse();
    expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges['index'], $ranges))->toBeFalse();

    // status は Inertia を render しない = 対象外 (ファイル粒度なら誤検出するケース)
    expect(documentTitleBodyRendersInertia($tokens, $ranges['status']))->toBeFalse();
});

/*
 * 正のコントロール: メソッド粒度で setPrivateTitle を判定できること。
 * 同一ファイルの別メソッドが setPrivateTitle を持っていても、それに引きずられない
 * (CaptureManualController の index / show がまさにこの形で、本 gate の本命 1 件)。
 */
test('正のコントロール: 同一ファイルの別メソッドの setPrivateTitle に引きずられない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function index() {
            return Inertia::render('Fixture/Index', []);
        }
        public function show($seo, $manual) {
            $seo->setPrivateTitle($manual->title);
            return Inertia::render('Fixture/Show', []);
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    expect(documentTitleBodyCallsMethod($tokens, $ranges['show'], 'setPrivateTitle'))->toBeTrue();
    expect(documentTitleBodyCallsMethod($tokens, $ranges['index'], 'setPrivateTitle'))->toBeFalse();
});

/*
 * 正のコントロール: 1 hop 追跡が仕様どおり動くこと。
 * 本バッチ時点で 1 hop を必要とする route は存在しないため、fixture でのみ機能を保証する。
 */
test('正のコントロール: $this-> / self:: 経由の private helper (1 hop) を追跡する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function viaThis($seo) {
            $this->applyTitle($seo);
            return Inertia::render('Fixture/A', []);
        }
        public function viaSelf($seo) {
            self::applyTitle($seo);
            return Inertia::render('Fixture/B', []);
        }
        private function applyTitle($seo): void {
            $seo->setPrivateTitle('固有名');
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    // ranges のキーは小文字化されている
    expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges['viathis'], $ranges))->toBeTrue();
    expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges['viaself'], $ranges))->toBeTrue();
});

/*
 * 負のコントロール: 1 hop の追跡条件を満たさないものは辿らない。
 * 別オブジェクトへの同名呼び出しを「タイトルを供給している」と誤認すると
 * gate が取りこぼす方向に倒れる (最悪の失敗)。
 */
test('負のコントロール: 別オブジェクト / public / 2 hop は 1 hop 追跡の対象外', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function otherObject($helper, $seo) {
            $helper->applyTitle($seo);          // 別オブジェクト = 辿らない
            return Inertia::render('Fixture/A', []);
        }
        public function viaPublic($seo) {
            $this->publicApplyTitle($seo);      // public = 専用 helper と見なさない
            return Inertia::render('Fixture/B', []);
        }
        public function twoHop($seo) {
            $this->firstHop($seo);              // 2 hop 先は辿らない
            return Inertia::render('Fixture/C', []);
        }
        public function dynamicName($seo, $m) {
            $this->$m($seo);                    // 変数メソッド名 = 静的に決まらない
            return Inertia::render('Fixture/D', []);
        }
        public function publicApplyTitle($seo): void {
            $seo->setPrivateTitle('固有名');
        }
        private function firstHop($seo): void {
            $this->applyTitle($seo);
        }
        private function applyTitle($seo): void {
            $seo->setPrivateTitle('固有名');
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    // ranges のキーは小文字化されている
    foreach (['otherobject', 'viapublic', 'twohop', 'dynamicname'] as $method) {
        expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges[$method], $ranges))
            ->toBeFalse("{$method} は 1 hop 追跡の対象外であるべき");
    }
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（全ヘルパ）
- [x] null 安全（`getName()` / `getAction()` / `getFileName()` /
      `documentTitleNextSignificant()` の戻り値を毎回チェック。
      `Webmozart\Assert\Assert` は使わず**アーリーリターン**で処理）
- [x] DTO を返している（該当なし — テスト内純関数。array shape で表す）
- [x] Generics の型パラメータが正しい（`list<PhpToken>` /
      `array<string, array{start: int, end: int, visibility: string}>` /
      `array<string, string>`）
- [x] `config()` の戻り値に PHPDoc `@var` を付けて `mixed` を狭める
      （`SeoManager` / `SeoComposer` の既存作法と同一）

### テスト計画

- [x] 予防 + 是正 gate。**負のコントロールを先に書いて fail を確認**してから
      実 route 走査を有効化する
- [x] 新規テスト: 「Inertia route は全てページ固有タイトルを持つ」— 実 route 走査
      （施策 7 適用後 green）
- [x] 新規テスト: 「静的解決できない route は config タイトルか allowlist 必須」
- [x] 新規テスト: 「走査が空振りしていない」— inertiaRoutes > 0
      （プロトタイプ実測で 49 route 中 Inertia は十分な数を検出）
- [x] 新規テスト: 「allowlist の key は現存 named route」（stale 検出。
      `NestedRouteIdorDefenseTest` と同じ逆方向整合）
- [x] 新規テスト: 「allowlist の各エントリは理由コメントを持つ」
- [x] 新規テスト: 負のコントロール 2 本 / 正のコントロール 2 本（メソッド粒度・1 hop）
- [x] 個別の `DatabaseTransactions` を使っていない
- 検証: `bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/DocumentTitleCoverageTest.php`

### リスク

- **`debug.login` は local/test でのみ route 登録される**。stale 検出テストは
  「allowlist の key が現存 route か」を見るため、テスト実行時
  （`runningUnitTests()` = true）には必ず存在する。**production では
  そもそもこのテストが走らない**ため矛盾しない
- **`Route::getRoutes()` の列挙コスト**: `documentTitleCollectAll()` を 3 テストが呼ぶ。
  ファイル tokenize は `$cache` で同一ファイルを再利用するため、
  controller 数（数十）ぶんの tokenize で収まる
- **将来 responder パターンを導入した場合**: 1 hop を超える間接化は
  `documentTitleExemptAllowlist()` への理由付き登録が必要になる。
  これは**意図した摩擦**（deny-by-default）であり、
  gate を静的解析器へ肥大化させない設計判断

---

## 施策 7: `config/seo.php` へ不足 4 route の固有名を追加

### 変更箇所

- ファイル: `config/seo.php` の `app_titles` 配列（L80〜130）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Seo/SeoManagerTest.php` に
  「未登録だったアプリ画面が固有 title を返す (仕様固定・h1 と一致)」という
  **dataset 駆動のテストが既にある**（L86〜）。新規 4 件を dataset に**追加**する
  （既存ケースは削除・変更しない）

### タイトル文言の決定根拠

`config/seo.php` の既存規約は「**各画面の h1 見出しと一致させる**（タブ title と
画面見出しの表現一貫性）」。各ページの実際の見出しを確認して決めた:

| route | ページ | 画面上の見出し | 採用タイトル |
|---|---|---|---|
| `billing.plans` | `Billing/Plans.svelte` | `title="プラン比較"` (L153) | **プラン比較** |
| `onboarding.checkout` | `Onboarding/Checkout.svelte` | `` title={`ようこそ、${organization.name}`} `` (L184) = **動的** | **プランの選択**（下記） |
| `onboarding.billing-required` | `Onboarding/BillingRequired.svelte` | `title="課金手続き中です"` (L32) | **課金手続き中です** |
| `capture.manuals.index` | `Capture/Index.svelte` | `title="撮影するマニュアルを選ぶ"` (L53) | **撮影するマニュアルを選ぶ** |

**`onboarding.checkout` だけ h1 と一致させない理由**: 見出しが
`ようこそ、{組織名}` という**動的な挨拶文**で、タブタイトルとしては
(a) 組織名がタブ幅を食う (b)「何をする画面か」を伝えない、という二重の問題がある。
タブの役割は**画面の識別**なので、機能を表す静的名 `プランの選択` を採用する。
既存の `billing.tickets.show` が h1 と別に「チケットを購入」を持つのと同じ判断。

### 現行コード

```php
    'app_titles' => [
        'dashboard' => 'ダッシュボード',
        // ...
        // 課金
        'billing.index' => 'プランとお支払い',
        'billing.tickets.show' => 'チケットを購入',
        // プロジェクト (show は controller が setPrivateTitle でプロジェクト名を供給)
        'projects.index' => 'プロジェクト',
        // ...
        // 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)
        'notifications.index' => '通知',
    ],
```

### 変更後コード

```php
    'app_titles' => [
        'dashboard' => 'ダッシュボード',
        // ...
        // 課金
        'billing.index' => 'プランとお支払い',
        // プラン比較 (billing.plans — Billing/Plans.svelte の見出し「プラン比較」)
        'billing.plans' => 'プラン比較',
        'billing.tickets.show' => 'チケットを購入',
        /*
        | 課金オンボーディング (課金ゲートの着地先。未契約組織が「契約するために」
        | 到達する導線なので、タブ識別性は詰み回避の一部。AGENTS.md ドメイン規約 4)。
        | onboarding.checkout の画面見出しは `ようこそ、{組織名}` という動的な挨拶文で、
        | タブ title としては組織名が幅を食い機能も伝わらないため、
        | 機能を表す静的名を採る (billing.tickets.show と同じ判断)。
        */
        'onboarding.checkout' => 'プランの選択',
        // 課金手続き待ち (onboarding.billing-required — Onboarding/BillingRequired.svelte
        // の見出し「課金手続き中です」)
        'onboarding.billing-required' => '課金手続き中です',
        // プロジェクト (show は controller が setPrivateTitle でプロジェクト名を供給)
        'projects.index' => 'プロジェクト',
        // ...
        // 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)
        'notifications.index' => '通知',
        /*
        | 撮影 PWA (/app/*)。manuals.show は controller が setPrivateTitle で
        | マニュアル名を供給するため、静的名が必要なのは一覧 (index) のみ。
        | スマホで複数タブ / ホーム画面から戻る現場ユースケースではタブ名が唯一の識別子。
        */
        'capture.manuals.index' => '撮影するマニュアルを選ぶ',
    ],
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（config 配列。`SeoManager` が
      `@var array<string, string>` で読む既存契約に合致）
- [x] null 安全（`$appTitles[$routeName] ?? null` の既存実装で処理済み）
- [x] DTO を返している（該当なし）
- [x] Generics（該当なし）

### テスト計画

- [x] 既存テスト `tests/Feature/Seo/SeoManagerTest.php` の
      「未登録だったアプリ画面が固有 title を返す」dataset に**4 件を追加**
      （既存ケースは削除・変更しない = 禁止事項 3 を守る）
- [x] 施策 6 の gate が「未網羅 0 件」で green になることで登録漏れを機械検出する
      （**この環境でも実行可能** = DB 非依存）
- [x] `SeoManagerTest` 本体は Feature レーン（DB あり）のため
      **この環境では実行不能**。CI で確認する
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- **文言レビューが未了**: 「プランの選択」等の文言は本設計での提案。
  UX 文言の最終決定は実装時にレビューする（ただし gate が要求するのは
  「固有名が存在すること」なので、文言変更は gate に影響しない）
- **`SeoTitle::compose` の二重化回避**: 固有名がサイト名と一致すると
  サイト名のみになる仕様。今回の 4 件はいずれも `AI-CUE` と異なるため影響なし

---

## 施策 8: 招待無効分岐の専用タイトル (3b)

### 変更箇所

- ファイル: `app/Http/Controllers/Organizations/InvitationAcceptanceController.php`
  （`show()` の無効招待分岐、L47〜49）
- ファイル: `tests/Feature/Organization/InvitationTest.php`（**テスト追加**）

### 波及変更

- TypeScript 型定義: なし（Inertia 共有 prop `title` は既存）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Organization/InvitationTest.php` に**追加**
  （既存テストの削除・上書きはしない）

### なぜ config ではなく controller か

`config('seo.app_titles')` は **route 名でキーを引く**。`invitations.accept` は
1 つの route が有効/無効で 2 ページ（`Invitations/Accept` / `Invitations/Invalid`）を
出し分けているため、config では分岐を表現できない。
「config = route 既定値、controller = 分岐ごとの上書き」という既存の責務分担どおり、
無効分岐で `SeoManager::setPrivateTitle()` を呼ぶ。

**施策 6 の gate はこれを強制しない**（route 粒度の gate の射程外。
`invitations.accept` は既に `app_titles` に `'組織への招待'` を持つため gate は通る）。
**ただしテストは持つ**（禁止事項 1）。

### 現行コード

```php
use App\Http\Controllers\Controller;
use App\Models\Organization;
// ...
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

class InvitationAcceptanceController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && $token !== '', 404);

        $invitation = OrganizationInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // 無効招待は理由非開示の専用ページへ (guest / auth 共通)
        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
            return Inertia::render('Invitations/Invalid');
        }
```

### 変更後コード

```php
use App\Http\Controllers\Controller;
use App\Models\Organization;
// ...
use App\Support\Seo\SeoManager;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

class InvitationAcceptanceController extends Controller
{
    /**
     * 受諾確認画面 (GET, guest 可)。
     *
     * ... (既存 docblock は維持)
     *
     * タイトル: route 既定は config('seo.app_titles')['invitations.accept'] =「組織への招待」。
     * 無効分岐だけは同じ route で別ページ (Invitations/Invalid) を返すため、
     * SeoManager::setPrivateTitle() で上書きする (config は route 名でしか引けない)。
     * **理由・組織名は開示しない**既存の秘匿契約を守り、固有名にも組織名を混ぜない。
     */
    public function show(Request $request, SeoManager $seo): Response|RedirectResponse
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && $token !== '', 404);

        $invitation = OrganizationInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // 無効招待は理由非開示の専用ページへ (guest / auth 共通)
        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
            // タブ title は h1「この招待リンクは使用できません」から指示語「この」を落とした形。
            // SeoTitle::compose が ` | {サイト名}` を付けるため、タブ幅を圧迫しない範囲で見出しと揃える
            // (config/seo.php の「h1 と一致させる」規約に対する意図的な短縮。
            //  文言を変えるときは Invitations/Invalid.svelte の h1 も追随させる)。
            $seo->setPrivateTitle('招待リンクは使用できません');

            return Inertia::render('Invitations/Invalid');
        }
```

**`SeoManager` を DI で受け取る根拠**: `SeoManager` は `SeoServiceProvider` で
**scoped 束縛**（リクエスト単位。Octane でも状態が漏れない）。
既存の `CaptureManualController::show()` が同じ形（メソッド引数で `$seo` を受ける）で
`setPrivateTitle` を呼んでおり、**既存パターンに揃う**。

**文言**: `Invitations/Invalid.svelte` の h1 は「この招待リンクは使用できません」(L22)。
タブ title は先頭の指示語「この」を落として `招待リンクは使用できません` とする。

**h1 と完全一致させない理由をコードコメントで固定する**（運用の安定化。
`config/seo.php` の「h1 見出しと一致させる」規約からの意図的なズレなので、
後から「不一致だから直そう」と揺り戻されないようにする）:

```php
// タブ title は h1「この招待リンクは使用できません」から指示語「この」を落とした形。
// SeoTitle::compose が ` | {サイト名}` を付けるため、タブ幅を圧迫しない範囲で見出しと揃える
// (config/seo.php の「h1 と一致させる」規約に対する意図的な短縮。文言を変えるときは両方を追随させる)。
$seo->setPrivateTitle('招待リンクは使用できません');
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Response|RedirectResponse` は既存のまま）
- [x] null 安全（既存の `abort_unless` / `Assert::isInstanceOf` を維持）
- [x] DTO を返している（Inertia render のため該当なし。
      `response()->json()` の直書きなし = 禁止事項 4 に抵触しない）
- [x] Generics（該当なし）
- [x] `SeoManager` の型は具象クラス（interface なし）で解決される

### テスト計画

- [x] **バグ修正ではないが挙動変更のため、先にテストを書いて fail を確認する**
- [x] 既存テスト `tests/Feature/Organization/InvitationTest.php` の
      「無効な招待リンクは理由非開示の専用ページを返す (guest)」(L289) /
      「…ログイン済みでも専用ページを返す」(L303) は**変更しない**
- [x] 新規テスト（同ファイルへ追加）:
      **「無効な招待リンクは有効時と異なる専用タイトルを返す (組織名は漏らさない)」**
      — 検証内容:
      1. 無効 token での GET が Inertia 共有 prop `title` に
         `招待リンクは使用できません | {サイト名}` を返す
      2. **有効** token での GET の `title` と**一致しない**こと
         （「無効ページなのに『組織への招待』」の退行を落とす）
      3. `title` に**組織名が含まれない**こと（既存の秘匿契約。
         組織名を含む Organization factory を使って negative assert する）
- [x] 検証手段: `assertInertia(fn (AssertableInertia $page) => $page->where('title', ...))`
      （共有 prop は Inertia page props に載る。既存 L362 の
      `assertInertia(fn (AssertableInertia $page) => ...)` と同じ作法）
- [x] テストデータは **Factory で生成**（`User::factory()` / `Organization::factory()` /
      既存ヘルパ `createOrganizationWithOwner()`。`Model::create()` 手組みはしない）
- [x] 個別の `DatabaseTransactions` を使っていない（グローバル `RefreshDatabase`）
- [x] **この環境では実行不能**（PostgreSQL 不在）。DB のある CI の Feature レーンで走る

### リスク

- **秘匿契約の後退**: タイトルに理由（失効/取消済/受諾済）を書くと
  token オラクルになる。**どの無効理由でも同一文言**にすることで既存契約を守る
  （テストで固定）
- **`show()` のシグネチャ変更**: 引数追加は Laravel の DI が解決するため
  route 定義の変更は不要。`InvitationAcceptanceController::show` を直接呼ぶ
  他のコードは無い（route 経由のみ）
- **有効分岐のタイトルは変えない**（`app_titles` の「組織への招待」のまま）。
  変更範囲を無効分岐に限定し、既存の期待を壊さない

---

## 施策 9: `<svelte:head>` 二重 SoT 禁止 gate 新設

### 変更箇所

- ファイル: `tests/js/architecture/svelte-head-no-title.test.ts`（**新規**）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本ファイル自体。既存テストの変更なし
- `vitest.config.ts` の `include` は `tests/js/**/*.test.ts` なので**設定変更不要**

### 設計の要点

**現状**: `resources/js/pages/` 配下に `<svelte:head>` は **0 件**（実測）= 純粋な予防 gate。

**なぜ `<title>` を禁止するか**: `resources/js/lib/document-title.ts` が明文で
「クライアント側に第二の title SoT (`<svelte:head>` 等) を作らない」と宣言している。
サーバは `SeoManager::resolveDocumentTitle()` を単一 SoT とし、
Blade `<title>`（フルロード）と Inertia 共有 prop `title`（SPA 遷移）の両方に
同じ文字列を流している。クライアントに第二 SoT ができるとフルロードと SPA 遷移で
タイトルが食い違い、**再現条件が遷移経路依存**になってデバッグが極めて難しい。

**なぜ `<meta name="description">` も禁止するか**: description にも
サーバ単一 SoT が存在する（`config('seo.default_description')` →
`SeoMeta::$description` → `SeoRenderer::render()`）。しかも
`resources/js/pages/` には**公開ページの実体が含まれる**:

- `Welcome.svelte` ← `HomeController`（`home`, full 分類）
- `Guest/Pricing.svelte` ← `PricingController`（`pricing`, full 分類。
  **`->withDescription('AI-CUE の料金プラン…')` を実際に供給**）

ここでクライアントから description を書くと、同一 `<head>` に
`<meta name="description">` が **2 個並ぶ**（クローラから見た明確な defect）上、
サーバ側にしかない `og:description` / `twitter:description` と食い違い、
**SNS カードと検索結果の説明文が別物になる**。

**`<svelte:head>` 自体は禁止しない**: preload hint 等の正当な用途があるため、
禁止するのは title / description という **SoT が競合する 2 要素**に限定する。
`og:description` / `twitter:description` はクライアントから書かれる懸念が薄く、
検出集合を広げすぎない（「今必要なものだけ作る」）ため今回は対象外。

### 変更後コード

```ts
import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

/*
 * svelte-head-no-title — ページ側に title / description の第二 SoT を作らせない。
 *
 * SoT = サーバの SEO 基盤:
 *   - <title>  … SeoManager::resolveDocumentTitle() が唯一の解決経路。
 *                Blade (SeoComposer/SeoRenderer) と Inertia 共有 prop `title`
 *                (HandleInertiaRequests) の両方が同じ文字列を読み、
 *                resources/js/lib/document-title.ts が SPA 遷移で document.title に反映する。
 *   - <meta name="description"> … config('seo.default_description') →
 *                SeoMeta::$description (withDescription) → SeoRenderer::render()。
 *                認証配下 (renderPrivate) では **意図的に出さない** (noindex ページに
 *                メタを残さない)。
 *
 * ここで <svelte:head> に title / description を書くと:
 *   - title: フルロード (サーバ描画) と SPA 遷移 (クライアント上書き) で食い違う。
 *            再現条件が遷移経路依存になりデバッグが極めて難しい
 *   - description: 公開ページ (Welcome.svelte = home / Guest/Pricing.svelte = pricing。
 *            後者は controller が withDescription を実際に供給している) で
 *            同一 <head> に description が 2 個並ぶ = クローラから見た明確な defect。
 *            さらにサーバ側にしかない og:description / twitter:description と食い違い、
 *            SNS カードと検索結果の説明文が別物になる。
 *            認証配下では「noindex なのに description だけ生えている」不整合が復活する
 *
 * **<svelte:head> 自体は禁止しない** (preload hint 等の正当な用途がある)。
 * 禁止するのは SoT が競合する title / description の 2 要素のみ。
 * og:description / twitter:description は現状クライアントから書かれる懸念が薄いため対象外
 * (必要になったら検出集合を広げる)。
 *
 * 現時点で違反 0 件 = 純粋な予防 gate。よって「検出器が実際に点灯すること」を
 * 負のコントロールで固定し、空振り green を防ぐ
 * (tests/js/architecture/pages-path-case-invariant.test.ts と同じ作法)。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const PAGES_DIR = path.join(REPO_ROOT, "resources/js/pages");

/** <svelte:head> ブロックの中身を列挙する (複数ブロック・複数行に対応)。 */
const HEAD_BLOCK = /<svelte:head\s*>([\s\S]*?)<\/svelte:head\s*>/g;

/** <title ...> 開始タグ (属性の有無を問わない)。 */
const TITLE_TAG = /<title[\s/>]/i;

/**
 * <meta ... name=description ...> (属性順・クォート有無を問わない)。
 * HTML は無引用の属性値を許すため `name=description` も有効な書き方であり、
 * クォート必須の regex だと抜け道になる。
 */
const META_DESCRIPTION_TAG = /<meta\b[^>]*\bname\s*=\s*(?:"description"|'description'|description\b)/i;

/**
 * 静的に判定できない <meta> (属性値が式 `name={...}` / スプレッド `{...attrs}`)。
 * 「description ではないと証明できない」ので **fail させる** (deny-by-default)。
 * ページ側で meta を動的に組む必要は現状無く、必要になったら本 gate を拡張する。
 */
const META_DYNAMIC_ATTR = /<meta\b[^>]*\{/i;

/**
 * ソース中の <svelte:head> ブロックから、禁止要素の種類を列挙する純関数。
 * 戻り値は "title" / "meta[name=description]" の列。
 */
export function findForbiddenHeadElements(source: string): string[] {
    const found: string[] = [];
    for (const match of source.matchAll(HEAD_BLOCK)) {
        const inner = match[1];
        if (TITLE_TAG.test(inner)) found.push("title");
        if (META_DESCRIPTION_TAG.test(inner)) found.push("meta[name=description]");
        else if (META_DYNAMIC_ATTR.test(inner)) found.push("meta[dynamic-attr]");
    }
    return found;
}

async function pageFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

describe("architecture/svelte-head-no-title", () => {
    it("resources/js/pages の <svelte:head> に title / meta description が存在しない", async () => {
        const files = await pageFiles(PAGES_DIR);
        const offenders: string[] = [];
        for (const file of files) {
            const hits = findForbiddenHeadElements(await fs.readFile(file, "utf8"));
            for (const hit of hits) offenders.push(`${path.relative(REPO_ROOT, file)}: <${hit}>`);
        }
        expect(
            offenders.sort(), // 失敗メッセージを走査順の環境差で揺らさない
            `<svelte:head> 内の title / meta[name=description] を検出。これらのサーバ単一 SoT ` +
                `(SeoManager::resolveDocumentTitle / SeoRenderer) を壊します。` +
                `title は共有 prop 経由で自動反映されるので何も書かないでください。` +
                `description が必要なら controller から SeoMeta::withDescription() で供給してください: ` +
                `${offenders.join(", ")}`,
        ).toEqual([]);
    });

    it("走査が空振りしていない (ページファイルを実際に列挙できている)", async () => {
        // ディレクトリ移動やビルド構成変更で「0 件検査して green」になる退行を落とす。
        expect((await pageFiles(PAGES_DIR)).length).toBeGreaterThan(0);
    });

    /*
     * 負のコントロール: 検出器が実際に点灯することを fixture 文字列で確認する
     * (実ファイルは書き換えない)。違反 0 件の予防 gate を green として扱わないため。
     */
    it("負のコントロール: <svelte:head> 内の title / meta description を検出する", () => {
        const violations: Array<[string, string[]]> = [
            ["<svelte:head><title>ダッシュボード</title></svelte:head>", ["title"]],
            [
                `<svelte:head>\n  <meta name="description" content="説明" />\n</svelte:head>`,
                ["meta[name=description]"],
            ],
            // 属性順が逆でも検出する
            [
                `<svelte:head><meta content="説明" name="description"></svelte:head>`,
                ["meta[name=description]"],
            ],
            // シングルクォートでも検出する
            [`<svelte:head><meta name='description' content='説明'></svelte:head>`, ["meta[name=description]"]],
            // **無引用の属性値** も HTML として有効なので検出する
            [`<svelte:head><meta name=description content="説明"></svelte:head>`, ["meta[name=description]"]],
            // 属性値が式 / スプレッドの <meta> は「description でないと証明できない」ので fail
            [`<svelte:head><meta name={metaName} content={desc}></svelte:head>`, ["meta[dynamic-attr]"]],
            [`<svelte:head><meta {...metaAttrs}></svelte:head>`, ["meta[dynamic-attr]"]],
            // 同一ブロックに両方あれば 2 件
            [
                `<svelte:head><title>A</title><meta name="description" content="B"></svelte:head>`,
                ["title", "meta[name=description]"],
            ],
        ];
        for (const [source, expected] of violations) {
            expect(findForbiddenHeadElements(source), source).toEqual(expected);
        }
    });

    it("正のコントロール: 許可される <svelte:head> の中身と、head 外の title は検出しない", () => {
        const allowed = [
            // <svelte:head> 自体は禁止しない (preload hint 等は正当な用途)
            `<svelte:head><link rel="preload" href="/fonts/x.woff2" as="font" /></svelte:head>`,
            `<svelte:head><meta name="theme-color" content="#1f2937"></svelte:head>`,
            // SVG の <title> は a11y の正当な用途。<svelte:head> の外なので対象外
            `<svg role="img"><title>再生</title><path d="" /></svg>`,
            // description という語が別文脈にあっても誤検出しない
            `<svelte:head><meta name="og:description-like" content="x"></svelte:head>`,
            // 無引用でも別の属性値なら誤検出しない (`\b` 境界で descriptionfoo を弾く)
            `<svelte:head><meta name=descriptionfoo content="x"></svelte:head>`,
            `<p>name="description" という文字列を本文に書いても対象外</p>`,
            // <svelte:head> が無ければ何も検出しない
            `<script lang="ts">const title = "ダッシュボード";</script><h1>{title}</h1>`,
        ];
        for (const source of allowed) {
            expect(findForbiddenHeadElements(source), source).toEqual([]);
        }
    });
});
```

### PHPStan 適合チェック

- 該当なし（TypeScript）。代わりに:
- [x] `pnpm typecheck`（`tsc --noEmit`）を通す
- [x] `pnpm lint`（eslint）を通す — ただし `eslint` の対象は
      `resources/js` のみ（`package.json` の `lint` スクリプト）なので
      本ファイルは lint 対象外。**フォーマットは既存テストに揃える**

### テスト計画

- [x] 予防 gate（違反 0 件）。**負のコントロールを先に書いて fail を確認**する
- [x] 新規テスト: 「`<svelte:head>` に title / meta description が存在しない」— 実ファイル走査
- [x] 新規テスト: 「走査が空振りしていない」— ページファイル数 > 0
- [x] 新規テスト: 負のコントロール（title / 属性順 2 通り / クォート 3 種
      (ダブル・シングル・**無引用**) / **式・スプレッド属性** 2 種 /
      同一ブロック 2 件 = 計 8 ケース）
- [x] 新規テスト: 正のコントロール（preload hint / theme-color /
      **SVG の `<title>`** / 紛らわしい語 2 種 / 本文中の文字列 / head 無し = 計 7 ケース）
- **全 15 ケースを Node で実走検証済み**（設計時点で ALL OK）
- 検証: `pnpm test`（`scripts/run-vitest.sh` = グローバルロック経由）

### リスク

- **SVG の `<title>` を誤検出するリスク**: `<svelte:head>` ブロック内に限定して
  検出するため、a11y 目的の SVG `<title>`（head 外）は影響を受けない。
  正のコントロールで固定する
- **属性値が式 / スプレッドの `<meta>`**（`<meta name={x}>` / `<meta {...attrs}>`）は
  「description ではないと静的に証明できない」ため **deny-by-default で fail させる**
  （`meta[dynamic-attr]`）。現状 0 件なので追加コストはなく、
  「静的に判定できないものを黙って通さない」という他 gate と同じ規律に揃う
- **`{@html}` で head の中身を丸ごと注入する書き方**は検出できない。
  ただし Svelte として極めて不自然であり、「今必要なものだけ作る」原則から対象外とする
- **`resources/js/pages` 以外**（`components/` 配下）は走査対象外。
  ページ以外のコンポーネントが `<svelte:head>` を持つのは
  現状 0 件であり、必要になったら走査範囲を広げる

---

## 施策 10: テンプレート差分レジストリへ D11 登録

### 変更箇所

- ファイル: `docs/template-divergence.md`（末尾に **D11** を追加）

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / テストファイル: なし

### 変更後コード（追記内容）

```markdown
## D11 ✅ ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| `<title>` の供給 | ページ側が JS helper 経由で宣言する契約 (helper 経由必須を frontend gate で強制) | サーバが単一 SoT。`SeoManager::resolveDocumentTitle()` が Blade `<title>` と Inertia 共有 prop `title` の両方へ同じ文字列を流す |
| `<meta name="description">` | ページ側 helper の守備範囲 | サーバのみ (`config('seo.default_description')` → `SeoMeta::$description` → `SeoRenderer::render()`)。認証配下 (`renderPrivate`) は**意図的に出さない** |
| frontend gate の役割 | 「helper を経由していること」を強制 | 「クライアントに第二 SoT を作らせない」を強制 (`<svelte:head>` の `<title>` / `<meta name="description">` を禁止) |

### なぜ正当な差分か(logic-driven)

本アプリは **SEO ヘッドをサーバ描画に一本化している** (`app/Support/Seo/`)。
クローラが読む正本はサーバが描画した `<head>` であり、title / canonical / og / JSON-LD は
すべて `config/seo.php` を起点に組み立てる。この構造では、ページ固有名の供給点は
`config('seo.app_titles')` (route 既定) と `SeoManager::setPrivateTitle()` (controller の
動的上書き) の 2 つで完結し、**JS helper を挟む層が存在しない**。

テンプレートの「helper 経由必須」契約は「ページ側が title の一次情報を持つ」前提の設計だが、
本アプリでは title の一次情報は **controller / config** が持つ。同じ契約を移植すると
一次情報が 2 箇所に分かれ、**フルロードと SPA 遷移でタイトルが食い違う**という
テンプレートが防ごうとしたまさにその破綻を招く。よって helper 契約は不採用とし、
同じ不変条件を「第二 SoT の禁止」という別の機構で保証する。

### 揃えている不変条件(これは保証し続ける)

> `<title>` の SoT はサーバ (`SeoManager::resolveDocumentTitle`) ただ 1 つであり、
> **フルロードと SPA 遷移で一致する** (共有 prop `title` + `resources/js/lib/document-title.ts`)。
> `<meta name="description">` は **サーバが生成する初回 HTML のみを SoT とし**、
> クライアントから第二 SoT や重複タグを作らない。

**title と description で射程が違う点に注意**: `HandleInertiaRequests::share()` が
共有するのは `title` のみで、description に SPA 同期経路は無い。description の読み手は
クローラであり、クローラが読むのは初回 HTML なので、SPA 遷移後の追従は保証しない
(必要になったら共有 prop + クライアント反映機構 + テストを別途設計する)。

どの機構でカバーするか:

- **`DocumentTitleCoverageTest`** (Architecture): Inertia を render する GET named route が
  必ずページ固有名を持つことを deny-by-default で強制する (未網羅は fail。
  action を静的解決できない route は理由付き allowlist が必須)
- **`tests/js/architecture/svelte-head-no-title.test.ts`**: `resources/js/pages/**/*.svelte` の
  `<svelte:head>` に `<title>` / `<meta name="description">` を書かせない
  (`<svelte:head>` 自体は preload hint 等のため許可)
- **`tests/Feature/Seo/SeoManagerTest.php`**: 解決優先順位と各 route の固有 title を仕様固定

### 関連

- 実装: `app/Support/Seo/SeoManager.php` / `app/Support/Seo/SeoRenderer.php` /
  `app/View/Composers/SeoComposer.php` / `app/Http/Middleware/HandleInertiaRequests.php` /
  `resources/js/lib/document-title.ts` / `config/seo.php`
- 設計: `devnotes/20260805-0101-architecture-gate-followup/`
- c2c 台帳: `gate-document-title-coverage` / `page-title-frontend-contract`
```

### PHPStan 適合チェック

- 該当なし（Markdown）

### テスト計画

- [x] 不変条件そのものは施策 6 / 施策 9 の gate と既存 `SeoManagerTest` が担保する
      （文書だけで終わらせない = 禁止事項 1）
- [x] D 番号の採番が既存と衝突しないこと（現在 D1〜D10。**D11** が次番）

### リスク

- なし（追記のみ。既存エントリは変更しない）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental**（1 worktree・1 ブランチで施策 1〜10 を順に積む） |
| 判断根拠 | 下記 |
| 競合リスク | 低（下記） |

### 判断根拠

1. **施策間に強い依存がある**。gate と是正はペアで、片方だけ入れると赤くなる:
   - 施策 1（gate）↔ 施策 2（8 箇所置換）: gate だけ入れると 8 件 fail
   - 施策 4（gate）↔ 施策 5（migration 2 件）: gate だけ入れると 2 件 fail
   - 施策 6（gate）↔ 施策 7（config 4 件）: gate だけ入れると 4 件 fail
   別 worktree に分けると「中間状態が常に赤い」ブランチが並走し、
   マージ順序の制約が生まれるだけで得がない
2. **共通の走査規約を共有する**。3 本の Architecture テストは
   `nextSignificant` 相当のヘルパ、空振り検知、負/正コントロールという
   同じ骨格を持つ。1 ブランチで書くほうが規約のブレが出ない
   （これが 4 件を 1 バッチに統合した理由そのもの）
3. **変更ファイルが小さく散っている**。新規 4 本 + 既存 10 ファイルの
   ピンポイント修正で、コンフリクトしやすい大きなファイルを触らない

### 実装順序（テストファーストを守る順）

各 gate は「**負のコントロールを書いて fail を確認 → 実走査を有効化して
既存違反で fail を確認 → 是正して green**」の順で進める（AGENTS.md 思考原則 5）。

1. 施策 1（Carbon gate）→ 8 件 fail を確認 → 施策 2（置換）→ green
2. 施策 3（AGENTS.md 追記）
3. 施策 4（非複合 use gate）→ 2 件 fail を確認 → 施策 5（migration）→ green
4. 施策 6（タイトル gate）→ 5 件 fail を確認 → 施策 7（config 4 件）+
   `debug.login` allowlist → green
5. 施策 8（招待無効分岐）: テスト追加 → fail 確認 → controller 修正
   （**この環境では実行不能**。CI で確認）
6. 施策 9（svelte:head gate）→ `pnpm test` で green
7. 施策 10（D11 登録）

### 競合リスク

- `config/seo.php`: 施策 7 のみが触る
- `AGENTS.md` / `docs/template-divergence.md`: 追記のみ。他 worktree の
  並行作業とコンフリクトしうるが、**追記位置が末尾または独立セクション**なので解決は容易
- `tests/Feature/Billing/*.php`: 施策 2 が 1 行ずつ触る。
  他タスクが同じ行を触る可能性は低い
- **新規ファイル 4 本**はコンフリクトしない

### 最終検証（全 green でコミット）

この devcontainer で実行可能なもの:

```bash
bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/CarbonOverflowArithmeticGateTest.php
bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/NoNonCompoundGlobalUseTest.php
bash scripts/with-global-test-lock.sh vendor/bin/pest tests/Architecture/DocumentTitleCoverageTest.php
composer phpstan
vendor/bin/pint --test
pnpm test
pnpm lint
pnpm typecheck
```

DB のある CI で追加確認するもの:

```bash
composer test        # Feature レーン全体 (施策 2 の置換後 / 施策 8 の新規テスト)
```

