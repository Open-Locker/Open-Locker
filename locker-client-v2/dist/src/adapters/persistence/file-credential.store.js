"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.FileCredentialStore = void 0;
const fs_1 = __importDefault(require("fs"));
const zod_1 = require("zod");
const paths_1 = require("../../infrastructure/paths");
const credentialsSchema = zod_1.z.object({
    username: zod_1.z.string().min(1),
    password: zod_1.z.string().min(1),
});
class FileCredentialStore {
    getCredentials() {
        if (!fs_1.default.existsSync(paths_1.MQTT_CREDENTIALS_FILE)) {
            return null;
        }
        const raw = JSON.parse(fs_1.default.readFileSync(paths_1.MQTT_CREDENTIALS_FILE, "utf8"));
        const parsed = credentialsSchema.safeParse(raw);
        return parsed.success ? parsed.data : null;
    }
    saveCredentials(credentials) {
        fs_1.default.writeFileSync(paths_1.MQTT_CREDENTIALS_FILE, JSON.stringify(credentials, null, 2), "utf8");
    }
    isProvisioned() {
        return fs_1.default.existsSync(paths_1.PROVISIONING_STATE_FILE);
    }
    markProvisioned() {
        fs_1.default.writeFileSync(paths_1.PROVISIONING_STATE_FILE, "provisioned", "utf8");
    }
}
exports.FileCredentialStore = FileCredentialStore;
