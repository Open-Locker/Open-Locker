import path from 'path';

export const DATA_DIR = process.env.DATA_DIR || '/data';
export const CONFIG_DIR = process.env.CONFIG_DIR || '/config';

export const MQTT_CLIENT_ID_FILE = path.join(DATA_DIR, '.mqtt-client-id');
export const MQTT_CREDENTIALS_FILE = path.join(DATA_DIR, '.mqtt-credentials.json');
export const MQTT_DEDUP_STATE_FILE = path.join(DATA_DIR, '.mqtt-dedup-state.json');
export const RUNTIME_CONFIG_OVERLAY_FILE = path.join(DATA_DIR, '.runtime-config-overlay.json');
export const CONFIG_FILE = path.join(CONFIG_DIR, 'locker-config.yml');
