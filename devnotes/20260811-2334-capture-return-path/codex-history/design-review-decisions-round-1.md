# 対応マトリクス: design-review Round 1

全体判定 CHANGES_REQUESTED。Critical 1 / Warning 6 / Suggestion 2。以下のとおり捌いた。

## [Critical] (施策 3) `assertOk()` だけでは「到達条件が同じ」を固定できない — Inertia component まで assert せよ

- 判断: **対応する**
- 根拠: 妥当。200 を返す別画面 (例: 何かの案内ページへ逃がす実装) に置き換わっても緑になる。
  復路が「行き先として成立している」ことを言うなら、着地した画面まで見る必要がある。
- 対応内容: 両 route の assert に `->assertInertia(fn (Assert $page) => $page->component('Capture/Show'))` /
  `->component('Manuals/Show')` を追加した。**リダイレクトも塞がる**
  (`assertOk` は 302 を弾くので既に塞がっているが、200 の別画面はこれで初めて塞がる)。

## [Warning] (施策 2) DOM 順を固定していない

- 判断: **対応する**
- 根拠: 設計文が「既存を先・新規を後」と判断しているのにテストが見ていない = 設計と検査の乖離。
- 対応内容: `compareDocumentPosition` で「一覧へ戻る」→「マニュアル詳細へ」の順を固定するケースを追加。

## [Warning] (施策 2) 1 本目はアクセシブルネームで取れ

- 判断: **対応する**
- 根拠: 文言を契約にするなら、利用者が認識する名前 (accessible name) で取るのが正しい。
  `getByTestId` + `textContent` はアイコンの `aria-hidden` が外れても気づけない。
- 対応内容: `screen.getByRole("link", { name: /マニュアル詳細へ/ })` + `toHaveAttribute("href", …)` に変更
  (`@testing-library/jest-dom/vitest` は `tests/js/setup.ts` で読み込み済みなので `toHaveAttribute` が使える)。
  `data-testid` 自体は残す (他のテストや bug-hunt からの参照手段として既存流儀に合わせる)。

## [Warning] (施策 2) status dataset の型が広がる / 網羅性が弱い

- 判断: **対応する** (ただし Codex の `as const satisfies readonly VideoManualStatus[]` 案は採らない)
- 根拠: `satisfies readonly VideoManualStatus[]` は「各要素が妥当な status か」しか見ず、
  **status が増えたときにテストが自動追従しない** (Codex の懸念そのものが残る)。
  このリポジトリには既に `Record<VideoManualStatus, …>` を `satisfies` で固定した写像が
  `resources/js/types/manual.ts` に 4 つあり、その**キー集合は型で全数が保証されている**。
- 対応内容: `Object.keys(VIDEO_MANUAL_STATUS_LABELS) as VideoManualStatus[]` を dataset にした。
  status が増えれば写像側がコンパイルエラーになり、修正すると dataset も自動で増える
  (二重管理をつくらない)。

## [Warning] (施策 3) `ready` / `rendering` の 2 状態では不足。全 status で固定せよ

- 判断: **対応する**
- 根拠: 「status に依らず常に出す」が設計主張なのだから、サーバ側の到達可否も全 status で見るのが対。
- 対応内容: `VideoManualStatus::cases()` を Pest の dataset にして、全 status で両 route の
  200 + component を固定する形に書き換えた。テストは 2 本 → 1 本 (dataset 化) になる。

## [Warning] (施策 3/4) 「同じ middleware 2 本」は不正確

- 判断: **対応する**
- 根拠: そのとおり。外側 group (`auth` / `verified` / `not-pending-deletion`)、内側 group
  (`require-active-subscription` / `project.in-current-org`)、`scopeBindings()`、
  controller の `resolveOrganizationProject()`、`Gate::authorize('view', $manual)` の合成である。
  セキュリティ不変条件に関わる説明を省略形で書くと、次に読む人が省略された層を見落とす。
- 対応内容: 詳細設計・テストコメント・`docs/architecture.md` 追記のすべてを具体名の列挙に置き換えた。

## [Warning] (施策 4/施策 1 リスク) Vitest にレイアウト保証を背負わせるな

- 判断: **対応する**
- 根拠: jsdom は flex-wrap も truncate も実際の overflow も計算しない。既存の
  「レイアウト overflow ガード」テストが見ているのはクラス名の存在であって実レイアウトではない。
- 対応内容: 施策 1 のリスク欄から「mobile 幅の overflow を再確認する」を削除し、
  **クラス名の存在しか見ていない / 実レイアウトは保証しない**と書き直した。
  「保証しないもの」にも狭幅ヘッダーの実レイアウトを明記。

## [Suggestion] (施策 1) Svelte 内コメントが重い

- 判断: **対応する**
- 根拠: 判断理由の正本は `docs/architecture.md` とテスト名に置くべきで、コンポーネントに
  設計文を複写すると乖離の種になる。
- 対応内容: コメントを 2 行に圧縮し、詳細は docs 参照へ委ねた。

## [Suggestion] 「サーバ側 0 行」は「アプリ実装コード 0 行」と書くべき

- 判断: **対応する**
- 対応内容: 施策一覧の脚注を「**アプリ実装コード (route / controller / DTO / policy / Service) の
  変更は 0 行**。PHP 側に増えるのは Feature テスト 1 ファイルのみ」に修正した。

## [Suggestion] 施策 1 は DESIGN / Atomic Design 的に問題なし / Browser lane 非追加は妥当

- 判断: **対応不要** (肯定的評価)
