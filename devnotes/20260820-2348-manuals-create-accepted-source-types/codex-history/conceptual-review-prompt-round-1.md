【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト（既存実装の事実。すべて実読で確認済み）】

- `app/Support/Manual/AcceptedSourceDocumentTypes.php` は `extensions()` / `mimes()` /
  `acceptAttribute()` / `imagesEnabled()` を持つ final クラス。`config('manual.source_document_mimes')`
  (= `['pdf','xlsx','xls','txt']`) と `config('manual.ocr_analysis_enabled')`
  (既定 false、env `MANUAL_OCR_ANALYSIS_ENABLED`) を合成する。
- `VideoManualController::show()` は既に
  `'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute()` と
  `'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled()` を props に渡す。
  `create()` はどちらも渡していない。
- `resources/js/pages/Manuals/Create.svelte` の file input は
  `accept=".pdf,.xlsx,.xls,.txt"` を直書き、FormField の help は
  `"PDF / Excel / テキスト。アップロードすると AI 解析でシナリオを生成できます。"` を直書き。
  外部送信の案内は 1 文も無い。
- `resources/js/components/features/manual/SourceDocumentUpload.svelte` は
  `accept={sourceDocumentAccept}` を使い、`data-testid="source-document-send-notice"` の
  一般案内 (常時) と `data-testid="source-document-image-notice"` の OCR 固有警告
  (`imageSourceDocumentsEnabled` が true のときだけ) を出す。
- `StoreVideoManualRequest` / `StoreSourceDocumentRequest` はどちらも
  `AcceptedSourceDocumentTypes::extensions()` で mimes ルールを組み、
  `messages()` で `imagesEnabled()` の三項演算子により
  `'PDF・Excel・テキスト形式、または JPEG・PNG の画像'` / `'PDF・Excel・テキスト形式'` を
  **同じ形で 2 ファイルに直書き**している。
- `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` に
  「公開面の一貫性: FormRequest / Service / Inertia Props がフラグに応じて同じ集合を表す」
  という既存テストがあり、Inertia Props の検証対象は `projects.manuals.show` だけである。
- `tests/js/pages/ManualsCreate.test.ts` は
  `expect(input.getAttribute("accept")).toBe(".pdf,.xlsx,.xls,.txt")` を
  フラグと無関係に pin している。
- `resources/js` 配下の `type="file"` は 4 箇所:
  `features/manual/SourceDocumentUpload.svelte` (accept は props 由来)、
  `pages/Manuals/Create.svelte` (直書き)、
  `features/capture/CaptureFileFallback.svelte` (`isStill ? "image/*" : "video/*"`)、
  `features/manual/TakeFileUpload.svelte` (同じ三項演算子)。
- component の import 方向契約 (`tests/js/architecture/atomic-import-graph.test.ts`) は
  `features/{domain}` 内の同 domain 参照を許可し、pages → features も許可する。
- 静的検査を新設する場合の規約は AGENTS.md 「静的検査 (gate) と走査器の共通規約」
  ((a) 完全修飾名で突合 / (b) 解決できない形は fail-closed / (c) 負例で裏取り /
  (d) 集めた結果を判定に使う / (e) 語彙一致はトークン完全一致) と
  「同じ PR で揃える 4 点」(負例と正例・fail-closed 分岐・母集団が空でない検査・docblock)。

---

## 概念設計

<!-- 以下は devnotes/20260820-2348-manuals-create-accepted-source-types/conceptual-design.md の全文 -->
# 概念設計: 新規動画作成画面の SOP ファイル入力を受理形式の単一情報源へ揃える

## この設計の出所 (判断の記録)

- 2026-08-20 の要件カバレッジ監査で、**記録の無いギャップ**として発見された。
- aicue の T234 (画像・スキャン SOP の OCR 対応、実装済み) は
  `devnotes/20260819-1053-sop-image-ocr-support/detailed-design.md` の**施策 10** で
  UI の変更対象を
  `resources/js/components/features/manual/SourceDocumentUpload.svelte` と
  `resources/js/pages/Manuals/Show.svelte` の 2 つに**明示的に限定**していた。
  当時この限定に理由は書かれておらず、レビューでも指摘されていない。
  **新規動画作成画面 `resources/js/pages/Manuals/Create.svelte` も SOP を受け取る面**で
  あることが見落とされていた (作成と同時に SOP を添付できる導線が既にある)。
