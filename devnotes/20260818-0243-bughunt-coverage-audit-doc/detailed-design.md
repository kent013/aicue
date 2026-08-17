# 詳細設計: bug-hunt 網羅監査文書 (coverage-audit) の新設

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作
4. `response()->json()` の直書き 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き
7. 操作系 POST での `redirect()->intended()` 8. 必須条件未充足での disabled ボタン 9. Artifact の使用

本施策は**製品コード (`app/`) の振る舞いを変えない**。触れる `app/` は 1 ファイルのコメントだけである
(施策 6)。

### コーディングルール

- PHP は `declare(strict_types=1)` + 日本語コメント。PHPStan level 10 / Pint。
- Python は**標準ライブラリのみ** (`.claude/skills/app-bug-hunt/coverage/` の既存規約)。
- テストファースト。fail を確認してから実装に入る (思考原則 5)。
- 新規 `.md` / `.py` は `coverage/test_naming_no_stale.py` の禁止語走査の対象に入る
  (旧付番・旧 fail-open 文言を書かない)。
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `python3 -m unittest` (coverage ディレクトリ) / `scripts/bug-hunt-inventory-check.sh`。

## 概念設計リファレンス

- [`conceptual-design.md`](./conceptual-design.md) (Codex 合議 Round 2 で **APPROVED**)
- 家系の正典: lctl feature `bughunt-coverage-audit` (canonical_version **t1** =
  「集計器は現状維持 + 監査文書のうち『対象外の理由と代替検証』だけをデータ化」)
- 参照実装: aigenba `.claude/skills/app-bug-hunt/coverage-audit.md` (49 行) +
  `coverage/out-of-scope.json` (11 件) + `coverage/out_of_scope.py`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 対象外宣言 (データ) の新設 | `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` (新規) | Critical |
| 2 | 読み取り器・検証器・出力器 | `.claude/skills/app-bug-hunt/coverage/out_of_scope.py` (新規) | Critical |
| 3 | 自己テスト (契約ごとに 1 本) | `.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py` (新規) | Critical |
| 4 | `composer test` への配線 | `tests/Architecture/BughuntCoverageToolSelfTest.php` | Critical |
| 5 | 監査文書の新設 | `.claude/skills/app-bug-hunt/coverage-audit.md` (新規) | Critical |
| 6 | 「pcov 未導入」という古い前提の訂正 + 古い断定の再混入検知 | `.claude/skills/app-bug-hunt/coverage/README.md` / `coverage/merge_pcov.py` / `coverage/test_naming_no_stale.py` / `app/Http/Middleware/BughuntCoverageMiddleware.php` (コメントのみ) | High |
| 7 | テンプレート差分の登録 | `docs/template-divergence.md` | High |

実装順は **3 → 1 → 2 → 4 → 5 → 6 → 7** (テストファースト。まず落ちる自己テストを書く)。

---

## 施策 1: 対象外宣言 (`coverage/out-of-scope.json`) の新設

### 何を宣言するか (面の定義)

**面 = ブラウザ走行では検査できない理由と代替検証を共有する `app/` 配下のコード群。**
理由か代替検証が違うなら別の面にする。1 件 = 1 面。

宣言が答える問いは 1 つだけ: **「この範囲のコードがコード到達カバレッジで未到達でも、
なぜ穴ではないのか。代わりに何が検査しているのか」**。

### スキーマ (v1)

```json
{
  "version": 1,
  "note": "bug-hunt のコード到達カバレッジで未到達でよい面の唯一の正本。人の判断であり機械生成しない。",
  "entries": [
    {
      "id": "filament-admin",
      "title": "運営向け Filament 管理画面",
      "reason": "運営 (社内) 向けの管理画面であり、現場作業者が触るマニュアル作成の導線ではない。探索ストーリー S1..S7 はいずれもこの面を走らない。",
      "alternative_verification": "画面と認可の実挙動は tests/Feature/Filament と tests/Feature/Admin が検査する。",
      "verification_refs": ["tests/Feature/Filament", "tests/Feature/Admin"],
      "path_prefixes": ["app/Filament", "app/Providers/Filament", "app/Http/Controllers/Admin"]
    }
  ]
}
```

| キー | 値域 | 必須 |
|---|---|---|
| `version` | 整数 `1` (真偽値は不可。`type(v) is int` で判定) | 必須 |
| `note` | 非空文字列 | 必須 |
| `entries` | 1 件以上の配列 | 必須 |
| `entries[].id` | `^[a-z0-9][a-z0-9-]*$` / 宣言内で一意 | 必須 |
| `entries[].title` | 非空文字列 | 必須 |
| `entries[].reason` | 非空文字列 / trim 後 **30 文字以上** / 無内容な値でない | 必須 |
| `entries[].alternative_verification` | 非空文字列 / trim 後 **30 文字以上** / 無内容な値でない | 必須 |
| `entries[].verification_refs` | 1 件以上 / 正規形の相対パス / リポジトリ内に実在 (ファイルまたはディレクトリ) / 宣言内で重複なし | 必須 |
| `entries[].path_prefixes` | 1 件以上 / `app/` 配下 / 実在 / **全宣言を通じて**包含関係なし / 幹でない | 必須 |
| 未知キー | **拒否** (fail-closed。トップレベルと entry の両方) | — |

- **型は実行時に厳密判定する**。トップレベルが配列 / `entries` が object / 文字列欄が数値 /
  配列要素が非文字列 / 空白だけの文字列は、すべて `DeclarationError` にする。
  `version` は `type(v) is int`  で見る (真偽値は `int` の派生なので `isinstance` では通ってしまう)。
