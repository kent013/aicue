【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt) の1 本道のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---



あなたは経験豊富なコードレビュアーです。Laravel + Svelte アプリケーション改善の**実装**をレビューしてください。

【前提環境】
- PHP 8.4 + laravel/framework v13.18.0 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし paths は app/config/database/routes のみ。tests は対象外)
- Pest (RefreshDatabase グローバル適用 + --parallel)

【本件 (aicue:T215) の性質 — ここを取り違えないこと】
- rio-development/laravel-claude-template@9b54b74522a6627dd44bd828279a1703d38c398e から
  検査資産 **31 ファイル**を移植した追従作業である。うち **28 本は 1 byte も変えていない**
  (移植元から取得して cmp で一致を確認済み)。残り 3 本だけが部分適合である。
- 適用対象 (退避 release を正常系に持つジョブ) は aicue に **0 件**。家系の裁定 AG-081b が
  「機構とその自己検査が実在すれば実装済み。適用件数は保証の一部ではない」と定めており、
  適用対象 0 件のまま gate が緑になることを受け入れ条件に含めている。
- **app/ は 1 行も変更していない** (追跡外を含めて差分 0 を機械確認済み)。フロントの変更も 0 行。
- したがってレビューしてほしいのは **(a) 部分適合した 3 本の差分、(b) 目録 2 関数の中身
  (20 クラス全数の申告と理由)、(c) 文書 (AGENTS.md ドメイン規約 17 /
  docs/architecture.md の新節 / docs/template-divergence.md の D25)** である。
  byte 一致の 28 本は移植元のレビュー済み資産なので、内容の是非ではなく
  「移植して aicue の既存検査群と衝突しないか」の観点で見てほしい。

【レビュー観点】
1. 目録 (jobDeferralTerminationInventory) の 20 エントリの `reason` が実装の事実と合っているか。
   「退避しない」と言い切れる根拠になっているか。allowlist の口を作っていないか
2. 母集団を既存の `Tests\Support\QueuedJobPopulation::shouldQueueClasses()` から取る判断
   (テンプレート正典は tests/Pest.php に 2 関数を置く) が妥当か。
   逸脱登録 D25 の記述が実態と合っているか
3. 部分適合 3 本の差分が**必要最小限**か。実行される行を変えていないか。
   事実に反する記述を残していないか (移植元の記述が aicue では嘘になる箇所)
4. 文書の「保証しないもの」が**誇張していないか / 逆に漏れていないか**。
   AGENTS.md は要約・docs/architecture.md が正本、という二重管理の回避が守れているか
5. 既存の検査群 (JobExecutionDedupInventoryTest / QueuedJobLeaseInventoryTest /
   QueueDispatchAtomicityInventoryTest / ForbiddenStatementTokenInvariantTest /
   StrictTypesDeclarationGateTest 等) と衝突・意味の重複が生まれていないか
6. 禁止事項 (とくに 1: テストなしの実装完了報告 / 2: PHPStan の widen・baseline) に触れていないか

【出力形式】
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定 (APPROVED / CHANGES_REQUESTED) を書く

---

## 詳細設計書 (抜粋ではなく全文)

# 詳細設計: 退避 (release) を正常系に持つジョブの再試行終端 — 正典資産の移植

- 作業項目: `aicue:T215` (登録予定)
- 概念設計: `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/conceptual-design.md`
- 台帳 feature: `job-deferral-termination` (area: async / canonical_version: v1)
- 移植元: `rio-development/laravel-claude-template@9b54b74522a6627dd44bd828279a1703d38c398e`
  (`main` の 2026-08-17 時点の先端。**取得は必ず `?ref=<sha>` で commit を固定する**)
  (作業項目 `laravel-claude-template:T075` / 台帳の観測点 `laravel-claude-template@d18a46d`)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書 (SOP) を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ (PWA) でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

