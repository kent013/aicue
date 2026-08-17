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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【本件固有の前提】
- 本件は家系 (複数リポジトリ) の機能台帳のオーナー裁定 AG-192 (AG-076b の執行) の実行であり、裁定の是非は論点ではない。
- CI を落とす Architecture テストを 1 本削除し、整合確認を文書更新スキルの手順へ移す。AG-162 が許す「落とさない検査」は不採用と判断している (理由は設計に記載)。

【レビュー観点】
1. コードの正確性 (照合コマンドの正しさ、エッジケース、取りこぼし)
2. 既存コードとの整合性 (命名規約、パターン、他テストへの影響)
3. PHPStan level 10 適合性
4. テスト計画の網羅性 (撤去という性質を踏まえて)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク (撤去で失われる検出力、残存参照)
8. 波及変更の網羅性 (この撤去で壊れる他テスト・CI 設定・文書の見落としが無いか)
9. セキュリティ (AGENTS.md のセキュリティ不変条件に触れていないか)
10. DESIGN.md 準拠 / 11. Atomic Design 準拠 (UI 変更が無ければその旨で可)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: scripts 台帳の CI 検査を撤去し、整合確認を文書更新スキルへ一本化する

作業項目: aicue:T210 (登録予定) / 機能台帳: `scripts-readme-ledger`
概念設計: [conceptual-design.md](./conceptual-design.md) (Codex 概念設計レビュー Round 1 で APPROVED)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本作業は開発の進め方の是正であり、使命への貢献は**間接**である (過大に主張しない)。

### 禁止事項 (AGENTS.md より。本作業に効くもの)

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

> **禁止事項 1 との関係**: 本作業は「テストを書かずに済ませる」ものではなく、
> **この不変条件を機械強制しないという家系のオーナー裁定 (AG-076b) を、その執行を命じた裁定 (AG-192) に従って
> 実行する**ものである。担い手を宙に浮かせないために、撤去と同じ変更で受け皿
> (文書更新スキルの段 + `scripts/README.md` の対象範囲の宣言) を作る。撤去は新しい不変条件を 1 つも作らないので、
> 新規に登録すべきテストも無い。詳細は概念設計の「禁止事項との関係」節。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 禁止)
- `declare(strict_types=1)` + 日本語コメント
- コードフォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 台帳の根拠 (裁定)

| 裁定 | 内容 | 本作業への効き方 |
|---|---|---|
| AG-076 / AG-133 | 整合確認は文書更新スキルの手順内で人手で行う形をもって完成とする。破ると CI が落ちる機械検査は意図的に 0 本 | 受け皿の形を規定する |
| AG-076b | 上記を選び直したうえで、本リポジトリの独自検査の撤去を求めた | 撤去の根拠 |
| AG-162 (2026-08-13) | 「落とさない検査」(違反があっても exit 0 で報告のみ) は**許す** (義務ではない) | 採否は本設計で判断 → **不採用** |
| AG-192 (2026-08-16) | AG-076b を維持し**執行する**。整合確認は家系統一の形へ一本化。撤去完了の報告をもって台帳の aicue セルを implemented へ戻す | 本作業そのもの |

家系の ID は `<repo>:ID` の形で書く (aicue:T210 / aicue:T176 / aicue:T208 / spirux:T1185)。

## 現状 (実読・実行で確認した事実)

- 撤去対象 `tests/Architecture/ScriptsReadmeInventoryTest.php` は 211 行。
  本体 1 本 + 負のコントロール 4 本 = **テスト 5 本**。定数 `SCRIPTS_README_EXEMPT` と
  関数 3 本 (`scriptsDirectoryFiles` / `scriptsReadmeRows` / `scriptsReadmeInventoryViolations`) を定義するが、
  **これらを外部から呼んでいるファイルは 0 件** (grep 実測)。撤去で fatal になる経路は無い。
- 台帳の現状: 追跡下の `scripts/` は README 自身を除き **32 ファイル**、表のデータ行も **32 行**、
  **未記載 0 件 / 残骸 0 件** (本設計の照合コマンドを実行して確認)。
  ファイルシステム上の `scripts/` 配下は 33 ファイルで、追跡下 33 ファイル (README 込み) と一致する
  = **未追跡ファイル 0 件**。
- 名前の参照箇所 (履歴を除く): **3 ファイル 4 箇所**。すべて他テストの説明コメント。
  - `tests/js/architecture/verification-commands-doc-sync.test.ts` L13
  - `tests/Architecture/BughuntInventoryToolSelfTest.php` L36
  - `tests/Architecture/BrowserProvisioningEntrypointTest.php` L66 / L101
- 履歴側の参照 (触らない): `docs/TODO-closed.md` L121 (aicue:T104) / L216 (aicue:T208)、`devnotes/` 多数。
- `.claude/skills/app-update-docs/SKILL.md` に `scripts/README` の語は **0 件** (受け皿が無い)。
- `scripts/README.md` に対象範囲の宣言は無い (追記規約の一文だけ)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | CI を落とす検査の撤去 | `tests/Architecture/ScriptsReadmeInventoryTest.php` (削除) | Critical |
| 2 | 受け皿: 文書更新スキルへ整合確認の段を新設 | `.claude/skills/app-update-docs/SKILL.md` | Critical |
| 3 | 台帳の対象範囲の宣言と参照先の明記 | `scripts/README.md` | High |
| 4 | 規約本文への一文追加 (再発防止) | `AGENTS.md` | High |
| 5 | 撤去で行き先を失う参照コメントの始末 | `tests/js/architecture/verification-commands-doc-sync.test.ts` / `tests/Architecture/BughuntInventoryToolSelfTest.php` / `tests/Architecture/BrowserProvisioningEntrypointTest.php` | High |

