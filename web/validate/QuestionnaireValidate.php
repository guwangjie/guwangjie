<?php

namespace app\web\validate;

use think\Validate;

class QuestionnaireValidate extends  Validate
{
    protected $rule = [
        'telephone'=>'require|regex:/^1[34758]{1}\d{9}$/',
        'answer_1'=>'require',
        'answer_2'=>'require',
        'answer_3'=>'require',
        'answer_4'=>'require',
        'answer_5'=>'require',
        'answer_6'=>'require',
        'answer_7'=>'require',
        'answer_8'=>'require',
    ];

    protected $message = [
        'telephone.require' => '请输入手机号码',
        'telephone.regex'   => '手机号码错误',
        'answer_1.require'  => '题目1未选择',
        'answer_2.require'  => '题目2未选择',
        'answer_3.require'  => '题目3未选择',
        'answer_4.require'  => '题目4未选择',
        'answer_5.require'  => '题目5未选择',
        'answer_6.require'  => '题目6未选择',
        'answer_7.require'  => '题目7未选择',
        'answer_8.require'  => '题目8未选择',
    ];
    protected $scene = [
        'submit'  =>  ['telephone'],
    ];
}