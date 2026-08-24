# 対応マトリクス: impl-review Round 1

## [Critical] `orgUrl()` / `currentOrgUrl()` の許可判定が生ソースへの正規表現だけで抜け道になる
- 判断: 対応する
- 根拠: 指摘のとおり。コメントの `// currentOrgUrl(` が次の行のリテラルまで届き、
  `notCurrentOrgUrl(` のような接尾辞一致でも免除されていた。
- 対応内容: 3 点で締めた。
  1. `SourceLiterals::maskComments()` を新設し、**コメントを空白へ潰した写し**の上で
     前後関係を判定する (位置は元と同一。字句規則は `walk()` 1 本に集約して 2 本持たない)。
  2. 呼び出し名の直前が識別子の文字なら一致しない lookbehind を足した
     (`notCurrentOrgUrl(` / `x.orgUrl(` を弾く)。
  3. **ファイルが `@/lib/org-url` を取り込んでいること**を前提にした
     (同名関数の自前定義では免除が効かない)。
  負例 fixture (`legacy-script-source.txt` / `legacy-shadowed-builder.txt`) で 4 形を裏取りした。

## [Critical] `/app` の「配下つきのみ」規則は設計の許可目録を弱めている
- 判断: 対応する
- 根拠: 指摘のとおり。規則にしたことで「どこにでも直書きしてよい」状態になり、
  設計が守ろうとした「入口への導線は route helper 経由だけ」が消えていた。
- 対応内容: 「配下つきのみ」規則を撤去し、裸の出現も検出する形へ戻した。
  正規入口としての出現は許可目録へ **パス + 規則 + 語 + 件数** で exact-fit 登録し
  (区分 `CanonicalCaptureEntry`)、区分の前提として
  **route 表の `capture.entry` の URI が語と一致すること**を機械検査する。

## [Critical] 「正規化済み path」を実装しておらず、絶対 URL・query/hash を見ていない
- 判断: 対応する (保証の主張を狭める形で)
- 根拠: 実装が主張に追いついていないのは (b) 違反。ただし絶対 URL は
  外部サービスの URL と字面で区別できず、host 一覧を作ると別の嘘を増やす。
- 対応内容: gate の docblock に「検出力の主張は次の範囲に**狭める**」節を新設し、
  相対 path / 1 リテラル (1 行) に収まる形だけを見ること、絶対 URL・query/hash の中・
  実行時連結・script 抽出の発見的規則は**主張から除く**ことを明記した。

## [Critical] 撤去 route 名が 1 行 1 件しか数えない
- 判断: 対応する
- 根拠: exact-fit を件数で迂回できる。
- 対応内容: `substr_count()` による**出現数**の計上へ変えた。
  自己検査の件数 pin 側も同じく出現数へ揃え、1 行 2 個の負例 (`legacy-data-source.txt`) を足した。

## [Warning] `/app/?query` と `/app/#fragment` を「配下あり」と誤判定する
- 判断: 対応する (規則ごと撤去したので消滅)
- 根拠: 「配下つきのみ」規則を撤去したため、この分岐自体が無くなった。

## [Critical] script 抽出器の「見逃し側には倒れない」という保証が事実と違う
- 判断: 対応する
- 根拠: 正規表現リテラル中の引用符で対応がずれ、指摘のコードで実際に見逃していた。
- 対応内容: (1) 正規表現リテラルを読み飛ばす発見的規則を実装 (直前の意味のある文字が
  値の終わりでないときだけ。`return` 等のキーワードも扱う)。(2) docblock の
  「倒れる方向は過検出であり見逃さない」という主張を撤回し、
  **見逃す方向にも倒れうる**と明記して利用側 gate の主張から除いた。
  指摘のコード片を fixture へ入れて回帰を固定した。

## [Critical] 許可キーが `path + rule ID` だけで対象パターンを固定できない
- 判断: 対応する
- 根拠: 同じ件数で別の旧 URL へ置き換えると通る。設計は「対象パターン完全一致」を要求している。
- 対応内容: キーを **パス + 規則 ID + 一致した語** にした (`LegacyUrlAllowance::keyOf()`)。
  語は目録に文字列で書かず、走査器が組み立てた根から選ぶ (`legacyRootEndingWith()`)。

## [Critical] `kind` が判定に使われていない (規約 (d))
- 判断: 対応する
- 根拠: 指摘のとおり説明ラベルだった。
- 対応内容: 区分を 5 つに整理し、**区分ごとの前提を `preconditionViolation()` が機械検査**する:
  `CanonicalCaptureEntry` = 語が撮影 PWA の根かつ route 表の入口 URI と一致 /
  `FilesystemPath` = 語が実在するディレクトリ / `StorageObjectKey` = 鍵を扱う印が同じファイルにある /
  `AbsenceAssertion` = 撤去の語が同じファイルにある /
  `OrganizationRelativePath` = **名指しした利用側**が実在し組織 URL を組み立てている。

