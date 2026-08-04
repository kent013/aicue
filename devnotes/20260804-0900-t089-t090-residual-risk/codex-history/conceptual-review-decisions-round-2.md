# 対応マトリクス: conceptual-review Round 2

Codex 判定: CHANGES_REQUESTED ([Critical] 1 / [Warning] 1 / [Suggestion] 5)

## [Critical] `Inertia::clearHistory()` はリダイレクトを跨げない (フラグがログイン画面へ引き継がれない)

- 判断: **反論する (事実誤認)**
- 根拠 (すべてソースで裏取り):
  1. `Inertia\ResponseFactory::clearHistory()` の実体は
     **`session([SessionKey::CLEAR_HISTORY => true])`**
     (`vendor/inertiajs/inertia-laravel/src/ResponseFactory.php:182-185`)。
     flash ではなく**永続 session put** であり、当該リクエストの応答オブジェクトには何も書かない。
     メソッドの docblock も *"Clear the browser history on the **next visit**"* と明記している。
  2. 消費は `Inertia\Response::__construct` の
     **`session()->pull(SessionKey::CLEAR_HISTORY, false)`**
     (`vendor/inertiajs/inertia-laravel/src/Response.php:111`)。
     = **次に生成される Inertia 応答**が pull する = リクエスト境界を跨ぐのが設計そのもの。
  3. **このリポジトリで既に稼働している**。T089 の `LogoutResponse::toResponse()` は
     `Inertia::clearHistory()` を呼んでから **`redirect()->route('home')` を返す**だけで、
     着地 `GET /` の Inertia 応答がフラグを消費する。
     マージ済みテスト `tests/Feature/Security/InertiaHistoryGuardTest.php` が
     この**リダイレクト境界そのもの**を固定している:
     - `'ログアウトの着地 Inertia 応答に clearHistory が載る'` (L63-74): `POST /logout` を
       `assertRedirect(route('home'))` で**自動追従させずに**確認し、
       **別リクエスト**で `GET /` を叩いて `clearHistory: true` を検証している。
     - `'JSON ログアウトでもフラグは積まれ、次の Inertia 応答で clearHistory が消費される'`
       (L135-145): 204 応答 (= Inertia 応答を返さないリクエスト) でフラグを積み、
       **後続の別リクエスト**の Inertia 応答で消費されることを固定している。
     - `'clearHistory は 1 度きりで、次の Inertia 応答には持ち越さない'` (L77-86): one-shot 性。
  4. 例外レンダリング時点で session は利用可能。
     `Illuminate\Routing\Pipeline::handleException` (L40-47) が
     **middleware パイプラインの内側**で `ExceptionHandler::render()` を呼ぶため、
     外側の `StartSession` は既に適用済みで、応答は StartSession を通って戻る際に保存される。
     `AuthenticateSession::logout()` が `session()->flush()` した**後**に throw する経路でも、
     flush はデータを消すだけで session 自体は生きているため put は成立する。
- 対応内容: 提案された 3 つの代案 (one-shot marker + 専用 middleware / 同一リクエストで
  Inertia login 応答を返す / 公式永続化機構の明記) のうち **3 番目が既に成立している**ため、
  機構は変えない。ただし**設計文がこの機構を説明していなかった**ことは事実なので、
  概念設計に **F5b (フラグのライフサイクル)** を追加し、
  「認証失敗リクエスト → 302 → `/login` リクエスト → 消費」の状態遷移を明記する。
  代案 (専用 middleware で marker を消費する) は**採らない** —
  Inertia が同じことを公式にやっており、二重実装になる (思考原則 1 / 3)。

## [Warning] 期待効果が満たされない (上記 Critical に依存)

- 判断: **反論する (前提が崩れたため自動的に解消)**
- 根拠: 上記のとおり機構は成立する。
- 対応内容: ただし Codex のテスト要求
  「最終 `/login` payload だけでなく、リダイレクト境界を再現して固定せよ」は**妥当なので取り込む**。
  テスト方針を「**302 を自動追従させず、別リクエストとして `/login` を叩いて `clearHistory` を確認する**」
  と明示する (既存 `InertiaHistoryGuardTest` と同じ書き方に揃える)。

## [Suggestion] Filament 利用後まで marker が残る点を意図した仕様としてテストせよ

- 判断: **対応する**
- 根拠: marker 方式は採らないが、「フラグが**次の Inertia 応答まで残る**」という性質は同じ。
  Filament 認証失敗で積まれたフラグが、後続の Inertia 応答で消費されることは**意図した挙動**であり、
  意図であることをテストで示すべきという指摘は正しい。
- 対応内容: テスト方針に「**非 Inertia 面 (Filament) の認証失敗で積まれたフラグが、
  次の Inertia 応答で 1 度だけ消費される**」を追加する (安全側の偽陽性が仕様であることの固定)。

## [Suggestion] 文字列キーの散在を避けよ

- 判断: **対応する (ただし新しい定数は作らない)**
- 根拠: session キー `inertia.clear_history` は Inertia 側の `SessionKey` 定数であり、
  アプリは `Inertia::clearHistory()` / `Inertia::render()` 越しにしか触らない。
  アプリ側に文字列キーは 1 つも増えない。
- 対応内容: 設計文にその旨を明記する。

## [Suggestion] その他 (使命整合 / 禁止事項 / スコープ / 型安全性)

- 判断: **変更しない** (いずれも問題なしとの評価)。