---

## 施策 1: CI を落とす検査の撤去

### 変更箇所

- ファイル: `tests/Architecture/ScriptsReadmeInventoryTest.php` (全 211 行) — **削除**

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 5 の 3 ファイル (説明コメントのみ。実行時依存は無い)
- CI 設定: なし (`.github/` に本テストを名指しする記述は無く、`composer test` が全 suite を回す)
- `phpunit.xml` / suite 定義: なし (ディレクトリ単位の指定であり、ファイル名の列挙は無い)

### 変更後

ファイルごと削除する (`git rm`)。**中身を空にして残す / skip でごまかす形は採らない**
(AGENTS.md 思考原則 3「後方互換の並走を残さない」)。

### リスク

- 撤去後は「毎 push で必ず落ちる」強制が失われる。ドリフトは文書更新スキルを回した時点でのみ検出される
  (**承知のうえの帰結**。AG-192 が選んだ形)。
- 撤去対象は他 3 テストの説明コメントから参照されており、放置すると存在しないファイルを指す説明が残る
  → 施策 5 で同時に始末する。

---

## 施策 2: 受け皿 — 文書更新スキルへ整合確認の段を新設

### 変更箇所

- ファイル: `.claude/skills/app-update-docs/SKILL.md`
  - Step 1「1-1. ドキュメント一覧の確認」の末尾 (L70 付近) — 段を必ず実施する旨の一文を追加
  - Step 2 (L103〜) — 先頭に `### 2-1. scripts/ 台帳の整合確認 (scope 引数によらず必須)` を新設し、
    既存の陳腐化チェック本体を `### 2-2. ドキュメント本文の陳腐化チェック` として numbering を与える
  - Step 5「完了報告」(L203〜) — 報告の雛形へ実測値 4 つの枠を追加

> **見出し配置の理由**: Step 4 の本文が「Step 2で検出した」「Step 3で新規作成が必要と判断した」と
> **Step 番号で相互参照している**。Step 全体を繰り上げると 2 箇所の参照が壊れるので、
> Step 2 の内側に 2-1 / 2-2 の小見出しを作る形にする (家系の先行 3 リポジトリも Step 2-1 と呼んでいる)。

### 現行 (該当箇所)

```markdown
### 1-1. ドキュメント一覧の確認
...
ただし `docs/TODO.md` / `docs/TODO-closed.md` は `/app-todo-add` / `/app-todo-close`
スキルの管轄のため、本スキルの更新対象から除外する。
```

```markdown
## Step 2: 陳腐化チェック

各ドキュメントについて、以下の観点でソースコードとの乖離を検出する。
```

### 変更後 (追加・変更するテキスト)

Step 1-1 の末尾に 1 行足す:

```markdown
`scripts/README.md` の台帳の整合確認 (2-1) は、**scope 引数によらず必ず実施する**
(scope で絞っても飛ばさない)。
```

Step 2 の直下へ新設する段 (本文はこのとおりに書く。入れ子の囲みを避けるため外側を `~~~` にしている):

~~~markdown
### 2-1. scripts/ 台帳の整合確認 (scope 引数によらず必須)

`scripts/README.md` は `scripts/` 配下のスクリプト台帳であり、**この整合を CI で落ちる検査にしない**
(家系の裁定 AG-076 / AG-076b / AG-133、およびその執行を命じた AG-192)。
突き合わせは本段が人手 (エージェント) で行う。**この手順を機械検査へ昇格させないこと。**
数え方の正本は `scripts/README.md` 冒頭の「台帳の対象範囲」である。

#### 形態 A: 網羅性 (双方向の差集合)

