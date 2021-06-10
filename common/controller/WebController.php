<?php

namespace app\common\controller;

use Firebase\JWT\JWT;
use think\Controller;
use think\Db;
use think\Request;
use app\dashboard\model\Log;

class WebController extends Controller
{

    protected $statusCode = 200;
    protected $request;
    protected $data;
    protected $ip;

    protected $Log;
    const SYSTEM_ERROR = -1; //系统错误
    const EMPTY_ERROR = -2; //返回值为空
    const SUCCESS = 200; //请求成功
    const ERROR = 400; //失败
    const ERROR_NO_AUTH = 1000; //签名失败
    const EMPTY_PARAMETER = 450;
    const UNLOGIN = 2001; //未登录
    const INVALID_PARAMETER = 2002; //不合法的参数,缺少参数
    const INVALID_REQUEST = 2003; //不合法的请求格式
    const INVALID_TOKEN = 2004; //不合法TOKEN
    const CHECK_CODE_ERROR = 2006; //验证码错误
    const UN_REGISTERED = 2007; //未注册
    const REGISTERED = 2009; //已注册，直接登录
    const ACCOUNT_SYSTEM = 2018;//系统账号
    const ACCOUNT_BAD = 2019;//账号异常
    const PAYMENT_ERROR = 2030;//支付失败

    const AUTH_FALSE = 0; //未认证
    const AUTH_TURE  = 1; //已认证
    const qinniu_url = 'https://qiniu.huacaijia.com';

    public function __construct(Request $request)
    {
        parent::__construct();
        $this->request = $request;
        $this->ip = $request->ip();
        $this->data = $request->param();

        $this->Log = new Log();
        $GLOBALS['user_id']   = 1;
        $GLOBALS['user_rank'] = 23;
        $GLOBALS['discount']  = 0.95;
        $this->checkDashBoardAuth();
        /*--未登录时默认折扣和等级*/
    }

    protected function checkDashBoardAuth(){


        $controller = $this->request->controller();
        $action = $this->request->action();

        $notRequireController = ['AuthController','AjaxController','IndexController','MyPlantController'];

        $notAuthRuleController = ['GeneralController']; //需验证是否登录，不需验证是否有权限

        if (!in_array($controller, $notRequireController)) {

            $token = $this->request->header('accessToken');
//
            if(!isset($token) || empty($token)){
                abort(401,' Unauthozied');
            }

//            if(!isset($this->data['accessToken']) || empty($this->data['accessToken'])){
//                abort(401,' Unauthozied');
//            }
//            $token = $this->data['accessToken'];

            $decoded = (array)JWT::decode($token, config('others.salt'), array('HS256'));

            if(time()<$decoded['iat'] || time()>$decoded['exp']){
                $this->result(NULL,self::INVALID_TOKEN,'授权已过时,请重新登录','json');
            }
//            $route = strtolower($controller.'/'.$action); 没必要精确到action
            $route = strtolower($controller);

            if($decoded['role'][0] != '-1'){
                $btn=false;

                if (in_array($controller,$notAuthRuleController)){
                    $btn = true;
                }else{
                    $rule_id = Db::name('admin_rule')->where('name','like','%'.$route.'%')->column('rule_id');
                    foreach ($rule_id as $value){
                        if(in_array($value,$decoded['role'])){
                            $btn=true;
                        }
                    }
                }

                if(!$btn){
                    $this->result(NULL,self::INVALID_TOKEN,'该用户无此权限','json');
                }
            }

            $GLOBALS['user_id'] = $decoded['user_id'];
            $GLOBALS['user_name'] = $decoded['user_name'];
            $GLOBALS['store_id'] = $decoded['store_id'];

            $GLOBALS['controller'] = strtolower($controller);
            $GLOBALS['action'] = strtolower($action);

        }
        return true;
    }

    /*-纯粹验证用户合法性 调一些不重要的接口不判断权限控制-*/
    public function actionAuth(){

        $token = $this->request->header('accessToken');

    

        $decoded = (array)JWT::decode($token, config('others.salt'), array('HS256'));

        if(time()<$decoded['iat'] || time()>$decoded['exp']){
            $this->result(NULL,self::INVALID_TOKEN,'授权已过时,请重新登录','json');
        }

        if (isset($decoded['store_id'])){
            $GLOBALS['store_id'] = $decoded['store_id'];
        }

    }

    protected function _validate()
    {
        foreach (func_get_args() as $key) {
            if (! isset($this->data[$key])) {
                throw new \Exception("The $key parameter is required");
            }
        }
    }

    /**
     * 防止重复提交表单redis锁
     * @param $arg_name {锁的键名}
     * @param $arg_id {锁的键值}
     * @param $ttl {锁时间}
     */
    public function submit_lock($arg_name,$arg_id,$ttl){

        $controller =  $this->request->controller();
        $action     =  $this->request->action();
        $lock_name = sprintf('%s/%s/%s/%d',$controller,$action,$arg_name,$arg_id);
        if(redis(3)->exists($lock_name)){
            abort(500,'操作频繁,请稍后再试');
        }

        redis(3)->set($lock_name,1,['EX'=>$ttl]);
    }

    /**
     * Get the status code.
     *
     * @return int $statusCode
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * Set the status code.
     *
     * @param $statusCode
     * @return $this
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * Repond a no content response.
     *
     * @return response
     */
    public function noContent()
    {
        return json(null, 204);
    }

    public function respondWithArray($array,$msg='请求成功', array $headers = [])
    {
        return json_success($array, $msg,$this->statusCode, $headers);
    }
}