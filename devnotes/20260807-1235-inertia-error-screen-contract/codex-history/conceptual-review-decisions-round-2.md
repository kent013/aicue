# 対応マトリクス: conceptual-review Round 2

Codex 全体判定: CHANGES_REQUESTED / [Critical] 1 件・[Warning] 6 件・[Suggestion] 2 件。

## [Critical] 観点 3: eager 化では旧 JavaScript を保持したタブを救えない

- 判断: **対応する**（指摘は正しく、設計の穴だった）
- 根拠: 検証した結果、指摘は事実として成立する。
  - `resources/js/inertia.ts` の `resolvePage()` は未解決ページで throw する
  - Inertia の version mismatch (`Middleware::handle()` の `onVersionChange`) は
    `$request->method() === 'GET'` の分岐の中にあり、**非 GET には働かない**
  - さらにテナント guard 404 のように `HandleInertiaRequests` が走る**前**に例外が出る経路では
    version チェック自体が起きない
  - 「長時間開きっぱなしのタブ」= 419 を最も踏む母集団と、旧 asset を保持する母集団は強く相関する
- 反論は成立しない。ただし**採る対処は Codex 提案の 1 案目 (二段階配備) ではなく 2 案目**とする。
  - 二段階配備は運用手順 (人間の記憶) に依存し、恒久的な保証にならない。
    しかも本問題は「Error.svelte を含まない build を読んだタブ」限定 = 導入時 1 回きりの
    現象なのに、runbook という恒久資産を増やしてしまう (思考原則 2「今必要なものだけ作る」)
  - 一方 2 案目 (リクエスト version による判定) は**サーバ側の条件 1 つ**で閉じ、
    運用手順も config フラグも増やさない。自己修復的で、GET なら version mismatch が
    409 + full reload で自動的に新 bundle へ載せ替える
- 対応内容:
  - 骨子の適用条件に「2. リクエストの `X-Inertia-Version` が現在の asset version と一致する」を追加
  - 「配備境界: 旧 asset を保持したタブへ `Error` を返さない」節を新設し、version mismatch に
    依存できない根拠 (GET 限定 / middleware 前の例外) と、判定の取得元を
    `app(HandleInertiaRequests::class)->version($request)` にする理由
    (`Inertia::getVersion()` は middleware が走る前だと空文字になり誤判定する) を明記
  - 素通しテスト表に「X-Inertia + stale version」ケースを追加
  - **副次効果**: Round 1 で入れた `TenantBoundaryPrecedenceTest` の正規化改修は**不要になったので撤回**。
    同テストは `X-Inertia-Version: stale-version` を送るため差し替え対象外になり、
    既存テスト・`Tests\Support\ResponseSignature` とも無改修で green。安全ヘルパを緩めずに済む
  - 新規 Feature テストは正しい version ヘッダを組み立てるローカルヘルパを持つことを明記
- Codex の「GET・POST・PUT/PATCH・DELETE それぞれの配備境界テスト」提案は、
  version 判定がメソッド非依存になったため**メソッド別ではなく version 一致/不一致の 2 ケース**で
  十分と判断した (メソッド別に分けても同じ条件式を 4 回通るだけで、検査の情報量が増えない)。

## [Warning] 観点 2: 「非空前提の DTO」の型表現を具体化せよ

- 判断: **対応する**
- 根拠: 正当。PHP の `array` では空を排除できず、`toInertiaProps(): array` は level 10 に対して広すぎる。
- 対応内容: 「型境界」節を新設し、`non-empty-list<ErrorScreenDestination>` の PHPDoc、
  コンストラクタでの空配列拒否、`toInertiaProps()` の具体的 array shape を明記。
  DTO 単体の空配列拒否テストは詳細設計のテスト計画に入れる。

## [Warning] 観点 3: respond callback から返すレスポンス型が不明確

- 判断: **対応する**
- 根拠: 正当。`Inertia::render()` が返すのは `Inertia\Response` (Responsable) であり
  Symfony Response ではない。finalize callback へそのまま返すと型不整合になる。
- 対応内容: 型境界の表に renderer の入出力型
  (`(SymfonyResponse, Request): ?SymfonyResponse`)、`toResponse($request)` まで renderer 内で
  完了させること、`toResponse()` の例外も含めて try/catch し原応答を返す fail-safe を明記。
  fail-safe のテストは詳細設計のテスト計画に入れる。

## [Warning] 観点 4: `Retry-After` の API 側挙動は厳密には「不変」ではない

- 判断: **対応する**
- 根拠: 正当。関数の入力契約は変わっており、「実挙動は変わらない」は不正確。
- 対応内容: 「現在の正規発行経路では挙動不変だが、不正形式は意図的に非表示へ厳格化する」に修正し、
  6 ケース (int / 整数文字列 / `"0"` / 負数 / HTTP-date / 未設定) の現行値・変更後・変化を表で固定。

## [Warning] 観点 5: 差し替え後に保持するヘッダの契約が不足

- 判断: **対応する**
- 根拠: 正当。特に 429/503 の `Retry-After` は HTTP ヘッダとしての機械可読性を失ってはいけない。
- 対応内容: 「差し替え後に保持するヘッダ (allowlist)」節を新設。
  移植は **allowlist 方式 (deny-by-default)** で `Retry-After` のみ。
  全ヘッダ移植は `Content-Type` / `Content-Length` / `X-Inertia` と競合するため採らない。
  `SecurityHeaders` / `NoStoreCacheHeaders...` は middleware の post 処理で従来どおり適用されること、
  middleware より前の例外で付かないのは**現状と同じ**であることも明記。

## [Warning] 観点 6: 差し替え対象 status の正本が確定していない

- 判断: **対応する**
- 根拠: 正当。目録型 gate を作るのに母集団が曖昧では gate が書けない。
- 対応内容: 「差し替え対象 status の正本 (v1)」節を新設し、6 件
  (403 / 404 / 419 / 429 / 500 / 503) を文言・待ち時間・戻り先・根拠つきで確定。
  目録に入れない status (401 / 409 / 422 / 400・405・410・502・504) も理由付きで明記。
  さらに「Architecture gate (目録) の契約」節を新設し、
  母集団下限 6・exact-fit cap 6・30 文字根拠・stale 検出・Blade 併存検査・負のコントロール・
  mutation による赤化確認手順を規定。
- 追加判断: **5xx は `app.debug` が true のときは差し替えない**（Inertia 公式レシピが
  local/testing を除外しているのと同じ理由。開発時に例外詳細を中立文言で潰さない）。

## [Warning] 観点 7: 型境界を詳細設計へ持ち越しすぎている

- 判断: **対応する**
- 根拠: 正当。PHPStan level 10 を成功条件に掲げる以上、境界型は概念段階で決まっているべき。
- 対応内容: 「型境界」節で 8 項目 (renderer 入出力 / Inertia 応答生成 / fail-safe /
  status enum / `int<0, max>|null` / `non-empty-list` / `toInertiaProps()` の array shape /
  TS の readonly interface) を確定。

## [Suggestion] 観点 5: gate の保証範囲 (文字列走査の限界) を明記せよ

- 判断: **対応する**
- 根拠: aicue には未検査領域を明示宣言する作法 (`contrast-invariant.test.ts`) があり、整合する。
- 対応内容: respond gate の節に「文字列走査の範囲に限られる (動的呼び出し・vendor の別名
  再エクスポート・将来の別 API は検出できない)」をテスト名と docblock へ書く旨を追記。

## [Suggestion] 観点 1 / 観点 6 の肯定的評価

- 判断: **見送る** (対応不要)
