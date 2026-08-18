<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 静的呼び出しの受け手 (receiver) を完全修飾名まで解決できたか。
 *
 * ★**未解決を「受け手が無い」と同じ値へ潰さない**。潰すと利用側は
 *   「見なくてよい site」と「解決できなかった site」を区別できず、
 *   `$client::setHttpClient()` のような書き方が**無言で候補から外れる**
 *   (`AGENTS.md` の共通規約 (b) が禁じる形)。
 */
enum ReceiverResolution
{
    /** 完全修飾名まで解決できた。 */
    case Resolved;

    /**
     * 受け手は書かれているが、静的には確定できない。
     *
     * 変数 (`$gateway::`) / 遅延静的束縛 (`static::`) / 親クラス (`parent::`) /
     * 式の結果 (`foo()::`) など。利用側は**拾いすぎる方向**へ倒して扱う。
     */
    case Unresolved;

    /** そもそも受け手を持たない種別 (`NameReference` / `Construction` / `MethodCall`)。 */
    case Absent;
}
