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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

# system: コードレビュアー

あなたは Laravel + Svelte アプリの改善実装をレビューするコードレビュアーである。
本 diff は **インフラ / 運用スクリプト層**の変更 (git index 是正 + テスト DB の孤児回収経路) であり、
UI / API の変更を含まない。したがって DESIGN.md / Atomic Design 観点は該当しない (該当なしと明記してよい)。

## レビュー観点

1. **設計との一致性**: 詳細設計 (施策 C1 / C2) の承認条件を実装が満たしているか。
   特に「概念設計 Round 5 の承認条件」= (a) 分類優先順位 Protected→Live→Foreign→Orphan→Unlabeled、
   (b) `--apply` は LLM が実行しない契約を usage / AGENTS.md / scripts/README.md の 3 箇所に明記、
   (c) `Orphan` / `Unlabeled` の両方が `--include-hash` の明示指定制で一括フラグを持たない
2. **正確性・安全性**: dev DB (`app`) / bug-hunt DB へ破壊操作が届く経路が無いか。
   token 照合 → lock → DROP の順序に TOCTOU が残っていないか。lock の解放漏れ・
   例外経路での lock 保持漏れ。`git rm --cached` 由来の index 差分が意図どおりか
3. **PHPStan level 10 適合性**: 境界での `mixed` 正規化、null 安全、generics
   (`list<...>`) の正しさ。**widen / baseline / @phpstan-ignore は禁止**
4. **テスト網羅性**: 詳細設計のテスト計画 (T-C2-1〜T-C2-22 / N1〜N7) を実装がカバーしているか。
   偽グリーン (skip で逃げる / 正コントロールが無い) になっていないか
5. **セキュリティ**: SQL のクォート (識別子 / リテラル)、コメント (provenance) を
   信頼境界として扱っていないか、細工された provenance で生存 DB が落ちないか
6. **不必要な複雑化**: 「今必要なものだけ作る」に反する追加が無いか

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で明記する

---

# user

## 詳細設計書 (施策 C1)

# 施策 C1: `doc/reference/` の NFC/NFD 重複解消 + 再発防止ゲート

### 変更箇所

- git index（`doc/reference/` の NFD 形 entry **58 件**を除去）
- `.git/config` の `core.precomposeunicode`（ローカル設定。**リポジトリ恒久対策ではない**）
- `docs/worktree-isolation-strategy.md`（背景と再発防止の記述）
- **新規** `tests/Architecture/GitIndexNormalizationTest.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規ゲート 1 本
- **作業ツリーのファイル**: **0 件**（`git rm --cached` は index のみを操作する）
- **コードからの参照**: `doc/reference/sample-sop/`（`tests/Unit/Manual/SopTextExtractorTest.php:38` が
  `base_path("doc/reference/sample-sop/{$name}")` で参照）は **NFD entry に 1 件も含まれない**
  （NFD の内訳は `mockups` 57 / `scenarios` 1）

### 現状（実測 2026-08-05 18:13 JST / main = `c490de0`）

| 指標 | 値 | 算出方法 |
|---|---|---|
| index entry 総数 | **197** | `git ls-files doc/reference \| wc -l` |
| 作業ツリーの実体 | **139** | `find doc/reference -type f \| wc -l` |
| NFC 正規化衝突グループ | **58**（全て size 2） | `git ls-files -z` を `unicodedata.normalize('NFC', p)` でグルーピング |
| blob が異なるグループ | **0 件** | 各グループ内の blob hash 集合サイズ |
| NFC 形 entry を持たないグループ | **0 件** | グループ key（NFC path）が entry として実在するか |
| 197 − 58 | **139** | 実体数と一致 |
| `core.precomposeunicode` | **false** | `git config --get core.precomposeunicode` |

> **注意**: 監査レポートの「重複 blob **55**」は**別指標**である
> （index 内で 2 回以上出現する distinct blob hash 数。出現内訳は 2 回×42 / 3 回×3 / 4 回×7 / 6 回×3 で、
> NFC/NFD 由来でない「同一内容・別名」も数える。distinct blob 総数は 113）。
> **本施策の判定に使うのは 58 の側**であり、両者は矛盾しない。

### 安全性の根拠（概念設計 Round 2 で訂正した論拠）

**「`git rm --cached` は作業ツリーを触らないから安全」は根拠として不十分**である
（index から落とした entry はコミット後、他環境の checkout からは消えるため）。

正しい根拠は「**落とす NFD entry の内容が、残す NFC entry に同一 blob で保存されていること**」。
これを上表の 4 条件で確認済みであり、**実装時にも事前確認として再検証し、
1 つでも崩れたら中止する**。

`git rm --cached` が作業ツリーを壊さないことは、**作業中断時の回復を容易にする副次的性質**として扱う。

### 手順（C1 → C2 の 2 段階）

**前提**: AGENTS.md §worktree 運用ルールに従い、**task worktree の branch/index で完結させる**
（main 直接実装はしない）。worktree 上で完結できないことが実証された場合は、
通常の contingency ではなく**人間の明示的な例外承認を要する停止条件**として扱う。

#### C1（検証 + manifest 生成。index を一切変更しない）

```bash
# 0) 実行前の記録 (ロールバックの基点)
git rev-parse HEAD                                  # → BASE_SHA を控える
git status --porcelain=v1 -uall                     # → 空であること
git worktree list                                   # → 想定どおりの worktree のみ
git config --get core.precomposeunicode             # → 現在値を控える (false)
git ls-files -s doc/reference > devnotes/{dir}/index-before.txt   # index のフルバックアップ (R4 用)
# NFC(path) -> "<mode> <blob> <stage>" の map (V-C4 用。NUL 区切りで生成する)
git ls-files -s -z doc/reference | python3 <NFC 正規化スクリプト> > devnotes/{dir}/index-map-before.txt
#   → 197 entry から **139 key** になること / 同一 key に異なる値が現れないこと (現れたら中止)

# 1) 事前確認 4 条件 (1 つでも崩れたら中止)
#    - index entry 総数 197
#    - NFC 正規化衝突グループ 58 / 全て size 2
#    - blob が異なるグループ 0 件
#    - NFC 形 entry を持たないグループ 0 件
#    - 197 - 58 == 139 == find doc/reference -type f | wc -l
#    (nfd-index-entries.txt の manifest と一致することも確認する)

# 2) 削除対象 manifest の再生成 (devnotes/{dir}/nfd-index-entries.txt と一致すること)
#    NUL 区切りの pathspec ファイルも生成する (日本語 + 結合文字の path を安全に渡すため)
```

#### C2（適用）

```bash
# 3) index から NFD entry のみを除去する。--cached なので作業ツリーは触らない。
git rm --cached --quiet --pathspec-from-file=<NUL 区切りファイル> --pathspec-file-nul

# 4) 直後の検証 (すべて満たすこと)
git ls-files doc/reference | wc -l          # → 139
find doc/reference -type f | wc -l          # → 139 (作業ツリーの実体は 1 つも消えていない)
git status --porcelain=v1 -uall
#   機械判定 (3 条件すべてを満たすこと):
#     (a) '^D ' で始まる行 (staged deletion / 列 2 = 空白) が **ちょうど 58 件**
#     (b) **列 2 が空白でない行が 0 件** (unstaged 変更が無い = 作業ツリーを触っていない)
#     (c) '^\?\?' の行が **0 件** (untracked が生まれていない)
#   NFC(path) -> "<mode> <blob> <stage>" の map を **NUL 安全**に生成して施策前後で比較する
#   (blob 集合だけでは path 消失を検出できない。同一 blob が複数 path にあるため)
#     git ls-files -s -z doc/reference | python3 -c '...unicodedata.normalize("NFC", path)...' \
#         > devnotes/{dir}/index-map-after.txt
#   施策前も同じスクリプトで index-map-before.txt を作っておく (手順 0)。
#   ※ before の生成時、**同一 NFC key に異なる値 (mode/blob/stage) が現れたら即中止**する
#     (blob 不一致 0 件の事前確認と同じ性質を map 生成側でも fail-fast させる)
#   → index-map-before.txt (139 key) と index-map-after.txt (139 key) が **完全一致**すること

# 5) 正規化衝突が 0 になったことを確認 (新設ゲートと同じ判定)
#    → NFC 正規化衝突グループ 0 件

# 6) コミット (ゲート追加と同一コミットにする = 「直したが検査は無い」状態を作らない)
```

**任意の補助手順（受入条件にもロールバックにも含めない）**:

```bash
git config core.precomposeunicode true
```

`core.precomposeunicode` は **`.git/config` のローカル設定**であり、
clone した各人が設定しない限り効かない = **リポジトリの恒久対策にはならない**。
実装者の環境差を受入条件に持ち込まないよう、受入条件（V-C1〜V-C7）とロールバック（R1〜R4）から
**外す**。恒久対策は index 正規化 + `GitIndexNormalizationTest` に限定する
（`docs/worktree-isolation-strategy.md` には「各自 `true` にしておくと再発を緩和できる」と
補助情報として書く）。

#### 検証（受入条件）

| # | 条件 | 確認方法 |
|---|---|---|
| V-C1 | index entry = **139** | `git ls-files doc/reference \| wc -l` |
| V-C2 | 作業ツリー実体 = **139**（**減っていない**） | `find doc/reference -type f \| wc -l` |
| V-C3 | NFC 正規化衝突 = **0** | `GitIndexNormalizationTest` |
| V-C4 | **`NFC(path) → "<mode> <blob> <stage>"` の map が施策前後で完全一致** | `index-map-before.txt`（197 entry → **139 key**）と `index-map-after.txt`（139 entry → 139 key）を `diff` して**差分 0**。※blob **集合**の比較では path 消失を検出できない（同一 blob が複数 path にある。実測: 2 回×42 / 3 回×3 / 4 回×7 / 6 回×3）ため、必ず path→値の map で比較する |
| V-C5 | コミット**前**は `^D ` が 58 件 / 列 2 が非空白の行 0 件 / `^??` 0 件。コミット**後**は `git status --porcelain=v1 -uall` が**空** | 同左 |
| V-C6 | **worktree ラウンドトリップ**: `setup-worktree.sh` → 何もせず `teardown-worktree.sh` が **成功する**（dirty チェックを通る） | 実走。これが施策 C2 の前提条件でもある |
| V-C7 | `tests/Unit/Manual/SopTextExtractorTest.php` が green（`sample-sop` の参照が生きている） | `composer test` |

#### ロールバック方法

| 段階 | 状況 | ロールバック手順 |
|---|---|---|
| **R1** | C2 の手順 3〜5 の途中（**未コミット**） | `git reset HEAD -- doc/reference` で index を復元（**`--hard` ではない。作業ツリーに触れない**）→ `git status --porcelain=v1 -uall` が空になることを確認。**作業ツリーのファイルは一度も触っていないので復元不要** |
| **R2** | コミット済み・**未マージ**（task branch 上） | **`git revert <commit>`**（履歴を残す非破壊のロールバック）を原則とする。直前コミットのみを取り消す場合は `git reset --soft HEAD^`（**作業ツリーに触れない**）。**`git reset --hard` は使わない** — task branch 上でも未コミットの作業を消しうるため、必要な場合は**人間の明示承認を得てから**実行する |
| **R3** | main へマージ済み | `git revert <merge-commit> -m 1` で index entry が復活する（blob は object DB に残っているため内容も完全に戻る） |
| **R4** | 最終手段（index が壊れた） | `git update-index --index-info < devnotes/{dir}/index-before.txt` で **手順 0 で保存した index を丸ごと再構成**する（作業ツリーは触らない） |

> **ロールバック手順に破壊的コマンドを既定で置かない**: ロールバックは*復元*操作であり、
> それ自体が新しい損失を生んではならない。`--hard` 系は既定手順から外し、
> 人間の明示承認を要する例外に限定する。

**ロールバックが安全である理由**: 本施策は blob を 1 つも削除しない（entry の付け替えのみ）。
`index-before.txt` が全 entry の `<mode> <blob> <stage>\t<path>` を保持しているので、
最悪でも手順 0 時点の index を機械的に再構成できる。

### 新規ゲート `GitIndexNormalizationTest` の設計

```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * git index に **NFC 正規化で衝突する path** が無いことを deny-by-default で固定する。
 *
 * なぜ必要か: doc/reference/ に NFD 形と NFC 形の entry が両方載っており
 * (index 197 に対し実体 139)、正規化非依存 lookup の FS では 1 ファイルに潰れる。
 * worktree では「削除済み扱いの NFD entry + untracked 扱いの NFC ファイル」が現れて
 * teardown-worktree.sh の dirty チェックを**常に fail** させ、
 * `git worktree remove --force` による迂回 → drop-test-db.php を通らない →
 * **孤児テスト DB が単調増加**する、という運用事故の起点になっていた
 * (tech-debt.md §5-3 / §5-4)。
 *
 * `core.precomposeunicode` は**ローカル設定**であってリポジトリの恒久対策にならない
 * (clone した各人が設定しない限り効かない)。恒久対策は本ゲートである。
 *
 * 本テストは DB を触らない (git index の読み取りのみ)。
 */

/**
 * NFC 正規化して衝突する path のグループを返す (純関数)。
 *
 * @param  list<string>  $paths  index 上の path 一覧
 * @return array<string, list<string>>  NFC path => 衝突している元 path 群 (2 件以上のみ)
 */
