# 対応マトリクス: design-review Round 1

全体判定 **CHANGES_REQUESTED**。施策 D と G が REQUEST_CHANGES、A/B/C/E/F は APPROVE。
Critical 2 件 / Warning 7 件 / Suggestion 6 件。全件の判断を記録する。

---

## [Critical] 施策 D: migration の停止許容と lock の扱いが設計に書かれていない

- 判断: **対応する**
- 根拠: 妥当な指摘。「`CONCURRENTLY` を使わない」という判断は書いたが、
  **その判断が何を許容することなのか**（= 索引構築の間、認証系 INSERT が待つ）を
  migration 自身のコメントと実行条件として書いていなかった。
  「使わない理由」だけあって「使わないと何が起きるか」が無い設計は、
  実行する人が判断できない。
- 対応内容: 施策 D を全面的に書き直した。
  (1) migration の doc comment に「短時間の認証系 INSERT の待ちを許容する」ことを明記、
  (2) 実行条件（低トラフィック時間帯に実行する / 行数が小さいうちに済ませる）を
  「運用条件」節として独立させ、
  (3) 無停止が要件になった場合に何が追加で要るか（`$withinTransaction = false` +
  `CREATE INDEX CONCURRENTLY` + invalid index の復旧手順）を将来の見直し条件として明記、
  (4) rollback (`down()`) も同じ lock を取ることをリスクに追加した。

## [Critical] 施策 G テスト 7: remember me の代替案が弱い

- 判断: **対応する（代替案を撤去して、実 HTTP 経路に一本化する）**
- 根拠: 完全に正しい指摘である。「handler を直接呼ぶ」に落とすと、
  **守りたい不変条件（recaller 復元で `Login` が発火し、監査行が増える）そのものが検査されない**。
  それは検査対象を「listener が guard 名を見ないこと」へすり替えているだけで、
  AGENTS.md の「保証範囲を誇張しない」に反する。
  実現可能性も確認した — `SessionGuard::getRecallerName()` が公開されており、
  テストの `withCookie()` は既定で暗号化されて渡るため、
  ログイン応答後に `remember_token` を読んで recaller cookie を組み立て、
  セッションを捨てた 2 回目のリクエストで踏める。**書ける**ので落とす理由が無い。
- 対応内容: テスト 7 を「実 HTTP で recaller 経路を踏む」形に確定し、
  組み立て手順（recaller の値の形 `id|remember_token|password`）を設計に書いた。
  弱い代替案の記述は**削除**した。

## [Warning] 施策 B: `pluck('id')->all()` の PHPStan level 10 での型

- 判断: **対応する**
- 根拠: 指摘のとおり。`pluck()` の戻り型は `Collection<int, mixed>` に落ちやすく、
  `list<int>` の narrowing は `@var` の自己申告になる。禁止事項 2（型を緩めて黙らせる）の
  境界に近い書き方を最初から避けるべきである。
- 対応内容: 施策 B の変更後コードを Codex の提案どおり
  `array_values(array_map(static fn (User $member): int => $member->id, …))` に差し替えた。

## [Warning] 施策 D: 「新索引が旧索引を完全に包含する」は言い過ぎ

- 判断: **対応する**
- 根拠: 正しい。B-tree の左端一致で代替できるのは
  「先頭列から連続した前置き集合に対する述語」であり、
  索引の「全用途」（例: 索引だけで完結する読み取りの列構成、統計の取られ方）を
  保証する言い方ではない。本リポジトリは「保証範囲を誇張しない」を規約として持つ。
- 対応内容: 「完全に含む」→「`user_id, event_type` の前方一致クエリでは代替できる」に
  表現を下げ、リスク節の「等価かそれ以上」という書き方も
  「前方一致の用途では等価。それ以外は保証しない」に直した。

## [Warning] 施策 D: 既定命名への依存をコメントで固定せよ

- 判断: **対応する（コメントで固定する。明示名での drop は採らない）**
- 根拠: 既存 migration が配列指定で張っているため既定命名であることは実読で確定している
  （`security_audit_events_user_id_event_type_index`）。
  ただし読む人がそれを再確認しなくて済むようコメントに書く価値はある。
  **明示名を直書きする形は採らない** — 張った側が既定命名なので、
  落とす側だけ名前を直書きすると「2 か所に同じ文字列を持つ」形になり、
  本リポジトリが繰り返し禁じている二重管理になる。
- 対応内容: migration のコメントに既定名を明記し、
  「張った側も配列指定なので命名は一致する」と根拠を書いた。

## [Warning] 施策 D: rollback も lock を取る

- 判断: **対応する**
- 根拠: そのとおり。
- 対応内容: リスク節に追加した。

## [Warning] 施策 E: factory の `CarbonImmutable` が「モデルが immutable を返す」と読めてしまう

- 判断: **対応する**
- 根拠: 正しい。`SecurityAuditEvent::casts()` は `occurred_at => 'datetime'`（mutable Carbon）であり、
  施策 A が immutable を得ているのは**別名列への `withCasts` の効果**である。
  factory の説明が誤読を生むと、後から「モデルも immutable だ」と思って
  `CarbonImmutable` 前提のコードが書かれる。
