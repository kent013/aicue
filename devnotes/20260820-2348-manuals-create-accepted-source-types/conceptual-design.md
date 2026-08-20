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

案内 2 段落は**file input の上**に置く (詳細画面と同じ並び)。
ファイルを選ぶ前に外部送信の事実が見えていなければ開示の意味が無い。

### (C) 受理形式の人間向けラベルも単一の情報源へ寄せる

現在「PDF・Excel・テキスト形式 / …または JPEG・PNG の画像」という句は
`StoreSourceDocumentRequest` と `StoreVideoManualRequest` の 2 ファイルに
**同じ条件分岐ごと直書きされている**。作成画面の help 文言でも同じ句が要るため、
このまま進めると 3 箇所目の複写になる。
`AcceptedSourceDocumentTypes` に受理形式のラベルを返す 1 メソッドを足し、
2 つの FormRequest と作成画面の props がそこを経由する形にする
(拡張子リスト・sniff MIME・accept 属性・**人間向けラベル**の 4 つが同じクラスから出る)。

**ラベルは機械導出しない** (conceptual-review Round 1 Warning 対応)。
拡張子リストから日本語の文 (「…形式、または JPEG・PNG の画像」) を組み立てる形にすると、
**法務確認済みの文面をコードが生成する**ことになり、config を触った副作用で
文面が変わりうる。承認済みの 2 文をそのままクラスへ置き、
乖離は**前提の pin** で検出する:
基底の拡張子集合 (`config('manual.source_document_mimes')`) と画像拡張子集合が
現在値ちょうどであることを検査し、config を変えたらラベルの見直しが
必ずレビューに乗るようにする (既存の `*PremiseTest` と同じ形)。

### (D) 再発防止: accept の供給元を目録で宣言させる (責務は「分類漏れの検出」のみ)

今回の漏れは「新しい面を足したときに単一の情報源へ繋ぐのを忘れても、誰も赤くならない」
ために起きた。同じ形の漏れを止めるため、`resources/js` 配下の **native `input` 要素を
全数走査**し、ファイル入力の `accept` の供給元区分を目録へ宣言させる deny-by-default の
gate を 1 本置く。区分は 2 つ:

- **動的値** (値は実行時に決まる。SOP は Inertia props で供給する意図。
  gate はこの区分において**由来を検証しない** — 名前が「サーバ props 由来」だと
  gate が由来を確かめているように読めるため、区分名は検証済みの事実
  (「静的に確定できない値である」) の側に合わせる)
- **クライアント側の固定値** (+ 30 文字以上の理由)

現在の母集団は 4 件 = SOP 2 件 (`SourceDocumentUpload` / `Manuals/Create`) +
撮影 2 件 (`CaptureFileFallback` / `TakeFileUpload`)。撮影の 2 件は `image/*` / `video/*`
の固定値が正しいため理由付きで後者に載る。
新しいアップロード面を足すと**登録するまで赤くなる**ので、単一の情報源へ繋ぐ判断が
必ずレビューに見える。

**母集団の取り方は fail-closed にする** (conceptual-review Round 2 Critical 対応)。
`type="file"` の静的な形だけを母集団にすると、`<input type={kind}>` や
`<input {...attrs}>` のように**実行時に file input になりうる形が無言で候補から外れる**
(共通規約 (b) 違反)。したがって:

| `type` 属性の形 | 扱い |
|---|---|
| 静的に file 以外 (`type="text"` 等) | 対象外 (母集団に入れない) |
| 静的に `file` | 目録の対象。`accept` の区分宣言が必須 |
| 式・短縮記法・spread 等で静的に確定できない | **未解決として gate を失敗させる** (「非 file」と決めつけない) |
| `type="file"` と spread が併存 | **失敗させる** (spread が `type` / `accept` を上書きできる) |
| `accept` 属性が無い file input | **失敗させる** |
| ファイルの parse に失敗 | **失敗させる** |

これらは負例 (わざと違反させた入力を検出できること) と正例 (規定どおりの入力を
誤検出しないこと) の両方向で固定する (共通規約 (c))。

