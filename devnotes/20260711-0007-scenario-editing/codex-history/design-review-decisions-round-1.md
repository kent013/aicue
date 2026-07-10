# 対応マトリクス: design-review Round 1

## [Critical] 施策3: steps.*.points の present だけでは欠落エラーパスが不明瞭
- 判断: 対応する
- 対応内容: `steps.*` に `required_array_keys:points` を追加（Laravel 9.32+ で利用可・12 も可）。
  `steps.*.points` の present + array + max と併用し、points キー欠落を行単位の明示 422 にする。

## [Critical] 施策4: no-op 保存でも version+1 は競合を不要に誘発（仕様確定が必要）
- 判断: 現方針（常に +1）を維持し、レビュー指示どおり設計根拠とクライアント戦略を明文化する
- 根拠: doc/10 §10.8-2 は「成功時 scenario_version += 1」を確定契約とし、§10.8 は §10.1〜§10.7 に
  優先する（実装ブリーフでも必須制約に指定）。仕様の文言を設計側で変えない。
  実害は「同一内容を同時編集」した他クライアントのリロード 1 回に限定される。
- 対応内容: 施策 4 の設計判断に §10.8-2 準拠の根拠 + クライアント戦略を規定:
  (a) 保存クライアントは成功応答の scenario_version を必ず取り込む（applySaved）、
  (b) 他クライアントは 409 の current_version 入りバナー → 明示同意リロード（自動再読込は
  ローカル編集を破壊しうるため行わない）。テスト #9 の期待値も一本化。

## [Critical] 施策6: 401/419（セッション・CSRF 失効）の復旧導線が未定義
- 判断: 対応する
- 対応内容: handleResponse に分岐を追加。419 は cookie 再取得（同一オリジン GET）→ 1 回だけ
  自動リトライ（doc/10 §10.8-3 の 419 共通処理方針と同型）。401（またはリトライ後 419）は
  作業コピーを破棄せず「別タブでログインし直してから再保存」を案内（リダイレクトしない）。
  Vitest に 419 リトライ / 401 メッセージ + 作業コピー保持を追加。

## [Critical] 施策7: Service 境界テストが cross-project 2 件のみでは不足
- 判断: 対応する
- 対応内容: 新規 `tests/Feature/Projects/ScenarioServiceTest.php`（Service 直テスト S1〜S5）:
  cross-project 拒否 / id 重複 ValidationException / 異物 id ModelNotFoundException /
  階層降格 422 / 階層昇格 422（すべて DB 不変も検証）。

## [Warning] 施策2: ScenarioPointData::id が int 固定だとフロントの id:null と齟齬
- 判断: 対応する
- 対応内容: サーバ shape は id: number（int）に統一。未保存行 id: null は編集中の作業コピー
  専用型 `DraftStep` / `DraftPoint`（TS）に分離（Omit ベース）。PUT payload は Draft 型を直列化。

## [Warning] 施策2: `public const string CODE` は PHP 構文上不正
- 判断: 反論する（レビュー側の誤り）
- 根拠: 型付き class 定数は PHP 8.3 で導入された正規構文（本リポジトリは PHP 8.4.21）。
  既存コードに同構文の前例が 3 件ある: `app/DataTransferObjects/Auth/RecentAuthRequiredDto.php:17`
  `TwoFactorRequiredDto.php:18` / `TwoFactorDisableForbiddenDto.php:20`（いずれも
  `public const string CODE = '...';`）。既存規約に合わせてそのまま採用する。
- 対応内容: 施策 2 の設計判断に前例パスを明記。

## [Warning] 施策3: null→'' 正規化の責務が Request と Service に分散
- 判断: 対応する
- 対応内容: `prepareForValidation()` で正規化し、rules は `present + string`、DTO は非 null
  文字列で統一。Service 側の正規化記述を削除。

## [Warning] 施策4: keyBy('id') 単一集合では型不一致判定がすり抜けやすい
- 判断: 対応する
- 対応内容: 既存集合を `existingSteps` / `existingPoints` に事前分離し、payload 位置と厳密照合
  する形へ save() / assertPayloadIds() の骨子を書き換え。

## [Warning] 施策4: each->delete() の大量時メモリ
- 判断: 見送る（根拠明示）
- 根拠: 入力は有界（steps≤100 × points≤20 = 最大 2100 行）で chunk 化は過剰設計。
  上限が外れる設計変更時に chunkById へ移行する旨を設計判断に明記。

## [Warning] 施策6: dirty の JSON 比較はキー順/不要キーで誤判定
- 判断: 対応する
- 対応内容: PUT payload を組み立てる `payloadSteps()`（キー順固定・送信フィールドのみ）を
  snapshot 比較にも共用し、比較と送信の正規形を一本化。

## [Warning] 施策6: saving=true 依存のみでは多重送信レースが残る
- 判断: 対応する
- 対応内容: save() 冒頭に `if (saving) return;` を明示（disabled 禁止は維持し、押下は受けて
  即 return）。Vitest に「保存中の再押下で fetch 1 回のみ」を追加。

## [Warning] 施策7: テスト #9 は version 方針の確定が前提
- 判断: 対応する（上記 Critical の確定で解消）
- 対応内容: #9 の注記に「施策 4 で確定済み（§10.8-2 の文言どおり常に +1）」を明記。

## [Suggestion] fromManual の O(n^2)
- 判断: 対応する（groupBy('parent_cut_id') の 1 パス整形に変更）

## [Suggestion] 409 code 厳密一致テスト
- 判断: 対応する（テスト #5 に `code === 'scenario_conflict'` 厳密一致を追加）

## [Suggestion] draft→ready は状態遷移表への追記前提で固定
- 判断: 対応する（施策 8 に doc/10 §10.2 状態遷移表への追記を追加）