- **長さの閾値 30 文字は既存規約に揃える**。`inventory/annotations.toml` の区分 `外` の理由が
  30 文字以上必須なので、同じ判断に同じ閾値を使う (2 つの正本で閾値が違うと説明できない)。
  無内容な値の拒否 (trim 後の完全一致で `対象外` / `なし` / `-` / `N/A` / `TBD`) も併用する。
- **パスの検証は 2 層に分ける** (層を混ぜると `covers()` が `repo_root` を要求する形になり、
  公開インターフェースと契約が食い違う)。

  **層 1: 字句の正規形 (リポジトリに依存しない)** — `normalize(raw) -> tuple[str, ...]`。
  実在検査・包含検査・循環検査・`covers()` の**すべてがこの 1 本を共用する**。
  1. **生の文字列のまま**拒否する: 絶対パス / 先頭スラッシュ / 末尾スラッシュ / バックスラッシュ /
     空セグメント (`a//b`) / セグメントが `.` または `..`。
     `PurePosixPath` へ入れた後では `a//b` や `.` が畳まれて**元の非正規形を検出できない**ため、
     変換より前に見る。
  2. その後で `PurePosixPath.parts` を作って返す。

  **層 2: リポジトリ依存の検証 (`load()` のときだけ)** —
  3. `repo_root` を基点に解決し、結果が **`repo_root` の外へ出るものを拒否**する。
     `path_prefixes` はさらに **`repo_root/app` の内側**であることを確認する。
  4. 実在を確認する。
  5. **未解決の `repo_root / rel_path` を先頭から 1 要素ずつたどり、各要素**について
     シンボリックリンクを拒否する (先に完全解決すると、どの要素が symlink だったかが失われる。
     最後の要素だけを見ると、親ディレクトリが symlink の場合を通してしまう)。

  `covers()` は**層 1 だけ**を使い、非正規形の引数は拒否する。
  「`app/../tests` は先頭が `app` だから通る」といった迂回は層 1 で閉じ、
  「repo の外・不在・symlink」は層 2 で閉じる。
- **一致はパス要素の境界で行う**: `app/Foo` は `app/Foobar` を覆わない
  (`PurePosixPath.parts` の前方一致で判定し、素の `startswith` を使わない)。
- **幹の禁止**: `path_prefixes` は `app` / `app/Http` / `app/Http/Controllers` を禁止する
  (**明示的な禁止集合との完全一致**で判定する。規則を推測させない。増やすときは
  禁止集合に足す = レビューに出る)。
- **包含関係は全 entry の直積で禁止する** (antichain)。同一 entry 内だけを見ると、
  別々の面に `app/Foo` と `app/Foo/Bar` が入ったときに `covers()` の結果が宣言の並び順に依存する。
  完全重複も禁止する。
- **循環参照の禁止**: `verification_refs` に宣言自身 (`out-of-scope.json`)・監査文書
  (`coverage-audit.md`)・**いずれかの `path_prefixes` が覆うパス**を書けない
  (「対象コード自身が代替検証」を形式で排除する)。
- **追跡下であることは自己テストが見る** (loader は「リポジトリ内に実在する」までを契約にする)。
  自己テストは `git ls-files` で追跡集合を取り、`verification_refs` と `path_prefixes` が
  追跡下にあることを検査する (ファイルは完全一致、ディレクトリは**パス要素の境界で**配下に
  追跡ファイルが 1 本以上。`tests/Foo` を `tests/Foobar/Test.php` で満たしたことにしない)。
  git が使えない環境では **skip ではなく fail** させる (環境不備を隠さない。既存
  `BugHuntInventoryCheckInvariantTest` の先例と同じ)。

### 初期の登録内容 (8 面・全文)

いずれも**実装の構造だけから正当化できる面**に限る。「今回の走行でたまたま踏まれなかった」は
理由にしない (それは穴である)。**理由か代替検証が違えば別の面**という定義に従い、
機械向けの面は接続方式ごとに、プロセス外の実行は実行単位ごとに分けた (計 8 件)。

1. `filament-admin` — 運営向けの Filament 管理画面
   - path_prefixes: `app/Filament` / `app/Providers/Filament` / `app/Http/Controllers/Admin`
   - reason: 運営 (社内) 向けの管理画面であり、現場作業者が手順書から動画を作る導線ではない。
     探索ストーリー S1..S7 はいずれもこの面を走らない設計になっている。
   - alternative_verification: 画面の表示と認可の実挙動を tests/Feature/Filament と
     tests/Feature/Admin の Feature テストが検査する。
   - verification_refs: `tests/Feature/Filament` / `tests/Feature/Admin`
2. `seo-static-delivery` — クローラ向けの静的配信
   - path_prefixes: `app/Http/Controllers/Seo` / `app/Providers/SeoServiceProvider.php`
   - reason: 認証もセッションも持たない機械可読の配信で、人が操作する画面ではない。
     目録でも区分 外 として探索の分母から外している。
   - alternative_verification: 配信内容とヘッダを tests/Feature/Seo の Feature テストが検査する。
   - verification_refs: `tests/Feature/Seo`
3. `inbound-webhook` — 外部サービスから届く受信通知
   - path_prefixes: `app/Http/Controllers/Webhooks`
   - reason: 外部サービスが送ってくる受信要求で、署名検証が前提のためブラウザ操作からは発火しない。
   - alternative_verification: 署名検証と副作用を tests/Feature/Mail の受信通知まわりの
     Feature テストが検査する。
   - verification_refs: `tests/Feature/Mail/SesNotificationControllerTest.php` /
     `tests/Feature/Mail/SesSignatureMiddlewareTest.php`
