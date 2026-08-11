# 概念設計: manual-create-initial-state

> 一次入力: [recon-brief.md](recon-brief.md)
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
  `create()` に `status=Draft` / `scenario_version=0` を明示代入すれば、
  戻り値は hydrate 済みになり、pipeline-smoke の踏んだ経路は原因側 1 箇所で閉じる。
- **H2**: migration の default は**保険として残す**のが正しい。消すと既存行および
  他の INSERT 経路 (seeder / factory / 将来の一括投入) に影響し、本件と無関係な破壊を招く。
- **成功判定**:
  - (a) `create()` の戻り値の `status` / `scenario_version` を読むテストが**修正前に赤・修正後に緑**
  - (b) pipeline-smoke の fixture 段が通る
  - (c-1) `ScenarioWritePathInventoryTest` は **allowlist 無変更のまま緑**である
  - (c-2) mutation 2 種で赤化を実証する —
    ① allowlist から `Services/Manual/VideoManualService.php` を外す → gate が赤
    (**これはファイル粒度 gate の実効性の確認であり、`duplicate()` の既存 write だけでも
    成立する赤である = `create()` の登録が load-bearing であることの証明ではない**)、
    ②-a `create()` の `status` 明示代入**のみ**を消す → status の assertion が赤、
    ②-b `create()` の `scenario_version` 明示代入**のみ**を消す → scenario_version の assertion が赤
    (2 つを**個別に**行う。同時に消すと先に評価される assertion で停止し、
    もう片方の保証を実証できない。これが `create()` 経路に対する唯一の機械的保証)

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
   (現在は「status は DB default の draft」= **これから嘘になる記述**)
2. `ScenarioWritePathInventoryTest` の経路表 docblock に `VideoManualService::create()` を追記
3. `docs/architecture.md` §シナリオ整合の共有不変条件 の経路表を**現行コードに合わせて是正**
   (duplicate() の誤記の訂正 + create() の追加)
4. 再現テスト (fail-first) を `tests/Feature/Projects/ManualServiceBoundaryTest.php` に追加

### 却下した代替案

| 案 | 却下理由 |
|---|---|
| **A. 呼び出し側で `refresh()`** (pipeline-smoke 側の防御) | 原因を残したまま症状だけ消す。全呼び出し側に refresh を強制し、忘れた次の呼び出し側でまた再発する。何より **migration default 変更時の silent break (duplicate() の docblock が警告している当のもの) を一切防がない**。二重に直すと「どちらが本当の保証か」が曖昧になる |
| **B. Model の `protected $attributes` に既定値を置く** | 「フレームワークのレンジ内」ではあるが、(1) 初期状態が**書き込み経路から見えなくなる** — ドメイン規約 1 の inventory は経路ベースで、model 側に隠すと経路表が実態を語らなくなる、(2) 思考原則 3 (後方互換の並走を残さない) に従えば `duplicate()` の明示代入を撤去することになり、登録済み write 経路と T066 の fail-first 契約テストを壊す、(3) `new VideoManual` を作る全箇所 (factory / テスト) に波及する。**本件を閉じるのに必要な最小形ではない** |
| **C. migration の default を消す** | recon-brief の「やらないこと」。既存行と他の INSERT 経路に影響する。default は保険として残す |
| **D. 「INSERT に DB default を頼るな」を機械で横断強制する Architecture テストの新設** | 思考原則 2 (今必要なものだけ作る)。判定式を書けない (どのカラムが「初期状態」かはドメイン知識であり静的に決まらない) 割に偽陽性が多く、gate の信用を落とす。**作らない** |

## 期待効果

- **使命への貢献**: pipeline-smoke は「SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4」の
  全段が通ることを確認する唯一の通し確認である。その **1 段目が構造的に落ちる状態**を解消し、
  パイプライン全体の実走確認を再び機能させる (使命の各段が実際に繋がっていることの担保)
- **将来の silent break の予防**: migration default を変えても `create()` の挙動は変わらない
  (duplicate() の docblock が警告していた事象を、クラス全体で閉じる)
- **クラス内の方針の一致**: 「初期状態はアプリ層で宣言する」が `VideoManualService` 全体の規約になる

## 実装方針 (概要)

| # | 対象 | 変更 |
|---|---|---|
| 1 | `app/Services/Manual/VideoManualService.php` | `create()` に `status=Draft` / `scenario_version=0` を明示代入 + docblock 是正 |
| 2 | `tests/Feature/Projects/ManualServiceBoundaryTest.php` | 再現テスト (fail-first) 2 本を追加 |
| 3 | `tests/Architecture/ScenarioWritePathInventoryTest.php` | 経路表 docblock に `create()` を追記 (**allowlist 定数の変更は不要** — 後述) |
| 4 | `docs/architecture.md` | §シナリオ整合の共有不変条件 の経路表を現行コードへ是正 + `create()` 追加 |
| 5 | `AGENTS.md` | ドメイン規約 1 を「更新経路 / 生成経路」の 2 分類に最小改訂 (下記) |

### 施策 5: なぜ `AGENTS.md` (正本) にも手を入れるのか

規約 1 の現行文面は「書き込む**全経路**は、対象 VideoManual 行を `lockForUpdate()` で取得した
同一トランザクション内で反映する」と**例外なく**書いている。しかし `duplicate()` は
**対象行が未存在**のため、この文面を literal には満たしていない。
**つまり矛盾は本設計が持ち込むものではなく、T066 の時点で既に発生していた。**

