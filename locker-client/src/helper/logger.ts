import dotenv from 'dotenv';
import winston from 'winston';

dotenv.config();

export const logger = winston.createLogger({
    level: process.env.LOG_LEVEL || 'info',
    format: winston.format.combine(
        winston.format.timestamp(),
        winston.format.json(),
    ),
    transports: [
        new winston.transports.Console(),
        new winston.transports.File({ filename: '/log/app.log' })
    ]
});
