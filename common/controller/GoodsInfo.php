<?php

namespace app\common\controller;

use app\common\model\Goods;
use think\db;

class GoodsInfo extends Goods{
    private $redis_goods_sup_info = 'Goods_id:%d';

    public function __construct($data = [])
    {
        parent::__construct($data);
    }

    public function getGoodsRedis($gsup_id,$attr_id=false){

        /*通过指定的商品id,从redis取数据*/
        $Goods_sup_info = sprintf($this->redis_goods_sup_info,$gsup_id);

        if(!redis(3)->exists($Goods_sup_info))
        {
            $goodsInfo = $this->get_gsup_list($gsup_id);
            if(!empty($goodsInfo)){
                $goodsInfo = arr2obj($goodsInfo->toArray());

                if(isset($goodsInfo->gsup_id)){
                    redis(3)->set($Goods_sup_info,serialize($goodsInfo));
                }else{
                    $goodsInfo = [];
                }
            }else{
                $goodsInfo = [];
            }
        }
        else
        {
            $goodsInfo = unserialize(redis(3)->get($Goods_sup_info));
            $goodsInfo = arr2obj($goodsInfo);
        }
        if(!empty($goodsInfo)){
            $goodsInfo = $this->goodsAdd($goodsInfo,$attr_id);
        }
        return $goodsInfo;
    }

    private function goodsAdd($goodsInfo,$attr_id){
        if(!empty(get_object_vars($goodsInfo->product_arr))){   //多规格商品
            $goodsInfo->isSpecifications = true;
            if($attr_id){

                $product_item = [];
                foreach ($goodsInfo->product_arr as $val) {
                    $temp = explode('|', $val->goods_attr_sou);
                    if (!array_diff($temp, $attr_id)) {
                        $product_item = $val;
                        break;
                    }
                }
                if(!empty($product_item)){
                    $goodsInfo->product_info = $product_item;
                }else{
                    $goodsInfo->product_info = [];
                }
            }
        }else{
            $goodsInfo->isSpecifications = false;
            $goodsInfo->product_info = [];
        }
        if(isset($goodsInfo->product_info->product_id))
            $goodsInfo->stock = $this->get_goods_stock($goodsInfo->gsup_id,$goodsInfo->product_info->product_id);
        else
            $goodsInfo->stock = $this->get_goods_stock($goodsInfo->gsup_id);
        return $goodsInfo;
    }

    public function get_goods_list($gsup_id,$attr_id=false)
    {
        if(empty($gsup_id)){
            return [];
        }
        if(is_array($gsup_id)){//首页模块用到批量
            $arr_ids = [];
            foreach($gsup_id as $id){
                $Goods_sup_info = sprintf($this->redis_goods_sup_info,$id);
                $arr_ids[] = $Goods_sup_info;
            }
            $res = redis(3)->mget($arr_ids);
            foreach($res as $key=>&$item){
                if(!$item){
                    $item = $this->getGoodsRedis($gsup_id[$key]);
                }else{
                    $item = arr2obj(unserialize($item));
                    $item = $this->goodsAdd($item,false);//批量商品取某个属性入参默认先为false
                }
            }
            return $res;
        }else{
            /*通过指定的商品id,从redis取数据*/
            return $res = $this->getGoodsRedis($gsup_id,$attr_id);
        }
    }
    public function get_goods_stock($gsup_id,$product_id=0)
    {
        $Goods_stock_list = sprintf('Goods_stock:%d',$gsup_id);
        $Goods_alone_stock_list = sprintf('Goods_alone_stock:%d',$gsup_id);
        $products = $this->name('products')->where([['goods_id','=',$gsup_id],['is_show','=',1]])->select()->toArray();
        $res = redis(3)->hgetall($Goods_stock_list);
        $alone_res =redis(3)->hgetall($Goods_alone_stock_list);
        if(empty($res)){
            $res = $this->goods_stock_sum($gsup_id);
        }
        if(empty($alone_res)){
            $alone_res = $this->goods_alone_stock_sum($gsup_id);
        }
        $stock=0;
        if(empty($products)){
            if(isset($alone_res[$gsup_id])){
                $stock=$res[$gsup_id]>$alone_res[$gsup_id]?$alone_res[$gsup_id]:$res[$gsup_id];
            }else{
                $stock=$res[$gsup_id];
            }
        }else{
            if($product_id){
                $key = $gsup_id."-".$product_id;
                if(!isset($res[$key])&&!isset($alone_res[$key])){
                    $stock = 0;
                }else{
                    if(!isset($alone_res[$key])||$alone_res[$key]>$res[$key]){
                        $stock = $res[$key];
                    }else{
                        $stock = $alone_res[$key];
                    }
                }
            }else{
                unset($res[$gsup_id]);
                unset($alone_res[$gsup_id]);
                foreach ($products as $key=>$item){
                    $key = $gsup_id."-".$item['product_id'];
                    if(isset($alone_res[$key])){
                        $stock+=$res[$key]>$alone_res[$key]?$alone_res[$key]:$res[$key];
                    }else{
                        $stock+=$res[$key];
                    }
                }

            }
        }


        return intval($stock);
    }