v1 スコープ: 字幕のみ (TTS 後回し) / 撮影は PWA (同一オリジン・セッション認証) /
動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 (AGENTS.md の正本から転記)

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き (`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

**本件は `app/` を 1 行も変更せず、テストと文書だけを足す。**
禁止事項 4・5・6・7・8 は構造的に触れない。1 は本設計の中心そのもの。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。ただし `phpstan.neon` の `paths` は
  `app` `config` `database` `routes` であり **`tests` を含まない**。
  本件の新規 31 ファイルは解析対象外なので、`composer phpstan` について主張できるのは
  **「悪化していない」ことだけ**である (誇張しない)。
- **Pest** (`composer test`)。`RefreshDatabase` は `tests/Pest.php` でグローバル適用済みで、
  個別の `DatabaseTransactions` は使わない。`--parallel` で実行される。
- `declare(strict_types=1)` + 日本語コメント。
- 整形は `vendor/bin/pint --test` / 修正は `composer fix`。
- PHP 8.4 + `laravel/framework` **v13.18.0** + Svelte 5 + Inertia.js + TypeScript。
  版は `composer.lock` の実値であり、Laravel のメジャー系列の呼び名は本設計では使わない
  (移植元と同一版であることだけが本件の前提である)。
  **本件にフロントの変更は 1 行も無い。**

## 目的と台帳の根拠

### 目的

キューに載る 20 クラス全数について「順番待ちのためにキューへ戻す (退避) を持たない」という
申告を置き、その申告を**毎回の走査で裏取りする** deny-by-default の検査を作る。
将来 aicue が退避を正常系に持つジョブを書いた瞬間に CI が赤くなり、
標準形 v1 (絶対時刻の期限 + 未処理例外の分離計数) を要求する状態にする。

### 台帳の根拠 (裁定番号)

- **AG-081** (2026-08-06): 3 案の比較のうえ統合形 v1 を標準形として確定した。
  必須 4 点は (1) 絶対時刻の期限で終端し試行回数を終端条件にしない、
  (2) 期限の基準時刻を投入時刻に取る、(3) 未処理例外だけを別に数える、
  (4) 採った終端方式を検査で固定する。
- **AG-081b** (2026-08-10、オーナー委任): 「配る側」としての整備を裁定されたセルは
  **機構とその自己検査が実在すれば implemented とする。適用件数は保証の一部ではない。**
  条件は 2 点 — (a) 「適用対象を持たない」という申告を母集団との完全一致で
  deny-by-default に取り走査で毎回裏取りすること、(b) 検出器自身の負のコントロールと、
  依存フレームワークの前提を pin する振る舞い検査を持つこと。
  同裁定は `aicue` と `metamovics` を名指しし「適用対象が無いことは追従しない理由にならない」
  「両セルの目標版を v1 として明示する」と述べている。

### 家系の実装状況 (台帳 `get_feature('job-deferral-termination')` の実読)

| リポジトリ | 状態 | 形 |
|---|---|---|
| `laravel-claude-template` | implemented (v1) | 配る側として整備。適用対象 0 件。`app/` 変更 0 行 |
| `aigenba` | implemented (v1) | 適用対象 1 ジョブ。`app/` 変更 0 行 (保証の層だけが不足していた) |
| `motivation` | implemented (v1) | 原初。配信ジョブ 1 本へ適用 |
| `spirux` | implemented (v1) | 案 2 から統合形へ移行。走査は目録型 (完成形の走査 5 種は未移植) |
| **`aicue`** | **pending** (target_version: v1) | **本件** |
| `metamovics` | pending | テンプレート取り込み待ち |

### `spirux` の申し送りへの回答

`spirux` は差分巡回 2026-08-16 で
「重複防止の仕組み (`WithoutOverlapping`) は**戻すまでの秒数を書かなくても既定でキューへ戻す**ので、
秒数の明示を条件にする字句の検出器は同じ取り逃がしをする」と家系全体へ申し送っている。

移植する検出器は**この取り逃がしをしない**。`JobDeferralScanner::deferralMarkersIn()` の
`middleware-new` マーカーは**生成式そのもの**(`new WithoutOverlapping(...)` /
FQCN 直書き / alias import / 変数への代入) を検出し、引数の有無を条件にしない。
`dontRelease()` が生成式に直結している形だけを非退避と判定する (それ以外は保守的に退避側へ倒す)。
本設計はこの性質を**受け入れ条件 AC-8 の変異検査**で実際に確かめる。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t215-job-deferral-gate-port/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| 1 | 検出器・契約表・probe の byte 一致移植 | `tests/Support/Queue/` に新規 28 本 | 最高 |
| 2 | 配布雛形の移植 (docblock 2 箇所のみ適合) | `tests/Support/Queue/DeferringJobTemplate.php` | 最高 |
| 3 | 静的 gate の移植と目録 2 関数の実装 | `tests/Architecture/JobDeferralTerminationGateTest.php` | 最高 |
| 4 | 振る舞い検査の移植 (docblock 2 箇所のみ適合) | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | 最高 |
| 5 | 逸脱の登録 | `docs/template-divergence.md` (`aicue:D25`) | 高 |
| 6 | 規約と保証しないものの明文化 | `AGENTS.md` / `docs/architecture.md` | 高 |
| 7 | 移植の byte 一致を確かめる一時スクリプト | `devnotes/.../verify-byte-parity.sh` | 中 |

## 変更ファイル一覧

### 新規 (31)

**`tests/Support/Queue/` — byte 一致 (28 本)**

| # | ファイル | 役割 |
|---|---|---|
| 1 | `JobDeferralContract.php` | 契約表 (退避 middleware 5 / 非退避 3 / container 解決子 3 / 時計起点 4 / 加算メソッド 8 / mode 値域 2) |
| 2 | `JobDeferralScanner.php` | 検出器 (走査根の推移閉包 / マーカー 5 kind / C1-C4 の純関数) |
| 3 | `DeferralProbeAliasedHorizon.php` | C4 正例 (alias 解決) |
| 4 | `DeferralProbeInheritedHorizon.php` | C4 正例 (継承) |
| 5 | `DeferralProbeInheritedHorizonBase.php` | 上記の基底 (定数の供給元) |
| 6 | `DeferralProbeInheritedHorizonZero.php` | C4 負例 (`static::` が 0 分) |
| 7 | `DeferralProbeInheritedTries.php` | C2 負例 (継承した `$tries`) |
| 8 | `DeferralProbeInnerReleasingTrait.php` | 走査根の推移閉包の内側 trait |
| 9 | `DeferralProbeInteractsOnly.php` | 負例 (vendor trait を走査根に含めて 0 件) |
| 10 | `DeferralProbeMissingContract.php` | C1 / C3 負例 |
| 11 | `DeferralProbeNestedTraitJob.php` | 推移閉包の正例 |
| 12 | `DeferralProbeNullableHorizon.php` | C1 負例 (戻り型が nullable) |
| 13 | `DeferralProbeOuterTrait.php` | 推移閉包の外側 trait |
| 14 | `DeferralProbePropertyHorizon.php` | C1 負例 (メソッドでなくプロパティ) |
| 15 | `DeferralProbeShadowedHorizon.php` | C4 正例 (1 ファイル 2 クラスの行範囲切り出し) |
| 16 | `DeferralProbeTimestampHorizon.php` | C1 負例 (戻り型が int) |
| 17 | `DeferralProbeTriesAttributeTrait.php` | C2 負例の材料 |
| 18 | `DeferralProbeTriesBase.php` | C2 負例の材料 (基底) |
| 19 | `DeferralProbeTriesMethod.php` | C2 負例 (`tries()`) |
| 20 | `DeferralProbeTriesOuterAttributeTrait.php` | C2 負例の材料 |
| 21 | `DeferralProbeTriesProperty.php` | C2 負例 (`$tries`) |
| 22 | `DeferralProbeTriesUninitialized.php` | C2 負例 (既定値なし typed property) |
| 23 | `DeferralProbeTriesViaNestedTrait.php` | C2 負例 (trait の trait の `#[Tries]`) |
| 24 | `DeferralProbeTriesViaTrait.php` | C2 負例 (trait 経由の `$tries`) |
| 25 | `DeferralProbeZeroMaxExceptions.php` | C3 負例 (`$maxExceptions = 0`) |
| 26 | `DeferringNoContractProbeJob.php` | B3 対照 (期限を持たず回数で終端する) |
| 27 | `DeferringReleaseProbeJob.php` | B0 / B2 / B3 (退避を正常系に持つ) |
| 28 | `DeferringThrowProbeJob.php` | B4 (未処理例外を投げる) |

**`tests/Support/Queue/` — docblock 2 箇所のみ適合 (1 本)**

| # | ファイル | 役割 |
|---|---|---|
| 29 | `DeferringJobTemplate.php` | 配布雛形 (`app/Jobs/` へコピーして使う標準形 v1 の見本) |

**検査 (2 本)**

| # | ファイル | 役割 |
|---|---|---|
| 30 | `tests/Architecture/JobDeferralTerminationGateTest.php` | 静的 gate 16 ケース + 目録 2 関数 |
| 31 | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | 振る舞い検査 5 ケース (docblock 2 箇所のみ適合) |

**作業用 (恒久化しない。すべて devnotes 配下)**

- `verify-byte-parity.sh` — byte 一致 28 本 + 部分適合 3 本を移植元と突き合わせる。
  `scripts/` へは昇格させない (1 回きりの移植の検証であり、恒久的に回す性質が無い)。
- `mutations/M1.patch` 〜 `mutations/M12.patch` — テストファースト計画の変異 12 通り。
  当てるのは `git apply`、戻すのは `git apply -R` で**同じパッチを逆適用**する
  (広い取り消しを使わない)。
- `mutation-log.md` — 変異ごとの「変異名 / 期待 / 実測 / 戻した後の `git status --porcelain`」。
- `base-sha.txt` — worktree を作った時点の `main` の SHA (AC-7 の比較基準)。

> **「恒久化しない」の意味**: 上の 4 種は **PR に含めてコミットする** (AC-8 / AC-9 / AC-7 の証跡であり、
> 後から「本当に変異が赤くなったのか」を追えなくなると受け入れ条件が検証不能になる)。
> 恒久化しないというのは **`scripts/` へ昇格させない** (= `scripts/README.md` の台帳に載せず、
> CI や定期実行の配線を作らない) という意味である。devnotes は設計と議論の履歴を
> コミット対象に含める置き場所なので、そこへ残すことと矛盾しない。

### 変更 (5)

| ファイル | 変更内容 |
|---|---|
| `AGENTS.md` | ドメイン固有規約に **17.** を追加 (退避を正常系に持つジョブの終端方式) |
| `docs/architecture.md` | 「§退避を正常系に持つジョブの終端方式」を新設 (保証しないものの正本) |
| `docs/template-divergence.md` | `D25` を登録 + 冒頭の「登録エントリ: 23 件」を **24 件** へ |
| `docs/TODO.md` | `aicue:T215` の登録 (`app-todo-add` スキルが行う) → クローズ時に削除 |
| `docs/TODO-closed.md` | `aicue:T215` のクローズ行を追加 (`app-todo-close` スキルが行う) |

### 削除 (0)

無し。**後方互換の並走も作らない** (そもそも旧実装が存在しない)。

---

## 施策 1: 検出器・契約表・probe の byte 一致移植

### 変更箇所

`tests/Support/Queue/` に 28 ファイルを新規作成。取得は

```
REF=9b54b74522a6627dd44bd828279a1703d38c398e
gh api "repos/rio-development/laravel-claude-template/contents/tests/Support/Queue/<name>.php?ref=$REF" \
  --jq '.content' | base64 -d > tests/Support/Queue/<name>.php
```

**`?ref=<sha>` を省かない**。`main` を指したまま取ると、設計時に実読した正典と
実際に入るものが別になりうる (byte 一致という主張が「何と一致しているのか」を失う)。
設計時点で `main` の先端 = `9b54b7452` であり、
`JobDeferralScanner.php` と `JobDeferralTerminationGateTest.php` の 2 本については
この sha で取得したものが設計で実読したものと byte 一致することを `cmp` で確認済みである。

### 波及変更

- TypeScript 型定義: **なし**
- API Resource / DTO: **なし**
- テストファイル: 施策 3・4 が参照する (同一 PR 内で同時に入る)

### 前提の実読結果 (byte 一致で成立する根拠)

| 前提 | 移植元 | aicue | 一致 |
|---|---|---|---|
| `laravel/framework` | v13.18.0 | v13.18.0 (`composer.lock`) | ○ |
| `Illuminate\Queue\Attributes\Tries` / `MaxExceptions` | 使用 | `vendor/` に実在 | ○ |
| 退避 middleware 5 種 | `RateLimited` / `RateLimitedWithRedis` / `ThrottlesExceptions` / `ThrottlesExceptionsWithRedis` / `WithoutOverlapping` | 5 種とも `vendor/.../Queue/Middleware/` に実在 | ○ |
| 非退避 middleware 3 種 | `Skip` / `SkipIfBatchCancelled` / `FailOnException` | 3 種とも実在 | ○ |
| namespace | `Tests\Support\Queue` | 同ディレクトリが既に存在 | ○ |
| 名前の衝突 | — | 既存 5 本 (`JobQueueingTransactionRecords` / `QueueDispatchDeferralInventory` / `RecordsJobQueueingTransactionLevel` / `TriesOnceProbeJob` / `TriesThriceProbeJob`) と 1 件も衝突しない | ○ |
| 外部依存 | `DateTimeInterface` / `Illuminate\Queue\Attributes\*` / `Reflection*` / `Illuminate\Bus\Queueable` 等の vendor のみ | 同じ | ○ |

### 既存の検査群との整合 (実読で確認済み)

| 検査 | 判定 | 根拠 |
|---|---|---|
| `StrictTypesDeclarationGateTest` | 通る | 31 本すべてが `declare(strict_types=1)` を持つ |
| `ForbiddenStatementTokenInvariantTest` | 通る | 禁止 4 語彙が 0 件。`tests` は分類済みの置き場所 |
| `NoNonCompoundGlobalUseTest` | 通る | 非複合 `use` はすべて名前付き namespace の中 |
| `PcreUnicodeModifierGateTest` | 通る | `\R` を含むパターンが 0 件 (検出器の唯一の `preg_match` は `\R` を持たない) |
| `CarbonOverflowArithmeticGateTest` | 通る | 使う加算は `addMinutes` / `addHours` のみ |
| `QueueWorkerLeaseInvariantTest` 規則 1 | 通る | ワーカー起動コマンドの文字列を持たない |
| `QueuedJobLeaseInventoryTest` | 影響なし | 母集団は `app/` 走査 |
| `JobExecutionDedupInventoryTest` | 影響なし | 母集団は `QueuedJobPopulation::shouldQueueClasses()` = `app/` 走査 |
| `QueueDispatchAtomicityInventoryTest` | 影響なし | D3 / D5 の母集団も `app/` 走査 |
| `TemplateDivergenceLedgerFormatTest` | 施策 5 で対応 | 対象パスの実在・件数の 3 点一致を満たす |

`tests/Support/Queue/` に `ShouldQueue` 実装の probe を置くこと自体は
既存の `TriesOnceProbeJob` / `TriesThriceProbeJob` という先例があり、
どの母集団検出器も `app/` しか見ないので既存 gate には現れない。

### PHPStan 適合チェック

- [x] `tests/` は `phpstan.neon` の `paths` に含まれないので解析対象外
- [x] `@phpstan-ignore-line` / baseline は 1 件も足さない (禁止事項 2)
- [x] `composer phpstan` の結果は本件で変化しない (悪化なしの確認のみ)

### テスト計画

本施策はテストの材料そのものなので、単体の新規テストは持たない。
検出力の証明は施策 3 の E11-E16 と施策 4 の B0-B4 が担う。
byte 一致は施策 7 のスクリプトが機械で確かめる。

### リスク

- **`gh` の取得に失敗する / 移植元が変わっている**
  → 施策 7 のスクリプトが差分を出すので、気付かず古い版を入れることはない。
    取得できない場合は実装を進めず作業項目を止める (概念設計の前提が崩れるため)。
- **移植元が今後変わり、aicue の写しが古くなる**
  → 本件は「その時点の正典を移す」作業であり、追随の自動化は作らない
    (家系の同期はテンプレート取り込みの領分)。`aicue:D25` の「再判定の条件」に書く。

---

## 施策 2: 配布雛形の移植 (docblock 2 箇所のみ適合)

### 変更箇所

`tests/Support/Queue/DeferringJobTemplate.php` (新規。移植元 3,767 byte)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 施策 3 の E10 が C1-C4 の非劣化を毎回検査する

### 現行コード (移植元。**実行される行はこのまま入れる**)

```php
final class DeferringJobTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 3;

    private const HORIZON_MINUTES = 30;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::HORIZON_MINUTES);
    }

    public function handle(): void
    {
        // 雛形なので何もしない (配布物に「何もしない handle()」以上のものを入れない)。
    }
}
```

### 変更後 (docblock の 2 箇所だけ)

**適合 1 — 目録の所在**

```
  4. **退避を正常系に持たないジョブには適用しない** (失敗が本当の失敗であるジョブ)。
-    分類は `jobDeferralTerminationInventory()` (tests/Pest.php) へ全数申告する。
+    分類は `jobDeferralTerminationInventory()`
+    (tests/Architecture/JobDeferralTerminationGateTest.php) へ全数申告する。
```

理由: aicue は目録を静的 gate のファイル内に持つ (施策 3 / `aicue:D25`)。
所在が違うポインタを配ると、雛形を `app/Jobs/` へ写した人が申告先を探せない。

**適合 2 — 回収経路の有無 (事実の訂正)**

```
  5. **退避したジョブの回収経路を持たないまま本形を採らないこと** —
     終端しなかった仕事がどう回収されるかまで pin しないと、ジョブが黙って消える退行を
-    検出できない (本リポジトリに回収機構は無い。stuck-job-recovery の領分)。
+    検出できない。**本リポジトリには回収の入口が既にある** —
+    `work:recover-stuck --stream=<key>` ただ 1 本である (AGENTS.md ドメイン規約 14)。
+    退避を正常系に持つジョブを足すときは、そこへ系列を 1 つ足すかどうかを必ず判断すること
+    (`App\Enums\Recovery\RecoveryStream` の case / registry / 目録 / Schedule の 4 つを同時に更新する)。
```

理由: **移植元の記述が aicue では事実に反する**。aicue は裁定 AG-083 標準形 v1 に沿って
滞留回収の単一入口 `work:recover-stuck` を実装済みである (ドメイン規約 14)。
そのまま配ると「回収機構は無い」という誤った前提で判断させることになる。
逆に**この 1 文を直さないほうが危険**なので、byte 一致より事実の正しさを優先する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`DateTimeInterface`)
- [x] null 安全 (null を返す経路が無い)
- [x] DTO を返している (該当なし。キュージョブの雛形)
- [x] Generics の型パラメータ (該当なし)
- [x] `tests/` は解析対象外

