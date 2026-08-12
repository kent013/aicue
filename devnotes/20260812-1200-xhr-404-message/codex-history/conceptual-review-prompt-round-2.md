# Round 2: Round 1 指摘への対応

Warning 4 件・Suggestion 2 件を**すべて対応**しました (対象条件の精緻化と除外 prefix、
403/422/409 の棚卸し、配置契約、status 404 での判定、文言つき abort(404) 0 件のテスト固定)。
反論はありません。

確認してほしい点:
- 除外 prefix (`api/*` / `oauth/*` / `.well-known/*`) の選び方は妥当か。漏れている機械向け経路は無いか
- 配置の契約に穴は無いか
- 実装着手可能か

---

# 対応マトリクス: conceptual-review Round 1

判定 CHANGES_REQUESTED。Critical 0 / Warning 4 / Suggestion 2。**すべて対応**(反論なし)。

## [Warning] 原因分析は概ね正しい (肯定)

- 判断: 対応不要。読みが妥当と確認された。

## [Warning] collapse 条件が粗い。`/app/*` に限定しない理由と影響範囲を書け

- 判断: **対応する**
- 対応内容: 条件を「`expectsJson()` かつ **機械向け経路でない**」に精緻化し、
  `api/*` / `oauth/*` (11 route) / `.well-known/*` (4 route) を**除外**すると明記した
  (機械クライアントへ日本語の人間向け文言を返さないため)。
  `/app/*` に限定しない理由も書いた — **同じ穴が web 面の XHR にも等しく開いており**、
  限定すると「撮影 PWA だけ直っている」新しい非対称を作るから。
  Filament / Livewire も条件に合えば掛かるが**404 の文言が日本語になるだけ**と明示。

## [Warning] 403/422/409 の棚卸しをせよ

- 判断: **対応する**
- 対応内容: 実査した — `abort(403,'…')` / `abort(422,'…')` で**クラス名・SQL・モデル名を
  出している箇所は 0 件**、422 は ValidationException の日本語 field message、
  409 は固定文言 (`FROZEN_MESSAGE` 等)。設計に記載し、
  **網羅的な監査ではない**ことも併記した。

## [Warning] `bootstrap/app.php` での配置契約を明記せよ

- 判断: **対応する**
- 対応内容: 「配置の契約」節を新設。`ApiExceptionRenderer` より後 / 条件に合う 404 のときだけ
  非 null / 401・402 とは status が違うので競合しない / Inertia エラー画面は respond 側の
  最終整形なので JSON 要求にしか反応しない本 callback とは競合しない、を明記した。

## [Suggestion] 封筒にしない判断は妥当 (肯定)

- 判断: 対応不要。

## [Suggestion] 判定は `NotFoundHttpException` / status 404 で行え

- 判断: **対応する**
- 対応内容: 「判定は例外クラスではなく status で行う」節を追加。
  `ModelNotFoundException` だけを見ると **Laravel が変換した後**の経路 (実際に漏れている経路) を
  取り逃がすため、`HttpExceptionInterface` の status 404 を条件にする。
  あわせて「文言つき `abort(404, …)` が 0 件」という前提を**テストで固定する**と決めた。
\n\n---\n\n## 改訂後の概念設計 (全文)\n\n# 概念設計: xhr-404-message (XHR の 404 に内部クラス名が漏れる)

> bug-hunt run 20260812-100645 の **F-1-03 (Medium)** 起点。

## 背景・課題

### 症状

撮影 PWA (`/app/*`) へ `Accept: application/json` で叩くと、404 の body が

```json
{"message": "No query results for model [App\\Models\\Take] 1"}
```

になる。ブラウザ経路の 404 は日本語の友好的なページで、**同じ 404 でも経路によって露出が非対称**。

- `APP_DEBUG` は関係ない (bughunt env で未設定 = false。デバッグ表示由来ではない)。
- 認可境界そのものは健全 (cross-org は 404 で弾かれている)。漏れるのは**内部の名前空間構造**だけ。

### 原因 (実コードで特定)

例外応答を整形するクラスは 2 つあり、**担当領域が隙間なく敷き詰められていない**。

| クラス | 担当 | 判定 |
|---|---|---|
| `App\Exceptions\ApiExceptionRenderer` | REST API v1 の統一エラー封筒 | `shouldHandle()` = **`$request->is('api/*')`** |
| `App\Exceptions\InertiaExceptionRenderer` | Inertia のエラー画面 | `passthroughReason()` が `is('api/*') || expectsJson()` で**素通し** |

`InertiaExceptionRenderer` が `expectsJson()` を素通しにする理由は
`InertiaErrorScreenPassthrough::MachineReadableEnvelope` = 「**(c) の統一エラー封筒 JSON が
正しい応答形**」である。ところが `ApiExceptionRenderer` は `api/*` でなければ何もしないので、
**`expectsJson() かつ !is('api/*')` の領域では封筒を作る者が居ない**。
そこは Laravel 既定の JSON 化 (= `NotFoundHttpException` の message をそのまま `{"message": …}`)
に落ちる。`ModelNotFoundException` は Laravel が
`NotFoundHttpException($e->getMessage())` へ変換するので、モデルクラス名を含む文言が残る。

**つまり `MachineReadableEnvelope` という名前が、この領域では事実に反している。**

### 影響範囲

