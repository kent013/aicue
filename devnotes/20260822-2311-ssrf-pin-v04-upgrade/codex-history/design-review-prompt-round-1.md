# アプリの使命（North Star）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

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

# あなたの役割

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# この設計の位置づけ（重要な前提）

本設計は aicue 単独の思いつきではなく、**家系（6 リポジトリ）の機能台帳 lctl** の
feature `ssrf-pin-boundary`（canonical_version t0）が aicue セルへ明示指定した
`target_version` への**安全上の追従**である。原文:

> kent013/laravel-ssrf-pin を完全区間分類の版 (^0.4) へ改版し回帰テストで受ける (手本 spirux@a41aabbd)

- 概念設計は Codex（gpt-5.6-terra）レビューを 4 ラウンドで **APPROVED** 済み。
- 家系の版の現状: spirux `^0.4` / laravel-claude-template・aigenba・metamovics `^0.3` / aicue・motivation `^0.2`。
- 裁定 AG-003: 配布経路は VCS 参照 + 版指定に統一（版番号は裁定文へ焼き込まない）。
- 裁定 AG-003b の settle 論点（正典の版を 1 つ上げるか）は**別件**で、本追従とは独立。

## 特に厳しく見てほしい点

1. **施策 C のテストコードの正確性**。とくに
   (a) `deny_ip_literals: true` により IP literal URL が分類より前で切られるため
       「host → DNS 応答」経由で書く必要があるという前提、
   (b) `bind → forgetInstance → resolve` の 3 段手順、
   (c) Pest のデータセットの書き方と型、
   (d) グローバル名前空間の `const` の使い方（`NoNonCompoundGlobalUseTest` /
       Pest のファイル読み込み順との関係）。
2. **波及変更の網羅性**。既存 fixture が TEST-NET-3 を使っていたため
   版上げで 3 テストが赤くなることを実測で見つけて施策 D に入れたが、
   **他に見落ちている影響は無いか**。
3. **「検査を緩めている」形になっていないか**（施策 D）。
4. **乖離台帳の判断**（新規テストファイルを逸脱として登録しないと決めた根拠）が妥当か。
5. **スコープが過小になっていないか**（第二層契約検査を作らない判断など）。

---

## 詳細設計書

# 詳細設計: ssrf-pin-v04-upgrade

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

<!-- AGENTS.md の「使命 (North Star)」セクションの転記 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

<!-- AGENTS.md の「禁止事項」セクションの転記 -->

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。**実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、`PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する (deny-by-default なので exempt にする操作がレビューで必ず見える)。欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応するFactoryの作成も施策に含める** こと
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

---

## 正典の不変条件（全数列挙。これを満たす最小スコープにする）

家系の機能台帳 lctl の feature `ssrf-pin-boundary`（canonical_version t0）と、
そこに至る裁定・AGENTS.md から導出した不変条件を**全数**挙げる。
右列は本設計のどの施策 / どのテストがそれを保つかである。

| # | 不変条件 | 出所 | 本設計での担保 |
|---|---|---|---|
| I1 | **判定は `UrlSafetyInspector` に一元化されたまま**。deny 規則の本体を aicue 側で再実装しない | 正典 t0 / spirux の AGENTS.md が同趣旨を明文化 | 施策 A〜E のいずれも判定ロジックを書かない。**新設 gate も判定を再実装せず「package の判定結果」を観測するだけ**である |
| I2 | **`config/ssrf-pin.php` の pin 値 5 つを維持**（`allowed_schemes` / `allowed_ports` / `max_redirect_hops` / `additional_deny_cidrs` / `deny_ip_literals`） | 正典 t0（「設定の pin 値は 5 つで正典 t0 の形」） | **同ファイルを 1 文字も変更しない**（施策 0 = 不変の宣言）。既存 `SsrfPinBoundaryTest` が 5 値を固定し続ける |
| I3 | **境界 gate が緩まない**。`SsrfPinBoundaryTest` を弱めない・削らない | 正典 t0 の gates 一覧 | 同ファイルを変更しない。回帰は**別ファイル**で足す。新設 gate は古典区分が拒否され続けることも固定する（S2） |
| I4 | **外部 URL 取得は SSRF 検査経由**（`UrlSafetyInspector` / `PinnedHttpClient` を通す） | AGENTS.md セキュリティ不変条件 8 | 呼び出し側 `SnsCertificateFetcher` を変更しない。`SnsCertificateFetchContractTest`（既存）が取得口の唯一性を固定し続ける |
| I5 | **配布経路は VCS 参照 + 版指定**（版番号は裁定文に焼き込まない。版は正典の前進に追従する） | 裁定 AG-003（2026-08-14 の言い直し） | `composer.json` の `repositories` は VCS のまま。版制約だけを `^0.2` → `^0.4`。施策 B の合格条件が `source.type === "git"` と VCS URL を確認する |
| I6 | **`target_version` の中身 = 「完全区間分類の版 (^0.4) へ改版し、回帰テストで受ける」** | 正典 aicue セルの `target_version` | 施策 A/B が改版、施策 C が回帰テスト。**両方揃って初めて追従が済む** |
| I7 | **第二層（package 契約検査）は t0 の必須要素ではない** | 正典（差分巡回 2026-08-18 夕 第 2 ラウンド） | 新設しない（スコープ外に明記）。過大化を避ける根拠 |
| I8 | **TypeScript 側の URL 安全性判定は本 feature の boundary 外** | 正典 boundary（`capture-core-package` の管轄） | 触らない。aicue に該当実装は無い |
| I9 | **AG-003b の settle 論点（正典の版を 1 つ上げるか）を代行しない** | 裁定 AG-003b / 正典 `agenda_resolved` | aicue 1 リポジトリ分の安全追従に限る。他リポジトリの追従・正典側の版判断は扱わない |
| I10 | **採用時債務パスは「変更したまま債務に残す」が選べない** | `docs/template-divergence.md` / 突合 gate の `mutatedDebtPaths` | 債務パス 3 件（`config/ssrf-pin.php` / `tests/Architecture/SsrfPinBoundaryTest.php` / `tests/Support/SnsTestData.php`）を**いずれも変更しない**（§乖離台帳の確認段） |

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) — Codex 概念設計レビュー **APPROVED**（Round 4 / `gpt-5.6-terra`）
- レビュー履歴: `conceptual-review-round-1.md` 〜 `-round-4.md` /
  `codex-history/conceptual-review-decisions-round-{1,2,3}.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 0 | 触らないことの宣言（債務パス 3 件と呼び出し側） | （変更なし。検証手順のみ） | 必須 |
| A | 版制約を `^0.4` へ上げる | `composer.json` | 必須 |
| B | 当該パッケージだけを再解決する | `composer.lock` | 必須 |
| C | 塞がった区間の回帰 gate を新設する | `tests/Architecture/SsrfPinSpecialPurposeRangeRegressionTest.php`（新規） | 必須 |
| D | TEST-NET-3 fixture を公開到達可能なアドレスへ移す | `tests/Pest.php` / `tests/Feature/Mail/SnsCertificateFetcherTest.php` / `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` / `tests/Feature/Mail/SesSignatureMiddlewareTest.php` | 必須 |
| E | 不変条件 8 の記述を実態へ揃える | `AGENTS.md` | 必須 |

**実装順序**: D → C →（ここで **`composer test` を走らせて C が赤くなることを確認**）→ A → B →（緑になることを確認）→ E。
テストファーストの原則（AGENTS.md 思考原則 5）に従い、**版を上げる前に回帰テストが fail することを実測する**
（spirux@a41aabbd は 0.2 に対し 9 failed を確認してから実装した。同じ作法を踏む）。

> ★注意: D を C より先に置いている。D を後回しにすると、A/B の直後に
> 「C の赤（意図した fail）」と「既存 3 テストの赤（fixture の前提崩れ）」が同時に出て、
> **赤の原因を切り分けられなくなる**。D は版に依存せず単独で緑を保てる変更なので先に済ませる。

---

## 施策 0: 触らないことの宣言（債務パス 3 件と呼び出し側）

### 変更箇所

**なし**。以下は**変更してはならない**ことを実装記録として明示する。

| ファイル | 触らない理由 |
|---|---|
| `config/ssrf-pin.php` | I2（pin 値 5 つ維持）+ I10（採用時債務パス）。v0.4.1 の package 側 config が持つ `max_body_bytes`（1 MiB）は `SsrfPinServiceProvider::register()` の `mergeConfigFrom()` で package 既定が入る。aicue は `PinnedHttpClient` を 1 か所も使っていないので実効挙動に差が無い |
| `tests/Architecture/SsrfPinBoundaryTest.php` | I3 + I10。pin 値の固定と「境界で拒否できる」ことの検査は v0.4.1 でもそのまま通る（IP literal 拒否 / スキーム / ポートはいずれも分類層より**前**で決まるので、判定層の反転の影響を受けない） |
| `tests/Support/SnsTestData.php` | I10。fixture 定数の自然な置き場に見えるが債務パスなので、施策 D の値は `tests/Pest.php` へ置く |
| `app/Services/Mail/Sns/SnsCertificateFetcher.php` | I1 / I4。上流の後方互換 pin（`tests/Unit/BackwardCompatibilityTest.php`）により `UrlSafetyDecision` は無変更・`SsrfDenyReason` は case の追加のみなので**無改修で通る** |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし

### 検証手順（実装者が必ず行う）