- したがって本設計は新機能ではなく、**T234 の適用漏れの回収**である。
  T234 で確定した方針 (受理形式の単一の情報源 = `AcceptedSourceDocumentTypes`、
  一般案内は常時表示 / OCR 固有警告はフラグ true のときだけ) は**変更しない**。

## 背景・課題

現状、SOP (手順書) の受理形式は `App\Support\Manual\AcceptedSourceDocumentTypes` が
唯一の情報源で、`manual.ocr_analysis_enabled` フラグに連動して画像 (jpg/jpeg/png) を
含む・含まないが切り替わる。サーバ側の 3 経路はすべてここを経由している:

| 経路 | 単一の情報源を経由しているか |
|---|---|
| `StoreSourceDocumentRequest` (後付けアップロード) | 経由済み |
| `StoreVideoManualRequest` (**作成と同時のアップロード**) | 経由済み |
| `SourceDocumentService::allowedMimeTypes` (内容 sniff) | 経由済み |
| `Manuals/Show` の Inertia props | 経由済み (`VideoManualController::show()` L184-187) |
| **`Manuals/Create` の Inertia props** | **未経由 (props 自体が無い)** |

帰結として、`MANUAL_OCR_ANALYSIS_ENABLED=true` にした環境では次の不整合が起きる:

1. **主導線から画像 SOP を選べない**。`Create.svelte` の file input は
   `accept=".pdf,.xlsx,.xls,.txt"` を直書きしているため、OS のファイル選択ダイアログで
   画像ファイルが**表示されない**。サーバ (`StoreVideoManualRequest`) は受理するのに、
   利用者は選ぶ手段を持たない。「新しい動画マニュアルを作る」→「手元の写真の手順書を
   添付する」という**最も自然な初回導線が塞がっている**。
   後付けアップロード (詳細画面) を知っている利用者だけが迂回できる = 導線の知識に
   依存した非対称。
2. **受理形式の案内が事実と食い違う**。help 文言が「PDF / Excel / テキスト。」と
   直書きされており、フラグ true でも画像に言及しない。
   同じ画面の 422 応答は `StoreVideoManualRequest::messages()` により
   「PDF・Excel・テキスト形式、**または JPEG・PNG の画像**で…」と返るため、
   **入力前の案内と入力後のエラー文言が矛盾する**。
3. **外部送信のリスク開示が無い**。詳細画面の `SourceDocumentUpload.svelte` は
   一般案内 (「AI 解析のためファイル内容が外部の LLM provider に送信されます」) と
   OCR 固有警告 (「画像やスキャン PDF では紙面の見た目がそのまま送信されます」) を
   出しているが、**作成画面には一般案内すら無い**。
   T234 が「法務確認の対象」とした開示が、SOP を受け取れるもう一方の面では欠けている。
4. **検査が不整合を検出できない構造になっている**。
   `tests/js/pages/ManualsCreate.test.ts` はハードコードされた accept 値を
   フラグと無関係に pin しているため、フラグを true にしても赤くならない。
   props を渡していないこと自体を見張る検査も無い。

使命 (「現場に既にある作業手順書(SOP)を起点に」) から見ると、(1) は起点の入口を
狭めている。紙・写真しか無い現場は、OCR 機能が有効な環境であっても
新規作成の主導線では使い始められない。

## 改善アイデア

**「受理形式を宣言する面」を漏れなく単一の情報源へ接続し、同じ漏れが二度起きない
形にする。** 4 つに分ける。

### (A) 作成画面の props を詳細画面と同一にする

`VideoManualController::create()` の Inertia props へ
`sourceDocumentAccept` (accept 属性文字列) と
`imageSourceDocumentsEnabled` (画像対応可否の真偽値) を追加する。
値は `AcceptedSourceDocumentTypes` から取る (show() と同一の 2 行)。

