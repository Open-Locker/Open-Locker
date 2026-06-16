"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
require("dotenv/config");
const createApp_1 = require("./bootstrap/createApp");
const logging_1 = require("./infrastructure/logging");
async function main() {
    const app = await (0, createApp_1.createApp)();
    const shutdown = async (signal) => {
        logging_1.logger.info(`Received ${signal}, shutting down...`);
        await app.shutdown();
        process.exit(0);
    };
    process.on("SIGINT", () => void shutdown("SIGINT"));
    process.on("SIGTERM", () => void shutdown("SIGTERM"));
}
main().catch((error) => {
    logging_1.logger.error("Fatal startup error", error);
    process.exit(1);
});