function gitIndexNormalizationCollisions(array $paths): array
{
    $byNfc = [];
    foreach ($paths as $path) {
        $nfc = Normalizer::normalize($path, Normalizer::FORM_C);
        Assert::string($nfc, "path を NFC 正規化できない: {$path}");
        $byNfc[$nfc][] = $path;
    }

    return array_filter($byNfc, static fn (array $g): bool => count($g) > 1);
}
```

| ID | 内容 | 期待 |
|---|---|---|
| N1 | git index 全体に NFC 正規化衝突が **0 件** | pass |
| N2 | **空振り防止**: 読み取った index entry 数が **50 件以上** かつ **代表 path（`AGENTS.md` / `composer.json`）が含まれる**（規模連動の高い閾値は将来の偽赤になるため、代表 path の存在検査を主にする） | pass |
| N3 | `git ls-files -z` の実行が**成功する**（失敗したら skip せず **fail**。偽グリーン禁止） | pass |
| N4 | 正コントロール: NFD/NFC ペアを含む合成 path 配列で衝突を検出 | **1 グループ検出** |
| N5 | 正コントロール: 3 件衝突（NFC + NFD 2 種）を検出 | **1 グループ / 3 要素** |
| N6 | 負コントロール: 純 NFC のみの配列 | 0 件 |
| N7 | 負コントロール: 日本語を含むが正規化しても衝突しない path 群 | 0 件 |

**intl 依存**: `Normalizer` クラスの可用性は確認済み
（`php -r 'var_dump(extension_loaded("intl"), class_exists("Normalizer"));'` → `true` / `true`）。
CI の `shivammathur/setup-php` は intl を既定で含むが、**N3 と同じ思想で
「`Normalizer` が無ければ skip ではなく fail」**とする（偽グリーンを作らない）。

**`git` 実行方法**: `exec('git ls-files -z', $out, $rc)` ではなく、
NUL 区切りを正しく扱うため `shell_exec` ではなく **`proc_open` / `Symfony\Component\Process`**
（Laravel 同梱）を使い、`getOutput()` を `explode("\0", ...)` する。
`$rc !== 0` なら `Assert::same(0, $rc, ...)` で明示的に fail させる。

### docs の追記（`docs/worktree-isolation-strategy.md`）

teardown が常時失敗していた経路と、その恒久対策（本ゲート）を短く記録する
（「なぜこのゲートがあるか」を散文で辿れるようにする。docs-freshness.md §6 の作法）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`array<string, list<string>>`）
- [x] null 安全（`Normalizer::normalize()` は失敗時 `false` を返すため `Assert::string()` で潰す）
- [x] DTO を返している（Architecture テストの純関数なので配列 shape で表現）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] バグ修正の再現テスト: **N1 を先に書き、現行 index（衝突 58 件）で赤くなることを確認**してから index を直す（テストファースト）
- [x] 新規テスト: `GitIndexNormalizationTest`（N1〜N7）
- [x] 既存テストの更新: なし
- [x] 実運用の受入テスト: **V-C6（setup → teardown のラウンドトリップ成功）**
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| 正規化に敏感な FS（素の ext4 等）では NFD/NFC が**別ファイル**になるため、他環境の clone で 58 ファイルが減る | 減るのは**重複していた同一内容のコピー**だけ（blob 一致 58/58 を実測済み）。参照しているコード・テストは 0 件（`sample-sop` は対象外） |
| `git rm --cached` の pathspec が結合文字を含み、シェル経由で壊れる | **`--pathspec-from-file` + `--pathspec-file-nul`** で NUL 区切りファイルから渡す（シェルの引数展開を通さない） |
| worktree 作成時点で phantom deletion が出て作業できない | 事前確認で「worktree の dirty 集合が想定 NFD 集合と一致すること」を検査する。一致しなければ**中止して人間へ報告**（勝手に main へ逃げない） |
| ゲートが他の（正当な）NFD path を将来ブロックする | それが目的（deny-by-default）。正当な理由で NFD が必要になったら、そのとき例外機構を設計する（今は必要ない = 思考原則 2） |
| `Normalizer` 不在環境で fail する | intl の可用性を確認済み。CI も含む。skip にすると偽グリーンになるので fail を選ぶ |

---

# 施策 C2: 孤児テスト DB の回収経路

### 変更箇所

- `scripts/ci/pgsql_test_conn.php`（`pgsqlCommentDatabaseSql()` を追加）
- `scripts/ci/ensure-test-db.php`（CREATE 直後に `COMMENT ON DATABASE` を実行）
- `scripts/ci/drop-test-db.php`（`--orphans` / `--apply` / `--confirm` / `--protect-hash` / `--include-hash`）

## 詳細設計書 (施策 C2)

# 施策 C2: 孤児テスト DB の回収経路

### 変更箇所

- `scripts/ci/pgsql_test_conn.php`（`pgsqlCommentDatabaseSql()` を追加）
- `scripts/ci/ensure-test-db.php`（CREATE 直後に `COMMENT ON DATABASE` を実行）
- `scripts/ci/drop-test-db.php`（`--orphans` / `--apply` / `--confirm` / `--protect-hash` / `--include-hash`）
- **新規** `tests/Support/Ci/TestDatabaseCandidate.php` / `TestDatabaseClassification.php` / `TestDatabaseDecision.php`
- `tests/Support/Ci/TestDatabaseEnv.php`（denylist に `bug_hunt*` を追加 + 分類の純関数）
- `scripts/teardown-worktree.sh`（dirty 失敗メッセージに回収導線を追記）
- `scripts/README.md`（`drop-test-db.php` の行を更新。`ScriptsReadmeInventoryTest` が整合を強制）
- `AGENTS.md` §worktree 運用ルール（強制撤去したときの回収手順 + `--apply` の運用条件）
- **新規** `tests/Unit/Ci/TestDatabaseClassificationTest.php`

### 現状（実測 2026-08-05 18:13 JST）

`git worktree list` は `/workspace` のみ（hash `8af22c44`）。`pg_database` の実測:

| DB 群 | 個数 | 判定 |
|---|---|---|
| `app` | 1 | **dev DB（絶対に触らない）** |
| `app_test_8af22c44` + `_test_1..4` | 5 | **生存**（`/workspace`） |
| `app_test_3a7d6b4e` + `_test_1..4` | 5 | 孤児 |
| `app_test_823cbbd2` + `_test_1..4` | 5 | 孤児 |
| `app_test_b4f0102e` + `_test_1..4` | 5 | 孤児（**今サイクルで新規発生**） |
| `app_test_018d63c6` | 1 | 孤児 |
| `app_test_91c7197b` | 1 | 孤児 |
| **孤児 合計** | **17** | **221.9 MB** |

### 根本原因（コードで確認）

`scripts/teardown-worktree.sh` の実行順は
**(2) dirty チェック（L69-81、失敗で `exit 1`） → (4) DB 回収（L95-99） → (5) worktree 削除**。
`doc/reference/` の NFC/NFD 重複（施策 C1）により dirty チェックが**必ず fail** するため、
**(4) に到達しない**。開発者は `git worktree remove --force` で迂回し、
`drop-test-db.php` を通らないまま worktree が消える → **孤児 DB が残る**。

さらに `drop-test-db.php` は base 名を `TestDatabaseEnv::pgsqlBaseDatabase($projectRoot)`
= **projectRoot の realpath から算出**するため、**worktree が既に消えている孤児には使えない**
（projectRoot が存在しないので hash を再現できない）。これが「掃除コマンドが無い」理由。

### 設計方針（生 DDL を新設しないこと）

- **DROP の実行責務を既存ファイルから分散させない**: DROP DDL を実行するのは
  `scripts/ci/drop-test-db.php` の 1 本のままとし、`--orphans` は
  「**どの DB を落とすかを決める入口**」を足すだけ。DROP の実装（`pgsqlDropDatabaseSql()` +
  `isDevDatabase()` / `isAllowedTestDatabase()` の再検証ループ）は既存コードを共有する
- 追加する DDL は `ensure-test-db.php` から実行する**非破壊の `COMMENT ON DATABASE` のみ**
- 孤児の列挙は **SELECT のみ**（`pg_database` + `shobj_description`）

### 出自の機械記録（provenance）

DB 名の hash だけでは「削除済み worktree の残骸」と「**同一 PostgreSQL を共有する
別クローンの生存 DB**」を区別できない。「由来不明は除外」を素直に適用すると
**現存 17 個はすべて由来不明なので 1 つも掃除できず施策が無意味になる**。
したがって**区別できるようにする** — PostgreSQL 標準の `COMMENT ON DATABASE` を使う。

```php
// scripts/ci/pgsql_test_conn.php に追加
/**
 * allowlist 検証済み DB 名に、出自 (worktree の realpath) を記録する COMMENT 文を生成する。
 *
 * 孤児 sweep が「削除済み worktree の残骸」と「別クローンの生存 DB」を区別するための
 * **分類材料**。信頼境界ではない (誰でも書き換えられるため単独では guard にならない)。
 * 識別子は pgsqlQuoteIdentifier、リテラルは呼び出し側で PDO::quote する。
 */
function pgsqlCommentDatabaseSql(PDO $pdo, string $name, string $provenance): string
{
    return 'COMMENT ON DATABASE '.pgsqlQuoteIdentifier($name).' IS '.$pdo->quote($provenance);
}
```

**ラベル付与は冪等にする（作成時だけでなく既存時も更新する）**。
現行の `ensure-test-db.php` は base が既にあれば `exit 0` する（L40-43）ため、
**そのままだと既に存在する生存 base DB に provenance が付かない**。
ラベルの無い現役 DB を作らないよう、両経路でラベルを付け直す:

```php
// scripts/ci/ensure-test-db.php
// 出自を記録/更新する (非破壊)。孤児 sweep の**分類材料**であり guard ではない。
// 既存 DB でも必ず通す (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
$exists = $stmt->fetchColumn() !== false;
$provenance = (string) realpath($projectRoot);

// plan は **action の列** を返す純関数 (PDO に触れない)。SQL 文字列を返さないのは、
// COMMENT のリテラルクォートに PDO::quote() が要る = 純関数では安全な SQL を作れないため
// (provenance path に ' が含まれうる。独自連結は不可)。
foreach (testDatabaseEnsurePlan($exists) as $action) {
    match ($action) {
        TestDatabaseEnsureAction::Create => $pdo->exec(pgsqlCreateDatabaseSql($base)),
        // best-effort: ラベルは分類材料であって必須ではない。ここで落とすと
        // 権限設定の差でテスト実行そのものが止まり、偽赤を増やす。
        TestDatabaseEnsureAction::StampProvenance => pgsqlStampProvenance(
            static fn (string $s): mixed => $pdo->exec($s),
            pgsqlCommentDatabaseSql($pdo, $base, $provenance),
        ),
    };
}
fwrite(STDERR, $exists
    ? "ensure-test-db: base DB already exists: {$base}\n"
    : "ensure-test-db: created base DB: {$base}\n");
exit(0);
```

**PDO 境界を注入可能にする**（design-review R2 [Warning]。「両分岐が実際に stamp を呼ぶ」
「例外時に exit 0 で続行する」を SQL 文字列の検証ではなく**実行で**証明するため）。
**plan は SQL ではなく action を返す**（design-review R3 [Warning]。
クォート責務を既存の `pgsqlCommentDatabaseSql($pdo, ...)` に残したまま、計画を純粋に保つ）:

```php
// scripts/ci/pgsql_test_conn.php

/** ensure が行う操作。SQL 生成はしない (クォート責務は既存の SQL ビルダに残す)。 */
enum TestDatabaseEnsureAction
{
    case Create;
    case StampProvenance;
}

/**
 * ensure が実行すべき action 列を返す (純関数。PDO にも SQL にも触れない)。
 *
 * **両分岐とも StampProvenance を含む**のが契約: 既存 DB のとき省くと
 * 「ラベルの無い現役 DB」が生まれ、将来の孤児 sweep の分類材料が欠ける。
 *
 * @return list<TestDatabaseEnsureAction>
 *         $exists=false → [Create, StampProvenance] / $exists=true → [StampProvenance]
 */
function testDatabaseEnsurePlan(bool $exists): array;

/**
 * provenance ラベルを best-effort で実行する。`$exec` を注入するので PDO 無しでテストできる。
 *
 * @param  callable(string): mixed  $exec
 * @return bool  成功したか (失敗時は false + stderr へ warning。例外は伝播させない)
 */
function pgsqlStampProvenance(callable $exec, string $sql): bool;
```

> **comment は base DB にのみ付く**。paratest の worker DB（`_test_N`）は Laravel の
> `ParallelTesting` が作るため comment を持たない。**hash グループ全体の出自を base の
> comment で代表させる**。base が不在で worker だけが残っている場合は **unlabeled** になる。

**ラベル付与失敗を fail-closed にしない理由**（および、それでも安全である理由）:
「COMMENT 失敗時に作成した base DB を DROP して失敗させる」案は、
**`ensure-test-db.php` に DROP DDL を持ち込む**ことになり、本設計の中核方針
（DROP の実行責務を `drop-test-db.php` から分散させない）を壊す。
かつテスト前処理が権限差で落ちるようになり、**偽赤を増やす**。
危険の本体は「ラベルが無いこと」ではなく
「**ラベルの無い DB がフラグ 1 つで一括 DROP されうること**」なので、
そちらを次項の `--include-hash` で構造的に潰す。

### 分類の型（PHPStan level 10 前提）

```php
namespace Tests\Support\Ci;

/** 孤児判定の入力 1 件 (境界で検証済みの値だけを持つ)。 */
final readonly class TestDatabaseCandidate
{
    public function __construct(
        public string $name,             // 実 DB 名 (allowlist 検証済み)
        public string $hash,             // 8 桁 worktree hash
        public bool $isWorker,           // `_test_N` サフィックスを持つか
        public ?string $provenancePath,  // COMMENT ON DATABASE の値 (base のみ / 無ければ null)
    ) {}
}

enum TestDatabaseClassification: string
{
    case Protected = 'protected';   // --protect-hash で明示保護
    case Live = 'live';             // 生存 worktree hash
    case Foreign = 'foreign';       // ラベルあり / その path が実在 = 別クローンが生きている
    case Orphan = 'orphan';         // ラベルあり / その path が実在しない
    case Unlabeled = 'unlabeled';   // ラベルなし (legacy / worker のみ)
}

/** 分類結果 1 件。理由は必ず具体文字列で持つ (dry-run 出力の説明責任)。 */
final readonly class TestDatabaseDecision
{
    public function __construct(
        public TestDatabaseCandidate $candidate,
        public TestDatabaseClassification $classification,
        public string $reason,
        public bool $shouldDrop,
    ) {}
}
```

### 分類優先順位（**概念設計 Round 5 の承認条件 1**）

**同一候補が複数条件を満たす場合も結果が一意になるよう、以下の順に評価して最初に一致した分類で確定する**:

```
1. Protected  — hash が --protect-hash に含まれる          → shouldDrop = false (常に保護)
2. Live       — hash が生存 worktree hash 集合に含まれる    → shouldDrop = false (常に保護)
3. Foreign    — hash グループの provenancePath が実在する   → shouldDrop = false (常に保護)
4. Orphan     — hash グループの provenancePath が実在しない → shouldDrop = (hash ∈ --include-hash)
5. Unlabeled  — hash グループに provenance が無い          → shouldDrop = (hash ∈ --include-hash)
```

**中核原則: 分類は「説明」のために行い、削除可否を分類だけで自動決定しない。**
`--include-hash=<hash>`（複数指定可）で**人間が 1 つずつ名指しした hash 以外は 1 件も落ちない**。

- **1 が 2 より先**: 明示保護は生存判定より強い（人間の意思を最優先する）
- **2 が 3 より先**: comment は書き換え可能な分類材料にすぎず、**生存 worktree の突合が優先**する
  （**comment を細工しても生存 DB は落とせない**。T-C2-5 が固定する）
- **3 が 4 より先**: path が実在する = 誰かが使っている可能性がある側へ倒す（fail-safe）
- **4・5 は「現在のクローンから生存を否定できない」群**なので、**どちらも明示指定制**にする
- **`Protected` / `Live` は `--include-hash` に指定されても DROP しない**（保護が優先。T-C2-21）
- **worker DB は base と同じ hash グループの分類を継承する**（base の provenance が代表）

**なぜ `Orphan` まで明示指定制にするのか**（design-review R2 [Critical]）:
「ラベルあり / path 不在」は**本当に消えた worktree の残骸**とは限らない。
別クローンの生存 DB について、

- provenance を**存在しないパスへ書き換えられた**
- あるいはその path が**現在のコンテナ / namespace から見えない**（bind mount の差など）

のいずれでも分類は `Orphan` に落ちる。`Orphan → 自動 DROP` にすると
**細工または可視性の差だけで他人の生存 DB が消える**。
「現在のクローンから生存を否定できない hash はすべて人間の名指しを要求する」方が正しい。

**`--include-unlabeled`（一括フラグ）を採らない理由**:
権限不足・一時失敗で provenance が付かなかった**現役 DB**が、フラグの巻き添えで
一括 DROP されうる（design-review R1 [Critical]）。
`--include-hash` なら、**dry-run 出力を人間が読んで hash を転記しない限り 1 件も落ちない**。
現存 17 個（5 hash 群）の初回回収は `--include-hash` を 5 回指定する運用になり、
明示性が上がる（手間は初回 1 回だけ）。

```php
/**
 * 孤児判定 (純関数)。上記の優先順位で評価する。
 *
 * @param  list<TestDatabaseCandidate>  $candidates
 * @param  list<string>  $liveHashes       生存 worktree の hash
 * @param  list<string>  $protectedHashes  --protect-hash
 * @param  list<string>  $includeHashes    --include-hash (Orphan / Unlabeled をこの hash に限り候補化)
 * @return list<TestDatabaseDecision>
 */
function classifyTestDatabases(
    array $candidates,
    array $liveHashes,
    array $protectedHashes,
    array $includeHashes,
): array { /* ... */ }
```

`--protect-hash` / `--include-hash` の値は **`^[0-9a-f]{8}$` を強制**する
（不正なら即エラー。T-C2-16 が固定）。

**境界での正規化（PHPStan level 10 の要件）**: `pg_database` の問い合わせ結果と
`git worktree list --porcelain` の出力はいずれも `mixed` 由来なので、
`TestDatabaseCandidate` を生成する時点で
「`isAllowedTestDatabase()` に一致する」「hash が `[0-9a-f]{8}`」「provenance は string か null」を
検証してから純関数へ渡す。純関数は `mixed` を受け取らない。

### `--orphans` の入出力

```
使い方:
  php scripts/ci/drop-test-db.php                       # 従来どおり (この worktree の DB を回収)
  php scripts/ci/drop-test-db.php --orphans             # dry-run (既定。DROP しない)
  php scripts/ci/drop-test-db.php --orphans --include-hash=3a7d6b4e --include-hash=823cbbd2
  php scripts/ci/drop-test-db.php --orphans --apply --confirm=<token> \
      [--include-hash=<hash> ...] [--protect-hash=<hash> ...]

⚠ --apply は **LLM / エージェントが実行してはならない**。
   ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
   (AGENTS.md 禁止事項 3)。
```

dry-run 出力（例）:

```
== hash 対応表 (人間が cross-clone を判断するための材料) ==
  hash      provenance / live path                 分類
  8af22c44  /workspace (live worktree)             Live
  3a7d6b4e  (ラベルなし)                            Unlabeled
  823cbbd2  (ラベルなし)                            Unlabeled
  b4f0102e  (ラベルなし)                            Unlabeled
  018d63c6  (ラベルなし)                            Unlabeled
  91c7197b  (ラベルなし)                            Unlabeled

== 保護 (--protect-hash) ==
  (なし)

== 所有元を確認できない hash (unlabeled) ==
  3a7d6b4e 823cbbd2 b4f0102e 018d63c6 91c7197b
  → これらは本施策より前に作られた DB か、base が既に消えた worker のみの群です。
     **同一 PostgreSQL を共有する別クローン / 別チェックアウトがある場合、その生存 DB が
     ここに含まれます**。apply する前に、別チェックアウトが無いことを必ず確認してください。
     落とすには --include-hash=<hash> で **1 つずつ明示**してください
     (一括指定のフラグは意図的に用意していません)。

== 分類 ==
  app                       skip     dev DB denylist
  bug_hunt                  skip     dev DB denylist (bug-hunt 環境)
  app_test_8af22c44         keep     Live (生存 worktree /workspace)
  app_test_8af22c44_test_1  keep     Live (生存 worktree /workspace / base の分類を継承)
  ...
  app_test_3a7d6b4e         keep     Unlabeled (--include-hash=3a7d6b4e 指定時のみ DROP)
  ...
  (Orphan 分類も同様に --include-hash 指定時のみ DROP される。
   分類は説明のためのもので、削除可否を分類だけで自動決定しない)

== 集計 ==
  DROP 対象: 0 / 保持: 22 / skip: 2
  (--include-hash を 5 群すべてに指定した場合: DROP 対象 17 / 221.9 MB)

--confirm=<64 桁の SHA-256>
  (token は「DROP 対象 + 生存 hash + 保護 hash + include hash + 分類バージョン」の
   canonical JSON から算出しています。--apply は lock 取得後に同じ入力を再計算して
   token を照合し、一致した場合だけ実行します)
