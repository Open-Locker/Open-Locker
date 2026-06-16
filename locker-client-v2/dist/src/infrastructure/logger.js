"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.createWinstonLogger = createWinstonLogger;
const winston_1 = __importDefault(require("winston"));
function createWinstonLogger(level = process.env.LOG_LEVEL ?? 'info') {
    return winston_1.default.createLogger({
        level,
        format: winston_1.default.format.combine(winston_1.default.format.timestamp(), winston_1.default.format.json()),
        transports: [new winston_1.default.transports.Console()],
    });
}
