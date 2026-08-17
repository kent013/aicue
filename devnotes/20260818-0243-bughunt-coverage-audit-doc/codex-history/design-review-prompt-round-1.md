【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

思考原則: (1) フレームワークのレンジ内でやる (2) **今必要なものだけ作る (オーバーエンジニアリング禁止)** (3) 後方互換の並走を残さない (4) 別物の概念を「似ているから」で統合しない (5) テストファースト (6) タコツボ実装を避ける。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest
- 本件は**開発用スキル (`.claude/skills/app-bug-hunt/`) の文書・データ・Python ツール**の設計であり、製品コードの振る舞いは変えない (触れる `app/` はコメント 1 か所のみ)。
- Python は標準ライブラリのみ (既存規約)。Python の自己テストは既存の Architecture テスト (`BughuntCoverageToolSelfTest`) が `composer test` の下で実走させる。

【本件の背景】
- 複数リポジトリで共有する機能台帳 lctl の feature `bughunt-coverage-audit` の正典 t1 =「集計器は現状維持 + 監査文書のうち『対象外の理由と代替検証』だけをデータ化」。本リポジトリ (aicue) は pending で、裁定 2026-08-05 の条件 (網羅監査文書を持つこと) が未解消。
- 計測基盤 (pcov) は稼働済み。2026-08-12 の走行でコード到達 429 ファイル / 未到達 46 / 参考 line_pct 59.9% が出ている。
- 参照実装 (aigenba) は監査文書 49 行 + `out-of-scope.json` (11 件) + loader + PHP 検査 420 行。別の参照実装 (テンプレート) は PHP 検査 1298 行。本設計はこれらを一括移植しない方針。
- 本リポジトリには既に route 単位の対象外宣言 (`inventory/annotations.toml` の区分 `外`、理由 30 文字以上必須、ドリフト検査が exit 3) がある。

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン)
3. PHPStan level 10 適合性 (該当があれば)
4. テスト計画の網羅性 (契約ごとに検査があるか、空振りしないか)
5. 検査の実効性 (その検査は本当に赤くなるか。自己参照で空回りしないか)
6. 二重管理・腐敗のリスク (家系が既に腐らせた失敗を再演しないか)
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ (該当があれば)
10. スコープ (過大・過小)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| 6 | 「pcov 未導入」という古い前提の訂正 | `.claude/skills/app-bug-hunt/coverage/README.md` / `coverage/merge_pcov.py` / `app/Http/Middleware/BughuntCoverageMiddleware.php` (コメントのみ) | High |
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
| `entries[].reason` | 非空文字列 / 無内容な値でない | 必須 |
| `entries[].alternative_verification` | 非空文字列 / 無内容な値でない | 必須 |
| `entries[].verification_refs` | 1 件以上 / 追跡下に実在する相対パス (ファイルまたはディレクトリ) | 必須 |
| `entries[].path_prefixes` | 1 件以上 / `app/` 配下 / 実在 / 包含関係なし / 幹でない | 必須 |
| 未知キー | **拒否** (fail-closed) | — |

- **無内容な値の拒否**: trim 後の完全一致で `対象外` / `なし` / `-` / `N/A` / `TBD` を拒否する
  (「書けば通る」空洞化の最小限の防止。表現のチューニングはしない)。
- **幹の禁止**: `path_prefixes` は `app` そのもの、および `app/Http` のような**分岐点だけの節**を
  禁止する。判定は「`app/` を除いた残りが 1 セグメント以上で、かつ禁止語 `Http` 単独でないこと」
  ではなく、**明示的な禁止集合 (`app` / `app/Http` / `app/Http/Controllers`) との完全一致**で行う
  (規則を推測させない。増やすときは自己テストの禁止集合に足す = レビューに出る)。
- **一致はパス要素の境界で行う**: `app/Foo` は `app/Foobar` を覆わない。実装は
  `PurePosixPath.is_relative_to()` 相当 (セグメント比較) で判定し、素の `startswith` を使わない。
- **循環参照の禁止**: `verification_refs` に宣言自身 (`out-of-scope.json`)・監査文書
  (`coverage-audit.md`)・自分の `path_prefixes` が覆うパスを書けない
  (「対象コード自身が代替検証」を形式で排除する)。

### 初期の登録内容 (6 面)

いずれも**実装の構造だけから正当化できる面**に限る。「今回の走行でたまたま踏まれなかった」は
理由にしない (それは穴である)。

