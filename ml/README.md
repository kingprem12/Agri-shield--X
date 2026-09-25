# PSO LightGBM and drought outlook

The website remains PHP. Python is needed only for offline model training; PHP evaluates exported LightGBM numeric trees. No external chart service or Python web server is needed. The existing sensor upload, history and selected-save logic are unchanged.

## Train with real sensor history

1. Collect at least 60 consecutive daily JSON histories ending on the desired cutoff. This is a minimum implementation guard, not proof of sufficient predictive accuracy. Snapshots and current configuration values are not training samples. Each area is trained separately; mirrored areas must not be pooled as independent observations.
2. In a Python environment, install `pip install -r ml/requirements.txt`.
3. Run `python ml/train_pso_lightgbm.py --area 1 --until YYYY-MM-DD` using an actual recorded cutoff date. Repeat for the other areas as needed.
4. Open Prediction, select that same area and cutoff, choose PSO-LightGBM and generate the outlook. Retrain for a new cutoff. Keep `data` protected from public downloads.

Seven days of four sensor values form 28 numeric features. The next day's four readings are the regression targets. Particle swarm optimization searches leaf count, learning rate, boosting rounds and minimum leaf sample count. Splits are chronological: early training, validation for PSO, untouched final 20% one-step test. The final models are refitted on all cutoff data. Exported metrics compare test MAE with a persistence baseline. These are one-step metrics, not evidence for 365-day recursive forecasts. Long-horizon forecasts recursively reuse previous predictions and need separate evaluation.

Official model API reference: https://lightgbm.readthedocs.io/en/stable/pythonapi/lightgbm.Booster.html

## Honest presentation states

Without a compatible trained model the UI says Awaiting training and names the actual linear or constant baseline. No sample model is bundled or trained from invented observations. Sparse history may produce flat lines. Historical cutoffs with no data do not borrow today's readings. Latest configured values used for today's constant baseline may not be live.

Drought Low/Medium/High is a transparent experimental screening rule applied to forecast values, not a LightGBM drought classifier. Score = .55(100-soil%) + .20(100-rain%) + .15(100-humidity%) + .10 clamp((temperature-20)*5,0,100). Low <33, Medium <66, otherwise High. This is not probability, calibrated drought severity, rainfall volume or irrigation automation. Verify sensor polarity: the current firmware treats larger readings as wetter. Field-labelled drought outcomes and a validation study are needed for a trained drought classifier.

Desktop: graph left, risk panel right. Mobile: stacked panels and a horizontally scrollable daily table. The highest-risk forecast day is shown on the right, while the table lists all daily categories.
