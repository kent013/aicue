**全体判定: CHANGES_REQUESTED**

大枠の方針、つまり `users.last_login_at` を足さず `security_audit_events` の `login` から導出する判断は妥当です。ただし、施策 D の migration 安全性と、数え方の前提、テスト 7 の落とし方に修正が必要です。

**施策 A: APPROVE**

[Suggestion] `withCasts(['last_login_at' => 'immutable_datetime'])` は Laravel の範囲内の素直な解です。pgsql の `max(timestamp)` の別名属性に対して runtime cast を使う設計は妥当です。

[Suggestion] `Assert::numeric($userId)` より、実装時に可能なら `Assert::integerish($userId)` の方が意図に近いです。`numeric` は float も含むため、ID の narrowing としては少し広いです。修正案:

```php
Assert::integerish($userId);
```

[Suggestion] `selectRaw('user_id, max(occurred_at) as last_login_at')` は動きますが、SQL 断片を少し狭めるなら以下が読みやすいです。

```php
->select('user_id')
->selectRaw('max(occurred_at) as last_login_at')
```

**施策 B: APPROVE**

[Warning] `$organizationMembers->pluck('id')->all()` が PHPStan level 10 で `list<int>` と認識される保証は弱いです。設計内にも代替案がありますが、実装案としては最初からこちらを採る方が堅いです。修正案:

```php
/** @var list<int> $memberIds */
$memberIds = array_values(array_map(
    static fn (User $member): int => $member->id,
    $organizationMembers->all(),
));
```

[Suggestion] `MemberRowData::fromUser(..., ?CarbonImmutable $lastLoginAt)` を必須引数にする判断は良いです。渡し忘れをコンパイル時に落とせます。

**施策 C: APPROVE**

[Suggestion] `formatDateTime(member.lastLoginAt, "記録なし")` に寄せる判断は既存 SSoT と整合しています。DESIGN.md / Atomic Design の観点でも、新規 token・hex・SVG を増やさないため問題ありません。

[Suggestion] 追加行の `data-testid` は妥当ですが、表示だけの要素なのでテスト目的以外の DOM 契約が増える点は認識しておく程度で十分です。

**施策 D: REQUEST_CHANGES**

[Critical] `up()` で同一 `Schema::table()` 内に「新索引作成 → 旧索引削除」を書くと、Laravel が生成する SQL の順序は概ね期待どおりでも、旧索引と新索引の共存時間があり、巨大表では lock と書き込み停止が発生します。現時点で `CONCURRENTLY` を採らない判断自体はあり得ますが、詳細設計としては deploy 時の停止許容を明示した migration になっていません。修正案: 少なくとも migration コメントに「短時間の認証系 INSERT 待ちは許容する」ことを明記し、実行タイミングを低トラフィック時に限定する運用条件を書いてください。無停止を要件にするなら `withinTransaction = false` + `CREATE INDEX CONCURRENTLY` + invalid index 復旧手順まで設計が必要です。

[Warning] 「新索引が旧索引を完全に包含する」は、等価検索 `where user_id = ? and event_type = ?` には正しいですが、旧索引の全用途を完全保証する表現としては強すぎます。B-tree の左端一致として使える、という範囲に留めるべきです。修正案: 「既存の `user_id, event_type` 前方一致クエリでは代替可能」に表現を下げてください。

[Warning] `dropIndex(['user_id', 'event_type'])` は既定命名なら再構成できます。ただし、既存 migration が既定名であることに依存します。設計としては OK ですが、よりレビュー耐性を上げるなら既存名をコメントに固定し、必要なら明示名で drop してください。

[Warning] `down()` は旧索引を作ってから新索引を落とす順序で妥当ですが、同じく lock を取ります。rollback 時も認証経路に影響することをリスクに明記してください。

**施策 E: APPROVE**

[Warning] `occurred_at` の既存 cast は `datetime` なので、factory に `CarbonImmutable::now()` を入れても、モデルから読めば通常は mutable Carbon 側に寄る可能性があります。今回の lookup は alias に `immutable_datetime` を明示するため問題ありませんが、factory の説明で「監査モデル自体が immutable を返す」と読めないようにしてください。修正案: factory の `occurredAt(CarbonImmutable $at)` は入力都合と明記する。

[Suggestion] `HasFactory` の PHPDoc は `SecurityAuditEventFactory` の import も必要です。

**施策 F: APPROVE**

[Suggestion] RC-8 を既存トリップワイヤとして使い、新 gate を足さない判断は妥当です。同じ不変条件を二重に pin すると、将来の変更時にレビュー観点が分散します。

**施策 G: REQUEST_CHANGES**

[Critical] テスト 7 の代替案が弱いです。「`RecordSecurityEvent::handleLogin` が viaRemember を区別しない」だけを unit 的に固定しても、実際の remember-me 復元で `Login` が発火すること、またアプリ配線で監査行が増えることは守れません。修正案: 代替案は最低でも `SessionGuard` recaller 経路を HTTP で踏む Feature テストにしてください。どうしても困難な場合でも、`SessionGuard` が発火した `Login` イベントを listener が記録する統合寄りテストに落とし、単なる handler 直接呼び出しで済ませないでください。

[Warning] 数え方の網羅性に 2FA 途中離脱の明示テストがありません。設計上は `Login` が 2FA 完了後にだけ発火するなら途中離脱は数えないのが正しいですが、それを固定するテストが必要です。修正案: 「パスワード通過後、2FA challenge 未完了では login 行が増えない」を追加してください。

[Warning] Filament admin guard の扱いがテスト計画にありません。`RecordSecurityEvent` は guard 名を metadata に入れるだけで、施策 A は guard を絞っていません。つまり `admin` guard の `login` 行に `user_id` が入れば `/manage/users` の最終ログインに混ざります。設計が「users プロバイダのセッション guard は web だけ」と主張するなら、Filament admin が別モデル/別 provider で `asUser()` により null になることをテストまたは architecture 的に固定してください。修正案: admin guard の Login が `User` として記録されない、または `metadata.guard = web` のみ数える、のどちらかに設計を決めてください。

[Warning] 招待受諾の扱いが「login 記録なし」の想定だけでは不十分です。招待受諾フローが登録直後にログインさせるなら `Login` が発火して数えるべきです。修正案: 実際の招待受諾フローが自動ログインするかを設計に明記し、必要なら Feature テストを追加してください。

[Suggestion] G-2 の「1人と10人でクエリ数が同じ」は良いですが、既存の roles/pivot/invitations の件数差で揺れない fixture にする必要があります。last login 以外の差分を極力固定してください。

**重点論点への回答**

- 施策 A の query/cast は概ね妥当です。`withCasts` + `Assert::isInstanceOf(CarbonImmutable::class)` で level 10 対応の方向性も良いです。
- 施策 D の新索引は左端一致として旧索引を代替できますが、「完全包含」と言い切るのは強すぎます。migration lock の扱いも補強が必要です。
- 「Login イベント発火集合 = 数える集合」は良い名前付けですが、2FA 途中離脱、招待受諾、Filament/admin guard は未固定です。
- 施策 F は妥当です。RC-8 が既に pin しているなら gate 追加は不要です。
- G-1/G-2 は方向性として十分ですが、remember me の代替案、2FA 途中離脱、admin guard、招待受諾の検査が不足しています。