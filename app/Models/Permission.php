<?php

namespace App\Models;

use Laratrust\Models\Permission as PermissionModel;

class Permission extends PermissionModel
{
    /**
     * パッケージ既定は $guarded = [] (全開放) のため、明示的に許可リスト化する
     * (tests/Architecture/MassAssignmentSafetyTest の不変条件)。
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'display_name', 'description'];

    public $guarded = [];
}
