# 対応マトリクス: design-review Round 3

[Critical] 0 件。[Warning] 1 件・[Suggestion] 1 件。**両方対応**（反論なし）。

## [Warning] 別ファイルへ置くプラン有効化テストから `expectNotThrottled()` を参照できる保証がない
- 判断: **対応する**
- 根拠: 指摘のとおり。Pest のテストファイルで宣言したグローバル関数を別ファイルから
  参照する構成は、ファイル単独実行・ロード順・`--filter` 指定に依存する。
  本設計は **mutation 確認で 1 ファイルずつ走らせる手順**を規定しているため、
  この依存は仮定ではなく確実に踏む。
- 対応内容:
  1. helper 名を `expectNotThrottled()` → **`throttleProbeExpectNotThrottled()`** に変更
     （同ファイル既存の `throttleProbePost()` / `throttleProbeResolvedClasses()` と同じ接頭辞。
      Pest のグローバル関数汚染を抑える）。docblock に
     「**このファイル内でのみ使う**」と明記した。
  2. `ActivatePersonalTest.php` 側へ置くプラン有効化テストは helper を呼ばず、
     残数ヘッダ検査と 429 検査を**直接書く**形に変更した。
     `tests/Support` のクラス化は利用箇所が 1 か所のため見送る
     （AGENTS.md 思考原則 2「今必要なものだけ作る」。Codex も直接記述を推奨）。
  3. S8 の注記に「ファイル配置とテスト間依存」の節を追加し、
     なぜ跨いで呼ばないのかと、追加する `use` 文を明記した。

## [Suggestion] mutation M9 の対象テストを限定して記録する
- 判断: **対応する**
- 根拠: 指摘のとおり。M9-a で `recent-auth.password` の throttle を剥がすと、
  「パスワード照合レーンを使い切っても…」は 7 回目の 429 期待で**先に赤になる**ため、
  `AuthThrottleCoverageTest` 全体が緑になるわけではない。
  「全体が緑」と記録すると観測が誤りになる。
- 対応内容: M9-a / M9-b の primary を
  「`recent-auth.password` を cross-lane probe としてのみ使う 4 本
  （Livewire / 2FA 管理 / メール検証 / プラン有効化）」に限定し、
  collateral 欄に「パスワード照合レーン枯渇テストは 7 回目の 429 期待で赤になるため対象外」と明記した。

## [補足] Codex の重点観点への回答
- vendor provenance の残るすり抜け（名前空間 allowlist 自体の変更 / 名前空間の偽装 /
  独自 middleware による user resolver 設定）は設計に限界として明記済みであり、
  目録型 gate として妥当との評価を得た。追加対応はしない。
- 単純な自己完結 assertion（文字数検査・母集団下限・共有グループの 2 本以上）に
  専用 mutation を割り当てない判断も妥当との評価。17 項目から増やさない。
