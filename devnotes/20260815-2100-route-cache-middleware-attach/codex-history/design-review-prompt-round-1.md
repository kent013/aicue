# 前提: アプリの使命・禁止事項・思考原則

## 使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 — AGENTS.md より

## 禁止事項

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


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 13 + Svelte 5 + Inertia.js + TypeScript
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
8. 波及変更の網羅性
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠 / 11. Atomic Design準拠（本設計は UI 変更を含まないため該当なしなら「該当なし」と書いてよい）

【この設計に固有の、特に厳しく見てほしい論点】
- 施策 3 の検査 1（全 named route について生の `action['middleware']` と `compile()` の
  `attributes` を厳密比較）が、実際に成立するか。同語反復（同じ配列を 2 回読んでいるだけで
  常に緑になる）になっていないか。もし同語反復なら、それでも置く価値があるか、
  別の形にすべきかを述べよ。
- 施策 3 の検査 2 が Laravel 13 の実機序で本当に 409 / 200 の差を出せるか。
  `setCompiledRoutes()` 後に既存のテスト用リクエストが compiled 経路一覧を経由するか。
- 施策 2 の走査条件（デプロイ定義のパターン / `route:cache` の語）に、
  実運用で困る偽陽性・偽陰性が無いか。
- 施策 1 の文章と施策 2 の検査条件が将来ずれないための仕掛けが十分か。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: route-cache-middleware-attach

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
5. LLM 呼び出しの Prism 直呼び(窓口 `PromptDefense` → 実行単位 `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数が対象）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 13 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260815-2100-route-cache-middleware-attach/conceptual-design.md`（Round 4 で APPROVED）

## 決定事項（この設計が答えを出す 3 つの問い）

| 問い | 答え |
|---|---|
| 家系の正典 (a) 形（経路一覧が組み上がった後に走らせる専用の実行点クラス）へ移行するか | **移行しない**。判断を `docs/template-divergence.md` D19 として登録する |
| 移行しない場合、後付けの内容・順序・入口の目録を壊さないか | **`app/` を 1 行も変えない**。壊しようがない形にする |
| stale cache が無音で保護を外す実測を回帰として固定できるか | **できる**。ただし固定できるのは「middleware が欠けた compiled 経路一覧では保護が外れる」という局所の回帰までで、cached 起動そのものの再現ではない |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 逸脱 D19 の登録と参照の結線 | `docs/template-divergence.md`（追記）/ `AGENTS.md`（追記）/ `docs/app-integration-guide.md`（§7c 追記） | 高 |
| 2 | 免除の前提のトリップワイヤ | `tests/Architecture/RouteCacheExemptionPremiseTest.php`（新規） | 高 |
| 3 | 焼き込みと剥落の回帰 | `tests/Feature/Security/RouteCacheBakedProtectionTest.php`（新規） | 高 |

**実装順序**: 3 → 2 → 1（テストファースト。1 の文章は 2 / 3 が固定した内容を指すため最後に書く）。

---

## 施策 1: 逸脱 D19 の登録と参照の結線

### 変更箇所

- `docs/template-divergence.md`: 末尾（現在の最終エントリは D18）へ **D19** を追加
- `AGENTS.md`: 「運用要件 (route:cache)」ブロック（現在 L119〜L129）へ 3 行追記
- `docs/app-integration-guide.md`: §7c の「**現状**」段落（現在 L509 付近）へ 2 行追記

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（ただし D19 の「再検討の条件」は施策 2 の検査条件と一致させる）

### D19 に書く内容（構成）

既存エントリと同じ書式（観点の表 → なぜ logic-driven か → 揃えている不変条件 → 関連）で書く。

**見出し**: `## D19 ✅ 経路キャッシュ起動での後付けは「走らせない」側の契約を維持する（専用の実行点クラスへは移行しない）`

**観点の表**:

| 観点 | 家系の正典 / テンプレート | 本アプリ |
|---|---|---|
| 実行点 | 専用クラス 1 つ（`AfterRoutesLoaded` 相当）へ集約 | 2 つの binder が各々 `Application::booted()` を使う |
| 経路キャッシュ起動での契約 | 正典 (a) = 容器の `routes` 束縛の張り替えを捕まえて、読み込まれた直後に後付けを走らせる | (b) = 走らせない。実効は cache 生成時の焼き込み |
| 入口の絞り込み | 素の起動完了フックの直呼びを走査で禁止 | `PostBootRouteMutationInventoryTest` が入口を 2 binder に絞る（deny-by-default） |
| 経路キャッシュ起動での実証 | 別プロセスで起動して後付けの残存を確認 | 同一プロセスで「焼き込みの入力」と「欠落時の剥落」を確認（別プロセス起動は導入しない） |

**なぜ logic-driven か**（要点。本文は概念設計の該当節を写す）:

1. 本リポジトリにデプロイ定義の実体が無く、`route:cache` を実行する記述も追跡下に 0 件である。
   **管理下で検出できる発生経路が無い**（「発生確率がゼロ」とは書かない）。
2. 毎デプロイ再生成の**機械強制**は、`AGENTS.md` が「存在しない基盤のための preflight 機構を
   先回りして作らないこと」と明記しているため現時点で採れない。
3. 正典 (a) 形は Laravel 13 では 4 つの問題を同時に解く必要があり（容器の `routes` 束縛の
   張り替えの捕捉 / その束縛がまだ無いときに張り替えが発火しない穴の手当て / 経路一覧の実体ごとの
   冪等 / cached 起動で起動を止めると `route:list` も `route:clear` も落ちて復旧手段を失う問題への
   例外設計）、実証には別プロセスで起動する検査基盤も要る。
   **(a) を採る利益は「運用要件を 1 つ消せること」であり、その運用要件が効く相手（デプロイ基盤）が
   まだ無い**。基盤を作る PR で実物の手順と突き合わせて設計する方が確実である。
4. **これは保留ではなく明示の判断である**。期限で自然解消せず、**前提が崩れたときに解消する**。

**揃えている不変条件（これは保証し続ける）**:

> 「後付けした保護は、実効の経路に必ず載る」
> 「後付けの入口は 2 つの binder に限られる」
> 「経路名が消えたら起動を止める（無防備なまま公開しない）」

担い手の対応表を書く（Codex Round 4 の指摘。**どの不変条件をどのテストが担うか**を明示し、
施策 3 が既存目録と二重管理でないことを読み取れるようにする）:

| 不変条件 | 担い手 |
|---|---|
| どの route に何を付けるべきか（対象と件数） | `ThrottleCoverageInventoryTest` / `RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` / `PasskeyRouteProtectionTest` |
| 後付けの入口が 2 binder に限られること | `PostBootRouteMutationInventoryTest` |
| 後付けの契約（cached では resolver すら呼ばない / 経路が引けなければ起動を止める / 冪等 / 列の順） | `RouteMiddlewareBinderTest` / `RouteThrottleBinderTest` |
| 実際に付いた middleware 列が、焼き込みの入力へ欠落なく移ること | **施策 3 の検査 1（新規）** |
| 焼き込みが欠けた経路一覧では保護が実際に外れること | **施策 3 の検査 2（新規）** |
| 直列化の準備が middleware 列を変えないこと（版依存の事実） | **施策 3 の検査 3（新規）** |
| この逸脱を許す前提（デプロイ定義が無い / `route:cache` の実行が無い） | **施策 2（新規）** |

**再検討の条件（解消条件）**:

- リポジトリにデプロイ定義の実体が入ったとき
- `route:cache`（または `artisan optimize`）を実行する記述が入ったとき
- 家系の機能台帳の裁定が変わったとき

前の 2 つは**施策 2 の検査条件と同じ言葉で書く**（文章と検査が別々に育つのを防ぐ）。

**関連**:

- 実装: `app/Support/Http/RouteMiddlewareBinder.php` / `app/Support/Http/RouteThrottleBinder.php`
- 設計: 本 devnotes ディレクトリ
- 契約の正本: `docs/app-integration-guide.md` §7c

### AGENTS.md への追記（運用要件 (route:cache) ブロックの末尾）

> 家系の正典はこの後付けを「経路の一覧が組み上がった後に走らせる専用の実行点」へ集約する形だが、
> **本リポジトリは「経路キャッシュ起動では走らせない」側を選んでいる**。この判断は
> `docs/template-divergence.md` **D19** に登録済みで、判断を許している前提
> （デプロイ定義が無く `route:cache` の実行が無い）は
> `tests/Architecture/RouteCacheExemptionPremiseTest.php` が機械で固定する。
> **前提が崩れたらこのテストが赤くなる**ので、そこで D19 を読み直すこと。

### リスク

- AGENTS.md / guide は他タスクも触る中心ドキュメントである → 追記は既存段落を書き換えず**末尾に足す**。
- D19 の文章と施策 2 の検査条件が将来ずれる → D19 側に「施策 2 と同じ言葉で書く」と明記し、
  テストの失敗メッセージから D19 を指す（双方向に参照を張る）。

---

## 施策 2: 免除の前提のトリップワイヤ

### 変更箇所

- 新規: `tests/Architecture/RouteCacheExemptionPremiseTest.php`

命名は既存の `tests/Feature/Security/ThrottleExemptionPremiseTest.php` /
`tests/Feature/Security/IdempotencyExemptionPremiseTest.php`（免除の前提を機械で固定する形）に
そろえる。置き場所は DB を使わない静的走査なので `tests/Architecture/` にする。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テストの更新: なし

### 何を検査するか

git 追跡下のファイルを走査し、次の 2 つを **0 件で pin** する。

**検査 A: デプロイ定義の実体が無いこと**

追跡下のパスに、次のいずれにも一致するものが無いこと。

| 種類 | 一致条件（パスの前方一致 / 拡張子） |
|---|---|
| 専用ディレクトリ | `deploy/` `ansible/` `.ebextensions/` `k8s/` `kubernetes/` `helm/` `charts/` |
| Terraform | `*.tf` `*.tfvars` |
| PaaS の宣言 | `Procfile` `fly.toml` `render.yaml` `app.yaml` `vercel.json` `railway.json` `captain-definition` |
| CI のデプロイ job | `.github/workflows/*.yml` のうちファイル名に `deploy` / `release` / `cd` を含むもの |

**検査 B: `route:cache` を実行する記述が無いこと**

追跡下の **`.php` でも `.md` でもない**ファイルの中身に、次の語が現れないこと。

- `route:cache`
- `artisan optimize` で、直後が `:` **でない**もの（素の `optimize` は `route:cache` を含む複合コマンド）

`optimize:clear`（消す側。bug-hunt が使う）は一致しない。

### 実装方針（要点）

```php
<?php

declare(strict_types=1);

/*
 * 逸脱 D19（経路キャッシュ起動での後付けは「走らせない」側を維持する）を
 * 許している**前提そのもの**を機械で固定する。
 *
 * ★これは「デプロイの正しさ」を事前検査する仕組みではない（AGENTS.md が禁じているのはそちら）。
 *   固定するのは「その基盤がまだ無い」という免除の前提の方である。同じ形は
 *   ThrottleExemptionPremiseTest / IdempotencyExemptionPremiseTest に前例がある。
 *
 * ★赤くなったときに求めるのは「デプロイを正しくすること」ではなく
 *   「D19 を読み直して、正典 (a) 形への移行か、毎デプロイ再生成の機械強制かを
 *   同じ PR で決めること」である。
 *
 * ★保証範囲を誇張しない: PHP からの `Artisan::call(...)` と Markdown 中の説明文は見ない。
 *   起動時の cache の鮮度も、デプロイ手順の正しさも検査しない。
 */
```

- 追跡下ファイルの列挙は `git ls-files -z` を `Symfony\Component\Process\Process` で実行する
  （`tests/Support/TrackedPhpSourceFiles.php` と同じ作法。ただし対象が `*.php` に限らないので
  **共用クラスは作らず本テスト内の関数に閉じる** = 今必要なものだけ作る）。
  git が使えない環境では**空を返さず例外**にする（fail-open の防止）。
- 検査 B の needle は**この設計文とテスト自身が持つ語**でもあるため、走査対象から
  `.md` と `.php` を外すことで自己言及を構造的に避ける
  （本テストは `.php` なので自分自身を読まない）。
- **床値の pin**: 列挙件数が 0 件や極端に少ないと「対象が無いから緑」の空振りになる。
  追跡下ファイル数の下限（例: 500 件）と、必ず存在する代表パス
  （`composer.json` / `.github/workflows/ci.yml` / `scripts/bug-hunt-shard.sh`）が
  列挙に含まれることを表明する（`TrackedPhpSourceFiles` の docblock が求めている作法）。

### テスト計画

| # | テスト名 | 検証内容 |
|---|---|---|
| 2-1 | デプロイ定義の実体が追跡下に 1 件も無い | 検査 A。違反時は一致したパスを列挙し、D19 と二択を提示 |
| 2-2 | `route:cache` を実行する記述が追跡下に 1 件も無い | 検査 B。違反時はファイル:行を列挙 |
| 2-3 | 走査の母集団が空振りでない（床値と代表パスの pin） | 追跡下ファイルが下限件数以上で、代表 3 パスを含む |
| 2-4 | 負のコントロール: 判定関数は `optimize:clear` を違反にせず、`artisan optimize` を違反にする | 判定を純関数へ切り出し、文字列を直接渡して両方向を固定（bug-hunt の既存記述が誤検出されないことの証拠） |

- 個別の `DatabaseTransactions` は使わない（DB 不使用）。
- 実行は `composer test`。

### PHPStan 適合チェック

- [ ] `git ls-files` の出力は `string` として扱い、`explode()` の結果は `list<string>` へ絞る
- [ ] 判定関数は `bool` / `list<string>` を返し、`mixed` を返さない
- [ ] `preg_match` の戻り値は `int|false` を明示的に判定する
- [ ] 型を緩める注釈は使わない（禁止事項 2）

### リスク

- **偽陽性**: 将来 `deploy` を名に含む無関係な workflow（例: `deploy-docs`）が入ると赤くなる。
  これは意図した摩擦である（D19 の前提が本当に崩れていないかを人が見る契機）。
  失敗メッセージにその旨を書き、無関係なら検査条件と D19 を**同じ PR で**直すよう促す。
- **偽陰性**: PHP からの `Artisan::call('route:cache')` には沈黙する。docblock に明記する。

---

## 施策 3: 焼き込みと剥落の回帰

### 変更箇所

- 新規: `tests/Feature/Security/RouteCacheBakedProtectionTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テストの更新: なし
  （`tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php` の設定手順を**踏襲**するが、変更はしない）

### 前提として確定させた機序（実読済み。laravel/framework 13.18.0）

1. `Illuminate\Foundation\Console\RouteCacheCommand::handle()` は
   `route:clear` → cache 無しのアプリを再 bootstrap → 各 route に `prepareForSerialization()` →
   `$routes->compile()` の戻り値を `var_export` してファイルへ書く。
2. `Route::prepareForSerialization()` が触るのは `action['uses']` / `action['missing']` の
   Closure 直列化、正規表現の事前構築、`router` / `container` 参照の切り離しだけで、
   **`action['middleware']` には触れない**。
3. `AbstractRouteCollection::compile()` は `['compiled' => …, 'attributes' => [route 名 => […, 'action' => …]]]`
   を返し、`Router::setCompiledRoutes()` は同じ形を受け取って
   `CompiledRouteCollection` を作り、容器の `routes` 束縛を張り替える。
4. `CompiledRouteCollection::getByName()` は生成した `Route` を `nameCache` へ覚え、
   `match()` はその `getByName()` を通る。よって差し替えた経路一覧の中身がそのまま dispatch に効く。

### 検査 1: 焼き込みの実証（全数一致）

```
起動済みの Route の action['middleware']
        ==（順序と重複を含めて厳密に）==
compile() の attributes[route 名]['action']['middleware']
```

- 比較は**生の `action['middleware']`** で行う。`gatherMiddleware()` /
  `Router::resolveMiddleware()` は使わない（alias の解決・group の展開・重複の畳み込みを挟むと
  「焼き込みの入力が同じ」を見たことにならない）。
- **集合化も sort もしない**。順序と重複をそのまま比較する
  （passkey 削除 route の `throttle:passkeys` → `recent-auth` → `ensure-login-method` の順序と、
  意図しない重複の両方を見逃さないため）。
- 個々の alias の一覧表は**作らない**（2 binder の呼び出し側の定数と二重管理になるため）。

**空振り防止（負のコントロール）**: 後付けの 5 系統が、それぞれ 1 本以上の route の
`attributes` に現れること。

| 系統 | 代表 |
|---|---|
| `recent-auth` | 2FA 秘密 GET 3 本 / passkey 系 |
| `recent-auth.on-email-change` | プロフィール更新 |
| `ensure-login-method` | `passkey.destroy` |
| `no-store` | `passkey.login-options` |
| `throttle:` で始まる後付け | `passkey.destroy` / 2FA 秘密 GET / `cashier.webhook` |

`throttle:` の系統は**アプリ全体の無関係な throttle で成立しない**よう、
**後付け対象の route 名を名指しして**その route の `attributes` に載っていることを見る
（Codex Round 4 の指摘）。名指しするのは代表 1 本ずつで、件数の網羅は既存目録の担当。

さらに `passkey.destroy` の 1 本で 3 つの alias の**相対順序**を固定する。

### 検査 2: 剥落の実証（負のコントロール）

**1 テスト 1 シナリオ**。差し替えはそのテストの**最後の操作**。

- 2-a（保護が載っている側）: `compile()` の結果を**そのまま** `setCompiledRoutes()` へ渡し、
  鮮度切れセッションで `GET /user/two-factor-secret-key`（`Accept: application/json`）が
  **409** で、本文に `secretKey` が**無い**こと。
- 2-b（保護が欠けている側）: 同じ結果から
  `attributes['two-factor.secret-key']['action']['middleware']` の `recent-auth` を **1 本だけ**抜いて
  `setCompiledRoutes()` へ渡し、同じ要求が **200** になること。
  本文の形には踏み込まない（Fortify の応答表現の変更に脆くしない）。
  抜く前と抜いた後で middleware 列の**件数が 1 減っていること**も表明する
  （抜けていないのに 200 になった、という取り違えを防ぐ）。

利用者の用意は `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php` と同じ
`User::factory()->withTwoFactor()->create()` を使う（Factory 必須）。

### 検査 3: 版依存の事実の固定

`two-factor.secret-key` の Route（担い手が文字列）を 1 本取り、**複製**に対して
`prepareForSerialization()` を呼び、次の 3 点を表明する。

1. 複製前の元 Route の `action['middleware']`（`recent-auth` を含む列）
2. 呼んだ後の複製の `action['middleware']` が 1 と**同一**であること
3. 呼んだ後も**元 Route の `action['middleware']` が変わっていない**こと（複製で隔離できている証明）

これが無いと検査 1 / 2 は「未加工の経路一覧を見ているだけ」になり、将来 vendor が
`prepareForSerialization()` で middleware を触るようになっても緑のまま素通りする。

### 状態の隔離（Codex Round 2 / 3 の指摘への回答）

- `setCompiledRoutes()` は Router の持ち物だけでなく容器の `routes` 束縛を張り替え、
  それを見ている URL 生成器も付いてくる。したがって**元へ戻す形は採らない**。
- 代わりに次の 4 点で隔離する。
  1. **1 テスト 1 シナリオ**（2-a と 2-b を同じテスト関数で続けない）
  2. 差し替えは**そのテストの最後の操作**（差し替えた後に別の前提で検査しない）
  3. テスト間の隔離は Laravel が**各テストでアプリを作り直す**ことに依る
     （これは全テストが既に依っている既定であり、本テスト固有の仮定ではない）
  4. 差し替えた経路一覧を dataset / ループで持ち越さない。静的変数や共有 singleton へ保存しない
- **テストの途中でアプリを作り直さない**（`RefreshDatabase` が張っているトランザクションが
  接続ごと道連れになるため）。

### 保証範囲（誇張しない。docblock にそのまま書く）

- 保証する: 起動済みの経路一覧に後付けが載っており、それが `compile()` の `attributes` に
  欠落なく現れること / その並びから alias が欠けると保護が効かなくなること /
  直列化の準備が middleware 列を変えないこと。
- 保証しない: `php artisan route:cache` コマンド全体が成功すること（Closure route の
  直列化可否を含む）/ 実際に cache ファイルを置いた**別プロセスでの起動順**の再現 /
  起動時の cache の鮮度。**「cached 起動を再現した」とは書かない**。
- `compile()` と書き出し内容の同一性は vendor の版に依存する推論であり、
  検査 3 がそれを支える。framework 更新時には本テスト群の前提を人手で読み直すこと。

### テスト計画

| # | テスト名 | 検証内容 |
|---|---|---|
| 3-1 | 全 named route の middleware 列が compile() の attributes と厳密一致する | 検査 1 の本体 |
| 3-2 | 後付けの 5 系統が attributes に現れる（空振り防止） | 検査 1 の負のコントロール。代表 route を名指し |
| 3-3 | passkey 削除 route で 3 つの alias の順序が保たれる | 付与順の契約が焼き込み入力でも崩れないこと |
| 3-4 | 保護が載った compiled 経路一覧では 2FA 秘密 GET が 409 で秘密を返さない | 検査 2-a |
| 3-5 | recent-auth を 1 本抜いた compiled 経路一覧では同じ要求が 200 になる | 検査 2-b（剥落の実測） |
| 3-6 | 直列化の準備は middleware 列を変えず、複製が元へ波及しない | 検査 3 |

- `RefreshDatabase` はグローバル適用。個別の `DatabaseTransactions` は使わない。
- テストデータは Factory（`User::factory()->withTwoFactor()`）で作る。

### PHPStan 適合チェック

- [ ] `compile()` の戻り値の形を局所の PHPDoc で宣言する
      （`array{compiled: array<mixed>, attributes: array<string, array{action: array<string, mixed>, …}>}` 程度）。
      `mixed` のまま添字を掘らない
- [ ] `action['middleware']` は `list<string>` へ絞ってから比較する（`array_values` + 型表明）
- [ ] `getByName()` の戻りは `Route|null` なので `instanceof` で絞る
- [ ] 型を緩める注釈・baseline は使わない（禁止事項 2）

### リスク

- **3-1 が広すぎて壊れやすい可能性**: 名前のない route は `attributes` のキーが空文字に潰れるため、
  比較の母集団は**名前を持つ route に限る**。この限定を docblock に書く。
- **3-5 が Fortify の実装変更で 200 以外になる可能性**: そのときは「保護が外れる」の実測が
  変わったということなので、テストを弱めずに実測を取り直す（弱めると設計の根拠が消える）。
- **並列実行**: 経路一覧の差し替えはプロセス内で完結し、プロセス間でアプリ状態を共有しないため
  `--parallel` と両立する。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は新規テスト 2 本と、`AGENTS.md` / `docs/template-divergence.md` / `docs/app-integration-guide.md` の追記だけで完結する。`app/` には一切触れない。一方で `AGENTS.md` と `docs/app-integration-guide.md` は他タスクも触る中心ドキュメントなので、単独の worktree で完結させてから main へ入れる方が競合を捌きやすい |
| 競合リスク | `AGENTS.md`（運用要件ブロック末尾への追記）と `docs/app-integration-guide.md` §7c、`docs/template-divergence.md`（末尾へ D19 追加）。いずれも**既存段落を書き換えず末尾に足す**形にすることで、行単位の衝突を最小化する。D 番号は登録時点の最終番号を確認してから採る（他タスクが D19 を先に取っていたら D20 にする） |

## 関連する現行コード（抜粋）

### app/Support/Http/RouteMiddlewareBinder.php
```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use RuntimeException;

/**
 * vendor が登録した named route へ **middleware alias** を後付けする binder。
 *
 * ★責務境界 (統合しない): {@see RouteThrottleBinder} は **limiter の形式検証・二重付与検出**
 *   という固有責務を持つ throttle 後付け専用の binder であり、こちらは
 *   **任意の alias を spec の列の順に冪等に足すだけ**である。
 *   **「throttle は必ず向こう」ではない**: 本 binder も `passkey.destroy` へ
 *   `throttle:passkeys` を付ける (既存の付与内容・付与順を 1 つも変えないため
 *   passkey 系は 1 route の alias 列として **throttle → recent-auth → 手段保持** の順で
 *   まとめて扱う必要があり、throttle だけ別 binder へ切り出すと順序契約が割れる)。
 *   本 binder にとって `throttle:passkeys` は**検証対象ではないただの alias 文字列**である。
 *   両者は route:cache との契約 (下記) を共有する。
 *
 * ★**2 つの事象を混ぜないこと** (ここが本 binder の存在理由):
 *
 *   1. **生成時** (`php artisan route:cache` の実行中)
 *      `RouteCacheCommand::handle()` は先頭で `route:clear` してから
 *      `getFreshApplicationRoutes()` で **cache 無しのアプリを再 bootstrap** する。
 *      そこでは `loadRoutesFrom()` が `require` を通すため本後付けが**完全に走り**、
 *      付与済み middleware がそのまま cache へ**焼き込まれる**。
 *      route 名が消えていれば**ここでデプロイが止まる**。
 *      ★fail-fast そのものは「**本後付けが実際に走る起動すべて**」= route cache が無い起動
 *        (ローカル開発起動・テスト・`route:cache` 生成時の再 bootstrap) で効く。
 *        `route:cache` 生成時だけに効くわけではない (等号で結ばない)。
 *        ただし**本番デプロイで意味を持つ地点はここ**である —
 *        cached 運用の本番では、ここで止まらなければサービス投入まで誰も気づかない。
 *
 *   2. **起動時** (route cache がある状態でのリクエスト処理 / artisan)
 *      本後付けは **1 本も効かない**。理由は 2 つあり、片方だけでも成立する:
 *        (a) `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を
 *            飛ばす。Fortify / laravel-passkeys はこれを使うため、**この callback が走る
 *            時点では対象 named route が 1 本も登録されていない**
 *            (compiled routes は後で読まれるので「route が永久に存在しない」の意味ではない。
 *            ここを誤読すると次の担当がまた別の誤った結論に着く)。
 *        (b) 仮に触れていても、framework の `RouteServiceProvider` が本 callback より**後**の
 *            app-booted で compiled routes を読み、`Router::setCompiledRoutes()` が
 *            route collection を**新品へ丸ごと差し替える**ため捨てられる。
 *      よって cached 起動では `$routesAreCached` を見て**明示 skip** する。
 *      **ここで例外を投げてはならない** (`route:list` が必ず落ちる = T120 の事故)。
 *
 *   ⇒ **cached 起動での保護を持っているのは cache の中身である**。したがって
 *     **`php artisan route:cache` を毎デプロイ再生成することが本機構の前提条件**になる。
 *     stale な route cache は古い付与状態のまま起動し、**無音で保護が外れる**
 *     (実測: 剥がした cache では 2FA 秘密 GET が 409 でなく 200 を返す)。
 *     運用契約の正本は `docs/app-integration-guide.md` §7c。
 *
 * ★よくある誤読の否定 (この記述を消さないこと):
 *   `CompiledRouteCollection::getByName()` が Route instance を `nameCache` へ memoize し、
 *   `match()` がその `getByName()` を通るのは**事実**である。しかしそれは
 *   「**compiled collection が読まれた後に** getByName して書き換えた場合」の話であり、
 *   本 callback はその前に走って**別の collection** を見ているため前提が成立しない。
 *   「nameCache があるから cached 起動でも後付けが効く」とは書かない。
 */
final class RouteMiddlewareBinder
{
    /**
     * 起動完了後に named route 群へ middleware alias を後付けする (登録の唯一の入口)。
     *
     * ★spec を **resolver (callable) で受け、ここでは呼ばない**。理由は 2 つ:
     *   1. spec の構築 (呼び出し側の feature flag 判定を含む) を `boot()` の時点へ
     *      前倒し評価しないため。resolver は **booted callback の中でだけ**評価される。
     *   2. **cached 起動では resolver 自体を実行しない**ため。resolver をここで呼んで
     *      配列にしてから渡す形にすると、将来 resolver が route collection を覗く実装に
     *      なったとき early return の**前**に落ちる = T120 の再導入になる。
     *      ★ただし「型で不可能」なわけではない (`$specs = $specResolver();` してから
     *      `static fn () => $specs` を渡す退行は型検査を通る)。**誇張しない**。
     *      この配線が前倒し評価へ退行していないことは
     *      `RouteMiddlewareBinderTest` の配線テスト (`routes.cached` を true に束ねて
     *      「呼ばれたら throw する resolver」を渡す) が**振る舞いで**固定する。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     *                                                                 route 名 => 付与する middleware alias の列
     */
    public static function attachOnBooted(Application $app, callable $specResolver): void
    {
        $app->booted(static function (Application $app) use ($specResolver): void {
            self::attachAll(
                $app->make(Router::class),
                $specResolver,
                $app instanceof CachesRoutes && $app->routesAreCached(),
            );
        });
    }

    /**
     * named route 群へ middleware alias を後付けする (`$routesAreCached` なら何もしない)。
     *
     * skip 判定を**引数で受ける**ことで、判定と後付けの両方を純粋関数として検証できる
     * ({@see attachOnBooted} が実アプリの状態を渡す唯一の配線点)。
     *
     * ★`RouteThrottleBinder::attachAll()` は spec を **array** で受けるが、こちらは
     *   **resolver** で受ける。揃っていないのは意図である — あちらの spec は副作用の無い
     *   定数表で cached 起動でも評価してよいが、こちらは feature flag 判定を含み、
     *   「cached では resolver すら呼ばない」ことを保証したいため。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     */
    public static function attachAll(Router $router, callable $specResolver, bool $routesAreCached): void
    {
        if ($routesAreCached) {
            // 後付けは route:cache 生成時に焼き込み済み。
            // ★**resolver を呼ぶ前に**返すこと。route 解決はもちろん spec の構築にも
            //   到達させない (到達させると T120 型の事故を再導入する余地が残る)。
            return;
        }

        $routes = $router->getRoutes();
        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
        $routes->refreshNameLookups();

        foreach ($specResolver() as $name => $aliases) {
            self::attachByName($routes, $name, $aliases);
        }
    }

    /**
     * named route へ middleware alias 群を冪等に後付けする。
```

### tests/Architecture/PostBootRouteMutationInventoryTest.php (冒頭のみ)
```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: 起動後に route collection から named route を引いて
 * 加工するコードは **skip 判定を引数で受ける 2 つの binder に限る**。
 *
 * ★止めたい具体的失敗は 1 つで、**過去に実際に起きている**:
 *   新しい後付け経路を追加した人が cached 起動の skip 判定を書かず、
 *   `routesAreCached()` の起動で例外を投げて `php artisan route:list` が必ず落ちる
 *   (aicue T120。docs/TODO-closed.md の T120 行に記録)。
 *   後付け経路はこの 1 年で 3 本増えており (T120 / T121 / T124)、4 本目が足される
 *   確率は低くない。入口を 2 クラスに絞れば、その 2 クラスが持つ
 *   「skip 判定を引数で受ける純粋関数」の形が自動的に効く。
 *
 * ★何を検査するか: `app/` 配下の PHP ファイルに現れる `getByName(` /
 *   `refreshNameLookups(` の出現ファイルが allowlist の 2 ファイルだけであること。
 *
 * ★何を検査しないか (誇張しない):
 *   - **docblock の主張が機序と一致していること**は検査しない。自然言語の主張は
 *     機械で照合できない。ここで守れるのは「後付けの**入口**が 1 本に絞られている」までである。
 *   - **起動時の route cache 鮮度**は検査しない。本番デプロイは全ファイルを新規展開するため
 *     mtime が揃い、cache が古いソースから作られたかは起動時から判定できない
 *     (「作れるが作らない」ではなく **正しく作れない**)。
 *   - トークン走査であるため `$router->getRoutes()->{$m}(...)` のように変数越しに
 *     組み立てる書き方は**すり抜ける**。この gate は「うっかり」を止めるものであって、
 *     意図的な迂回を止めるものではない。
 *
 * ★素の文字列走査で足りる理由: 現在の出現は 3 ファイル・7 箇所のみで、
 *   コメント中の記述を誤検出しないための `token_get_all()` 除外は**今は入れない**
 *   (思考原則 2: 今必要なものだけ作る)。必要になってから入れる。
 *   ただし docblock 内の記述も検出対象になるため、allowlist 外のファイルで
 *   これらの識別子に**言及**するときは「メソッド名 + `(`」の形を避けること。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 後付け経路の唯一の入口として許可されたファイル (repo 相対)。
 *
 * 増やすときは「skip 判定を引数で受ける純粋関数になっているか」を必ず review すること。
 * これは**意図した摩擦**である。
 *
 * @var list<string>
 */
const POST_BOOT_ROUTE_MUTATION_ALLOWLIST = [
    'app/Support/Http/RouteMiddlewareBinder.php',
    'app/Support/Http/RouteThrottleBinder.php',
];

/**
 * 後付けの痕跡となるトークン。
 *
 * @var list<string>
 */
const POST_BOOT_ROUTE_MUTATION_TOKENS = [
    'getByName(',
    'refreshNameLookups(',
];
```

### tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php (冒頭のみ)
```php
<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Fortify;

/*
 * 2FA seed を返す GET 2 本 (qr-code / secret-key) の recent-auth (step-up) 配線 (T124)。
 *
 * Fortify 登録ルートには FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が
 * booted callback で recent-auth middleware を後付けする。ここではその実効性
 * (stale で遮断 = 秘密を返さない / fresh で通過 = 秘密を返す) を HTTP 経由で検証する。
 * 付与漏れ検出は RecentAuthRouteTest / TwoFactorStepUpInventoryTest (Architecture) 側。
 *
 * ★`Accept: application/json` を**実ヘッダで**指定する (getJson() ヘルパ任せにしない)。
 *   フロント (Settings/Security.svelte) の素 fetch が同じ条件で 409 契約へ入ることの証拠にする。
 */

test('鮮度なしの GET QR コード (Accept: application/json) は 409 recent_auth_required で svg も url も返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required')
        ->assertJsonPath('redirect', route('recent-auth.confirm'))
        ->assertJsonMissingPath('svg')
        ->assertJsonMissingPath('url');
});