```bash
git diff --name-only            # 上の 4 ファイルが 1 件も出ないこと
composer test -- --filter=SsrfPinBoundaryTest      # 版上げ後も緑であること
composer test -- --filter=SnsCertificateFetchContractTest  # 版上げ後も緑であること
```

### リスク

- `composer update` が `config/ssrf-pin.php` を上書きすることは無い
  （`publishes()` は `vendor:publish` のときだけ動く）。ただし
  `post-update-cmd` に `vendor:publish --tag=laravel-assets --force` があるので、
  **`--tag=ssrf-pin-config` は絶対に付けない**。
  施策 B の後に `git diff --name-only` で確認する手順がこれを捕まえる。

---

## 施策 A: 版制約を `^0.4` へ上げる

### 変更箇所

- ファイル: `composer.json`（`require` の該当 1 行）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（`composer.json` を pin している Architecture テストは
  `PhpstanWrapperInvariantTest` / `GlobalTestLockInventoryTest` / `ClaudeHooksWiringTest` /
  `EnvExampleInvariantTest` などだが、いずれも `scripts` / hooks / env を見ており
  `require` の版制約は見ていない。**版制約を pin する gate は aicue に無い** —
  だから施策 C の回帰 gate が「実際に何が入っているか」を独立に固定する必要がある）

### 現行コード

```json
        "kent013/laravel-prism-prompt": "^0.17.0",
        "kent013/laravel-ssrf-pin": "^0.2",
        "laravel/cashier": "^16.5",
```

### 変更後コード

```json
        "kent013/laravel-prism-prompt": "^0.17.0",
        "kent013/laravel-ssrf-pin": "^0.4",
        "laravel/cashier": "^16.5",
```

`repositories` は変更しない（I5）:

```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kent013/laravel-ssrf-pin.git"
        }
    ],
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている — 該当なし（JSON）
- [x] null安全 — 該当なし
- [x] DTOを返している — 該当なし
- [x] Genericsの型パラメータが正しい — 該当なし

### テスト計画

- [x] 施策 B と一体で検証する（`composer.lock` の再解決なしでは `composer install` が壊れる）
- [ ] `composer validate` が通ること
- [ ] `composer test` / `composer phpstan` 全数

### リスク

- `^0.4` は `>=0.4.0 <0.5.0` を意味する（`^` は 0.x でマイナー固定）。
  したがって将来 v0.5 が出ても自動では上がらない。**これは意図である** —
  判定層の次の変更を無言で取り込まない。

---

## 施策 B: 当該パッケージだけを再解決する

### 変更箇所

- ファイル: `composer.lock`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし

### 事前確認（更新の前に行う）

上流 v0.4.1 の `require` は次のとおり（clone して実読済み。v0.2.0 からの増分は下 2 行）:

```
php: ^8.2 / ext-curl: * / guzzlehttp/guzzle: ^7.5
illuminate/support: ^11.0|^12.0|^13.0 / webmozart/assert: ^1.11 || ^2.0
guzzlehttp/psr7: ^2.4          ← v0.4 で増えた
psr/http-message: ^1.1 || ^2.0 ← v0.4 で増えた
```

aicue の `composer.lock` の現物（実測）:

| パッケージ | lock の版 | v0.4.1 の制約 | 判定 |
|---|---|---|---|
| `guzzlehttp/guzzle` | 7.15.2 | `^7.5` | 満たす |
| `guzzlehttp/psr7` | 2.13.0 | `^2.4` | **満たす（新規取得なし）** |
| `psr/http-message` | 2.0 | `^1.1 \|\| ^2.0` | **満たす（新規取得なし）** |
| `webmozart/assert` | 2.4.1 | `^1.11 \|\| ^2.0` | 満たす |
| PHP | 8.4.24（`require: ^8.4`） | `^8.2` | 満たす |
| `laravel/framework` | v13.18.0 | `illuminate/support ^13.0` | 満たす |

→ **新規に取る必要がある依存は 0 件**の見込み。ただしこれは見積であり合格条件ではない。

### 実行コマンド

```bash
composer update kent013/laravel-ssrf-pin
```

- **`--with-all-dependencies` / `-W` は使わない**（依存全体の巻き添え解決を避ける）
- **`vendor:publish --tag=ssrf-pin-config` は付けない**（施策 0 のリスク欄）

### 合格条件（許容差分）

単位は**「パッケージのエントリ全体」**である。`autoload` / `description` /
`license` / `authors` / `extra` / `config` などのメタデータは版更新に伴って
正当に変わりうるので、フィールドを列挙して絞る形にはしない。

1. `composer.json`: 対象パッケージの版制約の行（施策 A の 1 行）
2. `composer.lock`: `kent013/laravel-ssrf-pin` の**パッケージエントリ全体**
3. `composer.lock`: 事前確認で「新規に取る必要がある」と判定した依存の
   **パッケージエントリの追加**（見積では 0 件）
4. `composer.lock` ルートの `content-hash`

エントリ全体を許容しても緩まないように、**中身を 4 点で別途確認する**:

| 確認項目 | 期待値 |
|---|---|
| `version` | `v0.4.1` |
| `source.type` / `source.url` | `git` / `https://github.com/kent013/laravel-ssrf-pin.git`（I5） |
| `source.reference` | `93ba837c661bf2c31b6801c4c9ad866bdff4445e` |
| `require` | 上流 v0.4.1 の `composer.json` の `require` と一致 |

> ★`source.reference` は**注釈を剥がした commit** である。
> `git ls-remote --tags` の出力で `refs/tags/v0.4.1` は `37b80705e0799c682fc89f05b8a8619661cd35d8`
> （注釈つきタグの object id）、`refs/tags/v0.4.1^{}` が
> `93ba837c661bf2c31b6801c4c9ad866bdff4445e`（実際の commit）である。
> **前者を定数に据えると lock の参照と食い違う**。aigenba:T1203 が同じ罠を明記している。

そのうえで **「上記以外の既存パッケージについて、名前 → 版の写像が不変」**を機械照合する。
1 件でも動いていたら**やり直す**。

```bash
# 版上げ前に取っておく
git show HEAD:composer.lock > /tmp/lock-before.json
# 版上げ後
python3 - <<'PY'
import json
def m(p):
    d=json.load(open(p))
    return {q['name']: q['version'] for q in d['packages']+d.get('packages-dev',[])}
b, a = m('/tmp/lock-before.json'), m('composer.lock')
moved = {k: (b.get(k), a.get(k)) for k in set(b) | set(a) if b.get(k) != a.get(k)}
print(len(b), '->', len(a), 'packages')
print('moved:', json.dumps(moved, indent=1))
PY
```

期待出力は `moved` が `kent013/laravel-ssrf-pin: (v0.2.0, v0.4.1)` の **1 件だけ**
（新規依存が必要だった場合はその追加のみが増える）。

### PHPStan適合チェック

- [x] 該当なし（JSON）。ただし `composer phpstan` は vendor の型変化の影響を受けるので全数実行する

### テスト計画

- [ ] `composer validate`
- [ ] `composer install` がクリーンに通る（lock と json が整合）
- [ ] 上記の Python 照合で `moved` が 1 件
- [ ] `composer test` 全数 / `composer phpstan` level 10 / `vendor/bin/pint --test`
- [ ] 施策 C の gate が**緑になる**（版上げ前は赤だったこと＝ fail の実測が済んでいること）

### リスク

- **アプリコードの型が壊れる可能性**: `SsrfDenyReason` に case が増えたので、
  この enum を `match` で**既定分岐なしに**受けている箇所があれば PHPStan が赤くなる。
  aicue の該当箇所を実読済み — `SnsCertificateFetcher::inspect()` は
  `if ($decision->reason === SsrfDenyReason::DnsResolutionFailed)` の**単一比較**であり
  網羅 `match` を持たないので影響しない（aigenba は `SsrfReasonMapper` の網羅 `match` を
  持っていたので追従が要った。aicue にはその層が無い）。
- **`NotGloballyReachable` は 403 側へ落ちる**: `SnsCertificateFetcher::inspect()` は
  「`DnsResolutionFailed` だけ 503、それ以外は 403（恒久）」なので、
  新しい理由は自動的に恒久拒否になる。これは正しい扱いである
  （SNS の証明書 host が公開到達不可なアドレスへ解決される状態は再送では直らない）。
  `docs/architecture.md` の「DNS 解決失敗のみ 503、他は 403」という記述も引き続き正しい。

---

## 施策 C: 塞がった区間の回帰 gate を新設する

### 変更箇所

- ファイル: `tests/Architecture/SsrfPinSpecialPurposeRangeRegressionTest.php`（**新規**）

置き場所の理由: DB を使わない（`tests/Pest.php` は Architecture レーンに
`RefreshDatabase` を付けていない）。既存の `SsrfPinBoundaryTest` と同じレーン・同じ関心事。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規 1 本。**既存テストの削除・上書きはしない**（禁止事項 3）

### 現行コード

該当ファイルは存在しない。既存の `tests/Architecture/SsrfPinBoundaryTest.php` は
pin 値と「境界で拒否できる」ことだけを見ており、**分類層の中身は 1 件も見ていない**
（IP literal / スキーム / ポートは分類より前で決まる）。ここが本施策で埋める穴である。

### 変更後コード

