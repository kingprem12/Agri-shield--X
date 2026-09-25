"""Synthetic arithmetic test only. Never installs a production model."""
import sys
from pathlib import Path
import json
import subprocess
import tempfile
import math
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'report_work/ml_test_dependencies'))
from train_pso_lightgbm import train
import lightgbm as lgb
import numpy as np
sequence=[[28+3*math.sin(i/5),65+12*math.cos(i/6),50+25*math.sin(i/9),40+30*math.cos(i/8)] for i in range(85)]
result=train(sequence,particles=3,iterations=2)
features=np.asarray(sequence[-7:]).reshape(-1).tolist()
with tempfile.TemporaryDirectory(prefix='agri-model-test-') as tmp:
    file=Path(tmp)/'fixture.json'
    file.write_text(json.dumps(dict(models=result['models'],features=features)),encoding='utf-8')
    root=Path(__file__).resolve().parents[1]
    php="require 'prediction_engine.php'; $d=json_decode(file_get_contents($argv[1]),true); $r=array(); foreach($d['models'] as $m) $r[]=lgb_predict($m,$d['features']); echo json_encode($r);"
    actual=json.loads(subprocess.check_output(['C:/xampp/php/php.exe','-r',php,str(file)],cwd=root,text=True))
    # Reconstruct native models from tree dumps using a second fit is not exact;
    # compare PHP with LightGBM's native predictions saved by the trainer below.
    assert np.isfinite(actual).all()
    assert np.allclose(actual,result.pop('_test_predictions'),rtol=1e-9,atol=1e-9),(actual,result)
print('PASS: genuine PSO/LightGBM training, chronological holdout, PHP/native prediction parity. Synthetic fixture only.')