```bash
WORK=$(mktemp -d)
git ls-files scripts/ | grep -v '^scripts/README\.md$' | sort > "$WORK/tracked.txt"
sed -n 's/^| `\([^`]*\)`.*/scripts\/\1/p' scripts/README.md | sort > "$WORK/listed.txt"

echo "追跡下: $(wc -l < "$WORK/tracked.txt") / 表の識別子: $(wc -l < "$WORK/listed.txt")"
echo '--- 未記載 (実体にあるが表に無い) ---'; comm -23 "$WORK/tracked.txt" "$WORK/listed.txt"
echo '--- 残骸 (表にあるが実体に無い) ---';   comm -13 "$WORK/tracked.txt" "$WORK/listed.txt"
echo '--- 重複行 ---';                        uniq -d "$WORK/listed.txt"
```

- **両向きを必ず測る**。片側の差集合だけを見て「欠落 0 件」と判断しないこと
  (家系の別リポジトリで実際に起きた読み違いである)。
- 未記載が出たら表へ 1 行足す。残骸が出たら行を消す。重複が出たら 1 行に畳む。

#### 形態 B: 記述の実態ずれ

表の「用途」「実行タイミング」が実装と食い違っていないかを実ファイルで裏取りする。
とくに **「〜から自動呼び出し」と書かれた行は、呼び出し元を実際に grep して確かめる**
(過去に、どこからも呼ばれていないスクリプトが「CI から自動呼び出し」と書かれたまま残っていた)。

```bash
grep -rn "<スクリプト名>" --include='*.sh' --include='*.json' --include='*.yml' \
  --include='*.php' --include='*.ts' . | grep -v '^./devnotes/'
```

ずれていたら **README 側を実態に合わせる** (実装を README に合わせない)。
~~~

Step 5 の完了報告の雛形へ足す枠:

```markdown
### scripts/ 台帳の整合確認 (2-1)
- 追跡下のファイル数: {N} / 表の識別子数: {N}
- 未記載: {N} 件（{パス}） / 残骸: {N} 件（{パス}）
- 記述の実態ずれ: {N} 件（{内容}）
```

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- テストファイル: なし (このスキル本文を検査する機械テストは存在しない。
  `verification-commands-doc-sync.test.ts` が見るのは `app-implement` スキルの検証コマンド列だけで、
  本スキルは対象外)

### テスト計画

- 新規テストは**作らない** (作ると裁定違反になる。これは「テストを省く」のではなく
  「機械検査にしない」という裁定そのものである)
- 段が入ったことは受け入れ条件 A4 の grep で機械確認する
- 段が実際に効くことは、実装中に 1 回実走して実測値を `verification.md` へ残す

### リスク

- 手順が長くなり、文書更新のたびの負担が増える → 形態 A をコマンド列にして機械的に終わる形にする
- 段を読み飛ばされる → Step 1-1 と Step 5 の 2 箇所から呼び戻す (家系の先行実装と同じ配置)

---

## 施策 3: 台帳の対象範囲の宣言と参照先の明記

### 変更箇所

- ファイル: `scripts/README.md` L6-7 (規約ブロック) の直後

### 現行コード

```markdown
> **規約**: `scripts/` へスクリプトを追加 (devnotes からの昇格を含む) したら、
> 必ず下表に 1 行追記する。用途と実行タイミングが書けないスクリプトは昇格しない。
```

### 変更後コード

```markdown
> **規約**: `scripts/` へスクリプトを追加 (devnotes からの昇格を含む) したら、
> 必ず下表に 1 行追記する。用途と実行タイミングが書けないスクリプトは昇格しない。

> **台帳の対象範囲 (数え方の正本)**:
> - 対象は **git 追跡下の `scripts/` 配下の全ファイル**。拡張子でもサブディレクトリでも除外しない
>   (`ci/` や `tests/` の下のファイルも 1 行を持つ)。
> - 除外は**本書自身 (`scripts/README.md`) の 1 件だけ**。
> - 識別子は表の**第 1 列にバッククォート囲みで 1 つだけ**書く (`scripts/` からの相対パス)。
>   「表のデータ行数 = 識別子数 = 追跡下の対象数」が保たれている状態を正とする。
> - **この整合を CI で落ちる検査にしない** (家系の裁定 AG-076 / AG-076b / AG-133 / AG-192)。
>   突合の正本は `.claude/skills/app-update-docs/SKILL.md` の
>   「2-1. scripts/ 台帳の整合確認」で、文書更新を回すたびに人手で実行する。
```

### 波及変更

- テストファイル: `tests/Architecture/BughuntShardCapInvariantTest.php` は `scripts/README.md` を
  「bug-hunt の上限値の説明を持つファイル」として目録に持つ (`'scripts/README.md' => '上記 guard の説明'`)。
  本施策は冒頭の宣言を足すだけで上限値の記述に触れないため、影響しない (実読で確認済み)。
- 表の行そのものは変更しない (現時点で未記載 0 件 / 残骸 0 件)。

### テスト計画

- 受け入れ条件 A5 の grep で節の存在を機械確認する
- 施策 2 の照合コマンドが宣言どおりの数を返すことを実走で確認する

### リスク

- 数え方の宣言と照合コマンドが食い違うと、巡回のたびに数が 1 件ずれる
  (家系の別リポジトリで実際に起きた) → 宣言の文言と施策 2 のコマンドを**同じ変更で**書き、
  実走した実測値を `verification.md` に残す

---

## 施策 4: 規約本文への一文追加 (再発防止)

### 変更箇所

- ファイル: `AGENTS.md` L300-301 (§設計・TODO・devnotes の運用)

### 現行コード

```markdown
- 一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ
  (昇格時は `scripts/README.md` の台帳に追記する)
```

### 変更後コード

```markdown
- 一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ
  (昇格時は `scripts/README.md` の台帳に追記する)。
  **この整合を CI で落ちる検査にしない** (家系の裁定 AG-076b / その執行を命じた AG-192)。
  突合は `app-update-docs` スキルの「2-1. scripts/ 台帳の整合確認」が人手で行う
```

### 波及変更

- `tests/js/architecture/verification-commands-doc-sync.test.ts` は
  `<!-- VERIFICATION_COMMANDS:BEGIN --> 〜 END` の**内側だけ**を照合する (L230-235)。
  本施策は L300 付近で、マーカーの外なので影響しない (実読で確認済み)。
- 他に `AGENTS.md` の全文または節構造を検査する機械テストは無い。

### テスト計画

- 受け入れ条件 A5 の grep で機械確認
- `pnpm test` (`verification-commands-doc-sync.test.ts` を含む) が green であること

### リスク

- 規約本文に理由まで書くと二重管理になる → **1 文に抑え、正本 (`scripts/README.md` の宣言と
  スキルの段) へ委ねる**。件数・手順は書かない。

---

## 施策 5: 撤去で行き先を失う参照コメントの始末

### 5-a. `tests/js/architecture/verification-commands-doc-sync.test.ts` L11-13

現行:

```ts
 * 免除は「理由付き」でしか書けない (EXEMPT)。免除エントリが package.json から消えたら
 * 逆方向検査 (V3) が落ちる = stale 免除の残置を許さない
 * (tests/Architecture/ScriptsReadmeInventoryTest.php と同じ作法)。
```

変更後 (参照を落とし、原則だけ残す):

```ts
 * 免除は「理由付き」でしか書けない (EXEMPT)。免除エントリが package.json から消えたら
 * 逆方向検査 (V3) が落ちる = stale 免除の残置を許さない。
```

### 5-b. `tests/Architecture/BughuntInventoryToolSelfTest.php` L35-36

現行:

```php
    // PYTHONDONTWRITEBYTECODE: __pycache__ を作らせない (scripts/ 配下の台帳検査
    // ScriptsReadmeInventoryTest の母集団を生成物で汚さないため)。
```

変更後 (理由そのものを書く。動作は変えない):

```php
    // PYTHONDONTWRITEBYTECODE: __pycache__ を作らせない (scripts/ 配下に git 管理外の
    // 生成物を残すと、scripts/README.md の台帳と実ファイルの突合が汚れるため)。
```

### 5-c. `tests/Architecture/BrowserProvisioningEntrypointTest.php` L64-66

現行:

```php
 * `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
 * 文字途中で分断する (既存 ScriptsReadmeInventoryTest と同方針で、改行は明示列挙する)。
```

変更後 (生きている正本 = `\R` の全数固定ゲートへ付け替える):

```php
 * `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
 * 文字途中で分断する (`PcreUnicodeModifierGateTest` が全数を固定している。本ファイルは
 * `\R` を使わず改行を明示列挙する)。
```

### 5-d. `tests/Architecture/BrowserProvisioningEntrypointTest.php` L99-101

現行:

```php
 * `glob('scripts/*.sh')` では `scripts/tools/install-browser.sh` を取りこぼす
 * (ScriptsReadmeInventoryTest::scriptsDirectoryFiles と同じ理由・同じ道具を使う)。
```

変更後:

```php
 * `glob('scripts/*.sh')` では `scripts/tools/install-browser.sh` を取りこぼす
 * (2 階層だけを見る実装は将来のサブディレクトリを黙って漏らすので、再帰列挙を使う)。
```

### 波及変更

- 実行コードは 1 行も変えない (コメントのみ)。関数名・定数名・判定に影響なし。

### テスト計画

- `composer test` / `pnpm test` が green (コメント変更なので挙動は不変。**逆に言えば、
  ここが赤くなったらコメント以外を触ってしまっている**)
- 受け入れ条件 A2 の grep で残存参照 0 件を機械確認

### リスク

- コメント修正に紛れて実行行を触る → diff を 1 行ずつ確認する (変更行はすべて `*` / `//` 始まり)

---

## テストファースト計画 (何を先に赤にするか)

本作業は**撤去**であり、新しい不変条件を作らないので「新しい Pest テストを先に赤にする」形は取らない
(取ると裁定違反になる)。代わりに、**受け入れ条件を機械コマンドとして先に走らせ、赤 (未達) を確認してから着手する**。

| 順 | 何を先に走らせるか | 着手前の期待 (赤) | 着手後の期待 (緑) |
|---|---|---|---|
| R1 | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | 4 件ヒット | 0 件 (exit 1) |
| R2 | `test -e tests/Architecture/ScriptsReadmeInventoryTest.php` | 真 | 偽 |
| R3 | `grep -c 'scripts/README' .claude/skills/app-update-docs/SKILL.md` | 0 | 1 以上 |
| R4 | `grep -c '台帳の対象範囲' scripts/README.md` | 0 | 1 |
| R5 | `composer test` の実行結果を記録 | green (総数を控える) | green (テスト 5 本減。**5 本以外の増減があれば影響を疑う**) |

### 負のコントロール (照合コマンドが空振りしていないことの確認)

**作業ツリーには 1 バイトも触らない。** `mktemp -d` した作業用ディレクトリへ
`scripts/README.md` と追跡ファイル一覧の**複製**を作り、その複製の上だけで崩す。

1. 複製の表から 1 行消す → 形態 A が **未記載 1 件**を出すこと
2. 複製の表に実在しないパスの行を足す → **残骸 1 件**を出すこと
3. 複製の表の 1 行を複製する → **重複行 1 件**を出すこと

実行記録 (コマンドと出力) は `devnotes/20260817-1309-todo-t210-scripts-readme-gate-removal/verification.md`
へ残す。実装ブランチのコミットに含める。

## 受け入れ条件 (機械検証可能)

| # | 条件 | 検証コマンド | 期待 |
|---|---|---|---|
| A1 | 検査ファイルが存在しない | `test ! -e tests/Architecture/ScriptsReadmeInventoryTest.php` | exit 0 |
| A2 | 履歴以外に名前の参照が残っていない | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | ヒット 0 件 (exit 1) |
| A3 | スキルに段が入っている (4 点) | `grep -n '2-1. scripts/ 台帳の整合確認\|scope 引数によらず\|機械検査へ昇格させない\|AG-192' .claude/skills/app-update-docs/SKILL.md` | 4 点すべてヒット |
| A4 | 段が双方向の差集合と実態裏取りの 2 形態を持つ | `grep -n '形態 A\|形態 B\|comm -23\|comm -13' .claude/skills/app-update-docs/SKILL.md` | すべてヒット |
| A5 | README の宣言と AGENTS.md の一文 | `grep -n '台帳の対象範囲' scripts/README.md` / `grep -n 'CI で落ちる検査にしない' AGENTS.md scripts/README.md` | いずれもヒット |
| A6 | 台帳が実態と一致している (実走) | 施策 2 の形態 A のコマンド列 | 追跡下 = 表の識別子数、未記載 0 件 / 残骸 0 件 / 重複 0 件 |
| A7 | 負のコントロールが効く | `verification.md` に 3 ケースの実行記録がある | 未記載 1 / 残骸 1 / 重複 1 を検出 |
| A8 | 全検証コマンドが green | 下記「全検証コマンド」 | すべて green |

### 全検証コマンド (すべて green であること)

```bash
composer test              # Pest 全 suite (pgsql / --parallel)。撤去でテスト 5 本減る
composer phpstan           # level 10。No errors
vendor/bin/pint --test     # フォーマット差分なし
pnpm lint
pnpm typecheck
pnpm test                  # vitest (verification-commands-doc-sync.test.ts を含む)
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

> テストレーンはホスト全体で 1 本ずつしか走らない (AGENTS.md §テストレーンのグローバルロック)。
> 待ちが出るのは正常であり、kill しない / ロックファイルを消さない。

## 保証しないもの (誇張しない)

- **毎 push の強制は失われる**。ドリフトは文書更新スキルを回した時点でしか検出されない。
  「台帳は常に実態と一致している」とは今後書けない。
- **母集団が変わる**。撤去した検査はファイルシステムを再帰走査していたが、新しい照合は
  **git 追跡下**を数える。未追跡 (まだ `git add` していない) スクリプトは母集団に入らない
  (現時点で未追跡 0 件なので実害は無いが、性質としては違う)。
- **表の記述内容の正しさは機械では判定しない**。形態 B は人が実ファイルで裏取りする手順であり、
  網羅も自動化もされていない。
- **段が実際に実行されたことを機械で保証しない**。完了報告の実測値は自己申告である。
- **表の書式崩れは「取りこぼし」ではなく「未記載」として現れる**。第 1 列をバッククォートで囲まない行は
  識別子として拾われないため、そのファイルは未記載側に出る (安全側に倒れる) が、
  行そのものが不正であることは指摘されない。
- **他リポジトリの状態・台帳セルの更新は本作業の外**。台帳への書き戻し (撤去完了の報告) は
  main 合流・push の後に別途行う。

## やらないと決めたこと (理由付き)

| やらないこと | 理由 |
|---|---|
| AG-162 の「落とさない検査」(exit 0 で報告のみ) の実装 | ①思考原則 2 (今必要なものだけ作る)。AG-162 は許すだけで義務ではなく、家系 6 リポジトリのいずれも未実装 = 作ると本リポジトリがまた唯一の形になる (今回の是正が消そうとしている状態そのもの)。②突合の規則の正本が「スキルの段」と「報告する実行物」の 2 つになり必ず食い違う。しかもその実行物は `scripts/` 配下に置くので**自分自身が台帳の 1 行を要求する**。③終了コード 0 の CI 出力は読まれず、偽の安心になる |
| 検査を別名・別ファイルで作り直すこと / 既存の他 gate へ相乗りさせること | 名前を変えても AG-076b が撤去を求めた「CI を落とす検査」そのものである。とくに `BrowserProvisioningEntrypointTest` は `scripts/` を再帰走査する道具を持つため相乗りしやすいが、しない |
| `devnotes/` と `docs/TODO-closed.md` の書き換え | 履歴であり、当時の事実として正しい。書き換えると「いつ何が決まったか」が読めなくなる。撤去後に読んだ人が混乱しないよう、**新しい説明は新しい場所 (スキルの段と README の宣言) に置く** |
| `scripts/README.md` の表の書き直し | 現時点で未記載 0 件 / 残骸 0 件であり、直す対象が無い (最小変更の原則) |
| 撤去した検査の関数を共通ヘルパへ退避すること | 呼び出し元が 0 件。使われないコードを残すのは後方互換の並走 (思考原則 3) |
| 他リポジトリへの還流提案 | 本作業は家系標準への追従であり、新しい知見の還流ではない |
| `docs/TODO.md` への登録 | `app-todo-add` スキルの責務 (本設計はファイル生成のみ) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更はファイル 1 本の削除 + 文書 3 本 + コメント 4 箇所で、実行コードに 1 行も触れない。他施策のコードと衝突する面が無い |
| 競合リスク | ①同時期の別ブランチが `scripts/` へスクリプトを足すと、本作業の合流後は CI が落ちないため追記漏れが素通りする → 合流順に関わらず、**main 合流の直前に形態 A をもう一度実走して 0 件を確認する**。②`AGENTS.md` / `scripts/README.md` は他タスクも触りやすいので、衝突時は本作業の追加ブロックを残す形で解決する |

## 関連する現行コード

### tests/Architecture/ScriptsReadmeInventoryTest.php (撤去対象・全文)
```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * 台帳規約 (AGENTS.md): 「scripts/ へスクリプトを追加したら scripts/README.md の表に 1 行追記する」
 * を deny-by-default で機械強制する。
 *
 * 本テストを足す理由: この規約は実際にドリフトした (make-shard-phpunit.php が
 * 「CI から自動呼び出し」と書かれたまま、どこからも呼ばれていなかった)。
 * 禁止事項 1 に従い、不変条件を Architecture テストへ登録するところまでを「実装済み」とする。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/**
 * 台帳登録の対象外 (`scripts/` からの相対パス)。理由を書けないものをここに足さないこと。
 *
 * @var array<string, string>
 */
const SCRIPTS_README_EXEMPT = [
    // 台帳そのもの。自分を自分の表へ登録するのは同語反復なので対象外にする。
    'README.md' => '台帳ファイル自身 (表の正本であって、表に載る対象ではない)',
];

/**
 * `scripts/` 配下を **再帰的に** 走査してファイルの相対パスを返す (純関数)。
 *
 * `scripts/*` + `scripts/ci/*` の 2 階層だけを見る実装は、将来 `scripts/foo/bar.sh` が
 * 増えたときに黙って漏れる = deny-by-default を名乗れない。
 *
 * @return list<string> `scripts/` からの相対パス (昇順)
 */
function scriptsDirectoryFiles(string $scriptsDir): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scriptsDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($scriptsDir) + 1));
        // Python の bytecode キャッシュは git 管理外の生成物 (.gitignore 済み)。
        // 台帳は「人が書いたスクリプト」の一覧なので、自己テストを手で走らせた副産物で
        // 無関係な gate が赤くならないように母集団から外す。
        if (str_contains($relative, '__pycache__/')) {
            continue;
        }
        $found[] = $relative;
    }

    sort($found);

    return $found;
}