```php
<?php

declare(strict_types=1);

/*
 * SSRF 判定の完全区間分類への追従の回帰 gate（家系の feature ssrf-pin-boundary の
 * aicue セル target_version。手本 spirux@a41aabbd）。
 *
 * ## なぜ SsrfPinBoundaryTest と別ファイルなのか
 *
 * 隣の SsrfPinBoundaryTest は「config/ssrf-pin.php の pin 値」と「境界で拒否できること」を
 * 固定するが、そこで拒否されるのは IP literal / 許可外スキーム / 許可外ポートであり、
 * いずれも**分類層より前**で決まる。つまり**判定層が何を拒否するかは 1 件も見ていない**。
 * 本 gate はその層だけを見る。加えて SsrfPinBoundaryTest は採用時債務パスなので、
 * 触ると債務の整理が連鎖する（`tests/Support/TemplateDivergence/adoption-debt.tsv`）。
 *
 * ## 何を固定するか
 *
 * package `kent013/laravel-ssrf-pin` は ^0.4 で判定を反転した — 列挙型の拒否リストから、
 * IANA Special-Purpose Address Registry を写した**完全区間分類**へ変わり、
 * 「公開到達可能と分類できた IP だけを許可」する既定拒否になった。
 * ^0.2 / ^0.3 の列挙型拒否では、列挙に無い特殊用途アドレス 8 区間が
 * 「拒否規則に該当しない = 許可」として素通りしていた（本リポジトリの vendor v0.2.0 で実測）。
 * 本 gate はその 8 区間が拒否されること、従来から拒否していた区分が緩んでいないこと、
 * 公開到達可能なアドレスは通ること、混在応答が拒否されることを固定する。
 *
 * ## 判定を再実装しない
 *
 * deny 規則の本体は共有パッケージ側にある（家系の不変条件）。本 gate は
 * **package の判定結果を観測するだけ**で、CIDR も区間表もアプリ側に持たない。
 *
 * ## 本 gate が保証しないもの（誇張しない）
 *
 *  1. **登録簿の陳腐化は検知しない**。R1 が見るのは「導入した版の中の登録簿が
 *     変わったか」だけで、IANA 側の更新は 1 度も参照しない。パッケージを更新しなければ
 *     緑のままである。定期の見直しは上流（kent013/laravel-ssrf-pin）と家系の巡回の責務。
 *  2. **区間分割の完全性は検証しない**。隙間・重複・覆い漏れの検査は package が
 *     load 時に行う（崩れていれば例外）。ここで写して二重管理にしない。
 *  3. **実到達性は検証しない**。DNS 応答はすべて FakeDnsResolver の固定値で、
 *     外向き通信は 1 度も起きない（全レーンで StrayHttpRequestGuard が既定拒否）。
 *  4. **呼び出し側の経路は見ない**。SNS 証明書取得が SSRF 検査を通ることは
 *     tests/Architecture/SnsCertificateFetchContractTest.php と
 *     tests/Feature/Mail/SnsCertificateFetcherTest.php の担当である。
 */

use Kent013\SsrfPin\Contracts\DnsResolverInterface;
use Kent013\SsrfPin\Dtos\UrlSafetyDecision;
use Kent013\SsrfPin\Enums\SsrfDenyReason;
use Kent013\SsrfPin\Testing\FakeDnsResolver;
use Kent013\SsrfPin\UrlSafetyInspector;

/**
 * 判定に使う登録簿の版（IANA Special-Purpose Address Registry の発行日）。
 * package v0.4.1 同梱の resources/ip-classification.json の registry_version。
 */
const SSRF_CLASSIFICATION_REGISTRY_VERSION = '2025-10-09';

/** 観測用の host。IP literal ではなく**名前**であることが本質（下の docblock 参照）。 */
const SSRF_PROBE_HOST = 'probe.ssrf-pin.test';

/**
 * 分類層の判定を「host → DNS 応答」経由で観測する。
 *
 * ★**IP literal URL を使ってはならない。** config/ssrf-pin.php の `deny_ip_literals` は
 *   true で、`inspect()` は IP literal を**分類より前に** `IpLiteralNotAllowed` で切る。
 *   IP literal で書くと 8 区間を 1 つも検査しないまま緑になる（偽グリーン）。
 *   手本の spirux はこのキーを true にしていないのでそのまま写せない。
 *
 * ★**手順は 3 段で、順序が本質である。**
 *   `SsrfPinServiceProvider::register()` は `UrlSafetyInspector` を `singleton()` で
 *   登録しているので、`DnsResolverInterface` を後から bind しても
 *   **既に解決済みの instance は作り直されない** = 前のケースの DNS 応答で判定してしまう。
 *   `forgetInstance()` を必ず挟む（`tests/Pest.php::bindSnsDnsResolver()` と同じ作法）。
 *   この差し替えが実際に効いていることは R2（負のコントロール）が固定する。
 *
 * ★`UrlSafetyInspector` 自体は差し替えない（`ExternalFakeDeclaration::neverSwapped()` が
 *   「偽物にすると内部宛ての取得が通る」として禁じている）。差し替えるのは**その依存**である。
 *
 * @param  list<string>  $ipv4  A レコードの応答
 * @param  list<string>  $ipv6  AAAA レコードの応答
 */
function ssrfProbe(array $ipv4, array $ipv6 = []): UrlSafetyDecision
{
    app()->bind(
        DnsResolverInterface::class,
        fn (): DnsResolverInterface => new FakeDnsResolver(
            [SSRF_PROBE_HOST => $ipv4],
            [SSRF_PROBE_HOST => $ipv6],
        ),
    );
    app()->forgetInstance(UrlSafetyInspector::class);

    return app(UrlSafetyInspector::class)->inspect('https://'.SSRF_PROBE_HOST.'/probe');
}

/** IPv4 を A レコードとして、IPv6 を AAAA レコードとして振り分ける。 */
function ssrfProbeSingle(string $ip): UrlSafetyDecision
{
    return str_contains($ip, ':') ? ssrfProbe([], [$ip]) : ssrfProbe([$ip]);
}

/*
|--------------------------------------------------------------------------
| S1: ^0.2 / ^0.3 の列挙型拒否が素通りさせていた IANA 特殊用途 8 区間
|--------------------------------------------------------------------------
| ★ケースを畳まない。区間名がケース名として読め、期待理由が個別に書かれていること。
|   本 gate は aicue が第二層（package 契約検査）を持たない以上、
|   「入った版が実際に何を備えているか」を見る唯一の検査である。
|   1 件そっと削る変更がレビューで見えなければならない。
*/
test('S1 素通りしていた IANA 特殊用途 8 区間を拒否する', function (string $ip): void {
    $decision = ssrfProbeSingle($ip);

    expect($decision->allowed)->toBeFalse("expected deny for {$ip}")
        ->and($decision->reason)->toBe(SsrfDenyReason::NotGloballyReachable, "for {$ip}");
})->with([
    'TEST-NET-1 (192.0.2.0/24)' => '192.0.2.1',
    'TEST-NET-2 (198.51.100.0/24)' => '198.51.100.7',
    'TEST-NET-3 (203.0.113.0/24)' => '203.0.113.5',
    '6to4 relay anycast (192.88.99.0/24)' => '192.88.99.1',
    'IPv6 documentation (2001:db8::/32)' => '2001:db8::1',
    'IPv6 6to4 (2002::/16)' => '2002::1',
    'IPv6 documentation new (3fff::/20)' => '3fff::1',
    'SRv6 SIDs (5f00::/16)' => '5f00::1',
]);

/*
|--------------------------------------------------------------------------
| S2: 従来から拒否していた区分が緩んでいないこと
|--------------------------------------------------------------------------
| 判定の反転（列挙型 → 完全区間分類）で、既に塞がっていた宛先が開くことがあってはならない。
| 理由コードまで固定するのは、区間の分類が別カテゴリへずれた形も検出するためである。
*/
test('S2 判定の反転で従来の拒否が緩んでいない', function (string $ip, SsrfDenyReason $reason): void {
    $decision = ssrfProbeSingle($ip);

    expect($decision->allowed)->toBeFalse("expected deny for {$ip}")
        ->and($decision->reason)->toBe($reason, "for {$ip}");
})->with([
    'loopback' => ['127.0.0.1', SsrfDenyReason::Loopback],
    'private 10/8' => ['10.0.0.5', SsrfDenyReason::PrivateRange],
    'link local (IMDS)' => ['169.254.169.254', SsrfDenyReason::LinkLocal],
    'CGNAT' => ['100.64.0.1', SsrfDenyReason::PrivateRange],
    'IPv6 ULA' => ['fc00::1', SsrfDenyReason::PrivateRange],
    'IPv6 link local' => ['fe80::1', SsrfDenyReason::LinkLocal],
]);

/*
|--------------------------------------------------------------------------
| S3: 正のコントロール
|--------------------------------------------------------------------------
| ★これが無いと「何かの理由で常に deny になる壊れ方」（config の取り違え・
|   分類表の読み込み失敗など）で全ケースが緑になる。
*/
test('S3 正のコントロール: 公開到達可能なアドレスは通る', function (string $ip): void {
    expect(ssrfProbeSingle($ip)->allowed)->toBeTrue("expected allow for {$ip}");
})->with([
    'public v4' => '93.184.216.34',
    'public v6' => '2606:2800:220:1:248:1893:25c8:1946',
]);

/*
|--------------------------------------------------------------------------
| S4: 応答の全件検査
|--------------------------------------------------------------------------
| inspect() は A + AAAA の**全件**を分類し、1 件でも非公開なら拒否する。
| ここが緩むと、攻撃者は公開 IP を 1 つ混ぜるだけで通せる。
*/
test('S4 公開 IP が混ざっていても非公開が 1 件あれば拒否する', function (): void {
    $decision = ssrfProbe(['93.184.216.34', '192.0.2.1']);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe(SsrfDenyReason::NotGloballyReachable);
});

/*
|--------------------------------------------------------------------------
| R1: 判定に使われた登録簿の版
|--------------------------------------------------------------------------
| 安全境界の一部が**同梱の登録簿の内容**になった。上流が登録簿を更新すれば
| ここが赤くなる。**これは意図である** — 更新時に登録簿の差分と S1〜S4 の
| 全ケースを見直すための入口として置く。
|
| ★config/ssrf-pin.php へ registry_version を足す代わりの手当である
|   （同ファイルは pin 値 5 つ維持の対象かつ採用時債務パスなので触らない）。
| ★**陳腐化の検知ではない**（上の「保証しないもの」1 を参照）。
*/
test('R1 判定に使われた分類表の登録簿の版が pin されている', function (): void {
    expect(app(UrlSafetyInspector::class)->classificationRegistryVersion())
        ->toBe(SSRF_CLASSIFICATION_REGISTRY_VERSION);
});

/*
|--------------------------------------------------------------------------
| R2: 負のコントロール（この gate 自身の実効性）
|--------------------------------------------------------------------------
| ssrfProbe() の 3 段手順（bind → forgetInstance → resolve）が本当に効いていることを
| **同一テストの中で 2 回呼んで**固定する。forgetInstance を落とすと
| 2 回目が 1 回目の singleton で判定され、この test が落ちる。
| これが無いと、差し替えが効かなくなっても S1 が「1 件目の応答」で緑になる形の
| 偽グリーンに気付けない。
*/
test('R2 負のコントロール: DNS 応答の差し替えが singleton を貫いて効く', function (): void {
    expect(ssrfProbe(['93.184.216.34'])->allowed)->toBeTrue('1 回目: 公開到達可能')
        ->and(ssrfProbe(['192.0.2.1'])->allowed)->toBeFalse('2 回目: TEST-NET-1');
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`ssrfProbe(): UrlSafetyDecision` /
      `ssrfProbeSingle(): UrlSafetyDecision` / test closure は `: void`）
- [x] null安全 — `UrlSafetyDecision::$reason` は `?SsrfDenyReason` だが
      `toBe(enum)` で比較するだけなので dereference しない
- [x] DTOを返している — package の `UrlSafetyDecision`（readonly DTO）をそのまま返す。配列返却なし
- [x] Genericsの型パラメータが正しい — `@param list<string>` を A / AAAA の両方に明示。
      `FakeDnsResolver` は `array<string, list<string>>` を受けるので形が一致する
- [x] `mixed` へ逃げていない・自作の fake resolver を作っていない
      （package 同梱の `Kent013\SsrfPin\Testing\FakeDnsResolver` を使う）
- [x] 拒否理由は `SsrfDenyReason` の case を渡す（文字列比較にしない）
- 注: `phpstan.neon` の `paths` は `app / config / database / routes` なので
  `tests/` は解析対象外。それでも上記を守る（`tests/` を解析対象へ広げたときに
  赤くならない書き方にしておく）

### 設計時点での実測（S1〜R2 の全 assertion を両方の版で実行済み）

上のテスト本体と**同一の assertion**を素の PHP スクリプトへ写し、
(a) 本リポジトリの `vendor/kent013/laravel-ssrf-pin`（v0.2.0）と
(b) 上流を clone した v0.4.1 の 2 つに対して実行した
（config pin 値は aicue の 5 値をそのまま与えた）。

| 版 | 失敗数 | 内訳 |
|---|---|---|
| **v0.2.0（現状）** | **11** | S1 × 8（全ケースが `allowed=true` / `reason=null` = 素通り）+ S4（混在も `allowed=true`）+ R1（`Error: Call to undefined method …::classificationRegistryVersion()`）+ R2（2 回目も `allowed=true`） |
| **v0.4.1（追従後）** | **0** | 全ケース緑 |

S2（6 ケース）と S3（2 ケース）は**両方の版で緑**である
（列挙型拒否が既に持っていた区分と公開アドレスなので、判定の反転で挙動が変わらない
= これが「緩んでいない」ことの実測である）。

→ 実装時に `composer test -- --filter=SsrfPinSpecialPurposeRange` で観測すべき
**版上げ前の期待 fail は 11 件**である（Pest のデータセットは 1 ケース 1 test として
数えられるので、この 11 という数はそのまま test 単位の失敗数になる）。
版上げ後は 0 件。

> ★spirux@a41aabbd は同種の回帰テストで 0.2 に対し **9 failed** を記録した。
> aicue は S1 の 8 件と S4 に加えて R1（登録簿の版）と R2（差し替えの実効性）を
> 足したので 11 件になる。数の差は検査の粒度の差であり、対象範囲は同じである。

### 既存 gate との作法の整合（実装時に必ず守る）

| gate | 要求 | 本ファイルでの充足 |
|---|---|---|
| `StrictTypesDeclarationGateTest` | `declare(strict_types=1);` | 1 行目に置く |
| `NoNonCompoundGlobalUseTest` | グローバル名前空間で**非複合名の `use` を書かない** | `use` は 5 本すべて名前空間つき。`use ReflectionMethod;` のような global class の import は**書かない**（必要になったら `\ReflectionMethod` と完全修飾で書く） |
| `ForbiddenStatementTokenInvariantTest` | 出力する文 / 飛び越す文 / 大域を持ち込む文 / 開始タグ付き出力記法を書かない | `echo` / `print` / `goto` / `global` / `<?=` を 1 つも使わない |
| `CachePayloadPlainDataGateTest` | キャッシュに触るファイルは面目録へ登録が要る | **キャッシュ API を 1 つも呼ばない**ので登録不要 |
| `StrayHttpEgressLaneGateTest` / `StrayHttpRequestGuard` | 外向き HTTP は既定拒否 | HTTP を 1 度も出さない（DNS も `FakeDnsResolver` 固定値） |
| `ExternalFakeWiringInvariantTest` 3-14 | `neverSwapped()` の abstract と `swaps()` の abstract が交わらない | `UrlSafetyInspector`（neverSwapped）を差し替えず、その依存 `DnsResolverInterface`（どちらの一覧にも無い）を bind する。`tests/Pest.php::bindSnsDnsResolver()` と同じ作法 |
| `FakeClassReferenceInvariantTest` | 本番コード（`app/` `routes/` `config/` `bootstrap/`）が fake クラス名を参照しない | 本ファイルは `tests/` 配下なので走査根の外 |
| `BaseTestDatabaseSchemaTest` | Architecture レーンは DB を使わない | DB に触らない |

### テスト計画

- [ ] **バグ修正の再現テストを先に書く**: 本施策そのものが再現テストである。
      **施策 A/B より前に**このファイルを追加し、`composer test -- --filter=SsrfPinSpecialPurposeRange` を
      実行して **S1 の 8 ケースと S4 が fail（計 9 件）、R1 が fail（`classificationRegistryVersion()` が
      v0.2.0 に存在せず `Error` になる）** ことを実測する。
      S2（6 件）と S3（2 件）は v0.2.0 でも緑である。
      → **版上げ前の期待 fail は S1 の 8 件 + S4 + R1 + R2 = 11 件**（上の
      「設計時点での実測」で両版に対して実行して確認済み）。この実測値を実装記録に残す。
- [ ] 版上げ後に全ケースが緑になること
- [ ] 既存テスト `tests/Architecture/SsrfPinBoundaryTest.php` は**無改修で緑**であること
- [ ] 個別の `DatabaseTransactions` を使っていないこと（使わない）
- [ ] `--parallel` 実行で緑であること（コンテナ差し替えはテストごとに
      `refreshApplication` でリセットされるので他テストへ漏れない）

### リスク

- **`const` をテストファイルの先頭に置くこと**: Pest のテストファイルに書いた
  `const` は**そのファイルが読み込まれた後にしか見えない**（`LedgerPins` の docblock が
  同じ注意を書いている）。本ファイル内でしか参照しないので問題ないが、
  他ファイルから参照したくなったらクラス定数へ移すこと。
- **`FakeDnsResolver` は host を完全一致で引く**。`SSRF_PROBE_HOST` は
  正規化後（小文字・末尾ドットなし）の形で書く必要がある。`probe.ssrf-pin.test` は
  すでにその形である。
- **`.test` TLD を使う理由**: RFC 6761 の予約 TLD で、実在しない。
  `localhost` / `*.localhost` は `inspect()` が分類より前に `Loopback` で切るので使えない。

---

## 施策 D: TEST-NET-3 fixture を公開到達可能なアドレスへ移す

### 変更箇所

- ファイル: `tests/Pest.php`（`bindSnsDnsResolver()` の直後に fixture の出所を新設）
- ファイル: `tests/Feature/Mail/SnsCertificateFetcherTest.php` (L38)
- ファイル: `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` (L16)
- ファイル: `tests/Feature/Mail/SesSignatureMiddlewareTest.php` (L25)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 上記 4 件が本施策の対象そのもの。
  **`tests/Support/SnsTestData.php` は変更しない**（採用時債務パス。I10）。
  他の `203.0.113.x` / `192.0.2.x` / `2001:db8::` の出現
  （`RateLimiterKeysTest` / `RateLimiterKeyConventionTest` / `PasswordSetupTest` /
  `RecaptchaVerifierFakeWiringTest` / `PasskeyOriginCanonicalizerTest` /
  `PasskeyConfigValidatorTest` / `TrustedProxyTokenTest` /
  `TrustedProxiesConfigValidatorTest`）は `UrlSafetyInspector` を通らないので
  **1 件も変更しない**（全数確認済み）。

### 現行コード

`tests/Pest.php`（L391-407）:

```php
/**
 * SNS 証明書取得テスト用に DNS 解決を固定する。
 *
 * `UrlSafetyInspector` そのものは ExternalFakeDeclaration::neverSwapped() により偽物に
 * できないので、差し替えるのは**その依存**である DnsResolverInterface である。
 * inspector は singleton なので、bind 後に作り直させる。
 *
 * @param  list<string>  $ips  空配列なら「DNS 解決失敗」を模す
 */
