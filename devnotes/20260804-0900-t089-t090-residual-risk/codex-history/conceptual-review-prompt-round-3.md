# 概念設計レビュー Round 3

Round 2 の [Critical] は**事実誤認**と判断したため、ソース根拠を添えて反論する。
併せて Warning / Suggestion の妥当な部分 (テストでリダイレクト境界を固定する / Filament 偽陽性を
仕様としてテストする / session キーを散在させない) は概念設計へ反映した。

反論が成立しているかを判定し、残る Critical / Warning が無ければ APPROVED を返してほしい。

---

## Claude 側の対応マトリクス

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


---

## 概念設計への反映差分 (該当箇所のみ抜粋)

### 追加した事実節 F5b

 `clearHistory` フラグはリダイレクトを跨ぐのが設計そのもの

`Inertia\ResponseFactory::clearHistory()` の実体は
**`session([SessionKey::CLEAR_HISTORY => true])`** (`ResponseFactory.php:182-185`) =
flash ではなく**永続 session put**。呼んだリクエストの応答には何も書かない
(docblock も *"Clear the browser history on the **next visit**"*)。
消費は `Inertia\Response::__construct` の
**`session()->pull(SessionKey::CLEAR_HISTORY, false)`** (`Response.php:111`)。

状態遷移:

```
認証失敗リクエスト (session に put)  →  302 /login  →  /login リクエスト
   → Inertia\Response 構築時に pull → page に clearHistory: true
   → クライアントが page.set() 冒頭で history.clear() (sessionStorage の鍵削除)
```

**この機構は本リポジトリで既に稼働している**。T089 の `LogoutResponse::toResponse()` は
`Inertia::clearHistory()` → `redirect()->route('home')` を返すだけで、着地の Inertia 応答が消費する。
マージ済みの `tests/Feature/Security/InertiaHistoryGuardTest.php` が
**302 を自動追従させず別リクエストとして着地を叩く**形でこの境界を固定しており
(L63-74 / L77-86 / L135-145)、204 応答 (= Inertia 応答を返さないリクエスト) で積んだフラグが
**後続の別リクエスト**で消費されることまで固定済み。
= 本設計は新しい永続化機構を発明しない。専用 middleware で marker を消費する案は
Inertia が公式にやっていることの二重実装になるため採らない。

例外レンダリング時点で session は使える: `Illuminate\Routing\Pipeline::handleException` (L40-47) が
**middleware パイプラインの内側**で `ExceptionHandler::render()` を呼ぶため
`StartSession` は適用済み。`AuthenticateSession::logout()` が `session()->flush()` した後に
throw する経路でも、flush はデータを消すだけなので put は成立する。
アプリ側に session キーの文字列は**1 つも増えない** (`Inertia::clearHistory()` 越しにしか触らない)。


### テスト方針に追加した 2 行

| Feature (Pest) | 認証失敗 (guest の認証済み route アクセス / `AuthenticateSession` による強制ログアウト) → **302 を自動追従させずに** assert → **別リクエスト**で `/login` を叩き Inertia payload に `clearHistory: true` が載る (リダイレクト境界そのものを固定する)。**負のコントロール**: 通常の guest の `/login` 直接アクセス・認証済み応答には載らない / **`expectsJson()` の 401 ではフラグが積まれない** |
| Feature (Pest) | 非 Inertia 面 (Filament `/admin`) の認証失敗で積まれたフラグが、次の Inertia 応答で **1 度だけ**消費される (安全側の偽陽性が「意図した仕様」であることの固定) |
