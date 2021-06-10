<?php

namespace app\web\controller;

use app\common\controller\ApiController;
use app\common\controller\Upload;
use app\common\model\Users;
use Doctrine\Common\Cache\RedisCache;
use EasyWeChat\Foundation\Application;
use think\Db;
use think\facade\Config;
use think\Request;
use think\exception\HttpException;

class WeChatController extends ApiController
{
    protected $apps;
    protected $Users;


    protected $options = [
        'debug'  => true,
        'app_id' => 'wx5f90a861eb4c7bef', //花彩+服务号key wx936787efefc58e83
        'secret' => 'ae9965298832fcf185211d017cca5742', //4fb2f32cc7684ac037a98bb2aaf9ad44
        // token 和 aes_key 开启消息推送后可见
        'token'  => 'LosingBattle',
        'oauth' => [
            'scopes'   => ['snsapi_userinfo'],
            'callback' => 'https://nd.losingbattle.site/web/wechat/js_sdk',
        ],
        // 'aes_key' => null, // 可选
        'mini_program' => [
            'app_id'   => '',//花彩小程序key
            'secret'   => '',
            // token 和 aes_key 开启消息推送后可见
            'token'    => 'your-token',
            'aes_key'  => 'your-aes-key'
        ],
        'log' => [
            'level' => 'debug',
            'file'  => '/tmp/easywechat.log', // XXX: 绝对路径！！！！
        ],
            //...
    ];

    protected $option2 = [
        'debug'  => true,
        'app_id' => 'wx764830f55f9a9054', //花彩+服务号key wx936787efefc58e83
        'secret' => '796f8bf691f95268fdef0cd6e1c0d71b', //4fb2f32cc7684ac037a98bb2aaf9ad44
        // token 和 aes_key 开启消息推送后可见
        'token'  => 'LosingBattle',
        'oauth' => [
            'scopes'   => ['snsapi_userinfo'],
            'callback' => 'https://nd.losingbattle.site/web/wechat/js_sdk',
        ],
        // 'aes_key' => null, // 可选
        'mini_program' => [
            'app_id'   => '',//花彩小程序key
            'secret'   => '',
            // token 和 aes_key 开启消息推送后可见
            'token'    => 'your-token',
            'aes_key'  => 'your-aes-key'
        ],
        'log' => [
            'level' => 'debug',
            'file'  => '/tmp/easywechat.log', // XXX: 绝对路径！！！！
        ],
    ];

    protected $option3 = [
        'debug'  => true,
        'app_id' => 'wx5f90a861eb4c7bef', //花彩+服务号key wx936787efefc58e83
        'secret' => 'ae9965298832fcf185211d017cca5742', //4fb2f32cc7684ac037a98bb2aaf9ad44
        // token 和 aes_key 开启消息推送后可见
        'token'  => 'LosingBattle',
        'oauth' => [
            'scopes'   => ['snsapi_userinfo'],
            'callback' => 'https://nd.losingbattle.site/web/wechat/js_sdk',
        ],
        // 'aes_key' => null, // 可选
        'mini_program' => [
            'app_id'   => '',//花彩小程序key
            'secret'   => '',
            // token 和 aes_key 开启消息推送后可见
            'token'    => 'your-token',
            'aes_key'  => 'your-aes-key'
        ],
        'log' => [
            'level' => 'debug',
            'file'  => '/tmp/easywechat.log', // XXX: 绝对路径！！！！
        ],
    ];


    public function __construct(Request $request,Users $users)
    {
        parent::__construct($request);
        $cacheDriver = new RedisCache();
        $cacheDriver->setRedis(redis());

        $this->options = Config::get('wechat.huacai_config');
        $this->options['cache'] = $cacheDriver;
        if($this->source =='huacai-xcx'){
            $this->options['mini_program']['app_id'] = 'wx8d2ca50855845d1d';
            $this->options['mini_program']['secret'] = 'ea92515d5196788bed990722a90651a1';
        }

        if($this->source == 'md-xcx'){
            $this->options['mini_program']['app_id'] = 'wx384704c6a3730b20';
            $this->options['mini_program']['secret'] = '40879cdbbc874c3ef879c41cb9a0c011';
        }

        $this->apps = new Application($this->options);
        $this->Users = $users;

    }


