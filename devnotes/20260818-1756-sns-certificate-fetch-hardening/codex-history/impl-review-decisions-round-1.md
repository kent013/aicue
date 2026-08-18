# 対応マトリクス: impl-review Round 1

## [Warning] C3 のロック site 検出が完全修飾名を解決していない

- 判断: 対応する
- 根拠: 指摘のとおり。`use Illuminate\Support\Facades\Cache as LockCache;` と書けば
  短名照合をすり抜け、ロック取得を 2 本に増やしても C3 が黙る。
  AGENTS.md「静的検査 (gate) と走査器の共通規約」(a) 違反であり、
  permit 1 という主張の根拠が実際には成立していなかった。
- 対応内容:
  - `snsCertStaticCallIndexes()` を `PhpReferenceScanner::references()` ベースへ書き換え、
    受け手を**完全修飾名まで解決**してから `Illuminate\Support\Facades\Cache` と突き合わせる。
    **未解決の受け手 (`$facade::lock()` 等) は候補に含める** (拾いすぎ側 = 見逃さない側へ倒す。規約 (b))。
  - 負例を追加: 別名つき取り込み / 完全修飾形 / 未解決の受け手の 3 形で `lockCalls` が 2 になること。
  - 正例を追加: 別名で `Cache` という短名が**別のクラス**を指す形を数えないこと (誤検出しない側)。

## [Warning] C8 / C9 が宣言している全判定の両方向を固定していない

- 判断: 対応する
- 根拠: 規約 (c)「検出力は負例で裏取りする (両方向)」と詳細設計 E の要求に未達だった。
  とくに C11 の順序判定は gate 本文にインラインで書いていたため、
  判定器そのものを合成入力に当てられなかった。
- 対応内容:
  - C11 の順序判定を純関数 `snsCertPromotionOrderViolations()` へ抽出し、gate 本文はそれを使う。
  - 負例を追加: 昇格が署名検証より前 / 昇格が 2 件 / 署名検証の site が無い、の 3 形。
  - 正例を追加: 規定どおりの順序を違反にしないこと。
  - C1 / C13b の正例を追加: 対象外のクラス参照 (`Illuminate\Http\Client\Response` /
    別名つきの `Symfony\Component\HttpFoundation\Response`) を違反にしないこと。

## [Warning] `Cache::swap()` が T228 の実行時キャッシュガードを置き換えている

- 判断: 対応する
- 根拠: 指摘のとおり。`Cache::swap()` は facade の解決済みインスタンスとコンテナの `cache` 束縛を
  素の `CacheManager` の部分モックへ差し替えるため、そのテストの間だけ
  `PlainDataGuardedRepository` を経由しなくなる。現時点でオブジェクトを書いていなくても、
  「テスト中のキャッシュ書き込みを受け皿の側で捕まえる」という不変条件 11 の被覆が
  1 ファイルぶん静かに消える形は残すべきでない。
- 対応内容:
  - `Cache` facade の差し替えをやめ、**本物の保管方式を実際に壊す**形に置き換えた
    (`useBrokenSnsCertificateCacheStore(bool $valueTableExists, bool $lockTableExists)`)。
    database driver は値とロックで**別の表**を使うので、
    「値の表だけ無い」= 読み書きだけ失敗 / 「ロックの表だけ無い」= ロック取得だけ失敗、
    を別々に再現できる。guard 付き受け皿はそのまま効いている。
  - 接続は**テスト専用の sqlite in-memory** にした。本番のテスト DB (pgsql) 上で存在しない表を
    引くと外側の transaction が abort し (`RefreshDatabase`)、後続の DB 操作がすべて
    別の理由で失敗して検証にならないためである。
  - あわせて**正のコントロール**を各テストへ入れた
    (F10: `Cache::get` が実際に `QueryException` になること /
     F11: `Cache::put` が実際に `QueryException` になること /
     F16: `Cache::lock(...)->get()` が実際に `QueryException` になること)。
    これが無いと「単に miss だった」「単に競合しなかった」と区別できない。
  - F15 (排他非対応の保管方式は fail-fast) は従来どおり `storage` driver
    (`StorageStore` は `LockProvider` を実装しない) で再現する。
  - L2 目録の件数を 6 → 7 (正のコントロールの `Cache::put` 1 件ぶん)、
    L3 の rationale から `swap` の記述を落とした。

## [Warning] 追加した L2 / L3 目録が `Cache::swap()` を固定・拒否していない

- 判断: 対応する (上の対応で解消)
- 根拠: 目録は分類であって迂回の禁止ではない。指摘の本質は
  「実行時ガードの迂回そのものをやめること」なので、目録側ではなくテスト側を直した。
- 対応内容: `Cache::swap()` の呼び出しを差分から除去した (0 件)。

## [Suggestion] docs/architecture.md の機械固定の説明が検出力より強い

- 判断: 対応する (上の C3 修正で解消)
- 根拠: C3 が完全修飾名ベースになったので、「単一ロックキー = permit 1 を機械固定する」
  という記述と実際の検出力が一致した。走査根の外・変数経由・列挙していない通信の原語に
  効かないことは既に「保証しないもの」3 に書いてある。
- 対応内容: 記述の変更は不要 (実装側が説明に追いついた)。