test('鮮度なしの GET セットアップキー (Accept: application/json) は 409 で secretKey を返さない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required')
        ->assertJsonMissingPath('secretKey');
});

test('鮮度なしの通常 GET (Accept: text/html) は recent-auth confirm へ 302 する', function (string $uri): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
```

### vendor: Illuminate/Routing/AbstractRouteCollection::compile()
```php
     * Compile the routes for caching.
     *
     * @return array
     */
    public function compile()
    {
        $compiled = $this->dumper()->getCompiledRoutes();

        $attributes = [];

        foreach ($this->getRoutes() as $route) {
            $attributes[$route->getName()] = [
                'methods' => $route->methods(),
                'uri' => $route->uri(),
                'action' => $route->getAction(),
                'fallback' => $route->isFallback,
                'defaults' => $route->defaults,
                'wheres' => $route->wheres,
                'bindingFields' => $route->bindingFields(),
                'lockSeconds' => $route->locksFor(),
                'waitSeconds' => $route->waitsFor(),
                'withTrashed' => $route->allowsTrashedBindings(),
            ];
        }

        return ['compiled' => $compiled, 'attributes' => $attributes];
    }

```

### vendor: Illuminate/Routing/Router::setCompiledRoutes()
```php
     * @return void
     */
    public function setCompiledRoutes(array $routes)
    {
        $this->routes = (new CompiledRouteCollection($routes['compiled'], $routes['attributes']))
            ->setRouter($this)
            ->setContainer($this->container);

        $this->container->instance('routes', $this->routes);
    }

```

### vendor: Illuminate/Routing/Route::prepareForSerialization()
```php
     *
     * @throws \LogicException
     */
    public function prepareForSerialization()
    {
        if ($this->action['uses'] instanceof Closure) {
            $this->action['uses'] = serialize(
                SerializableClosure::unsigned($this->action['uses'])
            );
        }

        if (isset($this->action['missing']) && $this->action['missing'] instanceof Closure) {
            $this->action['missing'] = serialize(
                SerializableClosure::unsigned($this->action['missing'])
            );
        }

        $this->compileRoute();

        unset($this->router, $this->container);
    }

```