| id | title | path_prefixes | verification_refs |
|---|---|---|---|
| `filament-admin` | 運営向け Filament 管理画面 | `app/Filament` / `app/Providers/Filament` / `app/Http/Controllers/Admin` | `tests/Feature/Filament` / `tests/Feature/Admin` |
| `seo-static-delivery` | クローラ向けの静的配信 | `app/Http/Controllers/Seo` / `app/Providers/SeoServiceProvider.php` | `tests/Feature/Seo` |
| `inbound-webhook` | 外部サービスからの受信通知 | `app/Http/Controllers/Webhooks` | `tests/Feature/Mail/SesNotificationControllerTest.php` |
| `machine-interface` | 機械向けの接続面 (MCP / REST API / OAuth 認可) | `app/Mcp` / `app/Passport` / `app/Http/Controllers/Api` | `tests/Feature/Mcp` / `tests/Feature/Api` |
| `out-of-process-execution` | serve プロセスの外で走る実行単位 (artisan コマンド / キューのワーカー) | `app/Console` / `app/Jobs` | `tests/Feature/Console` / `tests/Feature/Queue` |
| `bughunt-external-fake` | bug-hunt 専用の外部代替 (保存先の偽物) | `app/Http/Controllers/Testing` / `app/Providers/BughuntFakesServiceProvider.php` | `tests/Architecture/ExternalFakeWiringInvariantTest.php` |

理由の書き方 (各 entry の `reason`) は次の 3 型のいずれかになる。

1. **利用者が到達しない面** (`filament-admin` / `bughunt-external-fake`): 現場作業者の導線ではない。
2. **ブラウザ操作では発火しない面** (`seo-static-delivery` / `inbound-webhook` / `machine-interface`):
   署名検証・API キー・クローラ要求が前提で、UI から到達する手段がない。
