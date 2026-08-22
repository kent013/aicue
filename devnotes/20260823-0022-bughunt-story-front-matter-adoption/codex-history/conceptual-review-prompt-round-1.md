## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提 — レビュー時に踏まえること】
- 本件は「家系の機能台帳 lctl の feature `bughunt-story-structure` の正典 t1 への追従」である。
  正典は laravel-claude-template が origin で、テンプレートと aigenba が実装済み。aicue は pending。
- 変更対象はアプリコードではなく、bug-hunt スキルの資材 (シナリオカード / 目録の生成器 / 検査) である。
  したがって DTO / JsonResource / Inertia の観点は「新規 PHP は Architecture テスト 1 本のみ」という
  範囲で見ること。
- aicue は 2026-08-16 に一度この追従を見送っており、その理由 (割当の向きが逆で二重の正本になる) を
  解く設計であることが本件の核心である。
- 「正典に無い上乗せ」「正典から落とすもの」はいずれもテンプレート乖離台帳 (docs/template-divergence.md) への
  登録義務があるアプリである。スコープ判断はその義務を含めて評価すること。

【特に判断してほしい 3 点】
(a) 割当の正本をカードの前付けへ一本化し annotations.toml の story を撤去する判断は妥当か。
    双方向突合 (両方を正本にして食い違いを検出する) を採らない判断に穴はないか。
(b) 「ステップ表の書式の採用」をスコープ外に置く判断は妥当か。正典の boundary には含まれるが
    canonical_version t1 の名前には含まれない、という切り分けは通るか。
(c) coverage/correlate.py の割当セル複数値対応を本作業に含める判断は妥当か。
    含めないと後退が出る、という認識は正しいか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: bughunt-story-front-matter-adoption

家系の機能台帳 lctl の feature `bughunt-story-structure` (正典 t1) への追従設計。

- 正典全文の取得: `get_feature(bughunt-story-structure, project=aicue)` — feature_revision `22-c1b685bffd5e` / ledger_revision `81f0e624363b0c707a424c0695253eb6d1536451`
- 書式の正本の原本実読: `get_source(laravel-claude-template, .claude/skills/app-bug-hunt/stories/README.md)` — resolved_commit `5dd85a6da620e1c957885c694f8be56d40425af2` (18082 bytes)
- 正典の機械検査の原本実読: 同 `stories/test_story_front_matter.py` (54635 bytes) / `coverage/correlate.py` (48918 bytes) / `operations.md` / `stories/S7-authz-boundaries.md`

---

## 背景・課題

### 正典が求めていること (t1)

bug-hunt シナリオカードの先頭に機械可読な前付け (front matter) を置き、**番号を単なる識別子へ戻して、
対象面 (`surface`) と実行方式 (`lane`) を明示の項目に出す**。裁定 AG-034 (2026-08-05) で
`skill-bug-hunt` の 5 分割として起票され、origin は laravel-claude-template。

正典が解こうとしている問題は 2 つある。

1. **番号が 2 つ目の意味を密かに背負っていた**。「前半 = 並列に配れるブラウザの物語 / 後半 = 親が直列で
   追走する面」という実行方式のフラグを整数の通し番号が兼ねていたため、aigenba と spirux で
   同じ番号が別の面を指す食い違いが起きた。
2. **同じ事実が最大 4 か所に手書きで重複していた**。ある操作を「毎回実行する」のか「逸脱時だけ実行する」のかが、
   目録の列・カードの操作行・ステップ表の 3 か所に散り、1 つの区分の訂正に 3 ファイルの修正が要った。

家系の実装状況 (2026-08-22 時点): laravel-claude-template = implemented (t1) / aigenba = implemented (t1) /
metamovics = implemented (テンプレート一括取り込み) / **aicue = pending** / spirux = pending / motivation = pending。

### aicue の現状 (実測 2026-08-23)

