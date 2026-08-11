【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則 — AGENTS.md より】

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【関連するドメイン固有規約 — AGENTS.md ドメイン規約 1】

**シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` /
`video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で
取得した同一トランザクション内で反映する。経路 inventory は
`ScenarioWritePathInventoryTest` (Architecture テスト) へ昇格済み = 新しい書き込み経路は
inventory 登録が必須。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の留意点】
- これは推測ではなく **pipeline-smoke の初回実走で実際に発生した不具合**である
- 「原因側 1 箇所で直す」「migration の default は消さない」は前提として与えられている
- 設計者は inventory 登録について「gate は新たに赤くならない」と正直に書いている。
  この正直さが妥当か、あるいは何か見落としているかを検証してほしい

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260811-1518-manual-create-initial-state/conceptual-design.md の内容）

# 概念設計: manual-create-initial-state

> 一次入力: recon-brief.md
> 実走で発見済みの実在不具合 (pipeline-smoke 初回実行 / aicue:T147)。推測ではない。

## 背景・課題

`VideoManualService::create()` は新規 VideoManual の INSERT に `status` / `scenario_version` を
含めず、DB カラム既定値 (`->default('draft')` / `->default(0)`) に依存している。

```php
$manual = $locked->manuals()->make(['title' => $title]);
$manual->forceFill(['created_by' => $userId])->save();
```

Eloquent は INSERT に含めなかった属性を**インスタンスに持たない**ため、`create()` の戻り値の
`status` は `null` である (`refresh()` するまで)。

### 観測された事実

pipeline-smoke の fixture 段 (`PipelineSmokeCommand::runFixtureStage()`) が
`create()` の戻り値から直接 `status` を読んだ:

```php
$ok = $manual->status === VideoManualStatus::Draft && $documents === 1;   // ← false (null !== Draft)
$detail = "... status={$manual->status->value}";                          // ← ErrorException
```

結果: `RESULT: FAIL (failure_class=unknown)` /
`ErrorException: Attempt to read property "value" on null`。
**LLM は 1 回も呼ばれず費用ゼロで落ちた** (fail-fast は正しく効いた)。

### これは「たまたま踏んだ」以上の問題である — 同一クラス内で方針が割れている

同じ `VideoManualService` の `duplicate()` の docblock は、既定値依存の危険を**明文化している**:

> 複製 manual は必ず status=Draft・scenario_version=0 から開始する
> (**この初期状態を INSERT 時に明示代入し、DB カラム default に依存しない**
>  **= 将来の migration default 変更による silent break を防ぐ**)

`duplicate()` は方針を採り理由まで書いた。`create()` は同じクラスの中で逆をやっている。
**片方だけが守っている不変条件は不変条件ではない。**

### 二次的なドリフト (実コードで確認)

`docs/architecture.md` §シナリオ整合の共有不変条件 の経路表 (L237) が **現行コードと食い違っている**:

> `VideoManualService::duplicate()` | … scenario_version/status のリテラル書き込みはしない
> **(新規行は DB default 依存)** ため検出 1/2/4 は非対象

実際の `duplicate()` は status/scenario_version を明示 write しており、
`ScenarioWritePathInventoryTest` の `STATUS_WRITE_ALLOWED` / `SCENARIO_VERSION_ALLOWED` にも
登録済みである。**ドキュメント側だけが T066 以前の記述で止まっている。**

## 仮説

- **H1**: 原因は「呼び出し側が refresh していないこと」ではなく、
  **`create()` が生成した行の初期状態をアプリ層で宣言していないこと**である。
- **H2**: migration の default は**保険として残す**のが正しい。消すと既存行および
  他の INSERT 経路 (seeder / factory / 将来の一括投入) に影響する。
- **成功判定**: (a) `create()` の戻り値の `status` / `scenario_version` を読むテストが
  修正前に赤・修正後に緑、(b) pipeline-smoke の fixture 段が通る、
  (c) `ScenarioWritePathInventoryTest` が緑、かつ登録を外すと赤くなることを mutation で実証できる。

## 改善アイデア

**`create()` を `duplicate()` の方針に揃える (原因側 1 箇所の修正)。**

```php
$manual->forceFill([
    'created_by' => $userId,
    'status' => VideoManualStatus::Draft,
    'scenario_version' => 0,
])->save();
```

これに伴い:

1. `create()` の docblock を `duplicate()` と同じ水準で書き直す
   (現在は「status は DB default の draft」= これから嘘になる記述)
2. `ScenarioWritePathInventoryTest` の経路表 docblock に `VideoManualService::create()` を追記
3. `docs/architecture.md` §シナリオ整合の共有不変条件 の経路表を現行コードに合わせて是正
4. 再現テスト (fail-first) を `tests/Feature/Projects/ManualServiceBoundaryTest.php` に追加

### 却下した代替案