**フロント側で accept 文字列を解析して画像対応可否を判定しない**という T234 の
役割分担 (accept は属性専用・案内表示は専用の真偽値) をそのまま踏襲する。

### (B) 作成画面の直書きを props 由来に差し替え、文言は T234 の承認済みを再利用する

- `accept=".pdf,.xlsx,.xls,.txt"` → `accept={sourceDocumentAccept}`
- 外部送信の一般案内 (常時表示) と OCR 固有警告 (フラグ true のみ) を表示する。
  **文言は新規に作らない**。T234 で法務確認の対象として確定し `SourceDocumentUpload.svelte`
  に実装済みの文言を再利用する。
- ただし**同じ文言を 2 つの Svelte ファイルへ複写すると、法務確認済みの文が
  片方だけ更新される事故が起きうる**。複写ではなく、案内文言を
  `resources/js/components/features/manual/` 配下の小さな共有コンポーネント 1 つへ
  切り出し、**作成画面と詳細画面の両方がそれを使う**形にする
  (atomic design の階層は features/manual 同 domain 内の参照 + pages → features なので
  既存の import 方向契約に適合する)。
  文言の物理的な出現箇所を 1 つに保つことが目的であり、機能追加ではない。

### (C) 受理形式の人間向けラベルも単一の情報源へ寄せる

現在「PDF・Excel・テキスト形式 / …または JPEG・PNG の画像」という句は
`StoreSourceDocumentRequest` と `StoreVideoManualRequest` の 2 ファイルに
**同じ条件分岐ごと直書きされている**。作成画面の help 文言でも同じ句が要るため、
このまま進めると 3 箇所目の複写になる。
`AcceptedSourceDocumentTypes` に受理形式のラベルを返す 1 メソッドを足し、
2 つの FormRequest と作成画面の props がそこを経由する形にする
(拡張子リスト・sniff MIME・accept 属性・**人間向けラベル**の 4 つが同じクラスから出る)。

### (D) 再発防止: accept の供給元を目録で宣言させる

今回の漏れは「新しい面を足したときに単一の情報源へ繋ぐのを忘れても、誰も赤くならない」
ために起きた。同じ形の漏れを止めるため、
`resources/js` 配下の `type="file"` を持つ入力を**全数走査**し、
`accept` の供給元を目録へ宣言させる deny-by-default の gate を 1 本置く
(区分は「サーバ props 由来 (SOP)」と「クライアント側の固定値 + 理由」の 2 つ。
現在の母集団は 4 件 = SOP 2 件 + 撮影 2 件で、撮影の 2 件は `image/*` / `video/*` の
固定値が正しいため理由付きで後者に載る)。
新しいアップロード面を足すと**登録するまで赤くなる**ので、単一の情報源へ繋ぐ判断が
必ずレビューに見える。

> **コストの見積り**: gate は vitest 1 ファイル。静的検査の共通規約 (AGENTS.md
> 「静的検査 (gate) と走査器の共通規約」) の (b) 解決できない形は落とす /
> (c) 負例で裏取り / (d) 集めた結果を判定に使う / (e) 語彙一致はトークン完全一致、
> および「母集団が空でないことの検査」を満たす必要がある。
> **これを満たせない安直な実装 (accept の文字列 grep だけ) は作らない** —
> 作るなら規約どおり作り、割に合わないと判断するなら作らない、の二択にする。
> 本設計は「作る」を選ぶ。理由は、今回の漏れが**検出できないまま本番フラグを
> 立てられる状態**であり、同種の面 (撮影 PWA 側のアップロード) が既に 2 件あって
> 今後も増えるため。

## 期待効果

- **使命への貢献**: OCR を有効化した環境で、新規動画マニュアル作成の主導線から
  画像・スキャン SOP を投入できるようになる (「現場に既にある手順書を起点に」の
  入口が、後付けアップロードを知らない初回利用者にも開く)。