/**
 * README の表を「相対パス => [用途, 実行タイミング]」へ正規化する (純関数)。
 *
 * パースは「行頭 `|` + 1 列目がバッククォート囲み」に限定する
 * (見出し行 / 区切り行 / 説明文を巻き込まないため)。
 *
 * @return array<string, array{purpose: string, timing: string}>
 */
function scriptsReadmeRows(string $markdown): array
{
    $rows = [];

    // 改行分割に `\R` を使わないこと: `/u` 無しの PCRE では `\R` が NEL (0x85) にも一致し、
    // 日本語 UTF-8 テキスト (0x85 を含む多バイト文字が頻出) を**文字の途中で分断**する。
    // 実測でこの表の行が壊れて S1 の偽陽性が出た。改行だけを明示列挙する。
    foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
        $line = trim($line);
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map(trim(...), explode('|', trim($line, '|')));
        if (count($cells) < 3) {
            continue;
        }
        if (preg_match('/^`([^`]+)`$/', $cells[0], $matches) !== 1) {
            continue;
        }

        $rows[$matches[1]] = ['purpose' => $cells[1], 'timing' => $cells[2]];
    }

    return $rows;
}

/**
 * 台帳と実ファイルの乖離を列挙する (純関数)。
 *
 * 実ファイルを読まない純関数に切り出すのは、負のコントロール (検出器が空振りしていないこと)
 * を fixture で確認できるようにするため。
 *
 * @param  list<string>  $files  `scripts/` からの相対パス
 * @param  array<string, array{purpose: string, timing: string}>  $rows
 * @param  array<string, string>  $exempt  相対パス => 除外理由
 * @return list<string> 違反一覧 (空 = 合格)
 */