```

**token の定義**:

```
canonical = json_encode([
    'classifier_version' => <分類ロジックのバージョン。規則を変えたら上げる>,
    // キー名は 'orphans' ではなく 'drop_targets': 実際の対象は Orphan だけでなく
    // Unlabeled も含む「--include-hash で名指しされた DROP 対象」だから (design-review R4)
    'drop_targets'       => <DROP 対象 DB 名 / 昇順>,
    'live_hashes'        => <生存 hash / 昇順>,
    'protected'          => <保護 hash / 昇順>,
    'include_hashes'     => <--include-hash / 昇順>,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
token = hash('sha256', canonical)   // 全長 64 桁。先頭切り詰めをしない
```

- **JSON 配列にする理由**: 区切りなしの連結は `["a_b","c"]` と `["a","b_c"]` を区別できない
- **`include_hashes` を含める理由**: 承認文脈の一部だから
  （「**どの `Orphan` / `Unlabeled` 群**を落とすことを人間が承認したか」が token に焼き込まれる）
- **`classifier_version` を含める理由**: 分類規則を変更したら token が必ず変わり、
  **古い token では apply できない**（規則変更を人間の再承認なしに通過させない）

### apply の運用契約（**概念設計 Round 5 の承認条件 2**）

| # | 契約 | 実装での担保 |
|---|---|---|
| 1 | 既定は **dry-run**。`--apply` 無しでは 1 件も DROP しない | 引数解析の既定値。`--apply` が無い経路に `pdo->exec(DROP)` を通さない |
| 2 | **`--apply` は LLM / エージェントが実行してはならない**。ユーザー自身が実行するか、ユーザーが明示的に承認したときのみ | `--confirm=<token>` 必須（dry-run 出力を**人間が読んで**転記しない限り得られない）。同じ文言を **(a) スクリプトの usage、(b) `AGENTS.md` §worktree 運用ルール、(c) `scripts/README.md`** の 3 箇所に置く |
| 2-b | **`Orphan` / `Unlabeled` の両方**が一括フラグでは落とせない | `--include-hash=<hash>` で 1 つずつ名指し（`^[0-9a-f]{8}$` を強制）。**名指しの無い hash は 1 件も DROP されない** |
| 3 | apply は **lock 取得後に判定入力を再計算**し、`--confirm` と一致するときだけ DROP | `.claude/worktrees/.setup.lock` を token 再計算の直前に取得し、**全 DROP 完了まで保持**する。不一致なら中止して新 token を表示 |
| 4 | 三重 guard | 名前 allowlist regex / dev DB denylist（`app` + `bug_hunt` + `bug_hunt_1..8`）/ 生存 worktree hash 突合 |
| 5 | 排他の適用範囲を誇張しない | この lock が閉じるのは**同一クローンの協調スクリプト（setup / teardown / sweep）間の TOCTOU だけ**。別クローンとは共有されない。cross-clone の防御は **Foreign 分類 + `--protect-hash` + 人間承認**の 3 段 |

> **cross-clone advisory lock を採らない理由（スコープ外）**: `ensure-test-db.php` に
> PostgreSQL advisory lock を入れれば別クローンとも排他できるが、
> **CI と全 worktree のテスト前処理にロック待ちハングの経路を新設する**ことになり、
> 「偽赤を減らす」という本バッチの目的と逆行する。

### `TestDatabaseEnv` の denylist 拡張

```php
/** dev DB 名の hard-deny 対象。bug_hunt* は allowlist regex で既に構造的に除外されるが、
 *  「bug-hunt 環境の DB は絶対に触らない」という意図を明示する二重防御として列挙する。 */
public const DEV_DB_DENYLIST = ['app', 'bug_hunt', 'bug_hunt_1', ..., 'bug_hunt_8'];
```

### `teardown-worktree.sh` の導線追加

```diff
     if [[ -n "${worktree_status}" ]]; then
         echo "error: ${WORKTREE_DIR} に未コミット変更または untracked ファイルがあります" >&2
         echo "${worktree_status}" >&2
         echo "先に commit / stash / clean してください" >&2
         echo "(依存変更 = package.json / pnpm-lock.yaml / composer.json / composer.lock も必ずコミット)" >&2
+        echo "" >&2
+        echo "⚠️  ここで git worktree remove --force を使って強制撤去すると、下の DB 回収 (drop-test-db.php)" >&2
+        echo "    を通らずテスト DB が孤児として残ります。強制撤去した場合は後で必ず回収してください:" >&2
+        echo "      php scripts/ci/drop-test-db.php --orphans          # dry-run で対象を確認" >&2
+        echo "      (実 DROP は --apply --confirm=<token> が必要。LLM は実行しないこと)" >&2
         exit 1
     fi
```

**dirty チェックと DB 回収の順序は入れ替えない**。入れ替えると「teardown が失敗したのに
まだ使っている worktree のテスト DB が消える」という別の事故になる。
真の解決は施策 C1（dirty チェックが常時失敗する原因の除去）であり、
本施策は**迂回されたときの回収経路**を用意する役割に徹する。

### `scripts/README.md` の更新

```diff
-| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip) | worktree teardown / CI cleanup |
+| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip)。`--orphans` で「生存 worktree に紐づかない孤児 DB」の検出も行う (既定 dry-run。`--apply` は `--confirm=<token>` 必須で **LLM は実行しない** = ユーザー実行またはユーザーの明示承認のみ) | worktree teardown / CI cleanup / 孤児回収 (手動) |
```

（`ScriptsReadmeInventoryTest` が実ファイル ↔ 表の双方向を強制しているため、
**ファイルを増やさない**（`--orphans` をサブコマンド化する）設計は台帳の増加も伴わない）

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<TestDatabaseDecision>` / `list<TestDatabaseCandidate>` / `list<string>`）
- [x] null 安全（`realpath()` の `false`、`shobj_description` の `null`、`PDO::fetchAll` の `mixed` を境界で `Assert` する）
- [x] DTO を返している（`TestDatabaseCandidate` / `TestDatabaseDecision` は `final readonly class`。配列返却なし）
- [x] Generics の型パラメータが正しい（`list<...>` を使用。`array<string, list<string>>` は hash グループ用）
- [x] enum を使い、分類を文字列リテラルで持ち回らない

### テスト計画

- [x] バグ修正の再現テスト: **T-C2-1 を先に書き、現行の `TestDatabaseEnv` に分類関数が無い状態で赤くなることを確認**する
- [x] 新規テスト `tests/Unit/Ci/TestDatabaseClassificationTest.php`:

| ID | 内容 | 期待 |
|---|---|---|
| T-C2-1 | live: 生存 hash の base + worker 5 件 | 全て `Live` / `shouldDrop = false` |
| T-C2-2 | orphan: ラベルあり・path 不在（`--include-hash` 指定なし） | `Orphan` / **`shouldDrop = false`**（指定時の `true` は T-C2-20 が検証） |
| T-C2-3 | foreign: ラベルあり・path 実在 | `Foreign` / `shouldDrop = false` |
| T-C2-4 | **優先順位**: `--protect-hash` 指定 かつ 生存 hash かつ ラベル不在 の候補 | `Protected` で確定（1 が 2・5 に勝つ） |
| T-C2-5 | **優先順位**: 生存 hash かつ ラベルの path 不在（comment 細工） | `Live` で確定（2 が 4 に勝つ = comment で生存 DB を落とせない） |
| T-C2-6 | unlabeled: `--include-hash` 指定なし | `Unlabeled` / `shouldDrop = false` |
| T-C2-7 | unlabeled: 当該 hash を `--include-hash` に指定 | `Unlabeled` / `shouldDrop = true` |
| T-C2-7b | unlabeled: **別の** hash を `--include-hash` に指定（巻き添えが起きない） | `shouldDrop = false` |
| T-C2-8 | **dev DB `app` は候補に入らない**（境界で弾かれる） | 候補生成が `InvalidArgumentException` |
| T-C2-9 | **`bug_hunt` / `bug_hunt_3` は候補に入らない** | 同上 |
| T-C2-10 | allowlist 外（`app_test_XYZ` / `app_test_8af22c44_backup`）は候補に入らない | 同上 |
| T-C2-11 | worker DB は base と同じ分類を継承する | 同左 |
| T-C2-12 | base 不在で worker のみ → `Unlabeled` | 同左 |
| T-C2-13 | token: 同じ入力で同じ token / 1 件でも変われば別 token | 同左 |
| T-C2-14 | token: canonical JSON なので `["a_b","c"]` と `["a","b_c"]` が別 token | 同左 |
| T-C2-15 | 実行順序が違っても（入力順シャッフル）token が同一（昇順ソートの検証） | 同左 |
| T-C2-15b | token: `include_hashes` が変われば別 token / `classifier_version` が変われば別 token | 同左 |
| T-C2-16 | `--protect-hash` / `--include-hash` の形式検証（`ZZZZ` / 7 桁 / 9 桁 / 大文字は拒否） | 例外 |
| T-C2-17 | `testDatabaseEnsurePlan(false)` → `[Create, StampProvenance]` / `testDatabaseEnsurePlan(true)` → `[StampProvenance]`。**両分岐とも `StampProvenance` を含む** | pass |
| T-C2-17b | `pgsqlCommentDatabaseSql()` が provenance に `'` を含む path を**正しくクォート**する（`PDO::quote()` 経由。独自連結しない） | pass |
| T-C2-18 | `pgsqlStampProvenance()` に**例外を投げる `$exec`** を注入 → `false` を返し stderr に warning（例外を伝播させない） | pass |
| T-C2-18b | `pgsqlStampProvenance()` に**成功する `$exec`** を注入 → `true` を返し、渡された SQL が COMMENT 文である | pass |
| T-C2-19 | **細工された foreign**: provenance が不存在 path の候補（= `Orphan` 分類）は `--include-hash` 無しで `shouldDrop = false` | pass |
| T-C2-20 | `Orphan` は当該 hash を `--include-hash` に指定したときだけ `shouldDrop = true` | pass |
| T-C2-21 | `Protected` / `Live` は `--include-hash` に指定されても `shouldDrop = false` | pass |
| T-C2-22 | provenance path が**現在の namespace から見えない**ケース（`is_dir()` が false）も `Orphan` として保護される（指定なしでは落ちない） | pass |

- [x] 既存テストの更新: `TestDatabaseEnv` の denylist 拡張により、既存の
  `assertPgsqlTestDatabaseSafe` / `isDevDatabase` のテストへ `bug_hunt*` ケースを追加
- [x] `ensure-test-db.php` の COMMENT 付与: **実 DB を作らない**単体テストで
  `pgsqlCommentDatabaseSql()` の生成 SQL（識別子クォート / リテラルクォート）を固定する
