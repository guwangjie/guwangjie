<?php
namespace app\common\model;

use Firebase\JWT\JWT;
use think\Db;
use think\Exception;
use think\Model;
use code;
use Sms;
use app\common\model\Bonus;
use app\common\model\Point;
use app\dashboard\model\Message_system;
class Users extends Load
{
    public function findUser($mobile){


        $sql = "SELECT user_id,user_name,password,salt,mobile,visit_count,nick,avatar,is_delete FROM";
        $sql .="(SELECT user_id,user_name,mobile,password, ec_salt AS salt,visit_count,nick,avatar,is_delete FROM `ecs_users` ";
        $sql .=" WHERE `mobile` = '$mobile'";
        $sql .=" union ";
        $sql .=" SELECT `user_id`, `user_name`, `mobile`,`password`, ec_salt AS salt, `visit_count`,`nick`,`avatar`,`is_delete` FROM `ecs_users` ";
        $sql .=" WHERE `user_name` = '$mobile' ) as t1 ";
        $sql .=" order by mobile desc limit 1";

        $res = $this->query($sql);
        if($res){
            return $res[0];
        }else{
            return false;
        }
    }
    /**
     * 注册用户 并在k3中注册会员卡  {发送新人红包}
     * @param  number   $mobile  [手机号码]
     * @param  string $wxunionid  [微信unionid]
     * @param  string $password  密码注册时取密码
     * @param  array  $oauth_data 第三方登陆的data 方便
     * @return number    用户插入表中的自增id
     */
    public function register($mobile,$wxunionid='',$password='',$oauth_data =[],$account='',$idfa=''){
        $salt = rand(1000,9999);

        $bonus = new Bonus();

        $this->startTrans();
        try{
            if($password){
                $password = md5(md5($password).$salt);
            }else{
                $password = md5(md5(substr($mobile,-6)).$salt);
            }
            $u_grade=$this->name('user_grade')->where('mobile',$mobile)->field('u_grade')->find();
            if(!empty($u_grade['u_grade'])){
                $u_grade=$u_grade['u_grade'];
            }else{
                $u_grade=1;
            }
            $isCancel = Db::name('user_cancel')->where('mobile',$mobile)->count();
            $insert = [
                'email'=>date('YmdHis',time())."_".rand(100000,999999)."@huacai.com.cn", //没有什么卵用的字段
                'user_name'=>$mobile,
                'password' =>$password,
                'ec_salt'=>$salt,
                'reg_time'=>time(),
                'last_login'=>time(),
                'last_time'=>date('Y-m-d H:i:s'),
                'last_ip'=>request()->ip(),
                'visit_count'=>1,
                'mobile'=>$mobile,
                'qqopenid' =>'',
                'alipayopenid'=>'',
                'sinaopenid'=>'',
            ];
            if ($isCancel > 0){ //注销后重新注册不为新用户
                $insert['u_new'] = 0;
            }
            if($account!='mengdian') $insert['u_grade']=0;//门店注册初始为白银

            if($wxunionid){
                $insert['wxunionid'] = $wxunionid;
            }

            if(count($oauth_data)>0){
                $insert = array_merge($insert,$oauth_data);
            }

            $user_id = $this->name('users')->insertGetId($insert);

//            $card = (new \K3api())->get_mendian_cards($mobile,$mobile);
//            $insert_vipcard=[
//                'user_id'=>$user_id,
//                'vipcard_num'=>$card['FCardNumber'],
//                'vipfid'=>$card['FID'],
//                'vipname'=>$card['FName'],
//                'enable'=>1,
//                'addtime'=>time(),
//                'ftypename'=>$card['ftype'],
//                'fDisCount'=>$card['FRebateRateY']/100
//            ];
//            /*-将k3中获取的会员卡信息插入vipcard中-*/
//            $this->name('vipcard')->insert($insert_vipcard);
            if ($isCancel == 0){ //未注销过
                $bonus->register_bonus($user_id);
            }
            if($wxunionid){
                Sms::send_sms($mobile,Sms::np_register);
            }else{
                Sms::send_sms($mobile,Sms::register);
            }

            $this->commit();
        }catch (\Exception $e){
            $this->rollback();
            abort(500,$e->getMessage());
        }

        return $user_id;
    }
    public function credit_register($mobile,$wxunionid='',$password='',$oauth_data =[],$account='',$idfa='',$nick=''){
        $salt = rand(1000,9999);
        $this->startTrans();
        try{
            if($password){
                $password = md5(md5($password).$salt);
            }else{
                $password = md5(md5(substr($mobile,-6)).$salt);
            }
            $u_grade=$this->name('user_grade')->where('mobile',$mobile)->field('u_grade')->find();
            if(!empty($u_grade['u_grade'])){
                $u_grade=$u_grade['u_grade'];
            }else{
                $u_grade=1;
            }
            $insert = [
                'email'=>date('YmdHis',time())."_".rand(100000,999999)."@huacai.com.cn", //没有什么卵用的字段
                'user_name'=>$mobile,
                'password' =>$password,
                'nick' =>$nick,
                'ec_salt'=>$salt,
                'reg_time'=>time(),
                'last_login'=>time(),
                'last_time'=>date('Y-m-d H:i:s'),
                'last_ip'=>request()->ip(),
                'visit_count'=>1,
                'mobile'=>$mobile,
                'qqopenid' =>'',
                'alipayopenid'=>'',
                'sinaopenid'=>'',
                'account_from'=>$account,
                'idfa'=>$idfa,
                'u_grade'=>$u_grade,
            ];
            $user_id = $this->name('users')->insertGetId($insert);

            $this->commit();
        }catch (\Exception $e){
            $this->rollback();
            abort(500,$e->getMessage());
        }
        return $user_id;
    }
    /**
     * openid操作
     * @access  public
     * @param int   $user_id
     * @param string $openid
     * @param string $platform ('app','mini_program','official_accounts')
     * @param bool   $operate 1:新增|2:删除
     * @return bool
     */
    public function openid_operate(int $user_id,string $openid,string $platform,bool $operate)
    {
        if($operate){
            $rs = $this->name('openid')->where(['user_id'=>$user_id,'platform'=>$platform,'openid'=>$openid])->find();
            if(empty($rs))
                return $this->name('openid')->insert(['user_id'=>$user_id,'platform'=>$platform,'openid'=>$openid]);
        }else{
            /*-解绑操作-*/
            return $this->name('openid')->where('openid',$openid)->delete();
        }

    }