3. **計測の到達点の外** (`out-of-process-execution`): 走行では実際に動く (S3 の解析 → 合成は
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

- **宣言が「未到達を正当化する台帳」に育つ**。対策は (a) 凍結値との完全一致 pin (施策 3)、
  (b) 理由の 3 型を文書に明示し、それ以外は穴として残す規約 (施策 5)。
- 面の粒度が人によってぶれる。対策は面の定義 1 文と初期 6 件の実例。

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
| `covers(declaration, rel_path)` | そのパスがどの面に覆われるか (`OutOfScopeEntry` か `None`) をセグメント境界で判定 |
| `--emit markdown` | 面 / 理由 / 代替検証 / 対象パスの表を stdout へ (人が読む用) |
| `--emit json` | 正規化済みトップレベル object を stdout へ |
| 終了コード | `0` = 成功 / `2` = 宣言不正 (**stdout に何も出さない** fail-closed)。argparse の引数エラーも 2 |

- 読み込み失敗 (`OSError` / `UnicodeError`) と JSON parse 失敗 (`JSONDecodeError` /
  `RecursionError`) は**理由を問わず `DeclarationError` へ落とす** (traceback つきの exit 1 に
  漏らさない = 呼び出し側は「stdout を信用しない」だけでよい)。
- **実在検査の基点** (`repo_root`) は既定でファイル位置から 4 階層上
  (`.claude/skills/app-bug-hunt/coverage/` → リポジトリルート)。テストからは差し替えられる。
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
| 1 | 実宣言が `load()` を通る | 実データの妥当性 |
| 2 | 必須キー欠落を拒否 (各キーで反復) | 契約の穴 |
| 3 | 未知キーを拒否 | 黙って増えるキー |
| 4 | `version` が `1` 以外 / 真偽値を拒否 | `True` が `int` として通る罠 |
| 5 | `id` の書式違反・重複を拒否 | 参照不能な id |
| 6 | 無内容な値 (`対象外` / `なし` / `-` / `N/A` / `TBD`) を拒否 | 「書けば通る」空洞化 |
| 7 | `path_prefixes` が空 / `app/` 外 / 不在 を拒否 | 分母を無制限に縮める宣言 |
| 8 | `path_prefixes` の包含関係を拒否 | 冗長化と pin の空回り |
| 9 | 幹 (`app` / `app/Http` / `app/Http/Controllers`) を拒否 | 一撃で全体を対象外にする宣言 |
| 10 | `verification_refs` が空 / 不在 を拒否 | 実体の無い代替検証 |
| 11 | `verification_refs` の循環参照 (宣言自身 / 監査文書 / 自分の対象パス配下) を拒否 | 自己言及で成立する宣言 |
| 12 | `covers()` がセグメント境界で判定する (`app/Foo` は `app/Foobar` を覆わない) | 素の前方一致の混入 |
| 13 | **凍結値との完全一致** — 期待する id 集合と (id, path_prefixes) の対応をテスト側に**独立に**書き、宣言と完全一致することを検査 | 対象外の静かな増減 (増減どちらでも赤) |
| 14 | CLI: `--emit json` / `--emit markdown` が 0 で成功する | 出力器の破損 |
| 15 | CLI: 不正な宣言を渡すと **終了コード 2 かつ stdout が空** | fail-open |
| 16 | CLI: 未知の `--emit` 値も終了コード 2 | 引数エラーの取り違え |
| 17 | 監査文書 (`coverage-audit.md`) に対象パスのリテラルと表の区切り行が現れない | 一覧の写しの復活 |

- **13 の期待値は宣言から生成しない** (自己参照的な検査は無意味になる。Codex 概念レビュー
  Round 2 の指摘)。テストファイル内のタプル定数が期待値の正本で、対象外を増やす変更は
  必ずこの定数の diff としてレビューに出る。
- 17 は「aigenba が 96 行を腐らせた」失敗の再演防止である。監査文書は一覧を持たない。
- 実行は `python3 -m unittest` (stdlib のみ)。一時ディレクトリで不正宣言を組み立てて検査する。

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
 *   - test_out_of_scope   … コード到達で未到達でよい面の宣言の契約 (理由と代替検証の実在・凍結 pin)
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
2. 自己テストの凍結値を同じ変更で更新する (更新しないと赤になる = 追加は必ずレビューに出る)。
3. `composer test --filter=BughuntCoverageToolSelfTest` を通す。

**対象外を増やすことは分母を縮めることである。** 理由と代替検証が書けないなら、それは対象外ではなく
**未着手の穴**であり、次の走行で埋める対象として残す。理由として認めるのは 3 型だけである —
利用者が到達しない面 / ブラウザ操作では発火しない面 / 計測の到達点の外。

## 計測の癖 (未到達を誤読しないために)

コード到達は **serve のプロセスでしか採れない**。artisan コマンドとキューのワーカーは別プロセスで
走るため、bug-hunt が実際に動かしていても未到達として現れる。**動いていないのではなく見えていない**。

## この文書が保証しないもの

- 機械が見るのは宣言の**形式**と参照先の**実在**までである。代替検証がその面を本当に守っているか
  (テストの意味的十分性) は人のレビューの担当である。
- 集計器 (`merge_pcov.py`) は宣言を読まない。未到達の一覧と宣言の突合は**人が読んで行う**。
- `verification_refs` にディレクトリを書いた面では、その中のファイルが 1 本消えても気付けない
  (消えるのを検出できるのはディレクトリごと消えたときである)。
```

### 波及変更

- 既存の参照 3 か所 (`ledger/README.md` L5 / `coverage/README.md` L9 /
  `ledger/validate_findings.py` L12) は**書き換え不要**になる (実体ができるため)。
  実装時に 3 か所が指す説明と新設文書の内容が食い違っていないかだけ確認する。

### テスト計画

- 施策 3 の 17 番 (一覧の写しが現れないこと) が本文書を走査する。
- `coverage/test_naming_no_stale.py` の禁止語走査に自動的に載る (新規 `.md` のため)。

### リスク

- 文書が育って一覧を持ち始める。対策は 17 番の機械検査。

---

## 施策 6: 「pcov 未導入」という古い前提の訂正

### 変更箇所と現行の記述

| ファイル | 現行 | 事実 |
|---|---|---|
| `coverage/README.md` (正直な前提の節) | 「**pcov は本環境未導入**。コード到達カバレッジは pcov 非依存の純ロジック…」 | `docker/Dockerfile` L46-49 が pcov を導入済み。2026-08-12 の走行で実データが出た |
| `coverage/merge_pcov.py` (docstring) | 「HONEST 注記: 本環境は pcov 未導入のため実 coverage は取得できない」 | 同上 |
| `app/Http/Middleware/BughuntCoverageMiddleware.php` (docblock) | 「本環境/CI/本番には pcov が入っていない」 | 開発コンテナには**ある**。CI と本番には**無い** (`.github/workflows` に pcov の導入は無い) |

### 変更後の記述 (要点)

- 「開発コンテナ (`docker/Dockerfile`) には pcov がある。CI と本番には無い。よって二重 guard は
  引き続き必要で、pcov 不在の環境では middleware は完全 no-op のままである」と書き直す。
- `merge_pcov.py` の docstring からは「実 coverage は取得できない」を落とし、
  「テストは fixture で検証する (pcov 非依存の純ロジックであることは変わらない)」を残す。
- **middleware の guard の実装には一切触らない** (コメントのみ)。振る舞いの変更ゼロ。

### 波及変更

- `app/` に触るのはこの docblock だけ。PHPStan / Pint に影響しないことを確認する。
- `BughuntCoverageMiddleware` の docblock を参照している既存テストが無いことを
  `rg 'pcov 未導入'` と `rg '本環境/CI/本番'` で確認してから書き換える。

### テスト計画

- 文言のみ。`composer test` の全 green を再確認する (既存の docblock 走査系テストに
  引っかからないことの確認を含む)。

### リスク

- 「pcov がある」と読み替えられて guard が外される。対策は変更後の文にも
  「CI と本番には無い」「二重 guard は必要」を明記すること。

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
| 揃え続ける不変条件と保証機構 | 対象外は理由と代替検証と実在する参照を伴う。増減は凍結値との完全一致で必ずレビューに出る。`BughuntCoverageToolSelfTest` から `test_out_of_scope` が実走する |
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

### テスト計画

- `composer test --filter=TemplateDivergenceLedgerFormat` が緑であること。

---

## 守るべき不変条件と、それを落とす検査の対応表

| # | 不変条件 | 落とす検査 | 採否 |
|---|---|---|---|
| 1 | 対象外の面は理由と代替検証を必ず持つ | `test_out_of_scope` 2 / 6 / 10 | 採る |
| 2 | 代替検証は実在する | `test_out_of_scope` 10 / 11 | 採る |
| 3 | 対象外を静かに広げられない | `test_out_of_scope` 13 (凍結 pin) | 採る |
| 4 | 対象パスは実在し、幹や包含で無制限に広がらない | `test_out_of_scope` 7 / 8 / 9 / 12 | 採る |
| 5 | 宣言不正は fail-closed (stdout を汚さない) | `test_out_of_scope` 15 / 16 | 採る |
| 6 | 監査文書に一覧の写しが復活しない | `test_out_of_scope` 17 | 採る |
| 7 | これらが `composer test` から実走する | `BughuntCoverageToolSelfTest` (施策 4) | 採る |
| 8 | 目録のドリフト検査が宣言から導出される (参照実装の形) | — | **採らない**。本アプリの目録は注釈 TOML から生成する形 (D20) で、route 接頭辞を持たないため導出先が無い |
| 9 | カバレッジ出力に割合を目標とする語を書かせない | — | **採らない**。禁止語そのものを説明文に書く必要があり素朴な語句一致では偽陽性になる。現状は散文の規約で運用しており、別設計とする |
| 10 | 集計器の出力と宣言の自動突合 | — | **採らない**。まず宣言と文書が成立してから必要性を判断する (思考原則 2) |

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
5. `python3 out_of_scope.py --emit markdown` の出力を目視 (面 6 件が読めること)

## 付記: Codex レビューの実行環境

本設計の概念レビューは `scripts/codex` 経由で実施し、Round 2 で APPROVED を得た
(`conceptual-review-round-1.md` / `conceptual-review-round-2.md`)。詳細設計レビューの結果は
`detailed-review-round-{N}.md` に置く。


---

## 関連する現行コード (抜粋)

### tests/Architecture/BughuntCoverageToolSelfTest.php (全文)

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: bug-hunt のカバレッジ道具 (Python) の自己テストを
 * `composer test` の下で実走させる。
 *
 * 対象は 3 モジュール:
 *   - test_correlate      … 照合器の fail-closed 契約 (主入力が揃わない走行を成功にしない)
 *   - test_build_executed … 実行済み route の記録の集約器 (同上)
 *   - test_naming_no_stale … 旧 fail-open 文言・旧語彙の再混入検知
 *
 * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない
 * (禁止事項 1)。禁止語が戻っても、照合器が fail-open へ戻っても、緑のままになるため。
 * `test_merge_pcov` はコード到達カバレッジ (別 feature) の担当なので本目録には入れない。
 *
 * 先例は BugHuntInventoryCheckInvariantTest: python3 の不在は **skip ではなく fail** で
 * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
 */

/** カバレッジ道具の置き場 (作業ディレクトリ)。 */
function bctCoverageDir(): string
{
    return base_path('.claude/skills/app-bug-hunt/coverage');
}

/**
 * coverage ディレクトリで `python3 -m unittest <modules...>` を実走し [exitCode, output] を返す。
 *
 * @param  list<string>  $modules
 * @return array{0: int|null, 1: string}
 */
function bctRunUnittest(array $modules): array
{
    $process = new Process(['python3', '-m', 'unittest', ...$modules], bctCoverageDir());
    $process->setTimeout(120);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
    expect((new Process(['which', 'python3']))->run())->toBe(
        0,
        'python3 が PATH に無い。bug-hunt のカバレッジ道具は python3 必須 (stdlib のみ)。'
    );
});

test('カバレッジ道具の Python 自己テスト 3 本が composer test の下で通ること', function (): void {
    expect(is_dir(bctCoverageDir()))->toBeTrue('coverage ディレクトリが見つからない: '.bctCoverageDir());

    [$code, $out] = bctRunUnittest(['test_correlate', 'test_build_executed', 'test_naming_no_stale']);

    expect($code)->toBe(0, "bug-hunt カバレッジ道具の自己テストが失敗しました:\n".$out);
});

test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
    [$code] = bctRunUnittest(['test_no_such_module_exists']);

    expect($code)->not->toBe(0, '存在しないモジュールでも 0 が返る = 実走していない疑い');
});
```

### .claude/skills/app-bug-hunt/coverage/README.md (冒頭 20 行)

```markdown
# Bug-hunt Coverage (操作到達カバレッジ / コード到達カバレッジ) — App

bug-hunt の探索が「**どの操作 (route) を叩き、どのコード行に到達したか**」を run_id 突合で可視化する道具。
2 系統ある: **操作到達カバレッジ** (operation-reach / correlate.py、pcov 不要) と
**コード到達カバレッジ** (code-reach / merge_pcov.py、pcov 必要)。**主出力は未カバー worklist**（次に埋める対象）で、
**絶対 % は副**（`*_pct` に添えるだけ・目標にしない＝gaming 防止）。
「機能カバレッジ%」「品質保証%」という表現は出力にもこの README にも書かない。

> 静的棚卸しの `coverage-audit.md`（route/operation の机上対応表）とは役割が違う。
> こちらは **run 突合の動的 proxy**（実際に走った run の結果と機構分母を突き合わせる）。
> audit = 静的棚卸し / `coverage/` = run 突合の動的 proxy、と区別すること。

## 正直な前提（最重要・読み飛ばさない）

- **pcov は本環境未導入**。コード到達カバレッジ (merge_pcov.py) は pcov 非依存の純ロジック
  (入力は C3 middleware 出力形の JSON) であり、テストは fixture の shard を union して検証する。
  pcov を入れたら C3/C4/C5 の end-to-end を実機で検証してから運用する。
- **graph の TESTED_BY は TypeScript 専用**。`/workspace/.code-review-graph/graph.db` 実測
  (2026-06-20): **TESTED_BY=15787 全て TS、PHP(.php::)=0**。
  → PHP web route の TESTED_BY は **「false」ではなく `unknown_graph_gap`**（unknown）として扱う。
```

### .claude/skills/app-bug-hunt/inventory/annotations.toml (冒頭 20 行 = 注釈スキーマ)

```toml
# bug-hunt 目録の注釈 (人が書く。生成器は読むだけで書き換えない)。
#
# 目録本体 (screens.md / operations.md) は生成物である。実装から取れる事実 (URL / route 名 /
# メソッド / 画面題名) は生成器が入れるので、ここには**実装から導けない意味だけ**を書く。
#
#   kind   画面表の route で必須 (画面 / JSON)。操作表の route には書けない
#   story  区分が 通常 / 逸 のとき必須 (S1..S7)。区分が 外 / 終 には書けない
#   kubun  常に必須 (通常 / 逸 / 終 / 外)
#   reason 区分が 外 / 終 のとき必須・30 文字以上。それ以外には書けない
#
# 許すのはこの 4 項目だけで、未知の項目・未知の語彙・定義域のずれは
# `scripts/bug-hunt-inventory-check.sh` が exit 3 (ドリフト) で落とす。
schema_version = 1

[routes."billing.auto-recharge.setup"]
story = "S5"
kubun = "通常"

[routes."billing.auto-recharge.update"]
story = "S5"
```

### 参照実装 aigenba の coverage-audit.md (全文・比較用)

```markdown
# カバレッジ網羅監査 (静的棚卸し)

(要点) 走行のたびに機械で出せる数値はここに書かない。扱うのは「設計上ブラウザでは検査できない面はどれで、なぜか、代わりに何で検査するか」だけ。対象外判断の正本は coverage/out-of-scope.json のみで、監査文書にもスクリプトにも面の一覧や route 名の prefix を書き写してはならない (Architecture テストが写しの復活を検知する)。対象外を増やすときは reason と alternative_verification を必須とし、無内容な値は gate が拒否する。対象外を増やすことは分母を縮めることである。
```
