# 対応マトリクス: design-review Round 1

Critical 0 件 / Warning 11 件 / Suggestion 3 件。**Warning は全件対応**、Suggestion は 2 件対応・1 件見送り。

## [Warning] 施策 1: params 比較が緩い
- 判断: **対応する**
- 対応内容: entry を `{class, params}` に分解する private helper
  `parseThrottleEntry(string $entry): array{class: string, params: string}` を追加し、
  - **named 形式** (`^[a-z][a-z0-9-]*$`) と **inline 形式** (`^\d+,\d+$`) を明示的に区別する
  - params が空 / 未知形式 / 余計な comma を含む場合は「想定外」として例外に落とす
  - 例外メッセージに実 entry と期待値の両方を出す

## [Warning] 施策 2: S1 の説明が実セレクタより強い
- 判断: **対応する (表現の是正)**
- 根拠: 正しい。`signed` / 定数 405 / `LocalOnly` / 署名検証など `Authenticate` 以外で
  本体到達を閉じる route も S1 に入る。
- 対応内容: S1 の説明を「**未認証で到達可能な可能性がある**変更系」に弱め、
  exemption の役割を「**本体到達しない根拠を固定すること**」と定義し直す。

## [Warning] 施策 2 / 8: `debug.login-as` の exemption 文意が逆
- 判断: **対応する**
- 根拠: 正しい。「local / テスト実行時のみ route 登録自体が起きない」は文意が逆で、
  正しくは「local / testing でのみ登録され、production では登録自体が起きない」。
  (既存 `ControllerAuthorizationExemption::LocalOnlyDebugRoute` の文面もこの曖昧さを持つが、
   本タスクでは新 enum の文面だけを正しく書く。既存 enum の文面修正は射程外)
- 対応内容: 新 enum の docblock を書き直し、施策 8 のテストを
  「testing では登録される」と「production 相当では登録されない」に分ける。

## [Warning] 施策 3: Fortify feature フラグ差分で起動 fail する
- 判断: **対応する**
- 根拠: 重要。`register.store` は `Features::registration()`、2FA 管理は
  `Features::twoFactorAuthentication()`、reset 系は `Features::resetPasswords()` に依存する。
  無条件 fail-fast だと「機能を無効化したら起動できない」という別の退行を生む。
- 対応内容: inventory を
  `array<string, array{throttle: string, feature: ?string}>` にする。
  - `feature === null` → 常に必須 (route が無ければ fail-fast)
  - `feature !== null` → `Features::enabled($feature)` が true のときだけ必須。false なら skip
  - **skip が穴にならない根拠**を設計に明記する: feature を再有効化して binder が skip したままなら、
    施策 2 の目録検査が「throttle 無しの保護対象 route」として **必ず fail する** (二重の網)。

## [Warning] 施策 4: webhook throttle は正当通知保護の全体天井ではない
- 判断: **対応する (docblock 明記 + TODO 拡張)**
- 対応内容: `webhook-*` limiter の docblock に
  「これは**署名検証コストの上限**であり、正当通知を守る全体天井ではない」と明記。
  後続 TODO B1 に「provider 側の署名済み source identity が取れる場合の bucket 再設計」を追加。

## [Warning] 施策 6: `EmailHash::compute()` の二重正規化で canonical 化の正本が曖昧
- 判断: **対応する (責務の明文化)**
- 根拠: `EmailHash::compute()` は内部で `mb_strtolower(trim($email))` を行うため、
  呼び出し側の `EmailNormalizer::normalize()` と重複する。
- 対応内容: **canonical 化の正本は `EmailNormalizer`** と定義し、
  `EmailHash` の docblock に「防御的に同じ正規化を再適用する (呼び出し漏れへの保険)」と追記する。
  `EmailHash` の**実装は変えない** (既存の呼び出し元への波及を避けるため。思考原則 2)。

## [Warning] 施策 6 / 9: validation 前 limiter の異常入力テストが不足
- 判断: **対応する**
- 対応内容: 施策 9 に
  「login limiter は username が**配列 / 空文字 / 極端に長い文字列**でも 500 にならず、
   同一 IP bucket を消費する」を追加。`password-reset-*` / `account-register` も同様。

## [Warning] 施策 7: scanner が alias import (`use RateLimiter as X`) を取りこぼす
- 判断: **対応する**
- 根拠: 正しい。deny-by-default の検査で「未知の登録が scanner から消える」は最悪の失敗モード。
- 対応内容: scanner に import 解析を追加する。
  - `use Illuminate\Support\Facades\RateLimiter;` → 短縮名 `RateLimiter` を許容 facade とする
  - `use ... RateLimiter as X;` → **`X::for(...)` を `unresolved` に入れて fail させる**
    (alias を解決するのではなく「規約から外れた書き方を禁止する」= 単純で堅い)
  - 完全修飾 `\Illuminate\Support\Facades\RateLimiter::for` は受理する

## [Warning] 施策 7: 完全修飾 / 非完全修飾の token 形の網羅
- 判断: **対応する**
- 対応内容: scanner 単体テストに
  `Illuminate\Support\Facades\RateLimiter::for('x', …)` (`T_NAME_QUALIFIED`) と
  `\Illuminate\Support\Facades\RateLimiter::for('x', …)` (`T_NAME_FULLY_QUALIFIED`) の
  両方を追加する。

## [Warning] 施策 8: `.well-known` の Feature 固定が 1 本しかない
- 判断: **対応する**
- 対応内容: 4 route すべてについて
  「DB クエリ 0 件」と「nested `{path}` を変えても status と主要 JSON shape が変わらない」を固定する。

## [Warning] 施策 9: 「throttle が署名検証より先」の証明が実効順比較だけ
- 判断: **対応する**
- 対応内容: 実効順の index 比較に加えて、
  **無署名リクエストを上限+1 回連打し、最後が 403 (invalid signature) ではなく 429 になる**ことを固定する。
  429 応答の**契約そのもの**は別 feature (`error-response-contract`) の射程なので、
  ここで見るのは status と rate-limit ヘッダの存在までに留める。

## [Suggestion] 施策 3: inline throttle の許容理由を const コメントにも書く
- 判断: **対応する**

## [Suggestion] 施策 4: 専用 `RouteSecurityServiceProvider` への分離
- 判断: **見送る (反論)**
- 根拠: 本タスクで `AppServiceProvider` に増えるのは limiter 定義 2 本と booted callback 1 本のみ。
  provider を割るのは AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。
  Codex 自身も「今回の射程では必須ではない」と述べている。
  後続 TODO 候補としてのみ記録する。

## [Suggestion] 検証コマンドの `route:cache` に trap
- 判断: **対応する**
- 対応内容: 検証表に「**手動検証**であり CI script には入れない。
  失敗時に route cache が残らないよう `route:clear` を必ず実行する」と注記する。
