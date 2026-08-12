# Round 3: Round 2 指摘への対応

Warning 5 件・Suggestion 2 件を**すべて対応**しました。反論はありません。
中心は **除外 prefix の廃止** — collapse は `api/*` 以外へ全面適用し、
prefix は**文言の選択にしか使わない**形へ整理しています。

確認してほしい点:
- 「collapse は全面 / 文言だけ面で分ける」への整理で、Round 2 の懸念は解けているか
- テスト契約 6 項目で足りるか
- 実装着手可能か

---

# 対応マトリクス: conceptual-review Round 2

判定 CHANGES_REQUESTED。Critical 0 / Warning 5 / Suggestion 2。**すべて対応**(反論なし)。

## [Warning] 除外 prefix はセキュリティ上の目的と逆向き

- 判断: **対応する** (除外を廃止)
- 根拠: 完全に妥当。`oauth/*` / `.well-known/*` を除外すると、そこで既定 404 message に落ちた場合
  **同種の露出がそのまま残る**。「機械クライアントへ日本語を返さない」は**文言選択の問題**であって、
  collapse を外す理由ではない。
- 対応内容: **collapse は `api/*` 以外へ全面適用**し、**文言だけ**を面に応じて選ぶ形に変更した
  (機械向け経路は `Not Found`、それ以外は日本語)。
  これにより **prefix 集合は「安全性」ではなく「文言」しか決めない** — 分類から漏れても
  起きるのは「機械向けに日本語が返る」見た目の問題だけで、情報露出は起きない。

## [Warning] 「セッション認証の web XHR」という説明と条件が一致していない

- 判断: **対応する**
- 根拠: `expectsJson()` + prefix 否定では認証方式も middleware も route の有無も見ていない。
  未定義 URL・Webhook・Fortify・Filament・Passport も入りうる。
- 対応内容: 推奨案 (「`api/*` は既存封筒に任せ、それ以外の `expectsJson()` 404 を collapse」) を採用し、
  説明も条件に合わせて書き直した。**未定義 URL も対象に含む**ことを明記 (テスト契約 6)。

## [Warning] 機械向け経路の除外集合は完全でない (Webhook 等)

- 判断: **対応する** (除外集合そのものを廃止したので問題が消えた)
- 対応内容: 上記のとおり。inventory のドリフト問題は「文言の問題」に降格した。

## [Warning] 配置契約に「後なら安全」という仮定が残る。返却条件をテストで固定せよ

- 判断: **対応する**
- 対応内容: 「何をテストで固定するか」節を新設し、指摘された 6 項目
  (api 封筒維持 / 非 API JSON 404 のみ collapse / HTML 404 維持 / 401・402・403・409・422 維持 /
  OAuth 仕様内エラー維持 / 未定義 URL でも内部 message を返さない) をそのまま契約にした。

## [Warning] 応答生成方法と禁止事項 4 の関係が未確定

- 判断: **対応する**
- 対応内容: **`response()->json()` は直書きしない**と明記し、`ApiExceptionRenderer` と同じく
  **JsonResource 経由**で組み立てる方針にした (詳細設計で具体化する)。

## [Suggestion] `HttpExceptionInterface::getStatusCode() === 404` の判定は妥当。ただし型を固定せよ

- 判断: **対応する** (詳細設計で型を確定)
- 対応内容: callback の引数型と status 判定の書き方は詳細設計で固定する
  (型を緩めて例外を握る実装にはしない)。

## [Suggestion] 「文言つき 404 が 0 件」の検査対象を `app/` に限定するな

- 判断: **対応する**
- 対応内容: 検査対象に `routes/` / `bootstrap/` / 独自 middleware /
  `new NotFoundHttpException(...)` / `new HttpException(404, ...)` を含めた。
  あわせて**この静的検査は変更検知であって collapse の安全性の証明ではない**と明記し、
  安全性は Feature テスト群が受け持つと切り分けた。
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

**`api/*` 以外で JSON を期待された 404 は、例外の message を必ず捨てる。** 除外は作らない。

Round 2 の指摘どおり、**prefix を除外集合にすると、その領域に同種の露出がそのまま残る**。
「機械クライアントへ日本語を返さない」は**文言選択の問題**であって、collapse そのものを
外す理由にならない。そこで **collapse は全面適用し、文言だけを面に応じて選ぶ**:

| 対象 | collapse | 文言 |
|---|---|---|
| `api/*` | **しない** (`ApiExceptionRenderer` が統一封筒を作る担当領域) | 封筒の既定 |
| `oauth/*` / `.well-known/*` (機械向け経路) | **する** | `Not Found` (プロトコル中立の英語) |
| それ以外 (撮影 PWA・web 面の XHR・未定義 URL 含む) | **する** | 日本語の固定文言 |

この形なら **prefix 集合は「安全性」ではなく「文言」しか決めない**。
将来 prefix が増えて分類から漏れても、起きるのは「機械向け経路に日本語が返る」という
**見た目の問題**だけで、**情報露出は起きない** (ドリフトの危険度が質的に下がる)。

- **応答の「形」は変えない。** `{"message": "…"}` のまま。
  撮影 PWA のクライアント (`lib/capture/http.ts`) は **`record.message` を読み**、
  `lib/capture/upload-queue.ts` は **`body.code`** を見る。封筒形
  (`{error: {code, message}}`) に変えると**クライアントが壊れる**。
- **`ApiExceptionRenderer` の担当領域は広げない。** 上記の理由で、封筒をこの領域へ持ち込めない。
- **`response()->json()` は直書きしない** (禁止事項 4)。`ApiExceptionRenderer` と同じく
  **JsonResource 経由**で組み立てる。

### 意図した 404 メッセージを潰さないか (実査)

`abort(404, '説明')` のように**文言つきで 404 を投げている箇所は 0 件**
(すべて `abort(404)`)。よって collapse で失われる情報は無い。
**この「0 件」は前提なのでテストで固定する**が、**検査対象は `app/` だけにしない** —
`routes/` / `bootstrap/` / 独自 middleware / `new NotFoundHttpException(...)` /
`new HttpException(404, ...)` も対象に含める (Round 2 [Suggestion])。
なお**この静的検査は変更検知であって collapse の安全性の証明ではない**。
安全性は下記の Feature テスト群が受け持つ。

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

### 何をテストで固定するか (順序の仮定に頼らない)

「既存 callback より後に置いたから安全」は仮定にすぎない。**各面の応答をテストで固定する**
(Round 2 [Warning]):

1. `api/*` の 404 は**既存の API 封筒**を維持する
2. 非 API の JSON 404 だけが collapse される (内部クラス名を含まない)
3. HTML / Inertia の 404 は**既存のエラー画面**を維持する
4. 401 / 402 / 403 / 409 / 422 は**既存応答**を維持する
5. OAuth の仕様内エラー応答は**既存形**を維持する (先に確定するレンダラがある経路)
6. **未定義 URL** への JSON 要求も内部 message を返さない

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