    public function user_info($user_id='',$fields='user_id,user_name,nick,avatar,sex,birthday,mobile,0 as user_money,pay_points,rank_points,frozen_money,user_rank',$value=''){
        $fields=$fields.',u_grade,grade_time as new_grade_time,DATE_ADD(FROM_UNIXTIME(grade_time,\'%Y-%m-%d\'), INTERVAL 1 YEAR) as grade_time,FROM_UNIXTIME(grade_time,\'%Y-%m-%d\') as staff_time,is_finish';
        if(empty($user_id)){
            $user_id = $GLOBALS['user_id'];
//            $user_id = 276724;
        }

        $where = $this->name('users')->where('user_id',$user_id);
        if($value){
            return $where->value($value);
        }else{
            $res = $where->field($fields)->find();

            /*-第一次登陆的user_rank查表获取-*/
            $res['discount']  = (new Point())->get_discount($res['u_grade']);
            return $res;
        }

    }

    public function order_count(){

        $where = [
            ['user_id','=',$GLOBALS['user_id']],
            ['is_delete','=',0],
//            'o_status'=>['neq','7'],
        ];

        $arr = $this->name('order_info')->where($where)->select();
        $res['dfk'] = 0;
        $res['dfh'] = 0;
        $res['bffh'] = 0;
        $res['dsh'] = 0;
        $res['tk'] = $this->name('refund_order')->where([['user_id','=',$GLOBALS['user_id']],['status','=',0]])->count();
        $res['dj'] = 0;
        $res['group'] = 0;
        $res['dpj'] = 0;
        foreach ($arr as $item){
            if($item['o_status'] == 0 && $item['is_delete'] == 0 && $item['is_dingjin'] == 0 && in_array($item['is_group'],[0,98,97,3,5])){
                $res['dfk']++;
                continue;
            }
            if($item['o_status'] == 1 || $item['o_status'] == 2){
                $res['dfh']++;
                continue;
            }
            if($item['o_status'] == 3){
                $res['bffh']++;
                continue;
            }
            if($item['o_status'] == 4){
                $res['dsh']++;
                continue;
            }
            if($item['o_status'] == 7 && $item['is_comment'] == 0 && $item['finish_time']>strtotime("-0 year -1 month -0 day")){
                $res['dpj']++;
                continue;
            }
            if($item['o_status'] == code::NS_DINGJIN_P && $item['is_delete'] == 0){
                $res['dj']++;
                continue;
            }
            if($item['o_status'] == code::GROUP){
                $res['group']++;
                continue;
            }
        }
        return $res;
    }


