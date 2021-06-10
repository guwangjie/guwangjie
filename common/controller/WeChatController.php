<?php
namespace app\common\controller;

use EasyWeChat\Foundation\Application;
use think\Request;

class WeChatController extends ApiController
{
    protected $apps;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->apps = new Application(config('wechat.huacai_config'));
    }

}