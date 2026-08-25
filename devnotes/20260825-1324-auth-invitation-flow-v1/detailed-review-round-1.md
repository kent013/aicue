# レビュー結果

中心仮説は妥当です。ただし、施策Aのロック下再検証がMVCC環境で削除を見落とし得るため、現在の設計では「削除済み組織の招待を必ず無効へ畳む」と「成立した招待だけをverifiedにする」を保証できません。

## 施策A — REQUEST_CHANGES

### [Critical] `exists()` はロック後でも最新状態を読むとは限らない

提案されている次の処理は、非ロックのスナップショット読み取りです。

```php
if (! $locked->organization()->exists()) {
    return false;
}
```

特に登録経路では、外側の`CreateNewUser`トランザクション内で`findActiveByPlainToken()`が先に実行されます。MySQLのREPEATABLE READなどでは、その時点のスナップショットが以後の非ロックSELECTにも使われます。

そのため、次の競合が成立し得ます。

1. 登録トランザクションが有効な組織を読む
2. 別接続が組織を論理削除してcommit
3. `lockForMembershipWrite()`が削除後の行をロック
4. `organization()->exists()`は古いスナップショットを読み、存在すると判定
5. membership作成・`accepted_at`更新・verified付与まで進む

既存コメントにも「非ロックSELECTはMVCCスナップショット版を返し得る」と明記されており、`lockedUser`を再取得している設計とも非対称です。

修正案は、relation起点のロック読み取りで最新状態を取得することです。

```php
$lockedOrganization = $locked->organization()
    ->lockForUpdate()
    ->first();

if ($lockedOrganization === null) {
    return false;
}
```

可能なら以後の処理でも、事前取得した`$organization`ではなく、この`$lockedOrganization`を権威ある値として使ってください。relation起点なので`DirectFetchInventory`にも入りません。

### [Warning] 計画されたTOCTOUテストでは上記競合を検出できない

「先に組織を削除してからReflectionで呼ぶ」テストでは、トランザクション開始時点ですでに削除済みなので、単純な`exists()`でも緑になります。古いスナップショットを保持した状態で別接続が削除するケースを検証できません。

修正案:

- 既存の`InvitationAcceptRaceTest`と同じ複数接続・並行実行の仕組みを再利用する
- 接続Aで有効招待を読み、スナップショットを確立
- 接続Bで組織を論理削除してcommit
- 接続Aで受諾確定処理を進める
- `false`、membershipなし、`accepted_at`不変を確認する
- 登録経路との結合では、fallbackかつunverifiedであることも確認する

現在予定されている非並行テストも、基本的な負例として残す価値はあります。

### [Suggestion] `show()`も単一解決口へ寄せられる

`show()`は無効理由をすべて同じページへ畳むので、`findActiveByPlainToken()`を使っても応答仕様を維持できます。手書きのhash・状態条件・組織生存条件の重複を減らせます。ただし、表示直前のnull防御は引き続き必要です。

---

## 施策B — REQUEST_CHANGES

### [Warning] `CreateNewUser`が使うSession取得方法を設計段階で確定すべき

「`session()->driver()`と`request()->session()`のどちらにするかPHPStanの通り方で決める」は、詳細設計として未確定です。静的解析を通るかではなく、Fortifyリクエストに紐づくSessionを使うことを意味論として決める必要があります。

修正案としては、`create()`冒頭でリクエストのSessionを一度取得し、同じインスタンスをresolveとforgetに使うのが明確です。

```php
$session = request()->session();
$invitationToken = InvitationContinuation::resolve($session);

// ...

if ($invitationToken !== null) {
    InvitationContinuation::forget($session);
}
```

または`Illuminate\Contracts\Session\Session`をコンストラクタ注入できることを確認したうえで注入してください。

### [Warning] SoT gateの文字列復元とfail-closed条件が不足している

`trim($token[1], '\'"')`は引用符を除くだけで、PHP文字列を復元していません。例えば次は実行時には同じ鍵ですが検出されません。

```php
"\x69nvitation_token"
```

