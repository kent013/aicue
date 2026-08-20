# Round 2: Round 1 の指摘への対応と再レビュー依頼

Round 1 の指摘 (Critical 1 件 / Warning 6 件) をすべて捌きました。対応マトリクスと、
概念設計の変更後の該当箇所を示します。再レビューをお願いします。

## 対応マトリクス

### [Critical] (D) の gate は「props が真に単一情報源から来ている」ことを保証できない

**対応する。** 指摘のとおり、`accept={sourceDocumentAccept}` という形を静的に見ても
その識別子の値が `AcceptedSourceDocumentTypes` 由来であることは確定できません
(Inertia props は実行時注入で、静的検査の到達範囲外)。
gate の責務を「**供給元区分の宣言漏れの検出**」だけに縮め、単一情報源との一致は
主張しないことを概念設計と docblock に明記しました。一致の保証は
**実挙動の 2 段の契約テスト**へ移しました (提案どおりの二層分割):

1. サーバ側 Feature テスト: `create()` と `show()` の Inertia props を
   `AcceptedSourceDocumentTypes` の**出力そのもの**と両フラグで突き合わせる。
   受け取る面が増えたらこのテストへ 1 行足す形にする (追加漏れがレビューに見える)。
2. フロント側テスト: 渡された props が実際に `accept` 属性と案内の表示条件に
   使われることを、両フラグ相当の 2 状態で固定する。

### [Warning] 共有コンポーネント化で既存 testid・文言・表示順を変えないこと

**対応する。** 詳細画面側 (`SourceDocumentUpload`) の component テストで
testid 2 種 (`source-document-send-notice` / `source-document-image-notice`)・
文言・**案内 → file input の並び順**を固定することをテスト計画に明記しました。

### [Warning] Svelte の属性形態 (spread・引用符・式・条件レンダー) の扱いが未定義

**対応する。** gate は **`svelte/compiler` の `parse()` が返す AST** を走査する方式に
確定しました。svelte ^5.56.2 が既に依存にあり、`parse(src, { modern: true })` が
`Attribute` と `SpreadAttribute` を区別することは実測で確認済みです。
`SpreadAttribute` を持つ file input・`accept` 属性が無い file input・parse 失敗は
**すべて失敗** (fail-closed)。走査対象・保証しない範囲・母集団非空検査を docblock に書きます。

### [Warning] ラベルと config の拡張子集合が将来乖離しうる

**部分的に対応する (ラベルの機械導出は採らない)。**
拡張子リストから日本語の文 (「…形式、または JPEG・PNG の画像」) を組み立てる形にすると、
**法務確認済みの文面をコードが生成する**ことになり、config を触った副作用で
文面が変わりえます。「今必要なものだけ作る」にも反します。
代わりに承認済みの 2 文をそのまま単一情報源クラスへ置き、乖離は**前提の pin** で
検出します (本リポジトリの既存文化 `*PremiseTest` と同じ形):

- 基底の拡張子集合 (`config('manual.source_document_mimes')`) と画像拡張子集合が
  現在値ちょうどであることを検査する → config を変えるとラベルの見直しが
  必ずレビューに乗る。
- 併せて両フラグ状態で「extensions / accept 属性 / ラベル / FormRequest の 422 文言」が
  同じ集合を指すことを 1 つのテストで固定する (ご提案の後段をそのまま採用)。

この判断に異論があれば根拠を示してください。

### [Warning] 画像導線が開くとアップロード試行と外部送信対象が増える

**対応する。** 案内 2 段落は file input の**上**に置くことを設計に明記しました
(詳細画面と同じ並び)。サーバ側の二段防御 (`SourceDocumentSizeLimit` /
`SourceDocumentService::allowedMimeTypes` / 画像 1 枚制約) は 1 行も触らず、
既存の OCR 受理テストが回帰を担うことを制約節に書きました。

### [Warning] D の維持コストが大きい

**対応する (責務縮小で受ける)。** Critical 対応で gate は
「AST 走査 + 分類目録」だけになり、撮影側 2 件は値を変えず理由付き登録のみです。
優先度を A〜C より低く、独立に実装できる位置付けにしました。

### [Warning] props を個別に増やすと将来の追加漏れが起きる

**対応する。** Critical 対応 1. の Feature テストが `create()` と `show()` の
**両方**を 1 つのテストで突き合わせます。
なお **props を 1 つのオブジェクトへ束ねる案は採りません** — 既存 `show()` 側の
props 形状 (`sourceDocumentAccept` / `imageSourceDocumentsEnabled`) は T234 で
承認・実装済みの契約であり、束ね直すと本設計の目的 (適用漏れの回収) を超えた
破壊的変更になるためです。

## 変更後の概念設計 (該当箇所の全文)

### 改善アイデア (B) 末尾に追記

> 案内 2 段落は**file input の上**に置く (詳細画面と同じ並び)。
> ファイルを選ぶ前に外部送信の事実が見えていなければ開示の意味が無い。

### 改善アイデア (C) — 追記部分

