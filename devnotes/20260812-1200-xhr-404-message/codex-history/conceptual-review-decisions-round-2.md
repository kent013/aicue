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
