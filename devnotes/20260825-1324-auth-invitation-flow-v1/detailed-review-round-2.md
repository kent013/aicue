Round 1の実装ロジック上の問題は解消されています。ただし、施策Aの最重要なロック読みがテストで固定されていない点と、施策Cのredirect経路検証方法に残課題があります。

## 施策A — REQUEST_CHANGES

### [Warning] 1cの`lockForUpdate()`がテストで固定されていない

実装案そのものは適切です。

```php
$lockedOrganization = $locked->organization()
    ->lockForUpdate()
    ->first();
```

また、以後の`organization_id`と`laratrust_team_id`を`$lockedOrganization`由来にする判断も妥当です。

一方、提示されたone-shotテストは次のSQLを検出します。

```text
organization_invitations ... for update
```

これは既存の招待行ロックです。新設する1cの組織問い合わせが`FOR UPDATE`であることは固定していません。

さらに、注入される論理削除は同一接続・同一トランザクション内の自分自身の更新です。この場合、1cを誤って元の非ロック`exists()`へ戻しても、自トランザクションの更新は見えるためテストが緑になります。つまりRound 1で問題になった退行を検出できません。

修正案:

- one-shotによる決定的な状態注入はそのまま採用する
- その後に実行される`organizations`問い合わせも記録する
- 対象organization IDの問い合わせについて、少なくとも以下をassertする

  - `organizations`を対象としている
  - `deleted_at is null`相当のSoftDeletes条件がある
  - SQLに`for update`がある
  - 対象organization IDがbindingsにある

既存の`InvitationAcceptRaceTest`と同じSQL正規化・bindings照合の家風を使えば、新しい静的scannerを増やさずに固定できます。

テストの保証範囲は次のように分けて記述すると正確です。

- 状態注入テスト: 最終再検証が削除を受諾不能へ畳むこと
- SQL形状のassert: 最終再検証が非ロック読みではなくロック読みであること
- 保証外: 別接続を使ったDBエンジン固有のMVCCスケジュールの完全再現

### [Suggestion] 注入タイミングの説明を少し修正するとより正確

注入時点では`lockForMembershipWrite()`による組織行ロックがすでに完了しています。その後に別トランザクションが論理削除する実際の競合順序は成立しません。

したがって、「競合を再現する」ではなく、現在書かれているとおり「最終再検証の消費契約を決定的に検証する」と一貫して表現するのが適切です。

---

## 施策B — APPROVE

Round 1の指摘は十分に解消されています。

- `request()->session()`を一度だけ取得する意味論が確定している
- resolveとforgetで同一Sessionを利用する
- 定数名が隣接クラスと揃っている
- 旧private resolverを削除し、並走実装を残さない
- 文字列literalの実行時値を復元している
- `TOKEN_PARSE`、走査根、ファイル読み取り、母集団非空のfail-closedが設計されている
- 正例・負例・保証外が明示されている
- 既存session鍵を維持するためデプロイ跨ぎの互換性も保たれる

### [Suggestion] 単引用符復元器には組み合わせケースも置く

手動アンエスケープの置換順による誤復元を防ぐため、通常の対象literalに加えて、`\\`と`\'`が隣接する入力を自己検査へ1件置くとより堅牢です。今回の鍵そのものには記号が含まれないため、承認を妨げるものではありません。

---

## 施策C — REQUEST_CHANGES

verified付与ロジック、通知抑止、fallbackのfail-closed、docblock更新、JSON 201維持はいずれも妥当です。施策Aで組織のロック読みが正しく固定されれば、verified付与の前提も成立します。

### [Warning] `followRedirects`だけではverification画面を経由していないことを証明できない

次の2点だけでは不十分です。

- 最初のredirectが`app.entry`
- redirectをすべて追跡した最終応答が招待組織dashboard

例えば次の経路でも同じassertを通る可能性があります。

```text
app.entry
→ verification.notice
→ verifiedユーザーとしてbounce
→ dashboard
```

修正案は、redirectを自動追跡せず、一段ずつ検査することです。

1. 登録POSTが`app.entry`へredirectする
2. `app.entry`をGETする
3. その応答が招待組織dashboardへ直接redirectする
4. dashboardをGETして正常応答を確認する

概念的には次の形です。

```php
$registration->assertRedirectToRoute('app.entry');

$entry = $this->get(route('app.entry'));
$entry->assertRedirectToRoute('dashboard', [
    'organization' => $organization->slug,
]);

$this->get(route('dashboard', [
    'organization' => $organization->slug,
]))->assertOk();
```

これにより`app.entry → verification.notice`という中間経路が入れば2段目で失敗します。`followRedirects`は最終到達確認の補助には使えますが、「途中で経由しない」の根拠にはしないでください。

JSONテストでは、201だけでなく、同じケースが本当に招待成立であることをmembershipと`email_verified_at`でも確認すると、未検証の通常登録が偶然201を返しただけの偽グリーンを防げます。

---

## 横断評価

以下は適切に修正されています。

- 必須検証コマンド10本が完全に揃っている
- TypeScript/Inertia Props/API Resourceへの不要な変更を増やしていない
- PIIの平文検索を導入していない
- relation起点の問い合わせによりDirectFetch inventoryを増やしていない
- UI変更がないためDESIGN.md・Atomic Designの追加対応は不要
- fallback登録にverifiedを付与しない結合テストがある
- 通常登録・取消済み・論理削除済みの対称な負例がある
- FortifyのJSON応答契約を維持している

## 全体判定

**CHANGES_REQUESTED**

残る修正は設計の方向性ではなく、重要な保証をテストで正確に固定するための2点です。

1. 施策Aで、1cの`organizations ... FOR UPDATE`をSQL形状としてassertする  
2. 施策Cで、redirectを一段ずつ追い、`app.entry`からdashboardへ直接進むことを確認する