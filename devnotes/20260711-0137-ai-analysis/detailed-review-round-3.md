## 施策別判定

- 施策1〜5: `APPROVE`
- 施策6: `REQUEST_CHANGES`
- 施策7: `REQUEST_CHANGES`
- 施策8: `APPROVE`
- 施策9: `REQUEST_CHANGES`
- 施策10〜13: `APPROVE`

## 指摘

[Warning] 施策6: Queue timeout の算定が有界リトライと整合しません。最大では `3段 × 3試行 × 120秒 = 1,080秒` となり、`timeout=600` では正当なリトライ中に強制終了されます。また Laravel の queue `retry_after` より timeout を短くする必要があります。  
修正案: worst-case時間から timeout を再算定し、`timeout < retry_after < 予約TTL` を設定・Architectureテストで固定してください。あるいは段ごとのリトライではなく、全パイプライン共通の試行時間上限を設けて600秒以内を保証してください。

[Warning] 施策7: 不正UTF-8を常に推測変換・scrubすると、未知のバイナリを日本語として誤変換し、100 bytes以上の無意味な文字列をLLMへ渡す可能性があります。`mb_scrub()` 後の長さだけでは抽出成功を保証できません。  
修正案: `mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP'], true)` のstrict判定を行い、判定不能なら `unextractable()` としてください。変換後は再度 `mb_check_encoding()` を検証し、scrubは限定的な破損補修として扱ってください。判定不能バイナリのテストも追加が必要です。

[Warning] 施策9: 検出3がファイル単位allowlistなら、`ScenarioService` 内に新しい呼び出しを追加しても通過し、「AnalysisPipelineが唯一の呼び出し元」を保証できません。またテスト計画に検出3自身の自己検証がありません。  
修正案: コメント・文字列を除外したtoken走査で、メソッド宣言と `->materializeIntoLockedManual(...)` 呼び出しを区別し、呼び出しは `AnalysisPipeline` の該当メソッド内だけ許可してください。許可外呼び出しを検出できる自己テストも追加してください。

[Suggestion] Queue timeout算定では、LLM時間だけでなくPDF/XLSX抽出、DBロック待ち、レスポンス解析の余裕も含めると運用時の誤タイムアウトを防げます。

## 全体判定

`CHANGES_REQUESTED`

Round 2で指摘したロック順、二重防御、backed enumへの対応自体は妥当です。