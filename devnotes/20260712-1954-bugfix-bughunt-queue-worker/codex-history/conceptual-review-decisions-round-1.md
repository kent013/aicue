# 対応マトリクス: conceptual-review Round 1

全体判定: APPROVED (Round 1)。Critical なし。Warning 4 件はすべて「対応する」とし、
概念設計・詳細設計に反映する。

## [Warning] drift check を bash 文字列解析に寄せると壊れやすい (実現可能性)
- 判断: 対応する
- 根拠: config/queue.php は素の PHP 配列 return であり、`php -r` + vendor/autoload (env() ヘルパ) で
  実評価して database driver の専用 connection 一覧を機械抽出できる。grep より構造的に堅牢。
- 対応内容: self-test の drift check を「`php -r` で config/queue.php を require し、
  driver=database かつ名前が既定の `database` でない connection 一覧を抽出 → スクリプト側の
  worker 起動リストと比較」に変更。概念設計 4. self-test 拡張を更新。

## [Warning] 「有限時間内に completed / failed へ到達」は言い過ぎ (期待効果)
- 判断: 対応する
- 根拠: completed 到達は LLM/ffmpeg fake の配線状況 (スコープ外) に依存する。本修正が保証するのは
  「専用 connection のジョブが無限 queued に滞留しない」ことと「失敗時も終端状態が UI に返る」こと。
- 対応内容: 期待効果・受け入れ条件を「queued 停滞の解消」+「失敗時も UI に終端状態が返る」に絞り、
  completed は fake 配線に依存する旨を明記。

## [Warning] teardown の子 pid 採取 → master kill に race がある (リスク)
- 判断: 対応する
- 根拠: 採取と kill の間に listener が新しい `queue:work --once` 子を spawn しうる。
  process group 単位の停止なら構造的に取りこぼさない。
- 対応内容: worker を `setsid` で専用 process group (pid==pgid) として起動し、teardown は
  cmdline 検証後に `kill -TERM -- -{pid}` (process group kill) で master+子を一括停止する設計に変更。
  pgrep -P による子採取ループは不要になる。

## [Warning] keepdb-check の worker 生存確認が kill -0 だけだと stale pidfile / pid 再利用を誤判定 (リスク)
- 判断: 対応する
- 根拠: teardown と同じ cmdline 検証 (`queue:listen` + connection 名) を preflight にも適用すべき。
- 対応内容: keepdb-check の worker 生存確認を「pidfile 存在 ∧ /proc/{pid}/cmdline に
  `queue:listen` と当該 connection 名を含む」に強化。概念設計 3. を更新。

## [Suggestion] 受け入れ条件「S3 手順 5/8/9 を再走査可能」の明文化
- 判断: 対応する (期待効果に明記)

## [Suggestion] manifest に start timestamp / pgid も残す
- 判断: 対応する (worker pid = pgid (setsid) を manifest に記録。started_at は既存 provision 記録で兼ねる)

## [Suggestion] 実装完了条件に「queued のまま止まらない」再現解消確認を含める
- 判断: 対応する (詳細設計のテスト計画に provision → ジョブ投入 → 終端状態到達の実機確認手順を含める)
