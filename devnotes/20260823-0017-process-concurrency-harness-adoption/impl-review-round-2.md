Round 1 の主要5件は概ね適切に修正されています。特に rollback 実証、canonical env parser、複合回収エラー、cache driver の独立負例は十分です。ただし、秘密の保護範囲と2件の回帰テストがまだ不足しています。

## ファイルごとの判定

### `ConcurrencyProbeRunner.php`

[Warning] 秘密の伏せ字が stderr 経路にしか適用されていません。

子が制御する次の値は、引き続き無加工で例外メッセージへ入ります。

- `ready` の不正 payload
- `entered` の不正 payload
- 観測 JSON の `nonce` / `go_token` / `error_code`
- 回収失敗時に previous として保持される元例外

例えば malformed child が plain API key を `ready` payload として書くと、`identityMismatch()` の `$actual` にそのまま入り、CIログへ残せます。ハーネスは不正な子出力を fail-closed で扱う設計なので、正常な probe が秘密を書かないことだけでは境界を閉じられません。

子由来の値を診断へ表示しないか、すべての例外生成経路で既知の秘密を伏せてください。少なくとも ready payload と観測フィールドへ sentinel を入れた負例が必要です。

[Warning] `uri` の新しい受理条件に負例がありません。

照合実装は正しいですが、全テストの観測は正しい URI のままです。URI検査を削除してもテストが緑になります。`harnessProtocolScript()` の observation override で不正 URI を返し、`release` 後または最終受理時に拒否されることを固定してください。

[Warning] symlink を辿らない削除にも回帰テストがありません。

実装順序は正しいものの、`is_link()` 分岐を削除してもテストが検出しません。workspace 外に sentinel ファイルを置いたディレクトリを作り、workspace 内から symlink したうえで回収し、外側が無傷であることを検査してください。

### `ConcurrencyHarnessFailurePathTest.php`

[Warning] sentinel 検査が「既知の秘密5種」のうち3種しか検証していません。

現在確認しているのは以下です。

- plain API key
- raw body
- APP_KEY

未確認なのは以下です。

- CIPHERSWEET_KEY
- DB_PASSWORD

この2項目が `$secrets` から削除されても群4-43は緑です。偽プロセスしか使わないため、テスト内で設定を sentinel に差し替えて stderr に全5種を載せ、すべて消えることを検査できます。

また、上記の URI 不一致と symlink 非追跡の負例もこのファイルに追加するのが自然です。

### `OutOfTransactionFixturesTest.php`

OKです。行を実際に作った後で例外を投げ、8表すべての rollback を検査しているため、`DB::transaction()` の除去を検出できます。

### `ConcurrencyProtocolException.php`

実装自体はOKです。3 factory を `reapFailed()` へ一本化した判断も、後方互換を並走させない規約に合っています。

ただし、呼び出し側から渡される previous や子由来文字列が秘密を含まない保証はまだありません。

### `ProbeEnvironment.php`

OKです。正規表現と復号処理は encoder が生成する3 escape に閉じており、未知 escape、裸の `$`、重複キー、非canonical書式の負例も揃っています。

### `idempotency-claim-probe.php`

OKです。総括 catch を例外クラスと位置だけに限定したことで、切り詰められた trace 引数を親側の完全一致置換で消せない問題は解消しています。段6のメッセージを残す判断も、実装上キー名しか含まないため妥当です。

## Round 1 指摘の状態

- stderr の秘密漏えい: 部分解消。stderr は閉じたが、他の子由来 payload 経路が残る
- transaction rollback: 解消
- cache driver 負例: 解消
- 複合回収失敗: 解消
- strict parser: 解消
- URI未使用: 実装は解消、回帰テスト不足
- symlink再帰: 実装は解消、回帰テスト不足

最終版での full `composer test` も未完了ですが、判定を止める主因は上記のコード・テスト上の残件です。

CHANGES_REQUESTED