- [x] 受入テスト（実環境）: **dry-run のみ**を実行し、出力が
  「生存 5 件を keep / 孤児 17 件を Unlabeled として列挙（`--include-hash` なしでは DROP 対象 0）/
  dev DB `app` と `bug_hunt*` を skip」になることを確認する。
  **`--apply` は LLM が実行しない**（ユーザー自身の実行またはユーザーの明示承認が必要）
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Unit/Ci/` は DB を触らない純粋な単体テスト）

### リスク

| リスク | 対処 |
|---|---|
| **別クローンの生存 DB を落とす** | **`--include-hash` で 1 つずつ名指ししない限り 1 件も落ちない**（`Orphan` / `Unlabeled` の両方が明示指定制）/ Foreign 分類（provenance path 実在）で保持 / `--protect-hash` / dry-run が hash → provenance の対応表と「所有元不明 hash」を明示 / 人間承認必須 |
| **provenance を細工されて他人の生存 DB が `Orphan` に落ちる**（または path が namespace 差で見えない） | `Orphan` も `--include-hash` 必須にすることで自動 DROP の経路を断つ（T-C2-19 / T-C2-22 が固定） |
| **ラベルが付かなかった現役 DB が掃除される** | `ensure-test-db.php` が**作成時・既存時の両方**でラベルを付け直す（冪等）。それでも付かなかった場合も、`--include-hash` が無ければ落ちない（一括フラグを用意しないことで構造的に防ぐ） |
| dev DB を落とす | allowlist regex（`^app_test_[0-9a-f]{8}(_test_[0-9]+)?$`）で `app` は構造的に不一致 + denylist で無条件 skip + 既存の `isDevDatabase()` 再検証ループを通す（DROP 実装は既存コード共有） |
| setup 中の worktree の DB を落とす（TOCTOU） | `.setup.lock` を token 再計算の直前に取得し、**全 DROP 完了まで保持**する |
| `COMMENT ON DATABASE` が権限不足で失敗し、ensure が落ちる | comment は**分類材料であって必須ではない**ため、`try { ... } catch (Throwable) { warning }` で best-effort にする（テスト実行を止めない） |
| comment を書き換えて生存 DB を落とさせる攻撃 | 分類優先順位で **Live（生存 hash 突合）が Foreign/Orphan より先**なので、comment 細工では生存 DB を落とせない（T-C2-5 が固定） |
| `--orphans` の追加で `drop-test-db.php` が複雑化する | 既存の無引数経路は**挙動を一切変えない**（回帰は既存 teardown で確認）。分類ロジックは `tests/Support/Ci/` の純関数へ出し、スクリプト側は入出力に徹する |
| 施策 C1 が終わる前に apply して「シグナルを消す」 | **C1 完了を C4（apply）の前提条件**として TODO の受入条件に書く。実装順は C3（純関数・テスト・dry-run）→ C1 → C2 → C4 を許容する |

---

## 実装差分 (git diff --cached。doc/reference の index 削除 58 件は diff から除外している)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index fb64684..98ad268 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -176,6 +176,20 @@ ## worktree 運用ルール
 - **orphan 化した worktree**(teardown を経ず破棄)は `git worktree prune` で整理。
   検証なしの強制削除は
   `git worktree remove --force .claude/worktrees/tasks/<task-id> && git worktree prune`
+- **強制撤去したときのテスト DB 回収**: `git worktree remove --force` で teardown を迂回すると
+  `drop-test-db.php` を通らず**孤児テスト DB が残る**。回収は
+  `php scripts/ci/drop-test-db.php --orphans`(既定 **dry-run**。列挙は SELECT のみ)。
+  分類は **`Protected → Live → Foreign → Orphan → Unlabeled`** の順で確定し、
+  **`Orphan` / `Unlabeled` も `--include-hash=<hash>` で 1 つずつ名指ししない限り 1 件も落ちない**
+  (一括フラグは意図的に用意していない)。
+  - ⚠️ **`--apply`(実 DROP)は LLM / エージェントが実行してはならない**。
+    **ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ**実行できる(禁止事項 3)。
+    LLM が用意してよいのは dry-run の出力までである
+  - 排他の適用範囲を誇張しない: `.setup.lock` が閉じるのは**同一クローンの協調スクリプト
+    (setup / teardown / sweep)間の TOCTOU だけ**。cross-clone は
+    `Foreign` 分類 + `--protect-hash` + 人間承認で守る
+  - 背景(NFC/NFD 重複で dirty チェックが常時失敗 → 迂回 → 孤児 DB の単調増加)と
+    恒久対策(`GitIndexNormalizationTest`)は `docs/worktree-isolation-strategy.md`
 - **テストレーンのグローバルロック (T099)**: `composer test` / `composer test:browser` /
   `pnpm test` / `pnpm test:packages` / `pnpm test:coverage` は**ホスト全体で 1 本ずつ**しか
   走らない (worktree 横断で直列化し、テスト DB とポートの衝突を構造的に防ぐ)
diff --git a/docs/worktree-isolation-strategy.md b/docs/worktree-isolation-strategy.md
index 51373ca..ad57cb6 100644
--- a/docs/worktree-isolation-strategy.md
+++ b/docs/worktree-isolation-strategy.md
@@ -108,6 +108,69 @@ ## teardown (`scripts/teardown-worktree.sh <task-id>`)
 orphan 化した worktree (teardown を経ずに削除) は `git worktree prune` で整理する。
 検証なしの強制削除は `git worktree remove --force <path> && git worktree prune`。
 
+## teardown が常時失敗していた経路 (NFC/NFD) と恒久対策
+
+**症状**: `doc/reference/` に **NFC 形と NFD 形の index entry が両方**載っていた
+(index 197 に対し作業ツリーの実体 139)。正規化非依存 lookup の FS
+(macOS APFS / OrbStack の virtiofs 等) では 1 ファイルに潰れるため、
+新しい worktree を checkout すると「**削除済み扱いの entry**」と
+「**untracked 扱いのファイル**」が現れ、teardown の **(2) dirty チェックが必ず fail** した。
+
+**連鎖**: dirty チェックで止まる → **(4) の DB 回収に到達しない** →
+開発者は `git worktree remove --force` で迂回する → `drop-test-db.php` を通らない →
+**孤児テスト DB が単調増加**する (監査時点で 17 個 / 221.9 MB)。
+順序を入れ替えて DB 回収を先にするのは **誤り** — 「teardown が失敗したのに、
+まだ使っている worktree のテスト DB が消える」という別の事故になる。直すべきは原因の側。
+
+**恒久対策**: NFD 側 index entry 58 件を `git rm --cached` で除去し
+(落とす内容は残す NFC entry に**同一 blob**で保存されていることを実測で確認済み。
+作業ツリーのファイルは 1 つも消えていない)、再発を
+**`tests/Architecture/GitIndexNormalizationTest.php`** が deny-by-default で止める
+(index 全体を NFC 正規化して衝突が 0 件であること)。
+
+> `core.precomposeunicode = true` は **`.git/config` のローカル設定**であり、
+> clone した各人が設定しない限り効かない = **リポジトリの恒久対策にはならない**。
+> 各自 `true` にしておくと再発を緩和できる**補助情報**として扱い、
+> 受入条件にもロールバック手順にも含めない。恒久対策はあくまで上記ゲートである。
+
+**迂回されたときの回収経路**: それでも `--force` で強制撤去された場合に備え、
+`scripts/ci/drop-test-db.php --orphans` が「生存 worktree に紐づかない孤児 DB」を
+**dry-run で列挙**する (§孤児テスト DB の回収)。
+
+## 孤児テスト DB の回収 (`drop-test-db.php --orphans`)
+
+DB 名の hash は worktree の realpath から算出されるため、**worktree が既に消えていると
+hash を再現できない** = 従来の無引数 `drop-test-db.php` では孤児を回収できなかった。
+そこで出自を機械記録し、hash 単位で分類する:
+
+- `ensure-test-db.php` が base DB へ **`COMMENT ON DATABASE <base> IS '<worktree の realpath>'`**
+  を記録する (作成時・既存時の**両方** = 冪等。非破壊 DDL)。付与失敗は best-effort で無視する
+  (comment は分類材料であって必須ではない。ここで落とすとテスト前処理が権限差で止まり偽赤が増える)
+- `--orphans` は `pg_database` + `shobj_description` を **SELECT だけ**で列挙し、
+  **`Protected → Live → Foreign → Orphan → Unlabeled`** の順に評価して分類する。
+  `Live` (生存 worktree hash 突合) が `Foreign` / `Orphan` より**先**なので、
+  **comment を細工しても生存 DB は落とせない**
+- **削除可否を分類だけで自動決定しない**。`Orphan` も `Unlabeled` も
+  `--include-hash=<hash>` で人間が 1 つずつ名指ししない限り 1 件も落ちない
+  (一括フラグは**意図的に用意していない**)
+- `--apply` は `--confirm=<token>` 必須。token は
+  `classifier_version` / `drop_targets` / `live_hashes` / `protected` / `include_hashes` の
+  canonical JSON の SHA-256 で、apply は `.claude/worktrees/.setup.lock` 取得後に
+  判定入力を再取得して token を再計算し、一致したときだけ DROP する
+  (= 指紋ではなく **lock 下のスナップショット照合**)
+- **DROP DDL を実行するのは `drop-test-db.php` の 1 本のまま**。`--orphans` は
+  「どれを落とすかを決める入口」を足すだけで、`isDevDatabase()` /
+  `isAllowedTestDatabase()` / `pgsqlDropDatabaseSql()` は既存実装を共有する
+
+> **排他の適用範囲を誇張しない**: `.setup.lock` が閉じるのは
+> **同一クローンの協調スクリプト (setup / teardown / sweep) 間の TOCTOU だけ**である。
+> lock はファイルシステム上の 1 クローンに閉じており別クローンとは共有されない。
+> **cross-clone の防御は `Foreign` 分類 + `--protect-hash` + 人間承認**の 3 段で行う。
+
+> ⚠️ **`--apply` は LLM / エージェントが実行してはならない**。
+> ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
+> (AGENTS.md 禁止事項 3)。
+
 ## worktree 内のコマンド規則 (2 層)
 
 | 層 | コマンド | 条件 |
diff --git a/scripts/README.md b/scripts/README.md
index d37bcaa..c269e9b 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -16,8 +16,8 @@ ## スクリプト一覧
 | `run-test.sh` | `composer test` の pgsql 経路。**グローバルテストロック配下**で base テスト DB の冪等 CREATE (`ci/ensure-test-db.php`) → `artisan test --parallel` を実行 | `composer test` から自動呼び出し (直接呼ぶ必要なし) |
 | `run-vitest.sh` | vitest を**グローバルテストロック配下**で実行 (`exec` は使わない = fd 7 を保持したまま子を待つ) | `pnpm test` から自動呼び出し |
 | `phpstan.sh` | PHPStan の DX ラッパー。virtiofs 上の phar 並列 open レースを避けるため phar を実 fs に複製してから実行 | `composer phpstan` から自動呼び出し |
-| `ci/ensure-test-db.php` | pgsql テストの base DB を不在時のみ冪等 CREATE (dev-DB 保護の二重防御付き) | `run-test.sh` / CI から自動呼び出し |
-| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip) | worktree teardown / CI cleanup |
+| `ci/ensure-test-db.php` | pgsql テストの base DB を不在時のみ冪等 CREATE (dev-DB 保護の二重防御付き)。併せて出自 (worktree の realpath) を `COMMENT ON DATABASE` で冪等に記録する (孤児 sweep の分類材料。付与失敗は best-effort で無視) | `run-test.sh` / CI から自動呼び出し |
+| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip)。`--orphans` で「生存 worktree に紐づかない孤児 DB」の検出も行う (既定 dry-run。`--apply` は `--confirm=<token>` 必須で **LLM は実行しない** = ユーザー実行またはユーザーの明示承認のみ) | worktree teardown / CI cleanup / 孤児回収 (手動) |
 | `setup-worktree.sh` | TODO 用 worktree (`.claude/worktrees/tasks/<task-id>` + `todo/<task-id>` ブランチ) を作成し、実行時ファイルのコピー・worktree-local な `composer install --no-scripts` / `pnpm install` (GVS 共有)・health check・テスト DB ensure まで機械的に実行 (AGENTS.md §worktree 運用ルール) | 実装開始時 (`app-implement` W-3 等) |
 | `teardown-worktree.sh` | worktree の dirty チェック → テスト DB の best-effort 回収 → `git worktree remove --force`。ブランチの削除/マージは呼び出し側の責務 | 実装完了後 (`app-implement` C-4 等) |
 | `ci/pgsql_test_conn.php` | ensure / drop が共有する pgsql 接続 resolver | (上記 2 スクリプトの内部 include) |
diff --git a/scripts/ci/drop-test-db.php b/scripts/ci/drop-test-db.php
index cff81b6..4816cda 100644
--- a/scripts/ci/drop-test-db.php
+++ b/scripts/ci/drop-test-db.php
@@ -9,59 +9,531 @@
  * を回収する。ensure と接続 resolver を共有 (pgsql_test_conn.php) し、
  * 同一 PostgreSQL を見る (stale DB 排除)。
  *
+ * 2 つの入口を持つが、**DROP DDL を実行するのは本ファイルの 1 本だけ** (責務を分散させない):
+ *   - 引数なし    : 自 worktree の DB を回収する (teardown / CI cleanup。従来どおり)
+ *   - `--orphans` : 生存 worktree に紐づかない孤児 DB を **列挙する** (既定 dry-run。列挙は SELECT のみ)
+ *
+ * ⚠ `--apply` (実 DROP) は **LLM / エージェントが実行してはならない**。
+ *   ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
+ *   (AGENTS.md 禁止事項 3 の趣旨。同じ文言を usage / AGENTS.md / scripts/README.md に置く)。
+ *
  * dev-DB 保護 (NON-NEGOTIABLE):
  *   - base 名は TestDatabaseEnv::pgsqlBaseDatabase() (唯一のソース)。
  *   - pg_database を `datname = base OR datname LIKE base||'\_test\_%'` で列挙し、
  *     1 件ずつ isAllowedTestDatabase() で再検証。一致したものだけ DROP する。
  *   - isDevDatabase() true は無条件 skip + 警告 (理論上到達しないが防壁)。
  *   - best-effort: 接続失敗は skip + exit 0 (teardown を止めない)。失敗 DB 名は stderr に明示。
+ *     ただし明示的に呼ばれた `--orphans` は黙って成功にせず exit 1 する。
  */
 
+use Tests\Support\Ci\TestDatabaseCandidate;
+use Tests\Support\Ci\TestDatabaseClassification;
+use Tests\Support\Ci\TestDatabaseDecision;
 use Tests\Support\Ci\TestDatabaseEnv;
+use Webmozart\Assert\Assert;
 
 require __DIR__.'/../../vendor/autoload.php';
 require __DIR__.'/pgsql_test_conn.php';
 
-$projectRoot = dirname(__DIR__, 2);
-$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);
+// ───────────────────── DROP 実装 (両経路が共有する唯一の DDL 実行点) ─────────────────────
 
-try {
-    $pdo = pgsqlTestMaintenancePdo($projectRoot);
-} catch (Throwable $e) {
-    fwrite(STDERR, "drop-test-db: maintenance DB connect failed; skipping (best-effort): {$e->getMessage()}\n");
-    exit(0);
+/**
+ * 渡された DB 名を 1 件ずつ再検証して DROP する。
+ *
+ * **どちらの入口 (従来経路 / --orphans --apply) もこの関数だけを通る**。
+ * 呼び出し側で既に分類済みでも、DDL 直前に isDevDatabase / isAllowedTestDatabase を
+ * もう一度通す (防壁は末端に置く)。
+ *
+ * @param  list<string>  $names
+ * @return int DROP に成功した件数
+ */
+function dropTestDbDropAll(PDO $pdo, array $names): int
+{
+    $dropped = 0;
+    foreach ($names as $name) {
+        if (TestDatabaseEnv::isDevDatabase($name)) {
+            fwrite(STDERR, "drop-test-db: refusing to drop dev DB (skipped): {$name}\n");
+
+            continue;
+        }
+        if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
+            fwrite(STDERR, "drop-test-db: name not allowlisted (skipped): {$name}\n");
+
+            continue;
+        }
+        try {
+            $pdo->exec(pgsqlDropDatabaseSql($name));
+            $dropped++;
+        } catch (Throwable $e) {
+            fwrite(STDERR, "drop-test-db: failed to drop {$name} (manual cleanup may be needed): {$e->getMessage()}\n");
+        }
+    }
+
+    return $dropped;
+}
+
+// ───────────────────────── usage ─────────────────────────
+
+function dropTestDbUsage(): string
+{
+    return <<<'TXT'
+    使い方:
+      php scripts/ci/drop-test-db.php                       # 従来どおり (この worktree の DB を回収)
+      php scripts/ci/drop-test-db.php --orphans             # dry-run (既定。DROP しない)
+      php scripts/ci/drop-test-db.php --orphans --include-hash=3a7d6b4e --include-hash=823cbbd2
+      php scripts/ci/drop-test-db.php --orphans --apply --confirm=<token> \
+          [--include-hash=<hash> ...] [--protect-hash=<hash> ...]
+
+    オプション (--orphans モード):
+      --apply                実際に DROP する。--confirm=<token> が必須
+      --confirm=<token>      dry-run が表示した SHA-256 (64 桁)。apply 時に lock 下で再計算して照合する
+      --include-hash=<hash>  Orphan / Unlabeled の DROP を hash 単位で許可する (複数指定可)。
+                             **一括フラグは意図的に用意していない** = 名指しの無い hash は 1 件も落ちない
+      --protect-hash=<hash>  hash を明示保護する (別クローンの DB を守る。複数指定可)
+
+    ⚠ --apply は **LLM / エージェントが実行してはならない**。
+       ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
+       (AGENTS.md 禁止事項 3)。
+    TXT;
+}
+
+// ───────────────────── 引数解析 (fail-closed) ─────────────────────
+
+/**
+ * @param  list<string>  $args
+ * @return array{orphans: bool, apply: bool, confirm: ?string, protect: list<string>, include: list<string>}
+ */
+function dropTestDbParseArgs(array $args): array
+{
+    $parsed = ['orphans' => false, 'apply' => false, 'confirm' => null, 'protect' => [], 'include' => []];
+
+    foreach ($args as $arg) {
+        if ($arg === '--orphans') {
+            $parsed['orphans'] = true;
+
+            continue;
+        }
+        if ($arg === '--apply') {
+            $parsed['apply'] = true;
+
+            continue;
+        }
+        if (str_starts_with($arg, '--confirm=')) {
+            $parsed['confirm'] = substr($arg, strlen('--confirm='));
+
+            continue;
+        }
+        if (str_starts_with($arg, '--protect-hash=')) {
+            $parsed['protect'][] = substr($arg, strlen('--protect-hash='));
+
+            continue;
+        }
+        if (str_starts_with($arg, '--include-hash=')) {
+            $parsed['include'][] = substr($arg, strlen('--include-hash='));
+
+            continue;
+        }
+        // 未知の引数は fail-closed (typo した `--include-hasch=...` を黙って無視しない)。
+        throw new InvalidArgumentException("unknown argument: {$arg}");
+    }
+
+    // hash 形式は分類 / token 計算と同一の正規表現で先に弾く。
+    foreach ([...$parsed['protect'], ...$parsed['include']] as $hash) {
+        Assert::regex($hash, TestDatabaseCandidate::HASH_PATTERN, "hash must be 8 lowercase hex chars: {$hash}");
+    }
+    if ($parsed['apply'] && ! $parsed['orphans']) {
+        throw new InvalidArgumentException('--apply は --orphans と併用してください');
+    }
+    if ($parsed['apply'] && ($parsed['confirm'] === null || $parsed['confirm'] === '')) {
+        throw new InvalidArgumentException('--apply には --confirm=<token> が必須です (dry-run の出力から転記してください)');
+    }
+
+    return $parsed;
+}
+
+// ───────────────────── 入力の収集 (境界で正規化) ─────────────────────
+
+/**
+ * 生存 worktree の hash 集合。`git worktree list --porcelain -z` の各 path の realpath から算出する。
+ * 自分自身 ($projectRoot) は git の出力に関わらず必ず含める (自分の DB を孤児と誤判定しないため)。
+ *
+ * @return list<string>
+ */
+function dropTestDbLiveHashes(string $projectRoot): array
+{
+    $hashes = [TestDatabaseEnv::workrootHash($projectRoot)];
+
+    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+    $process = proc_open(['git', 'worktree', 'list', '--porcelain', '-z'], $descriptors, $pipes, $projectRoot);
+    if (! is_resource($process)) {
+        throw new RuntimeException('git worktree list を起動できませんでした (生存 worktree を確認できないため中止します)');
+    }
+    $stdout = stream_get_contents($pipes[1]);
+    $stderr = stream_get_contents($pipes[2]);
+    fclose($pipes[1]);
+    fclose($pipes[2]);
+    $exitCode = proc_close($process);
+
+    // 生存判定を落とすと「生きている worktree の DB を孤児扱いする」経路になるため fail-closed。
+    Assert::same(0, $exitCode, 'git worktree list が失敗しました: '.(is_string($stderr) ? $stderr : ''));
+    Assert::string($stdout, 'git worktree list の出力を取得できませんでした');
+
+    foreach (explode("\0", $stdout) as $line) {
+        if (! str_starts_with($line, 'worktree ')) {
+            continue;
+        }
+        $path = substr($line, strlen('worktree '));
+        $real = realpath($path);
+        if ($real === false) {
+            // git が知っているのに path が無い = prune 前の残骸。生存扱いしない。
+            fwrite(STDERR, "drop-test-db: worktree path が解決できません (生存扱いしません): {$path}\n");
+
+            continue;
+        }
+        $hashes[] = TestDatabaseEnv::workrootHash($real);
+    }
+
+    return array_values(array_unique($hashes));
+}
+
+/**
+ * pg_database を **SELECT だけ**で列挙し `<name, provenance, size>` へ正規化する。
+ *
+ * @return list<array{name: string, provenance: ?string, size: int}>
+ */
+function dropTestDbInventory(PDO $pdo): array
+{
+    $sql = <<<'SQL'
+    SELECT d.datname AS name,
+           shobj_description(d.oid, 'pg_database') AS provenance,
+           pg_database_size(d.oid) AS size
+      FROM pg_database d
+     WHERE d.datistemplate = false
+     ORDER BY d.datname
+    SQL;
+
+    $statement = $pdo->query($sql);
+    Assert::isInstanceOf($statement, PDOStatement::class, 'pg_database の列挙に失敗しました');
+    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
+
+    $inventory = [];
+    foreach ($rows as $row) {
+        Assert::isArray($row);
+        $name = $row['name'] ?? null;
+        Assert::string($name, 'pg_database.datname が文字列ではありません');
+        $provenance = $row['provenance'] ?? null;
+        Assert::nullOrString($provenance, 'shobj_description が文字列でも null でもありません');
+        $size = $row['size'] ?? 0;
+        Assert::numeric($size, 'pg_database_size が数値ではありません');
+
+        $inventory[] = ['name' => $name, 'provenance' => $provenance, 'size' => (int) $size];
+    }
+
+    return $inventory;
+}
+
+/** 表示幅で右詰めパディングする (printf の %-Ns はバイト数で数えるため日本語で崩れる)。 */
+function dropTestDbPad(string $text, int $width): string
+{
+    return $text.str_repeat(' ', max(1, $width - mb_strwidth($text, 'UTF-8')));
+}
+
+/** バイト数を人間可読へ (dry-run 出力用)。 */
+function dropTestDbHumanBytes(int $bytes): string
+{
+    if ($bytes < 1024 * 1024) {
+        return sprintf('%.1f kB', $bytes / 1024);
+    }
+
+    return sprintf('%.1f MB', $bytes / 1024 / 1024);
+}
+
+/**
+ * 孤児判定のスナップショット (同じ入力から何度でも再計算できる)。
+ *
+ * `--apply` はこの関数を **lock 取得後にもう一度呼んで** token を照合する
+ * (token は「指紋」ではなく **lock 下のスナップショット照合**である)。
+ *
+ * @param  list<string>  $protectedHashes
+ * @param  list<string>  $includeHashes
+ * @return array{
+ *     decisions: list<TestDatabaseDecision>,
+ *     skipped: list<array{name: string, reason: string}>,
+ *     sizes: array<string, int>,
+ *     liveHashes: list<string>,
+ *     dropTargets: list<string>,
+ *     token: string,
+ * }
+ */
+function dropTestDbOrphanSnapshot(PDO $pdo, string $projectRoot, array $protectedHashes, array $includeHashes): array
+{
+    $liveHashes = dropTestDbLiveHashes($projectRoot);
+
+    $candidates = [];
+    $skipped = [];
+    $sizes = [];
+    foreach (dropTestDbInventory($pdo) as $row) {
+        $sizes[$row['name']] = $row['size'];
+
+        if (TestDatabaseEnv::isDevDatabase($row['name'])) {
+            $skipped[] = ['name' => $row['name'], 'reason' => 'dev DB denylist (絶対に触らない)'];
+
+            continue;
+        }
+        if (! TestDatabaseEnv::isAllowedTestDatabase($row['name'])) {
+            $skipped[] = ['name' => $row['name'], 'reason' => 'allowlist 外 (テスト DB ではない)'];
+
+            continue;
+        }
+        $candidates[] = TestDatabaseCandidate::fromDatabaseName($row['name'], $row['provenance']);
+    }
+
+    $decisions = TestDatabaseEnv::classifyTestDatabases($candidates, $liveHashes, $protectedHashes, $includeHashes);
+
+    $dropTargets = array_values(array_map(
+        static fn (TestDatabaseDecision $d): string => $d->candidate->name,
+        array_filter($decisions, static fn (TestDatabaseDecision $d): bool => $d->shouldDrop),
+    ));
+
+    return [
+        'decisions' => $decisions,
+        'skipped' => $skipped,
+        'sizes' => $sizes,
+        'liveHashes' => $liveHashes,
+        'dropTargets' => $dropTargets,
+        'token' => TestDatabaseEnv::orphanConfirmToken($dropTargets, $liveHashes, $protectedHashes, $includeHashes),
+    ];
 }
 
-// base 完全一致 OR base_test_<token>。LIKE の _ / % を ESCAPE でリテラル化。
-$pattern = str_replace(['_', '%'], ['\_', '\%'], $base).'\_test\_%';
-$stmt = $pdo->prepare("SELECT datname FROM pg_database WHERE datname = :base OR datname LIKE :pat ESCAPE '\\'");
-$stmt->execute(['base' => $base, 'pat' => $pattern]);
+/**
+ * dry-run レポートを stdout へ出す。
+ * **人間がこれを読んで hash を転記しない限り 1 件も落ちない**ので、判断材料を省略しない。
+ *
+ * @param  array{decisions: list<TestDatabaseDecision>, skipped: list<array{name: string, reason: string}>, sizes: array<string, int>, liveHashes: list<string>, dropTargets: list<string>, token: string}  $snapshot
+ * @param  list<string>  $protectedHashes
+ * @param  list<string>  $includeHashes
+ */
+function dropTestDbPrintReport(array $snapshot, array $protectedHashes, array $includeHashes): void
+{
+    /** @var array<string, TestDatabaseClassification> $hashClass */
+    $hashClass = [];
+    /** @var array<string, string|null> $hashProvenance */
+    $hashProvenance = [];
+    foreach ($snapshot['decisions'] as $decision) {
+        $hash = $decision->candidate->hash;
+        $hashClass[$hash] ??= $decision->classification;
+        if (! $decision->candidate->isWorker && $decision->candidate->provenancePath !== null) {
+            $hashProvenance[$hash] = $decision->candidate->provenancePath;
+        }
+        $hashProvenance[$hash] ??= null;
+    }
+    ksort($hashClass);
+
+    echo "== hash 対応表 (人間が cross-clone を判断するための材料) ==\n";
+    if ($hashClass === []) {
+        echo "  (テスト DB はありません)\n";
+    }
+    foreach ($hashClass as $hash => $classification) {
+        echo '  '.dropTestDbPad($hash, 10).dropTestDbPad($hashProvenance[$hash] ?? '(ラベルなし)', 46).$classification->name."\n";
+    }
+
+    echo "\n== 保護 (--protect-hash) ==\n";
+    echo $protectedHashes === [] ? "  (なし)\n" : '  '.implode(' ', $protectedHashes)."\n";
+
+    $unlabeled = array_values(array_unique(array_map(
+        static fn (TestDatabaseDecision $d): string => $d->candidate->hash,
+        array_filter(
+            $snapshot['decisions'],
+            static fn (TestDatabaseDecision $d): bool => $d->classification === TestDatabaseClassification::Unlabeled,
+        ),
+    )));
+    sort($unlabeled, SORT_STRING);
+
+    echo "\n== 所有元を確認できない hash (unlabeled) ==\n";
+    if ($unlabeled === []) {
+        echo "  (なし)\n";
+    } else {
+        echo '  '.implode(' ', $unlabeled)."\n";
+        echo "  → これらは本機能より前に作られた DB か、base が既に消えた worker のみの群です。\n";
+        echo "     同一 PostgreSQL を共有する別クローン / 別チェックアウトがある場合、その生存 DB が\n";
+        echo "     ここに含まれます。apply する前に、別チェックアウトが無いことを必ず確認してください。\n";
+        echo "     落とすには --include-hash=<hash> で 1 つずつ明示してください\n";
+        echo "     (一括指定のフラグは意図的に用意していません)。\n";
+    }
 
-/** @var list<string> $names */
-$names = array_values(array_filter(
-    array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)),
-    static fn (string $v): bool => $v !== '',
-));
+    echo "\n== 分類 ==\n";
+    foreach ($snapshot['skipped'] as $skip) {
+        printf("  %-26s %-6s %s\n", $skip['name'], 'skip', $skip['reason']);
+    }
+    foreach ($snapshot['decisions'] as $decision) {
+        printf(
+            "  %-26s %-6s %s (%s)\n",
+            $decision->candidate->name,
+            $decision->shouldDrop ? 'DROP' : 'keep',
+            $decision->classification->name,
+            $decision->reason,
+        );
+    }
 
-$dropped = 0;
-foreach ($names as $name) {
-    if (TestDatabaseEnv::isDevDatabase($name)) {
-        fwrite(STDERR, "drop-test-db: refusing to drop dev DB (skipped): {$name}\n");
+    $dropBytes = 0;
+    foreach ($snapshot['dropTargets'] as $name) {
+        $dropBytes += $snapshot['sizes'][$name] ?? 0;
+    }
 
-        continue;
+    echo "\n== 集計 ==\n";
+    printf(
+        "  DROP 対象: %d (%s) / 保持: %d / skip: %d\n",
+        count($snapshot['dropTargets']),
+        dropTestDbHumanBytes($dropBytes),
+        count($snapshot['decisions']) - count($snapshot['dropTargets']),
+        count($snapshot['skipped']),
+    );
+    echo '  生存 worktree hash: '.implode(' ', $snapshot['liveHashes'])."\n";
+    if ($includeHashes !== []) {
+        echo '  --include-hash: '.implode(' ', $includeHashes)."\n";
     }
-    if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
-        fwrite(STDERR, "drop-test-db: name not allowlisted (skipped): {$name}\n");
 
-        continue;
+    echo "\n--confirm={$snapshot['token']}\n";
+    echo "  (token は classifier_version / drop_targets / live_hashes / protected / include_hashes の\n";
+    echo "   canonical JSON から算出しています。--apply は lock 取得後に同じ入力を再計算して\n";
+    echo "   token を照合し、一致した場合だけ実行します)\n";
+    echo "\n⚠ --apply は LLM / エージェントが実行してはなりません。\n";
+    echo "   ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できます。\n";
+}
+
+/**
+ * setup/teardown と同一の lock (`<main-clone>/.claude/worktrees/.setup.lock`) を取得する。
+ *
+ * **排他の適用範囲を誇張しない**: この lock が閉じるのは
+ * **同一クローンの協調スクリプト (setup / teardown / sweep) 間の TOCTOU だけ**である。
+ * `.setup.lock` は 1 クローンに閉じており別クローンとは共有されない。cross-clone の防御は
+ * lock ではなく **Foreign 分類 + --protect-hash + 人間承認**の 3 段で行う。
+ *
+ * @return resource 保持し続けるためのハンドル (全 DROP 完了まで閉じない)
+ */
+function dropTestDbAcquireSetupLock(string $projectRoot)
+{
+    // worktree からは `git rev-parse --git-common-dir` がメインクローンの .git を指す
+    // (worktree 内で --show-toplevel を使うと worktree 自身になり、別 lock を掴んでしまう)。
+    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+    $process = proc_open(
+        ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'],
+        $descriptors,
+        $pipes,
+        $projectRoot,
+    );
+    if (! is_resource($process)) {
+        throw new RuntimeException('git rev-parse を起動できませんでした');
     }
+    $stdout = stream_get_contents($pipes[1]);
+    fclose($pipes[1]);
+    fclose($pipes[2]);
+    Assert::same(0, proc_close($process), 'git rev-parse --git-common-dir が失敗しました');
+    Assert::string($stdout);
+
+    $lockDir = dirname(trim($stdout)).'/.claude/worktrees';
+    if (! is_dir($lockDir) && ! mkdir($lockDir, 0o775, true) && ! is_dir($lockDir)) {
+        throw new RuntimeException("lock ディレクトリを作成できません: {$lockDir}");
+    }
+
+    $handle = fopen($lockDir.'/.setup.lock', 'c');
+    if ($handle === false) {
+        throw new RuntimeException("lock ファイルを開けません: {$lockDir}/.setup.lock");
+    }
+    if (! flock($handle, LOCK_EX | LOCK_NB)) {
+        throw new RuntimeException(
+            "別の setup/teardown が実行中です ({$lockDir}/.setup.lock)。完了を待って再実行してください。",
+        );
+    }
+
+    return $handle;
+}
+
+// ───────────────────────── entrypoint ─────────────────────────
+
+$projectRoot = dirname(__DIR__, 2);
+
+try {
+    /** @var list<string> $argvRest */
+    $argvRest = array_values(array_slice($argv, 1));
+    $options = dropTestDbParseArgs($argvRest);
+} catch (Throwable $e) {
+    fwrite(STDERR, "drop-test-db: {$e->getMessage()}\n\n".dropTestDbUsage()."\n");
+    exit(1);
+}
+
+if (! $options['orphans']) {
+    // ── 従来経路 (自 worktree の DB 回収)。挙動は一切変えない ──
+    $base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);
+
     try {
-        $pdo->exec(pgsqlDropDatabaseSql($name));
-        $dropped++;
+        $pdo = pgsqlTestMaintenancePdo($projectRoot);
     } catch (Throwable $e) {
-        fwrite(STDERR, "drop-test-db: failed to drop {$name} (manual cleanup may be needed): {$e->getMessage()}\n");
+        fwrite(STDERR, "drop-test-db: maintenance DB connect failed; skipping (best-effort): {$e->getMessage()}\n");
+        exit(0);
     }
+
+    // base 完全一致 OR base_test_<token>。LIKE の _ / % を ESCAPE でリテラル化。
+    $pattern = str_replace(['_', '%'], ['\_', '\%'], $base).'\_test\_%';
+    $stmt = $pdo->prepare("SELECT datname FROM pg_database WHERE datname = :base OR datname LIKE :pat ESCAPE '\\'");
+    $stmt->execute(['base' => $base, 'pat' => $pattern]);
+
+    /** @var list<string> $names */
+    $names = array_values(array_filter(
+        array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)),
+        static fn (string $v): bool => $v !== '',
+    ));
+
+    $dropped = dropTestDbDropAll($pdo, $names);
+    fwrite(STDERR, "drop-test-db: dropped {$dropped} test DB(s) for base {$base}\n");
+    exit(0);
+}
+
+// ── --orphans 経路 ──
+
+try {
+    $pdo = pgsqlTestMaintenancePdo($projectRoot);
+} catch (Throwable $e) {
+    // teardown の best-effort と違い、明示的に呼ばれた sweep は黙って成功にしない。
+    fwrite(STDERR, "drop-test-db: maintenance DB connect failed: {$e->getMessage()}\n");
+    exit(1);
+}
+
+$snapshot = dropTestDbOrphanSnapshot($pdo, $projectRoot, $options['protect'], $options['include']);
+
+if (! $options['apply']) {
+    dropTestDbPrintReport($snapshot, $options['protect'], $options['include']);
+    exit(0);
+}
+
+// ── apply: lock を取ってから判定入力を再取得し、token を照合する ──
+// lock は **全 DROP が完了するまで保持する** ($lockHandle をスコープに残す)。
+
+try {
+    $lockHandle = dropTestDbAcquireSetupLock($projectRoot);
+} catch (Throwable $e) {
+    fwrite(STDERR, "drop-test-db: {$e->getMessage()}\n");
+    exit(1);
+}
+
+$verified = dropTestDbOrphanSnapshot($pdo, $projectRoot, $options['protect'], $options['include']);
+
+if (! hash_equals($verified['token'], (string) $options['confirm'])) {
+    fwrite(STDERR, "drop-test-db: --confirm が lock 下のスナップショットと一致しません (中止しました)\n");
+    fwrite(STDERR, "  受領: {$options['confirm']}\n");
+    fwrite(STDERR, "  現在: {$verified['token']}\n");
+    fwrite(STDERR, "  DB / worktree の状態が変わっています。dry-run をやり直して内容を確認してください。\n");
+    flock($lockHandle, LOCK_UN);
+    exit(1);
+}
+
+if ($verified['dropTargets'] === []) {
+    fwrite(STDERR, "drop-test-db: DROP 対象がありません (--include-hash で hash を名指ししてください)\n");
+    flock($lockHandle, LOCK_UN);
+    exit(0);
 }
 
-fwrite(STDERR, "drop-test-db: dropped {$dropped} test DB(s) for base {$base}\n");
+$dropped = dropTestDbDropAll($pdo, $verified['dropTargets']);
+fwrite(STDERR, 'drop-test-db: dropped '.$dropped.' orphan test DB(s) of '.count($verified['dropTargets'])." target(s)\n");
+flock($lockHandle, LOCK_UN);
 exit(0);
diff --git a/scripts/ci/ensure-test-db.php b/scripts/ci/ensure-test-db.php
index 82161ea..bd71ca9 100644
--- a/scripts/ci/ensure-test-db.php
+++ b/scripts/ci/ensure-test-db.php
@@ -37,11 +37,25 @@
 
 $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
 $stmt->execute(['name' => $base]);
-if ($stmt->fetchColumn() !== false) {
-    fwrite(STDERR, "ensure-test-db: base DB already exists: {$base}\n");
-    exit(0);
+$exists = $stmt->fetchColumn() !== false;
+
+// 出自 (worktree の realpath) を記録/更新する (非破壊の COMMENT ON DATABASE)。
+// 孤児 sweep (drop-test-db.php --orphans) の**分類材料**であって guard ではない。
+// 既存 DB でも必ず通す = 冪等 (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
+$provenance = realpath($projectRoot);
+Assert::string($provenance, "projectRoot must resolve to a real path: {$projectRoot}");
+
+foreach (testDatabaseEnsurePlan($exists) as $action) {
+    match ($action) {
+        TestDatabaseEnsureAction::Create => $pdo->exec(pgsqlCreateDatabaseSql($base)),
+        TestDatabaseEnsureAction::StampProvenance => pgsqlStampProvenance(
+            static fn (string $sql): mixed => $pdo->exec($sql),
+            pgsqlCommentDatabaseSql($pdo, $base, $provenance),
+        ),
+    };
 }
 
-$pdo->exec(pgsqlCreateDatabaseSql($base));
-fwrite(STDERR, "ensure-test-db: created base DB: {$base}\n");
+fwrite(STDERR, $exists
+    ? "ensure-test-db: base DB already exists: {$base}\n"
+    : "ensure-test-db: created base DB: {$base}\n");
 exit(0);
diff --git a/scripts/ci/pgsql_test_conn.php b/scripts/ci/pgsql_test_conn.php
index 02b6d1e..438bc2a 100644
--- a/scripts/ci/pgsql_test_conn.php
+++ b/scripts/ci/pgsql_test_conn.php
@@ -85,3 +85,64 @@ function pgsqlDropDatabaseSql(string $name): string
 {
     return 'DROP DATABASE IF EXISTS '.pgsqlQuoteIdentifier($name).' WITH (FORCE)';
 }
+
+/**
+ * allowlist 検証済み DB 名に、出自 (worktree の realpath) を記録する COMMENT 文を生成する。
+ *
+ * 孤児 sweep (`drop-test-db.php --orphans`) が「削除済み worktree の残骸」と
+ * 「同一 PostgreSQL を共有する**別クローンの生存 DB**」を区別するための**分類材料**。
+ * **信頼境界ではない** (誰でも書き換えられるため単独では guard にならない)。
+ * 識別子は pgsqlQuoteIdentifier、リテラルは PDO::quote で組み立てる (独自連結はしない。
+ * provenance path に `'` が含まれうる)。非破壊 DDL なので ensure 側から実行してよい。
+ */
+function pgsqlCommentDatabaseSql(PDO $pdo, string $name, string $provenance): string
+{
+    return 'COMMENT ON DATABASE '.pgsqlQuoteIdentifier($name).' IS '.$pdo->quote($provenance);
+}
+
+/** ensure が行う操作。SQL 生成はしない (クォート責務は既存の SQL ビルダに残す)。 */
+enum TestDatabaseEnsureAction
+{
+    case Create;
+    case StampProvenance;
+}
+
+/**
+ * ensure が実行すべき action 列を返す (純関数。PDO にも SQL にも触れない)。
+ *
+ * **両分岐とも StampProvenance を含む**のが契約: 既存 DB のときに省くと
+ * 「ラベルの無い現役 DB」が生まれ、将来の孤児 sweep の分類材料が欠ける (= 冪等にする)。
+ *
+ * @return list<TestDatabaseEnsureAction>
+ *                                        $exists=false → [Create, StampProvenance] / $exists=true → [StampProvenance]
+ */
+function testDatabaseEnsurePlan(bool $exists): array
+{
+    return $exists
+        ? [TestDatabaseEnsureAction::StampProvenance]
+        : [TestDatabaseEnsureAction::Create, TestDatabaseEnsureAction::StampProvenance];
+}
+
+/**
+ * provenance ラベルを **best-effort** で実行する。`$exec` を注入するので PDO 無しでテストできる。
+ *
+ * fail-closed にしない理由: comment は分類材料であって必須ではない。ここで落とすと
+ * 権限設定の差でテスト実行そのものが止まり、**偽赤を増やす**。
+ * 「ラベルの無い DB がフラグ 1 つで一括 DROP される」危険の方は
+ * `--include-hash` の明示指定制 (一括フラグを用意しない) で構造的に潰してある。
+ *
+ * @param  callable(string): mixed  $exec
+ * @return bool 成功したか (失敗時は false + stderr へ warning。例外は伝播させない)
+ */
+function pgsqlStampProvenance(callable $exec, string $sql): bool
+{
+    try {
+        $exec($sql);
+
+        return true;
+    } catch (Throwable $e) {
+        fwrite(STDERR, "ensure-test-db: provenance コメントの記録に失敗 (best-effort / 続行): {$e->getMessage()}\n");
+
+        return false;
+    }
+}
diff --git a/scripts/teardown-worktree.sh b/scripts/teardown-worktree.sh
index 017c9f1..d5907ed 100755
--- a/scripts/teardown-worktree.sh
+++ b/scripts/teardown-worktree.sh
@@ -74,6 +74,11 @@ if [[ -d "${WORKTREE_DIR}" ]]; then
         echo "${worktree_status}" >&2
         echo "先に commit / stash / clean してください" >&2
         echo "(依存変更 = package.json / pnpm-lock.yaml / composer.json / composer.lock も必ずコミット)" >&2