### テスト計画

- [x] E10 が `DeferringJobTemplate` に対して C1-C4 をすべて掛ける
  (雛形が劣化したら赤くなる)
- [x] B1 / B3 が実キューで雛形の期限の焼き込みを観測する

### リスク

- **docblock の適合が「移植元と違う」ことを後から誤って byte 一致へ戻される**
  → 施策 7 のスクリプトが「この 2 箇所だけが差分であること」を明示的に許容差分として
    列挙するので、戻すと逆にスクリプトが赤くなる。

---

## 施策 3: 静的 gate の移植と目録 2 関数の実装

### 変更箇所

`tests/Architecture/JobDeferralTerminationGateTest.php` (新規)。
16 ケース (E1-E16) は移植元のまま。**追加するのは目録 2 関数と冒頭コメント 1 段落だけ**。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Support/Queue/` の 29 本 (byte 一致 28 + 雛形 1) を `use` する (施策 1・2 と同時)

### 現行コード (aicue の先例)

`tests/Architecture/JobExecutionDedupInventoryTest.php` は目録関数を**自ファイル内**で定義し、
母集団だけを共有クラスから取る。

```php
test('キューに載る全クラスが保証側 or 免除に分類されている (未分類は fail)', function (): void {
    $scanned = QueuedJobPopulation::shouldQueueClasses();
    $classified = array_merge(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
    ...
});
```

### 変更後コード (追加する 2 関数)

```php
use Tests\Support\QueuedJobPopulation;

/**
 * 退避終端 gate の母集団 = **キューに載るクラスの全数**。
 *
 * **母集団を数える実装を新しく作らない**。aicue は「キューに載るクラスの母集団」を
 * `Tests\Support\QueuedJobPopulation` ただ 1 本へ集約しており、`QueuedJobLeaseInventoryTest` と
 * `JobExecutionDedupInventoryTest` が同じ集合を見ることを構造で保証している。
 * ここで別の検出器を建てると、片方だけ更新される食い違いが復活する。
 * テンプレート正典との置き場所の差は docs/template-divergence.md D25 に登録済み。
 *
 * **ここでの「ジョブ」はキューの payload に載るもの全般**を指す (Mailable / Notification を含む)。
 * どれも同じキューに載り同じ試行回数の勘定を受けるので、退避の有無を問う対象として同格である。
 * 帰結として、メールや通知に退避する job middleware を付けると本 gate が赤くなる。
 * それは誤検出ではなく設計どおりの動作である。
 *
 * @return list<class-string>
 */
function jobDeferralTerminationPopulation(): array
{
    return QueuedJobPopulation::shouldQueueClasses();
}

/**
 * 退避 (release) の有無による全数申告台帳 (lctl 裁定 AG-081 標準形 v1)。
 *
 * **allowlist ではない**: 母集団との**完全一致**を要求するので、キューに載るクラスを足したら
 * 必ずここに追記しないと E1 が落ちる。first-party / vendor で分岐を持たない
 * (分岐は allowlist の口になる)。
 *
 * mode:
 *   NO_DEFERRAL … 退避が起きない (失敗は本当の失敗)。走査根に退避マーカーが 0 件であることを
 *                 E4 が毎回裏取りする (申告を信じない)
 *   DEFERS      … 退避が起きうる。C1-C5 を E5-E9 が課す
 *
 * @return list<array{class: class-string, mode: string, reason: string, coveredBy: list<string>}>
 */
function jobDeferralTerminationInventory(): array
{
    // …20 エントリ (下表のとおり。すべて mode = 'NO_DEFERRAL' / coveredBy = [])…
}
```

### 目録 20 エントリ (全数。すべて `NO_DEFERRAL` / `coveredBy` は空)

`reason` は**実装を読んで書く**。共通の型は
「順番待ちのためにキューへ戻す (release) 経路を持たない。退避する job middleware も付けていない。
失敗は本当の失敗であり回数で終端してよい」+ **クラス固有の 1 文**。

| # | クラス | 固有の 1 文 (要旨) |
|---|---|---|
| 1 | `App\Jobs\Manual\RunManualAnalysis` | `startJob` が悲観ロック + status guard で `running` へ遷移させ、取れなければ退避ではなく**その場で終了**する |
| 2 | `App\Jobs\Manual\RunManualRender` | 同上 (`RenderPipeline` が同型) |
| 3 | `App\Jobs\Manual\DeleteRenderOutputsJob` | 削除の冪等 no-op のみ。競合したら退避ではなく何もしない |
| 4 | `App\Jobs\Capture\GenerateTakeThumbnailJob` | 一回性は条件付き UPDATE が担い、0 行更新なら退避ではなく終了する |
| 5 | `App\Jobs\Capture\DeleteTakeObjectsJob` | payload は S3 キーの list のみ。存在しないキーの削除は no-op |
| 6 | `App\Jobs\Billing\AutoRechargeTriggerJob` | 起票を 1 つの tx で行うだけ。起票できない条件では退避ではなくその場で終了する |
| 7 | `App\Jobs\Billing\ExecuteAutoRechargeAttemptJob` | 所有権を条件付き UPDATE で取ってから決済事業者を呼ぶ。取れなければその場で終了 |
| 8 | `App\Jobs\Billing\HandleAutoRechargeChargeFailureJob` | 取り消し確定の要求と短い決着 tx のみ。状態が変わっていればその場で終了 |
| 9 | `App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob` | 収束する同期の upsert のみ。順番待ちの概念を持たない |
| 10 | `App\Jobs\Billing\SetDefaultPaymentMethodJob` | 同上 |
| 11 | `App\Jobs\Billing\SyncBillingCustomerDetails` | 組織名を決済事業者へ写す片方向同期のみ |
| 12 | `App\Mail\InquiryReceivedMail` | 送信は 1 通の呼び出しだけ |
| 13 | `App\Mail\InquiryAcknowledgementMail` | 同上 |
| 14 | `App\Notifications\Account\AccountDeletionRequestedNotification` | 通知の送信だけでドメイン状態を書かない |
| 15 | `App\Notifications\Billing\AutoRechargeActionRequiredNotification` | 同上 |
| 16 | `App\Notifications\Billing\AutoRechargeDisabledNotification` | 同上 |
| 17 | `App\Notifications\Billing\AutoRechargeEnabledNotification` | 同上 |
| 18 | `App\Notifications\Billing\AutoRechargeFailedNotification` | 同上 |
| 19 | `App\Notifications\Billing\PaymentFailedNotification` | 同上 |
| 20 | `App\Notifications\Billing\RenewalReminderNotification` | 同上 |

> `reason` を「同上」で埋めない。E3 が非空しか見ないので機械では止まらないが、
> **後から読む人が「なぜ退避しないのか」を 1 件ずつ判断できること**が目録の値である。
> 実装時は上の要旨を各クラスの実コードで裏取りしてから文にする。

### `appDispatchableVendorJobs()` を移植しない判断

移植元は母集団を `appShouldQueueClasses()` + `appDispatchableVendorJobs()` の 2 つで組むが、
後者は**移植元でも空配列を返す**拡張点である。aicue は
`QueuedJobPopulation::shouldQueueClasses()` 1 本にする。
帰結として、**`app/` の外 (vendor が登録するキュークラス) は母集団に入らない**。
これは移植元と実効的に同じ挙動であり、`docs/architecture.md` の
「保証しないもの」へ明記する。

### E9 (`coveredBy`) が空振りすることについて

`DEFERS` が 0 件なので E5-E9 は空ループになる。**これは裁定 AG-081b が想定した状態そのもの**で、
「0 件だから何も見ていない」わけではないことは E2 (母集団が 0 件でない) /
E10 (雛形に C1-C4 を掛ける) / E11-E16 (検出器の正例・負例 13 形) が毎回示す。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`list<class-string>` / `list<array{...}>`)
- [x] null 安全 (null を返す経路が無い)
- [x] 配列返却だが DTO 化しない — **テストの目録**であり、
      aicue の既存目録 (`jobDedupGuarantees()` 等) と同じ作法に揃える
- [x] `tests/` は解析対象外 (level 10 の対象にならない)

### テスト計画

本施策そのものがテストである。fail-first の手順は §テストファースト計画。

### リスク

- **`--parallel` で目録関数が未定義になる**
  → 同一ファイル内定義なので起きない。先例
    (`JobExecutionDedupInventoryTest` の `jobDedupGuarantees()`) が緑で回っている。
- **E4 が 20 クラス分の走査根 (vendor trait を含む) を毎回読むので遅い**
  → 走査根は「クラス自身 + 祖先 + trait の推移閉包」であり 1 クラスあたり数ファイル。
    移植元は同じ形で許容範囲に収まっている。実測が 10 秒を超えるなら
    **検出力を落とさずに**キャッシュを足す余地はあるが、先回りしては作らない (思考原則 2)。
- **`QueuedJobPopulation::shouldQueueClasses()` が `class_exists()` の副作用を伴う**
  → 既存の 2 gate が既に同じ副作用の上で緑になっている。本件で新しい副作用は生まれない。

---

## 施策 4: 振る舞い検査の移植 (docblock 2 箇所のみ適合)

### 変更箇所

`tests/Feature/Queue/DeferredRetryHorizonTest.php` (新規。5 ケース)

| ケース | 何を pin するか |
|---|---|
| B0 | 退避したジョブは削除されず `attempts` を進めて再投入される (B2 / B3 の前提) |
| B1 | push すると payload の `retryUntil` が **push 時刻 + 地平線**になる (時刻を進めれば期限も進む) |
| B2 | 退避を繰り返しても `retryUntil` が延びない / `uuid` が変わらない |
| B3 | 期限内なら `attempts` が `maxTries` を超えても失敗しない / 期限後は失敗する。**対照**として期限を持たないジョブが回数で終端すること |
| B4 | 未処理例外が `$maxExceptions` に達したところで期限より前に終端する |

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `DeferringJobTemplate` / `DeferringReleaseProbeJob` /
  `DeferringNoContractProbeJob` / `DeferringThrowProbeJob` を `use` する

### 実行環境の前提 (実読で確認済み)

| 前提 | 実測 | 影響 |
|---|---|---|
| `phpunit.xml` の `QUEUE_CONNECTION` | `sync` | 検査は `QueueFacade::connection('database')` と**明示**するので問題ない |
| `phpunit.xml` の `CACHE_STORE` | `array` | B4 の `job-exceptions:{uuid}` カウンタに必要。検査は冒頭で `config('cache.default') === 'array'` を pin し、**`config()->set()` で書き換えない** (`CachePayloadPlainDataGateTest` に触れないため) |
| `jobs` 表の migration | `0001_01_01_000002_create_jobs_table.php` が実在 | ○ |
| `config('queue.connections.database.retry_after')` | 360 (int) | 施策 3 の E13 が config 解決の正例に使う |
| ワーカーの起動 | `app('queue.worker')` を解決して `runNextJob()` を直接呼ぶ | `QueueWorkerLeaseInvariantTest` 規則 1 に触れない |
| 失敗の観測 | `JobFailed` イベント (`failed_jobs` の行数は見ない) | `runNextJob()` 直呼びでは failer の listener が居ないため |

### 変更後 (docblock の 2 箇所だけ)

移植元は同型の先例として `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` を 2 箇所で参照するが、
**aicue にそのファイルは無い**。aicue の同型の先例は
`tests/Feature/Queue/WorkerTimeoutTransitionTest.php` である
(ワーカーを container から解決して直接叩き、失敗を `JobFailed` イベントで数える形)。
参照先をこちらへ差し替える。

```
- * (既存 `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` と同じ形)。
+ * (既存 `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` と同じ形)。
```

```
- * `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` も同じ理由でイベントを数えている。
+ * `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` も同じ理由でイベントを数えている。
```

### PHPStan 適合チェック

- [x] `tests/` は解析対象外
- [x] `Webmozart\Assert\Assert` で null 安全を担保する形が移植元にある (そのまま入れる)
- [x] 個別の `DatabaseTransactions` を使っていない (`RefreshDatabase` のグローバル適用に乗る)

### テスト計画

本施策そのものがテストである。

### リスク

- **`RefreshDatabase` + `--parallel` で `jobs` 表が他レーンと干渉する**
  → テスト DB は worktree ごと・並列シャードごとに分離済み (`docs/worktree-isolation-strategy.md`)。
    既存の `WorkerTimeoutTransitionTest` が同じく `jobs` 表を実際に使って緑で回っている。
- **`Carbon::setTestNow()` の後始末漏れ**
  → 移植元が `afterEach` で `Carbon::setTestNow(null)` を戻す形を持つ。そのまま入れる。

---

## 施策 5: 逸脱の登録 (`aicue:D25`)

### 変更箇所

`docs/template-divergence.md`
(冒頭の「登録エントリ: 23 件」→「24 件」、末尾に `D25` を追加)

### 登録メタ表 (9 行ちょうど・この順序)

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/JobDeferralTerminationGateTest.php` |
| 業務要件起因の説明 | キューに載るクラスの母集団を決める実装を `tests/Support/QueuedJobPopulation.php` ただ 1 本へ集約する既存の判断があり、正典の形をそのまま持ち込むと母集団の実装が 2 本になって片方だけ更新される食い違いが復活する |
| 揃え続ける不変条件と保証機構 | 母集団と全数申告の完全一致を既定拒否で取り、退避を持たないという申告を毎回の走査で裏取りする。同ファイルの E1 から E4 が固定する |
| 再判定の条件 | 母集団の正本が `QueuedJobPopulation` から移ったとき / 並列実行で同一ファイル内の関数定義が解決されなくなったとき / 移植元が目録の置き場所を変えたとき |
| 決めた日 | 2026-08-17 |
| 決めた人 | 開発者 |
| 根拠 | T215 |
| 状態 | 恒久 |
| 見直し期限 | — |

> **書式の注意**: `docs/template-divergence.md` の「根拠」欄の値域は
> `T<n>` (3 桁以上のゼロ埋め) であり、`<repo>:` 修飾は書式に含まれない
> (`TemplateDivergenceLedgerFormatTest` が値域を機械で強制する)。
> 他リポジトリから参照するときに `aicue:D25` / `aicue:T215` と書く、という規律は
> 同ファイルの「記録の原則」に既に書かれている。

### 対比表

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 母集団と目録の置き場所 | `tests/Pest.php` の 2 関数 | 静的 gate のファイル内の 2 関数 |
| 母集団の供給元 | `appShouldQueueClasses()` + `appDispatchableVendorJobs()` (後者は空) | `Tests\Support\QueuedJobPopulation::shouldQueueClasses()` (既存の唯一の正本) |
| 守る不変条件 | 母集団と申告の完全一致 + 走査による裏取り | **同じ** |
| 保証機構 | E1-E4 | **同じ** |

### 本文に書くこと

- なぜ正当な差分か: 上記「業務要件起因の説明」の展開。
  正典が `tests/Pest.php` を選んだ理由 (並列実行で別プロセスへ振り分けられると
  他のテストファイルの関数を参照できない) は**同一ファイル内の定義には掛からない**こと。
  aicue に先例 (`JobExecutionDedupInventoryTest`) があること。
- ここでの「ジョブ」がキューに載るもの全般 (Mailable / Notification を含む) を指すこと。
- 保証しないもの: `app/` の外 (vendor が登録するキュークラス) は母集団に入らないこと。

### リスク

- **対象パスの重複**: 登録メタ表の規約は「全登録の和集合で重複しないこと」。
  `tests/Architecture/JobDeferralTerminationGateTest.php` は新規ファイルなので重複しない。
- **件数の 3 点一致**: 冒頭の「登録エントリ: N 件」を 24 へ直し忘れると
  `TemplateDivergenceLedgerFormatTest` が赤くなる (**それが正しい振る舞い**)。

---

## 施策 6: 規約と保証しないものの明文化

### 変更箇所 1: `AGENTS.md` のドメイン固有規約に **17.** を追加

現在 16 まで。17 として次を足す (要旨。実装時に既存 16 項の語調へ揃える)。

> 17. **退避を正常系に持つジョブの終端方式 (`aicue:T215` / 家系の裁定 AG-081・AG-081b 標準形 v1)**:
>     キューに載るクラス (`ShouldQueue` 実装の全数。Mailable / Notification を含む) は、
>     `tests/Architecture/JobDeferralTerminationGateTest.php` の全数申告へ
>     `NO_DEFERRAL` か `DEFERS` のどちらかで登録する (deny-by-default。allowlist の口は無い)。
>     - **`NO_DEFERRAL` の申告は信じない**。走査根 (クラス自身 + 祖先 + trait の推移閉包。
>       vendor を含む) に退避マーカーが 0 件であることを E4 が毎回裏取りする。
>     - **`DEFERS` にしたら標準形 v1 が要る** — 絶対時刻の期限 (`retryUntil()`) を持ち、
>       `$tries` / `#[Tries]` / `tries()` を**書かない** (期限があるとワーカーは試行回数を
>       一切参照しないため、書いても効かず誤読しか生まない)。未処理例外は
>       `$maxExceptions` (1 以上) で別に数える。期限の基準時刻は**投入時刻**である。
>       雛形は `tests/Support/Queue/DeferringJobTemplate.php` を `app/Jobs/` へ写して使う。
>     - **退避したジョブの回収まで考える**。回収の入口は `work:recover-stuck` ただ 1 本
>       (ドメイン規約 14)。系列を足すかどうかを必ず判断する。
>     - 契約表 (`tests/Support/Queue/JobDeferralContract.php`) の棚卸しは
>       **Laravel / Carbon を更新したときの PR レビューの義務**である (機械検出できない)。
>     - **保証範囲を誇張しない**: サービスへの委譲 / 動的呼び出し / 自作の job middleware /
>       factory 経由 / 投入サイトでの後付けは**検出できない**。
>       保証しないものの正本は `docs/architecture.md` §退避を正常系に持つジョブの終端方式。

### 変更箇所 2: `docs/architecture.md` に「§退避を正常系に持つジョブの終端方式」を新設

**保証しないものの完全な一覧の正本**をここに置く (AGENTS.md には要約だけを書き、
2 か所に増減を持たない)。書く内容は §保証しないもの (下記) と同じ。

### リスク

- **AGENTS.md の番号の重複**: 現在 16 まで。17 を使う。
  実装時に他の作業項目が同時に 17 を取っていないかを確認する。
- **`docs/architecture.md` の節の重複**: 「§ジョブの重複実行と結果の一回性」
  「§キュー投入の原子性」「§滞留回収の共通基盤」と**別の節**として立てる
  (同じ節に混ぜると、どの検査が何を保証するかが読めなくなる)。

---

## 施策 7: 移植の byte 一致を確かめる一時スクリプト

### 変更箇所

`devnotes/20260817-1309-todo-t215-job-deferral-gate-port/verify-byte-parity.sh` (新規)

### 何をするか

1. 移植元 **31 ファイル**を **commit 固定**で一時ディレクトリへ取得する
   (`REF=9b54b74522a6627dd44bd828279a1703d38c398e` / `gh api ...?ref=$REF`)。
   内訳は `tests/Support/Queue/` 29 + `tests/Architecture/JobDeferralTerminationGateTest.php` 1
   + `tests/Feature/Queue/DeferredRetryHorizonTest.php` 1。
2. **byte 一致を要求する 28 本**を `cmp` で突き合わせ、**1 件でも差分があれば非ゼロ終了**する。
   対象はスクリプト内に**リテラルで 28 行列挙する** (glob で数えない = 取り違えを目で見えるようにする)。
3. **部分適合を許す 3 本**を `diff` で突き合わせ、
   **許容差分の位置が設計どおりであること**を確かめる。
   スクリプト内に許容差分をリテラルで列挙する。

   | ファイル | 許容する差分 |
   |---|---|
   | `tests/Support/Queue/DeferringJobTemplate.php` | docblock 2 箇所 (目録の所在 / 回収経路の有無) |
   | `tests/Feature/Queue/DeferredRetryHorizonTest.php` | docblock 2 箇所 (先例テストの参照先) |
   | `tests/Architecture/JobDeferralTerminationGateTest.php` | 目録 2 関数 + 冒頭コメント 1 段落 + `use` 1 行 |

4. 想定外の差分は**行番号つきで出力**し、終了コードを非ゼロにする。
5. 3 本の合計 = 31、28 + 3 = 31 を**スクリプト自身が数えて突き合わせる**
   (列挙の書き漏らしを黙って通さない)。

### 恒久化しない理由

1 回きりの移植の検証であり、恒久的に回す性質が無い。
`scripts/` へ昇格させると `scripts/README.md` の台帳にも載り、
「移植元へ毎回アクセスする検査」が CI の暗黙の前提になる
(テストレーンの外部 HTTP 出口は既定拒否である。AGENTS.md §テストレーンの外部 HTTP 出口)。
一時スクリプトは devnotes に置く、という既存の運用に従う。

### リスク

- **`gh` の認証が無い環境で動かない** → 一時スクリプトなので CI では回さない。
  移植を行う人の手元でだけ実行する。

---

## テストファースト計画

「どのテストを先に赤にするか」を段階で決める。**赤を見てから緑にする** (思考原則 5)。

### 段階 0: 移植前に赤を作れることの確認

`tests/Architecture/JobDeferralTerminationGateTest.php` は存在しない = 検査が 0 件である。
まずこの不在そのものを出発点として記録する (現状 `composer test` は緑)。

### 段階 1: 母集団の裏取りを先に赤にする (E1)

1. 施策 1 (検出器・契約表・probe) を先に入れる。**この時点ではテストが 1 本も増えない**。
2. 施策 3 の gate を **`jobDeferralTerminationInventory()` が空配列を返す状態**で入れる。
3. `composer test -- --filter JobDeferralTerminationGate` を実行 →
   **E1 が赤**になり、未登録の 20 クラスが名前つきで列挙される。
   これが「母集団の検出が実際に効いている」ことの最初の証拠である。
4. 列挙された 20 件を目録へ書き写し、`reason` を実コードの実読で埋める → E1 が緑。

> ここで**目録を先に手で書かない**。空で赤にして gate に列挙させることで、
> 母集団の検出器が本当に 20 件を見つけていることを人手の写経と独立に確かめられる。

### 段階 2: 申告の裏取りを赤にする (E4)

`app/Jobs/Manual/RunManualAnalysis.php` へ `middleware()` を一時的に足し、3 形を順に試す。
毎回 `composer test -- --filter JobDeferralTerminationGate` を回す。

| # | 変異 | 期待 | 確認するもの |
|---|---|---|---|
| M1 | `return [new WithoutOverlapping('x')];` | **red** (E4) | `RunManualAnalysis <- app/Jobs/Manual/RunManualAnalysis.php:NN (middleware-new)` が出る |
| M2 | `return [new WithoutOverlapping];` (引数なし) | **red** (E4) | 秒数の明示を条件にしていない (`spirux` の申し送りへの直接の回答) |
| M3 | `return [(new WithoutOverlapping('x'))->dontRelease()];` | **green** | fail-closed の境界が設計どおり (生成式に直結した `dontRelease()` だけを非退避と見る) |
5. 一時変更を戻す。**`git checkout app/` のような広い取り消しは使わない**
   (無関係な変更まで巻き添えで消えるため)。変異は
   `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/mutations/<name>.patch` として
   **1 つ 1 つ独立したパッチで持ち**、当てるときは `git apply`、戻すときは
   `git apply -R` で**同じパッチを逆適用**する。戻した後に `git status --porcelain app/` が
   空であることを毎回確かめる。

### 段階 3: 検出器の自己検査を入れる (E10-E16)

移植元のまま入れれば緑になる。ここは**赤を作る側ではなく、赤を作れることの証明**である。
入れた直後に次の変異を当てて、それぞれ赤になることを確かめてから戻す。

| # | 変異 | 期待 | 落ちるケース |
|---|---|---|---|
| M4 | `DeferringJobTemplate::retryUntil()` を `return now();` にする | **red** | E10 (C4) |
| M5 | `DeferringJobTemplate` に `public int $tries = 3;` を足す | **red** | E10 (C2) |
| M6 | `DeferringJobTemplate::$maxExceptions` を `0` にする | **red** | E10 (C3) |
| M7 | `JobDeferralContract::RELEASING_MIDDLEWARE` から `WithoutOverlapping` を消す | **red** | E11 (正例 2) |
| M8 | `JobDeferralScanner` の `dontRelease` 判定を常に true にする | **red** | E12 (対比 3c / 3d) |

### 段階 4: 振る舞いを赤にする (B0-B4)

1. 施策 4 の検査を入れる → 5 ケースが緑になるはずである。
2. **緑が偽物でないこと**を次の変異で確かめる。

| # | 変異 | 期待 | 落ちるケース |
|---|---|---|---|
| M9 | `DeferringReleaseProbeJob::retryUntil()` を消す | **red** | B3 の前半 (期限内なのに失敗しない) |
| M10 | `DeferringThrowProbeJob::$maxExceptions` を `99` にする | **red** | B4 (3 回目で終端しない) |
| M11 | `deferredHorizonRunWorker()` から `setCache()` を消す | **red** | B4 (`$maxExceptions` が無言で効かなくなる) |
| M12 | B1 の期待値を固定時刻にする | **red** | B1 の対照 (時刻を進めると期限も進む) |

3. 変異を**すべて逆適用で戻す** (`git apply -R`)。戻した後に `git status --porcelain` が
   移植したファイル以外に何も出さないことを確かめる。

### 段階 5: 文書と登録

施策 5・6 を入れ、`TemplateDivergenceLedgerFormatTest` が緑であることを確かめる
(件数の直し忘れで赤くなるのが正しい振る舞いなので、**わざと直さずに 1 度赤を見てから**直す)。

### 段階 6: 全数

§受け入れ条件の全検証コマンドを回す。

---

## 受け入れ条件 (機械検証可能な形で)

**AC-1 から AC-12 はすべて機械で判定できる。**
人手のレビューで確認すると決めた条件は、下の「人手で確認する条件」へ分けてある。

| # | 条件 | 検証方法 |
|---|---|---|
| AC-1 | 静的 gate の 16 ケースが緑 | `composer test -- --filter JobDeferralTerminationGate` が 16 passed |
| AC-2 | 振る舞い検査の 5 ケースが緑 | `composer test -- --filter DeferredRetryHorizon` が 5 passed |
| AC-3 | **母集団 = 目録 = 20 件、`DEFERS` = 0 件、`NO_DEFERRAL` = 20 件** | E1 が緑 + 目録のエントリ数が 20 + `mode` が全件 `NO_DEFERRAL` |
| AC-4 | **適用対象 0 件のまま gate が緑である** (裁定 AG-081b の受け入れ) | AC-1 と AC-3 の同時成立 |
| AC-5 | 母集団が 0 件でない (検出器の故障検出) | E2 が緑 |
| AC-6 | 20 件すべての走査根に退避マーカーが 0 件 | E4 が緑 |
| AC-7 | `app/` の差分が **0 行** (追跡外のファイルも含めて 1 件も無い) | 2 条件の**両方**を満たすこと — (a) 追跡下の差分が無い: `git diff --exit-code <base-sha> -- app/` が終了コード 0、(b) **追跡外を含む作業ツリーの差分**が無い: `git status --porcelain -- app/` の出力が空。`<base-sha>` は `scripts/setup-worktree.sh` で worktree を作った時点の `main` の SHA を `devnotes/.../base-sha.txt` へ記録して固定する |
| AC-8 | 変異 **M1-M12 の 12 通り**がすべて**期待どおりの色**になり (red 11 / green 1 = M3)、逆適用で元の緑に戻る | 変異ごとの実行ログを `devnotes/.../mutation-log.md` に残す (変異名 / 期待 / 実測 / 戻した後の `git status --porcelain`) |
| AC-9 | byte 一致 **28 本**の差分が 0 | `devnotes/.../verify-byte-parity.sh` が終了コード 0 |
| AC-10 | 部分適合 3 本の差分が設計どおりの箇所だけ | 同スクリプトが許容差分以外を検出しない |
| AC-11 | `docs/template-divergence.md` の件数 3 点一致 | `composer test -- --filter TemplateDivergenceLedgerFormat` が緑 |
| AC-12 | **全検証コマンドが green** | 下記 |

### 全検証コマンド (すべて green であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

- `composer phpstan` について主張できるのは **「悪化していない」ことだけ**である
  (`tests/` は `paths` に含まれない)。
- フロントの 6 コマンド (`pnpm *`) は**本件で 1 行も変更しないので変化しない**が、
  AGENTS.md の検証コマンド一覧が全数を要求しているので全部回す。
- **AC-7 の基準は `main` という名前ではなく SHA で固定する**。実装中に `main` が進むと
  `git diff main -- app/` の比較基準そのものが動き、「`app/` を触っていない」ことの検査が
  何と比べているのか分からなくなる。また `git diff` は**追跡外のファイルを見ない**ので、
  `app/` へ新しいファイルを置いても差分 0 と読めてしまう (fail-open)。
  したがって (a) SHA 比較の `git diff --exit-code` と
  (b) 追跡外を含む `git status --porcelain` の**両方**を条件にする。
- `composer test` は**ホスト全体で 1 本ずつ**しか走らない (T099 のグローバルロック)。
  待ち時間が出るのは正常で、30 秒ごとの heartbeat が出ている間はハングではない。
  **kill しない / ロックファイルを消さない**。

### 人手で確認する条件 (機械検証しないと決めたもの)

上の AC-1〜AC-12 は**すべて機械で判定できる**。次の 1 件だけは
**人手のレビューで確認する**と決めた。機械検査を作らない理由も一緒に書く。

| # | 条件 | 機械検査を作らない理由 |
|---|---|---|
| MR-1 | `AGENTS.md` ドメイン規約 17 と `docs/architecture.md` の新節が実在し、**保証しないものの完全な一覧は後者にだけある** (`AGENTS.md` 側は要約に留める) | 見出しの実在なら pin できるが、それでは条件の実体 (「完全な一覧が片方にだけある」) を見たことにならない。実体を機械で見るには両方の文章を突き合わせる必要があり、**同じ内容を 2 か所で管理する形**になって、この条件が防ごうとしている食い違いを自分で作ることになる。`AGENTS.md` には既に marker で同期を取る先例 (検証コマンド一覧 / hook 配線) があるが、それらは**機械が生成できる短い事実**の同期であり、保証しないものの散文には当てはまらない (思考原則 2) |

---

## 保証しないもの (誇張しない)

`docs/architecture.md` §退避を正常系に持つジョブの終端方式 を**正本**とし、
`AGENTS.md` には要約だけを書く (2 か所に増減を持たない)。

### 検出器が原理的に見えないもの (レビューの義務として残る)

走査根は「目録エントリのクラス自身 + 祖先クラス + 使用 trait の推移閉包 (vendor を含む)」である。
次はどれも**検出できない**。

1. **サービスへ委譲した先**で退避相当が呼ばれる形 (メソッド境界を越える伝播)
2. **動的呼び出し** (可変メソッド名 / 可変クラス名 / `call_user_func` 系)
3. **自作の job middleware** (自前で書いた、退避を行う middleware)
4. **factory / helper が middleware インスタンスを返す形** (使用サイトに現れない)
5. **投入サイトでの後付け** (`(new Job)->through([new RateLimited(...)])`)。
   マーカーがジョブクラスではなく投入側のファイルに現れるため走査根の外になる
6. `eval` / 動的 `include` で生成されるコード

### 判定の粗さ (意図的に fail-closed へ倒している)

- `dontRelease()` は**生成式に直接連結された形だけ**を非退避と判定する。
  変数へ入れてから呼ぶ形・条件分岐は追跡せず、**保守的に退避ありへ倒す**。
  正当なコードを赤にしうるが、修正経路 (生成式に直結して書く) が常に存在するので受け入れる。
- C4 は「return 式が許可された時計起点から始まり、1 以上の加算だけで構成されること」までを見る。
  **地平線の長さが妥当かは人間の判断**である (1 秒でも通る)。

### 射程の外 (別の機構が持つ)

- **`NO_DEFERRAL` のまま運用で退避が起きること** (ワーカー側の再取得 / リース満了) は射程外。
  `queue-lease-timeout-consistency` (aicue では `QueuedJobLeaseInventoryTest` /
  `QueueWorkerLeaseInvariantTest`) と `stuck-job-recovery` (aicue では `work:recover-stuck`) の担当。
- **重複実行の防止そのもの**は `JobExecutionDedupInventoryTest` (ドメイン規約 6) の担当。
- **キュー投入の原子性**は `QueueDispatchAtomicityInventoryTest` (ドメイン規約 11) の担当。
- **`app/` の外 (vendor が登録するキュークラス)** は母集団に入らない。
  移植元の `appDispatchableVendorJobs()` も空配列であり、実効は同じである。

### 更新で無言に古くなるもの

- **契約表**は vendor の実読で作った閉集合である。
  `laravel/framework` を更新して退避する job middleware が増えても、
  **契約表は自動では増えない** (機械検出できない)。棚卸しは PR レビューの義務である。
  `Carbon` を更新して単位固定の加算メソッドが増えた場合も同じ。
- **振る舞い検査は framework の実装に対する pin** である。更新で赤くなるのが正しい振る舞いで、
  そのとき pin を緩めるのではなく前提の変化を読み直す。

### 型検査について

- `phpstan.neon` の `paths` は `app` `config` `database` `routes` であり `tests` を含まない。
  **新規 31 ファイルは PHPStan level 10 の対象外**である。
  「level 10 が通っている」を「新しいテストの型が保証されている」と読み替えない。

---

## やらないと決めたこと (理由付き)

| やらないこと | 理由 |
|---|---|
| `app/` の既存 11 ジョブへ `retryUntil()` / `$maxExceptions` を足す | 退避が 0 件で**適用対象が無い**。標準形 v1 自身が「退避を正常系に持たないジョブには適用しない」と適用条件を明文化している。足すと思考原則 2 (今必要なものだけ作る) に反する |
| 実行時ガード (アプリ起動時に退避を検出して落とす等) を作る | 正典の概念設計 `laravel-claude-template:R5` で棄却済み。検査だけで足りる |
| 目録と母集団を `tests/Pest.php` へ置く | 母集団を数える実装が aicue の中に 2 本できる。既に潰した食い違いを復活させる (`aicue:D25` として登録) |
| `appDispatchableVendorJobs()` の拡張点を移植する | 移植元でも空配列を返す。使わない抽象を配らない (思考原則 2)。実効の差は「保証しないもの」に明記する |
| 検出器の限界 (委譲 / 動的呼び出し / 自作 middleware など) を埋める | 正典自身が「原理的に塑げない」と宣言している範囲である。埋めようとすると偽陽性か複雑さが跳ねる。**限界ごと移植して明文化する**ほうが正確 |
| 滞留回収に系列を足す | 退避するジョブが 0 件なので足すべき系列が無い。存在しない対象のための機構を先回りして作らない |
| `tests/` を PHPStan の解析対象に含める | 別の変更である。移植の byte 一致性と型検査の導入を 1 つの PR に混ぜると、どちらのレビューも粗くなる。やるなら独立した作業項目 |
| 移植元への追随を自動化する | 家系の同期はテンプレート取り込みの領分。テストレーンの外部 HTTP 出口は既定拒否であり、CI から移植元を毎回引く前提を作らない |
| `verify-byte-parity.sh` を `scripts/` へ昇格させる | 1 回きりの移植の検証で、恒久的に回す性質が無い。一時スクリプトは devnotes に置く既存の運用に従う |
| 台帳 (lctl) へ本設計の時点で書き込む | 観測の記録はキュレーター巡回の専権。実装・マージ・push が終わってから `status_reported` を出す |

---

## 実装モード

| 項目 | 内容 |
|---|---|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1〜4 は 1 つの検査群として同時に入らないと**そもそも読み込めない** (gate が probe を `use` する)。施策 5・6 は同じ変更の中で登録する規約 (`docs/template-divergence.md` の「登録は逸脱を作る変更そのものに含める」)。分割すると中間状態で `composer test` が赤いコミットが必ず生まれる |
| 競合リスク | **低**。`app/` を 1 行も触らず、`tests/Support/Queue/` へ足す 29 本 (byte 一致 28 + 雛形 1) は既存 5 本と名前が衝突しない。ただし `AGENTS.md` のドメイン規約の**番号 17** と `docs/template-divergence.md` の**番号 D25 / 件数 24** は、同時期に走る他の作業項目と取り合いになる。worktree をマージする直前に両方を再確認する |
| worktree | `scripts/setup-worktree.sh T215` → `.claude/worktrees/tasks/T215` / ブランチ `todo/T215` |

---

## 実装差分 (適合させた 3 本 = 移植元との差分)

### 1. tests/Architecture/JobDeferralTerminationGateTest.php (移植元との差分 = 追加のみ 209 行)
```diff
--- upstream/JobDeferralTerminationGateTest.php
+++ aicue/tests/Architecture/JobDeferralTerminationGateTest.php
@@ -2,6 +2,26 @@
 
 declare(strict_types=1);
 
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Jobs\Capture\GenerateTakeThumbnailJob;
+use App\Jobs\Manual\DeleteRenderOutputsJob;
+use App\Jobs\Manual\RunManualAnalysis;
+use App\Jobs\Manual\RunManualRender;
+use App\Mail\InquiryAcknowledgementMail;
+use App\Mail\InquiryReceivedMail;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
+use App\Notifications\Billing\AutoRechargeDisabledNotification;
+use App\Notifications\Billing\AutoRechargeEnabledNotification;
+use App\Notifications\Billing\AutoRechargeFailedNotification;
+use App\Notifications\Billing\PaymentFailedNotification;
+use App\Notifications\Billing\RenewalReminderNotification;
 use Tests\Support\Queue\DeferralProbeAliasedHorizon;
 use Tests\Support\Queue\DeferralProbeInheritedHorizon;
 use Tests\Support\Queue\DeferralProbeInheritedHorizonBase;
@@ -23,6 +43,7 @@
 use Tests\Support\Queue\DeferringJobTemplate;
 use Tests\Support\Queue\JobDeferralContract;
 use Tests\Support\Queue\JobDeferralScanner;
+use Tests\Support\QueuedJobPopulation;
 
 /*
 |--------------------------------------------------------------------------
@@ -71,6 +92,194 @@
 |
 */
 
+/*
+|--------------------------------------------------------------------------
+| 移植元との差 (docs/template-divergence.md D25)
+|--------------------------------------------------------------------------
+|
+| 本 gate はテンプレート正典 (laravel-claude-template) からの移植だが、母集団と目録の
+| **置き場所だけ**を変えている。正典は 2 関数を tests/Pest.php に置き、母集団を
+| `appShouldQueueClasses()` + `appDispatchableVendorJobs()` (正典でも空配列) で組むが、
+| 本リポジトリは「キューに載るクラスの母集団」を決める実装を
+| `Tests\Support\QueuedJobPopulation` **ただ 1 本**へ集約済みであり、
+| 正典の形を持ち込むと母集団を数える実装が 2 本になって片方だけ更新される食い違いが復活する。
+| よって 2 関数は本ファイル内に置き、母集団は既存の正本から取る
+| (先例: JobExecutionDedupInventoryTest が目録関数を自ファイル内に持ち --parallel で緑)。
+| 帰結として、上の「保証しないこと」にある `appDispatchableVendorJobs()` /
+| `vendorQueuedJobInventory()` は**本リポジトリには存在しない**。読み替えは
+| 「app/ の外 (vendor が登録するキュークラス) は母集団に入らない」であり、
+| 実効は移植元と同じである (正典の拡張点も空配列を返す)。
+|
+| **ここでの「ジョブ」はキューの payload に載るもの全般**を指す (Mailable / Notification を含む)。
+| どれも同じキューに載り同じ試行回数の勘定を受けるので、退避の有無を問う対象として同格である。
+| 帰結として、メールや通知に退避する job middleware を付けると本 gate が赤くなる。
+| それは誤検出ではなく設計どおりの動作である。
+|
+*/
+
+/**
+ * 退避終端 gate の母集団 = **キューに載るクラスの全数**。
+ *
+ * **母集団を数える実装を新しく作らない** (上の D25 の段落が理由の正本)。
+ *
+ * @return list<class-string>
+ */
+function jobDeferralTerminationPopulation(): array
+{
+    return QueuedJobPopulation::shouldQueueClasses();
+}
+
+/**
+ * 退避 (release) の有無による全数申告台帳 (lctl 裁定 AG-081 標準形 v1)。
+ *
+ * **allowlist ではない**: 母集団との**完全一致**を要求するので、キューに載るクラスを足したら
+ * 必ずここに追記しないと E1 が落ちる。first-party / vendor で分岐を持たない
+ * (分岐は allowlist の口になる)。
+ *
+ * mode:
+ *   NO_DEFERRAL … 退避が起きない (失敗は本当の失敗)。走査根に退避マーカーが 0 件であることを
+ *                 E4 が毎回裏取りする (申告を信じない)
+ *   DEFERS      … 退避が起きうる。C1-C5 を E5-E9 が課す
+ *
+ * @return list<array{class: class-string, mode: string, reason: string, coveredBy: list<string>}>
+ */
+function jobDeferralTerminationInventory(): array
+{
+    $common = '順番待ちのためにキューへ戻す (release) 経路を持たない。退避する job middleware も付けていない。'
+        .'失敗は本当の失敗であり回数で終端してよい。';
+
+    return [
+        [
+            'class' => AutoRechargeTriggerJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'attempt の起票を 1 つのトランザクションで行うだけで、'
+                .'起票できない条件 (opt-in 未設定 / pending が既にある) では退避ではなくその場で終了する。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => ExecuteAutoRechargeAttemptJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'attempt の所有権は永続状態遷移で取ってから決済事業者を呼ぶ。'
+                .'取れなければ退避ではなくその場で終了し、取りこぼしはリコンサイルが回収する。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => HandleAutoRechargeChargeFailureJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'決済事業者へ請求の現在状態を問い合わせて短い決着トランザクションを書くだけで、'
+                .'状態が既に変わっていれば退避ではなくその場で終了する。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => ReuseSubscriptionPaymentMethodJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'支払方法の解決と保存に収束する片方向の同期だけで、順番待ちの概念を持たない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => SetDefaultPaymentMethodJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'既定の支払方法の設定と控えの更新に収束する片方向の同期だけで、順番待ちの概念を持たない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => SyncBillingCustomerDetails::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'組織名を決済事業者の顧客情報へ写す片方向の同期だけを行う。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => DeleteTakeObjectsJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'payload はファイル置き場のキーの一覧だけで、既に無いキーの削除は何もしない。'
+                .'再試行しても安全なので回数と待ち時間で終端する。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => GenerateTakeThumbnailJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'一回性は条件付き更新が担い、他のワーカーに先を越されていれば退避ではなく終了する。'
+                .'生成に失敗してもテイクは撮影済みのままで、表示は代替画像へ落ちる。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => DeleteRenderOutputsJob::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'世代交代した出力の削除だけを行い、条件に合わなければ退避ではなく何もしない。'
+                .'削除は何度実行しても同じ結果になる。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => RunManualAnalysis::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'解析の開始は対象行の悲観ロックと状態の検査で実行中へ移す形であり、'
+                .'取れなければ退避ではなくその場で終了する。再実行は解析の再要求だけが入口である。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => RunManualRender::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'書き出しの開始も同じ形 (悲観ロックと状態の検査) で、'
+                .'取れなければ退避ではなくその場で終了する。再実行は書き出しの再要求だけが入口である。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => InquiryAcknowledgementMail::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'問い合わせ受付の控えを 1 通送るだけで、他の仕事と順番を争わない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => InquiryReceivedMail::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'問い合わせの内容を運営へ 1 通送るだけで、他の仕事と順番を争わない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => AccountDeletionRequestedNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'退会の申し出を知らせるだけで、業務の状態を書かない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => AutoRechargeActionRequiredNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'追加の手続きが要ることを知らせるだけで、業務の状態を書かない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => AutoRechargeDisabledNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'自動購入が止まったことを知らせるだけで、業務の状態を書かない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => AutoRechargeEnabledNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'自動購入が使えるようになったことを知らせるだけで、業務の状態を書かない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => AutoRechargeFailedNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'自動購入が失敗したことを知らせるだけで、業務の状態を書かない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => PaymentFailedNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'支払いが通らなかったことを知らせるだけで、業務の状態を書かない。',
+            'coveredBy' => [],
+        ],
+        [
+            'class' => RenewalReminderNotification::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'契約更新が近いことを知らせるだけで、業務の状態を書かない。',
+            'coveredBy' => [],
+        ],
+    ];
+}
+
 // ---------------------------------------------------------------------------
 // (a) 台帳と分類の裏取り
 // ---------------------------------------------------------------------------
```

### 2. tests/Support/Queue/DeferringJobTemplate.php (移植元との差分 = docblock 2 箇所)
```diff
--- /tmp/claude-1000/-workspace/5fd4255d-66b2-476d-aaeb-38e7253cd212/scratchpad/upstream/tests/Support/Queue/DeferringJobTemplate.php	2026-08-17 04:48:56.971600936 +0000
+++ tests/Support/Queue/DeferringJobTemplate.php	2026-08-17 06:24:11.259607446 +0000
@@ -30,10 +30,14 @@
  *      (`return now()->addMinutes(config('<自ドメイン>.retry_horizon_minutes'));` も許可形)。
  *      雛形が設定キーを持たないのは、誰も読まない dead config を家系へ配らないためである。
  *   4. **退避を正常系に持たないジョブには適用しない** (失敗が本当の失敗であるジョブ)。
- *      分類は `jobDeferralTerminationInventory()` (tests/Pest.php) へ全数申告する。
+ *      分類は `jobDeferralTerminationInventory()`
+ *      (tests/Architecture/JobDeferralTerminationGateTest.php) へ全数申告する。
  *   5. **退避したジョブの回収経路を持たないまま本形を採らないこと** —
  *      終端しなかった仕事がどう回収されるかまで pin しないと、ジョブが黙って消える退行を
- *      検出できない (本リポジトリに回収機構は無い。stuck-job-recovery の領分)。
+ *      検出できない。**本リポジトリには回収の入口が既にある** —
+ *      `work:recover-stuck --stream=<key>` ただ 1 本である (AGENTS.md ドメイン規約 14)。
+ *      退避を正常系に持つジョブを足すときは、そこへ系列を 1 つ足すかどうかを必ず判断すること
+ *      (`App\Enums\Recovery\RecoveryStream` の case / registry / 目録 / Schedule の 4 つを同時に更新する)。
  *   6. **期限と `$maxExceptions` は push 時に payload へ焼き込まれる**ので、
  *      既に払い出されたジョブには効かない (デプロイ後に投入されたものから効く)。
  *
```

### 3. tests/Feature/Queue/DeferredRetryHorizonTest.php (移植元との差分 = docblock 2 箇所)
```diff
--- /tmp/claude-1000/-workspace/5fd4255d-66b2-476d-aaeb-38e7253cd212/scratchpad/upstream/tests/Feature/Queue/DeferredRetryHorizonTest.php	2026-08-17 04:48:57.902577358 +0000
+++ tests/Feature/Queue/DeferredRetryHorizonTest.php	2026-08-17 06:25:03.376968792 +0000
@@ -101,7 +101,7 @@
  * **ワーカー起動コマンドの文字列をソースへ書かない** (`QueueWorkerLeaseInvariantTest` 規則 1 が
  * git 追跡ファイル全数から起動定義を検出し、非実行と分類するのは devnotes 配下の Markdown だけ)。
  * したがって `Worker` を container から解決して `runNextJob()` を直接呼ぶ形にする
- * (既存 `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` と同じ形)。
+ * (既存 `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` と同じ形)。
  *
  * **cache を明示的に渡す**のが要点である: `Worker::markJobAsFailedIfWillExceedMaxExceptions()` は
  * `if (! $this->cache || ...) { return; }` で始まるため、cache を渡し忘れたワーカーでは
@@ -128,7 +128,7 @@
  * (`Illuminate\Queue\Console\WorkCommand::listenForEvents()` → `queue.failer`)。
  * `runNextJob()` を直接呼ぶ本テストではその listener が居ないので、行数を見ると
  * **常に 0 件 = 偽グリーン**になる (実測済み)。既存の
- * `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` も同じ理由でイベントを数えている。
+ * `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` も同じ理由でイベントを数えている。
  *
  * 戻り値を **ArrayObject (参照型)** にしているのは、素の配列だと listener が書き込む先が
  * 関数ローカルの配列で、呼び出し側が受け取るのはその**コピー**になり、
```

## 実装差分 (文書)

### 4. AGENTS.md / docs (追加分)
```diff
diff --git a/AGENTS.md b/AGENTS.md
index bc9b705..6bd1c15 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -793,3 +793,26 @@ ## ドメイン固有規約
       同 gate が字句で固定する。
     - 保証しないもの (発行との隙間 / API キーの読み取りが残ること / 静的検査の限界) は
       `docs/architecture.md` §組織アクセスの失効 が正本。運用向けの説明は `docs/mcp-oauth.md`
+17. **退避を正常系に持つジョブの終端方式 (T215 / 家系の裁定 AG-081・AG-081b 標準形 v1)**:
+    キューに載るクラス (`ShouldQueue` 実装の全数。Mailable / Notification を含む) は、
+    `tests/Architecture/JobDeferralTerminationGateTest.php` の全数申告へ
+    `NO_DEFERRAL` か `DEFERS` のどちらかで登録する (deny-by-default。allowlist の口は無い。
+    母集団は既存の正本 `Tests\Support\QueuedJobPopulation` から取る = `docs/template-divergence.md` D25)。
+    - **`NO_DEFERRAL` の申告は信じない**。走査根 (クラス自身 + 祖先 + trait の推移閉包。
+      vendor を含む) に退避マーカーが 0 件であることを E4 が毎回裏取りする。
+      現在 `DEFERS` は **0 件**で、適用対象が無いまま gate が緑であることは裁定 AG-081b の
+      想定どおりである (「0 件だから何も見ていない」わけではないことを E2 / E10 / E11-E16 が毎回示す)。
+    - **`DEFERS` にしたら標準形 v1 が要る** — 絶対時刻の期限 (`retryUntil()`) を持ち、
+      `$tries` / `#[Tries]` / `tries()` を**書かない** (期限があるとワーカーは試行回数を
+      一切参照しないため、書いても効かず誤読しか生まない)。未処理例外は
+      `$maxExceptions` (1 以上) で別に数える。期限の基準時刻は**投入時刻**である。
+      雛形は `tests/Support/Queue/DeferringJobTemplate.php` を `app/Jobs/` へ写して使う。
+    - **退避したジョブの回収まで考える**。回収の入口は `work:recover-stuck` ただ 1 本
+      (ドメイン規約 14)。系列を足すかどうかを必ず判断する。
+    - 契約表 (`tests/Support/Queue/JobDeferralContract.php`) の棚卸しは
+      **Laravel / Carbon を更新したときの PR レビューの義務**である (機械検出できない)。
+    - **保証範囲を誇張しない**: サービスへの委譲 / 動的呼び出し / 自作の job middleware /
+      factory 経由 / 投入サイトでの後付けは**検出できない**。`app/` の外 (vendor が登録する
+      キュークラス) は母集団に入らない。**保証しないものの正本は
+      `docs/architecture.md` §退避を正常系に持つジョブの終端方式**
+      (ここは要約であり、増減はそちらで管理する)。
diff --git a/docs/architecture.md b/docs/architecture.md
index 03aa6ed..fcf3709 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -553,6 +553,85 @@ ### オートリチャージの失敗分類
 ログへ載せても green のままになるため (`ThrottleExemptionPremiseTest` と同じ作法)。
 catch を足す必要が出たら、観測目録へ移すか免除の分類を見直すこと。
 