    /**
     * 显示优惠券
     * @param  number   $type  [0:未使用|1:已过期|2:已使用]
     *
     * @return number    用户插入表中的自增id
     */
    public function get_user_bouns_list($type,$page,$limit,$is_page=false,$use_allowed=null){

        //适用范围(0:门店网店皆可用; 1:仅网店可用; 2:仅门店可用)
        $where = '';
        if(strlen($use_allowed)>0){
            if($use_allowed == 0){
                $where .= "b.use_allowed = 0 and ";
            }else if($use_allowed == 1){
                $where .= "b.use_allowed = 1 and ";
            }else if($use_allowed == 2){
                $where .= "b.use_allowed = 2 and ";
            }
        }

        if($type==1) //已过期 //已使用
        {
            $where .= "(ub.order_id>0 or (ub.order_id = 0 and ub.use_end_date<".time().") or ub.present = 2)";
        }
        if($type==0) //未使用(未过期的 未赠送的)
        {
            $where .= 'ub.order_id = 0 and ub.use_end_date >='.time()." and ub.present in(0,1)";

        }

//        $GLOBALS['user_id'] = 274568;
        $fields = 'ub.bonus_sn,ub.bonus_id, ub.order_id, b.type_name, b.type_money,b.is_heiwu,b.is_shipping, b.min_goods_amount,b.use_date_type,b.act_range,b.act_range_ext,b.act_range_ext_name, ';
        $fields .= "FROM_UNIXTIME( b.use_start_date,'%Y/%m/%d %H:%i:%s') as use_start_date,FROM_UNIXTIME( ub.use_end_date,'%Y/%m/%d %H:%i:%s') as use_end_date,b.is_present,ub.present,b.use_allowed,b.information";
//        $fields .= "b.use_start_date,ub.use_end_date";
        $result =   $this->name('user_bonus')->field($fields)->alias('ub')
                ->join('ecs_bonus_type b','ub.bonus_type_id = b.type_id and ub.user_id ='.$GLOBALS['user_id'],'inner')
                ->where($where)->page("$page,$limit")->order('use_end_date desc')->select()->toArray();
        if(count($result)){
            foreach ($result as &$item){
                $item['gshp_id']=0;
                if($item['act_range']==3){
                    $arr=explode(',',$item['act_range_ext']);
                    if(count($arr)==1){
                        $item['gshp_id']=$this->name('goods_shp')->where('goods_id',$item['act_range_ext'])->value('id');
                    }
                }

                $item['bonus_id_encode'] = "https://api.huacaijia.com/zhuanzeng/index.html?bonus_id_encode=".base64_encode($item['bonus_id']);
                $item['act_range_ext_name'] = ""; //朱老板需求 2018.8.15暂时不显示
                $item['encode'] = "HC".".".$item['bonus_id'].".".substr(md5($GLOBALS['user_id'].$item['bonus_id']),0,12);
            }
        }
        if($is_page){
            return $this->name('user_bonus')->field($fields)->alias('ub')
                ->join('ecs_bonus_type b','ub.bonus_type_id = b.type_id and ub.user_id ='.$GLOBALS['user_id'],'inner')
                ->where($where)->count();
        }
        return $result;
    }

