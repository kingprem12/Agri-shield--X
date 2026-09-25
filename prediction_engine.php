<?php
require_once __DIR__.'/database.php';
function prediction_date($v){if(!is_string($v))return false;$d=DateTime::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v&&$v<=date('Y-m-d');}
function forecast_trend($values,$day){$n=count($values);if(!$n)return 0;if($n===1)return $values[0];$sx=$sy=$sxy=$sx2=0;foreach($values as $i=>$y){$x=$i+1;$sx+=$x;$sy+=$y;$sxy+=$x*$y;$sx2+=$x*$x;}$den=$n*$sx2-$sx*$sx;$m=$den?($n*$sxy-$sx*$sy)/$den:0;return ($sy-$m*$sx)/$n+$m*($n+$day);}
// Experimental screening index, not drought probability or a trained classifier.
function drought_risk($temp,$hum,$soil,$rain){$clip=function($v){return max(0,min(100,(float)$v));};$score=round(.55*(100-$clip($soil))+.20*(100-$clip($rain))+.15*(100-$clip($hum))+.10*$clip(($temp-20)*5),1);$level=$score>=66?'High':($score>=33?'Medium':'Low');return array('score'=>$score,'level'=>$level,'class'=>strtolower($level));}
function lgb_tree_value($node,$features,$depth=0){
 if($depth>64||!is_array($node))throw new Exception('Invalid model tree.');
 if(isset($node['leaf_value'])&&is_numeric($node['leaf_value']))return (float)$node['leaf_value'];
 if(!isset($node['split_feature'],$node['threshold'],$node['left_child'],$node['right_child'])||request_value($node,'decision_type','')!=='<=')throw new Exception('Unsupported model split.');
 $index=$node['split_feature'];if(!isset($features[$index])||!is_numeric($node['threshold']))throw new Exception('Invalid model features.');
 return lgb_tree_value($features[$index]<=$node['threshold']?$node['left_child']:$node['right_child'],$features,$depth+1);
}
function lgb_predict($model,$features){if(empty($model['tree_info'])||!empty($model['average_output']))throw new Exception('Unsupported model format.');$sum=0;foreach($model['tree_info'] as $t)$sum+=lgb_tree_value($t['tree_structure'],$features);if(!is_finite($sum))throw new Exception('Invalid output.');return $sum;}
function prediction_bundle($area,$until,$days,$requested){
 $history=daily_trends($area,$until,30);$count=count($history);$training=read_json_file(__DIR__.'/data/area_'.$area.'/pso_lightgbm.json',array());
 $available=false;$reason='Awaiting training — at least 60 consecutive days of sensor history are required.';
 if($training){$available=request_value($training,'schema',0)===1&&request_value($training,'algorithm','')==='PSO-LightGBM'&&request_value($training,'area',0)===$area&&request_value($training,'trained_until','')===$until&&request_value($training,'feature_count',0)===28;$reason=$available?'Trained model available for the selected date.':'No compatible trained model for this area and cutoff date.';
 if($available){$window=array_slice($history,-7);if(count($window)!==7)$available=false;foreach($window as $i=>$d)if($d['day']!==date('Y-m-d',strtotime($until.' -'.(6-$i).' day')))$available=false;if(!$available)$reason='Seven consecutive sensor days are needed at the selected cutoff.';}}
 $mode='Linear trend';$rows=array();
 if($requested==='pso'&&$available){try{
 $window=array_map(function($d){return array($d['temperature'],$d['humidity'],sensor_percent($d['soil']),sensor_percent($d['rain']));},array_slice($history,-7));
 for($i=1;$i<=$days;$i++){$features=array();foreach($window as $d)foreach($d as $v)$features[]=$v;$v=array();foreach(array('temp','hum','soil','rain') as $key)$v[]=lgb_predict(isset($training['models'][$key])?$training['models'][$key]:array(),$features);$v=array(max(-10,min(60,$v[0])),max(0,min(100,$v[1])),max(0,min(100,$v[2])),max(0,min(100,$v[3])));$rows[]=array('date'=>date('Y-m-d',strtotime($until." +$i day")),'temp'=>$v[0],'hum'=>$v[1],'soil'=>$v[2]*4095/100,'rain'=>$v[3]*4095/100);array_shift($window);$window[]=$v;}$mode='PSO-LightGBM';
 }catch(Exception $e){$rows=array();$available=false;$reason='Model unavailable. Showing the labelled baseline instead.';}}
 if(!$rows){$data=$history;if(count($data)<2){if(!$data){if($until!==date('Y-m-d'))throw new Exception('No history exists for this cutoff. Select a date with recorded data.');$c=latest_reading($area);if(!$c)throw new Exception('No sensor values are available.');$data=array(array('temperature'=>$c['temperature'],'humidity'=>$c['humidity'],'soil'=>$c['soil_value'],'rain'=>$c['rain_value']));$reason.=' Latest available values are a baseline only and may not be live.';}$mode='Constant baseline';}
 for($i=1;$i<=$days;$i++)$rows[]=array('date'=>date('Y-m-d',strtotime($until." +$i day")),'temp'=>max(-10,min(60,forecast_trend(array_column($data,'temperature'),$i))),'hum'=>max(0,min(100,forecast_trend(array_column($data,'humidity'),$i))),'soil'=>max(0,min(4095,forecast_trend(array_column($data,'soil'),$i))),'rain'=>max(0,min(4095,forecast_trend(array_column($data,'rain'),$i))));}
 foreach($rows as &$r)$r['risk']=drought_risk($r['temp'],$r['hum'],sensor_percent($r['soil']),sensor_percent($r['rain']));unset($r);
 return array('rows'=>$rows,'mode'=>$mode,'available'=>$available,'reason'=>$reason,'history_count'=>$count);
}
