Round 3で多くの問題は解消されています。特にsubjectのAPP_KEY依存廃止、callback/disableの線形化、並行ハーネスへの依存、PinnedFailure分岐は妥当です。

ただし、認証材料更新後の身元再利用、C1のPostgreSQLトランザクション、B4のセッション破棄契約など、アカウント誤結合につながる問題が残っています。

## A1: 一時値の指紋

判定: APPROVE

一時値だけをAPP_KEY由来に限定した設計は妥当です。

- [Suggestion] `AttemptFingerprint::key()` のHKDFについて、APP_KEYのbase64デコード、salt、infoの割当をコード契約に明記すると実装差を防げます。

## A2: モデル・移行・秘密値型

判定: REQUEST_CHANGES

- [Critical] 接続削除時の `cascadeOnDelete()` によりIdentityだけが消え、Userは残ります。その後、同じIdP接続を再登録して同じsubjectでログインすると、新しいUserがJIT作成されます。企業SSO専用利用者は既存アカウントへ戻れず、アカウント分裂が起きます。

  修正案: Identityが1件でもある接続は物理削除を禁止し、Disabledとして保持してください。物理削除を許すなら、全対象Userが別のログイン手段を持つことと、再登録時のIdentity継承を設計する必要があります。v1では削除禁止が最小です。

- [Warning] `subject` の説明はbyte一致ですが、OIDCの`sub`入力検査として文字集合・長さの単位が曖昧です。

  修正案: OIDC仕様に合わせた許容文字・最大長をDTO構築時に固定し、DBの `varchar(255) COLLATE "C"` と同じ境界をテストしてください。

## A3: email nullable化

判定: APPROVE

null→非null経路でverificationを消し、昇格時に確認時刻を更新する契約は妥当です。

## B1: discovery/JWKS

判定: REQUEST_CHANGES

- [Warning] OIDC discoveryで必須となる `id_token_signing_alg_values_supported` がmetadata DTOにありません。現在はアプリ側enumだけで署名方式を許可しており、IdPが広告した方式との整合を確認できません。

  修正案: metadataへ対応署名方式を追加し、具体型・非空・許可enumとの共通部分を検査してください。ID tokenの`alg`は、アプリの許可集合とIdPの広告集合の両方に含まれることをB3で要求してください。

- [Warning] 不変条件表I1にはまだ「接続 × subjectの指紋」とあります。

  修正案: 「接続 × `COLLATE "C"` のsubject」へ全箇所を統一してください。

## B2: トークン交換

判定: REQUEST_CHANGES

- [Critical] 漏洩テストの「要求の記録にsecretのbase64/form-urlencoded形が出ない」は、実際の送信要求と両立しません。BasicならAuthorizationヘッダ、postならbodyに必ずその値が存在します。

  修正案: 次を分離してください。

  - transport seamが捕捉する実送信要求: 資格情報が正しく含まれることを検査
  - ログ、監査、例外、診断用HTTP履歴: 資格情報が平文・base64・form-urlencodedのいずれでも残らないことを検査

  「要求の記録」がどちらを指すかを曖昧にしないでください。

- [Warning] `Deadline::of(connect:, total:)` は前段TODO完了後にAPIを確認する条件分岐のままです。

  修正案: 本施策着手条件に「v0.4の確定APIへ詳細設計を追随済み」を追加し、実装時には一方へ確定してください。存在しないAPIをコード例の確定形として残さない方が安全です。

## B3: IDトークン検証

判定: REQUEST_CHANGES

- [Warning] B1のmetadataに署名方式の広告集合がないため、ID tokenのalgをIdP metadataと照合できません。

  修正案: `alg ∈ アプリ許可集合 ∩ discovery広告集合` を検査し、広告外algの署名付きtokenを拒否する負例を追加してください。

その他のJWK optional項目、aud/azp分離、claim再検査は妥当です。

## B4: ログイン試行

判定: REQUEST_CHANGES

