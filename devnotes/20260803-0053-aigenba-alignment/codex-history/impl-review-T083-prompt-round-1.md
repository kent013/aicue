# 実装レビュー依頼: T083 (施策 9 / 10) — bug-hunt adjudication registry の機構修復 + データ棚卸し

## アプリの使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項・セキュリティ不変条件 — AGENTS.md より

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

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

## 実装規約


---

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

---

# system: あなたの役割

あなたは **実装レビュアー (impl-review)** である。以下のブランチ `todo/T083` の差分が、
**詳細設計書 (正本) どおりに実装されているか**、および AGENTS.md の禁止事項・セキュリティ不変条件に
抵触していないかを厳格にレビューせよ。

## レビュー観点 (優先順)

1. **設計適合**: 詳細設計書「施策 9」「施策 10」の記述どおりか。設計にない変更が紛れていないか。
   設計から逸脱している箇所があれば、それが「改善」であっても**逸脱として指摘**せよ
   (この設計書は Codex 合議 11 ラウンドで APPROVED 済み。逸脱は理由の明示が必要)。
2. **禁止事項・不変条件**: AGENTS.md の禁止事項 (特に #1 テストなしの完了報告 / #2 型・テストを緩めて黙らせる)
   とセキュリティ不変条件に抵触していないか。
3. **テストが空振りしていないか**: 追加テストが**実際に不変条件を固定しているか**。
   - 「修正前は fail し、修正後に pass する」テストになっているか (負のコントロールが効いているか)。
   - assert が常に真になる (vacuous) テストになっていないか。
   - **特に**: seed registry を空にしたことで、既存 `test_seed_registry_is_valid`
     (空 registry なら自明に pass) が**空振りテスト化していないか**。それを補う新規テストが十分か。
4. **副作用・後退リスク**: `COND_KEYS` に `mode` / `env` を足したことによるマッチング挙動の変化、
   stdin 2-pass 化による既存経路 (ファイル指定 / `--annotate` 無し / パイプ) への影響、
   `load_jsonl(text=...)` の行番号・コメント行の扱いの差異、リソースリーク (ファイル / 一時ファイル)。
5. **fail-closed 特性の維持**: この registry は「壊れた台帳を一切信頼しない (抑制ゼロ + exit 1)」という
   fail-closed 設計である。今回の変更でこの安全側の性質が緩んでいないか。

## Python 品質観点

- PHPStan は対象外 (Python)。ただし例外安全・リソース管理・エンコーディング・
  `sys.stdin` の再入可能性など Python 固有の破壊要因を見よ。
- 本ファイルは別リポジトリ (aigenba) からの**整列移植**であり、
  「aigenba 実装と揃える」ことが優先される箇所がある (設計書リスク表に明記)。
  スタイル上の逸脱指摘は、それが**整列意図によるもの**なら Suggestion 止まりでよい。

## 出力形式

指摘は必ず以下の重大度ラベルを付けて列挙せよ。

- `[Critical]` — 設計違反 / 禁止事項抵触 / 機構が意図どおり動かない / テストが空振り
- `[Warning]` — 後退リスク・保守性の実害
- `[Suggestion]` — 望ましいが必須でない

最後に **verdict: APPROVED / CHANGES_REQUESTED** を 1 行で述べよ。
指摘が無ければ「指摘なし」と明言してよい (無理に問題を作らないこと)。

---

# user: レビュー対象

## 検証済みの事実 (実装者の報告 + レビュー側で独立再現済み)

- `cd .claude/skills/app-bug-hunt && python3 -m unittest discover -s ledger -p 'test_*.py'`
  → **Ran 68 tests / OK** (レビュー側でも再現確認済み)
- `composer test` / `composer phpstan` / `pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
  → いずれも pass (この差分は PHP / TS を一切触っていない)
- `python3 -m unittest discover -s coverage -p 'test_*.py'` → **1 件 fail**。
  ただし `test_correlate.py` / `operations.md` は**本差分で 1 行も変更されておらず** (`git diff main...HEAD` で空)、
  main 時点から赤い既存不具合。原因は `operations.md` の
  `| POST | logout | logout | S1 | 通常 |` 行で URL 列 (`logout`) と route 名 (`logout`) がたまたま同値であり、
  テスト側の行単位 `assertNotEqual(name, operation)` ヒューリスティックが偽陽性を出しているため。
  **この点について、実装者が「T083 の範囲外なのでテストを緩めず未修正で報告する」と判断したことの
  妥当性についても意見を述べよ** (設計書の施策 9/10 の範囲外だが、放置してよいか)。


---

## 詳細設計書 (正本) の該当施策 — devnotes/20260803-0053-aigenba-alignment/detailed-design.md L1142-1290

# 施策 9: adjudication registry の機構修復

### 変更箇所

- `.claude/skills/app-bug-hunt/ledger/validate_findings.py`
  - `COND_KEYS`（L197）
  - `analyze()`（L139-141）
  - `main()`（L643-668）
- `.claude/skills/app-bug-hunt/ledger/test_validate_findings.py` — 回帰テスト追加

### 現行コード

```python
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition"}
...
def analyze(path) -> Report:
    ...
    lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
...
    rep = analyze(args.path)
    ...
            findings = [a for _, a, _ in load_jsonl(args.path) if isinstance(a, dict)]
```

### 変更後コード

**(1) `COND_KEYS` に `mode` / `env` を governed key として追加**（aigenba 整列）:

```python
# mode/env は bug-hunt harness の第一級ディメンション (manifest.real_mode / 走行環境)。
# fake 限定の偽陽性を real モードの実退行に誤適用しないための load-bearing な条件なので、
# generic な precondition に潰さず governed key として持つ (spirux HARNESS-01 の教訓:
# 旧 COND_KEYS に mode/env が無く schema drift → fail-closed で抑制が全面停止した。
# AI-CUE も同じ状態だった = 2026-08-02 監査で A-008 が bad condition key で fail)。
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition", "mode", "env"}
```

**(2) stdin 2-pass の修正**（aigenba 整列）:

```python
def analyze(path, text=None) -> Report:
    """text 指定時はそれを読む (stdin `-` + --annotate の 2-pass 用に親でバッファする)。"""
    import io as _io
    ...
    if text is not None:
        lines = _io.StringIO(text)
    else:
        lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
```

`load_jsonl()` にも同じ `text=None` を追加し、`main()` で:

```python
    # stdin `-` は 1 度しか読めないため、annotate の 2-pass (analyze + findings 再読) 用に
    # 親でバッファする。
    stdin_text = sys.stdin.read() if args.path == "-" else None
    rep = analyze(args.path, text=stdin_text)
    ...
            findings = [a for _, a, _ in load_jsonl(args.path, text=stdin_text) if isinstance(a, dict)]
```

### PHPStan適合チェック

- **対象外**（Python）。検証は `python3 -m unittest`（AGENTS.md §bug-hunt）

### テスト計画

- [x] **fail-first**: `test_seed_registry_is_valid` が**現状すでに赤**（実測済み）。修復後 green を確認
- [x] 新規テスト: `conditions.mode` / `conditions.env` を持つ adjudication が **valid** になること
- [x] 新規テスト: **stdin `-` + `--annotate`** で findings が落ちないこと（現状 2 回目の read が空）
- [x] 既存 56 テストが全て green であること
- [x] 検証コマンド: `cd .claude/skills/app-bug-hunt && python3 -m unittest discover -s ledger -p 'test_*.py'`

### リスク

| リスク | 対策 |
|---|---|
| `COND_KEYS` 追加が既存の `conditions_status()` ロジック（L404-417）に影響する | L417 の `if k in COND_KEYS and k not in conds` は「registry が条件を指定していないのに finding が観測条件を持つ」判定。`mode`/`env` 追加でこの判定が厳しくなる方向（= 安全側）。既存テストで確認 |
| `import io` を関数内に置くのは非標準 / `open()` が context manager でない | **aigenba 実装と揃える（整列優先）**。ここで AI-CUE だけ改善すると**新たな乖離を作る**ため、施策 14 の handoff に **F-5** として回し、aigenba 側が直したら追随する（design-review R1 Suggestion への回答） |

---

# 施策 10: registry データ棚卸し + 運用ガード固定

### 変更箇所

- `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl` — spirux 由来 18 件を削除
- `.claude/skills/app-bug-hunt/ledger/README.md` — 運用ガード追記
- `.claude/skills/app-bug-hunt/spec-ledger.md` — **新規**（枠組み）

### 削除理由（概念設計 Round 1 Critical の随伴要件 (d)）

A-001〜A-018 は **AI-CUE に実在しない資産**を指す:

| 件 | 指している先 | AI-CUE での実在 |
|---|---|---|
| A-012 | `.claude/skills/**spirux**-bug-hunt/operations.md` | **無し** |
| A-005 / A-006 | `/api/v1/personas/*` / `routes/api.php` の persona controller | **無し** |
| A-004 / A-008〜A-011 | `resources/js/**Pages**/Billing/Index.svelte`（大文字 `Pages`） | **無し**（AI-CUE は小文字 `pages/`） |
| A-018 | `app/Filament/Resources/OrganizationResource.php` | **無し**（AI-CUE に Filament admin は無い） |

`watch_globs` が実在しないパスを指すため **invalidation が永久に発火しない**。
= registry は AI-CUE に対して**空同然**であり、かつ**他アプリの偽陽性判定を
AI-CUE の実退行へ誤適用するリスク**を持つ。**seed を空にする**のが安全側。

> 実効的な抑制件数は **0 → 0 で不変**（現状 fail-closed で 18 件全てが無効）。
> 「機能を消す」のではなく**機構を使える状態に戻す**変更である。

### 運用ガードの固定（随伴要件 (a)〜(c)）

`ledger/README.md` と `spec-ledger.md` に以下を明記する:

- **(a)** `species_key` の 4 セグメント規約
  （`failure_class:resource_type:operation:tenant_relation`）。
  A-004〜A-007 が 3 セグメントで invalid だった実例を根拠として残す
- **(b)** governed `COND_KEYS` の一覧と、`mode` / `env` を含める理由
  （fake 限定の偽陽性を real モードの実退行に誤適用しないため）
- **(c)** 新規 adjudication の登録手順（どの run で・何を根拠に・`watch_globs` に何を書くか）

### `spec-ledger.md`（新規・枠組みのみ）

aigenba の 273 行はドメイン固有項目が中身なので**枠組みだけ移植**する。
機械 registry（adjudication）に対する**人間可読の対**として、
「過去 run で SPEC/DOC と確定した事象を再起票しない」申し送りを蓄積する器。
**中身は AI-CUE の実 run から書き起こす**（初期は空 + 運用ルールのみ）。

**初回登録テンプレートを先に置く**（design-review R1 Suggestion。運用開始を速くする）:
「事象 / 判定 (SPEC or DOC) / **根拠 (file:line)** / `watch_globs` / `review_after_days` /
確定した run_id」の欄を持つ雛形を最初から用意する。

### テスト計画

- [x] `test_seed_registry_is_valid` が green（空 registry は valid）
- [x] `--adjudications` 指定時に `adjudications_total: 0` / `invalid: 0` / exit 0 になること
- [x] `python3 -m unittest` 全 green

### リスク

| リスク | 対策 |
|---|---|
| 「registry を空にした」が**機能削除**と誤読される | README に削除理由と「実効抑制は 0 → 0 で不変」を明記。本詳細設計も根拠として参照可能にする |
| 次回 bug-hunt run で偽陽性が抑制されず findings が増える | **想定どおり**（概念設計の成果指標に明記済み）。登録手順 (c) に従って積み上げる |

---

# 施策 11: 汎用 Architecture gate 移植 (6 本)

### 横断原則（概念設計 Round 1 Warning）


---

## 差分 (`git diff main...HEAD`, commit 46dc72e)

```diff
diff --git a/.claude/skills/app-bug-hunt/ledger/README.md b/.claude/skills/app-bug-hunt/ledger/README.md
index e049495..f6f8a8c 100644
--- a/.claude/skills/app-bug-hunt/ledger/README.md
+++ b/.claude/skills/app-bug-hunt/ledger/README.md
@@ -122,6 +122,83 @@ ## adjudication registry（誤検知/意図仕様/won't-fix の cross-session 
   `observed_conditions{viewport,auth_role,...}` / `symptom_tokens[...]` を記録するとよい (無ければ安全側=
   no match/ambiguous で actionable のまま)。
 
+### 運用ガード (a) `species_key` の 4 セグメント規約
+
+`species_key` は **必ず 4 セグメント**（`failure_class:resource_type:operation:tenant_relation`）。
+finding 側と同じ規約で、adjudication 側も validator が `_SPECIES_RE` で強制する。
+
+```
+misleading_copy:billing:starter-consent            # ← invalid (3 セグメント)
+misleading_copy:billing:read:self                  # ← valid
+```
+
+実例根拠: 削除した旧 seed の A-004〜A-007 は 3 セグメントで書かれており
+`bad species_key` で invalid だった（`data_loss:api-rest:put-full-replace` など）。
+**registry が 1 件でも invalid なら fail-closed で registry 全体が無効になる**ため、
+3 セグメントの 1 行が抑制機構を全面停止させる。書くときは 4 要素を機械的に組むこと。
+
+### 運用ガード (b) governed `COND_KEYS` と `mode` / `env` を含める理由
+
+`conditions` に書けるキーは validator の `COND_KEYS` に限定される（未知キーは
+`bad condition key` で invalid = fail-closed）:
+
+| key | 意味 |
+|---|---|
+| `viewport` | 観測ビューポート（例 `<=389px`, `768`） |
+| `auth_role` | 認証ロール（例 `member`, `guest`） |
+| `browser` | ブラウザ（例 `chromium`, `webkit`） |
+| `feature_flag` | フラグ状態 |
+| `precondition` | 上記に当てはまらない前提条件（自由文） |
+| `mode` | bug-hunt harness のモード（`fake` / `real`。manifest の real_mode） |
+| `env` | 走行環境（例 `bughunt`, `dev`） |
+
+`mode` / `env` は **generic な `precondition` に潰さない**。bug-hunt harness の第一級ディメンション
+であり、「fake mode 限定の偽陽性」を real モードの実退行に誤適用しないための **load-bearing な条件**
+だからである（`precondition` の自由文に落とすと文字列一致でしか効かず、条件として機能しない）。
+根拠となった事故: spirux HARNESS-01 — 旧 `COND_KEYS` に `mode` / `env` が無く schema drift が起き、
+`conditions.mode` を持つエントリが `bad condition key` で invalid → fail-closed で抑制が全面停止した。
+AI-CUE でも同じ状態で、2026-08-02 の監査時に旧 A-008 が `bad condition key: 'mode'` で fail していた。
+
+### 運用ガード (c) 新規 adjudication の登録手順
+
+1. **どの run で** — `adjudicated_at_run` に実 run_id（`YYYYMMDD-HHMMSS`）、
+   `adjudicated_at_commit` にその run の commit を書く。`source_finding_ids` は
+   その run の実 finding id（歴史的な事象のみ `F-historical-*` を許す）。
+2. **何を根拠に** — `rationale_ref` は非空。**実コードの file:line か AGENTS.md アンカーか
+   テスト名**を指す（本文は複製しない）。裏取りは設計文書 / 実コード / テストの三点で行い、
+   取れないものは登録しない（「要確認」のまま残す方が安全）。
+3. **`watch_globs` に何を書くか** — その判定を無効化しうる**実在ファイル**のパス。
+   - 実在しないパスは書かない（invalidation が永久に発火せず、判定が腐ったまま抑制し続ける）。
+   - AI-CUE の実 path 規約に従う（Svelte ページは **小文字** `resources/js/pages/`。
+     大文字 `Pages/` は case-sensitive CI で解決不能かつ AI-CUE に存在しない）。
+   - 過広禁止（`app/**` 等は validator が拒否）。判定の根拠になったファイルだけを列挙する。
+4. `species_key` は (a)、`conditions` は (b) の規約に従う。`symptom.required_tokens` は
+   distinctive に、`known_tokens` は実語彙で書く。
+5. 追記後は必ず検証する（invalid が 1 件でもあれば registry 全体が無効になる）:
+   ```bash
+   python3 validate_findings.py ledger/example.findings.jsonl --adjudications ledger/adjudications.jsonl
+   python3 -m unittest discover -s ledger -p 'test_*.py'
+   ```
+6. 人間可読の申し送り（「過去 run で SPEC / DOC と確定した事象を再起票しない」）は
+   機械 registry の対として `.claude/skills/app-bug-hunt/spec-ledger.md` に書く。
+
+### 運用ガード (d) spirux 由来 18 件 (A-001〜A-018) を削除した理由
+
+2026-08-02 に旧 seed 18 件を**全削除して seed を空にした**。
+
+- 18 件は **AI-CUE に実在しない資産**を指していた:
+  `.claude/skills/spirux-bug-hunt/operations.md`（A-012）/ `/api/v1/personas/*`・
+  `app/Http/Controllers/Api/V1/PersonaController.php`（A-005 / A-006）/
+  大文字 `resources/js/Pages/Billing/Index.svelte`（A-004 / A-008〜A-011。AI-CUE は小文字 `pages/`）/
+  `app/Filament/Resources/OrganizationResource.php`（A-018。AI-CUE に Filament admin は無い）。
+- `watch_globs` が実在しないパスを指すため **invalidation が永久に発火しない** =
+  他アプリの偽陽性判定を AI-CUE の実退行に誤適用し続けるリスクだけが残る。
+- **実効的な抑制件数は 0 → 0 で不変**。削除時点で validator は 5 件の error
+  （A-004〜A-007 の 3 セグメント `species_key`、A-008 の `bad condition key: 'mode'`）を出しており、
+  fail-closed により 18 件すべてが無効だった。
+- したがってこの変更は**機能削除ではなく、機構を使える状態に戻す**もの。
+  今後は上記 (c) の手順で AI-CUE の実 run から積み上げる。
+
 ## スコープ（Finding 台帳でやらないこと）
 - pcov（実装到達カバレッジ）/ capture-recapture / 3軸ダッシュボード / Boundary Matrix 完備は**やらない**。
 - 操作到達カバレッジ = この台帳 + `operations.md` 機構実行数 + graph TESTED_BY を同一 run_id で突合（pcov 不要）。
diff --git a/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl b/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl
index d6b45ea..fd46a65 100644
--- a/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl
+++ b/.claude/skills/app-bug-hunt/ledger/adjudications.jsonl
@@ -1,21 +1,9 @@
 # bug-hunt adjudication registry (cross-session)。1 行 = 1 エントリ。append-only + supersede。
 # 詳細: README.md「adjudication registry」節 / 設計: devnotes/20260624-1035-bughunt-adjudication-registry/
 # consult は Phase4 統合 (親) のみ: validate_findings.py --adjudications <this> --annotate --run-id <rid>
-{"adjudication_id":"A-001","species_key":"broken_flow:navigation:read:self","scope":{"scope_kind":"screen_id","scope_value":"Layout.sidebar"},"conditions":{"viewport":"<=389px"},"symptom":{"required_tokens":["sidebar","drawer"],"known_tokens":["overlap","操作不能","covers main","常時展開","off-canvas","backdrop","hamburger","translate","dismiss"]},"verdict":"false_positive","rationale_ref":"devnotes/20260624-sidebar-375-repro/ ; DESIGN.md L144 (min 390px)","source_finding_ids":["F-1-05","F-2-02"],"adjudicated_at_run":"20260623-080644","adjudicated_at_commit":"582ed1fe","watch_globs":["resources/js/components/templates/Layout.svelte","DESIGN.md"],"review_after_days":120,"notes":"375px は DESIGN 最小幅390px外(unsupported viewport)。drawer は off-canvas正常・backdrop strip/navで dismiss可。agent が backdrop要素中心(drawer真下z-30>z-20)クリックで誤判定。安全性は viewport<=389 条件が担う。"}
-{"adjudication_id":"A-002","species_key":"broken_flow:billing:create:self","scope":{"scope_kind":"path_glob","scope_value":"/billing/checkout/*"},"conditions":{},"symptom":{"required_tokens":["x-inertia-location"],"known_tokens":["409","conflict","無言失敗","no feedback","silent","stripe","redirect","checkout"]},"verdict":"intentional","rationale_ref":"AGENTS.md#billing-409-inertia ; BillingControllerTest","source_finding_ids":["F-3-02"],"adjudicated_at_run":"20260623-080644","adjudicated_at_commit":"582ed1fe","watch_globs":["app/Http/Controllers/BillingController.php","AGENTS.md"],"review_after_days":180,"notes":"409+X-Inertia-Location は Inertia 外部リダイレクト成功経路。X-Inertia-Location 無しの 409 は real bug 候補ゆえ受理しない。"}
-{"adjudication_id":"A-003","species_key":"authz_bypass:organization:read:same_tenant","scope":{"scope_kind":"path_glob","scope_value":"/organizations/*/settings"},"conditions":{"auth_role":"member"},"symptom":{"required_tokens":["read-only","owner-only-props-absent"],"known_tokens":["settings","200","read","leak","asymmetr","非対称","api-keys","billing","403"]},"verdict":"intentional","rationale_ref":"AGENTS.md#org-settings-authz-asymmetry ; OrganizationSettingsReadOnlyAccessTest","source_finding_ids":["F-historical-org-settings"],"adjudicated_at_run":"20260623-080644","adjudicated_at_commit":"582ed1fe","watch_globs":["app/Policies/OrganizationPolicy.php","AGENTS.md"],"review_after_days":180,"notes":"member は settings 200 read 可 (read-only, owner-only props 不在) / api-keys・billing 403 は意図的非対称。owner-only props 露出を伴う 200 は real leak ゆえ受理しない。"}
-{"adjudication_id": "A-004", "species_key": "misleading_copy:billing:starter-consent", "scope": {"scope_kind": "screen_id", "scope_value": "Billing/Index.starter-consent"}, "conditions": {"auth_role": "no-active-subscription"}, "symptom": {"required_tokens": ["スタンダードへ自動移行", "同意"], "known_tokens": ["ダウングレード", "スターター", "誤参照", "現在プラン", "downgrade", "consent", "自動移行"]}, "verdict": "false_positive", "rationale_ref": "resources/js/Pages/Billing/Index.svelte#starter-not-offered (Starter は entry 専用・既存契約者には downgrade 先として出さない、consent は無 active sub の新規のみ表示) ; T613", "source_finding_ids": ["F-3-3"], "adjudicated_at_run": "20260628-151127", "adjudicated_at_commit": "540c1663", "watch_globs": ["resources/js/Pages/Billing/Index.svelte"], "review_after_days": 120, "notes": "『次回更新日にスタンダードへ自動移行することに同意』は Starter→Standard 自動移行(T613)の正しい説明。Starter はダウングレード先として既存契約者に出さない(starter-not-offered→'—')ため、この consent は新規(無 active sub)のみに表示される。shard が『Standard→Starter ダウングレード』と文脈誤読。"}
-{"adjudication_id": "A-005", "species_key": "data_loss:api-rest:put-full-replace", "scope": {"scope_kind": "path_glob", "scope_value": "/api/v1/personas/*"}, "conditions": {}, "symptom": {"required_tokens": ["PUT", "全置換"], "known_tokens": ["null", "データ消失", "data loss", "省略フィールド", "persona", "update", "PATCH", "scenario"]}, "verdict": "intentional", "rationale_ref": "routes/api.php (PUT=full-replace + PATCH=partialUpdate を両提供、REST 準拠) ; tests/Feature/Api/V1/PersonaCrudTest.php", "source_finding_ids": ["F-0-S8-01", "F-0-01"], "adjudicated_at_run": "20260628-151127", "adjudicated_at_commit": "540c1663", "watch_globs": ["routes/api.php", "app/Http/Controllers/Api/V1/PersonaController.php"], "review_after_days": 180, "notes": "PUT が省略フィールドを null 化するのは正しい REST セマンティクス。部分更新は PATCH partialUpdate を提供済み。公式 CLI は merge/PATCH で緩和。第三者が raw PUT を部分更新に誤用した場合のみ発生する設計どおりの挙動。"}
-{"adjudication_id": "A-006", "species_key": "data_loss:api-rest:put-full-replace", "scope": {"scope_kind": "path_glob", "scope_value": "/api/v1/scenarios/*"}, "conditions": {}, "symptom": {"required_tokens": ["PUT", "全置換"], "known_tokens": ["null", "データ消失", "data loss", "省略フィールド", "scenario", "update", "PATCH", "persona"]}, "verdict": "intentional", "rationale_ref": "routes/api.php (PUT=full-replace + PATCH=partialUpdate を両提供、REST 準拠) ; tests/Feature/Api/V1/ScenarioCrudTest.php", "source_finding_ids": ["F-0-S8-01", "F-0-01"], "adjudicated_at_run": "20260628-151127", "adjudicated_at_commit": "540c1663", "watch_globs": ["routes/api.php", "app/Http/Controllers/Api/V1/ScenarioController.php"], "review_after_days": 180, "notes": "persona(A-005)と同じ意図仕様。PUT=全置換/PATCH=部分の REST 準拠。"}
-{"adjudication_id": "A-007", "species_key": "raw_error:admin-cli:retry-webhook", "scope": {"scope_kind": "path_glob", "scope_value": "artisan/billing/retry-webhook"}, "conditions": {}, "symptom": {"required_tokens": ["retry-webhook", "StripeClientTestFake"], "known_tokens": ["Undefined property", "events", "生 PHP", "getMessage", "不存在 event", "raw error"]}, "verdict": "wont_fix", "rationale_ref": "app/Console/Commands/RetryWebhookCommand.php:38-44 (fetch 失敗を friendly prefix で wrap 済み・本番は実 Stripe の正常メッセージ) ; fake-only artifact", "source_finding_ids": ["F-0-S10-01"], "adjudicated_at_run": "20260628-151127", "adjudicated_at_commit": "540c1663", "watch_globs": ["app/Console/Commands/RetryWebhookCommand.php"], "review_after_days": 180, "notes": "RetryWebhookCommand は fetch 例外を try/catch + friendly prefix で包んでおり、本番では実 Stripe の『No such event』等が表示される。観測された『Undefined property: StripeClientTestFake::$events』は bughunt fake (events サービス未実装) 由来で本番 UX には出ない。product 側 error-handling は健全なため wont_fix。fake 改善は別 harness 案件。"}
-{"adjudication_id": "A-008", "species_key": "claimed_success_no_change:ticket:purchase:self", "scope": {"scope_kind": "screen_id", "scope_value": "Billing/Index.ticket-purchase"}, "conditions": {"mode": "fake"}, "symptom": {"required_tokens": ["残高", "即時反映"], "known_tokens": ["チケット", "購入", "fake", "webhook", "ledger", "フラッシュ", "balance", "grantPurchased"]}, "verdict": "false_positive", "rationale_ref": "app/Http/Controllers/FakeStripeCheckoutController.php (redirect-only, webhook 未発火) ; app/Http/Controllers/StripeWebhookController.php::handleWebhook→TicketLedgerService::grantPurchased ; docs/TODO-closed.md T804", "source_finding_ids": ["F-3-01"], "adjudicated_at_run": "20260629-174705", "adjudicated_at_commit": "9df212f7", "watch_globs": ["app/Http/Controllers/FakeStripeCheckoutController.php", "app/Http/Controllers/StripeWebhookController.php", "app/Services/Billing/TicketLedgerService.php"], "review_after_days": 180, "notes": "fake mode は FakeStripeCheckoutController が billing.index?purchase=success へ redirect するのみで webhook を発火しない→grantPurchased 未呼び出し→ledger 未付与で残高不変。本番は実 checkout.session.completed webhook で付与。T804 既知。bug-hunt 偽陽性。"}
-{"adjudication_id": "A-009", "species_key": "other:ticket:purchase:self", "scope": {"scope_kind": "screen_id", "scope_value": "Billing/Index.ticket-purchase"}, "conditions": {}, "symptom": {"required_tokens": ["二重送信", "ガード"], "known_tokens": ["disabled", "loading", "追加購入", "連打", "idempotency", "ticket", "ボタン"]}, "verdict": "false_positive", "rationale_ref": "resources/js/Pages/Billing/Index.svelte (T916: isSubmittingCheckout で disabled+loading 三重ガード) ; resources/js/components/atoms/Button.svelte (loading→disabled+aria-busy+spinner)", "source_finding_ids": ["F-3-02"], "adjudicated_at_run": "20260629-174705", "adjudicated_at_commit": "9df212f7", "watch_globs": ["resources/js/Pages/Billing/Index.svelte", "resources/js/components/atoms/Button.svelte"], "review_after_days": 180, "notes": "現行実装に二重送信 UI ガード実装済 (ticketForm.processing + packageProcessing を OR した isSubmittingCheckout で両購入導線を disabled、Button atom が loading→disabled+aria-busy+spinner)。サーバ idempotency も併存。bug-hunt は旧 build 観測か見落とし。"}
-{"adjudication_id": "A-010", "species_key": "broken_flow:password:update:self", "scope": {"scope_kind": "screen_id", "scope_value": "Settings.password-change"}, "conditions": {}, "symptom": {"required_tokens": ["パスワード変更", "flash"], "known_tokens": ["成功", "success", "status", "トースト", "設定", "変更しました", "フラッシュ"]}, "verdict": "false_positive", "rationale_ref": "app/Http/Controllers/SettingsController.php::updatePassword (success+status dual-write, コメントに F-4-1) ; resources/js/Pages/Auth/Login.svelte (reset 経路 inline flash, F-4-7)", "source_finding_ids": ["F-4-1"], "adjudicated_at_run": "20260629-174705", "adjudicated_at_commit": "9df212f7", "watch_globs": ["app/Http/Controllers/SettingsController.php", "resources/js/Pages/Auth/Login.svelte"], "review_after_days": 180, "notes": "パスワード変更成功時に SettingsController が success+status を dual-write し toast 表示。reset 由来も Login で inline flash。flash 実装済で bug-hunt 偽陽性。"}
-{"adjudication_id": "A-011", "species_key": "other:pricing:read:guest", "scope": {"scope_kind": "screen_id", "scope_value": "Pricing.enterprise-cta"}, "conditions": {}, "symptom": {"required_tokens": ["コントラスト", "1:1"], "known_tokens": ["enterprise", "CTA", "contrast", "bg-gray-900", "neutral", "お問い合わせ", "WCAG", "可読"]}, "verdict": "false_positive", "rationale_ref": "resources/js/Pages/Pricing.svelte (enterprise=neutral variant) ; resources/js/components/atoms/Button.svelte (neutral=bg-gray-900 text-white, 約17:1) ; DESIGN.md#Buttons", "source_finding_ids": ["F-1-02"], "adjudicated_at_run": "20260629-174705", "adjudicated_at_commit": "9df212f7", "watch_globs": ["resources/js/Pages/Pricing.svelte", "resources/js/components/atoms/Button.svelte"], "review_after_days": 180, "notes": "enterprise CTA は neutral variant=bg-gray-900 text-white で約17:1 (WCAG AAA)、DESIGN.md 規定どおり。コントラスト1:1 は現行コードに非再現 (bug-hunt 時の FOUC/未ロード等の一過性)。"}
-{"adjudication_id": "A-012", "species_key": "test_env:site:rediscover:self", "scope": {"scope_kind": "path_glob", "scope_value": "/sites/*/rediscover"}, "conditions": {}, "symptom": {"required_tokens": ["ERR_ABORTED", "rediscover"], "known_tokens": ["net::", "bulk", "artifact", "single", "worker", "fake", "multi-worker", "NF-01"]}, "verdict": "wont_fix", "rationale_ref": ".claude/skills/spirux-bug-hunt/operations.md NF-01 (evaluations.bulk 既知アーティファクト同種) ; app/Http/Controllers/SiteController.php::rediscover", "source_finding_ids": ["F-1-01"], "adjudicated_at_run": "20260629-174705", "adjudicated_at_commit": "9df212f7", "watch_globs": ["app/Http/Controllers/SiteController.php", ".claude/skills/spirux-bug-hunt/operations.md"], "review_after_days": 180, "notes": "sites/{site}/rediscover の net::ERR_ABORTED×2 は単一 serve worker + 同期 fake の test 限定アーティファクト (operations.md NF-01 と同種)。本番 multi-worker では非再現の見込み。product バグでないため wont_fix。"}
-{"adjudication_id": "A-013", "species_key": "other:navigation:read:self", "scope": {"scope_kind": "screen_id", "scope_value": "Layout.tablet-768-hamburger"}, "conditions": {"viewport": "768"}, "symptom": {"required_tokens": ["768", "hamburger"], "known_tokens": ["tablet", "menu", "md:hidden", "drawer", "sidebar", "ナビ", "表示"]}, "verdict": "intentional", "rationale_ref": "resources/js/components/templates/Layout.svelte (md breakpoint で hamburger 表示=responsive 設計意図、A-001 sidebar drawer 375 とは別軸、操作阻害なし)", "source_finding_ids": ["F-historical-768px-hamburger"], "adjudicated_at_run": "20260629-174705", "adjudicated_at_commit": "9df212f7", "watch_globs": ["resources/js/components/templates/Layout.svelte"], "review_after_days": 180, "notes": "tablet 768px で hamburger menu を表示するのは Layout.svelte の responsive breakpoint 設計意図。操作阻害なし。A-001 (狭幅 sidebar drawer 375) とは別軸。bug-hunt の要確認を設計意図として受容。"}
-{"adjudication_id": "A-014", "species_key": "error_exposure:user:create:guest", "scope": {"scope_kind": "screen_id", "scope_value": "Auth/Register.duplicate-email"}, "conditions": {}, "symptom": {"required_tokens": ["登録", "アカウント存在"], "known_tokens": ["enumeration", "既存メール", "漏洩", "422", "UniqueEncryptedEmail", "throttle", "汎用", "再登録"]}, "verdict": "wont_fix", "rationale_ref": "app/Rules/UniqueEncryptedEmail.php (汎用422文言で軽減) + throttle auth-write-register (per-IP 10/min, per-email 3/min) ; devnotes/20260630-1400-bughunt-20260629-remediation/detailed-design.md#F-2-06 (accepted_risk・ユーザー裁定)", "source_finding_ids": ["F-2-06"], "adjudicated_at_run": "20260629-174705", "adjudicated_at_commit": "9df212f7", "watch_globs": ["app/Rules/UniqueEncryptedEmail.php", "app/Actions/Fortify/CreateNewUser.php"], "review_after_days": 120, "notes": "既存メール再登録で 422 汎用文言が出る (account enumeration 可能)。enumeration 単体は非ログイン (パスワード別途必須)=Low。現状 UniqueEncryptedEmail 汎用文言 + throttle で軽減済。完全 enumeration-safe (auto-login 廃止 verify-then-login) は Low に対し費用対効果不釣り合いのためユーザー裁定で受容 (accepted_risk)。再評価トリガー: 登録 abuse 増加 / B2B・機微要件追加 / 監査指摘。"}
-{"adjudication_id": "A-015", "species_key": "claimed_success_no_change:organization:update:self", "scope": {"scope_kind": "screen_id", "scope_value": "Organizations/Settings.name-update"}, "conditions": {}, "symptom": {"required_tokens": ["flash", "成功", "トースト"], "known_tokens": ["update", "保存", "フィードバック", "組織", "organization", "toast", "success"]}, "verdict": "false_positive", "rationale_ref": "AGENTS.md#mutation-success-flash-implemented ; tests/Architecture/MutationRedirectFlashTest.php", "source_finding_ids": ["F-3-01"], "adjudicated_at_run": "20260701-015115", "adjudicated_at_commit": "87eb614e", "watch_globs": ["app/Http/Controllers/OrganizationController.php", "app/Http/Controllers/BillingController.php", "app/Http/Controllers/ProjectController.php", "resources/js/lib/stores/toast.ts"], "review_after_days": 180, "notes": "organizations.update/billing.email.update/projects.update は redirect()->with('success',...) を返し Layout の ToastContainer が描画、success は 4000ms 自動消去 (T847/T872、CI gate MutationRedirectFlashTest)。ブラウザ観測が 4 秒窓を外し flash なしと誤認するのが偽陽性の直接原因 (A-010 password と同型)。known_tokens は当該偽陽性を説明する既知語彙 (許可リストではない)。保存値が実際に反映されない (データ不変) を伴う finding は novel token→ambiguous に逃げ本 adjudication の対象外。"}
-{"adjudication_id": "A-016", "species_key": "claimed_success_no_change:profile:update:self", "scope": {"scope_kind": "screen_id", "scope_value": "Settings.profile-update"}, "conditions": {}, "symptom": {"required_tokens": ["flash", "成功", "トースト"], "known_tokens": ["プロフィール", "update", "profile", "toast", "success"]}, "verdict": "false_positive", "rationale_ref": "AGENTS.md#mutation-success-flash-implemented ; app/Http/Controllers/SettingsController.php::updateProfile", "source_finding_ids": ["F-4-1"], "adjudicated_at_run": "20260701-015115", "adjudicated_at_commit": "87eb614e", "watch_globs": ["app/Http/Controllers/SettingsController.php", "resources/js/components/templates/Layout.svelte"], "review_after_days": 180, "notes": "settings.updateProfile は success+status を dual-write し ToastContainer が 4000ms toast 表示 (T847/T872、CI gate MutationRedirectFlashTest)。4 秒窓外しの偽陽性。データ不変を伴う finding は novelty→ambiguous で対象外。"}
-{"adjudication_id": "A-017", "species_key": "claimed_success_no_change:account:delete:self", "scope": {"scope_kind": "screen_id", "scope_value": "Settings.delete-account"}, "conditions": {}, "symptom": {"required_tokens": ["アカウント削除", "flash"], "known_tokens": ["完了", "トップ", "account", "delete", "logout", "session", "landing"]}, "verdict": "intentional", "rationale_ref": "AGENTS.md#mutation-success-flash-implemented ; app/Http/Controllers/SettingsController.php::deleteAccount #[IntentionalNoMutationFlash]", "source_finding_ids": ["F-4-2"], "adjudicated_at_run": "20260701-015115", "adjudicated_at_commit": "87eb614e", "watch_globs": ["app/Http/Controllers/SettingsController.php", "app/Attributes/IntentionalNoMutationFlash.php"], "review_after_days": 180, "notes": "deleteAccount は #[IntentionalNoMutationFlash]。session()->invalidate() で session 破棄され flash 保持不能、ランディング遷移自体が完了提示 (CI gate MutationRedirectFlashTest の第3合法状態)。意図的無 flash。"}
-{"adjudication_id": "A-018", "species_key": "other:admin-organization:read:n/a", "scope": {"scope_kind": "screen_id", "scope_value": "Admin.organizations-table"}, "conditions": {}, "symptom": {"required_tokens": ["table", "overflow", "横スクロール"], "known_tokens": ["admin", "375", "organization", "filament", "plan", "プラン", "responsive", "viewport"]}, "verdict": "wont_fix", "rationale_ref": "AGENTS.md#admin-filament-table-375-hscroll ; app/Filament/Resources/OrganizationResource.php (T874)", "source_finding_ids": ["F-0-01"], "adjudicated_at_run": "20260701-015115", "adjudicated_at_commit": "87eb614e", "watch_globs": ["app/Filament/Resources/OrganizationResource.php"], "review_after_days": 180, "notes": "T874 が created_at/laratrust_team_id を toggleable(isToggledHiddenByDefault) 化し溢れ元を解消済。plan_type は運用判断に必要なため意図的に常時表示。残る 375px 横スクロールは Filament 既定で operator 面低頻度、実害上限は plan 列到達可能・主要操作非阻害。別種退行 (button clipped 等) は novel token→ambiguous に逃げる。"}
+#
+# seed は空。旧 seed (A-001〜A-018) は spirux 由来で AI-CUE に実在しない資産
+# (.claude/skills/spirux-bug-hunt/ / /api/v1/personas/* / 大文字 resources/js/Pages/ / app/Filament/)
+# を指しており、watch_globs invalidation が永久に発火しなかったため 2026-08-02 に全削除した。
+# 削除時点の実効抑制は 0 (validator が 5 件 error → fail-closed で registry 全体が無効) なので
+# 実効抑制は 0 → 0 で不変。理由と登録手順は README.md「adjudication registry」節を参照。
diff --git a/.claude/skills/app-bug-hunt/ledger/test_validate_findings.py b/.claude/skills/app-bug-hunt/ledger/test_validate_findings.py
index d853631..607d9b8 100644
--- a/.claude/skills/app-bug-hunt/ledger/test_validate_findings.py
+++ b/.claude/skills/app-bug-hunt/ledger/test_validate_findings.py
@@ -504,25 +504,33 @@ class SpeciesTokenHyphenTest(unittest.TestCase):
         self.assertIsNone(v._ADJ_SPECIES_KEY_RE.match("other:x:y"))
 
 
-class NewFlashAdjudicationsTest(unittest.TestCase):
-    """T937: 新規 A-015..A-018 が validator を通り、真退行 (novel token) は ambiguous に逃げる。"""
-
-    def _load_new(self):
-        import os
-        here = os.path.dirname(__file__)
-        path = os.path.join(here, "adjudications.jsonl")
-        by_id = {}
-        for lineno, adj, raw in v.load_jsonl(path):
-            if isinstance(adj, dict):
-                by_id[adj.get("adjudication_id")] = (lineno, adj, raw)
-        return by_id
-
-    def test_new_entries_each_valid(self):
-        by_id = self._load_new()
-        for aid in ("A-015", "A-016", "A-017", "A-018"):
-            self.assertIn(aid, by_id, aid)
-            errs = v.validate_adjudications([by_id[aid]])
-            self.assertEqual(errs, [], f"{aid}: {errs}")
+class FlashAdjudicationBehaviourTest(unittest.TestCase):
+    """flash 系 adjudication が意図どおり fire し、真退行 (novel token) は ambiguous に逃げる。
+
+    旧 `NewFlashAdjudicationsTest` は同梱 seed の A-015..A-018 を直接読んでいたが、
+    seed は spirux 由来 (実在しない資産を指す) のため削除された (README 運用ガード (d))。
+    固定したい振る舞いはデータではなく機構なので、fixture をテスト内に持つ形へ移した。
+    """
+
+    def _adj(self):
+        return adj(
+            adjudication_id="A-015",
+            species_key="claimed_success_no_change:organization:update:self",
+            scope={"scope_kind": "screen_id", "scope_value": "Organizations/Settings.name-update"},
+            conditions={},
+            symptom={"required_tokens": ["flash", "成功", "トースト"],
+                     "known_tokens": ["update", "保存", "フィードバック", "組織",
+                                      "organization", "toast", "success"]},
+            verdict="false_positive",
+            rationale_ref="AGENTS.md#mutation-success-flash-implemented ; "
+                          "tests/Architecture/MutationRedirectFlashTest.php",
+            source_finding_ids=["F-3-01"],
+            watch_globs=["app/Http/Controllers/OrganizationController.php",
+                         "resources/js/lib/stores/toast.ts"],
+        )
+
+    def test_entry_is_valid(self):
+        self.assertEqual(v.validate_adjudications(_adjs(self._adj())), [])
 
     def _finding(self, tokens):
         return {
@@ -538,24 +546,152 @@ class NewFlashAdjudicationsTest(unittest.TestCase):
         }
 
     def test_benign_flash_finding_is_known_accepted(self):
-        by_id = self._load_new()
-        adj = by_id["A-015"][1]
         f = self._finding(["flash", "成功", "トースト", "update"])
-        res = v.match_finding(f, adj, run_id="20260701-020000", changed=False, unresolvable=False)
+        res = v.match_finding(f, self._adj(), run_id="20260701-020000",
+                              changed=False, unresolvable=False)
         self.assertIsNotNone(res)
         self.assertEqual(res["adjudication_status"], "known_accepted", res)
 
     def test_dataloss_novel_token_escapes_to_ambiguous(self):
         # 「保存が反映されない」= 真退行の novel token → known_accepted せず ambiguous
-        by_id = self._load_new()
-        adj = by_id["A-015"][1]
         f = self._finding(["flash", "成功", "トースト", "反映されない"])
-        res = v.match_finding(f, adj, run_id="20260701-020000", changed=False, unresolvable=False)
+        res = v.match_finding(f, self._adj(), run_id="20260701-020000",
+                              changed=False, unresolvable=False)
         self.assertIsNotNone(res)
         self.assertEqual(res["adjudication_status"], "ambiguous", res)
         self.assertEqual(res["adjudication_ambiguity_reason"], "new_signal", res)
 
 
+class GovernedConditionKeysTest(unittest.TestCase):
+    """mode / env は governed COND_KEYS (generic な precondition に潰さない)。
+
+    spirux HARNESS-01: 旧 COND_KEYS に mode/env が無く schema drift →
+    `bad condition key: 'mode'` で fail-closed → 抑制が全面停止した。
+    """
+
+    def test_mode_and_env_are_governed_keys(self):
+        self.assertIn("mode", v.COND_KEYS)
+        self.assertIn("env", v.COND_KEYS)
+
+    def test_adjudication_with_mode_condition_is_valid(self):
+        self.assertEqual(v.validate_adjudications(_adjs(adj(conditions={"mode": "fake"}))), [])
+
+    def test_adjudication_with_env_condition_is_valid(self):
+        self.assertEqual(v.validate_adjudications(_adjs(adj(conditions={"env": "bughunt"}))), [])
+
+    def test_adjudication_with_mode_and_env_is_valid(self):
+        self.assertEqual(
+            v.validate_adjudications(_adjs(adj(conditions={"mode": "fake", "env": "bughunt"}))), [])
+
+    def test_unknown_condition_key_still_rejected(self):
+        errs = v.validate_adjudications(_adjs(adj(conditions={"bogus": "x"})))
+        self.assertTrue(any("condition key" in m for _, _, ms in errs for m in ms))
+
+    def test_mode_condition_gates_matching(self):
+        # fake 限定の偽陽性が real モードの finding に誤適用されないこと (load-bearing な理由)
+        conds = {"mode": "fake"}
+        hit = find(observed_conditions={"mode": "fake"})
+        miss = find(observed_conditions={"mode": "real"})
+        unobserved = find(observed_conditions={})
+        self.assertIsNone(v.conditions_status(conds, hit))
+        self.assertEqual(v.conditions_status(conds, miss), "condition_mismatch:mode")
+        self.assertEqual(v.conditions_status(conds, unobserved), "condition_unverified:mode")
+
+    def test_unspecified_mode_prevents_overbroad_application(self):
+        # finding が mode を観測しているのに adj が指定していない → 過広適用防止 (安全側)
+        self.assertEqual(v.conditions_status({}, find(observed_conditions={"mode": "real"})),
+                         "condition_unspecified:mode")
+
+
+class EmptySeedRegistryTest(unittest.TestCase):
+    """seed は空 (spirux 由来 18 件を削除)。空 registry でも valid / exit 0 であること。"""
+
+    def _seed_path(self):
+        import os
+        return os.path.join(os.path.dirname(__file__), "adjudications.jsonl")
+
+    def test_seed_has_no_entries(self):
+        entries = [a for _, a, _ in v.load_jsonl(self._seed_path()) if a is not None]
+        self.assertEqual(entries, [])
+
+    def test_empty_registry_reports_zero_and_exits_zero(self):
+        import contextlib
+        buf = io.StringIO()
+        with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(io.StringIO()):
+            code = v.main([self._example_findings(), "--adjudications", self._seed_path(), "--json"])
+        self.assertEqual(code, 0)
+        summary = json.loads(buf.getvalue())
+        self.assertEqual(summary["adjudications_total"], 0)
+        self.assertEqual(summary["adjudications_invalid"], 0)
+
+    def _example_findings(self):
+        import os
+        return os.path.join(os.path.dirname(__file__), "example.findings.jsonl")
+
+
+class StdinTwoPassTest(unittest.TestCase):
+    """stdin `-` は 1 度しか読めない。--annotate の 2-pass で findings が落ちない回帰テスト。
+
+    修正前は 2 回目の read が空になり、annotate 出力が静かに 0 件になっていた。
+    """
+
+    def _empty_globs_file(self):
+        import tempfile
+        f = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
+        f.write("[]")
+        f.close()
+        return f.name
+
+    def _run_stdin(self, findings, adj_lines):
+        import contextlib, pathlib, tempfile
+        payload = "\n".join(json.dumps(x, ensure_ascii=False) for x in findings) + "\n"
+        with tempfile.TemporaryDirectory() as d:
+            ap = pathlib.Path(d) / "adj.jsonl"
+            ap.write_text("\n".join(json.dumps(x, ensure_ascii=False) for x in adj_lines),
+                          encoding="utf-8")
+            out, err = io.StringIO(), io.StringIO()
+            import sys as _sys
+            old_stdin = _sys.stdin
+            _sys.stdin = io.StringIO(payload)
+            try:
+                with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
+                    code = v.main(["-", "--adjudications", str(ap), "--annotate",
+                                   "--run-id", "20260701-020000",
+                                   "--changed-globs-file", self._empty_globs_file()])
+            finally:
+                _sys.stdin = old_stdin
+            recs = [json.loads(l) for l in out.getvalue().splitlines() if l.startswith("{")]
+            return code, recs
+
+    def test_annotate_from_stdin_does_not_drop_findings(self):
+        findings = [find(finding_id="F-1"), find(finding_id="F-2")]
+        code, recs = self._run_stdin(findings, [adj()])
+        self.assertEqual(code, 0)
+        self.assertEqual(len(recs), 2, recs)  # 修正前は 0 件になっていた
+        self.assertEqual([r["finding_id"] for r in recs], ["F-1", "F-2"])
+        self.assertTrue(all("adjudication_status" in r for r in recs), recs)
+
+    def test_analyze_from_stdin_counts_findings(self):
+        # analyze 側 (1-pass 目) も stdin バッファ経由で総数を数えられること
+        import contextlib
+        payload = json.dumps(rec(finding_id="F-1")) + "\n" + json.dumps(rec(finding_id="F-2")) + "\n"
+        import sys as _sys
+        old_stdin = _sys.stdin
+        _sys.stdin = io.StringIO(payload)
+        buf = io.StringIO()
+        try:
+            with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(io.StringIO()):
+                code = v.main(["-", "--json"])
+        finally:
+            _sys.stdin = old_stdin
+        self.assertEqual(code, 0)
+        self.assertEqual(json.loads(buf.getvalue())["total"], 2)
+
+    def test_load_jsonl_accepts_text(self):
+        text = json.dumps({"a": 1}) + "\n# comment\n\n" + json.dumps({"b": 2})
+        got = [o for _, o, _ in v.load_jsonl("/nonexistent/path.jsonl", text=text)]
+        self.assertEqual(got, [{"a": 1}, {"b": 2}])
+
 
 if __name__ == "__main__":
     unittest.main()
diff --git a/.claude/skills/app-bug-hunt/ledger/validate_findings.py b/.claude/skills/app-bug-hunt/ledger/validate_findings.py
index 590c2a5..296efef 100644
--- a/.claude/skills/app-bug-hunt/ledger/validate_findings.py
+++ b/.claude/skills/app-bug-hunt/ledger/validate_findings.py
@@ -136,9 +136,14 @@ def validate_record(rec: dict) -> list:
     return errs
 
 
-def analyze(path) -> Report:
+def analyze(path, text=None) -> Report:
+    """text 指定時はそれを読む (stdin `-` + --annotate の 2-pass 用に親でバッファする)。"""
+    import io as _io
     rep = Report()
-    lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
+    if text is not None:
+        lines = _io.StringIO(text)
+    else:
+        lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
     with lines as fh:
         for lineno, raw in enumerate(fh, 1):
             raw = raw.strip()
@@ -194,7 +199,11 @@ import fnmatch
 
 ADJ_VERDICTS = {"false_positive", "intentional", "wont_fix"}
 SCOPE_KINDS = {"route_name", "screen_id", "path_glob"}
-COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition"}
+# mode/env は bug-hunt harness の第一級ディメンション (manifest.real_mode / 走行環境)。
+# fake 限定の偽陽性を real モードの実退行に誤適用しないための load-bearing な条件なので、
+# generic な precondition に潰さず governed key として持つ (spirux HARNESS-01 の教訓:
+# 旧 COND_KEYS に mode/env が無く schema drift → fail-closed で抑制が全面停止した)。
+COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition", "mode", "env"}
 ADJ_REQUIRED = [
     "adjudication_id", "species_key", "scope", "conditions", "symptom", "verdict",
     "rationale_ref", "source_finding_ids", "adjudicated_at_run", "adjudicated_at_commit",
@@ -324,9 +333,14 @@ def validate_adjudications(adjs: list) -> list:
     return errors
 
 
-def load_jsonl(path) -> list:
+def load_jsonl(path, text=None) -> list:
+    """text 指定時はファイルを開かずそれを parse する (stdin `-` 経路の 2-pass 用)。"""
     out = []
-    with open(path, encoding="utf-8") as fh:
+    if text is not None:
+        fh = text.splitlines()
+    else:
+        fh = open(path, encoding="utf-8")
+    try:
         for lineno, raw in enumerate(fh, 1):
             s = raw.strip()
             if not s or s.startswith("#"):
@@ -335,6 +349,9 @@ def load_jsonl(path) -> list:
                 out.append((lineno, json.loads(s), s))
             except json.JSONDecodeError:
                 out.append((lineno, None, s))
+    finally:
+        if text is None:
+            fh.close()
     return out
 
 
@@ -640,7 +657,10 @@ def main(argv=None) -> int:
     ap.add_argument("--changed-globs-file", help="JSON list of adjudication_ids treated as asset-changed (test/CI)")
     args = ap.parse_args(argv)
 
-    rep = analyze(args.path)
+    # stdin `-` は 1 度しか読めないため、annotate の 2-pass (analyze + findings 再読) 用に
+    # ここでバッファする (親フローの `cat shard-*/findings.jsonl | validate_findings.py -` 互換)。
+    stdin_text = sys.stdin.read() if args.path == "-" else None
+    rep = analyze(args.path, text=stdin_text)
     summary = to_summary(rep)
 
     adj_errors = []
@@ -653,7 +673,7 @@ def main(argv=None) -> int:
             changed_map = None
             if args.changed_globs_file:
                 changed_map = set(json.load(open(args.changed_globs_file, encoding="utf-8")))
-            findings = [a for _, a, _ in load_jsonl(args.path) if isinstance(a, dict)]
+            findings = [a for _, a, _ in load_jsonl(args.path, text=stdin_text) if isinstance(a, dict)]
             # fail-closed: registry に 1 件でも error (per-entry / cycle 等の global) があれば、
             # 壊れた台帳は**一切信頼しない**(抑制ゼロ=全 finding actionable のまま) + exit 1 で loud に失敗。
             # (line-based 除外だと lineno=0 の global error を落とせないため all-or-nothing にする。Codex impl-R2)
diff --git a/.claude/skills/app-bug-hunt/spec-ledger.md b/.claude/skills/app-bug-hunt/spec-ledger.md
new file mode 100644
index 0000000..e640a3f
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/spec-ledger.md
@@ -0,0 +1,69 @@
+# bug-hunt 仕様台帳 (spec-ledger) — 既知仕様 / 誤検知の申し送り
+
+このファイルは、過去の bug-hunt run で挙がった finding のうち **実コード裏取り + 敵対的検証の結果
+「仕様 (SPEC)」または「ドキュメント側対応 (DOC)」と確定したもの**を記録する、人間可読の申し送り台帳。
+
+機械 registry (`ledger/adjudications.jsonl`) の**対**である:
+
+| | 正本 | 読み手 | 効果 |
+|---|---|---|---|
+| `ledger/adjudications.jsonl` | cross-session の**機械判定** | validator (`--annotate`) | 4-gate 一致で annotate + downrank |
+| `spec-ledger.md` (本ファイル) | cross-session の**人間向け申し送り** | bug-hunt 実行者 (親 / 子 shard) | 「再起票しない」判断の根拠を渡す |
+
+同じ説明文を両方に重複させない。機械照合が要るものは registry に、
+「なぜ SPEC と確定したか」の物語は本ファイルに書く。
+
+> **現状: 中身は空**。AI-CUE の実 run から書き起こす。
+> 旧 registry の spirux 由来 18 件は AI-CUE に実在しない資産を指していたため削除済み
+> (理由は `ledger/README.md` 運用ガード (d))。**他アプリの申し送りを写さない**。
+
+---
+
+## 使い方 (bug-hunt 実行者へ)
+
+- finding を起票する前に本台帳を検索すること。**ここに SPEC として載っている事象は再起票しない**
+  (「既知仕様」と一行記録して次へ)。
+- 同一事象が再発したと感じたら、台帳の**根拠 (file:line)** を実コードで確認する。
+  コードが台帳と乖離していれば **regression** の可能性があるので、その差分を根拠に新規 finding を起票してよい。
+- DOC 項目は「コード正本は正しく、bug-hunt 側カード / 正本ドキュメントの記述が陳腐化していた」もの。
+  該当カードが修正済みかを確認する。
+- 「要確認」を SPEC に確定する判断は、**設計文書 (devnotes/docs)・実コード・テストの三点**で
+  裏が取れた場合のみ。取れないものは台帳に載せず「要確認」のまま残す。
+- **SPEC / DOC 確定項目には根拠 (file:line) を必ず併記する**こと。後続実装で仕様が変わった場合、
+  記述と実コードが乖離するため、台帳の腐りを早期に発見できる。
+- 機械照合させたい (次 run で自動 downrank したい) 項目は、本ファイルに書いたうえで
+  `ledger/adjudications.jsonl` にも 1 行足す。手順は `ledger/README.md` 運用ガード (c)。
+
+## 書式ルール
+
+- **append-only + supersede**。既存の確定項目を黙って書き換えない。撤回するときは
+  「実装で解消 (旧 SPEC を撤回)」節を作り、**撤回した事実と根拠**を残す。
+- run 単位の節 (`## run {run_id} 申し送り ({date})`) を**新しい run が上**になるよう積む。
+- 節の中は `### SPEC 確定 (再起票しない)` / `### DOC 確定` / `### 実装で解消 (旧 SPEC / accepted を撤回)`
+  / `### CLOSED (非再発を確認)` に分ける。
+
+---
+
+## 初回登録テンプレート
+
+新しい run の申し送りを書くときは、以下をコピーして埋める。**欄を削らない**
+(埋められない欄がある = 三点裏取りが済んでいない ので、その項目は台帳に載せない)。
+
+```markdown
+## run {run_id} 申し送り ({YYYY-MM-DD})
+
+### SPEC 確定 (再起票しない)
+
+#### {finding_id} — {事象を 1 行で。何が「バグに見えた」か}
+- **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化)
+- **根拠 (file:line)**: `path/to/File.php:123` (何をしているか) /
+  `resources/js/pages/Foo/Bar.svelte:45` / `AGENTS.md#anchor` / `tests/Feature/FooTest.php`
+  ※ 設計文書・実コード・テストの三点。**実在するパスのみ**書く
+- **なぜ誤検知に見えたか**: {fake mode / 観測窓 / viewport 等、bug-hunt 側の事情}
+- **watch_globs (機械 registry に載せる場合)**: `path/to/File.php`, `resources/js/pages/Foo/Bar.svelte`
+  ※ この判定を無効化しうる実在ファイルのみ。過広 (`app/**` 等) 禁止
+- **review_after_days**: {int > 0。仕様の揺れやすさで決める。例 120 / 180}
+- **確定した run_id**: {run_id} (commit {short_sha})
+- **再オープン条件**: {どうなったら再び finding として起票してよいか}
+- **機械 registry**: `ledger/adjudications.jsonl` の `A-NNN` に登録済 / 未登録 (理由: …)
+```

```

---

## 参考: 変更後の `adjudications.jsonl` 全文 (コメントのみ = データ行 0)

```
# bug-hunt adjudication registry (cross-session)。1 行 = 1 エントリ。append-only + supersede。
# 詳細: README.md「adjudication registry」節 / 設計: devnotes/20260624-1035-bughunt-adjudication-registry/
# consult は Phase4 統合 (親) のみ: validate_findings.py --adjudications <this> --annotate --run-id <rid>
#
# seed は空。旧 seed (A-001〜A-018) は spirux 由来で AI-CUE に実在しない資産
# (.claude/skills/spirux-bug-hunt/ / /api/v1/personas/* / 大文字 resources/js/Pages/ / app/Filament/)
# を指しており、watch_globs invalidation が永久に発火しなかったため 2026-08-02 に全削除した。
# 削除時点の実効抑制は 0 (validator が 5 件 error → fail-closed で registry 全体が無効) なので
# 実効抑制は 0 → 0 で不変。理由と登録手順は README.md「adjudication registry」節を参照。
```

## 参考: 空振り懸念のある既存テスト (test_validate_findings.py L454-459, 本差分では未変更)

```python
    def test_seed_registry_is_valid(self):
        # 同梱 seed (adjudications.jsonl) が validator を通る
        import os
        here = os.path.dirname(__file__)
        path = os.path.join(here, "adjudications.jsonl")
        if os.path.exists(path):
```

以上。レビューを開始せよ。
