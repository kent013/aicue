全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当です。パスキー設定を本番起動時に fail-fast すること、`laravel/passkeys` を直接依存として pin することは、North Star に対して間接だが重要な基盤強化です。ただし、**既存パスキー利用環境での `PASSKEYS_USER_HANDLE_SECRET = APP_KEY` 移行手順**と、**APP_URL 由来設定を許容する方針の運用境界**に曖昧さがあり、このままだと初回デプロイで本番停止または既存パスキー破壊を招く可能性があります。

## 1. 使命との整合性

[Warning] パスキー設定強化は North Star の中核機能ではないが、撮影 PWA の継続利用性には貢献する、という位置付けは妥当です。  
ただし「思考ゼロ」の根拠としては少し広げすぎです。より正確には「現場作業者がログイン不能になる事故を防ぎ、撮影 PWA への到達性を守る」改善です。

修正提案: 期待効果の表現を、認証基盤の可用性・継続性に絞る。

## 2. 禁止事項違反

[Warning] 現時点の設計に明示的な禁止事項違反はありません。`response()->json()`、Prism 直呼び、disabled UI、Artifact などには触れていません。

ただし、`composer.json` / `composer.lock` 更新を含むため、実装時は依存更新の検証が必須です。テストなし完了報告禁止に抵触しないよう、設計に挙げたテストに加えて `composer phpstan` と関連 Feature/Architecture テストを必須にしてください。

修正提案: 実装方針に「依存更新後は `composer test` / `composer phpstan` / `vendor/bin/pint --test` の対象確認」を明記する。

## 3. 実現可能性

[Critical] `PASSKEYS_USER_HANDLE_SECRET` が `APP_KEY` と同一なら本番停止、というルールと、「既存本番では現行 `APP_KEY` をそのまま入れれば既存パスキーは維持される」という移行手順が衝突しています。  
同一値を禁止すると、既存パスキーを維持するための安全な初回移行が fail-fast で止まります。

修正提案: 次のどちらかに設計を寄せるべきです。

- 初回移行では `PASSKEYS_USER_HANDLE_SECRET=current APP_KEY` を一時許容する明示的な migration flag を用意し、期限付き・本番 preflight で警告扱いにする。
- 既存パスキー維持を諦める破壊的変更として扱い、全ユーザー再登録が必要であることを runbook に明記する。

現実的には前者が妥当です。恒久状態として `APP_KEY` と同一は止めるべきですが、既存環境の移行瞬間まで同一禁止にすると導入不能になります。

[Warning] `config/passkeys.php` を「3 キーだけ持つ最小ファイル」にする方針は、パッケージ側 `mergeConfigFrom` の挙動次第では他キーの既定が消えるリスクがあります。Laravel の `mergeConfigFrom` は通常、同名 config 配列をマージしますが、ネスト構造やパッケージ実装に依存します。

修正提案: `PasskeyPackageContractTest` に「vendor 側で必要な既定キーが config cache 後も欠落しない」検査を追加するか、vendor config の全キーを明示コピーし、今回守りたい 3 キーだけを env 化する。

## 4. 期待効果の妥当性

[Warning] 起動時検出への前倒しは合理的です。ただし `APP_URL` 由来の RP ID / origins を許容する限り、「env 未宣言でも安全」とは言い切れません。`APP_URL` が誤っていれば派生値も誤ります。

修正提案: 期待効果は「APP_URL の危険な値を起動時に検出する」に留め、「明示 env 必須と同等の安全性がある」とは書かない。

## 5. リスク

[Critical] 本番停止条件が増えるため、デプロイ前に `.env` の準備がない環境では初回起動が止まります。これは妥当な fail-fast ですが、破壊的運用変更です。

修正提案: `docs/auth-security-mechanisms.md` だけでなく、AGENTS.md の運用要件に「初回デプロイ前に設定必須」「既存パスキーがある場合の移行手順」を明記する。特に `APP_KEY` 同一禁止との関係は曖昧にしない。

[Warning] allowed origins の CSV 分割は、空白・末尾カンマ・重複・大文字 scheme/host の扱いで運用差が出ます。

修正提案: validator で正規化しすぎず、許容形式を明確に固定する。例: trim は許可、空要素は違反、scheme は小文字 `https` のみ、path/query/fragment は違反。

## 6. スコープの適切さ

[Suggestion] スコープは概ね適切です。`timeout` / `guard` / `middleware` など未観測のキーを広げない判断はよいです。

[Warning] ただし「feature flag が有効なときだけ」ProductionEnvGuard に追加する、とありますが、どの feature flag かが不明です。パスキー機能が本番で有効なら、この検査は常時有効であるべきです。

修正提案: flag 名と条件を明記する。Fortify/passkeys route が有効な状態なら検査対象、完全に機能無効なら skip、という判定にする。

## 7. 型安全性

[Suggestion] `final` な純粋 validator、`RuntimeException`、既存 `TrustedProxiesConfigValidator` と同形にする方針は PHPStan level 10 と相性がよいです。

[Warning] `Config::string('passkeys.relying_party_id')` 前提なら、`allowed_origins` は `array<int, non-empty-string>` 相当として扱えるよう、validator 内で入力型を厳密に検査する必要があります。

修正提案: config 取得境界で `mixed` を受けたら、validator 内で string/list<string> に狭める。テストに「文字列でも配列でもない」「配列内に非文字列」を追加する。

## 固有論点の判定

[Critical] **本番で「利用者ハンドルの導出鍵が APP_KEY と同一なら起動を止める」ことは妥当か。過剰か。**  
恒久状態としては妥当です。過剰ではありません。`APP_KEY` ローテートとパスキー生存が結合しているのは重大な運用リスクです。  
ただし、既存環境の移行手順として「現行 `APP_KEY` を入れる」と書くなら、その瞬間だけは同一値を許容する設計が必要です。ここが未解決なので Critical です。

[Warning] **RP ID / allowed origins を env 明示必須にせず、APP_URL 由来の解決値を検査する方針は妥当か。**  
妥当ですが、条件付きです。単一ドメイン・単一 Default Project の v1 では、`APP_URL` を正本にして派生値を検査する方が運用負荷は低いです。  
ただし本番では `APP_URL` 自体が強い設定正本になるため、`https`、非 localhost、host あり、RP ID との整合を fail-fast する必要があります。複数 origin、カスタムドメイン、サブドメイン横断が入る段階では env 明示必須へ見直すべきです。

[Warning] **版 pin の検査は composer.lock か composer.json か、両方か。**  
両方が妥当です。  
`composer.json` は「直接 import しているので直接要求する」という設計意思を固定します。`composer.lock` は実際に検証済みの解決版を固定します。0.x パッケージで契約検査の前提を守るなら、両方を見る設計でよいです。

## 結論

設計の狙いは承認可能ですが、現状案は **既存本番パスキーの移行と fail-fast 条件が矛盾**しています。そこを解消し、`APP_URL` 派生方針の境界と feature flag 条件を明確化すれば APPROVED にできます。