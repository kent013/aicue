<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 生成器の**ガードによる拒否** (終了コード 3)。
 *
 * ★「実行不能」(終了コード 1) と**別の型**にしてある。同じ例外型で理由文字列だけを
 *   変える形にすると、CLI 側の終了コードの写像が文字列一致に依存して壊れる。
 *   拒否は「入力も環境も正しいが、やってはいけない生成を要求された」ことを表し、
 *   実行不能は「そもそも判定できない」ことを表す。
 *
 * 拒否になるのは 4 経路だけである:
 *  1. 既存のアプリ側指紋台帳が `role: template` である (子アプリで正典側の生成を走らせている)
 *  2. 入力の正典台帳の sha256 が pin と違うのに `--adopt-new-template-ledger` が無い
 *  3. 採用時債務一覧へ**新規パスを追加**しようとした
 *  4. 同じ正典入力のまま母集合を**縮小**しようとした
 */
final class GenerationRefused extends RuntimeException {}
