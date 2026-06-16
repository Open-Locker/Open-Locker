import winston from "winston";

export function createWinstonLogger(level = process.env.LOG_LEVEL ?? "info") {
  return winston.createLogger({
    level,
    format: winston.format.combine(
      winston.format.timestamp(),
      winston.format.json(),
    ),
    transports: [new winston.transports.Console()],
  });
}