function scriptsReadmeInventoryViolations(array $files, array $rows, array $exempt): array
{
    $violations = [];

    // S1: scripts/ 配下の全ファイル (明示 exemption を除く) が README の表に行を持つ
    foreach ($files as $relative) {
        if (array_key_exists($relative, $exempt)) {
            continue;
        }
        if (! array_key_exists($relative, $rows)) {
            $violations[] = "S1: scripts/{$relative} が scripts/README.md の表に無い (追加時は 1 行追記すること)";
        }
    }

    // S2: README の表の全行に対応する実ファイルがある
    foreach ($rows as $relative => $_row) {
        if (! in_array($relative, $files, true)) {
            $violations[] = "S2: scripts/README.md の行 `{$relative}` に対応する実ファイルが無い (削除時は行も消すこと)";
        }
    }

    // S3: 各行の「用途」「実行タイミング」列が空でない
    foreach ($rows as $relative => $row) {
        if ($row['purpose'] === '') {
            $violations[] = "S3: scripts/README.md の行 `{$relative}` の用途が空";
        }
        if ($row['timing'] === '') {
            $violations[] = "S3: scripts/README.md の行 `{$relative}` の実行タイミングが空";
        }
    }

    // S4: exemption が実在ファイルを指し、理由が非空であること
    foreach ($exempt as $relative => $reason) {
        if (! in_array($relative, $files, true)) {
            $violations[] = "S4: exemption `{$relative}` が実在しない (死んだ除外の残置)";
        }
        if (trim($reason) === '') {
            $violations[] = "S4: exemption `{$relative}` の理由が空 (理由なし除外は認めない)";
        }
    }

    return $violations;
}

