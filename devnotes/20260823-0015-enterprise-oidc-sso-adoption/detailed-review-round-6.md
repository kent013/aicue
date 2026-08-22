結論として、Blade判断とCHECK制約は妥当です。`verify` の三段構成も方向性は正しいですが、実装例にセキュリティ不変条件違反と、成立しない並行テストがあります。

## 承認を妨げるもの

### D1: tenant-scoped再取得

判定: REQUEST_CHANGES

- [Critical] 第3段の再取得が、AGENTS.mdで禁止されているクラス起点の主キー同一性クエリです。

  ```php
  OrganizationOidcConnection::query()
      ->whereKey($snapshot->connectionId)
  ```

  設計冒頭では「クラス起点の主キー同一性クエリを書かない」と宣言しており、ここが矛盾します。最初のconnectionがscope binding済みでも、再取得経路自体は組織スコープを失っています。

  修正案: scoped bindingで得たOrganization relationを起点に、ロック付きで再取得してください。

  ```php
  $fresh = $organization->oidcConnections()
      ->whereKey($snapshot->connectionId)
      ->lockForUpdate()
      ->first();
  ```

  `verify()` に信頼済みの `Organization` と接続を渡す、または接続から親relationを保持する形にしてください。update/activate/disable/destroyのロック再取得も同じrelation起点へ統一する必要があります。

### D1: client secret変更の比較層

判定: REQUEST_CHANGES

- [Critical] 「`+1` を忘れた書き手を第2比較子が捕まえる」という説明は、client secretについて成立しません。

  第2比較子はissuer/client_idだけなので、client secretを変更しながらrevisionを増やし忘れた場合、古いverify結果が採用されます。「唯一のwriter」という規律だけに依存しています。

  修正案は次のいずれかです。

  - snapshotへ、生のciphertextのSHA-256等を追加し、復号せずにfreshなraw ciphertextと比較する
  - credential列の書き込み元をdeny-by-defaultのArchitecture gateでexact-fitに固定する
  - DB trigger等でrevision更新を構造的に強制する

  最小なのはraw ciphertextのdigest比較です。平文の復号は不要です。

  ```php
  hash('sha256', $connection->getRawOriginal('client_secret_encrypted'))
  ```

  これによりrevision、issuer/client_id実値、secret ciphertext digestの三層になります。

### D1/F4: verify待ち合わせテスト

判定: REQUEST_CHANGES

- [Critical] 同一プロセスで `beforeRespond` がreadyを立ててgoを待つ設計では、テスト本体がgoを立てるところまで制御を取り戻せず、デッドロックします。

  同期的なPHP呼び出しでは次の流れになります。

  1. テストが `verify()` を呼ぶ
  2. fakeのcallbackがgo待ちで停止
  3. `verify()` が戻らないため、テストはupdateもgo作成もできない

  修正案:

  - 同一プロセスで行うなら、`beforeRespond` callback自身が認証材料の更新・disable・transaction levelの表明を行って、そのまま戻る
  - ready/goで本当の並行性を作るなら、既存のprocess concurrency harnessへ載せる

  この競合で必要なのは「snapshot取得後・応答前に更新が割り込む」順序なので、前者の同期callback注入で十分です。時間待ちやready/goは不要です。

## 注目点への回答

### 1. verifyの三段構成

基本構造は妥当です。

- 外部HTTP中にトランザクションも行ロックも保持しない
- 最後にロックしてsnapshotを再確認する
- 表示名更新を `updated_at` で誤検出しない
- 同じ認証材料に対する二重verifyを冪等に扱う

ただし、上記3点――relation起点の再取得、secret比較層、成立するテスト同期――の修正が必要です。

### 2. standalone Bladeとmeta referrer

判定: APPROVE

既存のstandalone Bladeという先例があり、次の判断は整合しています。

- Inertia page objectへtokenを載せない
- hidden inputと明示POSTを使う
- `@csrf` とno-storeを付ける
- 外部リソースを読み込まない
- `<meta name="referrer" content="no-referrer">` でdocument単位に閉じる
- 共有・債務対象のSecurityHeadersを変更しない
- Vite/Tailwind外のBladeとして、既存例と同じinline CSS例外を明記する

ブラウザ履歴・proxy/CDNログへのURL露出を保証外として明記した点も適切です。

### 3. A2のCHECK制約

判定: APPROVE

保証範囲は過不足なく書けています。

- `octet_length` による1〜255バイト
- C0のうちU+0001〜U+001FとDELの拒否
- NULはPostgreSQL格納層で拒否
- C1および書式文字を許すことの明示
- DTOとDBが同じ集合を見る
- 制約名、実在検査、直接insertによる実効検査、許可側の負のコントロール

なお、CHECK制約を追加するmigrationでは `DB` facadeのimportと、migration rollback時にテーブルごと落ちることを実装時に確認すれば十分です。

## 文書上の軽微な修正

- [Warning] D1は「二段構成」と「3つの段」の両方で呼ばれています。外部取得前・外部取得・commit段の3段なので、「三段構成」に統一すると誤読がありません。
- [Warning] `ConnectionCredentialsSnapshot` の説明は、ciphertext digestを追加する場合「client secretの平文・値型を持たない」としてください。「client secretを持たない」ではdigestまで否定するように読めます。

## 施策別判定

| 施策 | 判定 |
|---|---|
| A1 | APPROVE |
| A2 | APPROVE |
| A3 | APPROVE |
| B1 | APPROVE |
| B2 | APPROVE |
| B3 | APPROVE |
| B4 | APPROVE |
| C1 | APPROVE |
| C2 | APPROVE |
| D1 | REQUEST_CHANGES |
| D2 | REQUEST_CHANGES |
| E1 | APPROVE |
| F1 | APPROVE |
| F2 | APPROVE |
| F3 | APPROVE |
| F4 | REQUEST_CHANGES |

## 全体判定

CHANGES_REQUESTED

残る承認阻害事項は3点です。

1. connectionのロック再取得をOrganization relation起点にする  
2. client secret変更もrevision忘れから独立して検出する  
3. verifyの割り込みテストを、デッドロックしない同期callbackまたは実プロセス方式へ直す