+### 退避を正常系に持つジョブの終端方式 (標準形 v1)
+
+順番待ちのためにいったんキューへ戻す動き (**退避** = `release`) を**正常な流れ**として持つ
+ジョブがある。Laravel はこの「戻す」動作でも試行回数 (`attempts`) を 1 つ消費するため、
+終端条件を試行回数で持つと**一度も失敗していない仕事が回数を使い切って落ちる**。
+家系の裁定 AG-081 が確定した標準形 v1 は、これを (1) 絶対時刻の期限 (`retryUntil()`) で終端し
+試行回数を終端条件にしない、(2) 期限の基準時刻を**投入時刻**に取る、
+(3) 未処理例外だけを `$maxExceptions` で別に数える、(4) 採った終端方式を検査で固定する、
+の 4 点で解く (設計は `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/`)。
+
+**本アプリの現在地**: 退避を正常系に持つジョブは **0 件**である
+(`app/` の `$this->release(` / `$this->job->release(` も、退避する job middleware も 0 件)。
+したがって `app/` へ標準形を適用した箇所は無く、本節が説明するのは**検査だけ**である。
+裁定 AG-081b は「配る側」としての整備について
+**機構とその自己検査が実在すれば実装済みとする。適用件数は保証の一部ではない**と定めており、
+本アプリはその条件 (申告の完全一致と走査による裏取り / 検出器の負のコントロール /
+依存フレームワークの前提を pin する振る舞い検査) を満たす形で移植した。
+
+#### 何を保証するか
+
+| 層 | 実装 | 保証 |
+|---|---|---|
+| 全数申告 | `tests/Architecture/JobDeferralTerminationGateTest.php` の E1-E4 | キューに載るクラス全数が `NO_DEFERRAL` / `DEFERS` のどちらかに分類され、`NO_DEFERRAL` の申告は走査で毎回裏取りされる |
+| 契約 | 同 E5-E9 (C1-C5) | `DEFERS` になった瞬間に標準形 v1 (期限の宣言形 / 試行回数の不宣言 / `$maxExceptions` / 期限式 / 追跡情報) を課す |
+| 配る雛形の非劣化 | 同 E10 | `tests/Support/Queue/DeferringJobTemplate.php` が C1-C4 を満たし続ける |
+| 検出器の自己証明 | 同 E11-E16 | 検出器が「常に緑を返す装置」でないことを正例・負例 13 形で毎回示す |
+| 振る舞い | `tests/Feature/Queue/DeferredRetryHorizonTest.php` の B0-B4 | 「期限は投入時に payload へ焼き込まれ、期限がある間ワーカーは試行回数を参照しない」という framework の前提そのものを実キューで pin する |
+
+母集団は既存の正本 `Tests\Support\QueuedJobPopulation::shouldQueueClasses()` から取る
+(置き場所がテンプレート正典と違う理由は `docs/template-divergence.md` D25)。
+ここでの「ジョブ」は**キューの payload に載るもの全般**を指し、Mailable と Notification を含む。
+
+#### 保証しないもの (誇張しない。ここが正本)
+
+**検出器が原理的に見えないもの** — 走査根は「目録エントリのクラス自身 + 祖先クラス +
+使用 trait の推移閉包 (vendor を含む)」であり、次はどれも**検出できない**。
+いずれも PR レビューの義務として残る。
+
+1. **サービスへ委譲した先**で退避相当が呼ばれる形 (メソッド境界を越える伝播)
+2. **動的呼び出し** (可変メソッド名 / 可変クラス名 / `call_user_func` 系)
+3. **自作の job middleware** (自前で書いた、退避を行う middleware)
+4. **factory / helper が middleware インスタンスを返す形** (使用サイトに現れない)
+5. **投入サイトでの後付け** (`(new Job)->through([new RateLimited(...)])`)。
+   マーカーがジョブクラスではなく投入側のファイルに現れるため走査根の外になる
+6. `eval` / 動的 `include` で生成されるコード
+
+**判定の粗さ (意図的に fail-closed へ倒している)**
+
+- `dontRelease()` は**生成式に直接連結された形だけ**を非退避と判定する。
+  変数へ入れてから呼ぶ形・条件分岐は追跡せず、**保守的に退避ありへ倒す**。
+  正当なコードを赤にしうるが、修正経路 (生成式に直結して書く) が常に存在するので受け入れる
+- C4 は「return 式が許可された時計起点から始まり、1 以上の加算だけで構成されること」までを見る。
+  **地平線の長さが妥当かは人間の判断**である (1 秒でも通る)
+
+**射程の外 (別の機構が持つ)**
+
+- **`NO_DEFERRAL` のまま運用で退避が起きること** (ワーカー側の再取得 / リース満了) は射程外で、
+  §キューのリース期間とワーカー制限時間の規約 と §滞留回収の共通基盤 (`work:recover-stuck`) の担当
+- **重複実行の防止そのもの**は §ジョブの重複実行と結果の一回性 の担当
+- **キュー投入の原子性**は §キュー投入の原子性 の担当
+- **`app/` の外 (vendor が登録するキュークラス)** は母集団に入らない。
+  移植元の拡張点 `appDispatchableVendorJobs()` も空配列を返すので実効は同じだが、
+  「vendor のキュークラスまで見ている」とは読めない
+
+**更新で無言に古くなるもの**
+
+- **契約表** (`tests/Support/Queue/JobDeferralContract.php`) は vendor の実読で作った閉集合である。
+  `laravel/framework` を更新して退避する job middleware が増えても**契約表は自動では増えない**
+  (機械検出できない)。棚卸しは PR レビューの義務である。`Carbon` を更新して
+  単位固定の加算メソッドが増えた場合も同じ
+- **振る舞い検査は framework の実装に対する pin** である。更新で赤くなるのが正しい振る舞いで、
+  そのとき pin を緩めるのではなく前提の変化を読み直す
+
+**型検査について**
+
+- `phpstan.neon` の `paths` は `app` `config` `database` `routes` であり `tests` を含まない。
+  本節の検査資産は **PHPStan level 10 の対象外**である。
+  「level 10 が通っている」を「これらのテストの型が保証されている」と読み替えない
+
 ### AI 解析ジョブの運用契約
 
 - 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index ef93329..71e628c 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 23 件
