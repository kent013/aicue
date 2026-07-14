# 対応マトリクス: conceptual-review Round 1

## [Critical] 「別名保存」と「複製」を同一視している（観点1）
- 判断: 対応する（中間案を採用）
- 根拠: doc/04 は「別名保存 = 新タイトル・カテゴリの別動画として複製」。Codex path 1（Edit 画面の live-buffer Save As）は、未保存の scenario 編集内容ごと新 manual へ退避することを意味し、`scenario.update` の全バリデーション経路を複製 endpoint に持ち込む大工事になり v1「今必要なものだけ作る」に反する。一方 path 2（機能名を複製に下げ gap 未解消）は doc/04 要件を放置する。→ **両者の中間**: 複製時に**新タイトル + カテゴリを入力**できるようにして doc/04 の「新タイトル・カテゴリ」を満たす。複製元は**保存済み**の cuts（= 保存済みシナリオ）とする。未保存エディタバッファの Save As は out-of-scope として明示。
- 対応内容: (1) 複製は新タイトル + category を受け取る。(2) 応答は redirect のまま。(3) 「保存済み manual を雛形にする」ため UI は詳細画面（Show）配置が整合的（保存済み状態を複製する操作の住処）。未保存バッファ Save As を out-of-scope に明記。

## [Warning] title/category 入力は FormRequest で（観点2, 7）
- 判断: 対応する
- 根拠: 入力を増やすなら Controller 直受けでなく専用 FormRequest。StoreVideoManualRequest が見本。
- 対応内容: `DuplicateVideoManualRequest`（`title` required|string|max:200、`category` nullable + project 内 exists、保護キー category_id は 422）を追加。Controller は validated のみ参照、Service は category を locked project から再解決。

## [Warning] 共有ロック規約: 新規 row 作成ケースの明文化（観点3）
- 判断: 対応する
- 根拠: cuts を書く先は新規 manual。規約文面「対象 VideoManual 行を lockForUpdate した同一 tx 内」を新規 insert ケースにどう当てるか明示すべき。
- 対応内容: 設計に「新 manual を同一 tx 内で insert → その tx 内で cuts を materialize。新 row は commit 前で他 tx から不可視のため別ロック不要（規約の目的 = 同一 manual への並行 writer 直列化は満たす）。元 manual は lockForUpdate で一貫読み取り」と明記。`docs/architecture.md` の書き込み経路表へ追記。

## [Warning] 「gap #4 を閉じる」は強すぎる（観点4）
- 判断: 対応する（表現を調整）
- 根拠: 中間案でも未保存バッファ Save As は残る。
- 対応内容: 効果を「複製経路の構造的欠落（route/UI ゼロ）を解消し、doc/04 の『新タイトル・カテゴリの別動画として複製』を満たす。未保存エディタバッファの Save As は out-of-scope」と正確化。

## [Warning] SOP 非引き継ぎの UX 事故（観点5）
- 判断: 対応する
- 根拠: 「複製したのに SOP が付かない」誤認防止。
- 対応内容: 成功フラッシュに「手順書（SOP）は引き継がれません」を明記。複製後の詳細画面は通常の SOP アップロード導線を持つ。

## [Warning] テスト登録方針が弱い（観点5b）
- 判断: 対応する
- 根拠: 本 repo はテスト登録まで含めて設計完了。
- 対応内容: 概念設計にテスト計画を明示（IDOR inventory 追加 / 権限別 Feature / reset 確認 / source_documents・takes・jobs 非複製確認 / vitest UI）。詳細設計で施策化。

## [Warning] Show 配置は要求最小でない（観点6）
- 判断: 一部対応（配置は Show 維持 + 根拠強化）
- 根拠: 複製対象は**保存済み** manual。詳細画面（Show）は保存済み manual の住処であり、そこから複製するのは整合的。Edit 画面の live-buffer Save As こそが out-of-scope 部分。title/category 入力ダイアログを持たせることで「要求最小」（新タイトル・カテゴリ）に到達する。
- 対応内容: Show に「複製」ボタン → Modal（title FormField + category Select、元値プリフィル）→ POST。

## [Suggestion] 機能名を「複製」に（観点1後半 / 観点6後半）
- 判断: 対応する（呼称整理）/ 同一 project 限定等は維持
- 対応内容: 概念設計の呼称を「複製（別名保存の実体）」に整理。同一 project 限定・takes/render/jobs 非複製・TTS 対象外は維持。