    public function update_user_info($user_id){

        $row =$this->name('users')->where('user_id',$user_id)->find();
        $hmset['last_time'] = $row['last_login'];
        $hmset['last_ip'] = $row['last_ip'];
        $hmset['login_fail'] = 0;
        if($this->name('order_info')->where([['user_id', '=', $user_id],['is_delete', '=', 0]])->sum('pay_time')>0){
            $hmset['u_new'] = 0;
        }else{
            $hmset['u_new'] = 1;
        }
        if($this->name('user_black_card')->where([['user_id', '=', $user_id]])->find()){
            $hmset['is_black'] = 1;
        }else{
            $hmset['is_black'] = 0;
        }
        if(Db::name('gcard')->where([['user_id', '=', $GLOBALS['user_id']],['is_black','=','3'],['able','=','1'],['valid_time','>',time()]])->find()&&date('Y-m-d')>='2021-01-01'){
            $hmset['is_mini'] = 1;
        }else{
            $hmset['is_mini'] = 0;
        }
//        $card_lists  = $row['mobile'] ? (new \K3api())->vipCard($row['mobile']) : null;

//        $arr = [];
        $update = [];
//        if(is_array($card_lists)){
//            foreach ($card_lists as $item){
//                if(intval($item['FRebateRate']) >  50){
//                    array_push($arr,$item['FRebateRate']);
//                }
//            }
//
//            $rank_id = count($arr)>=1 ? $this->name('user_rank')->where('discount',min($arr))->value('rank_id'):23;
//
//        }

        $hmset['user_rank'] = isset($rank_id)? $rank_id : 23;
        $hmset['discount']  = (new Point())->get_discount($row['u_grade']);
        $hmset['u_grade']  = $row['u_grade'];
        if($hmset['user_rank'] != $row['user_rank']){
            $update['user_rank'] = $hmset['user_rank'];
        }

        if(count($update)>0){
            $this->name('users')->where('user_id',$user_id)->update($update);
        }

        /*删除购物车中已下架售完删除的商品*/
        $join4 = [
            ['ecs_goods_shp shp', 'c.goods_id = shp.id','LEFT'],
            ['ecs_goods_sup sup', 'shp.goods_id = sup.id','LEFT'],
        ];
        $where4 = "(shp.is_delete = 1 or shp.is_sale = 1 or shp.status = 1 or sup.is_delete = 1 or sup.is_sale = 1) AND c.user_id =$user_id";
        $columns_array = $this->name('cart')->alias('c')->join($join4)->where($where4)->column('c.goods_id');

        if(!empty($columns_array)) {
            $columns = implode(',', $columns_array);
            $this->name('cart')->where([['goods_id','in', $columns], ['user_id','=',$user_id]])->delete();
        }
        return $hmset;

    }