+登録エントリ: 24 件
 
 ## 記録の原則
 
@@ -1386,3 +1386,71 @@ ### 関連
 - 実装: `app/Services/Auth/SocialiteDriverResolver.php` /
   `app/Services/Auth/Fakes/FakeSocialiteDriverResolver.php`
 - 設計: `devnotes/20260811-1736-bughunt-sso-egress/`
+
+---
+
+## D25 退避終端 gate の母集団と目録を静的 gate のファイル内に置く
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/JobDeferralTerminationGateTest.php` |
+| 業務要件起因の説明 | キューに載るクラスの母集団を決める実装を `tests/Support/QueuedJobPopulation.php` ただ 1 本へ集約する既存の判断があり、正典の形をそのまま持ち込むと母集団の実装が 2 本になって片方だけ更新される食い違いが復活する |
+| 揃え続ける不変条件と保証機構 | 母集団と全数申告の完全一致を既定拒否で取り、退避を持たないという申告を毎回の走査で裏取りする。同ファイルの E1 から E4 が固定する |
+| 再判定の条件 | 母集団の正本が `QueuedJobPopulation` から移ったとき / 並列実行で同一ファイル内の関数定義が解決されなくなったとき / 移植元が目録の置き場所を変えたとき |
+| 決めた日 | 2026-08-17 |
+| 決めた人 | 開発者 |
+| 根拠 | T215 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 母集団と目録の置き場所 | `tests/Pest.php` の 2 関数 | 静的 gate のファイル内の 2 関数 |
+| 母集団の供給元 | `appShouldQueueClasses()` と `appDispatchableVendorJobs()` (後者は移植元でも空) | `Tests\Support\QueuedJobPopulation::shouldQueueClasses()` (既存の唯一の正本) |
+| 守る不変条件 | 母集団と申告の完全一致 と 走査による裏取り | 同じ |
+| 保証機構 | E1 から E4 | 同じ |
+
+### なぜ正当な差分か (logic-driven)
+
+本アプリは「キューに載るクラスの母集団」を決める実装を `Tests\Support\QueuedJobPopulation`
+ただ 1 本へ集約済みである。同クラスの説明が書いているとおり、これは
+`QueuedJobLeaseInventoryTest` と `JobExecutionDedupInventoryTest` が同じ母集団を見ることを
+構造で保証するための集約であり、2 実装に分かれていると片方だけ更新される食い違いが起きる。
+正典の `tests/Pest.php` 版をそのまま持ち込むと、母集団を数える実装が本アプリの中に 2 本できる。
+既に潰した食い違いをわざわざ復活させる変更なので採らない。
+
+正典が `tests/Pest.php` を選んだ理由は「並列実行では Architecture テストが別プロセスへ
+振り分けられうるため、他のテストファイルで定義した関数を参照すると未定義関数で落ちる」ことである。
+**この理由は同一ファイル内の定義には掛からない**。本アプリには先例があり、
+`tests/Architecture/JobExecutionDedupInventoryTest.php` は目録関数を自ファイル内で定義して
+並列実行の下で緑になっている。
+
+ここでの「ジョブ」は**キューの payload に載るもの全般**を指す (メールと通知を含む)。
+どれも同じキューに載り同じ試行回数の勘定を受けるので、退避の有無を問う対象としては同格である。
+帰結として、メールや通知に退避する job middleware を付けると本 gate が赤くなる。
+それは誤検出ではなく設計どおりの動作である。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「母集団と全数申告の完全一致を既定拒否で取り、退避を持たないという申告を毎回の走査で裏取りする」
+
+- E1 が母集団と目録の集合一致を両方向で固定する (登録漏れも stale も落ちる)
+- E2 が母集団 0 件 (検出器の故障) を落とす
+- E3 が申告の値域と理由の非空を固定する
+- E4 が申告を信じず、走査根 (クラス自身と祖先と trait の推移閉包) に退避マーカーが
+  0 件であることを毎回裏取りする
+
+### 保証しないもの
+
+- **`app/` の外 (vendor が登録するキュークラス) は母集団に入らない**。移植元の拡張点
+  `appDispatchableVendorJobs()` も空配列を返すので実効は同じだが、
+  「vendor のキュークラスまで見ている」とは読めない
+- 検出器そのものの限界 (委譲・動的呼び出し・自作 job middleware・factory 経由・
+  投入サイトでの後付け) は移植元と同じで、限界ごと移植している。
+  正本は `docs/architecture.md` §退避を正常系に持つジョブの終端方式
+
+### 関連
+
+- 実装: `tests/Architecture/JobDeferralTerminationGateTest.php` /
+  `tests/Support/Queue/JobDeferralScanner.php` / `tests/Support/Queue/JobDeferralContract.php`
+- 設計: `devnotes/20260817-1309-todo-t215-job-deferral-gate-port/`
```