| 観点 | 実測 |
|---|---|
| カード枚数 | 7 枚 (`S1`〜`S7`)。`.claude/skills/app-bug-hunt/stories/` |
| 前付けを持つカード | **0 枚 / 7 枚** |
| 書式定義 (`stories/README.md`) | 44 行。**旧テンプレートのスケルトンのまま** (正典は 247 行超) |
| 前付けの機械検査 | **無い** |
| 手順の書き方 | 番号付きリスト (`1.` `2.` `4-b.`)。ステップ識別子は無い |
| 消化する画面・操作 | カード末尾の散文節 `## このストーリーで消化する screens / operations` に route 名で列挙 |
| route → カードの割当の正本 | **`inventory/annotations.toml` の `story` 項目** (T176 / 2026-08-15 で生成器化) |
| 目録 | `screens.md` / `operations.md` は生成物。生成器 `scripts/bug-hunt-inventory.py` が 4 段で検査 |

台帳の aicue セルが記録する「手順が route 名で書かれており目録側の結合鍵と語彙が揃っている
(前付けを入れるときの移行コストが低い側である)」は今も事実である。

### なぜ 2026-08-16 に見送られたか (handover の再読)

aicue 自身が lctl へ handover を残している (2026-08-16T06:10:37+09:00)。要旨:

> 正典の前付けは**カード側に消化する画面と操作を route 名で持ち、目録側の割当列をそこから逆引き生成する**形である。
> 一方 aicue は T176 の後、割当の正本が**注釈の設定ファイルの項目**になった。つまり**割当の向きが逆**であり、
> 正典形の前付けをそのまま入れると割当が 2 か所に並び、しかも**生成物の byte 比較は注釈側しか見ないので
> 食い違っても気づけない** = 二重の正本になる。
> …前付けを入れるなら、**注釈の設定ファイルの割当項目を廃止して前付けへ一本化する**ことを
> 同じ変更で行うのが筋である (思考原則 3 = 後方互換の並走を残さない)。
> **小さな掃除ではなく独立した作業項目として起票すべき**である。

**これは採否の判断ではなく、実装形と作業単位の判断である。** 本設計はその推奨どおり
「前付け付与 → 生成器の入力付け替え」を 1 作業として起票するための設計である。

### 実測で判明した、放置すると効く 2 つの穴

いずれも「割当の向きが逆」であることの帰結であり、追従によって初めて表現できるようになる。

1. **S7 (認可境界) に割り当てられた route が 0 件である**。`annotations.toml` は 1 route → 1 story の
   スカラー項目なので、S3 / S4 が作った状態を組織 B 視点で踏み直す S7 は、どの route も自分のものとして
   宣言できない (実測: S1=27 / S2=9 / S3=31 / S4=31 / S5=11 / S6=27 / **S7=0** / 未割当 (区分 外)=14)。
   カード本文は 9 個の操作を「越境で 404」として列挙しているのに、目録から見ると S7 は何も消化しない
   カードである。正典は `covers_operations` を**カードごとの配列**にして 1 route → N カードを表せるので、
   同じ事実が素直に載る (正典の operations.md には実際に `S4 S7` という複数値セルが出ている)。
2. **`逸` 区分が 1 件も使われていない** (実測 0 件)。区分の語彙は生きているが、逸脱でだけ踏む操作を
   宣言する動機が現状の 1:1 モデルに無い。本設計はここを直接は扱わないが、割当が配列になると
   「通常のカードには載せず逸脱でだけ踏む」を表現する土台ができる。

---

## 改善アイデア

**割当の正本をカードの前付けへ一本化し、目録の割当列を前付けから逆引き生成する。**
`annotations.toml` からは `story` 項目を撤去する (後方互換の並走を残さない)。

責務の切り分けを次のとおり固定する。

