# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Round 1)。Critical なし。以下 Warning/Suggestion の判断を記録する。

## [Warning] scenario-history.ts: isSerializedRow が NaN/Infinity を許容 (id / static_display_seconds)
- 判断: 見送る（反論）
- 根拠: parseHistorySnapshot の入力は常に同一セッション内 serializeSteps() の出力 (in-memory)。
  履歴は localStorage 等へ永続化せず、外部・跨セッションのデータ経路が存在しない。
  かつ JSON 仕様上 NaN/Infinity は表現不可 (JSON.stringify(NaN)==="null" / JSON.parse は受理しない)。
  よって実データ経路で NaN/Infinity は発生し得ず、Number.isFinite 追加は「今必要でない防御」= 不必要な複雑化
  (AGENTS.md 思考原則2・禁止事項6相当)。将来 localStorage 永続化を導入する場合は同時に有限数チェックを足す。
- 対応内容: コード変更なし。本判断を履歴に記録。

## [Warning] ScenarioEditor: isEditableField が SELECT を含み app undo を抑制
- 判断: 見送る（設計どおり・仕様明文化済み）
- 根拠: 詳細設計 3-4 / 3-5 の「編集フィールド内は native の文字単位 undo に委ねる (R1 決定)」に SELECT を
  含める前提。SELECT にフォーカスがある間はブラウザ native の選択操作に委ね、フィールド外で document undo。
  設計判断として確定済み。
- 対応内容: コード変更なし。設計書 3-4 コメントで方針は明文化済み。

## [Warning] テスト: partial mock の holder.real 代入タイミング依存
- 判断: 見送る（現状問題なし）
- 根拠: vi.mock factory は import 時に一度実行され holder.real を設定、beforeEach で毎回 real へ復帰する
  (app-codex-review R3 Suggestion 反映済みの構造)。現行で脆弱性はなく、Codex も「現状コードでは問題なし」と明記。
- 対応内容: コード変更なし。

## [Suggestion] 履歴専用型の独立 / Draft コメント強化 / corruption 文言 / NaN テスト
- 判断: 見送る
- 根拠: いずれも非ブロッキングの将来変更耐性向上提案。現行スコープ (v1・保存前ローカル編集) では
  過剰。SerializedRow=DraftPoint は type-only 依存で意図的 (施策1 設計)。
- 対応内容: コード変更なし。