4. `mcp-oauth-interface` — 外部クライアントが接続する MCP と OAuth 認可の面
   - path_prefixes: `app/Mcp` / `app/Passport`
   - reason: 外部の機械が接続する機械間の面で、ブラウザの画面と操作として存在しない。
   - alternative_verification: 接続の流れと道具の契約を tests/Feature/Mcp の Feature テストが検査する。
   - verification_refs: `tests/Feature/Mcp`
5. `rest-api` — API キーで認証する機械向けの REST 面
   - path_prefixes: `app/Http/Controllers/Api`
   - reason: セッションではなく API キーで認証する機械向けの面で、ブラウザのセッションからは
     たどれない。
   - alternative_verification: 認証と冪等性と応答契約を tests/Feature/Api の Feature テストが検査する。
   - verification_refs: `tests/Feature/Api`
6. `artisan-command` — 手動と定時で走る artisan コマンド
   - path_prefixes: `app/Console`
   - reason: コマンドは serve とは別のプロセスで走るため、走行中に実行されても行の到達として
     記録されない (計測の到達点の外)。
   - alternative_verification: 各コマンドの挙動を tests/Feature/Console の Feature テストが検査する。
   - verification_refs: `tests/Feature/Console`
7. `queued-job` — キューのワーカーが処理する実行単位
   - path_prefixes: `app/Jobs`
   - reason: ワーカーは serve とは別のプロセスで走るため、走行中に実際へ処理されても行の到達として
     記録されない (計測の到達点の外)。動いていないのではなく見えていない。
   - alternative_verification: 待ち時間の扱いと重複実行の目録を tests/Feature/Queue と
     tests/Architecture/JobExecutionDedupInventoryTest.php が検査する。
   - verification_refs: `tests/Feature/Queue` / `tests/Architecture/JobExecutionDedupInventoryTest.php`
8. `bughunt-external-fake` — bug-hunt 専用の外部代替 (保存先の偽物)
   - path_prefixes: `app/Http/Controllers/Testing` / `app/Providers/BughuntFakesServiceProvider.php`
   - reason: bug-hunt 環境でだけ差し替わる外部代替であり、製品の利用者が触る面ではない。
   - alternative_verification: 差し替えの配線と条件を tests/Feature/Providers と
     tests/Architecture/ExternalFakeWiringInvariantTest.php が検査する。
   - verification_refs: `tests/Feature/Providers/BughuntFakesServiceProviderTest.php` /
     `tests/Architecture/ExternalFakeWiringInvariantTest.php`

> 上の文面は JSON へそのまま入る値である (実装時に 30 文字以上・無内容でないことを満たすか
> 自己テストで確認する)。**パスは実装時に実在を確認してから確定する**。

理由は次の 3 型のいずれかに収まる (監査文書にもこの 3 型を書く)。

1. **利用者が到達しない面** (`filament-admin` / `bughunt-external-fake`)
2. **ブラウザ操作では発火しない面** (`seo-static-delivery` / `inbound-webhook` /
   `mcp-oauth-interface` / `rest-api`)
3. **計測の到達点の外** (`artisan-command` / `queued-job`): 走行では実際に動く (S3 の解析 → 合成は
   ワーカーが処理する) が、pcov は **serve プロセスにだけ**有効なので行到達として現れない
   (`scripts/bug-hunt-shard.sh` は `PHP_INI_SCAN_DIR` と `BUGHUNT_PCOV` を **serve の起動行だけ**に
   渡し、`start_shard_workers` には渡さない。観測器 `BughuntCoverageMiddleware` も HTTP の
   terminate でしか集計しない)。**動いていないのではなく、見えていない**。

**宣言に入れないもの** (穴として残す): 2026-08-12 の走行が名指しした
`AcceptInvitationInAppController` (アプリ内での招待受諾) と `SessionStatusController`
(履歴復元の状態プローブ)。どちらも顧客が触る面で、理由も代替検証も「書けない」から穴である。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 製品のテスト: なし。
- 影響するのは bug-hunt スキル配下と、その自己テストを起動する Architecture テスト 1 本のみ。

### テスト計画

施策 3 に集約 (契約ごとに 1 本)。

### リスク

- **宣言が「未到達を正当化する台帳」に育つ**。対策は (a) 承認済み範囲のスナップショットとの完全一致 (施策 3)、
  (b) 理由の 3 型を文書に明示し、それ以外は穴として残す規約 (施策 5)。
- 面の粒度が人によってぶれる。対策は面の定義 1 文と初期 8 件の実例。

---

## 施策 2: `coverage/out_of_scope.py` (読み取り器・検証器・出力器)

### 役割

宣言を**検証済みの型へ変換**して返す。生の `dict` を持ち回らない。

```python
@dataclass(frozen=True)
class OutOfScopeEntry:
    id: str
    title: str
    reason: str
    alternative_verification: str
    verification_refs: tuple[str, ...]
    path_prefixes: tuple[str, ...]

@dataclass(frozen=True)
class OutOfScopeDeclaration:
    version: int
    note: str
    entries: tuple[OutOfScopeEntry, ...]
```

### 公開インターフェース