| 情報 | 正本 | 理由 |
|---|---|---|
| route の**種別**・**区分**・**対象外の理由** (`kind` / `kubun` / `reason`) | `inventory/annotations.toml` | route ごとの意味であり、実装からも物語からも導けない |
| route を**どのカードが消化するか** | **カードの前付け** (`covers_screens` / `covers_operations`) | 物語側の意思である。1 route → N カードを表せる必要がある |
| **実行方式・依存・初期化要否** (`lane` / `depends_on` / `reseed_before`) | **カードの前付け** | 正典が明文で「正本はカードの前付け」と宣言している |
| **対象面の許可語彙** と **カード目録** | `stories/README.md` のマーカー区間 (表 A / 表 B) | 書式の正本の隣に置き、機械抽出で突合する |
| 兆候番号 (`H{n}`) の意味 | `.claude/skills/app-bug-hunt/SKILL.md` の横断ヒューリスティクス表 | 既存。カードは参照だけを持つ |

これにより「同じ事実が 2 か所に並ぶ」形は 1 つも残らない。`stories/README.md` は表 A / 表 B の
2 列だけを持ち、`lane` / `priority` / `depends_on` の写しは置かない (正典の明文の禁止)。

### 実現の 4 本柱

1. **書式の正本を置く** — `stories/README.md` を正典の書式定義へ差し替える (制限文法・項目定義・
   表 A / 表 B・番号規約・アカウントのトークン)。aicue 固有の差 (下記) だけを明記する。
2. **7 枚へ前付けを付与する** — 必須 13 key + 条件付き 1 key を正準順序で。旧メタ節
   (`前提状態` / `目的` の箇条・`## このストーリーで消化する screens / operations`) を撤去し、
   同じ事実を前付けと `## 目的` 節へ移す。カード内に二重の正本を作らない。
3. **生成器の入力を付け替える** — `scripts/bug-hunt-inventory.py` の段 2 の入力を
   「注釈 (`story` を除く) + カードの前付け」にし、割当列を前付けから逆引きして render する。
   `annotations.toml` から `story` を撤去し、未知項目として落ちるようにする (既存の deny-by-default)。
4. **機械検査を置く** — 書式の契約を強制する自己テストを `stories/` に置き、
   `composer test` の配線に載せる (aicue の様式 = 乖離 D21)。加えて生成器の段 2 に
   「対象内 route は 1 枚以上のカードに載ること」等の突合を足す。

---

## 期待効果

- **使命への貢献 (間接)**: bug-hunt は AI-CUE の中核パイプライン (SOP → シナリオ → 撮影 → レンダ) の
  UX 破綻・詰み・認可漏れを見つける唯一の探索的手段である。分母 (どの画面・操作を消化するか) の
  正本が 1 つに定まっていないと、走行の結果が「消化したつもり」で緑に見える。
  本改善は**探索の分母の正しさ**を機械で守り、詰みの見落としを減らす。
- **具体的な改善見込み**:
  - S7 (認可境界) が消化する 9 操作が目録に現れるようになる (現状 0 件)。
    セキュリティ不変条件 (子は親に属する / cross-org 不可 / tenant キー不信) の走行が
    カバレッジ上で可視になる。
  - 割当の食い違いが CI で落ちる。現状は「注釈の `story` が実態と合っていない」を検出する機械が無い。
  - 家系での対応が `surface` で取れるようになり、他リポジトリの走行結果と突き合わせられる。
  - 二重の正本 (2026-08-16 の見送り理由そのもの) を作らずに追従が完了する。

---

## 実装方針 (概要)

### 変更するもの

