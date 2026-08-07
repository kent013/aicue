# 対応マトリクス: design-review Round 4

全体判定は **CHANGES_REQUESTED**（Critical 0 / Warning 2）。両方とも対応した。反論はゼロ。

## [Warning] S1-1: 追加した role 検証分岐が現データでは一度も実行されない

- 判断: **対応する**（指摘が正しい。空振り検知を自分の gate に適用し忘れていた）
- 根拠: `CACHE_PAYLOAD_SURFACE_INVENTORY` に該当 role の entry が 0 件なので、
  検査 5 に足した分岐は**実行されない**。実装を反転・削除しても全テストが緑のままで、
  これは本設計が繰り返し主張してきた「空振りしない検査」「正負コントロールで規則を固定する」に
  真っ向から反する。**自分の gate に自分の原則を適用していなかった**という指摘に完全に同意する。
- 対応内容: role 判定を純関数
  `cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): list<string>` に切り出し、
  検査 5 はそれを呼ぶだけにした。加えて **検査 5b「role 判定規則そのものの正負コントロール」**を新設し、
  3 role すべての許可・拒否パターン（許可 6 / 拒否 10）を実在ファイルの構成に依存せず固定した。
  テスト本数は 21 → 22 になった。

## [Warning] S1-2: `read-only` という role 名と許可語彙が一致していない

- 判断: **対応する**（Codex の提案 2 = role 名の変更を採る）
- 根拠: `CACHE_PAYLOAD_NON_WRITE_METHODS` には `forget` / `flush` / `clear` / `increment` /
  `purge` など**読み出しではない操作**が含まれる。`Cache::flush()` だけを呼ぶファイルが
  「read-only」を名乗れるのは名前と実態の乖離であり、AGENTS.md 思考原則
  「機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している」に反する。
  本 gate の目的は **payload 制約**なので、role 名もその軸で切るのが正しい。
- 対応内容: role を `read-only` → **`no-payload-write`**（キャッシュに触れるが
  任意 payload を書く API を呼ばない）に改名し、定義コメント・fail message・
  検査 4 の復旧手順文言をすべて同期した。`lock-only` を独立に残す設計は維持
  （排他は payload 制約とは別の責務で、`JobExecutionDedupInventoryTest` 側の担当と接続するため）。
  現状の目録は `write` 1 件 + `lock-only` 4 件で、`no-payload-write` は 0 件のまま
  （規則は検査 5b が固定するので空振りしない）。

## S2 / S3 / S4 / S5: APPROVE（変更なし）
