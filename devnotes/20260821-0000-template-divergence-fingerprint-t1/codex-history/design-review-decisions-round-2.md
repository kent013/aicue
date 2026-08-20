# 対応マトリクス: design-review Round 2

## [Critical] (S3) 母集合を毎回「新正典キー ∩ 現在の追跡ファイル」で再計算すると、ローカル削除でパスを母集合から消せる
- 判断: 対応する
- 根拠: 指摘のとおり。`$previousLedger` を債務追加の判定にしか使っておらず、母集合の縮小規則が無かった
- 対応内容: 規則 2 を新設。初回生成は「新正典キー ∩ 現在の追跡パス」、2 回目以降は
  **「新正典キー ∩ (現在の追跡パス ∪ 旧アプリ台帳のキー)」**。ローカル削除したパスは母集合に残り
  gate では `MissingCurrent` になる。母集合から外れるのは正典側から消えたパスだけ。
  同じ正典入力での母集合の縮小そのものを拒否 (exit 3)。負例 4 本をテスト計画へ追加

## [Critical] (S4) 実プロセステストの root 切替方法が無く、本物の生成物を書き換える危険がある
- 判断: 対応する
- 根拠: 指摘のとおり。CLI が `dirname(__DIR__)` を使うなら cwd を変えても出力先は本物である
- 対応内容: §生成器の構造 を新設し 2 層に分けた。CLI = **薄い引数解析層のみ** (root は
  `dirname(__DIR__)` 固定。**root を差し替える隠しオプションは作らない**)、
  `FingerprintGenerationService` = root・入力・出力先・writer・git 実行を引数で受ける通常クラス。
  テストの割り当ても表にした — 生成の判定は service を一時ディレクトリ root で直接呼ぶ /
  実プロセスは**書き込み前に終了する経路だけ**を扱う

## [Critical] (S5) `PathObservation` の定義場所が一覧に無い (autoload が解決できない)
- 判断: 対応する
- 対応内容: `tests/Support/TemplateDivergence/PathObservation.php` を新規ファイル一覧と S5 の
  変更箇所へ追加 (1 クラス 1 ファイル)。readonly DTO として不変条件
  (`Matched`/`ContentMismatch` なら hash 非 null・理由 null / 検査不能なら理由 非 null・hash null) を
  コンストラクタで検査することも明記。新規 PHP は 18 本になった
  (`FingerprintGenerationService` の追加も含む)

## [Critical] (S9) D33 の対象パスに `AtomicLedgerWriter.php` が無い (3a が発火する)
- 判断: 対応する
- 根拠: 整合式では「相違 4 件」に数えていたのに本文の対象パスが 3 件だった。指摘のとおり
- 対応内容: D33 の対象パスへ `tests/Support/TemplateDivergence/AtomicLedgerWriter.php` を追加
  (9 パスへ)。忘れると 3a が発火することも注記した

## [Critical] (S9) D34 の対象パスが旧名 `adoption-debt.txt` のまま
- 判断: 対応する
- 対応内容: `adoption-debt.tsv` へ訂正 (TD3 の実在検査と F12 が落ちるため)

## [Critical] (S11) C3 でスキル 2 本を編集すると新モデルでは `mutatedDebtPaths` になる。波及変更に旧モデルの説明が残っている
- 判断: 対応する
- 根拠: 指摘のとおり。「債務パスは編集しても食い違いのまま」は Round 1 で廃止した挙動の記述である
- 対応内容: S11 の波及変更を書き換え、C3 は 3 択のうち**「登録を書いて債務から削る」**を必ず選ぶ形にした。
  新規登録 **D35** (対象パス = スキル 2 本。既存 88 パスと重複しないことを実測で確認) を足し、
  同じコミットで pin を `32 → 33` / `176 → 174`、債務一覧から 2 行削除、D34 本文の件数、
  S7 の現物期待値を更新する。母集合 281 は不変。
  **フェーズ別 pin の表**を冒頭に追加し、C2 = 32/176/281、C3 = 33/174/281 を受入条件に含めた。
  (S11 をスコープから外す案は採らない — 確認段は概念設計で APPROVED 済みの t3 要素であり、
  「共有ファイルを変えたら登録する」を自分自身の変更で実演する形になるため)

## [Warning] (S2) テスト計画に「10 形」の旧記述が残っている
- 判断: 対応する / 対応内容: 「11 形」へ統一し、dataset 名を件数の正本とすることを明記

## [Warning] (S2) 生成器の入力に正準形バイト一致が無く、載せ替え経路で非正準 JSON を採用できる
- 判断: 対応する
- 対応内容: 入力自身に
  `$templateRaw === FingerprintLedger::fromJson($templateRaw)->toJson()` を要求し、
  不一致は**書き込み前の exit 1** とした

## [Warning] (S2) byte 一致の drift 検出先が「F8 (3a)」のままだが現在は F9
- 判断: 対応する / 対応内容: F9 へ訂正

## [Warning] (S3) `AtomicTextWriter::replace(): ?string` は戻り値を無視すると fail-open
- 判断: 対応する
- 対応内容: `replace(): void` + `RuntimeException` にした。移植した `AtomicLedgerWriter` は
  正典の形 (戻り値) を保つので、**呼び出し側が null 以外なら即 exit 1 する**ことを
  コードとテストで固定する旨を docblock とテスト計画に書いた

## [Warning] (S3/S7) テスト経路数の表記が実数と合っていない
- 判断: 対応する
- 対応内容: atomic writer は **dataset 8 件 (正常系 1 + 失敗 7)** へ統一。
  S7 に「件数の表記は dataset を正本とし本文と一致させる」を明記
  (`FingerprintLedger` 11 / `AdoptionDebtInventory` 11 / `RepoRelativePath` 8 / writer 8)

## [Warning] (S4) 「件数 pin が合わないので必ず赤」は常に成立しない (増減が相殺され得る)
- 判断: 対応する (指摘が正しい。証明手段を足した)
- 対応内容: 2 生成物に**共通の世代識別子**を持たせた — 債務一覧の先頭行を
  `# template_ledger_commit=<40 桁 hex>` とし、**F14** が指紋台帳の `generated_at_commit` との一致と、
  各債務の採用時ハッシュが正典ハッシュと異なることを突き合わせる。
  部分更新の 3 状態 ((a) JSON だけ新世代 / (b) 債務一覧だけ新世代 /
  (c) 件数が同じで内容だけ違う) を失敗注入で作り、判定が実際に赤になることを固定する

## [Warning] (S5) `AdoptionDebtInventory` の「9 形」が実際は 10 形
- 判断: 対応する / 対応内容: ヘッダ行の検査を足したので **11 形**として本文・S7・dataset を統一

## [Warning] (実装モード) 旧記述「新規ファイル 12 本」が残っている
- 判断: 対応する / 対応内容: 「新規 PHP 18 本 + 生成物 2 本」へ統一し、実装順序にフェーズ別 pin を併記

## [Suggestion] (S6) F13 は F9/F10 の入力前提なので、突合器へ渡す前に評価する
- 判断: 対応する / 対応内容: F13 の説明に「解析違反を持つ入力から登録リストを組み立てない
  (突合器へ渡す前に評価する)」を明記 (番号は変えない)
