# 対応マトリクス: design-review Round 2

## [Critical 施策3] `toDraftSteps()` 二重呼び出しで初期 dirty=true
- 判断: 対応する
- 根拠: 決定的なバグ。clientKey 採番後は 2 回の `toDraftSteps` が別キーを振るため
  `serializeSteps(steps) !== snapshot` となり初期表示から dirty=true（離脱警告・保存済み表示が後退）。
- 対応内容: 作業コピーを一度だけ生成し snapshot も同一値から作る:
  `const initialSteps = toDraftSteps(scenario.steps); steps = $state(initialSteps);
   snapshot = $state(serializeSteps(initialSteps));`

## [Warning 施策1/2] clientKey の重複・空文字を許容
- 判断: 対応する
- 根拠: 破損履歴に重複 clientKey / 空文字があると keyed each が破綻。
- 対応内容: (a) `isSerializedRow` で `clientKey` を非空文字列に（`.length > 0`）。
  (b) `parseHistorySnapshot` で復元対象全体（step + 全 point）の clientKey 一意性を検証し、
  重複時は null。util テストに step 同士・point 同士・step×point 重複・空文字の拒否を追加。

## [Warning 施策3/4] payload に clientKey が混入しないことのテスト
- 判断: 対応する
- 根拠: 型変更後の最重要セキュリティ境界（保護キー混入防止）。
- 対応内容: PUT payload の step/point 双方に `clientKey` プロパティが存在しないことを明示テスト。

## [Suggestion 施策3] `clientKeySeq` は「インスタンス内」
- 判断: 対応する
- 根拠: instance script 宣言のためコンポーネントインスタンスごとの状態。用語修正。
- 対応内容: コメントを「インスタンス内カウンタ」に修正。

## [Critical 施策4] 初期表示で dirty 無し・Undo/Redo disabled のテスト
- 判断: 対応する
- 対応内容: render 直後に `scenario-dirty-indicator` が存在せず Undo/Redo が disabled である
  ことを検証（二重採番バグの回帰検出）。

## [Warning 施策4] vi.mock の hoist が同一ファイル他テストへ波及
- 判断: 対応する
- 対応内容: `vi.hoisted` の mock fn + `importOriginal` の partial mock で実 export を保持し、
  既定実装は real に委譲。fail-safe テストのみ `mockReturnValueOnce(null)`。beforeEach で既定
  実装を再設定し他テストへ波及させない、という手順を設計に明記。

## [確認済] relatedTarget 反論・restoreFrom/fail-safe・施策3b は Codex が妥当と承認
- 追加対応なし。
