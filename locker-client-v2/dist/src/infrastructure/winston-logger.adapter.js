"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createWinstonLoggerPort = createWinstonLoggerPort;
const logger_1 = require("./logger");
function createWinstonLoggerPort() {
    const winston = (0, logger_1.createWinstonLogger)();
    return {
        warn(message, meta) {
            winston.warn(message, meta);
        },
    };
}