+        echo "" >&2
+        echo "⚠️  ここで git worktree remove --force を使って強制撤去すると、下の DB 回収 (drop-test-db.php)" >&2
+        echo "    を通らずテスト DB が孤児として残ります。強制撤去した場合は後で必ず回収してください:" >&2
+        echo "      php scripts/ci/drop-test-db.php --orphans          # dry-run で対象を確認" >&2
+        echo "      (実 DROP は --apply --confirm=<token> が必要。LLM は実行しないこと)" >&2
         exit 1
     fi
 else
diff --git a/tests/Architecture/GitIndexNormalizationTest.php b/tests/Architecture/GitIndexNormalizationTest.php
new file mode 100644
index 0000000..087bc36
--- /dev/null
+++ b/tests/Architecture/GitIndexNormalizationTest.php
@@ -0,0 +1,154 @@
+<?php
+
+declare(strict_types=1);
+
+use Webmozart\Assert\Assert;
+
+/*
+ * git index に **NFC 正規化で衝突する path** が無いことを deny-by-default で固定する。
+ *
+ * なぜ必要か: `doc/reference/` に NFD 形と NFC 形の entry が両方載っており
+ * (index 197 に対し実体 139)、正規化非依存 lookup の FS では 1 ファイルに潰れる。
+ * worktree では「削除済み扱いの NFD entry + untracked 扱いの NFC ファイル」が現れて
+ * `scripts/teardown-worktree.sh` の dirty チェックを**常に fail** させ、
+ * `git worktree remove --force` による迂回 → `drop-test-db.php` を通らない →
+ * **孤児テスト DB が単調増加**する、という運用事故の起点になっていた
+ * (docs/tech-debt.md §5-3 / §5-4)。
+ *
+ * `core.precomposeunicode` は **`.git/config` のローカル設定**であってリポジトリの
+ * 恒久対策にならない (clone した各人が設定しない限り効かない)。恒久対策は本ゲートである。
+ *
+ * 本テストは DB を触らない (git index の読み取りのみ)。
+ */
+
+/**
+ * NFC 正規化して衝突する path のグループを返す (純関数)。
+ *
+ * @param  list<string>  $paths  index 上の path 一覧
+ * @return array<string, list<string>> NFC path => 衝突している元 path 群 (2 件以上のみ)
+ */
+function gitIndexNormalizationCollisions(array $paths): array
+{
+    /** @var array<string, list<string>> $byNfc */
+    $byNfc = [];
+    foreach ($paths as $path) {
+        $nfc = Normalizer::normalize($path, Normalizer::FORM_C);
+        Assert::string($nfc, "path を NFC 正規化できない: {$path}");
+        $byNfc[$nfc][] = $path;
+    }
+
+    return array_filter($byNfc, static fn (array $group): bool => count($group) > 1);
+}
+
+/**
+ * `git ls-files -z` で index の全 path を読む。
+ *
+ * 失敗したら **skip せず fail** させる (偽グリーン禁止)。NUL 区切りを壊さないため
+ * shell を介さず proc_open で引数配列のまま起動する。
+ *
+ * @return list<string>
+ */
+function gitIndexTrackedPaths(string $repositoryRoot): array
+{
+    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+    $process = proc_open(['git', 'ls-files', '-z'], $descriptors, $pipes, $repositoryRoot);
+    Assert::true(is_resource($process), 'git ls-files を起動できなかった (テスト環境に git が無い?)');
+
+    $stdout = stream_get_contents($pipes[1]);
+    $stderr = stream_get_contents($pipes[2]);
+    fclose($pipes[1]);
+    fclose($pipes[2]);
+    $exitCode = proc_close($process);
+
+    Assert::same(0, $exitCode, 'git ls-files -z が失敗した: '.(is_string($stderr) ? $stderr : ''));
+    Assert::string($stdout, 'git ls-files -z の出力を取得できなかった');
+
+    return array_values(array_filter(explode("\0", $stdout), static fn (string $p): bool => $p !== ''));
+}
+
+// ── N3: intl / git が使えないなら skip ではなく fail する ──
+
+it('has the intl Normalizer available (fail instead of skipping)', function (): void {
+    expect(extension_loaded('intl'))->toBeTrue()
+        ->and(class_exists(Normalizer::class))->toBeTrue();
+});
+
+// ── N1 + N2: 実 index に衝突が無い / 空振りしていない ──
+
+it('has no NFC-normalization collisions in the git index', function (): void {
+    $paths = gitIndexTrackedPaths(base_path());
+
+    // N2 空振り防止: 規模連動の高い閾値は将来の偽赤になるため、代表 path の存在検査を主にする。
+    expect(count($paths))->toBeGreaterThanOrEqual(50)
+        ->and($paths)->toContain('AGENTS.md')
+        ->and($paths)->toContain('composer.json');
+
+    $collisions = gitIndexNormalizationCollisions($paths);
+
+    $report = implode("\n", array_map(
+        static fn (string $nfc, array $group): string => '  '.$nfc.' <= '.implode(' | ', $group),
+        array_keys($collisions),
+        array_values($collisions),
+    ));
+
+    expect($collisions)->toBe([], <<<TXT
+        git index に NFC 正規化で衝突する path があります (正規化非依存 FS で teardown が壊れます):
+        {$report}
+        重複している側の entry を `git rm --cached` で 1 つに揃えてください
+        (詳細: docs/worktree-isolation-strategy.md §NFC/NFD 正規化)
+        TXT);
+});
+
+// ── N4 / N5: 正コントロール (検出器が本当に検出できること) ──
+
+it('detects an NFD/NFC pair as a collision', function (): void {
+    $nfc = Normalizer::normalize('doc/reference/カテゴリ.png', Normalizer::FORM_C);
+    $nfd = Normalizer::normalize('doc/reference/カテゴリ.png', Normalizer::FORM_D);
+    Assert::string($nfc);
+    Assert::string($nfd);
+    expect($nfc)->not->toBe($nfd); // 前提: 濁点が結合文字に分解される
+
+    $collisions = gitIndexNormalizationCollisions([$nfc, $nfd, 'README.md']);
+
+    expect($collisions)->toHaveCount(1)
+        ->and($collisions[$nfc])->toBe([$nfc, $nfd]);
+});
+
+it('detects a three-way collision as a single group', function (): void {
+    $nfc = Normalizer::normalize('doc/カテゴリ/プレビュー.png', Normalizer::FORM_C);
+    $nfd = Normalizer::normalize('doc/カテゴリ/プレビュー.png', Normalizer::FORM_D);
+    Assert::string($nfc);
+    Assert::string($nfd);
+    // 片側だけ分解した第 3 の表現 (ディレクトリ名は NFD / ファイル名は NFC)
+    $mixedHead = Normalizer::normalize('doc/カテゴリ/', Normalizer::FORM_D);
+    Assert::string($mixedHead);
+    $mixed = $mixedHead.Normalizer::normalize('プレビュー.png', Normalizer::FORM_C);
+
+    $collisions = gitIndexNormalizationCollisions([$nfc, $nfd, $mixed]);
+
+    expect($collisions)->toHaveCount(1)
+        ->and($collisions[$nfc])->toHaveCount(3);
+});
+
+// ── N6 / N7: 負コントロール ──
+
+it('reports no collision for pure NFC paths', function (): void {
+    $paths = ['AGENTS.md', 'composer.json', 'doc/reference/sample-sop/手順書.md'];
+    $normalized = array_map(static function (string $path): string {
+        $nfc = Normalizer::normalize($path, Normalizer::FORM_C);
+        Assert::string($nfc);
+
+        return $nfc;
+    }, $paths);
+
+    expect(gitIndexNormalizationCollisions($normalized))->toBe([]);
+});
+
+it('reports no collision for distinct Japanese paths', function (): void {
+    expect(gitIndexNormalizationCollisions([
+        'doc/reference/カテゴリ.png',
+        'doc/reference/カテゴリー.png',
+        'doc/reference/かてごり.png',
+        'doc/reference/mockups/動画アプリ/01_ログイン.png',
+    ]))->toBe([]);
+});
diff --git a/tests/Support/Ci/TestDatabaseCandidate.php b/tests/Support/Ci/TestDatabaseCandidate.php
new file mode 100644
index 0000000..4930e1f
--- /dev/null
+++ b/tests/Support/Ci/TestDatabaseCandidate.php
@@ -0,0 +1,68 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Ci;
+
+use InvalidArgumentException;
+use Webmozart\Assert\Assert;
+
+/**
+ * 孤児判定の入力 1 件。**境界で検証済みの値だけ**を持つ値オブジェクト。
+ *
+ * `pg_database` の問い合わせ結果は `mixed` 由来なので、純関数
+ * (`TestDatabaseEnv::classifyTestDatabases()`) へ渡す前にここで
+ *   - dev DB denylist に一致しない (`app` / `bug_hunt*`)
+ *   - allowlist regex (`^app_test_[0-9a-f]{8}(_test_[0-9]+)?$`) に一致する
+ *   - hash が `[0-9a-f]{8}`
+ * を検証する。1 つでも崩れたら `InvalidArgumentException` で fail-closed する
+ * (純関数側は `mixed` も未検証名も受け取らない)。
+ */
+final readonly class TestDatabaseCandidate
+{
+    /** worktree hash の形式 (8 桁小文字 hex)。`--protect-hash` / `--include-hash` にも使う。 */
+    public const HASH_PATTERN = '/^[0-9a-f]{8}$/';
+
+    /**
+     * @param  string  $name  実 DB 名 (allowlist 検証済み)
+     * @param  string  $hash  8 桁 worktree hash
+     * @param  bool  $isWorker  paratest worker (`_test_N`) か
+     * @param  string|null  $provenancePath  `COMMENT ON DATABASE` の値 (base のみ / 無ければ null)
+     */
+    public function __construct(
+        public string $name,
+        public string $hash,
+        public bool $isWorker,
+        public ?string $provenancePath,
+    ) {
+        if (TestDatabaseEnv::isDevDatabase($name)) {
+            throw new InvalidArgumentException("refusing to build a sweep candidate for a dev DB: {$name}");
+        }
+        if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
+            throw new InvalidArgumentException("database name is not allowlisted for sweep: {$name}");
+        }
+        Assert::regex($hash, self::HASH_PATTERN, "worktree hash must be 8 lowercase hex chars: {$hash}");
+        Assert::same(
+            TestDatabaseEnv::TEST_DB_PREFIX.$hash,
+            substr($name, 0, strlen(TestDatabaseEnv::TEST_DB_PREFIX) + 8),
+            "hash does not match the database name: {$name} / {$hash}",
+        );
+    }
+
+    /**
+     * DB 名 (+ provenance) から候補を組み立てる。allowlist 違反 / dev DB は例外で弾く。
+     *
+     * @param  string|null  $provenancePath  base DB の `shobj_description` (worker には付かない)
+     */
+    public static function fromDatabaseName(string $name, ?string $provenancePath): self
+    {
+        if (TestDatabaseEnv::isDevDatabase($name)) {
+            throw new InvalidArgumentException("refusing to build a sweep candidate for a dev DB: {$name}");
+        }
+        if (preg_match('/^'.preg_quote(TestDatabaseEnv::TEST_DB_PREFIX, '/').'([0-9a-f]{8})(_test_[0-9]+)?$/', $name, $m) !== 1) {
+            throw new InvalidArgumentException("database name is not allowlisted for sweep: {$name}");
+        }
+
+        return new self($name, $m[1], ($m[2] ?? '') !== '', $provenancePath);
+    }
+}
diff --git a/tests/Support/Ci/TestDatabaseClassification.php b/tests/Support/Ci/TestDatabaseClassification.php
new file mode 100644
index 0000000..2c2ac47
--- /dev/null
+++ b/tests/Support/Ci/TestDatabaseClassification.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Ci;
+
+/**
+ * 孤児テスト DB sweep の分類。
+ *
+ * **分類は「説明」のために行い、削除可否を分類だけで自動決定しない**。
+ * `Orphan` / `Unlabeled` であっても `--include-hash=<hash>` で人間が 1 つずつ名指ししない限り
+ * 1 件も DROP されない (一括フラグは意図的に用意しない)。
+ *
+ * 評価順序 (先に一致したもので確定する) は
+ * `TestDatabaseEnv::classifyTestDatabases()` が固定する:
+ *   Protected → Live → Foreign → Orphan → Unlabeled
+ * `Live` が `Foreign` / `Orphan` より先なのは、`COMMENT ON DATABASE` (provenance) が
+ * **書き換え可能な分類材料であって信頼境界ではない**ため。comment を細工しても
+ * 生存 worktree の DB は落とせない。
+ */
+enum TestDatabaseClassification: string
+{
+    /** `--protect-hash` で明示保護された hash 群 (人間の意思が最優先)。 */
+    case Protected = 'protected';
+
+    /** 生存 worktree の hash 群 (git worktree list 突合)。 */
+    case Live = 'live';
+
+    /** provenance ラベルあり / その path が実在する = 別クローンが生きている可能性が高い。 */
+    case Foreign = 'foreign';
+
+    /** provenance ラベルあり / その path が実在しない = 消えた worktree の残骸の可能性が高い。 */
+    case Orphan = 'orphan';
+
+    /** provenance ラベルなし (本機能より前に作られた legacy / base 不在で worker のみ)。 */
+    case Unlabeled = 'unlabeled';
+}
diff --git a/tests/Support/Ci/TestDatabaseDecision.php b/tests/Support/Ci/TestDatabaseDecision.php
new file mode 100644
index 0000000..bab7cc0
--- /dev/null
+++ b/tests/Support/Ci/TestDatabaseDecision.php
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Ci;
+
+/**
+ * 分類結果 1 件。
+ *
+ * `reason` は dry-run 出力の説明責任のために**必ず具体的な文字列**で持つ
+ * (「なぜ落とす / なぜ残す」を人間が読んで判断できないと `--apply` の承認ができない)。
+ */
+final readonly class TestDatabaseDecision
+{
+    public function __construct(
+        public TestDatabaseCandidate $candidate,
+        public TestDatabaseClassification $classification,
+        public string $reason,
+        public bool $shouldDrop,
+    ) {}
+}
diff --git a/tests/Support/Ci/TestDatabaseEnv.php b/tests/Support/Ci/TestDatabaseEnv.php
index abebc86..199d41b 100644
--- a/tests/Support/Ci/TestDatabaseEnv.php
+++ b/tests/Support/Ci/TestDatabaseEnv.php
@@ -32,8 +32,33 @@ final class TestDatabaseEnv
     /** 実テスト DB 名の許可パターン (base または paratest worker)。不可逆 DROP / bootstrap ガードの正の allow。 */
     public const TEST_DB_ALLOWLIST_PATTERN = '/^app_test_[0-9a-f]{8}(_test_[0-9]+)?$/';
 
