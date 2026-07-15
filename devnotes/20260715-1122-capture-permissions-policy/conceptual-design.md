# 概念設計: capture-permissions-policy

## 背景・課題

bug-hunt run 20260715-084108 F-1-04 (High / 実質 Critical)。**本番でも撮影カメラをブロックする設定バグ**。

### 事実 (コードで確定)

- `config/security.php` の `permissions_policy` 既定値:
  `geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")`
- `app/Http/Middleware/SecurityHeaders.php` はこれを**全 web レスポンスに常時送出**する
  (L38-42。`.well-known/oauth-*` の metadata subset だけ例外)。
- 撮影 PWA は `/app/*` (ルート group `prefix('app')->as('capture.')`、`routes/web.php` L477-505) で、
  この group も web middleware group 内にあり `SecurityHeaders` が適用される。
- Permissions-Policy の `camera=()` は**空 allowlist = self すら不許可**。したがって
  同一オリジンの実カメラでも `getUserMedia({video})` がブラウザの Permissions-Policy 層で
  ブロックされ、**撮影 (中核機能) が起動できない**。
- 撮影 recorder は `resources/js/components/features/capture/CameraRecorder.svelte` L177-179 で
  **`getUserMedia({ video, audio: true })`** を要求している。Permissions-Policy で許可されない
  kind (camera / microphone) が 1 つでも含まれると getUserMedia 呼び出し**全体**が reject される
  (W3C Media Capture)。つまり `microphone=()` も **camera とセットで撮影を不能にする**
  = v1 は現に音声トラックを取得しており、microphone 緩和は「今必要なもの」である。
- bug-hunt はヘッドレスで getUserMedia を動的検証できないが、ヘッダ値と適用範囲はコードで確定。

これは AI-CUE の使命の中核である「スマホ (PWA) でナビゲーション撮影」を本番環境で不能にする。

## 改善アイデア

**撮影 document ルート (recorder を描画する `capture.manuals.show` = `/app/projects/{project}/manuals/{manual}`)
に限り** Permissions-Policy の `camera` / `microphone` を `(self)` に緩める。同一オリジンの PWA のみ許可する
(v1 スコープ = 「撮影 = PWA・同一オリジン・セッション認証」。AGENTS.md / doc/10 §10.8)。

- **最小権限**: recorder (`CameraRecorder.svelte`) を描画するのは `pages/Capture/Show.svelte`
  (= `capture.manuals.show`) の 1 ルートのみ (grep で確認済み)。Permissions-Policy は document 単位に効くため、
  一覧など他の capture HTML document (`capture.manuals.index`) や未解決 404 まで緩めると、そこで XSS が成立した
  場合に camera/microphone を要求できてしまう。よって緩和は**撮影 document ルートに限定**し、他は厳格値を維持する。
- 緩和対象ルートは config 駆動 (`security.capture_permissions_policy_routes = ['capture.manuals.show']`)。
  将来の撮影画面追加時は明示的にこの allowlist へ足す (least-privilege を運用で維持)。
- 撮影 document 以外 (非 capture ルート・capture 内の非 recorder ルート・未解決 404) は
  `camera=()`, `microphone=()` を**維持**する (攻撃面を広げない)。
- `payment=(self "https://js.stripe.com")` / `geolocation=()` 等の他ディレクティブは capture でも**維持**。
- CSP・HSTS・X-Frame-Options 等の他ヘッダは**不変**。

### 実装方針 (概要)

1. `config/security.php` に **capture 専用の Permissions-Policy 値**を追加する
   (`capture_permissions_policy`)。既定値は
   `geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")`。
   既存 `permissions_policy` と同じく env 上書き可 (`SECURITY_CAPTURE_PERMISSIONS_POLICY`)、
   null / 空文字で非送出 (opt-out / 一時 rollback) の contract を踏襲する。
2. `SecurityHeaders` middleware で、リクエストが撮影 document ルートかを判定し、その場合のみ
   capture 用の値を送る。判定は Laravel 標準の **`$request->routeIs(...config()->array('security.capture_permissions_policy_routes'))`**
   (allowlist は既定 `['capture.manuals.show']`。意図が明確・route 未解決時も false を返す null 安全)。
   SecurityHeaders は web group の `append` に登録され `$next($request)` 実行後に走る =
   ルート解決済みのため参照できる。
3. capture 判定・値選択を **private helper (`resolvePermissionsPolicy(Request): ?string`)** に
   閉じ、null/空 opt-out contract を戻り値型で表現する。ヘッダ送出ロジックは共通のまま
   (helper が返した `?string` を `is_string() && !== ''` で narrow して set)。

### テスト観点 (概念)

不変条件変更のためテスト登録まで含めて「実装済み」(AGENTS.md 禁止事項 #1)。詳細設計で Pest Feature テストとして具体化:

1. 撮影 document ルート (`capture.manuals.show`) の応答 `Permissions-Policy` に
   `camera=(self)`, `microphone=(self)` が含まれる。
2. 非 capture ルート (例: `/`) は従来の厳格値 (`camera=()`, `microphone=()`) を維持。
3. **capture 内の非 recorder ルート** (`capture.manuals.index`) も厳格値を維持 (least-privilege)。
4. **/app 配下の未解決 404** は route 未解決 → 厳格値を維持 (fail-secure)。
5. 撮影 document でも `geolocation=()` / `payment=(self "https://js.stripe.com")` が不変 (他 directive 回帰)。
6. capture 用 config が空文字 (opt-out) のとき非送出になる (contract 踏襲)。
7. 既存 `SecurityHeadersTest` の非退行 (非 capture の baseline ヘッダ)。

## 期待効果

- **使命への貢献**: 本番の実機・実カメラで `getUserMedia({video})` が Permissions-Policy に
  ブロックされなくなり、PWA ナビ撮影 (中核機能) が起動できる。撮影不能という致命障害を解消。
- 撮影 UX の回復。「思考ゼロ・撮影」の入口が塞がっていた状態を解く。
- capture 以外のルートは従来どおり `camera=()` 維持で、攻撃面 (powerful features) を広げない。

## 制約・前提

- v1 スコープ: 撮影は PWA・同一オリジン・セッション認証 (AGENTS.md, doc/10 §10.8-3)。
  よって `(self)` で十分。cross-origin allowlist は導入しない。
- 既存の Permissions-Policy contract (env 駆動、null/空で opt-out) を capture 側でも踏襲し、
  二系統の knob が同じ挙動で扱えるようにする。
- `SecurityHeaders` は web group 内で route 解決後に走るため route 名判定が可能。
  route 未解決 (例: /app 配下の 404) では route 名が null → 既定 (厳格) 値にフォールバックする
  = camera を余計に開かない fail-secure。撮影実ページ (capture.manuals.show 等) は
  マッチ済みルートで route 名を持つため正しく緩和される。
- PHPStan L10: config 値は `config('security.capture_permissions_policy')` を
  `is_string()` narrow してから使う (既存 `permissions_policy` と同じ流儀)。

## スコープ外

- cross-origin での撮影許可 (別オリジン埋め込み等) — v1 は同一オリジン PWA のみ。
- CSP / HSTS / その他ヘッダの変更。
- production:preflight による Permissions-Policy 値の検証追加 (現状 preflight は
  camera 値を検査していない。今回の scope では追加しない)。
- microphone の実利用可否そのもの (v1 は字幕のみ・TTS 後回しだが、撮影 API は
  `getUserMedia({video, audio})` を将来使う余地があり、camera と対で `(self)` に揃える)。
