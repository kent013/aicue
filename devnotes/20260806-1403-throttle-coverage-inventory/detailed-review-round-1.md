**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、Laravel 実挙動とテスト実装上の穴がいくつかあり、このまま実装に入るには危険です。Critical はありませんが、Warning は修正してから進めるべきです。

**施策別判定**
| 施策 | 判定 |
|---|---|
| 1. throttle 判定述語と後付け binder | REQUEST_CHANGES |
| 2. 付与漏れの目録検査 | REQUEST_CHANGES |
| 3. 認証系 vendor route への throttle 後付け | REQUEST_CHANGES |
| 4. webhook 2 本への throttle 付与 | REQUEST_CHANGES |
| 5. 招待受諾への throttle 付与 | APPROVE |
| 6. キー規約統一 | REQUEST_CHANGES |
| 7. キー規約の機械検査 | REQUEST_CHANGES |
| 8. exemption 前提の Feature 固定 | REQUEST_CHANGES |
| 9. 新 limiter の behavioral テスト | REQUEST_CHANGES |
| 10. ドキュメント追記 | APPROVE |

**[Warning] 施策 1: `attachByName()` が既存 inline throttle と named throttle の区別を文字列 params だけで判定している**

`throttle:6,1` と `throttle:password-reset-request` はどちらも params 文字列比較になります。これは想定どおりですが、`throttle:password-reset-request,foo` のような想定外 params や、Laravel 側の middleware string 表現差分に弱いです。

修正案: entry を `{class, params}` に分解する private helper を作り、`params === $limiter` だけでなく「params が空でない」「params に余計な comma params がない」ことを明示してください。少なくとも named limiter 用と inline `{max},{decay}` 用の許容形式を分けると、例外メッセージも正確になります。

**[Warning] 施策 2: S1 の定義が `Authenticate` 不在だけだと “未認証で本体到達しうる” を過大評価する**

S1 は「変更系かつ Authenticate を含まない」とありますが、`signed` / vendor 固定 405 / LocalOnly / CSRF / signature middleware など、Authenticate 以外で本体到達を閉じる route も母集団に入ります。exemption で処理する方針自体はよいですが、設計文の「未認証で本体に到達しうる」は実際のセレクタより強すぎます。

修正案: S1 の説明を「未認証で到達可能な可能性がある変更系」に弱めてください。exemption は “本体到達しない根拠” を固定する、という役割にすると整合します。

**[Warning] 施策 2 / 8: `debug.login-as` の exemption が矛盾している**

実査母集団に `debug.login-as` が含まれている一方、exemption 理由は「local / testing 以外では route 登録自体が起きない」です。テスト環境では登録されるため、Architecture テストは常にこの route を見ます。`LocalOnlyDebugRoute` の説明も「local / テスト実行時のみ route 登録自体が起きない」と読め、文意が逆です。

修正案: enum docblock を「local / testing でのみ登録され、本番では route 登録自体が起きないデバッグ用 route」に直してください。施策 8 のテストも「testing では登録される / production 相当では登録されない」を明確に分けるべきです。

**[Warning] 施策 3: Fortify の route 名 fail-fast は環境・機能フラグ差分に弱い**

`register.store` や 2FA route は Fortify feature 設定に依存します。現行構成では存在する前提でも、将来 config で registration / 2FA / password reset を落とすと起動 fail になります。これは security fail-fast としては強い一方、「機能を無効化したら起動できない」という別の退行を生みます。

修正案: `THROTTLED_FORTIFY_ROUTES` を単純配列ではなく、存在必須か feature 依存かを明示する inventory にしてください。feature 依存 route は「機能が有効なら必須、無効なら missing 許容」にするのが安全です。

**[Warning] 施策 4: webhook throttle を署名検証前に置く設計は DoS リスクを軽減しきれていない**

固定キーを避け IP キーにする判断は妥当ですが、署名前消費である以上、攻撃者が送信元 IP を偽れないとしても、NAT / proxy / provider の出口 IP 単位の巻き添え 429 は残ります。設計文では監視に送っていますが、Feature として「新たな DoS を作らない」観点では運用依存が強いです。

修正案: このタスク内で閾値は変えなくてよいですが、`webhook-*` limiter の docblock に「これは署名検証コストの上限であり、正当通知保護の全体天井ではない」と明記してください。加えて TODO B1 に “署名成功後 limiter” だけでなく “provider ごとの署名済み source identity が取れる場合の bucket 再設計” を入れるとよいです。

**[Warning] 施策 6: `EmailHash::compute()` がすでに lower/trim するため、呼び出し側正規化との責務が重複している**

