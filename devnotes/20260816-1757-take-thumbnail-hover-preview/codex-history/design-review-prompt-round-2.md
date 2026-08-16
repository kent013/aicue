# Round 2: Round 1 指摘への対応

Round 1 の [Warning] 5 件と [Suggestion] 2 件に対する判断です。**1 件だけ別手段を提案**し、
**1 件は見送り**ました。それ以外はすべて対応済みです。

## 対応マトリクス

Codex 全体判定: CHANGES_REQUESTED (施策 1 / 2 / 4 が REQUEST_CHANGES、施策 3 は APPROVE)

## [Warning] 施策 1: `AdoptedTakeReferenceInventory` の根拠文が事実とずれる

- 判断: **対応する**
- 根拠: 目録の根拠文はレビューで読まれる説明であり、`thumbnail_path` を読むようになった後も
  「id と status を表示するため」のままだと実態と食い違う。AGENTS.md の目録運用の趣旨に反する。
- 対応内容: 提示された修正案をほぼそのまま採用し、詳細設計の波及変更に
  `app/Support/Security/AdoptedTakeReferenceInventory.php` を**変更ファイルとして追加**した。
  施策一覧の表と施策 4 の「新規登録不要」の記述も「**区分は維持・根拠文は更新**」へ直した。

## [Warning] 施策 1: `status === "ready" && has_thumbnail` の語彙が充足判定と混ざらないようにする

- 判断: **対応する**
- 根拠: ドメイン規約 12 は判定式の単一化を求めており、語彙が混ざると次に読む人が
  `AdoptedReadyTakeCoverage` と同一概念だと誤解する。区分自体は変えなくてよいという判断も一致した。
- 対応内容: 「これは**サムネイル表示条件 / 404 を踏まない条件**であって充足判定ではない」と明記し、
  **`coverage` / `充足` / `ready coverage` の語をコメント・変数名・テスト名に使わない**と規定した。
  変数名は `previewable` のまま (充足を連想させない)。

## [Warning] 施策 2: `onDestroy` の `document.removeEventListener` は SSR 耐性が弱い

- 判断: **対応する (ただし提示案とは別の手段を採る)**
- 根拠: 指摘の懸念自体は正しい。ただし `typeof document !== "undefined"` の自前 guard は、
  **このリポジトリに SSR レーンが存在しない**(`vite.config.ts` / `package.json` に ssr entry が無く
  `config/inertia.php` も無い = 実読で確認) 状況では、存在しない実行環境のための分岐になる
  (思考原則 2)。より根本的なのは**登録と解除が別のライフサイクルに分かれていること**である。
- 対応内容: `onMount` の**返り値 (後始末)** で解除する形に変えた。`onMount` は非ブラウザでは走らず、
  返した後始末もブラウザでしか走らないため、**guard を書かずに対称**になる
  (フレームワークのレンジ内でやる = 思考原則 1)。`onDestroy` には document に触らない
  `clearDwell` だけを残した。

## [Warning] 施策 2: `startPreview()` で `playbackUrl !== null` を再確認していない

- 判断: **対応する**
- 根拠: 「満了時に現在の条件を再確認する」と設計に書いた以上、URL の有無だけ入口の 1 回で
  済ませるのは一貫していない。
- 対応内容: `startPreview()` に `if (playbackUrl === null) return;` を追加した。

## [Warning] 施策 4: テスト計画に SSR / 非ブラウザ耐性が無い

- 判断: **対応する**
- 対応内容: テスト計画に「listener の対称性」ケース (unmount 後に `visibilitychange` を発火しても
  `stopPreview()` が呼ばれない) を追加し、**SSR レーンがリポジトリに存在しないことを実読で確認した**
  事実と、非ブラウザ実行は**テストではなく `onMount` の対で書く構造**で担保することを明記した。

## [Suggestion] 施策 3: 非 ready のとき `/thumbnail` `/playback` の文字列が DOM に無いことも固定する

- 判断: **対応する**
- 対応内容: ScenarioEditor のテスト計画に 1 ケース追加した。

## [Suggestion] `prefersReducedMotion` を `@/lib/browser/motion` 等へ移す

- 判断: **見送る**
- 根拠: (1) 既に `features/manual` の `TakePickerList.svelte` / `TakePreviewPanel.svelte` が
  `@/lib/capture/http` / `@/lib/capture/take-endpoints` を import しており、**先例がある**。
  (2) 移設は既存の呼び出し元 (`pages/Capture/Show.svelte` / `CutSwipeBar.svelte` ほか) を巻き込む
  改名であり、本施策の目的と無関係な差分を増やす。
  (3) AGENTS.md 思考原則 3 (後方互換の並走を残さない) により、移すなら同じ PR で旧経路を消す必要があり、
  小さな施策の範囲を超える。
- 対応内容: 変更しない。指摘は妥当な観測なので、この判断を対応マトリクスに残しておく。