function bindSnsDnsResolver(array $ips): void
{
    app()->bind(
        DnsResolverInterface::class,
        fn (): DnsResolverInterface => new FakeDnsResolver(['sns.us-east-1.amazonaws.com' => $ips]),
    );
    app()->forgetInstance(UrlSafetyInspector::class);
}
```

3 つの呼び出し側（いずれも `beforeEach` の中）:

```php
// tests/Feature/Mail/SnsCertificateFetcherTest.php L38
    bindSnsDnsResolver(['203.0.113.10']);
// tests/Unit/Mail/AwsSnsSignatureVerifierTest.php L16
    bindSnsDnsResolver(['203.0.113.10']);
// tests/Feature/Mail/SesSignatureMiddlewareTest.php L25
    bindSnsDnsResolver(['203.0.113.10']);
```

### 変更後コード

`tests/Pest.php` — `bindSnsDnsResolver()` は**そのまま**にして、直後に出所を足す:

```php
/**
 * SNS 証明書 host の DNS 応答に使う「公開到達可能」な fixture（**出所はここ 1 か所**）。
 *
 * ★この値は**分類表が globally reachable と判定する DNS 応答値**である。
 *   実在ホストの検証でも実到達性の検証でもない（全レーンで StrayHttpRequestGuard が
 *   外向き HTTP を既定拒否している）。
 *   **ここから「本当に到達するか」を確かめる外向き通信を足さない。**
 *
 * ★もとは TEST-NET-3（203.0.113.10）を使っていたが使えなくなった。
 *   package kent013/laravel-ssrf-pin が ^0.4 で判定を完全区間分類へ反転し、
 *   TEST-NET-3 は IANA 登録簿どおり `NotGloballyReachable` へ分類されるためである
 *   （^0.2 の列挙型拒否では素通りしていた = そもそも fixture として不適切だった）。
 *   塞がった区間の一覧と回帰は
 *   tests/Architecture/SsrfPinSpecialPurposeRangeRegressionTest.php が固定する。
 *
 * ★値を変えるときは「分類表が公開到達可能とする区間か」を上記 gate で確かめること。
 *
 * @return list<string>
 */
