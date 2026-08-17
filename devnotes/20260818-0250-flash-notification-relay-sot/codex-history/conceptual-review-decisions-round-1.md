# 対応マトリクス: conceptual-review Round 1

## [Critical] 4. ドリフト検査が語彙集合しか見ておらず、visitKey / prop 名の改名を検出できない
- 判断: 対応する
- 根拠: 指摘のとおり。背景節で「全通知が無音で消える」主因として `visitKey` の片側改名を
  挙げておきながら、検査の設計が種別集合の一致だけでは主張と設計が食い違う。
  `TsUnionValues` は union 抽出器であり、キー名までは見ない。
- 対応内容: 概念設計の「両レーンに検査を置く」を書き直し、**検査対象を 3 つ**に明記した。
  (a) 種別語彙の集合一致 (b) 共有 prop 名 `flash` の一致 (c) de-dup キー名 `visitKey` の一致。
  TS 側は `visitKey` を直書きのまま残さず `FLASH_VISIT_KEY` という 1 つの定数から
  `FlashPayload` と `consumeFlash` の参照を導出し、両レーンがその定数値を照合する形にする。

## [Warning] 3. Session::get() は mixed。PHPStan level 10 で array shape と整合しない
- 判断: 対応する
- 根拠: 正当。加えて壊れたセッション値 (配列・オブジェクト) が TS 側の string 契約を破る。
- 対応内容: 中継クラスの内部で **文字列以外は null に正規化**する方針を制約節に追加。
  戻り値は array shape で固定し `visitKey` は必ず string。

## [Warning] 4. 中継が実際に共有 props に繋がっていることは静的検査では見えない
- 判断: 対応する
- 根拠: 委譲漏れ (middleware が中継を呼ばずに直書きへ戻る) は静的一致検査を素通りする。
- 対応内容: 実装方針に **Feature テスト 1 本の追加**を明記
  (共有 `flash` の shape・4 種別が session から載ること・visit ごとに visitKey が変わること)。
  併せて「既存テスト更新は不要」という断定を撤回した。

## [Warning] 5. 正典の実ファイル未確認のまま「正典準拠」と称するリスク
- 判断: 対応する
- 根拠: 正当。台帳へ到達できていない以上、正典準拠は現時点で未検証の仮定である。
- 対応内容: 概念設計の位置づけを「現行課題を解く設計 + 正典との**後追い照合を完了条件に含む**」
  へ改めた。照合で食い違ったら正典に合わせる (合わせないなら逸脱台帳へ登録)。
  TODO 登録時にもこの照合を完了条件として持ち越す。

## [Warning] 7. FlashPayload を手書きのまま残すと union と二重管理になる
- 判断: 対応する
- 根拠: 正当。TS 側の中に 2 重定義が残ったままでは今回の目的 (出所を 1 つにする) を満たさない。
- 対応内容: `FlashPayload` を
  `Partial<Record<FlashNotificationKind, string | null>> & { visitKey?: string | null }`
  として導出する方針を明記。`FLASH_KEYS` は `readonly FlashNotificationKind[]` で制約する。

## [Suggestion] 3. get() と UUID 発行の意味論を変えないことを明記
- 判断: 対応する (追記のみ)
- 対応内容: 「振る舞いは同値。読み出し元・発行時点・毎 visit 新値という意味論を変えない」を明記。

## [Suggestion] 1 / 5 / 6 (使命整合・billing_feedback_kind 除外・スコープ範囲)
- 判断: 対応不要 (肯定的評価)
