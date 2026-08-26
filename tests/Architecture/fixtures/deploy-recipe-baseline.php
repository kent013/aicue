<?php

declare(strict_types=1);

namespace Deployer;

// DeployPipelineWiringTest W6 用の基準: recipe/laravel.php だけを読み込み、自前 task を 1 つも定義しない。
// これにより `dep list --raw` の出力が「recipe が元から持つ task 名の集合」になる。
// (override 台帳の entry が実在する recipe task を指していることの照合に使う)
require 'recipe/laravel.php';