下位ドキュメント (docs/architecture.md / inventory docblock) だけで例外を説明すると、
「正本を読んだ人が下位ドキュメントに辿り着くまで矛盾に気づかない」状態が固定化する。
これは規約の**追加**ではなく**既存規約の適用範囲の明確化**であり、既存の準拠実装
(`ScenarioService` 等の更新経路) への要求は 1 ミリも緩めない。

改訂の骨子 (2 分類。同じ語彙を inventory docblock と docs/architecture.md でも使う):

- **(i) 既存行の更新**: 対象 VideoManual 行を `lockForUpdate()` した同一 tx 内で反映する
- **(ii) 新規生成**: 対象行は未存在のため、**所有元 Project 行を `lockForUpdate()` した
  同一 tx 内で INSERT** し、そのとき初期状態 (`status` / `scenario_version`) を
  **明示代入する** (DB カラム default に依存しない)

### inventory 登録について — 誇張しない事実

AGENTS.md ドメイン規約 1 により `video_manuals.status` / `scenario_version` の書き込み経路は
inventory 登録が必須である。ただし `ScenarioWritePathInventoryTest` の allowlist は
**ファイル粒度**であり、`Services/Manual/VideoManualService.php` は `duplicate()` (T066) の時点で
`STATUS_WRITE_ALLOWED` / `SCENARIO_VERSION_ALLOWED` の**両方に既に登録済み**である。

したがって:

- **本変更で gate が新たに赤くなることはない**。「create() を明示代入に変えたら gate が赤くなり、
  登録して緑にした」という筋書きは**成り立たない**。そう書いたら嘘になる
- 実際に必要な「登録」は **経路表 (docblock + docs/architecture.md) への create() の追記**である
  (規約 1 が要求する inventory は「メソッド粒度の経路表」であり、その機械側の強制は
  ファイル粒度の allowlist という**粗い近似**になっている)

#### gate 実証は 2 つに分けて書く (Codex Round 1 の指摘を反映)

| 何を実証するか | 手段 | 実証**しない**こと |
|---|---|---|
| ファイル粒度 gate が実際に効いている | allowlist から `Services/Manual/VideoManualService.php` を一時除外 → `ScenarioWritePathInventoryTest` が赤 → 戻す | **`create()` の登録が load-bearing であること**。この赤は既存の `duplicate()` の write だけでも成立する |
| `create()` の明示代入が消えたら気づけること | `create()` の `forceFill` から `status` / `scenario_version` を**1 つずつ**消す → **behavioral 再現テストの対応する assertion が赤** | 将来新設される第 3 の生成経路 (沈黙する) |

**「inventory 登録によって create() が機械検出されるようになる」とは書かない。**
`create()` に対する唯一の機械的保証は **behavioral 再現テスト**である。

### ロック規約 (ドメイン規約 1) に対する `create()` の位置づけ — 生成経路カテゴリ

規約 1 の文面は「**対象 VideoManual 行**を `lockForUpdate()` で取得した同一 tx 内で反映」だが、
`create()` / `duplicate()` は**対象行がまだ存在しない**ため文面を literal には満たせない。
`duplicate()` は既にこの点を docblock で明文化して通している:

> 新規行生成のため lockForUpdate 前だが、その tx が生成した排他的新規行であり
> 既存行への並行書き込みではない

**同じ論拠を `create()` にも明示的に書く** (暗黙に流用しない)。経路表では
`create()` / `duplicate()` を「**生成経路** (既存行更新ではなく、Project 行を `lockForUpdate()` した
同一 tx 内の新規 INSERT)」という同一カテゴリとして扱い、docblock / `docs/architecture.md` の
両方に同じ表現で記す。

## 制約・前提

- **migration の default は消さない** (`->default('draft')` / `->default(0)` は保険として残す)
- **pipeline-smoke 側は触らない** (原因側 1 箇所で直す)
- `create()` は**新規行の生成**であり既存行への並行書き込みではない。よって
  ドメイン規約 1 のロック規約に対しては `duplicate()` と同一の論拠が使える
  (その tx が生成した排他的新規行 + 同一 tx 内反映)。実際 `create()` は既に
  Project 行を `lockForUpdate()` した tx 内で走っている
- テストは `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` 禁止
- PHPStan level 10 / Pint / 既存テスト不変

## 保証しないもの (誇張しない)

- **「VideoManual の INSERT が DB default に依存しない」ことは機械では保証されない**。
  本設計が固定するのは `create()` と `duplicate()` の 2 経路の**振る舞い**だけで、
  将来新設される第 3 の生成経路には**沈黙する** (代替案 D を作らないと決めた帰結)
- **他モデルの既定値依存は本件で閉じない**。実コード走査で
  `take_upload_reservations.status`(`TakeUploadService` が `make()` に含めず default `'pending'` に依存)
  が同型であることを確認したが、**その戻り値の `status` を読む呼び出し側は現状 1 つも無い**ため
  顕在化していない。思考原則 2 に従い**本件では直さない** (別 TODO の候補として記録するに留める)。
  なお `analysis_jobs` / `render_jobs` は `$job->status = JobStatus::Queued;` で既に明示代入済みで、
  同型の問題を持たない
- **pipeline-smoke が緑になることは本修正だけでは保証しない**。本修正が閉じるのは fixture 段の
  この 1 因だけであり、後続段 (LLM 実呼び出し 3 段 / ffmpeg 合成) の成否は別問題である

## スコープ外

- 品質評価 / pipeline-smoke の機能追加
- migration の default 削除、既存行の backfill
- `VideoManualStatus` の状態遷移メソッド追加
- `take_upload_reservations` の既定値依存の是正 (上記のとおり記録のみ)
