# 詳細設計レビュー Round 2 — Critical 1 件 / Warning 6 件への対応報告

Round 1 の全 Critical / Warning に対応した。再判定を求める。

## 対応内容

1. **[Critical] 検出側検体の個別発火の保証** → 既存の「真値が空振りしていない」(合計 > 0) を
   「検出側の各見本から真値が取れている」(検体ごとの `warnings` 非空 + 診断情報維持) へ
   **置き換える**設計にした。検体一覧の完全一覧 pin と合わせ「一覧の検出側検体すべてが
   個別に発火する」契約になる。検体ごとの警告**数**の pin は追加しない — 既存の
   名前・行の完全一致照合 (`globalUseSorted` 同士の `toBe`) が真値との件数一致まで
   固定しており二重になるため。

2. **[Warning] 施策1 の fail-first 手順** → 3 段へ書き換えた:
   (1) env を設定しない `buildProcess()` を振る舞い変更なしで抽出 (全テスト緑のまま) →
   (2) 配線検査を追加し `getEnv()` が `[]` で `['LC_ALL' => 'C']` と不一致の赤を確認 →
   (3) 明示 env を追加して緑化。

3. **[Suggestion] 施策1 の保証範囲の表現** → テスト名を「php -l の Process 組み立てが
   LC_ALL=C を明示している」へ変更。docblock・D54・リスク欄に「機械保証は builder の
   明示 env が LC_ALL=C の 1 変数ちょうどであることまで。inspect() が builder を経由する
   ことはコードレビューで見る (迂回は検査に映らない)」と明記。テスト用 DI は足さない。

4. **[Warning] 読み込み失敗の fail-open** → `globalUseScanTrackedTree()` へ注入 seam
   (`?array $targets = null`。null なら `TrackedPhpSourceFiles::all()`) を設け、
   `@file_get_contents` の失敗を `RuntimeException` で走査ごと落とす形へ変更
   (追跡下ファイルが読めない作業ツリーは異常であり、unresolved に積んで続行する価値が無い)。
   自己検査「読めない走査対象は無言で除外せず走査ごと失敗する」(存在しないパスの注入 → 例外)
   を fail-first で先に置く (現行 `continue` では例外にならず 0 ファイル走査で返る赤を確認)。

5. **[Warning] 両方向の同時観測** → 検体照合テストを
   `['unresolved' => …, 'entries' => globalUseSorted(…)]` の 1 つの構造比較へまとめ、
   旧実装での初回の赤で unresolved 非空と真値不一致 (検出漏れ) の双方が同じ差分に出る形に
   した (無違反側も expected の entries を `[]` にした同形)。

6. **[Warning] D54 の保証記述の過大** → 「揃え続ける不変条件と保証機構」を実際の機械保証の
   範囲 (検体照合 / 検体ごとの真値非空 / Process builder の明示 env pin / ガードの検体固定 /
   母集団の縮退検査 + 読めないファイルでの失敗) へ書き直した。

7. **[Warning] D54 の業務要件起因の説明** → 「本 gate は CI 全滅事故の再発防止装置であり、
   撮影 PWA と課金を持つ本アプリの変更安全性 (リリース継続性) がその検出力に依存する。
   6 root へ戻すと root 外の置き場が走査域から落ちて再発検出が置き場依存になる」という
   運用上の必要性を先頭へ据え、単一出典規約を手段として後置した。

8. **[Warning] 検証コマンドの不足** → 実装手順 7 へ AGENTS.md VERIFICATION_COMMANDS の
   全 10 コマンド (`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
   `pnpm build:packages` / `pnpm test:packages`) を列挙し、`composer fix` は実装中の整形、
   最終検証の正本は `vendor/bin/pint --test` と書き分けた。

9. **[Suggestion] ファイル数表記** → 「8 ファイル (走査器 / oracle / gate 本体 / 新規検体 2 /
   逸脱登録簿 / 債務一覧 / pin)」へ修正。

## 修正後の実装手順 (要約)

1. env なしの `buildProcess()` 抽出 (緑のまま) → 2. 配線検査で赤 → LC_ALL=C で緑 →
3. 読み込み失敗の自己検査で赤 → fail-closed 化で緑 → 4. 検体 2 本 + 一覧 + pin +
構造比較化 + 検体ごとの真値非空検査で赤 (突合 gate も mutatedDebtPaths で赤) →
5. ガード実装で緑 → 6. D54 + 債務削除 + LedgerPins で突合 gate 緑 →
7. 検証コマンド全数 → 8. lctl 報告 (実装フェーズ)。

全体判定を APPROVED にできるか、残る Critical/Warning があれば指摘されたい。