> **この gate が保証しないこと (conceptual-review Round 1 Critical 対応)**:
> gate は「区分が宣言されていること」しか見ない。
> **`accept={sourceDocumentAccept}` という形を見ても、その識別子の値が
> `AcceptedSourceDocumentTypes` 由来であることは証明できない** (Inertia props は
> 実行時に注入されるため静的検査の到達範囲外。同名の別の値を入れても静的層は黙る)。
> したがって gate の名前と docblock は「供給元区分の宣言漏れを止める目録」に留め、
> 単一情報源との一致は主張しない。

**一致の保証は実挙動の 2 段の契約テストが担う** (静的層とは別レイヤ):

1. **サーバ側**: `create()` と `show()` の Inertia props を、
   `AcceptedSourceDocumentTypes` の**出力そのもの**と両フラグで突き合わせる Feature テスト。
   受け取る面が増えたらこのテストへ 1 行足す形にする (= 追加漏れがレビューに見える)。
2. **フロント側**: 渡された props が実際に `accept` 属性と案内の表示条件に使われることを、
   両フラグ相当の 2 状態で固定する component / page テスト。

> **コストの見積り**: gate は vitest 1 ファイル + 目録。構文の解決は
> **`svelte/compiler` の `parse()` が返す AST** を使う (svelte ^5.56.2 が依存に既にあり、
> `modern: true` で `Attribute` と `SpreadAttribute` を区別できることを実測で確認済み)。
> spread 属性を持つ file input・`accept` の無い file input・parse 失敗は
> **すべて失敗させる** (fail-closed)。
> 静的検査の共通規約 (AGENTS.md 「静的検査 (gate) と走査器の共通規約」) の
> (b)(c)(d) と「母集団が空でないことの検査」を満たす形で作る。
> **満たせない安直な実装 (accept の文字列 grep だけ) は作らない** —
> 規約どおり作るか、作らないかの二択にする。本設計は「作る」を選ぶ。
> 理由は、今回の漏れが**検出できないまま本番フラグを立てられる状態**であり、
> 同種の面 (撮影 PWA 側のアップロード) が既に 2 件あって今後も増えるため。
> ただし優先度は A〜C より低く、独立に実装できる位置付けにする。

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
| D | `tests/js/architecture/` (新規 1 ファイル) | file input の accept 供給元目録 (deny-by-default。AST 走査・fail-closed) |
| test | `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` | 既存の「公開面の一貫性」テストの公開面に**作成画面**を追加し、props を `AcceptedSourceDocumentTypes` の出力と突き合わせる |
| test | `tests/Feature/` (ラベルの前提) | 基底拡張子集合・画像拡張子集合の pin と、両フラグでの extensions / accept / ラベル / 422 文言の一致 |
| test | `tests/js/pages/ManualsCreate.test.ts` | 直書き pin をやめ、両フラグ (props の 2 状態) で accept と案内の有無を固定 |
| test | `tests/js/components/` | 共有コンポーネント化後も詳細画面側の文言・testid・**案内 → file input の並び順**が不変であることを確認 |

## 制約・前提

- **フラグの既定は false のまま**。本設計は既定挙動 (画像を受理しない) を一切変えない。
  `.env.example` / config の値も変更しない。
- **サーバ側の受理判定を変えない**。`StoreVideoManualRequest` は既に単一の情報源を
  経由しており、本設計はフロントと案内文言を追随させるだけである。
  したがって**セキュリティ境界の変更は無い** (accept 属性は UX のフィルタであって
  検証ではない。検証は FormRequest + 内容 sniff の二段構えが担う)。
  ただし**画像導線が開くことでアップロード試行と外部送信の対象は実際に増える**
  (conceptual-review Round 1 Warning)。だからこそ開示 (一般案内 + OCR 固有警告) を
  ファイル選択の**前**に置き、二段防御のコード (`SourceDocumentSizeLimit` /
  `SourceDocumentService::allowedMimeTypes` / 1 枚制約) は 1 行も触らない
  (既存の OCR 受理テストが回帰を担う)。
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