`expectsJson() かつ !is('api/*')` に該当するのは **撮影 PWA (`/app/*`) の XHR** が主。
撮影 PWA は書き込みを XHR JSON で行う設計 (`routes/web.php` のコメント) なので、
**通常運用でリソース不在に当たれば普通に露出する**。
ただし穴は撮影 PWA 固有ではなく、**web 面へ XHR で叩いた場合にも等しく開いている**
(下記「改善アイデア」で対象条件を確定する)。

## 改善アイデア

**セッション認証の web XHR で返る 404 について、body の message を固定文言へ collapse する。**

対象条件は `expectsJson()` かつ **機械向け経路でないこと**:

| 除外する prefix | 理由 |
|---|---|
| `api/*` | `ApiExceptionRenderer` が統一封筒を作る担当領域 (触らない) |
| `oauth/*` (11 route) / `.well-known/*` (4 route) | **ステートレスな機械向け経路** (AGENTS.md の分類)。日本語の人間向け文言を機械クライアントへ返さない |

残るのは実質 `app/*` (撮影 PWA・11 route) と、`projects/*` / `organizations/*` / `settings/*` /
`billing/*` などの **web 面に XHR で叩いたとき**である。`/app/*` だけに限定しないのは、
**同じ穴が web 面の XHR にも等しく開いている**からで、限定すると
「撮影 PWA だけ直っている」という新しい非対称を作ることになる。
Filament (`admin/*`) や Livewire も条件に合えば掛かるが、**404 の文言が日本語になるだけ**で
運用者向け面の挙動は変わらない。

- **応答の「形」は変えない。** `{"message": "…"}` のまま。
  撮影 PWA のクライアント (`lib/capture/http.ts`) は **`record.message` を読み**、
  `lib/capture/upload-queue.ts` は **`body.code`** を見る。封筒形
  (`{error: {code, message}}`) に変えると**クライアントが壊れる**。
- **`ApiExceptionRenderer` の担当領域は広げない。** 上記の理由で、封筒をこの領域へ持ち込めない。
- 固定文言は既存の 404 画面と同じ意味の日本語にする (経路による非対称を、露出の面で無くす)。

### 意図した 404 メッセージを潰さないか (実査)

`abort(404, '説明')` のように**文言つきで 404 を投げている箇所は `app/` に 0 件**
(すべて `abort(404)`)。よって collapse で失われる情報は無い。
**この「0 件」は前提なのでテストで固定する** (将来 文言つき 404 を足したら気づけるように)。

### 判定は例外クラスではなく status で行う

`ModelNotFoundException` だけを見ると、**Laravel が既に `NotFoundHttpException` へ変換した後**の
経路 (= 実際に message が漏れている経路) を取り逃がす。
`HttpExceptionInterface` の **status 404** を条件にする。

### 403 / 422 / 409 の棚卸し (今回対象外だが確認した)

- `abort(403, '…')` / `abort(422, '…')` で**クラス名・SQL・モデル名を出している箇所は 0 件**。
- 422 は `ValidationException` の field message (人間向け日本語)。
- 409 は `EnsureAccountNotPendingDeletion::FROZEN_MESSAGE` 等の固定文言。

よって「404 だけ直せば当面の露出は塞がる」と言ってよい。ただし**網羅的な監査ではない**
(独自例外の message までは追っていない)。

## 期待効果

- 内部の名前空間構造 (`App\Models\Take` 等) が XHR 応答から消える。
- 「同じ 404 なのに経路で露出が違う」非対称が解消する。
- **新機構を作らない** — 既存の 2 レンダラの隙間を埋めるだけ。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `bootstrap/app.php` (`withExceptions`) | 上記条件の 404 を固定文言へ collapse する render callback を 1 本追加 |
| `App\Exceptions\InertiaErrorScreenPassthrough` の docblock | `MachineReadableEnvelope` が「封筒 or collapse 済み JSON」を指すことを明記 (名前が事実に反したままにしない) |
| Feature テスト | 契約を固定 |

### 配置の契約 (既存 callback との順序干渉を避ける)

Laravel の render callback は**先に非 null を返したものが勝つ**。既存の配線は
`AuthenticationException` (Inertia history 破棄) / 課金 402 / `ApiExceptionRenderer` /
最終整形の respond callback である。本 callback は:

- **`ApiExceptionRenderer` より後**に置く (`api/*` は向こうが先に確定させる)
- **404 かつ上記条件のときだけ**非 null を返す (それ以外は null = 既存経路を一切触らない)
- `AuthenticationException` (401) や課金 402 とは**status が違うので競合しない**
- Inertia のエラー画面は `respond` callback 側の最終整形であり、
  **こちらは JSON を期待している要求にしか反応しない**ので競合しない

## 保証しないもの（誇張しない）

- **404 以外は変えない。** 403 / 422 / 409 などの body は現行のまま。
  上記の棚卸しで「クラス名を出している箇所は 0 件」までは確認したが、
  **独自例外の message までは追っておらず、網羅的な監査ではない**。
- **封筒形にはしない。** `api/*` の統一エラー封筒とは**別の形のまま**である。
  「JSON 応答が統一された」とは書かない。
- **認可・存在秘匿の挙動は 1 mm も変えない。** 変わるのは message 文字列だけ。
- **HTML 経路は無変更。**

## スコープ外（今回やらないこと）

- `ApiExceptionRenderer` の担当領域拡大 (クライアント破壊のため)。
- `/app/*` 用の統一エラー封筒の新設 (今必要でない。思考原則 2)。
- 403 / 422 等の message 見直し。