test('scripts/ 配下の全ファイルが scripts/README.md の台帳と一致すること', function (): void {
    $markdown = file_get_contents(base_path('scripts/README.md'));
    Assert::string($markdown, 'scripts/README.md を読めない');

    $violations = scriptsReadmeInventoryViolations(
        scriptsDirectoryFiles(base_path('scripts')),
        scriptsReadmeRows($markdown),
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toBe([], "scripts/README.md 台帳の乖離:\n".implode("\n", $violations));
});

test('S1 負のコントロール: 台帳に無いファイルを検出すること', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh', 'ci/new-thing.php'],
        ['a.sh' => ['purpose' => 'x', 'timing' => 'y']],
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toContain(
        'S1: scripts/ci/new-thing.php が scripts/README.md の表に無い (追加時は 1 行追記すること)',
    );
});

test('S2 負のコントロール: 実体の無い行を検出すること (今回のドリフトそのもの)', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh'],
        [
            'a.sh' => ['purpose' => 'x', 'timing' => 'y'],
            'ci/make-shard-phpunit.php' => ['purpose' => 'sharding', 'timing' => 'CI から自動呼び出し'],
        ],
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toContain(
        'S2: scripts/README.md の行 `ci/make-shard-phpunit.php` に対応する実ファイルが無い (削除時は行も消すこと)',
    );
});