---

## 修正後の該当箇所

### 施策 1: 波及変更 (更新後)

- TypeScript 型定義: `CutTakeSummary.adopted` に `has_thumbnail: boolean` を追加 (**必須**)
- API Resource/DTO: `CutTakeSummaryData` のみ
- テストファイル: `tests/Feature/Manual/ScenarioVideoColumnTest.php` に 4 ケース追加
- **クエリは変えない** (追加クエリ 0 本)
- `AdoptedTakeReferenceInventory` は**区分は `DifferentCriterion` のまま / 根拠文は更新する**:

```php
'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
    'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
    'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id / status / '
        .'サムネイル生成有無を表示条件として読むだけで、採用済み ready テイクの充足判定はしない。'
        .'レンダの充足判定(AdoptedReadyTakeCoverage)とは基準が違うため意図的に統合しない。',
],
```

施策一覧の変更ファイル欄にも `app/Support/Security/AdoptedTakeReferenceInventory.php` を追加しました。

### 施策 3: 語彙の分離 (追記)

> ⚠ **語彙の分離 (ドメイン規約 12)**: この 3 条件は「**サムネイル表示条件 / 404 を踏まない条件**」であって、
> 「採用済みかつ ready のテイクを持つか」という**充足判定ではない**。
> コメント・変数名・テスト名に `coverage` / `充足` / `ready coverage` の語を使わない
> (`AdoptedReadyTakeCoverage` と概念が混ざるため)。変数名も `previewable`
> (= プレビューを張ってよい状態か) とし、充足を連想させない。

Pest のテスト名にも同じ規則を適用します (施策 4 の表に明記)。

### 施策 2: listener のライフサイクル (提示案とは別手段)

ご提示の `typeof document !== "undefined"` guard ではなく、**登録と解除を `onMount` の対にする**形へ
変えました。

```ts
    // ★ 登録と解除を onMount の中で対にする。onMount はブラウザでしか走らず、
    //   返した後始末もブラウザでしか走らないため、`typeof document !== "undefined"` の
    //   自前 guard を書かずに非ブラウザ環境と対称になる (フレームワークのレンジ内でやる)。
    onMount(() => {
        document.addEventListener("visibilitychange", onVisibilityChange);
        return () => document.removeEventListener("visibilitychange", onVisibilityChange);
    });

    // document に触らない後始末だけを onDestroy に置く (予約済みタイマーを捨てる)
    onDestroy(clearDwell);
```

判断根拠: このリポジトリには **SSR レーンが存在しません** (`vite.config.ts` / `package.json` に
ssr entry が無く、`config/inertia.php` も存在しないことを実読で確認)。存在しない実行環境のための
分岐を足すより (思考原則 2)、**登録と解除が別のライフサイクルに分かれている**という根の問題を
直すほうが確実だと判断しました。この形なら guard 無しで対称になります。

### 施策 2: `startPreview()` の再確認 (更新後)

```ts
    function startPreview(): void {
        dwellTimer = null;
        if (!hovering) return;
        if (playbackUrl === null) return; // props が入れ替わった場合に備えて再確認する
        if (prefersReducedMotion()) return;
        playing = true;
    }
```

### 施策 2 / 4: テスト計画への追加

- [ ] **listener の対称性**: unmount 後に `visibilitychange` を発火しても `stopPreview()` が呼ばれない
      (`onMount` の後始末で解除されている)。**SSR レーンはこのリポジトリに存在しない**
      (実読で確認) ため、非ブラウザ実行の検証はテストではなく**登録と解除を `onMount` の対で書く構造**で担保する

### 施策 3: テスト計画への追加

- [ ] 新規: `非 ready / has_thumbnail=false のとき DOM に "/thumbnail" と "/playback" の文字列が 1 つも無い`
      (component の不在だけでなく **404 になる URL を張っていないこと**を直接固定する)

### 施策 4: Architecture テストの記述 (更新後)

- Architecture テストへの**新規登録**は不要だが、**既存登録の根拠文の更新は要る** (施策 1)。
  「登録不要」ではなく「**区分は維持・根拠文は更新**」である。

### [Suggestion] `prefersReducedMotion` の移設 — 見送り

先例 (`features/manual` の既存 2 component が `@/lib/capture/*` を import 済み)、
移設が本施策と無関係な呼び出し元 (`pages/Capture/Show.svelte` / `CutSwipeBar.svelte` ほか) を
巻き込むこと、思考原則 3 (後方互換の並走を残さない = 移すなら同じ PR で旧経路を消す) の 3 点から、
小さな施策の範囲を超えると判断しました。指摘自体は妥当な観測なので対応マトリクスに残しています。

---

以上で Round 1 の指摘は解消したと考えます。全体判定を再度お願いします。
`onMount` の対で解除する案と `prefersReducedMotion` 見送りの判断について、
異論があれば [Critical] / [Warning] で指摘してください。
