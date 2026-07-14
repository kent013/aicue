# 対応マトリクス: design-review Round 1

## [Critical] 施策3/F: MAX_STEPS(100)+2=102 の境界が曖昧（仕様変更）
- 判断: 対応する（意味の再定義で固定。定数リネームはしない）
- 根拠: MAX_STEPS は LLM 生成/手動編集 step の DoS/桁 guard。導入/総括はサーバ定型 2 カットで別枠。生成 step を削るのは実手順欠落＝使命違反のため不可。
- 対応内容: ScenarioLimits::MAX_STEPS の doc コメントに「生成/編集 step の上限、定型 2 カットは別枠、materialized 総数最大 102」を明記。施策6 に境界テスト（生成 step=100 → 102 materialize、切り捨て/reject なし）を追加。

## [Warning] 施策2/5: line() が未定義キーで $key を返し文面欠落を静かに見逃す
- 判断: 対応する
- 対応内容: line() を Lang::has() チェック→未定義は LogicException（fail-fast）。Assert::string で型を閉じる。施策5 に全利用キー存在テストを追加。

## [Warning] 施策3: trim が全角空白を落とせない
- 判断: 対応する
- 対応内容: normalize() を新設（preg_replace '/^[\p{Z}\s]+|[\p{Z}\s]+$/u'）。recap 抽出の空判定を normalize 経由に。施策5/6 に全角空白のみ→非採用テスト追加。

## [Warning] 施策7/E: 件数 2→4 だけでは位置・型・親子の退行を見逃す
- 判断: 対応する
- 対応内容: 既存テスト更新に構造アサート追加（先頭/末尾 top-level=Hiki・parent_cut_id=null、生成 point が中間 step 配下）。成功パス L139-142 も同様に更新。

## [Suggestion] config 0以下は1扱いコメント / DI 解決の早期確認 / 再生成別文言テスト / max_points=0,-1 防御テスト
- 判断: 対応する
- 対応内容: config コメント追記。施策6 に「再生成の再掲元が今回生成のみ」テスト（1回目/2回目で別 point 文言）追加。施策5 に max_points=0/-1 防御テスト追加。DI 解決は施策6 の完走テストで担保。
</content>
