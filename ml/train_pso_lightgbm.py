"""Offline PSO training. Exports numeric LightGBM trees for PHP inference.
No invented samples, snapshots, duplicated areas, or shuffled temporal splits.
"""
import argparse
import datetime as dt
import json
import math
from pathlib import Path

FIELDS = ('temperature', 'humidity', 'soil_value', 'rain_value')
TARGETS = ('temp', 'hum', 'soil', 'rain')

def load_daily(folder, until):
    daily = {}
    for file in sorted(folder.glob('readings-????-??-??.json')):
        day = dt.date.fromisoformat(file.stem[9:])
        if day > until:
            continue
        values = []
        for row in json.loads(file.read_text(encoding='utf-8')):
            try:
                v = [float(row[k]) for k in FIELDS]
                if not all(math.isfinite(x) for x in v):
                    continue
                if not (-40 <= v[0] <= 125 and 0 <= v[1] <= 100 and all(0 <= x <= 4095 for x in v[2:])):
                    continue
                # Match PHP sensor_percent input transformation.
                values.append(v)
            except (KeyError, ValueError, TypeError):
                continue
        if values:
            avg = [sum(v[i] for v in values)/len(values) for i in range(4)]
            daily[day] = avg[:2] + [math.floor(x*100/4095*10+.5)/10 for x in avg[2:]]
    sequence = []
    day = until
    while day in daily:
        sequence.append(daily[day])
        day -= dt.timedelta(days=1)
    if len(sequence) < 60:
        raise ValueError(f'Need 60 consecutive recorded days ending {until}; found {len(sequence)}. No model was exported.')
    return list(reversed(sequence))

def train(sequence, particles=6, iterations=5):
    import numpy as np
    import lightgbm as lgb
    rng = np.random.default_rng(42)
    a = np.asarray(sequence, dtype=float)
    x = np.asarray([a[i-7:i].reshape(-1) for i in range(7, len(a))])
    y = a[7:]
    test_start = int(len(x)*.8)
    val_start = int(test_start*.75)
    scales = np.array([60.,100.,100.,100.])
    bounds = np.array([[5,24],[.02,.18],[30,120],[3,12]],dtype=float)

    def params(position):
        return dict(objective='regression', verbosity=-1, num_threads=1,
                    seed=42, deterministic=True, force_col_wise=True,
                    num_leaves=int(round(position[0])), learning_rate=float(position[1]),
                    min_data_in_leaf=int(round(position[3])), min_data_in_bin=1)

    def fit(position, end):
        return [lgb.train(params(position), lgb.Dataset(x[:end], label=y[:end,j]),
                          num_boost_round=int(round(position[2]))) for j in range(4)]

    def score(position):
        models=fit(position,val_start)
        pred=np.column_stack([m.predict(x[val_start:test_start]) for m in models])
        return float(np.mean(np.abs(pred-y[val_start:test_start])/scales))

    # Real particle swarm optimization over four LightGBM hyperparameters.
    positions=rng.uniform(bounds[:,0],bounds[:,1],size=(particles,4))
    velocity=np.zeros_like(positions)
    personal=positions.copy(); best_scores=np.full(particles,np.inf)
    global_best=positions[0].copy(); global_score=float('inf')
    for _ in range(iterations):
        for i in range(particles):
            value=score(positions[i])
            if value<best_scores[i]: personal[i]=positions[i].copy();best_scores[i]=value
            if value<global_score: global_best=positions[i].copy();global_score=value
        velocity=.6*velocity+1.4*rng.random(positions.shape)*(personal-positions)+1.4*rng.random(positions.shape)*(global_best-positions)
        positions=np.clip(positions+velocity,bounds[:,0],bounds[:,1])
    evaluation_models=fit(global_best,test_start)
    prediction=np.column_stack([m.predict(x[test_start:]) for m in evaluation_models])
    mae=np.mean(np.abs(prediction-y[test_start:]),axis=0)
    baseline=np.mean(np.abs(x[test_start:,-4:]-y[test_start:]),axis=0)
    final=fit(global_best,len(x))
    return dict(_test_predictions=[float(m.predict(a[-7:].reshape(1,-1))[0]) for m in final],
                models={key:m.dump_model() for key,m in zip(TARGETS,final)},
                pso=dict(particles=particles,iterations=iterations,seed=42,
                         validation_normalized_mae=global_score,best_parameters=params(global_best),
                         boosting_rounds=int(round(global_best[2]))),
                evaluation=dict(type='chronological one-step holdout before final refit',
                                test_samples=len(x)-test_start,
                                mae=dict(zip(TARGETS,mae.tolist())),
                                persistence_mae=dict(zip(TARGETS,baseline.tolist()))))

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--area',type=int,choices=range(1,5),required=True)
    parser.add_argument('--until',type=dt.date.fromisoformat,required=True)
    parser.add_argument('--data-root',type=Path,default=Path(__file__).resolve().parents[1]/'data')
    args=parser.parse_args()
    if args.until>dt.date.today(): parser.error('Cutoff cannot be in the future.')
    folder=args.data_root/f'area_{args.area}'
    try:
        sequence=load_daily(folder,args.until)
        result=train(sequence)
    except (ValueError,ImportError) as exc:
        parser.exit(2,str(exc)+'\n')
    result.pop('_test_predictions',None)
    result.update(schema=1,algorithm='PSO-LightGBM',area=args.area,
                  trained_until=args.until.isoformat(),feature_count=28,
                  feature_order='oldest to newest 7 days: temperature humidity soil_percent rain_percent',
                  training_days=len(sequence),generated_at=dt.datetime.now().isoformat())
    output=folder/'pso_lightgbm.json'
    temp=output.with_suffix('.tmp')
    temp.write_text(json.dumps(result,allow_nan=False),encoding='utf-8')
    temp.replace(output)
    print(f'Exported {output}; PHP can now forecast at this cutoff.')

if __name__=='__main__': main()
