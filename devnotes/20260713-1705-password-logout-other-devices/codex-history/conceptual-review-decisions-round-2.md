# 対応マトリクス: conceptual-review Round 2

## [Critical] DB行削除だけでは並行リクエストの旧セッション復活を防げない (観点3/4/6)
- 判断: **全面的に対応（設計方針を Laravel 標準へ転換）**
- 根拠: Codex の指摘は正しい。攻撃者の in-flight リクエストが行削除前に session をロードし、
  レスポンス終了処理（StartSession の save）で同一 session ID を authenticated payload 付きで
  書き戻すと、削除した行が復活しうる。行削除・token rotate はこの「既にロード済み session」に
  効かない。Laravel docs も `logoutOtherDevices()` は `AuthenticateSession` ミドルウェアと併用が
  前提と明記している（先人の知恵 = Jetstream/Laravel 標準）。
- 対応内容: **Jetstream/Laravel 標準の "Log Out Other Browser Sessions" 構成へ転換**:
  1. `AuthenticateSession`（alias `auth.session`）を **web ミドルウェアグループ（グローバル）** に
     配線する。guest には no-op（`$request->user()` 無しで即 next）なので副作用は認証済み
     リクエストのみ。web.php の auth ルート群と Fortify 登録の認証ルート（user-password.update 等）を
     **漏れなく**カバーするためグローバル配線を選ぶ。既に Filament panel で採用済みの実績あり。
  2. `UpdateUserPassword`: 新パスワード保存 → `Auth::logoutOtherDevices($input['password'])`
     （現在デバイスの recaller 再発行 + password_hash 同期の起点 + OtherDeviceLogout イベント）
     → 他 session 行の best-effort 削除（session connection 上）。
  - **correctness の保証は AuthenticateSession の password_hash 照合**が担う（毎リクエストで
    session 内 password_hash_web と現在 hash を比較し不一致なら logout。復活行も次リクエストで失効）。
    DB 行削除は「即時クリーンアップ」の best-effort であり correctness の前提ではない。これにより
    Round 1 で議論した「削除失敗時の整合性」問題自体が解消する（transaction 不要）。

## [Critical] 「両輪で必要十分・確実に失効」は並行競合で不成立 (観点4)
- 判断: 対応する
- 対応内容: 期待効果を **3 層**で書き直す: (1) DB 行削除=既存 session 即時失効 /
  (2) AuthenticateSession の password_hash 照合=復活行・他 recaller を次リクエストで失効 /
  (3) logoutOtherDevices=現在デバイス維持（recaller 再発行）+ 監査イベント。

## [Critical] AuthenticateSession をスコープ外にする判断は過小 (観点6)
- 判断: 対応する（スコープに含める）
- 対応内容: AuthenticateSession 配線を**本設計の施策に含める**。「多層防御だから省略」ではなく
  「並行書き戻し競合を閉じる整合性機構」として必須と位置づける。

## remember_token rotate の扱い（Round 1 で導入したが再考）
- 判断: **rotate は撤回（不要）**
- 根拠: AuthenticateSession の viaRemember 分岐が recaller の password_hash を現在 hash と照合し、
  他デバイスの recaller を次リクエストで失効させる（`retrieveByToken` は token のみ照合だが、
  hash 照合はミドルウェアが担う）。したがって token rotate なしでも remember-me は閉じる。
  Jetstream も rotate せず AuthenticateSession + logoutOtherDevices + deleteOtherSessionRecords で
  構成している（先人の知恵に合わせ、余計な機構を足さない=思考原則#2）。rotate は現在デバイスの
  recaller を壊すリスクと再発行順序の複雑さを持ち込むため撤回。

## [Warning] queueRecallerCookie は無条件ではないか (観点3)
- 判断: **反論（検証済み）**
- 根拠: pinned source（framework v13.18.0）`SessionGuard::logoutOtherDevices` L748-750 は
  `if ($this->recaller() || $this->getCookieJar()->hasQueued(...))` の**条件付き**再発行。
  session-only ユーザーに recaller を新規付与することはない。設計にソース行を明記して確定。

## [Warning] transaction 内の cookie/event 副作用が rollback されない (観点5)
- 判断: **対応（transaction を撤去して解消）**
- 根拠: correctness を AuthenticateSession に委ねたため DB 行削除は best-effort となり transaction
  不要。よって「DB rollback と cookie/event 副作用の乖離」問題は発生しない。行削除は 1 クエリで
  失敗時も次リクエストで AuthenticateSession が失効を保証する旨を明記。

## [Warning] session.connection がアプリ既定接続と異なる場合 (観点5)
- 判断: 対応する
- 対応内容: 行削除は `DB::connection(config('session.connection'))->table(config('session.table','sessions'))`
  と **session 設定の接続**を明示して行う。transaction を使わないため接続差異の整合性問題は消える。

## [Warning] テスト方針が概念設計に無い (観点2)
- 判断: 対応する
- 対応内容: 概念設計に「テスト方針」節を追加し、(a) 他 session 行削除 (b) 旧 recaller で再認証不可
  (c) 現在 session 維持 (d) 復活行が次リクエストで拒否される (AuthenticateSession) (e) driver!=database
  時 skip を列挙。詳細は詳細設計の Feature テストへ。

## [Warning] 不変条件を宣言しつつ reset 経路違反をスコープ外にする矛盾 (観点6)
- 判断: 対応する（不変条件を今回経路に限定）
- 対応内容: 不変条件の文言を「**認証済みセルフサービス変更経路**は変更後に他デバイスを失効させる」に
  限定。reset 経路は別 follow-up TODO 推奨（本 PR の完了条件にはしない）と明記。矛盾を解消。

## [Suggestion] config() 戻り値を型検証してからテーブル/接続名に使う (観点7)
- 判断: 対応する
- 対応内容: `config('session.table')` / `config('session.connection')` は
  `Webmozart\Assert\Assert::string(...)` 等で string 保証してから使う旨を実装方針に明記（PHPStan L10）。