    public function xcxoauth(){

        $validate = $this->validate($this->data, 'app\web\validate\WechatValidate.xcxlogin');

        if($validate !== true){
            throw new HttpException(self::INVALID_PARAMETER, $validate);
        }
        $miniProgram = $this->apps->mini_program;

        $cb = $miniProgram->sns->getSessionKey($this->data['code']);

        $decryptData = $miniProgram->encryptor->decryptData($cb['session_key'], $this->data['iv'],$this->data['encryptedData']);
        if(isset($this->data['type'])&&$this->data['type']==1) return $this->respondWithArray($decryptData);
        $res = Db::name('users')->where('wxunionid',$decryptData['unionId'])->find();

        /*已绑定返回key*/
        if($res){

            $update = [
                'last_login' => time(),
                'last_time'  => date('Y-m-d H:i:s'),
                'last_ip'    => $this->request->ip(),
                'visit_count'=> Db::raw('visit_count+1')
            ];
            /*-初始化头像和昵称-*/
            if(isset($this->data['avatar']) && !$res['avatar']){
                $upload = new Upload();
                $imageContent = getRemoteFileWithCurl($this->data['avatar']);
                $result = $upload->single_upload_stream($imageContent);
                $update['avatar'] = self::qinniu_url.'/'.$result;
//                $update['avatar'] = $this->data['avatar'];
            }
            if(isset($this->data['nick']) && !$res['nick']){
                $update['nick'] = $this->data['nick'];
            }

            try{
                Db::name('users')->where('user_id',$res['user_id'])->update($update);
                $token = $this->generateToken($res['mobile']);
                $info = $this->Users->user_info($res['user_id']);
//                $this->Users->recalculate_price($res['user_id']);
                $this->redis_store($token,$res['user_id'],$info['mobile']);
            }catch (\Exception $e){
                abort(500,$e->getMessage());
            }

            return $this->respondWithArray(['key'=>$token,'openid'=>$cb['openid'],'info'=>$info],'登陆成功');
        }else{
            redis()->setex($decryptData['unionId'],300,$cb['openid']);
            return $this->setStatusCode(self::UN_REGISTERED)->respondWithArray(['wxunionid'=>$decryptData['unionId']],'请绑定手机号');
        }

    }


    public function binding(){
        $validate = $this->validate($this->data, 'app\web\validate\WechatValidate.binding');

        if($validate !== true){
            return json_error(NULL,$validate,self::INVALID_PARAMETER);
        }
        $platform = isset($this->data['platform']) ? $this->data['platform'] : '';
        $info = (new Users())->findUser($this->data['mobile']);
        if($info['is_delete']==1){
            return json_error('','该号码的账户已注销',self::SYSTEM_ERROR);
        }
        if($platform!='mini_program'){
            $flag = $this->checkCode($this->data['sms_code'],$this->data['mobile'],5);
            if($flag !==true){
                return $flag;
            }
        }
        $openid = redis()->get($this->data['wxunionid']);
        if(!$openid){
            abort(500,'操作时间过长请稍后重试');
        }
        /*-用户已存在则更新字段 不存在则注册用户-*/
        if($info){

            if(!empty($info['wxunionid'])){
                return json_error(NULL,'该手机号已绑定了微信账号',self::REGISTERED);
            }

            $update['last_login'] = time();
            $update['last_time'] = date('Y-m-d H:i:s');
            $update['last_ip'] = $this->request->ip();
            $update['visit_count'] = Db::raw('visit_count+1');
            $update['wxunionid'] =$this->data['wxunionid'];//更新wxunionid字段
            /*-初始化头像-*/

            if(isset($this->data['avatar']) && !$info['avatar']){
                $upload = new Upload();
                $imageContent = getRemoteFileWithCurl($this->data['avatar']);
                $result = $upload->single_upload_stream($imageContent);
                $update['avatar'] = self::qinniu_url.'/'.$result;
//                $update['avatar'] = $this->data['avatar'];
            }
            if(isset($this->data['nick']) && !$info['nick']){
                $update['nick'] = $this->data['nick'];
            }
            Db::startTrans();
            try{
                Db::name('users')->where(['user_id'=>$info['user_id']])->update($update);
                $this->Users->openid_operate($info['user_id'],$openid,$platform,true);
                Db::commit();
                $token = $this->generateToken($info['mobile']);
                $permission_info = $this->Users->user_info($info['user_id']);
//                $this->Users->recalculate_price($info['user_id']);
                $this->redis_store($token,$info['user_id'],$permission_info['mobile']);
            }catch (\Exception $e){
                Db::rollback();
                abort(500,$e->getMessage());
            }

        }else{
            Db::startTrans();
            try{
                $oauth_data = [];
                if(isset($this->data['avatar'])){
                    $oauth_data['avatar'] =  $this->data['avatar'];
                }
                if(isset($this->data['nick'])){
                    $oauth_data['nick'] =  $this->data['nick'];
                }

                //小程序、wap站微信登录新增用户绑定手机号口子埋点
                if ($platform == 'mini_program')
                    $account_from = 'mini_program';
                elseif($platform == 'official_accounts')
                    $account_from = 'huacai_h5';
                else
                    $account_from = 'app_default';

                $user_id = $this->Users->register($this->data['mobile'],$this->data['wxunionid'],null,$oauth_data,$account_from);
                $this->Users->openid_operate($user_id,$openid,$platform,true);
                Db::commit();
                $token = $this->generateToken($this->data['mobile']);
                /*存储用户全局信息到redis*/
                $this->redis_store($token,$user_id,$this->data['mobile']);

                /*返回个人信息*/
                $permission_info = $this->Users->user_info($user_id);

            }catch (\Exception $e){
                Db::rollback();
                abort(500,$e->getMessage());
            }
        }

        return $this->respondWithArray(['key'=>$token,'openid'=>$openid,'info'=>$permission_info],'绑定成功');
    }

