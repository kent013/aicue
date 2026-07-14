# 対応マトリクス: conceptual-review Round 2

## [Critical] pivot 行 lockForUpdate では phantom (同時 INSERT/新規所属) を防げない。組織行を共通ロック基点にし全経路で統一せよ
- 判断: 対応する（scope を限定して採用）
- 根拠: 妥当。deleteAccount の allow 判定を覆し得る並行操作は 2 つ:
  (a1) 別 Owner の降格 (`changeRole`) — 2 Owner を読んで両者が進むと 0 Owner 成立、
  (a2) 新規メンバー追加 (`joinOrganization`=招待受諾) — 「他メンバー無し→許可」判定後に追加され孤児化。
  いずれも pivot 行ロックでは phantom を止められない。組織 (`organizations`) の親行を共通ロック境界にすれば止まる。
  重要な事実: **メンバーシップ書き込みは `OrganizationMembershipService` の唯一の窓口に集約済み**なので、
  「全経路で統一」は 1 ファイル内に閉じ、cross-cutting な散乱にはならない (= 過剰複雑化しない)。
- 対応内容: 共有 private helper `lockOrganizations(Organization ...$orgs)`（`organizations` 行を **id 昇順**で `lockForUpdate`, デッドロック回避）を導入。owner 数/メンバー数を変える全メソッド（`joinOrganization`・`changeRole`(+`applyConsoleRole`/`normalizeOrganizationRole`)・`removeMember`・`transferOwnership`・新規 `deleteAccount`）がトランザクション冒頭で同 helper を呼ぶ。`transferOwnership` の既存 pivot 行ロックは同一基点へ寄せる（挙動不変・OwnershipTransferTest は緑のまま）。deleteAccount は対象組織を昇順ロック後にロック内で述語再評価。

## [Warning] 不変条件を強制する Architecture テストが無い
- 判断: 対応する
- 根拠: AGENTS.md「不変条件は Architecture/Feature テストへの登録まで含む」。本リポジトリは inventory/drift-guard 方式の Architecture テスト慣習を持つ (`NestedRouteIdorDefenseTest` 等)。
- 対応内容: `OrganizationMembershipService` の public メソッドを reflection で列挙し「mutating メソッドはロック対象 inventory に登録必須」を強制する drift-guard Architecture テストを追加。新規 mutating メソッドが未登録なら fail。

## [Warning] pivot ロックのままでは「新規 Owner 不在組織を防止」を保証できない
- 判断: 対応する（上記 Critical 対応で解消）
- 根拠・対応内容: 組織行共通ロックにより a1/a2 とも直列化される。

## [Warning] AccountDeleted をトランザクション内・削除前に発行するとロールバック時に外部リスナーが誤処理し得る
- 判断: 反論する（対応不要）
- 根拠: `SecurityEventRecorder::record` は `security_audit_events` への純 DB insert のみで、Laravel event / 外部副作用の dispatch を持たない (best-effort・例外は report に握り潰す)。トランザクション内で記録すればロールバック時に監査行も巻き戻る = 「削除していないのに deleted 記録が残る」を防げて正しい。逆にコミット後発行だと user_id が nullOnDelete で null 化された後になり既存テスト期待 (user_id null で残存) と整合。したがって「削除確定と同一トランザクション内・削除直前」で問題ない。設計にこの根拠を明記する。

## [Warning] 成功時の logout/session invalidate/regenerate が設計から消えている
- 判断: 対応する（明記漏れ。挙動は現状維持）
- 根拠・対応内容: `deleteAccount` 成功後に Controller で `Auth::logout()` → `session()->invalidate()` → `session()->regenerateToken()` → `redirect()->route('home')` の順で行う旨を設計に明記（現行 destroy の順序を保持）。ブロック時は例外伝播でここに到達しない。

## [Warning] errors.account の表現が曖昧
- 判断: 対応する
- 根拠・対応内容: PHP 側 `ValidationException::withMessages(['account' => '先に...移譲...'])`、Svelte 側 `$page.props.errors.account` と明記。props は `list<array{name:string,slug:string}>`（PHPStan L10 適合）。
