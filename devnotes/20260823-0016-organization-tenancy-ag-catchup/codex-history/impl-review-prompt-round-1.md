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

---

# system: 実装レビュアー

あなたは Laravel 12 + Svelte 5 (Inertia) アプリの実装レビュアーである。
本レビューは **T247「組織テナンシー正典追従」** の実装レビューであり、
**Round 1 は施策 10 (旧 URL の走査根ベース残存検査) と、その実装中に見つけた実装欠陥の修正**に絞る。

## レビュー観点

1. **設計との一致性** — 詳細設計 (後述の抜粋) と実装がずれていないか。ずれている場合、
   その逸脱が「実装時に確定した事項」として妥当に説明されているか。
2. **静的検査 (gate) と走査器の共通規約への適合** — 本リポジトリの AGENTS.md は
   走査器に 5 条を課している:
   (a) クラス参照は完全修飾名で突き合わせる (名前解決を伴う走査のみ)
   (b) 解決できない形は落とす (fail-closed)。未解決を解決済みへ混ぜない。
       保証範囲外にする構文は docblock へ明記し、明記したならその構文の検出力を主張しない。
       「違反が 0 件」と「母集団が 0 件」を区別する
   (c) 検出力は負例で裏取りする (検出できること / 誤検出しないことの両方向)
   (d) 集めた走査結果を判定に使わない形を作らない
   (e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する。
       何を区切りとするかは走査ごとに宣言する
3. **正確性** — 走査器の見逃し・誤検出、免除目録の抜け道、自己検出の再帰。
4. **PHPStan level 10 適合性** (型を緩めていないか)。
5. **テスト網羅性** — 不変条件が機械検査になっているか。負例があるか。
6. **セキュリティ** — テナント境界 (層 2 = 404) と認可 (層 3 = 403) の分離、存在秘匿。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書く
- **保証範囲の誇張** (docblock が主張する検出力と実装の乖離) は Critical として扱う

---

# user

## 1. 背景 (T247 全体)

家系の機能台帳 lctl の feature `organization-tenancy` (裁定 AG-037/038/039 系/046/047) への追従。
「いまどの組織か」は **URL だけ**で決まる。保持列 (`users.current_organization_id`) と
切替 endpoint (`organizations.switch`) は撤去し、業務 route は全数を
`/organizations/{organization:slug}/…` 配下へ移した (**route 名は不変**)。
旧 URL からの転送は置かない (思考原則 3: 後方互換の並走を残さない)。

施策 1〜9・11 は先行実装済みで、本レビューの対象は **施策 10** と、
施策 10 を green にする過程で見つかった実装欠陥の修正である。

## 2. 詳細設計 (施策 10 の抜粋)

## 施策 10: 旧 URL の走査根ベース残存検査

### 2 つの台帳を分ける（reviewer Critical）

route 名は施策 5 で**維持**するので、検出対象は次の 2 つに分かれる。**同じ台帳にしない**。

| 台帳 | 対象 | 内容 |
|---|---|---|
| **旧パス台帳** | URL 文字列 | 組織 prefix を持たない旧パス（`/projects/` `/billing` `/dashboard` `/notifications` `/manage/users` `/onboarding/` `/purchase-tickets` `/billing-required`、および `/app` のうち**分岐入口以外**） |
| **撤去 route 名台帳** | route 名 | `organizations.switch` の **1 本だけ**（他の route 名は維持される） |

### 母集団と 4 分類（排他）

母集団は **git 追跡下ファイル全数**。次の **4 つ**へ排他的に分類し、
**どれにも分類していない置き場所・形式が現れたら赤**にする。

| 分類 | 対象 |
|---|---|
| **走査する** | PHP 全層（`route()` 呼び出しと URL 直書き）/ `resources/js/` / `resources/views/` / `tests/`（Feature / Browser / `tests/js/`）/ `docs/` / `doc/` / ルート直下の `README*` / `public/*.webmanifest` / `public/*.js` / `.claude/skills/app-bug-hunt/inventory/` / 生成テンプレート |
| **走査しない（理由付き）** | バイナリ / `public/build`（生成物）/ `vendor` / `node_modules` / `devnotes`（設計の記録であり実行されない。**本設計ディレクトリの旧 URL 記述は履歴として残す**） |
| **自己検査専用（名指し + 件数）** | 負例 fixture と抽出器の自己テスト。**検出したい語をわざと持つ**のが役目なので rule ID では表せない。`LegacyUrlSelfCheckPopulationTest` がファイル名と検出語の一致件数を**完全一致で pin** する（増えても減っても赤） |
| **未分類** | **1 件でも現れたら赤** |

### URL の抽出と判定（reviewer Critical 2 件）

**(1) 抽出はファイル種別ごとに行う。単一の区切り集合では足りない。**
走査対象には Markdown 文書が入っており、`/dashboard)` `/projects,` `/billing。` のような
**閉じ記号・句読点・空白**で終わる記述を、6 文字の区切り集合では拾えない。

| ファイル種別 | 抽出方法 |
|---|---|
| PHP / TypeScript / Svelte | **文字列リテラル**を構文で抽出し、その中身を URL として扱う（`route()` の第 1 引数は route 名なので別台帳） |
| Blade / HTML | 属性値（`href` / `action` / `src`）と文字列リテラル |
| Markdown（`docs/` `doc/` `README*`） | **Markdown リンクの宛先** + **プレーン URL**。終端は **空白文字全般**（半角空白・タブ・改行・全角空白）と、閉じ記号・句読点の**列挙した集合**: `)` `]` `}` `>` `"` `'` `` ` `` `,` `;` `:` `。` `、` `）` `｜` `|`。**この列挙が保証範囲であり、ここに無い終端は保証しない**（docblock に明記し、網羅を主張しない） |
| JSON / webmanifest | 値の文字列 |

区切り集合は**種別ごとに宣言**し、docblock に書く（走査器共通規約 (e)）。
**種別が増えたら未分類として赤**になる（施策 10 の 4 分類と同じ形）。

**(2) 旧パスの判定は「正規化済み path の root 一致」で行う。**
`/projects/` の前方一致だけでは、root の **`/projects`（末尾スラッシュなし）を拾えない**。

> query（`?`）と hash（`#`）を落として正規化した path が、
> **root と完全一致するか、`root/` で始まる**ときに旧パスと判定する。

`/app` は「path が `/app` と完全一致 or `/app/` で始まる」かつ
「`/organizations/{slug}/app…` ではない」ときだけ旧扱いにする。

**(3) 正例群と負例群を分ける。**
「検出すべき旧 URL の変形」と「誤検出してはいけない新 URL」は別の群である
（`/organizations/acme/app` は接尾辞つきの変形ではなく**許可すべき新 URL** であり、
走査器共通規約 (e) の 3 形とは別の話）。

| 群 | 例 |
|---|---|
| **検出すべき（旧 URL）** | `/projects` / `/projects/` / `/projects/12` / `/dashboard)` / `/billing。` / `"/app"` / `/onboarding/checkout` |
| **誤検出してはいけない（新 URL・無関係）** | `/organizations/acme/projects/12` / `/organizations/acme/app` / `/myapp` / `/app-old` / `/projectsomething` |
| **規約 (e) の 3 形（語彙一致の負例）** | 接頭辞つき `/myapp` / 打ち消しつき `/app-old` / 接尾辞つき `/appx` |

### 文書の例外化（reviewer Warning）

旧 URL を含む文書を例外にするときは、**ファイル数だけを pin しない**。
**ファイルごとの一致件数と対象パターンを完全一致で pin** する（増減のどちらでも赤）。

### 正規入口 `/app` の許可目録（**旧 URL 検出と区別する**）

`/app` は**旧 capture URL の接頭辞**であると同時に、**今後も残す正規の分岐入口**でもある。
「分岐入口以外」という条件には機械的な分類方法が無いので、
**正規入口としての `/app` の出現だけを exact-fit の許可目録で名指しする**。
それ以外の裸の `/app` は旧 URL として検出する。

```
tests/Support/Architecture/CaptureEntryUrlAllowance.php
  - 形式: パス + 構文の種別 (rule ID) + **件数** + 30 文字以上の理由
  - 件数は完全一致 (増えても減っても赤)
  - **ファイル全体を許可しない** — 登録した箇所以外の `/app` は同じファイルの中でも検出する
```

**rule ID は構文文脈まで識別する安定 ID にする**（単に `legacy-path` にしない）。
同じファイルの中で別の裸の `/app` と置き換わっても、件数だけでは通らない形にするためである。

登録する対象（**実装時に実数で確定する**。下表は設計時点の想定）:

| パス | rule ID | 何を許すか | 理由（要旨） |
|---|---|---|---|
| `public/manifest.webmanifest` | `manifest-start-url` | `start_url` の値 `/app` | PWA のホーム画面追加の入口。組織を持たない分岐入口であることが仕様 |
| `routes/web.php` | `capture-entry-route-definition` | `GET /app`（`capture.entry`）の route 定義 | 正規の分岐入口そのものの宣言 |

- **`route('capture.entry')` の呼び出しは許可目録に載せない**。抽出器が見るのは
  **URL 文字列**であって route 名ではないので、そもそも検出結果を発行しない。
  許可目録は**実際に検出される正規入口の出現だけ**に exact-fit させる
  （検出されないものを載せると、目録が「何を守っているか」を曖昧にする）。
  入口への導線は **route helper 経由だけを許す**（URL 直書きは旧 URL として検出される）。
- `public/capture-sw.js` に navigation fallback として `/app` を書く記述は**実測で存在しない**。
  **該当が無いので登録しない**（0 件の空登録は目録を膨らませるだけで、
  「登録が無い = 検出対象」という既定のほうが明快である）。

> **`/organizations/{slug}/app/...`（新 capture URL）は許可目録に載せない** — 旧パス判定が
> 「path が `/app` と完全一致 or `/app/` で始まる」なので、そもそも一致しない。

### 自己検出の閉じ方（**再帰しないメタデータ分類にする**）

本 gate 自身・負例 fixture・**例外目録**・**抽出器の自己テスト**には、
検出語（`/projects` / `/dashboard` / `organizations.switch` 等）が**必ず現れる**。
ここで「旧 URL 文字列を持つ例外目録」を作ると、**その目録自身が検出対象になり再帰する**。

**2 段で閉じる**（どちらも「ファイルを黙って走査対象外にする」ことはしない）:

**(1) 目録は旧 URL 文字列を複写しない。**
自己検出の例外は **検出器が発行する安定した rule ID + パス + 件数 + 理由** で表す。

```
tests/Support/Architecture/LegacyUrlSelfDetectionExemptions.php
  - 形式: パス + rule ID (例: `legacy-path` / `removed-route-name`) + **件数** + 30 文字以上の理由
  - ★旧 URL 文字列そのものを持たない (持つと自分が検出対象になる)
  - 件数は完全一致 / 登録した rule ID 以外はそのファイルの中でも検出する
```

**(2) 語彙を持たざるを得ないファイルは「自己検査専用」として名指し分類する。**
負例 fixture と抽出器の自己テストは、**検出したい語をわざと持つ**のが役目なので
rule ID では表せない。この 2 種類だけを**理由付きのメタデータ分類**へ入れ、
**別の gate がファイル名と一致件数を完全一致で固定する**。

```
tests/Support/Architecture/LegacyUrlScanRoots.php
  分類は 4 つ (排他):
    走査する / 走査しない (理由付き) / **自己検査専用 (名指し + 件数)** / 未分類 (1 件でも赤)
tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
  - 「自己検査専用」に入るファイル名と、その中の検出語の一致件数を完全一致で pin する
  - 増えても減っても赤 (fixture を黙って増やせない)
  - この gate 自身は旧 URL 文字列を持たない (件数とパスだけを持つ)
```

### 実装順序（reviewer Critical）

本 gate は**単位 B 完了後の「旧 URL ゼロ」を検査する**ものなので、
**単位 B より前に green で導入することはできない**。

> 抽出器だけ先に書いてもよいが、**main へ merge する単位は単位 B の後**である。
> 実装モードの進行順序でも、施策 10 を単位 B の後に置く。

### 保証外（誇張しない）

- 利用者のブックマーク・外部サービスに登録済みの URL
- デプロイ時点で queue に積まれている / 送信済みのメール本文
- ブラウザの履歴・bfcache・開いたままの旧画面（次の遷移で 404 になる）

### PHPStan適合チェック

- [x] 分類台帳は `array<string, LegacyUrlScanClass>`（enum）
- [x] 未分類は例外で落とす（`null` を返して黙らない）

### テスト計画

- [ ] 新規 `tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php` —
      旧パス 0 件 / 撤去 route 名 0 件 / **未分類の置き場所・形式 0 件** / 母集団が空なら fail
- [ ] 新規 `tests/Architecture/LegacyUrlSelfCheckPopulationTest.php` —
      「自己検査専用」分類のファイル名と検出語の一致件数を完全一致で pin
- [ ] **ファイル種別ごとの検出力**（各 1 つ以上の正例・負例）:

      | 種別 | 正例（検出すべき） | 負例（誤検出してはいけない） |
      |---|---|---|
      | PHP 文字列リテラル | `redirect('/projects')` | `redirect(route('projects.index', [...]))` |
      | TypeScript / Svelte 文字列 | `href="/billing"` | `href={`/organizations/${slug}/billing`}` |
      | Blade / HTML 属性 | `<a href="/dashboard">` | `<a href="{{ route('dashboard', [...]) }}">` |
      | Markdown リンク | `[課金](/billing)` | `[課金](/organizations/acme/billing)` |
      | Markdown プレーン URL | `… /dashboard) を開く` / `… /projects、` | `… /organizations/acme/projects/12` |
      | JSON / webmanifest 値 | `"start_url": "/projects"` | `"start_url": "/app"`（許可目録に登録済み） |

- [ ] **未分類のファイル種別が現れたら fail-closed**（新しい拡張子を足した合成入力で赤になること）
- [ ] 規約 (e) の 3 形（接頭辞 `/myapp` / 打ち消し `/app-old` / 接尾辞 `/appx`）を誤検出しない
- [ ] **許可目録に無い裸の `/app` を検出する**（正規入口の許可が効きすぎていないこと）

---

## 施策 11: 乖離台帳の更新

## 3. 実装時に確定した事項 (設計からの逸脱の記録)

# 実装時に確定した事項 (T247)

詳細設計からの**確定変更**と、設計時に決めていなかった判断を記録する。
設計書 (`detailed-design.md`) は変更せず、差分をここに集約する。

## 1. 施策 10 の走査方式を「ファイル種別ごとの抽出器」から「2 つの抽出方式 + 割り当て表」へ

設計は種別ごとに抽出方法を書き分ける表を持っていたが、母集団が git 追跡下**全ファイル**である以上、
種別は増え続ける。抽出方式を **2 つだけ** (`LegacyUrlExtractionMode`) に固定し、
**どの拡張子をどちらへ割り当てるか**を `LegacyUrlScanRoots::SCANNED_EXTENSIONS` が宣言する形にした。
割り当ての無い拡張子は**未分類 = 赤**なので、種別が増えたときの fail-closed は保たれる。

- `SourceLiteral` (PHP / TypeScript / JavaScript / Svelte / Python): **文字列リテラルだけ**を見る。
  コメントの言及は参照ではない (撤去を説明する docblock を違反にしない)。
- `PlainText` (Markdown / JSON / TOML / YAML / TSV / テキスト / Blade): 全文を見る。終端集合を宣言する。

抽出は `Tests\Support\SourceLiterals` に集約し、`CurrentOrganizationRemovalScanner` の
列名検出も同じ入口を使うようにした (コメントの言及で赤くなっていた実測を解消)。

## 2. `/app` は「配下つきのときだけ旧 URL」— 許可目録を使わない

設計は「分岐入口以外の `/app`」を許可目録で名指しする想定だったが、
**裸の `/app` は正規の分岐入口**であり、旧 URL なのは配下を持つ形 (`/app/projects/…`) だけである。
規則で表せるものを目録にすると、目録が旧 URL 文字列を持つことになり再帰する。
そこで `LegacyUrlScanner::captureRoot()` だけ「配下つき」を要求する規則にした。
結果として PWA の `start_url` / robots の宣言 / 入口の Feature テストは**登録なしで通る**。

## 3. 許可目録の区分を 3 つに限定した

`LegacyUrlAllowanceKind` は `FilesystemPath` / `AbsenceAssertion` / `OrganizationRelativePath` の 3 つ。
「なんとなく直せない」を入れる口を作らないため限定列挙にし、
区分を足す操作そのものがレビューに見えるようにした。登録は**パス + 規則 ID + 件数 + 30 文字以上の理由**で、
件数は増減のどちらでも赤になる。

## 4. `routes/` を走査対象から外した (理由付き)

route 定義の URI は group の prefix からの**相対セグメント**であり、組織 prefix の中では
根だけの記述が正しい姿になる。実 route 表が 1 本残らず組織 URL 配下にあることは
`OrganizationScopedRouteCoverageTest` が**解決済みの route 表**で固定するので、
ここを字面で走査しても新しい保証は増えない。

## 5. 撤去 route 名の検出を `LegacyOrganizationlessUrlAbsenceTest` へ一本化した

`CurrentOrganizationRemovalTest` の「検出 4 (撤去した route 名)」を撤去し、
追跡下ファイル**全数**を母集団に持つ施策 10 側へ移した (同じ事実を 2 か所で検査しない)。
`CurrentOrganizationRemovalTest` は 3 形 (列名リテラル / relation / 撤去した Service の FQCN) に絞り、
列名検出は `database/migrations/` を母集団から外した (撤去した列の名前は移行履歴に必ず残るため)。

## 6. 流量制限 `render-trigger` のキーを識別名の文字列にした

`ThrottleRequests` は framework の既定 priority で `SubstituteBindings` **より前**に走るため、
limiter からは組織モデルが束縛されていない。束縛の後ろへ動かすと束縛の DB 参照が
流量制限の外に出るので、**route parameter の識別名の文字列**をキーに使う。
改名は 30 日 5 回が上限で窓は 1 分なので、改名の前後で bucket が分かれても上限の意味は保たれる。

## 7. 課金ゲートの defense-in-depth を「所属」で判定するようにした

`RequireActiveSubscription::resolveOrganization()` は binder の回帰検出に
`Gate::allows('view')` を使っていたが、**所属はあるが役割が無い**利用者 (並行受諾レースの帰結) の
403 が 404 に化けて層 2 と層 3 の境目が消えていた。判定を **binder と同じ契約 (organization_user の所属)** に
揃え、役割不在は従来どおり controller / policy の 403 で成立させる。

## 8. 認証済みで guest 専用 route を開いたときの着地を分岐入口へ固定した

framework の既定は「`dashboard` という名前の route があればそこへ」だが、本アプリの `dashboard` は
組織 URL 配下 (`{organization}` 必須) なので既定のままだと引数不足で 500 になる。
`RedirectIfAuthenticated::redirectUsing()` で**組織文脈を持たない分岐入口** (`app.entry`) へ倒した。

## 9. 「切り替えてから解約」の一手を概念ごと撤去した

`AccountDeletionBlockerAction::SwitchOrganizationThenOpenBilling` は保持列と切替 endpoint の撤去に伴って
意味を失った (どの組織の課金画面へも URL で直接行ける)。enum の case と DTO の
`isCurrentOrganization` 引数を撤去し、テストの期待値を `OpenBilling` 1 手へ揃えた。

## 10. 乖離台帳の採番を繰り上げた

main が先に D40 (T250) / D41 (T245) / D42 (T244) を使っていたため、本ブランチの 2 件を
**D43 / D44** へ繰り上げた。件数 pin は実ファイルの実数から数え直して
`DIVERGENCE_ENTRY_COUNT = 41` / `ADOPTION_DEBT_COUNT = 154`。
`docs/app-integration-guide.md` は main の D42 が既に登録しているため D44 の対象パスからは外し、
代わりに `docs/default-team-pattern.md` と `tests/Architecture/AccountDeletionPathGateTest.php` を
D44 へ足して採用時債務から削った (3 択の (3))。

## 4. 実装差分 (施策 10 の新規ファイル)

```diff
diff --git a/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php b/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
new file mode 100644
index 00000000..3b4b08e4
--- /dev/null
+++ b/tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php
@@ -0,0 +1,195 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\LegacyUrl\LegacyUrlAllowance;
+use Tests\Support\LegacyUrl\LegacyUrlExtractionMode;
+use Tests\Support\LegacyUrl\LegacyUrlScannedFile;
+use Tests\Support\LegacyUrl\LegacyUrlScanner;
+use Tests\Support\LegacyUrl\LegacyUrlScanRoots;
+
+/*
+ * 組織を持たない**旧 URL** と**撤去した route 名**がリポジトリに 1 件も残っていない
+ * (家系裁定 AG-037 / 施策 10)。
+ *
+ * ## なぜ必要か
+ *
+ * 単位 B で業務 route を `/organizations/{organization:slug}/…` 配下へ移し、
+ * **旧 URL からの転送を置かない**判断をした (思考原則 3: 後方互換の並走を残さない)。
+ * したがってリポジトリ内に旧 URL が 1 つでも残っていれば、それは**壊れた導線**である
+ * (画面のリンクなら 404、文書なら誤った案内)。
+ *
+ * ## 台帳は 2 つ (同じ台帳にしない)
+ *
+ * route 名は施策 5 で**維持**したので、検出対象は「URL 文字列」と「撤去 route 名」に分かれる。
+ * 前者は `LegacyUrlScanner::legacyRoots()`、後者は `LegacyUrlScanner::removedRouteName()` が正本。
+ *
+ * ## 母集団と 4 分類
+ *
+ * 母集団は git 追跡下ファイル**全数**で、`LegacyUrlScanRoots` が
+ * 「走査する / 走査しない (理由付き) / 自己検査専用 / 未分類」へ**排他的に**分ける。
+ * **未分類が 1 件でもあれば赤**である (新しい置き場所・拡張子が黙って走査から外れない)。
+ *
+ * ## 自己検出の閉じ方
+ *
+ * 本 gate も走査器も**旧 URL 文字列を持たない** (断片を連結して組み立てる)。
+ * 検出語をわざと持つ見本だけが「自己検査専用」へ名指しで入り、
+ * その件数は `LegacyUrlSelfCheckPopulationTest` が完全一致で pin する。
+ *
+ * ## 保証しないもの
+ *
+ * 走査器 (`LegacyUrlScanner`) と母集団 (`LegacyUrlScanRoots`) の docblock が正本である。
+ * リポジトリの外 (利用者のブックマーク・送信済みメール・ブラウザ履歴) は対象外である。
+ */
+
+/** 走査対象の抽出方式が 5 規則とも生きている (走査根が壊れても気付ける)。 */
+test('母集団は空でなく、規則ごとに 1 件以上のファイルがある', function (): void {
+    $population = LegacyUrlScanRoots::population();
+
+    expect($population->scanned)->not->toBeEmpty();
+
+    $counts = $population->scannedCountByRule();
+    foreach ([
+        LegacyUrlScanner::RULE_PHP_LITERAL,
+        LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+        LegacyUrlScanner::RULE_BLADE_TEXT,
+        LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+        LegacyUrlScanner::RULE_DATA_TEXT,
+    ] as $rule) {
+        expect($counts[$rule] ?? 0)->toBeGreaterThan(0, "規則 {$rule} の母集団が空です");
+    }
+});
+
+test('未分類の置き場所・形式は 0 件 (分類漏れは赤)', function (): void {
+    expect(LegacyUrlScanRoots::population()->unclassified)->toBe([]);
+});
+
+test('解決できないファイルは 0 件 (読めないまま黙って落とさない)', function (): void {
+    expect(LegacyUrlScanRoots::population()->unresolved)->toBe([]);
+});
+
+test('走査しない分類と許可目録の理由はいずれも 30 文字以上', function (): void {
+    $short = [];
+    foreach (LegacyUrlScanRoots::notScannedPathReasons() as $path => $reason) {
+        if (mb_strlen($reason) < 30) {
+            $short[] = "not-scanned path {$path}";
+        }
+    }
+    foreach (LegacyUrlScanRoots::notScannedExtensionReasons() as $extension => $reason) {
+        if (mb_strlen($reason) < 30) {
+            $short[] = "not-scanned extension {$extension}";
+        }
+    }
+    foreach (LegacyUrlScanRoots::selfCheckOnlyReasons() as $path => $reason) {
+        if (mb_strlen($reason) < 30) {
+            $short[] = "self-check-only {$path}";
+        }
+    }
+    foreach (LegacyUrlAllowance::entries() as $entry) {
+        if (mb_strlen($entry['reason']) < 30) {
+            $short[] = "allowance {$entry['path']}";
+        }
+    }
+
+    expect($short)->toBe([]);
+});
+
+test('旧 URL と撤去 route 名は許可目録に登録したものを除いて 0 件', function (): void {
+    $allowed = LegacyUrlAllowance::counts();
+    $observed = [];
+    $violations = [];
+
+    foreach (LegacyUrlScanRoots::population()->scanned as $file) {
+        foreach (LegacyUrlScanner::scanFile($file) as $occurrence) {
+            $key = $occurrence->relative."\0".$occurrence->ruleId;
+            $observed[$key] = ($observed[$key] ?? 0) + 1;
+            if (! array_key_exists($key, $allowed)) {
+                $violations[] = $occurrence->describe();
+            }
+        }
+    }
+
+    sort($violations);
+    expect($violations)->toBe([]);
+
+    // ★件数は完全一致 (増えても減っても赤 / 登録が実在しなくなっても赤)
+    $mismatched = [];
+    foreach ($allowed as $key => $count) {
+        [$path, $rule] = explode("\0", $key);
+        $actual = $observed[$key] ?? 0;
+        if ($actual !== $count) {
+            $mismatched[] = "{$path} [{$rule}] 登録 {$count} 件 / 実測 {$actual} 件";
+        }
+    }
+    expect($mismatched)->toBe([]);
+});
+
+test('負例: 見本の旧 URL を検出できる (検出力の裏取り)', function (): void {
+    $base = base_path('tests/Architecture/fixtures/legacy-url/');
+
+    $markdown = new LegacyUrlScannedFile(
+        relative: 'fixture.md',
+        contents: (string) file_get_contents($base.'legacy-paths.md'),
+        mode: LegacyUrlExtractionMode::PlainText,
+        ruleId: LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+    );
+    $php = new LegacyUrlScannedFile(
+        relative: 'fixture.php',
+        contents: (string) file_get_contents($base.'legacy-php-source.txt'),
+        mode: LegacyUrlExtractionMode::SourceLiteral,
+        ruleId: LegacyUrlScanner::RULE_PHP_LITERAL,
+    );
+    $script = new LegacyUrlScannedFile(
+        relative: 'fixture.ts',
+        contents: (string) file_get_contents($base.'legacy-script-source.txt'),
+        mode: LegacyUrlExtractionMode::SourceLiteral,
+        ruleId: LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+    );
+
+    // Markdown: 旧パス 11 件 + 撤去 route 名 1 件
+    expect(LegacyUrlScanner::scanFile($markdown))->toHaveCount(12);
+    // PHP: リテラルの旧パス 2 件 (コメントは数えない) + 撤去 route 名 1 件
+    expect(LegacyUrlScanner::scanFile($php))->toHaveCount(3);
+    // script: リテラルの旧パス 1 件 (コメント / 組織 URL 組み立ての入口 / 組織 prefix は数えない)
+    expect(LegacyUrlScanner::scanFile($script))->toHaveCount(1);
+});
+
+test('負例: 新 URL と紛らわしい語を誤検出しない (接頭辞・打ち消し・接尾辞の 3 形を含む)', function (): void {
+    $allowed = new LegacyUrlScannedFile(
+        relative: 'fixture.md',
+        contents: (string) file_get_contents(base_path('tests/Architecture/fixtures/legacy-url/allowed-paths.md')),
+        mode: LegacyUrlExtractionMode::PlainText,
+        ruleId: LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+    );
+
+    expect(array_map(
+        static fn (object $occurrence): string => (string) $occurrence->describe(),
+        LegacyUrlScanner::scanFile($allowed),
+    ))->toBe([]);
+});
+
+test('種別ごとの割り当て: 拡張子は宣言した抽出方式と規則 ID へ 1:1 で写る', function (): void {
+    // ★「どの種別をどう抽出するか」は分類表が唯一の正本である。ここが壊れると
+    //   Blade / JSON / TOML が黙って別の方式で読まれ、検出力の裏取りが意味を失う。
+    $expected = [
+        'resources/views/app.blade.php' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_BLADE_TEXT],
+        'app/Models/User.php' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_PHP_LITERAL],
+        'resources/js/lib/org-url.ts' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'resources/js/pages/Dashboard.svelte' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'docs/architecture.md' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT],
+        'public/manifest.webmanifest' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT],
+        '.claude/skills/app-bug-hunt/inventory/annotations.toml' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT],
+    ];
+
+    foreach ($expected as $path => [$mode, $rule]) {
+        $classification = LegacyUrlScanRoots::classify($path);
+        expect($classification)->not->toBeNull("{$path} が未分類です");
+        expect($classification['mode'])->toBe($mode, "{$path} の抽出方式が宣言と違います");
+        expect($classification['rule'])->toBe($rule, "{$path} の規則 ID が宣言と違います");
+    }
+});
+
+test('負例: 未知の拡張子は未分類として落ちる (fail-closed)', function (): void {
+    expect(LegacyUrlScanRoots::classify('resources/js/app.unknownext'))->toBeNull();
+    expect(LegacyUrlScanRoots::classify('app/Models/User.php'))->not->toBeNull();
+});
diff --git a/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php b/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
new file mode 100644
index 00000000..2b9801b0
--- /dev/null
+++ b/tests/Architecture/LegacyUrlSelfCheckPopulationTest.php
@@ -0,0 +1,75 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\LegacyUrl\LegacyUrlScanner;
+use Tests\Support\LegacyUrl\LegacyUrlScanRoots;
+
+/*
+ * 「自己検査専用」分類のファイル名と検出語の一致件数を**完全一致で pin** する
+ * (家系裁定 AG-037 / 施策 10)。
+ *
+ * ## なぜ別の gate なのか
+ *
+ * 旧 URL の残存検査は、検出語をわざと持つ見本 (負例 fixture) を母集団から外さざるを得ない。
+ * 外しっぱなしにすると**そこへ書けば何でも通る**抜け道になるので、
+ * 「どのファイルが、何件の検出語を持つか」を別の検査が固定する。
+ * 増えても減っても赤になるので、見本を黙って増やすことも、
+ * 実装のついでに旧 URL を見本ファイルへ退避することもできない。
+ *
+ * ## この gate 自身は旧 URL 文字列を持たない
+ *
+ * 持つのは**パスと件数**だけである (旧 URL を書くと、この gate 自身が検出対象になる)。
+ */
+
+/** 自己検査専用のファイル名 (完全一致)。 */
+const LEGACY_URL_SELF_CHECK_FILES = [
+    'tests/Architecture/fixtures/legacy-url/allowed-paths.md',
+    'tests/Architecture/fixtures/legacy-url/legacy-paths.md',
+    'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt',
+    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt',
+];
+
+/**
+ * 各見本が持つ検出語の件数 (完全一致)。
+ *
+ * ★件数は**全文走査**で数える (見本の中身がどの言語かにかかわらず同じ数え方をする)。
+ *   ソースの見本はコメントにも検出語を置いてあるので、リテラルだけを見る本体の数え方とは
+ *   一致しない。ここで数えたいのは「見本が検出語を何個持っているか」である。
+ */
+const LEGACY_URL_SELF_CHECK_COUNTS = [
+    'tests/Architecture/fixtures/legacy-url/allowed-paths.md' => 0,
+    'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => 12,
+    'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 5,
+    'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 5,
+];
+
+test('自己検査専用の分類は目録と完全一致する', function (): void {
+    $classified = array_map(
+        static fn (object $file): string => (string) $file->relative,
+        LegacyUrlScanRoots::population()->selfCheckOnly,
+    );
+    sort($classified);
+
+    expect($classified)->toBe(LEGACY_URL_SELF_CHECK_FILES);
+});
+
+test('自己検査専用の見本が持つ検出語の件数は完全一致 (増減のどちらでも赤)', function (): void {
+    $counts = [];
+    foreach (LegacyUrlScanRoots::population()->selfCheckOnly as $file) {
+        $hits = 0;
+        foreach (explode("\n", $file->contents) as $line) {
+            $hits += count(LegacyUrlScanner::matchesIn($line));
+            if (str_contains($line, LegacyUrlScanner::removedRouteName())) {
+                $hits++;
+            }
+        }
+        $counts[$file->relative] = $hits;
+    }
+    ksort($counts);
+
+    $expected = LEGACY_URL_SELF_CHECK_COUNTS;
+    ksort($expected);
+
+    expect($counts)->toBe($expected);
+});
diff --git a/tests/Architecture/OrganizationRouteHandlerParameterTest.php b/tests/Architecture/OrganizationRouteHandlerParameterTest.php
new file mode 100644
index 00000000..6038e6e4
--- /dev/null
+++ b/tests/Architecture/OrganizationRouteHandlerParameterTest.php
@@ -0,0 +1,108 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * `{organization}` を持つ route の handler は **organization 引数を受ける** (家系裁定 AG-037)。
+ *
+ * ## なぜ必要か (実測事故)
+ *
+ * framework は route parameter を **位置で** handler の引数へ割り当てる
+ * (`RouteDependencyResolverTrait::resolveMethodDependencies` はクラス型を差し込んだ後、
+ *  残りを `array_values($routeParameters)` から順に埋める)。したがって組織 URL 配下の
+ * handler が `{organization}` を受けないと、**後続の引数が 1 つずつずれる**。
+ *
+ * 実測では通知の既読化 (`notifications.read`) が `string $notification` に Organization を
+ * 受け取り、通知が見つからず 404 になっていた。**型が合わないのに例外にならない**
+ * (Organization は `__toString()` を持たないが、そのまま検索値として渡ってしまう) ため、
+ * 失敗は「なぜか 404」という形でしか現れない。
+ *
+ * ## 判定
+ *
+ * `{organization}` を持つ route の handler に `organization` という名前の引数があること。
+ * **使うかどうかは問わない** — 位置ずれを防ぐことが目的なので、受けていれば足りる。
+ *
+ * ## 保証しないもの
+ *
+ * - handler が closure の route は対象外である (`app/` の外に本体があり、位置の契約も違う)。
+ * - `{organization}` 以外の parameter の順序ずれは見ない (この検査は組織セグメントの導入で
+ *   全業務 route が 1 つずれたことへの回帰固定である)。
+ * - 引数の**型**は見ない (binding が Organization を返すことは binder 側の契約)。
+ */
+
+test('{organization} を持つ route の handler はすべて organization 引数を受ける', function (): void {
+    $routes = Route::getRoutes();
+    $routes->refreshNameLookups();
+
+    $population = 0;
+    $violations = [];
+
+    /** @var RoutingRoute $route */
+    foreach ($routes as $route) {
+        if (! in_array('organization', $route->parameterNames(), true)) {
+            continue;
+        }
+
+        $action = $route->getActionName();
+        if ($action === 'Closure') {
+            continue;
+        }
+
+        [$class, $method] = str_contains($action, '@')
+            ? explode('@', $action, 2)
+            : [$action, '__invoke'];
+
+        if (! class_exists($class) || ! method_exists($class, $method)) {
+            // 解決できない形は落とす (fail-closed)
+            $violations[] = ($route->getName() ?? $route->uri()).' -> 解決できない handler: '.$action;
+
+            continue;
+        }
+
+        $population++;
+        $names = array_map(
+            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
+            (new ReflectionMethod($class, $method))->getParameters(),
+        );
+
+        if (! in_array('organization', $names, true)) {
+            $violations[] = ($route->getName() ?? $route->uri())
+                ." -> {$class}::{$method}(".implode(', ', $names).')';
+        }
+    }
+
+    // 母集団が空なら走査が壊れている (組織 route は多数あるのが前提)
+    expect($population)->toBeGreaterThan(20);
+
+    sort($violations);
+    expect($violations)->toBe([]);
+});
+
+test('負例: organization 引数を持たない合成 handler を検出できる', function (): void {
+    $withOrganization = new class
+    {
+        public function show(Organization $organization, string $notification): string
+        {
+            return $organization->slug.$notification;
+        }
+    };
+    $withoutOrganization = new class
+    {
+        public function show(string $notification): string
+        {
+            return $notification;
+        }
+    };
+
+    $names = static fn (object $handler): array => array_map(
+        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
+        (new ReflectionMethod($handler, 'show'))->getParameters(),
+    );
+
+    expect(in_array('organization', $names($withOrganization), true))->toBeTrue();
+    expect(in_array('organization', $names($withoutOrganization), true))->toBeFalse();
+});
diff --git a/tests/Architecture/fixtures/legacy-url/allowed-paths.md b/tests/Architecture/fixtures/legacy-url/allowed-paths.md
new file mode 100644
index 00000000..9f0aa879
--- /dev/null
+++ b/tests/Architecture/fixtures/legacy-url/allowed-paths.md
@@ -0,0 +1,15 @@
+# 誤検出してはいけない見本 (負例)
+
+この見本は**1 件も検出されてはならない**記述だけを持つ。
+
+- 新 URL: /organizations/acme/projects/12
+- route 表の写し: organizations/{organization}/dashboard
+- テンプレートリテラル: /organizations/${slug}/billing
+- 山括弧の置換子: /organizations/<slug>/manage/users
+- 根の下の第 2 セグメント: /organizations/acme/billing/purchase-tickets
+- 正規の分岐入口: /app
+- 接頭辞つき: /myapp
+- 打ち消しつき: /app-old
+- 接尾辞つき: /appx
+- 別語: /projectsomething
+- 外部サービスの絶対 URL: https://app.example.com/dashboard
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-paths.md b/tests/Architecture/fixtures/legacy-url/legacy-paths.md
new file mode 100644
index 00000000..4e2760c5
--- /dev/null
+++ b/tests/Architecture/fixtures/legacy-url/legacy-paths.md
@@ -0,0 +1,17 @@
+# 旧 URL の見本 (正例)
+
+この見本は**検出されなければならない**記述だけを持つ。`LegacyUrlScanRoots` の
+「自己検査専用」分類に入っており、旧 URL の残存検査の母集団からは外れている。
+
+- 一覧 (/projects) を開く
+- ダッシュボードは /dashboard
+- 末尾スラッシュだけの /projects/
+- 識別子つきの /projects/12 を開く
+- [課金](/billing)
+- 通知は /notifications。
+- 手続きは /onboarding/checkout
+- チケットは /purchase-tickets、
+- 遮断の着地は /billing-required)
+- 管理は /manage/users|
+- 撮影 PWA の配下は /app/projects/1/manuals
+- 撤去した route 名は organizations.switch である
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-php-source.txt b/tests/Architecture/fixtures/legacy-url/legacy-php-source.txt
new file mode 100644
index 00000000..21b5e38d
--- /dev/null
+++ b/tests/Architecture/fixtures/legacy-url/legacy-php-source.txt
@@ -0,0 +1,8 @@
+<?php
+
+// コメントの /dashboard は参照ではない
+/** docblock の /projects も参照ではない */
+$a = '/dashboard';
+$b = "/organizations/{$slug}/projects/{$id}";
+$c = dirname(__DIR__).'/app/Prompts';
+$d = route('organizations.switch');
diff --git a/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt b/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
new file mode 100644
index 00000000..bd4303a9
--- /dev/null
+++ b/tests/Architecture/fixtures/legacy-url/legacy-script-source.txt
@@ -0,0 +1,7 @@
+// コメントの /dashboard は参照ではない
+/* ブロックコメントの /projects も参照ではない */
+const a = "/billing";
+const b = orgUrl(slug, "/projects");
+const c = currentOrgUrl(`/manage/users`);
+const d = `/organizations/${slug}/notifications`;
+const e = "https://example.com/dashboard";
diff --git a/tests/Support/LegacyUrl/LegacyUrlAllowance.php b/tests/Support/LegacyUrl/LegacyUrlAllowance.php
new file mode 100644
index 00000000..4b1fc45d
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlAllowance.php
@@ -0,0 +1,119 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+/**
+ * 旧 URL 検出の許可目録 (deny-by-default。**件数まで完全一致**)。
+ *
+ * ★形式は **パス + 検出規則 ID + 区分 + 件数 + 30 文字以上の理由**である。
+ * ★**旧 URL の文字列そのものを目録へ写さない**。写すと目録自身が検出対象になり
+ *   「自分を許可するための登録」という再帰が始まる。目録が持つのは
+ *   「どの規則の、どのファイルの、何件を許すか」だけである。
+ * ★**ファイル全体を走査から外さない**。登録した規則 ID 以外の検出は、
+ *   同じファイルの中でも引き続き違反になる。
+ * ★件数は完全一致である (増えても減っても赤)。減ったときも赤にするのは、
+ *   許可の理由が消えたのに登録が残る状態を作らないためである。
+ */
+final class LegacyUrlAllowance
+{
+    /** インスタンス化しない (目録の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 登録一覧。
+     *
+     * @return list<array{path: string, rule: string, kind: LegacyUrlAllowanceKind, count: int, reason: string}>
+     */
+    public static function entries(): array
+    {
+        return [
+            [
+                'path' => 'tests/Architecture/PromptDefenseWindowGateTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'count' => 2,
+                'reason' => 'prompt factory の走査根をリポジトリルートからの相対ディレクトリとして組み立てている箇所であり、URL ではなくファイルシステムのパスである',
+            ],
+            [
+                'path' => '.claude/skills/app-bug-hunt/coverage/correlate.py',
+                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'count' => 1,
+                'reason' => 'スタックトレースの絶対パスをリポジトリ相対へ畳む処理の説明文であり、'
+                    .'指しているのは app/ ディレクトリのファイルパスで、画面の URL ではない。',
+            ],
+            [
+                'path' => '.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py',
+                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'count' => 1,
+                'reason' => '対象外判定の見本に置いた管理画面の実装ディレクトリのパスであり、'
+                    .'ファイルシステム上の位置を指す文字列で画面の URL ではない。',
+            ],
+            [
+                'path' => 'docs/architecture.md',
+                'rule' => LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME,
+                'kind' => LegacyUrlAllowanceKind::AbsenceAssertion,
+                'count' => 1,
+                'reason' => '撤去した切替 endpoint の route 名を「撤去済みである」と説明する 1 行であり、'
+                    .'撤去の記録としてこの名前を書けないと、何を撤去したのかが文書から読めなくなる。',
+            ],
+            [
+                'path' => 'doc/08_システムアーキテクチャ設計.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'count' => 1,
+                'reason' => 'オブジェクトストレージのキー prefix の書式であり、画面の URL ではない。'
+                    .'鍵は組織 id で始まる別の体系で、URL の組織セグメントとは無関係である。',
+            ],
+            [
+                'path' => 'doc/09_詳細実装設計.md',
+                'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'count' => 1,
+                'reason' => 'オブジェクトストレージに置くテイク動画の鍵の書式であり、画面の URL ではない。'
+                    .'鍵は組織 id で始まる別の体系で、URL の組織セグメントとは無関係である。',
+            ],
+            [
+                'path' => 'resources/js/types/dashboard.ts',
+                'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL,
+                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'count' => 3,
+                'reason' => '課金 callout の CTA を持つ静的な表であり、画面から識別名を受け取れない。'
+                    .'値は組織相対パスで、利用側 (Dashboard.svelte) が currentOrgUrl() で組織 URL へ写す。',
+            ],
+            [
+                'path' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'kind' => LegacyUrlAllowanceKind::OrganizationRelativePath,
+                'count' => 3,
+                'reason' => 'データセットが渡すのは組織 URL の**後ろに継ぐ suffix** であり、'
+                    .'同じテストの中で "/organizations/{slug}" と連結してから要求している (単独の URL ではない)。',
+            ],
+            [
+                'path' => 'tests/Unit/Services/Storage/FakeStorageKeyTest.php',
+                'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
+                'kind' => LegacyUrlAllowanceKind::FilesystemPath,
+                'count' => 1,
+                'reason' => 'オブジェクトストレージの鍵 (保存先のパス) を組み立てる期待値であり、画面の URL ではないので組織セグメントを持たない',
+            ],
+        ];
+    }
+
+    /**
+     * 許可の件数表 ("path\0rule" => count)。
+     *
+     * @return array<string, int>
+     */
+    public static function counts(): array
+    {
+        $counts = [];
+        foreach (self::entries() as $entry) {
+            $counts[$entry['path']."\0".$entry['rule']] = $entry['count'];
+        }
+
+        return $counts;
+    }
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php b/tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php
new file mode 100644
index 00000000..33f5c336
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlAllowanceKind.php
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+/**
+ * 旧 URL 検出の許可区分。
+ *
+ * ★区分は**限定列挙**である。「なんとなく直せない」を入れる口を作らない。
+ *   新しい区分を足す操作そのものがレビューに見えることが目的である。
+ */
+enum LegacyUrlAllowanceKind: string
+{
+    /**
+     * URL ではなく**保存先のパス**である (ファイルシステム / オブジェクトストレージの鍵)。
+     *
+     * 走査根を組み立てる `dirname(__DIR__, 2).'/app/Prompts'` や、
+     * 保存先の鍵 `orgs/{org}/projects/…` のような形は、字面が URL の根と一致するだけで
+     * 画面の経路ではない。
+     */
+    case FilesystemPath = 'filesystem_path';
+
+    /**
+     * 旧 URL が**もう存在しないこと自体を確かめている**記述。
+     *
+     * 「この URL は 404 になる」ことを固定するテストは、対象の旧 URL を持つのが役目である。
+     */
+    case AbsenceAssertion = 'absence_assertion';
+
+    /**
+     * **組織相対パス**として宣言された値で、組織 prefix は利用側が付ける。
+     *
+     * 静的な表 (画面から slug を受け取れない定数) が持つ相対パスがこれに当たる。
+     * 登録するときは「利用側が必ず組織 URL の入口を通す」ことを同じ変更で確かめること
+     * (通していなければそれは旧 URL であり、許可ではなく修正が要る)。
+     */
+    case OrganizationRelativePath = 'organization_relative_path';
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlExtractionMode.php b/tests/Support/LegacyUrl/LegacyUrlExtractionMode.php
new file mode 100644
index 00000000..3df3cb16
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlExtractionMode.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+/**
+ * URL 文字列の抽出方式 (走査器共通規約 (e) の「区切りの宣言」に当たる)。
+ *
+ * ★方式は **2 つだけ**である。ファイル種別ごとに別々の抽出器を持つと、種別が増えるたびに
+ *   抽出器が増えて検出力の裏取りが追いつかない。**どちらに割り当てるか**を
+ *   `LegacyUrlScanRoots` が拡張子ごとに宣言し、割り当ての無い拡張子は未分類 = 赤になる。
+ */
+enum LegacyUrlExtractionMode: string
+{
+    /**
+     * ソースコードの**文字列リテラルだけ**を見る (PHP / TypeScript / JavaScript / Svelte / Python)。
+     *
+     * ★コメントと識別子は見ない。散文で旧 URL に言及する行 (「/dashboard 配下に留まる」等) は
+     *   **参照ではない**ので検出しない。参照として効くのは実行時に URL になる文字列だけである。
+     * ★リテラルの区切りは `'` / `"` / `` ` `` の 3 つ。ヒアドキュメント本文・
+     *   実行時に連結する形 (`'/dash'.$suffix`) には**無言で効かない** (docblock に明記)。
+     */
+    case SourceLiteral = 'source_literal';
+
+    /**
+     * 文書・データの**全文**を見る (Markdown / JSON / TOML / YAML / TSV / テキスト / Blade)。
+     *
+     * ★終端は空白文字全般 (半角空白・タブ・改行・全角空白) と、閉じ記号・句読点の**列挙集合**
+     *   (`LegacyUrlScanner::PLAIN_TEXT_TERMINATORS`)。**この列挙が保証範囲**であり、
+     *   ここに無い終端は保証しない。
+     */
+    case PlainText = 'plain_text';
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlOccurrence.php b/tests/Support/LegacyUrl/LegacyUrlOccurrence.php
new file mode 100644
index 00000000..60124deb
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlOccurrence.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+/**
+ * 旧 URL / 撤去 route 名の検出 1 件。
+ *
+ * ★`ruleId` は**構文文脈まで識別する安定 ID** である (単なる `legacy-path` にしない)。
+ *   同じファイルの中で別の構文の出現と置き換わっても件数だけでは通らない形にするため。
+ */
+final readonly class LegacyUrlOccurrence
+{
+    public function __construct(
+        /** リポジトリルート相対パス。 */
+        public string $relative,
+        /** 1 起点の行番号。 */
+        public int $line,
+        /** 検出規則の安定 ID (`LegacyUrlScanner::RULE_*`)。 */
+        public string $ruleId,
+        /** 一致した語 (旧パスの根、または撤去 route 名)。 */
+        public string $matched,
+    ) {}
+
+    /** 失敗メッセージ用の 1 行表現。 */
+    public function describe(): string
+    {
+        return "{$this->relative}:{$this->line} [{$this->ruleId}] {$this->matched}";
+    }
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanClass.php b/tests/Support/LegacyUrl/LegacyUrlScanClass.php
new file mode 100644
index 00000000..0fb2dc65
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlScanClass.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+/**
+ * 旧 URL 残存検査における置き場所・形式の分類 (排他 4 分類のうち 3 つ)。
+ *
+ * ★4 つ目の「未分類」は case を持たない。分類できなかったことを enum の値で表すと、
+ *   その値を持ったまま判定を続けられてしまう (集めるだけで判定に使わない形になる)。
+ *   未分類は `LegacyUrlScanRoots::population()` が**別の配列**へ理由付きで積み、
+ *   利用側 gate が 1 件でもあれば落とす。
+ */
+enum LegacyUrlScanClass: string
+{
+    /** 走査する (旧 URL・撤去 route 名が 1 件も無いことを固定する)。 */
+    case Scanned = 'scanned';
+
+    /** 走査しない (理由必須)。 */
+    case NotScanned = 'not_scanned';
+
+    /**
+     * 自己検査専用 (名指し + 件数)。
+     *
+     * ★検出したい語を**わざと持つ**のが役目のファイル (負例 fixture と抽出器の自己テスト)。
+     *   rule ID では表せないので、`LegacyUrlSelfCheckPopulationTest` が
+     *   ファイル名と検出語の一致件数を完全一致で pin する。
+     */
+    case SelfCheckOnly = 'self_check_only';
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanPopulation.php b/tests/Support/LegacyUrl/LegacyUrlScanPopulation.php
new file mode 100644
index 00000000..0dc715c8
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlScanPopulation.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+/**
+ * 旧 URL 走査の実母集団 (4 分類の結果)。
+ *
+ * ★**数える集合と実際に走査する集合は同一**である (別に数え直さない)。
+ * ★`unclassified` と `unresolved` は利用側 gate が 0 件で固定する
+ *   (未分類のまま走査から外れる経路と、読めないまま黙って落ちる経路を塞ぐ)。
+ */
+final readonly class LegacyUrlScanPopulation
+{
+    /**
+     * @param  list<LegacyUrlScannedFile>  $scanned
+     * @param  list<LegacyUrlScannedFile>  $selfCheckOnly
+     * @param  array<string, string>  $notScanned  相対パス => 理由
+     * @param  list<string>  $unclassified
+     * @param  array<string, string>  $unresolved  相対パス => 理由
+     */
+    public function __construct(
+        public array $scanned,
+        public array $selfCheckOnly,
+        public array $notScanned,
+        public array $unclassified,
+        public array $unresolved,
+    ) {}
+
+    /** 走査対象のうち抽出方式ごとの件数 (走査根が生きていることの pin に使う)。 @return array<string, int> */
+    public function scannedCountByRule(): array
+    {
+        $counts = [];
+        foreach ($this->scanned as $file) {
+            $counts[$file->ruleId] = ($counts[$file->ruleId] ?? 0) + 1;
+        }
+        ksort($counts);
+
+        return $counts;
+    }
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanRoots.php b/tests/Support/LegacyUrl/LegacyUrlScanRoots.php
new file mode 100644
index 00000000..63d8681f
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlScanRoots.php
@@ -0,0 +1,386 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * 旧 URL 残存検査の**母集団と 4 分類**の単一出典 (家系裁定 AG-037 / 施策 10)。
+ *
+ * ## 母集団
+ *
+ * git 追跡下のファイル**全数** (拡張子で絞らない)。`Tests\Support\TrackedPhpSourceFiles` は
+ * `.php` に限った兄弟であり、こちらは同じ作法 (`git ls-files`) で全ファイルへ広げた別の母集団である
+ * (列挙を 2 本持つのではなく、対象の定義が違う)。
+ *
+ * ## 4 分類 (排他)
+ *
+ * | 分類 | 何を入れるか |
+ * |---|---|
+ * | 走査する (`Scanned`) | 抽出方式 (`LegacyUrlExtractionMode`) を割り当てて旧 URL を検出する |
+ * | 走査しない (`NotScanned`) | **理由必須**。理由の無い除外は書けない |
+ * | 自己検査専用 (`SelfCheckOnly`) | 検出語をわざと持つ負例 fixture。`LegacyUrlSelfCheckPopulationTest` が件数を pin |
+ * | 未分類 | **1 件でも現れたら赤**。enum の case を持たせず、別の配列へ理由付きで積む |
+ *
+ * ## 確定の順序 (固定)
+ *
+ *   git 追跡下の列挙
+ *   → symlink が解決でき解決先がリポジトリ内か (壊れている / 外なら未解決)
+ *   → 通常ファイルとして読めるか (失敗は未解決)
+ *   → 分類 (走査しない / 自己検査専用 / 走査する / 未分類)
+ *   → 走査する・自己検査専用のものだけ内容を読み、NUL / 不正 UTF-8 は**未解決** (無言で捨てない)
+ *
+ * ★**fail-open を作らない**: 追跡下にあるのに読めないパスを `continue` で捨てない。
+ * ★**バイナリ資産は分類で外す**。内容の NUL 判定で黙って落とすと、NUL を 1 つ入れるだけで
+ *   走査から逃げられる。逃げ道を塞ぐため、走査対象に分類したファイルが NUL を含んだら**赤**にする。
+ *
+ * ## 保証しないもの
+ *
+ * - git 未追跡のファイルは列挙しない (gate が守る境界は commit / CI であり、そこでは必ず追跡下にある)。
+ * - 分類は**パスと拡張子だけ**で決まる。中身から種別を推定しない。
+ */
+final class LegacyUrlScanRoots
+{
+    /**
+     * 走査しない置き場所 (接頭辞またはパス完全一致) と**理由**。
+     *
+     * ★理由は 30 文字以上を `LegacyOrganizationlessUrlAbsenceTest` が機械で要求する
+     *   (「一言で外す」ことをさせない)。
+     *
+     * @var array<string, string>
+     */
+    private const array NOT_SCANNED_PATHS = [
+        'devnotes/' => '設計・レビューの記録であり実行されない。当時の URL 表記は履歴であって参照ではないため、書き換えると記録が事実でなくなる',
+        'routes/' => 'route 定義の URI は group の prefix からの相対セグメントであり、組織 prefix の中では根だけの記述が正しい姿になる。実 route 表が 1 本残らず組織 URL 配下にあることは OrganizationScopedRouteCoverageTest が解決済みの route 表で固定するので、ここを走査しても新しい保証は増えない',
+        'doc/reference/' => '現場から預かった業務資料 (SOP・撮影シナリオ・モックアップ・プロンプト案) であり、本アプリの URL を 1 つも持たない。編集の権利も本リポジトリに無い',
+        'docs/TODO-closed.md' => 'クローズ済み TODO の記録は当時の事実である。過去の作業説明に現れる旧 URL を書き換えると記録そのものが嘘になるため、履歴として走査から外す',
+        'composer.lock' => '依存解決の生成物であり人が書く記述を含まない。パッケージ名や URL は上流の値であって本アプリの経路ではない',
+        'pnpm-lock.yaml' => '依存解決の生成物であり人が書く記述を含まない。パッケージ名や URL は上流の値であって本アプリの経路ではない',
+        'public/build/' => 'ビルド生成物であり、原本は resources/ 配下の走査で押さえている。生成物を直接直すことはない',
+    ];
+
+    /**
+     * 走査しない拡張子 (バイナリ資産) と**理由**。
+     *
+     * @var array<string, string>
+     */
+    private const array NOT_SCANNED_EXTENSIONS = [
+        'png' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
+        'jpg' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
+        'jpeg' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
+        'gif' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
+        'ico' => '画像バイナリであり、テキストとしての URL 参照を持たない (favicon)',
+        'pdf' => '配布資料のバイナリであり、テキストとしての URL 参照を持たない (現場から預かった手順書)',
+        'docx' => 'オフィス文書のバイナリであり、テキストとしての URL 参照を持たない (現場から預かった資料)',
+        'xlsx' => 'オフィス文書のバイナリであり、テキストとしての URL 参照を持たない (撮影シナリオ表)',
+        'mp4' => '動画バイナリであり、テキストとしての URL 参照を持たない (見本素材)',
+        'patch' => 'レビュー履歴として保存した差分の生の写しであり、当時のコードの記録である (置き場所は devnotes 配下だけ)',
+        'err' => '実行時の標準エラー出力の記録であり、当時の実行の記録である (置き場所は devnotes 配下だけ)',
+    ];
+
+    /**
+     * 自己検査専用 (検出語をわざと持つファイル) と**理由**。
+     *
+     * ★ここに入れたファイルは旧 URL の検出対象から外れるかわりに、
+     *   `LegacyUrlSelfCheckPopulationTest` がファイル名と検出語の一致件数を**完全一致で pin** する。
+     *
+     * @var array<string, string>
+     */
+    private const array SELF_CHECK_ONLY_PATHS = [
+        'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => '旧 URL を検出できることを確かめる正例の見本。検出したい語をわざと持つのが役目であり、rule ID では表せない',
+        'tests/Architecture/fixtures/legacy-url/allowed-paths.md' => '誤検出してはいけない新 URL・無関係な語の見本。旧 URL の根と紛らわしい語をわざと持つのが役目である',
+        'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 'PHP の文字列リテラルとコメントの扱いを分ける検出力の見本。旧 URL をコメントとリテラルの両方に持つ',
+        'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 'script の文字列リテラル・コメント・組織 URL 組み立ての入口の扱いを分ける検出力の見本である',
+    ];
+
+    /**
+     * 走査する拡張子 → 抽出方式と rule ID。
+     *
+     * ★`.blade.php` は PHP としてではなく全文として見る (テンプレートであり、
+     *   属性値と Blade 式が混在するため文字列リテラルの構文が閉じない)。
+     * ★拡張子を持たないファイル (`artisan` / `scripts/codex` / `.gitignore` 等) は
+     *   `''` のキーで全文走査に割り当てる。
+     *
+     * @var array<string, array{mode: LegacyUrlExtractionMode, rule: string}>
+     */
+    private const array SCANNED_EXTENSIONS = [
+        'php' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_PHP_LITERAL],
+        'ts' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'js' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'mjs' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'cjs' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'svelte' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'py' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
+        'md' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT],
+        'json' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'jsonl' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'webmanifest' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'toml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'yaml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'yml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'tsv' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'txt' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'css' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'sh' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'xml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'html' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'neon' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'ini' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'example' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        'testing' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+        '' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
+    ];
+
+    /** 確定済みの母集団 (プロセス内で 1 度だけ確定する。判定は持たない)。 */
+    private static ?LegacyUrlScanPopulation $memoized = null;
+
+    /** インスタンス化しない (純関数と定数の置き場)。 */
+    private function __construct() {}
+
+    /** リポジトリルート (テスト実行時の base path)。 */
+    public static function repositoryRoot(): string
+    {
+        $root = realpath(__DIR__.'/../../..');
+        if (! is_string($root)) {
+            throw new RuntimeException('リポジトリルートを解決できません');
+        }
+
+        return $root;
+    }
+
+    /** 走査しない置き場所の理由表 (gate が理由の長さを検査する)。 @return array<string, string> */
+    public static function notScannedPathReasons(): array
+    {
+        return self::NOT_SCANNED_PATHS;
+    }
+
+    /** 走査しない拡張子の理由表。 @return array<string, string> */
+    public static function notScannedExtensionReasons(): array
+    {
+        return self::NOT_SCANNED_EXTENSIONS;
+    }
+
+    /** 自己検査専用の理由表。 @return array<string, string> */
+    public static function selfCheckOnlyReasons(): array
+    {
+        return self::SELF_CHECK_ONLY_PATHS;
+    }
+
+    /** 走査する拡張子の割り当て表。 @return array<string, array{mode: LegacyUrlExtractionMode, rule: string}> */
+    public static function scannedExtensions(): array
+    {
+        return self::SCANNED_EXTENSIONS;
+    }
+
+    /**
+     * 拡張子 (小文字。拡張子なしは空文字列)。
+     *
+     * ★`.gitignore` のようにドットで始まるだけのファイルは**拡張子なし**として扱う。
+     *   `x.blade.php` は `php` を返す (blade の判別はパス側で行う)。
+     */
+    public static function extensionOf(string $relative): string
+    {
+        $basename = basename($relative);
+        $position = strrpos($basename, '.');
+        if ($position === false || $position === 0) {
+            return '';
+        }
+
+        return strtolower(substr($basename, $position + 1));
+    }
+
+    /** 解決済みの絶対パスがリポジトリルート配下か (純関数。自己検証の seam)。 */
+    public static function isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool
+    {
+        return str_starts_with($resolvedTarget, rtrim($repositoryRoot, '/').'/');
+    }
+
+    /**
+     * symlink の解決結果の判定 (母集団の確定も自己検証も必ずここを通る)。
+     *
+     * ★symlink でなければ null。壊れている / リポジトリ外へ解決されるなら理由を返す。
+     */
+    public static function symlinkUnresolvedReason(string $repositoryRoot, string $absolute): ?string
+    {
+        if (! is_link($absolute)) {
+            return null;
+        }
+
+        $target = realpath($absolute);
+        if ($target === false) {
+            return 'symlink の解決に失敗 (壊れた symlink)';
+        }
+        if (! self::isPathInsideRepository($repositoryRoot, $target)) {
+            return 'symlink がリポジトリ外へ解決される';
+        }
+
+        return null;
+    }
+
+    /**
+     * パスの分類 (純関数。**母集団の確定も自己検証も必ずここを通る**)。
+     *
+     * ★分類できなければ `null` を返す = 未分類。利用側が赤にする。
+     *
+     * @return array{class: LegacyUrlScanClass, mode: ?LegacyUrlExtractionMode, rule: ?string, reason: ?string}|null
+     */
+    public static function classify(string $relative): ?array
+    {
+        foreach (self::SELF_CHECK_ONLY_PATHS as $path => $reason) {
+            if ($relative === $path) {
+                return ['class' => LegacyUrlScanClass::SelfCheckOnly, 'mode' => null, 'rule' => null, 'reason' => $reason];
+            }
+        }
+
+        foreach (self::NOT_SCANNED_PATHS as $path => $reason) {
+            $matches = str_ends_with($path, '/') ? str_starts_with($relative, $path) : $relative === $path;
+            if ($matches) {
+                return ['class' => LegacyUrlScanClass::NotScanned, 'mode' => null, 'rule' => null, 'reason' => $reason];
+            }
+        }
+
+        $extension = self::extensionOf($relative);
+
+        if (isset(self::NOT_SCANNED_EXTENSIONS[$extension])) {
+            return [
+                'class' => LegacyUrlScanClass::NotScanned,
+                'mode' => null,
+                'rule' => null,
+                'reason' => self::NOT_SCANNED_EXTENSIONS[$extension],
+            ];
+        }
+
+        if (str_ends_with($relative, '.blade.php')) {
+            return [
+                'class' => LegacyUrlScanClass::Scanned,
+                'mode' => LegacyUrlExtractionMode::PlainText,
+                'rule' => LegacyUrlScanner::RULE_BLADE_TEXT,
+                'reason' => null,
+            ];
+        }
+
+        if (isset(self::SCANNED_EXTENSIONS[$extension])) {
+            return [
+                'class' => LegacyUrlScanClass::Scanned,
+                'mode' => self::SCANNED_EXTENSIONS[$extension]['mode'],
+                'rule' => self::SCANNED_EXTENSIONS[$extension]['rule'],
+                'reason' => null,
+            ];
+        }
+
+        return null;
+    }
+
+    /** 母集団を確定する (唯一の経路)。 */
+    public static function population(): LegacyUrlScanPopulation
+    {
+        if (self::$memoized instanceof LegacyUrlScanPopulation) {
+            return self::$memoized;
+        }
+
+        $repositoryRoot = self::repositoryRoot();
+        $scanned = [];
+        $selfCheckOnly = [];
+        $notScanned = [];
+        $unclassified = [];
+        $unresolved = [];
+
+        foreach (self::trackedPaths($repositoryRoot) as $relative) {
+            $absolute = $repositoryRoot.'/'.$relative;
+
+            $symlinkReason = self::symlinkUnresolvedReason($repositoryRoot, $absolute);
+            if ($symlinkReason !== null) {
+                $unresolved[$relative] = $symlinkReason;
+
+                continue;
+            }
+
+            if (! is_file($absolute)) {
+                $unresolved[$relative] = '追跡下だが通常ファイルとして読めない';
+
+                continue;
+            }
+
+            $classification = self::classify($relative);
+            if ($classification === null) {
+                $unclassified[] = $relative;
+
+                continue;
+            }
+
+            if ($classification['class'] === LegacyUrlScanClass::NotScanned) {
+                $notScanned[$relative] = (string) $classification['reason'];
+
+                continue;
+            }
+
+            $contents = @file_get_contents($absolute);
+            if ($contents === false) {
+                $unresolved[$relative] = 'ファイルの読み取りに失敗';
+
+                continue;
+            }
+            if (str_contains($contents, "\0")) {
+                // ★走査対象に分類したのにバイナリ = 分類が誤っている。無言で外さない
+                $unresolved[$relative] = '走査対象に分類されているが NUL を含む (分類の誤り)';
+
+                continue;
+            }
+            if (! mb_check_encoding($contents, 'UTF-8')) {
+                $unresolved[$relative] = 'UTF-8 として不正';
+
+                continue;
+            }
+
+            $file = new LegacyUrlScannedFile(
+                relative: $relative,
+                contents: $contents,
+                mode: $classification['mode'] ?? LegacyUrlExtractionMode::PlainText,
+                ruleId: (string) $classification['rule'],
+            );
+
+            if ($classification['class'] === LegacyUrlScanClass::SelfCheckOnly) {
+                $selfCheckOnly[] = $file;
+
+                continue;
+            }
+
+            $scanned[] = $file;
+        }
+
+        return self::$memoized = new LegacyUrlScanPopulation(
+            scanned: $scanned,
+            selfCheckOnly: $selfCheckOnly,
+            notScanned: $notScanned,
+            unclassified: $unclassified,
+            unresolved: $unresolved,
+        );
+    }
+
+    /**
+     * git 追跡下の相対パス全数。
+     *
+     * @return list<string>
+     */
+    private static function trackedPaths(string $repositoryRoot): array
+    {
+        $process = new Process(['git', 'ls-files', '-z'], $repositoryRoot);
+        $process->run();
+        if (! $process->isSuccessful()) {
+            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
+        }
+
+        $paths = [];
+        foreach (explode("\0", $process->getOutput()) as $relative) {
+            if ($relative === '') {
+                continue;
+            }
+            $paths[] = $relative;
+        }
+
+        return $paths;
+    }
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlScannedFile.php b/tests/Support/LegacyUrl/LegacyUrlScannedFile.php
new file mode 100644
index 00000000..608c33bc
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlScannedFile.php
@@ -0,0 +1,16 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+/** 走査対象に確定した 1 ファイル (内容つき)。 */
+final readonly class LegacyUrlScannedFile
+{
+    public function __construct(
+        public string $relative,
+        public string $contents,
+        public LegacyUrlExtractionMode $mode,
+        public string $ruleId,
+    ) {}
+}
diff --git a/tests/Support/LegacyUrl/LegacyUrlScanner.php b/tests/Support/LegacyUrl/LegacyUrlScanner.php
new file mode 100644
index 00000000..024acc88
--- /dev/null
+++ b/tests/Support/LegacyUrl/LegacyUrlScanner.php
@@ -0,0 +1,337 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\LegacyUrl;
+
+use Tests\Support\SourceLiterals;
+
+/**
+ * 組織を持たない**旧 URL** と**撤去した route 名**の検出器 (家系裁定 AG-037 / 施策 10)。
+ *
+ * ## 何を検出するか (2 つの台帳。同じ台帳にしない)
+ *
+ * | 台帳 | 対象 | 中身 |
+ * |---|---|---|
+ * | 旧パス台帳 | URL 文字列 | 単位 B で組織 URL 配下へ移した業務パスの**根** |
+ * | 撤去 route 名台帳 | route 名 | `organizations.switch` の 1 本だけ (他の route 名は維持されている) |
+ *
+ * ## 判定 (正規化済み path の root 一致)
+ *
+ * query (`?`) と hash (`#`) を落とした path が、**根と完全一致するか `根/` で始まる**ときに旧パスと判定する。
+ * 前方一致だけでは末尾スラッシュなしの根 (`/projects`) を拾えず、素の部分文字列一致では
+ * `/projectsomething` まで拾ってしまう。
+ *
+ * ## 誤検出を作らない 3 つの規則 (実測で必要になった順)
+ *
+ * 1. **根の直前が語の一部なら旧パスではない**。`/organizations/acme/projects` の `/projects` は
+ *    直前が英数字なので根の位置に無い。同じ理由で `/billing/purchase-tickets` も拾わない。
+ * 2. **組織セグメントの直後は新 URL である**。`organizations/{organization}/projects` /
+ *    `` `/organizations/${slug}/billing` `` のように直前が置換子で終わる形を明示的に許す
+ *    (規則 1 の英数字判定では `}` を拾ってしまうため)。先頭スラッシュの有無は問わない
+ *    (route 表を写した目録は `organizations/{organization}/…` と書く)。
+ * 3. **組織 URL 組み立ての入口に渡した相対パスは新 URL である**。フロントの
+ *    `orgUrl(slug, '/projects')` / `currentOrgUrl('/projects')` は
+ *    `resources/js/lib/org-url.ts` が組織 prefix を必ず付ける唯一の入口である。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - **scheme と host を伴う絶対 URL は対象外**である (`https://example.com/dashboard`)。
+ *   外部サービスの URL と自アプリの URL を字面で区別できないため、
+ *   host の後ろの path は根の位置と見なさない。
+ * - 実行時に組み立てる形 (`'/dash'.$suffix` / `'/' + name`) には**無言で効かない**。
+ * - `LegacyUrlExtractionMode::SourceLiteral` のファイルでは**コメントを見ない**。
+ *   撤去を説明する散文は参照ではないためである。
+ * - 利用者のブックマーク・外部サービスに登録済みの URL・送信済みメール本文・
+ *   ブラウザの履歴は**リポジトリの外**にあり、本検査の対象にならない。
+ */
+final class LegacyUrlScanner
+{
+    /** 規則 ID: PHP の文字列リテラル。 */
+    public const string RULE_PHP_LITERAL = 'php-string-literal';
+
+    /** 規則 ID: TypeScript / JavaScript / Svelte / Python の文字列リテラル。 */
+    public const string RULE_SCRIPT_LITERAL = 'script-string-literal';
+
+    /** 規則 ID: Blade テンプレートの全文。 */
+    public const string RULE_BLADE_TEXT = 'blade-text';
+
+    /** 規則 ID: Markdown の全文 (リンクの宛先とプレーン URL の両方を含む)。 */
+    public const string RULE_MARKDOWN_TEXT = 'markdown-text';
+
+    /** 規則 ID: JSON / TOML / YAML / TSV / テキスト等のデータの全文。 */
+    public const string RULE_DATA_TEXT = 'data-text';
+
+    /** 規則 ID: 撤去した route 名 (旧パスとは別台帳)。 */
+    public const string RULE_REMOVED_ROUTE_NAME = 'removed-route-name';
+
+    /**
+     * 全文走査での終端集合 (走査器共通規約 (e) の宣言)。
+     *
+     * ★空白文字全般 (半角空白・タブ・改行・復帰・全角空白) と、閉じ記号・句読点の**列挙**である。
+     *   **この列挙が保証範囲**であり、ここに無い終端 (`.` など) は保証しない
+     *   (`.` を入れると `app.example.com` のホスト名を旧パスとして拾ってしまう)。
+     *
+     * @var list<string>
+     */
+    public const array PLAIN_TEXT_TERMINATORS = [
+        ' ', "\t", "\n", "\r", "\u{3000}",
+        ')', ']', '}', '>', '"', "'", '`', ',', ';', ':', '。', '、', '）', '｜', '|',
+    ];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 組織 URL 配下へ移した業務パスの**根** (先頭スラッシュつき)。
+     *
+     * ★**検出語を文字列リテラルとして持たない** — 断片を連結して組み立てる。
+     *   こうしないと本走査器と利用側 gate が自分自身の走査に引っかかり、
+     *   「旧 URL 文字列を持つ例外目録」という再帰を作ることになる。
+     * ★根の出典: 単位 B の前後で `Route::getRoutes()` の第 1 セグメントから消えたものである
+     *   (`billing` / `billing-required` / `dashboard` / `manage` / `notifications` /
+     *   `onboarding` / `projects` / `purchase-tickets`)。`app` は**正規の分岐入口として残る**ため
+     *   `CaptureEntryUrlAllowance` の許可目録と対で扱う。
+     *
+     * @return list<string>
+     */
+    public static function legacyRoots(): array
+    {
+        return [
+            '/'.'bill'.'ing'.'-required',
+            '/'.'purchase'.'-tickets',
+            '/'.'notifi'.'cations',
+            '/'.'onboar'.'ding',
+            '/'.'dash'.'board',
+            '/'.'proj'.'ects',
+            '/'.'bill'.'ing',
+            '/'.'man'.'age',
+            self::captureRoot(),
+        ];
+    }
+
+    /**
+     * 撮影 PWA の根 (断片から組み立てる)。**この根だけは配下つきでのみ旧 URL である**。
+     *
+     * ★裸のこれは**正規の分岐入口** (`capture.entry`) であり、今後も残る
+     *   (PWA の `start_url` / robots の宣言 / 入口の Feature テストが正しく持つ)。
+     *   旧 URL なのは配下 (`…/projects/…` 等) を持つ形だけで、そちらは
+     *   組織 URL 配下 (`/organizations/{slug}/app/…`) へ移設済みである。
+     * ★この「配下つきのみ」規則があるので、正規入口のための許可目録は要らない
+     *   (許可目録は**目録の中身が旧 URL 文字列を持つ**という再帰を招きやすく、
+     *   規則で表せるならそちらが良い)。
+     */
+    public static function captureRoot(): string
+    {
+        return '/'.'a'.'pp';
+    }
+
+    /** 撤去した route 名 (断片から組み立てる)。 */
+    public static function removedRouteName(): string
+    {
+        return 'organizations.'.'switch';
+    }
+
+    /** 組織セグメントの接頭辞 (断片から組み立てる。規則 2 の判定に使う)。 */
+    public static function organizationSegment(): string
+    {
+        return 'organi'.'zations';
+    }
+
+    /**
+     * 1 ファイル分の検出。
+     *
+     * @return list<LegacyUrlOccurrence>
+     */
+    public static function scanFile(LegacyUrlScannedFile $file): array
+    {
+        $occurrences = [];
+
+        foreach (self::extract($file) as $chunk) {
+            foreach (self::matchesIn($chunk['value']) as $matched) {
+                $occurrences[] = new LegacyUrlOccurrence(
+                    relative: $file->relative,
+                    line: $chunk['line'],
+                    ruleId: $file->ruleId,
+                    matched: $matched,
+                );
+            }
+            if (str_contains($chunk['value'], self::removedRouteName())) {
+                $occurrences[] = new LegacyUrlOccurrence(
+                    relative: $file->relative,
+                    line: $chunk['line'],
+                    ruleId: self::RULE_REMOVED_ROUTE_NAME,
+                    matched: self::removedRouteName(),
+                );
+            }
+        }
+
+        return $occurrences;
+    }
+
+    /**
+     * 抽出方式に従って走査対象の断片を取り出す。
+     *
+     * @return list<array{line: int, value: string}>
+     */
+    public static function extract(LegacyUrlScannedFile $file): array
+    {
+        if ($file->mode === LegacyUrlExtractionMode::PlainText) {
+            $chunks = [];
+            foreach (explode("\n", $file->contents) as $index => $line) {
+                $chunks[] = ['line' => $index + 1, 'value' => $line];
+            }
+
+            return $chunks;
+        }
+
+        if ($file->ruleId === self::RULE_PHP_LITERAL) {
+            return SourceLiterals::php($file->contents);
+        }
+
+        return self::scriptLiteralsWithOrgUrlAllowance($file->contents, str_ends_with($file->relative, '.py'));
+    }
+
+    /**
+     * script のリテラル抽出。**組織 URL 組み立ての入口へ渡した相対パスは除く** (規則 3)。
+     *
+     * ★判定は**そのリテラルの開始位置**で行う (同じ値が入口の外にも書かれていれば
+     *   そちらは残る = 入口の中に 1 度書いたことで外の直書きまで許してしまうことはない)。
+     *
+     * @return list<array{line: int, offset: int, value: string}>
+     */
+    private static function scriptLiteralsWithOrgUrlAllowance(string $source, bool $hashComments): array
+    {
+        $kept = [];
+        foreach (SourceLiterals::script($source, $hashComments) as $literal) {
+            if (self::isOrganizationUrlBuilderArgument($source, $literal['offset'])) {
+                continue;
+            }
+            $kept[] = $literal;
+        }
+
+        return $kept;
+    }
+
+    /**
+     * その位置のリテラルが `orgUrl(...)` / `currentOrgUrl(...)` の引数として現れているか。
+     *
+     * ★「開き括弧を閉じないまま入口名まで遡れるか」で判定する。`[^()]*` が括弧を跨がせないので、
+     *   入口を呼んだ後の別の呼び出し (`foo(bar(), '/x')`) は一致しない。
+     */
+    private static function isOrganizationUrlBuilderArgument(string $source, int $literalOffset): bool
+    {
+        $before = substr($source, 0, $literalOffset);
+
+        return preg_match('/(?:currentOrgUrl|orgUrl)\(\s*[^()]*$/', $before) === 1;
+    }
+
+    /**
+     * 1 つの断片に含まれる旧パスの根 (重複を保つ = 件数がそのまま出現数になる)。
+     *
+     * @return list<string>
+     */
+    public static function matchesIn(string $chunk): array
+    {
+        $matches = [];
+        $organizationSegment = self::organizationSegment().'/';
+
+        foreach (self::legacyRoots() as $root) {
+            $offset = 0;
+            while (($position = strpos($chunk, $root, $offset)) !== false) {
+                $offset = $position + 1;
+
+                if (! self::isRootPosition($chunk, $position, $organizationSegment)) {
+                    continue;
+                }
+                $end = $position + strlen($root);
+                if ($root === self::captureRoot()) {
+                    // 配下つきのときだけ旧 URL (裸は正規の分岐入口)
+                    if (! self::hasSubPathAfter($chunk, $end)) {
+                        continue;
+                    }
+                } elseif (! self::isPathBoundaryAfter($chunk, $end)) {
+                    continue;
+                }
+                $matches[] = $root;
+            }
+        }
+
+        return $matches;
+    }
+
+    /**
+     * 根の位置に現れているか (規則 1 と規則 2)。
+     *
+     * ★直前が英数字・`.`・`_`・`-`・`/` のいずれかなら根ではない (長いパスの途中 /
+     *   ホスト名 / scheme 直後の `//`)。
+     * ★ただし直前が組織セグメントの置換子で終わっているときは**新 URL** なので、
+     *   やはり根ではない (規則 2)。
+     */
+    private static function isRootPosition(string $chunk, int $position, string $organizationSegment): bool
+    {
+        if ($position === 0) {
+            return true;
+        }
+
+        $previous = $chunk[$position - 1];
+        if (ctype_alnum($previous) || in_array($previous, ['.', '_', '-', '/'], true)) {
+            return false;
+        }
+
+        // 規則 2: `organizations/{organization}` / `organizations/${slug}` / `organizations/<slug>` の直後
+        $before = substr($chunk, 0, $position);
+        $pattern = '/'.preg_quote($organizationSegment, '/').'(?:\{[^}]*\}|\$\{[^}]*\}|<[^>]*>)$/';
+
+        return preg_match($pattern, $before) !== 1;
+    }
+
+    /**
+     * 根の直後が path の区切りか (根と完全一致 or `根/` で始まる)。
+     *
+     * ★`?` と `#` は query / hash の始まりなので path の終端として扱う。
+     * ★それ以外は `PLAIN_TEXT_TERMINATORS` の列挙だけを終端と認める
+     *   (`/appx` `/app-old` `/myapp` を拾わない = 走査器共通規約 (e) の 3 形)。
+     */
+    /**
+     * 根の直後に**配下のセグメントがあるか** (`/app` 専用)。
+     *
+     * ★`/` に続いて終端でない文字が 1 つ以上あることを求める。
+     *   裸の `/app` と末尾スラッシュだけの `/app/` は正規入口とみなして拾わない。
+     */
+    private static function hasSubPathAfter(string $chunk, int $end): bool
+    {
+        if ($end + 1 >= strlen($chunk) || $chunk[$end] !== '/') {
+            return false;
+        }
+
+        $next = substr($chunk, $end + 1);
+        foreach (self::PLAIN_TEXT_TERMINATORS as $terminator) {
+            if (str_starts_with($next, $terminator)) {
+                return false;
+            }
+        }
+
+        return true;
+    }
+
+    private static function isPathBoundaryAfter(string $chunk, int $end): bool
+    {
+        if ($end >= strlen($chunk)) {
+            return true;
+        }
+
+        $next = substr($chunk, $end, 1);
+        if ($next === '/' || $next === '?' || $next === '#' || $next === '\\') {
+            return true;
+        }
+
+        foreach (self::PLAIN_TEXT_TERMINATORS as $terminator) {
+            if (str_starts_with(substr($chunk, $end), $terminator)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+}
diff --git a/tests/Support/SourceLiterals.php b/tests/Support/SourceLiterals.php
new file mode 100644
index 00000000..2a309c49
--- /dev/null
+++ b/tests/Support/SourceLiterals.php
@@ -0,0 +1,214 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * ソースコードから**文字列リテラルだけ**を取り出す純関数 (走査器の共通部品)。
+ *
+ * ★存在理由: 「撤去した名前がコードに残っていないか」を見る走査は、**コメントの言及**を
+ *   参照と取り違えてはならない。撤去したことを説明する docblock は撤去の証拠であって
+ *   復活ではない。同じ切り出しを走査ごとに書くと必ず食い違うので 1 本に集約する。
+ *
+ * ★**区切りの宣言** (走査器共通規約 (e)):
+ *   - PHP: `token_get_all()` が返す `T_CONSTANT_ENCAPSED_STRING` /
+ *     `T_ENCAPSED_AND_WHITESPACE` / `T_INLINE_HTML` を採る。前 2 つが文字列リテラル
+ *     (補間つき二重引用符とヒアドキュメント本文を含む)、最後が PHP 開始タグの外の生テキストである
+ *     (`.php` に混ざった生 HTML を落とさないため)。コメント (`T_COMMENT` / `T_DOC_COMMENT`) は採らない。
+ *   - script (TypeScript / JavaScript / Svelte / Python): 自前の走査で
+ *     `'` / `"` / `` ` `` に挟まれた範囲を採り、`//` 行コメント・ブロックコメント (`/`+`*` から `*`+`/` まで)・
+ *     `#` 行コメント (Python) は読み飛ばす。**`//` の直前が `:` のときはコメントにしない**
+ *     (`https://` を行コメントと誤読して行の残りを落とさないため)。
+ *
+ * ★**保証しないもの (誇張しない)**:
+ *   - 実行時に組み立てる形 (`'/dash'.$suffix` / `'/' + name`) は 1 つのリテラルに見えないので
+ *     連結後の値では判定できない。連結前の断片だけを見る。
+ *   - script 側は言語の構文解析ではない。正規表現リテラル (`/…/g`) の中の引用符、
+ *     Svelte の `<!-- -->` コメント、JSX/HTML 属性の引用符なし記法は
+ *     **文字列として採られる / 採られないのどちらかに倒れる**。倒れる方向は
+ *     「採る (過検出)」であり、見逃す方向ではない。
+ *   - Python の三重引用符は、同じ引用符 3 つの連なりとして 2 つの空文字列 + 本体に割れる
+ *     (本体は採られるので見逃さない)。
+ */
+final class SourceLiterals
+{
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * PHP ソースの文字列リテラル (と PHP タグ外の生テキスト)。
+     *
+     * @return list<array{line: int, offset: int, value: string}>
+     */
+    public static function php(string $source): array
+    {
+        $tokens = @token_get_all($source);
+        $literals = [];
+        $offset = 0;
+        $count = count($tokens);
+
+        for ($index = 0; $index < $count; $index++) {
+            $token = $tokens[$index];
+            $text = is_array($token) ? (string) $token[1] : (string) $token;
+
+            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) {
+                $literals[] = ['line' => (int) $token[2], 'offset' => $offset, 'value' => $text];
+                $offset += strlen($text);
+
+                continue;
+            }
+
+            // 補間つき二重引用符 / ヒアドキュメントは **1 つの値として組み直す**。
+            // token 単位で拾うと "/organizations/{$slug}/projects" が "/projects" だけの
+            // 断片に割れ、根の位置に無い記述を根と誤判定する。
+            $isInterpolationStart = ($token === '"')
+                || (is_array($token) && $token[0] === T_START_HEREDOC);
+            if (! $isInterpolationStart) {
+                $offset += strlen($text);
+
+                continue;
+            }
+
+            $startLine = is_array($token) ? (int) $token[2] : self::lineAt($source, $offset);
+            $startOffset = $offset;
+            $offset += strlen($text);
+            $index++;
+            $value = '';
+            $pendingPlaceholder = false;
+
+            for (; $index < $count; $index++) {
+                $inner = $tokens[$index];
+                $innerText = is_array($inner) ? (string) $inner[1] : (string) $inner;
+
+                $isEnd = ($token === '"' && $inner === '"')
+                    || (is_array($inner) && $inner[0] === T_END_HEREDOC);
+                if ($isEnd) {
+                    $offset += strlen($innerText);
+                    break;
+                }
+
+                if (is_array($inner) && $inner[0] === T_ENCAPSED_AND_WHITESPACE) {
+                    $value .= $innerText;
+                    $pendingPlaceholder = false;
+                } elseif (! $pendingPlaceholder) {
+                    // 補間部分は中身を見ない。**波括弧つきの置換子**へ畳む
+                    // (組織セグメントの直後かどうかの判定が置換子の形に依存するため)
+                    $value .= '{$}';
+                    $pendingPlaceholder = true;
+                }
+
+                $offset += strlen($innerText);
+            }
+
+            $literals[] = ['line' => $startLine, 'offset' => $startOffset, 'value' => $value];
+        }
+
+        return $literals;
+    }
+
+    /** バイト位置から 1 起点の行番号を求める (ヒアドキュメント開始などの補助)。 */
+    private static function lineAt(string $source, int $offset): int
+    {
+        return substr_count(substr($source, 0, $offset), "\n") + 1;
+    }
+
+    /**
+     * script 系ソースの文字列リテラル。
+     *
+     * @param  bool  $hashComments  `#` を行コメントとして扱うか (Python)
+     * @return list<array{line: int, offset: int, value: string}>
+     */
+    public static function script(string $source, bool $hashComments = false): array
+    {
+        $literals = [];
+        $length = strlen($source);
+        $line = 1;
+        $index = 0;
+
+        while ($index < $length) {
+            $char = $source[$index];
+
+            if ($char === "\n") {
+                $line++;
+                $index++;
+
+                continue;
+            }
+
+            // 行コメント (`//`)。直前が `:` なら URL の一部なのでコメントにしない
+            if ($char === '/' && $index + 1 < $length && $source[$index + 1] === '/'
+                && ! ($index > 0 && $source[$index - 1] === ':')) {
+                while ($index < $length && $source[$index] !== "\n") {
+                    $index++;
+                }
+
+                continue;
+            }
+
+            // ブロックコメント
+            if ($char === '/' && $index + 1 < $length && $source[$index + 1] === '*') {
+                $index += 2;
+                while ($index + 1 < $length && ! ($source[$index] === '*' && $source[$index + 1] === '/')) {
+                    if ($source[$index] === "\n") {
+                        $line++;
+                    }
+                    $index++;
+                }
+                $index = min($index + 2, $length);
+
+                continue;
+            }
+
+            if ($hashComments && $char === '#') {
+                while ($index < $length && $source[$index] !== "\n") {
+                    $index++;
+                }
+
+                continue;
+            }
+
+            if ($char === "'" || $char === '"' || $char === '`') {
+                $startLine = $line;
+                $startOffset = $index;
+                $quote = $char;
+                $index++;
+                $value = '';
+                while ($index < $length) {
+                    $current = $source[$index];
+                    if ($current === '\\' && $index + 1 < $length) {
+                        $value .= $current.$source[$index + 1];
+                        if ($source[$index + 1] === "\n") {
+                            $line++;
+                        }
+                        $index += 2;
+
+                        continue;
+                    }
+                    if ($current === $quote) {
+                        $index++;
+                        break;
+                    }
+                    // ★引用符が閉じないまま改行した場合は文字列ではないと判断して打ち切る
+                    //   (単引用符を含む散文で行の残りを飲み込まないため)。
+                    //   ただしテンプレートリテラルは複数行にまたがれるので続ける
+                    if ($current === "\n" && $quote !== '`') {
+                        break;
+                    }
+                    if ($current === "\n") {
+                        $line++;
+                    }
+                    $value .= $current;
+                    $index++;
+                }
+                $literals[] = ['line' => $startLine, 'offset' => $startOffset, 'value' => $value];
+
+                continue;
+            }
+
+            $index++;
+        }
+
+        return $literals;
+    }
+}
```

## 5. 実装差分 (施策 10 の過程で直した実装の欠陥)

```diff
diff --git a/app/Http/Controllers/Auth/SocialAuthController.php b/app/Http/Controllers/Auth/SocialAuthController.php
index 0b9099b6..b27b9f2a 100644
--- a/app/Http/Controllers/Auth/SocialAuthController.php
+++ b/app/Http/Controllers/Auth/SocialAuthController.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Controllers\Auth;
 
+use App\Enums\OrganizationRole;
 use App\Http\Controllers\Controller;
 use App\Models\Organization;
 use App\Models\User;
@@ -111,7 +112,7 @@ public function callback(Request $request, string $provider, SocialAccountServic
             Auth::login($linkedUser, remember: true);
             $request->session()->regenerate();
 
-            return redirect()->intended(route('dashboard'));
+            return redirect()->intended(route('app.entry'));
         }
 
         if ($intent === 'login') {
@@ -139,16 +140,20 @@ public function callback(Request $request, string $provider, SocialAccountServic
         Auth::login($user, remember: true);
         $request->session()->regenerate();
 
-        // pending → 個人組織へ移送 (pending は必ず forget で消費される)。
-        // 個人組織が無い (= 招待経由等) 場合は promote 対象が存在しないため pending だけ落とす。
-        $personalOrganization = $user->organizations()->where('is_personal', true)->first();
-        if ($personalOrganization instanceof Organization) {
-            $this->intendedPlanResolver->promotePendingToOrganization($personalOrganization);
+        // pending → **自分が Owner の初期組織**へ移送 (pending は必ず forget で消費される)。
+        // ★種別フラグ (旧 `is_personal`) は撤去済み (家系裁定 AG-038) なので、
+        //   「所属組織の有無」では判定できない — 招待経由の利用者も所属組織を持つ。
+        //   Owner の組織が無い (= 招待経由で参加しただけ) なら promote 対象が存在しないため
+        //   pending だけ落とす (他人の組織へプラン意図を移送しない)。
+        $initialOrganization = $user->organizations()->orderBy('organizations.id')->get()
+            ->first(static fn (Organization $organization): bool => $user->organizationRole($organization) === OrganizationRole::Owner);
+        if ($initialOrganization instanceof Organization) {
+            $this->intendedPlanResolver->promotePendingToOrganization($initialOrganization);
         } else {
             $this->intendedPlanResolver->forgetPending();
         }
 
-        return redirect()->route('dashboard');
+        return redirect()->route('app.entry');
     }
 
     /**
@@ -183,7 +188,7 @@ private function completeStepUp(Request $request, string $provider, mixed $provi
         // (RequireRecentAuth の one-shot flag。password satisfier と同じ契約)。
         $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;
 
-        $redirect = redirect()->intended(route('dashboard'));
+        $redirect = redirect()->intended(route('app.entry'));
         if ($droppedMutation) {
             $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
         }
diff --git a/app/Http/Controllers/NotificationController.php b/app/Http/Controllers/NotificationController.php
index 3ccdf82e..7d888975 100644
--- a/app/Http/Controllers/NotificationController.php
+++ b/app/Http/Controllers/NotificationController.php
@@ -7,6 +7,7 @@
 use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
 use App\DataTransferObjects\Notification\NotificationListItemData;
 use App\Enums\Notification\NotificationType;
+use App\Models\Organization;
 use App\Models\User;
 use App\Services\Notification\NotificationCenterService;
 use App\Services\Organization\OrganizationMembershipService;
@@ -25,8 +26,10 @@
  *   解決する (cross-user は構造的に 404 = 存在オラクル封じ。403 で存在を漏らさない。
  *   1 param ルートのため NestedRouteIdorDefenseTest の inventory 対象外)
  * - open は POST + 303 (GET にしない = prefetch/リンクプレビューによる意図しない既読化防止)
+ * - 通知一覧は**組織 URL 配下**にある (家系裁定 AG-037)。一覧そのものは全 org 横断 (自分宛)
+ *   だが、遷移先の組み立てには URL 上の組織を使う (「いまどの組織か」を保持列から取らない)
  * - open は認可判断 (Gate) を一切複製しない。行うのは (a) 自通知の organization_id と
- *   current org の突合 (自分のデータ同士のルーティング判断) と (b) org→project→manual の
+ *   **URL 上の組織**の突合 (自分のデータ同士のルーティング判断) と (b) org→project→manual の
  *   relation 連鎖による存在解決のみ (「認可より前の 404」層の再利用。Gate::authorize は
  *   遷移先 projects.manuals.show が唯一の判断点)。(b) と遷移の間の TOCTOU
  *   (redirect 直後の削除) は遷移先の標準 404 が受ける (残余は許容)
@@ -39,7 +42,7 @@ public function __construct(
     ) {}
 
     /** 通知一覧 (全 org 横断 = 自分宛のみで構造的に閉じる) */
-    public function index(Request $request): Response
+    public function index(Request $request, Organization $organization): Response
     {
         $user = $this->authedUser($request);
         $paginator = $this->notifications->paginateFor($user);
@@ -73,7 +76,7 @@ public function index(Request $request): Response
     }
 
     /** 既読化 + 遷移先のサーバ解決 (POST + 303。開けない場合は一覧へ明示 redirect) */
-    public function open(Request $request, string $notification): RedirectResponse
+    public function open(Request $request, Organization $organization, string $notification): RedirectResponse
     {
         $user = $this->authedUser($request);
         $found = $this->notifications->findOwnOrFail($user, $notification); // cross-user 404
@@ -84,34 +87,45 @@ public function open(Request $request, string $notification): RedirectResponse
         // 遷移はすべて 303 (POST → GET の意味論を明示。Inertia の POST visit とも整合)
         return match (true) {
             // manual 系: 通知 org ≠ current org → 案内して一覧へ (自動 org 切替はしない = 驚き最小)
-            $item->isManualJob() && ! $this->belongsToCurrentOrg($user, $item) => redirect()
-                ->route('notifications.index', [], 303)
-                ->with('info', 'この通知は別の組織のものです。組織を切り替えてから開いてください。'),
-            // manual 系: current org → project → manual の relation 連鎖で現存する → manual 画面へ
-            $item->isManualJob() && $this->manualStillExists($user, $item) => redirect()
-                ->route('projects.manuals.show', [$item->projectId(), $item->manualId()], 303),
+            $item->isManualJob() && ! $this->belongsToCurrentOrg($organization, $item) => redirect()
+                ->route('notifications.index', ['organization' => $organization->slug], 303)
+                ->with('info', 'この通知は別の組織のものです。その組織の画面から開いてください。'),
+            // manual 系: URL 上の組織 → project → manual の relation 連鎖で現存する → manual 画面へ
+            $item->isManualJob() && $this->manualStillExists($organization, $item) => redirect()
+                ->route('projects.manuals.show', [
+                    'organization' => $organization->slug,
+                    'project' => $item->projectId(),
+                    'manual' => $item->manualId(),
+                ], 303),
             $item->isManualJob() => redirect()
-                ->route('notifications.index', [], 303)
+                ->route('notifications.index', ['organization' => $organization->slug], 303)
                 ->with('info', '対象の動画マニュアルは削除されています。'),
             $item->type === NotificationType::TicketBalanceLow => redirect()
-                ->route('billing.tickets.show', [], 303),
+                ->route('billing.tickets.show', ['organization' => $organization->slug], 303),
             // 招待通知: 受諾可能な一覧が出る通知センターへ戻す。
             // ★通知 payload は招待 id を持たないため「この招待」を特定できない。
             //   したがって flash は**集合表現**にする (件数 0 のときだけ説明を出す)。
             //   件数は受諾の解決と同一 scope から算出する。
             $item->type === NotificationType::InvitationReceived => $this->membership->pendingInvitationCountFor($user) > 0
-                ? redirect()->route('notifications.index', [], 303)
-                : redirect()->route('notifications.index', [], 303)
+                ? redirect()->route('notifications.index', ['organization' => $organization->slug], 303)
+                : redirect()->route('notifications.index', ['organization' => $organization->slug], 303)
                     ->with('info', '現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。'),
             // 未知 type (enum⇔DB ドリフト時の防御): 既読化のみ・汎用文言
             default => redirect()
-                ->route('notifications.index', [], 303)
+                ->route('notifications.index', ['organization' => $organization->slug], 303)
                 ->with('info', 'この通知には開ける対象がありません。'),
         };
     }
 
-    /** 1 件既読化 (back() 完結) */
-    public function read(Request $request, string $notification): RedirectResponse
+    /**
+     * 1 件既読化 (back() 完結)。
+     *
+     * ★`$organization` は**使わないが受ける**。framework は route parameter を
+     *   **位置で**引数へ割り当てるため、組織 URL 配下の action が `{organization}` を
+     *   受けないと後続の引数が 1 つずつずれる (実測: `$notification` に Organization が入り、
+     *   通知が見つからず 404 になる)。既読化は通知 id が権威なので組織で絞らない。
+     */
+    public function read(Request $request, Organization $organization, string $notification): RedirectResponse
     {
         $user = $this->authedUser($request);
         $this->notifications->markRead($this->notifications->findOwnOrFail($user, $notification));
@@ -119,8 +133,13 @@ public function read(Request $request, string $notification): RedirectResponse
         return back();
     }
 
-    /** 一括既読化 (back() 完結) */
-    public function readAll(Request $request): RedirectResponse
+    /**
+     * 一括既読化 (back() 完結)。
+     *
+     * ★`$organization` を受ける理由は `read()` と同じ (位置ずれの防止)。
+     *   一括既読は利用者の全組織横断であり、URL 上の組織では絞らない。
+     */
+    public function readAll(Request $request, Organization $organization): RedirectResponse
     {
         $this->notifications->markAllRead($this->authedUser($request));
 
@@ -136,24 +155,19 @@ private function authedUser(Request $request): User
         return $user;
     }
 
-    /** 通知の org 文脈 (organization_id 列) が current org と一致するか (認可判断ではない) */
-    private function belongsToCurrentOrg(User $user, NotificationListItemData $item): bool
+    /** 通知の org 文脈 (organization_id 列) が **URL 上の組織**と一致するか (認可判断ではない) */
+    private function belongsToCurrentOrg(Organization $organization, NotificationListItemData $item): bool
     {
         return $item->organizationId !== null
-            && $item->organizationId === $user->current_organization_id;
+            && $item->organizationId === $organization->getKey();
     }
 
     /**
-     * current org → projects() → manuals の relation 連鎖による存在解決 (exists() 1 クエリ。
+     * URL 上の組織 → projects() → manuals の relation 連鎖による存在解決 (exists() 1 クエリ。
      * 認可判断なし = 「認可より前の 404」層の再利用)。
      */
-    private function manualStillExists(User $user, NotificationListItemData $item): bool
+    private function manualStillExists(Organization $organization, NotificationListItemData $item): bool
     {
-        $organization = $user->currentOrganization;
-        if ($organization === null) {
-            return false;
-        }
-
         return $organization->projects()
             ->whereKey($item->projectId())
             ->whereHas('manuals', fn (Builder $query): Builder => $query->whereKey($item->manualId()))
diff --git a/app/Http/Middleware/RequireActiveSubscription.php b/app/Http/Middleware/RequireActiveSubscription.php
index 0eacacd6..b11686a4 100644
--- a/app/Http/Middleware/RequireActiveSubscription.php
+++ b/app/Http/Middleware/RequireActiveSubscription.php
@@ -15,6 +15,7 @@
 use Illuminate\Support\Facades\Gate;
 use Illuminate\Support\Facades\Log;
 use Symfony\Component\HttpFoundation\Response;
+use Webmozart\Assert\Assert;
 
 /**
  * 課金ゲート: BillingAccess の entitlement 判定で不許可 (= 未契約、または有償プラン契約中の
@@ -33,13 +34,13 @@
  *   到達できることを保証する = 「契約するための画面が契約してないと見られない」詰みの防止)
  *
  * 対象 organization の解決:
- *   1. route に `{organization}` binding があればそれを使う。その際、非メンバー /
- *      不在 org は **認可より前に 404** (テナント存在秘匿)。通常は
- *      MembershipScopedOrganizationBinder が 404 済みのため到達しない
- *      defense-in-depth (binder 回帰時の最終防波堤。403 を返すと存在が漏れる)
- *   2. binding が無い current org スコープ route (/projects 等) は
- *      user の currentOrganization を使う。未設定は controller 側の
- *      ResolvesCurrentOrganization が 404 に倒すため素通しする
+ *   route の `{organization}` binding **だけ**を使う (家系裁定 AG-037: 組織文脈は URL だけで
+ *   決まる。保持列は撤去済み)。非メンバー / 不在 org は **認可より前に 404**
+ *   (テナント存在秘匿)。通常は MembershipScopedOrganizationBinder が 404 済みのため到達しない
+ *   defense-in-depth (binder 回帰時の最終防波堤。403 を返すと存在が漏れる)。
+ *   **binding が無ければ fail-closed (500)** — 課金ゲート配下に組織を持たない route が
+ *   紛れ込んだら黙って通さない。「課金ゲート配下の全 route は組織引数を持つ」は
+ *   BillingGateRouteOrganizationParamTest が固定する。
  */
 final class RequireActiveSubscription
 {
@@ -71,9 +72,6 @@ public function handle(Request $request, Closure $next): Response
         }
 
         $organization = $this->resolveOrganization($request, $user);
-        if ($organization === null) {
-            return $next($request);
-        }
 
         $state = $this->access->state($organization);
         if ($state->grantsAccess()) {
@@ -106,34 +104,39 @@ public function handle(Request $request, Closure $next): Response
         FlashNotificationRelay::relayTo($request->session());
 
         // 遮断理由は着地ページが持つ (middleware は error flash を積まない)。
+        // ★着地先も組織 URL 配下なので、**識別名を明示して**組み立てる (家系裁定 AG-037)。
+        //   モデルをそのまま渡すと getRouteKeyName() = 'id' により URL に id が入る。
         return redirect()->route(
             $canManage
                 ? 'onboarding.checkout'          // 自分で契約できる = プラン選択へ
                 : 'onboarding.billing-required', // 契約権限なし = 説明画面へ
+            ['organization' => $organization->slug],
         );
     }
 
     /**
-     * gate 対象の Organization を解決する。route binding 優先、無ければ current org。
+     * gate 対象の Organization を解決する。**URL の binding だけ**が出所である。
+     * binding が無い = 配線ミスなので fail-closed (500)。
      */
-    private function resolveOrganization(Request $request, User $user): ?Organization
+    private function resolveOrganization(Request $request, User $user): Organization
     {
         $organization = $request->route('organization');
-        if ($organization instanceof Organization) {
-            // defense-in-depth: 非メンバーがここに到達する = binder の回帰。
-            // 存在秘匿方針に合わせ 404 で abort し、観測可能化して即検知する
-            if (! Gate::forUser($user)->allows('view', $organization)) {
-                Log::warning('non-member passed organization binder (binder regression?)', [
-                    'organization_id' => $organization->id,
-                    'user_id' => $user->id,
-                    'route' => $request->route()?->getName(),
-                ]);
-                abort(404);
-            }
-
-            return $organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        // defense-in-depth: 非メンバーがここに到達する = binder の回帰。
+        // 存在秘匿方針に合わせ 404 で abort し、観測可能化して即検知する。
+        // ★判定は **binder と同じ契約 (organization_user の所属)** で行う。役割の有無で
+        //   判定すると、所属はあるが役割が無い利用者 (並行受諾レースの帰結) の 403 が
+        //   404 に化けて層 2 と層 3 の境目が消える (拒否の理由が読めなくなる)。
+        if (! $organization->users()->whereKey($user->getKey())->exists()) {
+            Log::warning('non-member passed organization binder (binder regression?)', [
+                'organization_id' => $organization->id,
+                'user_id' => $user->id,
+                'route' => $request->route()?->getName(),
+            ]);
+            abort(404);
         }
 
-        return $user->currentOrganization;
+        return $organization;
     }
 }
diff --git a/app/Http/Responses/Fortify/RegisterResponse.php b/app/Http/Responses/Fortify/RegisterResponse.php
index 37e8ab71..cb8a6c24 100644
--- a/app/Http/Responses/Fortify/RegisterResponse.php
+++ b/app/Http/Responses/Fortify/RegisterResponse.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Responses\Fortify;
 
+use App\Enums\OrganizationRole;
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Onboarding\IntendedPlanResolver;
@@ -23,10 +24,10 @@
  * verification.notice (「認証してください」画面) へ直接誘導する。
  * XHR(201) は Fortify 標準と同じ後方互換を維持する。
  *
- * P7 の追加責務 (session 副作用のみ。個人組織生成は CreateNewUser の tx 内で完結済み):
- *   - 通常登録: pending のプラン意図を個人組織へ promote し、verify ソフトゲートの
- *     継続導線 (EmailVerificationContinuation) に個人組織 id を保持する。
- *   - 招待受諾成立 (= 個人組織が存在しない): 料金表由来の pending を forget し、
+ * P7 の追加責務 (session 副作用のみ。初期組織の生成は CreateNewUser の tx 内で完結済み):
+ *   - 通常登録: pending のプラン意図を**自分が Owner の初期組織**へ promote し、
+ *     verify ソフトゲートの継続導線 (EmailVerificationContinuation) にその組織 id を保持する。
+ *   - 招待受諾成立 (= Owner の組織を持たない): 料金表由来の pending を forget し、
  *     継続導線も張らない (招待組織へ参加するだけのユーザーに契約導線を出さない)。
  * session 副作用は XHR (201) 経路でも同じく先に実行してから応答を返す。
  */
@@ -44,15 +45,20 @@ public function toResponse($request): JsonResponse|RedirectResponse
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
-        // 招待受諾は CreateNewUser の tx 内で完了しており、成立時は個人組織を作らない。
-        // 「個人組織の有無」が招待経由かどうかの唯一の判定軸 (?-> で握り潰さず分岐を明示する)。
-        $personalOrganization = $user->organizations()->where('is_personal', true)->first();
+        // 招待受諾は CreateNewUser の tx 内で完了しており、成立時は初期組織を作らない。
+        // ★種別フラグ (旧 `is_personal`) は撤去済み (家系裁定 AG-038) なので、
+        //   「所属組織の有無」では判定できない — 招待経由の利用者も所属組織を 1 件持つ。
+        //   判定軸は **その利用者が Owner の組織かどうか**である。初期組織は必ず本人が Owner で、
+        //   招待は Owner を与えないため、料金表由来のプラン意図を他人の組織へ移送してしまう
+        //   経路が構造的に消える。
+        $initialOrganization = $user->organizations()->orderBy('organizations.id')->get()
+            ->first(static fn (Organization $organization): bool => $user->organizationRole($organization) === OrganizationRole::Owner);
 
-        if ($personalOrganization instanceof Organization) {
+        if ($initialOrganization instanceof Organization) {
             // pending → org-scoped へ移送 (pending は必ず forget で消費される)。
-            $this->intendedPlanResolver->promotePendingToOrganization($personalOrganization);
+            $this->intendedPlanResolver->promotePendingToOrganization($initialOrganization);
             // 生 URL ではなく組織 id のみ保持する (参照時に membership 確認 + route 再構築)。
-            EmailVerificationContinuation::remember($request->session(), $personalOrganization->id);
+            EmailVerificationContinuation::remember($request->session(), $initialOrganization->id);
         } else {
             // 招待経由: 料金表由来の pending が残っていても消費しない (stale 防止)。
             $this->intendedPlanResolver->forgetPending();
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 47cb5e76..63fd0f7b 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -49,6 +49,7 @@
 use App\Support\QueueDispatchAtomicityGuard;
 use Aws\Sns\SnsClient;
 use Illuminate\Auth\Events\Login;
+use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
 use Illuminate\Cache\RateLimiting\Limit;
 use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Contracts\Hashing\Hasher;
@@ -222,6 +223,13 @@ public function boot(): void
         // tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest が適用境界を pin)
         Route::bind('organization', MembershipScopedOrganizationBinder::class);
 
+        // 認証済みで guest 専用 route (ログイン / パスワード再設定要求 等) を開いたときの着地。
+        // ★framework の既定は「`dashboard` という名前の route があればそこへ」だが、
+        //   本アプリの `dashboard` は組織 URL 配下 (`{organization}` 必須) なので、
+        //   既定のままだと引数不足で 500 になる。組織文脈を持たない**分岐入口**へ倒す
+        //   (家系裁定 AG-037: どの組織かを裏口で決めない)。
+        RedirectIfAuthenticated::redirectUsing(static fn (): string => route('app.entry'));
+
         // route binding 型制約 (RouteBindingTypes が単一 SoT)。
         // 非適合セグメントは route にマッチしない = 404 になり、SubstituteBindings へ
         // 到達しないため pgsql 22P02 / 22003 (→ 生 500) が構造的に起きない。
@@ -434,11 +442,19 @@ private function configureRenderRateLimiter(): void
         RateLimiter::for('render-trigger', function (Request $request): Limit {
             $user = $request->user();
             $userId = $user instanceof User ? (string) $user->id : 'guest';
-            $orgId = $user instanceof User && $user->current_organization_id !== null
-                ? (string) $user->current_organization_id
-                : 'none';
-
-            return Limit::perMinute(6)->by("render-trigger:actor-org:{$userId}:{$orgId}");
+            // ★組織は **URL の route parameter からのみ**取る (家系裁定 AG-037)。'none' へ倒さない —
+            //   配線不良を黙って許すと、レーン全体が 1 つの bucket に潰れる。
+            //   「render-trigger 対象 route は必ず organization parameter を持つ」は
+            //   RenderTriggerRouteOrganizationParamTest が固定する。
+            // ★ここで得られるのは **識別名の文字列**である。流量制限は framework の既定 priority で
+            //   `SubstituteBindings` より前に走るため、モデルはまだ束縛されていない
+            //   (束縛の後ろへ動かすと、束縛の DB 参照が流量制限の外に出てしまう)。
+            //   識別名は改名で変わりうるが、改名は 30 日 5 回が上限で窓は 1 分なので、
+            //   改名の前後で bucket が分かれても上限の意味は保たれる。
+            $organizationSlug = $request->route('organization');
+            Assert::stringNotEmpty($organizationSlug);
+
+            return Limit::perMinute(6)->by("render-trigger:actor-org:{$userId}:{$organizationSlug}");
         });
     }
 
diff --git a/resources/js/components/features/manual/TakePickerList.svelte b/resources/js/components/features/manual/TakePickerList.svelte
index f2c18452..a1ff0e32 100644
--- a/resources/js/components/features/manual/TakePickerList.svelte
+++ b/resources/js/components/features/manual/TakePickerList.svelte
@@ -6,6 +6,7 @@
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { captureJson, extractErrorMessage } from "@/lib/capture/http";
     import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
+    import { currentOrganizationSlug } from "@/lib/org-url";
     import { formatBytes } from "@/lib/format-bytes";
     import { TAKE_STATUS_LABELS, type SelectableTake } from "@/types/manual";
 
@@ -49,7 +50,7 @@
 
     function thumbnailUrl(take: SelectableTake): string | null {
         return take.has_thumbnail
-            ? buildTakeUrl({ projectId, manualId, cutId }, take.id, "/thumbnail")
+            ? buildTakeUrl({ organizationSlug: currentOrganizationSlug(), projectId, manualId, cutId }, take.id, "/thumbnail")
             : null;
     }
 
@@ -67,7 +68,7 @@
         busyTakeId = id;
         try {
             const response = await captureJson(
-                buildTakeUrl({ projectId, manualId, cutId }, id),
+                buildTakeUrl({ organizationSlug: currentOrganizationSlug(), projectId, manualId, cutId }, id),
                 "DELETE",
             );
             if (!response.ok) {
diff --git a/resources/js/components/features/manual/TakePreviewPanel.svelte b/resources/js/components/features/manual/TakePreviewPanel.svelte
index 714a3fdf..c3b33ab9 100644
--- a/resources/js/components/features/manual/TakePreviewPanel.svelte
+++ b/resources/js/components/features/manual/TakePreviewPanel.svelte
@@ -7,6 +7,7 @@
     import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
     import { captureJson, extractErrorMessage } from "@/lib/capture/http";
     import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
+    import { currentOrganizationSlug } from "@/lib/org-url";
     import {
         TAKE_ADOPTABLE_BY_STATUS,
         TAKE_STATUS_LABELS,
@@ -60,7 +61,8 @@
     // (無駄な要素とネットワーク要求を出さない)
     const playbackUrl = $derived(
         take !== null && take.status === "ready"
-            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/playback")
+            ? buildTakeUrl(
+                { organizationSlug: currentOrganizationSlug(), projectId, manualId, cutId: cut.id }, take.id, "/playback")
             : null,
     );
 
@@ -68,7 +70,8 @@
 
     const thumbnailUrl = $derived(
         take !== null && take.has_thumbnail
-            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/thumbnail")
+            ? buildTakeUrl(
+                { organizationSlug: currentOrganizationSlug(), projectId, manualId, cutId: cut.id }, take.id, "/thumbnail")
             : null,
     );
 
@@ -94,7 +97,8 @@
         busy = true;
         try {
             const response = await captureJson(
-                buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/adopt"),
+                buildTakeUrl(
+                { organizationSlug: currentOrganizationSlug(), projectId, manualId, cutId: cut.id }, take.id, "/adopt"),
                 "POST",
             );
             if (!response.ok) {
```

## 6. 検証結果

- `composer test`: 6833 tests / 6831 passed / 0 failed (exit 0)
- `composer phpstan` (level 10, 1050 files): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: green
- `pnpm test`: 178 files / 2393 tests passed
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 tests): green
- 旧 URL 走査: 違反 0 件 / 未分類 0 件 / 未解決 0 件 (許可目録は 7 件、いずれも件数完全一致)

## 7. 特に見てほしい点

1. `LegacyUrlScanner` の「根の位置」判定 (規則 1〜3) に**見逃し**はないか。
   とくに規則 3 (`orgUrl(...)` / `currentOrgUrl(...)` の引数を新 URL とみなす) は
   抜け道になっていないか。
2. `/app` を「配下つきのときだけ旧 URL」とした判断 (実装ノート 2) は妥当か。
   設計は許可目録を想定していたが、規則で表した。
3. 許可目録 (`LegacyUrlAllowance`) の区分 3 つは「なんとなく直せない」を入れる口に
   なっていないか。とくに `OrganizationRelativePath` の 2 件。
4. `SourceLiterals` の PHP 側で補間文字列を `{$}` の置換子へ畳んでいる。
   この畳み方で**見逃す**形はないか。
5. `LegacyUrlScanRoots` の 4 分類で、`routes/` を走査対象から外した判断 (実装ノート 4) は
   穴になっていないか。
