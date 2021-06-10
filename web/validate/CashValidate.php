<?php

namespace app\web\validate;

use think\Validate;

class CashValidate extends  Validate
{
    protected $rule = [
        'mobile'=>'require',
    ];

    protected $message = [
        'mobile.require' => '请输入手机号码',
    ];

    protected $scene = [
        'cashcount'  =>  ['mobile'],
    ];
}