- **不整合の解消**: 入力前の案内 (help・accept) と入力後のエラー文言が、
  フラグの真偽にかかわらず同じ集合を指す。
- **リスク開示の一貫**: 外部 LLM provider への送信という事実が、SOP を受け取る
  どちらの面でも同じ文言で開示される (文言の出現箇所は 1 つ)。
- **再発防止**: 受理形式を宣言する面が増えたときに、単一の情報源へ繋がないと
  検査が赤くなる。

## 実装方針（概要）

| # | 変更対象 | 内容 |
|---|---|---|
| A | `app/Http/Controllers/Projects/VideoManualController.php` (`create()`) | props 2 件 (+ (C) のラベル 1 件) を追加 |
| B | `resources/js/pages/Manuals/Create.svelte` | Props 型追加・accept を props 化・案内コンポーネント設置・help をラベル props 化 |
| B | `resources/js/components/features/manual/` (新規 1 ファイル) | 外部送信の一般案内 + OCR 固有警告の共有コンポーネント |
| B | `resources/js/components/features/manual/SourceDocumentUpload.svelte` | 直書きの案内 2 段落を共有コンポーネント呼び出しへ置換 (文言・testid は不変) |
| C | `app/Support/Manual/AcceptedSourceDocumentTypes.php` | 受理形式ラベルのメソッドを追加 |
| C | `app/Http/Requests/Projects/StoreSourceDocumentRequest.php` / `StoreVideoManualRequest.php` | 直書きの条件分岐をラベルメソッド呼び出しへ置換 (文言は不変) |
| D | `tests/js/architecture/` (新規 1 ファイル) | file input の accept 供給元目録 (deny-by-default) |
| test | `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` | 既存の「公開面の一貫性」テストの公開面に**作成画面**を追加 |
| test | `tests/js/pages/ManualsCreate.test.ts` | 直書き pin をやめ、両フラグ (props の 2 状態) で accept と案内の有無を固定 |
| test | `tests/js/components/` (該当があれば) | 共有コンポーネント化後も詳細画面側の文言・testid が不変であることを確認 |

## 制約・前提

- **フラグの既定は false のまま**。本設計は既定挙動 (画像を受理しない) を一切変えない。
  `.env.example` / config の値も変更しない。
- **サーバ側の受理判定を変えない**。`StoreVideoManualRequest` は既に単一の情報源を
  経由しており、本設計はフロントと案内文言を追随させるだけである。
  したがって**セキュリティ境界の変更は無い** (accept 属性は UX のフィルタであって
  検証ではない。検証は FormRequest + 内容 sniff の二段構えが担う)。
- **法務確認済み文言を書き換えない**。再利用のみ (T234 施策 10 / 11 の rollout dependency)。
- Inertia props の追加なので TypeScript の Props インターフェースも同時に直す
  (波及変更として施策に明示する)。
- 静的検査を足す場合は AGENTS.md の「静的検査 (gate) と走査器の共通規約」5 条と
  「新設・変更するときに同じ PR で揃える 4 点」に従う (テストファースト = 先に赤くする)。

## スコープ外

- **OCR そのものの挙動・品質**、画像の容量上限、1 手順書 1 枚制約 (T234 で実装済み)。
- **画像対応の既定有効化** (フラグ運用の判断は T234 施策 11 の rollout の話であり、
  本設計は「有効化したときに UI が正しく振る舞う」ことだけを扱う)。
- **詳細画面 (`Manuals/Show`) の UI 仕様変更**。共有コンポーネント化に伴う
  markup の内部移動だけを行い、表示文言・testid・並び順は現状のまま維持する。
- **撮影 PWA のアップロード面 (`CaptureFileFallback` / `TakeFileUpload`) の accept 変更**。
  こちらは動画・静止画テイクの入力で、SOP の受理形式とは別概念である
  (「別物の概念を『似ているから』で統合しない」)。(D) の目録には
  「クライアント側の固定値」区分で理由付きで載せるだけで、値は変えない。
- ドラッグ & ドロップ投入、複数ファイル同時投入といった入力方式の追加。
