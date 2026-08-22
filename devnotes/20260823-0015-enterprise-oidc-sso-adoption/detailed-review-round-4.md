Round 4で中核設計はかなり固まりました。ただし、キャッシュ契約、状態遷移の競合制御、consume分類に承認を妨げる問題が残っています。

## 承認を妨げるもの

### B1: discoveryキャッシュ

判定: REQUEST_CHANGES

- [Critical] metadata DTOへ `idTokenSigningAlgorithms` を追加した一方、キャッシュ保存スキーマに同項目がありません。

  現在のスキーマは以下だけです。

  ```text
  issuer / authorization_endpoint / token_endpoint / jwks_uri / auth_methods
  ```

  キャッシュhit後はIdP広告algを復元できず、B3の検査が成立しません。

  修正案: `id_token_signing_algorithms: non-empty-list<string>` を保存スキーマへ追加し、破損・空配列・未知値を検出したらforgetするテストを追加してください。

- [Critical] B1のコード例が、G3で確定した例外constructorと矛盾します。

  ```php
  throw new EnterpriseSsoAttemptRejectedException('discovery_not_json');
  ```

  G3では「理由enumだけを受け取り、previousを受け取れないconstructor」としています。

  修正案: B1を含む全生成箇所を次の形へ統一してください。

  ```php
  throw EnterpriseSsoAttemptRejectedException::of(
      RejectionReason::DiscoveryNotJson,
  );
  ```

### B4: consume結果

判定: REQUEST_CHANGES

- [Critical] コードには `AttemptConsumeResult::consumeFailed()` があるのに、分類表は4通りしかなく、consumeFailed時の行・セッション秘密・応答が未定義です。

  修正案は次のいずれかです。

  - `consumeFailed` を5番目の分類として定義し、行が残るなら秘密も保持する
  - より自然には、`delete() !== true` をインフラ障害として例外にし、トランザクションをrollbackする。認証上の一様な拒否へ握り潰さず、行と秘密を保持する

  後者なら「業務上の拒否では例外を投げない」と「DB障害は握り潰さない」を分けて記述できます。

### D1/D2: 更新・削除とcallbackの競合

判定: REQUEST_CHANGES

- [Critical] 身元0件ならissuer/client_idを変更できるとしましたが、その場合に状態をどうするかが未定義です。Activeのまま変更できると、未検証の新構成で直ちにログインできます。

  修正案: 身元0件でもissuer/client_id変更時は必ずDraftへ戻し、`verified_at` を消してください。

- [Critical] 「身元があるか」の確認と更新・削除をcallbackと直列化する契約が不足しています。

  次の競合があり得ます。

  1. 管理操作が身元0件を確認
  2. callbackが接続行をlockしてJIT
  3. 管理操作がissuer更新または物理削除

  修正案: issuer/client_id更新とdestroyも、disableと同様に接続行を `lockForUpdate()` してから、同一トランザクション内でIdentityの存在確認と更新・削除を行ってください。callbackとのロック順を統一し、次を並行ハーネスで固定してください。

  - callbackが先なら、更新・削除は身元ありとして拒否
  - 更新・削除が先なら、callbackはDraft化または接続不在によりJITしない

### A2: subjectのDB境界

判定: REQUEST_CHANGES

- [Warning] PostgreSQLの `varchar(255)` は255バイトではなく255文字です。「DTOとDBが同じバイト境界」という説明は成立しません。

  修正案: 次のどちらかへ確定してください。

  - DBにも `octet_length(subject) BETWEEN 1 AND 255` のCHECK制約を置く
  - バイト長保証はDTO境界だけと明記し、「DBと同じ境界」という主張を削除する

  subjectは身元キーなので、DB制約も置く方が堅牢です。

## 文書整合として実装前に直すもの

### C1

判定: REQUEST_CHANGES

- [Warning] docblockにまだ「接続id, subjectの指紋」と残っています。生のsubject＋`COLLATE "C"`へ修正してください。
- [Warning] migrationの一意制約コメントにも「C1がこの制約だけを回復対象にする」と残っています。Round 4では回復処理を削除したため、「最後の防波堤。違反は再送出する」へ更新してください。

### E1/F2/実装モード

判定: REQUEST_CHANGES

- [Warning] route数が複数箇所で古いままです。

  - 施策一覧E1: route 3本
  - F2見出し: 10 route
  - 実装モード: routes/web.phpへ13 route
  - 実際の設計: 14 route

  修正案: すべて14本へ統一してください。

### E1: 確認画面のtoken

判定: REQUEST_CHANGES

- [Warning] メールリンクからGET確認画面へtokenをどう渡すか、その露出範囲が未確定です。queryへ原文tokenを載せる場合、no-storeではアクセスログ・ブラウザ履歴・Refererへの露出を防げません。

  修正案: tokenがURL queryに載ることを受容するなら、保証外として明記し、最低限以下を固定してください。

  - 外部リソースを読み込まずRefererを出さない
  - `Referrer-Policy: no-referrer`
  - GETではDB状態を変えず、tokenの存在可否も一様な画面にする
  - アプリログ・監査・例外へ完全URLを記録しない
  - Inertia props/historyに残す場合の扱いを明記する

  URLへ載せない方針なら、fragmentや手入力等の具体的なPOSTへの受け渡し方式が必要です。

### F3

判定: REQUEST_CHANGES

- [Warning] 新設する `EnterpriseSsoPruneScheduleTest.php` がD37の対象パスに含まれていません。D37が対応テストも対象に含める方針なら追加してください。
- [Warning] D37を機構横断の一時token方式へ広げたため、再判定条件もメール昇格側の正典化を含む形へ更新してください。

## 実装時に確認すればよいもの

以下は設計承認後、実装時の検証で確定可能です。

- B2のssrf-pin v0.4におけるdeadline/body APIの最終形
- G2の保護対象語彙が通常コードを誤検出しないこと
- `COLLATE "C"` のスキーマ introspection結果の表記
- 並行ハーネス上でのcallback/disable/update/destroyの同期点
- PinnedHttpClientが値のfailure以外に投げる例外型。投げ得る場合は、秘密を含むvendor例外をpreviousなしの固定例外へ変換する
- subjectの許容文字について、正典がASCII限定を要求するか、UTF-8の非制御文字を許すか

## 施策別判定まとめ

| 施策 | 判定 |
|---|---|
| A1 | APPROVE |
| A2 | REQUEST_CHANGES |
| A3 | APPROVE |
| B1 | REQUEST_CHANGES |
| B2 | APPROVE（v0.4 API確認を着手条件とする） |
| B3 | APPROVE |
| B4 | REQUEST_CHANGES |
| C1 | REQUEST_CHANGES |
| C2 | APPROVE |
| D1 | REQUEST_CHANGES |
| D2 | REQUEST_CHANGES |
| E1 | REQUEST_CHANGES |
| F1 | APPROVE |
| F2 | REQUEST_CHANGES |
| F3 | REQUEST_CHANGES |
| F4 | APPROVE |

## 全体判定

CHANGES_REQUESTED

承認を妨げる中心課題は次の4点です。

1. discoveryキャッシュにIdP広告algを保存する  
2. `consumeFailed` の意味を確定する  
3. issuer/client_id更新をDraftへ戻し、更新・削除も接続行ロックでcallbackと直列化する  
4. subjectの「255バイト」とDB制約の関係を正しくする  

それ以外は、主に文書内の旧記述・route件数・token URLの保証範囲の整理です。