| 関数 / CLI | 契約 |
|---|---|
| `load(path, repo_root)` | 検証に通れば `OutOfScopeDeclaration` を返す。通らなければ `DeclarationError` |
| `normalize(raw)` | 層 1 (字句)。正規形の相対パスの要素列を返す。非正規形は `DeclarationError` |
| `covers(declaration, rel_path)` | そのパスがどの面に覆われるか (`OutOfScopeEntry` か `None`) を **`normalize()` の結果同士**でセグメント境界比較して判定 (`repo_root` を要求しない)。宣言は antichain なので結果は並び順に依存しない |
| `--declaration PATH` | 宣言ファイル。既定は本ファイルと同じディレクトリの `out-of-scope.json` |
| `--repo-root PATH` | 実在検査の基点。既定は `Path(__file__).resolve().parents[4]` (`coverage` → `app-bug-hunt` → `skills` → `.claude` → リポジトリルート) |
| `--emit markdown` | 面 / 理由 / 代替検証 / 対象パスの表を stdout へ (人が読む用) |
| `--emit json` | 正規化済みトップレベル object を stdout へ (UTF-8・`ensure_ascii=False`・`indent=2`・末尾改行・**宣言の並び順を保つ**) |
| 終了コード | `0` = 成功 / `2` = 宣言不正 (**stdout に何も出さない** fail-closed)。argparse の引数エラーも 2 |

- `--declaration` / `--repo-root` は**自己テストのために必須**である。実ファイルを書き換えずに
  一時ディレクトリの不正な宣言を CLI へ渡せないと、終了コードの契約 (2 かつ stdout が空) を
  実プロセスで確かめられない。
- 読み込み失敗 (`OSError` / `UnicodeError`) と JSON parse 失敗 (`JSONDecodeError` /
  `RecursionError`) は**理由を問わず `DeclarationError` へ落とす** (traceback つきの exit 1 に
  漏らさない = 呼び出し側は「stdout を信用しない」だけでよい)。
- 失敗時の診断は 2 種類あり、**契約を分ける** (argparse の既定動作は usage を含む複数行なので、
  1 行に揃えるには `ArgumentParser.error()` の上書きが要る。標準ライブラリの作法から外れないよう
  上書きはしない)。
  - **宣言不正 (`DeclarationError`)**: stderr に **1 行**の短いメッセージ。traceback は出さない。
    メッセージに外部入力の値 (パス等) を含めるときは **CR / LF を空白へ置き換える**
    (1 行という契約を入力で壊されないため)。
  - **引数エラー (argparse)**: stderr のみ (複数行でよい)。traceback は出さない。
  - どちらも **exit 2** で、**stdout には 1 バイトも書かない** (これが fail-closed の実体である)。