- [Critical] セッション秘密の破棄規則を実装するための情報が呼び出し側へ残りません。

  `consume()` は最後に `$result->attemptOrFail()` を呼び、成功DTOを返すか一様な例外を投げます。しかし呼び出し側は、その失敗が次のどちらか判別できません。

  - expiredなど、行が削除済みなのでセッション秘密も消す
  - binding mismatchなど、行を保持したのでセッション秘密も保持する

  修正案: DB結果とセッション処理を同じapplication serviceで調停するか、内部用の分類結果を返してセッション処理後に外向きの一様例外へ変換してください。HTTP応答が一様であることと、内部で理由を区別することは両立します。

## C1: always-JIT

判定: REQUEST_CHANGES

- [Critical] C2が開いたPostgreSQLトランザクション内で一意制約違反をcatchし、そのまま再検索する実装は動きません。PostgreSQLでは一度SQLエラーが発生すると、rollback/savepoint回復までトランザクション全体がaborted状態になり、次のSELECTも失敗します。

  さらに接続行を `lockForUpdate()` しているため、正規経路の同一接続callbackは直列化され、通常はこの一意制約競合自体が発生しません。

  修正案: 次のいずれかへ確定してください。

  - 接続行ロックを唯一の競合制御とし、C1内の一意制約回復を削除して予期しない違反は再送出する
  - savepointを作る内側トランザクションでinsertし、savepoint rollback後に再検索する
  - C2の外側トランザクションをrollbackした後で競合制約だけを判定し、再度ロックして検索する

  現設計なら1番が最小です。

- [Warning] C1のdocblockにまだ「subjectの指紋」と残っています。

  修正案: 全記述を生のsubject＋`COLLATE "C"`へ揃えてください。

## C2: callback

判定: REQUEST_CHANGES

- [Critical] 接続IDだけを身元namespaceとしている一方、D1はissuer/client_idの更新を許しています。

  OIDCの身元は実質 `(issuer, subject)` であり、pairwise subjectではclient_id変更もnamespaceを変え得ます。同じ接続レコードのissuerやclient_idを別IdPへ変更した後、偶然同じsubjectが返ると、以前のUserへ誤ってログインさせます。

  修正案: Identityが存在する接続ではissuerとclient_idを変更不可にしてください。変更が必要なら新しい接続を作り、旧接続をDisabledで保持するのが最小です。client secretのみはDraft差し戻し＋再検証で更新可能です。

- [Warning] B4の分類結果とセッション秘密の破棄を、callbackのどの層が担当するかを明記してください。

## D1: 状態遷移

判定: REQUEST_CHANGES

- [Critical] 認証材料3種を同じ更新規則にしているため、既存Identityのnamespaceを壊します。

  修正案:

  - `issuer` / `client_id`: Identityが存在したら変更禁止。新規接続を要求
  - `client_secret`: Draftへ差し戻し、`verified_at`を消して更新可能
  - display name: 状態維持

  次のFeatureテストを追加してください。

  - Identityがある接続のissuer/client_id変更は拒否
  - 拒否後も旧接続で既存Userへログインできる
  - secret更新はDraftへ戻る
  - 新しい接続の同一subjectが旧Userへ結合されない

## D2: 管理route・画面

判定: REQUEST_CHANGES

- [Critical] `destroy` がA2のcascade問題を起こします。

  修正案: Identityがある接続のdestroyを拒否し、UIでも「無効化してください」と押下後エラーを返してください。disabledにはせず、禁止事項8を守ります。IdentityがないDraft接続だけを削除可能にする設計が自然です。

- [Warning] `dontFlash` は `client_secret` だけでは不足します。OIDC callbackの `code` / `state` やEmailPromotion tokenもvalidation失敗時にflashされ得ます。

  修正案: callbackはflashしない失敗処理にするか、少なくとも秘密入力を `dontFlash` に含めてください。汎用名をグローバル登録する影響も評価し、可能ならRequest単位で入力をflashしない実装に寄せてください。

## E1: メール昇格

判定: REQUEST_CHANGES

