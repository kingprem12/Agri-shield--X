# Agri Shield & Weather Prediction

ESP32 field sensors (temperature, humidity, soil moisture, rain) + a PHP dashboard + a
PSO-LightGBM drought model trained on Sindh satellite data. Everything runs on your own
machine: no database server and no cloud storage.

## Run locally

```bash
cp config.local.example.php config.local.php   # set DEVICE_API_KEY
php -S 0.0.0.0:8000 router.php                 # open http://localhost:8000  (login: see users.ini)
```

`router.php` blocks `data/`, `ml/`, `*.ini` and other private files, because PHP's built-in
server ignores `.htaccess`.

## Connect the ESP32

1. Laptop and ESP32 on the same WiFi. Find the laptop IP: `ipconfig getifaddr en0` (macOS).
2. Copy `secrets.example.h` to `secrets.h` next to the `.ino` and fill in WiFi, `SERVER_URL`
   (`http://<laptop-ip>:8000`) and the same `DEVICE_API_KEY` as `config.local.php`.
3. Flash `esp32_smart_irrigation.ino`. The Serial Monitor (115200) prints each server reply.

Each reading is saved to `data/area_N/readings-YYYY-MM-DD.json` and shown on the Live Dashboard.

## India drought model (PSO-LightGBM), Drought Predictor page

Data: NASA POWER monthly climate (MERRA-2) for 1,169 points on a 0.5° grid inside India, 1981–2025:
temperature, humidity, rainfall, wind speed, root-zone and surface soil moisture.

```bash
.venv/bin/python ml/india/fetch_power.py    # ~17 min first time (cached after), -> data/india/india.db + assets/india-*.geojson
.venv/bin/python ml/india/train_india.py    # PSO search + training (~4 min) -> data/india/model.json
php ml/india/test_parity.php                # PHP inference == native LightGBM
```

- Target: root-zone soil moisture 1, 2 and 3 months ahead. It is ranked against the location's
  1981–2010 values for that month (percentile) and mapped to US Drought Monitor categories D0–D4.
- Split by time: train 1981–2014, validation 2015–2018 (PSO fitness), test 2019–2025.
- `drought.php` + `api/drought.php` + `india_engine.php`: India district map (official boundaries),
  form auto-filled from the record, what-if edits, 3-month outlook, prediction log in `data/app.db`.
- Map boundaries: udit-001/india-maps-data (district GeoJSON), simplified.

### Real-time validation (Validation page)

```bash
.venv/bin/python ml/india/validate_live.py   # re-run monthly
```

Downloads NASA POWER *daily* data published after the training data (2026 onward), averages it into
months exactly like the seed data, appends it to `india.db` (the trainer never uses these months) and
scores the deployed model on it. `validation.php` shows seed-test vs real-time metrics side by side,
per-month accuracy, predicted-vs-actual charts, and compares the ESP32 daily readings with NASA POWER
daily values at the farm location (set on the page).

## Satellite drought model (PSO-LightGBM)

Data: monthly MODIS NDVI, land surface temperature and precipitation, 4,937 grid cells
(~10 km) over Sindh, 2001–2023 (`Sindh_Grid_YYYY_MM.csv` exports from Google Earth Engine).

```bash
python3 -m venv .venv && .venv/bin/pip install -r ml/requirements.txt
.venv/bin/python ml/satellite/build_dataset.py --src ~/Desktop/capstone_extracted   # CSVs -> data/satellite/sindh.db
.venv/bin/python ml/satellite/train_satellite.py                                      # PSO search + training -> data/satellite/model.json
php ml/satellite/test_parity.php                                                      # PHP inference == native LightGBM
```

- Targets: NDVI and LST 1, 2 and 3 months ahead (6 regressors). Drought class comes from the
  Vegetation Health Index (VHI = 0.5 VCI + 0.5 TCI, drought when VHI < 40).
- Split by time: train 2001–2017, validation 2018–2020 (PSO fitness), test 2021–2023
  (reported metrics, compared with persistence and climatology baselines).
- PHP evaluates the exported trees directly (`satellite_engine.php`); Python is only needed to train.
- **Satellite Report** page (`satellite.php`): full backtest view, regional forecast vs actual maps,
  and the model evaluation table.

The sensor-based forecast on the **Prediction** page (`ml/train_pso_lightgbm.py`) trains once
enough ESP32 history has been collected; see `ml/README.md`.
