<?php
// Run only from the CLI. Tests never write production sensor records.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../prediction_engine.php';
function check($value,$label){if(!$value)throw new Exception('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
check(drought_risk(20,100,100,100)['level']==='Low','wet inputs low');
check(drought_risk(30,50,50,50)['level']==='Medium','middle inputs medium');
check(drought_risk(40,0,0,0)['level']==='High','dry inputs high');
check(drought_risk(1000,-20,-5,200)['score']<=100,'risk bounded');
check(!prediction_date('2026-02-30')&&!prediction_date('../bad')&&!prediction_date(array()),'invalid dates rejected');
check(abs(forecast_trend(array(28,29,30),1)-31)<.001,'trend arithmetic');
$tree=array('split_feature'=>0,'threshold'=>5,'decision_type'=>'<=','left_child'=>array('leaf_value'=>3),'right_child'=>array('leaf_value'=>9));
check(lgb_tree_value($tree,array(5))===3.0&&lgb_tree_value($tree,array(6))===9.0,'numeric tree threshold');
$m=array('tree_info'=>array(array('tree_structure'=>$tree),array('tree_structure'=>array('leaf_value'=>2))));
check(lgb_predict($m,array(6))===11.0,'additive LightGBM tree inference');
foreach(array(1,2,3,4) as $area)foreach(array(1,7,365) as $days){$b=prediction_bundle($area,date('Y-m-d'),$days,'pso');check(count($b['rows'])===$days,'area '.$area.' horizon '.$days);check($b['mode']!=='PSO-LightGBM'||$b['available'],'model status truthful');}
echo "All checks passed.\n";
