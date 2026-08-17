# Round 5: Round 4 指摘への対応と再レビュー依頼

Round 4 の指摘 (Critical 1 / Warning 1 / Suggestion 1) にすべて対応しました。反論・見送りは 0 件です。

## 対応マトリクス

# 対応マトリクス: design-review Round 4

Codex 全体判定: **CHANGES_REQUESTED** (Critical 1 / Warning 1 / Suggestion 1)。
**すべてに対応した** (反論・見送りは 0 件)。

## [Critical] A-6 の母集団が `main...HEAD` なので未コミットの実装と結果ファイルを見ない
- 判断: **対応する** (Codex の修正案 1「検証前のチェックポイントコミット方式」を採る)
- 根拠: 正しい。作業ツリーに置いただけのファイルは `main...HEAD` に現れない。
  その状態で緑になると「最終成果物を検証していないのに合格」になる。
  修正案 2 (母集団を作業ツリー対応にする) は staged / unstaged / 未追跡の統合と
  実行中の出力更新の扱いが要るぶん複雑で、`main...HEAD` を保つ案 1 の方が単純である
  (rename 検出も安定する)。
- 対応内容: 検証手順に**段 0「作業ツリーが clean であること (`git status --porcelain` が空)」**を
  足し、実行順序を「空の結果ファイルごとコミット → 実行 → 結果を書いて `--amend` →
  もう 1 度実行して (i) 終了コード 0 / (ii) 結果ファイルが不変 / (iii) 実行後も clean」の
  4 段へ書き直した。

## [Warning] 「未分類は不合格」と「残りをすべて A-6a」は表現上矛盾している
- 判断: **対応する**
- 根拠: 正しい。無条件に A-6a へ落とすなら未分類は発生せず、新規ファイルは
  `git show main:<パス>` の失敗という遠回りな形でしか落ちない。分類の段で拒否すべきである。
- 対応内容: 状態の記号ごとの規則を表にした —
  `R` / `M` は明示一覧に無ければ A-6a、
  **`A` (新規) は A-6c か A-6e への明示登録を必須とし、無ければ未分類で不合格**、
  `D` (削除) は無条件で不合格。
  明示一覧どうしの重複と、一覧にあるのに差分へ現れないパスも不合格のまま残した。

## [Suggestion] 3-10 の候補集合を統一する理由の説明が正確でない
- 判断: **対応する**
- 根拠: 正しい。候補集合へ provider を足して増えるのは **provider 自身への参照の検出**であり、
  他の配線基盤クラスの検出可否はそのクラスが候補に入るかで決まる。
- 対応内容: 施策 4 の背景を「候補集合から配線 provider というクラスが 1 つ脱落すること自体が
  3-10 の見る集合を黙って狭める」という書き方へ改め、
  「将来 provider が別の配線基盤クラスを名指しした場合の検出範囲が広がる」という説明を消した。

## [Suggestion] 施策 1 / 2 / 3 の APPROVE と数値整合の確認
- 判断: **対応不要** (追認)

---

## 変更した箇所の抜粋 (詳細設計書より)

### A-6 の検証手順 (段 0〜段 5 と実行順序)

### A-6 の検証手順 (再実行できる形にする)

判定を文章の読み合わせにしない。**一時スクリプトを `devnotes/20260817-1309-todo-t214-bughunt-family-naming/`
配下に置いて実行する** (AGENTS.md「一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ」。
恒久化はしない — 1 回きりの改名の検証であり、`scripts/README.md` の台帳に載せる性質ではない)。

ファイル名: `verify-rename-only.php` (実行は `php devnotes/…/verify-rename-only.php`)

処理の流れ:

0. **作業ツリーが clean であることを確かめる** (`git status --porcelain` が空)。
   母集団は `main...HEAD` から取るので、**未コミットの変更は見えない**。
   汚れたまま実行すると「最終成果物を検証していないのに緑」になる (Codex 詳細レビュー
   Round 4 の Critical)。汚れていたら即不合格にする。
1. `git diff --name-status -M main...HEAD` を実行し、母集団を作る。
   - `R<類似度> <旧パス> <新パス>` の行から**改名の対応表**を取る。
   - `M <パス>` は旧パス = 新パス。`A <パス>` は新規。
2. 状態の記号ごとに分類の規則を分ける (Codex 詳細レビュー Round 4 の Warning への対応)。

   | 記号 | 規則 |
   |---|---|
   | `R` / `M` | A-6b / A-6e の明示一覧に載っていればそれ、載っていなければ **A-6a** |
   | `A` (新規) | **A-6c か A-6e への明示登録を必須**とする。どちらにも無ければ**未分類として不合格** (新規を A-6a へ落として `git show main:<パス>` の失敗に頼らない) |
   | `D` (削除) | **無条件で不合格** (本施策に削除は無い) |

   - **明示一覧どうしにパスの重複があれば不合格**。
   - **一覧にあるのに差分へ現れないパスがあっても不合格** (設計と実装のずれ)。
   - A-6e に入れてよいのは
     `devnotes/20260817-1309-todo-t214-bughunt-family-naming/` 配下と `docs/TODO.md` /
     `docs/TODO-closed.md` **だけ**で、それ以外のパスが A-6e に載っていたら不合格にする
     (メタ成果物という名目で本体の差分を逃がせないようにする)。
3. A-6a: 新内容へ逆置換 (`BughuntStripeSyncSeeder` → `BughuntBillingSeeder`、
   `BughuntFakesServiceProvider` → `FakeExternalsServiceProvider`) を掛け、
   `git show main:<旧パス>` と**バイト比較**する。1 バイトでも違えば不合格。
