## 施策 1: APPROVE

[Suggestion] `previewPlaceholderStateFullyResolved` の命名は概ね妥当です。ただし boolean 名としては少し長く、意味をさらに固定するなら `placeholderReasonFullyResolved` 程度でもよいです。重要なのは設計書どおり「プレビュー全体が stale」ではなく「黒背景理由が現在は完全解消した」だけを表すことです。

[Suggestion] 1 つの `<p>` に条件文を継ぎ足す形は問題ありません。句点で文が分かれており、日本語では空白なしでも読み上げ上の大きな問題は出にくいです。`role` / `aria-live` は必須ではありません。ユーザー操作直後にこの案内が差し替わる UX を強く意識するなら `aria-live="polite"` は検討余地がありますが、現状の注記説明としては過剰になり得ます。

## 施策 2: REQUEST_CHANGES

[Warning] 契約 5 と M1 の対応が弱いです。契約 5 は旧文言「ないため、その区間が黒背景になっています」だけを否定していますが、M1「生成時点で」を現在形に戻すことを確実には殺せません。最低限、本文に `生成時点で 20 件` を含むことを契約 1 / 2 で直接 assert してください。

[Warning] M5 の説明がやや不正確です。現行 `playbackNote` は `> 0` も見ているため、「null 判定を外す」だけでは 0 表示になりません。mutation としては `playbackJob.placeholder_cut_count !== null && ... > 0` を `playbackJob.placeholder_cut_count !== null` にする、または `> 0` を外す、と明記した方が契約 3 に対応します。

[Suggestion] 契約 6 は `finishedJob` あり/なしで契約 1 の結果が同じ、で妥当です。ただし M4「判定に `finishedJob !== null` を足す」は完全解消分岐側に足された場合、契約 1 だけでは殺せない可能性があります。契約 2 でも `finishedJob` あり/なしを比較するとより堅いです。

## 施策 3: APPROVE

[Suggestion] `docs/architecture.md` に「現在 coverage は再計算ではなく表示文脈としてだけ使う」と明記する判断は妥当です。T148 の値契約を守るため、`placeholder_cut_count` と `coverage.missing_count` の責務差分を書いておく価値があります。

## testid 判断: APPROVE

[Suggestion] `preview-placeholder-note` を変えない判断は妥当です。意味は「プレビューの placeholder 注記」のままで、表示文言の精度改善に過ぎません。既存テスト、bug-hunt、E2E 参照を壊してまで変更する理由はありません。

## 全体判定: REQUEST_CHANGES

設計方針は正しいです。実装案も既存 props の範囲で閉じており、サーバ側・型契約・副作用の増加はありません。

ただし、テスト契約と mutation の対応に抜けがあります。特に「生成時点で」を直接固定しないと、今回の主目的である現在形誤読の回帰を殺しきれません。契約 1 / 2 に `生成時点で N 件` の assert を追加し、M5 と M4 の検査意図を少し締めれば approve できます。