    public function get_goods_sync_stock($gsup_id,$product_id=0)
{
    $Goods_stock_list = sprintf('Goods_stock:%d',$gsup_id);
    $products = $this->name('products')->where([['goods_id','=',$gsup_id],['is_show','=',1]])->select()->toArray();
    $res = redis(3)->hgetall($Goods_stock_list);
    if(empty($res)){
        $res = $this->goods_stock_sum($gsup_id);
    }
    $stock = 0;
    if($product_id){
        $key = $gsup_id."-".$product_id;
        if(!isset($res[$key])){
            $stock = 0;
        }else{
            $stock = $res[$key];
        }
    }else{
        if(empty($products)){
            $stock=$res[$gsup_id];
        }else {
            foreach ($products as $key => $item) {
                $key = $gsup_id . "-" . $item['product_id'];
                if(isset($res[$key])){
                    $stock+=$res[$key];
                }
            }
        }
    }
    return intval($stock);
}

    public function get_goods_alone_stock($gsup_id,$product_id=0)
    {

        $Goods_alone_stock_list = sprintf('Goods_alone_stock:%d',$gsup_id);
        $products = $this->name('products')->where([['goods_id','=',$gsup_id],['is_show','=',1]])->select()->toArray();
        $res = redis(3)->hgetall($Goods_alone_stock_list);
        if(empty($res)){
            $res = $this->goods_alone_stock_sum($gsup_id);
        }
        if(empty($res)){
            return null;
        }
        $stock = null;
        if($product_id){
            $key = $gsup_id."-".$product_id;
            if(!isset($res[$key])){
                $stock = null;
            }else{
                $stock = $res[$key];
               }
        }else{
            if(empty($products)){
                $stock=$res[$gsup_id];
            }else{
                foreach ($products as $key=>$item){
                    $key = $gsup_id."-".$item['product_id'];
                    if(isset($res[$key])){
                        $stock+=$res[$key];
                    }
                }
            }
        }
        $stock=!isset($stock)?null:intval($stock);
        return $stock;
    }

