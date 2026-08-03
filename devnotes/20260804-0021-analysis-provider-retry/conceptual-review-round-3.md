全体判定: **CHANGES_REQUESTED**

Round 2 の中心課題はほぼ解消されていますが、時間算術と SIGALRM 時のトランザクション処理に、修正必須の誤りが残っています。

### 1. 使命との整合性

[Suggestion] timeout 改善と文字化け修正を分離し、成功条件を限定した判断は妥当です。North Star への寄与も適切な強さで記述されています。

### 2. 禁止事項違反

[Suggestion] 禁止事項への抵触は見当たりません。ストリーミング、reason enum、段別budgetを見送る判断も妥当です。

### 3. 時間 budget

[Critical] `C = 360` を「TTL/staleを動かさずに取れる最大値」とする算術は誤っています。

記載された条件は次のとおりです。

```text
T = 4C + 30 + 90
T < retry_after = 1680
```

したがって、

```text
4C + 120 < 1680
C < 390
```

です。`C ≤ 360` は、先に `$timeout = 1560` を固定した場合にだけ導けます。

```text
4C + 120 ≤ 1560
C ≤ 360
```

修正提案: 「360秒はTTLから導かれる最大値」ではなく、「実測274秒に対する運用上限を360秒と決め、その結果として `$timeout=1560` に収まる」と記述してください。値自体を変える必要はありません。

[Warning] 期待効果の「max_tokens上限まで使う段でも打ち切られない」は、360秒を運用上限へ格下げした説明と矛盾します。

修正提案: 「観測レンジと設定した運用上限の範囲では打ち切られない」に弱めてください。

### 4. SIGALRMとチケット会計

[Critical] 「terminal transaction途中のSIGALRMでは、ロールバック後に `failed()` がreleaseする」という順序は証明されていません。

SIGALRM handlerは同じPHPプロセス内で、処理中のtransactionを明示的にrollbackしてから `job->fail()` を呼ぶわけではありません。`failed()` が同じDB connectionを使う場合、次が起こり得ます。

- release処理が既存transactionに巻き込まれ、直後のprocess killで一緒にrollbackされる
- 実行中queryやconnection状態により `failed()` 自体が失敗する
- 行ロック取得が完了せず、release前にprocessが終了する

したがって、timeout直後のreleaseは保証できず、保証できるのはstale回復による最終的なreleaseです。

修正提案:

- 「commit前timeoutでは即時releaseまたはstale回復」と明記する
- SIGALRM相当テストで即時releaseを必須にしない
- stale回復後に予約がReleasedになることを必須テストにする
- commit済み、未commit、`failed()`失敗の3経路で最終会計状態を固定する

### 5. retryable例外

[Suggestion] `previous instanceof RequestException` でnarrowingして408/500/502/503/504を判定する方針は妥当です。実装ではpreviousをローカル変数へ格納するとPHPStanのnarrowingが明確になります。

429・413・529・接続例外の分類にも重大な漏れは見当たりません。

### 6. 時計

[Warning] wall clock採用はハード上限の安全性を壊しませんが、「TTLと同じ予算だから同じ時計にする」という理由は適切ではありません。deadlineは経過時間、TTL/staleは永続化された時刻であり、別概念です。

修正提案: 採用理由を「既存のテスト容易性を優先し、時計補正によるsoft deadlineの揺れはworker timeoutで上限されるため受容する」に限定してください。「別時計だと不整合」という主張は削除すべきです。

### 7. スコープ・型安全性

[Suggestion] 実装範囲は過大でも過小でもありません。DTO/JsonResource境界に変更はなく、typed config accessorと例外型narrowingを使う方針ならPHPStan level 10にも適合可能です。

承認に必要なのは、`C=360`の導出表現修正と、SIGALRM中の即時release保証をstale回復込みのeventual guaranteeへ修正することです。