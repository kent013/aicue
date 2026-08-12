【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
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

あなたは Laravel の概念設計レビュアーです。

【レビュー観点】
1. 使命との整合 2. 禁止事項違反 3. 実現可能性 4. 期待効果 5. リスク 6. スコープ 7. 型安全性
9. セキュリティ (AGENTS.md のセキュリティ不変条件。特に存在秘匿・層 2/層 3 の順序)

【この設計に固有の、特に厳しく見てほしい点】
- 「2 つのレンダラの担当領域に隙間がある」という原因分析は正しいか。読み違えていないか。
- collapse する範囲 (`expectsJson() && !is('api/*')` の 404 のみ) は狭すぎないか / 広すぎないか。
  他に同種の露出が残る経路はあるか (403/422/409、Passport/OAuth、Filament、webhook 等)。
- 応答の「形」を変えない判断 (封筒にしない) は妥当か。
- `bootstrap/app.php` に render callback を足す位置は、既存の callback 群と干渉しないか
  (このリポジトリは `ApiExceptionRenderer` / Inertia error screen / 課金 402 等を既に配線している)。

【出力形式】
全体判定 APPROVED / CHANGES_REQUESTED、[Critical][Warning][Suggestion]、日本語

---

## 概念設計

（`/workspace` の実コードを読んで検証してよい。特に `bootstrap/app.php` の withExceptions、
`app/Exceptions/ApiExceptionRenderer.php`、`app/Exceptions/InertiaExceptionRenderer.php`、
`app/Enums/Http/InertiaErrorScreenPassthrough.php`、`resources/js/lib/capture/http.ts`）

# 概念設計: xhr-404-message (XHR の 404 に内部クラス名が漏れる)

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

## 改善アイデア

**`expectsJson() かつ !is('api/*')` の 404 について、body の message を固定文言へ collapse する。**

- **応答の「形」は変えない。** `{"message": "…"}` のまま。
  撮影 PWA のクライアント (`lib/capture/http.ts`) は **`record.message` を読み**、
  `lib/capture/upload-queue.ts` は **`body.code`** を見る。封筒形
  (`{error: {code, message}}`) に変えると**クライアントが壊れる**。
- **`ApiExceptionRenderer` の担当領域は広げない。** 上記の理由で、封筒をこの領域へ持ち込めない。
- 固定文言は既存の 404 画面と同じ意味の日本語にする (経路による非対称を、露出の面で無くす)。

### 意図した 404 メッセージを潰さないか (実査)

`abort(404, '説明')` のように**文言つきで 404 を投げている箇所は `app/` に 0 件**
(すべて `abort(404)`)。よって collapse で失われる情報は無い。

## 期待効果

- 内部の名前空間構造 (`App\Models\Take` 等) が XHR 応答から消える。
- 「同じ 404 なのに経路で露出が違う」非対称が解消する。
- **新機構を作らない** — 既存の 2 レンダラの隙間を埋めるだけ。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `bootstrap/app.php` (`withExceptions`) | `expectsJson() かつ !is('api/*')` の 404 を固定文言へ collapse する render callback を 1 本追加 |
| `App\Exceptions\InertiaErrorScreenPassthrough` の docblock | `MachineReadableEnvelope` が「封筒 or collapse 済み JSON」を指すことを明記 (名前が事実に反したままにしない) |
| Feature テスト | 契約を固定 |

## 保証しないもの（誇張しない）

- **404 以外は変えない。** 403 / 422 / 409 などの body は現行のまま
  (今回の観測は 404 のみで、他の露出は確認していない)。
- **封筒形にはしない。** `api/*` の統一エラー封筒とは**別の形のまま**である。
  「JSON 応答が統一された」とは書かない。
- **認可・存在秘匿の挙動は 1 mm も変えない。** 変わるのは message 文字列だけ。
- **HTML 経路は無変更。**

## スコープ外（今回やらないこと）

- `ApiExceptionRenderer` の担当領域拡大 (クライアント破壊のため)。
- `/app/*` 用の統一エラー封筒の新設 (今必要でない。思考原則 2)。
- 403 / 422 等の message 見直し。
