### tests/Architecture/InvitationContinuationKeySoTTest.php

判定: 問題なし

走査根と読み取り処理が注入可能になり、IC-4b で以下を直接固定できています。

- 走査根不存在を例外にする
- 読み取り失敗を黙って除外せず例外にする

実運用時の既定値も維持され、走査対象や判定範囲を弱めていません。Round 1 の Warning は解消済みです。

### tests/Unit/Support/Auth/InvitationContinuationTest.php

判定: 問題なし

SessionStore の spy により、鍵なし時と有効 token 解決時の `forget()` 非呼び出しを直接観測できています。事後状態だけに依存した偽グリーンは解消されました。

継承メソッドの型も PHPStan level 10 を通過しており、テスト専用 spy として妥当です。Round 1 の Warning は解消済みです。

## 全体判定

**APPROVED**

Round 1 の2件はいずれも承認済み設計と走査器規約に沿って修正されており、新たな問題は見当たりません。