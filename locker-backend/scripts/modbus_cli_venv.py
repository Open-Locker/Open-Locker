#!/var/www/html/scripts/venv/bin/python

import argparse
import sys
from pymodbus.client import ModbusTcpClient
from pymodbus.exceptions import ModbusIOException, ConnectionException

def main():
    parser = argparse.ArgumentParser(description='Modbus CLI Tool using pymodbus')
    parser.add_argument('--host', required=True, help='Modbus TCP host IP address')
    parser.add_argument('--port', type=int, default=502, help='Modbus TCP port')
    parser.add_argument('--unit', type=int, required=True, help='Modbus unit ID (slave ID)')
    parser.add_argument('--action', required=True, choices=['read_coil', 'write_coil'], help='Modbus action')
    parser.add_argument('--address', type=int, required=True, help='Coil address (starting from 0)')
    parser.add_argument('--value', type=int, choices=[0, 1], help='Value to write (0 or 1) for write_coil action')
    parser.add_argument('--timeout', type=float, default=3.0, help='Connection timeout in seconds')

    args = parser.parse_args()

    if args.action == 'write_coil' and args.value is None:
        print("ERROR: --value is required for write_coil action", file=sys.stderr)
        sys.exit(1)

    client = ModbusTcpClient(args.host, port=args.port, timeout=args.timeout)

    try:
        if not client.connect():
            print(f"ERROR: Failed to connect to {args.host}:{args.port}", file=sys.stderr)
            sys.exit(1)

        if args.action == 'read_coil':
            result = client.read_coils(address=args.address, count=1, slave=args.unit)
            if isinstance(result, ModbusIOException):
                print(f"ERROR: Modbus IO Error: {result}", file=sys.stderr)
                sys.exit(1)
            if result.isError():
                 print(f"ERROR: Modbus Error Response: {result}", file=sys.stderr)
                 sys.exit(1)

            print(int(result.bits[0])) # Output 1 or 0

        elif args.action == 'write_coil':
            value_to_write = bool(args.value)
            result = client.write_coil(address=args.address, value=value_to_write, slave=args.unit)
            if isinstance(result, ModbusIOException):
                print(f"ERROR: Modbus IO Error: {result}", file=sys.stderr)
                sys.exit(1)
            if result.isError():
                 print(f"ERROR: Modbus Error Response: {result}", file=sys.stderr)
                 sys.exit(1)

            # Verify write if possible (optional, read back the coil)
            read_back = client.read_coils(address=args.address, count=1, slave=args.unit)
            if not read_back.isError() and read_back.bits[0] == value_to_write:
                 print("OK")
            else:
                 print(f"ERROR: Write verification failed or read error.", file=sys.stderr)
                 sys.exit(1)


    except ConnectionException as e:
        print(f"ERROR: Connection exception: {e}", file=sys.stderr)
        sys.exit(1)
    except Exception as e:
        print(f"ERROR: An unexpected error occurred: {e}", file=sys.stderr)
        sys.exit(1)
    finally:
        if client.is_socket_open():
            client.close()

if __name__ == "__main__":
    main()
