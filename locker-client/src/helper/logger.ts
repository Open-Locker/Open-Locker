import dotenv from 'dotenv';
import fs from 'fs';
import path from 'path';
import winston from 'winston';

dotenv.config();

const fileLogPath = '/log/app.log';
const transports: winston.transport[] = [
    new winston.transports.Console(),
];

if (fs.existsSync(path.dirname(fileLogPath))) {
    transports.push(new winston.transports.File({ filename: fileLogPath }));
}

export const logger = winston.createLogger({
    level: process.env.LOG_LEVEL || 'info',
    format: winston.format.combine(
        winston.format.timestamp(),
        winston.format.json(),
    ),
    transports,
});
