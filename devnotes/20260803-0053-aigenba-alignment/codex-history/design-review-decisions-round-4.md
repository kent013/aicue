# 対応マトリクス: design-review Round 4

## 施策 2 [Critical] `EXTERNAL` を param 名だけで管理しても同名衝突を検出できない

- **判断: 対応する（指摘が正しい。R3 の修正では穴が残っていた）**
- **根拠**: 全面的に正しい。vendor が非数値用途の `{user}` を追加しても、
  `user` は既に `BIGINT` 登録済みなので **IV-1 を素通り**し、
  global な数値 pattern が vendor route を破壊する。
  R3 で「出自判定を廃止して param 名の第 5 分類にする」と直したが、
  **当初防ぎたかった衝突そのものが検出できない**構造になっていた。
- **対応内容**: 検出を **2 段**にした。
  1. **`EXTERNAL` を route identity ごとの inventory へ変更**（Codex 提案）:
     `array<string, list<string>>` = **route name → その route が持つ external param**。
     route identity には **route name** を使う（URI は prefix 設定で動くため不安定）。
     name 無し route は `method:uri` signature を使う。
     **IV-7 を「EXTERNAL 宣言との衝突検査」に再定義**し、
     宣言された param が `BIGINT`/`UUID` と同名なら**明示的に fail** させる。
     IV-2 の逆方向検査が登録 route の実在を保証し陳腐化を検出する。
  2. **IV-9（binding 型解決の一致）を新設** — これが**本質的な防御**:
     `BIGINT`/`UUID` param を持つ**全 route** について、
     **action シグネチャに同名かつ対応モデル型の引数があるか**を
     `$route->signatureParameters()` で検査する。
     これは `SubstituteBindings` が実際に使う解決経路そのものなので、
     **「`{user}` を非モデル用途で使う route」を機械的に検出できる**。
     vendor が `{user}` を文字列で使う route を足せば `User $user` の typehint が無く IV-9 が fail する。
- **残存リスクの記録**: 対応モデル型の typehint を持ちながら意味的に非数値 ID を期待する route
  （実質存在しない）だけは検出できない。`Route::pattern` 方式の**受容したリスク**として記録した。

## 施策 2 [Critical] IV-7 が「vendor / 非アプリ route を判定する」ままで実装不能

- **判断: 対応する**
- **根拠**: 正当。R3 で出自判定を廃止したのに、IV-7 の定義文だけが
  「vendor / 非アプリ route が…」のまま取り残されており、**検証名と実効保証が一致していなかった**。
- **対応内容**: IV-7 を **「EXTERNAL 宣言との突合」**に再定義した
  （自動的な出自判定ではないことを定義文に明記）。
  併せて「宣言漏れは IV-9 が拾う」という 2 段構成を設計本文に書いた。

## 施策 2 [Warning] docblock・スケッチが「4 分類」、リスク表が「アプリ route 限定」のままで矛盾

- **判断: 対応する**
- **対応内容**: **5 分類 / 全 route 走査**へ表記を統一した。
  実装スケッチのコメントも IV-1〜IV-9 の一覧に更新し、リスク表の分断も直した。

## 施策 2 [Warning] `EXTERNAL` の値が「実装時に実走査して確定」のままでは inventory が未完成

- **判断: 対応する**
- **対応内容**: 以下を設計として確定した。
  - **採取方法**: `php artisan route:list --json` を実走査し、
    `App\Http\Controllers\` 配下でも `routes/{web,api}.php` 由来でもない route を洗い出す
  - **route identity の安定性**: route name を第一とする（URI は prefix 設定で動くため不安定）。
    name 無し route は `method:uri` signature
  - **逆方向検査**: IV-2 が「登録済み route が実在すること」を検証し、
    route 追加・削除時の陳腐化を検出する
  - これは「未確定の設計判断」ではなく**実データの採取**である旨も明記した

## 施策 6 [Suggestion] JSON Content-Type 判定を media type 判定にする

- **判断: 対応する**
- **対応内容**: `application/json; charset=UTF-8` を許容する **media type 判定**に変更した
  （完全一致にしない）。

## 施策 8 [Warning] 前提欄が `playwright install chromium` のみのまま

- **判断: 対応する**
- **対応内容**: 前提を **`pnpm exec playwright install chromium webkit`** に更新し、
  **`composer test:browser` が両レーンを実行する契約**（`scripts/run-browser-test.sh` の対応、
  `docs/testing-browser.md` の更新）を施策に明記した。

## 施策 1 / 3 / 4〜7 / 9〜14

- **判断: 対応不要**（すべて APPROVE）
