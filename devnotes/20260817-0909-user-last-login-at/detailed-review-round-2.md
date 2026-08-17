全体として Round 1 の主要指摘には適切に対応しています。2つの反論も妥当です。ただし、施策 D の性能保証、施策 G のテスト主体、検証コマンドの3点に Warning が残ります。

## 施策 A: APPROVE

`select()` と `selectRaw()` の分離、`withCasts()`、`Assert::integerish()`、`Assert::isInstanceOf()` の組み合わせは妥当です。Laravel 12 / PostgreSQL の戻り値差を Eloquent の一時 cast で閉じ、PHPStan level 10 に必要な narrowing も行えています。

[Suggestion] PHPStan が `Assert::integerish()` 後の `(int) $userId` を期待どおり扱うかは実装時に確認が必要ですが、設計上の問題ではありません。型を緩めず、PHPStan結果に応じて局所的に組み替える方針で十分です。

## 施策 B: APPROVE

`array_map()` と `array_values()` による `list<int>` の構築、DTOへの必須引数追加、組織relation由来のID集合という責務境界は妥当です。

## 施策 C: APPROVE

Inertia専用DTOとTS型の対応、`formatDateTime()` のSSoT利用、既存DS tokenのみの利用、Atomic Design上の配置に問題はありません。

[Suggestion] 「Browser laneはDOM契約を新設しない」という記述だけは、`data-testid` を新設する事実と一致しません。「Browserテストへの波及なし。Vitest用DOM契約は追加」に直すと正確です。

## 施策 D: REQUEST_CHANGES

[Warning] 新索引による性能効果をまだ強く表現しすぎています。

`(user_id, event_type, occurred_at)` は、集約対象を絞りつつ `occurred_at` を索引上から供給でき、可視性条件が整えば index-only scan の候補になります。しかし、現在の

```sql
group by user_id
max(occurred_at)
```

では、各ユーザーのlogin索引エントリを原則として走査します。索引に `occurred_at` を追加しても、利用者ごとの履歴件数に対する計算量が定数になるわけではありません。

修正案:

- 「行数の増加に耐える」「最大値取得に効く」を、「heap参照を減らし、集約に必要な値を索引から供給できる可能性を高める」に弱める。
- 性能を設計上の必須保証にするなら、実データ相当の `EXPLAIN (ANALYZE, BUFFERS)` を判断材料にする。
- 最新1件だけを索引順で取得する必要が出た場合は、`DISTINCT ON`、LATERAL、または別の導出方式を改めて設計する。今回先回りして導入する必要はありません。

lock、rollback、低トラフィック実行条件、`CONCURRENTLY` の見直し条件は十分に修正されています。

### 明示名を採らない判断

妥当です。作成側も配列指定でLaravel既定命名を使っている以上、`dropIndex(['user_id', 'event_type'])` は同じ命名規則を利用します。落とす側だけ生成済み名称を文字列として複製する必要はありません。

## 施策 E: APPROVE

Factory、`HasFactory`のgeneric、Factory型のimport、mutable/immutableの区別はいずれも適切です。

## 施策 F: APPROVE

RC-8は「未確定区分から外れたことをレビューに露出させる」という要求を満たしています。同じ状態を検査するgateを追加しない判断は妥当です。

## 施策 G: REQUEST_CHANGES

[Warning] 招待受諾テスト11では、最後に `/manage/users` を閲覧する主体を明示してください。

招待経由で登録したユーザーが通常のorg Memberなら、その本人は既存認可により403です。そのまま本人で閲覧すると、目的のpropsを検査できません。

修正案: 招待された利用者の登録・受諾完了後、ownerまたはorg Adminとして認証し直し、対象メンバーの`lastLoginAt`を検査すると明記してください。

[Warning] G-4の検証コマンドが現在のAGENTS.mdと同期していません。次の3件が欠けています。

- `pnpm typecheck:packages`
- `pnpm build:packages`
- `pnpm test:packages`

修正案: G-4をAGENTS.mdの検証コマンド全文と一致させてください。

[Warning] 最終確認表の「Feature 8件」が、修正後のG-1の11項目と一致していません。

修正案: 新規追加数と既存テスト再利用を分けて正確に数えるか、件数表現をやめて「G-1記載の全ケース」としてください。

### Filament admin guardの判断

妥当です。現状の要件は「web guardだけを数える」ではなく、「`App\Models\User`について発生したLoginを数える」です。`RecordSecurityEvent::asUser()` がその境界を一元化しており、AdminUserが別モデルである限り`user_id`付き行には混ざりません。

`metadata.guard = 'web'` を読み取り側へ追加すると、定義が二重化するうえ、将来正当に追加されたusers providerのセッションguardまで無言で除外します。G-1テスト10で実際のadminログイン経路を通すなら、構造保証として十分です。

remember meの実HTTPテスト、2FA途中離脱、admin guard、招待経路の追加方針も適切です。

## 全体判定: CHANGES_REQUESTED

Criticalはありません。残るWarningは以下の3点です。

- 施策Dの索引効果を、index-only scan候補化を超えて保証しない表現に直す
- 招待テスト後の`/manage/users`はowner/adminとして閲覧すると明記する
- 検証コマンドとテスト件数の記述を現行AGENTS.md・G-1に同期する

2つの反論はいずれも承認できます。