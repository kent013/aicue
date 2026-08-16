# 対応マトリクス: design-review Round 1

## 施策 3

### [Warning] 「送信中は再送しない」テストが `if (loggingOut) return;` を検証できていない
- 判断: **対応する**
- 根拠: 指摘のとおり。jsdom では `disabled` な button への `fireEvent.click` は onclick を発火しないので、
  2 回クリックのテストは **Button atom の `disabled={disabled || loading}` だけ**を固定している。
- 対応内容: テストを 2 つに分ける。
  1. 「ログアウト送信中はボタンが押下不可になる」— 1 回目のクリック後に `toBeDisabled()` を確認し、
     2 回目のクリックで `router.post` が増えないことを確認する (Button の loading 契約を固定)。
  2. `if (loggingOut) return;` は **DOM 経由では到達しない多重防御**であることを実装コメントに書き、
     テストで固定しない (到達不能な経路のテストを作らない)。テスト名も「押下不可になる」に改め、
     何を固定しているかを名前と一致させる。

### [Warning] URL 直書き vs route helper 規約
- 判断: **対応する (根拠を明記して直書きを維持)**
- 根拠: 実リポジトリを調べた。`package.json` / `composer.json` / `resources/js` のどこにも
  Ziggy は無く、`route("…")` 形式の呼び出しも 0 件である。既存は全て URL 直書きで、
  `pages/Dashboard.svelte` L328 の `href="/app"`、`pages/Capture/Show.svelte` の
  `href={`/app/projects/${project.id}/manuals`}` が前例。
- 対応内容: 施策 3 / 4 に「本リポジトリは route helper を持たず URL 直書きが規約である」ことと
  前例を明記した。

### [Suggestion] `currentOrganization` null 時の UI テストの位置づけ
- 判断: **対応する (表現のみ)**
- 対応内容: 「防御的表示の検証」ではなく「偽の既定値を出さないことの補助テスト」と書き直した。

## 施策 4

### [Suggestion] `PageHeader` → `PageHeaderSection` 置換で既存テストが壊れないか
- 判断: **対応する (確認事実を明記)**
- 根拠: `PageHeader.svelte` は `<PageHeaderSection {title} {description} {icon} {testId} />` の
  1 行ラッパーであり、置換してもマークアップは同一。既存 `tests/js/pages/CaptureIndex.test.ts` は
  4 ケースとも `capture-mine` / テキスト照合 / `router.get` 検証で、スナップショットも
  role/name 照合も使っていない。
- 対応内容: 施策 4 の「設計判断」にこの確認結果を追記した。

## 施策 5

### [Suggestion] docs は architecture test の走査対象外であることを明示
- 判断: **対応する**
- 対応内容: 走査根は `resources/js` 配下の `.svelte` / `.ts` のみで、`docs/` を見ない
  (= docs に `/logout` の語を増やしても目録は反応しない。docs 更新は人が守る約束である) と明記した。

## 施策 6

### [Warning] 既存ドリフトがあったときの判定基準が「報告する」だけでは PR を緑にできない
- 判断: **対応する**
- 対応内容: 判定基準を明記した。
  - 生成差分が **`capture.account` 由来の行だけ**なら本タスクに含める。
  - それ以外の差分が出たら**実装を止め、別タスク化して設計レビューへ戻す**
    (無関係な行を混ぜてレビュー不能にしない)。ドリフトを本タスクで巻き取らない。

## 施策 7

### [Warning] 「撮影者 (project_member) でも 200」はテスト名と実体がずれている
- 判断: **対応する**
- 根拠: 指摘のとおり。この route は project 非依存なので、到達条件は organization 在籍だけであり
  `attachProjectMember()` は効かない。
- 対応内容: テスト名を
  「**組織メンバー (撮影者ロールの利用者) でも 200 — project role はこの route の条件ではない**」に変え、
  `attachProjectMember()` は「現場で実際にこの画面へ来る人物像を作る」ためであり
  **到達条件ではない**とコメントで明記した。

### [Warning] `expect($owner->id)->not->toBe($stranger->id);` は前提確認として弱い
- 判断: **対応する**
- 対応内容: 確かめたいのは「`$stranger` が `$organization` に所属していないこと」なので、
  `expect($organization->users()->whereKey($stranger->getKey())->exists())->toBeFalse();` に置き換えた。

### [Warning] `page` の mock 方式
- 判断: **対応する (既存方式に合わせることを明記)**
- 根拠: `tests/js/pages/SettingsIndex.test.ts` が `vi.hoisted()` で
  `pageState = { props: {}, url: "/settings" }` の plain object を作り
  `vi.mock("@inertiajs/svelte", …)` で `page` に差し替える形を既に使っている。
- 対応内容: 「既存 `SettingsIndex.test.ts` と同一の mock 方式を使う」と設計に明記した。

### [Suggestion] `container.textContent` に `"42"` が含まれない検証は偽陽性を生む
- 判断: **対応する**
- 対応内容: テスト用の `auth.user.id` を `987654321` (他の表示値と衝突しない値) にし、
  併せて**なぜその値なのか**をコメントに書いた。

## 施策 2

### [Suggestion] `resolveMemberCurrentOrganization()` の戻り値を変数で受ける
- 判断: **見送る**
- 根拠: 未使用変数は `pnpm lint` 相当の PHP 側 (Pint / PHPStan) では素通りするが、
  「使わない変数を作る」ほうが読み手に「この後で使うのか」と探させる。
  副作用目的であることは 1 行コメントで足りる。Codex も「必須ではない」としている。

## 付随して見つかった事実 (設計へ反映)

`pages/Dashboard.svelte` L328 に **`href="/app"` の「撮影アプリを開く」ボタンが既にある**
(testId `qa-capture`)。よって概念設計 G3 (「`/settings` から `/app` へ戻る可視導線が無い」) は
**「ドロワー → ダッシュボード → 撮影アプリを開く」の 2 ホップで回復できる**のが正確であり、
行き止まりではない。G3 をスコープ外にする判断がさらに補強される。概念設計にも訂正を入れる。