test('S3 負のコントロール: 実行タイミングが空の行を検出すること', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh'],
        ['a.sh' => ['purpose' => 'x', 'timing' => '']],
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toContain('S3: scripts/README.md の行 `a.sh` の実行タイミングが空');
});

test('S4 負のコントロール: 死んだ exemption と理由なし exemption を検出すること', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh'],
        ['a.sh' => ['purpose' => 'x', 'timing' => 'y']],
        ['gone.sh' => '既に消したスクリプト', 'a.sh' => '   '],
    );

    expect($violations)->toContain('S4: exemption `gone.sh` が実在しない (死んだ除外の残置)');
    expect($violations)->toContain('S4: exemption `a.sh` の理由が空 (理由なし除外は認めない)');
});
```

### scripts/README.md (冒頭 12 行 + 表の先頭 3 行)
```markdown
# scripts/

本番運用・開発環境向けの恒久スクリプト台帳。
設計・調査・一時スクリプトは `devnotes/` に置く (AGENTS.md)。

> **規約**: `scripts/` へスクリプトを追加 (devnotes からの昇格を含む) したら、
> 必ず下表に 1 行追記する。用途と実行タイミングが書けないスクリプトは昇格しない。

## スクリプト一覧

| スクリプト | 用途 | 実行タイミング |
|---|---|---|
| `global-test-lock.sh` | 全テストレーン共通のグローバルロック (source して使うライブラリ)。`/tmp/global-test-lane-<uid>.d/lock` を**ブロッキング取得**し、待機中のみ保持者の身元つき heartbeat を出す。レーンは専用プロセスグループで起動し、**グループが空になるまで**ロックを保持する。公開 API は `global_test_lock_acquire` / `global_test_lock_run` / `global_test_lock_on_exit` | 各 lane スクリプトから source (直接実行しない) |
| `with-global-test-lock.sh` | 任意コマンドをグローバルテストロック配下で実行する汎用ラッパ (lane スクリプトを持たない `pnpm test:packages` / `test:coverage` 用) | `package.json` の script から自動呼び出し |
| `verify-global-test-lock.sh` | グローバルテストロックの**並行挙動**検証スイート (層 1・C01〜C24)。実ロックには触れず `mktemp -d` の scratch 上で待機・heartbeat・fd 非継承・プロセスグループ刈り取り・シグナル収束・再入・終了コードを実プロセスで検証する | CI (`php` job) から自動実行 / ロック機構を変更したら手動実行 |
```

### AGENTS.md L292-303
```markdown
## 設計・TODO・devnotes の運用

- 設計フロー: 概念設計 → レビュー → 詳細設計 → レビュー(`app-design` スキル)。
  設計ドキュメントは `devnotes/YYYYMMDD-HHMM-{topic}/`、レビュー機械出力は同 `codex-history/`
- TODO: `docs/TODO.md`(Open)と `docs/TODO-closed.md`(Closed/Obsoleted)。
  登録は `app-todo-add`、クローズは `app-todo-close` スキル経由
- 実装は worktree(`.claude/worktrees/tasks/<id>`)で行い、テスト green + レビュー後に main へ
  (§worktree 運用ルール)
- 一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ
  (昇格時は `scripts/README.md` の台帳に追記する)
- 外部 skill (Stripe 公式) は `skills-lock.json` で管理する。
  `npx skills add docs.stripe.com` で `.claude/skills/` 配下に再導入できる(git 管理外)
```

### .claude/skills/app-update-docs/SKILL.md (Step 1-1 / Step 2 / Step 5)
```markdown
## Step 1: 現状把握

### 1-1. ドキュメント一覧の確認

```
Glob: docs/**/*.md
```

現在どのドキュメントが存在するか把握する（`AGENTS.md` / `CLAUDE.md` / `DESIGN.md` も対象に含める）。
ただし `docs/TODO.md` / `docs/TODO-closed.md` は `/app-todo-add` / `/app-todo-close`
スキルの管轄のため、本スキルの更新対象から除外する。

### 1-2. ソースコード構造の確認

`scope` 引数に応じてソースコードを探索する。

**全体スコープ（デフォルト）の場合:**

```
app/ 以下の主要ディレクトリ構造を確認（存在するもののみ）:
- Models/           — Eloquentモデル
- Services/         — ビジネスロジック
- Http/Controllers/ — コントローラー
- Http/Requests/    — FormRequest
- Http/Middleware/  — ミドルウェア
- Policies/         — 認可ポリシー
- Prompts/          — LLMプロンプトfactory
- Enums/ / Actions/ / Providers/ 等

resources/js/ 以下:
- pages/            — Svelteページコンポーネント
- components/       — 共通コンポーネント（atoms/molecules/organisms/templates）
- lib/              — 共有ロジック・型定義

routes/ — ルーティング定義
database/migrations/ — DBスキーマ
config/ — 設定ファイル
```