## [Critical] `OrganizationRelativePath` が利用側を機械検査していない
- 判断: 対応する
- 根拠: 指摘のとおり「なんとなく直せない」の口になっていた。
- 対応内容: 登録に `consumer` (利用側のファイル) を必須にし、実在と
  「組織 URL を組み立てていること」を検査する。書かない登録は前提違反で赤になる。

## [Warning] 同一キーの重複登録が後勝ちで潰れる
- 判断: 対応する
- 対応内容: `counts()` が重複キーで例外を投げるようにした。

## [Warning] 検証結果の「許可目録は 7 件」が実装と食い違う
- 判断: 対応する
- 根拠: 報告の誤り (提示時点で 9 件)。
- 対応内容: 目録の作り直しで 32 件になったので、以降の報告は実数で書く。

## [Critical] `LegacyUrlOccurrence` の docblock が実装と乖離している
- 判断: 対応する
- 対応内容: 「rule ID が識別するのは抽出方式まで」と書き直し、
  構文の入れ替わりは**語と件数**でキーを作ることで塞いでいると明記した。

## [Critical] `routes/` 全体の除外は穴になる
- 判断: 対応する
- 根拠: closure 内の `redirect()` と撤去 route 名は route 表の検査では代替できない。
- 対応内容: 除外を **route 定義の URI 引数 1 つだけ**へ狭めた
  (`withoutRouteDefinitionUris()`)。他のリテラルと撤去 route 名は `routes/` の中でも検出する。
  合成入力の負例 (定義の URI は外れ、`redirect()` の直書きは残る) を足した。

## [Warning] `patch` / `err` の除外理由が実装 (拡張子だけ) と食い違う
- 判断: 対応する
- 対応内容: 拡張子の除外から削除した (`devnotes/` の接頭辞除外で足りており、
  他所に現れたら未分類として赤くなる)。

## [Warning] symlink / NUL / 不正 UTF-8 の fail-closed 分岐に負例が無い
- 判断: 見送る
- 根拠: これらの分岐は `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets` と同じ形で、
  発火させるには追跡下に壊れた symlink や NUL を含むファイルを置く必要がある。
  母集団の `unresolved` が 0 件であることは gate が毎回見ているので「集めて使っていない」
  状態ではない。負例の追加は別 TODO の候補として棚卸しに残す。

## [Critical] gate が保証範囲を狭めていない
- 判断: 対応する (上の「正規化済み path」と同じ対応)

## [Warning] Blade / JSON の検出正例・非検出正例が無い
- 判断: 対応する
- 対応内容: `legacy-blade-source.txt` / `legacy-data-source.txt` を足し、
  種別ごとの検出力をデータセットで裏取りした (7 種別)。

## [Critical] 自己検査の件数が本体と同じ数え方で独立していない
- 判断: 対応する
- 対応内容: 自己検査側は**全文の出現数**で数える (本体の抽出方式を通さない) ことを明記し、
  撤去 route 名も出現数で数えるようにした。

## [Critical] `OrganizationRouteHandlerParameterTest` が位置を見ていない / closure を除外している
- 判断: 対応する
- 根拠: 指摘のとおり。名前の有無だけでは位置ずれを防げず、closure も同じ resolution を通る。
- 対応内容: handler の引数のうち route parameter と同名のものを**宣言順**に取り出し、
  route parameter の並びと**同じ順序**であることを検査する形にした。closure も
  `ReflectionFunction` で同じ検査に掛ける。負例は欠落と順序違いの 2 形を置いた。
  この検査で `capture.csrf-cookie` の closure が `{organization}` を受けていないことが
  実際に見つかったので、同じ変更で直した。

## [Critical] `allowed-paths.md` が裸の `/app` を無条件の許可例にしている
- 判断: 対応する
- 対応内容: 裸の `/app` を負例から外した (検出される側へ戻したため)。

## [Warning] `legacy-php-source.txt` に複雑な補間・重複 route 名が無い
- 判断: 一部対応する
- 対応内容: 1 行 2 個の撤去 route 名は `legacy-data-source.txt` で裏取りした。
  複雑な補間の追加は見送り、`SourceLiterals` の docblock に
  「連結・複雑な補間は保証しない」と明記済みであることを確認した。

## [Warning] `NotificationController` の TicketBalanceLow が組織不一致でも現在の URL の課金画面へ送る
- 判断: 対応する
- 根拠: 通知の対象と操作先が食い違う。組織を URL 以外から読み替えない裁定とも整合しない。
- 対応内容: manual 系と同じく、**通知の org と URL の組織が一致するときだけ**その組織の
  購入画面へ送り、一致しなければ一覧へ 303 + 案内へ倒すようにした。