-    /** dev DB 名の hard-deny 対象 (docker-compose の POSTGRES_DB / slug 既定値)。trim+lowercase 比較。 */
-    public const DEV_DB_DENYLIST = ['app'];
+    /**
+     * dev DB 名の hard-deny 対象 (docker-compose の POSTGRES_DB / slug 既定値)。trim+lowercase 比較。
+     *
+     * `bug_hunt*` は allowlist regex でも構造的に除外されるが、
+     * 「bug-hunt 環境の DB は絶対に触らない」(AGENTS.md §bug-hunt の dev DB 防御) という
+     * 意図をコードに残す二重防御として明示列挙する。
+     */
+    public const DEV_DB_DENYLIST = [
+        'app',
+        'bug_hunt',
+        'bug_hunt_1',
+        'bug_hunt_2',
+        'bug_hunt_3',
+        'bug_hunt_4',
+        'bug_hunt_5',
+        'bug_hunt_6',
+        'bug_hunt_7',
+        'bug_hunt_8',
+    ];
+
+    /**
+     * 孤児 sweep の分類ロジックのバージョン。
+     *
+     * `--confirm` token の canonical JSON に含める。分類規則を変更したら**必ず上げる**こと
+     * (古い token では apply できなくなる = 規則変更を人間の再承認なしに通過させない)。
+     */
+    public const CLASSIFIER_VERSION = 1;
 
     /** worktree root realpath の決定論的 8 桁 hash。別 worktree との DB 衝突を防ぐキー。 */
     public static function workrootHash(string $projectRoot): string
@@ -99,4 +124,183 @@ public static function isAllowedTestDatabase(string $name): bool
     {
         return preg_match(self::TEST_DB_ALLOWLIST_PATTERN, $name) === 1;
     }