## byte 一致で入れた 28 本 (内容は移植元のまま。ファイル名のみ)
```
 .../Support/Queue/DeferralProbeAliasedHorizon.php  |   24 +
 .../Queue/DeferralProbeInheritedHorizon.php        |   14 +
 .../Queue/DeferralProbeInheritedHorizonBase.php    |   35 +
 .../Queue/DeferralProbeInheritedHorizonZero.php    |   15 +
 .../Support/Queue/DeferralProbeInheritedTries.php  |   18 +
 .../Queue/DeferralProbeInnerReleasingTrait.php     |   20 +
 tests/Support/Queue/DeferralProbeInteractsOnly.php |   25 +
 .../Support/Queue/DeferralProbeMissingContract.php |   11 +
 .../Support/Queue/DeferralProbeNestedTraitJob.php  |   16 +
 .../Support/Queue/DeferralProbeNullableHorizon.php |   18 +
 tests/Support/Queue/DeferralProbeOuterTrait.php    |   14 +
 .../Support/Queue/DeferralProbePropertyHorizon.php |   21 +
 .../Support/Queue/DeferralProbeShadowedHorizon.php |   40 +
 .../Queue/DeferralProbeTimestampHorizon.php        |   21 +
 .../Queue/DeferralProbeTriesAttributeTrait.php     |   11 +
 tests/Support/Queue/DeferralProbeTriesBase.php     |   11 +
 tests/Support/Queue/DeferralProbeTriesMethod.php   |   28 +
 .../DeferralProbeTriesOuterAttributeTrait.php      |   17 +
 tests/Support/Queue/DeferralProbeTriesProperty.php |   20 +
 .../Queue/DeferralProbeTriesUninitialized.php      |   26 +
 .../Queue/DeferralProbeTriesViaNestedTrait.php     |   20 +
 tests/Support/Queue/DeferralProbeTriesViaTrait.php |   25 +
 .../Queue/DeferralProbeZeroMaxExceptions.php       |   18 +
 tests/Support/Queue/DeferringJobTemplate.php       |   78 ++
 .../Support/Queue/DeferringNoContractProbeJob.php  |   40 +
 tests/Support/Queue/DeferringReleaseProbeJob.php   |   41 +
 tests/Support/Queue/DeferringThrowProbeJob.php     |   43 +
 tests/Support/Queue/JobDeferralContract.php        |   89 ++
 tests/Support/Queue/JobDeferralScanner.php         | 1421 ++++++++++++++++++++
 29 files changed, 2180 insertions(+)
```