function snsPublicCertHostIps(): array
{
    return ['93.184.216.34'];
}
```

3 つの呼び出し側:

```php
    bindSnsDnsResolver(snsPublicCertHostIps());
```

**他の `bindSnsDnsResolver()` 呼び出しは変更しない** — 意図的に非公開・解決失敗を
模しているものである:

| 呼び出し | 引数 | v0.4.1 での扱い | 変更 |
|---|---|---|---|
| `SnsCertificateFetcherTest` L124 | `['10.0.0.5']` | `PrivateRange` で拒否（従来どおり） | なし |
| `SnsCertificateFetcherTest` L134 | `[]` | `DnsResolutionFailed`（従来どおり） | なし |

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`: array` + `@return list<string>`）
- [x] null安全 — 該当なし
- [x] DTOを返している — 該当なし（テスト fixture の値。DTO を作るのは過剰）
- [x] Genericsの型パラメータが正しい（`list<string>` が `bindSnsDnsResolver(array $ips)` の
      `@param list<string>` と一致する）

### テスト計画

- [ ] **施策 A/B より前に**この変更だけを入れ、
      `composer test -- --filter=SnsCertificateFetcher` /
      `--filter=AwsSnsSignatureVerifier` / `--filter=SesSignatureMiddleware` が
      **v0.2.0 のままで緑**であることを確認する
      （93.184.216.34 は v0.2.0 でも allow なので緑になる。これが
      「D は版に依存しない」ことの実測である）
- [ ] 版上げ後も同 3 本が緑であること
- [ ] とくに `SnsCertificateFetcherTest` の
      「F0（正のコントロール）: 正常系 fixture は SSRF 検査を通る」が緑であること
      — この test は「境界が変わったらここが最初に赤くなる」ことを目的に置かれた検査で、
      **設計どおりに機能した**（版上げで赤くなるのを本施策が正しく解消する）
- [ ] 個別の `DatabaseTransactions` を使っていないこと（使わない）

### リスク

- **「検査を緩める」変更に見えうる**。緩めていないことの根拠を実装記録に明記する:
  この fixture は「SSRF 検査を**通る**正常系」を表すためのものであり、
  ^0.2 では TEST-NET-3 が誤って allow されていたので**偶然成立していた**にすぎない。
  「private IP は拒否される」（L124）「DNS 解決失敗は 503」（L134）という
  **拒否側の検査は 1 つも触っていない**。
  さらに施策 C の S1 が TEST-NET-3 を**拒否側**として明示的に固定するので、
  「TEST-NET-3 を allow として使う」ことは以後できない。
- **並列実行での漏れ**: `bindSnsDnsResolver()` はコンテナへの bind なので、
  Pest の `refreshApplication` でテストごとにリセットされる（既存の作法どおり）。

---

## 施策 E: 不変条件 8 の記述を実態へ揃える

### 変更箇所

- ファイル: `AGENTS.md`（「セキュリティ不変条件(アプリ都合で緩めない)」の項番 8）

`AGENTS.md` は `docs/template-fingerprints.json` の母集合外（突合 gate の docblock が
「アプリ固有ファイル」として名指ししている）なので、乖離台帳の登録は不要。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし。
  なお `AGENTS.md` の「使命 (North Star)」セクションは
  `app-codex-review` スキルがレビュー使命として参照するが、本施策は
  セキュリティ不変条件の項なので影響しない。

### 現行コード

```markdown
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
```

### 変更後コード

```markdown
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)。
   **判定は「公開到達可能と分類できた IP だけを許可」する既定拒否である**
   (package `^0.4` 以降。IANA Special-Purpose Address Registry を写した完全区間分類で
   アドレス空間を分割し、未分類と表の破損は拒否 / load 時例外)。
   したがって**安全境界の一部は同梱の登録簿の内容**であり、判定に使われた登録簿の版と、
   塞がっている区間・従来の拒否が緩んでいないこと・公開到達可能なら通ることを
   `SsrfPinSpecialPurposeRangeRegressionTest` が固定する
   (aicue の pin 値は `deny_ip_literals: true` なので、この gate は
   **IP literal ではなく DNS 応答経由**で判定層を観測する。IP literal で書くと
   分類より前に切られて 1 件も検査しない偽グリーンになる)。
   **監視条件**: 版上げでこの gate が赤くなったら、登録簿の差分と回帰ケースを
   見直してから追従する (判定の deny 規則を**アプリ側で再実装しない** — 正本は
   共有パッケージ `kent013/laravel-ssrf-pin` にある)。
   ★**登録簿の陳腐化は機械では見ていない** — 見るのは同梱の登録簿が変わったかだけで、
   IANA 側の更新は参照しない。定期の見直しは上流と家系の巡回の責務である
