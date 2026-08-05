全体判定: **APPROVED**

**1. 使命整合性**
[Suggestion] 期待効果の書き方は適切です。§7 で「使命への貢献は間接的」と明記しており、小さいインフラ整備をプロダクト価値に過大接続していません。

**2. 禁止事項**
[Suggestion] 禁止事項 1 への対応は十分です。`self-test` 更新に加えて `BughuntShardCapInvariantTest` を追加する方針は、このリポジトリの「不変条件はテスト登録まで」の運用に合っています。

[Suggestion] 禁止事項 3 も守れています。実 bug-hunt provision/teardown や残留 DB 削除をスコープ外にし、read-only 確認に留める判断は妥当です。

**3. セキュリティ不変条件**
[Warning] `SHARD_DB_RE` は「割り当て範囲」でもありつつ、スクリプト内では dev DB 防御の一部でもあります。4 へ狭めること自体は安全側ですが、§5.1 の表で単純に「割り当て」に分類すると、後続実装者が「DB ガードは cap と完全同期するもの」と誤読する余地があります。  
修正提案: `SHARD_DB_RE` は「新規に操作してよい bug-hunt shard DB の allowlist」と明記し、`DetectsBughuntDatabase` や `TestDatabaseEnv::DEV_DB_DENYLIST` のような「過去残留も含めて守る regex/denylist」と別物だとコメント・設計本文で強調してください。

**4. 実現可能性**
[Suggestion] bash + Pest Architecture テストで実装可能です。cap が 1 桁前提である点も、現在の `8010 + N` 採番と整合しています。self-test で cap の範囲を assert する設計も妥当です。

**5. 現状把握**
[Suggestion] cap=8 の写経箇所の洗い出しは十分に具体的です。特に「実装として効いている箇所」と「散文・守りの範囲」を分けた点がよいです。

[Warning] ただし `DetectsBughuntDatabase` が §6.3 で初めて出てくるため、§2.2 の現状一覧にも「守りの範囲として意図的に据え置く候補」として載せた方が、探索漏れに見えません。  
修正提案: §2.2 に B20 として追加し、§6.3 へ参照させてください。

**6. §5.1 原則**
[Suggestion] 「触れる対象は狭める / 守る対象は狭めない」は正しいです。今回の変更で最も重要な設計判断です。特に `DEV_DB_DENYLIST`、browser pre-flight guard、global lock 検証 fixture を 4 に縮めない判断は妥当です。

**7. スコープ**
[Suggestion] cap の SSOT 化はオーバーエンジニアリングではありません。現状で同じ意味の数字が複数箇所にあり、しかも既存 gate が効いていないため、定数化とテスト追加は小さい修正範囲に対する妥当な再発防止です。

[Suggestion] 散文同期の Architecture テストも妥当です。リポジトリ全文 grep ではなく、対象ファイルを明示列挙する設計なので過剰ではありません。

**8. リスク**
[Warning] `BughuntShardCapInvariantTest` の「cap 超過 literal」検出は、守りの範囲を対象外にする設計なので、除外理由のコメントが実装上かなり重要です。ここが曖昧だと、将来「8018 が残っているから赤」または「全部除外して赤くならない」のどちらにも崩れます。  
修正提案: テスト内に `allocation_docs` と `defense_surfaces` のような名前で明示的に分け、後者は「検出対象外であること」をコメントだけでなくテスト名やデータプロバイダ名にも残してください。

結論として、概念設計は承認可能です。修正必須ではありませんが、`SHARD_DB_RE` の二重の意味と `DetectsBughuntDatabase` の扱いだけは、実装前に本文へ追記しておくと後続の誤修正を防げます。