`EmailNormalizer::normalize()` してから `EmailHash::compute()` していますが、`EmailHash::compute()` 自体も `mb_strtolower(trim($email))` します。動作は壊れませんが、どちらが canonical 化の正本か曖昧になります。

修正案: limiter では `EmailNormalizer::normalize()` を正本にし、`EmailHash::compute()` は受け取った文字列をそのまま HMAC するか、docblock に「EmailHash は防御的に同じ正規化を再適用する」と明記してください。前者の方が責務は明確です。

**[Warning] 施策 6: `login` の no-email bucket が IP を含むため “anon” 固定ではないが、テスト観点が不足している**

`login:email-ip:anon:{ip}` なので非 string / 空 username は IP 単位に閉じます。これはよいです。ただし validation 前の limiter なので、配列 username・空文字・巨大文字列で例外にならないことを固定した方がよいです。

修正案: 施策 9 に「login limiter は username が配列 / 空文字でも 500 にならず同一 IP bucket を消費する」を追加してください。

**[Warning] 施策 7: token scanner は `use RateLimiter as Limiter; Limiter::for()` を検出できない**

現行コードが常に `RateLimiter::for` なら問題化しませんが、deny-by-default の検査としては alias import を逃します。未知 registration が scanner から消えると、inventory 完全一致が誤って green になります。

修正案: scanner は `use Illuminate\Support\Facades\RateLimiter;` と alias import を先に解析し、許容 facade 名集合を作ってください。最低限、`use ... RateLimiter as X; X::for()` は unresolved として fail させる必要があります。

**[Warning] 施策 7: `T_NAME_FULLY_QUALIFIED` の扱いだけでは `Illuminate\Support\Facades\RateLimiter::for` を拾えない可能性がある**

PHP の token はバージョンや書き方により `T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED` / `T_STRING` 群になります。`PHP 8.4` 前提でも、完全修飾と非完全修飾の両方を scanner test に入れる必要があります。

修正案: 単体テストに `Illuminate\Support\Facades\RateLimiter::for('x', ...)` と `\Illuminate\Support\Facades\RateLimiter::for('x', ...)` の両方を追加してください。

**[Warning] 施策 8: `.well-known/oauth-protected-resource` の DB クエリ 0 件だけでは “定数メタデータ” の証明が片側しかない**

exemption は 4 route ありますが、Feature 固定は protected-resource 1 本だけです。authorization-server 側や nested `{path}` が vendor 更新で DB / route parameter 依存になっても検出できません。

修正案: 4 route 全てについて DB クエリ 0 件と、nested `{path}` を変えてもステータスと主要 JSON shape が変わらないことを固定してください。

**[Warning] 施策 9: 「throttle が署名検証より先」は middleware 実効順だけでは behavioral proof として弱い**

実効 middleware 列の index 比較は必要ですが、Laravel priority list や alias 解決の証明に寄っています。署名検証が先に短絡して throttle header が出ない退行を直接検出できる方がよいです。

修正案: `POST /ses/notification` に無署名リクエストを連打し、429 到達時に signature middleware の通常エラーではなく throttle 応答になることも固定してください。ただし 429 応答契約そのものは別 feature なので、ここでは status と rate-limit headers 程度で十分です。

**[Suggestion] 施策 3: inline `throttle:6,1` / `10,1` の許容理由は妥当**

named limiter を増やさない判断は現実的です。ただし Architecture テストの docblock だけでなく、`THROTTLED_FORTIFY_ROUTES` のコメントにも「inline は actor 自身に閉じる route 限定」と書いておくと将来拡張時の誤用を減らせます。

**[Suggestion] 施策 4: `RouteThrottleBinder` の vendor route 後付けは `AppServiceProvider` より専用 provider に寄せたい**

現状の変更量では `AppServiceProvider` でも許容できます。ただし webhook / auth / api limiter が増えてきており、今後さらに増えるなら `RouteSecurityServiceProvider` のような分離を検討してよいです。今回の射程では必須ではありません。

**[Suggestion] 検証コマンド: `route:cache && route:list && route:clear` は設計上よいが、CI で副作用が残らないよう注意**

`route:clear` まで含めている点は妥当です。失敗時に route cache が残る可能性があるため、実装時は CI script ではなく手動検証か、trap 付き script 化が望ましいです。

**結論**

方針は承認可能な水準に近いですが、scanner の取りこぼし、Fortify feature 依存 route の fail-fast 条件、exemption 前提テストの不足は実装後に false green / false red を生みます。上記 Warning を設計に反映すれば、再レビューでは APPROVED にできる見込みです。