# 対応マトリクス: design-review Round 2

Critical 2 / Warning 5 / Suggestion 1。**全件対応**した。反論は 0 件。

## [Critical] S5 対称差の比較単位が「クラス」だけでは分類の完全性を保証できない

- 判断: **対応する**
- 根拠: 正しい。クラス集合の比較では (1) 同一クラスに複数種類の到達がある場合の帰属、(2) `(class, kind)` の重複登録、(3) 既存クラス名を借りた entry が stale 判定をすり抜ける、をすべて捨ててしまう。テスト 4 が「そのクラスの登録 kind」を単数扱いしていたのも同根。
- 対応内容: 識別単位を **`(クラス, 種別)`** に引き上げ、テスト 1 を**分類済み到達集合の双方向照合**へ書き換えた。
  - (a) 各 site に対し、`class` 一致 かつ `kind ∈ ruleKinds[site.rule]` の entry が**ちょうど 1 件**（0 = 未登録 / 2+ = 帰属が曖昧）
  - (b) 各 entry に対し、対応する site が**1 件以上**（残骸検出）
  - (c) `(class, kind)` の重複が 0 件
  - 同一クラスが別々の到達事実で複数 kind を持つことは**許可**する
  - テスト 4 は「規則→種別表が `ExternalSeamRule::cases()` を exact-fit で覆う」だけに縮め、突合はテスト 1 へ一本化した
  - mutation を 3 本追加（M13 = `(class,kind)` 重複 / M15 = 対応 site の無い kind を既存クラスへ追加）
  - S6 に #20（同一クラスに Http と Mail がある場合に 2 種類の site が出る）を追加

## [Critical] S5 委譲先 test 名の「文字列を含む」検査は test の実在を保証しない

- 判断: **対応する**
- 根拠: 正しい。改名しても旧名がコメントや別のリテラルに残れば緑になる。
- 対応内容: `tests/Support/PestTestNameScanner.php` を新設し、`PhpTokenScan::normalize()`（コメント除去済み）上で **`T_STRING(test|it)` + `(` + `T_CONSTANT_ENCAPSED_STRING`** の並びから test 名を抽出する。テスト 12 は抽出集合への**完全一致**で判定する。負のコントロールとして `tests/Unit/Architecture/PestTestNameScannerTest.php` に「コメントにだけある名前は抽出しない」「文字列リテラル中の `test('` は抽出しない」を置き、mutation **M14**（委譲先 test をコメント化し同名をコメントに残す）で赤化を実測する。走査器の限界（変数・ヒアドキュメント・連結で組み立てた test 名は抽出できない）も docblock に明記した。

## [Warning] S1 `ExternalClientBoundaryScanner` の public API 維持が不明確

- 判断: **対応する**
- 対応内容: **維持する public API の一覧表**（`boundarySites` / `stripeGlobalSites` / `scan` / `describe` / `phpFiles` / `STRIPE_GLOBAL_SYMBOLS`）を S1 に明記し、`phpFiles()` は実体を `PhpReferenceScanner` へ移して**委譲ラッパーを残す**コードを提示した。

## [Warning] S5 M3 の期待結果にテスト 10 が含まれるのは誤り

- 判断: **対応する**
- 根拠: 正しい。`FACADE_RULES=[]` でも `entries()` は残るため kind × dimension の被覆は成立し続ける。
- 対応内容: M3 の期待する赤を **テスト 1(b) + テスト 7** に訂正し、「テスト 2 も テスト 10 も赤にならない」と明記した。

## [Warning] S5 mutation の採番と分類が不整合

- 判断: **対応する**
- 対応内容:
  - 実装順序の「M1〜M13」を **M1〜M15** に訂正（coverage 表と ID 表も同期）
  - P1 / P2 を **等価変形**（緑のままであることを確認する枠）と改称
  - P3 を等価変形から外し、**規則強化の負のコントロール N1**（規則を緩めると S6 #6 が赤）へ移動。あわせて **N2**（facade 判定に `StaticCall->receiver` 分岐を足すと「ちょうど 1 件」が赤）を追加した

## [Warning] S6 分類単位に対応するテストが必要

- 判断: **対応する**（上記 Critical 1 と同一の対応で解消）
- 対応内容: S6 #20 の追加 + gate 側の分類単位を mutation（M1 / M2 / M13 / M15）で確認する手順を明記した。合成した目録断片ではなく mutation で確認する形にしたのは、目録が値の器であり「gate が本物の目録に対してどう振る舞うか」を見たいため。

## [Warning] S8 「3 箇所に同じ内容を書く」方針と AGENTS.md 案が一致していない

- 判断: **対応する**
- 根拠: 正しい。同文複製はドリフトする。
- 対応内容: **記載場所の契約**を「`docs/architecture.md` §外部到達点の目録 (標準形 v1) を詳細の正本とし、gate 冒頭と AGENTS.md 規約 9 には要約と正本への参照を書く」へ変更した。AGENTS.md の追記案にも欠けていた 4 項目（`.env.bughunt.local` / 決済の別 API 表面 / 部分修飾名 / 他種別の宛先集合）を要約として足し、「完全な一覧は正本」と明記した。「3 箇所に同じ内容」という記述は削除した。

## [Suggestion] S2 リスク節に残っていた「facade は `StaticCall` の receiver 経由でも拾う」

- 判断: **対応する**
- 対応内容: 該当箇所を削除し、「canonical 契約（facade は `NameReference` のみ）を守らないと 1 呼び出しが 2 site に数えられる」へ差し替えた。あわせて「`http_facade_reference` が名乗れる種別が 2 つあるため、同一クラスが両方の entry を持つと帰属が曖昧になり テスト 1(a) が赤くなる」というリスクも追記した。
