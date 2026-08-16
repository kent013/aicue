# 対応マトリクス: design-review Round 4

## [Warning] 施策 E: `getAllByTestId("upload-queue-bar")` は正常な 0 件で例外になる

- 判断: **対応する** (指摘のとおり。テストが書けない計画だった)
- 根拠: `UploadQueueBar` は `{#if pendingCount > 0 || quotaMessage !== null}` を内側に持つ。
  未送信 0 件の通常状態では要素そのものが無いので、`getAllByTestId` は
  **重複していない正常な状態で** 例外を投げる。
  さらに `queryAllByTestId(...).length <= 1` に替えるだけでは、
  未送信 0 件のまま検査すると**二重描画を作っても緑になる** (検出力ゼロ) 。
- 対応内容: `queryAllByTestId` を使い、かつ**未送信テイクがある状態を用意して**
  inline / fullscreen の**両方でちょうど 1 件**であることを固定する形に書き換えた。
  「0 件でも落ちない」と「二重描画を実際に検出できる」の両方を満たす。

## [Suggestion] 施策 C: 矩形検査を guide × secondary にも広げる

- 判断: **対応する**
- 根拠: 設計は primary / guide / secondary の **3 レーンが交差しない**と主張しているのに、
  機械保証が guide × primary の 1 組だけでは主張と保証がずれる
  (本リポジトリが繰り返し戒めている「保証範囲の誇張」に当たる)。
- 対応内容: `subtitle_primary` / `subtitle_secondary` / `shooting_point` の 3 つとも
  非空のカットを用意し、**`guide × primary` と `guide × secondary` の 2 組**を
  `getBoundingClientRect()` で検査する形にした。

## [Suggestion] 施策 F: `docs/supported-browsers.md` の保証列挙に非交差検査も加える

- 判断: **対応する**
- 根拠: 同ファイルは「Browser レーンが実際に何を固定しているか」を列挙する場所であり、
  施策 C で足す検査を書かないと文書と実際の検査範囲がずれる。
- 対応内容: 追記文の列挙に
  「撮影ガイドと字幕 (上下 2 帯) の矩形が互いに交差しないこと」を足した。

## 施策 A / B / C / D / F: APPROVE

- 判断: **対応不要**。