+
+    // ── 孤児テスト DB sweep (drop-test-db.php --orphans) の分類 ──
+
+    /**
+     * 孤児判定。**同一候補が複数条件を満たしても結果が一意**になるよう、
+     * 以下の順に評価して最初に一致した分類で確定する:
+     *
+     *   1. Protected — hash が `--protect-hash` に含まれる          → shouldDrop = false (常に保護)
+     *   2. Live      — hash が生存 worktree hash 集合に含まれる      → shouldDrop = false (常に保護)
+     *   3. Foreign   — hash グループの provenance path が実在する    → shouldDrop = false (常に保護)
+     *   4. Orphan    — hash グループの provenance path が実在しない  → shouldDrop = (hash ∈ includeHashes)
+     *   5. Unlabeled — hash グループに provenance が無い            → shouldDrop = (hash ∈ includeHashes)
+     *
+     * - 1 が 2 より先: 明示保護は生存判定より強い (人間の意思を最優先)
+     * - 2 が 3 より先: comment は書き換え可能な**分類材料**にすぎず、生存 worktree の突合が優先する
+     *   (= comment を細工しても生存 DB は落とせない)
+     * - 3 が 4 より先: path が実在する = 誰かが使っている可能性がある側へ倒す (fail-safe)
+     * - 4 / 5 は「現在のクローンから生存を**否定できない**」群なので、**どちらも明示指定制**にする
+     * - worker DB (`_test_N`) は base と同じ hash グループの分類を継承する (base の provenance が代表)
+     *
+     * **中核原則: 削除可否を分類だけで自動決定しない。**
+     * `$includeHashes` (= `--include-hash`) で人間が 1 つずつ名指しした hash 以外は 1 件も落ちない。
+     *
+     * @param  list<TestDatabaseCandidate>  $candidates
+     * @param  list<string>  $liveHashes  生存 worktree の hash
+     * @param  list<string>  $protectedHashes  `--protect-hash`
+     * @param  list<string>  $includeHashes  `--include-hash` (Orphan / Unlabeled をこの hash に限り候補化)
+     * @param  (callable(string): bool)|null  $pathExists  provenance path の実在判定。
+     *                                                     既定は `is_dir()`。**注入すると本メソッドは純関数になる**
+     *                                                     (FS を触らずに Foreign/Orphan 分岐を固定できる)
+     * @return list<TestDatabaseDecision>
+     */
+    public static function classifyTestDatabases(
+        array $candidates,
+        array $liveHashes,
+        array $protectedHashes,
+        array $includeHashes,
+        ?callable $pathExists = null,
+    ): array {
+        $exists = $pathExists ?? static fn (string $path): bool => is_dir($path);
+
+        $live = self::normalizeHashList($liveHashes, '--live hash');
+        $protected = self::normalizeHashList($protectedHashes, '--protect-hash');
+        $include = self::normalizeHashList($includeHashes, '--include-hash');
+
+        // provenance は **base DB の comment のみ**を hash グループ全体の出自として扱う。
+        // base 不在で worker だけ残っている群は provenance を持たない = Unlabeled になる。
+        /** @var array<string, string> $groupProvenance */
+        $groupProvenance = [];
+        foreach ($candidates as $candidate) {
+            if (! $candidate->isWorker && $candidate->provenancePath !== null && $candidate->provenancePath !== '') {
+                $groupProvenance[$candidate->hash] = $candidate->provenancePath;
+            }
+        }
+
+        $decisions = [];
+        foreach ($candidates as $candidate) {
+            $hash = $candidate->hash;
+            $provenance = $groupProvenance[$hash] ?? null;
+            $inherited = $candidate->isWorker ? ' (base の分類を継承)' : '';
+
+            if (in_array($hash, $protected, true)) {
+                $decisions[] = new TestDatabaseDecision(
+                    $candidate,
+                    TestDatabaseClassification::Protected,
+                    "--protect-hash={$hash} で明示保護{$inherited}",
+                    false,
+                );
+
+                continue;
+            }
+            if (in_array($hash, $live, true)) {
+                $decisions[] = new TestDatabaseDecision(
+                    $candidate,
+                    TestDatabaseClassification::Live,
+                    "生存 worktree の hash{$inherited}",
+                    false,
+                );
+
+                continue;
+            }
+            if ($provenance !== null && $exists($provenance)) {
+                $decisions[] = new TestDatabaseDecision(
+                    $candidate,
+                    TestDatabaseClassification::Foreign,
+                    "provenance path が実在する (別クローンが生きている可能性): {$provenance}{$inherited}",
+                    false,
+                );
+
+                continue;
+            }
+
+            $named = in_array($hash, $include, true);
+            if ($provenance !== null) {
+                $decisions[] = new TestDatabaseDecision(
+                    $candidate,
+                    TestDatabaseClassification::Orphan,
+                    $named
+                        ? "provenance path が不在で --include-hash={$hash} に名指しされている: {$provenance}{$inherited}"
+                        : "provenance path が不在 (落とすには --include-hash={$hash} が必要): {$provenance}{$inherited}",
+                    $named,
+                );
+
+                continue;
+            }
+
+            $decisions[] = new TestDatabaseDecision(
+                $candidate,
+                TestDatabaseClassification::Unlabeled,
+                $named
+                    ? "provenance ラベルなしで --include-hash={$hash} に名指しされている{$inherited}"
+                    : "provenance ラベルなし (落とすには --include-hash={$hash} が必要){$inherited}",
+                $named,
+            );
+        }
+
+        return $decisions;
+    }
+
+    /**
+     * `--apply` の confirm token。
+     *
+     * canonical JSON (キー順固定 / 要素は昇順 unique の JSON 配列) の SHA-256 **全長 64 桁**。
+     * 区切りなしの連結は `["a_b","c"]` と `["a","b_c"]` を区別できないため、必ず JSON 配列にする。
+     * `include_hashes` は「どの群を落とすことを人間が承認したか」= 承認文脈の一部なので含める。
+     * `classifier_version` は「分類規則を変えたら古い token を無効化する」ために含める。
+     *
+     * @param  list<string>  $dropTargets  DROP 対象の DB 名
+     * @param  list<string>  $liveHashes
+     * @param  list<string>  $protectedHashes
+     * @param  list<string>  $includeHashes
+     */
+    public static function orphanConfirmToken(
+        array $dropTargets,
+        array $liveHashes,
+        array $protectedHashes,
+        array $includeHashes,
+    ): string {
+        $sorted = static function (array $values): array {
+            /** @var list<string> $values */
+            $unique = array_values(array_unique($values));
+            sort($unique, SORT_STRING);
+
+            return $unique;
+        };
+
+        $canonical = json_encode([
+            'classifier_version' => self::CLASSIFIER_VERSION,
+            // キー名を 'orphans' にしないのは、実際の対象が Orphan だけでなく
+            // Unlabeled も含む「--include-hash で名指しされた DROP 対象」だから。
+            'drop_targets' => $sorted($dropTargets),
+            'live_hashes' => $sorted($liveHashes),
+            'protected' => $sorted($protectedHashes),
+            'include_hashes' => $sorted($includeHashes),
+        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+
+        return hash('sha256', $canonical);
+    }
+
+    /**
+     * hash 引数を検証して昇順 unique に正規化する。形式違反は即例外 (fail-closed)。
+     *
+     * @param  list<string>  $hashes
+     * @return list<string>
+     */
+    private static function normalizeHashList(array $hashes, string $label): array
+    {
+        foreach ($hashes as $hash) {
+            Assert::regex(
+                $hash,
+                TestDatabaseCandidate::HASH_PATTERN,
+                "{$label} must be 8 lowercase hex chars: {$hash}",
+            );
+        }
+        $unique = array_values(array_unique($hashes));
+        sort($unique, SORT_STRING);
+
+        return $unique;
+    }
 }
diff --git a/tests/Unit/Ci/TestDatabaseClassificationTest.php b/tests/Unit/Ci/TestDatabaseClassificationTest.php
new file mode 100644
index 0000000..8145ef4
--- /dev/null
+++ b/tests/Unit/Ci/TestDatabaseClassificationTest.php
@@ -0,0 +1,418 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Ci\TestDatabaseCandidate;
+use Tests\Support\Ci\TestDatabaseClassification;
+use Tests\Support\Ci\TestDatabaseDecision;
+use Tests\Support\Ci\TestDatabaseEnv;
+
+/*
+ * 孤児テスト DB sweep (`drop-test-db.php --orphans`) の分類ロジックと
+ * confirm token の Unit テスト。
+ *
+ * 固定する不変条件:
+ *   1. 分類優先順位 Protected → Live → Foreign → Orphan → Unlabeled が一意に決まる
+ *      (Live が Foreign/Orphan より先 = provenance コメントを細工しても生存 DB は落とせない)
+ *   2. **削除可否を分類だけで自動決定しない**。Orphan も Unlabeled も
+ *      `--include-hash` で 1 つずつ名指ししない限り shouldDrop = false
+ *   3. dev DB (`app` / `bug_hunt*`) と allowlist 外は候補生成の時点で例外 (境界で弾く)
+ *   4. token は canonical JSON の SHA-256 全長で、入力順に依存せず、
+ *      include_hashes / classifier_version の違いを必ず反映する
+ *
+ * 本テストは DB を触らない (純関数のみ。path 実在判定も注入する)。
+ */
+
+/** provenance path の実在判定を注入するためのヘルパ (FS に触らず Foreign/Orphan を作り分ける)。 */
+function ciPathExists(string ...$existing): callable
+{
+    return static fn (string $path): bool => in_array($path, $existing, true);
+}
+
+/**
+ * hash 群の base + worker DB 候補を作る。
+ *
+ * @return list<TestDatabaseCandidate>
+ */
+function ciGroup(string $hash, ?string $provenance, int $workers = 4): array
+{
+    $candidates = [new TestDatabaseCandidate("app_test_{$hash}", $hash, false, $provenance)];
+    for ($i = 1; $i <= $workers; $i++) {
+        $candidates[] = new TestDatabaseCandidate("app_test_{$hash}_test_{$i}", $hash, true, null);
+    }
+
+    return $candidates;
+}
+
+/**
+ * @param  list<TestDatabaseDecision>  $decisions
+ * @return array<string, TestDatabaseDecision>
+ */
+function ciByName(array $decisions): array
+{
+    $byName = [];
+    foreach ($decisions as $decision) {
+        $byName[$decision->candidate->name] = $decision;
+    }
+
+    return $byName;
+}
+
+// ── T-C2-1 / T-C2-11: live (base + worker 5 件が同じ分類) ──
+
+it('classifies a live worktree hash group as Live and never drops it', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('8af22c44', '/workspace'),
+        ['8af22c44'],
+        [],
+        [],
+        ciPathExists('/workspace'),
+    );
+
+    expect($decisions)->toHaveCount(5);
+    foreach ($decisions as $decision) {
+        expect($decision->classification)->toBe(TestDatabaseClassification::Live)
+            ->and($decision->shouldDrop)->toBeFalse();
+    }
+});
+
+// ── T-C2-2 / T-C2-19: orphan は --include-hash なしでは落ちない ──
+
+it('classifies a labelled group with a missing path as Orphan but does not drop it by default', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('3a7d6b4e', '/gone/worktree'),
+        ['8af22c44'],
+        [],
+        [],
+        ciPathExists('/workspace'),
+    );
+
+    foreach ($decisions as $decision) {
+        expect($decision->classification)->toBe(TestDatabaseClassification::Orphan)
+            ->and($decision->shouldDrop)->toBeFalse();
+    }
+});
+
+// ── T-C2-3: foreign ──
+
+it('classifies a labelled group whose path still exists as Foreign', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('823cbbd2', '/other/clone'),
+        ['8af22c44'],
+        [],
+        ['823cbbd2'], // 名指ししても Foreign は落ちない
+        ciPathExists('/workspace', '/other/clone'),
+    );
+
+    foreach ($decisions as $decision) {
+        expect($decision->classification)->toBe(TestDatabaseClassification::Foreign)
+            ->and($decision->shouldDrop)->toBeFalse();
+    }
+});
+
+// ── T-C2-4: 優先順位 1 (Protected) が 2 (Live) / 5 (Unlabeled) に勝つ ──
+
+it('gives --protect-hash precedence over live and unlabeled classification', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('8af22c44', null),
+        ['8af22c44'],
+        ['8af22c44'],
+        ['8af22c44'],
+        ciPathExists(),
+    );
+
+    foreach ($decisions as $decision) {
+        expect($decision->classification)->toBe(TestDatabaseClassification::Protected)
+            ->and($decision->shouldDrop)->toBeFalse();
+    }
+});
+
+// ── T-C2-5: 優先順位 2 (Live) が 4 (Orphan) に勝つ = comment 細工で生存 DB を落とせない ──
+
+it('keeps a live hash as Live even when its provenance comment points at a missing path', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('8af22c44', '/tampered/does-not-exist'),
+        ['8af22c44'],
+        [],
+        ['8af22c44'],
+        ciPathExists('/workspace'),
+    );
+
+    foreach ($decisions as $decision) {
+        expect($decision->classification)->toBe(TestDatabaseClassification::Live)
+            ->and($decision->shouldDrop)->toBeFalse();
+    }
+});
+
+// ── T-C2-6 / T-C2-7 / T-C2-7b: unlabeled ──
+
+it('classifies a group without provenance as Unlabeled and keeps it by default', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('018d63c6', null, 0),
+        ['8af22c44'],
+        [],
+        [],
+        ciPathExists('/workspace'),
+    );
+
+    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled)
+        ->and($decisions[0]->shouldDrop)->toBeFalse();
+});
+
+it('drops an Unlabeled group only when its own hash is named by --include-hash', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('018d63c6', null, 0),
+        ['8af22c44'],
+        [],
+        ['018d63c6'],
+        ciPathExists('/workspace'),
+    );
+
+    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled)
+        ->and($decisions[0]->shouldDrop)->toBeTrue();
+});
+
+it('does not drag a different Unlabeled hash along with --include-hash', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        [...ciGroup('018d63c6', null, 0), ...ciGroup('91c7197b', null, 0)],
+        ['8af22c44'],
+        [],
+        ['018d63c6'],
+        ciPathExists('/workspace'),
+    );
+
+    $byName = ciByName($decisions);
+    expect($byName['app_test_018d63c6']->shouldDrop)->toBeTrue()
+        ->and($byName['app_test_91c7197b']->shouldDrop)->toBeFalse();
+});
+
+// ── T-C2-8 / T-C2-9 / T-C2-10: 境界で弾く ──
+
+it('refuses to build a candidate for the dev database', function (): void {
+    TestDatabaseCandidate::fromDatabaseName('app', null);
+})->throws(InvalidArgumentException::class);
+
+it('refuses to build a candidate for bug-hunt databases', function (string $name): void {
+    TestDatabaseCandidate::fromDatabaseName($name, null);
+})->with(['bug_hunt', 'bug_hunt_3', 'bug_hunt_8'])->throws(InvalidArgumentException::class);
+
+it('refuses to build a candidate for names outside the allowlist', function (string $name): void {
+    TestDatabaseCandidate::fromDatabaseName($name, null);
+})->with([
+    'app_test_XYZ',
+    'app_test_8af22c44_backup',
+    'app_test_8AF22C44',
+    'app_test_8af22c4',
+    'postgres',
+])->throws(InvalidArgumentException::class);
+
+it('rejects a candidate whose hash does not match its database name', function (): void {
+    new TestDatabaseCandidate('app_test_8af22c44', 'deadbeef', false, null);
+})->throws(InvalidArgumentException::class);
+
+// ── T-C2-11 / T-C2-12: worker は base の分類を継承 / base 不在は Unlabeled ──
+
+it('inherits the base classification for paratest worker databases', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('3a7d6b4e', '/gone'),
+        [],
+        [],
+        ['3a7d6b4e'],
+        ciPathExists('/workspace'),
+    );
+
+    $byName = ciByName($decisions);
+    expect($byName['app_test_3a7d6b4e_test_2']->classification)->toBe(TestDatabaseClassification::Orphan)
+        ->and($byName['app_test_3a7d6b4e_test_2']->shouldDrop)->toBeTrue()
+        ->and($byName['app_test_3a7d6b4e_test_2']->reason)->toContain('base の分類を継承');
+});
+
+it('classifies worker-only groups (base already gone) as Unlabeled', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        [new TestDatabaseCandidate('app_test_91c7197b_test_1', '91c7197b', true, null)],
+        [],
+        [],
+        [],
+        ciPathExists('/workspace'),
+    );
+
+    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled)
+        ->and($decisions[0]->shouldDrop)->toBeFalse();
+});
+
+it('ignores a provenance comment attached to a worker database (base is the only source)', function (): void {
+    // worker に細工でラベルが付いても、hash グループの出自は base の comment だけが代表する。
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        [new TestDatabaseCandidate('app_test_91c7197b_test_1', '91c7197b', true, '/workspace')],
+        [],
+        [],
+        [],
+        ciPathExists('/workspace'),
+    );
+
+    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled);
+});
+
+// ── T-C2-13 / T-C2-14 / T-C2-15 / T-C2-15b: token ──
+
+it('produces a stable full-length sha256 token for the same input', function (): void {
+    $a = TestDatabaseEnv::orphanConfirmToken(['app_test_3a7d6b4e'], ['8af22c44'], [], ['3a7d6b4e']);
+    $b = TestDatabaseEnv::orphanConfirmToken(['app_test_3a7d6b4e'], ['8af22c44'], [], ['3a7d6b4e']);
+
+    expect($a)->toBe($b)->and($a)->toMatch('/^[0-9a-f]{64}$/');
+});
+
+it('changes the token when a single drop target changes', function (): void {
+    $a = TestDatabaseEnv::orphanConfirmToken(['app_test_3a7d6b4e'], ['8af22c44'], [], ['3a7d6b4e']);
+    $b = TestDatabaseEnv::orphanConfirmToken(
+        ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1'],
+        ['8af22c44'],
+        [],
+        ['3a7d6b4e'],
+    );
+
+    expect($a)->not->toBe($b);
+});
+
+it('distinguishes element boundaries because the token is canonical JSON', function (): void {
+    // 区切りなしの連結だと ["a_b","c"] と ["a","b_c"] が同じ文字列になり衝突する。
+    $a = TestDatabaseEnv::orphanConfirmToken(['a_b', 'c'], [], [], []);
+    $b = TestDatabaseEnv::orphanConfirmToken(['a', 'b_c'], [], [], []);
+
+    expect($a)->not->toBe($b);
+});
+
+it('is independent of the input ordering (ascending sort)', function (): void {
+    $a = TestDatabaseEnv::orphanConfirmToken(
+        ['app_test_823cbbd2', 'app_test_3a7d6b4e'],
+        ['b4f0102e', '8af22c44'],
+        ['91c7197b', '018d63c6'],
+        ['823cbbd2', '3a7d6b4e'],
+    );
+    $b = TestDatabaseEnv::orphanConfirmToken(
+        ['app_test_3a7d6b4e', 'app_test_823cbbd2'],
+        ['8af22c44', 'b4f0102e'],
+        ['018d63c6', '91c7197b'],
+        ['3a7d6b4e', '823cbbd2'],
+    );
+
+    expect($a)->toBe($b);
+});
+
+it('changes the token when include_hashes changes', function (): void {
+    $a = TestDatabaseEnv::orphanConfirmToken([], ['8af22c44'], [], ['3a7d6b4e']);
+    $b = TestDatabaseEnv::orphanConfirmToken([], ['8af22c44'], [], ['823cbbd2']);
+
+    expect($a)->not->toBe($b);
+});
+
+it('binds the token to the classifier version', function (): void {
+    $canonical = static fn (int $version): string => hash('sha256', json_encode([
+        'classifier_version' => $version,
+        'drop_targets' => [],
+        'live_hashes' => ['8af22c44'],
+        'protected' => [],
+        'include_hashes' => [],
+    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
+
+    expect(TestDatabaseEnv::orphanConfirmToken([], ['8af22c44'], [], []))
+        ->toBe($canonical(TestDatabaseEnv::CLASSIFIER_VERSION))
+        ->and($canonical(TestDatabaseEnv::CLASSIFIER_VERSION))
+        ->not->toBe($canonical(TestDatabaseEnv::CLASSIFIER_VERSION + 1));
+});
+
+// ── T-C2-16: hash 引数の形式検証 ──
+
+it('rejects malformed --include-hash / --protect-hash values', function (string $hash): void {
+    TestDatabaseEnv::classifyTestDatabases([], [], [], [$hash], ciPathExists());
+})->with(['ZZZZZZZZ', '8af22c4', '8af22c444', '8AF22C44', '', '8af22c4g'])
+    ->throws(InvalidArgumentException::class);
+
+it('rejects malformed hashes in the token input as well', function (): void {
+    TestDatabaseEnv::classifyTestDatabases([], ['nothex!!'], [], [], ciPathExists());
+})->throws(InvalidArgumentException::class);
+
+// ── T-C2-20 / T-C2-21 ──
+
+it('drops an Orphan group only when its hash is named by --include-hash', function (): void {
+    $without = TestDatabaseEnv::classifyTestDatabases(ciGroup('b4f0102e', '/gone', 0), [], [], [], ciPathExists());
+    $with = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('b4f0102e', '/gone', 0),
+        [],
+        [],
+        ['b4f0102e'],
+        ciPathExists(),
+    );
+
+    expect($without[0]->shouldDrop)->toBeFalse()
+        ->and($with[0]->classification)->toBe(TestDatabaseClassification::Orphan)
+        ->and($with[0]->shouldDrop)->toBeTrue();
+});
+
+it('never drops Protected or Live groups even when they are named by --include-hash', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        [...ciGroup('8af22c44', null, 0), ...ciGroup('3a7d6b4e', null, 0)],
+        ['3a7d6b4e'],
+        ['8af22c44'],
+        ['8af22c44', '3a7d6b4e'],
+        ciPathExists(),
+    );
+
+    $byName = ciByName($decisions);
+    expect($byName['app_test_8af22c44']->classification)->toBe(TestDatabaseClassification::Protected)
+        ->and($byName['app_test_8af22c44']->shouldDrop)->toBeFalse()
+        ->and($byName['app_test_3a7d6b4e']->classification)->toBe(TestDatabaseClassification::Live)
+        ->and($byName['app_test_3a7d6b4e']->shouldDrop)->toBeFalse();
+});
+
+// ── T-C2-22: namespace 差で path が見えないケースも保護される ──
+
+it('protects a group whose provenance path is invisible from this namespace', function (): void {
+    // bind mount の差などで is_dir() が false になるだけで他人の生存 DB が消えてはならない。
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('823cbbd2', '/mnt/other-namespace/clone', 0),
+        [],
+        [],
+        [],
+        ciPathExists(), // 何も見えない
+    );
+
+    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Orphan)
+        ->and($decisions[0]->shouldDrop)->toBeFalse();
+});
+
+// ── 既定の path 実在判定 (注入しない実運用経路) が働くこと ──
+
+it('uses is_dir() as the default path existence probe', function (): void {
+    $existing = realpath(sys_get_temp_dir());
+    expect($existing)->toBeString();
+
+    $foreign = TestDatabaseEnv::classifyTestDatabases(ciGroup('823cbbd2', (string) $existing, 0), [], [], [], null);
+    $orphan = TestDatabaseEnv::classifyTestDatabases(
+        ciGroup('823cbbd2', $existing.'/definitely-not-here-'.bin2hex(random_bytes(6)), 0),
+        [],
+        [],
+        [],
+        null,
+    );
+
+    expect($foreign[0]->classification)->toBe(TestDatabaseClassification::Foreign)
+        ->and($orphan[0]->classification)->toBe(TestDatabaseClassification::Orphan);
+});
+
+// ── 分類結果は必ず具体的な理由を持つ (dry-run の説明責任) ──
+
+it('always carries a concrete reason string', function (): void {
+    $decisions = TestDatabaseEnv::classifyTestDatabases(
+        [...ciGroup('8af22c44', '/workspace', 1), ...ciGroup('3a7d6b4e', '/gone', 1), ...ciGroup('018d63c6', null, 1)],
+        ['8af22c44'],
+        [],
+        [],
+        ciPathExists('/workspace'),
+    );
+
+    expect($decisions)->toHaveCount(6);
+    foreach ($decisions as $decision) {
+        expect($decision->reason)->not->toBe('');
+    }
+});
diff --git a/tests/Unit/Ci/TestDatabaseEnvTest.php b/tests/Unit/Ci/TestDatabaseEnvTest.php
index 1ba783b..17a14c3 100644
--- a/tests/Unit/Ci/TestDatabaseEnvTest.php
+++ b/tests/Unit/Ci/TestDatabaseEnvTest.php
@@ -90,6 +90,40 @@
     'APP',
 ]);
 