    public function consume(){

        $validate = $this->validate($this->data, 'app\web\validate\WechatValidate.cash_consume');

        if($validate !== true){
            return json_error(NULL,$validate,self::INVALID_PARAMETER);
        }
        $this->apps = new Application($this->option2);
        $card = $this->apps->card;


        $code_get = $card->getCode($this->data['code'], false, '');
        if($code_get->can_consume === false){
            return json_error(NULL,'该卡卷已经失效',self::INVALID_PARAMETER);
        }

        $card_info = $card->getCard($code_get->card['card_id']);

        $least_cost = $card_info->card['cash']['least_cost']/100;
        $reduce_cost = $card_info->card['cash']['reduce_cost']/100;

        if($this->data['order_amount'] < $least_cost){
            return json_error(NULL,sprintf("消费金额小于%s元",$least_cost),self::INVALID_PARAMETER);
        }
        if($this->data['order_amount'] < $reduce_cost){
            return json_error(NULL,sprintf("消费券金额必须大于等于抵扣卷金额%s元",$reduce_cost),static::INVALID_PARAMETER);
        }

//        if($this->data['order_amount'] > $least_cost && $this->data['order_amount'] < $reduce_cost){
//            $reduce_cost = $this->data['order_amount'];
//        }

        $card->consume($this->data['code']);

        return $this->respondWithArray(['reduce_cost'=>$reduce_cost],'使用成功');
    }


    public function server(){
        $this->apps = new Application($this->option3);
        $response = $this->apps->server->serve();
        return $response->send();
    }

    public function js_config(){
        $origin = isset($_SERVER['HTTP_ORIGIN'])? $_SERVER['HTTP_ORIGIN'] : '';

        $allow_origin = array(
            'http://qiniu.huacaijia.com',
            'https://qiniu.huacaijia.com'
        );
        if(in_array($origin, $allow_origin)){
            header('Access-Control-Allow-Origin:'.$origin);
        }
        $this->_validate('url');
        $apis = isset($this->data['apis']) && is_array($this->data['apis'])? $this->data['apis']:['chooseWXPay'];
        $this->apps = new Application($this->options);
        $this->apps->js->setUrl($this->data['url']);
//        $config = $this->apps->js->config(['addCard', 'chooseCard', 'openCard'],false);
        $config = $this->apps->js->config($apis,false,false,false);
        return $this->respondWithArray($config);
    }

}
