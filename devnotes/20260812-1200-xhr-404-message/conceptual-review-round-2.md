全体判定: **CHANGES_REQUESTED**

[Critical] なし。

[Warning] 除外 prefix はセキュリティ上の目的と逆向きです。`oauth/*` / `.well-known/*` を除外すると、その領域で Laravel 既定の 404 message が返る場合、今回と同種の内部情報露出がそのまま残ります。「日本語を機械クライアントへ返さない」は文言選択の問題であり、collapse 自体を外す理由にはなりません。

固定文言をプロトコル非依存の `"Not Found"` にすれば、`api/*` 以外の JSON 404 を一律 collapse できます。OAuth が独自仕様のエラー応答を正常に生成する経路は、そのレンダラが先に応答を確定するため影響しません。問題になるのは既定 JSON 404 に落ちた経路であり、そこはむしろ collapse 対象です。

[Warning] 「セッション認証の web XHR」という説明と実際の条件が一致していません。`expectsJson()` と prefix 除外だけでは、認証方式・middleware・route の有無を判定していません。未定義 URL、Webhook、Fortify、Filament、Passport なども条件に入り得ます。

次のどちらかに設計を揃える必要があります。

- 推奨: `api/*` は既存封筒に任せ、それ以外の `expectsJson()` 404 を英語の汎用文言へ collapse
- 代案: 本当に web/session 面だけが対象なら、prefix の否定集合ではなく、対象 route/middleware の肯定条件で分類

後者は未定義 route には route middleware が存在しないため境界が複雑です。今回の「情報露出を閉じる」という目的には前者が適します。

[Warning] 機械向け経路の除外集合は完全とはいえません。少なくとも設計文からは Webhook 系 route が除外されていません。また、将来別 prefix が増えるたびに無言で collapse 対象へ入るため、否定的 prefix allowlist はドリフトしやすい設計です。AGENTS.md にも `api/`・`oauth/`・`.well-known/oauth` 以外のステートレス経路が示唆されています。

ただし、上記のとおり機械向け経路も汎用英語 404 に collapse するなら、この inventory 問題自体を消せます。

[Warning] callback の配置契約には、まだ「既存 callback より後なら安全」という仮定が残っています。必要なのは順番だけでなく、各 callback の返却条件をテストで固定することです。最低限、以下が必要です。

- `api/*` の 404 は既存の API 封筒を維持する
- 非 API の JSON 404 だけが collapse される
- HTML/Inertia 404 は既存画面を維持する
- 401、402、403、409、422 は既存応答を維持する
- OAuth の仕様内エラー応答が既存形を維持する
- 未定義の JSON route も内部例外 message を返さない

特に「OAuth は除外する」場合、OAuth 404 が内部情報を露出しないことを別のレンダラまたはテストで証明しない限り、穴が残ります。

[Warning] 応答生成方法が禁止事項4との関係で未確定です。render callback から何を返すかを詳細設計で明記してください。`response()->json()` の直書きは不可です。既存の DTO / JsonResource / エラー応答生成部品を使うか、仕様固定の例外応答として明示的に例外登録する必要があります。

[Suggestion] `HttpExceptionInterface::getStatusCode() === 404` の判定は妥当です。ただし callback の引数を `HttpExceptionInterface` に狭める場合と、`Throwable` を受けて status を調べる場合とで Laravel の callback 適用範囲が変わるため、詳細設計で型を固定してください。型を緩めて例外を握る実装は避けるべきです。

[Suggestion] 「文言つき404が0件」の検査対象を `app/` だけに限定すると不十分です。`routes/`、`bootstrap/`、独自 middleware、`new NotFoundHttpException(...)`、`HttpException(404, ...)` も対象になり得ます。また、このテストは「現在意図的な404文言がない」という変更検知には有効ですが、collapse の安全性そのものは Feature テストで保証してください。

現状のままでは実装着手不可です。主な修正点は、除外 prefix を増やすことではなく、**非 API JSON 404 を `"Not Found"` に一律 collapse し、既存の専用レンダラが確定した応答を回帰テストで守る**という境界へ整理することです。これなら応答形を変えず、機械向け経路にも日本語を持ち込まず、未知の経路を含む露出を閉じられます。