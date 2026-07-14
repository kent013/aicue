# 対応マトリクス: conceptual-review Round 3

## [Warning] Laravel 12 対象なのに framework v13.18.0 を根拠にしている (観点3)
- 判断: **対応（根拠バージョンを明確化）**
- 根拠: `composer.lock` を確認すると実際にインストールされているのは
  **`laravel/framework v13.18.0`**（テンプレートの記載「Laravel 12」から更新済み）。設計根拠は
  composer.lock でピンされた実バージョンに合わせるのが正しい。既に引用済みの L748-750 は v13.18.0
  のソース。
- 対応内容: 概念設計に「本アプリの composer.lock は framework **v13.18.0** をピンしており、これが
  設計根拠の正本」と明記。`logoutOtherDevices` の条件付き recaller 再発行（L748-750）を同バージョンで
  再確認済みと記載。

## [Warning] session.connection は string|null。Assert::string は未指定構成で例外 (観点3/7)
- 判断: **対応**
- 根拠: `config/session.php` L76 は `env('SESSION_CONNECTION')` = **未設定時 null**。既定接続を
  意味する。`Assert::string` では標準構成で fail する。
- 対応内容: connection は `Assert::nullOrString($connection)`、table は `Assert::string($table)` に
  分けて確定。`DB::connection($connection)->table($table)`（$connection は null 可 = 既定接続）。

## [Warning] best-effort と言いつつ DELETE 例外処理が未定義 (観点3)
- 判断: **対応**
- 根拠: 例外をそのまま伝播するとパスワード変更済みなのに画面は失敗応答になる。correctness は層1が
  担うため削除失敗は致命的でない。
- 対応内容: session 削除のみ `try/catch` し、`report($e)` して**正常応答を維持**する旨を明記。

## [Warning] Filament の AuthenticateSession と二重適用の懸念 (観点5)
- 判断: **反論（検証済み・二重適用なし）**
- 根拠: `AdminPanelProvider` は Filament panel **専用の middleware stack**（`->middleware([...])` に
  EncryptCookies/StartSession/AuthenticateSession/... を独自列挙）を持ち、アプリの `web` グループを
  経由しない。よって web グループへの AuthenticateSession 追加は Filament ルートに二重適用されない。
  Filament 側の明示登録はそのまま維持する。
- 対応内容: 概念設計に「Filament は独自 middleware stack のため二重適用なし」を明記。

## [Suggestion] 「即時締め出す」の表現 (観点4)
- 判断: 対応（表現修正）
- 対応内容: 「保存済みの他 session 行を直ちに削除し新規リクエストでの利用を抑止。並行書き戻し行は
  AuthenticateSession が次回利用時に拒否」と正確化。

## [Suggestion] 「Architecture テストへ登録」の表現 (観点2)
- 判断: 対応（表現修正）
- 対応内容: 「セキュリティ不変条件を **Feature テストで固定**」に修正（Architecture inventory 登録は
  既存検出機構が当該経路を inventory 管理している場合のみ必要）。

## [Suggestion] グローバル配線の主要フロー影響評価 (観点5)
- 判断: 反映（Codex の評価を制約・前提へ取り込み）
- 対応内容: Fortify ログイン/2FA/SSO/actingAs/パスワード変更それぞれ guest no-op もしくは終端 hash
  保存で問題ないという評価を制約・前提に記載。