- [Critical] 「メールのトークンを踏んで画面でPOSTする」導線がありません。定義された3 routeはすべてPOSTなので、メール内の通常リンクから確認画面を開けません。

  修正案: 次のどちらかへ確定してください。

  - 認証必須・no-storeのGET確認画面を追加し、そこで明示ボタンからPOSTする
  - 既存設定画面へのリンクでtokenを安全に受け渡し、明示POSTする具体的な方式を設計する

  GET画面を追加する場合、新規routeは14本になり、F2の全分類も更新が必要です。メール先読みはGET画面を開くだけで、状態変更はPOSTに限定します。

- [Critical] routeコードには `auth` middlewareが見えません。表では認証必須ですが、コード上はthrottle等しかありません。

  修正案: 既存のauthenticated settings route group内へ置くことを明示するか、3 routeすべてに `auth` を明示してください。

- [Critical] 確認tokenを `dontFlash` する設計がありません。Confirm FormRequestが失敗すると、tokenがold inputとしてセッションへ残る可能性があります。

  修正案: tokenを機密入力としてflash対象外にし、`#[SensitiveParameter]`、ログ・例外・監査の漏洩テストにも追加してください。

- [Warning] `EmailPromotionToken` をログイン試行用の `FingerprintPurpose` に同居させた結果、F3のD37対象がメール昇格まで含む形になっています。

  修正案: ログイン試行とメール昇格のpurpose/導出クラスを分離するか、D37の説明と対象をEmailPromotionまで含むものへ変更してください。

## F1: gate・走査器

判定: REQUEST_CHANGES

- [Critical] G2の保証は依然として検出範囲より広いです。

  設計は固定メソッド呼び出しについて「宣言型から解決できる範囲だけ」としていますが、G2は「外向きはPinnedHttpClientだけ」と主張しています。例えばローカル変数やfactoryの戻り値に入ったHTTP clientの固定 `request()` 呼び出しは、動的構文ではなくても解決範囲外になり得ます。

  修正案: 保護対象らしい固定メソッド語彙（`request`、`get`、`post`、`send`、`fetch`等）でreceiver型が解決できない場合だけfail-closedにしてください。またはG2の主張を「既知の禁止型・ファサードの参照がない」まで明確に狭め、I3の主証明をDI結線テストとPinnedHttpClient実挙動テストへ移してください。

## F2: 13 route全分類

判定: REQUEST_CHANGES

- [Warning] `organizations.sso.index` はD2で `Gate::authorize` を通すのに、F2表では「認可: 母集団外（GET）」となっています。検査母集団外であることと、実装上認可しないことを混同しています。

  修正案: 「Gate::authorizeあり／ControllerAuthorizationGateTestの母集団外」と分けて記載してください。

- [Warning] E1にGET確認画面を追加する場合、route総数、throttle exemption、no-store、認可分類を14本へ更新してください。

## F3: 逸脱登録

判定: REQUEST_CHANGES

- [Warning] `FingerprintPurpose` はEmailPromotionTokenにも使われているため、「他の用途に使い回されていない」「ログイン試行方式の固有資産」というD37の説明と一致しません。

  修正案: fingerprint実装をログイン試行とメール昇格で分離するか、D37の対象理由を機構横断の一時token方式として書き直してください。登録対象の意味を曖昧にしない方が安全です。

- [Warning] `routes/console.php` を対象外にする判断とは別に、「日次で実行される」ことを検査する計画がありません。

  修正案: prune commandの実装テストに加え、scheduler登録自体をArchitecture/Featureテストで固定してください。コマンドが存在するだけでは日次掃除は成立しません。

## F4: 偽IdP

判定: APPROVE

環境限定、route不在、PinnedHttpClientの実経路検査を分離した構成は妥当です。

## 全体判定

CHANGES_REQUESTED

最優先の修正は以下です。

1. issuer/client_id変更後に既存Identityを再利用しない  
2. Identity付き接続の物理削除によるアカウント分裂を防ぐ  
3. C1でPostgreSQLのaborted transaction内再検索をしない  
4. B4の結果分類をセッション秘密の破棄まで保持する  
5. EmailPromotionの「メールリンク→確認画面→POST」導線と秘密入力保護を完成させる  
6. G2の保証範囲と実際の型解決能力を一致させる