| # | 対象 | 変更 |
|---|---|---|
| 1 | `.claude/skills/app-bug-hunt/stories/README.md` | 書式の正本へ差し替え (制限文法 / 項目定義 / 表 A / 表 B / 番号規約 / アカウント) |
| 2 | `.claude/skills/app-bug-hunt/stories/S1`〜`S7`*.md (7 枚) | 前付け付与 + 旧メタ節撤去 + `## 目的` 節化 |
| 3 | `.claude/skills/app-bug-hunt/stories/story_front_matter.py` (新規) | 制限文法の読み取り器 (stdlib のみ) |
| 4 | `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py` (新規) | 書式契約の自己テスト |
| 5 | `tests/Architecture/BughuntStoryToolSelfTest.php` (新規) | 4 を `composer test` の配線に載せる |
| 6 | `.claude/skills/app-bug-hunt/inventory/annotations.toml` | `story` 項目を全 route から撤去 + 冒頭の説明を更新 |
| 7 | `scripts/bug-hunt-inventory.py` | 段 2 の入力に前付けを足し、割当列を逆引き生成。突合の drift 条件を追加 |
| 8 | `scripts/tests/test_bug_hunt_inventory.py` | 7 の追加分の自己テスト |
| 9 | `.claude/skills/app-bug-hunt/coverage/correlate.py` | 割当セルの複数値化に追従 (`story` の空白区切りを分解) |
| 10 | `.claude/skills/app-bug-hunt/coverage/test_correlate.py` | 9 の自己テスト |
| 11 | `.claude/skills/app-bug-hunt/screens.md` / `operations.md` | 生成物。7 の再生成で更新される |
| 12 | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` | 乖離台帳の更新 (D20 の追記 + 新規 1 件) |
| 13 | `.claude/skills/app-bug-hunt/SKILL.md` | `applicability: not_applicable` のカードを実走対象から外す契約の明記 (正典が SKILL.md 側の責務と宣言) |

### 正典との差 (aicue 固有として明記するもの)

正典の書式定義は、aicue に無い前提を 2 つ持つ。無理に寄せず、**差を書式の正本に明記して機械検査の
射程を正しく宣言する**。

| 観点 | 正典 | aicue | 扱い |
|---|---|---|---|
| 機能定義の置き場 | `inventory/capabilities.yaml` (`no_route` / `coverage_surface` / `covered_via` を持つ) | `capability-catalog.md` (markdown 3 列。既存乖離 **D20**) | `covers_capabilities` は**実在・形・一意**まで検査する。継承宣言に基づく「被覆漏れ」(正典 C4) は**保証しない**と明記し、再判定条件を置く |
| 画面表の `kind` 語彙 | `screen` / `read` / `redirect` | `画面` / `JSON` (既存乖離 **D20**) | `covers_screens` の突合は「safe method の web 対象内 route であること」で行う (`kind` の語彙に依存させない) |
| 検査の CI 起動 | `scripts/bug-hunt-python-selftest.sh` を CI から起動 | `composer test` の配線に載せる (既存乖離 **D21**) | D21 の様式を踏襲する。新規の乖離は作らない |

### 割当列の複数値化と、その波及 (実測で確認した副作用)

正典の `operations.md` は割当列に `S4 S7` のような**空白区切りの複数値**を出す (原本実読で確認)。
aicue も同じ形にする。ここで 1 つ実害がある。

`coverage/correlate.py` は route 名を持たない finding を「割当が一致する機構群」へ
capability 経由でブロードキャストする (`rows_by_story[row.story]`)。セルを**そのままキー**に
使うため、`"S3 S7"` というセルは `"S3"` の finding と一致しなくなる。
現状 aicue は全行が単一値なのでブロードキャストが効いており、**複数値化はこの経路の後退になる**。

正典の `correlate.py` も同じ書き方であり (原本実読で確認)、正典側にも同じ不正確さがある。
本設計は**後退を作らない**ため、`rows_by_story` の構築時にセルを空白で分解する。
単一値のときの挙動は不変 (厳密な上位互換) であり、家系への還流候補として記録する。

---

## 制約・前提

### 正典の不変条件 (詳細設計で全数を列挙する)

書式の正本 (原本実読) が定める契約は、制限文法 / 項目定義 / 表 A・表 B の構造 / 番号規約 /
依存と実行方式の整合 / `not_applicable` カードの中身 / ステップ表の書式 / 旧メタ節の撤去 /
`covers_*` と目録の突合 / カード本文の確定形 の 10 群である。詳細設計で全数を表にして、
「本作業で満たす」「本作業では満たさない (理由と再判定条件つき)」を 1 件ずつ宣言する。

### アプリ側の制約

- **Python は標準ライブラリのみ** (AGENTS.md §bug-hunt)。読み取り器を自前で書くのはこの規約の帰結であり、
  正典も同じ理由で自前パーサを持つ。
- **既存の検査を壊さない**。`scripts/bug-hunt-inventory-check.sh` は判定を持たない起動だけのシェルで、
  かつ**採用時債務一覧に載っている** (`tests/Support/TemplateDivergence/adoption-debt.tsv`)。
  **本作業はこのファイルを 1 バイトも触らない**。終了コードの契約 (0 / 2 / 3) も変えない。
- **テンプレートと共有するファイル**を触る場合は乖離台帳の登録が要る (`docs/template-fingerprints.json` の
  キーに在るか)。実測: `scripts/bug-hunt-inventory.py` = 在る (既存 D20 の対象パス) /
  `coverage/correlate.py` = 在る (登録が要る) / `annotations.toml` = 在る (D20 の対象パス) /
  **`stories/**` = 無い (アプリ固有領域)**。
- **禁止事項 9**: Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する。

---

## スコープ外

いずれも「本作業に入れなくても後戻りしない」ことを確認したうえで外す。

1. **ステップ表の書式の採用** (正準 4 列 `| step | 操作 | 期待 | 注目 |`・疎な step 識別子 `S{n}-NNN`・
   副ブロック・注目欄への兆候番号の記入)。
   - 正典の `canonical_version` は **t1 = 「前付けの項目定義 + 対象面と実行方式の明示 + S1-S7 の意味の家系固定」**であり、
     ステップ表は版の名前に含まれない。
   - 表への変形は**手順・期待の中身の書き換えと不可分**になる (S1 の `4-b.` のような枝分かれを
     副ブロックへ割り直す判断が要る)。正典の boundary は「各シナリオの手順・期待の中身」を
     **含まない**と宣言している。
   - **読む機械がまだ無い**。aicue の `ledger/findings.schema.json` の必須項目は `story_id` 止まりで、
     step を指す欄が無い (実測)。step 識別子を入れても参照する機械が 0 なのは、
     2026-08-16 の handover が「消化する欄を落とした前付けだけ入れる案」を退けたのと同じ理由で退ける。
   - **今やらないと高くつく**という論は成り立たない。「既知判定が step を指し始めると再採番できない」
     という正典の懸念は、findings が step を持たない限り発生しない。step の導入と
     `findings.schema.json` への step 欄追加は同じ作業単位になる。
   - **再判定条件**: `findings.schema.json` に step を指す欄が入ったとき / 正典が t2 以降で
     ステップ表を版の名前に含めたとき。
2. **S8 以降のカードの新設** (`result_view` / `admin_console` / `cli_or_api` / `public_share` の面)。
   正典は分母を web 面に閉じており、admin 面や api 面のカードを足すのは「分母の定義から設計し直す作業」
   だと明文で宣言している。aicue の Filament 管理画面・MCP・機械向け API は現在の目録の母集合外である
   (D20 の保証境界)。
3. **`逸` 区分の活用**。実測 0 件という事実は記録するが、どの操作を逸脱専用にするかは
   走行の設計判断であり本作業の範囲外。
4. **`scripts/bug-hunt-shard.sh` の固定マップの前付けからの導出**。正典自身が
   「両者の一致はまだ機械検査されていない (前付けから導出する後続で閉じる)」と宣言しており、
   正典でも未達である。本作業は前付け側に `lane` / `depends_on` を正本として置くところまでとし、
   固定マップは派生キャッシュのまま残す (呼称・並列枠数は現状のまま)。
5. **`annotations.toml` の TOML → YAML 化**、**機能カタログの生成物化**、**中間 JSON の導入**。
   いずれも既存の乖離 D20 が扱う別の関心事であり、再判定条件も D20 側にある。
6. **アプリコード (`app/` / `resources/` / `routes/` / `database/`) の変更**。本作業は
   bug-hunt の資材と、その検査・生成器・乖離台帳だけを触る。