```

### PHPStan適合チェック

- [x] 該当なし（Markdown）

### テスト計画

- [ ] `AGENTS.md` を pin する gate は無いことを確認済み（`grep -rn "AGENTS.md" tests/`）。
      ただし `app-codex-review` / `app-design` スキルが本ファイルの
      「使命 (North Star)」「禁止事項」セクションを見出しで抽出するので、
      **見出しの文字列を変えない**こと（本施策は見出しに触らない）
- [ ] `composer test` 全数（Markdown 変更なので影響しないが、
      施策 A〜D と同じ枝で最終確認する）

### リスク

- **記述が長くなる**。ただし本ファイルの不変条件 4 / 11 は同程度の長さで
  「監視条件」「保証しないもの」まで書く形になっており、作法として揃っている。
- **同じ内容を 2 か所に書かない**。保証範囲の正本は施策 C の gate の docblock に置き、
  `AGENTS.md` にはその要点と gate の名前を書く（詳細は写さない）。
  この方針は突合 gate の docblock が「ここが正本であり AGENTS.md や
  docs/template-divergence.md には写さない」と宣言しているのと同じ形である。

---

## 乖離台帳の確認段（app-design Phase 3-0）

判定軸は「`docs/template-fingerprints.json` の `entries`（281 キー）に
そのパスが在るか」である。実測結果:

| 変更ファイル | 指紋台帳 | 採用時債務 | 台帳への対応 |
|---|---|---|---|
| `composer.json` | **無** | 無 | 不要（突合 gate の docblock が「アプリ固有ファイル」として名指し） |
| `composer.lock` | **無** | 無 | 不要 |
| `AGENTS.md` | **無** | 無 | 不要（同上） |
| `tests/Pest.php` | **無** | 無 | 不要（同上） |
| `tests/Feature/Mail/SnsCertificateFetcherTest.php` | **無** | 無 | 不要 |
| `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` | **無** | 無 | 不要 |
| `tests/Feature/Mail/SesSignatureMiddlewareTest.php` | **無** | 無 | 不要 |
| `tests/Architecture/SsrfPinSpecialPurposeRangeRegressionTest.php`（新規） | **無**（新規なので母集合外） | 無 | 下記の判断 |

**変更しないことにした債務パス**（触れば台帳作業が連鎖するため設計で回避した）:

| パス | 指紋台帳 | 採用時債務 | 回避方法 |
|---|---|---|---|
| `config/ssrf-pin.php` | 有 | **有** | 施策 0。pin 値 5 つ維持（I2）なので元より変更しない |
| `tests/Architecture/SsrfPinBoundaryTest.php` | 有 | **有** | 施策 0。回帰は別ファイルで足す（I3） |
| `tests/Support/SnsTestData.php` | 有 | **有** | 施策 D の値を `tests/Pest.php` へ置く |

→ **`docs/template-divergence.md` への登録の追加も削除も無く、
`tests/Support/TemplateDivergence/LedgerPins.php` の件数も変えない**
（`DIVERGENCE_ENTRY_COUNT = 36` / `FINGERPRINT_POPULATION_COUNT = 281` /
`ADOPTION_DEBT_COUNT = 171` はいずれも据え置き）。

### 新規テストファイルを逸脱として登録しない判断（根拠を明示する）

`docs/template-divergence.md` の記録の原則は「**登録するか迷ったら登録する**」であり、
「テンプレートに無い領域への上積み」は登録側へ倒すのが既定である。
それでも**登録しない**と判断した。理由は 3 つで、いずれも登録簿自身の規約から出る:

1. **登録簿の受け入れ基準に当たらない。** 同ファイルの冒頭が
   「逸脱が正当なのは **logic-driven（ドメイン要件起因）のときだけ**」と定めており、
   登録メタ表も「業務要件起因の説明」を必須行に持つ。本施策は
   **家系のキュレーターが家系全体へ割り当てた安全上の追従**であって、
   aicue のドメイン要件から出た逸脱ではない。書ける「業務要件起因の説明」が無い。
2. **テンプレートから外れる方向の変更ではない。** laravel-claude-template 自身が
   同じ `target_version`（`^0.4` へ改版し回帰テストで受ける）で `update_pending` である。
   aicue は**正典が向かっている先へ先に着く**ので、テンプレートが追いついた時点で
   差は消える。これを「逸脱」として登録すると、解消時に削除が必要な
   **一時的な登録**を作ることになり、「解消した逸脱は登録から消す」という規約と合わせると
   台帳が追従の遅れを表現する道具に化ける（登録簿は
   「**テンプレート更新への追従遅れは検出しない / 表現しない**」と明言している）。
3. **突合 gate の母集合外である。** 母集合は
   「正典が公開する指紋台帳のキー ∩ 本リポジトリの追跡ファイル」であり、
   新規のアプリ固有テストは 1 件も見られない。登録しても機械的な保証は増えない。

**再判定の条件**: laravel-claude-template が `^0.4` へ追従したとき、
`SsrfPinSpecialPurposeRangeRegressionTest` と同じ関心事のファイルが
正典側に**別の名前で**入った場合は、名前の割れとして登録の対象になりうる
（aigenba の gate 名の割れが `divergence_candidate` になっているのと同じ形）。
そのときは本設計の判断を見直す。

---

## 使命・禁止事項の最終チェック

| 項目 | 確認 |
|---|---|
| 使命への寄与 | 直接の機能ではなく**土台の安全**。aicue は SES/SNS の**無認証の入口**が外部 URL 取得を誘発する経路を持つ。現場の SOP と撮影データを預かる基盤の前提を守る |
| 禁止事項 1（テストなしの実装完了報告） | 施策 C が Architecture テストとして不変条件を登録する。施策 A/B は C が受ける |
| 禁止事項 2（PHPStan の widen / baseline 化） | 型を緩める変更を 1 件も含まない。`ignoreErrors` を増やさない |
| 禁止事項 3（dev DB への破壊操作） | DB に触らない |
| 禁止事項 4（`response()->json()` 直書き） | HTTP 応答を作らない |
| 禁止事項 5/6（LLM / prompt） | LLM 経路に触らない |
| 禁止事項 7/8（redirect / disabled UI） | UI に触らない |
| 禁止事項 9（Artifact の使用） | 成果物はすべて `devnotes/` 配下のファイル。Artifact を使わない |
| 既存テストの削除・上書き | 1 件もしない。施策 D は fixture 値の差し替えのみで、assertion を 1 つも弱めない |
| `DatabaseTransactions` の個別使用 | 使わない |
| DTO + JsonResource | 該当する変更が無い（HTTP 応答・Inertia Props を作らない） |
| DESIGN.md / Atomic Design | UI / frontend 変更なし。TypeScript 変更なし |

## スコープ外（再掲。過大化させない）

| 項目 | 理由 |
|---|---|
| 第二層 `SsrfPinPackageContractTest` の新設 | I7。正典が「第二層は t0 の必須要素ではない」と明示。`target_version` にも含まれない |
| `config/ssrf-pin.php` への `registry_version` の pin | I2 + I10。登録簿の版は施策 C の R1 で pin する |
| `config/ssrf-pin.php` への `max_body_bytes` の明示 | I2 + I10。aicue は `PinnedHttpClient` を使わないので実効差が無い（package 既定 1 MiB が `mergeConfigFrom` で入る） |
| `PinnedHttpClient` への取得の一本化 | 正典 boundary が「呼び出し側は各機能側」と切っている。aicue:T229 が裁定 AG-199 に沿って「inspect → fetch」を選んだ判断を覆さない |
| `docs/ses-mail-runbook.md` の 403 切り分け表の文言更新 | 指紋台帳 + 採用時債務パス。現行記述（「private IP へ解決されていないか」）は誤りではなく網羅的でないだけなので、債務の整理を伴う変更に見合わない。**再判定の条件**: 同ファイルが別の理由で債務から外れたとき |
| `docs/architecture.md` の SNS 節 | 「DNS 解決失敗のみ 503、他は 403」は v0.4.1 でもそのまま正しい（`NotGloballyReachable` は 403 側）。更新すべき事実が無い |
| 家系全体の版の扱い / 正典の版を 1 つ上げるかの判断 | I9。AG-003b の settle の代行はしない |
| 他リポジトリ（motivation / metamovics / laravel-claude-template / aigenba）の追従 | 本設計は aicue 1 リポジトリ分 |
| aigenba の gate 名の割れの是正 | 他リポジトリの話。aicue には既に正典と同名の gate がある |
| TypeScript 側の URL 安全性判定 | I8。`capture-core-package` の管轄。aicue に該当実装は無い |
| 登録簿の陳腐化の自動検知（IANA 側との突合） | 上流と家系の巡回の責務。施策 C の R1 は「同梱の登録簿が変わったか」しか見ない（gate の docblock に限界を明記する） |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `composer.lock` を変更するため、他の依存変更と**必ず競合する**。lock の競合は行単位のマージで解決できず、必ず再解決が要る。(2) 施策 A〜E が「版上げ → 回帰で受ける」という 1 つの不可分な単位であり、部分適用すると `composer install` が壊れる（A だけ）か、穴が開いたまま gate が緑になる（C だけ）。(3) 検証が全数実行（`composer test` / `composer phpstan` / `pint`）を要求するので、他施策と混ぜると赤の原因を切り分けられない。(4) 実装順序に「版上げ前に回帰テストが 11 件 fail することの実測」という**中間の観測点**があり、これを他の変更と混ぜると測れない |
| 競合リスク | **高**（`composer.lock`）。`tests/Pest.php` も共用ファイルなので中程度。`AGENTS.md` は追記のみで低。マージ前に main を取り込んで全数実行し直すこと |

## 実装完了の条件（受け入れ基準）

1. `composer.json` の `kent013/laravel-ssrf-pin` が `^0.4`、`repositories` は VCS のまま
2. `composer.lock` の当該エントリが `v0.4.1` /
   `source.reference === "93ba837c661bf2c31b6801c4c9ad866bdff4445e"` /
   `source.type === "git"` / VCS URL が裁定どおり
3. 既存パッケージの「名前 → 版の写像」が当該 1 件を除いて不変（機械照合の出力を記録に残す）
4. `tests/Architecture/SsrfPinSpecialPurposeRangeRegressionTest.php` が存在し全ケース緑。
   **版上げ前に 11 件 fail したことの実測値が実装記録に残っている**
5. `config/ssrf-pin.php` / `tests/Architecture/SsrfPinBoundaryTest.php` /
   `tests/Support/SnsTestData.php` の 3 件が `git diff --name-only` に**現れない**
6. `composer test` 全数緑 / `composer phpstan` level 10 エラー 0 /
   `vendor/bin/pint --test` 通過 / `pnpm lint` / `pnpm typecheck` / `pnpm build` 緑
   （フロントエンドは無改修だが版上げの巻き添えが無いことの確認）
7. `docs/template-divergence.md` と `LedgerPins.php` が**無変更**


---

## 関連する現行コード

### composer.json (抜粋: repositories + require の該当部)
```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kent013/laravel-ssrf-pin.git"
        }
    ],
    "require": {
        "php": "^8.4",
        "aws/aws-php-sns-message-validator": "^1.10",
        "aws/aws-sdk-php": "^3.384",
        "echolabsdev/prism": "^0.100.1",
        "filament/filament": "^5.6",
        "inertiajs/inertia-laravel": "^3.1",
        "kent013/laravel-prism-prompt": "^0.17.0",
        "kent013/laravel-ssrf-pin": "^0.2",
        "laravel/cashier": "^16.5",
        "laravel/fortify": "^1.37",
        "laravel/framework": "^13.8",
        "laravel/mcp": "^0.8.0",
        "laravel/passkeys": "^0.2.1",
        "laravel/passport": "^13.7",
        "laravel/socialite": "^5.27",
        "laravel/tinker": "^3.0",
        "league/flysystem-aws-s3-v3": "^3.0",
        "owen-it/laravel-auditing": "^14.0",
```

### config/ssrf-pin.php (全文 / **本設計では変更しない**)
```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SSRF 安全境界 pin (kent013/laravel-ssrf-pin)
|--------------------------------------------------------------------------
|
| 外部 URL 取得の SSRF 検査は `Kent013\SsrfPin\UrlSafetyInspector` が SSOT。
| パッケージは VCS 依存のため、package 側の既定値変更で外向き許可面が
| 広がらないよう、安全境界は必ず本 config で app 側に pin する
| (SsrfPinBoundaryTest が pin 値を固定)。
|
| 外部 URL (特にユーザ入力由来) を取得する機能を追加する場合は、
| 必ず UrlSafetyInspector / PinnedHttpClient を通すこと (AGENTS.md 参照)。
|
*/

return [
    // 許可するスキーム。
    'allowed_schemes' => ['http', 'https'],

    // 許可するポート。非標準ポート (内部サービス等) への到達を防ぐ。
    'allowed_ports' => [80, 443],

    // redirect 追従の最大 hop 数。
    'max_redirect_hops' => 5,

    // アプリ拡張用の追加 deny CIDR (例: 自社内部レンジ)。
    'additional_deny_cidrs' => [],

    // host が IP literal (例: http://93.184.216.34) の URL を一律拒否する。
    // パッケージ既定 (false) より厳しい保守既定。raw-IP URL を許可したい
    // アプリのみ意図的に false へ変更する (public IP のみ許可される)。
    'deny_ip_literals' => true,
];

```

### tests/Architecture/SsrfPinBoundaryTest.php (全文 / **本設計では変更しない**)
```php
<?php

declare(strict_types=1);

/*
 * SSRF 安全境界 pin の不変条件 (WP21)。
 *
 * kent013/laravel-ssrf-pin は VCS 依存のため、パッケージ側の既定値変更で
 * 外向き許可面が広がらないよう、config/ssrf-pin.php で保守既定を app 側に pin する。
 * ここでは pin 値そのものを固定する (緩める場合はこのテストごと意図的に変更する)。
 * DB 不要のため Architecture スイート (TestCase のみ) に置く。
 */

use Kent013\SsrfPin\UrlSafetyInspector;

test('SSRF 安全境界は保守既定に pin されている', function (): void {
    expect(config('ssrf-pin.allowed_schemes'))->toBe(['http', 'https'])
        ->and(config('ssrf-pin.allowed_ports'))->toBe([80, 443])
        ->and(config('ssrf-pin.max_redirect_hops'))->toBe(5)
        ->and(config('ssrf-pin.additional_deny_cidrs'))->toBe([])
        // パッケージ既定 (false) より厳しい raw-IP URL 一律拒否をテンプレート既定とする
        ->and(config('ssrf-pin.deny_ip_literals'))->toBeTrue();
});

test('UrlSafetyInspector は pin された境界で外向き URL を拒否できる', function (): void {
    $inspector = app(UrlSafetyInspector::class);

    // 以下はすべて DNS 解決前に判定されるケースのみ (テストは外部ネットワーク非依存)
    // deny_ip_literals=true が反映され、IP literal URL は private/public を問わず拒否される
    expect($inspector->inspect('http://127.0.0.1/')->allowed)->toBeFalse()
        ->and($inspector->inspect('http://93.184.216.34/')->allowed)->toBeFalse()
        // 許可外スキーム / ポートも拒否される
        ->and($inspector->inspect('ftp://example.com/')->allowed)->toBeFalse()
        ->and($inspector->inspect('https://example.com:8443/')->allowed)->toBeFalse();
});

```

### tests/Pest.php (L391-407: bindSnsDnsResolver。施策 D でこの直後に関数を足す)
```php
/**
 * SNS 証明書取得テスト用に DNS 解決を固定する。
 *
 * `UrlSafetyInspector` そのものは ExternalFakeDeclaration::neverSwapped() により偽物に
 * できないので、差し替えるのは**その依存**である DnsResolverInterface である。
 * inspector は singleton なので、bind 後に作り直させる。
 *
 * @param  list<string>  $ips  空配列なら「DNS 解決失敗」を模す
 */
function bindSnsDnsResolver(array $ips): void
{
    app()->bind(
        DnsResolverInterface::class,
        fn (): DnsResolverInterface => new FakeDnsResolver(['sns.us-east-1.amazonaws.com' => $ips]),
    );
    app()->forgetInstance(UrlSafetyInspector::class);
}

```

### tests/Pest.php (L46-120: レーン構成。Architecture レーンに RefreshDatabase が無いことの確認用)
```php
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Vite manifest 不在でも view が描画できるよう test では Vite をスタブする
        $this->withoutVite();

        // 未 fake の LLM 呼び出しを fail-fast させる guard。
        // (1) accumulator clear → (2) Prompt::stopFaking() → (3) PrismManager 差し替え
        // の 3 段で前テスト残留状態を一掃しつつ install する。テスト本体で
        // Prism::fake([...]) / Prompt::fake([...]) を呼ぶと guard は透過される。
        // Prism 基盤を直接テストする稀な Unit テストのみ
        // StrayLlmCallGuard::uninstallForTest($this->app) で opt-out できる。
        StrayLlmCallGuard::install($this->app);

        // 未 fake の外向き HTTP を fail-fast させる guard (裁定 AG-105)。
        // レーン既定として Http::preventStrayRequests() を常時 ON にし、
        // 自機宛て loopback だけを Http::allowStrayRequests([...]) で明示許可する。
        // テスト本体で Http::fake([...]) を呼ぶと該当 URL は透過する
        // (Factory::fake() は prevent フラグを reset しないため共存する)。
        StrayHttpRequestGuard::install($this->app);

        // キャッシュ guard は Tests\TestCase::createApplication() の bootstrap 前に結線済み。
        // ここでは**結線が効いていること**だけを確認する (accumulator には触らない。
        // 触ると起動中に記録された違反が消える)。
        PlainDataCacheGuard::assertInstalled($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            //
            // ★3 つの guard は順に flush する。**同時発生時は先に throw した guard の
            //   詳細だけが表示される** (他方の accumulator は finally の reset で
            //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
            //   すべてを集約する仕組みは入れない (今必要なものだけ作る)。
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
            PlainDataCacheGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
            PlainDataCacheGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

/*
| Architecture lane はファイル走査中心で DB を使わないが、HTTP 出口の既定拒否は
| **全レーン一律**にする (レーンごとに既定が違うと「どのレーンなら外へ出られるか」を
| 覚える必要が生まれ、gate も分岐だらけになる)。Tests\TestCase は
| Illuminate\Foundation\Testing\TestCase 継承で Laravel app 上を走るため install できる。
*/
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        StrayHttpRequestGuard::install($this->app);
        PlainDataCacheGuard::assertInstalled($this->app);
    })
    ->afterEach(function (): void {
        try {
            StrayHttpRequestGuard::flushAndFailIfStray();
            PlainDataCacheGuard::flushAndFailIfStray();
        } finally {
            StrayHttpRequestGuard::reset();
            PlainDataCacheGuard::reset();
        }
    })
    ->in('Architecture');

