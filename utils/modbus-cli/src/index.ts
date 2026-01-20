#!/usr/bin/env node

import { Command } from 'commander';
import { ModbusClient } from './modbus-client';
import chalk from 'chalk';

const program = new Command();

program
  .name('modbus-cli')
  .description('CLI tool to configure Waveshare Modbus RTU Relay boards')
  .version('1.0.0');

program
  .command('config')
  .description('Read current configuration from the board')
  .option('-p, --port <port>', 'Serial port (e.g., /dev/ttyACM0)', '/dev/ttyACM0')
  .option('-b, --baudrate <baudrate>', 'Baudrate', '9600')
  .action(async (options) => {
    console.log(chalk.yellow('⚠️  Please ensure only ONE board is connected to the host!'));
    console.log('');
    
    const client = new ModbusClient(options.port, parseInt(options.baudrate));
    
    try {
      await client.connect();
      console.log(chalk.green('✓ Connected to device'));
      
      const config = await client.readConfiguration();
      
      console.log('');
      console.log(chalk.bold.cyan('=== Current Configuration ==='));
      console.log('');
      console.log(`${chalk.bold('Slave ID:')}          ${config.slaveId} (0x${config.slaveId.toString(16).padStart(2, '0').toUpperCase()})`);
      console.log(`${chalk.bold('Baudrate:')}          ${config.baudrate}`);
      console.log(`${chalk.bold('Parity:')}            ${config.parity}`);
      console.log(`${chalk.bold('Software Version:')}  ${config.version}`);
      console.log('');
      console.log(chalk.bold.cyan('=== Operation Modes (by channel) ==='));
      console.log('');
      
      const modeNames = ['Normal', 'Linkage', 'Toggle', 'Edge Trigger'];
      config.controlModes.forEach((mode, index) => {
        console.log(`  Channel ${index + 1}: ${modeNames[mode] || 'Unknown'}`);
      });
      console.log('');
      
      await client.disconnect();
      console.log(chalk.green('✓ Disconnected'));
      
    } catch (error) {
      console.error(chalk.red(`Error: ${(error as Error).message}`));
      process.exit(1);
    }
  });

program
  .command('set-slave-id')
  .description('Set the slave ID (device address)')
  .argument('<id>', 'New slave ID (1-255)')
  .option('-p, --port <port>', 'Serial port (e.g., /dev/ttyACM0)', '/dev/ttyACM0')
  .option('-b, --baudrate <baudrate>', 'Baudrate', '9600')
  .action(async (id, options) => {
    console.log(chalk.yellow('⚠️  Please ensure only ONE board is connected to the host!'));
    console.log('');
    
    const slaveId = parseInt(id);
    
    if (slaveId < 1 || slaveId > 255) {
      console.error(chalk.red('Error: Slave ID must be between 1 and 255'));
      process.exit(1);
    }
    
    const client = new ModbusClient(options.port, parseInt(options.baudrate));
    
    try {
      await client.connect();
      console.log(chalk.green('✓ Connected to device'));
      
      await client.setSlaveId(slaveId);
      console.log(chalk.green(`✓ Slave ID set to ${slaveId} (0x${slaveId.toString(16).padStart(2, '0').toUpperCase()})`));
      console.log(chalk.yellow('⚠️  Please power cycle the device for changes to take effect'));
      
      await client.disconnect();
      
    } catch (error) {
      console.error(chalk.red(`Error: ${(error as Error).message}`));
      process.exit(1);
    }
  });

program
  .command('set-mode')
  .description('Set operation mode for a channel')
  .argument('<channel>', 'Channel number (1-8)')
  .argument('<mode>', 'Operation mode: normal, linkage, toggle, edge')
  .option('-p, --port <port>', 'Serial port (e.g., /dev/ttyACM0)', '/dev/ttyACM0')
  .option('-b, --baudrate <baudrate>', 'Baudrate', '9600')
  .option('-s, --slave-id <id>', 'Slave ID of the device', '1')
  .action(async (channel, mode, options) => {
    console.log(chalk.yellow('⚠️  Please ensure only ONE board is connected to the host!'));
    console.log('');
    
    const channelNum = parseInt(channel);
    
    if (channelNum < 1 || channelNum > 8) {
      console.error(chalk.red('Error: Channel must be between 1 and 8'));
      process.exit(1);
    }
    
    const modeMap: { [key: string]: number } = {
      'normal': 0,
      'linkage': 1,
      'toggle': 2,
      'edge': 3
    };
    
    const modeLower = mode.toLowerCase();
    if (!(modeLower in modeMap)) {
      console.error(chalk.red('Error: Mode must be one of: normal, linkage, toggle, edge'));
      process.exit(1);
    }
    
    const modeValue = modeMap[modeLower];
    const client = new ModbusClient(options.port, parseInt(options.baudrate), parseInt(options.slaveId));
    
    try {
      await client.connect();
      console.log(chalk.green('✓ Connected to device'));
      
      await client.setChannelMode(channelNum, modeValue);
      console.log(chalk.green(`✓ Channel ${channelNum} set to ${modeLower} mode`));
      
      await client.disconnect();
      
    } catch (error) {
      console.error(chalk.red(`Error: ${(error as Error).message}`));
      process.exit(1);
    }
  });

program.parse(process.argv);
