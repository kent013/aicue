# 対応マトリクス: design-review Round 2

## [Critical] `SopTextExtractor::countBy()` 削除で `decodeRunAsSjis()` (SJIS 復元) が壊れる
- 判断: 対応する
- 根拠: 指摘のとおり実装できないコードだった。`countBy()` は文書受理ゲート用の
  `japaneseRatio()` だけでなく、SJIS 復元判定 `decodeRunAsSjis()` からも
  `MULTIBYTE_JAPANESE_PATTERN` を渡して呼ばれている。全削除は誤り。
- 対応内容: 切り出す範囲を「文書受理ゲート用の `JAPANESE_PATTERN` / `NON_SPACE_PATTERN` /
  `japaneseRatio()` だけ」に限定し、`countBy()` と `MULTIBYTE_JAPANESE_PATTERN` は
  `SopTextExtractor` に残す設計へ訂正した。

## [Critical] OCR 失敗時に route が記録されず失敗率を算出できない
- 判断: 対応する
- 根拠: 指摘のとおり、当初案は `withBoundedRetry()` 成功後にしかログを出しておらず、
  media 検証失敗・OCR 応答スキーマ違反・provider エラー等の失敗ジョブには route が
  一切残らない。
- 対応内容: `resolveExtractInput()` 内で route が決まった時点 (成功・失敗を問わず) に
  `logRouteOutcome()` を呼ぶよう変更し、`runExtractStep()` の LLM 呼び出し段階の失敗も
  別途 route 付きでログするよう try/catch を追加した。これにより media 検証失敗・
  LLM 呼び出し失敗・成功のいずれでも route と failure_reason が記録される。

## [Warning] 機能フラグが Service だけをゲートし、FormRequest/Inertia Props/UI と不整合
- 判断: 対応する
- 根拠: 指摘のとおり、フラグ無効時に「UI では JPEG 対応と表示されるが Service で拒否される」
  という不整合が残っていた。
- 対応内容: `AcceptedSourceDocumentTypes` という単一の情報源を新設し、
  FormRequest・`SourceDocumentService`・Inertia Props (`sourceDocumentAccept`) の
  全てがこれを経由する設計にした。Inertia Props への変更が発生することを認め、
  当初「Inertia Props 変更なし」としていた記述を訂正した。

## [Warning] persisted mime と実バイトの形式一致を確認していない
- 判断: 対応する
- 対応内容: `getimagesizefromstring()` が返す `'mime'` キーと `$document->mime` の一致を
  `validateImage()` に追加した。

## [Warning] `$width * $height` の乗算がオーバーフローを前提にしている
- 判断: 対応する
- 対応内容: 先に 1 辺の長さの上限を検査し、画素数上限は `intdiv()` を使った除算で判定する
  よう書き直した。0 次元 (width/height が 0 以下) の拒否も追加した。

## [Warning] PDF の 0 ページが許可される
- 判断: 対応する
- 対応内容: `pageCount < 1` を `MediaUnreadable` として拒否する分岐を追加した。

## [Warning] `loadWithMedia()` の PHPStan 配列型注釈が不足
- 判断: 対応する
- 対応内容: `$untrusted`/`$variables`/`buildConversationMessages()` へ `@param`/`@return` を
  追加し、親のジェネリクス/戻り値型契約との共変性を実装時に確認することを明記した。

## [Warning] 契約テストが reflection レベルまでで `llm_call_logs` まで届く確認がない
- 判断: 対応する
- 対応内容: `llm_call_logs` の実際の行 (organization_id/subject_type/subject_id/
  prompt_template) を検証する Feature テストをテスト計画へ追加した。
  `PromptUntrustedInputContractTest` が既に明記している fake 実行時の既知の限界
  (`Prompt::$fake` は `executePrism()` の先頭で短絡する) に該当する場合は、
  既存方針どおり `dev:pipeline-smoke` の llm-evidence 段に委ねることを明記した。

## [Suggestion] 「vendor 媒体型の呼び出し箇所が 1 件」の表現が再び曖昧
- 判断: 対応する
- 対応内容: 「許可ファイル 1 件」と「呼び出し件数ちょうど 2 件」を明確に分離して記載した。

## [Warning] `[UNREADABLE]` でも「短い日本語 1 文 + 大量の判読不能」が閾値を超える可能性
- 判断: 対応する (部分対応 + 手動評価へ委譲)
- 根拠: マーカー除去後に文字が 1 つも残らない場合の構造的な下限は安価に追加できるが、
  「部分的な判読不能」をどこまで弾くかは閾値の感度次第であり、値の妥当性は
  概念設計が既に手動評価の対象に含めている領域と同じである。
- 対応内容: マーカー除去後に空文字列になる場合は比率計算を待たず無条件拒否する
  構造的な下限を追加した。閾値の感度そのものはロールアウト前手動評価の確認項目に
  含めることを明記した (思考原則: 仕組みが機能していない段階で値を弄らない)。

## [Warning] 静的 gate の「あらゆる構築手段」が動的呼び出し・subclass を説明していない
- 判断: 対応する
- 対応内容: 静的解析で解決できるのは「リテラルなクラス名を受信者にした static 呼び出し」までで
  あることを明記し、動的な間接呼び出し (`$class::method()` 等) は
  AGENTS.md §走査器の共通規約 (b) の fail-closed 原則に従って違反候補に含める設計にした。
  vendor の `Image`/`Document` が `final` かどうかを実装着手時に確認し、
  `final` でなければ subclass 定義の出現も検出対象に含めることを明記した。

## [Warning] 「母集団が空でない」が scanner 自己検査と本 gate で意味が異なる
- 判断: 対応する
- 対応内容: scanner 自己検査 (合成入力の候補数非空) と本 gate (実装後の期待件数の exact-fit)
  を区別して記載した。

## [Warning] 「provider 側の拒否が実際の上限を担保する」という表現が安全境界として不正確
- 判断: 対応する
- 対応内容: provider の hard limit が一次情報で確認できる場合は `AnalysisMediaValidator` の
  送信前チェックへ直接反映し、provider の拒否は最後の砦として位置づける表現に改めた。

## [Warning] 上限値・見積り定数が依然として仮値のまま
- 判断: 対応する (先送りを許さない形へ明記)
- 対応内容: 一次情報の出典・参照日・導出式の確定を実装着手前の必須項目として明記し、
  確定するまでこの施策一式を実装完了とみなさないことを明記した。

## [Warning] UI 変更対象コンポーネントが依然として未特定
- 判断: 対応する
- 根拠: 実コードを検索し、`resources/js/components/features/manual/SourceDocumentUpload.svelte`
  (FormField molecule 使用、既にサーバーエラー表示・disabled ボタンなし) を特定した。
- 対応内容: 具体的なファイルパスと変更内容 (accept 属性を Props 経由へ差し替え) を明記した。

## [Warning] Inertia Props でフラグ状態をどう伝えるか未定
- 判断: 対応する (上記 `AcceptedSourceDocumentTypes` 対応と同一)

## [Critical] rollout gate がフラグを Service だけにしか適用していない (施策 11)
- 判断: 対応する (施策 1 の対応と同一の根本修正)

## [Warning] `.env` 変更だけでは config cache 環境で反映されない可能性
- 判断: 対応する
- 対応内容: rollout 手順に config cache 再生成・プロセス再起動・smoke check を明記した。

## [Warning] フラグ無効化時の queued/running ジョブの扱いが未定義
- 判断: 対応する
- 対応内容: 「無効化は新規判定にのみ適用し、既に判定を終えて実行中のジョブは
  最後まで走らせる」ことを明記した。