```

### tests/Feature/Mail/SnsCertificateFetcherTest.php (L34-45 + fixture を使う周辺)
```php
    // ★テスト専用の array store へ既定を切り替える (前のテストの実体は捨てる)。
    //   `Cache::flush()` は使わない — store 全体を消すので rate limiter・lock・
    //   他テストの値まで巻き添えにする。
    useFreshSnsCertificateCacheStore();
    bindSnsDnsResolver(['203.0.113.10']);
});

function snsCertUrl(string $url = SnsTestData::CERT_URL): SnsCertificateUrl
{
    return SnsCertificateUrl::fromString($url);
}

...
test('F0 (正のコントロール): 正常系 fixture は SSRF 検査を通る', function (): void {
    // 境界 (config/ssrf-pin.php + vendor の deny CIDR) が変わったらここが最初に赤くなる。
    expect(app(UrlSafetyInspector::class)->inspect(SnsTestData::CERT_URL)->allowed)->toBeTrue();
});

...
});

test('F3: private IP に解決される host は恒久拒否 (403 系) で取りに行かない', function (): void {
    Http::fake();
    bindSnsDnsResolver(['10.0.0.5']);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
});

test('F4: DNS 解決失敗は一時障害 (503 系) で取りに行かない', function (): void {
    Http::fake();
    bindSnsDnsResolver([]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);

    Http::assertNothingSent();
});
```

### tests/Unit/Mail/AwsSnsSignatureVerifierTest.php (L14-22)
```php
beforeEach(function (): void {
    useFreshSnsCertificateCacheStore();
    bindSnsDnsResolver(['203.0.113.10']);
});

