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

### 旧検査が見ていた 4 つの性質の行き先 (落とすものと移すものを分ける)

| 旧検査 | 見ていた性質 | 撤去後の行き先 |
|---|---|---|
| S1 | 全ファイルが表に行を持つ (未記載) | **移す** — 施策 2 の形態 A (`comm -23`) |
| S2 | 表の全行に実ファイルがある (残骸) | **移す** — 施策 2 の形態 A (`comm -13`) |
| S3 | 用途・実行タイミングの列が空でない | **移す** — 施策 2 の形態 A の空欄検出 (`awk`) |
| S4 | 除外登録が実在ファイルを指し理由が非空 | **落とす** — 除外の仕組みそのものを廃止し、除外を `scripts/README.md` の宣言で **本書自身の 1 件に固定**する。登録簿が無いので死んだ除外も生まれない (施策 3) |

移した 3 つは**強制力が落ちる** (毎 push → 文書更新を回したとき)。この差は「保証しないもの」に書く。

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

#### 形態 A: 網羅性 (双方向の差集合) と列の空欄

貼って実行する形なので、全体を `(` `)` で囲んで呼び出し元のシェルに変数も `trap` も残さない。

```bash
(
WORK=$(mktemp -d) || exit 1     # 失敗を素通りさせない ($WORK が空だと / 直下へ書きに行く)
trap 'rm -rf -- "$WORK"' EXIT

# 台帳として扱う範囲を「## スクリプト一覧」からファイル末尾までに限定する。
# この範囲には台帳以外の表を置かないこと (置くと台帳の行として数えられる)。
sed -n '/^## スクリプト一覧$/,$p' scripts/README.md > "$WORK/table.md"

git ls-files scripts/ | grep -v '^scripts/README\.md$' | sort > "$WORK/tracked.txt"
sed -n 's/^| `\([^`]*\)`.*/scripts\/\1/p' "$WORK/table.md" | sort > "$WORK/listed.txt"

echo "追跡下: $(wc -l < "$WORK/tracked.txt") / 表の識別子: $(wc -l < "$WORK/listed.txt")"
echo '--- 未記載 (実体にあるが表に無い) ---'; comm -23 "$WORK/tracked.txt" "$WORK/listed.txt"
echo '--- 残骸 (表にあるが実体に無い) ---';   comm -13 "$WORK/tracked.txt" "$WORK/listed.txt"
echo '--- 重複した識別子 ---';                uniq -d "$WORK/listed.txt"

# 用途 (第 2 列) と実行タイミング (第 3 列) が空の行、および列数が 3 でない行。
echo '--- 空欄・書式不正 ---'
awk -F'|' '/^\| `/ {
  id = $2; purpose = $3; timing = $(NF - 1);
  gsub(/`/, "", id);
  gsub(/^[ \t]+|[ \t]+$/, "", id); gsub(/^[ \t]+|[ \t]+$/, "", purpose); gsub(/^[ \t]+|[ \t]+$/, "", timing);
  if (NF != 5)                             printf "書式不正 (セル数 %d): %s\n", NF - 2, id;
  else if (purpose == "" || timing == "")  printf "空欄: %s\n", id;
}' "$WORK/table.md"
)
```

- **両向きを必ず測る**。片側の差集合だけを見て「欠落 0 件」と判断しないこと
  (家系の別リポジトリで実際に起きた読み違いである)。
- 未記載が出たら表へ 1 行足す。残骸が出たら行を消す。重複が出たら 1 行に畳む。
  **同じパスが重複と残骸の両方に出たら、まず重複を畳んでから読み直す**
  (`comm` は重複行を差分として数えるため、重複がある間は残骸側にも同じパスが並ぶ)。
- 空欄が出たら埋める (**用途と実行タイミングが書けないスクリプトは昇格しない**、が規約である)。
  「書式不正」はセル内に区切り記号が入った行でも出るので、出たら目で見て判断する。

#### 形態 B: 記述の実態ずれ

表の「用途」「実行タイミング」が実装と食い違っていないかを実ファイルで裏取りする。
とくに **「〜から自動呼び出し」と書かれた行は、呼び出し元を実際に grep して確かめる**
(過去に、どこからも呼ばれていないスクリプトが「CI から自動呼び出し」と書かれたまま残っていた)。

```bash
grep -rn "<スクリプト名>" . \
  --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=devnotes
```

- **`scripts/README.md` 自身のヒットは呼び出し元ではない** (台帳の主張そのものなので、
  これを根拠に「呼ばれている」と判断しない)。同様に、説明コメントやテストの文字列だけの一致も
  呼び出しではない。**実行されている記述 (`package.json` の script / CI の job / 他スクリプトの
  実行行 / hook の配線) に当たっているかで判断する。**
- ずれていたら **README 側を実態に合わせる** (実装を README に合わせない)。
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
> - **用途 (第 2 列) と実行タイミング (第 3 列) を空にしない**。書けないスクリプトは昇格しない。
> - 台帳の表は **「## スクリプト一覧」より下の 1 つだけ**。
>   別の表を足すときはこの節より**上**に置き、第 1 列をバッククォートの識別子にしない
>   (照合が別の表を巻き込むため)。
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
3. 複製の表の 1 行を複製する → **重複した識別子 1 件**を出すこと
4. 複製の表の 1 行の実行タイミング列を空にする → **空欄 1 件**を出すこと

実行記録 (コマンドと出力) は `devnotes/20260817-1309-todo-t210-scripts-readme-gate-removal/verification.md`
へ残す。実装ブランチのコミットに含める。

> **設計時に空撃ちしていないことを確認済み**: 上のコマンド列と 4 ケースの負のコントロールは、
> 設計時に作業用ディレクトリの複製の上で 1 度実走してある (作業ツリーには触れていない)。
> 現行の台帳では未記載 0 / 残骸 0 / 重複 0 / 空欄 0、崩した複製では 4 ケースとも意図どおり 1 件ずつ検出した。
> 実装時にも同じ手順を回して `verification.md` へ残す (設計時の実測は設計の裏取りであって完了条件ではない)。

## 受け入れ条件 (機械検証可能)

| # | 条件 | 検証コマンド | 期待 |
|---|---|---|---|
| A1 | 検査ファイルが存在しない | `test ! -e tests/Architecture/ScriptsReadmeInventoryTest.php` | exit 0 |
| A2 | 履歴以外に名前の参照が残っていない | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | ヒット 0 件 (exit 1) |
| A3 | スキルに段と裁定の根拠が入っている | 下記 A3〜A5 の判定スクリプト | exit 0 |
| A4 | 段が双方向の差集合・空欄検出・実態裏取りを持つ | 同上 | exit 0 |
| A5 | README の宣言と AGENTS.md の一文 | 同上 | exit 0 |
| A6 | 台帳が実態と一致している (実走) | 施策 2 の形態 A のコマンド列 | 追跡下 = 表の識別子数、未記載 0 件 / 残骸 0 件 / 重複 0 件 / 空欄・書式不正 0 件 |
| A7 | 負のコントロールが効く | `verification.md` に 4 ケースの実行記録がある | 未記載 1 / 残骸 1 / 重複 1 / 空欄 1 を検出 |
| A8 | 全検証コマンドが green | 下記「全検証コマンド」 | すべて green |

### A3〜A5 の判定スクリプト (1 語ずつ確かめる)

`grep` の OR 検索は 1 語でも当たれば成功してしまい「全項目の存在」を終了コードで表せない。
1 語ずつ `grep -q` して、欠けた語を名指しで落とす。

```bash
(
fail=0
check() {  # check <file> <語> ...
  file=$1; shift
  for pattern in "$@"; do
    grep -qF -- "$pattern" "$file" || { echo "欠落: $file に「$pattern」が無い"; fail=1; }
  done
}

check .claude/skills/app-update-docs/SKILL.md \
  '2-1. scripts/ 台帳の整合確認' 'scope 引数によらず' '機械検査へ昇格させない' \
  'AG-076b' 'AG-192' '形態 A' '形態 B' 'comm -23' 'comm -13' '空欄'
check scripts/README.md '台帳の対象範囲' 'CI で落ちる検査にしない' 'app-update-docs'
check AGENTS.md 'CI で落ちる検査にしない'
exit "$fail"
)
```

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
- **照合は `## スクリプト一覧` からファイル末尾までを見る**。この節より**下**に別の表を置き、
  その第 1 列をバッククォートの識別子にすると台帳の一部と誤認される
  (README の宣言で「別の表はこの節より上に置く」と定めているが、機械では止まらない)。
- **除外の仕組みは無くなる**。旧検査は理由付きの除外登録 (S4) を持っていたが、撤去後は
  除外を「本書自身の 1 件」に固定する宣言だけになる。理由付きで 2 件目を外す手段は無い
  (必要になったら宣言そのものを直す議論をする)。
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