- **markdown 出力のセル正規化**: 値に含まれる `\` と `|` を退避し、改行を空白へ畳んでから 1 セルへ
  入れる (表を壊さない)。`|` や改行を含む値を持つ検体で表の列数が保たれることを自己テストで見る。
- 置き場所は `.claude/skills/` 配下のみ。**`app/` からは参照しない** (製品実行経路に混入させない)。

### 現行コード

なし (新規)。参照実装 (aigenba) との差分は次の 3 点で、**写経しない**理由が各々ある。

| 参照実装の要素 | 本設計 | 理由 |
|---|---|---|
| `route_name_prefixes` / `disappearance_prefixes` (route 名の接頭辞) | **持たない** | route 単位の対象外は `inventory/annotations.toml` の区分 `外` が正本。写すと二重管理になる |
| `--emit selection-regex` / `disappearance-regex` | **持たない** | 上記を持たないので導出先が無い。使われない出力を作らない (思考原則 2) |
| `verification_refs` | **足す** | 代替検証が実在するかを機械で見るため (Codex 概念レビュー Critical への対応) |

### PHPStan 適合チェック

- 本施策に PHP の変更なし (施策 4 で 1 行の配列追加のみ)。

### テスト計画

施策 3。

### リスク

- 実在検査の基点がずれると全件不正になる。対策: 基点をテストから差し替え可能にし、
  「基点が違うと落ちる」ことを負の対照テストで固定する。

---

## 施策 3: 自己テスト (`coverage/test_out_of_scope.py`)

**1 契約 1 テスト**。実装前に書き、`python3 -m unittest test_out_of_scope` が**落ちること**を確認する。

| # | テスト | 何が落ちたら赤か |
|---|---|---|
| 1 | 実宣言が `load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)` を通る | 実データの妥当性 |
| 1b | CLI を `--declaration` / `--repo-root` **なし**で実行して 0 で成功する | 既定パスの算出 (`parents[4]` の off-by-one) |
| 2 | 必須キー欠落を拒否 (トップレベル・entry の各キーで反復) | 契約の穴 |
| 3 | 未知キーを拒否 (トップレベル・entry の両方) | 黙って増えるキー |
| 4 | 型違反を拒否 — トップレベルが配列 / `entries` が object / 文字列欄が数値 / 配列要素が非文字列 / 空白だけの文字列 | 型を見ない読み込み |
| 5 | `version` が `1` 以外 / 真偽値 (`True`) を拒否 | `True` が `int` として通る罠 |
| 6 | `id` の書式違反・重複を拒否 | 参照不能な id |
| 7 | `reason` / `alternative_verification` が 30 文字未満、または無内容な値 (`対象外` / `なし` / `-` / `N/A` / `TBD`) を拒否 | 「書けば通る」空洞化 |
| 8 | `path_prefixes` が空 / 不在 / `app/` 外 を拒否 | 分母を無制限に縮める宣言 |
| 9 | **層 2 (`load()`)**: 不在 / **repo の外を指す symlink** / **repo の内を指す symlink** (symlink は全面禁止) / **親ディレクトリが symlink** を拒否 (`app/../../etc` のような字句の逸脱は層 1 が先に落とすので、ここは symlink と実在の担当) | 境界検査の迂回 |
| 10 | `path_prefixes` の包含関係と完全重複を **entry を跨いで**拒否 | 並び順に依存する `covers()` と pin の空回り |
| 11 | 幹 (`app` / `app/Http` / `app/Http/Controllers`) を拒否 | 一撃で全体を対象外にする宣言 |
| 12 | `verification_refs` が空 / 不在 / 重複 を拒否 | 実体の無い代替検証 |
| 13 | `verification_refs` の循環参照 (宣言自身 / 監査文書 / いずれかの `path_prefixes` が覆うパス) を拒否 | 自己言及で成立する宣言 |
| 14 | `verification_refs` と `path_prefixes` が **git の追跡下**にある (ファイルは完全一致 / ディレクトリは配下に追跡ファイルが 1 本以上)。git が使えなければ fail | 生成物や一時ファイルを代替検証にする宣言 |
| 14b | 追跡判定の負の対照 — `tests/Foo` に対して追跡集合が `tests/Foobar/Test.php` だけのとき**未追跡**と判定する | ディレクトリ判定の素の前方一致 |
| 15 | **層 1 (`normalize()` / `covers()`)**: 絶対パス / `.` / `..` (`app/../../etc` を含む) / 空セグメント / 末尾スラッシュ / バックスラッシュ / **実在するが正規形でない** (`app/../app/Filament`) を拒否し、セグメント境界で判定する (`app/Foo` は `app/Foobar` を覆わない)。`covers()` は `repo_root` を要求しない | 素の前方一致の混入と層の混線 |
| 16 | 入力障害が `DeclarationError` へ収束する — ファイル不在 / 不正 UTF-8 / 壊れた JSON / 過剰にネストした JSON | traceback つき exit 1 への漏れ |
| 17 | **承認済み範囲のスナップショットとの完全一致** — 期待する id 集合と (id, path_prefixes) の対応をテスト側に**独立に**書き、宣言と完全一致することを検査 | 対象外の静かな増減 (増減どちらでも赤) |
| 18 | CLI: `--emit json` の出力を parse し、正規化済みデータと一致することを検査 | 空出力でも通る空振り |
| 19 | CLI: `--emit markdown` の出力に全 entry の `title` / `reason` / `alternative_verification` / 対象パスが 1 回以上現れる。`\|` や改行を含む検体でも列数が保たれる (**素の `split('|')` では数えない** — 退避された区切りを区別する小さな解析か、期待する正規化済み行との一致で見る) | 出力器の破損と表の崩壊 |
| 20 | CLI: 不正な宣言 (`--declaration` で一時ファイルを渡す) は **終了コード 2 / stdout が空 / stderr が 1 行の非空で traceback を含まない** | fail-open と診断の垂れ流し |
| 21 | CLI: 未知の `--emit` 値も **終了コード 2 / stdout が空 / stderr に traceback を含まない** (行数は問わない) | 引数エラーの取り違え |
| 22 | CLI: `--repo-root` に誤った基点を渡すと落ちる (実在検査が本当に基点を使っている) | 基点を無視した実在検査 |
| 23 | 監査文書 (`coverage-audit.md`) に**宣言から読んだ** `id` / `title` / `path_prefixes` が現れない | 一覧の写しの復活 |

- **17 の期待値は宣言から生成しない** (自己参照的な検査は空回りする。Codex 概念レビュー
  Round 2 の指摘)。テストファイル内のタプル定数が**レビュー承認済み範囲のスナップショット**で、
  運用上の正本は JSON である。対象外を増やす変更は必ずこの定数の diff としてレビューに出る。
- **23 は逆に宣言から読む**。文書と宣言を同時に更新しても写しを検出できるからで、こちらは
  自己参照にならない (17 とは目的が違う)。表そのものは禁じない — 監査文書は軸の対応表を持つ。
  禁じるのは**対象外の面の一覧の複製**だけである。
- 実行は `python3 -m unittest` (stdlib のみ)。一時ディレクトリで不正宣言を組み立てて検査する。
- 権限エラーの検体は作らない (実行利用者に依存して移植性が落ちるため。`OSError` の収束は
  ファイル不在で代表させる)。

---

## 施策 4: `composer test` への配線

### 変更箇所

`tests/Architecture/BughuntCoverageToolSelfTest.php` (既存)。

### 現行コード

```php
 * 対象は 3 モジュール:
 *   - test_correlate      … 照合器の fail-closed 契約 (主入力が揃わない走行を成功にしない)
 *   - test_build_executed … 実行済み route の記録の集約器 (同上)
 *   - test_naming_no_stale … 旧 fail-open 文言・旧語彙の再混入検知