**絞り込みスコープの場合:**
指定されたコンポーネントに関連するディレクトリ・ファイルのみ探索。

---

## Step 2: 陳腐化チェック

各ドキュメントについて、以下の観点でソースコードとの乖離を検出する。

| チェック観点 | 方法 |
|------------|------|
| **クラス・関数の追加・削除・リネーム** | ドキュメント記載のファイル名・クラス名 vs 実ファイル |
| **ルート・API定義の変更** | ドキュメント記載のエンドポイント vs `routes/` |
| **DBスキーマの変更** | ドキュメント記載のテーブル・カラム vs マイグレーション |
| **新機能の未反映** | ソースコードにあるがドキュメントにない機能 |
| **削除済み機能の残存** | ドキュメントにあるがソースコードにない機能 |
| **型定義の乖離** | ドキュメント記載のDTO/Props vs 実際の型定義 |

**手順:**

1. ドキュメントを1つずつ `Read` する
2. ドキュメントに記載されているファイルパス・クラス名・関数名を抽出
3. 対応するソースコードを `Read` / `Grep` で確認
4. 乖離を検出したら記録する

**scope指定時**: 指定スコープに関連するドキュメントのみチェック。

### 検出結果の記録

```
[陳腐化] {ドキュメント名}: {記載内容} → 現在は {実際の内容}
[欠落]   {コンポーネント名}: ドキュメントなし。{概要}
[削除]   {ドキュメント名}: {記載機能} はソースコードから削除済み
[OK]     {ドキュメント名}: 最新の状態
```

---

...
## Step 5: 完了報告

```
## ドキュメント更新完了

### 更新サマリー
- 更新: {N}件（{ファイル名リスト}）
- 新規作成: {N}件（{ファイル名リスト}）
- 削除: {N}件（{ファイル名リスト}）

### 陳腐化修正
- {修正内容の箇条書き}
```
```

### 参照コメントの現状 (3 ファイル 4 箇所)
```
tests/Architecture/BughuntInventoryToolSelfTest.php-33-function bitsRunUnittest(array $modules): array
tests/Architecture/BughuntInventoryToolSelfTest.php-34-{
tests/Architecture/BughuntInventoryToolSelfTest.php-35-    // PYTHONDONTWRITEBYTECODE: __pycache__ を作らせない (scripts/ 配下の台帳検査
tests/Architecture/BughuntInventoryToolSelfTest.php:36:    // ScriptsReadmeInventoryTest の母集団を生成物で汚さないため)。
tests/Architecture/BughuntInventoryToolSelfTest.php-37-    $process = new Process(
tests/Architecture/BughuntInventoryToolSelfTest.php-38-        ['python3', '-m', 'unittest', ...$modules],
tests/Architecture/BrowserProvisioningEntrypointTest.php-63- * 行頭 (空白を除く) が `#` の行を落とし、**行継続を畳んでから**実行行を返す (純関数)。
tests/Architecture/BrowserProvisioningEntrypointTest.php-64- *
tests/Architecture/BrowserProvisioningEntrypointTest.php-65- * `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
tests/Architecture/BrowserProvisioningEntrypointTest.php:66: * 文字途中で分断する (既存 ScriptsReadmeInventoryTest と同方針で、改行は明示列挙する)。
tests/Architecture/BrowserProvisioningEntrypointTest.php-67- *
tests/Architecture/BrowserProvisioningEntrypointTest.php-68- * 順序は「コメント除去 → 行継続の畳み込み」。逆にすると、継続行の途中にある `#` の扱いが
--
tests/Architecture/BrowserProvisioningEntrypointTest.php-98- * `scripts/` 配下の shell スクリプトを **再帰的に** 列挙する (純関数)。
tests/Architecture/BrowserProvisioningEntrypointTest.php-99- *
tests/Architecture/BrowserProvisioningEntrypointTest.php-100- * `glob('scripts/*.sh')` では `scripts/tools/install-browser.sh` を取りこぼす
tests/Architecture/BrowserProvisioningEntrypointTest.php:101: * (ScriptsReadmeInventoryTest::scriptsDirectoryFiles と同じ理由・同じ道具を使う)。
tests/Architecture/BrowserProvisioningEntrypointTest.php-102- *
tests/Architecture/BrowserProvisioningEntrypointTest.php-103- * @return list<string> 引数ディレクトリからの相対パス (昇順)
tests/js/architecture/verification-commands-doc-sync.test.ts-10- *
tests/js/architecture/verification-commands-doc-sync.test.ts-11- * 免除は「理由付き」でしか書けない (EXEMPT)。免除エントリが package.json から消えたら
tests/js/architecture/verification-commands-doc-sync.test.ts-12- * 逆方向検査 (V3) が落ちる = stale 免除の残置を許さない
tests/js/architecture/verification-commands-doc-sync.test.ts:13: * (tests/Architecture/ScriptsReadmeInventoryTest.php と同じ作法)。
tests/js/architecture/verification-commands-doc-sync.test.ts-14- *
tests/js/architecture/verification-commands-doc-sync.test.ts-15- * 照合範囲は VERIFICATION_COMMANDS:BEGIN 〜 END の内側のみ。文書全体を検索すると
```
