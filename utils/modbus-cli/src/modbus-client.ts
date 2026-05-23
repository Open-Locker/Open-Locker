import ModbusRTU from 'modbus-serial';

export interface DeviceConfig {
  slaveId: number;
  baudrate: number;
  parity: string;
  version: string;
  controlModes: number[];
}

export class ModbusClient {
  private client: ModbusRTU;
  private port: string;
  private baudrate: number;
  private slaveId: number;

  constructor(port: string, baudrate: number = 9600, slaveId: number = 1) {
    this.client = new ModbusRTU();
    this.port = port;
    this.baudrate = baudrate;
    this.slaveId = slaveId;
  }

  async connect(): Promise<void> {
    await this.client.connectRTUBuffered(this.port, {
      baudRate: this.baudrate,
      dataBits: 8,
      parity: 'none',
      stopBits: 1,
    });

    this.client.setID(this.slaveId);
    this.client.setTimeout(1000);
  }

  async disconnect(): Promise<void> {
    this.client.close(() => {});
  }

  /**
   * Read current configuration from the device
   */
  async readConfiguration(): Promise<DeviceConfig> {
    // Use broadcast address to read device address
    this.client.setID(0);
    
    // Read device address (register 0x4000)
    const addressData = await this.client.readHoldingRegisters(0x4000, 1);
    const slaveId = addressData.data[0];
    
    // Switch to actual device address
    this.client.setID(slaveId);
    
    // Read UART parameters (register 0x2000)
    const uartData = await this.client.readHoldingRegisters(0x2000, 1);
    const uartParams = uartData.data[0];
    const parityMode = (uartParams >> 8) & 0xFF;
    const baudrateMode = uartParams & 0xFF;
    
    const parityMap = ['None', 'Even', 'Odd'];
    const baudrateMap = [4800, 9600, 19200, 38400, 57600, 115200, 128000, 256000];
    
    // Read software version (register 0x8000)
    this.client.setID(0);
    const versionData = await this.client.readHoldingRegisters(0x8000, 1);
    const versionValue = versionData.data[0];
    const version = `V${(versionValue / 100).toFixed(2)}`;
    
    // Read control modes for all 8 channels (registers 0x1000-0x1007)
    this.client.setID(slaveId);
    const modesData = await this.client.readHoldingRegisters(0x1000, 8);
    const controlModes = Array.from(modesData.data);
    
    return {
      slaveId,
      baudrate: baudrateMap[baudrateMode] || 9600,
      parity: parityMap[parityMode] || 'None',
      version,
      controlModes,
    };
  }

  /**
   * Set the slave ID (device address)
   * @param newSlaveId New slave ID (1-255)
   */
  async setSlaveId(newSlaveId: number): Promise<void> {
    if (newSlaveId < 1 || newSlaveId > 255) {
      throw new Error('Slave ID must be between 1 and 255');
    }

    // Use broadcast address to set device address
    this.client.setID(0);
    
    // Write to register 0x4000
    await this.client.writeRegister(0x4000, newSlaveId);
  }

  /**
   * Set the operation mode for a specific channel
   * @param channel Channel number (1-8)
   * @param mode Mode value (0=Normal, 1=Linkage, 2=Toggle, 3=Edge Trigger)
   */
  async setChannelMode(channel: number, mode: number): Promise<void> {
    if (channel < 1 || channel > 8) {
      throw new Error('Channel must be between 1 and 8');
    }

    if (mode < 0 || mode > 3) {
      throw new Error('Mode must be between 0 and 3');
    }

    // Register address: 0x1000 + (channel - 1)
    const registerAddress = 0x1000 + (channel - 1);
    
    await this.client.writeRegister(registerAddress, mode);
  }

  /**
   * Write a single coil (relay)
   * @param address Coil address (0-based)
   * @param value true = ON, false = OFF
   */
  async writeCoil(address: number, value: boolean): Promise<void> {
    await this.client.writeCoil(address, value);
  }

  /**
   * Flash a relay ON for a specified duration (Waveshare FC5 extension)
   * @param relay Relay number (1-based)
   * @param durationMs Duration in milliseconds (steps of 100ms, max ~54 min)
   */
  async flashRelayOn(relay: number, durationMs: number): Promise<void> {
    const FLASH_ON_BASE_ADDRESS = 0x0200;
    const FLASH_DURATION_STEP_MS = 100;
    const FLASH_DURATION_MAX_STEPS = 0x7fff;

    const address = relay - 1; // convert to 0-based
    const flashAddress = FLASH_ON_BASE_ADDRESS + address;
    const steps = Math.ceil(durationMs / FLASH_DURATION_STEP_MS);

    if (steps > FLASH_DURATION_MAX_STEPS) {
      throw new Error(`Duration exceeds maximum (${FLASH_DURATION_MAX_STEPS * FLASH_DURATION_STEP_MS}ms)`);
    }

    const payload = Buffer.from([
      (flashAddress >> 8) & 0xff,
      flashAddress & 0xff,
      (steps >> 8) & 0xff,
      steps & 0xff,
    ]);

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (this.client as any).customFunction(0x05, payload);
  }

  /**
   * Set baudrate for the device
   * @param baudrate Baudrate value (4800, 9600, 19200, 38400, 57600, 115200, 128000, 256000)
   * @param parity Parity mode (0=None, 1=Even, 2=Odd)
   */
  async setBaudrate(baudrate: number, parity: number = 0): Promise<void> {
    const baudrateMap: { [key: number]: number } = {
      4800: 0,
      9600: 1,
      19200: 2,
      38400: 3,
      57600: 4,
      115200: 5,
      128000: 6,
      256000: 7,
    };

    if (!(baudrate in baudrateMap)) {
      throw new Error('Invalid baudrate. Valid values: 4800, 9600, 19200, 38400, 57600, 115200, 128000, 256000');
    }

    if (parity < 0 || parity > 2) {
      throw new Error('Parity must be 0 (None), 1 (Even), or 2 (Odd)');
    }

    // Use broadcast address
    this.client.setID(0);
    
    const value = (parity << 8) | baudrateMap[baudrate];
    await this.client.writeRegister(0x2000, value);
  }
}