動的連結を保証外とすることは記載されていますが、エスケープ表現は保証外として記載されていません。

修正案:

- 推奨: 対象とする単・二重引用文字列を安全に復元し、実行時値で比較する
- 最低限: エスケープ表現も保証外へ明記し、gateの主張を「生の完全一致literal」に狭める
- エスケープされた同値文字列の負例を追加する

ただしi11を「実際のSession鍵の単一出典」として保証するなら、単なる説明の縮小ではなく検出対応が望まれます。

また、新設scannerのfail-closed要件として以下も設計に明記してください。

- `app/`が存在しない場合は失敗
- PHPファイルを読めなければ失敗し、黙って除外しない
- 可能なら`token_get_all($source, TOKEN_PARSE)`を使い、構文解析不能を失敗させる
- 走査したPHPファイル数が非ゼロであることを独立に確認する

SoTファイルとの完全一致だけでも全走査空振りは検出できますが、特定の1ファイルだけ読めなかった場合の見逃しは防げません。

### [Suggestion] 隣接クラスとの命名も揃える

`EmailVerificationContinuation`との整合を強く意識するなら、定数名も`KEY`ではなく`SESSION_KEY`に揃えると読みやすくなります。

---

## 施策C — REQUEST_CHANGES

### [Critical] verified付与条件が施策Aの不完全な生存再検証に依存している

Cの次の条件自体は適切です。

```php
$joined !== null
```

しかし現状の施策Aでは、並行論理削除を見落として`acceptInvitationIfValid()`が組織を返す可能性があります。その場合、削除済み組織の招待にも`email_verified_at`が付与されます。

修正案は、施策Aの最終再検証をロック読み取りへ変更し、その後にCを実装することです。Aが修正されれば、Cのverified付与ロジックは妥当です。

### [Warning] `RegisterResponse`のクラス説明が変更後の挙動と矛盾する

現在のdocblockは「未認証ユーザーを必ず`verification.notice`へ送る」と説明しつつ、全登録を同画面へ送るようにも読めます。変更後はverified登録だけ`app.entry`へ送るため、クラスレベルの責務説明も更新が必要です。

修正案:

- unverified登録 → `verification.notice`
- 招待成立によりverified済み → `app.entry`
- JSON応答 → 従来どおり201

をdocblockに明記してください。`CreateNewUser`のクラス説明にも、招待成立時のverified付与を追記するのが適切です。

### [Warning] 登録直後の「着地」は最終遷移まで固定した方がよい

`app.entry`へのredirectだけでは、`/go`が将来verification画面や誤った組織へ転送する後退を検出できません。

修正案として、招待成立テストに以下を追加してください。

- 最初の応答が`app.entry`である
- そのredirectを追跡すると、参加した招待組織の正規ページへ到達する
- `verification.notice`を経由しない

JSON後方互換を既存テストが固定していない場合は、招待成立時でもJSON要求が201のままであるケースも追加してください。

---

## 横断的な指摘

### [Warning] 検証コマンドがAGENTS.mdの必須集合を満たしていない

詳細設計末尾にはpackage系の3コマンドがありません。

修正後の必須集合は以下です。

```text
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

「frontend変更なし」であっても、AGENTS.mdが全greenを要求しているため省略できません。

## 問題がない観点

- 新規API payloadやInertia Propsの変更はなく、TypeScript型・JsonResource追加不要という判断は妥当
- `response()->json()`の新規直書きはない
- PIIの平文DB検索を増やしていない
- relation起点の組織解決方針はDirectFetch不変条件と整合
- 新規routeや認可境界の変更はない
- UI変更がないためDESIGN.md、Atomic Designの追加対応は発火しない
- `forceFill(['email_verified_at' => now()])`は保護キーの明示代入規約に適合
- validation失敗時に招待継続を残し、登録確定後だけforgetするライフサイクルは妥当

## 全体判定

**CHANGES_REQUESTED**

最優先修正は、施策Aの組織生存確認を非ロック`exists()`からrelation起点のロック読み取りへ変更することです。これが直れば、施策Cのverified付与条件も成立します。