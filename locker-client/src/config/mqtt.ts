import dotenv from "dotenv";

dotenv.config();

export const mqttConfig = {
  brokerUrl: process.env.MQTT_BROKER_URL || "mqtt://localhost:1883",
  username: process.env.MQTT_USERNAME,
  password: process.env.MQTT_PASSWORD,
  clientId:
    process.env.MQTT_CLIENT_ID ||
    `locker-client-${Math.random().toString(16).substr(2, 8)}`,
  topics: {
    registration: process.env.MQTT_TOPIC_REGISTRATION || "locker/registration",
    status: process.env.MQTT_TOPIC_STATUS || "locker/simon/status",
    open: process.env.MQTT_TOPIC_COMMANDS || "locker/simon/open",
  },
};
