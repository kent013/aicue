# 対応マトリクス: design-review Round 2

## [Critical] 施策 5: adapter 目録と面分類目録の対象が一致していない (fake 3 クラス)

- 判断: **対応する**（提案された修正案をそのまま採用）
- 根拠: 指摘のとおり自己矛盾していた。境界目録で fake 3 クラスを `surface: adapter` と
  していながら、面分類目録 (`S3SurfaceInventory::all()`) には実装 2 クラスしか登録しない
  設計だったため、検査 6 を素直に実装すると必ず fail する。
- 対応内容:
  1. 免除 enum に `TestDoubleWithoutExternalEgress` を追加した
     （適用条件: `disk()` が s3 以外のローカル disk (`s3_fake`) に固定されているか、
     `client()` が例外を投げて実 SDK 経路へ落ちないこと）。
  2. fake 3 クラス（`FakeTakeObjectStorage` / `FakeRenderObjectStorage` / `FakeObjectStore`）を
     `surface: exempt` へ移し、**`surface: adapter` の意味を
     「public method ごとの面分類を要求する本番集約」に定めた**（目録にコメントで明記）。
  3. **検査 5b を新設**し、
     `surface === 'adapter'` のクラス集合 == `S3SurfaceInventory::all()` のキー集合
     を固定した（2 つの目録の意味を機械で結ぶ。片方だけ更新される drift を断つ）。
  4. mutation #16（fake を `adapter` に戻すと検査 5b が赤くなる）を追加した。

## [Critical] 施策 5: Stripe setter の期待集合が自己矛盾している

- 判断: **対応する**（提案された「シンボル × 走査範囲」の分割をそのまま採用）
- 根拠: 指摘のとおり。設計は provider で `new CurlClient` を使うと明記しながら、
  検査 5 は `CurlClient::instance` の site 集合も `{provider}` としており矛盾していた。
  さらに走査範囲に `tests/` を含める以上、施策 2・6 のテストの `setHttpClient()` も
  当然検出されるのに期待集合へ入っていなかった。
- 対応内容: 検査 5 を**シンボルごと × 走査範囲ごと**の期待値表に置き換えた:

  | シンボル | `app/` | `tests/` |
  |---|---|---|
  | `ApiRequestor::setHttpClient` | provider に 1 件 | 明示 2 ファイルのみ |
  | `Stripe::setMaxNetworkRetries` | provider に 1 件 | provider テスト 1 ファイルのみ |
  | `CurlClient::instance` | **0 件** | **0 件** |
  | `ApiRequestor::httpClient` (getter) | 0 件 | 制限しない（状態を変えない） |

  併せて mutation #17（provider を `CurlClient::instance()` に変えると赤）と
  #18（無関係なテストに setter を足すと赤）を追加した。

## [Warning] 施策 3: SES behavioral テストの fallback が vendor 契約の検証を無効化する

- 判断: **対応する**（fallback を削除）
- 根拠: 指摘が正しい。`new SesV2Client(...)` へ落とすと
  「`MailManager` が `services.ses` を素通しする」という**pin が効く根拠そのもの**を
  検証できなくなり、gate が意味を失う。反論の論拠を自分で壊すことになる。
- 対応内容:
  - テスト計画から直接構築の fallback を削除し、
    「解決できない場合は `config(['mail.default' => 'ses'])` 等で**局所的に設定を整えて
    `MailManager` 経由で解決**する。それでも駄目なら**前提の破綻として fail させる**」に変更した。
  - 「未解決 / 実装時に確認すること」の項目も同じ方針へ書き換え、
    実装時確認に残すのは**具体的な設定値だけ**とした。