+// bug-hunt 環境の DB は allowlist regex でも構造的に除外されるが、
+// 「絶対に触らない」意図を denylist にも明示する二重防御 (AGENTS.md §bug-hunt)。
+it('hard-denies bug-hunt databases', function (string $variant): void {
+    expect(TestDatabaseEnv::isDevDatabase($variant))->toBeTrue()
+        ->and(TestDatabaseEnv::isAllowedTestDatabase($variant))->toBeFalse();
+})->with([
+    'bug_hunt',
+    'bug_hunt_1',
+    'bug_hunt_8',
+    'BUG_HUNT_3',
+    ' bug_hunt_5 ',
+]);
+
+it('covers every bug-hunt shard database in the denylist', function (): void {
+    // shard は :8011..:8018 = bug_hunt_1..8 (scripts/bug-hunt-shard.sh)。取りこぼしを機械検出する。
+    $expected = ['app', 'bug_hunt'];
+    for ($i = 1; $i <= 8; $i++) {
+        $expected[] = "bug_hunt_{$i}";
+    }
+
+    expect(TestDatabaseEnv::DEV_DB_DENYLIST)->toBe($expected);
+});
+
+it('does not deny unrelated names that merely start with bug_hunt', function (): void {
+    expect(TestDatabaseEnv::isDevDatabase('bug_hunt_9'))->toBeFalse()
+        ->and(TestDatabaseEnv::isDevDatabase('bug_hunts'))->toBeFalse()
+        // allowlist に載らないので DROP 経路には到達しない (denylist は二重防御の側)
+        ->and(TestDatabaseEnv::isAllowedTestDatabase('bug_hunt_9'))->toBeFalse();
+});
+
+it('assertPgsqlTestDatabaseSafe throws on bug-hunt databases', function (): void {
+    TestDatabaseEnv::assertPgsqlTestDatabaseSafe('bug_hunt_3');
+})->throws(InvalidArgumentException::class);
+
 it('does not flag test databases as dev', function (): void {
     expect(TestDatabaseEnv::isDevDatabase('app_test_deadbeef'))->toBeFalse()
         ->and(TestDatabaseEnv::isDevDatabase('app_testx'))->toBeFalse();
diff --git a/tests/Unit/Ci/TestDatabaseProvenanceTest.php b/tests/Unit/Ci/TestDatabaseProvenanceTest.php
new file mode 100644
index 0000000..63452bb
--- /dev/null
+++ b/tests/Unit/Ci/TestDatabaseProvenanceTest.php
@@ -0,0 +1,85 @@
+<?php
+
+declare(strict_types=1);
+
+require_once __DIR__.'/../../../scripts/ci/pgsql_test_conn.php';
+
+/*
+ * ensure-test-db.php が付ける provenance ラベル (COMMENT ON DATABASE) の Unit テスト。
+ *
+ * 固定する不変条件:
+ *   1. ensure の plan は **作成時・既存時の両方**で StampProvenance を含む (= 冪等。
+ *      ここを片方だけにすると「ラベルの無い現役 DB」が生まれ、孤児 sweep の分類材料が欠ける)
+ *   2. COMMENT 文のリテラルは **PDO::quote() 経由**で組み立てる (独自連結しない。
+ *      provenance の realpath に `'` が含まれうる)
+ *   3. ラベル付与は **best-effort**。失敗しても例外を伝播させずテスト実行を止めない
+ *      (権限差で偽赤を増やさない。危険の本体「ラベル無し DB の一括 DROP」は
+ *       --include-hash の明示指定制で潰してある)
+ *
+ * 本テストは実 DB を作らない (SQL 文字列の生成と callable の注入のみ)。
+ */
+
+// ── T-C2-17: plan は両分岐とも StampProvenance を含む ──
+
+it('plans create + stamp when the base database does not exist yet', function (): void {
+    expect(testDatabaseEnsurePlan(false))->toBe([
+        TestDatabaseEnsureAction::Create,
+        TestDatabaseEnsureAction::StampProvenance,
+    ]);
+});
+
+it('still plans a stamp when the base database already exists (idempotent labelling)', function (): void {
+    expect(testDatabaseEnsurePlan(true))->toBe([TestDatabaseEnsureAction::StampProvenance]);
+});
+
+it('never plans a create for an existing database', function (): void {
+    expect(testDatabaseEnsurePlan(true))->not->toContain(TestDatabaseEnsureAction::Create);
+});
+
+// ── T-C2-17b: 識別子 / リテラルのクォート ──
+
+it('quotes the identifier and delegates the literal to PDO::quote', function (): void {
+    $pdo = new PDO('sqlite::memory:');
+
+    expect(pgsqlCommentDatabaseSql($pdo, 'app_test_8af22c44', '/workspace'))
+        ->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
+});
+
+it('escapes single quotes in the provenance path via PDO::quote (no manual concatenation)', function (): void {
+    $pdo = new PDO('sqlite::memory:');
+
+    $sql = pgsqlCommentDatabaseSql($pdo, 'app_test_8af22c44', "/home/o'brien/repo");
+
+    expect($sql)->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/home/o\'\'brien/repo\'')
+        // 生の `'` が閉じられないまま残っていないこと (クォート漏れの回帰検出)
+        ->and(substr_count($sql, "'") % 2)->toBe(0);
+});
+
+it('quotes a double quote inside the identifier', function (): void {
+    $pdo = new PDO('sqlite::memory:');
+
+    expect(pgsqlCommentDatabaseSql($pdo, 'weird"name', '/workspace'))
+        ->toStartWith('COMMENT ON DATABASE "weird""name" IS ');
+});
+
+// ── T-C2-18 / T-C2-18b: best-effort な stamp ──
+
+it('returns true and passes the COMMENT statement through when the exec succeeds', function (): void {
+    $seen = null;
+    $result = pgsqlStampProvenance(function (string $sql) use (&$seen): int {
+        $seen = $sql;
+
+        return 1;
+    }, 'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
+
+    expect($result)->toBeTrue()
+        ->and($seen)->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
+});
+
+it('swallows exec failures so that a permission difference cannot break the test lane', function (): void {
+    $result = pgsqlStampProvenance(static function (string $sql): never {
+        throw new RuntimeException('permission denied for database');
+    }, 'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
+
+    expect($result)->toBeFalse();
+});
```

## git index 是正 (施策 C1) の実測

実装時に自分で再検証した 4 条件 (すべて設計の実測値と一致):

```
  [OK ] index entry 総数: 197 (期待 197)
  [OK ] NFC 正規化衝突グループ: 58 (期待 58)
  [OK ] 衝突グループのサイズ (2 以外の数): 0 (期待 0)
  [OK ] blob が異なるグループ: 0 (期待 0)
  [OK ] NFC 形 entry を持たないグループ: 0 (期待 0)
  [OK ] index 総数 - 衝突数: 139 (期待 139)
  [OK ] 作業ツリーの実体: 139 (期待 139)
  [OK ] 削除対象 (NFD) entry: 58 (期待 58)
  NFD の内訳: {'mockups': 57, 'scenarios': 1}
```

`git rm --cached --pathspec-from-file=<NUL 区切り> --pathspec-file-nul` 実行後の受入条件:

```
V-C1 index entries: 139 (期待 139)
V-C2 実体ファイル: 139 (期待 139)        ← 作業ツリーは 1 件も減っていない
V-C3 NFC 衝突グループ (index 全体): 0 (期待 0)
V-C4 index-map-before.txt (139 key) と index-map-after.txt (139 key) が完全一致 (diff 0)
V-C5 doc/reference の status: '^D ' 58 件 / 列 2 が非空白 0 件 / '??' 0 件 / 総行数 58
V-C7 doc/reference/sample-sop の 5 件は index に健在 (SopTextExtractorTest green)
```

**設計から意図的に逸脱した点 (レビュー対象)**:

1. `classifyTestDatabases()` に **第 5 引数 `?callable $pathExists = null` を追加**した
   (既定は `is_dir()`)。設計の署名は 4 引数だが「純関数」と明記されており、`is_dir()` を
   直接呼ぶと純関数にならない。設計自身が `pgsqlStampProvenance(callable $exec, ...)` で
   採っている「境界を注入する」パターンに合わせた。既定挙動は設計どおりで、実運用経路
   (`drop-test-db.php`) は注入せず `is_dir()` を使う (その経路も 1 本テストで固定している)。
2. 本 worktree は **修正前の index から checkout された**ため、
   `doc/reference/mockups/動画アプリ/SP_アプリ/` が **NFD 形のディレクトリ名で on-disk 作成**
   されており、index 是正後にそこだけ 14 件 untracked として残った。
   **ディレクトリ名のみを NFC へ改名**して解消した (中身のファイル・blob・index は不変)。
   main (`/workspace`) の on-disk NFD path は実測 **0 件**なので、修正後の index から
   checkout する worktree では発生しない。この経緯は
   `devnotes/20260805-2017-todo-T114/rollback.md` に記録している。
3. `drop-test-db.php` の DROP ループを `dropTestDbDropAll()` へ抽出した
   (従来経路と `--apply` が**同一の DDL 実行点**を通ることを構造で担保するため)。
   従来の無引数経路の挙動は変えていない (実走で回帰確認済み)。

## テスト結果

```
composer test        : 3087 passed / 0 failed / 2 skipped (main baseline 3026 passed → +61)
composer phpstan     : level 10 No errors (786 files)
vendor/bin/pint --test: passed
pnpm lint / typecheck : passed
pnpm test            : 1213 passed (124 files)
pnpm build           : built
pnpm typecheck:packages / build:packages : passed
pnpm test:packages   : 106 passed (10 files)
scripts/verify-global-test-lock.sh : passed=68 failed=0 skipped=0
scripts/bug-hunt-inventory-check.sh : exit 0
pnpm run audit:gate  : advisories 0 / Gate passed
```

## 孤児 DB の dry-run 実測 (実 DROP は実行していない)

```
生存 worktree hash: 0b1513d9 (T114 worktree) / 8af22c44 (/workspace)
Live      : app_test_8af22c44 + _test_1..4 (5) / app_test_0b1513d9 (1)
Unlabeled : app_test_3a7d6b4e(+4) / app_test_823cbbd2(+4) / app_test_b4f0102e(+4)
            / app_test_018d63c6 / app_test_91c7197b  = 17 件
Foreign   : 0 / Orphan: 0 / Protected: 0
skip      : app (dev DB denylist) / postgres (allowlist 外)
DROP 対象 : 0 (--include-hash を 1 つも指定していないため)
```

エラー経路も実走確認済み: 未知の引数 / 不正 hash / `--apply` に `--confirm` 無し /
`--orphans` 無しの `--apply` / token 不一致 (lock 取得後に中止・exit 1) はすべて fail-closed。

**孤児 DB の実 DROP (`--apply`) は実行していない** (ユーザーの明示承認が必要な操作のため)。