    public function get_goods_activities($gsup_id=0,$flag,$is_pro_fav_show=false){
        if(empty($gsup_id)){
            return [];
        }
        /*通过指定的商品id,从redis取数据*/
        $Goods_activities_index = sprintf('Goods_activities_index:%d',$gsup_id);

        if(!redis(3)->exists($Goods_activities_index))
        {
            $data = $this->get_sup_activities($gsup_id);
            if($data){
                redis(3)->set($Goods_activities_index,json_encode($data),['EX'=>3600]);
                if(isset($data[$flag])){
                    $temp = $data[$flag];
                }else{
                    $temp = [];
                }
            }else{
                $temp = [];
            }
        } else {
            $data = json_decode(redis(3)->get($Goods_activities_index),true);
            if(isset($data[$flag])) {
                $temp = $data[$flag];
            }else{
                $temp = [];
            }
        }
        //定金商品直接返回
        if($flag == 'dj'|| $flag == 'areaSale'){
            return $temp;
        }

        $arr = [];
        if($flag=='present' || $flag=='fav' || $flag=='freeshipping' || $flag=='water_goods'){
            $time = date('Y-m-d H:i:s');
        }else{
            $time = time();
        }
        if($temp){
            foreach($temp as $key => $item){
                if(($item['beg_time'] <= $time && $item['end_time'] >= $time)||($is_pro_fav_show&&$item['end_time'] >= $time)){
                    $arr[] = $item;
                }else{
                    $temp[$key] = [];
                    unset($temp[$key]);
                }
            }
        }
        return $arr;
    }

    public function change_goods_stock($gsup_id,$product_id,$number){
        $Goods_stock_list = sprintf('Goods_stock:%d',$gsup_id);
        $Goods_alone_stock_list = sprintf('Goods_alone_stock:%d',$gsup_id);
            $res = redis(3)->hgetall($Goods_stock_list);
            $alone_res =redis(3)->hgetall($Goods_alone_stock_list);
            if(empty($res)){
                $res = $this->goods_stock_sum($gsup_id);
            }
            if(empty($alone_res)){
                $alone_res = $this->goods_alone_stock_sum($gsup_id);
            }
            if($product_id){
                $key = $gsup_id.'-'.$product_id;
                if(!isset($res[$gsup_id])||!isset($res[$key])){  //找不到对应规格库存记录,直接返回
                    return false;
                }
                $all_stock =redis(3)->hIncrBy($Goods_stock_list,$gsup_id,intval($number));
                $stock=redis(3)->hIncrBy($Goods_stock_list,$key,intval($number));
                if($stock<0||$all_stock<0){
                  redis(3)->hIncrBy($Goods_stock_list,$key,abs(intval($number)));
                  redis(3)->hIncrBy($Goods_stock_list,$gsup_id,abs(intval($number)));
                  return false;
                }elseif(isset($alone_res[$key])){
                  $alone_all_stock =redis(3)->hIncrBy($Goods_alone_stock_list,$gsup_id,intval($number));
                  $alone_stock=redis(3)->hIncrBy($Goods_alone_stock_list,$key,intval($number));
                  if($alone_stock<0||$alone_all_stock<0) {
                    redis(3)->hIncrBy($Goods_alone_stock_list, $key, abs(intval($number)));
                    redis(3)->hIncrBy($Goods_alone_stock_list,$gsup_id,abs(intval($number)));
                    redis(3)->hIncrBy($Goods_stock_list,$key,abs(intval($number)));
                    redis(3)->hIncrBy($Goods_stock_list,$gsup_id,abs(intval($number)));
                    return false;
                  }
                }
            }else{
                if(!isset($res[$gsup_id])){   //找不到对商品库存记录,直接返回
                    return false;
                }
                $stock =redis(3)->hIncrBy($Goods_stock_list,$gsup_id,intval($number));
                if($stock < 0){
                  redis(3)->hIncrBy($Goods_stock_list,$gsup_id,abs(intval($number)));
                  return false;
                }elseif(isset($alone_res[$gsup_id])){
                  $alone_stock=redis(3)->hIncrBy($Goods_alone_stock_list,$gsup_id,intval($number));
                  if($alone_stock < 0){
                    redis(3)->hIncrBy($Goods_alone_stock_list,$gsup_id,abs(intval($number)));
                    redis(3)->hIncrBy($Goods_stock_list,$gsup_id,abs(intval($number)));
                    return false;
                  }
                }

            }

        return true;
    }
}
