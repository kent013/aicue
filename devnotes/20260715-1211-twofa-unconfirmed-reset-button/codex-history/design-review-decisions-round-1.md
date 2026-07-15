# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED（主因は T1 の Critical のみ）。

## [Critical] T1 の `queryAllByText("2FA").toHaveLength(2)` が脆い / 共有 fixture 変更
- 判断: 対応する
- 根拠: 妥当。件数アサーションは無関係な fixture 追加で壊れる。加えて共有 `membersFixture`
  (id 1-4) に id=5 を足すと他テストへ波及する副作用がある。
- 対応内容: T1 を**自己完結の members 配列**（既存「admin 閲覧者」テストと同じく props に
  ローカル members を渡す）で描画するよう改訂。共有 fixture は変更しない。検証は
  reset ボタンの **id 付き testid**（行スコープ相当）で presence/absence を確認し、バッジは
  対象行の `li` へ `within` スコープして非表示を確認。件数アサーションは撤廃。

## [Warning] T1 が baseProps 依存で viewer=owner 前提が不透明
- 判断: 対応する
- 根拠: role 由来失敗と 2FA 状態由来失敗を分離すべき。
- 対応内容: ローカル fixture に viewer=owner (isSelf owner) を明示配置し、対象メンバーは
  role=editor に固定（role 条件を満たすことを arrange で明示）。

## [Warning] T2 で「拒否時は通知・監査イベントを発火しない」ことを仕様固定すべき
- 判断: 対応する
- 根拠: 副作用抑止（誤解を招く通知/監査の回避）が本施策の主眼。テストで固定する価値が高い。
- 対応内容: T2 に `Notification::fake()` + `Notification::assertNothingSentTo($member, ...)`、
  および `SecurityAuditEvent` が作られないこと、`two_factor_secret` / `two_factor_confirmed_at`
  不変を追加。

## [Warning] pending を管理者がクリア不可になる仕様変更点の運用周知
- 判断: 対応する（設計内に明記）
- 根拠: 仕様変更点。運用者に「pending は本人再設定で解消」を伝えるべき。
- 対応内容: 施策 2 の運用ノート/リスクに「pending は本人が設定画面から再生成して解消する」旨を
  1 行追記（リリースノート反映は実装時 TODO へ）。

## [Suggestion] 群（enabled 成功 vs pending 拒否の対比テスト名、PHPStan/DTO 是認 等）
- 判断: 一部反映
- 根拠: 可読性向上。
- 対応内容: T2 のテスト名を disabled 拒否テストと並ぶ命名に統一（「pending も明示拒否」）。
  その他是認系 Suggestion は現設計を維持。
