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
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt) の 1 本道のみ)
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

【アプリ固有の思考原則 (AGENTS.md)】
1. フレームワークのレンジ内でやる
2. 今必要なものだけ作る(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. 後方互換の並走を残さない
4. 別物の概念を「似ているから」で統合しない
5. テストファースト
6. タコツボ実装を避ける

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest
- **ただし本設計はアプリコードを 1 行も変更しない**。対象はリポジトリ同梱の LLM バグハント基盤 (.claude/skills/app-bug-hunt/) の台帳資産で、変更は Python (stdlib のみ) と Markdown / JSON / JSONL である
- 「家系」= 同一テンプレートから生成された 6 リポジトリ。共有の機能台帳 (lctl) が正典設計と裁定を持つ。本設計は aicue の追従で、参照実装は aigenba
- adjudications.jsonl は「1 行でも不備があれば登録全体が無効になる」fail-closed 設計であり、bug-hunt の誤検知抑制はこの登録に依存する

【レビュー観点】
1. ロジックの正確性 (エッジケース、境界条件、決定性、None/型の扱い)
2. 既存コード・既存規約との整合性 (命名、パターン、ledger/ の既存資産)
3. テスト計画の網羅性 (各施策に対応するテストがあるか。fail-first になっているか)
4. 副作用・後退リスク (とくに fail-closed 機構と bug-hunt の走行への影響)
5. スコープ (過大・過小。オーバーエンジニアリングの有無)
6. 保証範囲の記述が正確か (誇張・過小のどちらも指摘する)
7. セキュリティ・機構の健全性 (抑制機構を弱めないか、存在オラクルの議論を歪めないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 概念設計 (Codex 概念レビュー APPROVED 済み。参考)

# 概念設計: bug-hunt の申し送り文書を生成物へ移し、経緯を登録の文脈項目へ寄せる

- 対象機能 (家系の機能台帳 lctl): `bughunt-findings-ledger`
- 追従の根拠: aicue セルの裁定 2026-08-05 —
  「**申し送り文書 69 行を保有。生成物へ移行し、内容は登録の文脈項目へ移す**」
- 参照実装: aigenba (同 feature を `implemented` (t1) にした唯一のリポジトリ。
  申し送り 308 行を生成物 645 行へ移行済み)
- 本設計の対象リポジトリ: aicue (/workspace)

---

## 背景・課題

### 裁定が指している「申し送り文書」の同定

`.claude/skills/app-bug-hunt/spec-ledger.md` である。git 履歴が行数と一致する:

| commit | 日付 | 行数 |
|---|---|---|
| `46dc72e` | 2026-08-02 | **69** |
| `9f8e92e` | 2026-08-04 | 112 (HEAD の内容) |

裁定 (2026-08-05) が見たのは 69 行版で、その 1 日前に 112 行へ増えている。対象ファイルは一意に定まる。

このファイルは自身を、機械 registry `ledger/adjudications.jsonl` の**対**と定義している
(spec-ledger.md:6-14) — registry が cross-session の機械判定の正本、spec-ledger.md が
cross-session の人間向け申し送りの正本、という 2 正本構成である。

### 仮説と、それを裏づける実測

**仮説**: 1 つの確定事象について正本を 2 か所に分けて手書きすると、片側だけが更新されて必ず食い違う。
「同じ説明文を重複させない」という規律と腐り検知テストでは防げない。

**実測 1 (2026-08-17 の HEAD)** — 既に食い違っている:

| 登録 | 由来 finding | 登録日 | `spec-ledger.md` の対応節 |
|---|---|---|---|
| A-001 | F-1-02 | 2026-08-04 | **あり** |
| A-002 (A-003 が supersede) | F-3-01 | 2026-08-12 | **無い** |
| A-003 | F-3-01 | 2026-08-12 | **無い** |

`spec-ledger.md` の最終更新は 2026-08-04 で、2026-08-12 に登録された 2 件の経緯は書かれていない。
**現行の腐り検知テスト (`ledger/test_spec_ledger.py` の 3 本) はこれを検出しない** —
3 本とも「文書に書いてあること」を起点に検査する向き (文書 → registry) しか持たず、
「registry に載ったのに文書に無い」向きを見ていないためである。

**実測 2** — 参照実装の側でも同じ根の事故が起きている。aigenba は移行作業のあと
**旧手書きの残骸が末尾 96 行残っている**ことが後から判明し、別 commit で是正している
(台帳の 2026-08-12 巡回記録)。移行は「新しい入力を作る」だけでは終わらず、
**移行元の全ブロックが移ったことを機械で突き合わせる**必要がある、という先例である。

**実測 3 (本設計の範囲外)** — A-002 の `watch_globs` は実在しないパス
(`app/Http/Controllers/Organizations/ProjectMemberController.php`) を指している。
`ledger/README.md` 運用ガード (d) が「他アプリ由来 18 件を全削除した理由」として挙げたのと同じ腐り方で、
台帳側も 2026-08-15 の巡回で同じ観測を記録している (「監視対象のパスが実在するかは検査しない」)。
台帳は**この不足を boundary へ足すかを settle 送りにしている**ので、本設計では触らない。

### 読み手に届いていない

`spec-ledger.md` は「使い方 (bug-hunt 実行者へ)」を持つが、**実行者をこの文書へ送る導線が無い**。
参照は `ledger/README.md:184` (書き手への指示) と `SKILL.md:267` (脚注 1 回) と
テスト自身の 3 か所だけで、探索エージェント (`.claude/agents/bughunt-shard.md`) も
Phase4 統合の手順もこの文書を読めとは言っていない。
つまり **読み手のいない手書き台帳を、規律とテストで維持している**状態である。

---

## 改善アイデア

家系の参照実装 (aigenba) が採った形をそのまま入れる。**新しい作法を作らない**。

### (1) 経緯を「登録の文脈項目」へ移す

`adjudications.jsonl` の各行に **任意の `context` オブジェクト**を持たせる。欄は参照実装と同じ:

| 欄 | 内容 | 移行元 (現行 9 欄) |
|---|---|---|
| `context.title` | 1 行要約 (何が「バグに見えた」か) | 節見出し `#### F-1-02 — …` |
| `context.spec_basis` | 仕様根拠の配列 (三点裏取りの参照) | 「根拠 (file:line)」 |
| `context.narrative` | 経緯の本文 (markdown をそのまま持つ) | 「なぜ誤検知に見えたか」「driver 側の再発防止」 |
| `context.reopen_condition` | 任意。再び起票してよい条件 | 「再オープン条件」 |

残る 5 欄は既存の機械項目と重複していたので**消える** — 「判定」= `verdict`、
「watch_globs」= `watch_globs`、「review_after_days」= 同名、「確定した run_id」=
`adjudicated_at_run` / `adjudicated_at_commit`、「機械 registry に登録済か」= 問い自体が成立しなくなる。

**`context` は照合器 (`validate_findings.py`) が読まない。** 検証するのは生成器だけである。
これは参照実装と同じ判断で、理由は本リポジトリ側でも load-bearing である —
registry は 1 行でも不備があれば**全体が無効になる** fail-closed 設計 (README 運用ガード (a)) なので、
散文や根拠パスを照合器の必須入力に足すと、**文章を書き損ねただけで抑制機構が全面停止する**。
経緯は抑制の判断に一切関与させない。

#### 検証責務の二層分離 (どちらが何を fail-closed にするか)

| | 検証するもの | 失敗したときに起きること |
|---|---|---|
| `validate_findings.py` (照合器) | 抑制判断に要る機械項目だけ (既存の 12 必須項目・species_key・scope・conditions・symptom・supersede 関係) | registry 全体が無効 = 抑制が止まる (既存の挙動。変えない) |
| `render_spec_ledger.py` (生成器) | `context` の形・移行台帳・断片・掲載の完全性 | **生成物を 1 バイトも書かずに落ちる** (照合器には影響しない) |

`context` の検証契約 — **不在は許すが、存在して壊れているものは通さない**という非対称にする:

- 許可キーは閉じた集合 (`title` / `spec_basis` / `narrative` / `reopen_condition`)。
  **未知キーは拒否する** (deny-by-default。既存の `COND_KEYS` と同じ作法)。
- `title` / `narrative` は非空文字列。`spec_basis` は**非空文字列の非空配列**。
  `reopen_condition` は任意だが、あるなら非空文字列。
- `spec_basis` は**パスの実在を検証しない** (実在検査を生成の必須条件にすると、
  通常のリファクタでファイルが動いただけで生成が止まる)。実在検査はテスト側に置く。
- 「`context` の不備は照合器の判定に影響しないが、生成器は落ちる」ことをテストで固定する。

### (2) `spec-ledger.md` を生成物にする

`adjudications.jsonl` から機械生成する。手編集を禁じ、`--check` が byte 比較で
手編集と再生成忘れの両方を検出する。生成は原子的に行い、途中で落ちたら既存ファイルを 1 バイトも変えない。

本リポジトリには同型の先例が既にある — bug-hunt の目録 (`screens.md` / `operations.md`) は
T176 で生成物化されており、告知ヘッダの文型と「生成物である。手で編集しない」の作法は
そのまま踏襲できる。

#### 掲載の完全性契約 (これが無いと直そうとしている欠陥を温存する)

参照実装は `context` を持つ登録だけを描く。それを写すと、`context` を書かなかった登録は
**registry にあるのに文書に出ない** — 本設計が実測 1 で挙げた食い違いが形を変えて残る。
そこで aicue では次を契約にする:

- **すべての `adjudication_id` を必ず生成物へ掲載する**。
- `context` がある登録は経緯つきで、無い登録は機械項目だけの簡潔な項目として出し、
  **「経緯は未記入」と明示する** (黙って落とさない)。
- supersede されている登録は、後継 (`supersedes` で指している側) を明示して掲載する。
- **テストは「現れる」ではなく集合の一致で固定する**。生成物から
  **見出し行の構造 (`^### (A-\d+) — `) で id を抽出**し、registry の id 集合と突き合わせて
  (a) 欠落なし (b) 余分なし (c) **各 id はちょうど 1 回** を固定する。
  本文中の言及や `A-0010` のような長い別 id への部分一致で緩まないよう、
  照合は識別子の境界を伴う完全一致で行う (本文の走査ではなく見出しの構造照合にするのはこのため)。
- supersede の表示は**後継を単数と仮定しない**。照合器は「循環」「自己参照」「未知 id」しか見ておらず、
  同じ id を supersede する登録が 2 件現れることを禁じていない。
  したがって「この登録を supersede している全 id」を**決定的な順序 (id の昇順)** で並べて表示する。

これにより「registry に載ったのに文書に無い」は起こり得なくなる (掲載が構造で強制されるため)。

#### この文書の役割 (縮小することを明示する)

生成物化後の `spec-ledger.md` は **「登録の可視化」**であり、運用手順の正本ではない。
運用手順 (どう登録するか / どう再生成するか / 何を検証するか) の正本は `ledger/README.md` に置く。
生成ヘッダにもこの役割分担を書く。

### (3) 移行の全数性と「痩せ」を機械で突き合わせる

`spec-ledger-migration.json` (移行台帳) を置く。移行元の**ブロック数を pin** し、
1 ブロックごとに「どの登録へ移ったか」「本文の下限文字数」「本文に残っていなければならない断片」を宣言する。
生成器は毎回これを突き合わせ、1 つでも解決できない / 痩せた / 断片が消えたら**生成物を書かずに落ちる**。

aicue の移行元ブロックは **1 件** (`#### F-1-02`。もう 1 つの `####` はテンプレート節の
コードフェンス内なので移行対象ではない) で、A-001 の `context` へ移る。

**保証範囲を誇張しない**: 移行台帳が見られるのは「**移行時に決めた断片と下限文字数が以後も保たれること**」
だけである。移行元のファイルは同じ変更で消えるので、以後これと再照合することはできず、
「全ブロックが移った」ことを将来にわたって証明するものではない。
**移行が正しかったことの確認は移行の commit で 1 度だけ人が行う** —
旧 `spec-ledger.md` の F-1-02 の本文と、移行後の `context` および生成結果を突き合わせる。
以後はブロック数の pin と断片照合で「痩せ」を監視する、という段構えである。

---

## 期待効果

- **使命への貢献** (間接): bug-hunt は **SOP 起点のシナリオ作成・ナビ撮影・課金・認可境界を含む
  全ユーザー導線**の詰みや漏れを、回帰テストの外側で拾う仕組みである。
  申し送り台帳が腐ると (a) 確定済みの誤検知を毎 run 再起票して探索時間を食う、
  (b) 逆に本物の退行を「既知」と誤って流す、の両方が起きる。正本を 1 か所にすることは、
  探索の時間を実際のバグへ向け続けるための土台である。
- **家系の裁定条件の解消**: aicue セルが pending に留まっている理由が外れる。
- **現に食い違っている 2 件 (A-002 / A-003) が解消し、以後は掲載の完全性契約で構造的に防がれる**。
- **二重更新の廃止と同期の機械化**: 1 件 adjudicate するたびに 2 か所へ書く運用が無くなり、
  同期が取れているかどうかは `--check` が機械で答える (人の記憶に依存しなくなる)。
  ただし `context` の記入と再生成の実行は残るので、作業がゼロになるわけではない。

---

## 実装方針 (概要)

| # | 施策 | 変更ファイル |
|---|---|---|
| 1 | 生成器を新設 (既定は生成 / `--check` で byte 比較。stdlib のみ、原子的書き込み) | `ledger/render_spec_ledger.py` (新規) |
| 2 | 移行台帳を新設 (ブロック数 1 を pin。下限文字数・必須断片つき) | `ledger/spec-ledger-migration.json` (新規) |
| 3 | A-001 に `context` を足す (現行 `spec-ledger.md` の F-1-02 節から移す) | `ledger/adjudications.jsonl` |
| 4 | A-003 に `context` を足す (**追跡可能な一次資料だけから構成する**。下の条件を満たせないなら足さない) | `ledger/adjudications.jsonl` |
| 5 | `spec-ledger.md` を生成物へ置換 (手書きの書式ルール節・初回登録テンプレート節は削除) | `spec-ledger.md` |
| 6 | 腐り検知テストを差し替え (手書き台帳の欄検査 → 生成物・移行・照合器の非参照) | `ledger/test_spec_ledger.py` |
| 7 | 運用ガード (c) 手順 6 を書き換え、「申し送りの生成物化」節を足す (再生成・`--check`・`python3 -m unittest` の手順と、**これらが CI 保証ではないこと**を明記) | `ledger/README.md` |
| 8 | bug-hunt 節に「申し送りも生成物である」を 1 文足す | `AGENTS.md` |

**施策 4 の条件 (経緯を後から創作しない)**: A-003 の申し送りは当時書かれていない。
後から `narrative` を書くと、**当時確認していない経緯を確定事実として記録する**危険がある。
そこで `context` は次の一次資料だけから構成し、出典を詳細設計に明示する:

- A-003 自身の `rationale_ref` (2026-08-12 に書かれた判断理由そのもの)
- 当該 run の成果物 `devnotes/20260812-100645-bug-hunt/report.md` と `findings-merged.jsonl` の F-3-01
- `AGENTS.md` セキュリティ不変条件 9 (層 2 = 404 は層 3 = 403 より前) — 判断の拠り所
- 登録が指す実コード (`OrganizationMemberController.php` / `OrganizationPolicy.php`)

これで復元できない部分は**書かない**。埋まらないなら施策 4 自体を落とし、
A-003 は「経緯は未記入」の簡潔な項目として掲載する (完全性契約があるので欠落にはならない)。

- **後方互換の並走を残さない** (思考原則 3): 手書きの書式ルール・初回登録テンプレート・
  「機械 registry に登録済か」欄は**同じ変更で消す**。移行元の残骸を残さないことは施策 2 が機械で担保する
  (aigenba が 96 行の残骸を出した失敗を繰り返さない)。
- **テストファースト** (思考原則 5): 施策 6 を先に置いて赤を確認してから施策 1-5 を入れる。

---

## 制約・前提

- **照合器の入力を増やさない**: `context` も生成物も `validate_findings.py` の入力にしない。
  照合器のソースが申し送り側のファイル名を 1 つも持たないことをテストで固定する
  (参照実装が持つ構造的保証と同じ)。
- **根拠パスの実在検査を照合器に入れない**: 実在検査を fail-closed の側に置くと、
  通常のリファクタでファイルが動いただけで registry 全体が無効になり抑制が止まる。
  実在検査はテスト側に置く (現行 `test_spec_ledger.py` が既にその判断をしており、理由も明記されている)。
- **skill 同梱物として `ledger/` に置く**: 生成器を `scripts/` へ昇格させない
  (家系へ mirror されるスキル同梱物であり、置き場所を変えると mirror の差分が壊れる。
  参照実装も同じ理由を明記している)。`scripts/README.md` の台帳は触らない。
- **移行元の識別子の形**: aicue の `source_finding_ids` は `F-1-02` のように run を含まない。
  かつ F-3-01 は A-002 と A-003 の**両方**に現れるため、finding id を移行台帳の鍵にすると
  一意に解決できない。したがって鍵は **`adjudication_id`** にする
  (validator が一意性を強制している唯一の識別子)。参照実装が run 修飾つき finding id を鍵にしているのは、
  向こうの登録が run 修飾を持つからで、**鍵の一意性という要件は同じ**である。

## スコープ外 (やらないことと、その理由)

- **`spec-notes.jsonl` (抑制しない履歴の登録簿) を今つくらない**。参照実装がこれを持つのは、
  向こうの申し送り 308 行に「実装で解消 / 事後訂正 / 運用の申し送り」が多数含まれていたためである。
  aicue の申し送り 112 行は**裁定済みの誤検知 1 件だけ**で、行き先はすべて `context` である。
  **追加の引き金**: 最初の「抑制しない申し送り」(実装で解消した記録など) を書く必要が出たときに、
  そのときの変更で `spec-notes.jsonl` と生成器の該当分岐を足す。
- **`watch_globs` の実在検査** (実測 3)。台帳側が settle 送りにしている論点であり、
  fail-closed の入力を増やす話なので本裁定の要求とは別件。
- **照合器の 4-gate・annotate・KPI の変更**、**findings.jsonl / report.md の正本分離の見直し**。
- **正典 t1 の他の要素**: 必須 13 項目 / 照合 3 項目の必須化 / donor 版検証器を基礎とすること /
  3 段分岐の候補抽出器 / **検証器自己テストの CI 実行 (AG-152)** は、いずれも別の追従タスクの範囲である。
  とくに CI 実行は AG-152 が家系全体へ課した項目で、`ledger/` と `coverage/` の
  Python レーン全体をどう CI へ載せるかという問題なので、ここで先取りしない。
  - **帰結として明記する保証の限界**: 本設計が入れる生成物のドリフト検査は
    `python3 -m unittest` を人が走らせたときにだけ効く。**CI では走らない**
    (これは現行の `test_spec_ledger.py` も同じで、後退ではない)。
- **bug-hunt 実行者を申し送りへ送る導線の新設** (SKILL.md / shard agent の手順変更)。
  読み手が居ないことは課題として記録するが、裁定が求めているのは正本の位置の是正である。

---

## 台帳の参照記録

- `get_feature("bughunt-findings-ledger")` を実施 (`feature_revision: 28-fc3946d423aa`)。
  aicue セルの裁定文と、aigenba の実装 (`render_spec_ledger.py` / `spec-notes.jsonl` /
  `spec-ledger-migration.json`) を確認した。
- 参照実装の原本 3 点 (`render_spec_ledger.py` / `test_spec_ledger.py` / `spec-notes.jsonl`) を
  `get_source(aigenba, …)` で実読した (`resolved_commit: 92e0e607023b3f2837a58df9a5049913ce69164f`)。
- **接続は不安定だった**: 台帳ホストへの到達が約 5 分間 (20 秒間隔で 7 回) 失敗し続けたのち復旧した。
  実装セッションで到達できない場合は、本書のこの節と詳細設計の引用が一次資料になる。
</content>

---

## 詳細設計書

# 詳細設計: bug-hunt の申し送り文書を生成物へ移し、経緯を登録の文脈項目へ寄せる

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書 (SOP) を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ (PWA) でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作 4. `response()->json()` の直書き 5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き 7. 操作系 POST の `redirect()->intended()`
8. 必須条件未充足を理由にした disabled ボタン 9. Artifact の使用

### コーディングルール

- 本タスクは **PHP / TypeScript / Svelte を 1 行も変更しない**。変更対象は
  bug-hunt スキル同梱の **Python (stdlib のみ)** と **Markdown / JSON / JSONL** である。
  したがって PHPStan level 10 / Pest / DTO / JsonResource / Factory の各規約は
  **適用対象が無い** (回避ではなく非該当。念のため実装後に `composer test` と
  `composer phpstan` は走らせて無影響を確認する)。
- Python は **stdlib のみ**。外部依存を足さない (`ledger/` の既存資産と同じ制約)。
- 生成器・テストとも `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'`
  で走ること。
- 日本語コメント。

## 概念設計リファレンス

`devnotes/20260817-1755-bughunt-handover-to-ledger/conceptual-design.md`
(Codex 概念レビュー Round 3 で APPROVED)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|---|---|---|
| 0 | 腐り検知テストの差し替え (**先に置いて赤を確認**) | `ledger/test_spec_ledger.py` | 高 |
| 1 | 生成器の新設 | `ledger/render_spec_ledger.py` (新規) | 高 |
| 2 | 移行台帳の新設 | `ledger/spec-ledger-migration.json` (新規) | 高 |
| 3 | A-001 に `context` を足す (移行) | `ledger/adjudications.jsonl` | 高 |
| 4 | A-003 に `context` を足す (一次資料の範囲で) | `ledger/adjudications.jsonl` | 中 |
| 5 | `spec-ledger.md` を生成物へ置換 | `spec-ledger.md` | 高 |
| 6 | 運用文書の更新 | `ledger/README.md` | 高 |
| 7 | bug-hunt 節に 1 項足す | `AGENTS.md` | 中 |

**実装順**: 0 (赤を確認) → 1 → 2 → 3 → 4 → 5 (生成器で出力) → 6 → 7 → 全テスト緑。

### 波及変更 (全施策共通)

- TypeScript 型定義: **なし** (フロントに影響しない)
- API Resource / DTO: **なし**
- Inertia Props: **なし**
- PHP テスト: **なし** (Architecture テストは `.claude/skills/` の Python を見ていない。
  ただし `ForbiddenStatementTokenInvariantTest` は git 追跡下の `*.php` 全件が対象で、
  本タスクは PHP を増やさないので母集団に変化なし)
- CI: **変更しない** (`scripts/bug-hunt-inventory-check.sh` の step とは別物。
  Python レーンの CI 配線は AG-152 の別タスク)

---

## 施策 0: 腐り検知テストの差し替え

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` (全面差し替え。196 行)

### 現行 (何を守っていて、なぜ役目を終えるか)

現行は手書き台帳の腐りを 3 点で見ている。

| 現行テスト | 見ているもの | 差し替え後の扱い |
|---|---|---|
| `test_required_fields_present` | 確定項目が 9 欄を持つ | **消える** (欄は `context` の形として生成器が検証する) |
| `test_evidence_paths_exist` | 根拠欄のパスが実在する | **残す** (対象が `context.spec_basis` に変わる) |
| `test_registry_cross_reference_resolves` | 「A-NNN 登録済」の相互参照が切れていない | **消える** (相互参照という概念が無くなる) |

3 本とも**文書 → registry の向き**しか見ておらず、逆向き (registry に載ったのに文書に無い) を
検出できない。これが実測 1 (A-002 / A-003 が文書に無い) を見逃した機序である。

### 変更後: テスト一覧 (26 本)

`staged(...)` ヘルパで入力 2 点 (`adjudications.jsonl` / `spec-ledger-migration.json`) を
一時ディレクトリへ写し、必要なら壊してから生成器へ渡す (**現物は絶対に書き換えない**)。

**A. 生成物であること**

| # | テスト | 固定する事実 |
|---|---|---|
| 1 | `test_generated_output_matches_committed_file` | `build()` の結果が現物と byte 一致 |
| 2 | `test_check_passes_on_committed_file` | `--check` が exit 0 |
| 3 | `test_manual_edit_is_detected` | 写しを 1 語書き換えると exit 1。**stderr に再生成コマンドが含まれる** |
| 4 | `test_check_fails_when_output_is_absent` | 出力が無ければ exit 1 |
| 5 | `test_render_is_atomic_on_failure` | 入力を壊して生成を走らせても現物の sha256 が変わらない |

**B. 掲載の完全性 (概念設計 Critical 1 の機械化)**

| # | テスト | 固定する事実 |
|---|---|---|
| 6 | `test_every_adjudication_id_is_listed_exactly_once` | 生成物の**見出し行**から `^### (A-\d+) — ` で抽出した id の多重集合が、registry の id 集合と一致し、各 1 回 |
| 7 | `test_entry_without_context_is_still_listed` | `context` を持たない登録を足した写しでも掲載され、`経緯は未記入` の印が付く |
| 8 | `test_supersede_relations_are_rendered_deterministically` | 同じ id を supersede する登録を 2 件にした写しで、両方の id が**昇順**で表示される |

**C. `context` の検証 (二層分離の機械化)**

| # | テスト | 固定する事実 |
|---|---|---|
| 9 | `test_unknown_context_key_is_rejected` | 許可外キーで `RenderError` |
| 10 | `test_context_field_type_and_emptiness_rejected` | `title` 空 / `narrative` が非文字列 / `spec_basis` が空配列 / 要素が空 / `reopen_condition` が空 → いずれも `RenderError` |
| 11 | `test_broken_context_does_not_affect_the_matcher` | **同じ壊れた入力に対し `validate_findings.validate_adjudications()` は error 0 件、`render_spec_ledger.build()` は `RenderError`** |
| 12 | `test_duplicate_adjudication_id_is_rejected_by_renderer` | 生成器は照合器が走った前提に寄りかからない |

**D. 移行台帳**

| # | テスト | 固定する事実 |
|---|---|---|
| 13 | `test_block_count_pinned_to_1` | 台帳の `block_count` / `len(entries)` / `EXPECTED_BLOCK_COUNT` が 1 で三点一致 |
| 14 | `test_block_count_change_fails` | 減らすと `RenderError` |
| 15 | `test_entries_count_mismatch_fails` | `block_count` と `entries` の数が違えば `RenderError` |
| 16 | `test_duplicate_key_in_manifest_fails` | 鍵の重複を拒否 |
| 17 | `test_unknown_key_does_not_resolve` | 実在しない `adjudication_id` を指すと `RenderError` |
| 18 | `test_narrative_min_chars_must_be_positive_int` | `0` / `-1` / `True` / `"900"` / `None` を拒否 (bool は int の派生なので明示的に弾く) |
| 19 | `test_narrative_below_min_chars_fails` | 本文を削ると `RenderError` (痩せの検出) |
| 20 | `test_required_fragment_missing_fails` | 必須断片を消すと `RenderError` |
| 21 | `test_required_fragment_does_not_match_a_longer_identifier` | `T095` を要求して本文に `T0950` しか無いとき落ちる |
| 22 | `test_fragment_boundary_allows_adjacent_non_identifier_characters` | 「`T095` の実装フェーズ」「\`T095\`」は一致、`xT095` / `T095-extra` は不一致 |
| 23 | `test_key_kind_and_target_vocabulary_is_closed` | 語彙外の値を拒否 |
| 24 | `test_manifest_shape_is_rejected_when_not_a_single_object` | 配列 / 不在ファイルを拒否 |

**E. 既存方針の継承 / 構造的保証**

| # | テスト | 固定する事実 |
|---|---|---|
| 25 | `test_spec_basis_references_exist` | `context.spec_basis` の各要素の**先頭トークン**からパス部を取り出し、リポジトリに実在する (**行番号・アンカーは見ない**。現行 `test_evidence_paths_exist` の判断をそのまま継承) |
| 26 | `test_matcher_source_never_names_the_handover_files` | `validate_findings.py` の本文に `spec-ledger` / `spec_ledger` / `render_spec_ledger` / `spec-notes` が 1 つも現れない |

### テスト計画 (fail-first の確認)

施策 0 だけを置いた状態で `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'`
を走らせ、**`import render_spec_ledger` の失敗**で全ケースが赤になることを確認する。
施策 1-5 を入れたのち全緑にする。

### リスク

- 既存 70 本 (`test_validate_findings.py` 含む) を壊さないこと。差し替えるのは
  `test_spec_ledger.py` の 3 本だけである。

---

## 施策 1: 生成器 `render_spec_ledger.py`

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py` (新規)

### 定数と契約

```python
HERE = os.path.dirname(os.path.abspath(__file__))
SKILL_DIR = os.path.dirname(HERE)
ADJUDICATIONS_PATH = os.path.join(HERE, "adjudications.jsonl")
MIGRATION_PATH = os.path.join(HERE, "spec-ledger-migration.json")
OUTPUT_PATH = os.path.join(SKILL_DIR, "spec-ledger.md")

# 移行元 spec-ledger.md の実ブロック数 (`^#### ` のうちコードフェンス外にあるもの)。
# 2026-08-17 の実測で 1 件 (F-1-02)。もう 1 つの `####` は初回登録テンプレートの
# フェンス内なので移行対象ではない。件数を pin しないと「1 件に痩せても通る」検査になる。
EXPECTED_BLOCK_COUNT = 1

# 経緯 (context) の欄。**閉じた集合**で、未知キーは拒否する (deny-by-default)。
CONTEXT_KEYS = ("title", "spec_basis", "narrative", "reopen_condition")
CONTEXT_REQUIRED = ("title", "spec_basis", "narrative")

# 移行台帳の語彙。どちらも現時点で 1 語だけである。
# 参照実装 (aigenba) は finding id を鍵にするが、aicue の source_finding_ids は run 修飾を持たず、
# F-3-01 が A-002 と A-003 の両方に現れるため一意に解決できない。
# 一意性を validator が強制している識別子は adjudication_id だけなので、それを鍵にする。
MIGRATION_KEY_KINDS = ("adjudication_id",)
MIGRATION_TARGETS = ("adjudications",)
```

### 検証 (すべて `RenderError` を投げ、`main()` が exit 1 に倒す)

`load_adjudications(path)`:

1. ファイルが無い / 実レコード 0 件 → error (`#` 始まりと空行は読み飛ばす)
2. 各行が object であること。JSON parse error は行番号つきで error
3. `adjudication_id` が非空文字列であること。**重複は error**
   (照合器が先に走った前提に寄りかからない)
4. `context` が無ければそのまま通す
5. `context` があるなら: dict であること / **`CONTEXT_KEYS` 以外のキーは error** /
   `title`・`narrative` は非空文字列 / `spec_basis` は**非空文字列の非空配列** /
   `reopen_condition` はあるなら非空文字列
6. **`spec_basis` のパス実在は検証しない** (実在検査を生成の必須条件にすると、
   通常のリファクタでファイルが動いただけで生成が止まる。実在検査はテスト 25 の担当)

`load_migration(path)`:

1. 単一 JSON object であること (配列は error) / 読めなければ error
2. `version` が正の int (bool は拒否) / `provenance` が非空 dict
3. `block_count` が int かつ `EXPECTED_BLOCK_COUNT` と一致
4. `entries` が list かつ長さ `block_count`
5. 各 entry は `key` / `key_kind` / `narrative_min_chars` / `required_fragments` / `target` を持ち、
   `key` は非空かつ一意、`key_kind` ∈ `MIGRATION_KEY_KINDS`、
   `narrative_min_chars` は正の int (bool 拒否)、`required_fragments` は非空文字列の非空配列、
   `target` ∈ `MIGRATION_TARGETS`

`check_migration(migration, adjudications)`:

- 各 entry を `_resolve()` で**ちょうど 1 件**へ解決する
  (`key_kind == "adjudication_id"` → `a["adjudication_id"] == key` の完全一致)。
  解決先に `context` が無ければ error (移行対象の登録は経緯を持たねばならない)
- `len(narrative) < narrative_min_chars` → error (痩せの検出)
- `required_fragments` の各断片が**識別子境界つき**で本文に現れること

```python
# 識別子を構成する文字。台帳が実際に使う識別子の文字集合に揃える
# (finding id `F-1-02` / TODO id `T095` / `feedback-probe.js`)。
# `-` と `.` を外すと `F-1-02` が `F-1-02-extra` の一部にも当たる。
# 日本語は含めない — 「T095 の実装フェーズ」のように直後へ日本語が続くのは正当な出現である。
_IDENT_CHARS = frozenset(
    "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_.-")


def fragment_present(fragment, narrative) -> bool:
    """断片が識別子の境界で現れるか。

    無境界の部分文字列一致だと、`T095` を要求しているのに本文へ `T0950` しか残っていない
    場合でも通ってしまう (短い参照が長い別参照へ誤って当たる)。
    断片の端が識別子文字のときだけ、その側に識別子文字が続かないことを要求する。
    """
    if not fragment:
        return False
    guard_left = fragment[0] in _IDENT_CHARS
    guard_right = fragment[-1] in _IDENT_CHARS
    i = narrative.find(fragment)
    while i >= 0:
        j = i + len(fragment)
        left_ok = not guard_left or i == 0 or narrative[i - 1] not in _IDENT_CHARS
        right_ok = not guard_right or j >= len(narrative) or narrative[j] not in _IDENT_CHARS
        if left_ok and right_ok:
            return True
        i = narrative.find(fragment, i + 1)
    return False
```

### 出力の構造 (掲載の完全性契約)

```
<!-- generated by .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py -->
<!-- DO NOT EDIT: 入力は ledger/adjudications.jsonl。
     再生成: python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py -->

# bug-hunt 仕様台帳 (spec-ledger) — 裁定済み事象の可視化

**このファイルは生成物である。手で編集しない。**
経緯は `ledger/adjudications.jsonl` の `context` に書き、上のコマンドで再生成する。
手編集と再生成忘れは `--check` が検出する。
**運用手順 (どう登録するか / 何を検証するか) の正本は `ledger/README.md` である。**
本ファイルの役割は「登録の可視化」だけであり、運用手順を持たない。

## 使い方 (bug-hunt 実行者へ)

- finding を起票する前に本台帳を検索すること。**ここに載っている事象は再起票しない**
  (「既知」と一行記録して次へ)。
- 同一事象が再発したと感じたら、**仕様根拠**を実コードで確認する。コードが台帳と乖離していれば
  regression の可能性があるので、その差分を根拠に新規 finding として起票してよい。
- 本ファイルに載る登録はすべて `validate_findings.py --annotate` の照合対象である
  (本ファイルはその人間向けの見え方であり、照合の入力ではない)。

---

## 登録一覧 (adjudications.jsonl の可視化)

### A-001 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた

- 由来 finding: F-1-02
- 判定: false_positive / 対象面: route_name=projects.manuals.destroy
- 確定: run 20260803-203721 (commit 22d6d30) / 見直し期限: 180 日
- 仕様根拠: {spec_basis を ` ; ` で連結}
- 再オープン条件: {reopen_condition}

{narrative}

### A-002 — (経緯は未記入)

- 由来 finding: F-3-01
- 判定: intentional / 対象面: route_name=organizations.members.destroy
- 確定: run 20260812-100645 (commit 6d0cf1d) / 見直し期限: 180 日
- 差し替え: A-003 に差し替えられた
- **経緯は未記入** (この登録には `context` が無い。書くときは `adjudications.jsonl` の
  当該行へ `context` を足して再生成する)

### A-003 — …

---

## 移行の全数性 (機械可読)

移行元 spec-ledger.md の全ブロックが上のどこかへ移ったことを機械が突き合わせる索引。
1 行 1 鍵。人向けの本文中の言及と取り違えないため、完全一致で比べる。

<!-- migration-keys:begin -->
- key: A-001
<!-- migration-keys:end -->
```

- 登録は `adjudication_id` の**昇順**で並べる (決定的)
- 「差し替え」行は 2 方向を出す: 自分が supersede した id (`supersedes`) と、
  **自分を supersede している全 id を昇順で**並べたもの
  (照合器は「同じ id を supersede する登録が 2 件」を禁じていないため単数と仮定しない)

### CLI

| 呼び方 | 動作 |
|---|---|
| (引数なし) | 生成して原子的に書き、`wrote … (N chars)` を出す |
| `--check` | 生成結果と現物を比較。違えば unified diff (先頭 200 行) を stderr へ出し、**再生成コマンドを添えて** exit 1 |
| `--output` / `--migration` / `--adjudications` | テスト用の差し替え |

**原子性**: 入力を全部読んで検証し、本文を**メモリ上で完成させてから** `tempfile.mkstemp` +
`os.replace` で置き換える。途中で落ちたら既存 `spec-ledger.md` は 1 バイトも変わらない。

### リスク

- 生成器が壊れると申し送りを更新できなくなる。→ 影響は文書生成に閉じており、
  **照合器と bug-hunt の走行には一切影響しない** (テスト 26 が構造的に固定)。

---

## 施策 2: 移行台帳 `spec-ledger-migration.json`

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json` (新規)

### 変更後 (内容。`narrative_min_chars` は移行後の実測値の 9 割を切り捨てた整数を入れる)

```json
{
  "version": 1,
  "block_count": 1,
  "provenance": {
    "source_file": ".claude/skills/app-bug-hunt/spec-ledger.md",
    "source_commit": "<移行 commit の親 sha>",
    "source_lines": "81-113",
    "source_block_headings": ["#### F-1-02 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた"],
    "migrated_at": "2026-08-__",
    "note": "移行元はこの変更で生成物へ置き換わる。以後この台帳と再照合することはできないので、内容の同一性の確認は移行 commit で 1 度だけ人が行った。"
  },
  "entries": [
    {
      "key": "A-001",
      "key_kind": "adjudication_id",
      "target": "adjudications",
      "narrative_min_chars": 0,
      "required_fragments": [
        "feedback-probe.js",
        "AUTO_DISMISS_MS",
        "T095",
        "installed_now"
      ]
    }
  ]
}
```

- **`heading_keyed_count` は持たない**。参照実装は run を特定できなかったブロックを
  `heading` 鍵で逃がすためにこの数を持つが、aicue の `key_kind` 語彙は 1 語しかないので
  この数は常に 0 で退化する。持たない代わりに `key_kind` の語彙が閉じていることを
  テスト 23 が固定する。

### リスク

- `narrative_min_chars` を実測値ちょうどにすると、誤字修正 1 文字で赤くなる。
  → 9 割にして「痩せ」だけを見る。上限は設けない (増えるのは問題ではない)。

---

## 施策 3: A-001 に `context` を足す (移行の本体)

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl` (A-001 の行)

### 現行 (A-001。抜粋)

```json
{"adjudication_id": "A-001", "species_key": "other:video_manual:delete:self",
 "scope": {...}, "conditions": {...}, "symptom": {...},
 "verdict": "false_positive",
 "rationale_ref": "devnotes/20260804-0021-ux-small-gaps/detailed-design.md",
 "source_finding_ids": ["F-1-02"], "adjudicated_at_run": "20260803-203721",
 "adjudicated_at_commit": "22d6d30", "watch_globs": [...], "review_after_days": 180}
```

### 変更後 (末尾に `context` を足す。既存キーは 1 つも変えない)

```json
"context": {
  "title": "動画マニュアル削除後に「成功 flash が出ない」ように見えた",
  "spec_basis": [
    "app/Http/Controllers/Projects/VideoManualController.php:230-232 削除後 projects.show へ redirect し ->with('success', '動画マニュアルを削除しました')",
    "resources/js/lib/stores/toast.ts:23-29 success/info/warning は 4000ms で auto-dismiss、error のみ null = 自動消去しない",
    "resources/js/components/organisms/ToastContainer.svelte role=\"status\" + data-testid=\"toast-{type}\" で描画",
    "tests/Browser/FlashToastTest.php 着地マーカーと同一時間窓で toast-success が可視になることを Chromium / WebKit の 2 レーンで pin"
  ],
  "narrative": "**なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**\n\n**driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。",
  "reopen_condition": "次のいずれか。(a) VideoManualController::destroy が ->with('success', ...) を落とした、(b) toast.ts の success 用 AUTO_DISMISS_MS が大幅に短縮された、(c) feedback probe が installed_now:false かつ seen(visible:true) / present_new ともに空を返した。**probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**"
}
```

### `spec_basis` の書式 (テスト 25 の契約)

**1 要素 = 先頭に参照 (パス、位置指定は任意)、空白、以降に説明**。
テストは**先頭の空白区切りトークン**だけを取り、`^[\w./-]+\.(php|ts|js|svelte|md|json|ya?ml|py|sh)([:#][\w.-]*)*$`
に一致したものについてリポジトリ内の実在を確認する (位置指定とアンカーは捨てる)。
`AGENTS.md#anchor` のような文書アンカーもこの形に収まる。

### 移行元との突合 (人が 1 度だけ行う)

移行 commit で、旧 `spec-ledger.md` の 85-113 行と上の `context` を並べ、
**9 欄すべての行き先**を確認する:

| 旧 9 欄 | 行き先 |
|---|---|
| 判定 | 既存 `verdict` (`false_positive`) |
| 根拠 (file:line) | `context.spec_basis` (4 件すべて) |
| なぜ誤検知に見えたか | `context.narrative` 前半 |
| driver 側の再発防止 | `context.narrative` 後半 |
| watch_globs | 既存 `watch_globs` (旧欄も「正本は registry」と書いていた) |
| review_after_days | 既存 `review_after_days` (180) |
| 確定した run_id | 既存 `adjudicated_at_run` / `adjudicated_at_commit` |
| 再オープン条件 | `context.reopen_condition` |
| 機械 registry | **消える** (registry そのものが正本になり問いが成立しない) |

### テスト計画

- テスト 1 / 6 / 25 / 19 / 20 が同時に効く。
- 手で追加確認: `python3 validate_findings.py ledger/example.findings.jsonl --adjudications ledger/adjudications.jsonl`
  が **errors 0** のままであること (= `context` の追加が照合器に無影響)。

### リスク

- JSONL の 1 行が長くなり読みにくい。→ 元から 1 行 = 1 登録の形式であり、
  読む窓口は生成物の `spec-ledger.md` になるので許容する。

---

## 施策 4: A-003 に `context` を足す (一次資料の範囲で)

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl` (A-003 の行)

### 一次資料と、その扱い (経緯を後から創作しない)

| 資料 | 位置づけ |
|---|---|
| A-003 自身の `rationale_ref` | **当時 (2026-08-12) の判断そのもの**。`narrative` の核はここから採る |
| `devnotes/20260812-100645-bug-hunt/report.md` の「F-3-01」節と「事後の決着」表 | 当時の run 成果物。症状と「バグと断定しない根拠」が書かれている |
| `devnotes/20260812-100645-bug-hunt/findings-merged.jsonl` の F-3-01 | 当時の機械記録 (species / symptom_tokens / surface / observed_conditions) |
| `AGENTS.md` セキュリティ不変条件 9 | 判断の拠り所 (当時から現在まで同一) |
| `app/Http/Controllers/Organizations/OrganizationMemberController.php` / `app/Policies/OrganizationPolicy.php` | **現在 (実装時) 確認した補足**。当時の判断根拠ではない |

**Codex 概念レビュー Round 3 の指摘に従い、最後の 1 行 (実コード) は
「当時の根拠」と混ぜずに書く** — `spec_basis` では
「(実装時に確認した現行の実装。当時の判断根拠は上の 2 件)」と注記する。

### 変更後 (骨子)

```json
"context": {
  "title": "同一組織内のメンバー削除で 403 と 404 が分かれ、組織内の id 存在を弱く推測できる",
  "spec_basis": [
    "AGENTS.md#セキュリティ不変条件 層 2 のテナント境界 404 は層 3 の認可 403 より前 (当時の判断の拠り所)",
    "devnotes/20260812-100645-bug-hunt/report.md 当該 run の F-3-01 節と事後の決着表 (当時の一次記録)",
    "app/Http/Controllers/Organizations/OrganizationMemberController.php 実装時に確認した現行の実装 (当時の判断根拠ではない)",
    "app/Policies/OrganizationPolicy.php 実装時に確認した現行の実装 (当時の判断根拠ではない)"
  ],
  "narrative": "**当時の判断 (run 20260812-100645 / commit 6d0cf1d)**: 同一組織内で権限が足りなければ 403 が設計どおりであり、404 へ潰すと文書化済みの 3 層モデル (層 2 のテナント境界 = 404 は層 3 の認可 = 403 より前) に反する。cross-tenant の存在秘匿とは層が違うため、bug-hunt は「バグと断定しない」として needs_spec で挙げ、事後に intentional として登録した。\n\n**この経緯は 2026-08-17 の移行時に、当時の rationale_ref と run 成果物から起こしたものである** (2026-08-12 の時点では人間向けの申し送りが書かれていなかった)。当時確認されていない事実は足していない。",
  "reopen_condition": "同一組織内の 403 / 404 の分岐が、テナント境界 (層 2) の判定より前で起きるようになったとき。層の順序が変わればこの判断は無効になる。"
}
```

- `AGENTS.md#…` のアンカーは**実在する見出しに合わせる**こと (テスト 25 はパス部
  `AGENTS.md` の実在しか見ないが、読み手のために正しいアンカーを書く)。

### この施策を落とす条件

上の一次資料から `title` / `spec_basis` / `narrative` を復元できないと判断したら、
**施策 4 を丸ごと落とす**。掲載の完全性契約により A-003 は
「経緯は未記入」の項目として必ず現れるので、欠落にはならない。
移行台帳には A-003 を入れない (移行元に A-003 のブロックは存在しないため。
`block_count` は 1 のまま)。

### テスト計画

- テスト 6 (掲載) / 10 (context の形) / 25 (`spec_basis` のパス実在)。
- A-002 は `context` を持たないままにする → テスト 7 の実データ側の裏づけになる。

### リスク

- 事後に書いた経緯が「当時の判断」と読まれる。→ `narrative` 内に
  **いつ・何から起こしたか**を明記することで区別する (上の骨子に含めた)。

---

## 施策 5: `spec-ledger.md` を生成物へ置換

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/spec-ledger.md` (112 行 → 生成物)

### 消すもの (後方互換の並走を残さない)

| 現行 | 扱い |
|---|---|
| 1-19 行: registry との「対」の表と注記 | **消す** (対ではなくなる。役割は生成ヘッダが書く) |
| 22-35 行: 使い方 (bug-hunt 実行者へ) | 生成器の固定文 (`PREAMBLE`) へ移す。**手書きとしては消える** |
| 37-47 行: 書式ルール | **消す** (書式は生成器が持つ) |
| 51-77 行: 初回登録テンプレート (9 欄) | **消す** (欄は `context` の形として生成器が検証する) |
| 81-113 行: run 20260803-203721 申し送り / F-1-02 | **A-001 の `context` へ移す** (施策 3) |

**残骸を残さない**ことは移行台帳 (施策 2) と `--check` の byte 比較 (テスト 1/2) が担保する。
参照実装 (aigenba) は移行後に旧手書きの残骸を 96 行残しており、この機構が無かったことが原因である。

### 生成手順

```bash
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check   # exit 0 を確認
```

### リスク

- 生成物なので diff が大きい。→ 移行 commit で旧内容との突合 (施策 3) を人が行い、
  以後は移行台帳が痩せを見る。

---

## 施策 6: `ledger/README.md` の更新

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/ledger/README.md`
  - 「構成」表 (8-13 行): `render_spec_ledger.py` と `spec-ledger-migration.json` を足す
  - 運用ガード (c) 手順 6 (183-184 行): 書き換える
  - 新設節「申し送りの生成物化」

### 現行 (運用ガード (c) 手順 6)

```
6. 人間可読の申し送り(「過去 run で SPEC / DOC と確定した事象を再起票しない」)は
   機械 registry の対として `.claude/skills/app-bug-hunt/spec-ledger.md` に書く。
```

### 変更後

```
6. 人間可読の申し送りは**別ファイルに手書きしない**。経緯は同じ行の `context` に書く
   (`title` / `spec_basis` / `narrative` / 任意の `reopen_condition`。
   キーはこの 4 つで閉じており、未知キーは生成器が拒否する)。書いたら再生成する:

   ```bash
   python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
   ```

   `context` を書かなくても登録は `spec-ledger.md` に「経緯は未記入」として必ず載る
   (掲載の完全性契約)。**黙って消えることはない。**
```

### 新設節「申し送りの生成物化」(要点)

- `spec-ledger.md` は**生成物**であり、入力は `adjudications.jsonl` の `context` だけである。
- 検証責務の二層分離の表 (照合器 = 抑制判断の機械項目 / 生成器 = 経緯と移行と掲載)。
  **`context` を壊しても抑制機構は止まらない。生成が止まる。**
- 検証コマンドと、**その保証の限界**:

  ```bash
  python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check
  python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
  ```

  > **これらは CI では走らない。** `.github/workflows/ci.yml` が起動する bug-hunt 関連の検査は
  > 目録のドリフト検査 (`scripts/bug-hunt-inventory-check.sh`) だけで、`ledger/` と `coverage/` の
  > Python レーンはどの job からも実行されていない。したがって生成物のドリフトは
  > **人が上のコマンドを走らせたときにだけ**見つかる。
  > (Python レーンを CI へ載せることは家系の裁定 AG-152 が別途求めている。ここでは扱わない。)

- 移行台帳の役割と**保証しないもの**: 移行元は消えているので再照合はできない。
  見られるのは「移行時に決めた断片と下限文字数が以後も保たれること」だけである。

### リスク

- README が長くなる。→ 消える記述 (手書き台帳の運用) と入れ替わるので純増は小さい。

---

## 施策 7: `AGENTS.md` の bug-hunt 節に 1 項足す

### 変更箇所

- ファイル: `AGENTS.md` §bug-hunt (「目録は生成物 (T176)」の項の直後)

### 変更後 (追加する 1 項)

```markdown
- **申し送りも生成物**: `spec-ledger.md` は手で書かない。経緯は
  `ledger/adjudications.jsonl` の `context` (`title` / `spec_basis` / `narrative` /
  任意の `reopen_condition`。未知キーは拒否) に書き、
  `python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py` で再生成する。
  **すべての登録が必ず載る** (経緯を書いていない登録も「経緯は未記入」として出る) ため、
  「登録したのに申し送りに無い」は起こらない。
  `context` は**照合器 (`validate_findings.py`) が読まない** — 経緯を書き損ねても
  抑制機構は止まらず、止まるのは生成だけである (fail-closed の境界をここに引いている)。
  ドリフト検査は `--check` と `python3 -m unittest` で、**どちらも CI では走らない**。
```

### リスク

- AGENTS.md の bug-hunt 節が長い。→ 1 項で収め、詳細は `ledger/README.md` に置く
  (二重管理を作らない)。

---

## 検証コマンド (実装完了の条件)

```bash
# 本タスクの本体
python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'   # 全緑
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check            # exit 0

# 照合器への無影響 (context 追加が registry を壊していないこと)
cd .claude/skills/app-bug-hunt && python3 ledger/validate_findings.py \
    ledger/example.findings.jsonl --adjudications ledger/adjudications.jsonl        # errors 0

# 無影響の確認 (PHP / フロントは 1 行も変えていないこと)
composer test && composer phpstan && vendor/bin/pint --test
pnpm lint && pnpm typecheck && pnpm test && pnpm build
bash scripts/bug-hunt-inventory-check.sh                                            # exit 0
```

## 実装モード

| 項目 | 内容 |
|---|---|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は `.claude/skills/app-bug-hunt/` の同梱物と `AGENTS.md` 1 項に閉じており、アプリコード・DB・依存関係に触れない。他の TODO と競合する面が無い |
| 競合リスク | 同じ `adjudications.jsonl` を触る作業 (新しい bug-hunt run の adjudicate) が並行すると衝突する。**bug-hunt を走らせる作業とは同時に進めない**こと |

## 保証しないこと (誇張しない)

- **CI では 1 つも走らない**。生成物のドリフト・移行の痩せ・掲載の完全性は、
  人が `python3 -m unittest` か `--check` を走らせたときにだけ検出される
  (現行の `test_spec_ledger.py` も同じで、後退ではない)。
- **経緯の内容が正しいことは検証しない**。機械が見るのは形・全数性・痩せ・drift だけである。
- **`watch_globs` の実在は依然として誰も検査しない** (A-002 が実在しないパスを持ったままである)。
  家系の台帳がこの不足を settle 送りにしているため、本タスクでは触らない。
- **`spec_basis` のパス実在検査はテストの担当**であり、生成の必須条件ではない。
  テストを走らせない限り腐りは見つからない。
</content>

---

## 関連する現行コード

### .claude/skills/app-bug-hunt/ledger/adjudications.jsonl (全文)

# bug-hunt adjudication registry (cross-session)。1 行 = 1 エントリ。append-only + supersede。
# 詳細: README.md「adjudication registry」節 / 設計: devnotes/20260624-1035-bughunt-adjudication-registry/
# consult は Phase4 統合 (親) のみ: validate_findings.py --adjudications <this> --annotate --run-id <rid>
#
# seed は空。旧 seed (A-001〜A-018) は spirux 由来で AI-CUE に実在しない資産
# (.claude/skills/spirux-bug-hunt/ / /api/v1/personas/* / 大文字 resources/js/Pages/ / app/Filament/)
# を指しており、watch_globs invalidation が永久に発火しなかったため 2026-08-02 に全削除した。
# 削除時点の実効抑制は 0 (validator が 5 件 error → fail-closed で registry 全体が無効) なので
# 実効抑制は 0 → 0 で不変。理由と登録手順は README.md「adjudication registry」節を参照。
{"adjudication_id": "A-001", "species_key": "other:video_manual:delete:self", "scope": {"scope_kind": "route_name", "scope_value": "projects.manuals.destroy"}, "conditions": {"browser": "chromium", "mode": "real-llm"}, "symptom": {"required_tokens": ["delete_success_flash_missing"], "known_tokens": ["toast", "auto_dismiss", "projects_show_redirect"]}, "verdict": "false_positive", "rationale_ref": "devnotes/20260804-0021-ux-small-gaps/detailed-design.md", "source_finding_ids": ["F-1-02"], "adjudicated_at_run": "20260803-203721", "adjudicated_at_commit": "22d6d30", "watch_globs": ["app/Http/Controllers/Projects/VideoManualController.php", "resources/js/components/organisms/ToastContainer.svelte", "resources/js/lib/stores/flash-to-toast.ts"], "review_after_days": 180}
{"adjudication_id": "A-002", "species_key": "other:organization_member:delete:same_tenant", "scope": {"scope_kind": "route_name", "scope_value": "organizations.members.destroy"}, "conditions": {"browser": "chromium", "mode": "real-llm"}, "symptom": {"required_tokens": ["403_vs_404"], "known_tokens": ["existence_hint", "member_delete"]}, "verdict": "intentional", "rationale_ref": "AGENTS.md セキュリティ不変条件 9 (層 2 テナント境界 = 404 は層 3 認可 = 403 より前)", "source_finding_ids": ["F-3-01"], "adjudicated_at_run": "20260812-100645", "adjudicated_at_commit": "6d0cf1d", "watch_globs": ["app/Http/Controllers/Organizations/ProjectMemberController.php", "app/Http/Controllers/Admin/UserManagementController.php", "app/Policies/OrganizationPolicy.php"], "review_after_days": 180}
{"adjudication_id": "A-003", "supersedes": "A-002", "species_key": "other:organization_member:delete:same_tenant", "scope": {"scope_kind": "route_name", "scope_value": "organizations.members.destroy"}, "conditions": {"browser": "chromium", "mode": "real-llm"}, "symptom": {"required_tokens": ["403_vs_404"], "known_tokens": ["existence_hint", "member_delete"]}, "verdict": "intentional", "rationale_ref": "AGENTS.md セキュリティ不変条件 9 (層 2 テナント境界 = 404 は層 3 認可 = 403 より前)。同一組織内で権限不足なら 403 が設計どおりで、404 へ潰すと文書化済みの 3 層モデルに反する", "source_finding_ids": ["F-3-01"], "adjudicated_at_run": "20260812-100645", "adjudicated_at_commit": "6d0cf1d", "watch_globs": ["app/Http/Controllers/Organizations/OrganizationMemberController.php", "app/Policies/OrganizationPolicy.php"], "review_after_days": 180}

### .claude/skills/app-bug-hunt/spec-ledger.md (移行元。全文)

# bug-hunt 仕様台帳 (spec-ledger) — 既知仕様 / 誤検知の申し送り

このファイルは、過去の bug-hunt run で挙がった finding のうち **実コード裏取り + 敵対的検証の結果
「仕様 (SPEC)」「ドキュメント側対応 (DOC)」「誤検知 (FALSE_POSITIVE)」と確定したもの**を記録する、
人間可読の申し送り台帳。

機械 registry (`ledger/adjudications.jsonl`) の**対**である:

| | 正本 | 読み手 | 効果 |
|---|---|---|---|
| `ledger/adjudications.jsonl` | cross-session の**機械判定** | validator (`--annotate`) | 4-gate 一致で annotate + downrank |
| `spec-ledger.md` (本ファイル) | cross-session の**人間向け申し送り** | bug-hunt 実行者 (親 / 子 shard) | 「再起票しない」判断の根拠を渡す |

同じ説明文を両方に重複させない。機械照合が要るものは registry に、
「なぜ SPEC と確定したか」の物語は本ファイルに書く。

> 旧 registry の spirux 由来 18 件は AI-CUE に実在しない資産を指していたため削除済み
> (理由は `ledger/README.md` 運用ガード (d))。**他アプリの申し送りを写さない**。

---

## 使い方 (bug-hunt 実行者へ)

- finding を起票する前に本台帳を検索すること。**ここに SPEC として載っている事象は再起票しない**
  (「既知仕様」と一行記録して次へ)。
- 同一事象が再発したと感じたら、台帳の**根拠 (file:line)** を実コードで確認する。
  コードが台帳と乖離していれば **regression** の可能性があるので、その差分を根拠に新規 finding を起票してよい。
- DOC 項目は「コード正本は正しく、bug-hunt 側カード / 正本ドキュメントの記述が陳腐化していた」もの。
  該当カードが修正済みかを確認する。
- 「要確認」を SPEC に確定する判断は、**設計文書 (devnotes/docs)・実コード・テストの三点**で
  裏が取れた場合のみ。取れないものは台帳に載せず「要確認」のまま残す。
- **SPEC / DOC 確定項目には根拠 (file:line) を必ず併記する**こと。後続実装で仕様が変わった場合、
  記述と実コードが乖離するため、台帳の腐りを早期に発見できる。
- 機械照合させたい (次 run で自動 downrank したい) 項目は、本ファイルに書いたうえで
  `ledger/adjudications.jsonl` にも 1 行足す。手順は `ledger/README.md` 運用ガード (c)。

## 書式ルール

- **append-only + supersede**。既存の確定項目を黙って書き換えない。撤回するときは
  「実装で解消 (旧 SPEC を撤回)」節を作り、**撤回した事実と根拠**を残す。
- run 単位の節 (`## run {run_id} 申し送り ({date})`) を**新しい run が上**になるよう積む。
- 節の中は `### SPEC 確定 (再起票しない)` / `### 誤検知確定 (再起票しない)` / `### DOC 確定`
  / `### 実装で解消 (旧 SPEC / accepted を撤回)` / `### CLOSED (非再発を確認)` に分ける。
  節見出しは機械 registry の `verdict` 語彙に対応させる
  (`誤検知確定` = `false_positive` / `SPEC 確定` = `intentional`)。
  `wont_fix` は現時点で該当項目が無いため節を作らない。必要になったら
  `### wont_fix 確定 (再起票しない)` を追加する (節の追加は書式ルールの更新を伴う)。

---

## 初回登録テンプレート

新しい run の申し送りを書くときは、以下をコピーして埋める。**欄を削らない**
(埋められない欄がある = 三点裏取りが済んでいない ので、その項目は台帳に載せない)。

```markdown
## run {run_id} 申し送り ({YYYY-MM-DD})

### SPEC 確定 (再起票しない)

#### {finding_id} — {事象を 1 行で。何が「バグに見えた」か}
- **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化) | FALSE_POSITIVE (観測 artifact)
- **根拠 (file:line)**: `path/to/File.php:123` (何をしているか) /
  `resources/js/pages/Foo/Bar.svelte:45` / `AGENTS.md#anchor` / `tests/Feature/FooTest.php`
  ※ 設計文書・実コード・テストの三点。**実在するパスのみ**書く
- **なぜ誤検知に見えたか**: {fake mode / 観測窓 / viewport 等、bug-hunt 側の事情}
- **driver 側の再発防止**: {この誤検知を機構で防ぐ手立て。SKILL.md のどの規約か / 「なし (人手注意のみ)」}
  ※ 人手の心構えで終わらせないための必須欄
- **watch_globs (機械 registry に載せる場合)**: `path/to/File.php`, `resources/js/pages/Foo/Bar.svelte`
  ※ この判定を無効化しうる実在ファイルのみ。過広 (`app/**` 等) 禁止
  ※ **既に registry に登録済なら glob を書き写さず「`A-NNN` に登録済 (正本は registry)」とだけ書く**
  (照合条件の正本は registry。二重管理は腐りの温床)
- **review_after_days**: {int > 0。仕様の揺れやすさで決める。例 120 / 180}
- **確定した run_id**: {run_id} (commit {short_sha})
- **再オープン条件**: {どうなったら再び finding として起票してよいか}
- **機械 registry**: `ledger/adjudications.jsonl` の `A-NNN` に登録済 / 未登録 (理由: …)
```

---

## run 20260803-203721 申し送り (2026-08-04)

### 誤検知確定 (再起票しない)

#### F-1-02 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた
- **判定**: FALSE_POSITIVE (観測 artifact)
- **根拠 (file:line)**: `app/Http/Controllers/Projects/VideoManualController.php:230-232`
  (削除後 `projects.show` へ redirect し `->with('success', '動画マニュアルを削除しました')`) /
  `resources/js/lib/stores/toast.ts:23-29` (success/info/warning は **4000ms で auto-dismiss**、
  error のみ `null` = 自動消去しない) /
  `resources/js/components/organisms/ToastContainer.svelte`
  (`role="status"` + `data-testid="toast-{type}"` で描画) /
  `tests/Browser/FlashToastTest.php` (着地マーカーと**同一時間窓**で `toast-success` が可視になることを
  Chromium / WebKit の 2 レーンで pin)
- **なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、
  Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に
  snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを
  両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**
- **driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に
  feedback probe (`.claude/skills/app-bug-hunt/probes/feedback-probe.js`) を仕込み、直後に読む。
  「事後 snapshot に無い」を根拠に H7 を起票することを禁止した。
  回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。
- **watch_globs (機械 registry に載せる場合)**: `ledger/adjudications.jsonl` の A-001 に登録済。
  **本ファイルには重複させない** (正本は registry)。
- **review_after_days**: 180 (A-001 と同値)
- **確定した run_id**: 20260803-203721 (commit 22d6d30)
- **再オープン条件**: 次のいずれか。
  (a) `VideoManualController::destroy` が `->with('success', ...)` を落とした、
  (b) `toast.ts` の success 用 `AUTO_DISMISS_MS` が大幅に短縮された、
  (c) feedback probe が `installed_now:false` かつ `seen`(visible:true) / `present_new` ともに空を返した。
  **probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**
- **機械 registry**: `ledger/adjudications.jsonl` の `A-001` に登録済 (verdict=false_positive)

### .claude/skills/app-bug-hunt/ledger/test_spec_ledger.py (差し替え対象。全文)

"""spec-ledger.md の腐り検知 (stdlib のみ)。

`spec-ledger.md` は機械 registry (`adjudications.jsonl`) の「対」であり、人間向け申し送りの正本。
台帳は放置すると腐る (根拠に書いたファイルが消える / registry に「登録済」と書いたのに実体が無い)
ため、次の 3 点だけを機械検知する:

 (1) 確定項目の必須欄が揃っているか (初回登録テンプレートの「欄を削らない」の機械化)
 (2) 根拠欄に書いたファイルが実在するか (**行番号は見ない**)
 (3) 「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在するか

(2) で行番号を検証しないのは意図的である。通常のリファクタで台帳テストが壊れる保守負債になるため。
旧 registry 18 件が「実在しないパス」を指し watch_globs invalidation が永久に発火しなかった事故
(`ledger/README.md` 運用ガード (d)) の再発防止が目的なので、**実在**だけを見れば足りる。

台帳が空 (エントリ 0 件) のときは 3 つとも vacuous に PASS する (テンプレート初期状態を壊さない)。

実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
"""

from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

LEDGER_DIR = Path(__file__).resolve().parent
SKILL_ROOT = LEDGER_DIR.parent
REPO_ROOT = SKILL_ROOT.parents[2]  # .claude/skills/app-bug-hunt -> repo root
SPEC_LEDGER = SKILL_ROOT / "spec-ledger.md"
ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"

ENTRY_RE = re.compile(r"^#### (?P<fid>\S+) — (?P<title>.+)$")
HEADING_RE = re.compile(r"^#{1,6} ")
FENCE_RE = re.compile(r"^\s*```")

# 初回登録テンプレートの全 9 欄。テンプレートを直したらこの定数も直す (1 対 1 の関係)。
REQUIRED_FIELDS = (
    "判定",
    "根拠 (file:line)",
    "なぜ誤検知に見えたか",
    "driver 側の再発防止",
    "watch_globs (機械 registry に載せる場合)",
    "review_after_days",
    "確定した run_id",
    "再オープン条件",
    "機械 registry",
)
# 照合は「キー文字列が本文のどこかにある」ではなく **行形式** で行う
# (本文中に同じ語が出ただけで PASS する誤検知を避ける)。
FIELD_LINE = "- **{name}**:"
FIELD_START_RE = re.compile(r"^- \*\*(?P<name>[^*]+)\*\*:")

BACKTICK_RE = re.compile(r"`([^`]+)`")
# 位置指定 (`:123-125` / `:12:5` / `#L12` / `#anchor`) は**捨てて**パス部だけを実在確認する。
# 位置記法を許容集合に入れておかないと、その記法で書かれた根拠が丸ごと検査対象外に
# すり抜けてしまう (腐りの見逃し)。
PATH_LIKE = re.compile(
    r"^(?P<path>[\w./-]+\.(?:php|ts|js|svelte|md|json|ya?ml|py|sh))(?:[:#][\w.-]*)*$"
)
ADJ_ID_RE = re.compile(r"\bA-\d{3}\b")


def _lines_outside_fences(text: str) -> list[str]:
    """コードフェンス (```) の内側を空行に潰した行リスト。

    `## 初回登録テンプレート` のプレースホルダ (`path/to/File.php` 等) を
    実エントリとして拾わないため。行番号を保つよう「除去」ではなく「空行化」する。
    """
    out: list[str] = []
    in_fence = False
    for line in text.splitlines():
        if FENCE_RE.match(line):
            in_fence = not in_fence
            out.append("")
            continue
        out.append("" if in_fence else line)
    return out


def _entries() -> list[tuple[str, str]]:
    """(finding_id, 本文) のリスト。テンプレート節 (フェンス内) は除外済み。"""
    if not SPEC_LEDGER.exists():
        raise AssertionError(f"spec-ledger.md が見つからない: {SPEC_LEDGER}")
    lines = _lines_outside_fences(SPEC_LEDGER.read_text(encoding="utf-8"))
    entries: list[tuple[str, str]] = []
    current_id: str | None = None
    body: list[str] = []
    for line in lines:
        match = ENTRY_RE.match(line)
        if match:
            if current_id is not None:
                entries.append((current_id, "\n".join(body)))
            current_id = match.group("fid")
            body = []
            continue
        if current_id is not None and HEADING_RE.match(line):
            entries.append((current_id, "\n".join(body)))
            current_id = None
            body = []
            continue
        if current_id is not None:
            body.append(line)
    if current_id is not None:
        entries.append((current_id, "\n".join(body)))
    return entries


def _field_body(entry_body: str, name: str) -> str:
    """`- **{name}**:` 欄の本文 (次の欄が始まるまでの継続行を含む)。無ければ空文字。"""
    prefix = FIELD_LINE.format(name=name)
    collected: list[str] = []
    capturing = False
    for line in entry_body.splitlines():
        if capturing:
            if FIELD_START_RE.match(line):
                break
            collected.append(line)
            continue
        if line.startswith(prefix):
            capturing = True
            collected.append(line[len(prefix) :])
    return "\n".join(collected)


def _registered_adjudication_ids() -> set[str]:
    if not ADJUDICATIONS.exists():
        return set()
    ids: set[str] = set()
    for raw in ADJUDICATIONS.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        record = json.loads(line)
        adjudication_id = record.get("adjudication_id")
        if isinstance(adjudication_id, str):
            ids.add(adjudication_id)
    return ids


class SpecLedgerTest(unittest.TestCase):
    def test_required_fields_present(self) -> None:
        """確定項目はテンプレートの全 9 欄を `- **欄名**:` の行形式で持つ。"""
        missing: list[str] = []
        for finding_id, body in _entries():
            for name in REQUIRED_FIELDS:
                prefix = FIELD_LINE.format(name=name)
                if not any(line.startswith(prefix) for line in body.splitlines()):
                    missing.append(f"{finding_id}: 欄 '{name}' が無い")
        self.assertEqual(
            missing,
            [],
            "spec-ledger.md の確定項目に必須欄の欠落:\n" + "\n".join(missing),
        )

    def test_evidence_paths_exist(self) -> None:
        """根拠欄に書いたファイルパスがリポジトリに実在する (行番号は見ない)。"""
        broken: list[str] = []
        for finding_id, body in _entries():
            evidence = _field_body(body, "根拠 (file:line)")
            for token in BACKTICK_RE.findall(evidence):
                matched = PATH_LIKE.match(token.strip())
                if matched is None:
                    continue
                path = matched.group("path")
                if not (REPO_ROOT / path).exists():
                    broken.append(f"{finding_id}: 根拠パスが実在しない: {path}")
        self.assertEqual(
            broken,
            [],
            "spec-ledger.md の根拠パスが腐っている:\n" + "\n".join(broken),
        )

    def test_registry_cross_reference_resolves(self) -> None:
        """「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在する。"""
        known = _registered_adjudication_ids()
        dangling: list[str] = []
        for finding_id, body in _entries():
            registry = _field_body(body, "機械 registry")
            if "登録済" not in registry:
                continue
            for adjudication_id in ADJ_ID_RE.findall(registry):
                if adjudication_id not in known:
                    dangling.append(
                        f"{finding_id}: {adjudication_id} が adjudications.jsonl に無い"
                    )
        self.assertEqual(
            dangling,
            [],
            "spec-ledger.md と機械 registry の相互参照が切れている:\n"
            + "\n".join(dangling),
        )


if __name__ == "__main__":
    unittest.main()

### .claude/skills/app-bug-hunt/ledger/validate_findings.py の adjudication 検証部 (抜粋 195-333 行)

# cross-session の「誤検知 / 意図的仕様 / won't-fix」台帳。Phase4 統合 (親) のみが consult し、
# 一致 finding を annotate + downrank する (drop しない)。過剰抑制 (同 species の新規 real bug の
# 取りこぼし) を多層ゲートで構造的に防ぐ。設計: devnotes/20260624-1035-bughunt-adjudication-registry/。
import fnmatch

ADJ_VERDICTS = {"false_positive", "intentional", "wont_fix"}
SCOPE_KINDS = {"route_name", "screen_id", "path_glob"}
# mode/env は bug-hunt harness の第一級ディメンション (manifest.real_mode / 走行環境)。
# fake 限定の偽陽性を real モードの実退行に誤適用しないための load-bearing な条件なので、
# generic な precondition に潰さず governed key として持つ (spirux HARNESS-01 の教訓:
# 旧 COND_KEYS に mode/env が無く schema drift → fail-closed で抑制が全面停止した)。
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition", "mode", "env"}
ADJ_REQUIRED = [
    "adjudication_id", "species_key", "scope", "conditions", "symptom", "verdict",
    "rationale_ref", "source_finding_ids", "adjudicated_at_run", "adjudicated_at_commit",
    "watch_globs", "review_after_days",
]
_ADJ_ID_RE = re.compile(r"^A-[0-9]{3,}$")
_RUN_ID_RE = re.compile(r"^[0-9]{8}-[0-9]{6}$")
_FIND_ID_RE = re.compile(r"^F-")
# adjudication species_key: finding 側 is_token と同一 SoT (_SPECIES_TOKEN) から導出する
# (3 segment の token + tenant_relation)。ハイフン付き resource_type (admin-organization 等) を許容。
_ADJ_SPECIES_KEY_RE = re.compile(
    rf"^{_SPECIES_TOKEN}:{_SPECIES_TOKEN}:{_SPECIES_TOKEN}:(self|same_tenant|cross_tenant|guest|n/a)$"
)
_NOVELTY_STOP = {
    "the", "and", "for", "with", "without", "but", "not", "this", "that", "から", "では",
    "して", "する", "した", "ない", "ます", "page", "url", "test", "step",
}


def normalize(s) -> str:
    return re.sub(r"\s+", " ", str(s).strip().lower())


def _overbroad_glob(v: str) -> bool:
    # path_glob は literal path segment >= 2 必須 (例 /billing/checkout/* / /organizations/*/settings は可、
    # /organizations/* や単独 */**//* は reject)。
    if v in ("", "*", "**", "/*", "/**"):
        return True
    literal_segs = [seg for seg in v.split("/") if seg and "*" not in seg]
    return len(literal_segs) < 2


def validate_adjudications(adjs: list) -> list:
    """adjs: [(lineno, dict|None, raw)] を検証。errors の list[(lineno, adj_id, [msg])] を返す。"""
    errors = []
    ids = {}
    active_keys = {}
    all_ids = {a.get("adjudication_id") for _, a, _ in adjs if isinstance(a, dict)}
    superseded = {a["supersedes"] for _, a, _ in adjs if isinstance(a, dict) and a.get("supersedes")}
    for lineno, adj, _raw in adjs:
        if adj is None:
            errors.append((lineno, "?", ["json parse error"]))
            continue
        errs = []
        for f in ADJ_REQUIRED:
            missing = f not in adj
            if not missing and f != "conditions" and adj[f] in (None, "", [], {}):
                missing = True
            if missing:
                errs.append(f"missing required: {f}")
        aid = adj.get("adjudication_id", "?")
        if "adjudication_id" in adj and not _ADJ_ID_RE.match(str(adj["adjudication_id"])):
            errs.append(f"bad adjudication_id: {adj.get('adjudication_id')!r}")
        if aid in ids:
            errs.append(f"duplicate adjudication_id: {aid}")
        ids[aid] = True
        sk = adj.get("species_key", "")
        if not _ADJ_SPECIES_KEY_RE.match(str(sk)):
            errs.append(f"bad species_key: {sk!r}")
        scope = adj.get("scope") or {}
        sk_kind = scope.get("scope_kind")
        sk_val = scope.get("scope_value", "")
        if sk_kind not in SCOPE_KINDS:
            errs.append(f"bad scope_kind: {sk_kind!r}")
        if not sk_val:
            errs.append("empty scope_value")
        elif sk_kind == "path_glob" and _overbroad_glob(sk_val):
            errs.append(f"overbroad path_glob (need >=2 literal segments): {sk_val!r}")
        conds = adj.get("conditions", {})
        if not isinstance(conds, dict):
            errs.append("conditions must be object")
        else:
            for k in conds:
                if k not in COND_KEYS:
                    errs.append(f"bad condition key: {k!r}")
        sym = adj.get("symptom") or {}
        if not sym.get("required_tokens"):
            errs.append("symptom.required_tokens must be non-empty")
        if adj.get("verdict") not in ADJ_VERDICTS:
            errs.append(f"bad verdict: {adj.get('verdict')!r}")
        sfi = adj.get("source_finding_ids") or []
        if not sfi or not all(_FIND_ID_RE.match(str(x)) for x in sfi):
            errs.append("source_finding_ids must be non-empty list of F-* ids")
        if "adjudicated_at_run" in adj and not _RUN_ID_RE.match(str(adj["adjudicated_at_run"])):
            errs.append(f"bad adjudicated_at_run: {adj.get('adjudicated_at_run')!r}")
        wg = adj.get("watch_globs") or []
        if not wg:
            errs.append("watch_globs must be non-empty")
        elif any(g in ("", "*", "**", "/*", "/**") for g in wg):
            errs.append("watch_globs contains overbroad glob")
        rad = adj.get("review_after_days")
        if not isinstance(rad, int) or rad <= 0:
            errs.append(f"review_after_days must be int>0: {rad!r}")
        sup = adj.get("supersedes")
        if sup is not None:
            if sup == aid:
                errs.append("supersedes self-reference (cycle)")
            elif sup not in all_ids:
                errs.append(f"supersedes unknown id: {sup}")
        # active (未 superseded) 多重: 同一 (species_key, scope, conditions, symptom)
        if aid not in superseded and not errs:
            akey = (sk, json.dumps(scope, sort_keys=True),
                    json.dumps(conds, sort_keys=True), json.dumps(sym, sort_keys=True))
            if akey in active_keys:
                errs.append(f"duplicate active adjudication for key (supersede instead): {active_keys[akey]}")
            else:
                active_keys[akey] = aid
        if errs:
            errors.append((lineno, aid, errs))
    # supersedes DAG: 循環検出 (A->B->A 等)。自己参照は上で個別検出済み。
    sup_map = {a["adjudication_id"]: a.get("supersedes")
               for _, a, _ in adjs if isinstance(a, dict) and a.get("adjudication_id")}
    cyc = set()
    for start in sup_map:
        seen, cur = [], start
        while cur in sup_map and sup_map[cur]:
            cur = sup_map[cur]
            if cur in seen or cur == start:
                cyc.add(start)
                break
            seen.append(cur)
            if len(seen) > len(sup_map):
                cyc.add(start)
                break
    if cyc:
        errors.append((0, "?", [f"supersedes cycle involving: {sorted(cyc)}"]))
    return errors