- 対応内容: `occurredAt(CarbonImmutable $at)` の doc comment に
  「引数の型は呼び出し側の都合であり、モデルから読み戻した `occurred_at` は
  cast `datetime` により mutable Carbon である」と明記した。

## [Warning] 施策 G: 2FA 途中離脱を数えないことの検査が無い

- 判断: **対応する**
- 根拠: 設計 §3 で「2FA 待ちは数えない」と主張しているのに、それを固定する検査が無かった。
  主張とテストの対応が欠けている（禁止事項 1 の趣旨）。
- 対応内容: G-1 にテスト 9 を追加した
  （パスワードだけ通過して 2FA challenge 未完了の状態では login 行が増えないこと）。

## [Warning] 施策 G: Filament の admin guard が混ざらないことの検査が無い

- 判断: **対応する（設計は「guard で絞らない」を維持し、前提をテストで固定する）**
- 根拠: Codex は「admin guard を数えない」ことを
  (i) 構造で保証してテストする、か (ii) `metadata.guard = 'web'` で絞る、の二択で求めた。
  **(i) を採る**。理由は 2 つある。
  - (ii) は JSON 列への述語になり、施策 D で張る索引が効かなくなる
    （絞り込みが索引の外へ出る）。性能のために索引を張り替える設計と矛盾する。
  - (ii) は「どの guard を数えるか」の定義を `RecordSecurityEvent`（記録側）と
    `LastLoginLookup`（読み取り側）の**2 か所**に持つことになる。食い違いの温床である。
  構造側の保証は実読で確認済み: `App\Models\AdminUser` は
  `Illuminate\Foundation\Auth\User` を直接継承する**別クラス**であり
  `App\Models\User` の派生ではない。したがって `RecordSecurityEvent::asUser()` が
  `null` を返し、admin guard の login 行には `user_id` が付かない。
  この前提が崩れる（AdminUser が User を継承する等）と静かに混ざるので、テストで固定する。
- 対応内容: G-1 にテスト 10 を追加した
  （Filament の admin ログイン後、`user_id` の付いた login 行が増えないこと）。
  施策 A の doc comment にもこの前提を明記した。

## [Warning] 施策 G: 招待受諾フローの扱いが曖昧

- 判断: **対応する（設計の記述を具体化し、テストを 1 件足す）**
- 根拠: 指摘は妥当だが、事実関係は既に実読済みだった。
  `InvitationAcceptanceController::show` は未ログインなら token を session に退避して
  `register` へ誘導し、`store`（受諾）は **auth 必須**である。
  つまり受諾の瞬間に `Login` は発火せず、その前段の**登録の自動ログイン**が数えられる。
  結果として「招待された人の最終ログイン = 参加した時刻」になり、意図どおりである。
  ただしこの鎖は設計の文章にしか無く、検査が無かった。
- 対応内容: §3 の経路表の「招待受諾」行を、この鎖が読めるように書き直し、
  G-1 にテスト 11（招待経由で登録・参加した利用者は、参加時刻が最終ログインとして出る）を追加した。

## [Suggestion] 施策 A: `Assert::numeric` より `Assert::integerish`

- 判断: **対応する**
- 根拠: 正しい。`numeric` は `1.5` のような float も通す。ID の narrowing としては
  `integerish` が意図に一致する。`vendor/webmozart/assert/src/Assert.php` L107 に実在を確認した。
- 対応内容: 施策 A のコードを `Assert::integerish($userId)` に差し替えた。

## [Suggestion] 施策 A: `select('user_id')` + `selectRaw('max(...)')` に分ける

- 判断: **対応する**
- 根拠: SQL 断片を最小にする方が読みやすく、列名の混入経路も狭い。
- 対応内容: 差し替えた。

## [Suggestion] 施策 E: `HasFactory` の PHPDoc に factory の import が要る

- 判断: **対応する**
- 根拠: `@use HasFactory<SecurityAuditEventFactory>` の型名解決に `use` が要る
  （`User` モデルが `Database\Factories\UserFactory` を import しているのと同じ）。
- 対応内容: 施策 E の変更後コードに `use Database\Factories\SecurityAuditEventFactory;` を足した。

## [Suggestion] 施策 G-2: 件数差で揺れない fixture にせよ

- 判断: **対応する**
- 根拠: 招待件数や pivot ロールの件数が両ケースで違うと、
  「最終ログインが N+1 になった」以外の理由でクエリ数が動き、検査の意味が薄れる。
- 対応内容: G-2 の条件に「招待は両ケースとも 0 件 / Default Project は両ケースとも不在 /
  変えるのはメンバー数だけ」を明記した。

## [Suggestion] 施策 C: `data-testid` が DOM 契約を増やす

- 判断: **見送る（変更しない）**
- 根拠: 認識しておく程度で十分、という指摘自体に同意する。
  既存の同ファイルが `member-role-{id}` / `remove-member-{id}` / `unassigned-{id}` と
  同じ流儀で testId を持っており、ここだけ持たない方が不揃いになる。

## [Suggestion] 施策 B / F への肯定的コメント

- 判断: **変更不要**