> **ラベルは機械導出しない** (conceptual-review Round 1 Warning 対応)。
> 拡張子リストから日本語の文 (「…形式、または JPEG・PNG の画像」) を組み立てる形にすると、
> **法務確認済みの文面をコードが生成する**ことになり、config を触った副作用で
> 文面が変わりうる。承認済みの 2 文をそのままクラスへ置き、
> 乖離は**前提の pin** で検出する:
> 基底の拡張子集合 (`config('manual.source_document_mimes')`) と画像拡張子集合が
> 現在値ちょうどであることを検査し、config を変えたらラベルの見直しが
> 必ずレビューに乗るようにする (既存の `*PremiseTest` と同じ形)。

### 改善アイデア (D) — 差し替え後の全文

> ### (D) 再発防止: accept の供給元を目録で宣言させる (責務は「分類漏れの検出」のみ)
>
> 今回の漏れは「新しい面を足したときに単一の情報源へ繋ぐのを忘れても、誰も赤くならない」
> ために起きた。同じ形の漏れを止めるため、`resources/js` 配下の `type="file"` を持つ入力を
> **全数走査**し、`accept` の供給元区分を目録へ宣言させる deny-by-default の gate を 1 本置く
> (区分は「サーバ props 由来 (SOP)」と「クライアント側の固定値 + 理由」の 2 つ。
> 現在の母集団は 4 件 = SOP 2 件 + 撮影 2 件で、撮影の 2 件は `image/*` / `video/*` の
> 固定値が正しいため理由付きで後者に載る)。
> 新しいアップロード面を足すと**登録するまで赤くなる**ので、単一の情報源へ繋ぐ判断が
> 必ずレビューに見える。
>
> > **この gate が保証しないこと**: gate は「区分が宣言されていること」しか見ない。
> > **`accept={sourceDocumentAccept}` という形を見ても、その識別子の値が
> > `AcceptedSourceDocumentTypes` 由来であることは証明できない** (Inertia props は
> > 実行時に注入されるため静的検査の到達範囲外。同名の別の値を入れても静的層は黙る)。
> > したがって gate の名前と docblock は「供給元区分の宣言漏れを止める目録」に留め、
> > 単一情報源との一致は主張しない。
>
> **一致の保証は実挙動の 2 段の契約テストが担う** (静的層とは別レイヤ):
>
> 1. **サーバ側**: `create()` と `show()` の Inertia props を、
>    `AcceptedSourceDocumentTypes` の**出力そのもの**と両フラグで突き合わせる Feature テスト。
>    受け取る面が増えたらこのテストへ 1 行足す形にする (= 追加漏れがレビューに見える)。
> 2. **フロント側**: 渡された props が実際に `accept` 属性と案内の表示条件に使われることを、
>    両フラグ相当の 2 状態で固定する component / page テスト。
>
> > **コストの見積り**: gate は vitest 1 ファイル + 目録。構文の解決は
> > **`svelte/compiler` の `parse()` が返す AST** を使う (svelte ^5.56.2 が依存に既にあり、
> > `modern: true` で `Attribute` と `SpreadAttribute` を区別できることを実測で確認済み)。
> > spread 属性を持つ file input・`accept` の無い file input・parse 失敗は
> > **すべて失敗させる** (fail-closed)。
> > 静的検査の共通規約の (b)(c)(d) と「母集団が空でないことの検査」を満たす形で作る。
> > **満たせない安直な実装 (accept の文字列 grep だけ) は作らない** —
> > 規約どおり作るか、作らないかの二択にする。本設計は「作る」を選ぶ。
> > 理由は、今回の漏れが**検出できないまま本番フラグを立てられる状態**であり、
> > 同種の面 (撮影 PWA 側のアップロード) が既に 2 件あって今後も増えるため。
> > ただし優先度は A〜C より低く、独立に実装できる位置付けにする。

### 制約・前提 — 追記部分

> ただし**画像導線が開くことでアップロード試行と外部送信の対象は実際に増える**
> (conceptual-review Round 1 Warning)。だからこそ開示 (一般案内 + OCR 固有警告) を
> ファイル選択の**前**に置き、二段防御のコード (`SourceDocumentSizeLimit` /
> `SourceDocumentService::allowedMimeTypes` / 1 枚制約) は 1 行も触らない
> (既存の OCR 受理テストが回帰を担う)。

### 実装方針の表 — テスト行の差し替え後

| # | 変更対象 | 内容 |
|---|---|---|
| D | `tests/js/architecture/` (新規 1 ファイル) | file input の accept 供給元目録 (deny-by-default。AST 走査・fail-closed) |
| test | `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` | 既存の「公開面の一貫性」テストの公開面に**作成画面**を追加し、props を `AcceptedSourceDocumentTypes` の出力と突き合わせる |
| test | `tests/Feature/` (ラベルの前提) | 基底拡張子集合・画像拡張子集合の pin と、両フラグでの extensions / accept / ラベル / 422 文言の一致 |
| test | `tests/js/pages/ManualsCreate.test.ts` | 直書き pin をやめ、両フラグ (props の 2 状態) で accept と案内の有無を固定 |
| test | `tests/js/components/` | 共有コンポーネント化後も詳細画面側の文言・testid・**案内 → file input の並び順**が不変であることを確認 |

以上をふまえて、全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。