4. A-6b: 逆置換した新内容と `git show main:<旧パス>` を `token_get_all()` に掛け、
   `T_COMMENT` / `T_DOC_COMMENT` / `T_WHITESPACE` を落としたトークン列 (種別と字句の対) を作る。
   2 つの列の差分を取り、**許可する追加トークン列 2 つ**
   ((i) `array_keys ( FakeClassCatalog :: placementExceptions ( ) )` を含む 3-10 の候補集合の追加、
   (ii) 4-3 に足した `expect ( $candidates ) -> toContain ( … )` の 1 文) と一致しなければ不合格。
   **削除側のトークンは 0 個であること**も要求する (既存の検査を弱めていないことの担保)。
5. すべて通ったら終了コード 0。失敗したファイルと理由を全件出力する
   (最初の 1 件で止めない = 直す作業一覧になる)。

**出力は決定的にする** — 時刻・実行環境・コミットハッシュのような毎回変わる値を出力に含めない
(含めると次の段の「2 回目でも結果ファイルが変わらない」が成立しない)。

### 実行の順序 (母集団は必ずコミット済みの差分から取る)

母集団を `main...HEAD` から取る以上、**検証の前に必ずコミットする**
(Codex 詳細レビュー Round 3 / Round 4 の Critical への対応)。

1. 実装を終え、**空の `rename-verification.md` を置いたうえで全部コミットする**
   (A-6e の一覧に載っているパスが差分に現れる状態にする = 「一覧にあるのに差分に現れない」で
   落ちないようにする)。
2. `git status --porcelain` が空であることを確かめてからスクリプトを実行する。
3. 出力をそのまま `rename-verification.md` へ書き、**`--amend` で同じコミットへ畳む**。
4. **もう 1 度スクリプトを実行**し、(i) 終了コード 0、(ii) `rename-verification.md` の内容が
   3 で書いたものと同じ (出力が決定的なので変化しない)、(iii) 実行後も
   `git status --porcelain` が空、の 3 つを確認する。

この 4 段まで通って初めて A-6d を満たしたとする。作業ツリーを汚したまま緑にできる経路は
段 0 の clean 検査と段 4 の (iii) で塞ぐ。

> **保証範囲を誇張しない**: A-6 が示すのは「意図しない実行コード差分が無い」ことまでである。
> **振る舞いの同値性そのものを証明するものではない** (autoload・キャッシュ・
> リポジトリ外の実行手順・動的に組み立てるクラス名は対象外)。振る舞い側の担保は、
> 既存の Feature / Architecture テストと `composer test` 全体が受け持つ。

## 全検証コマンド (すべて green であること)

### 施策 4 の背景 (3-10 の候補集合を統一する理由の書き直し)

### 背景 (この施策が必要な理由)

`FakeClassCatalog` は fake 系クラスの母集団を**ハードコードせずに導出**している。

- 定義 1「fake 実装クラス」= `app/**/Fakes/` か `app/**/Testing/` 配下の全クラス
- 定義 2「fake 命名クラス」= クラス名が `Fake` で始まる or `Fake` で終わるクラス

現在の `FakeExternalsServiceProvider` は**定義 2 に当たる**ため、`placementExceptions()`
(定義 2 のうち定義 1 に属さなくてよい例外) に登録されている。
`FakeClassReferenceInvariantTest` の **4-3 (本番コードは fake クラスを参照しない)** は、
候補集合を `implementationClasses() ∪ placementExceptions() のキー` としており、
その docblock は「配置例外を業務コードが参照しても検出できず**偽グリーン**になるため」と
理由を書いている。

**改名すると `BughuntFakesServiceProvider` は定義 2 に当たらなくなる** (先頭も末尾も `Fake` でない)。
帰結は 2 つある。

1. `placementExceptions()` から entry を落とすと、**配線 provider が 4-3 の候補集合から消え、
   業務コードが配線点を直接参照しても検出できなくなる** = docblock が名指しで警告している
   偽グリーンを自分で作ることになる。したがって **entry は残し、docblock の意味を書き直す**。
2. `ExternalFakeWiringInvariantTest` の **3-10** (provider が参照する fake 系クラスは配線基盤
   4 件ちょうど) は候補集合を `implementationClasses() ∪ namedClasses()` で作っているため、
   **改名すると provider 自身が候補から静かに落ちる**。いまの結果は変わらない
   (走査器は `isDeclarationName` のトークンを飛ばすので、クラス宣言名は参照として数えない
   — `FakeWiringSourceScanner` の docblock と実装で確認済み) が、
   **候補集合から配線 provider というクラスが 1 つ脱落する**こと自体が、
   3-10 が見ている集合を黙って狭める (Codex 詳細レビュー Round 4 の Suggestion に従い、
   「別の配線基盤クラスの検出まで広がる」という説明はしない。他クラスの検出可否は
   そのクラス自身が候補に入るかで決まる)。
   これは検査の網が縮む変化なので、候補集合を
   `implementationClasses() ∪ namedClasses() ∪ placementExceptions() のキー` へ揃える
   (Codex 詳細レビュー Round 1 の Critical への対応)。
   **これはアプリの振る舞いの変更ではなく、改名で縮む網を元の広さに戻す変更である。**

### 変更箇所

---

## 再レビューの依頼

最終判定をお願いします。Round 4 の Critical (未コミット差分を見ない) と Warning (分類規則の矛盾) が閉じているか、他に実装前に閉じるべき Critical / Warning が残っていないかを見てください。残る指摘が Suggestion だけであれば全体判定 APPROVED としてください。
