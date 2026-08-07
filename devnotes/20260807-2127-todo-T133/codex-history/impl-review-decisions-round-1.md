# 対応マトリクス: impl-review Round 1

## [Critical] `cachePayloadResolveName()` が namespace alias 付き qualified name を解決できない

- 判断: **対応する**
- 根拠: 指摘のとおり実装のバグ。`elseif (! str_contains($name, '\\'))` は「`\` を含まない
  名前に対して head 展開する」という**恒真に近い無意味な分岐**で、head 展開が必要な
  qualified name (`Facades\Cache`) にはそもそも到達しなかった。さらに到達したとしても
  `$name = $useMap[$head]` は残り (`\Cache`) を捨てるため `Illuminate\Support\Facades` に潰れる。
  この形は L2 (書き込み経路) だけでなく **L3 (面) からも消える**ので、
  「無申告でキャッシュ書き込みを増やせない」という gate の中核の主張が崩れる。
- 対応内容:
  - 条件を `str_contains($name, '\\')` に反転し、`strstr($name, '\\', true)` で head を取り、
    `$useMap[$head] . substr($name, strlen($head))` と**残りを連結**して解決するよう修正。
  - 負のコントロール fixture を追加 (`use Illuminate\Support\Facades as Facades;` の
    facade 形と `use Illuminate\Contracts\Cache as CacheContract;` の DI 形の 2 通り)。
  - mutation M14 (新規ファイルで `Facades\Cache::put(..., new \stdClass, ...)`) で
    検査 2 / 検査 4 が赤くなることを実測。
- 誇張の抑制: 完全な alias 解決を主張しない。head が use 表にある場合のみ展開する
  (group use は依然として非対応で、その旨は冒頭コメントの限界に明記済み)。

## [Critical] `app()->make('cache')->put(...)` が完全に見落とされる

- 判断: **対応する**
- 根拠: 指摘のとおり。`app` の 0 引数呼び出しは「第 1 引数が cache 束縛か」の判定に
  一致せず、続く `make` は `isMemberName` で捨てられていた。string 束縛の場合は
  import も型宣言も現れないため **L3 の粗い網にも掛からない**。実測 0 件だが、
  `app('cache')` と表記上ほぼ等価な書き方が素通りするのは「受け手を解決してから
  メソッド名を見る」という母集団定義の穴であり、限界として受容できる性質ではない。
- 対応内容:
  - コンテナ束縛判定を `cachePayloadIsCacheBindingArg()` に抽出して再利用可能にした。
  - `cachePayloadContainerMakeChain()` を新設し、`app()` (引数 0 個) → `->make(...)` /
    `->makeWith(...)` / `->get(...)` の第 1 引数が cache 束縛 literal (または受け手型の
    `::class`) のときだけ、その直後を受け手として連鎖を開始する。
  - 負のコントロール fixture (`app()->make('cache')` / `app()->make(Repository::class)` /
    `app()->get('cache.store')`) を追加。mutation M15 で赤化を実測。
- 適用範囲を広げすぎない判断: `$container->make($name)` のように**束縛名が変数**の形は
  従来どおり検出しない (静的に決まらない)。冒頭コメントの「保証しないもの」に既に明記済みで、
  今回この限界は変えていない。

## [Warning] `getFacadeRoot` を TERMINAL にしているため `Cache::getFacadeRoot()->put(...)` を追跡しない

- 判断: **対応する**
- 根拠: `getFacadeRoot()` は facade の**実体 (CacheManager)** を返すので、後続の `put` は
  本物の書き込みである。Warning 扱いだが、指摘のとおり **既に role=write のファイル
  (`FxRateService`) に足された場合は L3 でも捕まらず、write count も増えないため緑のまま**通る。
  これは「見落とし方向」の穴なので Warning でも修正する。
- 対応内容: `getfacaderoot` を TERMINAL から CHAIN へ移し (語彙表は検査 6b で互いに素を強制)、
  負のコントロール fixture を追加。mutation M16 (**既存 write ファイル内**への追加) で
  検査 2 が赤くなることを実測した。
- 注: Mockery 系 (`shouldReceive` / `spy` / `expects` 等) は TERMINAL のまま。こちらは
  受け手が期待値ビルダーに変わり payload を書かないので、CHAIN に寄せる理由がない。
