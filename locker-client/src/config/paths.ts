import path from "path";

// Directory paths for persistent storage
export const DATA_DIR = process.env.DATA_DIR || "/data";
export const CONFIG_DIR = process.env.CONFIG_DIR || "/config";

// Data files (stored in /data directory)
export const MQTT_CLIENT_ID_FILE = path.join(DATA_DIR, ".mqtt-client-id");
export const MQTT_CREDENTIALS_FILE = path.join(DATA_DIR, ".mqtt-credentials.json");
export const PROVISIONING_STATE_FILE = path.join(DATA_DIR, ".provisioning-state");

// Config files (stored in /config directory)
export const CONFIG_FILE = path.join(CONFIG_DIR, "locker-config.yml");