...
test('カバレッジ道具の Python 自己テスト 3 本が composer test の下で通ること', function (): void {
    ...
    [$code, $out] = bctRunUnittest(['test_correlate', 'test_build_executed', 'test_naming_no_stale']);
```

### 変更後コード

```php
 * 対象は 4 モジュール:
 *   - test_correlate      … 照合器の fail-closed 契約 (主入力が揃わない走行を成功にしない)
 *   - test_build_executed … 実行済み route の記録の集約器 (同上)
 *   - test_naming_no_stale … 旧 fail-open 文言・旧語彙の再混入検知
 *   - test_out_of_scope   … コード到達で未到達でよい面の宣言の契約 (理由と代替検証の実在・承認済み範囲との一致)
...
test('カバレッジ道具の Python 自己テスト 4 本が composer test の下で通ること', function (): void {
    ...
    [$code, $out] = bctRunUnittest([
        'test_correlate', 'test_build_executed', 'test_naming_no_stale', 'test_out_of_scope',
    ]);
```

### 波及変更

- テスト名の文字列 (「3 本」→「4 本」) を含むので、テスト名を参照している箇所が無いことを
  実装時に `rg 'Python 自己テスト'` で確認する。
- `test_merge_pcov` は**引き続き入れない** (既存の docblock どおり別 feature の担当)。

### テスト計画

- 既存の負の対照 (存在しないモジュール名で非 0) はそのまま活きる。
- 実装順の確認手順: 施策 3 を書いた直後に `composer test --filter=BughuntCoverageToolSelfTest` を
  走らせ、**赤になること**を確認してから施策 1・2 を実装する。

### リスク

- Python 実行が無い環境では既存方針どおり **skip ではなく fail** になる (環境不備を隠さない)。

---

## 施策 5: 監査文書 (`coverage-audit.md`) の新設

### 中身 (草案・約 60 行。数値と一覧を持たない)

```markdown
# カバレッジ網羅監査 (静的な棚卸し)

bug-hunt の網羅性を静的に棚卸しするための文書。**走行のたびに機械で出せるもの
(分母の件数・画面や操作の一覧・route 名・シナリオの割当・未実行の一覧) はここに書かない** —
それらは `scripts/bug-hunt-inventory-check.sh` と `coverage/correlate.py` /
`coverage/merge_pcov.py` の出力が正本であり、手で写すと必ず腐る。
この文書が扱うのは**実装から導けない人の判断**、すなわち
「設計上ブラウザでは検査できない面はどれで、なぜか、代わりに何で検査するか」だけである。

> 走行ごとの突合 (`coverage/`) との役割分担は `coverage/README.md` を参照。
> 過去の監査の実測値は git 履歴と `devnotes/{run}-bug-hunt/report.md` に残る。

## 対象外の正本は 2 本ある (軸が違う)

| 軸 | 単位 | 正本 |
|---|---|---|
| 探索の分母 (どの操作・画面を走るか) | route 名 | `inventory/annotations.toml` の区分 `外` + 理由 |
| コード到達 (どのコードが未到達でよいか) | `app/` 配下のパス | `coverage/out-of-scope.json` |

同じ面が両方に現れることはある。問いが違うからで、二重管理ではない。

## コード到達の対象外を読む

    python3 .claude/skills/app-bug-hunt/coverage/out_of_scope.py --emit markdown

宣言の妥当性の検査 (loader の自己テスト):

    cd .claude/skills/app-bug-hunt/coverage && python3 -m unittest test_out_of_scope

## 対象外を増やすとき

1. `coverage/out-of-scope.json` に面を足す。`reason` (なぜブラウザ走行では検査できないか) と
   `alternative_verification` (代わりに何が検査するか) と `verification_refs` (その実体のパス) は必須。
2. 自己テストの承認済み範囲のスナップショットを同じ変更で更新する (更新しないと赤になる = 追加は必ずレビューに出る)。
3. `composer test --filter=BughuntCoverageToolSelfTest` を通す。

**対象外を増やすことは、監査のうえで「未到達の穴」として扱う範囲を縮めることである**
(集計器の分母と出力は変わらない。変わるのは人がどこを穴と読むかである)。
理由と代替検証が書けないなら、それは対象外ではなく**未着手の穴**であり、次の走行で埋める対象として
残す。理由として認めるのは 3 型だけである — 利用者が到達しない面 / ブラウザ操作では発火しない面 /
計測の到達点の外。

## 計測の癖 (未到達を誤読しないために)

コード到達は **serve のプロセスでしか採れない**。artisan コマンドとキューのワーカーは別プロセスで
走るため、bug-hunt が実際に動かしていても未到達として現れる。**動いていないのではなく見えていない**。

## この文書が保証しないもの

- 機械が見るのは宣言の**形式**と参照先の**実在**まで (`composer test` の下では**追跡下かどうか**も
  見る) である。代替検証がその面を本当に守っているか (テストの意味的十分性) は人のレビューの
  担当である。
- 集計器 (`merge_pcov.py`) は宣言を読まない。未到達の一覧と宣言の突合は**人が読んで行う**。
- `verification_refs` にディレクトリを書いた面では、その中のファイルが 1 本消えても気付けない
  (消えるのを検出できるのはディレクトリごと消えたときである)。
- 古い断定の再混入を見る走査は、このスキル配下の文書と道具だけが射程である
  (`app/` のコメントは見ない)。
```

### 波及変更 (同じ変更で直す)

既存の 3 か所は「実体ができれば済む」ではない。**説明の中身が新設文書と食い違う**ため、
同じ変更で更新する。

| ファイル | 現行の説明 | 直す方向 |
|---|---|---|
| `coverage/README.md` L9 | 「静的棚卸しの `coverage-audit.md` (route/operation の机上対応表) とは役割が違う」 | 新設文書は route/operation の対応表を**持たない**。「コード到達で対象外と判断する面・その理由・代替検証」を扱う文書だと書き直す |
| `ledger/README.md` L5 | 「全体像・運用は SKILL.md と `coverage-audit.md` を参照」 | 参照先としては正しい。所見台帳との関係が誤読されないか実装時に読み直し、必要なら 1 文足す |
| `ledger/validate_findings.py` L12 | 設計根拠のヘッダに `coverage-audit.md` を列挙 | 同上 (行の意味は保つ) |

3 者の責務分担を README 側で 1 か所にそろえる: `coverage-audit.md` = コード到達の対象外の判断 /
`inventory/annotations.toml` = route 単位の探索対象外 / `correlate.py` `merge_pcov.py` =
走行ごとの突合結果。

### テスト計画

- 施策 3 の 23 番 (対象外の面の一覧が複製されていないこと) が本文書を走査する。
- `coverage/test_naming_no_stale.py` の禁止語走査に自動的に載る (新規 `.md` のため)。

### リスク

- 文書が育って一覧を持ち始める。対策は 23 番の機械検査 (表そのものは禁じず、
  面の一覧の複製だけを禁じる)。

---

## 施策 6: 「pcov 未導入」という古い前提の訂正

### 変更箇所と現行の記述

| ファイル | 現行 | 事実 |
|---|---|---|
| `coverage/README.md` (正直な前提の節) | 「**pcov は本環境未導入**。コード到達カバレッジは pcov 非依存の純ロジック…」 | `docker/Dockerfile` L46-49 が pcov を導入済み。2026-08-12 の走行で実データが出た |
| `coverage/merge_pcov.py` (docstring) | 「HONEST 注記: 本環境は pcov 未導入のため実 coverage は取得できない」 | 同上 |
| `app/Http/Middleware/BughuntCoverageMiddleware.php` (docblock) | 「本環境/CI/本番には pcov が入っていない」 | 開発コンテナには**ある**。CI の workflow に導入記述は無い。**本番については本リポジトリからは分からない** (デプロイ定義がそもそも無い) |

### 変更後の記述 (要点) — 「拡張の有無」と「収集の有効化」を分ける

古い断定を別の断定へ置き換えない。書けるのは次の 5 つだけである。

1. 開発コンテナ (`docker/Dockerfile`) では pcov を使える。
2. bug-hunt は **serve の起動時にだけ**収集を有効にする (`scripts/bug-hunt-shard.sh` が
   `PHP_INI_SCAN_DIR` と `BUGHUNT_PCOV` を serve の起動行へ渡す)。
3. **本リポジトリには、CI または本番で bug-hunt のコード到達収集を有効にする構成が存在しない**
   (CI の workflow に pcov の導入記述は無く、デプロイ定義そのものが無い)。
   リポジトリの外にある本番構成がどうなっているかは**分からない**。
4. 拡張の有無に関わらず、設定の guard (`config('bughunt.pcov.enabled')` +
   `function_exists('\pcov\start')`) を満たさなければ middleware は完全 no-op である。
   **二重 guard は引き続き必要**である。
5. 本番実行環境に拡張が入っているかは**本リポジトリからは保証しない** (デプロイ定義が無い。
   AGENTS.md の route:cache の運用要件と同じ立場)。

- `merge_pcov.py` の docstring からは「実 coverage は取得できない」を落とし、
  「テストは fixture で検証する (pcov 非依存の純ロジックであることは変わらない)」を残す。
- **middleware の guard の実装には一切触らない** (コメントのみ)。振る舞いの変更ゼロ。

### 古い断定が戻らないようにする

`coverage/test_naming_no_stale.py` の `STALE_PATTERNS` へ次の 3 つを足す (新しい正しい文面を
完全一致で pin するのではなく、**古い断定の再混入だけ**を検出する。腐りにくい側を選ぶ)。

- `pcov は本環境未導入`
- `本環境/CI/本番には pcov が入っていない`
- `実 coverage は取得できない`

同ファイルは自分自身を走査対象から外している (`EXCLUDE_NAMES`) ので、禁止語を書いても
自分では落ちない。走査対象は skill 配下の `.md` / `.py` なので、`app/` の docblock は対象外である
— そちらの後退は本設計では検出できない (**保証しない範囲として明記する**)。

### 波及変更

- `app/` に触るのはこの docblock だけ。PHPStan / Pint に影響しないことを確認する。
- `BughuntCoverageMiddleware` の docblock を参照している既存テストが無いことを
  `rg 'pcov 未導入'` と `rg '本環境/CI/本番'` で確認してから書き換える。

### テスト計画

- 文言のみ。`composer test` の全 green を再確認する (既存の docblock 走査系テストに
  引っかからないことの確認を含む)。

### リスク

- 「pcov がある」と読み替えられて guard が外される。対策は変更後の文に
  **「CI・本番での拡張の有無に依存せず、設定と関数存在の二重 guard は必要である」**と明記すること
  (実行環境の推測を根拠にしない書き方にする)。

---

## 施策 7: テンプレート差分の登録 (`docs/template-divergence.md`)

家系の参照実装は `out-of-scope.json` に **route 名の接頭辞**を持たせ、目録のドリフト検査を
そこから導出している。本アプリは route 単位の判断を `inventory/annotations.toml` (区分 `外`) が
持つため、宣言を**コード到達の軸だけ**に絞る。これは構造上の意図的な逸脱なので登録する。

### 登録エントリ (9 行の登録メタ表・値域は同ファイルの規約に従う)

| 行 | 値 |
|---|---|
| 対象パス | `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` / `.claude/skills/app-bug-hunt/coverage/out_of_scope.py` / `.claude/skills/app-bug-hunt/coverage-audit.md` |
| 業務要件起因の説明 | 探索の分母は route 単位の注釈が正本であり、コード到達の未到達は `app/` のパス単位でしか説明できないため、対象外の宣言を軸で 2 本に分ける |
| 揃え続ける不変条件と保証機構 | 対象外は理由と代替検証と実在する参照を伴う。増減は承認済み範囲のスナップショットとの完全一致で必ずレビューに出る。`BughuntCoverageToolSelfTest` から `test_out_of_scope` が実走する |
| 再判定の条件 | 家系の正典が route 名接頭辞を必須にしたとき / 注釈側へ代替検証の欄が入ったとき / 集計器が宣言を読む形になったとき |
| 決めた日 | 実装日 |
| 決めた人 | 開発者 |
| 根拠 | 本 TODO の番号 (`T<n>`) |
| 状態 | 恒久 |
| 見直し期限 | — |

- **対象パスは全登録の和集合で重複しない**必要がある。D14 / D20 が既に持つパス
  (`coverage/build_executed.py` / `correlate.py` / `inventory/annotations.toml` /
  `scripts/bug-hunt-inventory.py`) とは重ならないことを実装時に確認する。
- 冒頭の「登録エントリ: N 件」と本文の件数の 3 点一致は
  `TemplateDivergenceLedgerFormatTest` が機械で見る。**番号は既存の最大 + 1** を使う (欠番は詰めない)。
- 本文の散文に「集計器との自動照合は持たない」という保証範囲を 1 文入れる
  (将来の過大解釈の防止。Codex 概念レビュー Round 2 の提案)。
- **D20 との関係は確定済み: 新規登録にする**。設計時に D20 の本文を実読した (対象パスは
  `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` /
  `inventory/annotations.toml` の 3 本)。D20 が扱うのは**目録の生成方式**の 3 点
  (機能カタログを生成しない / 注釈は TOML / 中間 JSON を持たない) だけで、
  対象外宣言の軸の分離には触れていない (区分 `外` は保証範囲の注記に現れるだけである)。
  よって D20 の更新ではなく新規登録とし、対象パスも重ならない。

### テスト計画

- `composer test --filter=TemplateDivergenceLedgerFormat` が緑であること。

---

## 守るべき不変条件と、それを落とす検査の対応表

| # | 不変条件 | 落とす検査 | 採否 |
|---|---|---|---|
| 1 | 対象外の面は理由と代替検証を必ず持つ (中身のある文で) | `test_out_of_scope` 2 / 4 / 7 | 採る |
| 2 | 代替検証は実在し、追跡下にあり、自己言及でない | `test_out_of_scope` 12 / 13 / 14 | 採る |
| 3 | 対象外を静かに広げられない | `test_out_of_scope` 17 (承認済み範囲のスナップショット) | 採る |
| 4 | 対象パスは実在し、幹や包含や正規形の迂回で無制限に広がらない | `test_out_of_scope` 8 / 9 / 10 / 11 / 15 | 採る |
| 5 | 宣言不正・入力障害は fail-closed (stdout を汚さない) | `test_out_of_scope` 16 / 20 / 21 / 22 | 採る |
| 6 | 監査文書に面の一覧の写しが復活しない | `test_out_of_scope` 23 | 採る |
| 7 | 出力器が空振りしない | `test_out_of_scope` 18 / 19 | 採る |
| 8 | これらが `composer test` から実走する | `BughuntCoverageToolSelfTest` (施策 4) | 採る |
| 9 | 「pcov 未導入」という古い断定が戻らない | `test_naming_no_stale` への 3 語追加 (施策 6) | 採る |
| 10 | 目録のドリフト検査が宣言から導出される (参照実装の形) | — | **採らない**。本アプリの目録は注釈 TOML から生成する形 (D20) で、route 接頭辞を持たないため導出先が無い |
| 11 | カバレッジ出力に割合を目標とする語を書かせない | — | **採らない**。禁止語そのものを説明文に書く必要があり素朴な語句一致では偽陽性になる。現状は散文の規約で運用しており、別設計とする |
| 12 | 集計器の出力と宣言の自動突合 | — | **採らない**。まず宣言と文書が成立してから必要性を判断する (思考原則 2) |
| 13 | `app/` の docblock に古い断定が戻らない | — | **採らない**。禁止語走査は skill 配下の `.md` / `.py` が射程で、`app/` は対象外である (保証しない範囲として監査文書に書く) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 触るのは bug-hunt スキル配下と Architecture テスト 1 本と文書 2 本で、製品コードの振る舞いに依存しない。他施策との競合は `docs/template-divergence.md` の件数行だけである |
| 競合リスク | `docs/template-divergence.md` を触る他 TODO と同時に走ると件数行が衝突する (マージ時に件数を再計算する) |

## 検証手順 (実装時)

1. `cd .claude/skills/app-bug-hunt/coverage && python3 -m unittest test_out_of_scope` (最初は赤)
2. `composer test --filter=BughuntCoverageToolSelfTest`
3. `composer test` / `composer phpstan` / `vendor/bin/pint --test`
4. `scripts/bug-hunt-inventory-check.sh` (exit 0 = 目録に影響していないこと)
5. `python3 out_of_scope.py --emit markdown` の出力を目視 (面 8 件が読めること)

## 付記: Codex レビューの実行環境

本設計のレビューは `scripts/codex` 経由で実施した (環境要因による実行不能は起きていない)。

| 段階 | モデル | ラウンド | 結果 |
|---|---|---|---|
| 概念設計 | `gpt-5.6-terra` (medium) | 1 → 2 | Round 2 で **APPROVED** |
| 詳細設計 | `gpt-5.6-sol` (high) | 1 → 4 | Round 4 で **APPROVED** (Critical / Warning の未解決なし) |

送ったプロンプトと対応マトリクスは `codex-history/` に、返答は
`conceptual-review-round-{N}.md` / `detailed-review-round-{N}.md` に置いてある。