function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(SnsCertificateFetcher::class));
}
```

### tests/Feature/Mail/SesSignatureMiddlewareTest.php (L21-27)
```php
beforeEach(function (): void {
    config(['services.ses.sns_topic_arns' => [SnsTestData::TOPIC_ARN]]);
    // `Cache::flush()` は使わない (middleware の throttle も cache を使うため)。
    useFreshSnsCertificateCacheStore();
    bindSnsDnsResolver(['203.0.113.10']);
});

```

### app/Services/Mail/Sns/SnsCertificateFetcher.php (SSRF 検査の該当メソッドのみ。**本設計では変更しない**)
```php
    private function inspect(SnsCertificateUrl $url): void
    {
        $decision = $this->inspector->inspect($url->value);
        if ($decision->allowed) {
            return;
        }

        // DNS 解決失敗だけが一時障害である。
        if ($decision->reason === SsrfDenyReason::DnsResolutionFailed) {
            throw new SnsVerificationUnavailableException('certificate host is not resolvable');
        }

        throw new SnsSignatureInvalidException('certificate URL rejected by SSRF inspection');
    }
```
★網羅 match を持たない単一比較なので、`SsrfDenyReason` に case が増えても PHPStan は落ちない。

### app/Support/ExternalFakes/ExternalFakeDeclaration.php の neverSwapped()
```php
    public static function neverSwapped(): array
    {
        return [
            SnsSignatureVerifier::class => '受信通知の署名検証。偽物にすると差出人の詐称を検出できなくなる。',
            UrlSafetyInspector::class => '外部 URL の安全検査 (SSRF 防御)。偽物にすると内部宛ての取得が通る。',
        ];
    }
```

### 上流 package v0.4.1 の SsrfPinServiceProvider::register() (実読。singleton 登録の確認用)
```php
<?php

declare(strict_types=1);

namespace Kent013\SsrfPin;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Kent013\SsrfPin\Contracts\DnsResolverInterface;
use Kent013\SsrfPin\Contracts\PinnedCurlTransportInterface;
use Kent013\SsrfPin\Dns\SystemDnsResolver;
use Kent013\SsrfPin\Transport\GuzzleCurlTransport;

final class SsrfPinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ssrf-pin.php', 'ssrf-pin');

        $this->app->bind(DnsResolverInterface::class, SystemDnsResolver::class);

        $this->app->bind(PinnedCurlTransportInterface::class, function (Application $app): GuzzleCurlTransport {
            /** @var array{max_body_bytes?: int} $config */
            $config = $app->make(ConfigRepository::class)->get('ssrf-pin', []);

            return new GuzzleCurlTransport(
                $config['max_body_bytes'] ?? GuzzleCurlTransport::DEFAULT_MAX_BODY_BYTES,
            );
        });

        $this->app->singleton(UrlSafetyInspector::class, function (Application $app): UrlSafetyInspector {
            /** @var array{allowed_schemes?: list<string>, allowed_ports?: list<int>, additional_deny_cidrs?: list<string>, deny_ip_literals?: bool} $config */
            $config = $app->make(ConfigRepository::class)->get('ssrf-pin', []);

            return new UrlSafetyInspector(
                $app->make(DnsResolverInterface::class),
                $config['allowed_schemes'] ?? ['http', 'https'],
                $config['allowed_ports'] ?? [80, 443],
                $config['additional_deny_cidrs'] ?? [],
                $config['deny_ip_literals'] ?? false,
            );
        });

        $this->app->singleton(PinnedHttpClient::class, function (Application $app): PinnedHttpClient {
            /** @var array{max_redirect_hops?: int} $config */
            $config = $app->make(ConfigRepository::class)->get('ssrf-pin', []);

            return new PinnedHttpClient(
                $app->make(UrlSafetyInspector::class),
                $app->make(PinnedCurlTransportInterface::class),
                $config['max_redirect_hops'] ?? 5,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ssrf-pin.php' => $this->app->configPath('ssrf-pin.php'),
            ], 'ssrf-pin-config');
        }
    }
}

```

### 上流 package v0.4.1 の UrlSafetyInspector::classifyIp() と関連 (実読)
```php
    private readonly IpClassificationTable $classificationTable;

    public function __construct(
        private readonly DnsResolverInterface $dnsResolver,
        private readonly array $allowedSchemes = ['http', 'https'],
        private readonly array $allowedPorts = [80, 443],
        private readonly array $additionalDenyCidrs = [],
        private readonly bool $denyIpLiterals = false,
        ?IpClassificationTable $classificationTable = null,
    ) {
        $this->classificationTable = $classificationTable ?? IpClassificationTable::default();
    }

    public function classificationRegistryVersion(): string
    {
        return $this->classificationTable->registryVersion();
    }

    private function classifyIp(string $ip): ?SsrfDenyReason
    {
        $ip = $this->normalizeMappedIpv4($ip);
        // ... additionalDenyCidrs の解釈 ...
        $interval = $this->classificationTable->intervalFor($ip);
        $reachability = $this->classificationTable->reachabilityOf($interval);

        return match ($reachability) {
            Reachability::PublicUnicast => null,
            Reachability::Unclassified => SsrfDenyReason::NotGloballyReachable,
            Reachability::NotGloballyReachable => $interval->denyReason ?? SsrfDenyReason::NotGloballyReachable,
        };
    }
```
★`inspect()` の IP literal 分岐は分類より**前**にあり、`denyIpLiterals` が true なら
`IpLiteralNotAllowed` で即 return する（= 詳細設計が言う偽グリーンの罠の根拠）。

### 上流 package v0.4.1 の Testing/FakeDnsResolver (実読)
```php
<?php

declare(strict_types=1);

namespace Kent013\SsrfPin\Testing;

use Kent013\SsrfPin\Contracts\DnsResolverInterface;

/**
 * 消費者がテストで host→IP を固定するための出荷 fake DNS resolver。
 */
final class FakeDnsResolver implements DnsResolverInterface
{
    /**
     * @param  array<string, list<string>>  $aRecords
     * @param  array<string, list<string>>  $aaaaRecords
     */
    public function __construct(
        private array $aRecords = [],
        private array $aaaaRecords = [],
    ) {}

    public function resolveA(string $host): array
    {
        return $this->aRecords[$host] ?? [];
    }

    public function resolveAaaa(string $host): array
    {
        return $this->aaaaRecords[$host] ?? [];
    }
}

```

### 上流 package v0.4.1 の Enums/SsrfDenyReason (実読。case は追加のみ)
```php
<?php

declare(strict_types=1);

namespace Kent013\SsrfPin\Enums;

/**
 * 中立な SSRF deny 理由（セキュリティのドメインに閉じる）。
 *
 * アプリ固有の結果語彙（例: 到達性分類）はここに持ち込まない。各アプリが本 enum を
 * `match` で自プロダクトの reason へ写像する（密結合回避）。
 */
enum SsrfDenyReason: string
{
    case Loopback = 'loopback';
    case PrivateRange = 'private_range';
    case LinkLocal = 'link_local';
    case Multicast = 'multicast';
    case Reserved = 'reserved';
    /**
     * v0.4: 完全区間分類で「公開到達可能」と判定できなかった（= 既定拒否）。
     * 古典的な区分（loopback / private / link-local / multicast / reserved）に
     * 当てはまらない非到達区間と、分類表に当たらなかった表記がここに落ちる。
     */
    case NotGloballyReachable = 'not_globally_reachable';
    case DisallowedPort = 'disallowed_port';
    case CredentialInUrl = 'credential_in_url';
    case IpLiteralNotAllowed = 'ip_literal_not_allowed';
    case SchemeNotAllowed = 'scheme_not_allowed';
    case SchemeDowngrade = 'scheme_downgrade';
    case InvalidHost = 'invalid_host';
    case DnsResolutionFailed = 'dns_resolution_failed';
    case TooManyRedirects = 'too_many_redirects';
    case CurlHandlerUnavailable = 'curl_handler_unavailable';
}

```

### 上流 package v0.4.1 の config/ssrf-pin.php (実読。app 側は変更しないので mergeConfigFrom で既定が入る)
```php
<?php

declare(strict_types=1);

return [
    // 許可するスキーム。
    'allowed_schemes' => ['http', 'https'],

    // 許可するポート。非標準ポート（内部サービス等）への到達を防ぐ。
    'allowed_ports' => [80, 443],

    // redirect 追従の最大 hop 数。
    'max_redirect_hops' => 5,

    // 応答 body の上限バイト数（既定 1 MiB）。超過は切り捨てず TransportError::BodyTooLarge で
    // 失敗させる。上限は curl の write callback 段階で効くので、巨大応答は読み切られない。
    'max_body_bytes' => 1_048_576,

    // アプリ拡張用の追加 deny CIDR（例: 自社内部レンジ）。
    'additional_deny_cidrs' => [],

    // true の場合、host が IP literal（例: http://93.184.216.34）の URL を一律拒否する。
    // 既定 false（public IP literal は許可）。raw-IP URL を嫌うアプリは true にする。
    'deny_ip_literals' => false,
];

```

