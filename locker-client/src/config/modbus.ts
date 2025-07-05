import dotenv from "dotenv";

dotenv.config();

export const modbusConfig = {
  port: process.env.MODBUS_PORT || "/dev/ttyUSB0",
  baudRate: parseInt(process.env.MODBUS_BAUD_RATE || "9600"),
  dataBits: parseInt(process.env.MODBUS_DATA_BITS || "8") as 7 | 8,
  stopBits: parseInt(process.env.MODBUS_STOP_BITS || "1") as 1 | 2,
  parity: (process.env.MODBUS_PARITY || "none") as "none" | "even" | "odd",
  slaveId: parseInt(process.env.MODBUS_SLAVE_ID || "1"),
  timeout: parseInt(process.env.MODBUS_TIMEOUT || "1000"),

  // Locker-specific addresses
  addresses: {
    lockControl: parseInt(process.env.MODBUS_LOCK_CONTROL_ADDR || "0"),
    lockStatus: parseInt(process.env.MODBUS_LOCK_STATUS_ADDR || "1"),
    doorSensor: parseInt(process.env.MODBUS_DOOR_SENSOR_ADDR || "2"),
  },
};