    /**
     * 记录帐户变动
     * @param   int   $user_money      可用余额变动
     * @param   int   $frozen_money     冻结余额变动
     * @param   int     $rank_points    等级积分变动
     * @param   int     $pay_points     消费积分变动
     * @param   string  $change_desc    变动说明
     * @param   int     $change_type    变动类型：参见常量文件
     * @param   bool     $is_pay         是否扣取会员卡金额
     * @param   int     $user_id        用户id 通知的时候没用用户态需要传递一下
     * @return  void
     */
    public function log_account_change($user_money = 0, $frozen_money = 0,$rank_points = 0, $pay_points = 0, $change_desc = '', $change_type =code::ACT_OTHER,$is_pay = true,$user_id='',$trade_sn=0,$card_type=0,$order_sn=0,$is_group=0)
    {
        $CardBalance=new CardBalance();
        $Message_system=new Message_system();
        $user_id =  empty($user_id) ?  $GLOBALS['user_id'] : $user_id;
        /* 插入帐户变动记录 */
        $account_log = array(
            'user_id'       => $user_id,
            'user_money'    => $user_money,
            'frozen_money'  => $frozen_money,
            'rank_points'   => $rank_points,
            'pay_points'    => $pay_points,
            'change_time'   => time(),
            'change_desc'   => $change_desc,
            'change_type'   => $change_type
        );

        $this->name('account_log')->insert($account_log);

        if($is_pay&&$user_money){

            $refund=$this->name('card_balance')->where('third_no', $trade_sn)->where('user_id', $user_id)->where('third_type', CardBalance::online_order_pay)->find();
            try{//黑五卡报错拦截
                $card_money = (new CardBalance())->balance_money($refund['user_id'],$refund['card_type']);
            }catch  (\Exception $exception) {
                $card_money = (new CardBalance())->balance_money($refund['user_id'],1);
            }
            if($is_group==1){
                $message='您好！您参与的拼团由于未成团，参团金额：'.$user_money.'元，已原路退回，当前可用余额：'.(string)($card_money+$user_money).'元，请注意查收，订单号：'.$order_sn;
                $type=21;
            }elseif ($is_group==4){
                 $message=' 您好！您参与的阶梯团购已结束，优惠返还金额：'.$user_money.'元，已返还，当前可用余额：'.(string)($card_money+$user_money).'元，请注意查收，订单号：'.$order_sn;
                $type=22;
            }elseif($is_group == 2){
                $message= '您参与的拼团因人数不足失败，资金将在两三个工作日原路返回，注意查收。';
                $type = 2;
            }
            else{
                $message='您好！您申请的退款：'.$user_money.'元，已退回，当前可用余额：'.(string)($card_money+$user_money).'元，请注意查收，订单号：'.$order_sn;
                $type=20;
            }
            $mg_id=$Message_system->insert_message_system_balance($type, $user_id, $message);
            $CardBalance->refund_balance($user_id,$user_money,CardBalance::online_order_pay,$trade_sn,$trade_sn.'退款',$mg_id);
            $Message_system->insert_message_system_balance(20, $user_id, $message,1);
        }
        if(!$is_pay&&$user_money&&$card_type&&time()>'1582702800'){
            if($is_group==1){
                $message='您好！您参与的拼团由于未成团，参团金额：'.$user_money.'元，已原路退回，请注意查收，订单号：'.$order_sn;
                $type=21;
            }elseif ($is_group==4){
                $message=' 您好！您参与的阶梯团购已结束，优惠返还金额：'.$user_money.'元，已原路退回，请注意查收，订单号：'.$order_sn;
                $type=22;
            }elseif($is_group == 2){
                $message= '您参与的拼团因人数不足失败，资金将在两三个工作日原路返回，注意查收。';
                $type = 2;
            }else{
                $message='您好！您申请的退款：'.$user_money.'元，已原路退回，请注意查收，订单号：'.$order_sn;
                $type=20;
            }
            $mg_id=$Message_system->insert_message_system_balance($type, $user_id, $message);
            $CardBalance->add_card_balance_all($user_id,$user_money,$card_type,$trade_sn,$trade_sn.'退款','用户',date('Y-m-d H;i;s'),3,0,1,1,$mg_id);
            $Message_system->insert_message_system_balance($type, $user_id, $message,1);
        }

    }

    public function getMobileByUid($uid){
        return $this->name('users')->where('user_id',$uid)->value('mobile');
    }

    public function update_info($user_info){
        if (isset($this->data['avatar'])) {
            DB::name('users')->where('user_id', $GLOBALS['user_id'])->update(['avatar' => $cb['avatar']]);
            if (empty($user_info['avatar'])) {
                $description = '完善头像';
                (new Point())->add_free_point($GLOBALS['user_id'], 5, $description, '买家',10);
            }
        }
        if (isset($this->data['birthday'])) {
            DB::name('users')->where('user_id', $GLOBALS['user_id'])->update(['birthday' => $this->data['birthday']]);
            $description = '完善生日';
            if (empty($user_info['birthday']) || $user_info['birthday'] == '0000-00-00') {
                (new Point())->add_free_point($GLOBALS['user_id'], 5, $description, '买家',20);
            }
        }
        if (isset($this->data['nick'])) {
            DB::name('users')->where('user_id', $GLOBALS['user_id'])->update(['nick' => $this->data['nick']]);
        }
        if (isset($this->data['sex'])) {
            DB::name('users')->where('user_id', $GLOBALS['user_id'])->update(['sex' => $this->data['sex']]);
        }
    }
}
