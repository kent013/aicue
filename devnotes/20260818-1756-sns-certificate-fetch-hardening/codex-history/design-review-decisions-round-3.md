# 対応マトリクス: design-review Round 3

## E. [Critical] C13a が完全修飾 / 相対修飾 / `use function` 別名を検出できない

- 判断: 対応する (fail-open の指摘は正しい)
- 対応内容: C13a の判定を書き直した。関数呼び出し位置 (直後が `(`、直前が `->` / `?->` /
  `::` / `function` でない) にある **`T_STRING` / `T_NAME_FULLY_QUALIFIED` /
  `T_NAME_RELATIVE` / `T_NAME_QUALIFIED`** を解決対象にし、
  **`use function X as Y;` の別名対応表**も作る。**解決できない関数参照は
  未解決として gate を失敗させる**。負例に指摘の 4 形
  (`file_get_contents(...)` / `\file_get_contents(...)` /
  `namespace\file_get_contents(...)` / `use function ... as fetchCertificate;` + 別名呼び出し) を
  置いた。名前解決の限界 (**走査根と同じ名前空間にローカル定義された同名関数**は
  グローバル関数の呼び出しと判定する = 拾いすぎ側へ倒す) を保証しないものへ明記した。

## E. [Warning] C12 の `T_USE` は 3 種類あるので文脈で区別する

- 判断: 対応する (trait use の adaptation block を `;` まで読み飛ばすと後続を落とす、は正しい)
- 対応内容: `T_USE` を 3 つに分けた —
  (i) brace 深さ 0 の import は `;` まで読み飛ばす、
  (ii) 直前が `)` の closure capture は対応する `)` まで読み飛ばす、
  (iii) **クラス本体の中の trait use は読み飛ばさない** (参照位置として解決するか未解決で落とす)。
  正例に closure の `use ($x)` と trait use の adaptation block (完全修飾 trait 名) を、
  負例に trait use の中の部分修飾名を置いた。

## I. [Warning] `lambdaStyleNotification()` が override で canonical キーを戻せる

- 判断: 対応する
- 対応内容: 「既定値 → override → **最後に `SigningCertURL` を unset**」の順に直し、
  「lambda キーだけ」という契約が override では壊れないようにした。
  両キーの封筒は `notification()` へ `SigningCertUrl` を足して作る、とコメントに書いた。

## C. [Suggestion] 再検討条件を観測可能なデータへ結び付ける / 否定応答 cache は補助的事情に留める

- 判断: 両方とも対応する
- 対応内容: 再検討条件を「受け口 `webhooks.ses` の応答時間 p95 / p99 (アクセスログ)」
  「`mail.sns.verification_unavailable` の件数 (アプリログ)」「受け口の 429 応答 (アクセスログ)」の
  3 つに具体化した。NXDOMAIN の否定応答 cache は「補助的な事情。別名どうしで共有されるとは
  限らないので主たる根拠にはしない」と明記した。

## E. [Suggestion] C10 は同一 chain を保証しない

- 判断: 対応する
- 対応内容: 保証しないものへ「C10 は配線 site が同じ fluent chain の上にあることまでは
  証明しない (取得口の中にそれぞれの site があることしか見ない)」を足した。

## F. [Suggestion] ヘルパ自身のテスト

- 判断: 対応する
- 対応内容: F21 を足した (呼ぶ前に既定 store へ置いた目印が消えないこと /
  2 回呼ぶとテスト専用 store の値だけが消えること)。

## K. [Suggestion] 再検討条件にどのログ・メトリクスで判断するかを併記

- 判断: 対応する (C と同じ内容を文書側にも書く)
