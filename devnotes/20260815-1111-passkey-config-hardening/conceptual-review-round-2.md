全体判定: **CHANGES_REQUESTED**

Round 1 の Critical 2件は解消しています。特に、導出鍵を「値の不一致」ではなく「独立した宣言の有無」で判定する変更は正しく、期限付き migration flag も不要です。

ただし、ホスト検証と CSV の扱いに残る仕様不整合は、実装前に修正が必要です。

## 1. 使命との整合性

[Suggestion] 使命への貢献の位置づけは妥当です。

教材設計そのものではなく、撮影 PWA に到達するための認証手段の可用性・継続性を守る基盤改善と限定されました。効果を過大に主張していません。

## 2. 禁止事項違反

[Suggestion] 明示的な禁止事項違反はありません。

Architecture / Feature / Unit テストへの登録、PHPStan level 10、既存の `production:preflight` の再利用が設計に含まれています。新しい独自機構や不要なデプロイ基盤も追加していません。

## 3. 実現可能性

[Warning] RP ID の検査条件 `[A-Za-z0-9.-]+` だけでは「host 形式」を保証できません。

例えば以下が通ります。

- `-example.com`
- `example-.com`
- `example..com`
- `.example.com`
- `example.com.`

これらを許したまま「host 形式でない場合は停止」とするのは、仕様と判定が一致していません。

修正提案: RP ID と origin host を同じ DNS ラベル検証に通してください。少なくとも、各ラベルが空でない、先頭・末尾が `-` でない、ラベル長と全体長が妥当、末尾ドットを許可しない、という条件を設計に明記してください。単純な包含正規表現だけでなく、ラベル単位に検証する方が明確です。

[Suggestion] `config:cache` 下での `user_handle_secret_declared` は成立します。

config ファイル評価時に、例えば次の2値を確定し、キャッシュへ格納する構造であれば問題ありません。

- `user_handle_secret`: 宣言値または `APP_KEY` fallback
- `user_handle_secret_declared`: env の非空な宣言が存在したか

実行時に `env()` を再参照せず、キャッシュされた真偽値を Guard が読む設計にしてください。提示済みの config cache 往復テストで固定できます。

## 4. 期待効果の妥当性

[Suggestion] 期待効果は合理的です。

`APP_URL` 派生を残す限界も明記されており、「env 明示必須と同等」とは主張していません。設定事故の検出を利用時から起動時へ前倒しする効果は期待できます。

## 5. リスク

[Warning] allowed origins の CSV について、「空要素は違反」と「config 段で落とす」が整合していません。

`https://example.com,,` を空要素除去後に受理すると、設定ミスを fail-fast せず正規化して隠します。セキュリティ上の許可範囲が広がるわけではありませんが、「trim のみ許可」「設定事故を起動時検出」という設計目的には反します。

修正提案: CSV 分割後、trim 前後を問わず空要素が1つでも存在したら違反にしてください。そのために raw 文字列全体を validator へ渡す必要はなく、config 側で `allowed_origins_valid` のような事実を expose するか、Guard 側で env/config 入力を型付き変換する際に違反として扱えます。

[Suggestion] 既存本番の移行リスクは適切に扱われています。

現行 `APP_KEY` の値を専用 env に明示して初回デプロイし、その後 `APP_KEY` をローテートする手順で既存パスキーを維持できます。

## 6. スコープの適切さ

[Suggestion] スコープは適切です。

事故が確認されている3値と直接依存の固定に限定されており、public suffix 判定や他の vendor 設定の褷写を含めない判断も妥当です。

## 7. 型安全性

[Suggestion] validator を `string` / `list<string>` / `bool` で受け、`mixed` の絞り込みを Guard 境界に置く方針は妥当です。

PHPStan level 10 に適合できます。`stringList()` が不正な要素を黙って除去しないこと、および `list<string>` 化できない入力を明示的な violation にすることをテストで固定してください。

## 固有論点

### 導出鍵が APP_KEY と同一の場合

値が同一という理由だけで起動を止めるのは過剰です。修正版の「専用 env に独立して宣言されているか」で判定する方式が妥当です。

これで移行時の矛盾は解消されています。恒久値として現行 `APP_KEY` と同じ文字列を保存しても、その後の `APP_KEY` ローテートから独立するため、migration flag は不要です。

### RP ID / allowed origins を APP_URL から導出する方針

v1 の単一オリジン構成では妥当です。明示 env を必須にする必要まではありません。

ただし、APP_URL 由来の解決値そのものを厳密に検査することが条件です。上記の DNS ラベル検証を補えば、設計意図と検査が一致します。将来、複数ドメインやカスタムドメインを扱う場合は再設計が必要です。

### 版 pin の検査対象

`composer.json` と `composer.lock` の両方を見る方針が妥当です。

- `composer.json`: アプリが直接依存するという設計意図と許容バージョン範囲を固定
- `composer.lock`: 契約テストで実際に検証済みの解決バージョンを固定

0.x パッケージを直接利用している現状では、両方の検査に意味があります。

結論として、Round 1 の Critical は解消済みです。RP ID/origin host のDNSラベル検証と、CSV空要素を黙って除去しない仕様へ修正できれば **APPROVED** です。