| 案 | 却下理由 |
|---|---|
| **A. 呼び出し側で `refresh()`** (pipeline-smoke 側の防御) | 原因を残したまま症状だけ消す。全呼び出し側に refresh を強制し、忘れた次の呼び出し側でまた再発する。何より migration default 変更時の silent break を一切防がない。二重に直すと「どちらが本当の保証か」が曖昧になる |
| **B. Model の `protected $attributes` に既定値を置く** | 「フレームワークのレンジ内」ではあるが、(1) 初期状態が書き込み経路から見えなくなる — ドメイン規約 1 の inventory は経路ベース、(2) 思考原則 3 に従えば `duplicate()` の明示代入を撤去することになり、登録済み write 経路と T066 の fail-first 契約テストを壊す、(3) `new VideoManual` を作る全箇所に波及する |
| **C. migration の default を消す** | 既存行と他の INSERT 経路に影響する。default は保険として残す |
| **D. 「INSERT に DB default を頼るな」を機械で横断強制する Architecture テストの新設** | 思考原則 2。判定式を書けない (どのカラムが「初期状態」かは静的に決まらない) 割に偽陽性が多く gate の信用を落とす。作らない |

## 期待効果

- 使命への貢献: pipeline-smoke は「SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4」の
  全段が通ることを確認する唯一の通し確認である。その 1 段目が構造的に落ちる状態を解消する
- 将来の silent break の予防: migration default を変えても `create()` の挙動は変わらない
- クラス内の方針の一致: 「初期状態はアプリ層で宣言する」が `VideoManualService` 全体の規約になる

## 実装方針 (概要)

| # | 対象 | 変更 |
|---|---|---|
| 1 | `app/Services/Manual/VideoManualService.php` | `create()` に `status=Draft` / `scenario_version=0` を明示代入 + docblock 是正 |
| 2 | `tests/Feature/Projects/ManualServiceBoundaryTest.php` | 再現テスト (fail-first) 2 本を追加 |
| 3 | `tests/Architecture/ScenarioWritePathInventoryTest.php` | 経路表 docblock に `create()` を追記 (allowlist 定数の変更は不要 — 後述) |
| 4 | `docs/architecture.md` | §シナリオ整合の共有不変条件 の経路表を現行コードへ是正 + `create()` 追加 |

### inventory 登録について — 誇張しない事実

AGENTS.md ドメイン規約 1 により `video_manuals.status` / `scenario_version` の書き込み経路は
inventory 登録が必須である。ただし `ScenarioWritePathInventoryTest` の allowlist は
**ファイル粒度**であり、`Services/Manual/VideoManualService.php` は `duplicate()` (T066) の時点で
`STATUS_WRITE_ALLOWED` / `SCENARIO_VERSION_ALLOWED` の**両方に既に登録済み**である。

したがって:

- **本変更で gate が新たに赤くなることはない**。「create() を明示代入に変えたら gate が赤くなり、
  登録して緑にした」という筋書きは成り立たない。そう書いたら嘘になる
- 実際に必要な「登録」は経路表 (docblock + docs/architecture.md) への create() の追記である
- gate が正しく赤くなることは mutation で実証する — allowlist から
  `Services/Manual/VideoManualService.php` を一時的に外して赤を確認し、戻す

## 制約・前提

- migration の default は消さない
- pipeline-smoke 側は触らない (原因側 1 箇所で直す)
- `create()` は新規行の生成であり既存行への並行書き込みではない。よって
  ドメイン規約 1 のロック規約に対しては `duplicate()` と同一の論拠が使える。実際 `create()` は既に
  Project 行を `lockForUpdate()` した tx 内で走っている
- テストは `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` 禁止
- PHPStan level 10 / Pint / 既存テスト不変

## 保証しないもの (誇張しない)

- 「VideoManual の INSERT が DB default に依存しない」ことは機械では保証されない。
  本設計が固定するのは `create()` と `duplicate()` の 2 経路の振る舞いだけで、
  将来新設される第 3 の生成経路には沈黙する
- 他モデルの既定値依存は本件で閉じない。実コード走査で
  `take_upload_reservations.status` (`TakeUploadService` が `make()` に含めず default `'pending'` に依存)
  が同型であることを確認したが、その戻り値の `status` を読む呼び出し側は現状 1 つも無いため
  顕在化していない。思考原則 2 に従い本件では直さない。
  なお `analysis_jobs` / `render_jobs` は `$job->status = JobStatus::Queued;` で既に明示代入済み
- pipeline-smoke が緑になることは本修正だけでは保証しない。本修正が閉じるのは fixture 段の
  この 1 因だけであり、後続段 (LLM 実呼び出し 3 段 / ffmpeg 合成) の成否は別問題である

## スコープ外

- 品質評価 / pipeline-smoke の機能追加
- migration の default 削除、既存行の backfill
- `VideoManualStatus` の状態遷移メソッド追加
- `take_upload_reservations` の既定値依存の是正 (記録のみ)
