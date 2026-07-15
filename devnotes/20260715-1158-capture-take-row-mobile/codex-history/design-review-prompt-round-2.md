# design-review Round 2（対応報告）

Round 1 の指摘への対応を報告します。全体判定 CHANGES_REQUESTED → 再判定をお願いします。

## [Critical] 施策2: クラス文字列依存で実重なりを検知できない → 一部対応＋一部反論

**受け入れ基準を二段構えに明文化しました**（設計に「受け入れ基準」節を追加）:

1. 自動回帰ガード = vitest 構造契約テスト（`flex-wrap` / `w-full` / `min-w-0` / `sm:` の付与を検証）。CI 常時実行。
2. 受け入れゲート = 実装 PR で **375 / 320 / 768px**、**採用中+DL済み 両バッジ同時**の最悪ケースの
   実ブラウザ screenshot を取得し「重なり 0・アイコン欠け無し・『採用』切れ無し・768 は従来 1 行」を目視確認（必須ゲート）。

**Playwright ビジュアル回帰 CI の新設は見送り（反論）**: 現状アプリに E2E/Playwright harness が存在しません
（`package.json` に e2e スクリプト無し、`tests/e2e` 無し、playwright は bug-hunt スキルの隔離環境のみ）。
単一レイアウト回帰のためにビジュアル回帰 CI 基盤を新設するのは思考原則「今必要なものだけ作る
（オーバーエンジニアリング禁止）」に反するため、上記の手動 screenshot ゲート＋構造契約テストの二段で担保します。
将来ビジュアル回帰 CI を導入する際に再訪する旨を設計に明記しました。この判断で受け入れ可能かご確認ください。

## [Warning] 施策1: 640-767px 帯の 1 行成立根拠 → 対応

設計本文に根拠を明記: 操作列 ≈190px + chevron ≈30px = ≈220px、640px でも残り ≈400px がラベル列に確保され
窮屈化しない（640-767 も余裕で 1 行成立）。`md`(768) だと 640-767 の小型端末まで冗長に 2 段化するため
`sm`(640) を採用。

## [Warning] 施策1: 操作列の総幅増 failsafe → 対応

操作列に mobile のみ `flex-wrap gap-y-1` を許可（tablet は `sm:flex-nowrap` で 1 行維持）。
変更後クラス: `flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start`。
翻訳・将来ボタン増でボタン総幅が段幅を超えても右端で溢れず段内 wrap する。

## [Warning] 施策2: testid 依存の DOM 耐性 → 対応

テスト方針を明記: `data-testid` はレイアウト契約点のみに付与、文言/表示有無は role/text クエリ優先。

## [Suggestion] 施策2: 最小ケース追加 → 対応

`adopted=null` かつ `downloaded=false` でバッジが混入しないテストを 1 件追加。

## [Suggestion] 施策1: ラベル列 pr-1 → 見送り

2 段化＋wrap で視覚衝突は構造的に解消済み、DS 由来の gap で足りるため任意調整に留めます。

---

## 更新後の主要差分（クラス）

| 要素 | after |
|------|-------|
| 行 | `flex flex-wrap items-center gap-x-2 gap-y-2 ... sm:flex-nowrap` |
| chevron 列 | `flex shrink-0 flex-col gap-1` |
| バッジ `<p>` | `flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-body` + `data-testid=take-label-${id}` |
| 操作列 | `flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start` + `data-testid=take-actions-${id}` |

vitest 追加ケース: 行 wrap / 操作列 wrap+sm 復帰 / バッジ行 wrap+min-w-0 / 両バッジ同一ラベル内 / 最小ケース非混入。
受け入れゲート: 375/320/768px 両バッジの実ブラウザ screenshot 目視。
</content>
